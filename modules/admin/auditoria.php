<?php
require_once __DIR__ . '/../../core/conexion.php';
restringirAcceso(['administrador', 'consultor']);

// ---------- PARÁMETROS ----------
$buscar      = $_GET['buscar'] ?? '';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';
$pagina      = max(1, (int)($_GET['pagina'] ?? 1));
$por_pagina  = 25;

// Construir WHERE
$where = "WHERE 1=1";
$params = [];
if (!empty($buscar)) {
    $where .= " AND (usuario_nombre LIKE ? OR accion LIKE ? OR tabla_afectada LIKE ? OR ip_usuario LIKE ?)";
    $like = '%' . escaparLike((string)$buscar) . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
}
if (!empty($fecha_desde)) {
    $where .= " AND fecha_hora >= ?";
    $params[] = $fecha_desde . ' 00:00:00';
}
if (!empty($fecha_hasta)) {
    $where .= " AND fecha_hora <= ?";
    $params[] = $fecha_hasta . ' 23:59:59';
}

// ===== MODO IMPRESIÓN / VISTA PARA PDF (sin cabeceras falsas) =====
if (isset($_GET['imprimir']) && $_GET['imprimir'] == '1') {
    $stmt_all = $conexion->prepare("SELECT * FROM auditoria $where ORDER BY fecha_hora DESC");
    $stmt_all->execute($params);
    $logs_all = $stmt_all->fetchAll();

    require_once __DIR__ . '/../../includes/formato_documento.php';

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Auditoría – Reporte</title>
        <?php renderEstilosFormato(); ?>
        <style>
            .formato-hoja {
                max-width: 100%;
                margin: 0;
                padding: 0;
            }
        </style>
    </head>
    <body>
        <div class="formato-hoja">
            <div class="formato-cuerpo">
                <?php renderCintilloUDO('Seguridad y Auditoría del Sistema'); ?>

                <div class="doc-fecha">
                    Maturín, <?= date('d/m/Y') ?>
                </div>

                <div class="doc-titulo">REPORTE DE AUDITORÍA Y TRAZABILIDAD</div>
                <div class="doc-subtitulo">REGISTRO DE EVENTOS, OPERACIONES Y SEGURIDAD • SIGA-UDO</div>

                <table style="width: 100%; margin-bottom: 12px; background: #f8fafc; border: 1px solid #cbd5e1; font-size: 8.5pt; color: #334155;">
                    <tr>
                        <td style="padding: 6px 10px;"><strong>Total de eventos:</strong> <?= count($logs_all) ?></td>
                        <?php if (!empty($buscar)): ?>
                            <td style="padding: 6px 10px;"><strong>Filtro:</strong> "<?= htmlspecialchars($buscar) ?>"</td>
                        <?php endif; ?>
                        <?php if (!empty($fecha_desde) || !empty($fecha_hasta)): ?>
                            <td style="padding: 6px 10px;"><strong>Rango:</strong> <?= htmlspecialchars($fecha_desde ?: 'Inicio') ?> al <?= htmlspecialchars($fecha_hasta ?: 'Hoy') ?></td>
                        <?php endif; ?>
                        <td style="padding: 6px 10px; text-align: right;"><strong>Fecha de emisión:</strong> <?= date('d/m/Y H:i') ?></td>
                    </tr>
                </table>

                <table class="tabla-formal">
                    <thead>
                        <tr>
                            <th style="width: 20%;">Usuario Responsable</th>
                            <th style="width: 28%;">Acción Registrada</th>
                            <th style="width: 18%;">Módulo / Tabla</th>
                            <th style="width: 16%;">Dirección IP</th>
                            <th style="width: 18%;">Fecha y Hora</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($logs_all) > 0): ?>
                            <?php foreach ($logs_all as $l): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($l['usuario_nombre']) ?></strong></td>
                                <td><?= htmlspecialchars($l['accion']) ?></td>
                                <td><span class="badge-estado badge-info"><?= htmlspecialchars($l['tabla_afectada']) ?></span></td>
                                <td><code><?= htmlspecialchars($l['ip_usuario']) ?></code></td>
                                <td><?= date('d/m/Y H:i:s', strtotime($l['fecha_hora'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center; padding: 20px; color:#64748b;">No se encontraron registros de auditoría para los criterios seleccionados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <table class="firmas-grid">
                    <tr>
                        <td>
                            <div class="linea-firma"></div>
                            <div class="firma-nombre">Oficial de Seguridad de la Información</div>
                            <div class="firma-cargo">Delegación de Computación y Teleinformática</div>
                        </td>
                        <td>
                            <div class="linea-firma"></div>
                            <div class="firma-nombre">Administrador del Sistema SIGA-UDO</div>
                            <div class="firma-cargo">Universidad de Oriente • Núcleo Monagas</div>
                        </td>
                    </tr>
                </table>
            </div>

            <?php renderFooterUDO('REP-AUD-' . date('Ymd')); ?>
        </div>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    if (isset($_GET['html']) && $_GET['html'] == '1') {
        echo $html;
        exit;
    }

    $descargar = isset($_GET['descargar']) && $_GET['descargar'] == '1';
    $nombreArchivo = 'Reporte_Auditoria_' . date('Ymd_His') . '.pdf';
    generarPDF($html, $nombreArchivo, 'letter', 'landscape', $descargar);
}

// ───────── INTERFAZ NORMAL ─────────
include __DIR__ . '/../../includes/header.php';

// Totales y registros paginados
$total = $conexion->prepare("SELECT COUNT(*) FROM auditoria $where");
$total->execute($params);
$num_registros = $total->fetchColumn();
$total_paginas = ceil($num_registros / $por_pagina);
$offset = ($pagina - 1) * $por_pagina;

$sql = "SELECT * FROM auditoria $where ORDER BY fecha_hora DESC LIMIT $por_pagina OFFSET $offset";
$stmt = $conexion->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$qs = http_build_query(['buscar' => $buscar, 'fecha_desde' => $fecha_desde, 'fecha_hasta' => $fecha_hasta]);
?>

<div class="container-fluid animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h3 class="fw-bold mb-0"><i class="fas fa-history me-2" style="color:var(--accent-cyan);"></i>Auditoría del Sistema</h3>
            <p class="text-muted small mt-1">Registro de todas las acciones realizadas en SIGA-UDO</p>
        </div>
        <div class="d-flex gap-2">
            <a href="?imprimir=1&auto_print=1&<?= $qs ?>" target="_blank" class="btn btn-danger btn-sm rounded-pill shadow-sm">
                <i class="fas fa-file-pdf me-1"></i> PDF
            </a>
            <a href="?imprimir=1&auto_print=1&<?= $qs ?>" target="_blank" class="btn btn-outline-secondary btn-sm rounded-pill">
                <i class="fas fa-print me-1"></i> Imprimir
            </a>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="small text-muted">Buscar (usuario, acción, tabla, IP)</label>
                    <input type="text" name="buscar" class="form-control" placeholder="Escriba un término..." value="<?= e($buscar) ?>">
                </div>
                <div class="col-md-2">
                    <label class="small text-muted">Desde</label>
                    <input type="date" name="fecha_desde" class="form-control" value="<?= e($fecha_desde) ?>">
                </div>
                <div class="col-md-2">
                    <label class="small text-muted">Hasta</label>
                    <input type="date" name="fecha_hasta" class="form-control" value="<?= e($fecha_hasta) ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 rounded-pill">
                        <i class="fas fa-search me-1"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabla escritorio -->
    <div class="card border-0 shadow-sm rounded-4 vista-escritorio">
        <div class="card-header bg-transparent border-0 py-3 d-flex justify-content-between align-items-center">
            <span><i class="fas fa-list me-2" style="color:var(--accent-cyan);"></i>
                <strong><?= $num_registros ?> registro(s)</strong>
            </span>
            <small class="text-muted">Página <?= $pagina ?> de <?= $total_paginas ?: 1 ?></small>
        </div>
        <div class="card-body p-0" style="max-height: 65vh; overflow-y: auto;">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr class="text-white small text-uppercase">
                        <th>Usuario</th><th>Acción</th><th>Tabla</th><th>IP</th><th>Fecha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($logs) > 0): ?>
                        <?php foreach ($logs as $l): ?>
                        <tr>
                            <td><?= e($l['usuario_nombre']) ?></td>
                            <td><?= e($l['accion']) ?></td>
                            <td><span class="badge bg-secondary-subtle text-body border"><?= e($l['tabla_afectada']) ?></span></td>
                            <td><code><?= e($l['ip_usuario']) ?></code></td>
                            <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($l['fecha_hora'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">No se encontraron registros.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_paginas > 1): ?>
        <div class="card-footer bg-transparent border-0 py-2">
            <nav>
                <ul class="pagination pagination-sm justify-content-center mb-0">
                    <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                        <li class="page-item <?= $i == $pagina ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= $qs ?>&pagina=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tarjetas móvil -->
    <div class="vista-movil">
        <?php if (count($logs) > 0): ?>
            <div class="row g-3">
                <?php foreach ($logs as $l): ?>
                <div class="col-12">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-body p-3">
                            <h6 class="fw-bold mb-1"><?= e($l['usuario_nombre']) ?></h6>
                            <p class="mb-1 small"><?= e($l['accion']) ?></p>
                            <p class="mb-1 small">Tabla: <span class="badge bg-secondary-subtle text-body border"><?= e($l['tabla_afectada']) ?></span></p>
                            <p class="mb-2 small">IP: <code><?= e($l['ip_usuario']) ?></code></p>
                            <small class="text-muted"><?= date('d/m/Y H:i', strtotime($l['fecha_hora'])) ?></small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($total_paginas > 1): ?>
            <nav class="mt-3">
                <ul class="pagination pagination-sm justify-content-center">
                    <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                        <li class="page-item <?= $i == $pagina ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= $qs ?>&pagina=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center py-5 text-muted">No se encontraron registros.</div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>