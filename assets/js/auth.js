/**
 * Asset-UDO · Acceso: partículas, login (AJAX) y asistente de registro.
 * Las reglas de validación están en una sola tabla (RULES) y se aplican a todos los campos.
 */
(function () {
    'use strict';

    var $ = function (id) { return document.getElementById(id); };
    var LETRAS = /^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/;

    /* ============================================================
       Partículas de fondo
       ============================================================ */
    function particles() {
        var box = document.querySelector('.auth-particles');
        if (!box) return;
        for (var i = 0; i < 22; i++) {
            var s = document.createElement('span');
            s.style.cssText = '--x:' + Math.random() * 100 + '%;--s:' + (2 + Math.random() * 4) + 'px;' +
                '--d:' + (10 + Math.random() * 14) + 's;--delay:-' + Math.random() * 20 + 's;--dx:' + (Math.random() * 80 - 40) + 'px';
            box.appendChild(s);
        }
    }

    /* ============================================================
       Inicio de sesión (AJAX)
       ============================================================ */
    function initLogin() {
        var form = $('loginForm');
        if (!form) return;
        var card = document.querySelector('.auth-card');
        var alertBox = $('loginAlert');
        var btn = $('loginBtn');
        var label = btn.innerHTML;
        var hideTimer;

        function showError(msg) {
            clearTimeout(hideTimer);
            alertBox.innerHTML = '<i class="fas fa-circle-exclamation me-2"></i>';
            alertBox.appendChild(document.createTextNode(msg));
            alertBox.classList.add('show');
            card.classList.remove('shake'); void card.offsetWidth; card.classList.add('shake');
            hideTimer = setTimeout(function () { alertBox.classList.remove('show'); }, 5000);
        }
        function reset() { btn.disabled = false; btn.innerHTML = label; }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-circle-notch fa-spin me-2"></i>Verificando...';

            fetch('login.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(form) })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.success) { reset(); showError(data.message || 'No se pudo iniciar sesión.'); return; }
                    btn.classList.add('ok');
                    btn.innerHTML = '<i class="fas fa-check-circle me-2"></i>¡Bienvenido!';
                    card.style.transition = 'transform .6s ease, opacity .6s ease';
                    setTimeout(function () { card.style.transform = 'scale(.97)'; card.style.opacity = '.6'; }, 250);
                    setTimeout(function () { window.location.href = data.redirect; }, 650);
                })
                .catch(function () { reset(); showError('Error de conexión con el servidor.'); });
        });
    }

    /* ============================================================
       Registro: reglas de validación (una sola tabla)
       ============================================================ */
    var RULES = {
        reg_nombre:   { filter: function (v) { return v.replace(/[^a-zA-ZáéíóúÁÉÍÓÚñÑ\s]/g, ''); },
                        empty: 'El nombre es obligatorio.', ok: function (v) { return LETRAS.test(v); }, bad: 'Solo letras y espacios.' },
        reg_cedula:   { filter: function (v) { return v.replace(/\D/g, '').slice(0, 8); },
                        empty: 'La cédula es obligatoria.', ok: function (v) { return v.length <= 8; }, bad: 'Máximo 8 dígitos.',
                        remote: { param: 'check_cedula', key: 'cedula', taken: 'Esta cédula ya está registrada.' } },
        reg_fecha_nac:{ filter: maskFecha, optional: true, ok: fechaValida,
                        bad: 'Fecha no válida. Use DD-MM-AAAA (ej: 20-05-1990).' },
        reg_telefono: { filter: function (v) { return v.replace(/\D/g, '').slice(0, 11); },
                        empty: 'El teléfono es obligatorio.', ok: function (v) { return v.length === 11; }, bad: 'Deben ser exactamente 11 dígitos.' },
        reg_email:    { empty: 'El correo es obligatorio.', ok: function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v); }, bad: 'Ingrese un correo válido.',
                        remote: { param: 'check_email', key: 'email', taken: 'Este correo ya está registrado.' } },
        reg_cargo:    { empty: 'El cargo es obligatorio.', ok: function (v) { return /^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s\-]+$/.test(v); }, bad: 'Solo letras, espacios y guiones.' },
        reg_rol:      { empty: 'Seleccione un rol.', ok: function () { return true; } },
        reg_clave:    { raw: true, empty: 'La contraseña es obligatoria.', ok: function (v) { return v.length >= 6; }, bad: 'Mínimo 6 caracteres.' },
        reg_confirm:  { raw: true, empty: 'Confirme su contraseña.', ok: function (v) { return v === $('reg_clave').value; }, bad: 'Las contraseñas no coinciden.' }
    };
    [1, 2, 3].forEach(function (n) {
        RULES['reg_pregunta' + n]  = { empty: 'Seleccione la pregunta ' + n + '.', ok: function () { return true; } };
        RULES['reg_respuesta' + n] = { empty: 'Responda la pregunta ' + n + '.', ok: function (v) { return v.length >= 2; }, bad: 'Mínimo 2 caracteres.' };
    });

    var STEPS = [
        ['reg_nombre', 'reg_cedula', 'reg_fecha_nac', 'reg_telefono'],
        ['reg_email', 'reg_cargo', 'reg_rol', 'reg_clave', 'reg_confirm'],
        ['reg_pregunta1', 'reg_respuesta1', 'reg_pregunta2', 'reg_respuesta2', 'reg_pregunta3', 'reg_respuesta3']
    ];

    function maskFecha(v) {
        var n = v.replace(/\D/g, '').slice(0, 8), out = n.slice(0, 2);
        if (n.length > 2) out += '-' + n.slice(2, 4);
        if (n.length > 4) out += '-' + n.slice(4, 8);
        return out;
    }
    function fechaValida(v) {
        var m = /^(\d{2})-(\d{2})-(\d{4})$/.exec(v);
        if (!m) return false;
        var d = +m[1], mo = +m[2], y = +m[3];
        return y >= 1900 && y <= new Date().getFullYear() && mo >= 1 && mo <= 12 && d >= 1 && d <= new Date(y, mo, 0).getDate();
    }

    function setState(input, message) {
        var err = document.querySelector('.field-error[data-for="' + input.id + '"]');
        input.classList.toggle('is-invalid', !!message);
        input.classList.toggle('is-valid', !message && input.value !== '');
        if (err) { err.textContent = message || ''; err.classList.toggle('show', !!message); }
    }

    var remoteCache = {};
    function checkRemote(rule, value) {
        var cacheKey = rule.remote.param + ':' + value;
        if (cacheKey in remoteCache) return Promise.resolve(remoteCache[cacheKey]);
        return fetch('login.php?' + rule.remote.param + '=1&' + rule.remote.key + '=' + encodeURIComponent(value))
            .then(function (r) { return r.text(); })
            .then(function (t) { return (remoteCache[cacheKey] = t.trim() === 'existe'); })
            .catch(function () { return false; });   // si falla la red, el servidor valida de nuevo al enviar
    }

    /** Valida un campo; devuelve Promise<boolean>. */
    function validateField(id) {
        var input = $(id), rule = RULES[id];
        if (!input || !rule) return Promise.resolve(true);
        var value = rule.raw ? input.value : input.value.trim();

        if (value === '') {
            if (rule.optional) { setState(input, ''); input.classList.remove('is-valid'); return Promise.resolve(true); }
            setState(input, rule.empty); return Promise.resolve(false);
        }
        if (!rule.ok(value)) { setState(input, rule.bad || rule.empty); return Promise.resolve(false); }
        if (!rule.remote) { setState(input, ''); return Promise.resolve(true); }

        return checkRemote(rule, value).then(function (taken) {
            setState(input, taken ? rule.remote.taken : '');
            return !taken;
        });
    }

    function validateAll(ids) {
        return Promise.all(ids.map(validateField)).then(function (r) { return r.every(Boolean); });
    }

    /* ============================================================
       Registro: asistente por pasos
       ============================================================ */
    function initRegister() {
        var form = $('formRegistro');
        if (!form) return;
        var panes = form.querySelectorAll('.wiz-pane');
        var dots = document.querySelectorAll('.wiz-dot');
        var lines = document.querySelectorAll('.wiz-line');
        var labels = document.querySelectorAll('.wiz-labels span');
        var btnBack = $('wizBack'), btnNext = $('wizNext'), btnSend = $('wizSend');
        var current = 0;

        function go(step) {
            var back = step < current;
            current = step;
            panes.forEach(function (p, i) { p.classList.toggle('active', i === step); p.classList.toggle('back', back); });
            dots.forEach(function (d, i) { d.classList.toggle('active', i === step); d.classList.toggle('done', i < step); });
            lines.forEach(function (l, i) { l.classList.toggle('fill', i < step); });
            labels.forEach(function (l, i) { l.classList.toggle('active', i === step); });
            btnBack.style.visibility = step === 0 ? 'hidden' : 'visible';
            btnNext.classList.toggle('d-none', step === panes.length - 1);
            btnSend.classList.toggle('d-none', step !== panes.length - 1);
            var first = panes[step].querySelector('input:not([type=file]), select');
            if (first) setTimeout(function () { first.focus({ preventScroll: true }); }, 250);
        }

        btnNext.addEventListener('click', function () {
            validateAll(STEPS[current]).then(function (ok) { if (ok) go(current + 1); });
        });
        btnBack.addEventListener('click', function () { go(current - 1); });

        // Filtros y validación en vivo
        Object.keys(RULES).forEach(function (id) {
            var input = $(id), rule = RULES[id];
            if (!input) return;
            input.addEventListener('input', function () {
                if (rule.filter) { var f = rule.filter(input.value); if (f !== input.value) input.value = f; }
                validateField(id);
                if (id === 'reg_clave') { strength(); if ($('reg_confirm').value) validateField('reg_confirm'); }
            });
            input.addEventListener('blur', function () { validateField(id); });
            input.addEventListener('change', function () { validateField(id); });
        });

        // Una pregunta de seguridad no se puede repetir
        var selects = [1, 2, 3].map(function (n) { return $('reg_pregunta' + n); });
        selects.forEach(function (sel) {
            sel.addEventListener('change', function () {
                var used = selects.map(function (s) { return s.value; }).filter(Boolean);
                selects.forEach(function (s) {
                    Array.prototype.forEach.call(s.options, function (o) { o.disabled = o.value !== '' && o.value !== s.value && used.indexOf(o.value) !== -1; });
                });
            });
        });

        // Medidor de fuerza
        function strength() {
            var v = $('reg_clave').value, bar = document.querySelector('.pw-meter i');
            var score = (v.length >= 6) + (v.length >= 10) + /[A-Z]/.test(v) + /\d/.test(v) + /[^A-Za-z0-9]/.test(v);
            var colors = ['#f43f5e', '#f97316', '#eab308', '#22d3ee', '#10b981'];
            bar.style.width = (v ? Math.max(score, 1) * 20 : 0) + '%';
            bar.style.background = colors[Math.max(score - 1, 0)];
        }

        // Envío: valida todo, vuelve al primer paso con errores y manda el formulario con "registrar"
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var chain = Promise.resolve(-1);
            STEPS.forEach(function (ids, i) {
                chain = chain.then(function (bad) {
                    return validateAll(ids).then(function (ok) { return bad === -1 && !ok ? i : bad; });
                });
            });
            chain.then(function (firstBad) {
                if (firstBad !== -1) { go(firstBad); return; }
                var flag = document.createElement('input');
                flag.type = 'hidden'; flag.name = 'registrar'; flag.value = '1';
                form.appendChild(flag);
                btnSend.disabled = true;
                btnSend.innerHTML = '<i class="fas fa-circle-notch fa-spin me-2"></i>Enviando...';
                form.submit();
            });
        });

        // Al cerrar el modal el asistente vuelve al primer paso
        $('modalRegistro').addEventListener('hidden.bs.modal', function () { go(0); });
        go(0);
    }

    /** Botón ver/ocultar contraseña: <button class="pw-eye" data-pw="id_del_input"> */
    function initPasswordEyes() {
        document.addEventListener('click', function (e) {
            var eye = e.target.closest('.pw-eye[data-pw]');
            if (!eye) return;
            var input = $(eye.getAttribute('data-pw')), show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            eye.innerHTML = '<i class="fas ' + (show ? 'fa-eye-slash' : 'fa-eye') + '"></i>';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        particles();
        initPasswordEyes();
        initLogin();
        initRegister();
    });
})();
