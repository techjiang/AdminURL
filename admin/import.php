<?php
/**
 * AdminURL 管理后台 - 批量导入
 *
 * 通过上传 CSV 文件或粘贴内容批量创建短链，支持自定义短码、标题与有效期。
 * 与「数据迁移」的区别：本页面向通用 CSV（含本系统导出的格式），迁移页面向 YOURLS 导出。
 */

require_once __DIR__ . '/_init.php';

define('IMPORT_MAX_ROWS', 500);
define('IMPORT_MAX_BYTES', 1024 * 1024);

$pageTitle = '批量导入';
$activeNav = 'import';

// ---------- CSV 模板下载 ----------
if (($_GET['action'] ?? '') === 'template') {
    if (!verify_csrf($_GET['_csrf'] ?? '')) {
        $_SESSION['flash_error'] = '下载链接已过期，请刷新页面后重试';
        redirect(base_path('/admin/import.php'));
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="adminurl-import-template.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";
    echo "url,code,title,expires_at,tag\n";
    echo "https://example.com/page-a,pagea,示例标题一,,活动推广\n";
    echo "https://example.com/page-b,,示例标题二," . date('Y-m-d H:i:s', time() + 7 * 86400) . ",官网链接\n";
    exit;
}

// ---------- 导入处理 ----------
$result = null;
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'import_csv') {
    if (!verify_csrf($_POST['_csrf'] ?? null)) {
        $error = '请求已过期，请刷新页面后重试';
    } else {
        $text   = '';
        $upload = $_FILES['csv_file'] ?? null;
        $hasFile = is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($hasFile && (int)$upload['error'] !== UPLOAD_ERR_OK) {
            $error = '文件上传失败（错误码 ' . (int)$upload['error'] . '）';
        } elseif ($hasFile && (int)($upload['size'] ?? 0) > IMPORT_MAX_BYTES) {
            $error = 'CSV 文件不能超过 1 MB';
        } elseif ($hasFile && !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
            $error = '无效的文件上传';
        } elseif ($hasFile) {
            $text = (string)file_get_contents((string)$upload['tmp_name']);
        } else {
            $text = (string)($_POST['content'] ?? '');
        }

        if ($error === '' && trim($text) === '') {
            $error = '请选择 CSV 文件或粘贴 CSV 内容';
        }
        if ($error === '') {
            $parseError = '';
            $rows = parse_import_csv($text, $parseError);
            if ($parseError !== '') {
                $error = $parseError;
            } elseif (count($rows) === 0) {
                $error = '未解析出有效数据行，请检查 CSV 格式';
            } else {
                $result = import_link_rows($rows, (int)($adminUser['id'] ?? 0));
                log_event('import', '批量导入 CSV：成功 ' . $result['imported']
                    . ' 条，跳过 ' . $result['skipped'] . ' 条');
            }
        }
    }
    if ($error !== '') {
        $_SESSION['flash_error'] = $error;
    }
}

/**
 * 表头别名归一化，兼容本系统导出、YOURLS 与中文表头
 */
function import_csv_key(string $name): string
{
    $name = strtolower(trim($name));
    $aliases = [
        'url'         => 'url',
        'link'        => 'url',
        'long_url'    => 'url',
        'longurl'     => 'url',
        '目标地址'     => 'url',
        '目标网址'     => 'url',
        '长链接'       => 'url',
        'code'        => 'code',
        'keyword'     => 'code',
        'short'       => 'code',
        '短码'         => 'code',
        '短链接'       => 'code',
        'title'       => 'title',
        '标题'         => 'title',
        'expires_at'  => 'expires_at',
        'expiry'      => 'expires_at',
        'expire'      => 'expires_at',
        '有效期'       => 'expires_at',
        '过期时间'     => 'expires_at',
        'tag'         => 'tag',
        'label'       => 'tag',
        '分组'         => 'tag',
        '标签'         => 'tag',
        '分组标签'     => 'tag',
    ];
    return $aliases[$name] ?? $name;
}

/**
 * 解析 CSV 文本；首行含 url / 目标地址时视为表头，否则按 url,code,title,expires_at 顺序解析
 *
 * @return array<int, array{line:int,url:string,code:string,title:string,expires_at:string}>
 */
function parse_import_csv(string $text, string &$error): array
{
    $error = '';
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    if (strlen($text) > IMPORT_MAX_BYTES) {
        $error = 'CSV 内容不能超过 1 MB，请分批导入';
        return [];
    }
    if (function_exists('mb_check_encoding') && !mb_check_encoding($text, 'UTF-8')) {
        if (function_exists('mb_convert_encoding')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'GB18030');
        } else {
            $error = 'CSV 需使用 UTF-8 编码，请另存为 UTF-8 后重试';
            return [];
        }
    }

    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        $error = '无法读取 CSV 内容';
        return [];
    }
    fwrite($stream, $text);
    rewind($stream);

    $map  = null;
    $rows = [];
    $lineNo = 0;
    while (($cells = fgetcsv($stream, 8192, ',', '"')) !== false) {
        $lineNo++;
        $nonEmpty = array_filter($cells, static fn($v): bool => trim((string)$v) !== '');
        if (count($nonEmpty) === 0) {
            continue;
        }
        $cells = array_map(static fn($v): string => trim((string)$v), $cells);

        if ($map === null) {
            $lower = array_map(static fn($v): string => strtolower($v), $cells);
            $hasHeader = in_array('url', $lower, true)
                || in_array('目标地址', $cells, true)
                || in_array('目标网址', $cells, true);
            if ($hasHeader) {
                $map = [];
                foreach ($cells as $i => $name) {
                    $map[import_csv_key($name)] = $i;
                }
                if (!array_key_exists('url', $map)) {
                    $error = '表头缺少 url（目标地址）列';
                    break;
                }
                continue;
            }
            $map = ['url' => 0, 'code' => 1, 'title' => 2, 'expires_at' => 3, 'tag' => 4];
        }

        if (count($rows) >= IMPORT_MAX_ROWS) {
            $error = '单次最多导入 ' . IMPORT_MAX_ROWS . ' 条，请分批导入';
            break;
        }
        $get = static function (string $key) use ($cells, $map): string {
            $idx = $map[$key] ?? null;
            return $idx === null ? '' : (string)($cells[$idx] ?? '');
        };
        $rows[] = [
            'line'       => $lineNo,
            'url'        => $get('url'),
            'code'       => $get('code'),
            'title'      => $get('title'),
            'expires_at' => $get('expires_at'),
            'tag'        => $get('tag'),
        ];
    }
    fclose($stream);

    return $error === '' ? $rows : [];
}

/**
 * 校验并批量写入，单事务保证要么全部成功、要么整体回滚
 *
 * @return array{imported:int, skipped:int, errors:array, created:array}
 */
function import_link_rows(array $rows, int $userId): array
{
    $imported = 0;
    $skipped  = 0;
    $errors   = [];
    $created  = [];
    $seen     = [];
    $now      = date('Y-m-d H:i:s');

    $insert = db()->prepare(
        'INSERT INTO links (code, url, title, user_id, status, expires_at, tag, created_at, updated_at)
         VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)'
    );

    db()->beginTransaction();
    try {
        foreach ($rows as $row) {
            $line   = (int)$row['line'];
            $url    = trim((string)$row['url']);
            $code   = trim((string)$row['code']);
            $title  = trim((string)$row['title']);
            $rawExp  = trim((string)$row['expires_at']);
            $tagValue = normalize_tag((string)$row['tag']);

            if (!is_valid_url($url)) {
                $skipped++;
                $errors[] = "第 {$line} 行：目标地址不是有效的 http/https 链接";
                continue;
            }
            if ($code !== '' && !preg_match('/^[A-Za-z0-9]{3,32}$/', $code)) {
                $skipped++;
                $errors[] = "第 {$line} 行：短码仅支持 3-32 位字母或数字（{$code}）";
                continue;
            }
            if ($code !== '' && is_reserved_code($code)) {
                $skipped++;
                $errors[] = "第 {$line} 行：短码 {$code} 属于系统保留路径";
                continue;
            }
            $expiry = validate_expiry_input($rawExp);
            if (!$expiry['ok']) {
                $skipped++;
                $errors[] = "第 {$line} 行：{$expiry['error']}";
                continue;
            }

            if ($code === '') {
                $code = generate_unique_code((int)config('code_length', 5));
            } elseif (isset($seen[$code]) || link_exists($code)) {
                $skipped++;
                $errors[] = "第 {$line} 行：短码 {$code} 重复，已跳过";
                continue;
            }
            $seen[$code] = true;

            $insert->execute([
                $code, $url, mb_substr($title, 0, 200), $userId,
                $expiry['value'], $tagValue, $now, $now,
            ]);
            $imported++;
            if (count($created) < 10) {
                $created[] = $code;
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        $errors[] = '导入中断，本次未写入任何数据：' . $e->getMessage();
        return ['imported' => 0, 'skipped' => $skipped, 'errors' => $errors, 'created' => []];
    }

    if (count($errors) > 20) {
        $errors = array_slice($errors, 0, 20);
        $errors[] = '（更多提示已省略）';
    }
    return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors, 'created' => $created];
}

require __DIR__ . '/_header.php';
?>

<div class="page-head">
    <div>
        <h2>批量导入</h2>
        <p>用一份 CSV 一次创建多条短链，支持自定义短码、标题与有效期。</p>
    </div>
    <a href="<?= e(base_path('/admin/import.php?action=template&_csrf=' . urlencode(csrf_token()))) ?>"
       class="btn btn-ghost btn-sm"><?= icon('download', 'icon-sm') ?> 下载 CSV 模板</a>
</div>

<div class="import-grid">
    <div class="card">
        <div class="card-head"><h2><?= icon('upload') ?> 上传或粘贴 CSV</h2></div>
        <form method="post" enctype="multipart/form-data" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="form" value="import_csv">
            <label class="import-drop" for="import-file">
                <input type="file" name="csv_file" id="import-file" accept=".csv,text/csv">
                <?= icon('upload') ?>
                <strong>点击选择 CSV 文件</strong>
                <span>UTF-8 / GBK 编码 · 最大 1 MB · 单次最多 <?= IMPORT_MAX_ROWS ?> 条</span>
            </label>
            <p class="import-files" id="import-filename">未选择文件</p>

            <div class="form-group" style="margin-top:18px">
                <label for="import-content">或直接粘贴 CSV 内容</label>
                <textarea name="content" id="import-content" rows="9" placeholder="url,code,title,expires_at&#10;https://example.com/a,pagea,示例标题,&#10;https://example.com/b,,另一个标题,2026-12-31 23:59"></textarea>
                <p class="form-help">两者同时填写时优先使用上传的文件。</p>
            </div>
            <button type="submit" class="btn btn-primary" onclick="return confirm('确定按当前 CSV 批量创建短链吗？')">
                <?= icon('upload', 'icon-sm') ?> 开始导入
            </button>
        </form>
    </div>

    <div class="card">
        <div class="card-head"><h2><?= icon('info') ?> 格式与规则</h2></div>
        <p class="card-desc">推荐先下载模板，用 Excel / WPS 填好再上传。表头可按名称识别，列顺序不限。</p>
        <ul class="import-list">
            <li><code>url</code> 必填，必须是 http/https 链接。</li>
            <li><code>code</code> 可选，3-32 位字母数字；留空自动生成，重复或系统保留短码会跳过。</li>
            <li><code>title</code> 可选，最长 200 字。</li>
            <li><code>expires_at</code> 可选，如 <code>2026-12-31 23:59</code>；留空永久有效。</li>
            <li><code>tag</code> 可选，分组标签（最多 32 字），也支持中文表头「分组」。</li>
        </ul>
        <div class="notice-box" style="margin-top:16px">
            <p><?= icon('shield', 'icon-sm') ?> 所有行在同一个事务中写入：只要出现数据库错误，本次导入会整体回滚，不会留下半截数据。</p>
        </div>
        <p class="form-help" style="margin-top:16px">从 YOURLS 迁移已有短链请使用「数据迁移」页；本页导入的链接点击数均从 0 开始。</p>
    </div>
</div>

<?php if ($result !== null): ?>
    <div class="result-card <?= $result['imported'] > 0 ? 'success' : 'warn' ?>" style="margin-top:20px">
        <p><?= icon($result['imported'] > 0 ? 'check' : 'info', 'icon-sm') ?> <strong>导入完成：</strong>
            成功创建 <strong><?= (int)$result['imported'] ?></strong> 条，
            跳过 <strong><?= (int)$result['skipped'] ?></strong> 条。
        </p>
        <?php if (count($result['errors']) > 0): ?>
            <ul class="error-list">
                <?php foreach ($result['errors'] as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php if (count($result['created']) > 0): ?>
        <div class="card" style="margin-top:20px">
            <div class="card-head">
                <h2><?= icon('link') ?> 新生成的短链（前 <?= count($result['created']) ?> 条）</h2>
                <a href="<?= e(base_path('/admin/links.php')) ?>" class="btn btn-ghost btn-sm">前往链接管理 <?= icon('arrow-right', 'icon-sm') ?></a>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>短码</th><th>短链接</th></tr></thead>
                    <tbody>
                    <?php foreach ($result['created'] as $newCode): ?>
                        <tr>
                            <td><span class="code-chip"><?= e($newCode) ?></span></td>
                            <td class="td-url"><a href="<?= e(short_url($newCode)) ?>" target="_blank" rel="noopener"><?= e(short_url($newCode)) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
    (function () {
        var input = document.getElementById('import-file');
        var label = document.getElementById('import-filename');
        if (input && label) {
            input.addEventListener('change', function () {
                label.textContent = this.files.length ? '已选择：' + this.files[0].name : '未选择文件';
            });
        }
    })();
</script>

<?php require __DIR__ . '/_footer.php'; ?>
