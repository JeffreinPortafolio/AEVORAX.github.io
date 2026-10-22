<?php
require_once __DIR__ . '/../../core/conexion.php';
require_once __DIR__ . '/../../includes/formato_documento.php';
restringirAcceso(['administrador', 'tecnico', 'consultor']);

$esAdmin = ($_SESSION['rol'] === 'administrador');
$esTecnico = ($_SESSION['rol'] === 'tecnico');

// =========================================================================
// ACCIONES POST (Sólo Administrador)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarCsrfToken($_POST['csrf_token'] ?? '')) {
        flash('error', 'Token de seguridad inválido o expirado. Por favor, reintente.');
        header("Location: bajas.php");
        exit;
    }

    $accion = $_POST['accion'] ?? '';

    // 1. APROBAR BAJA TÉCNICA Y EMITIR OFICIO OFICIAL
    if ($accion === 'aprobar_baja') {
        if (!$esAdmin) {
            flash('error', 'Sólo el Administrador (Jefe de Delegación) tiene potestad para aprobar bajas y emitir oficios oficiales de exclusión.');
            header("Location: bajas.php");
            exit;
        }

        $id_activo = (int)($_POST['id_activo'] ?? 0);
        $id_novedad = (int)($_POST['id_novedad'] ?? 0);
        $observaciones_jefatura = trim($_POST['observaciones_jefatura'] ?? '');

        // Obtener activo y novedad
        $stmtAct = $conexion->prepare("SELECT * FROM activos WHERE id_activo = ? AND estado_equipo = 'Baja Técnica'");
        $stmtAct->execute([$id_activo]);
        $activo = $stmtAct->fetch();

        $stmtNov = $conexion->prepare("SELECT * FROM novedades WHERE id_novedad = ? AND id_activo = ?");
        $stmtNov->execute([$id_novedad, $id_activo]);
        $novedad = $stmtNov->fetch();

        if (!$activo || !$novedad) {
            flash('error', 'El activo no se encuentra en estado de Baja Técnica pendiente o los datos son inválidos.');
            header("Location: bajas.php");
            exit;
        }

        // Generar correlativo oficial SIGA-EX-YYYY-XXXX
        $anio = date('Y');
        $stmtCount = $conexion->prepare("SELECT COUNT(*) FROM oficios WHERE numero_oficio LIKE ?");
        $stmtCount->execute(["SIGA-EX-$anio-%"]);
        $correlativo = str_pad($stmtCount->fetchColumn() + 1, 4, '0', STR_PAD_LEFT);
        $numero_oficio = "SIGA-EX-$anio-$correlativo";

        // Armar dictamen técnico consolidado
        $dictamen_final = $novedad['diagnostico_tecnico'] ?: 'Dictamen técnico emitido: El equipo presenta fallas irreversibles y no resulta económica ni técnicamente recuperable.';
        if (!empty($observaciones_jefatura)) {
            $dictamen_final .= "\n\n[RESOLUCIÓN DE JEFATURA]: " . $observaciones_jefatura;
        }

        // Insertar Oficio
        $stmtOficio = $conexion->prepare("
            INSERT INTO oficios 
            (numero_oficio, id_activo, id_novedad, motivo_baja, causa_especifica, porcentaje_costo_reparacion, dictamen_tecnico, foto_evidencia, generado_por, fecha_generacion, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'Aprobado')
        ");
        $stmtOficio->execute([
            $numero_oficio,
            $id_activo,
            $id_novedad,
            $novedad['motivo_baja'] ?: 'Obsolescencia y deterioro severo de componentes principales',
            $novedad['causa_especifica'] ?: 'Falla no recuperable / Costo supera valor residual',
            $novedad['porcentaje_costo_reparacion'],
            $dictamen_final,
            $novedad['foto_evidencia'],
            $_SESSION['usuario_id']
        ]);
        $id_oficio = $conexion->lastInsertId();

        // Actualizar Activo a Desincorporado
        $stmtUpAct = $conexion->prepare("UPDATE activos SET estado_equipo = 'Desincorporado', ultima_modificacion = NOW(), modificado_por = ? WHERE id_activo = ?");
        $stmtUpAct->execute([$_SESSION['usuario_id'], $id_activo]);

        // Actualizar Novedad
        $stmtUpNov = $conexion->prepare("UPDATE novedades SET estado_final = 'No Reparable', solucion_aplicada = CONCAT(COALESCE(solucion_aplicada, ''), ' [Oficio de Exclusión Aprobado: ', ?, ']'), fecha_fin_reparacion = NOW() WHERE id_novedad = ?");
        $stmtUpNov->execute([$numero_oficio, $id_novedad]);

        // Auditoría
        registrarAuditoria($conexion, "Aprobación de Baja Técnica y Emisión de Oficio de Exclusión $numero_oficio", 'oficios', $id_oficio, null, json_encode([
            'codigo_udo' => $activo['codigo_udo'],
            'numero_oficio' => $numero_oficio
        ]));

        flash('mensaje', "¡Baja técnica aprobada exitosamente! Se emitió el Oficio de Exclusión <strong>$numero_oficio</strong> y el equipo pasó a estado <strong>Desincorporado</strong>.");
        header("Location: bajas.php?tab=historico&nuevo_oficio=$id_oficio");
        exit;
    }

    // 2. REGISTRAR DISPOSICIÓN FINAL DEL EQUIPO DESINCORPORADO
    if ($accion === 'registrar_disposicion') {
        if (!$esAdmin) {
            flash('error', 'Sólo el Administrador puede registrar la disposición final de bienes desincorporados.');
            header("Location: bajas.php?tab=historico");
            exit;
        }

        $id_oficio = (int)($_POST['id_oficio'] ?? 0);
        $disposicion_final = trim($_POST['disposicion_final'] ?? '');
        $fecha_disposicion = !empty($_POST['fecha_disposicion']) ? $_POST['fecha_disposicion'] : date('Y-m-d H:i:s');
        $responsable_disposicion = trim($_POST['responsable_disposicion'] ?? '');

        if ($id_oficio > 0 && !empty($disposicion_final)) {
            $stmtDisp = $conexion->prepare("UPDATE oficios SET disposicion_final = ?, fecha_disposicion = ?, responsable_disposicion = ? WHERE id_oficio = ?");
            $stmtDisp->execute([$disposicion_final, $fecha_disposicion, $responsable_disposicion, $id_oficio]);

            registrarAuditoria($conexion, "Registro de Disposición Final en Oficio #$id_oficio", 'oficios', $id_oficio, null, json_encode([
                'disposicion' => $disposicion_final,
                'responsable' => $responsable_disposicion
            ]));

            flash('mensaje', 'Disposición final del bien desincorporado registrada correctamente.');
        } else {
            flash('error', 'Debe especificar el destino o disposición final del bien.');
        }

        header("Location: bajas.php?tab=historico");
        exit;
    }
}

// =========================================================================
// MODO IMPRESIÓN OFICIAL DE OFICIO DE EXCLUSIÓN
// =========================================================================
if (isset($_GET['imprimir']) && $_GET['imprimir'] == '1' && !empty($_GET['id_oficio'])) {
    $id_oficio = (int)$_GET['id_oficio'];
    $stmt = $conexion->prepare("
        SELECT o.*, 
               a.codigo_udo, a.tipo_equipo, a.marca, a.modelo, a.serial, 
               a.ubicacion, a.departamento, a.responsable_actual, a.cedula_responsable, a.valor_compra,
               u.nombre as nombre_jefe, u.cargo as cargo_jefe
        FROM oficios o 
        JOIN activos a ON o.id_activo = a.id_activo 
        LEFT JOIN usuarios u ON o.generado_por = u.id_usuario
        WHERE o.id_oficio = ?
    ");
    $stmt->execute([$id_oficio]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$data) die("Oficio no encontrado.");

    $evidencia_b64 = !empty($data['foto_evidencia']) ? convertirImagenABase64($data['foto_evidencia']) : '';
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Oficio de Exclusión <?= htmlspecialchars($data['numero_oficio']) ?></title>
        <?php renderEstilosFormato('portrait'); ?>
        <style>
            .header-republica {
                text-align: center;
                margin-bottom: 12px;
                font-size: 9pt;
                font-weight: bold;
                color: #334155;
                text-transform: uppercase;
                letter-spacing: 0.4px;
                line-height: 1.35;
            }
            .section-title {
                font-size: 9pt;
                font-weight: bold;
                color: #003366;
                text-transform: uppercase;
                border-bottom: 1.5px solid #0284c7;
                padding-bottom: 2px;
                margin: 12px 0 6px;
                letter-spacing: 0.3px;
            }
            .dictamen-box {
                background-color: #f8fafc;
                border-left: 4px solid #dc2626;
                border-top: 1px solid #e2e8f0;
                border-right: 1px solid #e2e8f0;
                border-bottom: 1px solid #e2e8f0;
                padding: 8px 12px;
                font-size: 8.5pt;
                margin: 8px 0;
                text-align: justify;
                line-height: 1.45;
            }
            .evidencia-container {
                margin: 10px 0;
                text-align: center;
                page-break-inside: avoid;
            }
            .evidencia-img {
                max-height: 140px;
                max-width: 100%;
                border: 1px solid #cbd5e1;
                padding: 3px;
                background-color: #fff;
            }
            .evidencia-caption {
                font-size: 7.5pt;
                color: #64748b;
                margin-top: 3px;
            }
        </style>
    </head>
    <body>
        <?php renderCintilloUDO('Delegación de Computación y Teleinformática'); ?>

        <div class="header-republica">
            República Bolivariana de Venezuela • Universidad de Oriente • Núcleo Monagas<br>
            Delegación de Computación y Teleinformática
        </div>

        <table class="meta-box">
            <tr>
                <td style="width: 50%;"><strong>OFICIO N°:</strong> <span style="font-size:10pt; font-weight:bold; color:#003366;"><?= htmlspecialchars($data['numero_oficio']) ?></span></td>
                <td style="width: 50%;"><strong>FECHA:</strong> Maturín, <?= date('d/m/Y', strtotime($data['fecha_generacion'])) ?></td>
            </tr>
            <tr>
                <td colspan="2"><strong>DE:</strong> Delegación de Computación y Teleinformática (Núcleo Monagas)</td>
            </tr>
            <tr>
                <td colspan="2"><strong>A / PARA:</strong> Coordinación Administrativa del Núcleo / Decanato</td>
            </tr>
            <tr>
                <td colspan="2"><strong>C.C.:</strong> Oficina Central de Bienes Públicos de la Universidad de Oriente</td>
            </tr>
            <tr>
                <td colspan="2"><strong>ASUNTO:</strong> Solicitud Oficial de Exclusión y Desincorporación de Bien Mueble Institucional</td>
            </tr>
        </table>

        <div class="doc-titulo" style="font-size: 11pt; background-color: #f1f5f9; padding: 5px; border-radius: 3px;">
            Oficio de Solicitud de Exclusión de Bienes Públicos
        </div>

        <p style="text-align: justify; margin: 8px 0; font-size: 9pt; line-height: 1.45;">
            Por medio de la presente, nos dirigimos a su distinguido despacho con la finalidad de solicitar formalmente el inicio del procedimiento administrativo de <strong>exclusión y desincorporación del inventario patrimonial universitario</strong> correspondiente al equipo de computación y teleinformática identificado a continuación, con base en el peritaje técnico realizado por los especialistas de esta delegación.
        </p>

        <div class="section-title">1. Identificación Patrimonial del Activo</div>
        <table class="tabla-datos">
            <tr>
                <th>Código de Inventario UDO</th>
                <td><strong style="color:#003366; font-size:9.5pt;"><?= htmlspecialchars($data['codigo_udo']) ?></strong></td>
            </tr>
            <tr>
                <th>Tipo de Equipo / Categoría</th>
                <td><?= htmlspecialchars($data['tipo_equipo']) ?></td>
            </tr>
            <tr>
                <th>Marca y Modelo</th>
                <td><?= htmlspecialchars($data['marca']) ?> <?= htmlspecialchars($data['modelo']) ?></td>
            </tr>
            <tr>
                <th>Número de Serial de Fábrica</th>
                <td><code><?= htmlspecialchars($data['serial'] ?: 'No legible / Sin serial visible') ?></code></td>
            </tr>
            <tr>
                <th>Ubicación / Dependencia Asignada</th>
                <td><?= htmlspecialchars($data['ubicacion']) ?> <?= !empty($data['departamento']) ? '('.htmlspecialchars($data['departamento']).')' : '' ?></td>
            </tr>
            <tr>
                <th>Responsable Registrado</th>
                <td><?= htmlspecialchars($data['responsable_actual'] ?: 'No asignado') ?> <?= !empty($data['cedula_responsable']) ? ' - C.I. '.htmlspecialchars($data['cedula_responsable']) : '' ?></td>
            </tr>
            <tr>
                <th>Valor de Adquisición Registrado</th>
                <td>$<?= number_format((float)($data['valor_compra'] ?? 0), 2) ?> USD</td>
            </tr>
        </table>

        <div class="section-title">2. Sustento Técnico y Económico de la Solicitud</div>
        <table class="tabla-datos">
            <tr>
                <th>Motivo de Baja Institucional</th>
                <td><strong><?= htmlspecialchars($data['motivo_baja']) ?></strong></td>
            </tr>
            <tr>
                <th>Causa Específica Diagnosticada</th>
                <td><?= htmlspecialchars($data['causa_especifica'] ?: 'Falla catastrófica no reparable') ?></td>
            </tr>
            <tr>
                <th>% Costo Reparación vs. Valor</th>
                <td>
                    <?php if ($data['porcentaje_costo_reparacion'] !== null): ?>
                        <strong><?= $data['porcentaje_costo_reparacion'] ?>%</strong>
                        <?php if ($data['porcentaje_costo_reparacion'] >= 70): ?>
                            <span style="color:#dc2626; font-size:8pt;"> (Supera el umbral reglamentario de incosteabilidad del 70%)</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <em>Irrecuperable / Componentes principales destruidos</em>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <div class="section-title">3. Dictamen Pericial de la Delegación</div>
        <div class="dictamen-box">
            <?= nl2br(htmlspecialchars($data['dictamen_tecnico'])) ?>
        </div>

        <?php if ($evidencia_b64): ?>
        <div class="evidencia-container">
            <img src="<?= $evidencia_b64 ?>" alt="Evidencia de Daño" class="evidencia-img">
            <div class="evidencia-caption">Figura 1: Registro fotográfico pericial de daño irreversible.</div>
        </div>
        <?php endif; ?>

        <p style="text-align: justify; margin: 10px 0 0; font-size: 9pt; line-height: 1.45;">
            En mérito de lo expuesto y habiéndose comprobado que la reparación es técnica o económicamente inviable, se remite el presente expediente para que la Coordinación Administrativa y la Oficina de Bienes Públicos procedan conforme al marco normativo vigente.
        </p>

        <table class="firmas-grid">
            <tr>
                <td>
                    <div class="linea-firma"></div>
                    <div class="firma-nombre"><?= htmlspecialchars($data['nombre_jefe'] ?? 'Jefe de Delegación') ?></div>
                    <div class="firma-cargo">Jefe de la Delegación de Computación y Teleinformática<br>Universidad de Oriente • Núcleo Monagas</div>
                </td>
                <td>
                    <div class="linea-firma"></div>
                    <div class="firma-nombre">Firma y Sello de Autorización</div>
                    <div class="firma-cargo">Coordinación Administrativa del Núcleo / Decanato<br>Universidad de Oriente • Núcleo Monagas</div>
                </td>
            </tr>
        </table>

        <?php renderFooterUDO($data['numero_oficio']); ?>
    </body>
    </html>
    <?php
    $html = ob_get_clean();

    if (isset($_GET['html']) && $_GET['html'] == '1') {
        echo $html;
        exit;
    }

    $descargar = isset($_GET['descargar']) && $_GET['descargar'] == '1';
    $nombreArchivo = 'Oficio_Exclusion_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $data['numero_oficio']) . '.pdf';
    generarPDF($html, $nombreArchivo, 'letter', 'portrait', $descargar);
}

// =========================================================================
// MODO IMPRESIÓN DE FICHA DE DESINCORPORADO
// =========================================================================
if (isset($_GET['ficha']) && $_GET['ficha'] == '1' && !empty($_GET['id'])) {
    $id_activo = (int)$_GET['id'];
    $stmt = $conexion->prepare("
        SELECT a.*, n.diagnostico_tecnico, n.numero_orden, n.fecha_salida,
               o.numero_oficio, o.motivo_baja, o.causa_especifica, o.porcentaje_costo_reparacion, o.dictamen_tecnico,
               o.disposicion_final, o.fecha_disposicion, o.responsable_disposicion
        FROM activos a 
        LEFT JOIN novedades n ON a.id_activo = n.id_activo 
        LEFT JOIN oficios o ON a.id_activo = o.id_activo
        WHERE a.id_activo = ? AND a.estado_equipo = 'Desincorporado'
        ORDER BY o.id_oficio DESC LIMIT 1
    ");
    $stmt->execute([$id_activo]);
    $equipo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$equipo) die("Equipo no encontrado o no está desincorporado.");

    ob_start();
    ?>
    <!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
    <title>Ficha de Equipo Desincorporado - <?= htmlspecialchars($equipo['codigo_udo']) ?></title>
    <?php renderEstilosFormato(); ?>
    </head><body>
    <div class="formato-hoja">
        <div class="formato-cuerpo">
            <?php renderCintilloUDO('Delegación de Computación y Teleinformática'); ?>

            <div class="doc-fecha">
                Maturín, <?= !empty($equipo['fecha_disposicion']) ? date('d/m/Y', strtotime($equipo['fecha_disposicion'])) : date('d/m/Y') ?>
            </div>

            <div class="doc-titulo">FICHA OFICIAL DE EQUIPO DESINCORPORADO</div>
            <div class="doc-subtitulo">EXPEDIENTE DE EXCLUSIÓN DEL PATRIMONIO UNIVERSITARIO</div>

            <table class="meta-box">
                <tr>
                    <td style="width: 50%;"><strong>CÓDIGO UDO:</strong> <span style="font-size:11pt; font-weight:800; color:#dc2626;"><?= htmlspecialchars($equipo['codigo_udo']) ?></span></td>
                    <td style="width: 50%;"><strong>ESTADO PATRIMONIAL:</strong> <span class="badge-estado badge-baja">Desincorporado</span></td>
                </tr>
                <tr>
                    <td><strong>OFICIO DE EXCLUSIÓN:</strong> <strong style="color:#003366;"><?= htmlspecialchars($equipo['numero_oficio'] ?: 'En trámite') ?></strong></td>
                    <td><strong>FECHA DE DISPOSICIÓN:</strong> <?= !empty($equipo['fecha_disposicion']) ? date('d/m/Y H:i', strtotime($equipo['fecha_disposicion'])) : '—' ?></td>
                </tr>
            </table>

            <table class="tabla-datos">
                <tr><th>Código de Inventario UDO</th><td><strong><?= htmlspecialchars($equipo['codigo_udo']) ?></strong></td></tr>
                <tr><th>Tipo de Activo</th><td><?= htmlspecialchars($equipo['tipo_equipo']) ?></td></tr>
                <tr><th>Marca y Modelo</th><td><?= htmlspecialchars($equipo['marca']) ?> <?= htmlspecialchars($equipo['modelo']) ?></td></tr>
                <tr><th>Número de Serial</th><td><code><?= htmlspecialchars($equipo['serial'] ?: 'NO POSEE / NO VISIBLE') ?></code></td></tr>
                <tr><th>Ubicación Original</th><td><?= htmlspecialchars($equipo['ubicacion']) ?></td></tr>
                <tr><th>Departamento / Dependencia</th><td><?= htmlspecialchars($equipo['departamento'] ?: '—') ?></td></tr>
                <tr><th>Último Responsable Registrado</th><td><?= htmlspecialchars($equipo['responsable_actual'] ?: '—') ?></td></tr>
                <tr><th>N° de Oficio de Exclusión</th><td><strong style="color:#dc2626;"><?= htmlspecialchars($equipo['numero_oficio'] ?: '—') ?></strong></td></tr>
                <tr><th>Motivo Institucional de Baja</th><td><?= htmlspecialchars($equipo['motivo_baja'] ?: '—') ?></td></tr>
                <tr><th>Causa Específica Diagnosticada</th><td><?= htmlspecialchars($equipo['causa_especifica'] ?: '—') ?></td></tr>
                <tr><th>% Costo Reparación vs. Valor</th><td><?= $equipo['porcentaje_costo_reparacion'] !== null ? $equipo['porcentaje_costo_reparacion'].'%' : '—' ?></td></tr>
                <tr><th>Dictamen Pericial Técnico</th><td><?= nl2br(htmlspecialchars($equipo['dictamen_tecnico'] ?: '—')) ?></td></tr>
                <tr><th>Disposición Final del Bien</th><td><strong><?= htmlspecialchars($equipo['disposicion_final'] ?: 'En resguardo para entrega a Bienes Nacionales') ?></strong></td></tr>
                <tr><th>Responsable de la Disposición</th><td><?= htmlspecialchars($equipo['responsable_disposicion'] ?: '—') ?></td></tr>
            </table>

            <table class="firmas-grid">
                <tr>
                    <td>
                        <div class="linea-firma"></div>
                        <div class="firma-nombre">Delegación de Computación y Teleinformática</div>
                        <div class="firma-cargo">Peritaje y Custodia Técnica</div>
                    </td>
                    <td>
                        <div class="linea-firma"></div>
                        <div class="firma-nombre">Coordinación de Bienes Públicos</div>
                        <div class="firma-cargo">Universidad de Oriente • Núcleo Monagas</div>
                    </td>
                </tr>
            </table>
        </div>

        <?php renderFooterUDO($equipo['codigo_udo']); ?>
    </div>
    </body></html>
    <?php
    $html = ob_get_clean();

    if (isset($_GET['html']) && $_GET['html'] == '1') {
        echo $html;
        exit;
    }

    $descargar = isset($_GET['descargar']) && $_GET['descargar'] == '1';
    $nombreArchivo = 'Ficha_Desincorporado_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $equipo['codigo_udo']) . '.pdf';
    generarPDF($html, $nombreArchivo, 'letter', 'portrait', $descargar);
}

// =========================================================================
// MODO REPORTE GENERAL DE DESINCORPORADOS
// =========================================================================
if (isset($_GET['reporte_general']) && $_GET['reporte_general'] == '1') {
    $desincReporte = $conexion->query("
        SELECT a.*, o.numero_oficio, o.fecha_generacion as fecha_oficio, o.disposicion_final
        FROM activos a 
        LEFT JOIN oficios o ON a.id_activo = o.id_activo
        WHERE a.estado_equipo = 'Desincorporado'
        ORDER BY a.codigo_udo ASC
    ")->fetchAll();

    ob_start();
    ?>
    <!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Reporte General de Equipos Desincorporados</title>
    <?php renderEstilosFormato(); ?>
    <style>
        .formato-hoja {
            max-width: 100%;
            margin: 0;
            padding: 0;
        }
    </style>
    </head><body>
    <div class="formato-hoja">
        <div class="formato-cuerpo">
            <?php renderCintilloUDO('Delegación de Computación y Teleinformática'); ?>

            <div class="doc-fecha">
                Maturín, <?= date('d/m/Y') ?>
            </div>

            <div class="doc-titulo">RELACIÓN GENERAL DE EQUIPOS DESINCORPORADOS</div>
            <div class="doc-subtitulo">INVENTARIO PATRIMONIAL OFICIAL DE BIENES EXCLUIDOS • NÚCLEO MONAGAS</div>

            <table style="width: 100%; margin-bottom: 12px; background: #f8fafc; border: 1px solid #cbd5e1; font-size: 8.5pt; color: #334155;">
                <tr>
                    <td style="padding: 6px 10px;"><strong>Total de activos desincorporados:</strong> <?= count($desincReporte) ?></td>
                    <td style="padding: 6px 10px;"><strong>Estado:</strong> Baja Patrimonial Definitiva</td>
                    <td style="padding: 6px 10px; text-align: right;"><strong>Fecha de emisión:</strong> <?= date('d/m/Y H:i') ?></td>
                </tr>
            </table>

            <table class="tabla-formal">
                <thead>
                    <tr>
                        <th style="width: 12%;">Código UDO</th>
                        <th style="width: 13%;">Tipo</th>
                        <th style="width: 18%;">Marca / Modelo</th>
                        <th style="width: 13%;">Serial</th>
                        <th style="width: 16%;">Ubicación Original</th>
                        <th style="width: 11%;">N° Oficio</th>
                        <th style="width: 17%;">Disposición Final</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($desincReporte) > 0): foreach($desincReporte as $d): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($d['codigo_udo']) ?></strong></td>
                        <td><?= htmlspecialchars($d['tipo_equipo']) ?></td>
                        <td><?= htmlspecialchars($d['marca']) ?> <?= htmlspecialchars($d['modelo']) ?></td>
                        <td><code><?= htmlspecialchars($d['serial'] ?: 'S/S') ?></code></td>
                        <td><?= htmlspecialchars($d['ubicacion']) ?></td>
                        <td><strong style="color:#dc2626;"><?= htmlspecialchars($d['numero_oficio'] ?: '—') ?></strong></td>
                        <td><?= htmlspecialchars($d['disposicion_final'] ?: 'En resguardo') ?></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="7" style="text-align:center; padding:20px; color:#64748b;">No existen equipos desincorporados registrados en el sistema.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <table class="firmas-grid">
                <tr>
                    <td>
                        <div class="linea-firma"></div>
                        <div class="firma-nombre">Jefe de Delegación de Computación</div>
                        <div class="firma-cargo">Universidad de Oriente • Núcleo Monagas</div>
                    </td>
                    <td>
                        <div class="linea-firma"></div>
                        <div class="firma-nombre">Oficina Central de Bienes Públicos</div>
                        <div class="firma-cargo">Universidad de Oriente</div>
                    </td>
                </tr>
            </table>
        </div>

        <?php renderFooterUDO('REP-DESINC-' . date('Ymd')); ?>
    </div>
    </body></html>
    <?php
    $html = ob_get_clean();

    if (isset($_GET['html']) && $_GET['html'] == '1') {
        echo $html;
        exit;
    }

    $descargar = isset($_GET['descargar']) && $_GET['descargar'] == '1';
    $nombreArchivo = 'Relacion_Desincorporados_' . date('Ymd_His') . '.pdf';
    generarPDF($html, $nombreArchivo, 'letter', 'landscape', $descargar);
}

// =========================================================================
// CONSULTAS PARA INTERFAZ PRINCIPAL
// =========================================================================

// 1. Activos con dictamen técnico de "Baja Técnica" pendientes de aprobación
$pendientes = $conexion->query("
    SELECT a.*, 
           n.id_novedad, n.numero_orden, n.fecha_diagnostico, n.diagnostico_tecnico,
           n.motivo_baja, n.causa_especifica, n.costo_reparacion_estimado, n.porcentaje_costo_reparacion,
           n.foto_evidencia,
           u.nombre as nombre_tecnico
    FROM activos a 
    INNER JOIN novedades n ON a.id_activo = n.id_activo 
    LEFT JOIN usuarios u ON n.id_tecnico_diagnostico = u.id_usuario
    WHERE a.estado_equipo = 'Baja Técnica'
      AND n.id_novedad = (SELECT MAX(id_novedad) FROM novedades WHERE id_activo = a.id_activo)
    ORDER BY n.fecha_diagnostico DESC, a.ultima_modificacion DESC
")->fetchAll();

// 2. Activos con Oficio de Exclusión aprobado ("Desincorporado")
$desincorporados = $conexion->query("
    SELECT a.*, 
           n.diagnostico_tecnico, 
           n.numero_orden,
           o.id_oficio,
           o.numero_oficio, 
           o.motivo_baja as oficio_motivo,
           o.causa_especifica as oficio_causa,
           o.porcentaje_costo_reparacion as oficio_pct,
           o.dictamen_tecnico as oficio_dictamen,
           o.foto_evidencia as oficio_foto,
           o.fecha_generacion as oficio_fecha,
           o.disposicion_final,
           o.fecha_disposicion,
           o.responsable_disposicion,
           u.nombre as nombre_jefe
    FROM activos a 
    INNER JOIN oficios o ON a.id_activo = o.id_activo
    LEFT JOIN novedades n ON o.id_novedad = n.id_novedad 
    LEFT JOIN usuarios u ON o.generado_por = u.id_usuario
    WHERE a.estado_equipo = 'Desincorporado'
    ORDER BY o.fecha_generacion DESC, a.ultima_modificacion DESC
")->fetchAll();

$tabActivo = $_GET['tab'] ?? (count($pendientes) > 0 ? 'pendientes' : 'historico');
$nuevoOficioId = (int)($_GET['nuevo_oficio'] ?? 0);

include __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid animate__animated animate__fadeIn">
    <!-- Encabezado de Página -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-1 text-danger">
                <i class="fas fa-file-signature me-2"></i>Gestión de Bajas Técnicas y Exclusiones
            </h3>
            <p class="text-secondary small mb-0">
                Flujo oficial de desincorporación patrimonial de activos conforme a normativas de la Delegación y Oficina de Bienes Públicos UDO.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="bajas.php?reporte_general=1" target="_blank" class="btn btn-outline-danger btn-sm rounded-pill px-3 shadow-sm">
                <i class="fas fa-print me-1"></i> Reporte General
            </a>
        </div>
    </div>

    <?php if ($nuevoOficioId > 0): ?>
    <div class="alert alert-success alert-dismissible fade show rounded-4 shadow-sm border-0 d-flex align-items-center justify-content-between" role="alert">
        <div>
            <i class="fas fa-check-circle fa-lg me-2"></i>
            ¡El Oficio de Exclusión ha sido generado exitosamente! Puede visualizar e imprimir el documento oficial de inmediato.
        </div>
        <div class="d-flex gap-2">
            <a href="bajas.php?imprimir=1&id_oficio=<?= $nuevoOficioId ?>" target="_blank" class="btn btn-success btn-sm rounded-pill px-3">
                <i class="fas fa-print me-1"></i> Imprimir Oficio Oficial
            </a>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Pestañas de Navegación -->
    <ul class="nav nav-pills mb-4 gap-2" id="bajasTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill px-4 <?= $tabActivo === 'pendientes' ? 'active bg-danger' : 'bg-body-secondary text-body' ?>" 
                    id="pendientes-tab" data-bs-toggle="pill" data-bs-target="#pendientes-pane" type="button" role="tab">
                <i class="fas fa-clock me-2"></i>Pendientes de Autorización
                <span class="badge bg-white text-danger ms-2 rounded-pill"><?= count($pendientes) ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link rounded-pill px-4 <?= $tabActivo === 'historico' ? 'active bg-danger' : 'bg-body-secondary text-body' ?>" 
                    id="historico-tab" data-bs-toggle="pill" data-bs-target="#historico-pane" type="button" role="tab">
                <i class="fas fa-archive me-2"></i>Equipos Desincorporados (Histórico)
                <span class="badge bg-white text-danger ms-2 rounded-pill"><?= count($desincorporados) ?></span>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="bajasTabsContent">
        <!-- ============================================================= -->
        <!-- PESTAÑA 1: PENDIENTES DE AUTORIZACIÓN (Baja Técnica) -->
        <!-- ============================================================= -->
        <div class="tab-pane fade <?= $tabActivo === 'pendientes' ? 'show active' : '' ?>" id="pendientes-pane" role="tabpanel">
            <?php if (empty($pendientes)): ?>
                <div class="card border-0 shadow-sm rounded-4 text-center py-5">
                    <div class="card-body">
                        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                        <h5 class="fw-bold text-body">No hay bajas técnicas pendientes de aprobación</h5>
                        <p class="text-secondary small mb-0">
                            Todos los dictámenes técnicos emitidos por el taller han sido tramitados o no existen equipos en condición de baja.
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <?php if (!$esAdmin): ?>
                    <div class="alert alert-info rounded-4 border-0 shadow-sm mb-4">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Aviso para Técnicos:</strong> Los dictámenes de baja técnica listados a continuación están en espera de revisión, aprobación y emisión del Oficio de Exclusión por parte del <strong>Jefe de Delegación / Administrador</strong>.
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <?php foreach ($pendientes as $p): ?>
                    <div class="col-lg-6">
                        <div class="card border-0 shadow-sm rounded-4 h-100 border-start border-danger border-4">
                            <div class="card-body p-4 d-flex flex-column justify-content-between">
                                <div>
                                    <!-- Header Activo -->
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill mb-1">
                                                Baja Técnica Dictaminada
                                            </span>
                                            <h5 class="fw-bold text-body mb-0">
                                                <?= e($p['codigo_udo']) ?> 
                                                <small class="text-muted fw-normal fs-6">• <?= e($p['tipo_equipo']) ?></small>
                                            </h5>
                                        </div>
                                        <span class="text-muted small">
                                            <i class="fas fa-ticket-alt me-1"></i><?= e($p['numero_orden']) ?>
                                        </span>
                                    </div>

                                    <p class="text-secondary small mb-3">
                                        <strong><?= e($p['marca']) ?> <?= e($p['modelo']) ?></strong> (Serial: <code><?= e($p['serial']) ?></code>)<br>
                                        <i class="fas fa-map-marker-alt text-danger me-1"></i><?= e($p['ubicacion']) ?>
                                        <?= !empty($p['departamento']) ? ' - ' . e($p['departamento']) : '' ?>
                                    </p>

                                    <!-- Datos Técnicos y Cálculo -->
                                    <div class="bg-body-secondary p-3 rounded-3 mb-3">
                                        <div class="row g-2 small">
                                            <div class="col-sm-6">
                                                <span class="text-muted d-block">Técnico que dictamina:</span>
                                                <strong><?= e($p['nombre_tecnico'] ?? 'Taller Especializado') ?></strong>
                                            </div>
                                            <div class="col-sm-6">
                                                <span class="text-muted d-block">Fecha de Peritaje:</span>
                                                <strong><?= !empty($p['fecha_diagnostico']) ? date('d/m/Y H:i', strtotime($p['fecha_diagnostico'])) : '—' ?></strong>
                                            </div>
                                            <div class="col-sm-6">
                                                <span class="text-muted d-block">Valor de Compra:</span>
                                                <strong>$<?= number_format((float)($p['valor_compra'] ?? 0), 2) ?> USD</strong>
                                            </div>
                                            <div class="col-sm-6">
                                                <span class="text-muted d-block">Costo Est. Reparación:</span>
                                                <strong class="text-danger">$<?= number_format((float)($p['costo_reparacion_estimado'] ?? 0), 2) ?> USD</strong>
                                            </div>
                                        </div>

                                        <?php if ($p['porcentaje_costo_reparacion'] !== null): ?>
                                        <div class="mt-2 pt-2 border-top border-secondary-subtle d-flex align-items-center justify-content-between">
                                            <span class="small text-muted">Proporción de Costo:</span>
                                            <?php if ($p['porcentaje_costo_reparacion'] >= 70): ?>
                                                <span class="badge bg-danger rounded-pill px-3 py-1">
                                                    <i class="fas fa-exclamation-triangle me-1"></i><?= $p['porcentaje_costo_reparacion'] ?>% (Supera umbral del 70%)
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark rounded-pill px-3 py-1">
                                                    <?= $p['porcentaje_costo_reparacion'] ?>%
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Causa y Dictamen -->
                                    <div class="mb-3">
                                        <small class="text-muted fw-bold d-block mb-1">Causa Específica de Inoperatividad:</small>
                                        <div class="small text-danger fw-semibold mb-2">
                                            <i class="fas fa-circle-exclamation me-1"></i><?= e($p['causa_especifica'] ?: ($p['motivo_baja'] ?: 'Falla no subsanable')) ?>
                                        </div>

                                        <small class="text-muted fw-bold d-block mb-1">Dictamen Técnico Pericial:</small>
                                        <div class="small bg-body-tertiary p-2 rounded border text-secondary">
                                            <?= nl2br(e($p['diagnostico_tecnico'])) ?>
                                        </div>
                                    </div>

                                    <!-- Foto de Evidencia -->
                                    <?php if (!empty($p['foto_evidencia']) && file_exists(__DIR__ . '/../../' . $p['foto_evidencia'])): ?>
                                    <div class="mb-3">
                                        <small class="text-muted fw-bold d-block mb-1">Evidencia Fotográfica de Daño:</small>
                                        <div class="d-flex align-items-center gap-2">
                                            <img src="<?= url($p['foto_evidencia']) ?>" alt="Evidencia" 
                                                 class="rounded border shadow-sm" style="height: 60px; width: 80px; object-fit: cover; cursor: pointer;"
                                                 data-bs-toggle="modal" data-bs-target="#modalFoto_<?= $p['id_activo'] ?>">
                                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-bs-toggle="modal" data-bs-target="#modalFoto_<?= $p['id_activo'] ?>">
                                                <i class="fas fa-search-plus me-1"></i>Ver Foto Completa
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Modal Foto -->
                                    <div class="modal fade" id="modalFoto_<?= $p['id_activo'] ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered modal-lg">
                                            <div class="modal-content border-0 shadow rounded-4">
                                                <div class="modal-header border-0 pb-0">
                                                    <h6 class="modal-title fw-bold">Evidencia Fotográfica • <?= e($p['codigo_udo']) ?></h6>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body text-center p-3">
                                                    <img src="<?= url($p['foto_evidencia']) ?>" alt="Evidencia Completa" class="img-fluid rounded-3 shadow-sm">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Acciones -->
                                <div class="pt-3 border-top mt-2">
                                    <?php if ($esAdmin): ?>
                                        <button type="button" class="btn btn-danger w-100 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#modalAprobar_<?= $p['id_activo'] ?>">
                                            <i class="fas fa-stamp me-1"></i> Aprobar Baja y Emitir Oficio de Exclusión
                                        </button>

                                        <!-- Modal Aprobación -->
                                        <div class="modal fade" id="modalAprobar_<?= $p['id_activo'] ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content border-0 shadow rounded-4">
                                                    <form method="POST" action="bajas.php">
                                                        <?= csrfCampo() ?>
                                                        <input type="hidden" name="accion" value="aprobar_baja">
                                                        <input type="hidden" name="id_activo" value="<?= $p['id_activo'] ?>">
                                                        <input type="hidden" name="id_novedad" value="<?= $p['id_novedad'] ?>">

                                                        <div class="modal-header bg-danger text-white rounded-top-4">
                                                            <h6 class="modal-title fw-bold">
                                                                <i class="fas fa-stamp me-2"></i>Aprobar Exclusión Oficial
                                                            </h6>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body p-4">
                                                            <p class="text-secondary small mb-3">
                                                                Al autorizar la baja, el sistema generará automáticamente el <strong>Oficio Oficial de Solicitud de Exclusión</strong> correlativo y el activo pasará a estado <strong>Desincorporado</strong>.
                                                            </p>

                                                            <div class="bg-body-secondary p-3 rounded-3 mb-3 small">
                                                                <strong>Equipo:</strong> <?= e($p['codigo_udo']) ?> - <?= e($p['tipo_equipo']) ?> <?= e($p['marca']) ?><br>
                                                                <strong>Serial:</strong> <?= e($p['serial']) ?><br>
                                                                <strong>Causa:</strong> <?= e($p['causa_especifica'] ?: $p['motivo_baja']) ?>
                                                            </div>

                                                            <div class="mb-3">
                                                                <label class="form-label small fw-bold text-secondary">
                                                                    Observaciones o Consideraciones de Jefatura (Opcional):
                                                                </label>
                                                                <textarea name="observaciones_jefatura" class="form-control rounded-3" rows="3" 
                                                                          placeholder="Añada cualquier instrucción, visto bueno especial o detalle administrativo..."></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer border-0 p-3 pt-0">
                                                            <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancelar</button>
                                                            <button type="submit" class="btn btn-danger rounded-pill px-4 shadow-sm">
                                                                <i class="fas fa-check-circle me-1"></i> Autorizar y Emitir Oficio
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center py-2 text-muted small">
                                            <i class="fas fa-lock me-1"></i> Requiere aprobación y firma del Jefe de Delegación.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================================================= -->
        <!-- PESTAÑA 2: EQUIPOS DESINCORPORADOS (Histórico Oficial) -->
        <!-- ============================================================= -->
        <div class="tab-pane fade <?= $tabActivo === 'historico' ? 'show active' : '' ?>" id="historico-pane" role="tabpanel">
            <?php if (empty($desincorporados)): ?>
                <div class="card border-0 shadow-sm rounded-4 text-center py-5">
                    <div class="card-body">
                        <i class="fas fa-archive fa-3x text-muted mb-3"></i>
                        <h5 class="fw-bold text-body">No hay equipos desincorporados en el histórico</h5>
                        <p class="text-secondary small mb-0">Cuando se aprueben oficios de exclusión formal, aparecerán archivados aquí.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($desincorporados as $d): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card border-0 shadow-sm rounded-4 h-100 d-flex flex-column justify-content-between">
                            <div class="card-body p-4">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="fw-bold mb-0 text-danger"><?= e($d['codigo_udo']) ?></h6>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill">
                                        Desincorporado
                                    </span>
                                </div>
                                <p class="small text-secondary mb-2">
                                    <strong><?= e($d['tipo_equipo']) ?></strong> - <?= e($d['marca']) ?> <?= e($d['modelo']) ?><br>
                                    <span class="text-muted">S/N: <?= e($d['serial']) ?></span>
                                </p>

                                <div class="bg-body-secondary p-2 rounded-3 small mb-3">
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted">N° Oficio:</span>
                                        <strong class="text-danger"><?= e($d['numero_oficio']) ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted">Fecha Emisión:</span>
                                        <span><?= !empty($d['oficio_fecha']) ? date('d/m/Y', strtotime($d['oficio_fecha'])) : '—' ?></span>
                                    </div>
                                    <?php if (!empty($d['nombre_jefe'])): ?>
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted">Autorizado por:</span>
                                        <span class="text-truncate" style="max-width: 150px;"><?= e($d['nombre_jefe']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Disposición Final -->
                                <div class="mb-3">
                                    <small class="text-muted fw-bold d-block mb-1">Disposición Final:</small>
                                    <?php if (!empty($d['disposicion_final'])): ?>
                                        <div class="badge bg-success-subtle text-success border border-success-subtle p-2 text-wrap text-start w-100 rounded-3">
                                            <i class="fas fa-check-circle me-1"></i><?= e($d['disposicion_final']) ?>
                                            <?php if (!empty($d['fecha_disposicion'])): ?>
                                                <div class="small fw-normal text-muted mt-1">
                                                    <?= date('d/m/Y', strtotime($d['fecha_disposicion'])) ?>
                                                    <?= !empty($d['responsable_disposicion']) ? ' • ' . e($d['responsable_disposicion']) : '' ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="badge bg-secondary-subtle text-secondary border border-secondary-subtle p-2 text-wrap text-start w-100 rounded-3">
                                            <i class="fas fa-boxes-packing me-1"></i> En resguardo de la delegación (Pendiente retiro físico)
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="card-footer bg-transparent border-0 p-4 pt-0">
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="bajas.php?imprimir=1&id_oficio=<?= $d['id_oficio'] ?>" target="_blank" class="btn btn-sm btn-outline-danger rounded-pill flex-fill">
                                        <i class="fas fa-file-pdf me-1"></i> Oficio
                                    </a>
                                    <a href="bajas.php?ficha=1&id=<?= $d['id_activo'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary rounded-pill flex-fill">
                                        <i class="fas fa-print me-1"></i> Ficha
                                    </a>
                                    <?php if ($esAdmin && empty($d['disposicion_final'])): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill" data-bs-toggle="modal" data-bs-target="#modalDisp_<?= $d['id_oficio'] ?>" title="Registrar Disposición Final">
                                        <i class="fas fa-dolly"></i>
                                    </button>

                                    <!-- Modal Disposición Final -->
                                    <div class="modal fade" id="modalDisp_<?= $d['id_oficio'] ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content border-0 shadow rounded-4">
                                                <form method="POST" action="bajas.php">
                                                    <?= csrfCampo() ?>
                                                    <input type="hidden" name="accion" value="registrar_disposicion">
                                                    <input type="hidden" name="id_oficio" value="<?= $d['id_oficio'] ?>">

                                                    <div class="modal-header bg-primary text-white rounded-top-4">
                                                        <h6 class="modal-title fw-bold">
                                                            <i class="fas fa-dolly me-2"></i>Registrar Disposición Final
                                                        </h6>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body p-4">
                                                        <p class="text-secondary small mb-3">
                                                            Asiente la salida física definitiva del equipo desincorporado (<?= e($d['codigo_udo']) ?> - Oficio: <?= e($d['numero_oficio']) ?>).
                                                        </p>

                                                        <div class="mb-3">
                                                            <label class="form-label small fw-bold text-secondary">Destino / Disposición Final:</label>
                                                            <select name="disposicion_final" class="form-select rounded-3" required>
                                                                <option value="">Seleccione destino...</option>
                                                                <option value="Entregado a Almacén Central de Bienes Públicos UDO">Entregado a Almacén Central de Bienes Públicos UDO</option>
                                                                <option value="Custodia en Depósito para Actas Periódicas de Chatarrización">Custodia en Depósito para Actas de Chatarrización</option>
                                                                <option value="Despiece Autorizado para Repuestos de Taller Técnico">Despiece Autorizado para Repuestos de Taller Técnico</option>
                                                                <option value="Donación Académica para Prácticas de Laboratorio">Donación Académica para Prácticas de Laboratorio</option>
                                                            </select>
                                                        </div>

                                                        <div class="mb-3">
                                                            <label class="form-label small fw-bold text-secondary">Fecha de Entrega / Retiro:</label>
                                                            <input type="datetime-local" name="fecha_disposicion" class="form-control rounded-3" value="<?= date('Y-m-d\TH:i') ?>" required>
                                                        </div>

                                                        <div class="mb-3">
                                                            <label class="form-label small fw-bold text-secondary">Persona / Responsable que Recibe:</label>
                                                            <input type="text" name="responsable_disposicion" class="form-control rounded-3" placeholder="Ej: Lcdo. Carlos Gómez (Bienes Públicos)" required>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer border-0 p-3 pt-0">
                                                        <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Cancelar</button>
                                                        <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm">
                                                            <i class="fas fa-save me-1"></i> Guardar Disposición
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>