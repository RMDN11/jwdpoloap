<?php

class AutoReplyEngine
{
    private $conn;
    private $apiUrl;
    private $apiToken;
    private $logFile;

    public function __construct($conn, $apiUrl, $apiToken, $logFile = null)
    {
        $this->conn     = $conn;
        $this->apiUrl   = rtrim(trim($apiUrl), '/');
        $this->apiToken = $apiToken;
        $this->logFile  = $logFile;
    }

    /**
     * FUNGSI UTAMA: Bertindak Langsung Sebagai Webhook Listener
     */
    public function listen(): void
    {
        // 1. Validasi Method (Hanya terima POST)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->respondJSON(['status' => 'error', 'reason' => 'Method Not Allowed']);
            return;
        }

        // 2. Tangkap & Parse Payload JSON dari OneSender
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        if (!is_array($data)) {
            http_response_code(400);
            $this->respondJSON(['status' => 'error', 'reason' => 'Invalid JSON']);
            return;
        }

        // 3. Ekstrak Data
        $senderPhone = trim($data['sender_phone'] ?? '');
        $messageText = trim($data['message_text'] ?? '');
        $senderName  = trim($data['from_name'] ?? 'Unknown');
        $isFromMe    = !empty($data['is_from_me']) && $data['is_from_me'] === true;

        // 4. Abaikan pesan dari diri sendiri
        if ($isFromMe) {
            $this->respondJSON(['status' => 'ignored', 'reason' => 'from_self']);
            return;
        }

        // Abaikan jika payload kosong
        if ($senderPhone === '' || $messageText === '') {
            $this->respondJSON(['status' => 'ignored', 'reason' => 'empty_payload']);
            return;
        }

        // Normalisasi
        $phone = $this->normalizePhone($senderPhone);
        $message = trim(strtolower($messageText));

        // [OPSIONAL] Simpan riwayat chat ke tabel log_wa
        $this->saveToLogWa($phone, $senderName, $messageText);

        // 5. Cek kecocokan Trigger dari Database
        $matchedReply = $this->matchTriggerFromDB($message);

        // Jika tidak ada kata kunci yang cocok, hentikan & abaikan
        if ($matchedReply === null) {
            $this->respondJSON(['status' => 'ignored', 'reason' => 'no_match_rule']);
            return;
        }

        // 6. Kirim Balasan (Auto Reply) ke API OneSender
        $sent = $this->sendText($phone, $matchedReply);
        $this->log("Auto-reply ke {$phone} " . ($sent ? "BERHASIL" : "GAGAL"));

        // 7. Berikan Respons Sukses ke Webhook OneSender
        $this->respondJSON([
            'status' => $sent ? 'success' : 'error',
            'action' => 'auto_reply',
            'to'     => $phone
        ]);
    }

    /**
     * Fitur Test Message dari Dashboard (manage_auto_reply.php)
     */
    public function sendTestMessage($phone, $text)
    {
        $phone = $this->normalizePhone($phone);
        return $this->sendText($phone, $text);
    }

    /**
     * Pencocokan Keyword dengan Database
     */
    private function matchTriggerFromDB(string $message): ?string
    {
        if (!$this->conn || $this->conn->connect_error) {
            $this->log("Error DB: Database tidak terkoneksi.");
            return null;
        }

        $sql = "SELECT keyword, reply FROM auto_reply_rules WHERE is_active = 1 ORDER BY priority DESC, id DESC";
        $res = $this->conn->query($sql);
        
        if (!$res || $res->num_rows === 0) return null;

        while ($row = $res->fetch_assoc()) {
            $keywords = explode('|', strtolower($row['keyword']));
            foreach ($keywords as $kw) {
                $kw = trim($kw);
                if ($kw !== '' && strpos($message, $kw) !== false) {
                    return $row['reply'];
                }
            }
        }
        
        return null; 
    }

    /**
     * Kirim Pesan via API
     */
    private function sendText(string $to, string $message): bool
    {
        $payload = json_encode([
            'recipient_type' => 'individual',
            'to'             => $to,
            'type'           => 'text',
            'text'           => ['body' => $message]
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiToken,
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload)
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 15
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err || ($httpCode !== 200 && $httpCode !== 201)) {
            $this->log("API Error: HTTP {$httpCode} - {$err}");
            return false;
        }

        return true;
    }

    /**
     * Simpan Riwayat Pesan Masuk
     */
    private function saveToLogWa($phone, $name, $message)
    {
        if ($this->conn && !$this->conn->connect_error) {
            $stmt = $this->conn->prepare("INSERT INTO log_wa (nowa, nama, message, created_at) VALUES (?, ?, ?, NOW())");
            if ($stmt) {
                $stmt->bind_param('sss', $phone, $name, $message);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    /**
     * Output JSON Standard
     */
    private function respondJSON(array $data)
    {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function normalizePhone($phone)
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        }
        return $phone;
    }

    private function log($msg)
    {
        if ($this->logFile) {
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($this->logFile, "[{$timestamp}] {$msg}\n", FILE_APPEND);
        }
    }
}