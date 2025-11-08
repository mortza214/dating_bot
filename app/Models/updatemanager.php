<?php
namespace App\Core;

class UpdateManager
{
    private $lastUpdateId = 0;
    private $storageFile;

    public function __construct()
    {
        $this->storageFile = __DIR__ . '/../../storage/last_update_id.txt';
        $this->loadLastUpdateId();
    }

    public function getLastUpdateId()
    {
        return $this->lastUpdateId;
    }

    public function saveLastUpdateId($updateId)
    {
        $this->lastUpdateId = $updateId;
        file_put_contents($this->storageFile, $updateId);
    }

    private function loadLastUpdateId()
    {
        if (file_exists($this->storageFile)) {
            $content = file_get_contents($this->storageFile);
            if (is_numeric($content)) {
                $this->lastUpdateId = (int)$content;
            }
        }
    }
}