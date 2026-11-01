<?php
/**
 * AdminURL 页脚（前台页面）
 *
 * 展示作者信息与相关链接，符合开源项目惯例。
 */
$author = require ROOT . '/includes/author.php';
?>
<footer class="site-footer">
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
        <div class="footer-groups" id="qq-groups">
            <span>QQ群</span>
            <?php foreach (['qq' => $author['qq'], 'qq2' => $author['qq2'], 'qq3' => $author['qq3']] as $key => $num): ?>
                <button type="button" class="copy-qq" data-copy="<?= e($num) ?>" title="点击复制群号"><?= e($num) ?></button>
            <?php endforeach; ?>
        </div>
        <p class="footer-copy">
            &copy; <?= date('Y') ?> <a href="https://www.asoe.cn" target="_blank" rel="noopener nofollow"><?= e($author['author']) ?></a> ·
            AdminURL 开源短链接系统 ·
            <a href="https://github.com/techjiang/AdminURL" target="_blank" rel="noopener nofollow">GitHub 仓库</a>
        </p>
    </div>
</footer>
</div><!-- /.page-wrap -->
<script src="<?= e(base_path('/assets/js/app.js?v=1.0')) ?>"></script>
</body>
</html>