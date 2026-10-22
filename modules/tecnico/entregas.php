<?php
require_once __DIR__ . '/../../core/conexion.php';
restringirAcceso(['administrador', 'tecnico', 'recepcionista']);

// ============================================================
// MODO IMPRESIÓN DE ACTA DE DEVOLUCIÓN (VOUCHER)
// ============================================================
if (isset($_GET['modo']) && $_GET['modo'] == 'acta' && !empty($_GET['id'])) {
    require_once __DIR__ . '/../../includes/formato_documento.php';
    $id_novedad = (int)$_GET['id'];
    $stmt = $conexion->prepare("SELECT n.*, a.codigo_udo, a.tipo_equipo, a.marca, a.modelo, a.serial
                                FROM novedades n
                                JOIN activos a ON n.id_activo = a.id_activo
                                WHERE n.id_novedad = ?");
    $stmt->execute([$id_novedad]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$data) die("Acta no encontrada.");

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Acta de Devolución – <?= htmlspecialchars($data['codigo_udo']) ?></title>
        <?php renderEstilosFormato('portrait'); ?>
    </head>
    <body>
        <?php renderCintilloUDO('Delegación de Computación y Teleinformática'); ?>

        <div class="doc-fecha">
            Maturín, <?= !empty($data['fecha_entrega_final']) ? date('d/m/Y', strtotime($data['fecha_entrega_final'])) : date('d/m/Y') ?>
        </div>

        <div class="doc-titulo">ACTA OFICIAL DE ENTREGA Y CONFORMIDAD TÉCNICA</div>
        <div class="doc-subtitulo">DELEGACIÓN DE COMPUTACIÓN Y TELEINFORMÁTICA • NÚCLEO DE MONAGAS</div>

        <table class="meta-box">
            <tr>
                <td style="width: 50%;"><strong>ORDEN ASOCIADA:</strong> <span style="font-size: 10.5pt; font-weight:bold; color:#003366;"><?= htmlspecialchars($data['numero_orden']) ?></span></td>
                <td style="width: 50%;"><strong>FECHA DE ENTREGA:</strong> <?= !empty($data['fecha_entrega_final']) ? date('d/m/Y h:i A', strtotime($data['fecha_entrega_final'])) : date('d/m/Y h:i A') ?></td>
            </tr>
            <tr>
                <td><strong>CÓDIGO ACTIVO UDO:</strong> <strong><?= htmlspecialchars($data['codigo_udo']) ?></strong></td>
                <td><strong>ESTADO FINAL:</strong> <span class="badge-estado badge-operativo">Operativo / Entregado</span></td>
            </tr>
        </table>

        <table class="tabla-datos">
            <tr>
                <th>Código Institucional UDO</th>
                <td><strong><?= htmlspecialchars($data['codigo_udo']) ?></strong></td>
            </tr>
            <tr>
                <th>Equipo / Categoría</th>
                <td><?= htmlspecialchars($data['tipo_equipo']) ?></td>
            </tr>
            <tr>
                <th>Marca y Modelo</th>
                <td><?= htmlspecialchars($data['marca']) ?> <?= htmlspecialchars($data['modelo']) ?></td>
            </tr>
            <tr>
                <th>Número de Serial</th>
                <td><code><?= htmlspecialchars($data['serial'] ?: 'NO POSEE / NO VISIBLE') ?></code></td>
            </tr>
            <tr>
                <th>Diagnóstico Técnico Realizado</th>
                <td><?= nl2br(htmlspecialchars($data['diagnostico_tecnico'] ?? 'No especificado')) ?></td>
            </tr>
            <tr>
                <th>Solución / Mantenimiento Aplicado</th>
                <td><?= nl2br(htmlspecialchars($data['solucion_aplicada'] ?? 'Mantenimiento preventivo / correctivo concluido')) ?></td>
            </tr>
            <tr>
                <th>Fecha de Finalización de Reparación</th>
                <td><?= !empty($data['fecha_salida']) ? date('d/m/Y h:i A', strtotime($data['fecha_salida'])) : '—' ?></td>
            </tr>
            <tr>
                <th>Funcionario / Responsable que Recibe</th>
                <td><strong><?= htmlspecialchars($data['persona_recibe'] ?? '—') ?></strong></td>
            </tr>
            <tr>
                <th>Cédula de Identidad</th>
                <td><?= htmlspecialchars($data['cedula_recibe'] ?? '—') ?></td>
            </tr>
        </table>

        <div class="clausula-texto">
            <strong>CONSTANCIA DE CONFORMIDAD:</strong> Por medio de la presente, el funcionario receptor certifica que el equipo tecnológico anteriormente identificado ha sido revisado, verificado en funcionamiento y entregado a entera satisfacción por la Delegación de Computación y Teleinformática, reincorporándose a sus labores habituales en la dependencia universitaria correspondiente.
        </div>

        <table class="firmas-grid">
            <tr>
                <td>
                    <div class="linea-firma"></div>
                    <div class="firma-nombre">Delegación de Computación y Teleinformática</div>
                    <div class="firma-cargo">Técnico Responsable del Servicio</div>
                </td>
                <td>
                    <div class="linea-firma"></div>
                    <div class="firma-nombre"><?= htmlspecialchars($data['persona_recibe'] ?? 'Custodio / Solicitante') ?></div>
                    <div class="firma-cargo"><?= !empty($data['cedula_recibe']) ? 'C.I. ' . htmlspecialchars($data['cedula_recibe']) : 'Conforme Recibido' ?></div>
                </td>
            </tr>
        </table>

        <?php renderFooterUDO($data['numero_orden']); ?>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    if (isset($_GET['html']) && $_GET['html'] == '1') {
        echo $html;
        exit;
    }

    $descargar = isset($_GET['descargar']) && $_GET['descargar'] == '1';
    $nombreArchivo = 'Acta_Entrega_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $data['codigo_udo']) . '.pdf';
    generarPDF($html, $nombreArchivo, 'letter', 'portrait', $descargar);
}

// ============================================================
// PROCESAR FORMULARIO DE ENTREGA (POST)
// ============================================================
$mensaje = '';
$error   = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['entregar_equipo'])) {
    $id_novedad = $_POST['id_novedad'] ?? 0;
    $id_activo  = $_POST['id_activo'] ?? 0;
    $persona_recibe = limpiar($_POST['persona_recibe'] ?? '');
    $cedula_recibe  = limpiar($_POST['cedula_recibe'] ?? '');

    if (empty($persona_recibe)) {
        $error = "Debe ingresar el nombre de quien recibe.";
    } else {
        try {
            $conexion->beginTransaction();

            $conexion->prepare("UPDATE novedades SET 
                fecha_entrega_final = NOW(),
                persona_recibe = ?,
                cedula_recibe = ?
                WHERE id_novedad = ?")->execute([$persona_recibe, $cedula_recibe, $id_novedad]);

            $conexion->prepare("UPDATE activos SET estado_equipo = 'Operativo', ultima_modificacion = NOW(), modificado_por = ? WHERE id_activo = ?")
                ->execute([$_SESSION['usuario_id'], $id_activo]);

            $conexion->commit();
            registrarAuditoria($conexion, "Entrega de equipo reparado – Activo ID $id_activo – Recibido por: $persona_recibe", "novedades", $id_novedad);

            // ============================================================
            // ✅ REGISTRO DEL VOUCHER EN EL HISTORIAL
            // ============================================================
            $stmtVoucher = $conexion->prepare("INSERT INTO vouchers (tipo, id_novedad, titulo, generado_por, fecha_generacion) 
                                               VALUES (?, ?, ?, ?, NOW())");
            $stmtVoucher->execute(['entrega', $id_novedad, 'Acta de Devolución', $_SESSION['usuario_id']]);

            $_SESSION['voucher_url'] = "entregas.php?modo=acta&id=$id_novedad";
            $_SESSION['voucher_title'] = "Acta de Devolución";
            $_SESSION['flash_mensaje'] = "✅ Equipo entregado correctamente.";
        } catch (Exception $e) {
            $conexion->rollBack();
            $error = errorInterno($e);
        }
        header("Location: entregas.php");
        exit;
    }
}

// ============================================================
// INTERFAZ PRINCIPAL (GET)
// ============================================================
include __DIR__ . '/../../includes/header.php';

$mensaje_flash = $_SESSION['flash_mensaje'] ?? '';
$error_flash   = $_SESSION['flash_error']   ?? '';
$voucher_url = $_SESSION['voucher_url'] ?? '';
$voucher_title = $_SESSION['voucher_title'] ?? '';
unset($_SESSION['flash_mensaje'], $_SESSION['flash_error'], $_SESSION['voucher_url'], $_SESSION['voucher_title']);

if (!empty($error)) $error_flash = $error;
if (!empty($mensaje)) $mensaje_flash = $mensaje;

// Consulta de equipos listos para entregar (reparados, sin entregar)
$entregas = $conexion->query("
    SELECT a.*, 
           n.id_novedad, 
           n.solucion_aplicada, 
           n.diagnostico_tecnico, 
           n.numero_orden, 
           n.fecha_salida
    FROM activos a 
    JOIN novedades n ON a.id_activo = n.id_activo 
    WHERE a.estado_equipo = 'Reparado' 
      AND n.estado_final = 'Reparado'
      AND n.fecha_entrega_final IS NULL
      AND n.id_novedad = (SELECT MAX(n2.id_novedad) FROM novedades n2 WHERE n2.id_activo = a.id_activo)
    ORDER BY n.fecha_salida DESC
")->fetchAll();
?>

<div class="container-fluid animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0" style="color: var(--text-primary);">
                <i class="fas fa-handshake me-2" style="color: var(--accent-cyan);"></i>Equipos Listos para Entrega
            </h3>
            <p class="text-muted small">Equipos reparados que esperan ser retirados</p>
        </div>
        <span class="badge bg-success rounded-pill px-3 py-2 fs-6 shadow-sm">
            <i class="fas fa-box-open me-1"></i> <?= count($entregas) ?> Listos
        </span>
    </div>

    <?php if($mensaje_flash): ?><div class="alert alert-success animate__animated animate__fadeInDown"><?= $mensaje_flash ?></div><?php endif; ?>
    <?php if($error_flash): ?><div class="alert alert-danger animate__animated animate__shakeX"><?= $error_flash ?></div><?php endif; ?>

    <?php if(empty($entregas)): ?>
        <div class="card border-0 shadow-sm rounded-4 text-center py-5">
            <div class="card-body">
                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                <h5 class="text-muted">No hay equipos esperando ser retirados</h5>
                <a href="reparar.php" class="btn btn-primary rounded-pill mt-3">
                    <i class="fas fa-tools me-2"></i>Ir al Taller
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach($entregas as $e): ?>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm rounded-4 overflow-hidden animate__animated animate__fadeInUp">
                    <div class="card-header bg-success text-white border-0 py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-bold"><i class="fas fa-check-circle me-2"></i>Listo para Entrega</span>
                            <code class="text-white"><?= htmlspecialchars($e['codigo_udo']) ?></code>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <h5 class="fw-bold mb-1" style="color:var(--accent-cyan);"><?= e($e['tipo_equipo']) ?></h5>
                        <p class="text-secondary small mb-2"><?= e($e['marca']) ?> - <?= e($e['modelo']) ?></p>
                        <div class="mb-3 small p-2 rounded bg-body-secondary border">
                            <small class="text-success fw-bold text-uppercase"><i class="fas fa-wrench me-1"></i> Reparación:</small>
                            <p class="mb-0 mt-1"><?= !empty($e['solucion_aplicada']) ? e($e['solucion_aplicada']) : 'No registrada' ?></p>
                        </div>
                        <div class="d-flex justify-content-between align-items-center p-2 mb-3 rounded bg-body-tertiary border">
                            <div><small class="text-muted">Orden:</small><br><span class="fw-bold text-body"><?= e($e['numero_orden']) ?></span></div>
                            <div class="text-end"><small class="text-muted">Reparado el:</small><br><span class="text-body small"><?= date('d/m/Y', strtotime($e['fecha_salida'])) ?></span></div>
                        </div>
                        <form method="POST">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="id_novedad" value="<?= $e['id_novedad'] ?>">
                            <input type="hidden" name="id_activo" value="<?= $e['id_activo'] ?>">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">¿QUIÉN RECIBE? <span class="text-danger">*</span></label>
                                    <input type="text" name="persona_recibe" class="form-control" required placeholder="Nombre completo">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">CÉDULA</label>
                                    <input type="text" name="cedula_recibe" class="form-control" placeholder="V-12345678">
                                </div>
                                <div class="col-12">
                                    <button type="submit" name="entregar_equipo" class="btn btn-success rounded-pill px-5 fw-bold">
                                        <i class="fas fa-handshake me-2"></i>CONFIRMAR ENTREGA
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Popup de voucher (se muestra después de una entrega exitosa) -->
<?php if (!empty($voucher_url)): ?>
<script>
window.addEventListener('load', function() {
    var voucherModal = new bootstrap.Modal(document.getElementById('modalVoucher'));
    voucherModal.show();
});
</script>
<div class="modal fade" id="modalVoucher" tabindex="-1" data-bs-backdrop="false">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title w-100 text-center fw-bold" style="color: var(--accent-cyan);">
                    <i class="fas fa-check-circle text-success me-2"></i><?= e($voucher_title) ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <i class="fas fa-print fa-4x mb-3" style="color: var(--accent-cyan);"></i>
                <p class="mb-3">¿Deseas imprimir o guardar el comprobante?</p>
                <div class="d-flex justify-content-center gap-2">
                    <a href="<?= e($voucher_url) ?>" target="_blank" class="btn btn-primary rounded-pill px-4">
                        <i class="fas fa-print me-1"></i> Imprimir
                    </a>
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>