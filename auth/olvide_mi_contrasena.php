<?php
require_once __DIR__ . '/../core/conexion.php';

// ============================================================
// VERIFICAR PHPMailer
// ============================================================
$phpmailer_base = __DIR__ . '/../core/PHPMailer/src/';
$phpmailer_ok = file_exists($phpmailer_base . 'PHPMailer.php');
if ($phpmailer_ok) {
    require_once $phpmailer_base . 'Exception.php';
    require_once $phpmailer_base . 'PHPMailer.php';
    require_once $phpmailer_base . 'SMTP.php';
}

function enviarCorreoRecuperacion($conexion, $destinatario, $nombre, $codigo) {
    global $phpmailer_ok;
    if (!$phpmailer_ok) return false;
    
    $correo_envio = trim(obtenerConfiguracion($conexion, 'correo_envio', ''));
    $pass_correo = obtenerConfiguracion($conexion, 'pass_correo', '');
    $nombre_sistema = obtenerConfiguracion($conexion, 'nombre_sistema', 'SIGA-UDO');
    
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $correo_envio;
        $mail->Password   = $pass_correo;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($correo_envio, $nombre_sistema);
        $mail->addAddress($destinatario);
        $mail->isHTML(true);
        $mail->Subject = '🔐 Código de recuperación - ' . $nombre_sistema;
        $mail->Body = "<html><body><h2>Recuperación de contraseña</h2>
                       <p>Hola <strong>$nombre</strong>,</p>
                       <p>Tu código de verificación es: <strong style='font-size:24px;'>$codigo</strong></p>
                       <p>Este código es válido por <strong>15 minutos</strong>.</p></body></html>";
        $mail->AltBody = "Tu código de recuperación es: $codigo. Válido por 15 minutos.";
        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log("Error PHPMailer: " . $mail->ErrorInfo);
        return false;
    }
}

// ============================================================
// PROCESAR PETICIONES AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $accion = $_POST['accion'] ?? '';

    // 1. Enviar código por correo
    if ($accion === 'enviar_codigo') {
        $email = trim($_POST['email'] ?? '');
        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Debe ingresar un correo electrónico.']);
            exit;
        }
        $stmt = $conexion->prepare("SELECT id_usuario, nombre FROM usuarios WHERE email = ? AND estado = 'activo'");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$usuario) {
            echo json_encode(['success' => false, 'message' => 'No existe un usuario activo con ese correo.']);
            exit;
        }
        $codigo = sprintf("%06d", mt_rand(1, 999999));
        $expiracion = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $conexion->prepare("DELETE FROM password_resets WHERE email = ? AND usado = 0")->execute([$email]);
        $stmt = $conexion->prepare("INSERT INTO password_resets (email, token, expiracion) VALUES (?, ?, ?)");
        $stmt->execute([$email, $codigo, $expiracion]);
        if (enviarCorreoRecuperacion($conexion, $email, $usuario['nombre'], $codigo)) {
            $_SESSION['reset_email'] = $email;
            $_SESSION['codigo_expiracion'] = strtotime($expiracion);
            echo json_encode(['success' => true, 'message' => 'Código enviado a tu correo.', 'expiracion' => $_SESSION['codigo_expiracion']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error al enviar el código. Intente de nuevo más tarde.']);
        }
        exit;
    }

    // 2. Verificar código
    if ($accion === 'verificar_codigo') {
        $email = $_SESSION['reset_email'] ?? '';
        $codigo = trim($_POST['codigo'] ?? '');
        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Sesión expirada. Reinicie el proceso.']);
            exit;
        }
        $stmt = $conexion->prepare("SELECT * FROM password_resets WHERE email = ? AND token = ? AND expiracion > NOW() AND usado = 0");
        $stmt->execute([$email, $codigo]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($reset) {
            $_SESSION['reset_verified'] = $email;
            echo json_encode(['success' => true, 'message' => 'Código correcto. Ahora puede cambiar su contraseña.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Código inválido o expirado. Solicite uno nuevo.']);
        }
        exit;
    }

    // 3. Reenviar código
    if ($accion === 'reenviar_codigo') {
        $email = $_SESSION['reset_email'] ?? '';
        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'No hay correo en sesión. Ingrese nuevamente.']);
            exit;
        }
        $stmt = $conexion->prepare("SELECT nombre FROM usuarios WHERE email = ? AND estado = 'activo'");
        $stmt->execute([$email]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$usuario) {
            echo json_encode(['success' => false, 'message' => 'Usuario no encontrado.']);
            exit;
        }
        $codigo = sprintf("%06d", mt_rand(1, 999999));
        $expiracion = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $conexion->prepare("DELETE FROM password_resets WHERE email = ? AND usado = 0")->execute([$email]);
        $stmt = $conexion->prepare("INSERT INTO password_resets (email, token, expiracion) VALUES (?, ?, ?)");
        $stmt->execute([$email, $codigo, $expiracion]);
        if (enviarCorreoRecuperacion($conexion, $email, $usuario['nombre'], $codigo)) {
            $_SESSION['codigo_expiracion'] = strtotime($expiracion);
            echo json_encode(['success' => true, 'message' => 'Nuevo código enviado.', 'expiracion' => $_SESSION['codigo_expiracion']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error al reenviar el código.']);
        }
        exit;
    }

    // 4. Obtener pregunta de seguridad (solo una)
    if ($accion === 'obtener_pregunta') {
        $email = trim($_POST['email'] ?? '');
        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Debe ingresar un correo.']);
            exit;
        }
        $stmt = $conexion->prepare("SELECT pregunta1 FROM usuarios WHERE email = ? AND estado = 'activo'");
        $stmt->execute([$email]);
        $pregunta = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pregunta) {
            echo json_encode(['success' => false, 'message' => 'No existe un usuario con ese correo.']);
            exit;
        }
        if (empty($pregunta['pregunta1'])) {
            echo json_encode(['success' => false, 'message' => 'Este usuario no tiene pregunta de seguridad configurada. Use la opción de envío por correo.']);
            exit;
        }
        $_SESSION['reset_pregunta_email'] = $email;
        echo json_encode(['success' => true, 'pregunta' => $pregunta['pregunta1']]);
        exit;
    }

    // 5. Verificar respuesta de seguridad
    if ($accion === 'verificar_respuesta') {
        $email = $_SESSION['reset_pregunta_email'] ?? '';
        $respuesta = trim($_POST['respuesta'] ?? '');
        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Sesión expirada. Reinicie el proceso.']);
            exit;
        }
        $stmt = $conexion->prepare("SELECT respuesta1_hash FROM usuarios WHERE email = ? AND estado = 'activo'");
        $stmt->execute([$email]);
        $hash = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$hash) {
            echo json_encode(['success' => false, 'message' => 'Usuario no encontrado.']);
            exit;
        }
        if (password_verify($respuesta, $hash['respuesta1_hash'])) {
            $_SESSION['reset_verified'] = $email;
            echo json_encode(['success' => true, 'message' => 'Respuesta correcta. Ahora puede cambiar su contraseña.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Respuesta incorrecta.']);
        }
        exit;
    }

    // 6. Restablecer contraseña
    if ($accion === 'resetear_password') {
        $email = $_SESSION['reset_verified'] ?? $_SESSION['reset_email'] ?? '';
        $nueva = $_POST['nueva_clave'] ?? '';
        $confirmar = $_POST['confirmar_clave'] ?? '';
        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'No autorizado. Inicie el proceso nuevamente.']);
            exit;
        }
        if (strlen($nueva) < 6) {
            echo json_encode(['success' => false, 'message' => 'La contraseña debe tener al menos 6 caracteres.']);
            exit;
        }
        if ($nueva !== $confirmar) {
            echo json_encode(['success' => false, 'message' => 'Las contraseñas no coinciden.']);
            exit;
        }
        $hash = password_hash($nueva, PASSWORD_DEFAULT);
        $stmt = $conexion->prepare("UPDATE usuarios SET clave = ? WHERE email = ? AND estado = 'activo'");
        $stmt->execute([$hash, $email]);
        if ($stmt->rowCount() > 0) {
            unset($_SESSION['reset_email'], $_SESSION['reset_pregunta_email'], $_SESSION['reset_verified'], $_SESSION['codigo_expiracion']);
            echo json_encode(['success' => true, 'message' => 'Contraseña actualizada. Redirigiendo al login...']);
        } else {
            echo json_encode(['success' => false, 'message' => 'No se pudo actualizar la contraseña.']);
        }
        exit;
    }
}

// ============================================================
// INTERFAZ HTML
// ============================================================
$nombre_sistema = obtenerConfiguracion($conexion, 'nombre_sistema', 'SIGA-UDO');
$logo_url = obtenerConfiguracion($conexion, 'logo_url', '');
$institucion = obtenerConfiguracion($conexion, 'nombre_institucion', 'Universidad de Oriente - Núcleo Monagas');
$slogan = obtenerConfiguracion($conexion, 'slogan', 'Del pueblo venimos, hacia el pueblo vamos');
$logo_versionado = '';
if (!empty($logo_url) && file_exists(__DIR__ . '/../' . $logo_url)) {
    $logo_versionado = '../' . $logo_url . '?v=' . filemtime(__DIR__ . '/../' . $logo_url);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($nombre_sistema) ?> | Recuperar contraseña</title>
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

<main class="auth-shell single">
  <section class="auth-card-wrap">
    <div class="auth-card">
        <div class="text-center mb-3">
            <div class="auth-logo-ring" style="margin-inline:auto;width:84px;height:84px;margin-bottom:1rem">
                <?php if ($logo_versionado): ?>
                    <img src="<?= e($logo_versionado) ?>" alt="Logo" style="max-width:44px;max-height:44px">
                <?php else: ?>
                    <i class="fas fa-key" style="font-size:1.7rem"></i>
                <?php endif; ?>
            </div>
            <h2>Recuperar contraseña</h2>
            <p class="lead-sm mb-0"><?= e($nombre_sistema) ?></p>
        </div>

        <!-- Paso 1: correo y método -->
        <div id="panelEmail" class="auth-step">
            <div id="alertEmail"></div>
            <div class="seg">
                <label><input type="radio" name="metodo" value="correo" checked><span><i class="fas fa-envelope"></i>Código por correo</span></label>
                <label><input type="radio" name="metodo" value="pregunta"><span><i class="fas fa-shield-halved"></i>Pregunta de seguridad</span></label>
            </div>
            <form id="formEmail" novalidate>
                <div class="fl">
                    <input type="email" name="email" id="rec_email" class="form-control" placeholder=" " required autofocus>
                    <label for="rec_email">Correo electrónico</label><i class="fas fa-envelope ico"></i>
                </div>
                <button type="submit" id="btnContinuar" class="btn-auth">Continuar<i class="fas fa-arrow-right ms-2"></i></button>
                <div class="auth-back"><a href="login.php">&larr; Volver al inicio de sesión</a></div>
            </form>
        </div>

        <!-- Paso 2a: código por correo -->
        <div id="panelCodigo" class="auth-step" style="display:none;">
            <div id="alertCodigo"></div>
            <div class="fl auth-code">
                <input type="text" id="codigoInput" class="form-control" placeholder=" " maxlength="6" inputmode="numeric" autocomplete="off">
                <label for="codigoInput" style="left:50%;transform:translateX(-50%) translateY(-50%)">Código de 6 dígitos</label>
            </div>
            <div class="auth-timer" id="timerDisplay"></div>
            <button id="btnVerificarCodigo" class="btn-auth">Verificar código</button>
            <div class="auth-back"><a href="#" id="reenviarCodigo">Reenviar código</a> &nbsp;·&nbsp; <a href="#" id="volverEmailDesdeCodigo">&larr; Volver</a></div>
        </div>

        <!-- Paso 2b: pregunta de seguridad -->
        <div id="panelPregunta" class="auth-step" style="display:none;">
            <div id="alertPregunta"></div>
            <div id="preguntaContainer" class="mb-3"></div>
            <div class="fl">
                <input type="text" id="respuestaPregunta" class="form-control" placeholder=" " autocomplete="off">
                <label for="respuestaPregunta">Tu respuesta</label><i class="fas fa-key ico"></i>
            </div>
            <button id="btnVerificarPregunta" class="btn-auth">Verificar respuesta</button>
            <div class="auth-back"><a href="#" id="volverEmailDesdePregunta">&larr; Volver</a></div>
        </div>

        <!-- Paso 3: nueva contraseña -->
        <div id="panelNueva" class="auth-step" style="display:none;">
            <div id="alertNueva"></div>
            <form id="formNueva" novalidate>
                <div class="fl">
                    <input type="password" name="nueva_clave" id="rec_nueva" class="form-control" placeholder=" " required minlength="6" autocomplete="new-password">
                    <label for="rec_nueva">Nueva contraseña</label><i class="fas fa-lock ico"></i>
                    <button type="button" class="pw-eye" data-pw="rec_nueva" aria-label="Mostrar u ocultar contraseña"><i class="fas fa-eye"></i></button>
                </div>
                <div class="fl">
                    <input type="password" name="confirmar_clave" id="rec_confirmar" class="form-control" placeholder=" " required minlength="6" autocomplete="new-password">
                    <label for="rec_confirmar">Confirmar contraseña</label><i class="fas fa-circle-check ico"></i>
                    <button type="button" class="pw-eye" data-pw="rec_confirmar" aria-label="Mostrar u ocultar contraseña"><i class="fas fa-eye"></i></button>
                </div>
                <button type="submit" id="btnReset" class="btn-auth">Restablecer contraseña</button>
                <div class="auth-back"><a href="login.php">Cancelar</a></div>
            </form>
        </div>

        <div class="auth-foot"><?= e($nombre_sistema) ?> &mdash; &ldquo;<?= e($slogan) ?>&rdquo;</div>
    </div>
  </section>
</main>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="/Asset-UDO/assets/js/csrf.js"></script>
<script src="/Asset-UDO/assets/js/theme-toggle.js"></script>
<script src="/Asset-UDO/assets/js/auth.js?v=<?= @filemtime(__DIR__ . '/../assets/js/auth.js') ?: 1 ?>"></script>
<script>
$(document).ready(function() {
    let timerInterval = null;
    let expiracionTimestamp = null;

    function startTimer(expTimestamp) {
        if (timerInterval) clearInterval(timerInterval);
        expiracionTimestamp = expTimestamp;
        function updateTimer() {
            let now = Math.floor(Date.now() / 1000);
            let remaining = expiracionTimestamp - now;
            if (remaining <= 0) {
                $("#timerDisplay").html('<span class="text-danger">Código expirado. Solicite uno nuevo.</span>');
                if (timerInterval) clearInterval(timerInterval);
                $("#btnVerificarCodigo").prop('disabled', true);
                return;
            }
            let minutes = Math.floor(remaining / 60);
            let seconds = remaining % 60;
            $("#timerDisplay").html(`Código válido por: <strong>${minutes}:${seconds < 10 ? '0'+seconds : seconds}</strong>`);
            $("#btnVerificarCodigo").prop('disabled', false);
        }
        updateTimer();
        timerInterval = setInterval(updateTimer, 1000);
    }

    function showPanel(panelName) {
        $("#panelEmail, #panelCodigo, #panelPregunta, #panelNueva").hide();
        $("#" + panelName).show();
    }

    // Envío del formulario de email
    $("#formEmail").on("submit", function(e) {
        e.preventDefault();
        var metodo = $("input[name='metodo']:checked").val();
        var email = $("input[name='email']").val();
        var btn = $("#btnContinuar");
        var originalText = btn.html();
        btn.html('<i class="fas fa-spinner fa-spin me-2"></i>Procesando...').prop("disabled", true);
        
        if (metodo === 'correo') {
            $.ajax({
                url: "olvide_mi_contrasena.php",
                type: "POST",
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                data: { accion: "enviar_codigo", email: email },
                dataType: "json",
                success: function(res) {
                    if (res.success) {
                        $("#alertEmail").html('<div class="alert alert-success">' + res.message + '</div>');
                        if (res.expiracion) startTimer(res.expiracion);
                        showPanel('panelCodigo');
                        $("#alertCodigo").empty();
                        $("#codigoInput").val('');
                    } else {
                        $("#alertEmail").html('<div class="alert">' + res.message + '</div>');
                    }
                    btn.html(originalText).prop("disabled", false);
                },
                error: function() { $("#alertEmail").html('<div class="alert">Error de conexión.</div>'); btn.html(originalText).prop("disabled", false); }
            });
        } else {
            // Método pregunta de seguridad
            $.ajax({
                url: "olvide_mi_contrasena.php",
                type: "POST",
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                data: { accion: "obtener_pregunta", email: email },
                dataType: "json",
                success: function(res) {
                    if (res.success) {
                        $("#preguntaContainer").html('<div class="alert alert-info">' + res.pregunta + '</div>');
                        $("#alertEmail").empty();
                        showPanel('panelPregunta');
                        $("#alertPregunta").empty();
                        $("#respuestaPregunta").val('');
                    } else {
                        $("#alertEmail").html('<div class="alert">' + res.message + '</div>');
                    }
                    btn.html(originalText).prop("disabled", false);
                },
                error: function() { $("#alertEmail").html('<div class="alert">Error de conexión.</div>'); btn.html(originalText).prop("disabled", false); }
            });
        }
    });

    // Verificar código
    $("#btnVerificarCodigo").click(function() {
        var codigo = $("#codigoInput").val().trim();
        if (codigo.length !== 6) {
            $("#alertCodigo").html('<div class="alert">Ingrese el código de 6 dígitos.</div>');
            return;
        }
        var btn = $(this);
        var originalText = btn.html();
        btn.html('<i class="fas fa-spinner fa-spin"></i> Verificando...').prop("disabled", true);
        $.ajax({
            url: "olvide_mi_contrasena.php",
            type: "POST",
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            data: { accion: "verificar_codigo", codigo: codigo },
            dataType: "json",
            success: function(res) {
                if (res.success) {
                    $("#alertCodigo").html('<div class="alert alert-success">' + res.message + '</div>');
                    setTimeout(function() {
                        showPanel('panelNueva');
                        $("#alertNueva").empty();
                    }, 1500);
                } else {
                    $("#alertCodigo").html('<div class="alert">' + res.message + '</div>');
                }
                btn.html(originalText).prop("disabled", false);
            },
            error: function() { $("#alertCodigo").html('<div class="alert">Error de conexión.</div>'); btn.html(originalText).prop("disabled", false); }
        });
    });

    // Reenviar código
    $("#reenviarCodigo").click(function(e) {
        e.preventDefault();
        var btn = $(this);
        btn.html('<i class="fas fa-spinner fa-spin"></i> Reenviando...');
        $.ajax({
            url: "olvide_mi_contrasena.php",
            type: "POST",
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            data: { accion: "reenviar_codigo" },
            dataType: "json",
            success: function(res) {
                if (res.success) {
                    $("#alertCodigo").html('<div class="alert alert-success">' + res.message + '</div>');
                    if (res.expiracion) startTimer(res.expiracion);
                    $("#codigoInput").val('');
                } else {
                    $("#alertCodigo").html('<div class="alert">' + res.message + '</div>');
                }
                btn.html('Reenviar código');
            },
            error: function() { $("#alertCodigo").html('<div class="alert">Error al reenviar.</div>'); btn.html('Reenviar código'); }
        });
    });

    // Verificar respuesta de pregunta de seguridad
    $("#btnVerificarPregunta").click(function() {
        var respuesta = $("#respuestaPregunta").val().trim();
        if (respuesta === "") {
            $("#alertPregunta").html('<div class="alert">Debe ingresar su respuesta.</div>');
            return;
        }
        var btn = $(this);
        var originalText = btn.html();
        btn.html('<i class="fas fa-spinner fa-spin"></i> Verificando...').prop("disabled", true);
        $.ajax({
            url: "olvide_mi_contrasena.php",
            type: "POST",
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            data: { accion: "verificar_respuesta", respuesta: respuesta },
            dataType: "json",
            success: function(res) {
                if (res.success) {
                    $("#alertPregunta").html('<div class="alert alert-success">' + res.message + '</div>');
                    setTimeout(function() {
                        showPanel('panelNueva');
                        $("#alertNueva").empty();
                    }, 1500);
                } else {
                    $("#alertPregunta").html('<div class="alert">' + res.message + '</div>');
                }
                btn.html(originalText).prop("disabled", false);
            },
            error: function() { $("#alertPregunta").html('<div class="alert">Error de conexión.</div>'); btn.html(originalText).prop("disabled", false); }
        });
    });

    // Volver desde los paneles
    $("#volverEmailDesdeCodigo, #volverEmailDesdePregunta").click(function(e) {
        e.preventDefault();
        if (timerInterval) clearInterval(timerInterval);
        showPanel('panelEmail');
        $("#alertEmail").empty();
        $("input[name='email']").val('');
    });

    // Restablecer contraseña
    $("#formNueva").on("submit", function(e) {
        e.preventDefault();
        var btn = $("#btnReset");
        var originalText = btn.html();
        btn.html('<i class="fas fa-spinner fa-spin me-2"></i>Procesando...').prop("disabled", true);
        $.ajax({
            url: "olvide_mi_contrasena.php",
            type: "POST",
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            data: $(this).serialize() + "&accion=resetear_password",
            dataType: "json",
            success: function(res) {
                if (res.success) {
                    $("#alertNueva").html('<div class="alert alert-success">' + res.message + '</div>');
                    setTimeout(function() {
                        window.location.href = "login.php";
                    }, 1500);
                } else {
                    $("#alertNueva").html('<div class="alert">' + res.message + '</div>');
                    btn.html(originalText).prop("disabled", false);
                }
            },
            error: function() { $("#alertNueva").html('<div class="alert">Error de conexión.</div>'); btn.html(originalText).prop("disabled", false); }
        });
    });
});
</script>
</body>
</html>