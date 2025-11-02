<?php
// check_database.php

try {
    $pdo = new PDO("mysql:host=localhost;dbname=dating_system", "root", "");
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Columns in users table:\n";
    foreach ($columns as $column) {
        echo "- {$column['Field']} ({$column['Type']})\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}