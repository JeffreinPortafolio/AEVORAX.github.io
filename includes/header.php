<?php

// ===== CABECERAS ANTICACHÉ =====
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
// ================================

require_once __DIR__ . '/../core/conexion.php'; // inicia la sesión con la configuración común

// ============================================================
// DESACTIVAR MANTENIMIENTO VÍA URL
// ============================================================
procesarDesbloqueoMantenimiento($conexion, strtok($_SERVER['REQUEST_URI'], '?'));

// Verificación estricta de sesión (usuario activo en BD y rol vigente)
restringirAcceso();
$pagina_actual = basename($_SERVER['PHP_SELF']);
$titulo_pagina = $titulo_pagina ?? ucfirst(trim(str_replace('_', ' ', basename($pagina_actual, '.php'))));
$rol_actual = $_SESSION['rol'];

// ───── Configuración dinámica ─────
$nombre_sistema = obtenerConfiguracion($conexion, 'nombre_sistema', 'SIGA-UDO');
$logo_url       = obtenerConfiguracion($conexion, 'logo_url', '');
$institucion    = obtenerConfiguracion($conexion, 'nombre_institucion', 'Universidad de Oriente - Núcleo Monagas');
$slogan         = obtenerConfiguracion($conexion, 'slogan', 'Del pueblo venimos, hacia el pueblo vamos');

// ───── Duración de sesión ─────
$duracion_sesion = (int)obtenerConfiguracion($conexion, 'duracion_sesion', 0);
if ($duracion_sesion > 0) {
    $ahora = time();
    if (!isset($_SESSION['ultima_actividad'])) {
        $_SESSION['ultima_actividad'] = $ahora;
    }
    $tiempo_inactivo = $ahora - $_SESSION['ultima_actividad'];
    if ($tiempo_inactivo > ($duracion_sesion * 60)) {
        $conexion->prepare("DELETE FROM sesiones_activas WHERE usuario_id = ?")->execute([$_SESSION['usuario_id']]);
        registrarAuditoria($conexion, "Cierre de sesión por inactividad", "usuarios");
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        }
        session_destroy();
        header("Location: " . url('auth/login.php?msj=inactivo'));
        exit;
    }
    $_SESSION['ultima_actividad'] = $ahora;
}

// ───── Mantenimiento programado ─────
$mensaje_mantenimiento = obtenerConfiguracion($conexion, 'mensaje_mantenimiento', 'Sistema en mantenimiento.');
if (sistemaEnMantenimiento($conexion) && $_SESSION['rol'] !== ROL_ADMIN) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= e($nombre_sistema) ?> – Mantenimiento</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <?php require_once __DIR__ . '/theme_head.php'; ?>
        <style>
            body { display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .card-mantenimiento { border-radius: 1.5rem; max-width: 500px; padding: 40px; text-align: center; }
            .card-mantenimiento i { font-size: 3rem; color: var(--accent-cyan); margin-bottom: 20px; }
        </style>
    </head>
    <body>
        <div class="card card-mantenimiento shadow-lg">
            <?php if (!empty($logo_url) && file_exists(__DIR__ . '/../' . $logo_url)): ?>
                <img src="/Asset-UDO/<?= e($logo_url) ?>?v=<?= filemtime(__DIR__ . '/../' . $logo_url) ?>" style="max-height: 60px; margin-bottom: 15px;" alt="Logo">
            <?php endif; ?>
            <i class="fas fa-tools"></i>
            <h3><?= e($nombre_sistema) ?></h3>
            <p class="text-muted"><?= nl2br(e($mensaje_mantenimiento)) ?></p>
            <a href="/Asset-UDO/auth/logout.php" class="btn btn-outline-primary rounded-pill mt-3">Cerrar sesión</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ───── Limpieza automática de logs (como máximo una vez por hora y sesión) ─────
$dias_retencion = (int)obtenerConfiguracion($conexion, 'dias_retencion_logs', 365);
if ($dias_retencion > 0 && ($_SESSION['limpieza_logs'] ?? 0) < time() - 3600) {
    $_SESSION['limpieza_logs'] = time();
    try {
        $conexion->prepare("DELETE FROM auditoria WHERE fecha_hora < DATE_SUB(NOW(), INTERVAL ? DAY)")
                 ->execute([$dias_retencion]);
    } catch (Exception $e) {
        error_log('Limpieza de auditoría: ' . $e->getMessage());
    }
}

// ───── Foto de perfil (con timestamp para evitar caché) ─────
$foto_usuario = '';
if (!empty($_SESSION['usuario_id'])) {
    if (!empty($_SESSION['foto']) && file_exists(__DIR__ . '/../' . $_SESSION['foto'])) {
        $foto_path = $_SESSION['foto'];
        $foto_usuario = '/Asset-UDO/' . $foto_path . '?v=' . filemtime(__DIR__ . '/../' . $foto_path);
    } else {
        $stmt = $conexion->prepare("SELECT foto FROM usuarios WHERE id_usuario = ?");
        $stmt->execute([$_SESSION['usuario_id']]);
        $foto_bd = $stmt->fetchColumn();
        if ($foto_bd && file_exists(__DIR__ . '/../' . $foto_bd)) {
            $foto_usuario = '/Asset-UDO/' . $foto_bd . '?v=' . filemtime(__DIR__ . '/../' . $foto_bd);
            $_SESSION['foto'] = $foto_bd;
        } else if (!empty($_SESSION['foto']) && file_exists(__DIR__ . '/../' . $_SESSION['foto'])) {
            $foto_usuario = '/Asset-UDO/' . $_SESSION['foto'] . '?v=' . filemtime(__DIR__ . '/../' . $_SESSION['foto']);
        }
    }
}

$logo_versionado = '';
if (!empty($logo_url) && file_exists(__DIR__ . '/../' . $logo_url)) {
    $logo_versionado = '/Asset-UDO/' . $logo_url . '?v=' . filemtime(__DIR__ . '/../' . $logo_url);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title><?= e($titulo_pagina) ?> | <?= e($nombre_sistema) ?></title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <?php require_once __DIR__ . '/theme_head.php'; ?>
</head>
<body>
<div class="menu-toggle" data-sidebar-toggle role="button" aria-label="Abrir menú"><i class="fas fa-bars"></i></div>
<div class="sidebar-backdrop" data-sidebar-toggle></div>
<nav class="navbar-custom">
    <div class="d-flex justify-content-between align-items-center">
        <a class="navbar-brand" href="/Asset-UDO/index.php">
            <?php if ($logo_versionado): ?>
                <img src="<?= e($logo_versionado) ?>" alt="Logo" style="height: 30px;">
            <?php endif; ?>
            <span class="d-none d-md-inline"><?= e($nombre_sistema) ?></span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <!-- Botón de cambio de tema Claro / Oscuro -->
            <button type="button" class="btn-theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Cambiar a modo claro" title="Cambiar tema">
                <i class="fas fa-sun"></i>
            </button>

            <!-- Notificaciones / Chat -->
            <a href="/Asset-UDO/modules/mensajes/chat.php" class="btn position-relative rounded-pill" style="border: 1px solid var(--border-color); color: var(--text-primary); padding: 7px 13px;" title="Mensajes">
                <i class="fas fa-comment-dots"></i>
                <span id="mensajesBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem; display: none;">0</span>
            </a>

            <?php if ($rol_actual == ROL_ADMIN): ?>
            <a href="/Asset-UDO/modules/admin/usuarios.php#seccion-pendientes" class="btn position-relative rounded-pill text-warning" style="border: 1px solid var(--border-color); padding: 7px 13px;" title="Solicitudes pendientes">
                <i class="fas fa-user-clock"></i>
                <span id="solicitudesBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem; display: none;">0</span>
            </a>
            <?php endif; ?>

            <!-- Perfil de usuario -->
            <div class="dropdown">
                <div class="d-flex align-items-center user-profile-badge dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="me-2 text-end d-none d-sm-block">
                        <div class="fw-bold small"><?= e($_SESSION['nombre'] ?? '') ?></div>
                        <div class="text-muted small"><?= strtoupper(e($_SESSION['rol'] ?? '')) ?></div>
                    </div>
                    <div id="headerAvatar" class="rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; overflow: hidden; background: linear-gradient(135deg, var(--accent-cyan), var(--udo-blue));">
                        <?php if ($foto_usuario): ?>
                            <img src="<?= e($foto_usuario) ?>" style="width:100%; height:100%; object-fit:cover;" onerror="this.style.display='none'; this.parentNode.innerHTML='<i class=\'fas fa-user text-white fa-sm\'></i>'">
                        <?php else: ?>
                            <i class="fas fa-user text-white fa-sm"></i>
                        <?php endif; ?>
                    </div>
                </div>
                <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2">
                    <li><a class="dropdown-item" href="/Asset-UDO/modules/tecnico/mi_perfil.php"><i class="fas fa-id-card me-2"></i>Mi Perfil</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="/Asset-UDO/auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Cerrar Sesión</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<div class="sidebar" id="sidebar">
    <div class="p-3 text-center border-bottom" style="border-color:var(--border-color);">
        <?php if ($logo_versionado): ?>
            <img src="<?= e($logo_versionado) ?>" style="max-height: 50px; margin-bottom: 8px;" alt="Logo">
        <?php else: ?>
            <i class="fas fa-microchip fa-2x" style="color:var(--accent-cyan);"></i>
        <?php endif; ?>
        <h6 class="mt-2 mb-0" style="color:var(--text-primary); font-weight:700;"><?= e($nombre_sistema) ?></h6>
        <small class="text-muted"><?= e($institucion) ?></small>
    </div>
    <nav class="nav flex-column p-2">
        <div class="nav-section-title">Principal</div>
        <a class="nav-link <?= $pagina_actual == 'index.php' ? 'active' : '' ?>" href="/Asset-UDO/index.php"><i class="fas fa-tachometer-alt"></i>Panel de Control</a>
        <div class="nav-section-title">Inventario</div>
        <?php if(in_array($rol_actual, [ROL_ADMIN, ROL_RECEPCIONISTA, ROL_TECNICO, ROL_CONSULTOR])): ?>
        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/activos/lista') !== false ? 'active' : '' ?>" href="/Asset-UDO/modules/activos/lista.php"><i class="fas fa-boxes"></i>Inventario</a>
        <?php endif; ?>
        <?php if($rol_actual == ROL_ADMIN): ?>
        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/activos/registro') !== false ? 'active' : '' ?>" href="/Asset-UDO/modules/activos/registro.php"><i class="fas fa-plus-circle"></i>Registrar Activo</a>
        <?php endif; ?>
        <div class="nav-section-title">Procesos</div>
        <?php if(in_array($rol_actual, [ROL_ADMIN, ROL_RECEPCIONISTA, ROL_TECNICO])): ?>
        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/recepcion/') !== false ? 'active' : '' ?>" href="/Asset-UDO/modules/recepcion/novedad.php"><i class="fas fa-clinic-medical"></i>Recepción</a>
        <?php endif; ?>
        <?php if(in_array($rol_actual, [ROL_ADMIN, ROL_TECNICO])): ?>
        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/diagnostico') !== false ? 'active' : '' ?>" href="/Asset-UDO/modules/tecnico/diagnostico.php"><i class="fas fa-stethoscope"></i>Diagnóstico</a>
        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/reparar') !== false ? 'active' : '' ?>" href="/Asset-UDO/modules/tecnico/reparar.php"><i class="fas fa-tools"></i>Taller</a>
        <?php endif; ?>
        <?php if(in_array($rol_actual, [ROL_ADMIN, ROL_TECNICO, ROL_RECEPCIONISTA])): ?>
        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/entregas') !== false ? 'active' : '' ?>" href="/Asset-UDO/modules/tecnico/entregas.php"><i class="fas fa-truck-loading"></i>Entregas</a>
        <?php endif; ?>
        <?php if(in_array($rol_actual, [ROL_ADMIN, ROL_TECNICO])): ?>
        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/bajas') !== false ? 'active' : '' ?>" href="/Asset-UDO/modules/tecnico/bajas.php"><i class="fas fa-skull-crossbones"></i>Bajas</a>
        <?php endif; ?>
        <div class="nav-section-title">Administración y Reportes</div>
        <?php if($rol_actual == ROL_ADMIN): ?>
        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/usuarios') !== false ? 'active' : '' ?>" href="/Asset-UDO/modules/admin/usuarios.php"><i class="fas fa-users"></i>Usuarios</a>
        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/auditoria') !== false ? 'active' : '' ?>" href="/Asset-UDO/modules/admin/auditoria.php"><i class="fas fa-history"></i>Auditoría</a>
        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/reportes') !== false ? 'active' : '' ?>" href="/Asset-UDO/modules/admin/reportes.php"><i class="fas fa-chart-bar"></i>Reportes</a>
        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/historial_vouchers') !== false ? 'active' : '' ?>" href="/Asset-UDO/modules/admin/historial_vouchers.php"><i class="fas fa-file-alt"></i>Historial de Vouchers</a>
        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/configuracion') !== false ? 'active' : '' ?>" href="/Asset-UDO/modules/admin/configuracion.php"><i class="fas fa-cog"></i>Configuración</a>
        <?php endif; ?>
        <?php if($rol_actual == ROL_CONSULTOR): ?>
        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/reportes') !== false ? 'active' : '' ?>" href="/Asset-UDO/modules/admin/reportes.php"><i class="fas fa-chart-bar"></i>Reportes</a>
        <a class="nav-link <?= strpos($_SERVER['REQUEST_URI'], '/admin/auditoria') !== false ? 'active' : '' ?>" href="/Asset-UDO/modules/admin/auditoria.php"><i class="fas fa-history"></i>Auditoría</a>
        <?php endif; ?>
    </nav>
</div>
<script>
/* Posición del menú lateral: se restaura de inmediato (antes del primer pintado) y se centra el enlace activo si queda fuera de vista. */
(function () {
    var sb = document.getElementById('sidebar');
    if (!sb) return;
    var y = 0;
    try { y = parseInt(sessionStorage.getItem('sidebarScroll'), 10) || 0; } catch (e) {}
    sb.scrollTop = y;
    var act = sb.querySelector('.nav-link.active');
    if (act) {
        var top = act.offsetTop, bottom = top + act.offsetHeight;
        if (top < sb.scrollTop + 40 || bottom > sb.scrollTop + sb.clientHeight - 40) {
            sb.scrollTop = Math.max(0, top - sb.clientHeight / 2 + act.offsetHeight / 2);
        }
    }
})();
</script>

<main class="content-body">
    <?php
    $fl = consumirFlash();
    $errorGlobal = !empty($fl['error']) ? $fl['error'] : ($_GET['error'] ?? null);
    $msjGlobal   = !empty($fl['mensaje']) ? $fl['mensaje'] : ($_GET['msj'] ?? null);
    ?>
    <?php if ($errorGlobal): ?>
        <div class="alert alert-danger animate__animated animate__shakeX mb-4">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?= $errorGlobal === 'sin_permiso' ? 'No tienes permisos para acceder a esta sección.' : flashHtml($errorGlobal) ?>
        </div>
    <?php endif; ?>
    <?php if ($msjGlobal): ?>
        <div class="alert alert-success animate__animated animate__fadeInDown mb-4">
            <i class="fas fa-check-circle me-2"></i>
            <?= $msjGlobal === '1' ? 'Operación realizada con éxito.' : flashHtml($msjGlobal) ?>
        </div>
    <?php endif; ?>

    <div class="modal fade" id="modalInactividad" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4 shadow-lg">
                <div class="modal-header border-0">
                    <h5 class="fw-bold"><i class="fas fa-clock me-2 text-warning"></i>Sesión por expirar</h5>
                </div>
                <div class="modal-body">
                    <p>Su sesión está por cerrarse por inactividad. Toque <strong>Continuar</strong> para seguir trabajando.</p>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill" id="btnCerrarSesionInactividad">
                        <i class="fas fa-sign-out-alt me-1"></i> Cerrar sesión
                    </button>
                    <button type="button" class="btn btn-primary rounded-pill" id="btnContinuarSesion">
                        <i class="fas fa-check me-1"></i> Continuar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($duracion_sesion > 0): ?>
    <script>
    (function () {
        var LIMITE = <?= $duracion_sesion * 60 * 1000 ?>;           // inactividad permitida (ms)
        var AVISO  = Math.max(LIMITE - 60000, LIMITE * 0.8);        // el aviso sale 1 min antes
        var temporizador, modalInactividad, ultimoPing = Date.now();
        var elModal = function () { return document.getElementById('modalInactividad'); };

        function ping() {                                           // renueva la sesión en el servidor
            ultimoPing = Date.now();
            return fetch('/Asset-UDO/auth/ping.php', { credentials: 'same-origin', cache: 'no-store' }).catch(function () {});
        }
        function mostrar() {
            if (!modalInactividad) modalInactividad = new bootstrap.Modal(elModal());
            modalInactividad.show();
        }
        function reiniciar() {
            var m = elModal();
            if (m && m.classList.contains('show')) return;
            clearTimeout(temporizador);
            temporizador = setTimeout(mostrar, AVISO);
            // La actividad del usuario (táctil incluida) también cuenta en el servidor, como mucho 1 vez por minuto
            if (Date.now() - ultimoPing > 60000) ping();
        }
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('btnCerrarSesionInactividad').addEventListener('click', function () {
                window.location.href = '/Asset-UDO/auth/logout.php?motivo=inactividad';
            });
            document.getElementById('btnContinuarSesion').addEventListener('click', function () {
                ping().then(function () { if (modalInactividad) modalInactividad.hide(); reiniciar(); });
            });
        });
        ['touchstart', 'touchmove', 'pointerdown', 'mousemove', 'keydown', 'click'].forEach(function (ev) {
            document.addEventListener(ev, reiniciar, { passive: true });
        });
        window.addEventListener('scroll', reiniciar, { passive: true, capture: true });   // también scroll en listas/menú
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) return;
            // Al volver a la pestaña (teléfono): si ya pasó el límite se va al login; si no, se renueva
            if (Date.now() - ultimoPing > LIMITE) { window.location.reload(); } else { reiniciar(); }
        });
        window.addEventListener('load', reiniciar);
    })();
    </script>
    <?php endif; ?>

    <script>
    function actualizarBadgeMensajes() {
        fetch('/Asset-UDO/modules/mensajes/chat.php?contar_no_leidos=1')
            .then(res => res.text())
            .then(count => {
                let badge = document.getElementById('mensajesBadge');
                if (badge) {
                    if (count > 0) {
                        badge.textContent = count > 99 ? '99+' : count;
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            })
            .catch(err => console.log('Error actualizando badge mensajes:', err));
    }
    actualizarBadgeMensajes();
    setInterval(function () { if (!document.hidden) actualizarBadgeMensajes(); }, 30000);

    <?php if ($rol_actual == 'administrador'): ?>
    function actualizarBadgeSolicitudes() {
        fetch('/Asset-UDO/modules/admin/usuarios.php?contar_pendientes=1')
            .then(res => res.text())
            .then(count => {
                let badge = document.getElementById('solicitudesBadge');
                if (badge) {
                    if (count > 0) {
                        badge.textContent = count > 99 ? '99+' : count;
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            })
            .catch(err => console.log('Error actualizando badge solicitudes:', err));
    }
    actualizarBadgeSolicitudes();
    setInterval(function () { if (!document.hidden) actualizarBadgeSolicitudes(); }, 30000);
    <?php endif; ?>
    </script>