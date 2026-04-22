<?php
require_once 'config.php';

if (!function_exists('dd')) {
    /**
     * Laravel-style dump and die helper.
     */
    function dd(...$vars) {
        http_response_code(200);
        echo '<pre style="background:#111;color:#f8f8f2;padding:12px;border-radius:6px;overflow:auto;">';
        foreach ($vars as $var) {
            var_dump($var);
        }
        echo '</pre>';
        exit;
    }
}

/**
 * Process incoming data - Extract name, number and message from WhatsApp webhook
 * Returns an array with 'text', 'number', and 'name' keys
 */
function processIncomingData($data) {
    $text = '';
    $number = '';
    $name = '';
    
    // If data is empty, return empty result
    if (empty($data) || !is_array($data)) {
        return [
            'text' => $text,
            'number' => $number,
            'name' => $name
        ];
    }
    
    // Try different data structures
    
    // Structure 1: data.messages (original structure)
    if (isset($data['data']['messages'])) {
        $messages = $data['data']['messages'];
        
        // Extract name
        $name = isset($messages['pushName']) ? $messages['pushName'] : '';
        
        // Extract number from cleanedSenderPn or remoteJid
        if (isset($messages['key']['cleanedSenderPn'])) {
            $number = $messages['key']['cleanedSenderPn'];
        } elseif (isset($messages['remoteJid'])) {
            // Extract number from format: 923166143939@s.whatsapp.net
            $remoteJid = $messages['remoteJid'];
            $number = explode('@', $remoteJid)[0];
        } elseif (isset($messages['key']['remoteJid'])) {
            $remoteJid = $messages['key']['remoteJid'];
            $number = explode('@', $remoteJid)[0];
        }
        
        // Extract message text
        if (isset($messages['messageBody'])) {
            $text = $messages['messageBody'];
        } elseif (isset($messages['message']['extendedTextMessage']['text'])) {
            $text = $messages['message']['extendedTextMessage']['text'];
        } elseif (isset($messages['message']['conversation'])) {
            $text = $messages['message']['conversation'];
        }
    }
    // Structure 2: Direct messages array
    elseif (isset($data['messages'])) {
        $messages = $data['messages'];
        
        // Extract name
        $name = isset($messages['pushName']) ? $messages['pushName'] : '';
        
        // Extract number
        if (isset($messages['key']['cleanedSenderPn'])) {
            $number = $messages['key']['cleanedSenderPn'];
        } elseif (isset($messages['remoteJid'])) {
            $remoteJid = $messages['remoteJid'];
            $number = explode('@', $remoteJid)[0];
        } elseif (isset($messages['key']['remoteJid'])) {
            $remoteJid = $messages['key']['remoteJid'];
            $number = explode('@', $remoteJid)[0];
        }
        
        // Extract message text
        if (isset($messages['messageBody'])) {
            $text = $messages['messageBody'];
        } elseif (isset($messages['message']['extendedTextMessage']['text'])) {
            $text = $messages['message']['extendedTextMessage']['text'];
        } elseif (isset($messages['message']['conversation'])) {
            $text = $messages['message']['conversation'];
        }
    }
    // Structure 3: Direct fields (simple structure)
    elseif (isset($data['text']) || isset($data['number']) || isset($data['message'])) {
        $text = $data['text'] ?? $data['message'] ?? '';
        $number = $data['number'] ?? $data['phone'] ?? '';
        $name = $data['name'] ?? '';
    }
    // Structure 4: Check if it's an array of messages
    elseif (isset($data[0]) && is_array($data[0])) {
        $messages = $data[0];
        
        // Extract name
        $name = isset($messages['pushName']) ? $messages['pushName'] : '';
        
        // Extract number
        if (isset($messages['key']['cleanedSenderPn'])) {
            $number = $messages['key']['cleanedSenderPn'];
        } elseif (isset($messages['remoteJid'])) {
            $remoteJid = $messages['remoteJid'];
            $number = explode('@', $remoteJid)[0];
        } elseif (isset($messages['key']['remoteJid'])) {
            $remoteJid = $messages['key']['remoteJid'];
            $number = explode('@', $remoteJid)[0];
        }
        
        // Extract message text
        if (isset($messages['messageBody'])) {
            $text = $messages['messageBody'];
        } elseif (isset($messages['message']['extendedTextMessage']['text'])) {
            $text = $messages['message']['extendedTextMessage']['text'];
        } elseif (isset($messages['message']['conversation'])) {
            $text = $messages['message']['conversation'];
        }
    }
    
    return [
        'text' => $text,
        'number' => $number,
        'name' => $name
    ];
}

/**
 * Normalize phone into digits only.
 */
function normalizePhoneDigits($phone) {
    return preg_replace('/\D+/', '', (string)$phone);
}

/**
 * Get last 6 digits from a phone number.
 */
function getPhoneLastSix($phone) {
    $digits = normalizePhoneDigits($phone);
    if ($digits === '') {
        return '';
    }
    return strlen($digits) <= 6 ? $digits : substr($digits, -6);
}

/**
 * Parse ignore list text (comma/newline/space separated) and return unique last-6 suffixes.
 *
 * @param string $raw
 * @return array<int, string>
 */
function parseIgnoreNumberSuffixes($raw) {
    $parts = preg_split('/[\s,;]+/', (string)$raw);
    if (!is_array($parts)) {
        return [];
    }
    $suffixes = [];
    foreach ($parts as $part) {
        $digits = normalizePhoneDigits($part);
        if ($digits === '') {
            continue;
        }
        $suffix = strlen($digits) <= 6 ? $digits : substr($digits, -6);
        if ($suffix !== '') {
            $suffixes[$suffix] = true;
        }
    }
    return array_keys($suffixes);
}

/**
 * Check whether incoming phone should be ignored for this sub-admin.
 */
function isIgnoredPhone($incomingPhone, $ignoreListRaw) {
    $incomingSuffix = getPhoneLastSix($incomingPhone);
    if ($incomingSuffix === '') {
        return false;
    }
    $ignoreSuffixes = parseIgnoreNumberSuffixes($ignoreListRaw);
    return in_array($incomingSuffix, $ignoreSuffixes, true);
}

/**
 * Check if phone number exists in leads table for specific sub-admin
 */
function checkPhoneExists($phone, $sub_admin_id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT id FROM leads WHERE phone = ? AND sub_admin_id = ?");
    $stmt->bind_param("si", $phone, $sub_admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    $conn->close();
    return $exists;
}

/**
 * Insert new lead into database
 */
function insertLead($phone, $message, $sub_admin_id, $name = '') {
    $conn = getDBConnection();
    $stmt = $conn->prepare("INSERT INTO leads (sub_admin_id, phone, name, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("iss", $sub_admin_id, $phone, $name);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * Get welcome message for new leads using AI
 */
function getWelcomeMessage($name = '', $settings = null) {
    if ($settings !== null) {
        $settings = mergePlatformAiSettings($settings);
    }
    // Determine which API provider to use
    $apiProvider = ($settings && !empty($settings['api_provider'])) ? $settings['api_provider'] : 'gemini';
    
    // Get instructions from settings
    $instructions = '';
    if ($apiProvider === 'chatgpt') {
        $instructions = ($settings && !empty($settings['system_instruction'])) 
            ? $settings['system_instruction'] 
            : '';
    } else {
        $instructions = ($settings && !empty($settings['starting_message'])) 
            ? $settings['starting_message'] 
            : '';
    }
    
    // Prepare welcome message prompt with instructions
    $welcomePrompt = '';
    if (!empty($instructions)) {
        $welcomePrompt = $instructions . " ";
    }
    
    $welcomePrompt .= !empty($name) 
        ? "Generate a friendly and professional welcome message for a new customer named $name. Keep it short (1-2 sentences), welcoming, and ask how you can help them. Reply in the same language if the name suggests a specific language."
        : "Generate a friendly and professional welcome message for a new customer. Keep it short (1-2 sentences), welcoming, and ask how you can help them.";
    
    if ($apiProvider === 'chatgpt') {
        return getChatGPTWelcomeMessage($welcomePrompt, $name, $settings);
    } else {
        return getGeminiWelcomeMessage($welcomePrompt, $name, $settings);
    }
}

/**
 * Get welcome message using Gemini API
 */
function getGeminiWelcomeMessage($prompt, $name, $settings = null) {
    $apiKey = ($settings && !empty($settings['gemini_api_key'])) ? $settings['gemini_api_key'] : GEMINI_API_KEY;
    $model = ($settings && !empty($settings['gemini_model'])) ? $settings['gemini_model'] : null;
    $apiUrl = getGeminiApiUrl($apiKey, $model);
    
    // Prompt already includes instructions from getWelcomeMessage, use it directly
    $fullPrompt = $prompt;

    $sub_admin_id = ($settings && isset($settings['admin_id'])) ? (int) $settings['admin_id'] : 0;
    $faqBlock = $sub_admin_id > 0 ? faq_build_system_prompt_block($sub_admin_id) : '';

    $postData = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $fullPrompt],
                ],
            ],
        ],
    ];
    if ($faqBlock !== '') {
        $postData['systemInstruction'] = [
            'parts' => [['text' => "Store FAQ (context for your welcome message):\n" . $faqBlock]],
        ];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $apiStartTime = microtime(true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $apiTime = microtime(true) - $apiStartTime;
    curl_close($ch);
    
    // Parse response
    $responseData = json_decode($response, true);
    
    // Extract generated text
    $generatedText = '';
    if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        $generatedText = trim($responseData['candidates'][0]['content']['parts'][0]['text']);
    }

    $ctx = gemini_resolve_category_context($sub_admin_id);
    $outgoingWelcome = $generatedText;
    if (!$error && $httpCode === 200 && $generatedText !== '') {
        // $fullPrompt = instruction sent to Gemini; used as "requested" context for the filter
        $outgoingWelcome = filter_gemini_reply_output(
            $fullPrompt,
            $generatedText,
            $sub_admin_id,
            $ctx['name'],
            $ctx['id'],
            false
        );
    }
    
    // Save to database if sub_admin_id is provided and we have a response
    if ($sub_admin_id > 0 && $responseData !== null) {
        $request_payload = json_encode($postData, JSON_UNESCAPED_UNICODE);
        $response_data = json_encode($responseData, JSON_UNESCAPED_UNICODE);
        
        saveGeminiHistory(
            $sub_admin_id,
            '',
            $name,
            'welcome',
            '',
            $outgoingWelcome ?: 'No response generated',
            $request_payload,
            $response_data,
            $httpCode,
            $apiTime,
            $error
        );
    }
    
    if ($error || $httpCode !== 200) {
        // Fallback to simple message if API fails
        $greeting = !empty($name) ? "Hello $name! " : "Hello! ";
        return $greeting . "How may I help you?";
    }
    
    if (!empty($generatedText)) {
        return $outgoingWelcome;
    }
    
    // Fallback
    $greeting = !empty($name) ? "Hello $name! " : "Hello! ";
    return $greeting . "How may I help you?";
}

/**
 * Get welcome message using ChatGPT API
 */
function getChatGPTWelcomeMessage($prompt, $name, $settings = null) {
    $apiKey = ($settings && !empty($settings['chatgpt_api_key'])) ? $settings['chatgpt_api_key'] : '';
    
    if (empty($apiKey)) {
        // Fallback to simple message if API key not configured
        $greeting = !empty($name) ? "Hello $name! " : "Hello! ";
        return $greeting . "How may I help you?";
    }
    
    // Get system instruction (prompt already includes it, but keep system message for context)
    $systemInstruction = ($settings && !empty($settings['system_instruction'])) 
        ? $settings['system_instruction'] 
        : "You are a polite and persuasive sales representative for a skincare clinic that offers HydraFacial services. Reply in the same language the user speaks. Be professional and focus on converting inquiries into appointments.";
    
    $messages = [
        [
            'role' => 'system',
            'content' => $systemInstruction . (!empty($name) ? " The customer's name is " . $name . "." : "")
        ],
        [
            'role' => 'user',
            'content' => $prompt
        ]
    ];
    
    $postData = [
        'model' => 'gpt-4o-mini',
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 150
    ];
    
    $apiStartTime = microtime(true);
    $apiUrl = 'https://api.openai.com/v1/chat/completions';
    
    // Get sub_admin_id from settings
    $sub_admin_id = ($settings && isset($settings['admin_id'])) ? $settings['admin_id'] : 0;
    
    // Log ChatGPT request
    logChatGPTRequest([
        'sub_admin_id' => $sub_admin_id,
        'phone' => '',
        'name' => $name,
        'type' => 'Welcome Message',
        'url' => $apiUrl,
        'payload' => $postData
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $apiTime = microtime(true) - $apiStartTime;
    curl_close($ch);
    
    // Parse response
    $responseData = json_decode($response, true);
    
    // Log ChatGPT response
    logChatGPTRequest([
        'sub_admin_id' => $sub_admin_id,
        'phone' => '',
        'name' => $name,
        'type' => 'Welcome Message Response',
        'payload' => $postData
    ], $responseData, $httpCode, $apiTime, $error);
    
    if ($error || $httpCode !== 200) {
        // Fallback to simple message if API fails
        $greeting = !empty($name) ? "Hello $name! " : "Hello! ";
        return $greeting . "How may I help you?";
    }
    
    $responseData = json_decode($response, true);
    if (isset($responseData['choices'][0]['message']['content'])) {
        return trim($responseData['choices'][0]['message']['content']);
    }
    
    // Fallback
    $greeting = !empty($name) ? "Hello $name! " : "Hello! ";
    return $greeting . "How may I help you?";
}

/**
 * Save Gemini history to database
 */
function saveGeminiHistory($sub_admin_id, $phone, $name, $type, $incoming_message, $generated_message, $request_payload, $response_data, $http_code = null, $api_time = null, $error = null) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("INSERT INTO gemini_history (sub_admin_id, phone, name, type, incoming_message, generated_message, request_payload, response_data, http_code, api_time, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssssssids", $sub_admin_id, $phone, $name, $type, $incoming_message, $generated_message, $request_payload, $response_data, $http_code, $api_time, $error);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * Save ChatGPT history to database
 */
function saveChatGPTHistory($sub_admin_id, $phone, $name, $type, $incoming_message, $generated_message, $request_payload, $response_data, $http_code = null, $api_time = null, $error = null) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("INSERT INTO chatgpt_history (sub_admin_id, phone, name, type, incoming_message, generated_message, request_payload, response_data, http_code, api_time, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssssssids", $sub_admin_id, $phone, $name, $type, $incoming_message, $generated_message, $request_payload, $response_data, $http_code, $api_time, $error);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * Log ChatGPT API request and response to chatgpt.log and database
 */
function logChatGPTRequest($requestData, $responseData = null, $httpCode = null, $apiTime = null, $error = null) {
    $logFile = __DIR__ . '/chatgpt.log';
    $timestamp = date('Y-m-d H:i:s');
    
    $logEntry = "\n" . str_repeat("=", 100) . "\n";
    $logEntry .= "TIMESTAMP: $timestamp\n";
    $logEntry .= str_repeat("-", 100) . "\n";
    
    // Log request details
    $logEntry .= "REQUEST DETAILS:\n";
    if (isset($requestData['phone'])) {
        $logEntry .= "PHONE: " . $requestData['phone'] . "\n";
    }
    if (isset($requestData['name'])) {
        $logEntry .= "NAME: " . $requestData['name'] . "\n";
    }
    if (isset($requestData['type'])) {
        $logEntry .= "TYPE: " . $requestData['type'] . "\n";
    }
    if (isset($requestData['url'])) {
        $logEntry .= "API URL: " . $requestData['url'] . "\n";
    }
    if (isset($requestData['payload'])) {
        $logEntry .= "REQUEST PAYLOAD:\n" . json_encode($requestData['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
    
    // Log response details
    $generatedMessage = '';
    if ($responseData !== null) {
        $logEntry .= "\nRESPONSE DETAILS:\n";
        if ($httpCode !== null) {
            $logEntry .= "HTTP CODE: " . $httpCode . "\n";
        }
        if ($apiTime !== null) {
            $logEntry .= "API TIME: " . number_format($apiTime, 3) . " seconds\n";
        }
        if ($error) {
            $logEntry .= "ERROR: " . $error . "\n";
        }
        $logEntry .= "RESPONSE DATA:\n" . json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        
        // Extract and log the actual message content
        if (isset($responseData['choices'][0]['message']['content'])) {
            $generatedMessage = $responseData['choices'][0]['message']['content'];
            $logEntry .= "\nGENERATED MESSAGE: " . $generatedMessage . "\n";
        }
    }
    
    $logEntry .= str_repeat("=", 100) . "\n";
    
    // Append to log file (with error handling)
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    
    // Save to database if response is available and we have sub_admin_id
    if ($responseData !== null && isset($requestData['sub_admin_id'])) {
        $type = (isset($requestData['type']) && strpos($requestData['type'], 'Welcome') !== false) ? 'welcome' : 'reply';
        $phone = $requestData['phone'] ?? '';
        $name = $requestData['name'] ?? '';
        $incoming_message = $requestData['incoming_message'] ?? '';
        $request_payload = json_encode($requestData['payload'] ?? [], JSON_UNESCAPED_UNICODE);
        $response_data = json_encode($responseData, JSON_UNESCAPED_UNICODE);
        
        saveChatGPTHistory(
            $requestData['sub_admin_id'],
            $phone,
            $name,
            $type,
            $incoming_message,
            $generatedMessage,
            $request_payload,
            $response_data,
            $httpCode,
            $apiTime,
            $error
        );
    }
}

/**
 * Log API interaction details to reply.log
 * Only keeps the latest message processing (log file is cleared at webhook start)
 */
function logApiInteraction($logData) {
    $logFile = __DIR__ . '/reply.log';
    $timestamp = date('Y-m-d H:i:s');
    
    $logEntry = "\n" . str_repeat("=", 80) . "\n";
    $logEntry .= "TIMESTAMP: $timestamp\n";
    $logEntry .= str_repeat("-", 80) . "\n";
    
    if (isset($logData['phone'])) {
        $logEntry .= "PHONE: " . $logData['phone'] . "\n";
    }
    if (isset($logData['incoming_message'])) {
        $logEntry .= "INCOMING MESSAGE: " . $logData['incoming_message'] . "\n";
    }
    if (isset($logData['api_provider'])) {
        $logEntry .= "API PROVIDER: " . strtoupper($logData['api_provider']) . "\n";
    }
    if (isset($logData['process'])) {
        $logEntry .= "PROCESS: " . $logData['process'] . "\n";
    }
    if (isset($logData['api_url'])) {
        $logEntry .= "API URL: " . $logData['api_url'] . "\n";
    }
    if (isset($logData['payload'])) {
        $logEntry .= "PAYLOAD SENT:\n" . json_encode($logData['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
    if (isset($logData['response'])) {
        $logEntry .= "RESPONSE RECEIVED:\n" . json_encode($logData['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
    if (isset($logData['http_code'])) {
        $logEntry .= "HTTP CODE: " . $logData['http_code'] . "\n";
    }
    if (isset($logData['api_time'])) {
        $logEntry .= "API TIME: " . number_format($logData['api_time'], 3) . " seconds\n";
    }
    if (isset($logData['total_time'])) {
        $logEntry .= "TOTAL TIME: " . number_format($logData['total_time'], 3) . " seconds\n";
    }
    if (isset($logData['error'])) {
        $logEntry .= "ERROR: " . $logData['error'] . "\n";
    }
    if (isset($logData['final_reply'])) {
        $logEntry .= "FINAL REPLY: " . $logData['final_reply'] . "\n";
    }
    if (isset($logData['chat_history_count'])) {
        $logEntry .= "CHAT HISTORY MESSAGES: " . $logData['chat_history_count'] . "\n";
    }
    if (isset($logData['lead_name'])) {
        $logEntry .= "LEAD NAME: " . $logData['lead_name'] . "\n";
    }
    
    $logEntry .= str_repeat("=", 80) . "\n";
    
    // Append to log file (or write if file was cleared) - with error handling
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Get lead name from database
 */
function getLeadName($phone, $sub_admin_id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT name FROM leads WHERE phone = ? AND sub_admin_id = ?");
    $stmt->bind_param("si", $phone, $sub_admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $lead = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $lead ? ($lead['name'] ?? '') : '';
}

/**
 * Get chat history for a lead
 */
function getChatHistory($phone, $sub_admin_id, $limit = 50) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT message, direction, created_at FROM chat_history 
                           WHERE phone = ? AND sub_admin_id = ? 
                           ORDER BY created_at ASC 
                           LIMIT ?");
    $stmt->bind_param("sii", $phone, $sub_admin_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $history = [];
    while ($row = $result->fetch_assoc()) {
        $history[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $history;
}

/**
 * Store categories (super admin): name + developer_prompt; each sub-admin belongs to one category.
 */
function categories_ensure_schema(mysqli $conn) {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $conn->query("CREATE TABLE IF NOT EXISTS store_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        developer_prompt TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_sc_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($col = $conn->query("SHOW COLUMNS FROM admins LIKE 'category_id'")) {
        if ($col->num_rows === 0) {
            @$conn->query("ALTER TABLE admins ADD COLUMN category_id INT NULL DEFAULT NULL");
        }
    }
    @$conn->query("ALTER TABLE admins ADD CONSTRAINT fk_admins_store_category FOREIGN KEY (category_id) REFERENCES store_categories(id) ON DELETE SET NULL");

    $r = @$conn->query("SELECT COUNT(*) AS c FROM store_categories");
    if ($r) {
        $row = $r->fetch_assoc();
        if ($row && (int) $row['c'] === 0) {
            $conn->query("INSERT INTO store_categories (name, developer_prompt) VALUES ('General', '')");
        }
    }
}

/**
 * Store type config schema:
 * - super admin defines active store types (ecommerce/service/...)
 * - super admin defines per-type visible + required fields
 * - sub-admin stores selected type + values JSON in sub_admin_settings
 */
function store_config_ensure_schema(mysqli $conn) {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $conn->query("CREATE TABLE IF NOT EXISTS store_type_definitions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(64) NOT NULL UNIQUE,
        title VARCHAR(255) NOT NULL,
        details TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_std_active_sort (is_active, sort_order, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS store_type_fields (
        id INT AUTO_INCREMENT PRIMARY KEY,
        store_type_slug VARCHAR(64) NOT NULL,
        field_key VARCHAR(100) NOT NULL,
        field_label VARCHAR(255) NOT NULL,
        field_type ENUM('text','textarea','number','password','select') NOT NULL DEFAULT 'text',
        placeholder TEXT NULL,
        help_text TEXT NULL,
        options_json TEXT NULL,
        is_required TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_store_type_field_key (store_type_slug, field_key),
        INDEX idx_stf_type_sort (store_type_slug, is_active, sort_order, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($col = $conn->query("SHOW COLUMNS FROM sub_admin_settings LIKE 'store_type'")) {
        if ($col->num_rows === 0) {
            @$conn->query("ALTER TABLE sub_admin_settings ADD COLUMN store_type VARCHAR(64) NULL AFTER test_chat_token");
        }
    }
    if ($col = $conn->query("SHOW COLUMNS FROM sub_admin_settings LIKE 'store_type_config_json'")) {
        if ($col->num_rows === 0) {
            @$conn->query("ALTER TABLE sub_admin_settings ADD COLUMN store_type_config_json LONGTEXT NULL AFTER store_type");
        }
    }

    $hasTypes = 0;
    $r = $conn->query("SELECT COUNT(*) AS c FROM store_type_definitions");
    if ($r) {
        $row = $r->fetch_assoc();
        $hasTypes = (int) ($row['c'] ?? 0);
    }
    if ($hasTypes === 0) {
        $conn->query("INSERT INTO store_type_definitions (slug, title, details, is_active, sort_order) VALUES
            ('ecommerce', 'Ecommerce', 'Product based stores. Can include catalog, stock, price and order related integrations.', 1, 10),
            ('service', 'Service', 'Service businesses (appointments, bookings, consultation).', 1, 20)");
    }

    $seedField = $conn->prepare("INSERT IGNORE INTO store_type_fields (store_type_slug, field_key, field_label, field_type, placeholder, help_text, options_json, is_required, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
    if ($seedField) {
        $rows = [
            ['ecommerce', 'catalog_provider', 'Catalog Provider', 'select', '', 'Choose where product lookup should happen.', '[{"value":"custom","label":"Custom (local DB)"},{"value":"woo","label":"WooCommerce"},{"value":"shopify","label":"Shopify"}]', 1, 5],
            ['ecommerce', 'whatsapp_api_token', 'WhatsApp API Token', 'text', 'Enter WhatsApp Bearer token', 'Required for sending messages from this store.', '', 1, 10],
            ['ecommerce', 'starting_message', 'Starting Message (Gemini context)', 'textarea', 'Optional Gemini context', 'Prepended context when Gemini is used.', '', 0, 20],
            ['ecommerce', 'system_instruction', 'System Instruction (ChatGPT)', 'textarea', 'Optional ChatGPT instruction', 'Behavior/role context when ChatGPT is used.', '', 0, 30],
            ['ecommerce', 'shopify_store_url', 'Shopify Store URL', 'text', 'https://your-store.myshopify.com', 'Required when catalog provider is Shopify.', '', 0, 40],
            ['ecommerce', 'shopify_access_token', 'Shopify Access Token', 'password', 'shpat_xxx', 'Private app/admin API token for Shopify.', '', 0, 50],
            ['ecommerce', 'shopify_api_version', 'Shopify API Version', 'text', '2024-07', 'Optional. Keep default unless you know your version.', '', 0, 60],
            ['ecommerce', 'woo_base_url', 'WooCommerce Base URL', 'text', 'https://example.com', 'Required when catalog provider is WooCommerce.', '', 0, 70],
            ['ecommerce', 'woo_consumer_key', 'WooCommerce Consumer Key', 'password', 'ck_xxx', 'REST API consumer key.', '', 0, 80],
            ['ecommerce', 'woo_consumer_secret', 'WooCommerce Consumer Secret', 'password', 'cs_xxx', 'REST API consumer secret.', '', 0, 90],
            ['service', 'whatsapp_api_token', 'WhatsApp API Token', 'text', 'Enter WhatsApp Bearer token', 'Required for sending messages from this store.', '', 1, 10],
            ['service', 'starting_message', 'Starting Message (Gemini context)', 'textarea', 'Optional Gemini context', 'Prepended context when Gemini is used.', '', 0, 20],
            ['service', 'system_instruction', 'System Instruction (ChatGPT)', 'textarea', 'Optional ChatGPT instruction', 'Behavior/role context when ChatGPT is used.', '', 0, 30],
            ['service', 'service_booking_api_url', 'Booking API URL', 'text', 'https://api.example.com/bookings', 'Used for service availability/booking checks.', '', 0, 40],
            ['service', 'service_booking_api_key', 'Booking API Key', 'password', 'Optional secret key', 'Used when booking API requires authentication.', '', 0, 50],
        ];
        foreach ($rows as $row) {
            $seedField->bind_param(
                "sssssssii",
                $row[0],
                $row[1],
                $row[2],
                $row[3],
                $row[4],
                $row[5],
                $row[6],
                $row[7]
                ,
                $row[8]
            );
            $seedField->execute();
        }
        $seedField->close();
    }
}

/**
 * @return array<int, array<string,mixed>>
 */
function store_type_get_definitions(mysqli $conn, $onlyActive = true) {
    store_config_ensure_schema($conn);
    $sql = "SELECT id, slug, title, details, is_active, sort_order FROM store_type_definitions";
    if ($onlyActive) {
        $sql .= " WHERE is_active = 1";
    }
    $sql .= " ORDER BY sort_order ASC, id ASC";
    $res = $conn->query($sql);
    if (!$res) {
        return [];
    }
    return $res->fetch_all(MYSQLI_ASSOC);
}

/**
 * @return array<int, array<string,mixed>>
 */
function store_type_get_fields(mysqli $conn, $storeTypeSlug, $onlyActive = true) {
    store_config_ensure_schema($conn);
    $storeTypeSlug = trim((string) $storeTypeSlug);
    if ($storeTypeSlug === '') {
        return [];
    }
    $sql = "SELECT id, store_type_slug, field_key, field_label, field_type, placeholder, help_text, options_json, is_required, is_active, sort_order
            FROM store_type_fields
            WHERE store_type_slug = ?";
    if ($onlyActive) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY sort_order ASC, id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param("s", $storeTypeSlug);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Ensure FAQ tables and sub_admin_settings FAQ columns exist (idempotent).
 */
function faq_ensure_schema(mysqli $conn) {
    static $faqSchemaDone = false;
    if ($faqSchemaDone) {
        return;
    }
    $faqSchemaDone = true;

    categories_ensure_schema($conn);
    store_config_ensure_schema($conn);

    $conn->query("CREATE TABLE IF NOT EXISTS store_faq (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sub_admin_id INT NOT NULL,
        question TEXT NOT NULL,
        answer TEXT NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (sub_admin_id) REFERENCES admins(id) ON DELETE CASCADE,
        INDEX idx_store_faq_sub (sub_admin_id),
        INDEX idx_store_faq_sort (sub_admin_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS pending_questions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sub_admin_id INT NOT NULL,
        customer_phone VARCHAR(32) NOT NULL,
        message_text TEXT NOT NULL,
        message_hash CHAR(64) NOT NULL,
        status ENUM('open','answered','dismissed') NOT NULL DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        answered_at TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (sub_admin_id) REFERENCES admins(id) ON DELETE CASCADE,
        INDEX idx_pq_sub_status (sub_admin_id, status),
        INDEX idx_pq_open_hash (sub_admin_id, message_hash, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($col = $conn->query("SHOW COLUMNS FROM sub_admin_settings LIKE 'faq_strict_unknown'")) {
        if ($col->num_rows === 0) {
            @$conn->query("ALTER TABLE sub_admin_settings ADD COLUMN faq_strict_unknown TINYINT(1) NOT NULL DEFAULT 0 AFTER message_interval");
        }
    }
    if ($col = $conn->query("SHOW COLUMNS FROM sub_admin_settings LIKE 'unknown_question_reply'")) {
        if ($col->num_rows === 0) {
            @$conn->query("ALTER TABLE sub_admin_settings ADD COLUMN unknown_question_reply TEXT NULL AFTER faq_strict_unknown");
        }
    }
    if ($col = $conn->query("SHOW COLUMNS FROM sub_admin_settings LIKE 'test_chat_token'")) {
        if ($col->num_rows === 0) {
            @$conn->query("ALTER TABLE sub_admin_settings ADD COLUMN test_chat_token VARCHAR(64) DEFAULT NULL UNIQUE AFTER webhook_token");
        }
    }
}

/**
 * Ensure this store has a secret test-chat token (for public test UI, not WhatsApp).
 */
function ensure_test_chat_token(mysqli $conn, int $adminId) {
    faq_ensure_schema($conn);
    $adminId = (int) $adminId;
    $stmt = $conn->prepare("SELECT test_chat_token FROM sub_admin_settings WHERE admin_id = ? LIMIT 1");
    $stmt->bind_param("i", $adminId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $existing = $row['test_chat_token'] ?? '';
    if ($existing !== null && $existing !== '') {
        return (string) $existing;
    }
    $new = bin2hex(random_bytes(24));
    $up = $conn->prepare("UPDATE sub_admin_settings SET test_chat_token = ? WHERE admin_id = ?");
    $up->bind_param("si", $new, $adminId);
    $up->execute();
    $up->close();
    return $new;
}

/** Fixed sender phone for sandbox test UI (92… passes webhook-style checks; not used for real WhatsApp). */
function test_chat_sandbox_phone() {
    return '923000000001';
}

/**
 * Load sub_admin row + admin_id for test-chat token (public page / AJAX).
 *
 * @return array<string,mixed>|null
 */
function load_sub_admin_settings_by_test_chat_token(mysqli $conn, $token) {
    faq_ensure_schema($conn);
    $token = trim((string) $token);
    if ($token === '') {
        return null;
    }
    $stmt = $conn->prepare("SELECT s.*, a.id as admin_id, a.username AS store_username FROM sub_admin_settings s 
        INNER JOIN admins a ON s.admin_id = a.id 
        WHERE s.test_chat_token = ? AND a.status = 'active' LIMIT 1");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $settings = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$settings) {
        return null;
    }
    $rid = (int) $settings['admin_id'];
    $ref = $conn->prepare("SELECT faq_strict_unknown, unknown_question_reply FROM sub_admin_settings WHERE admin_id = ? LIMIT 1");
    $ref->bind_param("i", $rid);
    $ref->execute();
    $extra = $ref->get_result()->fetch_assoc();
    $ref->close();
    if (is_array($extra)) {
        $settings['faq_strict_unknown'] = $extra['faq_strict_unknown'] ?? 0;
        $settings['unknown_question_reply'] = $extra['unknown_question_reply'] ?? '';
    }
    return $settings;
}

/**
 * Normalize text for FAQ matching (lowercase, collapse whitespace).
 */
function faq_normalize_text($s) {
    $s = trim((string) $s);
    if ($s === '') {
        return '';
    }
    if (function_exists('mb_strtolower')) {
        $s = mb_strtolower($s, 'UTF-8');
    } else {
        $s = strtolower($s);
    }
    return preg_replace('/\s+/u', ' ', $s);
}

function faq_cache_dir() {
    $dir = __DIR__ . '/cache/faq';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function faq_cache_file_path($sub_admin_id) {
    return faq_cache_dir() . '/store_' . (int) $sub_admin_id . '.json';
}

function faq_category_cache_file_path($category_id) {
    return faq_cache_dir() . '/category_' . (int) $category_id . '.json';
}

/**
 * FAQ items (cache shape with q/a) → plain Q/A block.
 */
function faq_format_items_as_qa_text(array $items) {
    $lines = [];
    foreach ($items as $it) {
        $q = isset($it['q']) ? trim((string) $it['q']) : '';
        $a = isset($it['a']) ? trim((string) $it['a']) : '';
        if ($q === '' || $a === '') {
            continue;
        }
        $lines[] = 'Q: ' . $q . "\nA: " . $a;
    }
    return implode("\n\n", $lines);
}

/**
 * Plain FAQ rows from DB (question/answer keys) → Q/A text.
 *
 * @param array<int, array<string,mixed>> $rows
 */
function faq_format_db_faq_rows_as_qa_text(array $rows) {
    $lines = [];
    foreach ($rows as $row) {
        $q = isset($row['question']) ? trim((string) $row['question']) : '';
        $a = isset($row['answer']) ? trim((string) $row['answer']) : '';
        if ($q === '' || $a === '') {
            continue;
        }
        $lines[] = 'Q: ' . $q . "\nA: " . $a;
    }
    return implode("\n\n", $lines);
}

/**
 * Single cache string: category + store instructions + FAQ (for JSON file + tooling).
 */
function faq_combined_instruction_and_faq_string($categoryDeveloperPrompt, $startingMessage, $systemInstruction, $faqQaText) {
    $parts = [];
    $cp = trim((string) $categoryDeveloperPrompt);
    if ($cp !== '') {
        $parts[] = "[Category instructions]\n" . $cp;
    }
    $sm = trim((string) $startingMessage);
    if ($sm !== '') {
        $parts[] = "[Store starting / Gemini context]\n" . $sm;
    }
    $si = trim((string) $systemInstruction);
    if ($si !== '') {
        $parts[] = "[Store system / ChatGPT]\n" . $si;
    }
    $faq = trim((string) $faqQaText);
    if ($faq !== '') {
        $parts[] = "[FAQ]\n" . $faq;
    }
    return implode("\n\n---\n\n", $parts);
}

/**
 * One JSON file per category: metadata + all sub-admins in that category + their FAQ rows.
 */
function category_rebuild_aggregate_cache($category_id) {
    $category_id = (int) $category_id;
    if ($category_id <= 0) {
        return;
    }
    $conn = getDBConnection();
    categories_ensure_schema($conn);
    faq_ensure_schema($conn);

    $st = $conn->prepare("SELECT id, name, developer_prompt FROM store_categories WHERE id = ? LIMIT 1");
    $st->bind_param("i", $category_id);
    $st->execute();
    $cat = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$cat) {
        $conn->close();
        return;
    }

    $st2 = $conn->prepare("SELECT id, username FROM admins WHERE role = 'sub_admin' AND category_id = ? ORDER BY username ASC");
    $st2->bind_param("i", $category_id);
    $st2->execute();
    $subs = $st2->get_result()->fetch_all(MYSQLI_ASSOC);
    $st2->close();

    $stores = [];
    $fq = $conn->prepare("SELECT id, question, answer, sort_order FROM store_faq WHERE sub_admin_id = ? ORDER BY sort_order ASC, id ASC");
    $stSet = $conn->prepare("SELECT starting_message, system_instruction FROM sub_admin_settings WHERE admin_id = ? LIMIT 1");
    $catDev = (string) ($cat['developer_prompt'] ?? '');
    foreach ($subs as $sub) {
        $sid = (int) $sub['id'];
        $fq->bind_param("i", $sid);
        $fq->execute();
        $fr = $fq->get_result();
        $faqRows = [];
        while ($row = $fr->fetch_assoc()) {
            $faqRows[] = [
                'id' => (int) $row['id'],
                'question' => $row['question'],
                'answer' => $row['answer'],
                'sort_order' => (int) $row['sort_order'],
            ];
        }
        $startMsg = '';
        $sysInst = '';
        $stSet->bind_param("i", $sid);
        $stSet->execute();
        $setR = $stSet->get_result()->fetch_assoc();
        if ($setR) {
            $startMsg = (string) ($setR['starting_message'] ?? '');
            $sysInst = (string) ($setR['system_instruction'] ?? '');
        }
        $faqQaText = faq_format_db_faq_rows_as_qa_text($faqRows);
        $combined = faq_combined_instruction_and_faq_string($catDev, $startMsg, $sysInst, $faqQaText);
        $stores[] = [
            'sub_admin_id' => $sid,
            'username' => $sub['username'],
            'faq' => $faqRows,
            'instructions' => [
                'category_developer_prompt' => $catDev,
                'starting_message' => $startMsg,
                'system_instruction' => $sysInst,
            ],
            'combined_instruction_and_faq' => $combined,
        ];
    }
    $fq->close();
    $stSet->close();
    $conn->close();

    $payload = [
        'category_id' => $category_id,
        'name' => $cat['name'],
        'developer_prompt' => $cat['developer_prompt'],
        'built_at' => date('c'),
        'stores' => $stores,
    ];
    @file_put_contents(faq_category_cache_file_path($category_id), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Rebuild every sub-admin store JSON + per-category aggregate JSON (super admin "Recache all").
 */
function faq_rebuild_all_stores_and_categories() {
    $conn = getDBConnection();
    categories_ensure_schema($conn);
    faq_ensure_schema($conn);
    $r = $conn->query("SELECT id FROM admins WHERE role = 'sub_admin'");
    $ids = [];
    while ($row = $r->fetch_assoc()) {
        $ids[] = (int) $row['id'];
    }
    $conn->close();
    foreach ($ids as $id) {
        faq_rebuild_cache_file($id);
    }
    $conn = getDBConnection();
    $cr = $conn->query("SELECT id FROM store_categories");
    if ($cr) {
        while ($row = $cr->fetch_assoc()) {
            category_rebuild_aggregate_cache((int) $row['id']);
        }
    }
    $conn->close();
}

/**
 * Rebuild JSON cache from DB (call after any FAQ or promoted-answer change).
 */
function faq_rebuild_cache_file($sub_admin_id) {
    $sub_admin_id = (int) $sub_admin_id;
    $conn = getDBConnection();
    faq_ensure_schema($conn);
    $items = [];
    $stmt = $conn->prepare("SELECT id, question, answer, sort_order FROM store_faq WHERE sub_admin_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->bind_param("i", $sub_admin_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'id' => (int) $row['id'],
            'q' => $row['question'],
            'a' => $row['answer'],
            'n' => faq_normalize_text($row['question']),
        ];
    }
    $stmt->close();

    $catRow = ['id' => null, 'name' => null, 'developer_prompt' => ''];
    $cq = $conn->prepare("SELECT c.id, c.name, c.developer_prompt FROM admins a LEFT JOIN store_categories c ON a.category_id = c.id WHERE a.id = ? LIMIT 1");
    $cq->bind_param("i", $sub_admin_id);
    $cq->execute();
    $crow = $cq->get_result()->fetch_assoc();
    $cq->close();
    if ($crow && !empty($crow['id'])) {
        $catRow['id'] = (int) $crow['id'];
        $catRow['name'] = $crow['name'];
        $catRow['developer_prompt'] = (string) $crow['developer_prompt'];
    }

    $setRow = ['starting_message' => '', 'system_instruction' => ''];
    $sq = $conn->prepare("SELECT starting_message, system_instruction FROM sub_admin_settings WHERE admin_id = ? LIMIT 1");
    $sq->bind_param("i", $sub_admin_id);
    $sq->execute();
    $srow = $sq->get_result()->fetch_assoc();
    $sq->close();
    if ($srow) {
        $setRow['starting_message'] = (string) ($srow['starting_message'] ?? '');
        $setRow['system_instruction'] = (string) ($srow['system_instruction'] ?? '');
    }
    $conn->close();

    $faqQaText = faq_format_items_as_qa_text($items);
    $combinedInstructionAndFaq = faq_combined_instruction_and_faq_string(
        $catRow['developer_prompt'],
        $setRow['starting_message'],
        $setRow['system_instruction'],
        $faqQaText
    );

    $payload = [
        'built_at' => date('c'),
        'sub_admin_id' => $sub_admin_id,
        'category_id' => $catRow['id'],
        'category_name' => $catRow['name'],
        'developer_prompt' => $catRow['developer_prompt'],
        'instructions' => [
            'category_developer_prompt' => $catRow['developer_prompt'],
            'starting_message' => $setRow['starting_message'],
            'system_instruction' => $setRow['system_instruction'],
        ],
        'combined_instruction_and_faq' => $combinedInstructionAndFaq,
        'items' => $items,
    ];
    @file_put_contents(faq_cache_file_path($sub_admin_id), json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);

    faq_clear_gemini_tenant_cache($sub_admin_id);
    if (!empty($catRow['id'])) {
        category_rebuild_aggregate_cache((int) $catRow['id']);
    }
}

/**
 * Directory for TenantGemini-style per-phone history files (see TenantGeminiChatHistory).
 */
function faq_gemini_tenant_cache_dir() {
    $dir = __DIR__ . '/cache/gemini_tenant';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Clear on-disk (and Redis) Gemini chat history for one store so the next reply does not use stale context.
 * Called automatically when FAQ is saved in admin.
 */
function faq_clear_gemini_tenant_cache($sub_admin_id) {
    $sub_admin_id = (int) $sub_admin_id;
    if ($sub_admin_id <= 0) {
        return;
    }
    $dir = faq_gemini_tenant_cache_dir();
    $pattern = $dir . DIRECTORY_SEPARATOR . 'history_' . $sub_admin_id . '_*.json';
    foreach (glob($pattern) ?: [] as $file) {
        @unlink($file);
    }
    foreach (glob($dir . DIRECTORY_SEPARATOR . 'history_' . $sub_admin_id . '_*.json.lock') ?: [] as $lock) {
        @unlink($lock);
    }

    if (extension_loaded('redis')) {
        $host = getenv('REDIS_HOST');
        if ($host !== false && $host !== '') {
            try {
                $r = new Redis();
                $port = (int) (getenv('REDIS_PORT') ?: 6379);
                if (@$r->connect($host, $port, 1.5)) {
                    $pass = getenv('REDIS_PASSWORD');
                    if ($pass !== false && $pass !== '') {
                        $r->auth($pass);
                    }
                    $prefix = 'tenant_gemini:history_' . $sub_admin_id . '_';
                    $keys = $r->keys($prefix . '*');
                    if (is_array($keys) && $keys !== []) {
                        foreach ($keys as $k) {
                            $r->del($k);
                        }
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
    }
}

/**
 * Merged instructions + FAQ string from per-store JSON cache (written by faq_rebuild_cache_file).
 *
 * @return string|null non-empty string, or null if missing / legacy cache
 */
function faq_load_combined_instruction_and_faq_from_cache($sub_admin_id) {
    $sub_admin_id = (int) $sub_admin_id;
    if ($sub_admin_id <= 0) {
        return null;
    }
    $path = faq_cache_file_path($sub_admin_id);
    if (!is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        return null;
    }
    $c = isset($data['combined_instruction_and_faq']) ? trim((string) $data['combined_instruction_and_faq']) : '';
    return $c !== '' ? $c : null;
}

/**
 * Plain-text FAQ list for Gemini systemInstruction: prefers cache file merged block (category + store + FAQ).
 *
 * @return string non-empty or ''
 */
function faq_build_system_prompt_block($sub_admin_id) {
    $sub_admin_id = (int) $sub_admin_id;
    if ($sub_admin_id <= 0) {
        return '';
    }

    $cached = faq_load_combined_instruction_and_faq_from_cache($sub_admin_id);
    if ($cached !== null) {
        $max = 12000;
        if (strlen($cached) > $max) {
            return substr($cached, 0, $max) . "\n…";
        }
        return $cached;
    }

    $prefix = '';
    $conn = getDBConnection();
    categories_ensure_schema($conn);
    $cq = $conn->prepare("SELECT c.developer_prompt FROM admins a LEFT JOIN store_categories c ON a.category_id = c.id WHERE a.id = ? LIMIT 1");
    $cq->bind_param("i", $sub_admin_id);
    $cq->execute();
    $cr = $cq->get_result()->fetch_assoc();
    $cq->close();
    $conn->close();
    if ($cr && trim((string) ($cr['developer_prompt'] ?? '')) !== '') {
        $prefix = trim((string) $cr['developer_prompt']) . "\n\n---\n\n";
    }

    $items = faq_load_items_cached($sub_admin_id);
    if ($items === []) {
        $p = rtrim($prefix);
        return $p !== '' ? $p : '';
    }
    $lines = [];
    foreach ($items as $it) {
        $q = isset($it['q']) ? trim((string) $it['q']) : '';
        $a = isset($it['a']) ? trim((string) $it['a']) : '';
        if ($q === '' || $a === '') {
            continue;
        }
        $lines[] = 'Q: ' . $q . "\nA: " . $a;
    }
    if ($lines === []) {
        $p = rtrim($prefix);
        return $p !== '' ? $p : '';
    }
    $out = $prefix . implode("\n\n", $lines);
    $max = 12000;
    if (strlen($out) > $max) {
        $out = substr($out, 0, $max) . "\n…";
    }
    return $out;
}

/**
 * Load FAQ items (from file cache if present and readable; else DB + write cache).
 *
 * @return array<int, array{id:int,q:string,a:string,n:string}>
 */
function faq_load_items_cached($sub_admin_id) {
    $sub_admin_id = (int) $sub_admin_id;
    $path = faq_cache_file_path($sub_admin_id);
    if (is_readable($path)) {
        $raw = @file_get_contents($path);
        $data = json_decode((string) $raw, true);
        if (is_array($data) && isset($data['items']) && is_array($data['items'])) {
            return $data['items'];
        }
    }
    faq_rebuild_cache_file($sub_admin_id);
    $raw = @file_get_contents($path);
    $data = json_decode((string) $raw, true);
    return (is_array($data) && isset($data['items']) && is_array($data['items'])) ? $data['items'] : [];
}

/**
 * Best matching FAQ answer or null.
 */
function faq_find_best_answer($incomingMessage, array $items) {
    $incoming = faq_normalize_text($incomingMessage);
    if ($incoming === '' || $items === []) {
        return null;
    }

    $bestScore = 0.0;
    $bestAnswer = null;

    foreach ($items as $it) {
        $q = isset($it['n']) ? $it['n'] : faq_normalize_text($it['q'] ?? '');
        if ($q === '') {
            continue;
        }
        if ($incoming === $q) {
            return $it['a'] ?? '';
        }

        $score = 0.0;
        $minLen = min(strlen($incoming), strlen($q));
        if ($minLen >= 4) {
            if (strpos($incoming, $q) !== false || strpos($q, $incoming) !== false) {
                $score = 85.0;
            }
        }

        if ($score < 85.0) {
            similar_text($incoming, $q, $pct);
            if ($pct > $score) {
                $score = $pct;
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestAnswer = $it['a'] ?? '';
        }
    }

    if ($bestScore >= 40.0 && $bestAnswer !== null) {
        return $bestAnswer;
    }
    return null;
}

/**
 * Heuristic: product URL, order intent, price/stock — allow AI (tools or free-form).
 */
function faq_incoming_looks_catalog_related($text) {
    $t = faq_normalize_text($text);
    if ($t === '') {
        return false;
    }
    if (preg_match('#https?://#u', $t)) {
        return true;
    }
    if (preg_match('#/(product|products)(/|$)#u', $t)) {
        return true;
    }
    $needles = ['order ', ' order', 'buy ', ' buy', 'price', 'stock', 'quantity', 'qty', 'cart', 'checkout', 'product', 'delivery', 'ship', 'pkr', ' rs ', 'rs.', 'slug'];
    foreach ($needles as $n) {
        if (strpos($t, $n) !== false) {
            return true;
        }
    }
    if (preg_match('/\b\d+\s*(x|×)\s*\d/u', $t)) {
        return true;
    }
    return false;
}

/**
 * Save unknown customer question when strict mode is on (dedupe open rows by hash).
 */
function faq_save_pending_question($sub_admin_id, $customer_phone, $message_text) {
    $sub_admin_id = (int) $sub_admin_id;
    $message_text = trim((string) $message_text);
    if ($message_text === '') {
        return;
    }
    $hash = hash('sha256', faq_normalize_text($message_text));

    $conn = getDBConnection();
    faq_ensure_schema($conn);

    $chk = $conn->prepare("SELECT id FROM pending_questions WHERE sub_admin_id = ? AND message_hash = ? AND status = 'open' LIMIT 1");
    $chk->bind_param("is", $sub_admin_id, $hash);
    $chk->execute();
    $exists = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($exists) {
        $conn->close();
        return;
    }

    $ins = $conn->prepare("INSERT INTO pending_questions (sub_admin_id, customer_phone, message_text, message_hash) VALUES (?, ?, ?, ?)");
    $ins->bind_param("isss", $sub_admin_id, $customer_phone, $message_text, $hash);
    $ins->execute();
    $ins->close();
    $conn->close();
}

/**
 * FAQ-first replies: match cache → answer; else strict+non-catalog → pending + template; else AI.
 *
 * @param array<string,mixed> $ctx Optional: skip_pending_save (bool) for test-chat UI (no DB pending, no WhatsApp).
 */
function getReplyMessageWithFaqLayer($incomingMessage, $phone, $settings = null, array $ctx = []) {
    if ($settings !== null) {
        $settings = mergePlatformAiSettings($settings);
    }

    $sub_admin_id = (int) ($settings['admin_id'] ?? 0);
    if ($sub_admin_id <= 0) {
        return getReplyMessage($incomingMessage, $phone, $settings);
    }

    $items = faq_load_items_cached($sub_admin_id);
    $faqHit = faq_find_best_answer($incomingMessage, $items);
    if ($faqHit !== null) {
        logApiInteraction([
            'phone' => $phone,
            'incoming_message' => $incomingMessage,
            'api_provider' => $settings['api_provider'] ?? '',
            'process' => 'FAQ matched (cache/DB); AI skipped',
            'final_reply' => $faqHit,
        ]);
        return $faqHit;
    }

    $strict = !empty($settings['faq_strict_unknown']);
    if ($strict && !faq_incoming_looks_catalog_related($incomingMessage)) {
        if (empty($ctx['skip_pending_save'])) {
            faq_save_pending_question($sub_admin_id, $phone, $incomingMessage);
        }
        $template = isset($settings['unknown_question_reply']) ? trim((string) $settings['unknown_question_reply']) : '';
        if ($template === '') {
            $template = 'Thanks for your message. This question is not in our FAQ yet — our team will add an answer and get back to you soon.';
        }
        logApiInteraction([
            'phone' => $phone,
            'incoming_message' => $incomingMessage,
            'api_provider' => $settings['api_provider'] ?? '',
            'process' => !empty($ctx['skip_pending_save'])
                ? 'FAQ strict: test UI (pending not saved); AI skipped'
                : 'FAQ strict: unknown saved to pending_questions; AI skipped',
            'final_reply' => $template,
        ]);
        return $template;
    }

    return getReplyMessage($incomingMessage, $phone, $settings);
}

/**
 * Get reply message using Google Gemini or ChatGPT API with sub-admin settings
 */
function getReplyMessage($incomingMessage, $phone, $settings = null) {
    if ($settings !== null) {
        $settings = mergePlatformAiSettings($settings);
    }
    $startTime = microtime(true);
    
    // Determine which API provider to use
    $apiProvider = ($settings && !empty($settings['api_provider'])) ? $settings['api_provider'] : 'gemini';
    
    // Log start of process
    logApiInteraction([
        'phone' => $phone,
        'incoming_message' => $incomingMessage,
        'api_provider' => $apiProvider,
        'process' => 'Starting reply generation'
    ]);
    
    $reply = '';
    if ($apiProvider === 'chatgpt') {
        $reply = getChatGPTReply($incomingMessage, $phone, $settings);
        
    } else {
        if (defined('GEMINI_USE_FUNCTION_CALLING') && GEMINI_USE_FUNCTION_CALLING) {
            require_once __DIR__ . '/classes/GeminiFunctionCalling.php';
            $reply = getGeminiReplyWithTools($incomingMessage, $phone, $settings);
        } else {
            $reply = getGeminiReply($incomingMessage, $phone, $settings);
        }
    }
    
    $totalTime = microtime(true) - $startTime;
    
    // Log total time
    logApiInteraction([
        'phone' => $phone,
        'api_provider' => $apiProvider,
        'process' => 'Reply generation completed',
        'total_time' => $totalTime,
        'final_reply' => $reply
    ]);
    
    return $reply;
}

/**
 * Category id + display name for a sub-admin (for Gemini reply filter).
 *
 * @return array{id: int|null, name: string}
 */
function gemini_resolve_category_context($sub_admin_id) {
    $sub_admin_id = (int) $sub_admin_id;
    if ($sub_admin_id <= 0) {
        return ['id' => null, 'name' => ''];
    }
    $conn = getDBConnection();
    if (function_exists('categories_ensure_schema')) {
        categories_ensure_schema($conn);
    }
    $st = $conn->prepare('SELECT c.id, c.name FROM admins a LEFT JOIN store_categories c ON a.category_id = c.id WHERE a.id = ? LIMIT 1');
    $st->bind_param('i', $sub_admin_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    $conn->close();
    if (!$row || empty($row['id'])) {
        return ['id' => null, 'name' => ''];
    }
    return ['id' => (int) $row['id'], 'name' => trim((string) ($row['name'] ?? ''))];
}

/**
 * Post-process every Gemini text reply before it is sent to WhatsApp / test UI / history.
 *
 * @param string $requestedMessage Incoming customer text (empty for welcome-only flows)
 * @param string $geminiRawResponse Raw model output
 * @param int $sub_admin_id Store owner id
 * @param string $categoryType Category name (empty if uncategorized)
 * @param int|null $categoryId Category row id
 * @param bool $persistNewFaqFromJson When true and JSON has new_question=1, insert FAQ (reply flows only; false for welcome so prompt text is not stored as question)
 */
function filter_gemini_reply_output($requestedMessage, $geminiRawResponse, $sub_admin_id, $categoryType = '', $categoryId = null, $persistNewFaqFromJson = true) {
    $sub_admin_id = (int) $sub_admin_id;
    $originalTrim = trim((string) $geminiRawResponse);

    // Clean the JSON string (remove extra whitespaces/newlines if any)
    // Remove any extra text before the first '{' and after the matching '}'
    $start = strpos($geminiRawResponse, '{');
    $end = strrpos($geminiRawResponse, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $geminiRawResponse = substr($geminiRawResponse, $start, $end - $start + 1);
    }
    $cleanedResponse = trim((string) $geminiRawResponse);

    $decodedResponse = json_decode($cleanedResponse, true);

    if (!is_array($decodedResponse)) {
        return $originalTrim !== '' ? $originalTrim : $cleanedResponse;
    }
    

    // Model returns JSON with ai_answer (and optionally new_question, faq_answer, …)
    if (array_key_exists('ai_answer', $decodedResponse)) {
        $aiAnswer = trim((string) ($decodedResponse['ai_answer'] ?? ''));
        $isNewQuestion = isset($decodedResponse['new_question']) && (int) $decodedResponse['new_question'] === 1;

        if ($persistNewFaqFromJson && $isNewQuestion && $sub_admin_id > 0 && $aiAnswer !== '') {
            $qText = trim((string) $requestedMessage);
            if ($qText !== '') {
                faq_auto_insert_from_gemini_json($sub_admin_id, $qText, $aiAnswer);
            }
        }

        if ($aiAnswer !== '') {
            return $aiAnswer;
        }
        $faqAns = isset($decodedResponse['faq_answer']) ? trim((string) $decodedResponse['faq_answer']) : '';
        if ($faqAns !== '' && strcasecmp($faqAns, 'null') !== 0) {
            return $faqAns;
        }
        return $cleanedResponse;
    }

    return $cleanedResponse;
}

/**
 * Insert FAQ row from Gemini JSON protocol (new_question=1, answer in ai_answer) and rebuild file cache.
 */
function faq_auto_insert_from_gemini_json($sub_admin_id, $question, $answer) {
    $sub_admin_id = (int) $sub_admin_id;
    if ($sub_admin_id <= 0 || $question === '' || $answer === '') {
        return;
    }
    $conn = getDBConnection();
    faq_ensure_schema($conn);
    $chk = $conn->prepare('SELECT id FROM store_faq WHERE sub_admin_id = ? AND question = ? LIMIT 1');
    $chk->bind_param('is', $sub_admin_id, $question);
    $chk->execute();
    $exists = $chk->get_result()->fetch_assoc();
    $chk->close();
    if ($exists) {
        $conn->close();
        return;
    }
    $ins = $conn->prepare('INSERT INTO store_faq (sub_admin_id, question, answer, sort_order) VALUES (?, ?, ?, 99)');
    $ins->bind_param('iss', $sub_admin_id, $question, $answer);
    if ($ins->execute()) {
        $ins->close();
        $conn->close();
        faq_rebuild_cache_file($sub_admin_id);
        return;
    }
    $ins->close();
    $conn->close();
}

/**
 * Get reply using Google Gemini API
 */
function getGeminiReply($incomingMessage, $phone, $settings = null) {
    $apiStartTime = microtime(true);
    
    // Use sub-admin's API key if provided, otherwise use default
    $apiKey = ($settings && !empty($settings['gemini_api_key'])) ? $settings['gemini_api_key'] : GEMINI_API_KEY;
    $model = ($settings && !empty($settings['gemini_model'])) ? $settings['gemini_model'] : null;
    $apiUrl = getGeminiApiUrl($apiKey, $model);

    $sub_admin_id = ($settings && isset($settings['admin_id'])) ? (int) $settings['admin_id'] : 0;

    // System context: store prompt + latest FAQ (refreshed whenever admin saves FAQ / file cache)
    $systemParts = [];
    if ($settings && !empty($settings['starting_message'])) {
        $systemParts[] = trim((string) $settings['starting_message']);
    } else {
        $systemParts[] = 'You are a helpful store chat assistant. Reply in the same language as the customer when possible.';
    }
    $faqBlock = $sub_admin_id > 0 ? faq_build_system_prompt_block($sub_admin_id) : '';
    if ($faqBlock !== '') {
        $systemParts[] = "Store FAQ (follow when relevant; do not contradict):\n" . $faqBlock;
    }
    $systemText = implode("\n\n", array_filter($systemParts));

    // User turn = current message only (FAQ lives in systemInstruction so it stays in sync with admin updates)
    $postData = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $incomingMessage],
                ],
            ],
        ],
    ];
    if ($systemText !== '') {
        $postData['systemInstruction'] = [
            'parts' => [['text' => $systemText]],
        ];
    }
    
    // Log payload before sending
    logApiInteraction([
        'phone' => $phone,
        'incoming_message' => $incomingMessage,
        'api_provider' => 'gemini',
        'process' => 'Sending request to Gemini API',
        'api_url' => $apiUrl,
        'payload' => $postData
    ]);
    
    // Initialize cURL
    $ch = curl_init();
    
    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $apiTime = microtime(true) - $apiStartTime;
    
    // Close cURL
    curl_close($ch);
    
    // Parse response
    $responseData = json_decode($response, true);
    
    // Get sub_admin_id from settings
    $sub_admin_id = (int) (($settings && isset($settings['admin_id'])) ? $settings['admin_id'] : 0);
    $leadName = getLeadName($phone, $sub_admin_id);
    
    // Extract generated text from response
    $generatedText = '';
    if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        $generatedText = $responseData['candidates'][0]['content']['parts'][0]['text'];
    }

    $ctx = gemini_resolve_category_context($sub_admin_id);
    $outgoingReply = $generatedText;
    if (!$error && $httpCode === 200 && is_string($generatedText) && trim($generatedText) !== '') {
        $outgoingReply = filter_gemini_reply_output(
            $incomingMessage,
            $generatedText,
            $sub_admin_id,
            $ctx['name'],
            $ctx['id']
        );
    }
    
    // Save to database if sub_admin_id is provided and we have a response
    if ($sub_admin_id > 0 && $responseData !== null) {
        $request_payload = json_encode($postData, JSON_UNESCAPED_UNICODE);
        $response_data = json_encode($responseData, JSON_UNESCAPED_UNICODE);
        
        saveGeminiHistory(
            $sub_admin_id,
            $phone,
            $leadName,
            'reply',
            $incomingMessage,
            $outgoingReply ?: 'No response generated',
            $request_payload,
            $response_data,
            $httpCode,
            $apiTime,
            $error
        );
    }
    
    // Log API response
    logApiInteraction([
        'phone' => $phone,
        'api_provider' => 'gemini',
        'process' => 'Received response from Gemini API',
        'http_code' => $httpCode,
        'api_time' => $apiTime,
        'response' => $responseData,
        'error' => $error ? $error : null,
        'final_reply' => $outgoingReply,
    ]);
    
    // Handle errors
    if ($error) {
        error_log("Gemini API cURL Error: $error");
        return "Sorry, I'm having trouble processing your message right now. Please try again later.";
    }
    
    if ($httpCode !== 200) {
        error_log("Gemini API HTTP Error: $httpCode. Response: $response");
        return "Sorry, I'm having trouble processing your message right now. Please try again later.";
    }
    
    // Return generated text (after filter when model returned content)
    if (!empty(trim((string) $generatedText))) {
        return $outgoingReply;
    } else {
        error_log("Gemini API Unexpected Response: " . json_encode($responseData));
        return "Sorry, I couldn't generate a proper response. Please try again.";
    }
}

/**
 * Get reply using ChatGPT API with chat history
 */
function getChatGPTReply($incomingMessage, $phone, $settings = null) {
    $apiStartTime = microtime(true);
    
    // Get API key
    $apiKey = ($settings && !empty($settings['chatgpt_api_key'])) ? $settings['chatgpt_api_key'] : '';
    
    if (empty($apiKey)) {
        error_log("ChatGPT API key not provided");
        logApiInteraction([
            'phone' => $phone,
            'api_provider' => 'chatgpt',
            'process' => 'Error: ChatGPT API key not configured',
            'error' => 'API key missing'
        ]);
        return "Sorry, ChatGPT API key is not configured. Please contact administrator.";
    }
    
    // Get system instruction
    $systemInstruction = ($settings && !empty($settings['system_instruction'])) 
        ? $settings['system_instruction'] 
        : "You are a polite and persuasive sales representative for a skincare clinic that offers HydraFacial services. Reply in the same language the user speaks. Be professional and focus on converting inquiries into appointments.";
    
    // Get chat history for this lead
    $sub_admin_id = ($settings && isset($settings['admin_id'])) ? $settings['admin_id'] : 0;
    $chatHistory = getChatHistory($phone, $sub_admin_id);
    
    // Get lead name
    $leadName = getLeadName($phone, $sub_admin_id);
    
    // Build messages array for ChatGPT API
    $messages = [];
    
    // Add system message with lead name if available
    if (!empty($leadName)) {
        $systemInstruction = $systemInstruction . " The customer's name is " . $leadName . ".";
    }
    $messages[] = [
        'role' => 'system',
        'content' => $systemInstruction
    ];
    
    // If this is the first message and we have lead name, add it as context
    if (empty($chatHistory) && !empty($leadName)) {
        $messages[] = [
            'role' => 'user',
            'content' => "Hello, my name is " . $leadName . ". " . $incomingMessage
        ];
    } else {
        // Add chat history
        foreach ($chatHistory as $chat) {
            if ($chat['direction'] === 'incoming') {
                $messages[] = [
                    'role' => 'user',
                    'content' => $chat['message']
                ];
            } else {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $chat['message']
                ];
            }
        }
        
        // Add current incoming message
        $messages[] = [
            'role' => 'user',
            'content' => $incomingMessage
        ];
    }
    // die('OKK');
    
    // Prepare request data
    $postData = [
        'model' => 'gpt-4o-mini',
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 300
    ];
    
    $apiUrl = 'https://api.openai.com/v1/chat/completions';
    
    // Log ChatGPT request
    logChatGPTRequest([
        'sub_admin_id' => $sub_admin_id,
        'phone' => $phone,
        'name' => $leadName,
        'type' => 'Reply Message',
        'url' => $apiUrl,
        'payload' => $postData,
        'incoming_message' => $incomingMessage,
        'chat_history_count' => count($chatHistory)
    ]);
    
    // Log payload before sending (for reply.log)
    logApiInteraction([
        'phone' => $phone,
        'incoming_message' => $incomingMessage,
        'api_provider' => 'chatgpt',
        'process' => 'Sending request to ChatGPT API',
        'api_url' => $apiUrl,
        'payload' => $postData,
        'chat_history_count' => count($chatHistory),
        'lead_name' => $leadName
    ]);
    
    // Initialize cURL
    $ch = curl_init();
    
    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $apiTime = microtime(true) - $apiStartTime;
    
    // Close cURL
    curl_close($ch);
    
    // Parse response
    $responseData = json_decode($response, true);
    // Log ChatGPT response
    logChatGPTRequest([
        'sub_admin_id' => $sub_admin_id,
        'phone' => $phone,
        'name' => $leadName,
        'type' => 'Reply Message Response',
        'payload' => $postData,
        'incoming_message' => $incomingMessage
    ], $responseData, $httpCode, $apiTime, $error);
    
    // Log API response (for reply.log)
    logApiInteraction([
        'phone' => $phone,
        'api_provider' => 'chatgpt',
        'process' => 'Received response from ChatGPT API',
        'http_code' => $httpCode,
        'api_time' => $apiTime,
        'response' => $responseData,
        'error' => $error ? $error : null
    ]);
    
    // Handle errors
    if ($error) {
        error_log("ChatGPT API cURL Error: $error");
        return "Sorry, I'm having trouble processing your message right now. Please try again later.";
    }
    
    if ($httpCode !== 200) {
        error_log("ChatGPT API HTTP Error: $httpCode. Response: $response");
        return "Sorry, I'm having trouble processing your message right now. Please try again later.";
    }
    
    // Extract generated text from response
    if (isset($responseData['choices'][0]['message']['content'])) {
        $generatedText = $responseData['choices'][0]['message']['content'];
        return trim($generatedText);
    } else {
        error_log("ChatGPT API Unexpected Response: " . json_encode($responseData));
        return "Sorry, I couldn't generate a proper response. Please try again.";
    }
}

/**
 * Add message to queue
 */
function addMessageToQueue($sub_admin_id, $phone, $name, $message, $settings = null) {
    $conn = getDBConnection();
    
    // Get message interval from settings (default 60 seconds = 1 minute)
    $interval = 60; // Default 1 minute
    if ($settings && isset($settings['message_interval']) && $settings['message_interval'] > 0) {
        $interval = (int)$settings['message_interval'];
    }
    
    // Get last message sent time for this sub_admin_id
    $lastMessageQuery = "SELECT MAX(scheduled_at) as last_scheduled FROM message_queue WHERE sub_admin_id = ? AND status = 'sent'";
    $stmt = $conn->prepare($lastMessageQuery);
    $stmt->bind_param("i", $sub_admin_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $lastMessage = $result->fetch_assoc();
    $stmt->close();
    
    // Calculate scheduled time
    $scheduledAt = date('Y-m-d H:i:s');
    if ($lastMessage && $lastMessage['last_scheduled']) {
        // Add interval to last scheduled time
        $scheduledAt = date('Y-m-d H:i:s', strtotime($lastMessage['last_scheduled']) + $interval);
    }
    
    // Insert into queue
    $insertQuery = "INSERT INTO message_queue (sub_admin_id, phone, name, message, status, scheduled_at) VALUES (?, ?, ?, ?, 'pending', ?)";
    $stmt = $conn->prepare($insertQuery);
    $stmt->bind_param("issss", $sub_admin_id, $phone, $name, $message, $scheduledAt);
    $stmt->execute();
    $queueId = $conn->insert_id;
    $stmt->close();
    $conn->close();
    
    return $queueId;
}

/**
 * Process message queue - Send pending messages and retry failed messages
 */
function processMessageQueue($limit = 10) {
    $conn = getDBConnection();
    
    // Get pending messages that are ready to send (scheduled_at <= now)
    $query = "SELECT mq.*, s.whatsapp_api_token 
              FROM message_queue mq 
              INNER JOIN sub_admin_settings s ON mq.sub_admin_id = s.admin_id 
              WHERE (mq.status = 'pending' AND mq.scheduled_at <= NOW())
              OR (mq.status = 'failed' AND mq.attempts < 5 AND mq.scheduled_at <= NOW())
              ORDER BY mq.scheduled_at ASC 
              LIMIT ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $processed = 0;
    $maxRetries = 5; // Maximum retry attempts
    
    foreach ($messages as $msg) {
        // Update status to processing
        $updateStmt = $conn->prepare("UPDATE message_queue SET status = 'processing', attempts = attempts + 1 WHERE id = ?");
        $updateStmt->bind_param("i", $msg['id']);
        $updateStmt->execute();
        $updateStmt->close();
        
        // Send message
        $result = sendMessage($msg['phone'], $msg['message'], $msg['whatsapp_api_token'], $msg['sub_admin_id'], $msg['name']);
        
        if ($result['success']) {
            // Update status to sent
            $updateStmt = $conn->prepare("UPDATE message_queue SET status = 'sent', sent_at = NOW() WHERE id = ?");
            $updateStmt->bind_param("i", $msg['id']);
            $updateStmt->execute();
            $updateStmt->close();
            $processed++;
        } else {
            // Check if we should retry
            $newAttempts = $msg['attempts'] + 1;
            
            if ($newAttempts < $maxRetries) {
                // Retry after 2 minutes (120 seconds)
                $retryDelay = 120;
                $newScheduledAt = date('Y-m-d H:i:s', time() + $retryDelay);
                
                // Update status back to pending for retry
                $error = $result['error'] ?: 'Unknown error';
                $updateStmt = $conn->prepare("UPDATE message_queue SET status = 'pending', scheduled_at = ?, error = ? WHERE id = ?");
                $updateStmt->bind_param("ssi", $newScheduledAt, $error, $msg['id']);
                $updateStmt->execute();
                $updateStmt->close();
            } else {
                // Max retries reached - delete the message
                $deleteStmt = $conn->prepare("DELETE FROM message_queue WHERE id = ?");
                $deleteStmt->bind_param("i", $msg['id']);
                $deleteStmt->execute();
                $deleteStmt->close();
            }
        }
    }
    
    // Also delete old failed messages that have exceeded max retries
    $deleteOldFailed = $conn->prepare("DELETE FROM message_queue WHERE status = 'failed' AND attempts >= ?");
    $deleteOldFailed->bind_param("i", $maxRetries);
    $deleteOldFailed->execute();
    $deleteOldFailed->close();
    
    $conn->close();
    return $processed;
}

/**
 * Save WhatsApp message to database
 */
function saveWhatsAppMessage($sub_admin_id, $phone, $name, $message, $request_payload, $response_data, $http_code, $success, $error = null) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("INSERT INTO whatsapp_messages (sub_admin_id, phone, name, message, request_payload, response_data, http_code, success, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $request_payload_json = json_encode($request_payload, JSON_UNESCAPED_UNICODE);
    $response_data_json = json_encode($response_data, JSON_UNESCAPED_UNICODE);
    $stmt->bind_param("isssssiis", $sub_admin_id, $phone, $name, $message, $request_payload_json, $response_data_json, $http_code, $success, $error);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * Send message to phone number using wasenderapi.com API
 */
function sendMessage($phone, $message, $apiToken = null, $sub_admin_id = 0, $name = '') {
    $apiUrl = 'https://wasenderapi.com/api/send-message';
    // Use provided token or default
    $bearerToken = $apiToken ? $apiToken : WHATSAPP_API_TOKEN;
    
    // Format phone number with + prefix if not already present
    $formattedPhone = $phone;
    if (substr($phone, 0, 1) !== '+') {
        $formattedPhone = '+' . $phone;
    }
    
    // Prepare POST data
    $postData = [
        'to' => $formattedPhone,
        'text' => $message
    ];
    
    // Initialize cURL
    $ch = curl_init();
    
    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $bearerToken,
        'Content-Type: application/json'
    ]);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    // Close cURL
    curl_close($ch);
    
    // Parse response
    $responseData = json_decode($response, true);
    if ($responseData === null) {
        $responseData = ['raw_response' => $response];
    }
    
    $success = ($httpCode >= 200 && $httpCode < 300);
    
    // Save to database if sub_admin_id is provided
    if ($sub_admin_id > 0) {
        saveWhatsAppMessage($sub_admin_id, $phone, $name, $message, $postData, $responseData, $httpCode, $success ? 1 : 0, $error);
    }
    
    // Log response for debugging
    if ($error) {
        error_log("cURL Error sending message to $phone: $error");
    } else {
        error_log("Message sent to $phone. HTTP Code: $httpCode. Response: $response");
    }
    
    return [
        'success' => $success,
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $error
    ];
}

/**
 * Log message to database
 */
function logMessage($data, $type = 'received') {
    $conn = getDBConnection();
    
    $sub_admin_id = isset($data['sub_admin_id']) ? $data['sub_admin_id'] : 0;
    $phone = isset($data['phone']) ? $data['phone'] : (isset($data['number']) ? $data['number'] : '');
    $name = isset($data['name']) ? $data['name'] : '';
    $message = isset($data['message']) ? $data['message'] : (isset($data['text']) ? $data['text'] : json_encode($data));
    
    $stmt = $conn->prepare("INSERT INTO message_logs (sub_admin_id, phone, name, message, type, received_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("issss", $sub_admin_id, $phone, $name, $message, $type);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * Save chat history
 */
function saveChatHistory($phone, $incomingMessage, $result) {
    $conn = getDBConnection();
    
    $sub_admin_id = isset($result['sub_admin_id']) ? $result['sub_admin_id'] : 0;
    $name = isset($result['name']) ? $result['name'] : '';
    
    // Save incoming message with name
    $stmt = $conn->prepare("INSERT INTO chat_history (sub_admin_id, phone, name, message, direction, created_at) VALUES (?, ?, ?, ?, 'incoming', NOW())");
    $stmt->bind_param("isss", $sub_admin_id, $phone, $name, $incomingMessage);
    $stmt->execute();
    $stmt->close();
    
    // If there's a reply message in result, save it too
    if (isset($result['reply']) && $result['reply'] !== null) {
        $stmt = $conn->prepare("INSERT INTO chat_history (sub_admin_id, phone, name, message, direction, created_at) VALUES (?, ?, ?, ?, 'outgoing', NOW())");
        $stmt->bind_param("isss", $sub_admin_id, $phone, $name, $result['reply']);
        $stmt->execute();
        $stmt->close();
    }
    
    $conn->close();
}
?>

