<?php
namespace App\Core;

class PerformanceMonitor
{
    private static $metrics = [];
    private static $startTime;
    
    public static function start($operation = 'total_request')
    {
        self::$startTime = microtime(true);
        self::$metrics[$operation] = [
            'start' => microtime(true),
            'end' => null,
            'duration' => null,
            'memory_start' => memory_get_usage(true),
            'memory_end' => null,
            'memory_peak' => null
        ];
    }
    
    public static function end($operation = 'total_request')
    {
        if (isset(self::$metrics[$operation])) {
            self::$metrics[$operation]['end'] = microtime(true);
            self::$metrics[$operation]['duration'] = 
                round((self::$metrics[$operation]['end'] - self::$metrics[$operation]['start']) * 1000, 2);
            self::$metrics[$operation]['memory_end'] = memory_get_usage(true);
            self::$metrics[$operation]['memory_peak'] = memory_get_peak_usage(true);
            
            self::logSlowOperation($operation);
        }
    }
    
    private static function logSlowOperation($operation)
    {
        $duration = self::$metrics[$operation]['duration'];
        $memory = round((self::$metrics[$operation]['memory_end'] - self::$metrics[$operation]['memory_start']) / 1024 / 1024, 2);
        
        if ($duration > 1000) { // بیش از 1 ثانیه
            error_log("🚨 PERFORMANCE ISSUE: {$operation} took {$duration}ms, Memory: {$memory}MB");
        } elseif ($duration > 500) { // بیش از 0.5 ثانیه
            error_log("⚠️ PERFORMANCE WARNING: {$operation} took {$duration}ms, Memory: {$memory}MB");
        } else {
            error_log("✅ PERFORMANCE OK: {$operation} took {$duration}ms");
        }
    }
    
    public static function getMetrics()
    {
        return self::$metrics;
    }
    
    public static function getSummary()
    {
        $totalDuration = round((microtime(true) - self::$startTime) * 1000, 2);
        $summary = "📊 **خلاصه عملکرد**\n\n";
        $summary .= "⏱️ زمان کل: {$totalDuration}ms\n";
        
        foreach (self::$metrics as $operation => $metric) {
            if ($metric['duration'] !== null) {
                $memoryUsed = round(($metric['memory_end'] - $metric['memory_start']) / 1024 / 1024, 2);
                $summary .= "• {$operation}: {$metric['duration']}ms (حافظه: {$memoryUsed}MB)\n";
            }
        }
        
        $summary .= "\n💾 اوج مصرف حافظه: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . "MB";
        
        return $summary;
    }
    
    public static function reset()
    {
        self::$metrics = [];
        self::$startTime = null;
    }
}