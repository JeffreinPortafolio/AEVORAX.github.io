<?php
require_once __DIR__ . '/../../core/conexion.php';
restringirAcceso(['administrador', 'consultor']);

// ────────────── GENERACIÓN DE REPORTES (Imprimir/PDF) ──────────────
if (isset($_GET['reporte'])) {
    $tipo   = (string)$_GET['reporte']; // se valida contra la lista del switch (default → die)
    $titulo = '';
    $datos  = [];

    // Seleccionar datos según el reporte
    switch ($tipo) {
        case 'usuarios':
            $titulo = 'Listado de Usuarios';
            $stmt = $conexion->query("SELECT id_usuario, nombre, email, cedula, rol, estado, fecha_creacion FROM usuarios ORDER BY fecha_creacion DESC");
            $datos = $stmt->fetchAll();
            break;

        case 'activos':
            $titulo = 'Inventario de Activos';
            $stmt = $conexion->query("SELECT codigo_udo, tipo_equipo, marca, modelo, serial, ubicacion, estado_equipo, fecha_registro FROM activos WHERE estado_equipo <> 'Desincorporado' ORDER BY codigo_udo ASC");
            $datos = $stmt->fetchAll();
            break;

        case 'taller':
            $titulo = 'Equipos en Taller (En Reparación)';
            $stmt = $conexion->query("SELECT codigo_udo, tipo_equipo, marca, modelo, serial, ubicacion, fecha_registro FROM activos WHERE estado_equipo = 'En Reparación' ORDER BY fecha_registro DESC");
            $datos = $stmt->fetchAll();
            break;

        case 'entregas':
            $titulo = 'Entregas Realizadas';
            $stmt = $conexion->query("
                SELECT a.codigo_udo, a.tipo_equipo, a.marca, a.modelo, n.persona_recibe, n.cedula_recibe, n.fecha_entrega_final
                FROM novedades n 
                JOIN activos a ON n.id_activo = a.id_activo 
                WHERE n.fecha_entrega_final IS NOT NULL 
                ORDER BY n.fecha_entrega_final DESC
            ");
            $datos = $stmt->fetchAll();
            break;

        case 'bajas':
            $titulo = 'Equipos Desincorporados';
            $stmt = $conexion->query("
                SELECT a.codigo_udo, a.tipo_equipo, a.marca, a.modelo, a.serial, a.ubicacion, o.numero_oficio
                FROM activos a 
                LEFT JOIN oficios o ON o.id_activo = a.id_activo 
                WHERE a.estado_equipo = 'Desincorporado'
                ORDER BY a.ultima_modificacion DESC
            ");
            $datos = $stmt->fetchAll();
            break;

        case 'auditoria':
            $titulo = 'Auditoría del Sistema';
            $stmt = $conexion->query("SELECT usuario_nombre, accion, tabla_afectada, ip_usuario, fecha_hora FROM auditoria ORDER BY fecha_hora DESC");
            $datos = $stmt->fetchAll();
            break;

        default:
            die("Reporte no válido.");
    }

    require_once __DIR__ . '/../../includes/formato_documento.php';

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title><?= htmlspecialchars($titulo) ?></title>
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
                <?php renderCintilloUDO('Administración y Control del Sistema'); ?>

                <div class="doc-fecha">
                    Maturín, <?= date('d/m/Y') ?>
                </div>

                <div class="doc-titulo"><?= htmlspecialchars($titulo) ?></div>
                <div class="doc-subtitulo">INFORME EJECUTIVO DE GESTIÓN • DELEGACIÓN DE COMPUTACIÓN</div>

                <table style="width: 100%; margin-bottom: 12px; background: #f8fafc; border: 1px solid #cbd5e1; font-size: 8.5pt; color: #334155;">
                    <tr>
                        <td style="padding: 6px 10px;"><strong>Total de registros:</strong> <?= count($datos) ?></td>
                        <td style="padding: 6px 10px;"><strong>Módulo:</strong> <?= ucfirst($tipo) ?></td>
                        <td style="padding: 6px 10px; text-align: right;"><strong>Fecha y hora de corte:</strong> <?= date('d/m/Y H:i') ?></td>
                    </tr>
                </table>

                <table class="tabla-formal">
                    <thead>
                        <tr>
                            <?php if ($tipo == 'usuarios'): ?>
                                <th>Nombre</th><th>Email</th><th>Cédula</th><th>Rol</th><th>Estado</th><th>Fecha creación</th>
                            <?php elseif ($tipo == 'activos'): ?>
                                <th>Código UDO</th><th>Tipo</th><th>Marca / Modelo</th><th>Serial</th><th>Ubicación</th><th>Estado</th><th>Fecha registro</th>
                            <?php elseif ($tipo == 'taller'): ?>
                                <th>Código UDO</th><th>Tipo</th><th>Marca / Modelo</th><th>Serial</th><th>Ubicación</th><th>Fecha registro</th>
                            <?php elseif ($tipo == 'entregas'): ?>
                                <th>Código UDO</th><th>Equipo</th><th>Recibido por</th><th>Cédula</th><th>Fecha entrega</th>
                            <?php elseif ($tipo == 'bajas'): ?>
                                <th>Código UDO</th><th>Tipo</th><th>Marca / Modelo</th><th>Serial</th><th>Ubicación</th><th>Oficio</th>
                            <?php elseif ($tipo == 'auditoria'): ?>
                                <th>Usuario</th><th>Acción</th><th>Tabla</th><th>IP</th><th>Fecha</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($datos) > 0): foreach ($datos as $fila): ?>
                        <tr>
                            <?php if ($tipo == 'usuarios'): ?>
                                <td><strong><?= htmlspecialchars($fila['nombre']) ?></strong></td>
                                <td><?= htmlspecialchars($fila['email']) ?></td>
                                <td><?= htmlspecialchars($fila['cedula'] ?? '—') ?></td>
                                <td><span class="badge-estado badge-info"><?= htmlspecialchars($fila['rol']) ?></span></td>
                                <td><span class="badge-estado <?= $fila['estado'] === 'activo' ? 'badge-operativo' : 'badge-baja' ?>"><?= htmlspecialchars($fila['estado']) ?></span></td>
                                <td><?= date('d/m/Y', strtotime($fila['fecha_creacion'])) ?></td>
                            <?php elseif ($tipo == 'activos' || $tipo == 'taller'): ?>
                                <td><strong><?= htmlspecialchars($fila['codigo_udo']) ?></strong></td>
                                <td><?= htmlspecialchars($fila['tipo_equipo']) ?></td>
                                <td><?= htmlspecialchars($fila['marca']) ?> <?= htmlspecialchars($fila['modelo']) ?></td>
                                <td><code><?= htmlspecialchars($fila['serial'] ?: 'S/S') ?></code></td>
                                <td><?= htmlspecialchars($fila['ubicacion']) ?></td>
                                <?php if ($tipo == 'activos'): ?>
                                    <td><span class="badge-estado <?= getEstadoColor($fila['estado_equipo']) === 'success' ? 'badge-operativo' : 'badge-taller' ?>"><?= htmlspecialchars($fila['estado_equipo']) ?></span></td>
                                <?php endif; ?>
                                <td><?= date('d/m/Y', strtotime($fila['fecha_registro'])) ?></td>
                            <?php elseif ($tipo == 'entregas'): ?>
                                <td><strong><?= htmlspecialchars($fila['codigo_udo']) ?></strong></td>
                                <td><?= htmlspecialchars($fila['tipo_equipo']) ?> <?= htmlspecialchars($fila['marca']) ?></td>
                                <td><?= htmlspecialchars($fila['persona_recibe']) ?></td>
                                <td><?= htmlspecialchars($fila['cedula_recibe'] ?? '—') ?></td>
                                <td><?= date('d/m/Y', strtotime($fila['fecha_entrega_final'])) ?></td>
                            <?php elseif ($tipo == 'bajas'): ?>
                                <td><strong><?= htmlspecialchars($fila['codigo_udo']) ?></strong></td>
                                <td><?= htmlspecialchars($fila['tipo_equipo']) ?></td>
                                <td><?= htmlspecialchars($fila['marca']) ?> <?= htmlspecialchars($fila['modelo']) ?></td>
                                <td><code><?= htmlspecialchars($fila['serial'] ?: 'S/S') ?></code></td>
                                <td><?= htmlspecialchars($fila['ubicacion']) ?></td>
                                <td><strong style="color:#dc2626;"><?= htmlspecialchars($fila['numero_oficio'] ?? '—') ?></strong></td>
                            <?php elseif ($tipo == 'auditoria'): ?>
                                <td><strong><?= htmlspecialchars($fila['usuario_nombre']) ?></strong></td>
                                <td><?= htmlspecialchars($fila['accion']) ?></td>
                                <td><?= htmlspecialchars($fila['tabla_afectada']) ?></td>
                                <td><code><?= htmlspecialchars($fila['ip_usuario']) ?></code></td>
                                <td><?= date('d/m/Y H:i', strtotime($fila['fecha_hora'])) ?></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="7" style="text-align:center; padding: 20px; color:#64748b;">No existen registros que coincidan con la consulta.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <table class="firmas-grid">
                    <tr>
                        <td>
                            <div class="linea-firma"></div>
                            <div class="firma-nombre">Generado por: <?= htmlspecialchars($_SESSION['nombre'] ?? 'Administrador') ?></div>
                            <div class="firma-cargo">Administración del Sistema • SIGA-UDO</div>
                        </td>
                        <td>
                            <div class="linea-firma"></div>
                            <div class="firma-nombre">Jefatura de la Delegación</div>
                            <div class="firma-cargo">Universidad de Oriente • Núcleo Monagas</div>
                        </td>
                    </tr>
                </table>
            </div>

            <?php renderFooterUDO('REP-ADM-' . date('Ymd')); ?>
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
    $nombreArchivo = 'Reporte_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $tipo) . '_' . date('Ymd_His') . '.pdf';
    generarPDF($html, $nombreArchivo, 'letter', 'landscape', $descargar);
}

// ────────────── INTERFAZ DE BOTONES ──────────────
include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h3 class="fw-bold mb-0"><i class="fas fa-file-pdf me-2" style="color: var(--accent-cyan);"></i>Reportes del Sistema</h3>
            <p class="text-muted small mt-1">Descargue o imprima informes de las distintas áreas</p>
        </div>
        <div>
            <a href="<?= url('modules/admin/auditoria.php') ?>" class="btn btn-outline-info btn-sm rounded-pill">
                <i class="fas fa-history me-1"></i> Auditoría
            </a>
        </div>
    </div>

    <div class="row g-4">
        <!-- Usuarios -->
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body text-center p-4">
                    <i class="fas fa-users fa-3x mb-3" style="color: var(--accent-cyan);"></i>
                    <h5 class="fw-bold">Usuarios</h5>
                    <p class="text-muted small">Listado completo de usuarios del sistema.</p>
                    <div class="d-flex gap-2 justify-content-center">
                        <a href="reportes.php?reporte=usuarios&modo=imprimir" target="_blank" class="btn btn-outline-danger btn-sm rounded-pill">
                            <i class="fas fa-file-pdf me-1"></i> PDF / Imprimir
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inventario -->
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body text-center p-4">
                    <i class="fas fa-boxes fa-3x mb-3" style="color: #4ade80;"></i>
                    <h5 class="fw-bold">Inventario de Activos</h5>
                    <p class="text-muted small">Todos los equipos registrados con su estado.</p>
                    <div class="d-flex gap-2 justify-content-center">
                        <a href="reportes.php?reporte=activos&modo=imprimir" target="_blank" class="btn btn-outline-danger btn-sm rounded-pill">
                            <i class="fas fa-file-pdf me-1"></i> PDF / Imprimir
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Equipos en Taller -->
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body text-center p-4">
                    <i class="fas fa-tools fa-3x mb-3" style="color: #facc15;"></i>
                    <h5 class="fw-bold">Equipos en Taller</h5>
                    <p class="text-muted small">Equipos que actualmente están en reparación.</p>
                    <div class="d-flex gap-2 justify-content-center">
                        <a href="reportes.php?reporte=taller&modo=imprimir" target="_blank" class="btn btn-outline-danger btn-sm rounded-pill">
                            <i class="fas fa-file-pdf me-1"></i> PDF / Imprimir
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Entregas -->
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body text-center p-4">
                    <i class="fas fa-handshake fa-3x mb-3" style="color: #60a5fa;"></i>
                    <h5 class="fw-bold">Entregas Realizadas</h5>
                    <p class="text-muted small">Equipos reparados y devueltos.</p>
                    <div class="d-flex gap-2 justify-content-center">
                        <a href="reportes.php?reporte=entregas&modo=imprimir" target="_blank" class="btn btn-outline-danger btn-sm rounded-pill">
                            <i class="fas fa-file-pdf me-1"></i> PDF / Imprimir
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bajas -->
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body text-center p-4">
                    <i class="fas fa-skull-crossbones fa-3x mb-3" style="color: #ef4444;"></i>
                    <h5 class="fw-bold">Equipos Desincorporados</h5>
                    <p class="text-muted small">Equipos dados de baja definitiva.</p>
                    <div class="d-flex gap-2 justify-content-center">
                        <a href="reportes.php?reporte=bajas&modo=imprimir" target="_blank" class="btn btn-outline-danger btn-sm rounded-pill">
                            <i class="fas fa-file-pdf me-1"></i> PDF / Imprimir
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Auditoría -->
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body text-center p-4">
                    <i class="fas fa-history fa-3x mb-3" style="color: #2f7bff;"></i>
                    <h5 class="fw-bold">Auditoría Completa</h5>
                    <p class="text-muted small">Historial de todas las acciones.</p>
                    <div class="d-flex gap-2 justify-content-center">
                        <a href="reportes.php?reporte=auditoria&modo=imprimir" target="_blank" class="btn btn-outline-danger btn-sm rounded-pill">
                            <i class="fas fa-file-pdf me-1"></i> PDF / Imprimir
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>