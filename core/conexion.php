<?php
/**
 * Conexión PDO a MySQL/MariaDB.
 * El usuario `Jeffrein2804` lo crea el script de database/*.sql al importarlo con root.
 */
const DB_HOST = 'localhost';
const DB_NAME = 'siga_udo';
const DB_USER = 'Jeffrein2804';
const DB_PASS = 'Jeffrein2804';

try {
    $conexion = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    error_log('Conexión BD: ' . $e->getMessage());
    http_response_code(500);
    exit('No se pudo conectar con la base de datos. Verifique que importó database/siga_udo.sql con root.');
}

require_once __DIR__ . '/funciones.php';
