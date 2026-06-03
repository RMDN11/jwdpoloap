<?php

class AutoReplyEngine {
    private $db;
    private $apiUrl;
    private $apiToken;
    private $logFile;

    public function __construct($dbConnection, $apiUrl, $apiToken, $logFile) {
        $this->db = $dbConnection;
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiToken = $apiToken;
        $this->logFile = $logFile;
    }

    private function log($msg) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->logFile, "[{$timestamp}] {$msg}\n", FILE_APPEND);
    }

    public function processIncomingMessage($phoneNumber, $messageText) {
        $this->log("Processing message from {$phoneNumber}: {$messageText}");
        
        $replyText = $this->getAutoReply($messageText);
        if ($replyText) {
            return $this->sendReply($phoneNumber, $replyText);
        }
        return false;
    }

    private function getAutoReply($message) {
        $lowerMsg = strtolower(trim($message));
        switch ($lowerMsg) {
            case 'halo':
                return "Halo! Ada yang bisa kami bantu?";
            case 'info':
                return "Kami melayani berbagai produk dan layanan. Silakan kirim pesan lebih detail.";
            default:
                return "Terima kasih pesannya. Kami akan segera merespon.";
        }
    }

    private function sendReply($to, $message) {
        $url = $this->apiUrl . '/api/v1/messages';
        $payload = [
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $message]
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            $this->log("cURL Error: {$error}");
            return false;
        }
        
        $this->log("Send reply to {$to}: HTTP {$httpCode}, Response: {$response}");
        return ($httpCode >= 200 && $httpCode < 300);
    }
}