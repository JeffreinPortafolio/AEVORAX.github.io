<?php
require_once __DIR__ . '/../core/conexion.php';

if (isset($_SESSION['usuario_id'])) {
    // Eliminar sesión activa en base de datos
    try {
        $conexion->prepare("DELETE FROM sesiones_activas WHERE usuario_id = ?")->execute([$_SESSION['usuario_id']]);
    } catch (Exception $e) {}

    registrarAuditoria($conexion, "Cierre de sesión", "usuarios");
}

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();
header("Location: " . url('auth/login.php'));
exit();