<?php
// config/database.php

class Database {
    private static $pdo = null;
    
    public static function getConnection() {
        if (self::$pdo === null) {
            try {
                $host = 'localhost';
                $dbname = 'dating_system';
                $username = 'root';
                $password = '';
                
                self::$pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $e) {
                die("Database connection failed: " . $e->getMessage());
            }
        }
        return self::$pdo;
    }
}