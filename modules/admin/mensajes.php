<?php
require_once __DIR__ . '/../../core/conexion.php';
restringirAcceso(['administrador','tecnico','recepcionista','consultor']);

$id_usuario = (int)($_SESSION['usuario_id'] ?? 0);

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['enviar'])) {
    $destinatario = (int)$_POST['destinatario_id'];
    $asunto = trim($_POST['asunto'] ?? '');
    $contenido = trim($_POST['contenido'] ?? '');
    
    if ($destinatario > 0 && !empty($asunto) && !empty($contenido)) {
        $stmt_ins = $conexion->prepare("INSERT INTO mensajes (remitente_id, destinatario_id, asunto, contenido) VALUES (?,?,?,?)");
        $stmt_ins->execute([$id_usuario, $destinatario, $asunto, $contenido]);
        flash('success', "Mensaje enviado exitosamente.");
    } else {
        flash('danger', "Por favor complete todos los campos requeridos.");
    }
    header("Location: mensajes.php");
    exit;
}

$stmt_msg = $conexion->prepare("SELECT m.*, u.nombre as remitente_nombre FROM mensajes m JOIN usuarios u ON m.remitente_id = u.id_usuario WHERE m.destinatario_id = ? ORDER BY m.fecha_envio DESC LIMIT 50");
$stmt_msg->execute([$id_usuario]);
$bandeja = $stmt_msg->fetchAll();

$stmt_users = $conexion->prepare("SELECT id_usuario, nombre FROM usuarios WHERE id_usuario != ? AND estado = 'activo' ORDER BY nombre ASC");
$stmt_users->execute([$id_usuario]);
$usuarios = $stmt_users->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>
<div class="container-fluid animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h3 class="fw-bold mb-0"><i class="fas fa-envelope me-2" style="color:var(--accent-cyan);"></i>Mensajes</h3>
            <p class="text-muted small mt-1">Bandeja de entrada interna</p>
        </div>
        <div>
            <button class="btn btn-primary rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#nuevoMensaje">
                <i class="fas fa-plus me-2"></i>Nuevo Mensaje
            </button>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr class="text-white small text-uppercase">
                            <th>Remitente</th>
                            <th>Asunto</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (count($bandeja) > 0): ?>
                        <?php foreach($bandeja as $m): ?>
                        <tr<?= $m['leido'] ? '' : ' class="fw-bold table-active"' ?>>
                            <td><?= e($m['remitente_nombre']) ?></td>
                            <td><?= e($m['asunto']) ?></td>
                            <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($m['fecha_envio'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="3" class="text-center py-5 text-muted">No tiene mensajes en su bandeja.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal Nuevo -->
<div class="modal fade" id="nuevoMensaje" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <?= csrfCampo() ?>
                <div class="modal-header border-bottom">
                    <h5 class="modal-title fw-bold"><i class="fas fa-paper-plane me-2 text-primary"></i>Nuevo Mensaje</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label">Destinatario</label>
                        <select name="destinatario_id" class="form-select" required>
                            <option value="">Seleccione un usuario...</option>
                            <?php foreach($usuarios as $u): ?>
                                <option value="<?= $u['id_usuario'] ?>"><?= e($u['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Asunto</label>
                        <input type="text" name="asunto" class="form-control" required placeholder="Motivo del mensaje">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contenido</label>
                        <textarea name="contenido" class="form-control" rows="4" required placeholder="Escriba su mensaje aquí..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top">
                    <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="enviar" class="btn btn-primary rounded-pill px-4">Enviar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>