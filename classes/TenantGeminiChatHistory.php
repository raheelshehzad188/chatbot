<?php

/**
 * Multi-tenant isolated chat history for Gemini: one JSON blob per store + customer.
 * Storage: Redis (if extension available and connected) or JSON files under a base path.
 * Keys: history_{store_id}_{sanitized_phone}.json (files) / same logical key in Redis.
 */
class TenantGeminiChatHistory
{
    /** @var string */
    protected $storageDir;

    /** @var \Redis|null */
    protected $redis;

    /** @var string Redis key prefix */
    protected $redisPrefix = 'tenant_gemini:';

    /** @var int Max messages to keep (user + model pairs count as 2) */
    protected $maxMessages;

    /** @var string */
    protected $model;

    /** @var string */
    protected $apiBaseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(
        string $storageDir,
        ?\Redis $redis = null,
        int $maxMessages = 40,
        string $model = 'gemini-2.5-flash'
    ) {
        $this->storageDir = rtrim($storageDir, DIRECTORY_SEPARATOR);
        $this->redis = $redis;
        $this->maxMessages = max(2, $maxMessages);
        $this->model = $model;

        if ($this->redis === null && extension_loaded('redis')) {
            $this->redis = $this->tryConnectRedisFromEnv();
        }

        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Attempt Redis connection from environment (REDIS_HOST, REDIS_PORT, REDIS_PASSWORD).
     */
    protected function tryConnectRedisFromEnv(): ?\Redis
    {
        $host = getenv('REDIS_HOST');
        if ($host === false || $host === '') {
            return null;
        }
        try {
            $r = new \Redis();
            $port = (int) (getenv('REDIS_PORT') ?: 6379);
            $timeout = 1.5;
            if (@$r->connect($host, $port, $timeout)) {
                $pass = getenv('REDIS_PASSWORD');
                if ($pass !== false && $pass !== '') {
                    $r->auth($pass);
                }
                return $r;
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }

    public function setRedis(?\Redis $redis): self
    {
        $this->redis = $redis;
        return $this;
    }

    public function usesRedis(): bool
    {
        return $this->redis !== null;
    }

    /**
     * Filename / key fragment: history_{store_id}_{phone}.json (phone sanitized).
     */
    public function buildFilename(int $storeId, string $customerPhone): string
    {
        $safe = $this->sanitizePhone($customerPhone);
        return 'history_' . $storeId . '_' . $safe . '.json';
    }

    protected function sanitizePhone(string $phone): string
    {
        $s = preg_replace('/[^0-9a-zA-Z]+/', '_', trim($phone));
        $s = trim($s, '_');
        return $s !== '' ? $s : 'unknown';
    }

    protected function redisKey(int $storeId, string $customerPhone): string
    {
        return $this->redisPrefix . $this->buildFilename($storeId, $customerPhone);
    }

    protected function filePath(int $storeId, string $customerPhone): string
    {
        return $this->storageDir . DIRECTORY_SEPARATOR . $this->buildFilename($storeId, $customerPhone);
    }

    /**
     * @return array<int, array{role: string, text: string}>
     */
    public function getMessages(int $storeId, string $customerPhone): array
    {
        $raw = $this->readRaw($storeId, $customerPhone);
        if ($raw === null || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        if (isset($data['messages']) && is_array($data['messages'])) {
            return $this->normalizeMessages($data['messages']);
        }
        return [];
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, array{role: string, text: string}>
     */
    protected function normalizeMessages(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $role = isset($row['role']) ? (string) $row['role'] : '';
            $text = isset($row['text']) ? (string) $row['text'] : '';
            if ($role === '' || $text === '') {
                continue;
            }
            if (!in_array($role, ['user', 'model'], true)) {
                continue;
            }
            $out[] = ['role' => $role, 'text' => $text];
        }
        return $out;
    }

    protected function readRaw(int $storeId, string $customerPhone): ?string
    {
        if ($this->redis !== null) {
            try {
                $key = $this->redisKey($storeId, $customerPhone);
                $v = $this->redis->get($key);
                return $v === false ? null : (string) $v;
            } catch (\Throwable $e) {
                // fall through to file
            }
        }

        $path = $this->filePath($storeId, $customerPhone);
        if (!is_readable($path)) {
            return null;
        }
        return (string) file_get_contents($path);
    }

    /**
     * Append a single turn (user or model). Trims to maxMessages.
     */
    public function appendMessage(int $storeId, string $customerPhone, string $role, string $text): void
    {
        if (!in_array($role, ['user', 'model'], true)) {
            throw new InvalidArgumentException('role must be "user" or "model"');
        }
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $this->withLock($storeId, $customerPhone, function () use ($storeId, $customerPhone, $role, $text) {
            $messages = $this->getMessagesUnlocked($storeId, $customerPhone);
            $messages[] = ['role' => $role, 'text' => $text];
            $messages = $this->trimMessages($messages);
            $this->writeMessages($storeId, $customerPhone, $messages);
        });
    }

    /**
     * @param array<int, array{role: string, text: string}> $messages
     */
    protected function trimMessages(array $messages): array
    {
        if (count($messages) <= $this->maxMessages) {
            return $messages;
        }
        return array_slice($messages, -$this->maxMessages);
    }

    /**
     * @param array<int, array{role: string, text: string}> $messages
     */
    protected function writeMessages(int $storeId, string $customerPhone, array $messages): void
    {
        $payload = json_encode(
            ['messages' => $messages],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        if ($this->redis !== null) {
            try {
                $this->redis->set($this->redisKey($storeId, $customerPhone), $payload);
                return;
            } catch (\Throwable $e) {
                // fallback file
            }
        }

        $path = $this->filePath($storeId, $customerPhone);
        $tmp = $path . '.' . uniqid('tmp', true);
        if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write chat history file');
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot finalize chat history file');
        }
    }

    /**
     * File-only mutual exclusion when not using Redis (Redis SET is single-key atomic enough for small payloads;
     * for file we use flock on a sibling lock file).
     */
    protected function withLock(int $storeId, string $customerPhone, callable $fn): void
    {
        if ($this->redis !== null) {
            $fn();
            return;
        }

        $path = $this->filePath($storeId, $customerPhone);
        $lockPath = $path . '.lock';
        $fh = fopen($lockPath, 'cb+');
        if ($fh === false) {
            throw new RuntimeException('Cannot open lock file');
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            throw new RuntimeException('Cannot lock chat history');
        }
        try {
            $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public function clear(int $storeId, string $customerPhone): void
    {
        if ($this->redis !== null) {
            try {
                $this->redis->del($this->redisKey($storeId, $customerPhone));
            } catch (\Throwable $e) {
                // ignore
            }
        }
        $p = $this->filePath($storeId, $customerPhone);
        if (is_file($p)) {
            @unlink($p);
        }
    }

    /**
     * Load store-specific system prompt from DB (adapt table/columns to your SaaS schema).
     * Example: stores.id + stores.system_prompt OR sub_admin_settings.system_instruction.
     *
     * @param \mysqli $conn
     * @param int $storeId Primary key of the store / tenant row
     * @param string $table Table name
     * @param string $idColumn PK column name
     * @param string $promptColumn Column holding the system prompt text
     */
    public static function fetchSystemPromptFromDb(
        \mysqli $conn,
        int $storeId,
        string $table = 'sub_admin_settings',
        string $idColumn = 'admin_id',
        string $promptColumn = 'system_instruction'
    ): ?string {
        $sql = "SELECT `$promptColumn` AS p FROM `$table` WHERE `$idColumn` = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $storeId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row || !isset($row['p'])) {
            return null;
        }
        $p = trim((string) $row['p']);
        return $p !== '' ? $p : null;
    }

    /**
     * Append user message, call Gemini with full history + systemInstruction, append model reply, return text.
     *
     * @param int $storeId Tenant / store identifier (isolates cache key)
     * @param string $customerPhone Customer phone (isolates cache key)
     * @param string $userMessage New user utterance
     * @param string $systemPrompt From your DB for this store (never mix stores)
     * @param string $masterApiKey Single platform Gemini API key
     * @return string Model text reply
     */
    public function reply(
        int $storeId,
        string $customerPhone,
        string $userMessage,
        string $systemPrompt,
        string $masterApiKey
    ): string {
        $userMessage = trim($userMessage);
        if ($userMessage === '') {
            return '';
        }

        $this->appendMessage($storeId, $customerPhone, 'user', $userMessage);

        $messages = $this->getMessages($storeId, $customerPhone);
        $contents = $this->toGeminiContents($messages);

        $postData = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ],
            'contents' => $contents,
        ];

        $url = $this->apiBaseUrl . $this->model . ':generateContent?key=' . rawurlencode($masterApiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || $httpCode !== 200) {
            // Roll back last user message so failed requests do not pollute history
            $this->removeLastMessageIfUser($storeId, $customerPhone);
            throw new RuntimeException(
                'Gemini request failed: HTTP ' . $httpCode . ($err ? ' ' . $err : '')
            );
        }

        $data = json_decode((string) $response, true);
        $text = '';
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            $text = trim((string) $data['candidates'][0]['content']['parts'][0]['text']);
        }

        if ($text !== '') {
            $this->appendMessage($storeId, $customerPhone, 'model', $text);
        }

        return $text;
    }

    /**
     * @param array<int, array{role: string, text: string}> $messages
     * @return array<int, array<string, mixed>>
     */
    protected function toGeminiContents(array $messages): array
    {
        $contents = [];
        foreach ($messages as $m) {
            $role = $m['role'] === 'model' ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $m['text']]],
            ];
        }
        return $contents;
    }

    protected function removeLastMessageIfUser(int $storeId, string $customerPhone): void
    {
        $this->withLock($storeId, $customerPhone, function () use ($storeId, $customerPhone) {
            $messages = $this->getMessagesUnlocked($storeId, $customerPhone);
            if ($messages === []) {
                return;
            }
            $last = $messages[count($messages) - 1];
            if ($last['role'] === 'user') {
                array_pop($messages);
                $this->writeMessages($storeId, $customerPhone, $messages);
            }
        });
    }

    /**
     * @return array<int, array{role: string, text: string}>
     */
    protected function getMessagesUnlocked(int $storeId, string $customerPhone): array
    {
        $raw = $this->readRaw($storeId, $customerPhone);
        if ($raw === null || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['messages']) || !is_array($data['messages'])) {
            return [];
        }
        return $this->normalizeMessages($data['messages']);
    }

}
