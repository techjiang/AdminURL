<?php
/**
 * AdminURL 后台布局 · 页脚
 */
$author = require ROOT . '/includes/author.php';
?>
        </div><!-- /.admin-content -->

        <footer class="admin-footer">
            <div>
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
                <span>QQ 群</span>
                <?php foreach ([$author['qq'], $author['qq2'], $author['qq3']] as $num): ?>
                    <button type="button" class="copy-qq" data-copy="<?= e($num) ?>" title="点击复制群号"><?= e($num) ?></button>
                <?php endforeach; ?>
            </div>
            <p style="width:100%;margin:0;padding-top:12px;border-top:1px solid var(--border)">
                &copy; <?= date('Y') ?> <a href="https://www.asoe.cn" target="_blank" rel="noopener nofollow"><?= e($author['author']) ?></a>
                · AdminURL v<?= e(ADMINURL_VERSION) ?> 开源短链接系统
                · <a href="https://github.com/techjiang/AdminURL" target="_blank" rel="noopener nofollow">GitHub</a>
            </p>
        </footer>
    </main>
</div><!-- /.admin-shell -->
<script src="<?= e(base_path('/assets/js/app.js?v=2.0')) ?>"></script>
</body>
</html>
