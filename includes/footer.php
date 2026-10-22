</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="/Asset-UDO/assets/js/csrf.js?v=<?= @filemtime(__DIR__ . '/../assets/js/csrf.js') ?: 1 ?>"></script>
<script src="/Asset-UDO/assets/js/theme-toggle.js?v=<?= @filemtime(__DIR__ . '/../assets/js/theme-toggle.js') ?: 1 ?>"></script>
<script src="/Asset-UDO/assets/js/ui.js?v=<?= @filemtime(__DIR__ . '/../assets/js/ui.js') ?: 1 ?>"></script>
<footer>
    <div class="container-fluid text-center">
        <small>
            &copy; <?= date('Y') ?> <?= e(obtenerConfiguracion($conexion, 'nombre_institucion', 'Universidad de Oriente - Núcleo Monagas')) ?>
            | <?= e(obtenerConfiguracion($conexion, 'nombre_sistema', 'SIGA-UDO')) ?>
        </small>
    </div>
</footer>
</body>
</html>