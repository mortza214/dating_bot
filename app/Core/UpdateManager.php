<?php
namespace App\Core;

class UpdateManager
{
    private $filePath;

    public function __construct()
    {
        $this->filePath = __DIR__ . '/../../storage/last_update_id.txt';
        
        // ایجاد پوشه storage اگر وجود ندارد
        $storageDir = dirname($this->filePath);
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }
    }

    public function getLastUpdateId()
    {
        if (file_exists($this->filePath)) {
            return (int) file_get_contents($this->filePath);
        }
        return 0;
    }

    public function saveLastUpdateId($updateId)
    {
        file_put_contents($this->filePath, $updateId);
    }
}