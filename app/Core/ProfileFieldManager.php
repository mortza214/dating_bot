<?php
// app/Core/ProfileFieldManager.php

namespace App\Core;

use App\Models\User;
use App\Models\ProfileField;

class ProfileFieldManager
{
    public static function checkAndFixMissingFields()
    {
        $missingFields = self::findMissingFields();
        
        if (empty($missingFields)) {
            return "✅ همه فیلدها هماهنگ هستند";
        }
        
        return self::fixMissingFields($missingFields);
    }
    
    public static function findMissingFields()
    {
        $activeFields = ProfileField::where('is_active', true)->get();
        $userInstance = new User();
        $fillable = $userInstance->getFillable();
        
        $missingFields = [];
        
        foreach ($activeFields as $field) {
            if (!in_array($field->field_name, $fillable)) {
                $missingFields[] = [
                    'name' => $field->field_name,
                    'label' => $field->field_label,
                    'type' => $field->field_type
                ];
            }
        }
        
        return $missingFields;
    }
    
    public static function fixMissingFields($missingFields)
    {
        try {
            $pdo = self::getPDO();
            $results = [];
            
            foreach ($missingFields as $field) {
                $result = self::addFieldToTable($pdo, $field);
                $results[] = $result;
            }
            
            return implode("\n", $results);
            
        } catch (\Exception $e) {
            return "❌ خطا: " . $e->getMessage();
        }
    }
    
    private static function getPDO()
    {
        $host = 'localhost';
        $dbname = 'dating_system';
        $username = 'root';
        $password = '';
        
        $pdo = new \PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        
        return $pdo;
    }
    
    private static function addFieldToTable($pdo, $field)
    {
        $fieldType = self::getSQLType($field['type']);
        $sql = "ALTER TABLE users ADD COLUMN {$field['name']} {$fieldType}";
        
        try {
            $pdo->exec($sql);
            return "✅ فیلد {$field['label']} ({$field['name']}) اضافه شد";
        } catch (\Exception $e) {
            return "⚠️ فیلد {$field['label']}: " . $e->getMessage();
        }
    }
    
    private static function getSQLType($fieldType)
    {
        $types = [
            'text' => 'VARCHAR(255)',
            'number' => 'INT',
            'select' => 'VARCHAR(255)',
            'textarea' => 'TEXT'
        ];
        
        return $types[$fieldType] ?? 'VARCHAR(255)';
    }
}