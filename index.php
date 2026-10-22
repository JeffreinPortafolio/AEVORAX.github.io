<?php
require_once __DIR__ . '/core/conexion.php';
restringirAcceso(); // sesión estricta: cualquier rol válido y activo

$rol = $_SESSION['rol'];

// ============================================================
// CONTADORES REALES (Optimizado con GROUP BY en una sola consulta)
// ============================================================

$stmt_estados = $conexion->query("SELECT estado_equipo, COUNT(*) as cantidad FROM activos GROUP BY estado_equipo");
$conteo_estados = $stmt_estados->fetchAll(PDO::FETCH_KEY_PAIR);

$operativos   = (int)($conteo_estados['Operativo'] ?? 0);
$enRecepcion  = (int)($conteo_estados['En Recepción'] ?? 0);
$enReparacion = (int)($conteo_estados['En Reparación'] ?? 0);
$reparados    = (int)($conteo_estados['Reparado'] ?? 0);
$bajas        = (int)($conteo_estados['Desincorporado'] ?? 0);

// "Activo" = equipo en servicio (Operativo o ya Reparado). En recepción, diagnóstico, reparación,
// baja técnica o desincorporado NO cuentan como activos hasta que se repare.
$totalActivos = $operativos + $reparados;

// Usuarios activos (solo visible para administrador)
$totalUsuarios = ($rol == ROL_ADMIN) ? (int)$conexion->query("SELECT COUNT(*) FROM usuarios WHERE estado = 'activo'")->fetchColumn() : 0;

// ============================================================
// DATOS PARA EL GRÁFICO DE PASTEL
// ============================================================
$estados_mostrar = ['Operativo', 'En Recepción', 'En Reparación', 'Reparado', 'Desincorporado'];
$colores_estado = [
    'Operativo'      => '#22c55e',   // verde
    'En Recepción'   => '#eab308',   // amarillo
    'En Reparación'  => '#3b82f6',   // azul
    'Reparado'       => '#2f7bff',   // morado
    'Desincorporado' => '#ef4444'    // rojo
];

$labels = [];
$data   = [];
$colors = [];
foreach ($estados_mostrar as $est) {
    $labels[] = $est;
    $data[]   = (int)($conteo_estados[$est] ?? 0);
    $colors[] = $colores_estado[$est] ?? '#6b7280';
}

// Últimas actividades (solo para administrador)
$recientes = ($rol == ROL_ADMIN) ? $conexion->query("SELECT usuario_nombre, accion, fecha_hora FROM auditoria ORDER BY fecha_hora DESC LIMIT 10")->fetchAll() : [];

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <!-- Encabezado -->
    <div class="page-header">
        <div>
            <h3 class="fw-bold mb-1">
                <i class="fas fa-tachometer-alt me-2" style="color:var(--accent-cyan);"></i>Panel de Control
            </h3>
            <p class="text-muted mb-0">
                Bienvenido, <strong><?= e($_SESSION['nombre']) ?></strong> — 
                <span class="badge badge-info"><?= ucfirst($_SESSION['rol']) ?></span>
            </p>
        </div>
        <div class="text-end small text-muted">
            <i class="far fa-calendar-alt me-1"></i> <?= date('d/m/Y') ?> 
            <i class="far fa-clock ms-2 me-1"></i> <?= date('H:i') ?>
        </div>
    </div>

    <!-- Tarjetas de resumen según rol -->
    <div class="row g-4 mb-4">
        <?php if ($rol == 'administrador'): ?>
            <div class="col-xxl-2 col-xl-4 col-md-4 col-6">
                <div class="card stat-card" style="--stat-color:var(--accent-cyan);"><div class="stat-icon"><i class="fas fa-boxes"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$totalActivos ?>"><?= $totalActivos ?></div><div class="stat-label">Total Activos</div></div></div>
            </div>
            <div class="col-xxl-2 col-xl-4 col-md-4 col-6">
                <div class="card stat-card" style="--stat-color:#22c55e;"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$operativos ?>"><?= $operativos ?></div><div class="stat-label">Operativos</div></div></div>
            </div>
            <div class="col-xxl-2 col-xl-4 col-md-4 col-6">
                <div class="card stat-card" style="--stat-color:#eab308;"><div class="stat-icon"><i class="fas fa-clock"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$enRecepcion ?>"><?= $enRecepcion ?></div><div class="stat-label">En Recepción</div></div></div>
            </div>
            <div class="col-xxl-2 col-xl-4 col-md-4 col-6">
                <div class="card stat-card" style="--stat-color:#3b82f6;"><div class="stat-icon"><i class="fas fa-tools"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$enReparacion ?>"><?= $enReparacion ?></div><div class="stat-label">En Taller</div></div></div>
            </div>
            <div class="col-xxl-2 col-xl-4 col-md-4 col-6">
                <div class="card stat-card" style="--stat-color:#2f7bff;"><div class="stat-icon"><i class="fas fa-clipboard-check"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$reparados ?>"><?= $reparados ?></div><div class="stat-label">Por entregar</div></div></div>
            </div>
            <div class="col-xxl-2 col-xl-4 col-md-4 col-6">
                <div class="card stat-card" style="--stat-color:#ef4444;"><div class="stat-icon"><i class="fas fa-ban"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$bajas ?>"><?= $bajas ?></div><div class="stat-label">Bajas</div></div></div>
            </div>
        <?php elseif ($rol == 'tecnico'): ?>
            <div class="col-xl-3 col-md-6 col-6">
                <div class="card stat-card" style="--stat-color:var(--accent-cyan);"><div class="stat-icon"><i class="fas fa-boxes"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$totalActivos ?>"><?= $totalActivos ?></div><div class="stat-label">Total Activos</div></div></div>
            </div>
            <div class="col-xl-3 col-md-6 col-6">
                <div class="card stat-card" style="--stat-color:#3b82f6;"><div class="stat-icon"><i class="fas fa-tools"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$enReparacion ?>"><?= $enReparacion ?></div><div class="stat-label">En Taller</div></div></div>
            </div>
            <div class="col-xl-3 col-md-6 col-6">
                <div class="card stat-card" style="--stat-color:#2f7bff;"><div class="stat-icon"><i class="fas fa-clipboard-check"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$reparados ?>"><?= $reparados ?></div><div class="stat-label">Por entregar</div></div></div>
            </div>
            <div class="col-xl-3 col-md-6 col-6">
                <div class="card stat-card" style="--stat-color:#ef4444;"><div class="stat-icon"><i class="fas fa-ban"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$bajas ?>"><?= $bajas ?></div><div class="stat-label">Bajas</div></div></div>
            </div>
        <?php elseif ($rol == 'recepcionista'): ?>
            <div class="col-xl-3 col-md-6 col-6">
                <div class="card stat-card" style="--stat-color:var(--accent-cyan);"><div class="stat-icon"><i class="fas fa-boxes"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$totalActivos ?>"><?= $totalActivos ?></div><div class="stat-label">Total Activos</div></div></div>
            </div>
            <div class="col-xl-3 col-md-6 col-6">
                <div class="card stat-card" style="--stat-color:#22c55e;"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$operativos ?>"><?= $operativos ?></div><div class="stat-label">Operativos</div></div></div>
            </div>
            <div class="col-xl-3 col-md-6 col-6">
                <div class="card stat-card" style="--stat-color:#eab308;"><div class="stat-icon"><i class="fas fa-clock"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$enRecepcion ?>"><?= $enRecepcion ?></div><div class="stat-label">En Recepción</div></div></div>
            </div>
            <div class="col-xl-3 col-md-6 col-6">
                <div class="card stat-card" style="--stat-color:#2f7bff;"><div class="stat-icon"><i class="fas fa-clipboard-check"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$reparados ?>"><?= $reparados ?></div><div class="stat-label">Por entregar</div></div></div>
            </div>
        <?php elseif ($rol == 'consultor'): ?>
            <div class="col-md-6 col-6">
                <div class="card stat-card" style="--stat-color:var(--accent-cyan);"><div class="stat-icon"><i class="fas fa-boxes"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$totalActivos ?>"><?= $totalActivos ?></div><div class="stat-label">Total Activos</div></div></div>
            </div>
            <div class="col-md-6 col-6">
                <div class="card stat-card" style="--stat-color:#22c55e;"><div class="stat-icon"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$operativos ?>"><?= $operativos ?></div><div class="stat-label">Operativos</div></div></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Segunda fila: Gráfico y Actividad Reciente -->
    <div class="row g-4">
        <?php if ($rol == 'administrador'): ?>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header fw-bold"><i class="fas fa-chart-pie me-2" style="color:var(--accent-cyan);"></i>Distribución de Equipos</div>
                    <div class="card-body d-flex align-items-center justify-content-center">
                        <div style="width:100%; max-width:300px; height:250px;" class="mx-auto"><canvas id="pieChart"></canvas></div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header fw-bold"><i class="fas fa-history me-2" style="color:var(--accent-cyan);"></i>Actividad Reciente</div>
                    <div class="card-body p-0" style="max-height: 280px; overflow-y: auto;">
                        <table class="table table-sm mb-0">
                            <tbody>
                                <?php if ($recientes): foreach ($recientes as $r): ?>
                                    <tr><td class="text-muted small"><?= date('d/m H:i', strtotime($r['fecha_hora'])) ?></td><td><strong><?= e($r['usuario_nombre']) ?></strong></td><td class="small"><?= e($r['accion']) ?></td></tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="3" class="text-muted text-center py-3">Sin actividad registrada.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header fw-bold"><i class="fas fa-chart-pie me-2" style="color:var(--accent-cyan);"></i>Distribución de Equipos por Estado</div>
                    <div class="card-body d-flex align-items-center justify-content-center">
                        <div style="width:100%; max-width:400px; height:250px;"><canvas id="pieChart"></canvas></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Métricas adicionales solo para administrador -->
    <?php if ($rol == 'administrador'): ?>
    <div class="row g-4 mt-2">
        <div class="col-md-4">
            <div class="card stat-card" style="--stat-color:var(--accent-cyan);"><div class="stat-icon"><i class="fas fa-users"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$totalUsuarios ?>"><?= $totalUsuarios ?></div><div class="stat-label">Usuarios Activos</div></div></div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card" style="--stat-color:#2f7bff;"><div class="stat-icon"><i class="fas fa-file-alt"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$totalActivos ?>"><?= $totalActivos ?></div><div class="stat-label">Total de Activos</div></div></div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card" style="--stat-color:#2f7bff;"><div class="stat-icon"><i class="fas fa-hand-holding-heart"></i></div><div class="stat-body"><div class="stat-value" data-count="<?= (int)$reparados ?>"><?= $reparados ?></div><div class="stat-label">Equipos por entregar</div></div></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Accesos rápidos -->
    <div class="mt-4">
        <h5 class="fw-bold mb-3"><i class="fas fa-bolt me-2" style="color:var(--accent-cyan);"></i>Accesos Rápidos</h5>
        <div class="d-flex flex-wrap gap-2">
            <?php if(in_array($rol, ['administrador','recepcionista'])): ?>
            <a href="modules/recepcion/novedad.php" class="btn btn-outline-primary rounded-pill"><i class="fas fa-clinic-medical me-1"></i> Nueva Recepción</a>
            <a href="modules/activos/registro.php" class="btn btn-outline-primary rounded-pill"><i class="fas fa-plus-circle me-1"></i> Registrar Activo</a>
            <?php endif; ?>
            <a href="modules/activos/lista.php" class="btn btn-outline-light rounded-pill"><i class="fas fa-list me-1"></i> Ver Inventario</a>
            <?php if($rol == 'administrador'): ?>
            <a href="modules/admin/reportes.php" class="btn btn-outline-light rounded-pill"><i class="fas fa-chart-bar me-1"></i> Reportes</a>
            <a href="modules/admin/usuarios.php" class="btn btn-outline-light rounded-pill"><i class="fas fa-users me-1"></i> Usuarios</a>
            <?php elseif($rol == 'consultor'): ?>
            <a href="modules/admin/reportes.php" class="btn btn-outline-light rounded-pill"><i class="fas fa-chart-bar me-1"></i> Reportes</a>
            <?php endif; ?>
            <?php if($rol == 'tecnico'): ?>
            <a href="modules/tecnico/diagnostico.php" class="btn btn-outline-primary rounded-pill"><i class="fas fa-stethoscope me-1"></i> Diagnóstico</a>
            <a href="modules/tecnico/reparar.php" class="btn btn-outline-primary rounded-pill"><i class="fas fa-tools me-1"></i> Taller</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('pieChart').getContext('2d');
const labels = <?= json_encode($labels) ?>;
const data   = <?= json_encode($data) ?>;
const colors = <?= json_encode($colors) ?>;

function getChartThemeColors() {
    const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    return {
        borderColor: isDark ? '#1a1e29' : '#ffffff',
        textColor: isDark ? '#cdd3e0' : '#475569'
    };
}

let themeCols = getChartThemeColors();
const pieChart = new Chart(ctx, {
    type: 'pie',
    data: { 
        labels: labels, 
        datasets: [{ 
            data: data, 
            backgroundColor: colors,
            borderColor: themeCols.borderColor, 
            borderWidth: 2 
        }] 
    },
    options: { 
        responsive: true, 
        maintainAspectRatio: false, 
        plugins: { 
            legend: { 
                position: 'bottom', 
                labels: { color: themeCols.textColor } 
            } 
        } 
    }
});

// Actualizar colores del gráfico si cambia el tema
const observer = new MutationObserver(() => {
    const nextCols = getChartThemeColors();
    pieChart.data.datasets[0].borderColor = nextCols.borderColor;
    pieChart.options.plugins.legend.labels.color = nextCols.textColor;
    pieChart.update();
});
observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>