<?php
namespace App\Core;

class TelegramAPI
{
    private $token;
    private $apiUrl;
    protected $baseUrl;

    public function __construct($token)
    {
        $this->token = $token;
        $this->apiUrl = "https://api.telegram.org/bot{$token}/";
    }
    public function getBotToken()
{
    return $this->token;
}

    public function sendMessage($chatId, $text, $replyMarkup = null)
    {
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];

        // اضافه کردن کیبورد اگر وجود دارد
        if ($replyMarkup !== null) {
            $data['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->request('sendMessage', $data);
    }
private function makeRequest($url, $data)
{
    error_log("🌐 Making request to: " . $url);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    // برای دیباگ بیشتر
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_error($ch)) {
        $error = curl_error($ch);
        error_log('❌ CURL Error: ' . $error);
        
        // خواندن اطلاعات verbose برای دیباگ
        rewind($verbose);
        $verboseLog = stream_get_contents($verbose);
        error_log("🔍 CURL Verbose: " . $verboseLog);
        
        curl_close($ch);
        fclose($verbose);
        return false;
    }
    
    curl_close($ch);
    fclose($verbose);

    error_log("📡 Response HTTP Code: " . $httpCode);

    if ($httpCode !== 200) {
        error_log("❌ Telegram API Error: HTTP {$httpCode} - Response: {$response}");
        return false;
    }

    error_log("✅ Request successful");
    return $response;
}
    public function getUpdates($offset = null)
    {
        $data = [];
        if ($offset) {
            $data['offset'] = $offset;
        }

        $data['timeout'] = 10;

        return $this->request('getUpdates', $data);
    }

    public function deleteWebhook()
    {
        return $this->request('deleteWebhook');
    }

    public function answerCallbackQuery($callbackQueryId, $text = null, $showAlert = false)
    {
        $data = [
            'callback_query_id' => $callbackQueryId
        ];

        if ($text) {
            $data['text'] = $text;
        }

        if ($showAlert) {
            $data['show_alert'] = true;
        }

        return $this->request('answerCallbackQuery', $data);
    }

    private function request($method, $data = [])
    {
        $url = $this->apiUrl . $method;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_error($ch)) {
            error_log("CURL Error: " . curl_error($ch));
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("Telegram API Error: HTTP $httpCode - $response");
            return false;
        }

        return json_decode($response, true);
    }

    public function getUserProfilePhotos($userId, $offset = 0, $limit = 1)
    {
        $url = $this->baseUrl . 'getUserProfilePhotos';
        $data = [
            'user_id' => $userId,
            'offset' => $offset,
            'limit' => $limit
        ];

        $result = $this->sendRequest($url, $data);
        error_log("📡 پاسخ getUserProfilePhotos: " . json_encode($result));
        return $result;
    }

    public function sendPhoto($chatId, $photo, $caption = null, $replyMarkup = null)
{
    // 🔴 مطمئن شو baseUrl درست ساخته شده است
    if (empty($this->baseUrl)) {
        $this->baseUrl = 'https://api.telegram.org/bot' . $this->token . '/';
    }
    
    $url = $this->baseUrl . 'sendPhoto';
    
    error_log("📤 Sending photo to URL: " . $url);
    error_log("📸 Photo file_id: " . $photo);
    error_log("💬 Chat ID: " . $chatId);

    $data = [
        'chat_id' => $chatId,
        'photo' => $photo
    ];

    if ($caption) {
        $data['caption'] = $caption;
        $data['parse_mode'] = 'Markdown';
    }

    if ($replyMarkup) {
        $data['reply_markup'] = json_encode($replyMarkup);
    }

    $response = $this->makeRequest($url, $data);
    error_log("📡 Photo send response: " . $response);
    
    return $response;
}
    private function sendRequest($url, $data)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_error($ch)) {
            error_log("❌ خطای cURL: " . curl_error($ch));
        }
        
        curl_close($ch);
        
        return json_decode($response, true);
    }

}