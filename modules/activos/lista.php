<?php
require_once __DIR__ . '/../../core/conexion.php';
restringirAcceso(['administrador', 'recepcionista', 'tecnico', 'consultor']);
include __DIR__ . '/../../includes/header.php';

// Filtros
$filtro_estado   = $_GET['estado'] ?? '';
$filtro_tipo     = $_GET['tipo'] ?? '';
$filtro_busqueda = trim($_GET['buscar'] ?? '');

$estados = ['Operativo', 'En Recepción', 'En Reparación', 'Reparado', 'Baja Técnica', 'Desincorporado'];
$tipos   = ['Laptop', 'Desktop', 'Monitor', 'Impresora', 'Servidor', 'Switch', 'Router', 'Otro'];
$puede_crear = in_array($_SESSION['rol'], ['administrador', 'recepcionista']);

$sql = "SELECT a.*, (SELECT COUNT(*) FROM novedades n WHERE n.id_activo = a.id_activo AND n.estado_final = 'Reparado') AS veces_reparado FROM activos a WHERE 1=1";
$params = [];
if (!empty($filtro_estado)) {
    $sql .= " AND a.estado_equipo = ?";
    $params[] = $filtro_estado;
} else {
    // Los equipos dados de baja (Desincorporado) ya no se listan como activos
    $sql .= " AND a.estado_equipo <> 'Desincorporado'";
}
if (!empty($filtro_tipo)) {
    $sql .= " AND a.tipo_equipo = ?";
    $params[] = $filtro_tipo;
}
if (!empty($filtro_busqueda)) {
    $sql .= " AND (a.codigo_udo LIKE ? OR a.marca LIKE ? OR a.modelo LIKE ? OR a.serial LIKE ? OR a.ubicacion LIKE ?)";
    $buscar = "%$filtro_busqueda%";
    $params = array_merge($params, [$buscar, $buscar, $buscar, $buscar, $buscar]);
}
$sql .= " ORDER BY a.codigo_udo ASC";
$stmt = $conexion->prepare($sql);
$stmt->execute($params);
$activos = $stmt->fetchAll();

$total_filtrado = count($activos);
$hay_filtros = ($filtro_estado !== '' || $filtro_tipo !== '' || $filtro_busqueda !== '');
?>

<div class="container-fluid animate__animated animate__fadeIn px-2 px-md-4">
    <div class="page-header">
        <div>
            <h3 class="fw-bold mb-0" style="color: var(--text-primary);">
                <i class="fas fa-boxes me-2" style="color: var(--accent-cyan);"></i>Inventario General
            </h3>
            <p class="text-muted small mt-1 mb-0">Listado completo de activos registrados en SIGA-UDO</p>
        </div>
        <?php if ($puede_crear): ?>
        <a href="registro.php" class="btn btn-primary rounded-pill d-none d-sm-inline-flex align-items-center"><i class="fas fa-plus me-2"></i>Nuevo Activo</a>
        <?php endif; ?>
    </div>

    <!-- Filtros: buscador + chips de estado con scroll horizontal + tipo -->
    <form method="GET" class="inv-filters card border-0 shadow-sm" id="formFiltros">
        <div class="inv-filters-row">
            <div class="inv-search">
                <i class="fas fa-search"></i>
                <input type="search" name="buscar" class="form-control" enterkeyhint="search"
                       placeholder="Buscar código, marca, serial…" value="<?= e($filtro_busqueda) ?>">
            </div>
            <select name="tipo" class="form-select inv-tipo" aria-label="Tipo de equipo" onchange="this.form.submit()">
                <option value="">Todos los tipos</option>
                <?php foreach ($tipos as $t): ?>
                <option value="<?= e($t) ?>" <?= $filtro_tipo === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary inv-go"><i class="fas fa-search me-1"></i><span>Filtrar</span></button>
        </div>
        <div class="chips" role="radiogroup" aria-label="Estado">
            <label class="chip"><input type="radio" name="estado" value="" <?= $filtro_estado === '' ? 'checked' : '' ?> onchange="this.form.submit()"><span>Todos</span></label>
            <?php foreach ($estados as $est): ?>
            <label class="chip"><input type="radio" name="estado" value="<?= e($est) ?>" <?= $filtro_estado === $est ? 'checked' : '' ?> onchange="this.form.submit()"><span><?= e($est) ?></span></label>
            <?php endforeach; ?>
        </div>
    </form>

    <!-- Contenedor principal: tabla en escritorio, tarjetas en móvil/tablet -->
    <div class="card border-0 shadow-sm">
        <div class="card-toolbar">
            <div class="title">
                <i class="fas fa-list" style="color:var(--accent-cyan);"></i>
                <span>Activos</span>
                <span class="badge badge-info"><?= $total_filtrado ?> registro(s)</span>
                <?php if ($hay_filtros): ?>
                <a href="lista.php" class="small ms-1 text-nowrap"><i class="fas fa-times-circle me-1"></i>Limpiar</a>
                <?php endif; ?>
            </div>
            <div class="actions">
                <a href="../../reports/reporte_general.php?<?= http_build_query($_GET) ?>" target="_blank" class="btn btn-primary btn-sm">
                    <i class="fas fa-file-pdf me-1"></i> PDF General
                </a>
            </div>
        </div>

        <!-- VISTA ESCRITORIO -->
        <div class="card-body p-0 vista-escritorio">
            <div class="table-scroll">
                <table class="table table-hover align-middle mb-0" id="tablaActivosDesktop">
                    <thead>
                        <tr>
                            <th>Img</th>
                            <th>Código UDO</th>
                            <th>Equipo</th>
                            <th>Marca / Modelo</th>
                            <th>Serial</th>
                            <th>Ubicación</th>
                            <th>Estado</th>
                            <th class="text-center">Reparado</th>
                            <th>Última modif.</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($total_filtrado > 0): foreach ($activos as $a):
                            $color_estado = getEstadoColor($a['estado_equipo']);
                            $icono_estado = getEstadoIcono($a['estado_equipo']);
                            if (!empty($a['foto_url']) && file_exists(__DIR__.'/../../'.$a['foto_url'])) {
                                $imgHtml = "<img src='" . e('../../'.$a['foto_url']) . "' class='rounded' style='width: 34px; height: 34px; object-fit: cover;' alt=''>";
                            } else {
                                $imgHtml = getIconoEquipo('', $a['tipo_equipo']);
                            }
                            $ultima_mod = $a['ultima_modificacion'] ?? $a['fecha_registro'];
                            $fecha_mostrada = ($ultima_mod && $ultima_mod != '0000-00-00 00:00:00') ? date('d/m/Y H:i', strtotime($ultima_mod)) : '—';
                        ?>
                        <tr>
                            <td class="text-center"><?= $imgHtml ?></td>
                            <td><span class="fw-bold text-nowrap" style="color:var(--accent-cyan);"><?= e($a['codigo_udo']) ?></span></td>
                            <td class="text-nowrap"><?= e($a['tipo_equipo']) ?></td>
                            <td><span class="d-block"><?= e($a['marca']) ?></span><small class="text-muted"><?= e($a['modelo']) ?></small></td>
                            <td><code><?= e($a['serial']) ?></code></td>
                            <td><i class="fas fa-map-marker-alt me-1 small" style="color:var(--accent-pink);"></i><?= e($a['ubicacion']) ?></td>
                            <td><span class="badge bg-<?= $color_estado ?> text-nowrap"><i class="fas <?= $icono_estado ?> me-1"></i><?= e($a['estado_equipo']) ?></span></td>
                            <td class="text-center"><span class="badge bg-secondary"><?= (int)$a['veces_reparado'] ?>×</span></td>
                            <td class="small text-nowrap"><i class="far fa-clock me-1 text-muted"></i><?= $fecha_mostrada ?></td>
                            <td>
                                <div class="d-flex gap-1 justify-content-center flex-nowrap">
                                    <a href="detalle.php?id=<?= $a['id_activo'] ?>" class="btn btn-sm btn-outline-info" title="Mostrar más detalles"><i class="fas fa-eye"></i></a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr>
                            <td colspan="10" class="empty-state">
                                <i class="fas fa-box-open fa-3x mb-3 d-block"></i>
                                No hay activos registrados con esos filtros.
                                <?php if(in_array($_SESSION['rol'], ['administrador', 'recepcionista'])): ?>
                                    <br><a href="registro.php" class="btn btn-primary btn-sm mt-3">Registrar primer activo</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- VISTA TARJETAS (móvil y tablet) -->
        <div class="card-body vista-movil">
            <?php if ($total_filtrado > 0): ?>
            <div class="inv-list">
                <?php foreach ($activos as $i => $a):
                    $color_estado = getEstadoColor($a['estado_equipo']);
                    $icono_estado = getEstadoIcono($a['estado_equipo']);
                    if (!empty($a['foto_url']) && file_exists(__DIR__.'/../../'.$a['foto_url'])) {
                        $imgHtml = "<img src='" . e('../../'.$a['foto_url']) . "' alt='' loading='lazy'>";
                    } else {
                        $imgHtml = getIconoEquipo('', $a['tipo_equipo']);
                    }
                    $ultima_mod = $a['ultima_modificacion'] ?? $a['fecha_registro'];
                    $fecha_mostrada = ($ultima_mod && $ultima_mod != '0000-00-00 00:00:00') ? date('d/m/Y H:i', strtotime($ultima_mod)) : '—';
                ?>
                <article class="inv-card st-<?= e($color_estado) ?>" style="--i:<?= min($i, 12) ?>">
                    <a class="inv-main" href="detalle.php?id=<?= (int)$a['id_activo'] ?>">
                        <div class="inv-top">
                            <div class="inv-thumb"><?= $imgHtml ?></div>
                            <div class="inv-title">
                                <div class="inv-code"><?= e($a['codigo_udo']) ?></div>
                                <div class="inv-sub"><?= e($a['tipo_equipo']) ?> · <?= e(trim($a['marca'] . ' ' . $a['modelo'])) ?></div>
                            </div>
                            <i class="fas fa-chevron-right inv-arrow"></i>
                        </div>
                        <div class="inv-meta">
                            <span class="badge bg-<?= $color_estado ?>"><i class="fas <?= $icono_estado ?> me-1"></i><?= e($a['estado_equipo']) ?></span>
                            <span class="inv-chip"><i class="fas fa-map-marker-alt"></i><?= e($a['ubicacion']) ?></span>
                        </div>
                    </a>
                    <details class="inv-more">
                        <summary><span>Más datos</span><i class="fas fa-chevron-down"></i></summary>
                        <dl class="inv-grid">
                            <div><dt>Serial</dt><dd><code><?= e($a['serial']) ?></code></dd></div>
                            <div><dt>Veces reparado</dt><dd><?= (int)$a['veces_reparado'] ?></dd></div>
                            <div><dt>Modificado</dt><dd><?= $fecha_mostrada ?></dd></div>
                            <?php if (!empty($a['responsable_actual'])): ?><div><dt>Responsable</dt><dd><?= e($a['responsable_actual']) ?></dd></div><?php endif; ?>
                            <?php if (!empty($a['departamento'])): ?><div><dt>Departamento</dt><dd><?= e($a['departamento']) ?></dd></div><?php endif; ?>
                        </dl>
                        <div class="inv-actions">
                            <a href="detalle.php?id=<?= (int)$a['id_activo'] ?>" class="btn btn-sm btn-outline-info"><i class="fas fa-eye"></i> Mostrar más detalles</a>
                        </div>
                    </details>
                </article>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-box-open fa-3x mb-3 d-block"></i>
                No hay activos registrados con esos filtros.
                <?php if ($puede_crear): ?>
                    <br><a href="registro.php" class="btn btn-primary btn-sm mt-3">Registrar primer activo</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($puede_crear): ?>
<!-- Botón flotante para registrar (solo teléfono) -->
<a href="registro.php" class="fab-add d-sm-none" aria-label="Nuevo activo"><i class="fas fa-plus"></i></a>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
