<?php
require_once __DIR__ . '/../stores/StoreCatalogFactory.php';

/**
 * Gemini function calling (tools): get_product_details + place_order with PDO + multi-turn follow-up.
 * Requires: getPDOConnection(), products + orders tables (see migration_products_orders.sql).
 */
class GeminiFunctionCalling
{
    const MODEL = 'gemini-3-flash-preview';
    const MAX_TOOL_ROUNDS = 5;

    /** @var PDO */
    protected $pdo;

    /** @var int sub_admin / store id */
    protected $storeId;

    /** @var string verified customer phone (never trust model for identity) */
    protected $customerPhone;

    /** @var string */
    protected $apiKey;

    /** @var string */
    protected $systemInstruction;

    /** @var StoreCatalogInterface */
    protected $catalog;

    /** @var string */
    protected $catalogProvider;

    /**
     * @param array<string,mixed>|null $settings
     */
    public function __construct(PDO $pdo, int $storeId, string $customerPhone, string $apiKey, string $systemInstruction, ?array $settings = null)
    {
        $this->pdo = $pdo;
        $this->storeId = $storeId;
        $this->customerPhone = $customerPhone;
        $this->apiKey = $apiKey;
        $this->systemInstruction = $systemInstruction;
        $this->catalog = StoreCatalogFactory::make($pdo, $storeId, $settings);
        $cfg = [];
        if ($settings && !empty($settings['store_type_config_json'])) {
            $decoded = json_decode((string) $settings['store_type_config_json'], true);
            if (is_array($decoded)) {
                $cfg = $decoded;
            }
        }
        $this->catalogProvider = strtolower(trim((string) ($cfg['catalog_provider'] ?? 'custom')));
    }

    /**
     * Tool definitions for Gemini REST (function_declarations).
     */
    public static function toolDeclarations(): array
    {
        return [
            [
                'functionDeclarations' => [
                    [
                        'name' => 'get_product_details',
                        'description' => 'Fetch live price, stock, and description for a product. Use the slug from a product URL path (last segment) or a normalized slug from the product name. Do not guess prices; always call this when the user asks about a specific product, price, or stock.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'slug' => [
                                    'type' => 'string',
                                    'description' => 'Product slug (e.g. blue-denim-jeans) from URL or name',
                                ],
                            ],
                            'required' => ['slug'],
                        ],
                    ],
                    [
                        'name' => 'place_order',
                        'description' => 'Create a pending order when the customer clearly wants to purchase. Uses the system-verified phone number; only pass slug and quantity.',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'slug' => [
                                    'type' => 'string',
                                    'description' => 'Product slug to order',
                                ],
                                'quantity' => [
                                    'type' => 'integer',
                                    'description' => 'Number of units (minimum 1)',
                                ],
                            ],
                            'required' => ['slug', 'quantity'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Default system rules layered on top of store settings.
     */
    public static function defaultToolSystemRules(): string
    {
        return <<<TXT
You are a helpful store assistant.
- For any question about product price, availability, stock, description, or when the user sends a product URL or product name, you MUST call get_product_details with the correct slug. Extract the slug from URLs (typically the last path segment after /product/ or similar). Never invent prices or inventory.
- When the user clearly wants to buy, call place_order with slug and quantity. Do not place orders without clear purchase intent.
- For general greetings or topics unrelated to catalog/orders, respond naturally without calling tools.
TXT;
    }

    /**
     * Run tool loop: user message → optional function calls → final text.
     */
    public function run(string $userMessage): string
    {
        $userMessage = trim($userMessage);
        if ($userMessage === '') {
            return '';
        }

        $contents = [
            [
                'role' => 'user',
                'parts' => [['text' => $userMessage]],
            ],
        ];

        $url = $this->buildUrl();

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $payload = [
                'systemInstruction' => [
                    'parts' => [['text' => $this->systemInstruction]],
                ],
                'contents' => $contents,
                'tools' => self::toolDeclarations(),
                'toolConfig' => [
                    'functionCallingConfig' => [
                        'mode' => 'AUTO',
                    ],
                ],
            ];

            $http = $this->postJson($url, $payload);
            $raw = $http['body'];
            if ($http['http_code'] !== 200) {
                error_log('GeminiFunctionCalling HTTP ' . $http['http_code'] . ': ' . substr($raw, 0, 500));
                return 'Sorry, the AI service returned an error. Please try again later.';
            }
            $data = json_decode($raw, true);

            if (!is_array($data)) {
                return 'Sorry, the assistant returned an invalid response. Please try again.';
            }

            if (isset($data['error'])) {
                $msg = isset($data['error']['message']) ? $data['error']['message'] : json_encode($data['error']);
                error_log('GeminiFunctionCalling API error: ' . $msg);
                return 'Sorry, I could not complete that request. Please try again later.';
            }

            $candidate = $data['candidates'][0] ?? null;
            if (!is_array($candidate)) {
                return 'Sorry, I could not generate a reply. Please try again.';
            }

            $finishReason = $candidate['finishReason'] ?? '';
            $parts = $candidate['content']['parts'] ?? [];

            $functionCalls = [];
            foreach ($parts as $part) {
                if (!empty($part['functionCall'])) {
                    $functionCalls[] = $part['functionCall'];
                }
            }

            if ($functionCalls !== []) {
                // Pass through API parts (preserves any model metadata alongside functionCall)
                $contents[] = [
                    'role' => 'model',
                    'parts' => $parts,
                ];

                $responseParts = [];
                foreach ($functionCalls as $fc) {
                    $name = $fc['name'] ?? '';
                    $args = isset($fc['args']) && is_array($fc['args']) ? $fc['args'] : [];
                    $responseParts[] = [
                        'functionResponse' => [
                            'name' => $name,
                            'response' => $this->executeTool($name, $args),
                        ],
                    ];
                }

                $contents[] = [
                    'role' => 'user',
                    'parts' => $responseParts,
                ];

                continue;
            }

            $text = self::extractTextFromParts($parts);
            if ($text !== '') {
                return $text;
            }

            if ($finishReason === 'MALFORMED_FUNCTION_CALL' || $finishReason === 'ERROR') {
                return 'Sorry, something went wrong processing that request.';
            }

            return 'Sorry, I could not generate a reply. Please try again.';
        }

        return 'Sorry, the request took too many steps. Please try a simpler question.';
    }

    protected function buildUrl(): string
    {
        if (function_exists('getGeminiApiUrl')) {
            return getGeminiApiUrl($this->apiKey, self::MODEL);
        }
        return 'https://generativelanguage.googleapis.com/v1beta/models/'
            . self::MODEL
            . ':generateContent?key=' . rawurlencode($this->apiKey);
    }

    /**
     * @return array{body: string, http_code: int}
     */
    protected function postJson(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['body' => (string) $body, 'http_code' => $code];
    }

    /**
     * @param mixed $parts
     */
    protected static function extractTextFromParts($parts): string
    {
        if (!is_array($parts)) {
            return '';
        }
        $chunks = [];
        foreach ($parts as $part) {
            if (!empty($part['text'])) {
                $chunks[] = (string) $part['text'];
            }
        }
        return trim(implode('', $chunks));
    }

    /**
     * @return array<string, mixed> Structured payload for functionResponse.response
     */
    protected function executeTool(string $name, array $args): array
    {
        if ($name === 'get_product_details') {
            return $this->toolGetProductDetails($args);
        }
        if ($name === 'place_order') {
            return $this->toolPlaceOrder($args);
        }
        return ['error' => 'Unknown tool: ' . $name];
    }

    protected function toolGetProductDetails(array $args): array
    {
        $slug = isset($args['slug']) ? trim((string) $args['slug']) : '';
        if ($slug === '') {
            return ['ok' => false, 'error' => 'missing_slug'];
        }

        $slug = strtolower(preg_replace('/[^a-zA-Z0-9\-]+/', '-', $slug));
        $slug = trim($slug, '-');

        $row = $this->catalog->getSingle($slug);
        if (is_array($row) && !empty($row)) {
            return [
                'ok' => true,
                'product' => $row,
                'provider' => $this->catalogProvider,
            ];
        }

        $rows = $this->catalog->getSearch($slug, 5);
        if (count($rows) === 1) {
            return ['ok' => true, 'product' => $rows[0], 'provider' => $this->catalogProvider];
        }
        if (count($rows) > 1) {
            return [
                'ok' => true,
                'ambiguous' => true,
                'provider' => $this->catalogProvider,
                'matches' => array_map(function ($r) {
                    return [
                        'slug' => $r['slug'] ?? '',
                        'name' => $r['name'] ?? '',
                        'price' => $r['price'] ?? '',
                        'stock' => $r['stock'] ?? null,
                    ];
                }, $rows),
            ];
        }

        return ['ok' => false, 'error' => 'product_not_found', 'slug' => $slug];
    }

    protected function toolPlaceOrder(array $args): array
    {
        $slug = isset($args['slug']) ? trim((string) $args['slug']) : '';
        $qty = isset($args['quantity']) ? (int) $args['quantity'] : 0;
        if ($slug === '' || $qty < 1) {
            return ['ok' => false, 'error' => 'invalid_arguments'];
        }

        $slug = strtolower(preg_replace('/[^a-zA-Z0-9\-]+/', '-', $slug));
        $slug = trim($slug, '-');

        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                'SELECT id, slug, name, price, stock FROM products WHERE sub_admin_id = ? AND slug = ? FOR UPDATE'
            );
            $st->execute([$this->storeId, $slug]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) {
                $this->pdo->rollBack();
                return ['ok' => false, 'error' => 'product_not_found'];
            }
            if ((int) $p['stock'] < $qty) {
                $this->pdo->rollBack();
                return [
                    'ok' => false,
                    'error' => 'insufficient_stock',
                    'available' => (int) $p['stock'],
                ];
            }

            $ins = $this->pdo->prepare(
                'INSERT INTO orders (sub_admin_id, phone, product_id, quantity, status) VALUES (?, ?, ?, ?, ?)'
            );
            $ins->execute([$this->storeId, $this->customerPhone, (int) $p['id'], $qty, 'pending']);

            $upd = $this->pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND sub_admin_id = ?');
            $upd->execute([$qty, (int) $p['id'], $this->storeId]);

            $this->pdo->commit();

            return [
                'ok' => true,
                'order' => [
                    'product' => $p['name'],
                    'slug' => $p['slug'],
                    'quantity' => $qty,
                    'status' => 'pending',
                ],
            ];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('place_order failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'database_error'];
        }
    }
}

/**
 * Gemini reply with tools (used when GEMINI_USE_FUNCTION_CALLING is true).
 */
function getGeminiReplyWithTools($incomingMessage, $phone, $settings = null)
{
    if ($settings !== null) {
        $settings = mergePlatformAiSettings($settings);
    }
    $apiStartTime = microtime(true);
    $apiKey = ($settings && !empty($settings['gemini_api_key'])) ? $settings['gemini_api_key'] : GEMINI_API_KEY;
    $sub_admin_id = ($settings && isset($settings['admin_id'])) ? (int) $settings['admin_id'] : 0;

    $storeRules = GeminiFunctionCalling::defaultToolSystemRules();
    $custom = '';
    if ($settings && !empty($settings['system_instruction'])) {
        $custom .= trim((string) $settings['system_instruction']) . "\n\n";
    } elseif ($settings && !empty($settings['starting_message'])) {
        $custom .= trim((string) $settings['starting_message']) . "\n\n";
    }
    $systemInstruction = trim($custom . $storeRules);
    if ($sub_admin_id > 0 && function_exists('faq_build_system_prompt_block')) {
        $faqBlock = faq_build_system_prompt_block($sub_admin_id);
        if ($faqBlock !== '') {
            $systemInstruction .= "\n\nStore FAQ (use when relevant; do not contradict):\n" . $faqBlock;
        }
    }

    try {
        $pdo = getPDOConnection();
    } catch (Throwable $e) {
        error_log('getPDOConnection failed: ' . $e->getMessage());
        return getGeminiReply($incomingMessage, $phone, $settings);
    }

    $svc = new GeminiFunctionCalling($pdo, $sub_admin_id, $phone, $apiKey, $systemInstruction, is_array($settings) ? $settings : null);
    $generatedText = $svc->run($incomingMessage);
    $apiTime = microtime(true) - $apiStartTime;

    $ctx = function_exists('gemini_resolve_category_context') ? gemini_resolve_category_context($sub_admin_id) : ['id' => null, 'name' => ''];
    $outgoingReply = $generatedText;
    if ($generatedText !== '' && trim($generatedText) !== '' && function_exists('filter_gemini_reply_output')) {
        $outgoingReply = filter_gemini_reply_output(
            $incomingMessage,
            $generatedText,
            $sub_admin_id,
            $ctx['name'] ?? '',
            $ctx['id'] ?? null
        );
    }

    if ($sub_admin_id > 0) {
        $leadName = getLeadName($phone, $sub_admin_id);
        $request_payload = json_encode(
            ['mode' => 'function_calling', 'incoming' => $incomingMessage],
            JSON_UNESCAPED_UNICODE
        );
        $response_data = json_encode(['reply' => $outgoingReply], JSON_UNESCAPED_UNICODE);
        saveGeminiHistory(
            $sub_admin_id,
            $phone,
            $leadName,
            'reply',
            $incomingMessage,
            $outgoingReply ?: 'No response generated',
            $request_payload,
            $response_data,
            200,
            $apiTime,
            null
        );
    }

    logApiInteraction([
        'phone' => $phone,
        'api_provider' => 'gemini',
        'process' => 'Function calling reply completed',
        'api_time' => $apiTime,
        'final_reply' => $outgoingReply,
    ]);

    return $outgoingReply !== '' ? $outgoingReply : 'Sorry, I could not generate a reply.';
}
