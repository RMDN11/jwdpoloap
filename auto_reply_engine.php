<?php
class AutoReplyEngine {
    private $db, $apiUrl, $apiToken, $logFile;

    public function __construct($db, $url, $token, $log) {
        $this->db = $db;
        $this->apiUrl = rtrim($url, '/');
        $this->apiToken = $token;
        $this->logFile = $log;
    }

    private function log($msg) {
        file_put_contents($this->logFile, "[" . date('Y-m-d H:i:s') . "] {$msg}\n", FILE_APPEND);
    }

    public function processIncomingMessage($phone, $text, $senderName) {
        $replyText = $this->getAutoReplyFromDB($text);
        
        if ($replyText) {
            // Penggantian Placeholder {nama}
            $finalReply = str_replace('{nama}', $senderName, $replyText);
            return $this->sendReply($phone, $finalReply);
        }
        return false;
    }

    private function getAutoReplyFromDB($message) {
        $msg = trim(strtolower($message));
        $query = "SELECT keyword, reply FROM auto_reply_rules WHERE is_active = 1";
        $result = $this->db->query($query);
        while ($row = $result->fetch_assoc()) {
            if (strpos($msg, strtolower($row['keyword'])) !== false) return $row['reply'];
        }
        return null;
    }

    private function sendReply($to, $message) {
        $payload = json_encode([
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $message]
        ]);

        $ch = curl_init($this->apiUrl . '/api/v1/messages');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: application/json'
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
}