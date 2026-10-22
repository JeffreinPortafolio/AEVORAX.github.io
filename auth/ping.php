<?php
// Renueva la actividad de la sesión (lo llama el JS cuando el usuario sigue activo).
require_once __DIR__ . '/../core/conexion.php';
restringirAcceso();
$_SESSION['ultima_actividad'] = time();
header('Cache-Control: no-store');
http_response_code(204);
