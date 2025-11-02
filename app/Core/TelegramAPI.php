<?php
namespace App\Core;

class TelegramAPI
{
    private $token;
    private $apiUrl;

    public function __construct($token)
    {
        $this->token = $token;
        $this->apiUrl = "https://api.telegram.org/bot{$token}/";
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

    public function sendPhoto($chatId, $photo, $caption = '')
    {
        $url = $this->baseUrl . 'sendPhoto';
        $data = [
            'chat_id' => $chatId,
            'photo' => $photo,
            'caption' => $caption,
            'parse_mode' => 'Markdown'
        ];

        $result = $this->sendRequest($url, $data);
        error_log("📡 پاسخ sendPhoto: " . json_encode($result));
        return $result;
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