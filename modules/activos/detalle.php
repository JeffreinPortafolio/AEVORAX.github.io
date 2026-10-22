<?php
require_once __DIR__ . '/../../core/conexion.php';
restringirAcceso(['administrador', 'recepcionista', 'tecnico', 'consultor']);
$id_activo = $_GET['id'] ?? 0;
if (!$id_activo) { header("Location: lista.php"); exit; }

$stmt = $conexion->prepare("SELECT * FROM activos WHERE id_activo = ?");
$stmt->execute([$id_activo]);
$activo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$activo) { header("Location: lista.php"); exit; }

include __DIR__ . '/../../includes/header.php';

// Obtener historial de novedades asociadas
$novedades = $conexion->prepare("SELECT * FROM novedades WHERE id_activo = ? ORDER BY fecha_entrada DESC");
$novedades->execute([$id_activo]);
$historial = $novedades->fetchAll(PDO::FETCH_ASSOC);

$veces_reparado = 0;
foreach ($historial as $h) { if (($h['estado_final'] ?? '') === 'Reparado') $veces_reparado++; }

$color_estado = getEstadoColor($activo['estado_equipo']);
$icono_estado = getEstadoIcono($activo['estado_equipo']);
$foto_equipo = !empty($activo['foto_url']) ? '../../'.$activo['foto_url'] : null;
?>

<div class="container-fluid animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold">
                <i class="fas fa-desktop me-2" style="color:var(--accent-cyan);"></i>Detalle del Activo
            </h3>
            <p class="text-muted small">Información completa del equipo</p>
        </div>
        <div>
            <a href="lista.php" class="btn btn-outline-secondary rounded-pill me-2"><i class="fas fa-arrow-left me-1"></i> Volver</a>
            <a href="editar.php?id=<?= $activo['id_activo'] ?>" class="btn btn-primary rounded-pill me-2"><i class="fas fa-edit me-1"></i> Editar</a>
            <a href="../../reports/ficha_individual.php?id=<?= $activo['id_activo'] ?>" class="btn btn-outline-danger rounded-pill" target="_blank"><i class="fas fa-file-pdf me-1"></i> Ficha PDF</a>
        </div>
    </div>

    <!-- Estado y código -->
    <div class="d-flex align-items-center mb-4">
        <span class="badge bg-<?= $color_estado ?> fs-6 px-3 py-2 me-3">
            <i class="fas <?= $icono_estado ?> me-1"></i> <?= $activo['estado_equipo'] ?>
        </span>
        <code class="fs-5 me-3" style="color:var(--accent-cyan);"><?= e($activo['codigo_udo']) ?></code>
        <span class="badge bg-secondary fs-6 px-3 py-2"><i class="fas fa-tools me-1"></i> Reparado <?= $veces_reparado ?> <?= $veces_reparado === 1 ? 'vez' : 'veces' ?></span>
    </div>

    <!-- Imagen del equipo (si hay) -->
    <?php if ($foto_equipo && file_exists(__DIR__.'/../../'.$activo['foto_url'])): ?>
    <div class="text-center mb-4">
        <img src="<?= $foto_equipo ?>" class="img-fluid rounded shadow" style="max-height:300px;">
    </div>
    <?php endif; ?>

    <!-- Datos generales -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-transparent border-0 fw-bold">
            <i class="fas fa-info-circle me-2" style="color:var(--accent-cyan);"></i> Datos Generales
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <small class="text-muted">Código UDO</small>
                    <div class="fw-bold"><?= htmlspecialchars($activo['codigo_udo']) ?></div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">Tipo de Equipo</small>
                    <div class="fw-bold"><?= htmlspecialchars($activo['tipo_equipo']) ?></div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">Estado</small>
                    <div><span class="badge bg-<?= $color_estado ?>"><?= $activo['estado_equipo'] ?></span></div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">Marca</small>
                    <div><?= htmlspecialchars($activo['marca']) ?></div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">Modelo</small>
                    <div><?= htmlspecialchars($activo['modelo']) ?></div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">Serial (S/N)</small>
                    <div><code><?= htmlspecialchars($activo['serial']) ?></code></div>
                </div>
                <div class="col-md-6">
                    <small class="text-muted">Ubicación</small>
                    <div><i class="fas fa-map-marker-alt text-danger me-1"></i> <?= htmlspecialchars($activo['ubicacion']) ?></div>
                    <?php if($activo['departamento']): ?>
                        <small class="text-muted"><?= htmlspecialchars($activo['departamento']) ?></small>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <small class="text-muted">Responsable</small>
                    <div>
                        <?= htmlspecialchars($activo['responsable_actual'] ?? 'No asignado') ?>
                        <?php if($activo['cedula_responsable']): ?>
                            <br><small class="text-muted">C.I.: <?= htmlspecialchars($activo['cedula_responsable']) ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Detalles financieros y fechas -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-transparent border-0 fw-bold">
            <i class="fas fa-calendar-alt me-2" style="color:var(--accent-cyan);"></i> Información Financiera y Fechas
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <small class="text-muted">Fecha de adquisición</small>
                    <div><?= $activo['fecha_adquisicion'] ? date('d/m/Y', strtotime($activo['fecha_adquisicion'])) : '—' ?></div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">Valor de compra</small>
                    <div><?= $activo['valor_compra'] > 0 ? '$ '.number_format($activo['valor_compra'], 2) : 'No especificado' ?></div>
                </div>
                <div class="col-md-4">
                    <small class="text-muted">Fecha de registro</small>
                    <div><?= date('d/m/Y H:i', strtotime($activo['fecha_registro'])) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Observaciones / Anotaciones -->
    <?php if (!empty($activo['observaciones'])): ?>
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-transparent border-0 fw-bold">
            <i class="fas fa-sticky-note me-2" style="color:var(--accent-cyan);"></i> Observaciones / Anotaciones
        </div>
        <div class="card-body">
            <p><?= nl2br(htmlspecialchars($activo['observaciones'])) ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Historial de novedades -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-transparent border-0 fw-bold">
            <i class="fas fa-history me-2" style="color:var(--accent-cyan);"></i> Historial de Servicios
        </div>
        <div class="card-body p-0">
            <?php if ($historial): ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr class="text-white small">
                                <th>Orden</th>
                                <th>Falla Reportada</th>
                                <th>Diagnóstico</th>
                                <th>Estado Final</th>
                                <th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historial as $n): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($n['numero_orden'] ?? '—') ?></td>
                                    <td class="small"><?= htmlspecialchars(substr($n['descripcion_falla'], 0, 60)) ?>…</td>
                                    <td class="small"><?= htmlspecialchars(substr($n['diagnostico_tecnico'] ?? 'Pendiente', 0, 50)) ?><?= strlen($n['diagnostico_tecnico'] ?? '') > 50 ? '...' : '' ?></td>
                                    <td>
                                        <?php if ($n['estado_final']): ?>
                                            <span class="badge bg-<?= $n['estado_final'] == 'Reparado' ? 'success' : 'danger' ?>"><?= $n['estado_final'] ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">En Proceso</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?= date('d/m/Y', strtotime($n['fecha_entrada'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-4 text-muted">
                    <i class="fas fa-clipboard-list fa-2x mb-2"></i>
                    <p>Este equipo no tiene historial de servicios registrados.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>