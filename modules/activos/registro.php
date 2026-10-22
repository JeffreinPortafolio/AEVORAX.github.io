<?php
require_once __DIR__ . '/../../core/conexion.php';
restringirAcceso(['administrador']);
include __DIR__ . '/../../includes/header.php';

$mensaje = ''; $error = '';

// Sugerencia de código UDO (autoincrementable)
$num = 0;
$ultimo = $conexion->query("SELECT codigo_udo FROM activos ORDER BY id_activo DESC LIMIT 1")->fetch();
$sugerencia = '';
if ($ultimo) {
    $num = (int) substr($ultimo['codigo_udo'], -3);
    $sugerencia = "UDO-COMP-" . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
} else {
    $sugerencia = "UDO-COMP-001";
}

// Procesar formulario
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['registrar_activo'])) {
    // Sanitización
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
    $observaciones = limpiar($_POST['observaciones'] ?? '');

    $errores = [];

    // Validar código UDO no vacío
    if (empty($codigo_udo)) $errores[] = "El Código UDO es obligatorio.";
    else {
        // Verificar duplicado
        $check = $conexion->prepare("SELECT id_activo FROM activos WHERE codigo_udo = ?");
        $check->execute([$codigo_udo]);
        if ($check->rowCount() > 0) $errores[] = "El código UDO <strong>$codigo_udo</strong> ya existe.";
    }

    // Tipo equipo
    if (empty($tipo_equipo)) $errores[] = "Debe seleccionar un Tipo de Equipo.";

    // Marca, modelo, serial
    if (empty($marca)) $errores[] = "La Marca es obligatoria.";
    elseif (!preg_match("/^[a-zA-Z0-9\s\-\.]+$/", $marca)) $errores[] = "La Marca solo permite letras, números, espacios, guiones y puntos.";
    
    if (empty($modelo)) $errores[] = "El Modelo es obligatorio.";
    elseif (!preg_match("/^[a-zA-Z0-9\s\-\.]+$/", $modelo)) $errores[] = "El Modelo solo permite letras, números, espacios, guiones y puntos.";
    
    if (empty($serial)) $errores[] = "El Número de Serial es obligatorio.";
    elseif (!preg_match("/^[a-zA-Z0-9\s\-]+$/", $serial)) $errores[] = "El Serial solo permite letras, números, espacios y guiones.";

    if (empty($ubicacion)) $errores[] = "La Ubicación es obligatoria.";

    // Validación de cédula: solo números, máximo 8 dígitos (opcional)
    if (!empty($ci_resp)) {
        if (!ctype_digit($ci_resp)) $errores[] = "La Cédula debe contener solo números.";
        elseif (strlen($ci_resp) > 8) $errores[] = "La Cédula no puede tener más de 8 dígitos.";
    }

    // Validación valor de compra: no negativo
    if ($valor < 0) $errores[] = "El valor de compra no puede ser negativo.";

    // Si hay errores, mostrar
    if (!empty($errores)) {
        $error = implode("<br>", $errores);
    } else {
        // Procesar imagen
        $foto_url = '';
        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
            $errImg = '';
            $foto_url = procesarImagenSubida($_FILES['imagen'], 'equipos', 'equipo', $errImg, 1600, 10) ?? '';
            if ($foto_url === '') { $error = $errImg; }
        }

        if (empty($error)) {
            $stmt = $conexion->prepare("INSERT INTO activos 
                (codigo_udo, tipo_equipo, marca, modelo, serial, ubicacion, departamento, responsable_actual, cedula_responsable, fecha_adquisicion, valor_compra, observaciones, foto_url, registrado_por)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$codigo_udo, $tipo_equipo, $marca, $modelo, $serial, $ubicacion, $departamento, $responsable, $ci_resp, $fecha_adq, $valor, $observaciones, $foto_url, $_SESSION['usuario_id']]);
            registrarAuditoria($conexion, "Registró activo: $codigo_udo", "activos", $conexion->lastInsertId());
            $mensaje = "✅ Activo <strong>$codigo_udo</strong> registrado con éxito.";
            // Limpiar formulario
            $_POST = [];
            // Nueva sugerencia
            $num++;
            $sugerencia = "UDO-COMP-" . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
        }
    }
}
?>

<?php
$tipos_ico = [
    'Laptop' => 'fa-laptop', 'Desktop' => 'fa-desktop', 'Monitor' => 'fa-tv', 'Impresora' => 'fa-print',
    'Servidor' => 'fa-server', 'Switch' => 'fa-network-wired', 'Router' => 'fa-wifi', 'Otro' => 'fa-box',
];
?>
<div class="container-fluid animate__animated animate__fadeIn px-2 px-md-3">
    <div class="form-wrap">
        <div class="page-header mb-3">
            <div>
                <h3 class="fw-bold mb-0"><i class="fas fa-plus-circle me-2" style="color:var(--accent-cyan);"></i>Registro de Activo</h3>
                <p class="text-muted small mt-1 mb-0">Completa los datos del equipo. Los campos con <span class="text-info fw-bold">*</span> son obligatorios.</p>
            </div>
            <a href="lista.php" class="btn btn-outline-secondary btn-sm d-none d-sm-inline-flex"><i class="fas fa-arrow-left me-1"></i> Inventario</a>
        </div>

        <?php if($mensaje): ?><div class="alert alert-success"><?= $mensaje ?> <a href="lista.php" class="alert-link ms-1">Ver inventario</a></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="formRegistroActivo" novalidate>
            <?= csrfCampo() ?>

            <!-- 1. Tipo de equipo (botones grandes táctiles) -->
            <section class="form-sec card border-0">
                <h5 class="sec-title"><span class="sec-n">1</span>Tipo de equipo <span class="req">*</span></h5>
                <div class="type-grid" id="tipoGrid">
                    <?php foreach ($tipos_ico as $tipo => $ico): ?>
                    <label class="type-tile">
                        <input type="radio" name="tipo_equipo" value="<?= e($tipo) ?>" required <?= ($_POST['tipo_equipo'] ?? '') === $tipo ? 'checked' : '' ?>>
                        <span><i class="fas <?= $ico ?>"></i><b><?= e($tipo) ?></b></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="field-err" data-err-for="tipo_equipo">Selecciona un tipo de equipo.</div>
            </section>

            <!-- 2. Identificación -->
            <section class="form-sec card border-0">
                <h5 class="sec-title"><span class="sec-n">2</span>Identificación</h5>
                <div class="fgrid">
                    <div class="f f-full">
                        <label class="form-label is-required" for="codigo_udo">Código de inventario UDO</label>
                        <input type="text" name="codigo_udo" id="codigo_udo" class="form-control up" autocapitalize="characters" autocomplete="off"
                               value="<?= e($_POST['codigo_udo'] ?? $sugerencia) ?>" placeholder="UDO-COMP-001" required>
                        <small class="hint">Código único. Ya viene sugerido el siguiente disponible.</small>
                    </div>
                    <div class="f">
                        <label class="form-label is-required" for="marca">Marca</label>
                        <input type="text" name="marca" id="marca" class="form-control up" autocapitalize="characters" autocomplete="off"
                               value="<?= e($_POST['marca'] ?? '') ?>" placeholder="DELL, HP, LENOVO" required data-filter="alnum-dot">
                        <div class="invalid-feedback">Solo letras, números, espacios, guiones y puntos.</div>
                    </div>
                    <div class="f">
                        <label class="form-label is-required" for="modelo">Modelo</label>
                        <input type="text" name="modelo" id="modelo" class="form-control up" autocapitalize="characters" autocomplete="off"
                               value="<?= e($_POST['modelo'] ?? '') ?>" placeholder="LATITUDE 5420" required data-filter="alnum-dot">
                        <div class="invalid-feedback">Solo letras, números, espacios, guiones y puntos.</div>
                    </div>
                    <div class="f f-full">
                        <label class="form-label is-required" for="serial">Número de serial (S/N)</label>
                        <input type="text" name="serial" id="serial" class="form-control up" autocapitalize="characters" autocomplete="off"
                               value="<?= e($_POST['serial'] ?? '') ?>" placeholder="ABC123XYZ" required data-filter="alnum-dash">
                        <div class="invalid-feedback">Solo letras, números, espacios y guiones.</div>
                    </div>
                </div>
            </section>

            <!-- 3. Ubicación y responsable -->
            <section class="form-sec card border-0">
                <h5 class="sec-title"><span class="sec-n">3</span>Ubicación y responsable</h5>
                <div class="fgrid">
                    <div class="f">
                        <label class="form-label is-required" for="ubicacion">Ubicación / Laboratorio</label>
                        <input type="text" name="ubicacion" id="ubicacion" class="form-control" autocomplete="off"
                               value="<?= e($_POST['ubicacion'] ?? '') ?>" placeholder="Laboratorio de Redes" required>
                        <div class="invalid-feedback">La ubicación es obligatoria.</div>
                    </div>
                    <div class="f">
                        <label class="form-label" for="departamento">Departamento / Facultad</label>
                        <input type="text" name="departamento" id="departamento" class="form-control" autocomplete="off"
                               value="<?= e($_POST['departamento'] ?? '') ?>" placeholder="Ingeniería en Computación">
                    </div>
                    <div class="f">
                        <label class="form-label" for="responsable_actual">Responsable actual</label>
                        <input type="text" name="responsable_actual" id="responsable_actual" class="form-control" autocomplete="off"
                               value="<?= e($_POST['responsable_actual'] ?? '') ?>" placeholder="Nombre del encargado">
                    </div>
                    <div class="f">
                        <label class="form-label" for="cedula_resp">Cédula del responsable</label>
                        <input type="text" name="cedula_responsable" id="cedula_resp" class="form-control" inputmode="numeric" pattern="[0-9]*"
                               value="<?= e($_POST['cedula_responsable'] ?? '') ?>" placeholder="12345678" maxlength="8" data-filter="digits" autocomplete="off">
                        <div class="invalid-feedback">Solo números, máximo 8 dígitos.</div>
                    </div>
                </div>
            </section>

            <!-- 4. Adquisición -->
            <section class="form-sec card border-0">
                <h5 class="sec-title"><span class="sec-n">4</span>Adquisición <small class="text-muted fw-normal">(opcional)</small></h5>
                <div class="fgrid">
                    <div class="f">
                        <label class="form-label" for="fecha_adquisicion">Fecha de adquisición</label>
                        <input type="date" name="fecha_adquisicion" id="fecha_adquisicion" class="form-control" max="<?= date('Y-m-d') ?>"
                               value="<?= e($_POST['fecha_adquisicion'] ?? '') ?>">
                    </div>
                    <div class="f">
                        <label class="form-label" for="valor_compra">Valor de compra ($/Bs)</label>
                        <input type="number" step="0.01" min="0" inputmode="decimal" name="valor_compra" id="valor_compra" class="form-control"
                               value="<?= e($_POST['valor_compra'] ?? '') ?>" placeholder="0.00">
                        <div class="invalid-feedback">El valor no puede ser negativo.</div>
                    </div>
                </div>
            </section>

            <!-- 5. Foto y notas -->
            <section class="form-sec card border-0">
                <h5 class="sec-title"><span class="sec-n">5</span>Foto y notas <small class="text-muted fw-normal">(opcional)</small></h5>
                <input type="file" name="imagen" id="imagen" accept="image/*" hidden>
                <label for="imagen" class="photo-drop" id="photoDrop">
                    <span class="photo-empty" id="photoEmpty">
                        <i class="fas fa-camera"></i>
                        <b>Tomar o elegir foto</b>
                        <small>JPG, PNG o GIF · máx. 10 MB</small>
                    </span>
                    <span class="photo-has" id="photoHas" hidden>
                        <img id="imgPreview" src="" alt="Vista previa">
                        <span class="photo-name" id="photoName"></span>
                    </span>
                </label>
                <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="photoClear" hidden><i class="fas fa-trash-alt me-1"></i>Quitar foto</button>
                <div class="field-err" id="photoErr"></div>

                <div class="f f-full mt-3">
                    <label class="form-label" for="observaciones">Observaciones / notas</label>
                    <textarea name="observaciones" id="observaciones" class="form-control" rows="3"
                              placeholder="Estado físico, condiciones especiales, accesorios..."><?= e($_POST['observaciones'] ?? '') ?></textarea>
                </div>
            </section>

            <div class="savebar">
                <a href="lista.php" class="btn btn-outline-secondary">Cancelar</a>
                <button type="submit" name="registrar_activo" class="btn btn-primary"><i class="fas fa-save me-2"></i>Registrar activo</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('formRegistroActivo');
    var RULES = {
        marca:  /^[a-zA-Z0-9\s\-\.]+$/,
        modelo: /^[a-zA-Z0-9\s\-\.]+$/,
        serial: /^[a-zA-Z0-9\s\-]+$/
    };
    function $(id) { return document.getElementById(id); }

    function check(el) {
        var v = el.value.trim(), ok = true;
        if (el.required && v === '') ok = false;
        else if (RULES[el.id] && v !== '' && !RULES[el.id].test(v)) ok = false;
        else if (el.id === 'cedula_resp' && v !== '' && !/^\d{1,8}$/.test(v)) ok = false;
        else if (el.id === 'valor_compra' && v !== '' && parseFloat(v) < 0) ok = false;
        el.classList.toggle('is-invalid', !ok);
        return ok;
    }
    ['codigo_udo','marca','modelo','serial','ubicacion','cedula_resp','valor_compra'].forEach(function (id) {
        var el = $(id);
        el.addEventListener('blur', function () { check(el); });
        el.addEventListener('input', function () { if (el.classList.contains('is-invalid')) check(el); });
    });

    var tipoErr = document.querySelector('[data-err-for="tipo_equipo"]');
    form.querySelectorAll('input[name="tipo_equipo"]').forEach(function (r) {
        r.addEventListener('change', function () { tipoErr.classList.remove('show'); });
    });

    // Foto: vista previa, tamaño máximo y quitar
    var file = $('imagen'), err = $('photoErr');
    function resetPhoto() {
        file.value = ''; $('photoEmpty').hidden = false; $('photoHas').hidden = true; $('photoClear').hidden = true; $('imgPreview').removeAttribute('src');
    }
    file.addEventListener('change', function () {
        err.textContent = ''; err.classList.remove('show');
        var f = file.files[0];
        if (!f) return resetPhoto();
        if (f.size > 10 * 1024 * 1024) {
            err.textContent = 'La imagen supera los 10 MB.'; err.classList.add('show'); return resetPhoto();
        }
        var rd = new FileReader();
        rd.onload = function (e) {
            $('imgPreview').src = e.target.result; $('photoName').textContent = f.name;
            $('photoEmpty').hidden = true; $('photoHas').hidden = false; $('photoClear').hidden = false;
        };
        rd.readAsDataURL(f);
    });
    $('photoClear').addEventListener('click', resetPhoto);

    form.addEventListener('submit', function (e) {
        var firstBad = null;
        if (!form.querySelector('input[name="tipo_equipo"]:checked')) {
            tipoErr.classList.add('show'); firstBad = $('tipoGrid');
        }
        ['codigo_udo','marca','modelo','serial','ubicacion','cedula_resp','valor_compra'].forEach(function (id) {
            if (!check($(id)) && !firstBad) firstBad = $(id);
        });
        if (firstBad) {
            e.preventDefault();
            firstBad.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (firstBad.focus && firstBad.tagName === 'INPUT') firstBad.focus({ preventScroll: true });
        }
    });
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
