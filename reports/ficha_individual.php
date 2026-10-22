<?php
require_once __DIR__ . '/../core/conexion.php';
require_once __DIR__ . '/../includes/formato_documento.php';
restringirAcceso(['administrador', 'recepcionista', 'tecnico', 'consultor']);

$id_activo = $_GET['id'] ?? 0;
if (!$id_activo) die("ID de activo no especificado.");

$stmt = $conexion->prepare("SELECT * FROM activos WHERE id_activo = ?");
$stmt->execute([$id_activo]);
$activo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$activo) die("Activo no encontrado.");

$stRep = $conexion->prepare("SELECT COUNT(*) FROM novedades WHERE id_activo = ? AND estado_final = 'Reparado'");
$stRep->execute([$id_activo]);
$veces_reparado = (int)$stRep->fetchColumn();

$foto_base64 = '';
if (!empty($activo['foto_url'])) {
    $foto_base64 = convertirImagenABase64($activo['foto_url']);
}

$colorEstado = getEstadoColor($activo['estado_equipo'] ?? '');
$claseBadge = 'badge-info';
if ($colorEstado === 'success') $claseBadge = 'badge-operativo';
elseif ($colorEstado === 'warning') $claseBadge = 'badge-taller';
elseif ($colorEstado === 'danger') $claseBadge = 'badge-baja';

ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ficha Técnica – <?= htmlspecialchars($activo['codigo_udo']) ?></title>
    <?php renderEstilosFormato('portrait'); ?>
    <style>
        .foto-container {
            text-align: center;
            margin: 10px 0 14px 0;
            page-break-inside: avoid;
        }
        .foto-container img {
            max-height: 140px;
            max-width: 220px;
            border: 1px solid #cbd5e1;
            padding: 3px;
            background: #ffffff;
        }
        .foto-caption {
            font-size: 7.5pt;
            color: #64748b;
            margin-top: 2px;
        }
    </style>
</head>
<body>
    <?php renderCintilloUDO('Delegación de Computación y Teleinformática'); ?>

    <div class="doc-fecha">
        Maturín, <?= date('d/m/Y') ?>
    </div>

    <div class="doc-titulo">FICHA TÉCNICA INDIVIDUAL DE ACTIVO</div>
    <div class="doc-subtitulo">EXPEDIENTE DE BIEN MUEBLE INSTITUCIONAL</div>

    <table class="meta-box">
        <tr>
            <td style="width: 50%;"><strong>CÓDIGO INSTITUCIONAL:</strong> <span style="font-size: 10pt; font-weight:bold; color:#003366;"><?= htmlspecialchars($activo['codigo_udo']) ?></span></td>
            <td style="width: 50%;"><strong>ESTADO PATRIMONIAL:</strong> <span class="badge-estado <?= $claseBadge ?>"><?= e($activo['estado_equipo']) ?></span></td>
        </tr>
        <tr>
            <td><strong>CATEGORÍA:</strong> <?= htmlspecialchars($activo['tipo_equipo']) ?></td>
            <td><strong>FECHA DE REGISTRO:</strong> <?= date('d/m/Y H:i', strtotime($activo['fecha_registro'])) ?></td>
        </tr>
    </table>

    <?php if ($foto_base64): ?>
    <div class="foto-container">
        <img src="<?= $foto_base64 ?>" alt="Fotografía del equipo">
        <div class="foto-caption">Registro fotográfico del activo patrimonial</div>
    </div>
    <?php endif; ?>

    <table class="tabla-datos">
        <tr><th>Código UDO</th><td><strong><?= htmlspecialchars($activo['codigo_udo']) ?></strong></td></tr>
        <tr><th>Tipo de Equipo</th><td><?= htmlspecialchars($activo['tipo_equipo']) ?></td></tr>
        <tr><th>Marca y Modelo</th><td><?= htmlspecialchars($activo['marca']) ?> <?= htmlspecialchars($activo['modelo']) ?></td></tr>
        <tr><th>Número de Serial</th><td><code><?= htmlspecialchars($activo['serial'] ?: 'NO VISIBLE / NO POSEE') ?></code></td></tr>
        <tr><th>Ubicación Física</th><td><?= htmlspecialchars($activo['ubicacion']) ?></td></tr>
        <tr><th>Departamento / Área</th><td><?= htmlspecialchars($activo['departamento'] ?? '—') ?></td></tr>
        <tr><th>Responsable / Custodio</th><td><strong><?= htmlspecialchars($activo['responsable_actual'] ?? 'No asignado') ?></strong></td></tr>
        <tr><th>Cédula de Identidad</th><td><?= htmlspecialchars($activo['cedula_responsable'] ?? '—') ?></td></tr>
        <tr><th>Fecha de Adquisición</th><td><?= $activo['fecha_adquisicion'] ? date('d/m/Y', strtotime($activo['fecha_adquisicion'])) : '—' ?></td></tr>
        <tr><th>Valor de Adquisición</th><td><?= $activo['valor_compra'] > 0 ? '$ ' . number_format($activo['valor_compra'], 2) : 'No especificado' ?></td></tr>
        <tr><th>Observaciones Técnicas</th><td><?= nl2br(htmlspecialchars($activo['observaciones'] ?? 'Sin observaciones registradas.')) ?></td></tr>
        <tr><th>Veces Reparado</th><td><strong><?= $veces_reparado ?></strong></td></tr>
        <tr><th>Última Actualización</th><td><?= $activo['ultima_modificacion'] ? date('d/m/Y H:i', strtotime($activo['ultima_modificacion'])) : '—' ?></td></tr>
    </table>

    <table class="firmas-grid">
        <tr>
            <td>
                <div class="linea-firma"></div>
                <div class="firma-nombre"><?= htmlspecialchars($activo['responsable_actual'] ?? 'Responsable / Custodio') ?></div>
                <div class="firma-cargo"><?= !empty($activo['cedula_responsable']) ? 'C.I. ' . htmlspecialchars($activo['cedula_responsable']) : 'Custodio del Activo' ?></div>
            </td>
            <td>
                <div class="linea-firma"></div>
                <div class="firma-nombre">Delegación de Computación y Teleinformática</div>
                <div class="firma-cargo">Universidad de Oriente • Núcleo Monagas</div>
            </td>
        </tr>
    </table>

    <?php renderFooterUDO($activo['codigo_udo']); ?>
</body>
</html>
<?php
$html = ob_get_clean();

// Si se pide explícitamente HTML para depuración en pantalla
if (isset($_GET['html']) && $_GET['html'] == '1') {
    echo $html;
    exit;
}

// Generar PDF nativo con Dompdf
$descargar = isset($_GET['descargar']) && $_GET['descargar'] == '1';
$nombreArchivo = 'Ficha_Tecnica_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $activo['codigo_udo']) . '.pdf';
generarPDF($html, $nombreArchivo, 'letter', 'portrait', $descargar);