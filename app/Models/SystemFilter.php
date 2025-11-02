<?php
namespace App\Models;

class SystemFilter
{
    protected $table = 'system_filters';
    
    public static function getActiveFilters()
    {
        $pdo = self::getPDO();
        $sql = "SELECT * FROM system_filters WHERE is_active = 1 ORDER BY sort_order";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }
    
    public static function getFilterByFieldName($fieldName)
    {
        $pdo = self::getPDO();
        $sql = "SELECT * FROM system_filters WHERE field_name = ? AND is_active = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$fieldName]);
        return $stmt->fetch(\PDO::FETCH_OBJ);
    }

    // 🔴 متد جدید: ایجاد فیلترهای سیستم
    public static function createSystemFilter($field, $filterType)
    {
        $pdo = self::getPDO();
        
        $sql = "INSERT INTO system_filters (field_name, field_label, filter_type, options, is_active, sort_order, created_at, updated_at) 
                VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())";
        
        $stmt = $pdo->prepare($sql);
        
        // تعیین options بر اساس نوع فیلتر
        $options = null;
        if ($filterType === 'select') {
            if ($field->field_name === 'gender') {
                $options = json_encode(['مرد', 'زن']);
            } elseif ($field->field_name === 'city') {
                $options = json_encode((new self)->getDefaultCities());
            } else {
                $fieldOptions = json_decode($field->options, true) ?? [];
                $options = json_encode($fieldOptions);
            }
        }
        
        // محاسبه sort_order
        $maxOrder = self::getMaxSortOrder();
        $sortOrder = $maxOrder + 1;
        
        return $stmt->execute([
            $field->field_name,
            $field->field_label,
            $filterType,
            $options,
            $sortOrder
        ]);
    }

    private static function getMaxSortOrder()
    {
        $pdo = self::getPDO();
        $sql = "SELECT MAX(sort_order) as max_order FROM system_filters";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result->max_order ?? 0;
    }

    private function getDefaultCities()
    {
        return [
            'تهران', 'مشهد', 'اصفهان', 'شیراز', 'تبریز', 'کرج', 'قم', 'اهواز',
            'کرمانشاه', 'ارومیه', 'رشت', 'زاهدان', 'کرمان', 'همدان', 'اراک',
            'یزد', 'اردبیل', 'بندرعباس', 'قدس', 'خرم‌آباد', 'ساری', 'گرگان'
        ];
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
}