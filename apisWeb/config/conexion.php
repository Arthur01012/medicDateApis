<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function conectarBD(): mysqli
{
    $host = getenv('DB_HOST') ?: 'medicdate.innego.net';
    $usuario = getenv('DB_USER') ?: 'innegone_u_medicdate';
    $contrasena = getenv('DB_PASSWORD') ?: '*EzsnHGD8%)mdEgu';
    $baseDatos = getenv('DB_NAME') ?: 'innegone_bd_medicdate';

    try {
        $conexion = new mysqli($host, $usuario, $contrasena, $baseDatos);
        $conexion->set_charset('utf8mb4');

        return $conexion;
    } catch (mysqli_sql_exception $excepcion) {
        error_log('Error de conexión MySQL: ' . $excepcion->getMessage());

        throw new RuntimeException(
            'No fue posible conectar con la base de datos. Verifica la configuración de acceso.',
            0,
            $excepcion
        );
    }
}

// medicdate.innego.net/web/apis/medicdate/config/conexion.php
// medicdate.innego.net/web/apis/movil/medicdate_apis/config/conexion.php