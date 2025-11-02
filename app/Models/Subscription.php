<?php
namespace App\Models;

class Subscription
{
    public $id;
    public $user_id;
    public $plan_type;
    public $cost;
    public $expires_at;
    public $is_active;
    public $created_at;
    public $updated_at;

    public static function hasActiveSubscription($userId)
    {
        $pdo = self::getPDO();
        $sql = "SELECT COUNT(*) as count FROM subscriptions 
                WHERE user_id = ? AND is_active = 1 
                AND (expires_at IS NULL OR expires_at > NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        return $result['count'] > 0;
    }

    public static function where($column, $value)
    {
        $pdo = self::getPDO();
        $sql = "SELECT * FROM subscriptions WHERE {$column} = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$value]);
        
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $objects = [];
        
        foreach ($results as $result) {
            $subscription = new self();
            foreach ($result as $key => $value) {
                $subscription->$key = $value;
            }
            $objects[] = $subscription;
        }
        
        return $objects;
    }

    public static function first()
    {
        $pdo = self::getPDO();
        $sql = "SELECT * FROM subscriptions LIMIT 1";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            $subscription = new self();
            foreach ($result as $key => $value) {
                $subscription->$key = $value;
            }
            return $subscription;
        }
        
        return null;
    }

    public static function find($id)
    {
        $pdo = self::getPDO();
        $sql = "SELECT * FROM subscriptions WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($result) {
            $subscription = new self();
            foreach ($result as $key => $value) {
                $subscription->$key = $value;
            }
            return $subscription;
        }
        
        return null;
    }

    public static function create($data)
    {
        $pdo = self::getPDO();
        
        $sql = "INSERT INTO subscriptions 
                (user_id, plan_type, cost, expires_at, is_active, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $pdo->prepare($sql);
        
        return $stmt->execute([
            $data['user_id'],
            $data['plan_type'] ?? 'basic',
            $data['cost'] ?? 0,
            $data['expires_at'] ?? null,
            $data['is_active'] ?? 1
        ]);
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
        
        $sql = "UPDATE subscriptions SET " . implode(', ', $setParts) . " WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($values);
    }

    public function delete()
    {
        $pdo = self::getPDO();
        $sql = "DELETE FROM subscriptions WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$this->id]);
    }

    public static function count()
    {
        $pdo = self::getPDO();
        $sql = "SELECT COUNT(*) as count FROM subscriptions";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        
        return $result ? $result['count'] : 0;
    }

    public function isValid()
    {
        return $this->is_active && 
               ($this->expires_at === null || strtotime($this->expires_at) > time());
    }

    public static function getActiveSubscriptions($userId)
    {
        $pdo = self::getPDO();
        $sql = "SELECT * FROM subscriptions 
                WHERE user_id = ? AND is_active = 1 
                AND (expires_at IS NULL OR expires_at > NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        
        $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $objects = [];
        
        foreach ($results as $result) {
            $subscription = new self();
            foreach ($result as $key => $value) {
                $subscription->$key = $value;
            }
            $objects[] = $subscription;
        }
        
        return $objects;
    }

    public static function createTrialSubscription($userId, $days = 7)
    {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$days} days"));
        
        return self::create([
            'user_id' => $userId,
            'plan_type' => 'trial',
            'cost' => 0,
            'expires_at' => $expiresAt,
            'is_active' => 1
        ]);
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