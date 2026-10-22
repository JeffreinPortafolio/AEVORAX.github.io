/**
 * Asset-UDO: Theme Toggle Module
 * Implementa cambio de tema claro/oscuro compatible con Bootstrap 5.3 data-bs-theme.
 * Libre de FOUC, accesible, sincronizado entre pestañas y con el sistema operativo.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'udo-theme';
    const root = document.documentElement;
    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

    function getStoredTheme() {
        try {
            return localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            return null;
        }
    }

    function getSystemTheme() {
        return mediaQuery.matches ? 'dark' : 'light';
    }

    function getActiveTheme() {
        return root.getAttribute('data-bs-theme') || getStoredTheme() || getSystemTheme();
    }

    function updateIconsAndAria(theme) {
        const isDark = theme === 'dark';
        const buttons = document.querySelectorAll('[data-theme-toggle]');
        buttons.forEach((btn) => {
            btn.setAttribute('aria-pressed', String(isDark));
            btn.setAttribute(
                'aria-label',
                isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'
            );
            btn.setAttribute(
                'title',
                isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'
            );

            // Soporte para FontAwesome (fas fa-sun / fas fa-moon)
            const faIcon = btn.querySelector('.fa-sun, .fa-moon');
            if (faIcon) {
                if (isDark) {
                    faIcon.classList.remove('fa-moon');
                    faIcon.classList.add('fa-sun');
                } else {
                    faIcon.classList.remove('fa-sun');
                    faIcon.classList.add('fa-moon');
                }
            }

            // Soporte para Bootstrap Icons (bi bi-sun / bi bi-moon)
            const biIcon = btn.querySelector('.bi-sun-fill, .bi-moon-stars-fill, .bi-sun, .bi-moon');
            if (biIcon) {
                biIcon.className = isDark ? 'bi bi-sun-fill' : 'bi bi-moon-stars-fill';
            }
        });
    }

    function applyTheme(theme, save = false) {
        const targetTheme = (theme === 'dark' || theme === 'light') ? theme : getSystemTheme();
        root.setAttribute('data-bs-theme', targetTheme);
        if (save) {
            try {
                localStorage.setItem(STORAGE_KEY, targetTheme);
            } catch (e) {}
        }
        updateIconsAndAria(targetTheme);
    }

    function toggleTheme() {
        const current = getActiveTheme();
        const next = current === 'dark' ? 'light' : 'dark';
        applyTheme(next, true);
    }

    // Escuchar cambios de preferencia en el sistema operativo
    if (mediaQuery.addEventListener) {
        mediaQuery.addEventListener('change', (e) => {
            const stored = getStoredTheme();
            if (!stored) {
                applyTheme(e.matches ? 'dark' : 'light', false);
            }
        });
    }

    // Sincronizar entre pestañas
    window.addEventListener('storage', (e) => {
        if (e.key === STORAGE_KEY && e.newValue) {
            applyTheme(e.newValue, false);
        }
    });

    // Inicializar eventos en el DOM
    document.addEventListener('DOMContentLoaded', () => {
        const current = getActiveTheme();
        updateIconsAndAria(current);

        document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
            btn.removeEventListener('click', toggleTheme);
            btn.addEventListener('click', toggleTheme);
        });
    });

    // Exponer globalmente para invocaciones programáticas si fuera necesario
    window.udoTheme = {
        get: getActiveTheme,
        set: (t) => applyTheme(t, true),
        toggle: toggleTheme
    };
})();
