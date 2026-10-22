<?php
require_once __DIR__ . '/../../core/conexion.php';
restringirAcceso(['administrador']);

$id_activo = $_GET['id'] ?? 0;
if (!$id_activo) { header("Location: lista.php"); exit; }

$stmt = $conexion->prepare("SELECT * FROM activos WHERE id_activo = ?");
$stmt->execute([$id_activo]);
$activo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$activo) { header("Location: lista.php"); exit; }

// =========================================================
// PROCESAR GUARDADO ANTES DE CUALQUIER SALIDA HTML
// =========================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['guardar_cambios'])) {
    $codigo_udo    = strtoupper(limpiar($_POST['codigo_udo']));
    $tipo_equipo   = $_POST['tipo_equipo'];
    $marca         = strtoupper(limpiar($_POST['marca']));
    $modelo        = strtoupper(limpiar($_POST['modelo']));
    $serial        = strtoupper(limpiar($_POST['serial']));
    $ubicacion     = limpiar($_POST['ubicacion']);
    $departamento  = limpiar($_POST['departamento'] ?? '');
    $responsable   = limpiar($_POST['responsable_actual'] ?? '');
    $ci_resp       = limpiar($_POST['cedula_responsable'] ?? '');
    $fecha_adq     = $_POST['fecha_adquisicion'] ?? null;
    $valor         = floatval($_POST['valor_compra'] ?? 0);
    $estado        = $_POST['estado_equipo'];
    $observaciones = limpiar($_POST['observaciones'] ?? '');

    // Validar duplicado de código (excluyendo el mismo ID)
    $check = $conexion->prepare("SELECT id_activo FROM activos WHERE codigo_udo = ? AND id_activo != ?");
    $check->execute([$codigo_udo, $id_activo]);
    $error_form = '';
    if ($check->rowCount() > 0) {
        $error_form = "El código UDO ya pertenece a otro equipo.";
    } elseif (empty($codigo_udo) || empty($tipo_equipo) || empty($marca) || empty($modelo) || empty($serial)) {
        $error_form = "Complete todos los campos obligatorios (*).";
    }

    $foto_url = $activo['foto_url']; // mantener actual por defecto

    if (empty($error_form)) {
        // Procesar nueva imagen si se subió
        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
            $errImg = '';
            $nuevaImg = procesarImagenSubida($_FILES['imagen'], 'equipos', 'equipo', $errImg, 1600, 10);
            if ($nuevaImg) {
                borrarImagenSubida($activo['foto_url'] ?? '');
                $foto_url = $nuevaImg;
            } else { $error_form = $errImg; }
        }
    }

    if (empty($error_form)) {
        $stmtUpd = $conexion->prepare("UPDATE activos SET 
            codigo_udo=?, tipo_equipo=?, marca=?, modelo=?, serial=?, 
            ubicacion=?, departamento=?, responsable_actual=?, cedula_responsable=?,
            fecha_adquisicion=?, valor_compra=?, estado_equipo=?, observaciones=?, foto_url=?,
            ultima_modificacion=NOW(), modificado_por=?
            WHERE id_activo=?");
        $stmtUpd->execute([
            $codigo_udo, $tipo_equipo, $marca, $modelo, $serial,
            $ubicacion, $departamento, $responsable, $ci_resp,
            $fecha_adq, $valor, $estado, $observaciones, $foto_url,
            $_SESSION['usuario_id'], $id_activo
        ]);
        registrarAuditoria($conexion, "Editó activo $codigo_udo", "activos", $id_activo);
        header("Location: detalle.php?id=$id_activo&msj=editado");
        exit;
    }
    // Si hay error, lo guardamos en sesión para mostrarlo en el formulario
    $_SESSION['flash_error'] = $error_form;
    // No redirigimos, simplemente recargamos la página para mostrar el error
    header("Location: editar.php?id=$id_activo");
    exit;
}

// =========================================================
// MOSTRAR FORMULARIO (solo GET o tras error)
// =========================================================
include __DIR__ . '/../../includes/header.php';

$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);
?>

<div class="container-fluid animate__animated animate__fadeIn">
    <div class="row"><div class="col-lg-10 mx-auto">
        <h3 class="fw-bold mb-4"><i class="fas fa-edit me-2" style="color:var(--accent-cyan);"></i>Editar Activo: <?= htmlspecialchars($activo['codigo_udo']) ?></h3>

        <?php if($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="row g-4">
            <?= csrfCampo() ?>
            <!-- Código UDO y Tipo -->
            <div class="col-md-6">
                <label class="form-label fw-bold">Código UDO *</label>
                <input type="text" name="codigo_udo" class="form-control form-control-lg" value="<?= e($activo['codigo_udo']) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">Tipo de Equipo *</label>
                <select name="tipo_equipo" class="form-select form-select-lg" required>
                    <?php $tipos = ['Laptop','Desktop','Monitor','Impresora','Servidor','Switch','Router','Otro'];
                    foreach($tipos as $t): ?>
                    <option value="<?= $t ?>" <?= $activo['tipo_equipo'] == $t ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Marca, Modelo, Serial -->
            <div class="col-md-4">
                <label class="form-label fw-bold">Marca *</label>
                <input type="text" name="marca" class="form-control" value="<?= htmlspecialchars($activo['marca']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Modelo *</label>
                <input type="text" name="modelo" class="form-control" value="<?= htmlspecialchars($activo['modelo']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Serial *</label>
                <input type="text" name="serial" class="form-control" value="<?= htmlspecialchars($activo['serial']) ?>" required>
            </div>

            <!-- Ubicación y Departamento -->
            <div class="col-md-6">
                <label class="form-label fw-bold">Ubicación *</label>
                <input type="text" name="ubicacion" class="form-control" value="<?= htmlspecialchars($activo['ubicacion']) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">Departamento</label>
                <input type="text" name="departamento" class="form-control" value="<?= htmlspecialchars($activo['departamento']) ?>">
            </div>

            <!-- Responsable -->
            <div class="col-md-6">
                <label class="form-label fw-bold">Responsable Actual</label>
                <input type="text" name="responsable_actual" class="form-control" value="<?= htmlspecialchars($activo['responsable_actual']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold">Cédula Responsable</label>
                <input type="text" name="cedula_responsable" class="form-control" value="<?= htmlspecialchars($activo['cedula_responsable']) ?>">
            </div>

            <!-- Fechas y valor -->
            <div class="col-md-4">
                <label class="form-label fw-bold">Fecha Adquisición</label>
                <input type="date" name="fecha_adquisicion" class="form-control" value="<?= $activo['fecha_adquisicion'] ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Valor Compra</label>
                <input type="number" step="0.01" name="valor_compra" class="form-control" value="<?= $activo['valor_compra'] ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold">Estado</label>
                <select name="estado_equipo" class="form-select">
                    <?php $estados = ['Operativo','En Recepción','En Reparación','Reparado','Baja Técnica','Desincorporado'];
                    foreach($estados as $e): ?>
                    <option value="<?= $e ?>" <?= $activo['estado_equipo'] == $e ? 'selected' : '' ?>><?= $e ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Imagen -->
            <div class="col-md-12">
                <label class="form-label fw-bold">Imagen del Equipo (opcional, 10 MB máx.)</label>
                <input type="file" name="imagen" accept="image/*" class="form-control" onchange="previewImagen(event)">
                <?php if (!empty($activo['foto_url'])): ?>
                <div class="mt-2" id="previewContainer">
                    <img id="imgPreview" src="../../<?= e($activo['foto_url']) ?>" class="img-fluid rounded" style="max-height:120px;">
                </div>
                <?php else: ?>
                <div class="mt-2" id="previewContainer" style="display:none;">
                    <img id="imgPreview" src="" class="img-fluid rounded" style="max-height:120px;">
                </div>
                <?php endif; ?>
            </div>

            <!-- Observaciones -->
            <div class="col-md-12">
                <label class="form-label fw-bold">Observaciones</label>
                <textarea name="observaciones" class="form-control" rows="3"><?= htmlspecialchars($activo['observaciones']) ?></textarea>
            </div>

            <div class="col-12 mt-3">
                <a href="detalle.php?id=<?= $id_activo ?>" class="btn btn-outline-secondary rounded-pill me-2">Cancelar</a>
                <button type="submit" name="guardar_cambios" class="btn btn-primary rounded-pill px-5"><i class="fas fa-save me-1"></i> Guardar Cambios</button>
            </div>
        </form>
    </div></div>
</div>

<script>
function previewImagen(event) {
    const reader = new FileReader();
    reader.onload = function(e) {
        const preview = document.getElementById('imgPreview');
        const container = document.getElementById('previewContainer');
        if (preview) {
            preview.src = e.target.result;
            container.style.display = 'block';
        }
    }
    if (event.target.files.length > 0) {
        reader.readAsDataURL(event.target.files[0]);
    }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>