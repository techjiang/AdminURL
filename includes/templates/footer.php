<?php
/**
 * AdminURL 前台页脚
 * $auLayout 由 header.php 定义：site | auth
 */
$author = require ROOT . '/includes/author.php';
$auLayout = $auLayout ?? 'site';
?>
<?php if ($auLayout === 'site'): ?>
<footer class="site-footer" id="footer">
    <div class="footer-inner">
        <p class="footer-brand">
            <strong><?= e($author['author']) ?></strong>
            <span class="footer-slogan"><?= e($author['slogan']) ?></span>
        </p>
        <div class="footer-links">
            <?php foreach ($author['links'] as $label => $url): ?>
                <a href="<?= e($url) ?>" target="_blank" rel="noopener nofollow"><?= e($label) ?></a>
            <?php endforeach; ?>
            <a href="mailto:<?= e($author['email']) ?>" rel="nofollow">邮箱联系</a>
        </div>
        <div class="footer-groups">
            <span>QQ 交流群</span>
            <?php foreach ([$author['qq'], $author['qq2'], $author['qq3']] as $num): ?>
                <button type="button" class="copy-qq" data-copy="<?= e($num) ?>" title="点击复制群号"><?= e($num) ?></button>
            <?php endforeach; ?>
        </div>
        <p class="footer-copy">
            &copy; <?= date('Y') ?> <a href="https://www.asoe.cn" target="_blank" rel="noopener nofollow"><?= e($author['author']) ?></a>
            · AdminURL 开源短链接系统
            · <a href="https://github.com/techjiang/AdminURL" target="_blank" rel="noopener nofollow">GitHub</a>
        </p>
    </div>
</footer>
</div><!-- /.page-wrap -->
<?php endif; ?>
<script src="<?= e(base_path('/assets/js/app.js?v=2.0')) ?>"></script>
</body>
</html>
