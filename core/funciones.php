<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    // Las sesiones viven en una carpeta propia: otras apps del servidor (con gc_maxlifetime corto)
    // ya no pueden borrarlas. Antes, el celular volvía al login tras ~24 min de pausa.
    $dirSesiones = __DIR__ . '/../storage/sesiones';
    if (!is_dir($dirSesiones)) { @mkdir($dirSesiones, 0700, true); }
    if (is_dir($dirSesiones) && is_writable($dirSesiones)) {
        if (!file_exists($dirSesiones . '/.htaccess')) { @file_put_contents($dirSesiones . '/.htaccess', "Require all denied\nDeny from all\n"); }
        ini_set('session.save_path', $dirSesiones);
    }
    $vidaSesion = 12 * 3600;                      // la inactividad real la controla duracion_sesion (configuración)
    ini_set('session.gc_maxlifetime', (string)$vidaSesion);
    session_set_cookie_params([
        'lifetime' => $vidaSesion,                // cookie persistente: sobrevive si el navegador del teléfono se suspende
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ============================================================
// CONSTANTES DEL SISTEMA
// ============================================================
const BASE_URL = '/Asset-UDO';

const ROL_ADMIN         = 'administrador';
const ROL_TECNICO       = 'tecnico';
const ROL_RECEPCIONISTA = 'recepcionista';
const ROL_CONSULTOR     = 'consultor';
const ROLES_VALIDOS     = [ROL_ADMIN, ROL_TECNICO, ROL_RECEPCIONISTA, ROL_CONSULTOR];
/** Roles que un visitante puede solicitar desde el formulario público de registro. */
const ROLES_AUTOREGISTRO = [ROL_RECEPCIONISTA, ROL_TECNICO, ROL_CONSULTOR];

/** Construye una URL absoluta dentro de la aplicación. */
function url(string $ruta = ''): string {
    return BASE_URL . '/' . ltrim($ruta, '/');
}

/** Escapa un valor para imprimirlo de forma segura en HTML. */
function e($valor): string {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function esPeticionAjax(): bool {
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

/**
 * Normaliza un dato de entrada (recorta espacios). NO escapa HTML: el escape se hace al
 * imprimir con e(). Escapar al guardar provocaba doble codificación (O&amp;#039;Neil).
 */
function limpiar($dato): string {
    return trim((string)($dato ?? ''));
}

function registrarAuditoria($conexion, $accion, $tabla_afectada, $registro_id = null, $datos_anteriores = null, $datos_nuevos = null) {
    try {
        $usuario_id = $_SESSION['usuario_id'] ?? 0;
        $usuario_nombre = $_SESSION['nombre'] ?? 'Sistema';
        $usuario_rol = $_SESSION['rol'] ?? 'sistema';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $conexion->prepare("INSERT INTO auditoria (usuario_id, usuario_nombre, usuario_rol, accion, tabla_afectada, registro_id, datos_anteriores, datos_nuevos, ip_usuario, user_agent, fecha_hora) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$usuario_id, $usuario_nombre, $usuario_rol, $accion, $tabla_afectada, $registro_id, $datos_anteriores, $datos_nuevos, $ip, $user_agent]);
    } catch (Exception $e) {
        error_log("Error en auditoría: " . $e->getMessage());
    }
}

/**
 * Verificación estricta de sesión. En cada petición protegida:
 *  1. Debe existir sesión iniciada.
 *  2. (La sesión ya no se ata al User-Agent: cambiaba al inspeccionar o al pasar a modo móvil.)
 *  3. El usuario se revalida contra la BD (una consulta preparada, cacheada 60 s por sesión):
 *     debe existir, estar 'activo' y conservar el rol de la sesión. Si el admin desactiva
 *     o cambia de rol a alguien, el cambio surte efecto de inmediato (máx. 60 s).
 *  4. Solo los roles permitidos entran. Un rol fuera de ROLES_VALIDOS nunca entra.
 */
function cerrarSesionForzada(string $motivo = 'sesion_invalida'): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    @session_destroy();
    if (esPeticionAjax()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Sesión no válida. Inicie sesión de nuevo.']);
        exit;
    }
    header('Location: ' . url('auth/login.php?msj=' . urlencode($motivo)));
    exit;
}

function restringirAcceso($rolesPermitidos = []) {
    global $conexion;

    if (empty($_SESSION['usuario_id']) || empty($_SESSION['rol'])) {
        header('Location: ' . url('auth/login.php'));
        exit();
    }

    // Huella del navegador
    // Sin atar la sesión al User-Agent: cambia con el modo dispositivo de "Inspeccionar", el modo
    // "sitio de escritorio", actualizaciones del navegador o proxies de datos, y cerraba la sesión sin motivo.
    // La seguridad de la sesión la dan la cookie HttpOnly + SameSite, el ID regenerado al iniciar sesión y la revalidación contra la BD.

    // Revalidación contra la BD (cada 60 s como máximo)
    if (($_SESSION['verificado_en'] ?? 0) < time() - 60) {
        $errorBD = false; $u = null;
        try {
            $st = $conexion->prepare("SELECT rol, estado FROM usuarios WHERE id_usuario = ? LIMIT 1");
            $st->execute([(int)$_SESSION['usuario_id']]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('restringirAcceso: ' . $e->getMessage());
            $errorBD = true;   // fallo momentáneo de la BD: NO se cierra la sesión, se reintenta luego
        }
        if (!$errorBD) {
            if (!$u || $u['estado'] !== 'activo') {
                cerrarSesionForzada('cuenta_inactiva');
            }
            $_SESSION['rol'] = $u['rol']; // el rol de la BD manda
            $_SESSION['verificado_en'] = time();
        }
    }

    if (!in_array($_SESSION['rol'], ROLES_VALIDOS, true)) {
        cerrarSesionForzada();
    }
    if (!empty($rolesPermitidos) && !in_array($_SESSION['rol'], $rolesPermitidos, true)) {
        if (esPeticionAjax()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'No tiene permisos para esta acción.']);
            exit;
        }
        header('Location: ' . url('index.php?error=sin_permiso'));
        exit();
    }
}

/** Atajo: exige rol administrador. */
function exigirAdmin(): void {
    restringirAcceso([ROL_ADMIN]);
}

/** Registra el detalle técnico en el log del servidor y devuelve un mensaje genérico (sin exponer SQL ni rutas). */
function errorInterno(Throwable $e): string {
    error_log('[SIGA-UDO] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    return 'Ocurrió un error interno. Inténtelo de nuevo; si persiste, contacte al administrador.';
}

/** Escapa los comodines de LIKE (% _ \) para que una búsqueda sea literal. */
function escaparLike(string $texto): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto);
}

/**
 * Caché de configuración por petición: una sola consulta en vez de ~13 por página.
 * $reiniciar = true descarta la caché (se llama tras guardar un ajuste).
 */
function configuracionCache($conexion = null, bool $reiniciar = false): array {
    static $todo = null;
    if ($reiniciar) { $todo = null; }
    if ($todo === null && $conexion !== null) {
        $todo = [];
        try {
            foreach ($conexion->query("SELECT clave, valor FROM configuracion") as $fila) {
                $todo[$fila['clave']] = $fila['valor'];
            }
        } catch (Exception $e) {
            error_log('Configuración: ' . $e->getMessage());
            $todo = null;
            return [];
        }
    }
    return $todo ?? [];
}

function obtenerConfiguracion($conexion, $clave, $default = '') {
    $todo = configuracionCache($conexion);
    return (array_key_exists($clave, $todo) && $todo[$clave] !== null) ? $todo[$clave] : $default;
}

/** Guarda un parámetro; si la clave no existe todavía, la crea (clave es UNIQUE). */
function guardarConfiguracion(PDO $conexion, string $clave, string $valor): void {
    $stmt = $conexion->prepare(
        "INSERT INTO configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
    );
    $stmt->execute([$clave, $valor]);
    configuracionCache(null, true); // la próxima lectura vuelve a consultar la BD
}

/** Convierte un valor de <input type="datetime-local"> ("2026-10-21T08:30") a "2026-10-21 08:30:00". */
function fechaHoraParaBD(string $valor): string {
    $valor = trim(str_replace('T', ' ', $valor));
    if ($valor === '') return '';
    $ts = strtotime($valor);
    return $ts === false ? '' : date('Y-m-d H:i:s', $ts);
}

/** Convierte "2026-10-21 08:30:00" al formato de <input type="datetime-local">. */
function fechaHoraParaInput(string $valor): string {
    $ts = $valor !== '' ? strtotime($valor) : false;
    return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
}

// ============================================================
// MODO MANTENIMIENTO (solo programado: inicio y fin)
// ============================================================
function desactivarMantenimiento(PDO $conexion): void {
    guardarConfiguracion($conexion, 'modo_mantenimiento', '0');
    guardarConfiguracion($conexion, 'mantenimiento_inicio', '');
    guardarConfiguracion($conexion, 'mantenimiento_fin', '');
}

/**
 * Desbloqueo de emergencia por URL: ?desactivar_mantenimiento=<clave>
 * (la opción "forzar mantenimiento" fue eliminada del sistema).
 */
function procesarDesbloqueoMantenimiento(PDO $conexion, string $destino): void {
    $enviada = $_GET['desactivar_mantenimiento'] ?? null;
    if (!is_string($enviada) || $enviada === '') return;
    $clave = (string)obtenerConfiguracion($conexion, 'clave_desbloqueo_mantenimiento', '');
    if ($clave === '' || !hash_equals($clave, $enviada)) return;

    desactivarMantenimiento($conexion);
    header("Location: $destino");
    exit;
}

/** El sistema está en mantenimiento solo dentro del rango programado. */
function sistemaEnMantenimiento(PDO $conexion): bool {
    $inicio = fechaHoraParaBD((string)obtenerConfiguracion($conexion, 'mantenimiento_inicio', ''));
    $fin    = fechaHoraParaBD((string)obtenerConfiguracion($conexion, 'mantenimiento_fin', ''));
    if ($inicio !== '' && $fin !== '') {
        $ahora = date('Y-m-d H:i:s');
        return $ahora >= $inicio && $ahora <= $fin;
    }
    return false;
}

// ============================================================
// MENSAJES FLASH
// ============================================================
/** $tipo: 'error' | 'danger' para errores; cualquier otro ('success', 'mensaje', 'info') es un aviso positivo. */
function flash(string $tipo, string $mensaje): void {
    $esError = in_array($tipo, ['error', 'danger'], true);
    $_SESSION[$esError ? 'flash_error' : 'flash_mensaje'] = $mensaje;
}

/** Escapa un mensaje para HTML permitiendo únicamente <strong> y <br>. */
function flashHtml($mensaje): string {
    return str_replace(
        ['&lt;strong&gt;', '&lt;/strong&gt;', '&lt;br&gt;', '&lt;br/&gt;', '&lt;br /&gt;'],
        ['<strong>', '</strong>', '<br>', '<br>', '<br>'],
        e($mensaje)
    );
}

/** Devuelve y consume los mensajes flash pendientes: ['mensaje' => ..., 'error' => ...]. */
function consumirFlash(): array {
    $flash = [
        'mensaje' => $_SESSION['flash_mensaje'] ?? '',
        'error'   => $_SESSION['flash_error'] ?? '',
    ];
    unset($_SESSION['flash_mensaje'], $_SESSION['flash_error']);
    return $flash;
}

// ============================================================
// PROTECCIÓN CSRF
// ============================================================
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfCampo(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function csrfEsValido(): bool {
    $enviado = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return is_string($enviado) && $enviado !== '' && hash_equals(csrfToken(), $enviado);
}

/** Valida un token enviado explícitamente (usado por Recepción y Bajas). */
function validarCsrfToken($token): bool {
    if (!is_string($token) || $token === '') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }
    return is_string($token) && $token !== '' && hash_equals(csrfToken(), $token);
}

/**
 * Rechaza cualquier POST sin token CSRF válido.
 * El token se inyecta automáticamente en formularios y peticiones AJAX
 * mediante assets/js/csrf.js (ver includes/theme_head.php).
 */
function exigirCsrfEnPost(): void {
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    if (csrfEsValido()) return;

    $mensaje = 'Tu sesión expiró o la solicitud no es válida. Recarga la página e inténtalo de nuevo.';
    http_response_code(403);
    if (esPeticionAjax()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $mensaje]);
        exit;
    }
    flash('error', $mensaje);
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $mismoHost = $referer !== '' && parse_url($referer, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '');
    header('Location: ' . ($mismoHost ? $referer : url('index.php')));
    exit;
}

// ============================================================
// SUBIDA Y COMPRESIÓN AUTOMÁTICA DE IMÁGENES
// ============================================================
/**
 * Valida, comprime y guarda una imagen subida.
 *  - Verifica el contenido real (getimagesize), no solo la extensión.
 *  - Corrige la orientación EXIF, reduce a $maxLado px y recomprime (JPG 78, WEBP 78, PNG nivel 8).
 *  - Al re-codificar con GD se eliminan metadatos y cualquier código embebido.
 *  - El nombre final es aleatorio (no predecible) y se guarda en $carpeta bajo assets/uploads/.
 *
 * @param array  $archivo  Elemento de $_FILES.
 * @param string $carpeta  Subcarpeta de assets/uploads (equipos, perfiles, evidencias, chat, config).
 * @param string $prefijo  Prefijo del nombre de archivo.
 * @param string $error    (salida) mensaje de error si falla.
 * @param int    $maxLado  Lado máximo en píxeles.
 * @param int    $maxMB    Tamaño máximo de subida en MB.
 * @return string|null     Ruta relativa ('assets/uploads/...') o null si falla.
 */
function procesarImagenSubida(array $archivo, string $carpeta, string $prefijo, string &$error, int $maxLado = 1600, int $maxMB = 10): ?string {
    $error = '';
    $codigo = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($codigo !== UPLOAD_ERR_OK) { $error = 'No se pudo recibir la imagen (código ' . (int)$codigo . ').'; return null; }
    if (empty($archivo['tmp_name']) || !is_uploaded_file($archivo['tmp_name'])) { $error = 'Archivo de subida no válido.'; return null; }
    if (($archivo['size'] ?? 0) > $maxMB * 1024 * 1024) { $error = "La imagen no debe superar los $maxMB MB."; return null; }
    if (!preg_match('/^[a-z0-9_]+$/', $carpeta)) { $error = 'Carpeta de destino no válida.'; return null; }

    $info = @getimagesize($archivo['tmp_name']);
    $tipos = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
    if (defined('IMAGETYPE_WEBP')) $tipos[IMAGETYPE_WEBP] = 'webp';
    if (!$info || !isset($tipos[$info[2]])) { $error = 'Formato no permitido. Use JPG, PNG, GIF o WEBP.'; return null; }
    if ($info[0] * $info[1] > 50000000) { $error = 'La imagen tiene demasiados píxeles.'; return null; }

    $dir = dirname(__DIR__) . '/assets/uploads/' . $carpeta;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { $error = 'No se pudo crear la carpeta de destino.'; return null; }

    $ext     = $tipos[$info[2]];
    $nombre  = $prefijo . '_' . bin2hex(random_bytes(8));
    $destino = $dir . '/' . $nombre . '.' . $ext;

    // Sin GD o GIF (puede ser animado): se guarda tal cual, ya validado.
    $gdOk = function_exists('imagecreatefromstring') && function_exists('imagejpeg');
    if (!$gdOk || $info[2] === IMAGETYPE_GIF) {
        if (!move_uploaded_file($archivo['tmp_name'], $destino)) { $error = 'No se pudo guardar la imagen.'; return null; }
        return 'assets/uploads/' . $carpeta . '/' . $nombre . '.' . $ext;
    }

    $datos = @file_get_contents($archivo['tmp_name']);
    $img   = $datos !== false ? @imagecreatefromstring($datos) : false;
    unset($datos);
    if (!$img) { $error = 'La imagen está dañada o no se pudo procesar.'; return null; }

    // Orientación EXIF (fotos de celular)
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($archivo['tmp_name']);
        $giro = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
        if ($giro !== 0 && ($rot = @imagerotate($img, $giro, 0))) { $img = $rot; }
    }

    // Reducir si excede el lado máximo
    $w = imagesx($img); $h = imagesy($img);
    if (max($w, $h) > $maxLado) {
        $f = $maxLado / max($w, $h);
        $nw = max(1, (int)round($w * $f)); $nh = max(1, (int)round($h * $f));
        $nuevo = imagecreatetruecolor($nw, $nh);
        if ($info[2] === IMAGETYPE_PNG || $info[2] === IMAGETYPE_WEBP) {
            imagealphablending($nuevo, false); imagesavealpha($nuevo, true);
            imagefill($nuevo, 0, 0, imagecolorallocatealpha($nuevo, 0, 0, 0, 127));
        }
        imagecopyresampled($nuevo, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img); $img = $nuevo;
    }

    switch ($info[2]) {
        case IMAGETYPE_PNG:
            imagealphablending($img, false); imagesavealpha($img, true);
            $ok = imagepng($img, $destino, 8);
            break;
        case IMAGETYPE_WEBP:
            $ok = function_exists('imagewebp') && imagewebp($img, $destino, 78);
            break;
        default:
            imageinterlace($img, true); // JPEG progresivo: carga más fluida
            $ok = imagejpeg($img, $destino, 78);
    }
    imagedestroy($img);

    if (!$ok || !is_file($destino)) { $error = 'No se pudo guardar la imagen comprimida.'; return null; }
    @chmod($destino, 0644);
    return 'assets/uploads/' . $carpeta . '/' . $nombre . '.' . $ext;
}

/** Borra una imagen previa solo si está dentro de assets/uploads/ (evita borrar rutas arbitrarias). */
function borrarImagenSubida(?string $ruta): void {
    if (!$ruta) return;
    $ruta = ltrim($ruta, '/');
    if (strpos($ruta, 'assets/uploads/') !== 0 || strpos($ruta, '..') !== false) return;
    $archivo = dirname(__DIR__) . '/' . $ruta;
    if (is_file($archivo)) @unlink($archivo);
}

function getEstadoColor($estado) {
    $colores = [
        'Operativo' => 'success',
        'En Recepción' => 'warning',
        'En Reparación' => 'info',
        'Reparado' => 'primary',
        'Baja Técnica' => 'danger',
        'Desincorporado' => 'secondary'
    ];
    return $colores[$estado] ?? 'secondary';
}

function getEstadoIcono($estado) {
    $iconos = [
        'Operativo' => 'fa-check-circle',
        'En Recepción' => 'fa-clock',
        'En Reparación' => 'fa-tools',
        'Reparado' => 'fa-check-double',
        'Baja Técnica' => 'fa-skull-crossbones',
        'Desincorporado' => 'fa-ban'
    ];
    return $iconos[$estado] ?? 'fa-question-circle';
}

function getIconoEquipo($marca, $tipo_equipo, $foto_url = '') {
    if (!empty($foto_url)) {
        return '<img src="' . e($foto_url) . '" alt="Foto del equipo" style="width: 40px; height: 40px; object-fit: contain;">';
    }
    $marca = strtolower($marca);
    $tipo = strtolower($tipo_equipo);
    $iconos = [
        'dell' => '<i class="fab fa-dell fa-2x" style="color:#007db8;"></i>',
        'hp' => '<i class="fab fa-hp fa-2x" style="color:#0096d6;"></i>',
        'lenovo' => '<i class="fas fa-laptop fa-2x" style="color:#e2231a;"></i>',
        'apple' => '<i class="fab fa-apple fa-2x" style="color:#000;"></i>',
        'asus' => '<i class="fas fa-laptop fa-2x" style="color:#2a6e97;"></i>',
        'acer' => '<i class="fas fa-laptop fa-2x" style="color:#83b81a;"></i>',
        'samsung' => '<i class="fab fa-samsung fa-2x" style="color:#1428a0;"></i>',
        'microsoft' => '<i class="fab fa-microsoft fa-2x" style="color:#f65314;"></i>',
    ];
    foreach ($iconos as $key => $icono) {
        if (strpos($marca, $key) !== false) return $icono;
    }
    $tipos = [
        'laptop' => '<i class="fas fa-laptop fa-2x" style="color:var(--accent-cyan);"></i>',
        'desktop' => '<i class="fas fa-desktop fa-2x" style="color:var(--accent-cyan);"></i>',
        'monitor' => '<i class="fas fa-tv fa-2x" style="color:var(--accent-cyan);"></i>',
        'impresora' => '<i class="fas fa-print fa-2x" style="color:var(--accent-cyan);"></i>',
        'servidor' => '<i class="fas fa-server fa-2x" style="color:var(--accent-cyan);"></i>',
        'switch' => '<i class="fas fa-network-wired fa-2x" style="color:var(--accent-cyan);"></i>',
        'router' => '<i class="fas fa-wifi fa-2x" style="color:var(--accent-cyan);"></i>',
    ];
    foreach ($tipos as $key => $icono) {
        if (strpos($tipo, $key) !== false) return $icono;
    }
    return '<i class="fas fa-microchip fa-2x" style="color:var(--accent-cyan);"></i>';
}

// Validación CSRF global: se ejecuta en cada petición que carga este archivo.
exigirCsrfEnPost();