<?php

// Create the shared MySQL connection once so every page reuses the same PDO instance.
function getDatabaseConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        // Throw database errors as exceptions so callers can handle failures explicitly.
        $pdo = new PDO(
            'mysql:host=localhost;dbname=bfar_exim;charset=utf8mb4',
            'root',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        foreach (['shippers', 'consignees'] as $table) {
            $columns = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('is_active', $columns, true)) {
                $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');
            }
        }
    }

    return $pdo;
}