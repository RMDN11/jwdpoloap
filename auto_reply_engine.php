<?php

class AutoReplyEngine
{
    private $conn;
    private $apiUrl;
    private $apiToken;
    private $logFile;

    public function __construct($conn, $apiUrl, $apiToken, $logFile)
    {
        $this->conn     = $conn;
        $this->apiUrl   = trim($apiUrl);
        $this->apiToken = $apiToken;
        $this->logFile  = $logFile;
    }

    public function processIncomingMessage($contactId, $message)
    {
        $this->logToFile("=== START AUTO-REPLY ===");
        
        try {
            $phone = preg_replace('/\D/', '', $contactId);
            $message = trim($message);

            if (!$this->isDbConnected()) {
                $this->logToFile("❌ DB Connection failed");
                return false;
            }

            if ($this->isContactBlocked($phone)) {
                $this->logToFile("Skipped: blocked contact");
                return false;
            }

            $rule = $this->findMatchingRule($message);
            if (!$rule) {
                $this->logToFile("Skipped: No matching keyword for '{$message}'");
                return false;
            }

            $replyText = trim($rule['reply'] ?? '');
            
            // Magic Replace [NAMA]
            $namaUser = ''; 
            if (preg_match('/nama saya\s+\*?([^\*\(]+)\*?\s*\(/i', $message, $matches)) {
                $namaUser = trim($matches[1]);
            }
            $replyText = str_replace(['[NAMA]', '[nama]', '[Nama]'], $namaUser, $replyText);
            $replyText = str_replace('  ', ' ', $replyText);

            $this->logToFile("Membalas dengan Rule ID {$rule['id']}");
            $sent = $this->sendViaOneSender($phone, $replyText);
            
            return $sent;
        } catch (Throwable $e) {
            $this->logToFile("ERROR: " . $e->getMessage());
            return false;
        }
    }

    private function sendViaOneSender($phone, $text)
    {
        // FORMAT PAYLOAD YANG SUDAH TERBUKTI BERHASIL DI POSTMAN
        $payload = json_encode([
            "recipient_type" => "individual",
            "to" => $phone,
            "type" => "text",
            "text" => [
                "body" => $text
            ]
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiToken
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->logToFile("API Response (HTTP {$httpCode}): " . $body);

        if ($httpCode >= 200 && $httpCode < 300) {
            $this->logToFile("✅ Pesan Berhasil Terkirim!");
            return true;
        } else {
            $this->logToFile("❌ Gagal Kirim!");
            return false;
        }
    }

    // Fungsi Pembantu Database
    private function isDbConnected() {
        return isset($this->conn) && $this->conn instanceof mysqli && !$this->conn->connect_error;
    }

    private function isContactBlocked($phone) {
        $stmt = $this->conn->prepare("SELECT 1 FROM blocked_peserta WHERE nowa = ? LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $res = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $res;
    }

    private function findMatchingRule($message) {
        $sql = "SELECT * FROM auto_reply_rules WHERE is_active = 1 ORDER BY priority DESC, id DESC";
        $res = $this->conn->query($sql);
        if (!$res) return null;

        $message = strtolower($message);
        while ($row = $res->fetch_assoc()) {
            $keywords = explode('|', $row['keyword'] ?? '');
            foreach ($keywords as $kw) {
                $kw = trim(strtolower($kw));
                if ($kw !== '' && strpos($message, $kw) !== false) {
                    return $row;
                }
            }
        }
        return null;
    }

    private function logToFile($msg) {
        file_put_contents($this->logFile, "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n", FILE_APPEND);
    }
}