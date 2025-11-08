<?php
// bot_watcher.php
require_once __DIR__ . '/vendor/autoload.php';

class BotWatcher
{
    private $botProcess = null;
    private $startTime = null;
    private $maxUptime = 6 * 60 * 60; // 6 ساعت
    private $restartCount = 0;
    private $maxRestarts = 10;

    public function start()
    {
        echo "🤖 Starting Bot Watcher...\n";
        
        while ($this->restartCount < $this->maxRestarts) {
            try {
                $this->startBot();
                $this->monitor();
            } catch (Exception $e) {
                echo "❌ Watcher error: " . $e->getMessage() . "\n";
                sleep(30);
            }
            
            $this->restartCount++;
            echo "🔄 Restarting bot ({$this->restartCount}/{$this->maxRestarts})...\n";
            sleep(5);
        }
        
        echo "🚨 Maximum restarts reached. Exiting.\n";
    }

    private function startBot()
    {
        $this->startTime = time();
        $this->botProcess = proc_open('php auto_bot.php', [
            ['pipe', 'r'], // stdin
            ['pipe', 'w'], // stdout
            ['pipe', 'w']  // stderr
        ], $pipes);

        if (!is_resource($this->botProcess)) {
            throw new Exception('Failed to start bot process');
        }

        echo "✅ Bot process started\n";
    }

    private function monitor()
    {
        while (true) {
            // چک کردن وضعیت process
            $status = proc_get_status($this->botProcess);
            
            if (!$status['running']) {
                echo "❌ Bot process stopped\n";
                break;
            }

            // چک کردن uptime
            if (time() - $this->startTime > $this->maxUptime) {
                echo "⏰ Max uptime reached, restarting...\n";
                $this->stopBot();
                break;
            }

            // خواندن خروجی
            $this->readOutput();
            
            sleep(10);
        }
    }

    private function readOutput()
    {
        // می‌توانی اینجا لاگ‌ها را پردازش کنی
    }

    private function stopBot()
    {
        if (is_resource($this->botProcess)) {
            proc_terminate($this->botProcess);
            proc_close($this->botProcess);
        }
    }
}

// اجرای watcher
$watcher = new BotWatcher();
$watcher->start();