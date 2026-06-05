<?php

class AutoReplyEngine
{
    private $conn;
    private $apiUrl;
    private $apiToken;
    private $logFile;

    // Konstruktor disesuaikan dengan kebutuhan manage_auto_reply.php dan webhook.php
    public function __construct($conn, $apiUrl, $apiToken, $logFile = null)
    {
        $this->conn     = $conn;
        $this->apiUrl   = rtrim(trim($apiUrl), '/');
        $this->apiToken = $apiToken;
        $this->logFile  = $logFile;
    }

    /**
     * Memproses pesan masuk dari Webhook (Sesuai Ilustrasi Panah No 2 & 3)
     */
    public function processIncomingMessage($contactPhone, $messageText)
    {
        // 1. Validasi Input Dasar (Gaya Skrip 1)
        $phone = $this->normalizePhone($contactPhone);
        $message = trim(strtolower($messageText));

        if (empty($phone) || empty($message)) {
            $this->log("Diabaikan: Nomor atau pesan kosong.");
            return false;
        }

        // 2. Pencocokan Trigger dari Database
        $matchedReply = $this->matchTriggerFromDB($message);

        if ($matchedReply === null) {
            $this->log("Diabaikan: Tidak ada keyword yang cocok untuk '{$message}'.");
            return false;
        }

        // 3. Kirim Balasan ke OneSender (Sesuai Ilustrasi Panah No 3)
        $sent = $this->sendText($phone, $matchedReply);
        
        $this->log("Auto-reply ke {$phone} " . ($sent ? "BERHASIL" : "GAGAL"));
        return $sent;
    }

    /**
     * Fitur Test Message dari Dashboard (Dipanggil oleh manage_auto_reply.php)
     */
    public function sendTestMessage($phone, $text)
    {
        $phone = $this->normalizePhone($phone);
        return $this->sendText($phone, $text);
    }

    /**
     * Pencocokan Keyword (Trigger) dengan Data di MySQL
     */
    private function matchTriggerFromDB(string $message): ?string
    {
        if (!$this->conn || $this->conn->connect_error) {
            $this->log("Error: Database tidak terkoneksi.");
            return null;
        }

        // Ambil rules yang aktif, urutkan berdasarkan prioritas
        $sql = "SELECT keyword, reply FROM auto_reply_rules WHERE is_active = 1 ORDER BY priority DESC, id DESC";
        $res = $this->conn->query($sql);
        
        if (!$res || $res->num_rows === 0) return null;

        while ($row = $res->fetch_assoc()) {
            // Pecah keyword jika menggunakan tanda '|' (Contoh: harga|biaya)
            $keywords = explode('|', strtolower($row['keyword']));
            
            foreach ($keywords as $kw) {
                $kw = trim($kw);
                // Jika keyword ada di dalam pesan (Contains Match)
                if ($kw !== '' && strpos($message, $kw) !== false) {
                    return $row['reply'];
                }
            }
        }
        
        return null; // Tidak ada yang cocok
    }

    /**
     * Eksekusi Pengiriman Pesan ke API OneSender (Mengadopsi kebersihan cURL Skrip 1)
     */
    private function sendText(string $to, string $message): bool
    {
        $payload = json_encode([
            'recipient_type' => 'individual',
            'to'             => $to,
            'type'           => 'text',
            'text'           => [
                'body' => $message
            ]
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
            CURLOPT_TIMEOUT        => 15 // Dipercepat agar webhook tidak timeout
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err || ($httpCode !== 200 && $httpCode !== 201)) {
            $this->log("API Error: HTTP {$httpCode} - {$err} - {$response}");
            return false;
        }

        return true;
    }

    /**
     * Normalisasi nomor telepon ke format internasional (62)
     */
    private function normalizePhone($phone)
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        }
        return $phone;
    }

    /**
     * Fungsi Log sederhana
     */
    private function log($msg)
    {
        if ($this->logFile) {
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($this->logFile, "[{$timestamp}] {$msg}\n", FILE_APPEND);
        }
    }
}