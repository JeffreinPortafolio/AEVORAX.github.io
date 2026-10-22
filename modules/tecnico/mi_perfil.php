<?php
require_once __DIR__ . '/../../core/conexion.php';
restringirAcceso(['administrador', 'tecnico', 'recepcionista', 'consultor']);

$id_sesion = $_SESSION['usuario_id'];
$stmt = $conexion->prepare("SELECT * FROM usuarios WHERE id_usuario = ?");
$stmt->execute([$id_sesion]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$usuario) { header("Location: /Asset-UDO/auth/logout.php"); exit; }

// ------------------------------------------------------------
// PROCESAR FORMULARIOS
// ------------------------------------------------------------

// 1. SUBIR SOLO LA FOTO (vía AJAX) - sin cambios, pero se incluye
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES['foto_ajax']) && isset($_POST['subir_foto_ajax'])) {
    header('Content-Type: application/json');
    $respuesta = ['success' => false, 'message' => '', 'foto_url' => ''];
    if (isset($_FILES['foto_ajax']) && $_FILES['foto_ajax']['error'] === UPLOAD_ERR_OK) {
        $errImg = '';
        $foto_path = procesarImagenSubida($_FILES['foto_ajax'], 'perfiles', 'perfil_' . (int)$id_sesion, $errImg, 800, 12);
        if ($foto_path) {
            borrarImagenSubida($usuario['foto'] ?? '');
            $conexion->prepare("UPDATE usuarios SET foto = ? WHERE id_usuario = ?")->execute([$foto_path, $id_sesion]);
            $_SESSION['foto'] = $foto_path;
            $respuesta['success'] = true;
            $respuesta['foto_url'] = '/Asset-UDO/' . $foto_path . '?v=' . time();
            $respuesta['message'] = "Foto actualizada correctamente.";
        } else {
            $respuesta['message'] = $errImg;
        }
    } else {
        $respuesta['message'] = "No se recibió ninguna imagen.";
    }
    echo json_encode($respuesta);
    exit;
}

// 2. ACTUALIZAR DATOS PERSONALES (nombre, teléfono, etc.) - incluye fecha
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['actualizar_datos'])) {
    $nombre_nuevo = limpiar($_POST['nombre']);
    $cedula       = limpiar($_POST['cedula']);
    $telefono     = limpiar($_POST['telefono']);
    $direccion    = limpiar($_POST['direccion']);
    $cargo        = limpiar($_POST['cargo']);
    $fecha_nac_raw = trim($_POST['fecha_nacimiento'] ?? '');

    // Convertir fecha de DD-MM-AAAA a YYYY-MM-DD si está presente
    $fecha_nac = null;
    if (!empty($fecha_nac_raw)) {
        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $fecha_nac_raw)) {
            $partes = explode('-', $fecha_nac_raw);
            $dia = (int)$partes[0];
            $mes = (int)$partes[1];
            $anio = (int)$partes[2];
            if (checkdate($mes, $dia, $anio) && $anio >= 1900 && $anio <= date('Y')) {
                $fecha_nac = sprintf("%04d-%02d-%02d", $anio, $mes, $dia);
            } else {
                $_SESSION['flash_error'] = "La fecha de nacimiento no es válida. Verifique día, mes y año (1900-".date('Y').").";
                header("Location: mi_perfil.php");
                exit;
            }
        } else {
            $_SESSION['flash_error'] = "Formato de fecha incorrecto. Debe ser DD-MM-AAAA (ejemplo: 20-05-1990).";
            header("Location: mi_perfil.php");
            exit;
        }
    }

    $conexion->prepare("UPDATE usuarios SET nombre=?, cedula=?, telefono=?, direccion=?, cargo=?, fecha_nacimiento=? WHERE id_usuario=?")
        ->execute([$nombre_nuevo, $cedula, $telefono, $direccion, $cargo, $fecha_nac, $id_sesion]);
    registrarAuditoria($conexion, "Actualizó sus datos personales", "usuarios", $id_sesion);
    $_SESSION['nombre'] = $nombre_nuevo;
    $_SESSION['flash_mensaje'] = "✅ Datos actualizados correctamente.";
    header("Location: mi_perfil.php");
    exit;
}

// 3. CAMBIAR CONTRASEÑA (sin cambios)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['cambiar_clave'])) {
    $clave_actual = $_POST['clave_actual'];
    $nueva        = $_POST['nueva_clave'];
    $confirm      = $_POST['confirm_clave'];

    $error_clave = '';
    if (!password_verify($clave_actual, $usuario['clave'])) {
        $error_clave = "La contraseña actual es incorrecta.";
    } elseif (password_verify($nueva, $usuario['clave'])) {
        $error_clave = "La nueva contraseña no puede ser igual a la actual.";
    } elseif (strlen($nueva) < 6) {
        $error_clave = "La nueva contraseña debe tener al menos 6 caracteres.";
    } elseif ($nueva !== $confirm) {
        $error_clave = "Las contraseñas no coinciden.";
    }

    if ($error_clave) {
        $_SESSION['flash_modal_error'] = $error_clave;
        $_SESSION['flash_modal_abrir'] = 'cambiar_clave';
    } else {
        $hash = password_hash($nueva, PASSWORD_DEFAULT);
        $conexion->prepare("UPDATE usuarios SET clave=? WHERE id_usuario=?")->execute([$hash, $id_sesion]);
        registrarAuditoria($conexion, "Cambió su contraseña", "usuarios", $id_sesion);
        $_SESSION['flash_mensaje'] = "🔐 Contraseña actualizada correctamente.";
    }
    header("Location: mi_perfil.php");
    exit;
}

// 4. GUARDAR PREGUNTAS (sin cambios)
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['guardar_preguntas'])) {
    $clave_actual_preg = $_POST['clave_actual_preguntas'] ?? '';
    if (!password_verify($clave_actual_preg, $usuario['clave'])) {
        $_SESSION['flash_error'] = "La contraseña actual es incorrecta para modificar preguntas.";
    } else {
        $p1 = limpiar($_POST['pregunta1']);
        $r1 = password_hash(limpiar($_POST['respuesta1']), PASSWORD_DEFAULT);
        $p2 = limpiar($_POST['pregunta2']);
        $r2 = password_hash(limpiar($_POST['respuesta2']), PASSWORD_DEFAULT);
        $p3 = limpiar($_POST['pregunta3']);
        $r3 = password_hash(limpiar($_POST['respuesta3']), PASSWORD_DEFAULT);

        $conexion->prepare("UPDATE usuarios SET pregunta1=?, respuesta1_hash=?, pregunta2=?, respuesta2_hash=?, pregunta3=?, respuesta3_hash=? WHERE id_usuario=?")
            ->execute([$p1, $r1, $p2, $r2, $p3, $r3, $id_sesion]);
        $_SESSION['flash_mensaje'] = "🔒 Preguntas de seguridad actualizadas.";
    }
    header("Location: mi_perfil.php");
    exit;
}

// ------------------------------------------------------------
// INTERFAZ
// ------------------------------------------------------------
include __DIR__ . '/../../includes/header.php';

$mensaje = $_SESSION['flash_mensaje'] ?? '';
$error   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_mensaje'], $_SESSION['flash_error']);

$modal_abrir = $_SESSION['flash_modal_abrir'] ?? '';
$modal_error = $_SESSION['flash_modal_error'] ?? '';
unset($_SESSION['flash_modal_abrir'], $_SESSION['flash_modal_error']);

$preguntas_disponibles = [
    '¿Nombre de tu primera mascota?',
    '¿Ciudad de nacimiento de tu madre?',
    '¿Nombre de tu escuela primaria?',
    '¿Comida favorita?',
    '¿Color favorito?',
    '¿Nombre de tu mejor amigo de la infancia?',
    '¿Modelo de tu primer carro?',
    '¿Segundo nombre de tu padre?'
];

$valoresOriginales = [
    'nombre' => limpiar($usuario['nombre']),
    'cedula' => limpiar($usuario['cedula']),
    'telefono' => limpiar($usuario['telefono']),
    'cargo' => limpiar($usuario['cargo']),
    'direccion' => limpiar($usuario['direccion']),
    'fecha_nacimiento' => $usuario['fecha_nacimiento'] ?? ''
];

$fecha_creacion = $usuario['fecha_creacion'] ?? '';
$dias_miembro = $fecha_creacion ? (new DateTime($fecha_creacion))->diff(new DateTime())->days : 0;
$ultimo_acceso = $usuario['ultimo_acceso'] ? date('d/m/Y H:i', strtotime($usuario['ultimo_acceso'])) : 'Nunca';

$preguntas_actuales = [];
for ($i = 1; $i <= 3; $i++) {
    $preg = $usuario["pregunta$i"] ?? null;
    if ($preg) $preguntas_actuales[] = ['numero' => $i, 'pregunta' => $preg];
}

$foto_actual = (!empty($usuario['foto']) && file_exists(__DIR__.'/../../'.$usuario['foto']))
               ? '/Asset-UDO/'.$usuario['foto'] . '?t=' . filemtime(__DIR__.'/../../'.$usuario['foto'])
               : null;
$tiene_foto = !empty($foto_actual);
?>

<div class="container-fluid animate__animated animate__fadeIn px-2 px-md-4">
    <div class="row"><div class="col-xl-10 mx-auto">
        <h3 class="fw-bold mb-3"><i class="fas fa-id-card me-2" style="color:var(--accent-cyan);"></i>Mi Perfil</h3>
        <?php if($mensaje): ?><div class="alert alert-success animate__animated animate__fadeInDown"><?= flashHtml($mensaje) ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger animate__animated animate__shakeX"><?= flashHtml($error) ?></div><?php endif; ?>

        <!-- CABECERA: foto, nombre, rol y datos de cuenta -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 perfil-hero">
            <div class="perfil-cover"></div>
            <div class="card-body pt-0 px-3 px-md-4 pb-4">
                <div class="perfil-head d-flex flex-column flex-md-row align-items-center align-items-md-end gap-3">
                    <div class="avatar-wrapper">
                        <div class="avatar-box" id="avatarBox" role="button" tabindex="0" title="Ver foto" onclick="mostrarModalFoto()">
                            <?php if($tiene_foto): ?>
                                <img id="previewAvatar" src="<?= e($foto_actual) ?>" alt="Foto de perfil">
                            <?php else: ?>
                                <i class="fas fa-user"></i>
                            <?php endif; ?>
                        </div>
                        <div class="avatar-loading" id="avatarLoading" style="display:none;"><div class="spinner-border spinner-border-sm text-light" role="status"></div></div>
                        <label for="inputFoto" class="camera-icon" title="Cambiar foto (cámara o galería)"><i class="fas fa-camera"></i></label>
                        <input type="file" id="inputFoto" accept="image/*" hidden>
                    </div>
                    <div class="text-center text-md-start flex-grow-1">
                        <h4 class="fw-bold mb-1"><?= e($usuario['nombre']) ?></h4>
                        <div class="d-flex flex-wrap justify-content-center justify-content-md-start align-items-center gap-2">
                            <span class="badge badge-info"><?= e(ucfirst($usuario['rol'])) ?></span>
                            <?php if(!empty($usuario['cargo'])): ?><span class="text-muted small"><i class="fas fa-briefcase me-1"></i><?= e($usuario['cargo']) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="perfil-stats d-flex gap-4">
                        <div><span class="k">Miembro desde</span><span class="v"><?= $fecha_creacion ? date('d/m/Y', strtotime($fecha_creacion)) : '—' ?></span><span class="s"><?= (int)$dias_miembro ?> días</span></div>
                        <div><span class="k">Último acceso</span><span class="v"><?= e($ultimo_acceso) ?></span></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- INFORMACIÓN PERSONAL, ordenada por secciones -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold mb-0"><i class="fas fa-user-edit me-2" style="color:var(--accent-cyan);"></i>Información personal</h5>
                            <button type="button" class="btn btn-outline-primary btn-sm rounded-pill px-3" id="btnEditar" onclick="habilitarEdicion()">
                                <i class="fas fa-pen me-1"></i>Editar
                            </button>
                        </div>

                        <form method="POST" id="formPerfil" class="perfil-form">
                            <div class="perfil-section">
                                <div class="perfil-section-title"><i class="fas fa-user"></i>Identidad</div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="nombre">Nombre completo *</label>
                                        <input type="text" name="nombre" id="nombre" class="form-control" value="<?= e($usuario['nombre']) ?>" readonly required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="cedula">Cédula de identidad</label>
                                        <input type="text" name="cedula" id="cedula" class="form-control" value="<?= e($usuario['cedula']) ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="fecha_nacimiento">Fecha de nacimiento</label>
                                        <input type="text" name="fecha_nacimiento" id="fecha_nacimiento" class="form-control font-monospace" maxlength="10" placeholder="DD-MM-AAAA" autocomplete="off" onfocus="this.select()" oninput="validarFechaInput(this)" value="<?= $usuario['fecha_nacimiento'] ? date('d-m-Y', strtotime($usuario['fecha_nacimiento'])) : '' ?>" readonly>
                                        <div class="invalid-feedback" id="error_fecha" style="color: #f87171;"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="perfil-section">
                                <div class="perfil-section-title"><i class="fas fa-address-book"></i>Contacto</div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Correo electrónico</label>
                                        <input type="email" class="form-control siempre-ro" value="<?= e($usuario['email']) ?>" readonly tabindex="-1">
                                        <div class="form-text">El correo no se puede modificar.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="telefono">Teléfono / Celular</label>
                                        <input type="text" name="telefono" id="telefono" class="form-control" value="<?= e($usuario['telefono']) ?>" readonly>
                                    </div>
                                </div>
                            </div>

                            <div class="perfil-section mb-0">
                                <div class="perfil-section-title"><i class="fas fa-building-columns"></i>Institución</div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label" for="cargo">Cargo / Posición</label>
                                        <input type="text" name="cargo" id="cargo" class="form-control" value="<?= e($usuario['cargo']) ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label" for="direccion">Dirección / Oficina</label>
                                        <input type="text" name="direccion" id="direccion" class="form-control" value="<?= e($usuario['direccion']) ?>" readonly>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 d-flex flex-wrap gap-2">
                                <button type="submit" name="actualizar_datos" id="btnActualizar" class="btn btn-primary rounded-pill px-4" style="display:none;">
                                    <i class="fas fa-save me-2"></i>Guardar cambios
                                </button>
                                <button type="button" id="btnCancelar" class="btn btn-outline-secondary rounded-pill px-4" style="display:none;" onclick="cancelarEdicion()">
                                    <i class="fas fa-times me-2"></i>Cancelar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- SEGURIDAD DE LA CUENTA -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body p-4">
                        <h5 class="fw-bold mb-3"><i class="fas fa-shield-alt me-2" style="color:var(--accent-cyan);"></i>Seguridad</h5>
                        <div class="perfil-seg-item">
                            <div><div class="fw-semibold">Contraseña</div><div class="text-muted small">Cámbiala periódicamente.</div></div>
                            <button type="button" class="btn btn-outline-primary btn-sm rounded-pill" data-bs-toggle="modal" data-bs-target="#modalCambiarClave"><i class="fas fa-lock me-1"></i>Cambiar</button>
                        </div>
                        <div class="perfil-seg-item">
                            <div><div class="fw-semibold">Preguntas de seguridad</div><div class="text-muted small"><?= count($preguntas_actuales) ?> de 3 configuradas</div></div>
                            <div class="d-flex gap-1">
                                <button type="button" class="btn btn-outline-info btn-sm rounded-pill" data-bs-toggle="modal" data-bs-target="#modalVerPreguntas" title="Ver preguntas"><i class="fas fa-eye"></i></button>
                                <button type="button" class="btn btn-outline-primary btn-sm rounded-pill" onclick="togglePreguntas()" title="Editar preguntas"><i class="fas fa-edit"></i></button>
                            </div>
                        </div>
                        <div class="text-muted small mt-3"><i class="fas fa-info-circle me-1"></i>Las preguntas sirven para recuperar tu contraseña si la olvidas.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- EDITAR PREGUNTAS (se muestra al pulsar el lápiz) -->
        <div id="seccionPreguntas" class="card border-0 shadow-sm rounded-4 mt-4" style="display: none;">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-1"><i class="fas fa-key me-2" style="color:var(--accent-cyan);"></i>Editar preguntas de seguridad</h5>
                <p class="text-muted small">Confirma con tu contraseña actual y elige tres preguntas con sus respuestas.</p>
                <form method="POST" class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Contraseña actual (para confirmar) *</label>
                        <input type="password" name="clave_actual_preguntas" class="form-control" required>
                    </div>
                    <div class="w-100 m-0"></div>
                    <?php for($i=1; $i<=3; $i++): ?>
                    <div class="col-md-4">
                        <label class="form-label">Pregunta <?= $i ?></label>
                        <select name="pregunta<?= $i ?>" class="form-select" required>
                            <option value="">-- Selecciona --</option>
                            <?php foreach($preguntas_disponibles as $p): ?>
                                <option value="<?= e($p) ?>" <?= ($usuario["pregunta$i"] ?? '') == $p ? 'selected' : '' ?>><?= e($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="respuesta<?= $i ?>" class="form-control mt-2" placeholder="Tu respuesta" required>
                    </div>
                    <?php endfor; ?>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" name="guardar_preguntas" class="btn btn-primary rounded-pill px-4"><i class="fas fa-save me-2"></i>Guardar preguntas</button>
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-4" onclick="togglePreguntas()">Cancelar</button>
                    </div>
                </form>
            </div>
        </div>
    </div></div>
</div>

<!-- Modal Cambiar Contraseña -->
<div class="modal fade" id="modalCambiarClave" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom">
                <h5 class="fw-bold mb-0"><i class="fas fa-lock me-2 text-primary"></i>Cambiar Contraseña</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="formCambiarClave" autocomplete="off">
                <?= csrfCampo() ?>
                <div class="modal-body p-4">
                    <div id="alertaLocal" class="alert alert-danger" style="display:none;"></div>
                    <div class="mb-3 position-relative">
                        <label class="form-label">Contraseña Actual *</label>
                        <input type="password" name="clave_actual" id="clave_actual_input" class="form-control" required autocomplete="off">
                        <i class="fas fa-eye-slash toggle-password position-absolute" style="right:15px; top:42px; cursor:pointer; color:#8a92a4;" onclick="togglePassword('clave_actual_input', this)"></i>
                    </div>
                    <div class="mb-3 position-relative">
                        <label class="form-label">Nueva Contraseña *</label>
                        <input type="password" name="nueva_clave" id="nueva_clave_input" class="form-control" required minlength="6" autocomplete="off">
                        <i class="fas fa-eye-slash toggle-password position-absolute" style="right:15px; top:42px; cursor:pointer; color:#8a92a4;" onclick="togglePassword('nueva_clave_input', this)"></i>
                        <div class="invalid-feedback">Mínimo 6 caracteres</div>
                    </div>
                    <div class="mb-3 position-relative">
                        <label class="form-label">Confirmar Nueva Contraseña *</label>
                        <input type="password" name="confirm_clave" id="confirm_clave_input" class="form-control" required minlength="6" autocomplete="off">
                        <i class="fas fa-eye-slash toggle-password position-absolute" style="right:15px; top:42px; cursor:pointer; color:#8a92a4;" onclick="togglePassword('confirm_clave_input', this)"></i>
                        <div class="invalid-feedback" id="confirmFeedback">Las contraseñas no coinciden</div>
                    </div>
                </div>
                <div class="modal-footer border-top">
                    <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="cambiar_clave" id="btnAplicarClave" class="btn btn-primary rounded-pill px-4">Aplicar Cambio</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Ver Preguntas -->
<div class="modal fade" id="modalVerPreguntas" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom">
                <h5 class="fw-bold mb-0"><i class="fas fa-eye me-2 text-info"></i>Mis Preguntas de Seguridad</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <?php if (empty($preguntas_actuales)): ?>
                    <p class="text-muted mb-0">Aún no has configurado preguntas de seguridad.</p>
                <?php else: ?>
                    <ul class="list-group list-group-flush rounded-3">
                        <?php foreach ($preguntas_actuales as $pq): ?>
                            <li class="list-group-item">
                                <strong>Pregunta <?= $pq['numero'] ?>:</strong> <?= e($pq['pregunta']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <small class="text-muted mt-2 d-block">Las respuestas permanecen ocultas por seguridad.</small>
                <?php endif; ?>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Foto Grande -->
<div class="modal fade" id="modalFotoGrande" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-bottom">
                <h5 class="fw-bold mb-0"><i class="fas fa-image me-2 text-primary"></i>Foto de Perfil</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-4" id="modalFotoBody">
                <?php if($tiene_foto): ?>
                    <img src="<?= e($foto_actual) ?>" alt="Foto de perfil" class="modal-foto-img">
                <?php else: ?>
                    <i class="fas fa-user modal-foto-vacio"></i>
                <?php endif; ?>
            </div>
            <div class="modal-footer border-top">
                <label for="inputFoto" class="btn btn-primary rounded-pill mb-0" data-bs-dismiss="modal"><i class="fas fa-camera me-2"></i>Cambiar foto</label>
                <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script>
// ========== FUNCIONES DE EDICIÓN Y VALIDACIÓN ==========
const originales = {
    nombre: <?= json_encode($valoresOriginales['nombre'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    cedula: <?= json_encode($valoresOriginales['cedula'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    telefono: <?= json_encode($valoresOriginales['telefono'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    cargo: <?= json_encode($valoresOriginales['cargo'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    direccion: <?= json_encode($valoresOriginales['direccion'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    fecha_nacimiento: <?= json_encode($valoresOriginales['fecha_nacimiento'] ? date('d-m-Y', strtotime($valoresOriginales['fecha_nacimiento'])) : '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>
};

let editando = false;

function habilitarEdicion() {
    editando = true;
    document.getElementById('formPerfil').classList.add('editando');
    ['nombre','cedula','telefono','cargo','direccion','fecha_nacimiento'].forEach(id => {
        let el = document.getElementById(id);
        if (el) el.readOnly = false;
    });
    document.getElementById('nombre').focus();
    document.getElementById('btnActualizar').style.display = 'inline-block';
    document.getElementById('btnCancelar').style.display = 'inline-block';
    document.getElementById('btnEditar').style.display = 'none';
}

function cancelarEdicion() {
    editando = false;
    document.getElementById('formPerfil').classList.remove('editando');
    document.getElementById('nombre').value = originales.nombre;
    document.getElementById('cedula').value = originales.cedula;
    document.getElementById('telefono').value = originales.telefono;
    document.getElementById('cargo').value = originales.cargo;
    document.getElementById('direccion').value = originales.direccion;
    document.getElementById('fecha_nacimiento').value = originales.fecha_nacimiento;
    ['nombre','cedula','telefono','cargo','direccion','fecha_nacimiento'].forEach(id => {
        let el = document.getElementById(id);
        if (el) el.readOnly = true;
    });
    document.getElementById('btnActualizar').style.display = 'none';
    document.getElementById('btnCancelar').style.display = 'none';
    document.getElementById('btnEditar').style.display = 'inline-block';
}

// Reduce una imagen a un lado máximo y la recomprime como JPEG. Si falla, devuelve el archivo original.
function reducirImagen(file, maxLado, calidad) {
    return new Promise(function (resolve) {
        if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap) { resolve(file); return; }
        createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
            const f = Math.min(1, maxLado / Math.max(bmp.width, bmp.height));
            const c = document.createElement('canvas');
            c.width = Math.max(1, Math.round(bmp.width * f));
            c.height = Math.max(1, Math.round(bmp.height * f));
            const ctx = c.getContext('2d');
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
            ctx.drawImage(bmp, 0, 0, c.width, c.height);
            if (bmp.close) bmp.close();
            c.toBlob(function (blob) {
                if (!blob || blob.size >= file.size) { resolve(file); return; }
                resolve(new File([blob], 'perfil.jpg', { type: 'image/jpeg' }));
            }, 'image/jpeg', calidad);
        }).catch(function () { resolve(file); });
    });
}

// ========== FOTO DE PERFIL: vista previa inmediata + actualización en tiempo real ==========
function nodoAvatar(url, claseIcono) {
    if (url) { const i = document.createElement('img'); i.src = url; i.alt = 'Foto de perfil'; return i; }
    const ic = document.createElement('i'); ic.className = 'fas fa-user ' + (claseIcono || ''); return ic;
}
// Pinta la foto en TODOS los lugares a la vez: cabecera del perfil, avatar de la barra superior y vista ampliada.
function pintarAvatar(url) {
    const box = document.getElementById('avatarBox');
    if (box) { const n = nodoAvatar(url); if (url) n.id = 'previewAvatar'; box.replaceChildren(n); }
    const top = document.getElementById('headerAvatar');
    if (top) top.replaceChildren(nodoAvatar(url, 'text-white fa-sm'));
    const big = document.getElementById('modalFotoBody');
    if (big) { const n = nodoAvatar(url); n.className = url ? 'modal-foto-img' : 'fas fa-user modal-foto-vacio'; big.replaceChildren(n); }
}
function avisoPerfil(ok, texto) {
    const t = document.createElement('div');
    t.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger') + ' position-fixed top-0 start-50 translate-middle-x mt-3 animate__animated animate__fadeInDown';
    t.style.zIndex = '9999';
    const ic = document.createElement('i'); ic.className = 'fas ' + (ok ? 'fa-check-circle' : 'fa-triangle-exclamation') + ' me-2';
    t.appendChild(ic); t.appendChild(document.createTextNode(texto));
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
}
let fotoVigente = <?= json_encode($foto_actual ?: '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

document.getElementById('inputFoto').addEventListener('change', function (e) {
    const input = e.target;
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    input.value = '';
    if (file.type && !/^image\//.test(file.type)) { avisoPerfil(false, 'El archivo seleccionado no es una imagen.'); return; }

    const local = URL.createObjectURL(file);           // se ve al instante, sin esperar al servidor
    const cargando = document.getElementById('avatarLoading');
    pintarAvatar(local);
    cargando.style.display = 'flex';

    reducirImagen(file, 1024, 0.82).then(function (archivoFinal) {
        const fd = new FormData();
        fd.append('foto_ajax', archivoFinal, archivoFinal.name || file.name);
        fd.append('subir_foto_ajax', '1');
        return fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json());
    }).then(function (data) {
        if (!data || !data.success) { throw new Error((data && data.message) || 'No se pudo guardar la foto.'); }
        // Precarga la imagen definitiva para que el cambio no parpadee
        const pre = new Image();
        pre.onload = pre.onerror = function () { fotoVigente = data.foto_url; pintarAvatar(data.foto_url); URL.revokeObjectURL(local); };
        pre.src = data.foto_url;
        avisoPerfil(true, data.message || 'Foto actualizada correctamente.');
    }).catch(function (err) {
        pintarAvatar(fotoVigente || '');               // vuelve a la foto anterior
        URL.revokeObjectURL(local);
        avisoPerfil(false, (err && err.message && err.message.indexOf('JSON') === -1) ? err.message : 'No se pudo subir la foto. Revise su conexión o el tamaño de la imagen.');
    }).finally(function () { cargando.style.display = 'none'; });
});

function togglePreguntas() {
    const s = document.getElementById('seccionPreguntas');
    s.style.display = (s.style.display === 'none' || s.style.display === '') ? 'block' : 'none';
}

function togglePassword(id, icon) {
    const inp = document.getElementById(id);
    if (inp.type === 'password') { inp.type = 'text'; icon.classList.replace('fa-eye-slash','fa-eye'); }
    else { inp.type = 'password'; icon.classList.replace('fa-eye','fa-eye-slash'); }
}

function mostrarModalFoto() {
    new bootstrap.Modal(document.getElementById('modalFotoGrande')).show();
}

// ========== FUNCIÓN PARA AUTO-FORMATEAR FECHA EN DD-MM-AAAA ==========
function validarFechaInput(input) {
    let valor = input.value;
    let numeros = valor.replace(/\D/g, '');
    if (numeros.length > 8) numeros = numeros.substring(0, 8);
    
    let formateado = '';
    if (numeros.length > 0) {
        formateado = numeros.substring(0, 2);
        if (numeros.length >= 3) {
            formateado += '-' + numeros.substring(2, 4);
        }
        if (numeros.length >= 5) {
            formateado += '-' + numeros.substring(4, 8);
        }
    }
    input.value = formateado;
    
    let regex = /^\d{2}-\d{2}-\d{4}$/;
    let errorSpan = document.getElementById('error_fecha');
    if (formateado !== "" && !regex.test(formateado)) {
        input.classList.add('is-invalid');
        if (errorSpan) errorSpan.textContent = "Formato inválido. Use DD-MM-AAAA (ej: 20-05-1990).";
        return;
    }
    if (formateado !== "") {
        let partes = formateado.split('-');
        let dia = parseInt(partes[0], 10);
        let mes = parseInt(partes[1], 10);
        let anio = parseInt(partes[2], 10);
        let anioActual = new Date().getFullYear();
        let fechaValida = true;
        if (anio < 1900 || anio > anioActual) fechaValida = false;
        if (mes < 1 || mes > 12) fechaValida = false;
        let ultimoDia = new Date(anio, mes, 0).getDate();
        if (dia < 1 || dia > ultimoDia) fechaValida = false;
        if (!fechaValida) {
            input.classList.add('is-invalid');
            if (errorSpan) errorSpan.textContent = "Fecha no válida (día 01-31, mes 01-12, año 1900-"+anioActual+").";
        } else {
            input.classList.remove('is-invalid');
            if (errorSpan) errorSpan.textContent = "";
        }
    } else {
        input.classList.remove('is-invalid');
        if (errorSpan) errorSpan.textContent = "";
    }
}

// ========== VALIDACIÓN DE CONTRASEÑA (sin cambios) ==========
const nueva = document.getElementById('nueva_clave_input');
const conf = document.getElementById('confirm_clave_input');
const btnAplic = document.getElementById('btnAplicarClave');
const alertLoc = document.getElementById('alertaLocal');

function validarClave() {
    if (!nueva || !conf) return true;
    let ok = true;
    nueva.classList.remove('is-invalid');
    conf.classList.remove('is-invalid');
    if (alertLoc) alertLoc.style.display = 'none';
    if (nueva.value.length > 0 && nueva.value.length < 6) { nueva.classList.add('is-invalid'); ok = false; }
    if (conf.value.length > 0 && nueva.value !== conf.value) { conf.classList.add('is-invalid'); ok = false; }
    else if (conf.value.length > 0 && nueva.value === conf.value && nueva.value.length >= 6) conf.classList.remove('is-invalid');
    if (btnAplic) btnAplic.disabled = !ok;
    return ok;
}
if (nueva && conf) {
    nueva.addEventListener('input', validarClave);
    conf.addEventListener('input', validarClave);
}
if (document.getElementById('formCambiarClave')) {
    document.getElementById('formCambiarClave').addEventListener('submit', function(e) {
        if (!validarClave()) { e.preventDefault(); if(alertLoc){ alertLoc.style.display='block'; alertLoc.textContent='Corrige los campos marcados en rojo.'; } }
    });
}

document.getElementById('modalCambiarClave')?.addEventListener('show.bs.modal', function() {
    if (!<?= $modal_abrir === 'cambiar_clave' ? 'true' : 'false' ?>) {
        if (document.getElementById('clave_actual_input')) document.getElementById('clave_actual_input').value = '';
        if (nueva) nueva.value = ''; if (conf) conf.value = '';
        if (nueva) nueva.classList.remove('is-invalid'); if (conf) conf.classList.remove('is-invalid');
        if (alertLoc) alertLoc.style.display = 'none'; if (btnAplic) btnAplic.disabled = false;
    }
    document.querySelectorAll('#modalCambiarClave .toggle-password').forEach(icon => {
        icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash');
    });
});

<?php if ($modal_abrir === 'cambiar_clave'): ?>
window.addEventListener('load', function() {
    var md = new bootstrap.Modal(document.getElementById('modalCambiarClave'));
    md.show();
    if (document.getElementById('alertaLocal')) {
        document.getElementById('alertaLocal').style.display='block';
        document.getElementById('alertaLocal').textContent = <?= json_encode($modal_error, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    }
    if (document.getElementById('clave_actual_input')) document.getElementById('clave_actual_input').value = '';
    if (nueva) nueva.value = ''; if (conf) conf.value = '';
    if (nueva) nueva.classList.remove('is-invalid'); if (conf) conf.classList.remove('is-invalid');
    if (btnAplic) btnAplic.disabled = false;
});
<?php endif; ?>
</script>

<style>
/* ---- Cabecera ---- */
.perfil-hero { overflow: visible; }
.perfil-cover { height: 96px; border-radius: 1rem 1rem 0 0; background: linear-gradient(135deg, var(--udo-blue), var(--accent-cyan)); opacity: .9; }
.perfil-head { margin-top: -44px; }
.avatar-wrapper { position: relative; flex: 0 0 auto; }
.avatar-box { width: 104px; height: 104px; border-radius: 50%; overflow: hidden; cursor: pointer; border: 4px solid var(--bs-body-bg, #fff); box-shadow: 0 4px 14px rgba(0,0,0,.25); background: linear-gradient(135deg, var(--accent-cyan), var(--udo-blue)); color: #fff; font-size: 2.6rem; display: flex; align-items: center; justify-content: center; transition: transform .2s; }
.avatar-box:hover { transform: scale(1.04); }
.avatar-box img { width: 100%; height: 100%; object-fit: cover; display: block; }
.avatar-loading { position: absolute; inset: 0; border-radius: 50%; background: rgba(0,0,0,.45); align-items: center; justify-content: center; z-index: 3; }
.camera-icon { position: absolute; bottom: 2px; right: 2px; background: var(--accent-cyan); color: #fff; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 2px 6px rgba(0,0,0,.4); transition: .2s; z-index: 4; border: 2px solid var(--bs-body-bg, #fff); }
.camera-icon:hover { background: var(--udo-blue); }
.camera-icon i { font-size: 13px; }
.perfil-stats .k { display: block; font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: var(--bs-secondary-color, #6c757d); }
.perfil-stats .v { display: block; font-weight: 600; }
.perfil-stats .s { display: block; font-size: .75rem; color: var(--bs-secondary-color, #6c757d); }
/* Barra superior: la foto nueva se ajusta al círculo */
#headerAvatar img { width: 100%; height: 100%; object-fit: cover; }
/* ---- Formulario por secciones ---- */
.perfil-section { margin-bottom: 1.5rem; padding-bottom: 1.5rem; border-bottom: 1px dashed var(--bs-border-color, #dee2e6); }
.perfil-section.mb-0 { padding-bottom: 0; border-bottom: 0; }
.perfil-section-title { font-size: .78rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--accent-cyan); margin-bottom: .9rem; }
.perfil-section-title i { margin-right: .5rem; }
.perfil-form .form-label { font-size: .82rem; font-weight: 600; margin-bottom: .25rem; color: var(--bs-secondary-color, #6c757d); }
/* Modo lectura: se ve como texto; al pulsar Editar aparecen los campos */
.perfil-form:not(.editando) .form-control[readonly] { background: transparent; border-color: transparent; padding-left: 0; font-weight: 500; box-shadow: none; }
.perfil-form.editando .form-control[readonly].siempre-ro { opacity: .7; }
.perfil-seg-item { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .85rem 0; border-bottom: 1px solid var(--bs-border-color, #dee2e6); }
.perfil-seg-item:last-of-type { border-bottom: 0; }
/* ---- Vista ampliada ---- */
.modal-foto-img { max-width: 100%; max-height: 70vh; border-radius: .75rem; }
.modal-foto-vacio { width: 200px; height: 200px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: linear-gradient(135deg, var(--accent-cyan), var(--udo-blue)); color: #fff; font-size: 5rem; }
.toggle-password { z-index: 10; }
@media (max-width: 767.98px) { .perfil-stats { width: 100%; justify-content: center; text-align: center; } }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>