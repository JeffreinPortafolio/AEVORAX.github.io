<?php
require_once __DIR__ . '/../../core/conexion.php';
restringirAcceso(['administrador', 'tecnico']);

// Procesar acciones POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Iniciar reparación
    if (isset($_POST['iniciar_reparacion'])) {
        $id_novedad = $_POST['id_novedad'] ?? 0;
        $id_activo  = $_POST['id_activo']  ?? 0;
        try {
            $conexion->prepare("UPDATE novedades SET fecha_inicio_reparacion = NOW(), id_tecnico_inicio_reparacion = ? WHERE id_novedad = ?")
                     ->execute([$_SESSION['usuario_id'], $id_novedad]);
            registrarAuditoria($conexion, "Inició reparación – Activo ID $id_activo", "novedades", $id_novedad);
            $_SESSION['flash_mensaje'] = "🔧 Reparación iniciada.";
        } catch (Exception $e) {
            $_SESSION['flash_error'] = errorInterno($e);
        }
        header("Location: reparar.php");
        exit;
    }

    // Finalizar reparación
    if (isset($_POST['cerrar_reparacion'])) {
        $id_novedad = $_POST['id_novedad'] ?? 0;
        $id_activo  = $_POST['id_activo']  ?? 0;
        $tecnico_inicio = $_POST['tecnico_inicio'] ?? 0;

        // Verificar que el usuario actual sea el que inició o sea administrador
        $usuario_actual = $_SESSION['usuario_id'];
        $es_admin = ($_SESSION['rol'] == 'administrador');
        if (!$es_admin && $usuario_actual != $tecnico_inicio) {
            $_SESSION['flash_error'] = "❌ Solo el técnico que inició la reparación puede finalizarla.";
            header("Location: reparar.php");
            exit;
        }

        $solucion   = limpiar($_POST['solucion'] ?? '');
        $horas      = floatval($_POST['horas_trabajadas'] ?? 0);
        $estado_final = $_POST['estado_final'] ?? '';
        $repuestos_usados = $_POST['repuestos_usados'] ?? [];

        // Campos específicos de baja (si es No Reparable)
        $motivo_baja = limpiar($_POST['motivo_baja'] ?? '');
        $causa       = $_POST['causa_especifica'] ?? '';
        $porcentaje  = intval($_POST['porcentaje_costo'] ?? 0);
        $dictamen    = limpiar($_POST['dictamen_tecnico'] ?? '');

        if (empty($solucion) || empty($estado_final)) {
            $_SESSION['flash_error'] = "Debe describir la solución y seleccionar un veredicto.";
        } elseif ($estado_final === 'No Reparable' && (empty($motivo_baja) || empty($causa) || empty($dictamen))) {
            $_SESSION['flash_error'] = "Para baja debe completar motivo, causa y dictamen.";
        } else {
            try {
                $conexion->beginTransaction();

                $conexion->prepare("UPDATE novedades SET solucion_aplicada=?, horas_trabajadas=?, fecha_fin_reparacion=NOW(), fecha_salida=NOW(), estado_final=? WHERE id_novedad=?")
                         ->execute([$solucion, $horas, $estado_final, $id_novedad]);

                if (!empty($repuestos_usados)) {
                    foreach ($repuestos_usados as $id_rep => $cant) {
                        $cant = (int)$cant;
                        if ($cant > 0) {
                            $conexion->prepare("INSERT INTO uso_repuestos (id_novedad, id_repuesto, cantidad) VALUES (?,?,?)")->execute([$id_novedad, $id_rep, $cant]);
                            $conexion->prepare("UPDATE repuestos SET stock = stock - ? WHERE id_repuesto = ?")->execute([$cant, $id_rep]);
                        }
                    }
                }

                if ($estado_final === 'Reparado') {
                    $nuevo_estado = 'Reparado';
                } else {
                    // No Reparable: Guardar dictamen técnico y remitir a 'Baja Técnica'
                    $foto_evidencia = null;
                    if (!empty($_FILES['foto_evidencia']['name'])) {
                        $errImg = '';
                        $foto_evidencia = procesarImagenSubida($_FILES['foto_evidencia'], 'evidencias', 'evidencia_' . (int)$id_novedad, $errImg, 1600, 10);
                        if (!$foto_evidencia) { flash('error', $errImg); }
                    }

                    $costo_estimado = floatval($_POST['costo_reparacion_estimado'] ?? 0);
                    $conexion->prepare("
                        UPDATE novedades SET 
                            motivo_baja = ?,
                            causa_especifica = ?,
                            costo_reparacion_estimado = ?,
                            porcentaje_costo_reparacion = ?,
                            dictamen_tecnico = ?,
                            foto_evidencia = COALESCE(?, foto_evidencia)
                        WHERE id_novedad = ?
                    ")->execute([$motivo_baja, $causa, $costo_estimado, $porcentaje, $dictamen, $foto_evidencia, $id_novedad]);

                    $nuevo_estado = 'Baja Técnica';
                }

                $conexion->prepare("UPDATE activos SET estado_equipo=?, ultima_modificacion=NOW(), modificado_por=? WHERE id_activo=?")
                         ->execute([$nuevo_estado, $_SESSION['usuario_id'], $id_activo]);

                $conexion->commit();
                registrarAuditoria($conexion, "Finalizó proceso de taller – Activo ID $id_activo – Estado: $nuevo_estado", "novedades", $id_novedad);

                $_SESSION['flash_mensaje'] = ($estado_final === 'Reparado') 
                    ? "✅ Reparación finalizada. Equipo listo para entrega." 
                    : "⚠️ Dictamen de Baja Técnica registrado. Remitido a la bandeja de autorización del Administrador.";
            } catch (Exception $e) {
                $conexion->rollBack();
                $_SESSION['flash_error'] = errorInterno($e);
            }
        }
        header("Location: reparar.php");
        exit;
    }
}

include __DIR__ . '/../../includes/header.php';

$mensaje = $_SESSION['flash_mensaje'] ?? '';
$error   = $_SESSION['flash_error']   ?? '';
$voucher_url = $_SESSION['voucher_url'] ?? '';
$voucher_title = $_SESSION['voucher_title'] ?? '';
unset($_SESSION['flash_mensaje'], $_SESSION['flash_error'], $_SESSION['voucher_url'], $_SESSION['voucher_title']);

// ============================================================
// CONSULTA DE EQUIPOS EN REPARACIÓN
// ============================================================
$reparando = $conexion->query("
    SELECT a.*, n.id_novedad, n.diagnostico_tecnico, n.nivel_dano, n.numero_orden, n.fecha_diagnostico, n.fecha_inicio_reparacion, n.id_tecnico_diagnostico, n.id_tecnico_inicio_reparacion,
           u.nombre AS tecnico_nombre,
           u2.nombre AS tecnico_inicio_nombre
    FROM activos a 
    LEFT JOIN novedades n ON a.id_activo = n.id_activo AND n.fecha_entrega_final IS NULL
    LEFT JOIN usuarios u ON n.id_tecnico_diagnostico = u.id_usuario
    LEFT JOIN usuarios u2 ON n.id_tecnico_inicio_reparacion = u2.id_usuario
    WHERE a.estado_equipo = 'En Reparación'
    ORDER BY n.fecha_diagnostico ASC
")->fetchAll();

// ============================================================
// REPUESTOS (solo si hay stock)
// ============================================================
$repuestos = $conexion->query("SELECT id_repuesto, nombre, stock FROM repuestos WHERE stock > 0 ORDER BY nombre")->fetchAll();

function horaVenezolana($fecha) {
    if (!$fecha) return '';
    try {
        $dt = new DateTime($fecha, new DateTimeZone('America/Caracas'));
        return $dt->format('d/m/Y h:i A');
    } catch (Exception $e) {
        return '';
    }
}
?>

<div class="container-fluid animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0"><i class="fas fa-tools me-2" style="color:var(--accent-cyan);"></i>Taller de Reparaciones</h3>
            <p class="text-muted small">Equipos en proceso de reparación</p>
        </div>
        <span class="badge bg-info rounded-pill px-3 py-2 fs-6 shadow-sm"><i class="fas fa-microchip me-1"></i> <?= count($reparando) ?> En Taller</span>
    </div>

    <?php if($mensaje): ?><div class="alert alert-success animate__animated animate__fadeInDown"><?= $mensaje ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-danger animate__animated animate__shakeX"><?= $error ?></div><?php endif; ?>

    <?php if(empty($reparando)): ?>
        <div class="card border-0 shadow-sm rounded-4 text-center py-5">
            <div class="card-body">
                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                <h5 class="text-muted">No hay equipos en reparación actualmente</h5>
                <a href="diagnostico.php" class="btn btn-primary rounded-pill mt-3"><i class="fas fa-stethoscope me-2"></i>Ir a Diagnóstico</a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach($reparando as $r):
                $nivel_color = ($r['nivel_dano'] == 'Leve') ? 'success' : (($r['nivel_dano'] == 'Moderado') ? 'warning' : 'danger');
                $tecnico_mostrar = $r['tecnico_nombre'] ?? 'No asignado';
                $hora_inicio = horaVenezolana($r['fecha_inicio_reparacion']);
                
                // Determinar si el usuario actual puede finalizar
                $usuario_actual = $_SESSION['usuario_id'];
                $es_admin = ($_SESSION['rol'] == 'administrador');
                $puede_finalizar = ($es_admin || ($r['id_tecnico_inicio_reparacion'] == $usuario_actual));
                
                // Mostrar quién inició
                $iniciado_por = $r['tecnico_inicio_nombre'] ?? 'Nadie';
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm rounded-4 animate__animated animate__fadeInUp">
                    <div class="card-header bg-info bg-opacity-10 border-0 py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="badge bg-info text-white px-3 py-2"><i class="fas fa-tools me-1"></i> En Reparación</span>
                            <code class="small text-white"><?= htmlspecialchars($r['codigo_udo']) ?></code>
                        </div>
                    </div>
                    <div class="card-body">
                        <h5 class="fw-bold mb-1"><?= e($r['tipo_equipo']) ?></h5>
                        <p class="text-secondary small mb-2"><?= e($r['marca']) ?> - <?= e($r['modelo']) ?></p>
                        <div class="mb-3 small p-2 rounded bg-body-tertiary border">
                            <i class="fas fa-user-cog me-1 text-info"></i> Técnico que diagnosticó: <strong><?= e($tecnico_mostrar) ?></strong>
                            <?php if ($r['fecha_inicio_reparacion']): ?>
                                <br><i class="far fa-clock me-1 text-info"></i> Inicio reparación: <?= $hora_inicio ?>
                                <br><i class="fas fa-user-check me-1 text-success"></i> Iniciada por: <strong><?= e($iniciado_por) ?></strong>
                            <?php else: ?>
                                <br><span class="text-warning">Aún no se ha iniciado la reparación.</span>
                            <?php endif; ?>
                        </div>
                        <div class="p-3 rounded-3 mb-3 small bg-body-secondary border">
                            <div class="fw-bold"><i class="fas fa-stethoscope me-1 text-primary"></i> Diagnóstico:</div>
                            <p class="mb-0 mt-1"><?= e($r['diagnostico_tecnico'] ?? 'Sin diagnóstico') ?></p>
                            <hr class="my-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="badge bg-<?= $nivel_color ?>"><?= $r['nivel_dano'] ?? '?' ?></span>
                                <small class="text-muted"><i class="far fa-calendar me-1"></i> <?= $r['fecha_diagnostico'] ? date('d/m/Y', strtotime($r['fecha_diagnostico'])) : '—' ?></small>
                            </div>
                        </div>
                        <?php if (!$r['fecha_inicio_reparacion']): ?>
                            <form method="POST">
                                <?= csrfCampo() ?>
                                <input type="hidden" name="id_novedad" value="<?= $r['id_novedad'] ?>">
                                <input type="hidden" name="id_activo" value="<?= $r['id_activo'] ?>">
                                <button type="submit" name="iniciar_reparacion" class="btn btn-success w-100 rounded-pill"><i class="fas fa-play me-2"></i>Iniciar Reparación</button>
                            </form>
                        <?php else: ?>
                            <?php if ($puede_finalizar): ?>
                                <button class="btn btn-primary w-100 rounded-pill" data-bs-toggle="modal" data-bs-target="#modalFinalizar<?= $r['id_novedad'] ?>"><i class="fas fa-check-circle me-2"></i>Finalizar Reparación</button>
                            <?php else: ?>
                                <div class="alert alert-warning text-center mb-0">
                                    <i class="fas fa-user-lock me-1"></i> Reparación iniciada por <strong><?= e($iniciado_por) ?></strong>. Solo él puede finalizarla.
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer bg-transparent border-0 text-center pb-3">
                        <small class="text-muted"><i class="fas fa-barcode me-1"></i> Orden: <?= e($r['numero_orden'] ?? '—') ?></small>
                    </div>
                </div>
            </div>

            <?php if ($r['fecha_inicio_reparacion'] && $puede_finalizar): ?>
            <div class="modal fade" id="modalFinalizar<?= $r['id_novedad'] ?>" tabindex="-1" aria-hidden="true" data-bs-backdrop="false" data-bs-keyboard="false">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content border-0 shadow-lg rounded-4">
                        <div class="modal-header border-bottom">
                            <h5 class="fw-bold mb-0 text-success"><i class="fas fa-check-circle me-2"></i>Finalizar Reparación</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST" id="formFinalizar<?= $r['id_novedad'] ?>" enctype="multipart/form-data">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="id_novedad" value="<?= $r['id_novedad'] ?>">
                            <input type="hidden" name="id_activo" value="<?= $r['id_activo'] ?>">
                            <input type="hidden" name="tecnico_inicio" value="<?= $r['id_tecnico_inicio_reparacion'] ?>">
                            <div class="modal-body p-4">
                                <div class="p-3 rounded-3 mb-3 bg-body-secondary border">
                                    <div class="row">
                                        <div class="col-md-6"><small class="text-muted">Equipo</small><div class="fw-bold"><?= e($r['codigo_udo']) ?></div></div>
                                        <div class="col-md-6"><small class="text-muted">Diagnóstico previo</small><div class="small"><?= e(substr($r['diagnostico_tecnico'], 0, 80)) ?>...</div></div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">SOLUCIÓN APLICADA <span class="text-danger">*</span></label>
                                    <textarea name="solucion" class="form-control" rows="2" required placeholder="Describa qué hizo para reparar el equipo..."></textarea>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">HORAS TRABAJADAS <span class="text-danger">*</span></label>
                                        <input type="number" step="0.1" name="horas_trabajadas" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">VEREDICTO FINAL <span class="text-danger">*</span></label>
                                        <select name="estado_final" class="form-select" required id="veredicto<?= $r['id_novedad'] ?>" onchange="toggleBajaFields(<?= $r['id_novedad'] ?>)">
                                            <option value="">Seleccione...</option>
                                            <option value="Reparado">✅ REPARADO – Funciona correctamente</option>
                                            <option value="No Reparable">❌ NO REPARABLE – Dar de baja</option>
                                        </select>
                                    </div>
                                </div>
                                <!-- Campos de baja (ocultos inicialmente) -->
                                <div id="bajaFields<?= $r['id_novedad'] ?>" style="display: none;">
                                    <hr>
                                    <div class="mb-3">
                                        <label class="fw-bold text-dark">Motivo de baja <span class="text-danger">*</span></label>
                                        <textarea name="motivo_baja" class="form-control" rows="2" placeholder="Justificación técnica de por qué no procede la reparación..."></textarea>
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="fw-bold text-dark">Causa específica <span class="text-danger">*</span></label>
                                            <select name="causa_especifica" class="form-select">
                                                <option value="">Seleccione...</option>
                                                <option value="Falta de repuesto">Falta de repuesto</option>
                                                <option value="Costo elevado">Costo elevado</option>
                                                <option value="Obsolescencia">Obsolescencia</option>
                                                <option value="Daño estructural">Daño estructural</option>
                                                <option value="Otro">Otro</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="fw-bold text-dark">Costo Est. Reparación ($)</label>
                                            <input type="number" step="0.01" min="0" name="costo_reparacion_estimado" id="costo_estimado_rep_<?= $r['id_novedad'] ?>" class="form-control" placeholder="0.00" oninput="calcularPorcentajeBajaRep(<?= $r['id_novedad'] ?>, <?= floatval($r['valor_compra'] ?? 0) ?>)">
                                            <small class="text-muted d-block" style="font-size:10px;">Valor compra: $<?= number_format(floatval($r['valor_compra'] ?? 0), 2) ?></small>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="fw-bold text-dark">% Costo / Valor</label>
                                            <input type="number" name="porcentaje_costo" id="porcentaje_costo_rep_<?= $r['id_novedad'] ?>" class="form-control" placeholder="0">
                                            <div id="alerta_porcentaje_rep_<?= $r['id_novedad'] ?>"></div>
                                        </div>
                                    </div>
                                    <div class="mt-3">
                                        <label class="fw-bold text-dark"><i class="fas fa-camera me-1 text-primary"></i>Foto de evidencia del daño (Opcional)</label>
                                        <input type="file" name="foto_evidencia" class="form-control" accept="image/*">
                                        <small class="text-muted d-block" style="font-size:10px;">Evidencia fotográfica para el expediente de baja técnica.</small>
                                    </div>
                                    <div class="mt-3">
                                        <label class="fw-bold text-dark">Dictamen técnico <span class="text-danger">*</span></label>
                                        <textarea name="dictamen_tecnico" class="form-control" rows="2" placeholder="Dictamen concluyente de irrecuperabilidad..."></textarea>
                                    </div>
                                </div>

                                <!-- ⚠️ SECCIÓN DE REPUESTOS: solo se muestra si existen -->
                                <?php if(count($repuestos) > 0): ?>
                                <div class="mt-3">
                                    <label class="form-label fw-bold text-dark">REPUESTOS UTILIZADOS</label>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered">
                                            <thead><tr><th>Repuesto</th><th width="100">Cantidad</th><th width="100">Stock</th></tr></thead>
                                            <tbody>
                                                <?php foreach($repuestos as $rep): ?>
                                                <tr>
                                                    <td class="text-dark"><?= htmlspecialchars($rep['nombre']) ?></td>
                                                    <td><input type="number" name="repuestos_usados[<?= $rep['id_repuesto'] ?>]" class="form-control form-control-sm" min="0" max="<?= $rep['stock'] ?>" value="0"></td>
                                                    <td class="text-dark"><?= $rep['stock'] ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <!-- Fin sección repuestos -->
                            </div>
                            <div class="modal-footer border-top">
                                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" name="cerrar_reparacion" class="btn btn-success rounded-pill px-5 fw-bold"><i class="fas fa-save me-2"></i>FINALIZAR</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Popup voucher (si se generó oficio) -->
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
                <h5 class="modal-title w-100 text-center fw-bold" style="color: var(--accent-cyan);"><i class="fas fa-check-circle text-success me-2"></i><?= e($voucher_title) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <i class="fas fa-print fa-4x mb-3" style="color: var(--accent-cyan);"></i>
                <p class="mb-3">¿Deseas imprimir el oficio de exclusión?</p>
                <div class="d-flex justify-content-center gap-2">
                    <a href="<?= e($voucher_url) ?>" target="_blank" class="btn btn-primary rounded-pill px-4"><i class="fas fa-print me-1"></i> Imprimir</a>
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function toggleBajaFields(id) {
    const select = document.getElementById('veredicto' + id);
    const bajaDiv = document.getElementById('bajaFields' + id);
    if (!select || !bajaDiv) return;
    const esBaja = select.value === 'No Reparable';
    bajaDiv.style.display = esBaja ? 'block' : 'none';
    bajaDiv.querySelectorAll('textarea, select, input').forEach(el => { el.required = false; });
    if (esBaja) {
        ['motivo_baja', 'causa_especifica', 'dictamen_tecnico'].forEach(n => {
            const el = bajaDiv.querySelector('[name="' + n + '"]');
            if (el) el.required = true;
        });
    }
}
function calcularPorcentajeBajaRep(id, valorCompra) {
    const costoInput = document.getElementById('costo_estimado_rep_' + id);
    const pctInput = document.getElementById('porcentaje_costo_rep_' + id);
    const alertDiv = document.getElementById('alerta_porcentaje_rep_' + id);
    if (!costoInput || !pctInput) return;
    const costo = parseFloat(costoInput.value) || 0;
    const valor = parseFloat(valorCompra) || 0;
    if (valor <= 0) return;
    const pct = Math.round((costo / valor) * 100);
    pctInput.value = pct;
    if (alertDiv) {
        alertDiv.innerHTML = pct >= 70
            ? '<span class="badge bg-danger mt-1">Costo supera el ' + pct + '% del valor (aplica baja)</span>'
            : '<span class="badge bg-secondary mt-1">Costo representa el ' + pct + '% del valor</span>';
    }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>