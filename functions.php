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
    
    $postData = [
        'contents' => [
            [
                'parts' => [
                    [
                        'text' => $fullPrompt
                    ]
                ]
            ]
        ]
    ];
    
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
    
    // Get sub_admin_id from settings
    $sub_admin_id = ($settings && isset($settings['admin_id'])) ? $settings['admin_id'] : 0;
    
    // Extract generated text
    $generatedText = '';
    if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        $generatedText = trim($responseData['candidates'][0]['content']['parts'][0]['text']);
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
            $generatedText ?: 'No response generated',
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
        return $generatedText;
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
 * Get reply using Google Gemini API
 */
function getGeminiReply($incomingMessage, $phone, $settings = null) {
    $apiStartTime = microtime(true);
    
    // Use sub-admin's API key if provided, otherwise use default
    $apiKey = ($settings && !empty($settings['gemini_api_key'])) ? $settings['gemini_api_key'] : GEMINI_API_KEY;
    $model = ($settings && !empty($settings['gemini_model'])) ? $settings['gemini_model'] : null;
    $apiUrl = getGeminiApiUrl($apiKey, $model);
    
    // Prepare message with starting message if available
    $fullMessage = '';
    if ($settings && !empty($settings['starting_message'])) {
        $fullMessage = $settings['starting_message'] . ' client msg: ' . $incomingMessage;
    } else {
        // Default starting message
        $fullMessage = 'ap as a chatbot kam kr rae ho '.$incomingMessage;
    }
    
    // Prepare request data
    $postData = [
        'contents' => [
            [
                'parts' => [
                    [
                        'text' => $fullMessage
                    ]
                ]
            ]
        ]
    ];
    
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
    $sub_admin_id = ($settings && isset($settings['admin_id'])) ? $settings['admin_id'] : 0;
    $leadName = getLeadName($phone, $sub_admin_id);
    
    // Extract generated text from response
    $generatedText = '';
    if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        $generatedText = $responseData['candidates'][0]['content']['parts'][0]['text'];
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
            $generatedText ?: 'No response generated',
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
        'error' => $error ? $error : null
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
    
    // Return generated text
    if (!empty($generatedText)) {
        return $generatedText;
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

