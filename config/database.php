<?php
$host = ('DB_HOST') ?: 'localhost';
$db   = ('DB_NAME') ?: 'nims';
$user = ('DB_USER') ?: 'root';
$pass = ('DB_PASS') ?: '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

function db(): PDO {
    global $dsn, $user, $pass, $options;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO($dsn, $user, $pass, $options);
    }
    return $pdo;
}
