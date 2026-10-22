<?php
require_once __DIR__ . '/../../core/conexion.php';
restringirAcceso(['administrador', 'recepcionista']);

// ============================================================
// MODO IMPRESIÓN DE ORDEN (VOUCHER)
// ============================================================
if (isset($_GET['modo']) && $_GET['modo'] == 'orden' && !empty($_GET['id'])) {
    require_once __DIR__ . '/../../includes/formato_documento.php';
    $id_novedad = (int)$_GET['id'];
    $stmt = $conexion->prepare("SELECT n.*, a.codigo_udo, a.tipo_equipo, a.marca, a.modelo, a.serial
                                FROM novedades n
                                JOIN activos a ON n.id_activo = a.id_activo
                                WHERE n.id_novedad = ?");
    $stmt->execute([$id_novedad]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$data) die("Orden no encontrada.");

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Orden de Servicio <?= htmlspecialchars($data['numero_orden']) ?></title>
        <?php renderEstilosFormato('portrait'); ?>
    </head>
    <body>
        <?php renderCintilloUDO('Delegación de Computación y Teleinformática'); ?>

        <div class="doc-fecha">
            Maturín, <?= date('d/m/Y', strtotime($data['fecha_entrada'])) ?>
        </div>

        <div class="doc-titulo">ORDEN DE SERVICIO TÉCNICO Y RESGUARDO DE RECEPCIÓN</div>
        <div class="doc-subtitulo">DELEGACIÓN DE COMPUTACIÓN Y TELEINFORMÁTICA • COMPROBANTE OFICIAL</div>

        <table class="meta-box">
            <tr>
                <td style="width: 50%;"><strong>N° DE ORDEN:</strong> <span style="font-size: 10.5pt; font-weight:bold; color:#003366;"><?= htmlspecialchars($data['numero_orden']) ?></span></td>
                <td style="width: 50%;"><strong>FECHA Y HORA:</strong> <?= date('d/m/Y h:i A', strtotime($data['fecha_entrada'])) ?></td>
            </tr>
            <tr>
                <td><strong>CÓDIGO ACTIVO UDO:</strong> <strong><?= htmlspecialchars($data['codigo_udo']) ?></strong></td>
                <td><strong>ESTATUS:</strong> <span class="badge-estado badge-taller">En Revisión Técnica</span></td>
            </tr>
        </table>

        <table class="tabla-datos">
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
                <th>Falla Reportada por el Usuario</th>
                <td><?= nl2br(htmlspecialchars($data['descripcion_falla'])) ?></td>
            </tr>
            <tr>
                <th>Accesorios / Cables Entregados</th>
                <td><?= htmlspecialchars($data['accesorios'] ?: 'Ninguno (Solo el equipo principal)') ?></td>
            </tr>
            <tr>
                <th>Funcionario / Solicitante</th>
                <td><strong><?= htmlspecialchars($data['persona_reporta']) ?></strong></td>
            </tr>
            <tr>
                <th>Teléfono de Contacto</th>
                <td><?= htmlspecialchars($data['telefono_reporta'] ?: 'No suministrado') ?></td>
            </tr>
        </table>

        <div class="clausula-texto">
            <strong>TÉRMINOS Y CONDICIONES DE RECEPCIÓN:</strong> El equipo arriba especificado queda formalmente depositado en el taller de la Delegación de Computación y Teleinformática para fines de peritaje técnico, diagnóstico, mantenimiento y/o reparación. Para el retiro del bien, será indispensable presentar el presente comprobante junto con la identificación correspondiente.
        </div>

        <table class="firmas-grid">
            <tr>
                <td>
                    <div class="linea-firma"></div>
                    <div class="firma-nombre"><?= htmlspecialchars($data['persona_reporta']) ?></div>
                    <div class="firma-cargo">Firma de Quien Entrega</div>
                </td>
                <td>
                    <div class="linea-firma"></div>
                    <div class="firma-nombre">Delegación de Computación y Teleinformática</div>
                    <div class="firma-cargo">Recepción Técnica y Custodia</div>
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
    $nombreArchivo = 'Orden_Servicio_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $data['numero_orden']) . '.pdf';
    generarPDF($html, $nombreArchivo, 'letter', 'portrait', $descargar);
}

// ============================================================
// AJAX: búsqueda en tiempo real
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 1) {
    header('Content-Type: application/json');
    $termino = $_GET['buscar'] ?? '';
    $todos   = isset($_GET['todos']) && $_GET['todos'] == 1;

    if ($todos || empty($termino)) {
        $stmt = $conexion->prepare("SELECT id_activo, codigo_udo, tipo_equipo, marca, modelo, serial
                                    FROM activos WHERE estado_equipo IN ('Operativo','Reparado')
                                    ORDER BY codigo_udo LIMIT 20");
        $stmt->execute();
    } else {
        $termino = "%$termino%";
        $stmt = $conexion->prepare("SELECT id_activo, codigo_udo, tipo_equipo, marca, modelo, serial
                                    FROM activos WHERE estado_equipo IN ('Operativo','Reparado')
                                    AND (codigo_udo LIKE ? OR serial LIKE ? OR tipo_equipo LIKE ? OR marca LIKE ? OR modelo LIKE ?)
                                    ORDER BY codigo_udo LIMIT 20");
        $stmt->execute([$termino, $termino, $termino, $termino, $termino]);
    }
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ============================================================
// PROCESAR FORMULARIO DE RECEPCIÓN (POST)
// ============================================================
$nombre_usuario  = $_SESSION['nombre'] ?? '';
$telefono_usuario = '';
if (!empty($_SESSION['usuario_id'])) {
    $st = $conexion->prepare("SELECT telefono FROM usuarios WHERE id_usuario = ?");
    $st->execute([$_SESSION['usuario_id']]);
    $telefono_usuario = $st->fetchColumn() ?? '';
}

$tecnicosDisponibles = $conexion->query("
    SELECT id_usuario, nombre, rol 
    FROM usuarios 
    WHERE rol IN ('tecnico', 'administrador') AND estado = 'activo' 
    ORDER BY nombre ASC
")->fetchAll();

$error_recepcion = '';
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['registrar_novedad'])) {
    if (!validarCsrfToken($_POST['csrf_token'] ?? '')) {
        $error_recepcion = "Token de seguridad inválido o sesión expirada. Por favor, reintente.";
    } else {
        $id_activo        = $_POST['id_activo'] ?? 0;
        $descripcion      = limpiar($_POST['descripcion_falla'] ?? '');
        $persona          = limpiar($_POST['persona_reporta'] ?? '');
        $telefono         = limpiar($_POST['telefono_reporta'] ?? '');
        $accesorios       = limpiar($_POST['accesorios'] ?? '');
        $tecnico_asignado = !empty($_POST['tecnico_asignado']) ? (int)$_POST['tecnico_asignado'] : null;

        if (empty($id_activo) || empty($descripcion) || empty($persona)) {
            $error_recepcion = "Complete el equipo, la falla y quién reporta.";
        } else {
            $check = $conexion->prepare("SELECT id_activo FROM activos WHERE id_activo = ? AND estado_equipo IN ('Operativo','Reparado')");
            $check->execute([$id_activo]);
            if (!$check->fetch()) {
                $error_recepcion = "El equipo ya no está disponible. Seleccione otro.";
            } else {
                try {
                    $conexion->beginTransaction();

                    $year  = date('Y');
                    $count = $conexion->prepare("SELECT COUNT(*) FROM novedades WHERE YEAR(fecha_entrada) = ?");
                    $count->execute([$year]);
                    $total = $count->fetchColumn();
                    $numero_orden = "ORD-" . $year . "-" . str_pad($total + 1, 4, '0', STR_PAD_LEFT);

                    $stmt = $conexion->prepare("INSERT INTO novedades
                        (id_activo, numero_orden, descripcion_falla, persona_reporta, telefono_reporta, accesorios, creado_por, tecnico_asignado, fecha_entrada)
                        VALUES (?,?,?,?,?,?,?,?,NOW())");
                    $stmt->execute([$id_activo, $numero_orden, $descripcion, $persona, $telefono, $accesorios, $_SESSION['usuario_id'], $tecnico_asignado]);

                    $ultima_novedad_id = $conexion->lastInsertId();

                    $conexion->prepare("UPDATE activos SET estado_equipo = 'En Recepción', ultima_modificacion = NOW(), modificado_por = ? WHERE id_activo = ?")
                          ->execute([$_SESSION['usuario_id'], $id_activo]);

                    $conexion->commit();
                    registrarAuditoria($conexion, "Recepción de equipo - Orden: $numero_orden", "novedades", $ultima_novedad_id);

                // ✅ REGISTRO DEL VOUCHER EN EL HISTORIAL
                $stmtVoucher = $conexion->prepare("INSERT INTO vouchers (tipo, id_novedad, titulo, generado_por, fecha_generacion) 
                                                   VALUES (?, ?, ?, ?, NOW())");
                $stmtVoucher->execute(['recepcion', $ultima_novedad_id, 'Orden de Servicio', $_SESSION['usuario_id']]);

                $_SESSION['voucher_url'] = "novedad.php?modo=orden&id=$ultima_novedad_id";
                $_SESSION['voucher_title'] = "Orden de Servicio";
                $_SESSION['flash_mensaje'] = "✅ Equipo recibido con éxito. Orden: <strong>$numero_orden</strong>";
                $_POST = [];
                header("Location: novedad.php");
                exit;
            } catch (Exception $e) {
                $conexion->rollBack();
                $error_recepcion = errorInterno($e);
            }
        }
    }
}
}

// ============================================================
// INTERFAZ
// ============================================================
include __DIR__ . '/../../includes/header.php';

$mensaje = $_SESSION['flash_mensaje'] ?? '';
$error   = $_SESSION['flash_error'] ?? $error_recepcion ?? '';
unset($_SESSION['flash_mensaje'], $_SESSION['flash_error']);

$voucher_url = $_SESSION['voucher_url'] ?? '';
$voucher_title = $_SESSION['voucher_title'] ?? '';
unset($_SESSION['voucher_url'], $_SESSION['voucher_title']);
?>

<div class="container-fluid animate__animated animate__fadeIn">
    <div class="row"><div class="col-lg-8 mx-auto">
        <h3 class="fw-bold mb-4"><i class="fas fa-clinic-medical me-2" style="color:var(--accent-cyan);"></i>Recepción de Equipos</h3>

        <?php if($mensaje): ?><div class="alert alert-success"><?= $mensaje ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

        <!-- Buscador + botón Ver lista -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <label class="form-label fw-bold"><i class="fas fa-search me-1" style="color:var(--accent-cyan);"></i> Buscar equipo (código, serial, tipo, marca, modelo)</label>
                <div class="input-group">
                    <input type="text" id="buscadorEquipo" class="form-control form-control-lg" placeholder="Escriba cualquier dato del equipo..." autocomplete="off">
                    <button class="btn btn-outline-primary rounded-pill ms-2" type="button" id="btnVerTodos"><i class="fas fa-list me-1"></i> Ver lista</button>
                </div>
                <div id="resultadosBusqueda" class="list-group mt-2 shadow-lg rounded-3" style="display:none; max-height:300px; overflow-y:auto;"></div>
                <small class="text-muted">Solo se muestran equipos en estado <strong>Operativo</strong>.</small>
            </div>
        </div>

        <!-- Formulario de recepción -->
        <div id="formularioRecepcion" style="display:none;">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-transparent border-0 pt-4 pb-0">
                    <h5 class="fw-bold d-flex align-items-center"><i class="fas fa-desktop me-2" style="color:var(--accent-cyan);"></i> <span id="nombreEquipoSeleccionado"></span></h5>
                    <span class="badge badge-info px-3 py-2" id="badgeCodigo"></span>
                </div>
                <div class="card-body p-4">
                    <form method="POST" id="formRecepcion">
                        <?= csrfCampo() ?>
                        <input type="hidden" name="id_activo" id="id_activo">
                        <div class="mb-3">
                            <label class="form-label fw-bold">DESCRIPCIÓN DE LA FALLA <span class="text-danger">*</span></label>
                            <textarea name="descripcion_falla" class="form-control" rows="2" required placeholder="Ej: No enciende, pantalla rota..."></textarea>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">NOMBRE DE QUIEN ENTREGA <span class="text-danger">*</span></label>
                                <input type="text" name="persona_reporta" class="form-control" required value="<?= e($nombre_usuario) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">TELÉFONO / CELULAR</label>
                                <input type="text" name="telefono_reporta" class="form-control" value="<?= e($telefono_usuario) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">ACCESORIOS ENTREGADOS</label>
                                <input type="text" name="accesorios" class="form-control" placeholder="Mouse, teclado, cable de poder...">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">ASIGNAR TÉCNICO (OPCIONAL)</label>
                                <select name="tecnico_asignado" class="form-select">
                                    <option value="">-- Libre para cualquier técnico --</option>
                                    <?php foreach ($tecnicosDisponibles as $tec): ?>
                                        <option value="<?= $tec['id_usuario'] ?>"><?= e($tec['nombre']) ?> (<?= ucfirst($tec['rol']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" name="registrar_novedad" class="btn btn-primary rounded-pill px-5"><i class="fas fa-clipboard-check me-2"></i> Confirmar Recepción</button>
                            <button type="button" class="btn btn-outline-secondary rounded-pill px-5" onclick="cancelarRecepcion()"><i class="fas fa-times me-2"></i> Cancelar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div></div>
</div>

<!-- Modal lista completa -->
<div class="modal fade" id="modalListaEquipos" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom">
                <h5 class="fw-bold"><i class="fas fa-list me-2" style="color:var(--accent-cyan);"></i>Equipos Operativos</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div id="contenidoLista" class="text-center text-muted">Cargando...</div>
            </div>
        </div>
    </div>
</div>

<!-- Popup de voucher (se muestra después de una recepción exitosa) -->
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

<script>
let timeoutId;
const buscador = document.getElementById('buscadorEquipo');
const resultados = document.getElementById('resultadosBusqueda');
const btnTodos = document.getElementById('btnVerTodos');
const formulario = document.getElementById('formularioRecepcion');
let modalLista;

function realizarBusqueda(termino, todos = false) {
    clearTimeout(timeoutId);
    let url = `novedad.php?ajax=1&buscar=${encodeURIComponent(termino)}`;
    if (todos) url += '&todos=1';
    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (!Array.isArray(data) || data.length === 0) {
                resultados.innerHTML = '<div class="list-group-item text-muted">Ningún equipo encontrado</div>';
            } else {
                let html = '';
                data.forEach(eq => {
                    html += `<a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                onclick="seleccionarEquipo(${eq.id_activo}, '${eq.codigo_udo.replace(/'/g, "\\'")}', '${eq.tipo_equipo.replace(/'/g, "\\'")}', '${eq.marca.replace(/'/g, "\\'")}', '${eq.modelo.replace(/'/g, "\\'")}')">
                                <span><strong>${eq.codigo_udo}</strong> – ${eq.tipo_equipo} ${eq.marca} ${eq.modelo}</span>
                                <i class="fas fa-chevron-right text-muted"></i>
                              </a>`;
                });
                resultados.innerHTML = html;
            }
            resultados.style.display = 'block';
        });
}

buscador.addEventListener('input', function() {
    const termino = this.value.trim();
    if (termino.length < 1) { resultados.style.display = 'none'; return; }
    timeoutId = setTimeout(() => realizarBusqueda(termino), 300);
});

btnTodos.addEventListener('click', function() {
    fetch(`novedad.php?ajax=1&todos=1&buscar=`)
        .then(r => r.json())
        .then(data => {
            let html = '';
            if (!Array.isArray(data) || data.length === 0) {
                html = '<p class="text-muted text-center">No hay equipos operativos.</p>';
            } else {
                html = '<div class="list-group">';
                data.forEach(eq => {
                    html += `<a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                onclick="seleccionarEquipo(${eq.id_activo}, '${eq.codigo_udo.replace(/'/g, "\\'")}', '${eq.tipo_equipo.replace(/'/g, "\\'")}', '${eq.marca.replace(/'/g, "\\'")}', '${eq.modelo.replace(/'/g, "\\'")}'); bootstrap.Modal.getInstance(document.getElementById('modalListaEquipos')).hide();">
                                <span><strong>${eq.codigo_udo}</strong> – ${eq.tipo_equipo} ${eq.marca} ${eq.modelo}</span>
                              </a>`;
                });
                html += '</div>';
            }
            document.getElementById('contenidoLista').innerHTML = html;
        });
    if (!modalLista) modalLista = new bootstrap.Modal(document.getElementById('modalListaEquipos'));
    modalLista.show();
});

document.addEventListener('click', function(e) {
    if (!resultados.contains(e.target) && e.target !== buscador && e.target !== btnTodos) {
        resultados.style.display = 'none';
    }
});

function seleccionarEquipo(id, codigo, tipo, marca, modelo) {
    document.getElementById('id_activo').value = id;
    document.getElementById('nombreEquipoSeleccionado').innerText = tipo + ' ' + marca + ' ' + modelo;
    document.getElementById('badgeCodigo').innerText = codigo;
    formulario.style.display = 'block';
    resultados.style.display = 'none';
    buscador.value = codigo;
    document.querySelector('textarea[name="descripcion_falla"]').focus();
}

function cancelarRecepcion() {
    formulario.style.display = 'none';
    buscador.value = '';
    resultados.style.display = 'none';
    buscador.focus({ preventScroll: true });
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>