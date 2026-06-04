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
        $replyText = $this->getAutoReplyFromDB($messageText);
        if ($replyText) {
            return $this->sendReply($phoneNumber, $replyText);
        }
        $this->log("No matching rule found for message: {$messageText}");
        return false;
    }
    

    private function getAutoReplyFromDB($message) {
        if (!$this->db || !($this->db instanceof mysqli) || $this->db->connect_error) {
            $this->log("Database not available");
            return null;
        }
        $message = trim(strtolower($message));
        $query = "SELECT id, keyword, reply FROM auto_reply_rules WHERE is_active = 1 ORDER BY priority DESC, id ASC";
        $result = $this->db->query($query);
        if (!$result) {
            $this->log("Query error: " . $this->db->error);
            return null;
        }
        while ($row = $result->fetch_assoc()) {
            $keywordRaw = strtolower(trim($row['keyword']));
            $keywords = explode('|', $keywordRaw);
            foreach ($keywords as $kw) {
                $kw = trim($kw);
                if ($kw !== '' && strpos($message, $kw) !== false) {
                    $this->log("Matched rule ID {$row['id']} with keyword '{$kw}'");
                    return $row['reply'];
                }
            }
        }
        return null;
    }

    private function sendReply($to, $message) {
        $to = preg_replace('/\D/', '', $to);
        if (substr($to, 0, 1) === '0') {
            $to = '62' . substr($to, 1);
        }
        $this->log("Sending to: {$to}");
        $url = $this->apiUrl . '/api/v1/messages';
        $payload = [
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $message]
        ];
        $jsonPayload = json_encode($payload);
        $this->log("Payload: " . $jsonPayload);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error) {
            $this->log("cURL Error: {$error}");
            return false;
        }
        $this->log("HTTP Code: {$httpCode}, Response: {$response}");
        $responseData = json_decode($response, true);
        if ($responseData && isset($responseData['error'])) {
            $this->log("API Error: " . json_encode($responseData['error']));
            return false;
        }
        $success = ($httpCode >= 200 && $httpCode < 300);
        $this->log($success ? "Message sent successfully" : "Failed to send message");
        return $success;
    }
    
    public function sendTestMessage($to, $message) {
        return $this->sendReply($to, $message);
    }
}