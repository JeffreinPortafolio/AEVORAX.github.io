<?php
require_once __DIR__ . '/../../core/conexion.php';
restringirAcceso(['administrador', 'tecnico']);

$usuario_id = $_SESSION['usuario_id'];

// Procesar inicio de diagnóstico
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['iniciar_diagnostico'])) {
    $id_novedad = (int)$_POST['id_novedad'];
    $id_activo  = (int)$_POST['id_activo'];
    try {
        $check = $conexion->prepare("SELECT diagnostico_iniciado_por FROM novedades WHERE id_novedad = ?");
        $check->execute([$id_novedad]);
        if ($check->fetchColumn()) throw new Exception("Este diagnóstico ya fue iniciado por otro técnico.");
        $stmt = $conexion->prepare("UPDATE novedades SET diagnostico_iniciado_por = ?, diagnostico_fecha_inicio = NOW() WHERE id_novedad = ?");
        $stmt->execute([$usuario_id, $id_novedad]);
        $_SESSION['flash_mensaje'] = "✅ Diagnóstico iniciado.";
    } catch (Exception $e) {
        $_SESSION['flash_error'] = errorInterno($e);
    }
    header("Location: diagnostico.php");
    exit;
}

// Procesar finalización de diagnóstico (reparar o dar de baja)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['terminar_diagnostico'])) {
    $id_novedad = (int)$_POST['id_novedad'];
    $id_activo  = (int)$_POST['id_activo'];
    $diagnostico    = trim($_POST['diagnostico'] ?? '');
    $causa_probable = trim($_POST['causa_probable'] ?? '');
    $nivel_dano     = $_POST['nivel_dano'] ?? '';
    $accion         = $_POST['accion'] ?? '';

    $errores = [];
    if (empty($diagnostico)) $errores[] = "El diagnóstico es obligatorio.";
    if (empty($nivel_dano)) $errores[] = "Debe seleccionar el nivel de daño.";
    if (empty($accion)) $errores[] = "Debe elegir una acción (reparar o dar de baja).";

    // Si es baja, validar campos adicionales
    if ($accion === 'baja') {
        $motivo_baja = trim($_POST['motivo_baja'] ?? '');
        $causa       = $_POST['causa_especifica'] ?? '';
        $dictamen    = trim($_POST['dictamen_tecnico'] ?? '');
        $porcentaje  = intval($_POST['porcentaje_costo'] ?? 0);
        if (empty($motivo_baja)) $errores[] = "Debe indicar el motivo de baja.";
        if (empty($causa)) $errores[] = "Debe seleccionar la causa específica.";
        if (empty($dictamen)) $errores[] = "Debe escribir el dictamen técnico.";
    }

    if (empty($errores)) {
        try {
            $conexion->beginTransaction();

            // Actualizar la novedad con el diagnóstico
            $stmt = $conexion->prepare("UPDATE novedades SET 
                diagnostico_tecnico = ?, nivel_dano = ?, causa_probable = ?,
                fecha_diagnostico = NOW(), id_tecnico_diagnostico = ?,
                diagnostico_iniciado_por = NULL, diagnostico_fecha_inicio = NULL
                WHERE id_novedad = ?");
            $stmt->execute([$diagnostico, $nivel_dano, $causa_probable, $usuario_id, $id_novedad]);

            if ($accion === 'reparar') {
                // Cambiar a estado "En Reparación"
                $nuevo_estado = 'En Reparación';
                $conexion->prepare("UPDATE activos SET estado_equipo = ?, ultima_modificacion = NOW(), modificado_por = ? WHERE id_activo = ?")
                    ->execute([$nuevo_estado, $usuario_id, $id_activo]);
                $_SESSION['flash_mensaje'] = "✅ Diagnóstico guardado. Equipo enviado a taller.";
            } else { // BAJA TÉCNICA: Guardar dictamen técnico y remitir a autorización del Administrador
                // Manejo de foto de evidencia
                $foto_evidencia = null;
                if (!empty($_FILES['foto_evidencia']['name'])) {
                    $errImg = '';
                    $foto_evidencia = procesarImagenSubida($_FILES['foto_evidencia'], 'evidencias', 'evidencia_' . (int)$id_novedad, $errImg, 1600, 10);
                    if (!$foto_evidencia) { flash('error', $errImg); }
                }

                $costo_estimado = floatval($_POST['costo_reparacion_estimado'] ?? 0);

                // 1. Guardar dictamen completo en la novedad
                $conexion->prepare("
                    UPDATE novedades SET 
                        estado_final = 'No Reparable', 
                        fecha_salida = NOW(),
                        motivo_baja = ?,
                        causa_especifica = ?,
                        costo_reparacion_estimado = ?,
                        porcentaje_costo_reparacion = ?,
                        dictamen_tecnico = ?,
                        foto_evidencia = COALESCE(?, foto_evidencia)
                    WHERE id_novedad = ?
                ")->execute([$motivo_baja, $causa, $costo_estimado, $porcentaje, $dictamen, $foto_evidencia, $id_novedad]);

                // 2. Cambiar estado del activo a 'Baja Técnica' (Esperando revisión y Oficio del Jefe de Delegación)
                $nuevo_estado = 'Baja Técnica';
                $conexion->prepare("UPDATE activos SET estado_equipo = ?, ultima_modificacion = NOW(), modificado_por = ? WHERE id_activo = ?")
                    ->execute([$nuevo_estado, $usuario_id, $id_activo]);

                $_SESSION['flash_mensaje'] = "⚠️ Dictamen de Baja Técnica registrado con éxito. Expediente remitido a la bandeja de autorización del Administrador.";
            }

            $conexion->commit();
            registrarAuditoria($conexion, "Finalizó diagnóstico – Activo ID $id_activo – Acción: $accion", "novedades", $id_novedad);
        } catch (Exception $e) {
            $conexion->rollBack();
            $_SESSION['flash_error'] = errorInterno($e);
        }
    } else {
        $_SESSION['flash_error'] = implode("<br>", $errores);
    }
    header("Location: diagnostico.php");
    exit;
}

// Obtener equipos pendientes de diagnóstico (novedades sin diagnóstico y sin entregar)
$pendientes = $conexion->query("
    SELECT a.id_activo, a.codigo_udo, a.marca, a.tipo_equipo, a.modelo, a.serial, a.ubicacion, a.estado_equipo,
           n.id_novedad, n.descripcion_falla, n.persona_reporta, n.telefono_reporta, n.numero_orden, n.fecha_entrada,
           n.diagnostico_iniciado_por, n.diagnostico_fecha_inicio
    FROM activos a 
    JOIN novedades n ON a.id_activo = n.id_activo 
    WHERE n.diagnostico_tecnico IS NULL 
      AND n.fecha_entrega_final IS NULL
    ORDER BY n.fecha_entrada ASC
")->fetchAll();

$mensaje_flash = $_SESSION['flash_mensaje'] ?? '';
$error_flash   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_mensaje'], $_SESSION['flash_error']);

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid animate__animated animate__fadeIn">
    <h3 class="fw-bold mb-4"><i class="fas fa-stethoscope me-2" style="color:var(--accent-cyan);"></i>Diagnóstico Técnico</h3>

    <?php if($mensaje_flash): ?><div class="alert alert-success"><?= $mensaje_flash ?></div><?php endif; ?>
    <?php if($error_flash): ?><div class="alert alert-danger"><?= $error_flash ?></div><?php endif; ?>

    <?php if (empty($pendientes)): ?>
        <div class="card border-0 shadow-sm rounded-4 text-center py-5">
            <div class="card-body">
                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                <h5 class="text-muted">No hay equipos pendientes de diagnóstico</h5>
                <a href="../recepcion/novedad.php" class="btn btn-primary rounded-pill mt-3">Ir a Recepción</a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($pendientes as $p): 
                $diagnostico_iniciado = ($p['diagnostico_iniciado_por'] == $usuario_id);
                $iniciado_por_otro = ($p['diagnostico_iniciado_por'] && $p['diagnostico_iniciado_por'] != $usuario_id);

                if ($diagnostico_iniciado) {
                    $borde_color = 'border-success';
                    $header_bg = 'bg-success bg-opacity-10';
                    $badge_color = 'bg-success text-white';
                    $badge_texto = 'En diagnóstico por ti';
                } elseif ($iniciado_por_otro) {
                    $borde_color = 'border-danger';
                    $header_bg = 'bg-danger bg-opacity-10';
                    $badge_color = 'bg-danger text-white';
                    $badge_texto = 'Ocupado por otro técnico';
                } else {
                    $borde_color = 'border-secondary';
                    $header_bg = 'bg-warning bg-opacity-10';
                    $badge_color = 'bg-warning text-dark';
                    $badge_texto = htmlspecialchars($p['estado_equipo']);
                }
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm rounded-4 border-start border-4 <?= $borde_color ?>">
                    <div class="card-header <?= $header_bg ?> border-0 py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="badge <?= $badge_color ?> px-3 py-2">
                                <i class="fas <?= $diagnostico_iniciado ? 'fa-user-check' : ($iniciado_por_otro ? 'fa-user-clock' : 'fa-clock') ?> me-1"></i> 
                                <?= $badge_texto ?>
                            </span>
                            <small class="text-muted"><?= date('d/m/Y', strtotime($p['fecha_entrada'])) ?></small>
                        </div>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <h4 class="fw-bold mb-1 text-primary">
                            <i class="fas fa-desktop me-2"></i><?= htmlspecialchars($p['codigo_udo']) ?>
                        </h4>
                        <p class="text-secondary mb-2 small">
                            <strong><?= htmlspecialchars($p['tipo_equipo']) ?></strong> – 
                            <?= htmlspecialchars($p['marca']) ?> <?= htmlspecialchars($p['modelo']) ?>
                        </p>

                        <!-- Vista información -->
                        <div id="infoView_<?= $p['id_novedad'] ?>" style="display: <?= $diagnostico_iniciado ? 'none' : 'block' ?>;">
                            <div class="p-3 rounded-3 mb-3 small flex-grow-1 bg-body-secondary border text-body">
                                <div class="fw-bold">Reporta: <?= e($p['persona_reporta']) ?></div>
                                <hr class="my-2">
                                <div class="fw-bold">Falla:</div>
                                <div class="fst-italic">"<?= e(substr($p['descripcion_falla'], 0, 100)) ?>"</div>
                            </div>

                            <?php if (!$p['diagnostico_iniciado_por'] && !$iniciado_por_otro): ?>
                                <form method="POST" class="mt-auto">
                                    <?= csrfCampo() ?>
                                    <input type="hidden" name="id_novedad" value="<?= $p['id_novedad'] ?>">
                                    <input type="hidden" name="id_activo" value="<?= $p['id_activo'] ?>">
                                    <button type="submit" name="iniciar_diagnostico" class="btn btn-primary w-100 rounded-pill">
                                        <i class="fas fa-play me-2"></i>Iniciar Diagnóstico
                                    </button>
                                </form>
                            <?php elseif ($iniciado_por_otro): ?>
                                <div class="alert alert-danger text-center mb-0 mt-auto">
                                    <i class="fas fa-user-lock me-1"></i> Ya está siendo diagnosticado por otro técnico
                                </div>
                            <?php elseif ($diagnostico_iniciado): ?>
                                <button class="btn btn-success w-100 rounded-pill mt-auto" onclick="mostrarFormulario(<?= $p['id_novedad'] ?>)">
                                    <i class="fas fa-check-circle me-2"></i>Terminar Diagnóstico
                                </button>
                            <?php endif; ?>
                        </div>

                        <!-- Formulario de diagnóstico (incluye campos para baja) -->
                        <div id="formView_<?= $p['id_novedad'] ?>" style="display: <?= $diagnostico_iniciado ? 'block' : 'none' ?>;" class="flex-grow-1">
                            <form method="POST" id="terminarForm_<?= $p['id_novedad'] ?>" enctype="multipart/form-data">
                                <?= csrfCampo() ?>
                                <input type="hidden" name="id_novedad" value="<?= $p['id_novedad'] ?>">
                                <input type="hidden" name="id_activo" value="<?= $p['id_activo'] ?>">
                                <div class="mb-2">
                                    <label class="fw-bold small">Diagnóstico *</label>
                                    <textarea name="diagnostico" class="form-control" rows="2" required></textarea>
                                </div>
                                <div class="row g-2 mb-2">
                                    <div class="col-6">
                                        <label class="fw-bold small">Nivel daño *</label>
                                        <select name="nivel_dano" class="form-select form-select-sm" required>
                                            <option value="">Seleccione</option>
                                            <option value="Leve">Leve</option>
                                            <option value="Moderado">Moderado</option>
                                            <option value="Grave">Grave</option>
                                            <option value="Irreparable">Irreparable</option>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="fw-bold small">Causa probable</label>
                                        <input type="text" name="causa_probable" class="form-control form-control-sm">
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="fw-bold small">Acción *</label>
                                    <div class="d-flex gap-3 mt-1">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="accion" value="reparar" required onchange="toggleBajaFields(<?= $p['id_novedad'] ?>)">
                                            <label class="form-check-label small">Reparar</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="accion" value="baja" required onchange="toggleBajaFields(<?= $p['id_novedad'] ?>)">
                                            <label class="form-check-label small">Dar de baja</label>
                                        </div>
                                    </div>
                                </div>
                                <!-- Campos específicos de baja (ocultos inicialmente) -->
                                <div id="bajaFields_<?= $p['id_novedad'] ?>" style="display: none;">
                                    <hr>
                                    <div class="mb-2">
                                        <label class="fw-bold small">Motivo de baja *</label>
                                        <textarea name="motivo_baja" class="form-control" rows="2" placeholder="Justificación técnica de por qué no procede la reparación..."></textarea>
                                    </div>
                                    <div class="row g-2 mb-2">
                                        <div class="col-12">
                                            <label class="fw-bold small">Causa específica *</label>
                                            <select name="causa_especifica" class="form-select form-select-sm">
                                                <option value="">Seleccione...</option>
                                                <option value="Falta de repuesto">Falta de repuesto</option>
                                                <option value="Costo elevado">Costo elevado</option>
                                                <option value="Obsolescencia">Obsolescencia</option>
                                                <option value="Daño estructural">Daño estructural</option>
                                                <option value="Otro">Otro</option>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label class="fw-bold small">Costo Est. Reparación ($)</label>
                                            <input type="number" step="0.01" min="0" name="costo_reparacion_estimado" id="costo_estimado_<?= $p['id_novedad'] ?>" class="form-control form-control-sm" placeholder="0.00" oninput="calcularPorcentajeBaja(<?= $p['id_novedad'] ?>, <?= floatval($p['valor_compra'] ?? 0) ?>)">
                                            <small class="text-muted d-block" style="font-size:10px;">Valor compra: $<?= number_format(floatval($p['valor_compra'] ?? 0), 2) ?></small>
                                        </div>
                                        <div class="col-6">
                                            <label class="fw-bold small">% Costo / Valor</label>
                                            <input type="number" name="porcentaje_costo" id="porcentaje_costo_<?= $p['id_novedad'] ?>" class="form-control form-control-sm" step="1" min="0" max="300" placeholder="0">
                                            <div id="alerta_porcentaje_<?= $p['id_novedad'] ?>"></div>
                                        </div>
                                    </div>
                                    <div class="mb-2">
                                        <label class="fw-bold small"><i class="fas fa-camera me-1 text-primary"></i>Foto de evidencia del daño (Opcional)</label>
                                        <input type="file" name="foto_evidencia" class="form-control form-control-sm" accept="image/*">
                                        <small class="text-muted d-block" style="font-size:10px;">Evidencia fotográfica para el expediente de baja técnica.</small>
                                    </div>
                                    <div class="mb-2">
                                        <label class="fw-bold small">Dictamen técnico *</label>
                                        <textarea name="dictamen_tecnico" class="form-control" rows="2" placeholder="Dictamen concluyente del tallerista..."></textarea>
                                    </div>
                                </div>
                                <div class="d-flex gap-2 mt-2">
                                    <button type="submit" name="terminar_diagnostico" class="btn btn-primary btn-sm flex-fill">Guardar</button>
                                    <button type="button" class="btn btn-secondary btn-sm flex-fill" onclick="mostrarInfo(<?= $p['id_novedad'] ?>)">Cancelar</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent border-0 text-center pb-3">
                        <small class="text-muted">Orden: <?= e($p['numero_orden']) ?></small>
                    </div>
                </div>
            </div>

            <script>
            function mostrarFormulario(id) {
                document.getElementById('infoView_' + id).style.display = 'none';
                document.getElementById('formView_' + id).style.display = 'block';
            }
            function mostrarInfo(id) {
                document.getElementById('infoView_' + id).style.display = 'block';
                document.getElementById('formView_' + id).style.display = 'none';
            }
            function toggleBajaFields(id) {
                let radios = document.querySelectorAll('#formView_' + id + ' input[name="accion"]');
                let bajaDiv = document.getElementById('bajaFields_' + id);
                let selected = null;
                radios.forEach(r => { if (r.checked) selected = r.value; });
                if (selected === 'baja') {
                    bajaDiv.style.display = 'block';
                    let mot = bajaDiv.querySelector('textarea[name="motivo_baja"]');
                    let cau = bajaDiv.querySelector('select[name="causa_especifica"]');
                    let dic = bajaDiv.querySelector('textarea[name="dictamen_tecnico"]');
                    if (mot) mot.required = true;
                    if (cau) cau.required = true;
                    if (dic) dic.required = true;
                } else {
                    bajaDiv.style.display = 'none';
                    bajaDiv.querySelectorAll('textarea, select, input').forEach(el => el.required = false);
                }
            }
            function calcularPorcentajeBaja(id, valorCompra) {
                const costoInput = document.getElementById('costo_estimado_' + id);
                const pctInput = document.getElementById('porcentaje_costo_' + id);
                const alertDiv = document.getElementById('alerta_porcentaje_' + id);
                if (!costoInput || !pctInput) return;
                const costo = parseFloat(costoInput.value) || 0;
                const valor = parseFloat(valorCompra) || 0;
                if (valor > 0) {
                    const pct = Math.round((costo / valor) * 100);
                    pctInput.value = pct;
                    if (alertDiv) {
                        if (pct >= 70) {
                            alertDiv.innerHTML = '<span class="badge bg-danger mt-1">⚠️ Costo supera el ' + pct + '% del valor (Aplica Baja)</span>';
                        } else {
                            alertDiv.innerHTML = '<span class="badge bg-secondary mt-1">Costo representa el ' + pct + '% del valor</span>';
                        }
                    }
                }
            }
            </script>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Popup de voucher (si se generó oficio) -->
<?php if (!empty($_SESSION['voucher_url'])): ?>
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
                <h5 class="modal-title w-100 text-center fw-bold" style="color: var(--accent-cyan);"><i class="fas fa-check-circle text-success me-2"></i><?= e($_SESSION['voucher_title'] ?? 'Documento') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <i class="fas fa-print fa-4x mb-3" style="color: var(--accent-cyan);"></i>
                <p class="mb-3">¿Deseas imprimir el oficio de exclusión?</p>
                <div class="d-flex justify-content-center gap-2">
                    <a href="<?= e($_SESSION['voucher_url']) ?>" target="_blank" class="btn btn-primary rounded-pill px-4"><i class="fas fa-print me-1"></i> Imprimir</a>
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php 
unset($_SESSION['voucher_url'], $_SESSION['voucher_title']);
endif; 
?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>