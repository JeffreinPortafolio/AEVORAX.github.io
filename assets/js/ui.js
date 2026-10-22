/**
 * Asset-UDO · Interfaz (sin dependencias)
 * Todo es opcional: si algo falla, el sistema sigue funcionando.
 * Módulos: tablas · contadores · menú lateral · alertas · efectos · formularios
 */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

    /* ---------- Tablas ---------- */
    function hasScrollParent(el) {
        var p = el.parentElement;
        for (var i = 0; i < 3 && p && p !== document.body; i++, p = p.parentElement) {
            if (p.classList.contains('table-scroll') || p.classList.contains('table-responsive')) return true;
            var oy = getComputedStyle(p).overflowY;
            if (oy === 'auto' || oy === 'scroll') { el.classList.add('table-in-scroll'); return true; }
        }
        return false;
    }

    function wrapTables(root) {
        $$('.content-body table.table, .modal table.table', root).forEach(function (t) {
            if (t.dataset.uiWrapped || t.classList.contains('no-wrap') || hasScrollParent(t)) return;
            var wrap = document.createElement('div');
            wrap.className = 'table-scroll';
            t.parentNode.insertBefore(wrap, t);
            wrap.appendChild(t);
            t.dataset.uiWrapped = '1';
        });
    }

    function stagger(selector, getItems, max) {
        $$(selector).forEach(function (box) {
            getItems(box).slice(0, max).forEach(function (el, i) { el.style.setProperty('--i', i); });
        });
    }

    /* ---------- Contadores animados ---------- */
    function countUp() {
        $$('[data-count]').forEach(function (el) {
            var target = parseInt(el.getAttribute('data-count'), 10);
            if (isNaN(target)) return;
            if (reduceMotion || target === 0) { el.textContent = target.toLocaleString('es-VE'); return; }
            var start = null, dur = 900;
            (function step(ts) {
                if (start === null) start = ts;
                var p = Math.min((ts - start) / dur, 1);
                el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))).toLocaleString('es-VE');
                if (p < 1) requestAnimationFrame(step);
            })(performance.now());
        });
    }

    /* ---------- Menú lateral ---------- */
    function sidebar() {
        var sb = document.getElementById('sidebar');
        if (!sb) return;
        var KEY = 'sidebarScroll';

        function setOpen(open) {
            sb.classList.toggle('active', open);
            document.body.classList.toggle('sidebar-open', open);
        }

        $$('[data-sidebar-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () { setOpen(!sb.classList.contains('active')); });
        });
        document.addEventListener('click', function (e) {
            if (window.innerWidth > 991 || !sb.classList.contains('active')) return;
            if (!sb.contains(e.target) && !e.target.closest('[data-sidebar-toggle]')) setOpen(false);
        });
        sb.addEventListener('click', function (e) {
            if (window.innerWidth <= 991 && e.target.closest('a.nav-link')) setOpen(false);
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
        window.addEventListener('resize', function () { if (window.innerWidth > 991) setOpen(false); });

        // Guardar la posición del menú: al hacer scroll (con rAF) y justo antes de salir de la página.
        // La restauración la hace un script en línea en header.php, antes del primer pintado.
        function guardar() {
            try { sessionStorage.setItem(KEY, String(sb.scrollTop)); } catch (err) { /* sin sessionStorage */ }
        }
        var tick = false;
        sb.addEventListener('scroll', function () {
            if (tick) return;
            tick = true;
            requestAnimationFrame(function () { guardar(); tick = false; });
        }, { passive: true });
        sb.addEventListener('click', function (e) { if (e.target.closest('a.nav-link')) guardar(); });
        window.addEventListener('pagehide', guardar);
    }

    /* ---------- Alertas temporales ---------- */
    function autoDismissAlerts() {
        setTimeout(function () {
            $$('.content-body .alert-success:not(.alert-permanent), .content-body .alert-danger:not(.alert-permanent)').forEach(function (el) {
                if (el.closest('.modal') || el.offsetHeight === 0) return; // los avisos de modales los controla cada página
                el.style.overflow = 'hidden';
                el.style.transition = 'opacity .4s ease, max-height .4s ease, margin .4s ease, padding .4s ease';
                el.style.maxHeight = el.offsetHeight + 'px';
                void el.offsetHeight; // fuerza el cálculo para animar el colapso
                el.style.opacity = '0';
                el.style.maxHeight = '0';
                el.style.marginTop = el.style.marginBottom = '0';
                el.style.paddingTop = el.style.paddingBottom = '0';
                setTimeout(function () { el.remove(); }, 450);
            });
        }, 6000);
    }

    /* ---------- Efectos ---------- */
    function ripple() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn');
            if (!btn || reduceMotion || btn.disabled) return;
            var r = btn.getBoundingClientRect(), size = Math.max(r.width, r.height) * 2;
            var dot = document.createElement('span');
            dot.className = 'ui-ripple';
            dot.style.cssText = 'width:' + size + 'px;height:' + size + 'px;left:' + (e.clientX - r.left - size / 2) + 'px;top:' + (e.clientY - r.top - size / 2) + 'px';
            btn.appendChild(dot);
            setTimeout(function () { dot.remove(); }, 700);
        });
    }

    function spotlight() {
        if (reduceMotion || (window.matchMedia && window.matchMedia('(hover: none)').matches)) return; // no en pantallas táctiles
        var pend = null, raf = false;
        document.addEventListener('pointermove', function (e) {
            var card = e.target.closest && e.target.closest('.card');
            if (!card) return;
            pend = { card: card, x: e.clientX, y: e.clientY };
            if (raf) return;
            raf = true;
            requestAnimationFrame(function () {
                raf = false;
                if (!pend) return;
                var r = pend.card.getBoundingClientRect();
                pend.card.style.setProperty('--mx', (pend.x - r.left) + 'px');
                pend.card.style.setProperty('--my', (pend.y - r.top) + 'px');
            });
        }, { passive: true });
    }

    function reveal() {
        var items = $$('[data-reveal]');
        if (!items.length) return;
        if (!('IntersectionObserver' in window) || reduceMotion) {
            items.forEach(function (el) { el.classList.add('is-visible'); });
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) { en.target.classList.add('is-visible'); io.unobserve(en.target); }
            });
        }, { threshold: .12 });
        items.forEach(function (el) { io.observe(el); });
    }

    /* ---------- Formularios ---------- */
    function markRequired() {
        $$('input[required], select[required], textarea[required]').forEach(function (field) {
            if (!field.id) return;
            var label = document.querySelector('label[for="' + field.id + '"]');
            if (label) label.classList.add('is-required');
        });
    }

    function passwordToggles() {
        $$('input[type="password"].form-control').forEach(function (input) {
            if (input.closest('.pw-wrap') || input.closest('.input-group')) return;
            var wrap = document.createElement('div');
            wrap.className = 'pw-wrap';
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);
            var eye = document.createElement('button');
            eye.type = 'button';
            eye.className = 'pw-eye';
            eye.setAttribute('aria-label', 'Mostrar u ocultar contraseña');
            eye.innerHTML = '<i class="fas fa-eye"></i>';
            eye.addEventListener('click', function () {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                eye.firstChild.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
            });
            wrap.appendChild(eye);
        });
    }

    /** Filtros de escritura declarativos: <input data-filter="digits|letters|letters-dash|alnum-dot|alnum-dash"> */
    var FILTERS = {
        'digits':       /[^0-9]/g,
        'letters':      /[^a-zA-ZáéíóúÁÉÍÓÚñÑ\s]/g,
        'letters-dash': /[^a-zA-ZáéíóúÁÉÍÓÚñÑ\s\-]/g,
        'alnum-dot':    /[^a-zA-Z0-9\s\-\.]/g,
        'alnum-dash':   /[^a-zA-Z0-9\s\-]/g
    };
    function inputFilters() {
        document.addEventListener('input', function (e) {
            var rule = e.target.getAttribute && FILTERS[e.target.getAttribute('data-filter')];
            if (!rule) return;
            var clean = e.target.value.replace(rule, '');
            if (clean !== e.target.value) e.target.value = clean;
        });
    }

    /** Evita envíos dobles: el botón de envío muestra un spinner mientras se procesa. */
    function submitLoading() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (e.defaultPrevented || form.hasAttribute('data-no-loading') || !form.checkValidity()) return;
            var btn = e.submitter || form.querySelector('button[type="submit"]');
            if (!btn || btn.classList.contains('is-loading')) return;
            setTimeout(function () { btn.classList.add('is-loading'); }, 0);
        });
        window.addEventListener('pageshow', function () {
            $$('.btn.is-loading').forEach(function (b) { b.classList.remove('is-loading'); });
        });
    }

    /* ---------- Arranque ---------- */
    function ready(fn) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
        else fn();
    }

    ready(function () {
        var steps = [
            wrapTables,
            function () { stagger('table.table tbody', function (tb) { return Array.prototype.slice.call(tb.rows); }, 14); },
            function () { stagger('.inv-list', function (l) { return Array.prototype.slice.call(l.children); }, 16); },
            countUp, sidebar, autoDismissAlerts, ripple, spotlight, reveal,
            markRequired, passwordToggles, inputFilters, submitLoading
        ];
        steps.forEach(function (step) {
            try { step(); } catch (err) { if (window.console) console.warn('ui.js:', err); }
        });
    });

    window.UDOUI = { wrapTables: wrapTables };
})();
