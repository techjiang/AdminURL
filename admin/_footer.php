<?php
/**
 * AdminURL 后台页脚（含完整作者信息，符合开源项目惯例）
 */
$author = require ROOT . '/includes/author.php';
?>
        </div><!-- /.admin-content -->

        <footer class="admin-footer">
            <div class="admin-footer-author">
                <span class="admin-footer-name"><?= e($author['author']) ?></span>
                <span class="admin-footer-slogan"><?= e($author['slogan']) ?></span>
            </div>
            <div class="admin-footer-links">
                <?php foreach ($author['links'] as $label => $url): ?>
                    <a href="<?= e($url) ?>" target="_blank" rel="noopener nofollow"><?= e($label) ?></a>
                <?php endforeach; ?>
                <a href="mailto:<?= e($author['email']) ?>" rel="nofollow">邮箱联系</a>
            </div>
            <div class="admin-footer-groups">
                <span>QQ群</span>
                <?php foreach (['qq' => $author['qq'], 'qq2' => $author['qq2'], 'qq3' => $author['qq3']] as $key => $num): ?>
                    <button type="button" class="copy-qq" data-copy="<?= e($num) ?>" title="点击复制群号"><?= e($num) ?></button>
                <?php endforeach; ?>
            </div>
            <p class="admin-footer-copy">
                &copy; <?= date('Y') ?> <a href="https://www.asoe.cn" target="_blank" rel="noopener nofollow"><?= e($author['author']) ?></a> ·
                AdminURL 开源短链接系统 ·
                <a href="https://github.com/techjiang/AdminURL" target="_blank" rel="noopener nofollow">GitHub 仓库</a>
            </p>
        </footer>
    </main>
</div><!-- /.admin-shell -->
<script src="<?= e(base_path('/assets/js/app.js?v=1.0')) ?>"></script>
</body>
</html>