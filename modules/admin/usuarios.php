<?php
require_once __DIR__ . '/../../core/conexion.php';
restringirAcceso(['administrador']);

// ===== AJAX (sin cambios) =====
if (isset($_GET['contar_pendientes'])) {
    $stmt = $conexion->prepare("SELECT COUNT(*) FROM usuarios WHERE estado = 'pendiente'");
    $stmt->execute();
    echo $stmt->fetchColumn();
    exit;
}
if (isset($_GET['check_cedula']) && isset($_GET['cedula'])) {
    header('Content-Type: text/plain');
    $cedula = trim($_GET['cedula']);
    $id_excluir = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!empty($cedula)) {
        $sql = "SELECT id_usuario FROM usuarios WHERE cedula = ?";
        $params = [$cedula];
        if ($id_excluir > 0) {
            $sql .= " AND id_usuario != ?";
            $params[] = $id_excluir;
        }
        $stmt = $conexion->prepare($sql);
        $stmt->execute($params);
        echo $stmt->fetch() ? 'existe' : 'no_existe';
    } else {
        echo 'no_existe';
    }
    exit;
}
if (isset($_GET['check_email']) && isset($_GET['email'])) {
    header('Content-Type: text/plain');
    $email = trim($_GET['email']);
    $id_excluir = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!empty($email)) {
        $sql = "SELECT id_usuario FROM usuarios WHERE email = ?";
        $params = [$email];
        if ($id_excluir > 0) {
            $sql .= " AND id_usuario != ?";
            $params[] = $id_excluir;
        }
        $stmt = $conexion->prepare($sql);
        $stmt->execute($params);
        echo $stmt->fetch() ? 'existe' : 'no_existe';
    } else {
        echo 'no_existe';
    }
    exit;
}

// ------------------- PROCESAR ACCIONES POST -------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Aprobar
    if (isset($_POST['aprobar'])) {
        $id = (int)$_POST['id_usuario'];
        try {
            $conexion->prepare("UPDATE usuarios SET estado = 'activo' WHERE id_usuario = ? AND estado = 'pendiente'")->execute([$id]);
            registrarAuditoria($conexion, "Aprobó registro de usuario ID $id", "usuarios", $id);
            header("Location: usuarios.php?msj=aprobado");
            exit;
        } catch (Exception $e) {
            $_SESSION['flash_error'] = errorInterno($e);
        }
    }
    // Rechazar solicitud (Eliminación lógica / Rechazo)
    if (isset($_POST['rechazar'])) {
        $id = (int)$_POST['id_usuario'];
        try {
            $conexion->prepare("UPDATE usuarios SET estado = 'inactivo' WHERE id_usuario = ? AND estado = 'pendiente'")->execute([$id]);
            registrarAuditoria($conexion, "Rechazó solicitud de usuario ID $id (marcado como inactivo)", "usuarios", $id);
            header("Location: usuarios.php?msj=rechazado");
            exit;
        } catch (Exception $e) {
            $_SESSION['flash_error'] = errorInterno($e);
        }
    }
    // Desactivar (Eliminación lógica - Regla de auditoría del Comité)
    if (isset($_POST['eliminar']) || isset($_POST['desactivar'])) {
        $id = (int)$_POST['id_usuario'];
        if ($id == 1) {
            $_SESSION['flash_error'] = "No se puede desactivar al administrador principal.";
        } else {
            try {
                $conexion->prepare("UPDATE usuarios SET estado = 'inactivo' WHERE id_usuario = ?")->execute([$id]);
                // Cerrar sesiones activas del usuario desactivado
                $conexion->prepare("DELETE FROM sesiones_activas WHERE usuario_id = ?")->execute([$id]);
                registrarAuditoria($conexion, "Desactivó usuario ID $id (Eliminación lógica)", "usuarios", $id);
                header("Location: usuarios.php?msj=desactivado");
                exit;
            } catch (Exception $e) {
                $_SESSION['flash_error'] = errorInterno($e);
            }
        }
    }
    // Reactivar usuario
    if (isset($_POST['reactivar'])) {
        $id = (int)$_POST['id_usuario'];
        try {
            $conexion->prepare("UPDATE usuarios SET estado = 'activo' WHERE id_usuario = ?")->execute([$id]);
            registrarAuditoria($conexion, "Reactivó usuario ID $id", "usuarios", $id);
            header("Location: usuarios.php?msj=reactivado");
            exit;
        } catch (Exception $e) {
            $_SESSION['flash_error'] = errorInterno($e);
        }
    }
    // Crear usuario
    if (isset($_POST['crear'])) {
        $nombre      = limpiar($_POST['nombre']);
        $email       = limpiar($_POST['email']);
        $cedula      = limpiar($_POST['cedula'] ?? '');
        $telefono    = limpiar($_POST['telefono'] ?? '');
        $cargo       = limpiar($_POST['cargo'] ?? '');
        $direccion   = limpiar($_POST['direccion'] ?? '');
        $fecha_nac_raw = trim($_POST['fecha_nacimiento'] ?? '');
        $rol         = $_POST['rol'];
        $estado      = $_POST['estado'] ?? 'activo';
        $clave       = $_POST['clave'] ?? '';

        $errores = [];
        if (!in_array($rol, ROLES_VALIDOS, true)) $errores[] = "El rol seleccionado no es válido.";
        if (!in_array($estado, ['activo', 'inactivo'], true)) $errores[] = "El estado seleccionado no es válido.";

        // Validaciones básicas (resumidas)
        if (empty($nombre)) $errores[] = "El nombre es obligatorio.";
        elseif (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/u", $nombre)) $errores[] = "El nombre solo puede contener letras y espacios.";
        if (empty($email)) $errores[] = "El correo es obligatorio.";
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = "El email no es válido.";
        else {
            $check = $conexion->prepare("SELECT id_usuario FROM usuarios WHERE email = ?");
            $check->execute([$email]);
            if ($check->fetch()) $errores[] = "El email ya está registrado.";
        }
        if (empty($cedula)) $errores[] = "La cédula es obligatoria.";
        else {
            if (!ctype_digit($cedula)) $errores[] = "La cédula debe contener solo números.";
            elseif (strlen($cedula) > 8) $errores[] = "La cédula no puede tener más de 8 dígitos.";
            else {
                $check = $conexion->prepare("SELECT id_usuario FROM usuarios WHERE cedula = ?");
                $check->execute([$cedula]);
                if ($check->fetch()) $errores[] = "La cédula ya está registrada.";
            }
        }
        if (empty($telefono)) $errores[] = "El teléfono es obligatorio.";
        else {
            if (!ctype_digit($telefono)) $errores[] = "El teléfono debe contener solo números.";
            elseif (strlen($telefono) != 11) $errores[] = "El teléfono debe tener exactamente 11 dígitos.";
        }
        if (empty($cargo)) $errores[] = "El cargo es obligatorio.";
        elseif (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s\-]+$/u", $cargo)) $errores[] = "El cargo solo puede contener letras, espacios y guiones.";
        if (empty($clave)) $errores[] = "La contraseña es obligatoria.";
        elseif (strlen($clave) < 6) $errores[] = "La contraseña debe tener al menos 6 caracteres.";
        if (empty($rol)) $errores[] = "Debe seleccionar un rol.";

        // Convertir fecha de DD-MM-AAAA a AAAA-MM-DD
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
                    $errores[] = "La fecha de nacimiento no es válida. Verifique día, mes y año (1900-".date('Y').").";
                }
            } else {
                $errores[] = "Formato de fecha incorrecto. Debe ser DD-MM-AAAA (ejemplo: 20-05-1990).";
            }
        }

        if (empty($errores)) {
            $clave_hash = password_hash($clave, PASSWORD_DEFAULT);
            $foto_path = '';
            if (!empty($_FILES['foto']['name']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $errFoto = '';
                $nuevaFoto = procesarImagenSubida($_FILES['foto'], 'perfiles', 'perfil', $errFoto, 800, 10);
                if ($nuevaFoto) { $foto_path = $nuevaFoto; }
                else { flash('error', $errFoto); }
            }

            try {
                $conexion->prepare("INSERT INTO usuarios (nombre, email, cedula, clave, rol, estado, telefono, direccion, cargo, foto, fecha_nacimiento, primer_acceso, creado_por)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                    $nombre, $email, $cedula, $clave_hash, $rol, $estado,
                    $telefono, $direccion, $cargo, $foto_path, $fecha_nac, 0, $_SESSION['usuario_id']
                ]);
                registrarAuditoria($conexion, "Creó usuario $email", "usuarios", $conexion->lastInsertId());
                header("Location: usuarios.php?msj=ok");
                exit;
            } catch (Exception $e) {
                $_SESSION['flash_error'] = errorInterno($e);
            }
        } else {
            $_SESSION['flash_error'] = implode("<br>", $errores);
        }
        header("Location: usuarios.php");
        exit;
    }
    // Editar usuario
    if (isset($_POST['editar'])) {
        $id = (int)$_POST['id_usuario'];
        if ($id == 1) { header("Location: usuarios.php"); exit; }
        $nombre      = limpiar($_POST['nombre']);
        $rol         = $_POST['rol'];
        $estado      = $_POST['estado'];
        $telefono    = limpiar($_POST['telefono'] ?? '');
        $cargo       = limpiar($_POST['cargo'] ?? '');
        $direccion   = limpiar($_POST['direccion'] ?? '');
        $fecha_nac_raw = trim($_POST['fecha_nacimiento'] ?? '');
        $cedula      = limpiar($_POST['cedula'] ?? '');

        $errores = [];
        if (!in_array($rol, ROLES_VALIDOS, true)) $errores[] = "El rol seleccionado no es válido.";
        if (!in_array($estado, ['activo', 'inactivo'], true)) $errores[] = "El estado seleccionado no es válido.";

        // Validaciones edición
        if (empty($nombre)) $errores[] = "El nombre es obligatorio.";
        elseif (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/u", $nombre)) $errores[] = "El nombre solo puede contener letras y espacios.";
        if (empty($cedula)) $errores[] = "La cédula es obligatoria.";
        else {
            if (!ctype_digit($cedula)) $errores[] = "La cédula debe contener solo números.";
            elseif (strlen($cedula) > 8) $errores[] = "La cédula no puede tener más de 8 dígitos.";
            else {
                $check = $conexion->prepare("SELECT id_usuario FROM usuarios WHERE cedula = ? AND id_usuario != ?");
                $check->execute([$cedula, $id]);
                if ($check->fetch()) $errores[] = "La cédula ya está registrada por otro usuario.";
            }
        }
        if (empty($telefono)) $errores[] = "El teléfono es obligatorio.";
        else {
            if (!ctype_digit($telefono)) $errores[] = "El teléfono debe contener solo números.";
            elseif (strlen($telefono) != 11) $errores[] = "El teléfono debe tener exactamente 11 dígitos.";
        }
        if (empty($cargo)) $errores[] = "El cargo es obligatorio.";
        elseif (!preg_match("/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s\-]+$/u", $cargo)) $errores[] = "El cargo solo puede contener letras, espacios y guiones.";

        // Convertir fecha de DD-MM-AAAA a AAAA-MM-DD
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
                    $errores[] = "La fecha de nacimiento no es válida. Verifique día, mes y año (1900-".date('Y').").";
                }
            } else {
                $errores[] = "Formato de fecha incorrecto. Debe ser DD-MM-AAAA (ejemplo: 20-05-1990).";
            }
        }

        if (empty($errores)) {
            // La foto actual se toma de la BD (nunca del formulario, que el cliente puede manipular)
            $stFoto = $conexion->prepare("SELECT foto FROM usuarios WHERE id_usuario = ?");
            $stFoto->execute([$id]);
            $foto_path = (string)($stFoto->fetchColumn() ?: '');
            if (!empty($_FILES['foto']['name']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                $errFoto = '';
                $nuevaFoto = procesarImagenSubida($_FILES['foto'], 'perfiles', 'perfil', $errFoto, 800, 10);
                if ($nuevaFoto) {
                    borrarImagenSubida($foto_path);
                    $foto_path = $nuevaFoto;
                } else { flash('error', $errFoto); }
            }

            $sql = "UPDATE usuarios SET nombre=?, rol=?, estado=?, telefono=?, cargo=?, direccion=?, fecha_nacimiento=?, foto=?, cedula=? WHERE id_usuario=?";
            $params = [$nombre, $rol, $estado, $telefono, $cargo, $direccion, $fecha_nac, $foto_path, $cedula, $id];
            $conexion->prepare($sql)->execute($params);

            if (!empty($_POST['nueva_clave']) && strlen($_POST['nueva_clave']) >= 6) {
                $hash = password_hash($_POST['nueva_clave'], PASSWORD_DEFAULT);
                $conexion->prepare("UPDATE usuarios SET clave = ? WHERE id_usuario = ?")->execute([$hash, $id]);
            }

            registrarAuditoria($conexion, "Editó usuario ID $id", "usuarios", $id);
            header("Location: usuarios.php?msj=editado");
            exit;
        } else {
            $_SESSION['flash_error'] = implode("<br>", $errores);
            header("Location: usuarios.php");
            exit;
        }
    }
}

// ------------------- PAGINACIÓN Y BÚSQUEDA (resumido) -------------------
$buscar          = $_GET['buscar'] ?? '';
$pag_usuarios    = max(1, (int)($_GET['pag_usuarios'] ?? 1));
$pag_pendientes  = max(1, (int)($_GET['pag_pendientes'] ?? 1));
$por_pag_usuarios = 5;
$por_pag_pendientes = 5;

$where_usuarios = "WHERE estado IN ('activo','inactivo')";
$params_usuarios = [];
if (!empty($buscar)) {
    $where_usuarios .= " AND (nombre LIKE ? OR email LIKE ? OR cedula LIKE ?)";
    $like_usuarios = '%' . escaparLike((string)$buscar) . '%';
    $params_usuarios = [$like_usuarios, $like_usuarios, $like_usuarios];
}
$total_usuarios = $conexion->prepare("SELECT COUNT(*) FROM usuarios $where_usuarios");
$total_usuarios->execute($params_usuarios);
$num_usuarios = $total_usuarios->fetchColumn();
$total_pag_usuarios = ceil($num_usuarios / $por_pag_usuarios);
$offset_usuarios = ($pag_usuarios - 1) * $por_pag_usuarios;
$sql_usuarios = "SELECT * FROM usuarios $where_usuarios ORDER BY fecha_creacion DESC LIMIT $por_pag_usuarios OFFSET $offset_usuarios";
$usuarios = $conexion->prepare($sql_usuarios);
$usuarios->execute($params_usuarios);
$usuarios = $usuarios->fetchAll();

$where_pendientes = "WHERE estado = 'pendiente'";
$params_pendientes = [];
if (!empty($buscar)) {
    $where_pendientes .= " AND (nombre LIKE ? OR email LIKE ? OR cedula LIKE ?)";
    $like_pendientes = '%' . escaparLike((string)$buscar) . '%';
    $params_pendientes = [$like_pendientes, $like_pendientes, $like_pendientes];
}
$total_pend = $conexion->prepare("SELECT COUNT(*) FROM usuarios $where_pendientes");
$total_pend->execute($params_pendientes);
$num_pendientes = $total_pend->fetchColumn();
$total_pag_pend = ceil($num_pendientes / $por_pag_pendientes);
$offset_pend = ($pag_pendientes - 1) * $por_pag_pendientes;
$sql_pend = "SELECT * FROM usuarios $where_pendientes ORDER BY fecha_creacion DESC LIMIT $por_pag_pendientes OFFSET $offset_pend";
$pendientes = $conexion->prepare($sql_pend);
$pendientes->execute($params_pendientes);
$pendientes = $pendientes->fetchAll();

$roles = ['administrador', 'tecnico', 'recepcionista', 'consultor'];

include __DIR__ . '/../../includes/header.php';
$mensaje = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);
?>

<div class="container-fluid animate__animated animate__fadeIn">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold mb-0"><i class="fas fa-users me-2" style="color:var(--accent-cyan);"></i>Gestión de Usuarios</h3>
        <div class="position-relative">
            <button class="btn btn-warning rounded-pill fw-bold"
                    onclick="document.getElementById('seccion-pendientes').scrollIntoView({behavior:'smooth'});">
                <i class="fas fa-clock me-2"></i> Solicitudes
                <?php if ($num_pendientes > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:0.7rem;"><?= $num_pendientes ?></span>
                <?php endif; ?>
            </button>
        </div>
    </div>

    <?php if (isset($_GET['msj'])): ?>
        <div class="alert alert-success">
            <?= $_GET['msj'] == 'aprobado' ? 'Usuario aprobado correctamente.' :
               ($_GET['msj'] == 'rechazado' ? 'Solicitud rechazada.' :
               ($_GET['msj'] == 'eliminado' ? 'Usuario eliminado.' : 'Operación exitosa.')) ?>
        </div>
    <?php endif; ?>
    <?php if ($mensaje): ?><div class="alert alert-danger"><?= flashHtml($mensaje) ?></div><?php endif; ?>

    <!-- Capacidades por rol (escritorio) -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 vista-escritorio">
        <div class="card-header bg-transparent border-0"><h5 class="fw-bold"><i class="fas fa-info-circle me-2" style="color:var(--accent-cyan);"></i>Capacidades por Rol</h5></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0" style="font-size:0.8rem;">
                    <thead><tr><th>Funcionalidad</th><th>Administrador</th><th>Técnico</th><th>Recepcionista</th><th>Consultor</th></tr></thead>
                    <tbody>
                        <tr><td>Gestión de usuarios</td><td><i class="fas fa-check text-success"></i></td><td><i class="fas fa-times text-danger"></i></td><td><i class="fas fa-times text-danger"></i></td><td><i class="fas fa-times text-danger"></i></td></tr>
                        <tr><td>Registrar activos</td><td><i class="fas fa-check text-success"></i></td><td><i class="fas fa-times text-danger"></i></td><td><i class="fas fa-check text-success"></i></td><td><i class="fas fa-times text-danger"></i></td></tr>
                        <tr><td>Recepción de equipos</td><td><i class="fas fa-check text-success"></i></td><td><i class="fas fa-check text-success"></i></td><td><i class="fas fa-check text-success"></i></td><td><i class="fas fa-times text-danger"></i></td></tr>
                        <tr><td>Diagnóstico</td><td><i class="fas fa-check text-success"></i></td><td><i class="fas fa-check text-success"></i></td><td><i class="fas fa-times text-danger"></i></td><td><i class="fas fa-times text-danger"></i></td></tr>
                        <tr><td>Reparación / Taller</td><td><i class="fas fa-check text-success"></i></td><td><i class="fas fa-check text-success"></i></td><td><i class="fas fa-times text-danger"></i></td><td><i class="fas fa-times text-danger"></i></td></tr>
                        <tr><td>Entregas</td><td><i class="fas fa-check text-success"></i></td><td><i class="fas fa-check text-success"></i></td><td><i class="fas fa-check text-success"></i></td><td><i class="fas fa-times text-danger"></i></td></tr>
                        <tr><td>Bajas y oficios</td><td><i class="fas fa-check text-success"></i></td><td><i class="fas fa-check text-success"></i></td><td><i class="fas fa-times text-danger"></i></td><td><i class="fas fa-times text-danger"></i></td></tr>
                        <tr><td>Auditoría / Reportes</td><td><i class="fas fa-check text-success"></i></td><td><i class="fas fa-times text-danger"></i></td><td><i class="fas fa-times text-danger"></i></td><td><i class="fas fa-check text-success"></i></td></tr>
                        <tr><td>Configuración</td><td><i class="fas fa-check text-success"></i></td><td><i class="fas fa-times text-danger"></i></td><td><i class="fas fa-times text-danger"></i></td><td><i class="fas fa-times text-danger"></i></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="vista-movil mb-4">
        <div class="card rounded-4"><div class="card-body"><h6 class="fw-bold">Administrador</h6><ul class="small"><li><i class="fas fa-check text-success me-1"></i> Gestión de usuarios</li><li><i class="fas fa-check text-success me-1"></i> Registrar activos</li><li><i class="fas fa-check text-success me-1"></i> Recepción</li><li><i class="fas fa-check text-success me-1"></i> Diagnóstico</li><li><i class="fas fa-check text-success me-1"></i> Taller</li><li><i class="fas fa-check text-success me-1"></i> Entregas</li><li><i class="fas fa-check text-success me-1"></i> Bajas</li><li><i class="fas fa-check text-success me-1"></i> Auditoría</li><li><i class="fas fa-check text-success me-1"></i> Configuración</li></ul><h6 class="fw-bold mt-3">Técnico</h6><ul class="small"><li><i class="fas fa-times text-danger me-1"></i> Gestión usuarios</li><li><i class="fas fa-times text-danger me-1"></i> Registrar activos</li><li><i class="fas fa-check text-success me-1"></i> Recepción</li><li><i class="fas fa-check text-success me-1"></i> Diagnóstico</li><li><i class="fas fa-check text-success me-1"></i> Taller</li><li><i class="fas fa-check text-success me-1"></i> Entregas</li><li><i class="fas fa-check text-success me-1"></i> Bajas</li><li><i class="fas fa-times text-danger me-1"></i> Auditoría</li><li><i class="fas fa-times text-danger me-1"></i> Configuración</li></ul><h6 class="fw-bold mt-3">Recepcionista</h6><ul class="small"><li><i class="fas fa-times text-danger me-1"></i> Gestión usuarios</li><li><i class="fas fa-check text-success me-1"></i> Registrar activos</li><li><i class="fas fa-check text-success me-1"></i> Recepción</li><li><i class="fas fa-times text-danger me-1"></i> Diagnóstico</li><li><i class="fas fa-times text-danger me-1"></i> Taller</li><li><i class="fas fa-check text-success me-1"></i> Entregas</li><li><i class="fas fa-times text-danger me-1"></i> Bajas</li><li><i class="fas fa-times text-danger me-1"></i> Auditoría</li><li><i class="fas fa-times text-danger me-1"></i> Configuración</li></ul><h6 class="fw-bold mt-3">Consultor</h6><ul class="small"><li><i class="fas fa-times text-danger me-1"></i> Gestión usuarios</li><li><i class="fas fa-times text-danger me-1"></i> Registrar activos</li><li><i class="fas fa-times text-danger me-1"></i> Recepción</li><li><i class="fas fa-times text-danger me-1"></i> Diagnóstico</li><li><i class="fas fa-times text-danger me-1"></i> Taller</li><li><i class="fas fa-times text-danger me-1"></i> Entregas</li><li><i class="fas fa-times text-danger me-1"></i> Bajas</li><li><i class="fas fa-check text-success me-1"></i> Auditoría</li><li><i class="fas fa-times text-danger me-1"></i> Configuración</li></ul></div></div>
    </div>

    <!-- Filtro de búsqueda -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-8">
                    <label class="small text-muted">Buscar (nombre, email, cédula)</label>
                    <input type="text" name="buscar" class="form-control" placeholder="Escriba para filtrar..." value="<?= htmlspecialchars($buscar) ?>">
                </div>
                <div class="col-md-2"><button type="submit" class="btn btn-primary w-100 rounded-pill"><i class="fas fa-search me-1"></i> Buscar</button></div>
                <div class="col-md-2"><a href="usuarios.php" class="btn btn-outline-secondary w-100 rounded-pill"><i class="fas fa-times me-1"></i> Limpiar</a></div>
            </form>
        </div>
    </div>

    <!-- Botón Nuevo Usuario -->
    <button class="btn btn-primary rounded-pill mb-3" data-bs-toggle="modal" data-bs-target="#modalCrear"><i class="fas fa-user-plus me-2"></i>Nuevo Usuario</button>

    <!-- TABLA DE USUARIOS (ESCRITORIO) -->
    <div class="card border-0 shadow-sm rounded-4 vista-escritorio">
        <div class="card-header bg-transparent border-0 py-3 d-flex justify-content-between">
            <span><i class="fas fa-list me-2" style="color:var(--accent-cyan);"></i><strong><?= $num_usuarios ?> usuario(s)</strong></span>
            <small class="text-muted">Pág. <?= $pag_usuarios ?> de <?= $total_pag_usuarios ?: 1 ?></small>
        </div>
        <div class="card-body p-0" style="max-height: 60vh; overflow-y: auto;">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr class="text-white small text-uppercase"><th>Nombre</th><th>Email</th><th>Cédula</th><th>Rol</th><th>Estado</th><th>Acciones</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                    <tr>
                        <td><?= e($u['nombre']) ?></td>
                        <td><?= e($u['email']) ?></td>
                        <td><?= e($u['cedula'] ?? '—') ?></td>
                        <td><span class="badge badge-info"><?= $u['rol'] ?></span></td>
                        <td><span class="badge bg-<?= $u['estado']=='activo'?'success':'secondary' ?>"><?= ucfirst($u['estado']) ?></span></td>
                        <td>
                            <?php if ($u['id_usuario'] == 1): ?>
                                <span class="text-muted small">Admin principal</span>
                            <?php else: ?>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-sm btn-outline-warning rounded-pill" onclick="abrirModalEditar(<?= $u['id_usuario'] ?>)"><i class="fas fa-edit"></i></button>
                                    <?php if ($u['estado'] === 'activo'): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('¿Desactivar este usuario? El registro se mantendrá inactivo para auditoría.')">
                                        <?= csrfCampo() ?>
                                        <input type="hidden" name="id_usuario" value="<?= $u['id_usuario'] ?>">
                                        <button type="submit" name="desactivar" class="btn btn-sm btn-outline-danger rounded-pill" title="Desactivar usuario"><i class="fas fa-user-slash"></i></button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('¿Reactivar este usuario para que pueda acceder nuevamente?')">
                                        <?= csrfCampo() ?>
                                        <input type="hidden" name="id_usuario" value="<?= $u['id_usuario'] ?>">
                                        <button type="submit" name="reactivar" class="btn btn-sm btn-outline-success rounded-pill" title="Reactivar usuario"><i class="fas fa-user-check"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pag_usuarios > 1): ?>
        <div class="card-footer bg-transparent border-0 py-2">
            <nav><ul class="pagination pagination-sm justify-content-center mb-0">
                <?php for ($i = 1; $i <= $total_pag_usuarios; $i++): ?>
                    <li class="page-item <?= $i == $pag_usuarios ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(['buscar' => $buscar, 'pag_usuarios' => $i]) ?>"><?= $i ?></a></li>
                <?php endfor; ?>
            </ul></nav>
        </div>
        <?php endif; ?>
    </div>

    <!-- TARJETAS DE USUARIOS (MÓVIL) (resumido) -->
    <div class="vista-movil">
        <?php if (count($usuarios) > 0): ?>
            <div class="row g-3">
                <?php foreach ($usuarios as $u): ?>
                <div class="col-12">
                    <div class="card rounded-4 shadow-sm h-100">
                        <div class="card-body p-3">
                            <h6 class="fw-bold mb-1"><?= e($u['nombre']) ?></h6>
                            <p class="mb-1 small"><?= e($u['email']) ?></p>
                            <p class="mb-1 small">Cédula: <?= e($u['cedula'] ?? '—') ?></p>
                            <p class="mb-1 small">Rol: <span class="badge badge-info"><?= $u['rol'] ?></span></p>
                            <p class="mb-2 small">Estado: <span class="badge bg-<?= $u['estado']=='activo'?'success':'secondary' ?>"><?= ucfirst($u['estado']) ?></span></p>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <small class="text-muted"><?= date('d/m/Y', strtotime($u['fecha_creacion'])) ?></small>
                                <div class="d-flex gap-1">
                                    <?php if ($u['id_usuario'] == 1): ?>
                                        <span class="text-muted small me-2">Admin principal</span>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-warning rounded-pill" onclick="abrirModalEditar(<?= $u['id_usuario'] ?>)"><i class="fas fa-edit"></i></button>
                                        <?php if ($u['estado'] === 'activo'): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Desactivar este usuario? Se mantendrá el registro histórico.')">
                                            <?= csrfCampo() ?>
                                            <input type="hidden" name="id_usuario" value="<?= $u['id_usuario'] ?>">
                                            <button type="submit" name="desactivar" class="btn btn-sm btn-outline-danger rounded-pill" title="Desactivar usuario"><i class="fas fa-user-slash"></i></button>
                                        </form>
                                        <?php else: ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Reactivar este usuario?')">
                                            <?= csrfCampo() ?>
                                            <input type="hidden" name="id_usuario" value="<?= $u['id_usuario'] ?>">
                                            <button type="submit" name="reactivar" class="btn btn-sm btn-outline-success rounded-pill" title="Reactivar usuario"><i class="fas fa-user-check"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($total_pag_usuarios > 1): ?>
            <nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">
                <?php for ($i = 1; $i <= $total_pag_usuarios; $i++): ?>
                    <li class="page-item <?= $i == $pag_usuarios ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(['buscar' => $buscar, 'pag_usuarios' => $i]) ?>"><?= $i ?></a></li>
                <?php endfor; ?>
            </ul></nav>
            <?php endif; ?>
        <?php else: ?>
            <div class="text-center py-5 text-muted">No hay usuarios con ese criterio.</div>
        <?php endif; ?>
    </div>

    <!-- SECCIÓN DE SOLICITUDES PENDIENTES -->
    <hr class="my-4">
    <div id="seccion-pendientes">
        <h5 class="fw-bold mb-3"><i class="fas fa-clock me-2 text-warning"></i>Solicitudes de Registro Pendientes (<?= $num_pendientes ?>)</h5>
        <?php if (empty($pendientes)): ?>
            <div class="alert alert-info">No hay solicitudes pendientes.</div>
        <?php else: ?>
            <div class="card border-0 shadow-sm rounded-4 vista-escritorio">
                <div class="card-header bg-transparent border-0 py-3 d-flex justify-content-between">
                    <span><strong><?= $num_pendientes ?> pendientes</strong></span>
                    <small class="text-muted">Pág. <?= $pag_pendientes ?> de <?= $total_pag_pend ?: 1 ?></small>
                </div>
                <div class="card-body p-0" style="max-height: 50vh; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr class="text-white small text-uppercase"><th>Nombre</th><th>Email</th><th>Cédula</th><th>Rol</th><th>Fecha</th><th>Acción</th></td>
                        </thead>
                        <tbody>
                            <?php foreach ($pendientes as $p): ?>
                            <tr>
                                <td><?= e($p['nombre']) ?></td>
                                <td><?= e($p['email']) ?></td>
                                <td><?= e($p['cedula'] ?? '—') ?></td>
                                <td><span class="badge bg-info"><?= $p['rol'] ?></span></td>
                                <td><?= date('d/m/Y', strtotime($p['fecha_creacion'])) ?></td>
                                <td><button class="btn btn-sm btn-success rounded-pill" onclick="abrirModalSolicitud(<?= $p['id_usuario'] ?>)"><i class="fas fa-check me-1"></i> Gestionar</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pag_pend > 1): ?>
                <div class="card-footer bg-transparent border-0 py-2">
                    <nav><ul class="pagination pagination-sm justify-content-center mb-0">
                        <?php for ($i = 1; $i <= $total_pag_pend; $i++): ?>
                            <li class="page-item <?= $i == $pag_pendientes ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(['buscar' => $buscar, 'pag_pendientes' => $i]) ?>#seccion-pendientes"><?= $i ?></a></li>
                        <?php endfor; ?>
                    </ul></nav>
                </div>
                <?php endif; ?>
            </div>
            <div class="vista-movil">
                <div class="row g-3">
                    <?php foreach ($pendientes as $p): ?>
                    <div class="col-12">
                        <div class="card border-warning rounded-4 shadow-sm h-100">
                            <div class="card-body p-3">
                                <h6 class="fw-bold mb-1"><?= e($p['nombre']) ?></h6>
                                <p class="mb-1 small"><?= e($p['email']) ?></p>
                                <p class="mb-1 small">Cédula: <?= e($p['cedula'] ?? '—') ?></p>
                                <p class="mb-1 small">Rol: <span class="badge bg-info"><?= $p['rol'] ?></span></p>
                                <p class="mb-2 small">Fecha: <?= date('d/m/Y', strtotime($p['fecha_creacion'])) ?></p>
                                <div class="text-end"><button class="btn btn-sm btn-success rounded-pill" onclick="abrirModalSolicitud(<?= $p['id_usuario'] ?>)"><i class="fas fa-check me-1"></i> Gestionar</button></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($total_pag_pend > 1): ?>
                <nav class="mt-3"><ul class="pagination pagination-sm justify-content-center">
                    <?php for ($i = 1; $i <= $total_pag_pend; $i++): ?>
                        <li class="page-item <?= $i == $pag_pendientes ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(['buscar' => $buscar, 'pag_pendientes' => $i]) ?>#seccion-pendientes"><?= $i ?></a></li>
                    <?php endfor; ?>
                </ul></nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ===================== MODAL CREAR USUARIO ===================== -->
<div class="modal fade" id="modalCrear" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 rounded-4 shadow-lg">
            <div class="modal-header border-0"><h5><i class="fas fa-user-plus me-2"></i>Nuevo Usuario</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST" enctype="multipart/form-data" id="formCrear">
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="fw-bold">Nombre completo *</label><input type="text" name="nombre" id="crear_nombre" class="form-control" required placeholder="Ej: María José Pérez" data-filter="letters"><div class="invalid-feedback" id="error_nombre_crear"></div></div>
                        <div class="col-md-6"><label class="fw-bold">Email *</label><input type="email" name="email" id="crear_email" class="form-control" required placeholder="usuario@dominio.com"><div class="invalid-feedback" id="error_email_crear"></div></div>
                        <div class="col-md-6"><label class="fw-bold">Contraseña *</label><input type="password" name="clave" id="crear_clave" class="form-control" required minlength="6" placeholder="Mínimo 6 caracteres"><div class="invalid-feedback" id="error_clave_crear"></div></div>
                        <div class="col-md-6"><label class="fw-bold">Cédula *</label><input type="text" name="cedula" id="crear_cedula" class="form-control" maxlength="8" required placeholder="Ej: 12345678 (máx 8 dígitos)" data-filter="digits"><div class="invalid-feedback" id="error_cedula_crear"></div></div>
                        <div class="col-md-4"><label class="fw-bold">Teléfono *</label><input type="text" name="telefono" id="crear_telefono" class="form-control" maxlength="11" required placeholder="Ej: 04121234567 (11 dígitos)" data-filter="digits"><div class="invalid-feedback" id="error_telefono_crear"></div></div>
                        <div class="col-md-4"><label class="fw-bold">Cargo *</label><input type="text" name="cargo" id="crear_cargo" class="form-control" required placeholder="Ej: Jefe de Recepción" data-filter="letters-dash"><div class="invalid-feedback" id="error_cargo_crear"></div></div>
                        <div class="col-md-4"><label class="fw-bold">Rol *</label><select name="rol" id="crear_rol" class="form-select" required><option value="">Seleccione...</option><?php foreach($roles as $r): ?><option value="<?=$r?>"><?=ucfirst($r)?></option><?php endforeach; ?></select><div class="invalid-feedback" id="error_rol_crear"></div></div>
                        <div class="col-md-4"><label class="fw-bold">Estado</label><select name="estado" class="form-select"><option value="activo">Activo (puede iniciar sesión)</option><option value="inactivo">Inactivo (no puede iniciar sesión)</option></select></div>
                        <div class="col-12"><label>Dirección / Oficina</label><input type="text" name="direccion" class="form-control" placeholder="Ej: Edificio Principal, Oficina 101"></div>
                        <div class="col-md-6">
                            <label>Fecha de nacimiento</label>
                            <input type="text" name="fecha_nacimiento" id="crear_fecha_nac" class="form-control" maxlength="10" placeholder="DD-MM-AAAA" autocomplete="off" style="font-family: monospace;" onfocus="this.select()" oninput="validarFechaInput(this)">
                            <div class="invalid-feedback" style="color: #f87171;"></div>
                        </div>
                        <div class="col-md-6"><label>Foto de perfil</label><input type="file" name="foto" accept="image/*" class="form-control"><small class="text-muted">Opcional, máx 10 MB.</small></div>
                    </div>
                </div>
                <div class="modal-footer border-0"><button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancelar</button><button type="submit" name="crear" class="btn btn-primary rounded-pill">Crear Usuario</button></div>
            </form>
        </div>
    </div>
</div>

<!-- ===================== MODALES DE EDICIÓN ===================== -->
<?php foreach ($usuarios as $u): if ($u['id_usuario'] == 1) continue; ?>
<div class="modal fade" id="modalEditar<?= $u['id_usuario'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 rounded-4 shadow-lg">
            <div class="modal-header border-0"><h5><i class="fas fa-user-edit me-2"></i>Editar Usuario: <?= e($u['nombre']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST" enctype="multipart/form-data" class="formEditar" data-id="<?= $u['id_usuario'] ?>">
                <input type="hidden" name="id_usuario" value="<?= $u['id_usuario'] ?>">
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="fw-bold">Nombre completo *</label><input type="text" name="nombre" value="<?= htmlspecialchars($u['nombre']) ?>" class="form-control" required data-filter="letters"></div>
                        <div class="col-md-6"><label class="fw-bold">Email</label><input type="email" class="form-control" value="<?= htmlspecialchars($u['email']) ?>" readonly></div>
                        <div class="col-md-4"><label class="fw-bold">Cédula *</label><input type="text" name="cedula" value="<?= htmlspecialchars($u['cedula'] ?? '') ?>" class="form-control" maxlength="8" required placeholder="12345678" data-filter="digits"><div class="invalid-feedback" id="error_cedula_edit_<?= $u['id_usuario'] ?>"></div></div>
                        <div class="col-md-4"><label class="fw-bold">Teléfono *</label><input type="text" name="telefono" value="<?= htmlspecialchars($u['telefono'] ?? '') ?>" class="form-control" maxlength="11" required placeholder="04121234567 (11 dígitos)" data-filter="digits"></div>
                        <div class="col-md-4"><label class="fw-bold">Cargo *</label><input type="text" name="cargo" value="<?= htmlspecialchars($u['cargo'] ?? '') ?>" class="form-control" required placeholder="Jefe de Recepción" data-filter="letters-dash"></div>
                        <div class="col-12"><label>Dirección</label><input type="text" name="direccion" value="<?= htmlspecialchars($u['direccion'] ?? '') ?>" class="form-control"></div>
                        <div class="col-md-6">
                            <label>Fecha nacimiento</label>
                            <input type="text" name="fecha_nacimiento" id="edit_fecha_nac_<?= $u['id_usuario'] ?>" class="form-control" maxlength="10" placeholder="DD-MM-AAAA" autocomplete="off" style="font-family: monospace;" onfocus="this.select()" oninput="validarFechaInput(this)" value="<?= $u['fecha_nacimiento'] ? date('d-m-Y', strtotime($u['fecha_nacimiento'])) : '' ?>">
                            <div class="invalid-feedback" style="color: #f87171;"></div>
                        </div>
                        <div class="col-md-6"><label>Foto</label><input type="file" name="foto" accept="image/*" class="form-control"><input type="hidden" name="foto_actual" value="<?= htmlspecialchars($u['foto'] ?? '') ?>"><?php if(!empty($u['foto'])): ?><img src="../../<?= e($u['foto']) ?>" class="rounded mt-2" style="width:60px;"><?php endif; ?></div>
                        <div class="col-md-4"><label class="fw-bold">Rol *</label><select name="rol" class="form-select"><?php foreach($roles as $r): ?><option value="<?=$r?>" <?= $u['rol']==$r?'selected':'' ?>><?=ucfirst($r)?></option><?php endforeach; ?></select></div>
                        <div class="col-md-4"><label class="fw-bold">Estado</label><select name="estado" class="form-select"><option value="activo" <?= $u['estado']=='activo'?'selected':'' ?>>Activo (puede iniciar sesión)</option><option value="inactivo" <?= $u['estado']=='inactivo'?'selected':'' ?>>Inactivo (no puede iniciar sesión)</option></select></div>
                        <div class="col-md-4"><label>Nueva contraseña</label><input type="password" name="nueva_clave" class="form-control" placeholder="Dejar vacío para no cambiar"><small class="text-muted">Mínimo 6 caracteres.</small></div>
                    </div>
                </div>
                <div class="modal-footer border-0"><button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancelar</button><button type="submit" name="editar" class="btn btn-primary rounded-pill">Guardar Cambios</button></div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- ===================== MODALES DE SOLICITUD ===================== -->
<?php foreach ($pendientes as $p): ?>
<div class="modal fade" id="modalSolicitud<?= $p['id_usuario'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow-lg">
            <div class="modal-header border-0"><h5 class="fw-bold"><i class="fas fa-user-clock me-2 text-warning"></i>Solicitud de Registro</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p><strong>Nombre:</strong> <?= e($p['nombre']) ?></p><p><strong>Email:</strong> <?= e($p['email']) ?></p><p><strong>Cédula:</strong> <?= e($p['cedula'] ?? '—') ?></p><p><strong>Teléfono:</strong> <?= e($p['telefono'] ?? '—') ?></p><p><strong>Cargo:</strong> <?= e($p['cargo'] ?? '—') ?></p><p><strong>Dirección:</strong> <?= e($p['direccion'] ?? '—') ?></p><p><strong>Rol solicitado:</strong> <span class="badge bg-info"><?= $p['rol'] ?></span></p><p><strong>Fecha solicitud:</strong> <?= date('d/m/Y H:i', strtotime($p['fecha_creacion'])) ?></p>
            </div>
            <div class="modal-footer border-0"><form method="POST" class="d-inline"><input type="hidden" name="id_usuario" value="<?= $p['id_usuario'] ?>"><button type="submit" name="rechazar" class="btn btn-danger rounded-pill">Rechazar</button><button type="submit" name="aprobar" class="btn btn-success rounded-pill ms-2">Aprobar</button></form></div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
function abrirModalEditar(id) { new bootstrap.Modal(document.getElementById('modalEditar' + id)).show(); }
function abrirModalSolicitud(id) { new bootstrap.Modal(document.getElementById('modalSolicitud' + id)).show(); }

// Función mejorada para autoformatear fecha en DD-MM-AAAA
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
    let feedback = input.parentElement.querySelector('.invalid-feedback');
    if (formateado !== "" && !regex.test(formateado)) {
        input.classList.add('is-invalid');
        if (feedback) feedback.textContent = "Formato inválido. Use DD-MM-AAAA (ej: 20-05-1990).";
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
            if (feedback) feedback.textContent = "Fecha no válida (día 01-31, mes 01-12, año 1900-"+anioActual+").";
        } else {
            input.classList.remove('is-invalid');
            if (feedback) feedback.textContent = "";
        }
    } else {
        input.classList.remove('is-invalid');
        if (feedback) feedback.textContent = "";
    }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>