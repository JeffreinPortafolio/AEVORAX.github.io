<?php
require_once __DIR__ . '/core/conexion.php';

$busqueda = trim($_GET['codigo'] ?? '');
$resultado = null;
$activo_solo = null;
$error_msg = null;

if (!empty($busqueda)) {
    // La consulta pública se hace SOLO con el N° de Orden de Servicio (el que recibe quien deja el equipo).
    // No se busca por serial, código ni oficio para no exponer datos del inventario.
    $stmt = $conexion->prepare("
        SELECT a.id_activo, a.codigo_udo, a.tipo_equipo, a.marca, a.modelo, a.ubicacion, a.departamento, a.estado_equipo as estado_activo_actual,
               n.id_novedad, n.numero_orden, n.descripcion_falla, n.fecha_entrada, n.diagnostico_tecnico, n.nivel_dano,
               n.fecha_diagnostico, n.fecha_fin_reparacion, n.solucion_aplicada, n.fecha_entrega_final, n.estado_final,
               (n.id_novedad = (SELECT MAX(n2.id_novedad) FROM novedades n2 WHERE n2.id_activo = a.id_activo)) AS es_ultima,
               o.id_oficio, o.numero_oficio
        FROM novedades n
        JOIN activos a ON a.id_activo = n.id_activo
        LEFT JOIN oficios o ON o.id_novedad = n.id_novedad
        WHERE n.numero_orden = ?
        LIMIT 1
    ");
    $stmt->execute([$busqueda]);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($resultado) {
        // Un equipo puede tener varias órdenes: el estado mostrado es el de ESTA orden, no el actual del activo.
        $entregada = !empty($resultado['fecha_entrega_final']);
        if ($resultado['estado_final'] === 'Reparado' && ($entregada || !$resultado['es_ultima'])) {
            $resultado['estado_activo_actual'] = 'Reparado';
        } elseif ($resultado['estado_final'] === 'No Reparable' && !$resultado['es_ultima']) {
            $resultado['estado_activo_actual'] = !empty($resultado['id_oficio']) ? 'Desincorporado' : 'Baja Técnica';
        }
    } else {
        $error_msg = "No se encontró ninguna orden con el número <strong>" . htmlspecialchars($busqueda) . "</strong>. Verifique el N° de Orden que aparece en su comprobante de recepción.";
    }
}

$nombre_sistema = obtenerConfiguracion($conexion, 'nombre_sistema', 'SIGA-UDO');
$nombre_inst    = obtenerConfiguracion($conexion, 'nombre_institucion', 'Universidad de Oriente - Núcleo Monagas');
$slogan         = obtenerConfiguracion($conexion, 'slogan', 'Del pueblo venimos, hacia el pueblo vamos');
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta de Estado de Equipos | <?= e($nombre_sistema) ?></title>
    <!-- Google Fonts Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php require_once __DIR__ . '/includes/theme_head.php'; ?>
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: var(--bg-body, #f8fafc);
            color: var(--text-primary, #0f172a);
        }
        .header-brand {
            background: linear-gradient(135deg, #1d4ed8 0%, #0a1a4a 100%);
            color: #ffffff;
            padding: 30px 0;
            border-bottom: 4px solid var(--accent-cyan, #2f7bff);
        }
        .stepper-wrapper {
            position: relative;
            display: flex;
            justify-content: space-between;
            margin: 30px 0;
        }
        .stepper-wrapper::before {
            content: '';
            position: absolute;
            top: 24px;
            left: 5%;
            right: 5%;
            height: 4px;
            background: var(--border-color, #e2e8f0);
            z-index: 1;
        }
        .step-item {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            width: 25%;
        }
        .step-circle {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--bg-card, #ffffff);
            border: 3px solid var(--border-color, #cbd5e1);
            color: var(--text-muted, #64748b);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            font-weight: 700;
            transition: all 0.3s ease;
        }
        .step-item.completed .step-circle {
            background: #10b981;
            border-color: #10b981;
            color: #ffffff;
            box-shadow: 0 0 12px rgba(16, 185, 129, 0.35);
        }
        .step-item.active .step-circle {
            background: #0284c7;
            border-color: #0284c7;
            color: #ffffff;
            box-shadow: 0 0 15px rgba(2, 132, 199, 0.45);
            animation: pulse-ring 2s infinite;
        }
        .step-item.danger .step-circle {
            background: #ef4444;
            border-color: #ef4444;
            color: #ffffff;
            box-shadow: 0 0 12px rgba(239, 68, 68, 0.35);
        }
        .step-title {
            margin-top: 10px;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-primary, #1e293b);
        }
        .step-desc {
            font-size: 0.75rem;
            color: var(--text-muted, #64748b);
            max-width: 130px;
        }
        @keyframes pulse-ring {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(2, 132, 199, 0.7); }
            70% { transform: scale(1.05); box-shadow: 0 0 0 10px rgba(2, 132, 199, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(2, 132, 199, 0); }
        }
        @media (max-width: 768px) {
            .stepper-wrapper { flex-direction: column; gap: 20px; margin: 20px 0; }
            .stepper-wrapper::before { display: none; }
            .step-item { width: 100%; flex-direction: row; text-align: left; gap: 15px; }
            .step-desc { max-width: none; }
        }
        .theme-toggle-floating {
            position: absolute;
            top: 20px;
            right: 20px;
        }
    </style>
</head>
<body>

<!-- Encabezado Institucional -->
<header class="header-brand position-relative shadow-sm">
    <div class="theme-toggle-floating">
        <button type="button" class="btn btn-outline-light rounded-pill btn-sm px-3" data-theme-toggle aria-pressed="false" title="Cambiar tema">
            <i class="fas fa-sun me-1"></i> Tema
        </button>
    </div>
    <div class="container text-center py-2">
        <div class="d-inline-flex align-items-center gap-3 mb-2">
            <i class="fas fa-microchip fa-2x text-info"></i>
            <h2 class="fw-bold mb-0 text-white tracking-wide"><?= e($nombre_sistema) ?></h2>
        </div>
        <p class="mb-0 text-white-50 small"><?= e($nombre_inst) ?> • Delegación de Computación y Teleinformática</p>
        <p class="mb-0 text-info small fst-italic">"<?= e($slogan) ?>"</p>
    </div>
</header>

<main class="container py-5 flex-grow-1">
    <!-- Barra de Búsqueda -->
    <div class="row justify-content-center mb-5">
        <div class="col-lg-8">
            <div class="card border-0 shadow-lg rounded-4 p-4 text-center">
                <h4 class="fw-bold mb-2">
                    <i class="fas fa-search me-2 text-primary"></i>Consulta de Orden de Servicio
                </h4>
                <p class="text-secondary small mb-4">
                    Ingrese el <strong>Número de Orden</strong> de su comprobante de recepción (ej. <code>ORD-2026-0001</code>) para conocer el estatus técnico de su equipo.
                </p>

                <form method="GET" action="consultar_estado.php" class="d-flex flex-column flex-sm-row gap-2">
                    <div class="input-group input-group-lg flex-grow-1">
                        <span class="input-group-text bg-transparent border-end-0">
                            <i class="fas fa-barcode text-muted"></i>
                        </span>
                        <input type="text" name="codigo" class="form-control border-start-0 text-uppercase fw-bold" 
                               placeholder="Ej: ORD-2026-0001" 
                               value="<?= e($busqueda) ?>" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg rounded-pill px-4 shadow-sm fw-bold">
                        <i class="fas fa-arrow-right me-1"></i> Consultar
                    </button>
                </form>

                <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top small text-muted">
                    <span><i class="fas fa-shield-halved me-1"></i> Acceso público para la comunidad universitaria</span>
                    <a href="auth/login.php" class="text-decoration-none fw-semibold">
                        <i class="fas fa-user-lock me-1"></i> Acceso Personal UDO
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Mensaje de Error / No Encontrado -->
    <?php if ($error_msg): ?>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="alert alert-warning border-0 shadow-sm rounded-4 p-4 text-center">
                <i class="fas fa-triangle-exclamation fa-2x mb-2 text-warning"></i>
                <h6 class="fw-bold mb-2">No se encontró información</h6>
                <p class="small mb-0"><?= $error_msg ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Caso 1: Equipo Encontrado con Orden de Servicio / Historial -->
    <?php if ($resultado): ?>
    <?php
        $estadoActual = $resultado['estado_activo_actual'];
        $nivelDano = $resultado['nivel_dano'];
        
        // Determinar estados del stepper
        $paso1_completado = !empty($resultado['fecha_entrada']);
        $paso2_completado = !empty($resultado['fecha_diagnostico']);
        $paso2_activo     = $paso1_completado && !$paso2_completado && ($estadoActual === 'En Recepción' || $estadoActual === 'En Reparación');
        
        $paso3_completado = !empty($resultado['fecha_fin_reparacion']) || $estadoActual === 'Reparado' || $estadoActual === 'Baja Técnica' || $estadoActual === 'Desincorporado';
        $paso3_activo     = $paso2_completado && !$paso3_completado && ($estadoActual === 'En Reparación');

        $esBaja = ($estadoActual === 'Baja Técnica' || $estadoActual === 'Desincorporado');
        $paso4_completado = ($estadoActual === 'Reparado' && !empty($resultado['fecha_entrega_final'])) || $estadoActual === 'Desincorporado';
        $paso4_activo     = ($estadoActual === 'Reparado' && empty($resultado['fecha_entrega_final'])) || ($estadoActual === 'Baja Técnica');
    ?>
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <!-- Tarjeta de Estado Principal -->
            <div class="card border-0 shadow rounded-4 mb-4 overflow-hidden">
                <div class="card-header bg-body-secondary p-4 border-0 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <?php if (!empty($resultado['numero_orden'])): ?>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1 mb-1">
                                Orden: <?= e($resultado['numero_orden']) ?>
                            </span>
                        <?php elseif (!empty($resultado['numero_oficio'])): ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1 mb-1">
                                Oficio: <?= e($resultado['numero_oficio']) ?>
                            </span>
                        <?php endif; ?>
                        <h4 class="fw-bold mb-0">
                            <?= e($resultado['codigo_udo']) ?> 
                            <small class="text-secondary fs-6 fw-normal">• <?= e($resultado['tipo_equipo']) ?> <?= e($resultado['marca']) ?> <?= e($resultado['modelo']) ?></small>
                        </h4>
                    </div>
                    <div>
                        <?php if ($estadoActual === 'Reparado' && !empty($resultado['fecha_entrega_final'])): ?>
                            <span class="badge bg-success fs-6 rounded-pill px-4 py-2 shadow-sm">
                                <i class="fas fa-handshake me-1"></i> Entregado
                            </span>
                        <?php elseif ($estadoActual === 'Reparado'): ?>
                            <span class="badge bg-success fs-6 rounded-pill px-4 py-2 shadow-sm">
                                <i class="fas fa-check-double me-1"></i> Listo para Retiro
                            </span>
                        <?php elseif ($estadoActual === 'En Reparación'): ?>
                            <span class="badge bg-warning text-dark fs-6 rounded-pill px-4 py-2 shadow-sm">
                                <i class="fas fa-screwdriver-wrench me-1"></i> En Reparación / Taller
                            </span>
                        <?php elseif ($estadoActual === 'En Recepción'): ?>
                            <span class="badge bg-info text-dark fs-6 rounded-pill px-4 py-2 shadow-sm">
                                <i class="fas fa-inbox me-1"></i> En Recepción / Cola
                            </span>
                        <?php elseif ($estadoActual === 'Baja Técnica'): ?>
                            <span class="badge bg-danger fs-6 rounded-pill px-4 py-2 shadow-sm">
                                <i class="fas fa-ban me-1"></i> Dictamen de Baja Técnica
                            </span>
                        <?php elseif ($estadoActual === 'Desincorporado'): ?>
                            <span class="badge bg-dark fs-6 rounded-pill px-4 py-2 shadow-sm">
                                <i class="fas fa-archive me-1"></i> Desincorporado Oficial
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary fs-6 rounded-pill px-4 py-2 shadow-sm">
                                <?= e($estadoActual) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card-body p-4">
                    <!-- Línea de Tiempo / Stepper -->
                    <h6 class="text-muted fw-bold text-uppercase small mb-3">Línea de Tiempo del Servicio</h6>
                    
                    <div class="stepper-wrapper">
                        <!-- Paso 1: Recepción -->
                        <div class="step-item <?= $paso1_completado ? 'completed' : '' ?>">
                            <div class="step-circle"><i class="fas fa-clipboard-check"></i></div>
                            <div class="step-title">1. Recepción</div>
                            <div class="step-desc">
                                <?= !empty($resultado['fecha_entrada']) ? date('d/m/Y', strtotime($resultado['fecha_entrada'])) : 'Pendiente' ?><br>
                                <span class="text-muted">Ingreso formal</span>
                            </div>
                        </div>

                        <!-- Paso 2: Diagnóstico -->
                        <div class="step-item <?= $paso2_completado ? 'completed' : ($paso2_activo ? 'active' : '') ?>">
                            <div class="step-circle"><i class="fas fa-stethoscope"></i></div>
                            <div class="step-title">2. Diagnóstico</div>
                            <div class="step-desc">
                                <?php if ($paso2_completado): ?>
                                    <?= date('d/m/Y', strtotime($resultado['fecha_diagnostico'])) ?><br>
                                    <span class="text-muted">Daño <?= e($nivelDano ?: 'Evaluado') ?></span>
                                <?php elseif ($paso2_activo): ?>
                                    <span class="text-primary fw-semibold">En Evaluación</span>
                                <?php else: ?>
                                    <span class="text-muted">En cola</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Paso 3: Taller -->
                        <div class="step-item <?= $paso3_completado ? ($esBaja ? 'danger' : 'completed') : ($paso3_activo ? 'active' : '') ?>">
                            <div class="step-circle"><i class="fas fa-wrench"></i></div>
                            <div class="step-title">3. Taller Técnico</div>
                            <div class="step-desc">
                                <?php if ($esBaja): ?>
                                    <span class="text-danger fw-semibold">No Reparable</span>
                                <?php elseif ($paso3_completado): ?>
                                    <span class="text-success fw-semibold">Intervención OK</span>
                                <?php elseif ($paso3_activo): ?>
                                    <span class="text-primary fw-semibold">En Taller</span>
                                <?php else: ?>
                                    <span class="text-muted">Pendiente</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Paso 4: Resolución Final -->
                        <div class="step-item <?= $paso4_completado ? ($esBaja ? 'danger' : 'completed') : ($paso4_activo ? ($esBaja ? 'danger' : 'active') : '') ?>">
                            <div class="step-circle">
                                <i class="fas <?= $esBaja ? 'fa-file-shield' : 'fa-handshake' ?>"></i>
                            </div>
                            <div class="step-title">4. <?= $esBaja ? 'Exclusión' : 'Entrega' ?></div>
                            <div class="step-desc">
                                <?php if ($estadoActual === 'Desincorporado'): ?>
                                    <span class="text-danger fw-semibold">Oficio Emitido</span>
                                <?php elseif ($estadoActual === 'Baja Técnica'): ?>
                                    <span class="text-danger">En Aprobación</span>
                                <?php elseif ($estadoActual === 'Reparado' && !empty($resultado['fecha_entrega_final'])): ?>
                                    <span class="text-success fw-semibold">Entregado</span>
                                <?php elseif ($estadoActual === 'Reparado'): ?>
                                    <span class="text-success fw-semibold">¡Listo para Retiro!</span>
                                <?php else: ?>
                                    <span class="text-muted">Pendiente</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Cuadrícula de Información Detallada -->
                    <div class="row g-4 mt-2">
                        <div class="col-md-6">
                            <div class="p-3 bg-body-secondary rounded-3 h-100">
                                <h6 class="fw-bold mb-3 text-primary"><i class="fas fa-laptop me-2"></i>Ficha del Activo</h6>
                                <table class="table table-sm table-borderless mb-0 small">
                                    <tr>
                                        <td class="text-muted" style="width: 40%;">Código UDO:</td>
                                        <td><strong><?= e($resultado['codigo_udo']) ?></strong></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted">Ubicación Asignada:</td>
                                        <td><?= e($resultado['ubicacion']) ?> <?= !empty($resultado['departamento']) ? '('.e($resultado['departamento']).')' : '' ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="p-3 bg-body-secondary rounded-3 h-100">
                                <h6 class="fw-bold mb-3 text-primary"><i class="fas fa-clipboard-list me-2"></i>Detalles del Servicio</h6>
                                <div class="small mb-2">
                                    <span class="text-muted d-block">Falla Reportada Inicialmente:</span>
                                    <span class="fw-semibold"><?= e($resultado['descripcion_falla']) ?></span>
                                </div>
                                <?php if (!empty($resultado['solucion_aplicada'])): ?>
                                <div class="small mb-2 pt-2 border-top">
                                    <span class="text-muted d-block">Solución Técnica Aplicada:</span>
                                    <span class="text-success fw-semibold"><?= e($resultado['solucion_aplicada']) ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($resultado['numero_oficio'])): ?>
                                <div class="small mb-2 pt-2 border-top">
                                    <span class="text-muted d-block">Oficio de Exclusión Asociado:</span>
                                    <span class="text-danger fw-bold"><i class="fas fa-file-contract me-1"></i><?= e($resultado['numero_oficio']) ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Mensaje Orientativo para el Usuario -->
                    <div class="mt-4 p-3 rounded-3 border d-flex align-items-center gap-3 bg-body-tertiary">
                        <i class="fas fa-info-circle fa-2x text-primary"></i>
                        <div class="small">
                            <?php if ($estadoActual === 'Reparado' && !empty($resultado['fecha_entrega_final'])): ?>
                                <strong>Equipo entregado.</strong> Esta orden de servicio ya fue cerrada con la entrega del bien.
                            <?php elseif ($estadoActual === 'Reparado'): ?>
                                <strong>¡Su equipo está listo!</strong> Puede acudir a las oficinas de la Delegación de Computación y Teleinformática en horario universitario para retirar el bien, presentando su carnet o cédula de identidad.
                            <?php elseif ($estadoActual === 'Baja Técnica' || $estadoActual === 'Desincorporado'): ?>
                                <strong>Estado Administrativo:</strong> El equipo ha sido dictaminado en condición de baja técnica irreversible. El expediente se encuentra en trámite conjunto ante la Coordinación Administrativa y la Oficina de Bienes Públicos de la universidad.
                            <?php else: ?>
                                <strong>Equipo en Atención Técnica:</strong> Nuestro personal especializado se encuentra procesando su solicitud. Esta página se actualizará automáticamente conforme avance el diagnóstico y reparación.
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</main>

<footer class="text-center py-4 text-muted small border-top mt-auto bg-body">
    <div class="container">
        <div><?= e($nombre_sistema) ?> • Delegación de Computación y Teleinformática</div>
        <div><?= e($nombre_inst) ?> • Todos los derechos reservados.</div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="/Asset-UDO/assets/js/theme-toggle.js"></script>
<?php if ($resultado && empty($resultado['fecha_entrega_final']) && !in_array($resultado['estado_activo_actual'], ['Desincorporado'], true)): ?>
<script>setTimeout(function () { location.reload(); }, 60000); // la orden sigue abierta: se actualiza cada minuto</script>
<?php endif; ?>
</body>
</html>
