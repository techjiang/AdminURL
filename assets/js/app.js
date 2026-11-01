/**
 * AdminURL 前端交互
 *
 * 全部使用原生 JavaScript，不依赖任何第三方库。
 */
(function () {
    'use strict';

    // ---------- 复制到剪贴板 ----------
    function copyText(text, btn) {
        var done = function () {
            var old = btn.textContent;
            btn.textContent = '已复制 ✓';
            btn.disabled = true;
            setTimeout(function () {
                btn.textContent = old;
                btn.disabled = false;
            }, 1400);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () {
                fallbackCopy(text, done);
            });
        } else {
            fallbackCopy(text, done);
        }
    }

    function fallbackCopy(text, done) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            done();
        } catch (e) {
            /* ignore */
        }
        document.body.removeChild(ta);
    }

    document.addEventListener('click', function (e) {
        var copyBtn = e.target.closest('[data-copy]');
        var copyUrl = e.target.closest('[data-copy-url]');

        if (copyBtn) {
            var ref = copyBtn.getAttribute('data-copy');
            // 纯文本（如 QQ 群号）直接复制；带 #/./ 前缀则当作元素选择器读取值
            if (ref && (ref.charAt(0) === '#' || ref.charAt(0) === '.')) {
                var input = document.querySelector(ref);
                if (input) {
                    copyText(input.value, copyBtn);
                }
            } else if (ref) {
                copyText(ref, copyBtn);
            }
        }
        if (copyUrl) {
            copyText(copyUrl.getAttribute('data-copy-url'), copyUrl);
        }
    });

    // ---------- 弹窗 ----------
    document.addEventListener('click', function (e) {
        var close = e.target.closest('[data-close]');
        if (!close) {
            return;
        }
        var target = document.querySelector(close.getAttribute('data-close'));
        if (target) {
            target.remove();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) {
                backdrop.remove();
            }
        }
    });

    // 打开编辑弹窗后聚焦短码输入框
    window.addEventListener('load', function () {
        var backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) {
            var input = backdrop.querySelector('input');
            if (input) {
                setTimeout(function () {
                    input.focus();
                }, 100);
            }
        }
    });

    // ---------- 迁移页 Tabs ----------
    var tabBtns = document.querySelectorAll('.tab-btns .btn[data-tab]');
    tabBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-tab');
            document.querySelectorAll('.tab-panel').forEach(function (p) {
                p.style.display = 'none';
            });
            document.querySelectorAll('.tab-btns .btn').forEach(function (b) {
                b.classList.remove('active');
            });
            var target = document.getElementById('tab-' + id);
            if (target) {
                target.style.display = 'block';
            }
            btn.classList.add('active');
        });
    });

    // ---------- 后台移动端侧栏 ----------
    var toggle = document.querySelector('.sidebar-toggle');
    if (toggle) {
        toggle.addEventListener('click', function () {
            document.querySelector('.admin-sidebar').classList.toggle('open');
        });
    }

    // 页码跳转（链接列表顶部快捷跳页）
    var pageJump = document.querySelector('.pagination .page-jump');
    if (pageJump) {
        pageJump.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                var base = pageJump.getAttribute('data-base');
                if (base) {
                    window.location = base + '&p=' + encodeURIComponent(pageJump.value);
                }
            }
        });
    }
})();