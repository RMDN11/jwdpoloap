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

    // PERBAIKAN: Menambahkan parameter $userName agar nama pengirim bisa ditangkap
    public function processIncomingMessage($contactId, $message, $userName = 'Kak')
    {
        $this->logToFile("=== START AUTO-REPLY ===");
        $this->logToFile("Contact: {$contactId}, Message: " . substr($message, 0, 100));

        try {
            if (empty($contactId) || empty($message)) {
                $this->logToFile("Auto-reply skipped: empty contact or message");
                return false;
            }

            $phone = $this->normalizePhoneNumber($contactId);
            $message = trim($message);

            $this->logToFile("Normalized phone: {$phone}");

            // Cek koneksi DB
            if (!$this->isDbConnected()) {
                $this->logToFile("DB Connection failed, skipping auto-reply");
                return false;
            }

            if ($this->isContactBlocked($phone)) {
                $this->logToFile("Auto-reply skipped: contact is blocked");
                $this->logAutoReply($phone, $message, null, 'blocked', null);
                return false;
            }

            // Mencari aturan/keyword di database
            $rule = $this->findMatchingRule($message);
            if (!$rule) {
                $this->logToFile("Auto-reply skipped: no matching rule found");
                $this->logAutoReply($phone, $message, null, 'no_rule', null);
                return false;
            }

            $this->logToFile("Rule found: ID={$rule['id']}, Keyword={$rule['keyword']}, Reply=" . substr($rule['reply'], 0, 50));

            $replyText = trim($rule['reply'] ?? '');
            
            // Jika balasan kosong di database
            if ($replyText === '') {
                $this->logToFile("Auto-reply skipped: empty reply (rule ID {$rule['id']})");
                $this->logAutoReply($phone, $message, null, 'empty_reply', $rule['id']);
                return false;
            }
            
            // PERBAIKAN: Pindahkan kode ini ke LUAR blok if agar tereksekusi dengan benar
            // Ubah format tag nama menjadi nama WhatsApp asli
            $replyText = str_ireplace(['{nama}', '[nama]'], $userName, $replyText);
            
            // Kirim pesan ke API OneSender
            $sent = $this->sendViaOneSender($phone, $replyText);
            $status = $sent ? 'sent' : 'failed';

            $this->logAutoReply($phone, $message, $replyText, $status, (int)($rule['id'] ?? 0));
            $this->logToFile("Auto-reply {$status} (rule ID {$rule['id']})");

            return $sent;
        } catch (Throwable $e) {
            $this->logToFile("ERROR: " . $e->getMessage());
            $this->logToFile("FILE: " . $e->getFile() . " LINE: " . $e->getLine());
            return false;
        }
    }

    public function sendTestMessage($phone, $text)
    {
        $this->logToFile("=== TEST MESSAGE ===");
        $phone = $this->normalizePhoneNumber($phone);
        $this->logToFile("Sending test to: {$phone}");
        return $this->sendViaOneSender($phone, $text);
    }

    private function isDbConnected()
    {
        return isset($this->conn) && 
               $this->conn instanceof mysqli && 
               !$this->conn->connect_error &&
               $this->conn->ping();
    }

    private function normalizePhoneNumber($phone)
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (substr($phone, 0, 2) === '08') {
            $phone = '62' . substr($phone, 1);
        } elseif (substr($phone, 0, 1) === '0') {
            $phone = '62' . substr($phone, 1);
        } elseif (substr($phone, 0, 3) === '620') {
            $phone = '62' . substr($phone, 3);
        } elseif (substr($phone, 0, 2) !== '62') {
            $phone = '62' . $phone;
        }
        return $phone;
    }

    private function findMatchingRule($message)
    {
        if (!$this->isDbConnected()) {
            $this->logToFile("DB Connection failed in findMatchingRule()");
            return null;
        }

        $sql = "SELECT * FROM auto_reply_rules WHERE is_active = 1 ORDER BY priority DESC, id DESC";
        $res = $this->conn->query($sql);
        
        if (!$res) {
            $this->logToFile("DB Query Error: " . $this->conn->error);
            return null;
        }

        if ($res->num_rows === 0) {
            $this->logToFile("No active rules found in database");
            return null;
        }

        $message = strtolower($message);
        $this->logToFile("Searching for keyword in message: '{$message}'");

        while ($row = $res->fetch_assoc()) {
            $keywords = explode('|', $row['keyword'] ?? '');
            
            foreach ($keywords as $kw) {
                $kw = trim(strtolower($kw));
                if ($kw !== '' && strpos($message, $kw) !== false) {
                    $this->logToFile("✓ Keyword match found: '{$kw}' in rule ID {$row['id']}");
                    return $row;
                }
            }
        }
        
        $this->logToFile("✗ No matching keyword found");
        return null;
    }

    private function sendViaOneSender($phone, $text)
    {
        // Format payload sesuai dokumentasi OneSender
        $payload = json_encode([
            "recipient_type" => "individual",
            "to" => $phone,
            "type" => "text",
            "text" => [
                "body" => $text
            ]
        ], JSON_UNESCAPED_UNICODE);

        $this->logToFile("Sending to API: {$this->apiUrl}");
        $this->logToFile("Payload: " . $payload);

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiToken
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,  // Untuk development
            CURLOPT_SSL_VERIFYHOST => 0,       // Untuk development
            CURLOPT_FAILONERROR    => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'AutoReplyEngine/1.0',
            CURLOPT_HEADER         => true,
        ]);

        $response = curl_exec($ch);
        
        $curlErrNo = curl_errno($ch);
        $curlError = curl_error($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrNo !== 0) {
            $this->logToFile("❌ cURL Error ({$curlErrNo}): {$curlError}");
            return false;
        }

        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        $this->logToFile("HTTP Code: {$httpCode}");
        $this->logToFile("Response Body: " . substr($body, 0, 500));

        if ($httpCode !== 200 && $httpCode !== 201) {
            $errorMsg = 'Unknown error';
            $responseData = json_decode($body, true);
            if ($responseData) {
                $errorMsg = $responseData['message'] ?? 
                           $responseData['error'] ?? 
                           $responseData['description'] ?? 
                           json_encode($responseData);
            }
            $this->logToFile("❌ OneSender API Error ({$httpCode}): {$errorMsg}");
            return false;
        }

        $data = json_decode($body, true);
        if ($data === null && $body !== '') {
            $this->logToFile("❌ OneSender API returned invalid JSON");
            return false;
        }

        $this->logToFile("✅ OneSender API Success");
        return true;
    }

    private function isContactBlocked($phone)
    {
        if (!$this->isDbConnected()) {
            $this->logToFile("DB Connection failed in isContactBlocked()");
            return false;
        }

        $stmt = $this->conn->prepare("SELECT 1 FROM blocked_peserta WHERE nowa = ? LIMIT 1");
        if (!$stmt) {
            $this->logToFile("DB Prepare Error (blocked): " . $this->conn->error);
            return false;
        }
        
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $result = $stmt->get_result();
        $isBlocked = $result->num_rows > 0;
        $stmt->close();
        
        return $isBlocked;
    }

    private function isContactRegistered($phone)
    {
        if (!$this->isDbConnected()) {
            $this->logToFile("DB Connection failed in isContactRegistered()");
            return false;
        }

        // PERBAIKAN: Gunakan dua placeholder untuk UNION
        $stmt = $this->conn->prepare("
            SELECT 1 FROM peserta WHERE nowa = ?
            UNION
            SELECT 1 FROM calon_peserta WHERE nowa = ?
            LIMIT 1
        ");
        
        if (!$stmt) {
            $this->logToFile("DB Prepare Error (registered): " . $this->conn->error);
            return false;
        }
        
        $stmt->bind_param("ss", $phone, $phone);  // ✅ FIXED: Dua placeholder, dua parameter
        $stmt->execute();
        $result = $stmt->get_result();
        $isRegistered = $result->num_rows > 0;
        $stmt->close();
        
        return $isRegistered;
    }

    private function logAutoReply($contact, $incoming, $reply, $status, $ruleId)
    {
        if (!$this->isDbConnected()) {
            $this->logToFile("DB Connection failed in logAutoReply()");
            return;
        }

        $stmt = $this->conn->prepare("
            INSERT INTO auto_reply_logs (contact_id, incoming_message, reply_message, status, rule_id, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        if (!$stmt) {
            $this->logToFile("DB Prepare Error (log): " . $this->conn->error);
            return;
        }
        
        $status = $status ?: 'unknown';
        $ruleId = $ruleId ?: 0;
        
        $stmt->bind_param("ssssi", $contact, $incoming, $reply, $status, $ruleId);
        $stmt->execute();
        
        if ($stmt->error) {
            $this->logToFile("DB Log Error: " . $stmt->error);
        }
        
        $stmt->close();
    }

    private function logToFile($msg)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMsg = "[{$timestamp}] {$msg}" . PHP_EOL;
        file_put_contents($this->logFile, $logMsg, FILE_APPEND);
    }
}