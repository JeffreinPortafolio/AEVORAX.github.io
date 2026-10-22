<?php
// Anti-FOUC + CSRF Meta + Theme CSS (Compartido por header.php y páginas de autenticación)
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<meta name="color-scheme" content="light dark">
<meta name="csrf-token" content="<?= e(csrfToken()) ?>">
<script>
(function() {
    try {
        var stored = localStorage.getItem('udo-theme');
        var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        var theme = (stored === 'light' || stored === 'dark') ? stored : (prefersDark ? 'dark' : 'light');
        document.documentElement.setAttribute('data-bs-theme', theme);
    } catch (e) {
        document.documentElement.setAttribute('data-bs-theme', 'light');
    }
})();
</script>
<link rel="stylesheet" href="/Asset-UDO/assets/css/theme.css?v=<?= @filemtime(__DIR__ . '/../assets/css/theme.css') ?: 1 ?>">
