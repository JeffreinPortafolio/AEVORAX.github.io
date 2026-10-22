<?php
/**
 * Plantilla Base Oficial y Componentes para Formatos Institucionales UDO
 * Sistema de Gestión de Activos (SIGA-UDO)
 * 
 * Generador de PDF nativo con Dompdf y plantillas formales con el cintillo
 * oficial de la Universidad de Oriente (Núcleo de Monagas) y pie de página institucional.
 */

// Cargar Dompdf
if (file_exists(__DIR__ . '/../core/dompdf/autoload.inc.php')) {
    require_once __DIR__ . '/../core/dompdf/autoload.inc.php';
}

use Dompdf\Dompdf;
use Dompdf\Options;

if (!function_exists('obtenerLogoUdoSrc')) {
    function obtenerLogoUdoSrc(): string {
        static $cachedSrc = null;
        if ($cachedSrc !== null) {
            return $cachedSrc;
        }
        $rutaArchivo = __DIR__ . '/../assets/img/udo_logo.png';
        if (file_exists($rutaArchivo)) {
            $tipo = pathinfo($rutaArchivo, PATHINFO_EXTENSION);
            $data = file_get_contents($rutaArchivo);
            $cachedSrc = 'data:image/' . $tipo . ';base64,' . base64_encode($data);
            return $cachedSrc;
        }
        if (function_exists('url')) {
            $cachedSrc = url('assets/img/udo_logo.png');
        } else {
            $cachedSrc = '/Asset-UDO/assets/img/udo_logo.png';
        }
        return $cachedSrc;
    }
}

if (!function_exists('convertirImagenABase64')) {
    function convertirImagenABase64(string $ruta): string {
        if (empty($ruta)) return '';
        $posibles = [
            $ruta,
            __DIR__ . '/../' . ltrim($ruta, '/\\'),
            __DIR__ . '/../../' . ltrim($ruta, '/\\')
        ];
        foreach ($posibles as $p) {
            if (file_exists($p) && is_file($p)) {
                $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
                if ($ext === 'jpg') $ext = 'jpeg';
                $data = file_get_contents($p);
                return 'data:image/' . $ext . ';base64,' . base64_encode($data);
            }
        }
        return '';
    }
}

if (!function_exists('generarPDF')) {
    function generarPDF(string $html, string $nombreArchivo = 'documento.pdf', string $papel = 'letter', string $orientacion = 'portrait', bool $descargar = false): void {
        if (ob_get_length()) {
            ob_end_clean();
        }

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Helvetica');
        $options->set('dpi', 110);
        $options->set('chroot', realpath(__DIR__ . '/..'));

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($papel, $orientacion);
        $dompdf->render();

        $dompdf->stream($nombreArchivo, [
            'Attachment' => $descargar ? 1 : 0
        ]);
        exit;
    }
}

if (!function_exists('renderEstilosFormato')) {
    function renderEstilosFormato(string $orientacion = 'portrait'): void {
        ?>
        <style>
            @charset "UTF-8";
            @page {
                size: letter <?= $orientacion ?>;
                margin: <?= $orientacion === 'landscape' ? '10mm 12mm 12mm 12mm' : '12mm 15mm 15mm 15mm' ?>;
            }
            * {
                box-sizing: border-box;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            body {
                font-family: Helvetica, Arial, sans-serif;
                margin: 0;
                padding: 0;
                color: #1e293b;
                background-color: #ffffff;
                font-size: 9pt;
                line-height: 1.45;
            }

            /* CINTILLO INSTITUCIONAL OFICIAL UDO (Estructurado con tabla para máxima compatibilidad Dompdf) */
            table.cintillo-udo {
                width: 100%;
                border-collapse: collapse;
                background-color: #80b3ff !important;
                margin-bottom: 16px;
                border: none;
            }
            table.cintillo-udo td {
                border: none !important;
                background-color: transparent !important;
                vertical-align: middle;
            }
            .cintillo-td-logo {
                width: 60px;
                padding: 8px 12px;
                text-align: center;
            }
            .cintillo-logo {
                width: 52px;
                height: 52px;
                display: block;
            }
            .cintillo-td-texto {
                padding: 8px 12px;
                text-align: left;
            }
            .cintillo-univ {
                font-size: 15pt;
                font-weight: bold;
                color: #ffffff !important;
                letter-spacing: 0.6px;
                text-transform: uppercase;
                line-height: 1.15;
            }
            .cintillo-sub {
                font-size: 10.5pt;
                font-weight: bold;
                color: #ffffff !important;
                letter-spacing: 0.4px;
                text-transform: uppercase;
                margin-top: 2px;
                line-height: 1.2;
            }
            .cintillo-dep {
                font-size: 8pt;
                font-weight: bold;
                color: #002b55 !important;
                letter-spacing: 0.4px;
                text-transform: uppercase;
                margin-top: 3px;
                background-color: #ffffff !important;
                display: inline-block;
                padding: 1px 6px;
                border-radius: 2px;
            }

            /* ENCABEZADOS Y TÍTULOS */
            .doc-fecha {
                text-align: right;
                font-size: 9pt;
                color: #334155;
                margin-bottom: 10px;
                font-weight: normal;
            }
            .doc-titulo {
                text-align: center;
                font-size: 12.5pt;
                font-weight: bold;
                color: #003366;
                text-transform: uppercase;
                letter-spacing: 0.6px;
                margin: 10px 0 3px 0;
            }
            .doc-subtitulo {
                text-align: center;
                font-size: 8.5pt;
                font-weight: bold;
                color: #64748b;
                text-transform: uppercase;
                letter-spacing: 0.4px;
                margin-bottom: 14px;
            }

            /* CAJAS DE METADATOS */
            table.meta-box {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 14px;
                font-size: 8.5pt;
                background-color: #f8fafc;
                border: 1px solid #cbd5e1;
            }
            table.meta-box td {
                padding: 6px 10px;
                border: 1px solid #cbd5e1;
                vertical-align: middle;
            }

            /* TABLAS FORMALES Y DE DATOS */
            table.tabla-formal {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
                margin-bottom: 14px;
                font-size: 8.5pt;
                border: 1px solid #cbd5e1;
            }
            table.tabla-formal th {
                background-color: #003366 !important;
                color: #ffffff !important;
                padding: 6px 8px;
                font-weight: bold;
                text-align: left;
                font-size: 8pt;
                text-transform: uppercase;
                letter-spacing: 0.3px;
                border: 1px solid #002244;
            }
            table.tabla-formal td {
                padding: 6px 8px;
                border: 1px solid #cbd5e1;
                color: #1e293b;
                background-color: #ffffff;
            }
            table.tabla-formal tr.fila-par td {
                background-color: #f8fafc !important;
            }

            table.tabla-datos {
                width: 100%;
                border-collapse: collapse;
                margin: 10px 0 14px 0;
                font-size: 8.5pt;
                border: 1px solid #cbd5e1;
            }
            table.tabla-datos th {
                width: 30%;
                background-color: #f1f5f9 !important;
                color: #0f2744 !important;
                font-weight: bold;
                padding: 6px 10px;
                font-size: 8pt;
                border: 1px solid #cbd5e1;
                text-transform: uppercase;
                letter-spacing: 0.3px;
                text-align: left;
            }
            table.tabla-datos td {
                padding: 6px 10px;
                border: 1px solid #cbd5e1;
                background-color: #ffffff;
                color: #1e293b;
            }

            /* BADGES / ESTADOS */
            .badge-estado {
                display: inline-block;
                padding: 2px 7px;
                border-radius: 8px;
                font-weight: bold;
                font-size: 7.5pt;
                text-transform: uppercase;
                letter-spacing: 0.3px;
                color: #ffffff !important;
            }
            .badge-operativo, .badge-success { background-color: #16a34a !important; }
            .badge-taller, .badge-warning { background-color: #ca8a04 !important; }
            .badge-baja, .badge-danger { background-color: #dc2626 !important; }
            .badge-info { background-color: #0284c7 !important; }

            /* CLÁUSULAS Y TEXTOS JURÍDICOS */
            .clausula-texto {
                font-size: 8pt;
                color: #334155;
                background-color: #f8fafc;
                border: 1px solid #cbd5e1;
                border-left: 3px solid #003366;
                padding: 7px 10px;
                margin: 12px 0;
                text-align: justify;
                line-height: 1.4;
            }

            /* SECCIÓN DE FIRMAS */
            table.firmas-grid {
                width: 100%;
                margin-top: 30px;
                border-collapse: collapse;
                border: none;
                page-break-inside: avoid;
            }
            table.firmas-grid td {
                width: 50%;
                text-align: center;
                vertical-align: top;
                padding: 0 20px;
                font-size: 8pt;
                border: none !important;
                background-color: transparent !important;
            }
            .linea-firma {
                border-top: 1px solid #0f172a;
                margin: 35px auto 5px auto;
                width: 80%;
            }
            .firma-nombre {
                font-weight: bold;
                color: #0f172a;
                margin-top: 2px;
            }
            .firma-cargo {
                color: #64748b;
                font-size: 7.5pt;
            }

            /* PIE DE PÁGINA REGLAMENTARIO UDO */
            .formato-footer {
                margin-top: 25px;
                text-align: center;
                page-break-inside: avoid;
            }
            .footer-slogan {
                font-weight: bold;
                font-size: 9pt;
                color: #475569;
                text-transform: uppercase;
                letter-spacing: 0.8px;
                margin-bottom: 4px;
            }
            .footer-linea {
                border-top: 1px solid #cbd5e1;
                margin: 4px 0 5px 0;
                width: 100%;
            }
            .footer-contacto {
                font-size: 7pt;
                color: #64748b;
                line-height: 1.35;
            }
            .footer-meta {
                font-size: 6.5pt;
                color: #94a3b8;
                margin-top: 3px;
            }
        </style>
        <?php
    }
}

if (!function_exists('renderCintilloUDO')) {
    function renderCintilloUDO(string $departamento = 'Delegación de Computación y Teleinformática'): void {
        $logoSrc = obtenerLogoUdoSrc();
        ?>
        <table class="cintillo-udo">
            <tr>
                <td class="cintillo-td-logo">
                    <img src="<?= $logoSrc ?>" alt="UDO" class="cintillo-logo">
                </td>
                <td class="cintillo-td-texto">
                    <div class="cintillo-univ">UNIVERSIDAD DE ORIENTE</div>
                    <div class="cintillo-sub">NÚCLEO DE MONAGAS</div>
                    <?php if (!empty($departamento)): ?>
                        <div class="cintillo-dep"><?= htmlspecialchars($departamento) ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }
}

if (!function_exists('renderFooterUDO')) {
    function renderFooterUDO(string $codigoControl = ''): void {
        ?>
        <div class="formato-footer">
            <div class="footer-slogan">DEL PUEBLO VENIMOS / HACIA EL PUEBLO VAMOS</div>
            <div class="footer-linea"></div>
            <div class="footer-contacto">
                Av. Universidad, Campus Los Guaritos, Maturín Estado Monagas. Apartado Postal Nº 6201.<br>
                Teléfono 0291-3004010. http://www.monagas.udo.edu.ve/
            </div>
            <div class="footer-meta">
                Documento Oficial emitido por SIGA-UDO – <?= date('d/m/Y H:i') ?>
                <?php if (!empty($codigoControl)): ?>
                    • Código de Control: <?= htmlspecialchars($codigoControl) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
