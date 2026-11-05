<?php
/**
 * AdminURL 管理后台 - YOURLS 数据迁移
 *
 * 支持两种迁移方式：
 *  1. 粘贴 YOURLS SQL 导出（urls 表 insert 语句）
 *  2. 上传 CSV（表头：keyword,url,title,clicks）
 *
 * 导入规则：
 *  - 已存在的短码自动跳过（不覆盖）
 *  - 缺失 title 的条目尝试抓取标题（可关闭）
 *  - 迁移过程逐条导入，结果汇总展示
 */

require_once __DIR__ . '/_init.php';

$pageTitle = '数据迁移';
$activeNav = 'migrate';

$result = null; // ['imported'=>, 'skipped'=>, 'errors'=>]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = $_POST['form'] ?? '';

    if ($form === 'migrate_csv' || $form === 'migrate_sql') {
        if (!verify_csrf($_POST['_csrf'] ?? null)) {
            $_SESSION['flash_error'] = '请求已过期，请刷新页面后重试';
        } else {
            $content = (string)($_POST['content'] ?? '');
            $autoFetch = isset($_POST['auto_title']);
            $source = 'csv';

            if ($form === 'migrate_sql') {
                $source = 'sql';
                $rows = parse_yourls_sql($content);
            } else {
                $rows = parse_yourls_csv($content);
            }

            if (count($rows) === 0) {
                $_SESSION['flash_error'] = '未能解析出有效数据，请检查格式';
            } else {
                $result = import_yourls_rows($rows, $autoFetch);
                db()->prepare('INSERT INTO migrations (source, imported, skipped, created_at) VALUES (?, ?, ?, ?)')
                    ->execute([$source, $result['imported'], $result['skipped'], date('Y-m-d H:i:s')]);
                log_event('migrate', 'YOURLS 迁移完成，来源: ' . $source . '，导入 ' . $result['imported'] . ' 条');
            }
        }
    }
}

// ---------- 解析函数 ----------

/**
 * 解析 YOURLS CSV（keyword,url,title,clicks）
 * 兼容带表头或无表头、中文引号的常见导出
 */
function parse_yourls_csv(string $text): array
{
    $lines = preg_split('/\r\n|\r|\n/', trim($text));
    $rows = [];
    foreach ($lines as $i => $line) {
        if ($line === '') {
            continue;
        }
        $raw = str_getcsv($line);
        if (count($raw) < 2) {
            // 尝试简单逗号切分
            $raw = explode(',', $line);
        }
        array_walk($raw, function (&$v) {
            $v = trim($v);
        });

        // 第一行可能是表头
        if ($i === 0 && count($raw) >= 2
            && in_array(strtolower($raw[0]), ['keyword', 'shorturl', '短码'], true)) {
            continue;
        }

        $rows[] = [
            'code'  => $raw[0] ?? '',
            'url'   => $raw[1] ?? '',
            'title' => $raw[2] ?? '',
            'clicks'=> (int)($raw[3] ?? 0),
        ];
    }
    return $rows;
}

/**
 * 解析 YOURLS SQL 导出中的 INSERT INTO urls 语句
 * 支持标准 phpMyAdmin 导出格式
 */
function parse_yourls_sql(string $text): array
{
    $rows = [];
    $sql = preg_replace('/\/\*.*?\*\//s', '', (string)$text); // 去注释
    $sql = preg_replace('/--[^\n]*/', '', $sql);

    // 匹配 INSERT INTO `xxx_urls` VALUES (...),(...);
    if (preg_match_all(
        '/INSERT\s+INTO\s+[`"\[]?[\w]*_?urls[`"\]]?\s+(?:\([^)]*\)\s*)?VALUES\s*(.*?);/is',
        $sql,
        $m
    )) {
        foreach ($m[1] as $valuesBlock) {
            // 按 ),( 拆分组
            $groups = preg_split('/\)\s*,\s*\(/i', trim($valuesBlock));
            foreach ($groups as $g) {
                $g = trim($g, "() \t\r\n");
                $cells = simple_sql_split($g);
                if (count($cells) >= 3) {
                    $rows[] = [
                        'code'   => (string)$cells[0],
                        'url'    => (string)$cells[1],
                        'title'  => (string)($cells[2] ?? ''),
                        'clicks' => (int)(end($cells) ?? 0),
                    ];
                }
            }
        }
    } elseif (preg_match_all('/\(([^()]*)\)/s', $text, $m)) {
        // 宽松兜底：解析所有括号组
        foreach ($m[1] as $g) {
            $cells = simple_sql_split($g);
            if (count($cells) >= 3 && filter_var($cells[1], FILTER_VALIDATE_URL)) {
                $rows[] = [
                    'code'   => (string)$cells[0],
                    'url'    => (string)$cells[1],
                    'title'  => (string)($cells[2] ?? ''),
                    'clicks' => (int)(end($cells) ?? 0),
                ];
            }
        }
    }
    return $rows;
}

/**
 * SQL 值切分（处理引号与转义）
 */
function simple_sql_split(string $text): array
{
    $cells = [];
    $len = strlen($text);
    $cur = '';
    $quote = null;
    for ($i = 0; $i < $len; $i++) {
        $c = $text[$i];
        if ($quote !== null) {
            if ($c === '\\' && $i + 1 < $len) { // 转义
                $cur .= $text[++$i];
                continue;
            }
            if ($c === $quote) {
                if ($i + 1 < $len && $text[$i + 1] === $quote) { // '' 转义
                    $cur .= $quote;
                    $i++;
                    continue;
                }
                $quote = null;
                continue;
            }
            $cur .= $c;
            continue;
        }
        if ($c === "'" || $c === '"') {
            $quote = $c;
            continue;
        }
        if ($c === ',' && ($cur === '' || $cur[0] !== '`')) {
            $cells[] = trim($cur);
            $cur = '';
            continue;
        }
        $cur .= $c;
    }
    if (trim($cur) !== '') {
        $cells[] = trim($cur);
    }
    // 去掉反引号
    foreach ($cells as &$cell) {
        if (strlen($cell) >= 2 && $cell[0] === '`' && substr($cell, -1) === '`') {
            $cell = substr($cell, 1, -1);
        }
    }
    return $cells;
}

/**
 * 执行导入
 */
function import_yourls_rows(array $rows, bool $autoFetch): array
{
    $imported = 0;
    $skipped = 0;
    $errors = [];

    $insert = db()->prepare(
        'INSERT INTO links (code, url, title, user_id, status, created_at, updated_at)
         VALUES (?, ?, ?, 0, 1, ?, ?)'
    );

    foreach ($rows as $row) {
        $code = (string)$row['code'];
        $url = (string)$row['url'];
        if ($code === '' || $url === '' || !is_valid_url($url)
            || !preg_match('/^[A-Za-z0-9]{3,32}$/', $code)) {
            $skipped++;
            continue;
        }
        if (link_exists($code)) {
            $skipped++;
            continue;
        }

        $title = trim((string)$row['title']);
        if ($title === '' && $autoFetch) {
            $title = fetch_page_title($url, (int)config('fetch_timeout', 6));
        }

        try {
            $insert->execute([$code, $url, mb_substr($title, 0, 200), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            // 回填已有点击数
            if ((int)$row['clicks'] > 0) {
                db()->prepare('UPDATE links SET clicks = clicks + ? WHERE code = ?')
                    ->execute([(int)$row['clicks'], $code]);
            }
            $imported++;
        } catch (Throwable $e) {
            $errors[] = $code . ': ' . $e->getMessage();
            if (count($errors) > 10) {
                $errors[] = '（更多错误已省略）';
                break;
            }
        }
    }

    return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
}

// 迁移历史
$migrations = db()->query('SELECT * FROM migrations ORDER BY id DESC LIMIT 20')->fetchAll();

require __DIR__ . '/_header.php';
?>

<div class="page-head">
    <div>
        <h2>数据迁移</h2>
        <p>从 YOURLS 平滑迁入已有短链，已存在的短码自动跳过，不影响原站点数据。</p>
    </div>
</div>

<div class="migrate-wrap">

    <div class="card">
        <div class="card-head"><h2><?= icon('database') ?> 从 YOURLS 导入</h2></div>
        <p class="card-desc">
            把 <a href="https://yourls.org" target="_blank" rel="noopener nofollow">YOURLS</a> 的短链接数据平滑迁移过来。
            短码已有的条目会自动跳过（不覆盖），迁移不影响原站点。
        </p>

        <div class="migrate-tabs">
            <div class="tab-btns">
                <button type="button" class="btn btn-ghost btn-sm active" data-tab="csv"><?= icon('upload', 'icon-sm') ?> CSV 粘贴</button>
                <button type="button" class="btn btn-ghost btn-sm" data-tab="sql"><?= icon('code', 'icon-sm') ?> SQL 导出</button>
            </div>

            <!-- CSV -->
            <div class="tab-panel" id="tab-csv">
                <p class="form-help">CSV 每行一条：<code>短码,目标地址,标题,点击数</code>，首行可带表头 <code>keyword,url,title,clicks</code>。</p>
                <form method="post" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="migrate_csv">
                    <div class="form-group">
                        <textarea name="content" rows="10" placeholder="abc,https://example.com/1,我是标题,12&#10;def,https://example.com/2,另一个标题,3" required></textarea>
                    </div>
                    <label class="switch-row"><input type="checkbox" name="auto_title" checked><span class="switch"></span><span>标题为空时自动抓取</span></label>
                    <button type="submit" class="btn btn-primary">开始导入</button>
                </form>
            </div>

            <!-- SQL -->
            <div class="tab-panel" id="tab-sql" style="display:none">
                <p class="form-help">粘贴 phpMyAdmin 导出的 YOURLS <code>urls</code> 表 INSERT 语句。</p>
                <form method="post" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="migrate_sql">
                    <div class="form-group">
                        <textarea name="content" rows="10" placeholder="INSERT INTO `yourls_urls` VALUES ('abc','https://…','标题',123,...);" required></textarea>
                    </div>
                    <label class="switch-row"><input type="checkbox" name="auto_title" checked><span class="switch"></span><span>标题为空时自动抓取</span></label>
                    <button type="submit" class="btn btn-primary">开始导入</button>
                </form>
            </div>
        </div>
    </div>

    <?php if ($result): ?>
        <div class="result-card <?= $result['imported'] > 0 ? 'success' : 'warn' ?>">
            <p><?= icon($result['imported'] > 0 ? 'check' : 'info', 'icon-sm') ?> <strong>迁移完成：</strong>
                成功导入 <strong><?= (int)$result['imported'] ?></strong> 条，
                跳过 <strong><?= (int)$result['skipped'] ?></strong> 条
                <?php if (count($result['errors']) > 0): ?>，<?= count($result['errors']) ?> 条失败<?php endif; ?>
            </p>
            <?php if (count($result['errors']) > 0): ?>
                <ul class="error-list">
                    <?php foreach ($result['errors'] as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (count($migrations) > 0): ?>
    <div class="card">
        <div class="card-head"><h2><?= icon('clock') ?> 迁移历史</h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>时间</th><th>来源</th><th>导入</th><th>跳过</th></tr></thead>
                <tbody>
                <?php foreach ($migrations as $m): ?>
                    <tr>
                        <td class="td-time"><?= e($m['created_at']) ?></td>
                        <td><?= e($m['source']) ?></td>
                        <td class="td-num"><?= (int)$m['imported'] ?></td>
                        <td class="td-num"><?= (int)$m['skipped'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/_footer.php'; ?>