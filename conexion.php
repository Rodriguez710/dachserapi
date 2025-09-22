<?php

class Cconexion
{
    public static function ConexionBD()
    {
        $host = 'localhost';
        $dbname = 'FOSSIL';
        $username = 'daniel';
        $password = 'daniel0987';
        $puerto = 1433;

        try {
            $conn = new PDO("sqlsrv:Server=$host,$puerto;Database=$dbname", $username, $password);
        } catch (PDOException $exp) {
            echo "No se ha conectado a la base de datos: $dbname, error: " . $exp->getMessage();
        }

        return $conn;
    }
}
?>
