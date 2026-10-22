<?php
require_once __DIR__ . '/../core/conexion.php';

// ================================================================
// CLAVE DE EMERGENCIA (para desactivar mantenimiento desde URL)
// ================================================================
procesarDesbloqueoMantenimiento($conexion, 'login.php');

// ================================================================
// CONTROL DE INTENTOS DE LOGIN
// ================================================================
$intentos_max    = (int)obtenerConfiguracion($conexion, 'intentos_login', 3);
$tiempo_bloqueo  = (int)obtenerConfiguracion($conexion, 'tiempo_bloqueo', 15);
$ahora = time();
if (!isset($_SESSION['login_intentos'])) {
    $_SESSION['login_intentos'] = 0;
    $_SESSION['login_bloqueado_hasta'] = 0;
}
if ($_SESSION['login_bloqueado_hasta'] > 0 && $ahora > $_SESSION['login_bloqueado_hasta']) {
    $_SESSION['login_intentos'] = 0;
    $_SESSION['login_bloqueado_hasta'] = 0;
}
$bloqueado = ($_SESSION['login_bloqueado_hasta'] > 0 && $ahora <= $_SESSION['login_bloqueado_hasta']);

// ================================================================
// MODO MANTENIMIENTO
// ================================================================
$mensaje_mant = obtenerConfiguracion($conexion, 'mensaje_mantenimiento', 'El sistema se encuentra en mantenimiento. Por favor, intente más tarde.');
$nombre_sistema = obtenerConfiguracion($conexion, 'nombre_sistema', 'SIGA-UDO');
$logo_url = obtenerConfiguracion($conexion, 'logo_url', '');

if (sistemaEnMantenimiento($conexion) && !isset($_SESSION['usuario_id'])) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title><?= e($nombre_sistema) ?> – Mantenimiento</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <?php require_once __DIR__ . '/../includes/theme_head.php'; ?>
    </head>
    <body class="d-flex align-items-center justify-content-center vh-100">
        <div class="card border-0 shadow-lg p-5 text-center" style="max-width: 480px;">
            <i class="fas fa-tools fa-3x mb-3" style="color: var(--accent-cyan);"></i>
            <h3><?= e($nombre_sistema) ?></h3>
            <p class="text-muted"><?= nl2br(e($mensaje_mant)) ?></p>
            <a href="login.php" class="btn btn-primary rounded-pill">Reintentar</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ================================================================
// PROCESAMIENTO AJAX DEL LOGIN
// ================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    if ($bloqueado) {
        $min = ceil(($_SESSION['login_bloqueado_hasta'] - time()) / 60);
        echo json_encode(['success' => false, 'message' => "Demasiados intentos. Espere $min minuto(s)."]);
        exit;
    }
    $identificador = trim($_POST['email'] ?? '');
    $clave = $_POST['clave'] ?? '';
    $stmt = $conexion->prepare("SELECT * FROM usuarios WHERE (email = ? OR nombre = ?) AND estado = 'activo' LIMIT 10");
    $stmt->execute([$identificador, $identificador]);
    $user = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidato) {
        if (password_verify($clave, $candidato['clave'])) { $user = $candidato; break; }
    }
    if ($user) {
        $_SESSION['login_intentos'] = 0;
        $_SESSION['login_bloqueado_hasta'] = 0;
        if (sistemaEnMantenimiento($conexion) && $user['rol'] !== ROL_ADMIN) {
            echo json_encode(['success' => false, 'message' => 'El sistema está en mantenimiento. Solo administradores pueden acceder.']);
            exit;
        }
        session_regenerate_id(true);
        $_SESSION['usuario_id'] = $user['id_usuario'];
        $_SESSION['nombre'] = $user['nombre'];
        $_SESSION['rol'] = $user['rol'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['foto'] = $user['foto'] ?? '';
        $conexion->prepare("INSERT INTO sesiones_activas (usuario_id) VALUES (?)")->execute([$user['id_usuario']]);
        $ip = $_SERVER['REMOTE_ADDR'];
        $conexion->prepare("UPDATE usuarios SET ultimo_acceso = NOW(), ultima_ip = ? WHERE id_usuario = ?")->execute([$ip, $user['id_usuario']]);
        registrarAuditoria($conexion, "Inicio de sesión", "usuarios");
        echo json_encode(['success' => true, 'redirect' => '../index.php']);
    } else {
        $_SESSION['login_intentos']++;
        if ($_SESSION['login_intentos'] >= $intentos_max) {
            $_SESSION['login_bloqueado_hasta'] = time() + ($tiempo_bloqueo * 60);
            $_SESSION['login_intentos'] = 0;
            echo json_encode(['success' => false, 'message' => "Demasiados intentos. Cuenta bloqueada por $tiempo_bloqueo minuto(s)."]);
        } else {
            $rest = $intentos_max - $_SESSION['login_intentos'];
            echo json_encode(['success' => false, 'message' => "Credenciales incorrectas. Le quedan $rest intento(s)."]);
        }
    }
    exit;
}

// ================================================================
// AJAX: VERIFICAR CÉDULA EN TIEMPO REAL
// ================================================================
if (isset($_GET['check_cedula']) && isset($_GET['cedula'])) {
    header('Content-Type: text/plain');
    $cedula = trim($_GET['cedula']);
    if (!empty($cedula)) {
        $stmt = $conexion->prepare("SELECT id_usuario FROM usuarios WHERE cedula = ?");
        $stmt->execute([$cedula]);
        echo $stmt->fetch() ? 'existe' : 'no_existe';
    } else {
        echo 'no_existe';
    }
    exit;
}

// ================================================================
// AJAX: VERIFICAR EMAIL EN TIEMPO REAL
// ================================================================
if (isset($_GET['check_email']) && isset($_GET['email'])) {
    header('Content-Type: text/plain');
    $email = trim($_GET['email']);
    if (!empty($email)) {
        $stmt = $conexion->prepare("SELECT id_usuario FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        echo $stmt->fetch() ? 'existe' : 'no_existe';
    } else {
        echo 'no_existe';
    }
    exit;
}

// ================================================================
// PROCESAMIENTO DEL FORMULARIO DE REGISTRO (POST normal)
// ================================================================
$registro_msg = '';
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['registrar'])) {
    $nombre      = limpiar($_POST['nombre']);
    $email       = limpiar($_POST['email']);
    $cedula      = limpiar($_POST['cedula'] ?? '');
    $telefono    = limpiar($_POST['telefono'] ?? '');
    $cargo       = limpiar($_POST['cargo'] ?? '');
    $direccion   = limpiar($_POST['direccion'] ?? '');
    $fecha_nac_raw = trim($_POST['fecha_nacimiento'] ?? '');
    $rol         = $_POST['rol'];
    $clave       = $_POST['clave'] ?? '';
    $confirmar   = $_POST['confirmar_clave'] ?? '';
    
    // 3 preguntas de seguridad
    $pregunta1   = limpiar($_POST['pregunta1'] ?? '');
    $respuesta1  = limpiar($_POST['respuesta1'] ?? '');
    $pregunta2   = limpiar($_POST['pregunta2'] ?? '');
    $respuesta2  = limpiar($_POST['respuesta2'] ?? '');
    $pregunta3   = limpiar($_POST['pregunta3'] ?? '');
    $respuesta3  = limpiar($_POST['respuesta3'] ?? '');

    $errores = [];

    // Nombre
    if (empty($nombre)) $errores[] = "El nombre es obligatorio.";
    elseif (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/u", $nombre)) $errores[] = "El nombre solo puede contener letras y espacios.";

    // Email
    if (empty($email)) $errores[] = "El correo es obligatorio.";
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = "El email no es válido.";
    else {
        $checkEmail = $conexion->prepare("SELECT id_usuario FROM usuarios WHERE email = ?");
        $checkEmail->execute([$email]);
        if ($checkEmail->fetch()) $errores[] = "El email ya está registrado.";
    }

    // Cédula
    if (empty($cedula)) $errores[] = "La cédula es obligatoria.";
    else {
        if (!ctype_digit($cedula)) $errores[] = "La cédula debe contener solo números.";
        elseif (strlen($cedula) > 8) $errores[] = "La cédula no puede tener más de 8 dígitos.";
        else {
            $checkCed = $conexion->prepare("SELECT id_usuario FROM usuarios WHERE cedula = ?");
            $checkCed->execute([$cedula]);
            if ($checkCed->fetch()) $errores[] = "La cédula ya está registrada.";
        }
    }

    // Teléfono
    if (empty($telefono)) $errores[] = "El teléfono es obligatorio.";
    else {
        if (!ctype_digit($telefono)) $errores[] = "El teléfono debe contener solo números.";
        elseif (strlen($telefono) != 11) $errores[] = "El teléfono debe tener exactamente 11 dígitos.";
    }

    // Cargo
    if (empty($cargo)) $errores[] = "El cargo es obligatorio.";
    elseif (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s\-]+$/u", $cargo)) $errores[] = "El cargo solo puede contener letras, espacios y guiones.";

    // Contraseña
    if (empty($clave)) $errores[] = "La contraseña es obligatoria.";
    elseif (strlen($clave) < 6) $errores[] = "La contraseña debe tener al menos 6 caracteres.";
    if ($clave !== $confirmar) $errores[] = "Las contraseñas no coinciden.";

    // Rol
    if (empty($rol)) $errores[] = "Debe seleccionar un rol.";
    elseif (!in_array($rol, ROLES_AUTOREGISTRO, true)) $errores[] = "El rol solicitado no es válido.";

    // Preguntas seguridad (3)
    if (empty($pregunta1) || empty($respuesta1)) $errores[] = "Debe completar la pregunta 1.";
    if (empty($pregunta2) || empty($respuesta2)) $errores[] = "Debe completar la pregunta 2.";
    if (empty($pregunta3) || empty($respuesta3)) $errores[] = "Debe completar la pregunta 3.";
    if (strlen($respuesta1) < 2) $errores[] = "Respuesta 1 muy corta.";
    if (strlen($respuesta2) < 2) $errores[] = "Respuesta 2 muy corta.";
    if (strlen($respuesta3) < 2) $errores[] = "Respuesta 3 muy corta.";

    // Fecha nacimiento (opcional)
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
                $errores[] = "Fecha no válida (día, mes, año 1900-".date('Y').")";
            }
        } else {
            $errores[] = "Formato fecha incorrecto. Use DD-MM-AAAA";
        }
    }

    if (!empty($errores)) {
        $_SESSION['registro_error'] = implode('|', $errores);
        header("Location: login.php?registro=error");
        exit;
    }

    // Foto (opcional)
    $foto_path = '';
    if (!empty($_FILES['foto']['name']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $errFoto = '';
        $foto_path = procesarImagenSubida($_FILES['foto'], 'perfiles', 'perfil', $errFoto, 800, 10) ?? '';
    }

    try {
        $clave_hash = password_hash($clave, PASSWORD_DEFAULT);
        $resp1_hash = password_hash($respuesta1, PASSWORD_DEFAULT);
        $resp2_hash = password_hash($respuesta2, PASSWORD_DEFAULT);
        $resp3_hash = password_hash($respuesta3, PASSWORD_DEFAULT);

        $conexion->prepare("INSERT INTO usuarios (nombre, email, cedula, clave, rol, estado, telefono, direccion, cargo, foto, fecha_nacimiento, primer_acceso, creado_por, pregunta1, respuesta1_hash, pregunta2, respuesta2_hash, pregunta3, respuesta3_hash)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
            $nombre, $email, $cedula, $clave_hash, $rol, 'pendiente',
            $telefono, $direccion, $cargo, $foto_path, $fecha_nac, 0, 0,
            $pregunta1, $resp1_hash,
            $pregunta2, $resp2_hash,
            $pregunta3, $resp3_hash
        ]);
        $_SESSION['registro_exito'] = "✅ Solicitud enviada. Un administrador revisará su registro.";
    } catch (Exception $e) {
        $_SESSION['registro_error'] = errorInterno($e);
    }
    header("Location: login.php?registro=ok");
    exit;
}

// Recuperar mensajes de registro
if (isset($_GET['registro'])) {
    if ($_GET['registro'] == 'ok' && isset($_SESSION['registro_exito'])) {
        $registro_msg = $_SESSION['registro_exito'];
        unset($_SESSION['registro_exito']);
    } elseif ($_GET['registro'] == 'error' && isset($_SESSION['registro_error'])) {
        $errores = explode('|', $_SESSION['registro_error']);
        $registro_msg = implode('<br>', $errores);
        unset($_SESSION['registro_error']);
    }
}

if (isset($_SESSION['usuario_id'])) {
    header("Location: ../index.php");
    exit();
}

$institucion = obtenerConfiguracion($conexion, 'nombre_institucion', 'Universidad de Oriente - Núcleo Monagas');
$slogan = obtenerConfiguracion($conexion, 'slogan', 'Del pueblo venimos, hacia el pueblo vamos');
$logo_versionado = '';
if (!empty($logo_url) && file_exists(__DIR__ . '/../' . $logo_url)) {
    $logo_versionado = '../' . $logo_url . '?v=' . filemtime(__DIR__ . '/../' . $logo_url);
}

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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($nombre_sistema) ?> | Iniciar Sesión</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php require_once __DIR__ . '/../includes/theme_head.php'; ?>
    <link rel="stylesheet" href="/Asset-UDO/assets/css/auth.css?v=<?= @filemtime(__DIR__ . '/../assets/css/auth.css') ?: 1 ?>">
</head>
<body class="auth-body">

<div class="auth-bg" aria-hidden="true">
    <div class="auth-grid"></div>
    <div class="auth-orb o1"></div><div class="auth-orb o2"></div><div class="auth-orb o3"></div>
    <div class="auth-particles"></div>
</div>

<div class="auth-theme">
    <button type="button" class="btn-theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Cambiar tema" title="Cambiar tema">
        <i class="fas fa-sun"></i>
    </button>
</div>

<main class="auth-shell">

    <!-- Marca -->
    <section class="auth-hero">
        <div class="auth-logo-ring">
            <?php if ($logo_versionado): ?>
                <img src="<?= e($logo_versionado) ?>" alt="Logo">
            <?php else: ?>
                <i class="fas fa-microchip"></i>
            <?php endif; ?>
        </div>
        <h1 class="auth-title"><?= e($nombre_sistema) ?></h1>
        <div class="auth-slogan"><?= e($slogan) ?></div>
        <div class="auth-inst"><?= e($institucion) ?></div>
        <ul class="auth-features">
            <li style="--i:0"><i class="fas fa-boxes-stacked"></i><div><b>Inventario de activos</b><small>Registro, ubicación y estado en tiempo real</small></div></li>
            <li style="--i:1"><i class="fas fa-screwdriver-wrench"></i><div><b>Órdenes de reparación</b><small>Recepción, diagnóstico, taller y entrega</small></div></li>
            <li style="--i:2"><i class="fas fa-chart-line"></i><div><b>Reportes y auditoría</b><small>Trazabilidad completa de cada acción</small></div></li>
        </ul>
    </section>

    <!-- Tarjeta de acceso -->
    <section class="auth-card-wrap">
        <div class="auth-card">
            <h2>Bienvenido</h2>
            <p class="lead-sm">Ingresa con tu correo o usuario para continuar.</p>

            <?php if (!empty($registro_msg)): ?>
                <div class="auth-alert info" role="status"><?= $registro_msg ?></div>
            <?php endif; ?>
            <div id="loginAlert" class="auth-alert" role="alert"></div>

            <form id="loginForm" autocomplete="on" novalidate>
                <div class="fl">
                    <input type="text" name="email" id="login_user" class="form-control" placeholder=" " autocomplete="username" required autofocus>
                    <label for="login_user">Correo o usuario</label>
                    <i class="fas fa-user ico"></i>
                </div>
                <div class="fl">
                    <input type="password" name="clave" id="login_clave" class="form-control" placeholder=" " autocomplete="current-password" required>
                    <label for="login_clave">Contraseña</label>
                    <i class="fas fa-lock ico"></i>
                    <button type="button" class="pw-eye" data-pw="login_clave" aria-label="Mostrar u ocultar contraseña"><i class="fas fa-eye"></i></button>
                </div>
                <button type="submit" id="loginBtn" class="btn-auth"><i class="fas fa-arrow-right-to-bracket me-2"></i>Ingresar al sistema</button>
            </form>

            <div class="auth-links">
                <a href="olvide_mi_contrasena.php" class="auth-link">¿Olvidaste tu contraseña?</a>
            </div>
            <div class="auth-sep">o</div>
            <div class="auth-links">
                <button type="button" class="btn-ghost" data-bs-toggle="modal" data-bs-target="#modalRegistro"><i class="fas fa-user-plus"></i>Registrarse</button>
                <a href="../consultar_estado.php" class="btn-ghost"><i class="fas fa-clipboard-check"></i>Consultar mi orden</a>
            </div>
        </div>
    </section>
</main>

<!-- Asistente de registro -->
<div class="modal fade" id="modalRegistro" tabindex="-1" data-bs-backdrop="static" aria-labelledby="registroTitulo">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="wiz-head">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <h5 class="fw-bold mb-1" id="registroTitulo"><i class="fas fa-user-plus me-2 text-info"></i>Solicitud de registro</h5>
            <small class="text-muted">Un administrador revisará tu solicitud antes de activar la cuenta.</small>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="wiz-steps">
          <div class="wiz-dot active"><span>1</span></div><div class="wiz-line"></div>
          <div class="wiz-dot"><span>2</span></div><div class="wiz-line"></div>
          <div class="wiz-dot"><span>3</span></div>
        </div>
        <div class="wiz-labels"><span class="active">Datos personales</span><span>Cuenta y rol</span><span>Seguridad</span></div>
      </div>

      <form method="POST" enctype="multipart/form-data" id="formRegistro" novalidate>
        <?= csrfCampo() ?>
        <div class="wiz-body">

          <!-- Paso 1 -->
          <div class="wiz-pane active">
            <div class="row g-0 column-gap-3">
              <div class="col-md-6"><div class="fl">
                <input type="text" name="nombre" id="reg_nombre" class="form-control" placeholder=" " required>
                <label for="reg_nombre">Nombre completo</label><i class="fas fa-id-badge ico"></i>
              </div><div class="field-error" data-for="reg_nombre"></div></div>
              <div class="col-md-5"><div class="fl">
                <input type="text" name="cedula" id="reg_cedula" class="form-control" placeholder=" " inputmode="numeric" maxlength="8" required>
                <label for="reg_cedula">Cédula</label><i class="fas fa-address-card ico"></i>
              </div><div class="field-error" data-for="reg_cedula"></div></div>
            </div>
            <div class="row g-0 column-gap-3">
              <div class="col-md-6"><div class="fl">
                <input type="text" name="telefono" id="reg_telefono" class="form-control" placeholder=" " inputmode="numeric" maxlength="11" required>
                <label for="reg_telefono">Teléfono (11 dígitos)</label><i class="fas fa-phone ico"></i>
              </div><div class="field-error" data-for="reg_telefono"></div></div>
              <div class="col-md-5"><div class="fl">
                <input type="text" name="fecha_nacimiento" id="reg_fecha_nac" class="form-control" placeholder=" " inputmode="numeric" maxlength="10">
                <label for="reg_fecha_nac">Nacimiento (DD-MM-AAAA)</label><i class="fas fa-cake-candles ico"></i>
              </div><div class="field-error" data-for="reg_fecha_nac"></div></div>
            </div>
            <div class="fl">
              <input type="text" name="direccion" id="reg_direccion" class="form-control" placeholder=" ">
              <label for="reg_direccion">Dirección (opcional)</label><i class="fas fa-location-dot ico"></i>
            </div>
            <div class="fl">
              <input type="file" name="foto" id="reg_foto" accept="image/*" class="form-control">
              <label for="reg_foto">Foto de perfil (opcional, máx. 10 MB)</label><i class="fas fa-camera ico"></i>
            </div>
          </div>

          <!-- Paso 2 -->
          <div class="wiz-pane">
            <div class="fl">
              <input type="email" name="email" id="reg_email" class="form-control" placeholder=" " required>
              <label for="reg_email">Correo electrónico</label><i class="fas fa-envelope ico"></i>
            </div><div class="field-error" data-for="reg_email"></div>
            <div class="row g-0 column-gap-3">
              <div class="col-md-6"><div class="fl">
                <input type="text" name="cargo" id="reg_cargo" class="form-control" placeholder=" " required>
                <label for="reg_cargo">Cargo</label><i class="fas fa-briefcase ico"></i>
              </div><div class="field-error" data-for="reg_cargo"></div></div>
              <div class="col-md-5"><div class="fl">
                <select name="rol" id="reg_rol" class="form-select" required>
                  <option value="">Seleccione...</option>
                  <option value="recepcionista">Recepcionista</option>
                  <option value="tecnico">Técnico</option>
                  <option value="consultor">Consultor</option>
                </select>
                <label for="reg_rol">Rol solicitado</label><i class="fas fa-user-shield ico"></i>
              </div><div class="field-error" data-for="reg_rol"></div></div>
            </div>
            <div class="fl">
              <input type="password" name="clave" id="reg_clave" class="form-control" placeholder=" " autocomplete="new-password" minlength="6" required>
              <label for="reg_clave">Contraseña (mín. 6 caracteres)</label><i class="fas fa-lock ico"></i>
              <button type="button" class="pw-eye" data-pw="reg_clave" aria-label="Mostrar u ocultar contraseña"><i class="fas fa-eye"></i></button>
            </div><div class="field-error" data-for="reg_clave"></div>
            <div class="pw-meter"><i></i></div>
            <div class="fl">
              <input type="password" name="confirmar_clave" id="reg_confirm" class="form-control" placeholder=" " autocomplete="new-password" minlength="6" required>
              <label for="reg_confirm">Confirmar contraseña</label><i class="fas fa-lock ico"></i>
              <button type="button" class="pw-eye" data-pw="reg_confirm" aria-label="Mostrar u ocultar contraseña"><i class="fas fa-eye"></i></button>
            </div><div class="field-error" data-for="reg_confirm"></div>
          </div>

          <!-- Paso 3 -->
          <div class="wiz-pane">
            <p class="hint mb-3"><i class="fas fa-shield-halved me-1"></i>Estas preguntas te permitirán recuperar tu contraseña. Elige tres distintas.</p>
            <?php for ($n = 1; $n <= 3; $n++): ?>
            <div class="row g-0 column-gap-3">
              <div class="col-md-6"><div class="fl">
                <select name="pregunta<?= $n ?>" id="reg_pregunta<?= $n ?>" class="form-select" required>
                  <option value="">Seleccione una pregunta</option>
                  <?php foreach ($preguntas_disponibles as $p): ?>
                    <option value="<?= e($p) ?>"><?= e($p) ?></option>
                  <?php endforeach; ?>
                </select>
                <label for="reg_pregunta<?= $n ?>">Pregunta <?= $n ?></label><i class="fas fa-circle-question ico"></i>
              </div><div class="field-error" data-for="reg_pregunta<?= $n ?>"></div></div>
              <div class="col-md-5"><div class="fl">
                <input type="text" name="respuesta<?= $n ?>" id="reg_respuesta<?= $n ?>" class="form-control" placeholder=" " required>
                <label for="reg_respuesta<?= $n ?>">Respuesta <?= $n ?></label><i class="fas fa-key ico"></i>
              </div><div class="field-error" data-for="reg_respuesta<?= $n ?>"></div></div>
            </div>
            <?php endfor; ?>
          </div>
        </div>

        <div class="wiz-foot">
          <button type="button" class="btn-ghost" id="wizBack"><i class="fas fa-arrow-left"></i>Atrás</button>
          <div>
            <button type="button" class="btn-auth" id="wizNext">Siguiente<i class="fas fa-arrow-right ms-2"></i></button>
            <button type="submit" class="btn-auth d-none" id="wizSend"><i class="fas fa-paper-plane me-2"></i>Enviar solicitud</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="/Asset-UDO/assets/js/csrf.js"></script>
<script src="/Asset-UDO/assets/js/theme-toggle.js"></script>
<script src="/Asset-UDO/assets/js/auth.js?v=<?= @filemtime(__DIR__ . '/../assets/js/auth.js') ?: 1 ?>"></script>
</body>
</html>
