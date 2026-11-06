<?php
/**
 * AdminURL 短链标签 / 分组
 *
 * tag 为单个自由文本标签（最多 32 字），空串表示「未分组」。
 * 旧数据库由 ensure_lifecycle_schema() 自动补列，无需手工迁移。
 */

/**
 * 归一化标签输入：去掉控制字符、合并空白、截断长度
 */
function normalize_tag(string $raw): string
{
    $raw = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw) ?? $raw;
    $raw = preg_replace('/\s+/u', ' ', $raw) ?? $raw;
    return mb_substr(trim($raw), 0, 32);
}

/**
 * 标签的稳定色相（0-359），用于生成彩色标签徽章
 */
function tag_hue(string $tag): int
{
    return abs(crc32($tag)) % 360;
}

/**
 * 所有标签及其汇总（含未分组）
 *
 * @return array<int, array{tag:string,links:int,clicks:int,expired:int,disabled:int}>
 */
function tag_list(): array
{
    try {
        $stmt = db()->prepare(
            "SELECT tag,
                    COUNT(*) AS links,
                    COALESCE(SUM(clicks), 0) AS clicks,
                    SUM(CASE WHEN expires_at IS NOT NULL AND expires_at <> '' AND expires_at <= ? THEN 1 ELSE 0 END) AS expired,
                    SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS disabled
             FROM links
             GROUP BY tag
             ORDER BY links DESC, tag ASC"
        );
        $stmt->execute([date('Y-m-d H:i:s')]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * 标签总览：分组数、已打标链接数
 *
 * @return array{groups:int, tagged:int}
 */
function tag_summary(): array
{
    try {
        return [
            'groups' => (int)db()->query("SELECT COUNT(DISTINCT tag) FROM links WHERE tag <> ''")->fetchColumn(),
            'tagged' => (int)db()->query("SELECT COUNT(*) FROM links WHERE tag <> ''")->fetchColumn(),
        ];
    } catch (Throwable $e) {
        return ['groups' => 0, 'tagged' => 0];
    }
}
