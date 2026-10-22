/**
 * Asset-UDO: Global CSRF Handler
 * Inyecta automáticamente el token CSRF en todos los formularios POST,
 * llamadas fetch() y peticiones AJAX (jQuery / XMLHttpRequest).
 */
(function () {
    'use strict';

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function ensureFormCsrf(form) {
        if (!form || (form.method || '').toUpperCase() !== 'POST') return;
        const token = getCsrfToken();
        if (!token) return;

        let input = form.querySelector('input[name="csrf_token"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            form.prepend(input);
        }
        input.value = token;
    }

    function setupForms() {
        document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(ensureFormCsrf);
    }

    // Interceptar envíos de formularios para garantizar que lleven el token
    document.addEventListener('submit', function (e) {
        if (e.target && e.target.tagName === 'FORM') {
            ensureFormCsrf(e.target);
        }
    }, true);

    // Patch global para window.fetch
    const originalFetch = window.fetch;
    if (typeof originalFetch === 'function') {
        window.fetch = function (resource, init) {
            init = init || {};
            const method = (init.method || 'GET').toUpperCase();
            if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
                const token = getCsrfToken();
                if (token) {
                    init.headers = init.headers || {};
                    if (init.headers instanceof Headers) {
                        if (!init.headers.has('X-CSRF-TOKEN')) {
                            init.headers.set('X-CSRF-TOKEN', token);
                        }
                    } else if (Array.isArray(init.headers)) {
                        const hasToken = init.headers.some(([k]) => k.toLowerCase() === 'x-csrf-token');
                        if (!hasToken) init.headers.push(['X-CSRF-TOKEN', token]);
                    } else {
                        if (!init.headers['X-CSRF-TOKEN'] && !init.headers['x-csrf-token']) {
                            init.headers['X-CSRF-TOKEN'] = token;
                        }
                    }

                    // Si el body es FormData, añadir el token también en FormData si falta
                    if (init.body && typeof FormData !== 'undefined' && init.body instanceof FormData) {
                        if (!init.body.has('csrf_token')) {
                            init.body.append('csrf_token', token);
                        }
                    }
                }
            }
            return originalFetch.call(this, resource, init);
        };
    }

    // Patch global para XMLHttpRequest
    const originalOpen = XMLHttpRequest.prototype.open;
    const originalSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) {
        this._method = (method || 'GET').toUpperCase();
        return originalOpen.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function (body) {
        if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(this._method)) {
            const token = getCsrfToken();
            if (token) {
                try {
                    this.setRequestHeader('X-CSRF-TOKEN', token);
                } catch (e) {}
                if (body && typeof FormData !== 'undefined' && body instanceof FormData) {
                    if (!body.has('csrf_token')) {
                        body.append('csrf_token', token);
                    }
                }
            }
        }
        return originalSend.apply(this, arguments);
    };

    // Soporte jQuery $.ajaxSend si jQuery se carga después
    function hookJQuery() {
        if (window.jQuery && window.jQuery.ajaxSend) {
            window.jQuery(document).ajaxSend(function (event, jqXHR, settings) {
                const method = (settings.type || 'GET').toUpperCase();
                if (['POST', 'PUT', 'DELETE', 'PATCH'].includes(method)) {
                    const token = getCsrfToken();
                    if (token) {
                        jqXHR.setRequestHeader('X-CSRF-TOKEN', token);
                    }
                }
            });
            return true;
        }
        return false;
    }

    document.addEventListener('DOMContentLoaded', function () {
        setupForms();
        if (!hookJQuery()) {
            setTimeout(hookJQuery, 500);
        }
    });

    window.getCsrfToken = getCsrfToken;
})();
