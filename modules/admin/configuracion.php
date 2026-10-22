<?php
require_once __DIR__ . '/../../core/conexion.php';
restringirAcceso([ROL_ADMIN]);

$raiz = __DIR__ . '/../..';

const CONFIG_DEFAULTS = [
    'nombre_sistema'                 => 'SIGA-UDO',
    'nombre_completo'                => 'Sistema de Gestión de Activos - Universidad de Oriente',
    'nombre_institucion'             => 'Universidad de Oriente - Núcleo Monagas',
    'slogan'                         => 'Del pueblo venimos, hacia el pueblo vamos',
    'correo_envio'                   => '',
    'pass_correo'                    => '',
    'intentos_login'                 => '3',
    'tiempo_bloqueo'                 => '15',
    'duracion_sesion'                => '0',
    'modo_mantenimiento'             => '0',
    'mensaje_mantenimiento'          => 'El sistema se encuentra en mantenimiento. Por favor, intente más tarde.',
    'mantenimiento_inicio'           => '',
    'mantenimiento_fin'              => '',
    'clave_desbloqueo_mantenimiento' => 'admin1234',
    'dias_retencion_logs'            => '365',
    'logo_url'                       => '',
];

function cargarConfiguracion(PDO $conexion): array {
    $config = CONFIG_DEFAULTS;
    foreach ($conexion->query("SELECT clave, valor FROM configuracion") as $fila) {
        $config[$fila['clave']] = (string)$fila['valor'];
    }
    return $config;
}

function postTexto(string $campo, int $max = 255): string {
    return mb_substr(trim((string)($_POST[$campo] ?? '')), 0, $max);
}

/** Sube el logotipo (se valida y comprime). Devuelve la ruta relativa, null si no se envió archivo; llena $error si falla. */
function subirLogo(array $archivo, string $raiz, string &$error): ?string {
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    return procesarImagenSubida($archivo, 'config', 'logo', $error, 800, 5);
}

/** Borra un logo anterior, solo si está dentro de la carpeta de logotipos. */
function borrarLogo(string $ruta, string $raiz): void {
    if ($ruta === '' || strpos($ruta, 'assets/uploads/config/') !== 0 || strpos($ruta, '..') !== false) return;
    $archivo = $raiz . '/' . $ruta;
    if (is_file($archivo)) @unlink($archivo);
}

/** Descarga un volcado SQL completo de la base de datos y termina la petición. */
function descargarRespaldo(PDO $conexion): void {
    $sql  = "-- SIGA-UDO · Respaldo generado el " . date('Y-m-d H:i:s') . "\n";
    $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tablas = $conexion->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    foreach ($tablas as $filaTabla) {
        $tabla = $filaTabla[0];
        $crear = $conexion->query("SHOW CREATE TABLE `$tabla`")->fetch(PDO::FETCH_NUM);
        $sql .= "DROP TABLE IF EXISTS `$tabla`;\n" . $crear[1] . ";\n\n";
        foreach ($conexion->query("SELECT * FROM `$tabla`", PDO::FETCH_NUM) as $fila) {
            $valores = [];
            foreach ($fila as $v) $valores[] = $v === null ? 'NULL' : $conexion->quote((string)$v);
            $sql .= "INSERT INTO `$tabla` VALUES (" . implode(',', $valores) . ");\n";
        }
        $sql .= "\n";
    }
    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

    registrarAuditoria($conexion, 'Descargó un respaldo de la base de datos', 'configuracion');
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="backup_siga_udo_' . date('Ymd_His') . '.sql"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

/** Valida el formulario y guarda. Devuelve la lista de errores (vacía si todo se guardó). */
function guardarFormulario(PDO $conexion, array $actual, string $raiz): array {
    $errores = [];

    $nombreSistema = postTexto('nombre_sistema', 100);
    $institucion   = postTexto('nombre_institucion', 150);
    $correo        = postTexto('correo_envio', 100);
    $intentos      = (int)($_POST['intentos_login'] ?? 0);
    $bloqueo       = (int)($_POST['tiempo_bloqueo'] ?? 0);
    $sesion        = (int)($_POST['duracion_sesion'] ?? 0);
    $diasLogs      = (int)($_POST['dias_retencion_logs'] ?? 0);
    $inicio        = fechaHoraParaBD(postTexto('mantenimiento_inicio', 25));
    $fin           = fechaHoraParaBD(postTexto('mantenimiento_fin', 25));
    $claveDesbloq  = postTexto('clave_desbloqueo_mantenimiento', 100);

    if ($nombreSistema === '')                 $errores[] = 'El nombre del sistema es obligatorio.';
    if ($institucion === '')                   $errores[] = 'El nombre de la institución es obligatorio.';
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) $errores[] = 'El correo de envío no es válido.';
    if ($intentos < 1)                         $errores[] = 'Los intentos de inicio de sesión deben ser al menos 1.';
    if ($bloqueo < 1)                          $errores[] = 'El tiempo de bloqueo debe ser al menos 1 minuto.';
    if ($sesion < 0)                           $errores[] = 'La duración de sesión no puede ser negativa.';
    if ($diasLogs < 1)                         $errores[] = 'Los días de retención de auditoría deben ser al menos 1.';
    if ($claveDesbloq === '')                  $errores[] = 'La clave de desbloqueo de mantenimiento no puede estar vacía.';
    if (($inicio === '') !== ($fin === ''))    $errores[] = 'Indique tanto el inicio como el fin del mantenimiento programado (o deje ambos vacíos).';
    if ($inicio !== '' && $fin !== '' && $fin <= $inicio) $errores[] = 'El fin del mantenimiento debe ser posterior al inicio.';

    $errorLogo = '';
    $logoNuevo = subirLogo($_FILES['logo'] ?? [], $raiz, $errorLogo);
    if ($errorLogo !== '') $errores[] = $errorLogo;

    if ($errores) {
        if ($logoNuevo) borrarLogo($logoNuevo, $raiz);
        return $errores;
    }

    // La contraseña de aplicación solo se cambia si se escribe una nueva (en blanco = conservar).
    $passCorreo = preg_replace('/\s+/', '', (string)($_POST['pass_correo'] ?? ''));
    if ($passCorreo === '') $passCorreo = $actual['pass_correo'];

    $mensajeMant = postTexto('mensaje_mantenimiento', 1000);
    if ($mensajeMant === '') $mensajeMant = CONFIG_DEFAULTS['mensaje_mantenimiento'];

    $valores = [
        'nombre_sistema'                 => $nombreSistema,
        'nombre_completo'                => postTexto('nombre_completo', 200),
        'nombre_institucion'             => $institucion,
        'slogan'                         => postTexto('slogan', 200),
        'correo_envio'                   => $correo,
        'pass_correo'                    => $passCorreo,
        'intentos_login'                 => (string)$intentos,
        'tiempo_bloqueo'                 => (string)$bloqueo,
        'duracion_sesion'                => (string)$sesion,
        'modo_mantenimiento'             => '0', // "forzar mantenimiento" eliminado: solo rango programado
        'mensaje_mantenimiento'          => $mensajeMant,
        'mantenimiento_inicio'           => $inicio,
        'mantenimiento_fin'              => $fin,
        'clave_desbloqueo_mantenimiento' => $claveDesbloq,
        'dias_retencion_logs'            => (string)$diasLogs,
        'logo_url'                       => $logoNuevo ?? $actual['logo_url'],
    ];

    $conexion->beginTransaction();
    try {
        foreach ($valores as $clave => $valor) guardarConfiguracion($conexion, $clave, $valor);
        $conexion->commit();
    } catch (Exception $e) {
        $conexion->rollBack();
        if ($logoNuevo) borrarLogo($logoNuevo, $raiz);
        error_log('Configuración: ' . $e->getMessage());
        return ['No se pudo guardar la configuración. Inténtelo de nuevo.'];
    }

    if ($logoNuevo) borrarLogo($actual['logo_url'], $raiz);
    registrarAuditoria($conexion, 'Actualizó la configuración del sistema', 'configuracion');
    return [];
}

// ===================== PROCESAR ACCIONES (todas por POST + CSRF) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config = cargarConfiguracion($conexion);

    switch ($_POST['accion'] ?? 'guardar') {
        case 'respaldo':
            descargarRespaldo($conexion);   // termina la petición
            break;

        case 'limpiar_auditoria':
            $total = (int)$conexion->query("SELECT COUNT(*) FROM auditoria")->fetchColumn();
            if ($total > 0) {
                $conexion->exec("TRUNCATE TABLE auditoria");
                registrarAuditoria($conexion, "Eliminó toda la auditoría ($total registros)", 'auditoria');
                flash('success', "Toda la auditoría fue eliminada ($total registros).");
            } else {
                flash('success', 'La auditoría ya estaba vacía.');
            }
            break;

        case 'restaurar_logo':
            guardarConfiguracion($conexion, 'logo_url', '');
            borrarLogo($config['logo_url'], $raiz);
            registrarAuditoria($conexion, 'Restauró el logotipo predeterminado', 'configuracion');
            flash('success', 'Logotipo restaurado al ícono predeterminado.');
            break;

        default:
            $errores = guardarFormulario($conexion, $config, $raiz);
            if ($errores) flash('error', implode('<br>', $errores));
            else          flash('success', 'Configuración guardada correctamente.');
    }
    header('Location: configuracion.php');
    exit;
}

// ===================== VISTA =====================
$config    = cargarConfiguracion($conexion);
$auditoria = $conexion->query("SELECT COUNT(*) AS total, MIN(fecha_hora) AS desde FROM auditoria")->fetch();

$logoPreview = '';
if ($config['logo_url'] !== '' && is_file($raiz . '/' . $config['logo_url'])) {
    $logoPreview = url($config['logo_url']) . '?v=' . filemtime($raiz . '/' . $config['logo_url']);
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid animate__animated animate__fadeIn">
    <div class="page-header">
        <div>
            <h3 class="fw-bold mb-0"><i class="fas fa-cog me-2" style="color:var(--accent-cyan);"></i>Configuración del Sistema</h3>
            <p class="text-muted small mt-1 mb-0">Parámetros globales de SIGA-UDO</p>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" id="formConfig">
        <?= csrfCampo() ?>
        <input type="hidden" name="accion" value="guardar">

        <ul class="nav nav-tabs mb-4" id="configTabs" role="tablist">
            <li class="nav-item"><button class="nav-link active" type="button" data-bs-toggle="tab" data-bs-target="#general"><i class="fas fa-building me-1"></i>General</button></li>
            <li class="nav-item"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#correo"><i class="fas fa-envelope me-1"></i>Correo</button></li>
            <li class="nav-item"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#seguridad"><i class="fas fa-shield-alt me-1"></i>Seguridad</button></li>
            <li class="nav-item"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#mantenimiento"><i class="fas fa-tools me-1"></i>Mantenimiento</button></li>
            <li class="nav-item"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#auditoria"><i class="fas fa-history me-1"></i>Auditoría</button></li>
            <li class="nav-item"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#respaldo"><i class="fas fa-database me-1"></i>Respaldo</button></li>
        </ul>

        <div class="tab-content">
            <!-- GENERAL -->
            <div class="tab-pane fade show active" id="general">
                <div class="card mb-4">
                    <div class="card-header"><i class="fas fa-building me-2" style="color:var(--accent-cyan);"></i>Información general</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label">Nombre del sistema</label><input type="text" name="nombre_sistema" class="form-control" maxlength="100" required value="<?= e($config['nombre_sistema']) ?>"></div>
                            <div class="col-md-6"><label class="form-label">Nombre completo</label><input type="text" name="nombre_completo" class="form-control" maxlength="200" value="<?= e($config['nombre_completo']) ?>"></div>
                            <div class="col-md-6"><label class="form-label">Institución</label><input type="text" name="nombre_institucion" class="form-control" maxlength="150" required value="<?= e($config['nombre_institucion']) ?>"></div>
                            <div class="col-md-6"><label class="form-label">Slogan / lema</label><input type="text" name="slogan" class="form-control" maxlength="200" value="<?= e($config['slogan']) ?>"></div>
                            <div class="col-12">
                                <label class="form-label fw-bold">Logotipo</label>
                                <div class="d-flex flex-wrap align-items-start gap-4">
                                    <div>
                                        <small class="text-muted d-block mb-1">Actual</small>
                                        <?php if ($logoPreview): ?>
                                            <img src="<?= e($logoPreview) ?>" alt="Logotipo actual" class="logo-preview">
                                        <?php else: ?>
                                            <div class="text-muted small">Logo predeterminado</div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <small class="text-muted d-block mb-1">Nuevo archivo</small>
                                        <input type="file" name="logo" id="inputLogo" accept="image/png,image/jpeg,image/webp,image/gif" class="form-control">
                                        <div id="previewNuevoLogo" class="mt-2 d-none"><img id="imgPreview" src="" alt="Vista previa" class="logo-preview"></div>
                                    </div>
                                </div>
                                <small class="text-muted d-block mt-2">Formatos: JPG, PNG, WEBP o GIF. Máx. 5 MB.</small>
                                <?php if ($logoPreview): ?>
                                    <button type="submit" form="formAcciones" name="accion" value="restaurar_logo" class="btn btn-sm btn-outline-warning rounded-pill mt-2"
                                            onclick="return confirm('¿Restaurar el ícono predeterminado?')"><i class="fas fa-undo me-1"></i>Restaurar logo predeterminado</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CORREO -->
            <div class="tab-pane fade" id="correo">
                <div class="card mb-4">
                    <div class="card-header"><i class="fas fa-envelope me-2" style="color:var(--accent-cyan);"></i>Correo para notificaciones</div>
                    <div class="card-body">
                        <p class="text-muted small">Se usa para enviar correos automáticos (recuperación de contraseña).</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Correo electrónico de envío</label>
                                <input type="email" name="correo_envio" class="form-control" maxlength="100" value="<?= e($config['correo_envio']) ?>">
                                <small class="text-muted">Cuenta Gmail que enviará los correos.</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Contraseña de aplicación</label>
                                <input type="password" name="pass_correo" class="form-control" autocomplete="new-password"
                                       placeholder="<?= $config['pass_correo'] !== '' ? '•••• configurada (en blanco = conservar)' : 'Sin configurar' ?>">
                                <small class="text-muted">No uses tu contraseña normal de Gmail.</small>
                            </div>
                        </div>
                        <div class="info-box mt-3 small">
                            <strong><i class="fas fa-info-circle me-1"></i>¿Cómo obtener una contraseña de aplicación?</strong>
                            <ol class="mb-0 mt-2 ps-3">
                                <li>Activa la <strong>verificación en dos pasos</strong> en tu cuenta de Google.</li>
                                <li>Entra a <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener noreferrer">Contraseñas de aplicación</a> y genera una.</li>
                                <li>Copia los <strong>16 caracteres</strong> y pégalos aquí (los espacios se ignoran).</li>
                            </ol>
                            <div class="mt-2 text-muted"><i class="fas fa-shield-alt me-1"></i>Se usa el SMTP de Gmail (smtp.gmail.com, puerto 587, TLS).</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SEGURIDAD -->
            <div class="tab-pane fade" id="seguridad">
                <div class="card mb-4">
                    <div class="card-header"><i class="fas fa-shield-alt me-2" style="color:var(--accent-cyan);"></i>Parámetros de seguridad</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4"><label class="form-label">Intentos máximos de inicio</label><input type="number" name="intentos_login" class="form-control" min="1" required value="<?= e($config['intentos_login']) ?>"></div>
                            <div class="col-md-4"><label class="form-label">Tiempo de bloqueo (min)</label><input type="number" name="tiempo_bloqueo" class="form-control" min="1" required value="<?= e($config['tiempo_bloqueo']) ?>"></div>
                            <div class="col-md-4"><label class="form-label">Inactividad de sesión (min)</label><input type="number" name="duracion_sesion" class="form-control" min="0" required value="<?= e($config['duracion_sesion']) ?>"><small class="text-muted">0 = sin límite</small></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MANTENIMIENTO -->
            <div class="tab-pane fade" id="mantenimiento">
                <div class="card mb-4">
                    <div class="card-header"><i class="fas fa-tools me-2" style="color:var(--accent-cyan);"></i>Modo mantenimiento</div>
                    <div class="card-body">
                        <div class="alert alert-info small"><i class="fas fa-info-circle me-1"></i>Durante el mantenimiento solo el administrador puede usar el sistema. Se activa únicamente cuando la hora actual cae dentro del rango programado (inicio y fin). Deje ambos campos vacíos para no programar ningún mantenimiento.</div>
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label">Inicio programado</label><input type="datetime-local" name="mantenimiento_inicio" class="form-control" value="<?= e(fechaHoraParaInput($config['mantenimiento_inicio'])) ?>"></div>
                            <div class="col-md-6"><label class="form-label">Fin programado</label><input type="datetime-local" name="mantenimiento_fin" class="form-control" value="<?= e(fechaHoraParaInput($config['mantenimiento_fin'])) ?>"></div>
                            <div class="col-12"><label class="form-label">Mensaje para los usuarios</label><textarea name="mensaje_mantenimiento" class="form-control" rows="3" maxlength="1000"><?= e($config['mensaje_mantenimiento']) ?></textarea></div>
                            <div class="col-md-6"><label class="form-label">Clave de desbloqueo por URL</label><input type="text" name="clave_desbloqueo_mantenimiento" class="form-control" maxlength="100" required value="<?= e($config['clave_desbloqueo_mantenimiento']) ?>"><small class="text-muted">Use <code>?desactivar_mantenimiento=clave</code> si se bloquea el acceso.</small></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- AUDITORÍA -->
            <div class="tab-pane fade" id="auditoria">
                <div class="card mb-4">
                    <div class="card-header"><i class="fas fa-history me-2" style="color:var(--accent-cyan);"></i>Retención de registros</div>
                    <div class="card-body">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4"><label class="form-label">Días de retención</label><input type="number" name="dias_retencion_logs" class="form-control" min="1" required value="<?= e($config['dias_retencion_logs']) ?>"></div>
                            <div class="col-md-8 small text-muted">Los registros más antiguos se eliminan automáticamente (revisión una vez por hora).<br>
                                Hay <strong><?= (int)$auditoria['total'] ?></strong> registros<?= $auditoria['desde'] ? ' desde el ' . e(date('d/m/Y', strtotime($auditoria['desde']))) : '' ?>.</div>
                            <div class="col-12">
                                <button type="submit" form="formAcciones" name="accion" value="limpiar_auditoria" class="btn btn-outline-danger rounded-pill"
                                        onclick="return confirm('¡ATENCIÓN! Se borrará TODA la auditoría y no se puede deshacer. ¿Continuar?')"><i class="fas fa-trash-alt me-1"></i>Limpiar toda la auditoría</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RESPALDO -->
            <div class="tab-pane fade" id="respaldo">
                <div class="card mb-4">
                    <div class="card-header"><i class="fas fa-database me-2" style="color:var(--accent-cyan);"></i>Copia de seguridad manual</div>
                    <div class="card-body">
                        <p class="text-muted">Descargue una copia completa de la base de datos. El archivo SQL se restaura desde phpMyAdmin (Importar).</p>
                        <button type="submit" form="formAcciones" name="accion" value="respaldo" class="btn btn-primary"><i class="fas fa-download me-2"></i>Descargar base de datos (SQL)</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="savebar">
            <button type="submit" class="btn btn-primary px-5"><i class="fas fa-save me-2"></i>Guardar configuración</button>
        </div>
    </form>

    <!-- Formulario aparte para acciones puntuales: así «Enter» en un campo nunca las dispara -->
    <form method="POST" id="formAcciones" class="d-none"><?= csrfCampo() ?></form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var KEY = 'udo-config-tab';

    // Recordar la pestaña activa entre recargas
    var guardada = sessionStorage.getItem(KEY);
    if (guardada) {
        var boton = document.querySelector('#configTabs [data-bs-target="' + guardada + '"]');
        if (boton) bootstrap.Tab.getOrCreateInstance(boton).show();
    }
    document.querySelectorAll('#configTabs [data-bs-toggle="tab"]').forEach(function (b) {
        b.addEventListener('shown.bs.tab', function (ev) {
            sessionStorage.setItem(KEY, ev.target.getAttribute('data-bs-target'));
        });
    });

    // Si el navegador bloquea el envío por un campo inválido en otra pestaña, ir a esa pestaña
    document.getElementById('formConfig').addEventListener('invalid', function (ev) {
        var panel = ev.target.closest('.tab-pane');
        var boton = panel && document.querySelector('#configTabs [data-bs-target="#' + panel.id + '"]');
        if (boton) bootstrap.Tab.getOrCreateInstance(boton).show();
    }, true);

    // Vista previa del logotipo nuevo
    document.getElementById('inputLogo').addEventListener('change', function (ev) {
        var archivo = ev.target.files[0];
        var caja = document.getElementById('previewNuevoLogo');
        if (!archivo) { caja.classList.add('d-none'); return; }
        var lector = new FileReader();
        lector.onload = function (e) {
            document.getElementById('imgPreview').src = e.target.result;
            caja.classList.remove('d-none');
        };
        lector.readAsDataURL(archivo);
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
