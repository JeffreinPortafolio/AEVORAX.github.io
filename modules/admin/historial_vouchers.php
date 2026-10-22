<?php
require_once __DIR__ . '/../../core/conexion.php';
restringirAcceso(['administrador']);

include __DIR__ . '/../../includes/header.php';

// ============================================================
// FILTROS Y PAGINACIÓN
// ============================================================
$filtro_tipo   = $_GET['tipo'] ?? '';
$filtro_buscar = trim($_GET['buscar'] ?? '');
$pagina        = max(1, (int)($_GET['pagina'] ?? 1));
$por_pagina    = 15;
$offset        = ($pagina - 1) * $por_pagina;

// ============================================================
// CONSULTA PRINCIPAL
// ============================================================
$sql = "SELECT v.*, u.nombre as usuario_nombre, n.numero_orden 
        FROM vouchers v 
        LEFT JOIN usuarios u ON v.generado_por = u.id_usuario 
        LEFT JOIN novedades n ON v.id_novedad = n.id_novedad
        WHERE 1=1";
$params = [];

if (!empty($filtro_tipo)) {
    $sql .= " AND v.tipo = ?";
    $params[] = $filtro_tipo;
}

if (!empty($filtro_buscar)) {
    $sql .= " AND (v.titulo LIKE ? OR u.nombre LIKE ? OR n.numero_orden LIKE ?)";
    $like = "%$filtro_buscar%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// Contar total para paginación
$sql_count = str_replace("SELECT v.*, u.nombre as usuario_nombre, n.numero_orden", "SELECT COUNT(*)", $sql);
$stmt_count = $conexion->prepare($sql_count);
$stmt_count->execute($params);
$total_registros = $stmt_count->fetchColumn();
$total_paginas = ceil($total_registros / $por_pagina);

// Orden y límites
$sql .= " ORDER BY v.fecha_generacion DESC LIMIT $por_pagina OFFSET $offset";
$stmt = $conexion->prepare($sql);
$stmt->execute($params);
$vouchers = $stmt->fetchAll();

// ============================================================
// CONSTANTES PARA TIPOS
// ============================================================
$tipos_voucher = [
    'recepcion' => ['label' => 'Recepción', 'badge' => 'bg-info'],
    'entrega'   => ['label' => 'Entrega', 'badge' => 'bg-success']
];

function getUrlReimpresion($tipo, $id_novedad) {
    if ($tipo === 'recepcion') {
        return url("modules/recepcion/novedad.php?modo=orden&id=" . (int)$id_novedad);
    }
    if ($tipo === 'entrega') {
        return url("modules/tecnico/entregas.php?modo=acta&id=" . (int)$id_novedad);
    }
    return '#';
}
?>

<div class="container-fluid animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
        <div>
            <h3 class="fw-bold mb-0">
                <i class="fas fa-file-alt me-2" style="color: var(--accent-cyan);"></i>Historial de Vouchers
            </h3>
            <p class="text-muted small mt-1">Todos los comprobantes generados (Órdenes de Servicio y Actas de Devolución)</p>
        </div>
        <span class="badge bg-primary rounded-pill px-3 py-2 fs-6 shadow-sm">
            <i class="fas fa-file-invoice me-1"></i> <?= $total_registros ?> registros
        </span>
    </div>

    <!-- FILTROS -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="small text-muted">Buscar (título, usuario, orden)</label>
                    <input type="text" name="buscar" class="form-control" placeholder="Ej: Orden de Servicio, Juan, ORD-2025..." value="<?= e($filtro_buscar) ?>">
                </div>
                <div class="col-md-3">
                    <label class="small text-muted">Tipo de Voucher</label>
                    <select name="tipo" class="form-select">
                        <option value="">Todos</option>
                        <option value="recepcion" <?= $filtro_tipo == 'recepcion' ? 'selected' : '' ?>>Recepción (Orden de Servicio)</option>
                        <option value="entrega" <?= $filtro_tipo == 'entrega' ? 'selected' : '' ?>>Entrega (Acta de Devolución)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 rounded-pill">
                        <i class="fas fa-search me-1"></i> Filtrar
                    </button>
                </div>
                <div class="col-md-2">
                    <a href="historial_vouchers.php" class="btn btn-outline-secondary w-100 rounded-pill">
                        <i class="fas fa-times me-1"></i> Limpiar
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- VISTA ESCRITORIO (TABLA) -->
    <div class="card border-0 shadow-sm rounded-4 vista-escritorio">
        <div class="card-header bg-transparent border-0 py-3 d-flex justify-content-between align-items-center">
            <span><i class="fas fa-list me-2" style="color:var(--accent-cyan);"></i><strong><?= $total_registros ?> voucher(es)</strong></span>
            <small class="text-muted">Página <?= $pagina ?> de <?= $total_paginas ?: 1 ?></small>
        </div>
        <div class="card-body p-0" style="max-height: 70vh; overflow-y: auto;">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr class="text-white small text-uppercase">
                        <th>Tipo</th>
                        <th>Título</th>
                        <th>Orden de Servicio</th>
                        <th>Generado por</th>
                        <th>Fecha / Hora</th>
                        <th class="text-center">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($vouchers) > 0): ?>
                        <?php foreach ($vouchers as $v): 
                            $tipo_info = $tipos_voucher[$v['tipo']] ?? ['label' => ucfirst($v['tipo']), 'badge' => 'bg-secondary'];
                            $url_reimprimir = getUrlReimpresion($v['tipo'], $v['id_novedad']);
                        ?>
                        <tr>
                            <td>
                                <span class="badge <?= $tipo_info['badge'] ?> px-3 py-2">
                                    <i class="fas <?= $v['tipo'] == 'recepcion' ? 'fa-clinic-medical' : 'fa-handshake' ?> me-1"></i>
                                    <?= $tipo_info['label'] ?>
                                </span>
                            </td>
                            <td><?= e($v['titulo']) ?></td>
                            <td><code><?= e($v['numero_orden'] ?? '—') ?></code></td>
                            <td><?= e($v['usuario_nombre'] ?? 'Sistema') ?></td>
                            <td class="text-nowrap">
                                <i class="far fa-calendar-alt me-1 text-muted"></i> <?= date('d/m/Y', strtotime($v['fecha_generacion'])) ?>
                                <br><small class="text-muted"><i class="far fa-clock me-1"></i> <?= date('H:i:s', strtotime($v['fecha_generacion'])) ?></small>
                            </td>
                            <td class="text-center">
                                <a href="<?= e($url_reimprimir) ?>" target="_blank" class="btn btn-sm btn-primary rounded-pill" title="Descargar PDF">
                                    <i class="fas fa-file-pdf me-1"></i> Descargar PDF
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-file-alt fa-3x mb-3 d-block"></i>
                                No hay vouchers registrados con esos filtros.
                            </td>
                        </tr>
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
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>

    <!-- VISTA TARJETAS (MÓVIL / ZOOM ALTO) -->
    <div class="vista-movil">
        <?php if (count($vouchers) > 0): ?>
            <div class="row g-3">
                <?php foreach ($vouchers as $v): 
                    $tipo_info = $tipos_voucher[$v['tipo']] ?? ['label' => ucfirst($v['tipo']), 'badge' => 'bg-secondary'];
                    $url_reimprimir = getUrlReimpresion($v['tipo'], $v['id_novedad']);
                ?>
                <div class="col-12">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <h6 class="fw-bold mb-1"><?= e($v['titulo']) ?></h6>
                                <span class="badge <?= $tipo_info['badge'] ?>"><?= $tipo_info['label'] ?></span>
                            </div>
                            <p class="mb-1 small">Orden: <code><?= e($v['numero_orden'] ?? '—') ?></code></p>
                            <p class="mb-1 small">Generado por: <?= e($v['usuario_nombre'] ?? 'Sistema') ?></p>
                            <p class="mb-2 small">
                                <i class="far fa-calendar-alt me-1"></i> <?= date('d/m/Y H:i', strtotime($v['fecha_generacion'])) ?>
                            </p>
                            <div class="text-end">
                                <a href="<?= e($url_reimprimir) ?>" target="_blank" class="btn btn-sm btn-primary rounded-pill">
                                    <i class="fas fa-file-pdf me-1"></i> Descargar PDF
                                </a>
                            </div>
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
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-file-alt fa-3x mb-3 d-block"></i>
                No hay vouchers registrados con esos filtros.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>