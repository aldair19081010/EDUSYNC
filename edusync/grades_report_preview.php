<?php
// Vista previa del reporte Detalle reutilizando exactamente la estructura HTML del Excel.
// Se ejecuta dentro de un buffer para poder quitar las cabeceras de descarga.
ob_start();

$_GET['download_token'] = '';

include __DIR__ . '/export_grades_excel.php';

$html = ob_get_clean();

// El exportador configura cabeceras para descarga. En vista previa las reemplazamos.
header_remove('Content-Disposition');
header_remove('Content-Type');
header_remove('Cache-Control');
header_remove('Pragma');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$preview_css = '<style>
body{margin:0;padding:18px;background:#fff;color:#25324b;font-family:Arial,sans-serif;}
table{font-size:12px;}
th{position:sticky;top:0;z-index:2;}
@page{size:landscape;margin:10mm;}
@media print{body{padding:0;}th{position:static;}}
</style>';

if (stripos($html, '</head>') !== false) {
    $html = str_ireplace('</head>', $preview_css . '</head>', $html);
}

echo $html;
