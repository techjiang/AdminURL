/* ==========================================================================
   AdminURL · 全站交互（原生 JS，零依赖）
   ========================================================================== */
(function () {
    'use strict';

    var root = document.documentElement;
    var THEME_KEY = 'au-theme';

    function systemDark() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    function applyTheme(mode) {
        var dark = mode === 'dark' || (mode === 'auto' && systemDark());
        root.setAttribute('data-theme', dark ? 'dark' : 'light');
        root.setAttribute('data-theme-mode', mode);
    }

    try { applyTheme(localStorage.getItem(THEME_KEY) || 'auto'); } catch (e) { applyTheme('auto'); }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-theme-toggle]');
        if (!btn) { return; }
        var mode = root.getAttribute('data-theme-mode') || 'auto';
        var next = mode === 'auto' ? (root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark') : (mode === 'dark' ? 'light' : 'dark');
        try { localStorage.setItem(THEME_KEY, next); } catch (err) { /* private mode */ }
        applyTheme(next);
        toast(next === 'dark' ? '已切换到深色模式' : '已切换到浅色模式');
    });

    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            if ((root.getAttribute('data-theme-mode') || 'auto') === 'auto') { applyTheme('auto'); }
        });
    }

    /* ------------------------------ Toast ------------------------------ */
    function toast(message, type) {
        var stack = document.querySelector('.toast-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'toast-stack';
            document.body.appendChild(stack);
        }
        var el = document.createElement('div');
        el.className = 'toast' + (type === 'error' ? ' error' : '');
        el.innerHTML = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="' +
            (type === 'error' ? 'M12 8v5M12 17h.01M10.3 4.3 2.6 18a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 4.3a2 2 0 0 0-3.4 0Z' : 'm5 12 4 4L19 6') +
            '"/></svg><span></span>';
        el.querySelector('span').textContent = message;
        stack.appendChild(el);
        setTimeout(function () {
            el.classList.add('out');
            setTimeout(function () { el.remove(); }, 220);
        }, 2400);
    }

    window.AU = { toast: toast };

    /* --------------------------- 复制到剪贴板 --------------------------- */
    function copyText(text, btn) {
        if (!text) { return; }
        var done = function () {
            toast('已复制到剪贴板');
            if (btn && !btn.querySelector('svg')) {
                var old = btn.textContent;
                btn.textContent = '已复制';
                btn.disabled = true;
                setTimeout(function () { btn.textContent = old; btn.disabled = false; }, 1500);
            }
        };
        var fallback = function () {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); done(); } catch (err) { toast('复制失败，请手动选择', 'error'); }
            document.body.removeChild(ta);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(fallback);
        } else {
            fallback();
        }
    }

    document.addEventListener('click', function (e) {
        var urlBtn = e.target.closest('[data-copy-url]');
        if (urlBtn) { copyText(urlBtn.getAttribute('data-copy-url'), urlBtn); return; }
        var copyBtn = e.target.closest('[data-copy]');
        if (copyBtn) {
            var ref = copyBtn.getAttribute('data-copy') || '';
            if (ref.charAt(0) === '#' || ref.charAt(0) === '.') {
                var input = document.querySelector(ref);
                if (input) { copyText(input.value || input.textContent, copyBtn); }
            } else {
                copyText(ref, copyBtn);
            }
        }
    });

    document.addEventListener('click', function (e) {
        var input = e.target.closest('.result-url input, .code-chip-input');
        if (input && input.readOnly) { input.select(); }
    });

    /* ------------------------------- 弹窗 ------------------------------- */
    function closeModal(target) {
        if (target) { target.remove(); }
        var backdrop = document.querySelector('.sidebar-backdrop');
        if (backdrop) { backdrop.remove(); }
    }

    document.addEventListener('click', function (e) {
        var close = e.target.closest('[data-close]');
        if (close) {
            closeModal(document.querySelector(close.getAttribute('data-close')));
            return;
        }
        if (e.target.classList && e.target.classList.contains('modal-backdrop')) { closeModal(e.target); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) { closeModal(backdrop); }
        }
    });

    window.addEventListener('load', function () {
        var backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) {
            var input = backdrop.querySelector('input[type="text"], input[type="url"]');
            if (input) { setTimeout(function () { input.focus(); }, 120); }
        }
    });

    /* ------------------------------- Tabs ------------------------------- */
    var tabBtns = document.querySelectorAll('[data-tab]');
    Array.prototype.forEach.call(tabBtns, function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-tab');
            var group = btn.closest('.migrate-wrap, .tabs-wrap, body') || document;
            Array.prototype.forEach.call(group.querySelectorAll('.tab-panel'), function (p) { p.style.display = 'none'; });
            Array.prototype.forEach.call(group.querySelectorAll('[data-tab]'), function (b) { b.classList.remove('active'); });
            var target = document.getElementById('tab-' + id);
            if (target) { target.style.display = 'block'; }
            btn.classList.add('active');
        });
    });

    /* ---------------------------- 移动端侧栏 ---------------------------- */
    var toggle = document.querySelector('.sidebar-toggle');
    if (toggle) {
        toggle.addEventListener('click', function () {
            var sidebar = document.querySelector('.admin-sidebar, .sidebar');
            if (!sidebar) { return; }
            var open = sidebar.classList.toggle('open');
            var backdrop = document.querySelector('.sidebar-backdrop');
            if (open) {
                backdrop = document.createElement('div');
                backdrop.className = 'sidebar-backdrop';
                backdrop.addEventListener('click', function () {
                    sidebar.classList.remove('open');
                    backdrop.remove();
                });
                document.body.appendChild(backdrop);
            } else if (backdrop) {
                backdrop.remove();
            }
        });
    }

    /* ------------------------------ 快捷搜索 ---------------------------- */
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            var search = document.querySelector('.search-box input, .search-form input[name="q"]');
            if (search) { e.preventDefault(); search.focus(); search.select(); }
        }
    });

    /* --------------------------- 表单提交反馈 --------------------------- */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var btn = form.querySelector('button[type="submit"]');
        if (!btn || btn.disabled) { return; }
        var label = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span> 处理中';
        setTimeout(function () { btn.disabled = false; btn.innerHTML = label; }, 6000);
    });

    /* ------------------------------ 实时时钟 ---------------------------- */
    var clock = document.querySelector('[data-clock]');
    if (clock) {
        var tick = function () {
            var d = new Date();
            var pad = function (n) { return n < 10 ? '0' + n : String(n); };
            clock.textContent = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        };
        tick();
        setInterval(tick, 30000);
    }

    /* --------------------------- Flash 自动淡出 ------------------------- */
    var flash = document.querySelector('[data-flash]');
    if (flash) {
        setTimeout(function () {
            flash.style.transition = 'opacity .4s ease, transform .4s ease';
            flash.style.opacity = '0';
            flash.style.transform = 'translateY(-8px)';
            setTimeout(function () { flash.remove(); }, 420);
        }, 5200);
    }
})();
