<?php
namespace App\Models;

class UserSuggestion
{
    public $id;
    public $user_id;
    public $suggested_user_id;
    public $shown_count;
    public $last_shown_at;
    public $contact_requested;
    public $created_at;
    public $updated_at;

    public static function getAlreadyShownUsers($userId)
    {
        $pdo = self::getPDO();
        $sql = "SELECT suggested_user_id FROM user_suggestions 
                WHERE user_id = ? AND shown_count >= 1";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public static function create($userId, $suggestedUserId)
{
    $pdo = self::getPDO();
    
    // 🔴 اصلاح: استفاده از متد مستقیم به جای زنجیره کردن where()
    $existing = self::getByUserAndSuggested($userId, $suggestedUserId);
    
    if ($existing) {
        // اگر وجود داره، تعداد نمایش رو افزایش بده
        return self::incrementShownCount($userId, $suggestedUserId);
    } else {
        // اگر وجود نداره، ایجاد کن
        $sql = "INSERT INTO user_suggestions (user_id, suggested_user_id, shown_count, last_shown_at, created_at, updated_at) 
                VALUES (?, ?, 1, NOW(), NOW(), NOW())";
        
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$userId, $suggestedUserId]);
    }
}
    public static function getShownCount($userId, $suggestedUserId)
    {
        $pdo = self::getPDO();
        $sql = "SELECT shown_count FROM user_suggestions 
                WHERE user_id = ? AND suggested_user_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $suggestedUserId]);
        $result = $stmt->fetch();
        
        return $result ? $result['shown_count'] : 0;
    }

    public static function getUserSuggestionCount($userId)
    {
        $pdo = self::getPDO();
        $sql = "SELECT COUNT(*) as count FROM user_suggestions WHERE user_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        return $result ? $result['count'] : 0;
    }

    public static function where($column, $value)
    {
        $pdo = self::getPDO();
        $sql = "SELECT * FROM user_suggestions WHERE {$column} = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$value]);
        
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $objects = [];
        
        foreach ($results as $result) {
            $suggestion = new self();
            foreach ($result as $key => $value) {
                $suggestion->$key = $value;
            }
            $objects[] = $suggestion;
        }
        
        return $objects;
    }

    public static function first()
    {
        $pdo = self::getPDO();
        $sql = "SELECT * FROM user_suggestions LIMIT 1";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            $suggestion = new self();
            foreach ($result as $key => $value) {
                $suggestion->$key = $value;
            }
            return $suggestion;
        }
        
        return null;
    }

    private static function incrementShownCount($userId, $suggestedUserId)
    {
        $pdo = self::getPDO();
        $sql = "UPDATE user_suggestions 
                SET shown_count = shown_count + 1, last_shown_at = NOW(), updated_at = NOW()
                WHERE user_id = ? AND suggested_user_id = ?";
        
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$userId, $suggestedUserId]);
    }

    public static function find($id)
    {
        $pdo = self::getPDO();
        $sql = "SELECT * FROM user_suggestions WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            $suggestion = new self();
            foreach ($result as $key => $value) {
                $suggestion->$key = $value;
            }
            return $suggestion;
        }
        
        return null;
    }

    public static function all()
    {
        $pdo = self::getPDO();
        $sql = "SELECT * FROM user_suggestions";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $objects = [];
        
        foreach ($results as $result) {
            $suggestion = new self();
            foreach ($result as $key => $value) {
                $suggestion->$key = $value;
            }
            $objects[] = $suggestion;
        }
        
        return $objects;
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
        
        $sql = "UPDATE user_suggestions SET " . implode(', ', $setParts) . " WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($values);
    }

    public function delete()
    {
        $pdo = self::getPDO();
        $sql = "DELETE FROM user_suggestions WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$this->id]);
    }

    public static function count()
    {
        $pdo = self::getPDO();
        $sql = "SELECT COUNT(*) as count FROM user_suggestions";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        
        return $result ? $result['count'] : 0;
    }

    public static function getByUserAndSuggested($userId, $suggestedUserId)
    {
        $pdo = self::getPDO();
        $sql = "SELECT * FROM user_suggestions WHERE user_id = ? AND suggested_user_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $suggestedUserId]);
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            $suggestion = new self();
            foreach ($result as $key => $value) {
                $suggestion->$key = $value;
            }
            return $suggestion;
        }
        
        return null;
    }

    public static function markContactRequested($userId, $suggestedUserId)
    {
        $pdo = self::getPDO();
        $sql = "UPDATE user_suggestions 
                SET contact_requested = 1, updated_at = NOW()
                WHERE user_id = ? AND suggested_user_id = ?";
        
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$userId, $suggestedUserId]);
    }

    public static function getContactRequests($userId)
    {
        $pdo = self::getPDO();
        $sql = "SELECT * FROM user_suggestions 
                WHERE user_id = ? AND contact_requested = 1 
                ORDER BY updated_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $objects = [];
        
        foreach ($results as $result) {
            $suggestion = new self();
            foreach ($result as $key => $value) {
                $suggestion->$key = $value;
            }
            $objects[] = $suggestion;
        }
        
        return $objects;
    }

    public function __get($name)
    {
        if (property_exists($this, $name)) {
            return $this->$name;
        }
        return null;
    }

    public function __set($name, $value)
    {
        if (property_exists($this, $name)) {
            $this->$name = $value;
        }
    }

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
}