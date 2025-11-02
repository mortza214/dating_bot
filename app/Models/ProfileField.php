<?php
namespace App\Models;

class ProfileField
{
    protected static $table = 'profile_fields';

   public static function getActiveFields()
{
    // اگر از Eloquent استفاده می‌کنید:
    // return self::where('is_active', 1)->orderBy('sort_order')->get();
    
    // اگر از PDO مستقیم استفاده می‌کنید:
    $pdo = self::getPDO();
    $sql = "SELECT * FROM profile_fields WHERE is_active = 1 ORDER BY sort_order";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_OBJ);
}

    public static function max($column)
    {
        $pdo = self::getPDO();
        $sql = "SELECT MAX($column) as max_value FROM profile_fields";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        
        return $result ? $result['max_value'] : 0;
    }

    public static function create($data)
{
    $pdo = self::getPDO();
    
    $sql = "INSERT INTO profile_fields 
            (field_name, field_label, field_type, is_required, is_active, is_hidden_for_non_subscribers, sort_order, options, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    
    return $stmt->execute([
        $data['field_name'],
        $data['field_label'], 
        $data['field_type'],
        $data['is_required'],
        $data['is_active'] ? 1 : 0,
        $data['is_hidden_for_non_subscribers'] ?? 0, // 🔴 اضافه شده
        $data['sort_order'],
        $data['options'] ?? null,
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s')
    ]);
}



    public static function first()
    {
        $pdo = self::getPDO();
        $sql = "SELECT * FROM profile_fields LIMIT 1";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            $field = new self();
            foreach ($result as $key => $value) {
                $field->$key = $value;
            }
            return $field;
        }
        
        return null;
    }

    public static function orderBy($column, $direction = 'ASC')
{
    $pdo = self::getPDO();
    $sql = "SELECT * FROM profile_fields ORDER BY {$column} {$direction}";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    
    $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    $objects = [];
    
    foreach ($results as $result) {
        $field = new self();
        foreach ($result as $key => $value) {
            $field->$key = $value;
        }
        $objects[] = $field;
    }
    
    return $objects;
}
    public static function count()
    {
        $pdo = self::getPDO();
        $sql = "SELECT COUNT(*) as count FROM profile_fields";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        
        return $result ? $result['count'] : 0;
    }

    // 🔴 اضافه کردن property های dynamic برای سازگاری
    public function __get($name)
    {
        if (property_exists($this, $name)) {
            return $this->$name;
        }
        return null;
    }

    public function validate($value)
{
    if ($this->is_required && empty($value)) {
        return "فیلد {$this->field_label} الزامی است";
    }
    
    if ($this->field_type === 'number' && !empty($value)) {
        if (!is_numeric($value)) {
            return "مقدار باید عددی باشد";
        }
    }
    // اعتبارسنجی برای فیلد موبایل
    if ($this->field_name === 'mobile' && !empty($value)) {
        // حذف کاراکترهای غیرعددی برای چک کردن
        $cleaned = preg_replace('/[^0-9]/', '', $value);
        
        // چک کردن طول شماره موبایل (برای ایران معمولاً 11 رقم)
        if (strlen($cleaned) < 10 || strlen($cleaned) > 12) {
            return "شماره موبایل باید بین ۱۰ تا ۱۲ رقم باشد";
        }
        
        // چک کردن شروع شماره (برای ایران معمولاً 09 یا +98)
        if (!preg_match('/^(09|\+98|98)/', $value)) {
            return "شماره موبایل باید با 09 یا +98 شروع شود";
        }
    }
    
    // اعتبارسنجی برای فیلدهای select
    if ($this->field_type === 'select' && !empty($value)) {
        $options = $this->getOptionsArray();
        if (!empty($options)) {
            $index = intval($value) - 1;
            if ($index < 0 || $index >= count($options)) {
                return "گزینه انتخاب شده معتبر نیست. لطفاً عدد بین ۱ تا " . count($options) . " وارد کنید.";
            }
        }
    }
    
    return true;
}

    // 🔴 اضافه کردن متد getPDO
    private static function getPDO()
    {
        static $pdo;
        if (!$pdo) {
            $host = 'localhost';
            $dbname = 'dating_system';
            $username = 'root';
            $password = '';
            
            $pdo = new \PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
        return $pdo;
    }

    // 🔴 اضافه کردن متد find برای پیدا کردن فیلد بر اساس field_name
    public static function whereFieldName($fieldName)
    {
        $pdo = self::getPDO();
        $sql = "SELECT * FROM profile_fields WHERE field_name = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$fieldName]);
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            $field = new self();
            foreach ($result as $key => $value) {
                $field->$key = $value;
            }
            return $field;
        }
        
        return null;
    }

    // اضافه کردن متد کمکی برای گرفتن آرایه گزینه‌ها
private function getOptionsArray()
{
    if (is_string($this->options)) {
        $decoded = json_decode($this->options, true);
        return is_array($decoded) ? $decoded : [];
    }
    
    return is_array($this->options) ? $this->options : [];
}

public function getOptions()
{
    return $this->getOptionsArray();
}
public static function find($id)
{
    $pdo = self::getPDO();
    $sql = "SELECT * FROM profile_fields WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    
    $result = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($result) {
        $field = new self();
        foreach ($result as $key => $value) {
            $field->$key = $value;
        }
        return $field;
    }
    
    return null;
}

public function update($data)
{
    $pdo = self::getPDO();
    
    $setParts = [];
    $values = [];
    
    foreach ($data as $key => $value) {
        $setParts[] = "{$key} = ?";
        $values[] = $value;
    }
    
    $values[] = $this->id;
    
    $sql = "UPDATE profile_fields SET " . implode(', ', $setParts) . " WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($values);
}
public static function getAllFields()
{
    // اگر از Eloquent استفاده می‌کنید:
    // return self::all();

    // اگر از PDO مستقیم استفاده می‌کنید:
    $pdo = self::getPDO();
    $sql = "SELECT * FROM profile_fields ORDER BY sort_order";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(\PDO::FETCH_OBJ);
}
}