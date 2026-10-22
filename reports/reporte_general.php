<?php
require_once __DIR__ . '/../core/conexion.php';
require_once __DIR__ . '/../includes/formato_documento.php';
restringirAcceso(['administrador', 'recepcionista', 'tecnico', 'consultor']);

$filtro_estado   = $_GET['estado'] ?? '';
$filtro_tipo     = $_GET['tipo'] ?? '';
$filtro_busqueda = $_GET['buscar'] ?? '';

$sql = "SELECT * FROM activos WHERE 1=1";
$params = [];
if (!empty($filtro_estado)) {
    $sql .= " AND estado_equipo = ?";
    $params[] = $filtro_estado;
} else {
    $sql .= " AND estado_equipo <> 'Desincorporado'";
}
if (!empty($filtro_tipo)) {
    $sql .= " AND tipo_equipo = ?";
    $params[] = $filtro_tipo;
}
if (!empty($filtro_busqueda)) {
    $sql .= " AND (codigo_udo LIKE ? OR marca LIKE ? OR modelo LIKE ? OR serial LIKE ? OR ubicacion LIKE ?)";
    $buscar = "%$filtro_busqueda%";
    $params = array_merge($params, [$buscar, $buscar, $buscar, $buscar, $buscar]);
}
$sql .= " ORDER BY codigo_udo ASC";
$stmt = $conexion->prepare($sql);
$stmt->execute($params);
$activos = $stmt->fetchAll();

ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte General de Inventario</title>
    <?php renderEstilosFormato('landscape'); ?>
    <style>
        .chips-filtros {
            font-size: 8pt;
            color: #475569;
            margin-bottom: 10px;
            background-color: #f8fafc;
            padding: 5px 10px;
            border: 1px solid #e2e8f0;
        }
    </style>
</head>
<body>
    <?php renderCintilloUDO('Delegación de Computación y Teleinformática'); ?>

    <div class="doc-fecha">
        Maturín, <?= date('d/m/Y') ?>
    </div>

    <div class="doc-titulo">REPORTE GENERAL DE INVENTARIO DE ACTIVOS</div>
    <div class="doc-subtitulo">DELEGACIÓN DE COMPUTACIÓN Y TELEINFORMÁTICA • NÚCLEO DE MONAGAS</div>

    <div class="chips-filtros">
        <strong>Total de activos listados:</strong> <?= count($activos) ?>
        <?php if ($filtro_estado): ?> • <strong>Estado:</strong> <?= htmlspecialchars($filtro_estado) ?><?php endif; ?>
        <?php if ($filtro_tipo): ?> • <strong>Tipo:</strong> <?= htmlspecialchars($filtro_tipo) ?><?php endif; ?>
        <?php if ($filtro_busqueda): ?> • <strong>Búsqueda:</strong> "<?= htmlspecialchars($filtro_busqueda) ?>"<?php endif; ?>
        • <strong>Fecha de corte:</strong> <?= date('d/m/Y H:i') ?>
    </div>

    <table class="tabla-formal">
        <thead>
            <tr>
                <th style="width: 12%;">Código UDO</th>
                <th style="width: 14%;">Tipo</th>
                <th style="width: 20%;">Marca / Modelo</th>
                <th style="width: 14%;">Serial</th>
                <th style="width: 18%;">Ubicación / Dpto.</th>
                <th style="width: 11%;">Estado</th>
                <th style="width: 11%;">Última Modif.</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($activos) > 0): 
                $i = 0;
                foreach ($activos as $a): 
                $colorEstado = getEstadoColor($a['estado_equipo'] ?? '');
                $badgeClass = 'badge-info';
                if ($colorEstado === 'success') $badgeClass = 'badge-operativo';
                elseif ($colorEstado === 'warning') $badgeClass = 'badge-taller';
                elseif ($colorEstado === 'danger') $badgeClass = 'badge-baja';
                $i++;
            ?>
            <tr class="<?= $i % 2 === 0 ? 'fila-par' : '' ?>">
                <td><strong><?= htmlspecialchars($a['codigo_udo']) ?></strong></td>
                <td><?= htmlspecialchars($a['tipo_equipo']) ?></td>
                <td><?= htmlspecialchars($a['marca']) ?> <?= htmlspecialchars($a['modelo']) ?></td>
                <td><code><?= htmlspecialchars($a['serial'] ?: 'S/S') ?></code></td>
                <td>
                    <?= htmlspecialchars($a['ubicacion']) ?>
                    <?php if (!empty($a['departamento'])): ?>
                        <br><small style="color:#64748b;"><?= htmlspecialchars($a['departamento']) ?></small>
                    <?php endif; ?>
                </td>
                <td><span class="badge-estado <?= $badgeClass ?>"><?= e($a['estado_equipo']) ?></span></td>
                <td><?= $a['ultima_modificacion'] ? date('d/m/Y', strtotime($a['ultima_modificacion'])) : '—' ?></td>
            </tr>
            <?php endforeach; else: ?>
            <tr>
                <td colspan="7" style="text-align:center; padding: 15px; color:#64748b;">
                    No se encontraron registros de activos que coincidan con los criterios seleccionados.
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <table class="firmas-grid">
        <tr>
            <td>
                <div class="linea-firma"></div>
                <div class="firma-nombre">Responsable de Inventario y Control de Bienes</div>
                <div class="firma-cargo">Delegación de Computación y Teleinformática</div>
            </td>
            <td>
                <div class="linea-firma"></div>
                <div class="firma-nombre">Jefatura / Delegado de Computación</div>
                <div class="firma-cargo">Universidad de Oriente • Núcleo Monagas</div>
            </td>
        </tr>
    </table>

    <?php renderFooterUDO('REP-INV-' . date('Ymd')); ?>
</body>
</html>
<?php
$html = ob_get_clean();

if (isset($_GET['html']) && $_GET['html'] == '1') {
    echo $html;
    exit;
}

$descargar = isset($_GET['descargar']) && $_GET['descargar'] == '1';
generarPDF($html, 'Reporte_General_Inventario_' . date('Ymd_His') . '.pdf', 'letter', 'landscape', $descargar);