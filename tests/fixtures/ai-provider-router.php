<?php

declare(strict_types=1);

/**
 * Deterministic, network-local OpenAI-compatible fixture used only by the
 * deployed headless acceptance lane. It validates the privacy-critical request
 * shape before returning one stock-count proposal with a bounded quantity.
 */

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function reject(string $detail): never
{
    respond(422, [
        'error' => [
            'type' => 'invalid_acceptance_request',
            'message' => $detail,
        ],
    ]);
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');

if ($method === 'GET' && $path === '/health') {
    respond(200, ['status' => 'ready']);
}
if ($method !== 'POST' || $path !== '/v1/chat/completions') {
    respond(404, ['error' => ['type' => 'not_found', 'message' => 'Fixture route not found.']]);
}

$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (! hash_equals('Bearer acceptance-ai-token-replacement-2222', $authorization)) {
    respond(401, ['error' => ['type' => 'authentication_failed', 'message' => 'Credential rejected.']]);
}

$raw = file_get_contents('php://input');
if (! is_string($raw) || $raw === '' || strlen($raw) > 1048576) {
    reject('The request body is missing or too large.');
}
try {
    $request = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    reject('The request body is not JSON.');
}
if (! is_array($request) || array_is_list($request)) {
    reject('The request body must be an object.');
}
if (($request['model'] ?? null) !== 'acceptance-vision' || ($request['stream'] ?? null) !== false) {
    reject('The deterministic model and non-streaming mode are required.');
}

$format = $request['response_format']['json_schema'] ?? null;
if (
    ! is_array($format)
    || ($request['response_format']['type'] ?? null) !== 'json_schema'
    || ($format['strict'] ?? null) !== true
    || ! is_string($format['name'] ?? null)
    || ! str_ends_with($format['name'], '_v2')
    || ! is_array($format['schema'] ?? null)
    || ! in_array('quantityMinimum', $format['schema']['properties']['candidates']['items']['required'] ?? [], true)
    || ! in_array('quantityMaximum', $format['schema']['properties']['candidates']['items']['required'] ?? [], true)
) {
    reject('The API 1.18 strict quantity-range response schema is required.');
}

$content = $request['messages'][0]['content'] ?? null;
if (! is_array($content) || ! array_is_list($content)) {
    reject('The provider request has no multimodal content list.');
}
$hasPrompt = false;
$hasPng = false;
foreach ($content as $part) {
    if (! is_array($part)) {
        continue;
    }
    if (($part['type'] ?? null) === 'text' && str_contains((string) ($part['text'] ?? ''), 'mandatory human review')) {
        $hasPrompt = true;
    }
    $imageUrl = $part['image_url']['url'] ?? null;
    if (
        ($part['type'] ?? null) === 'image_url'
        && is_string($imageUrl)
        && str_starts_with($imageUrl, 'data:image/png;base64,')
    ) {
        $hasPng = true;
    }
}
if (! $hasPrompt || ! $hasPng) {
    reject('The request must contain the review disclosure and one inline PNG.');
}

$extraction = [
    'documentType' => 'stock',
    'merchant' => null,
    'receiptNumber' => null,
    'purchaseDate' => null,
    'currency' => null,
    'totalAmount' => null,
    'taxAmount' => null,
    'notes' => 'Deterministic acceptance fixture.',
    'warnings' => [],
    'candidates' => [[
        'candidateType' => 'stock_item',
        'rawText' => 'Visible baked-bean tins',
        'description' => 'Acceptance baked beans',
        'brand' => 'Providentia fixture',
        'product' => 'Baked beans',
        'variant' => null,
        'quantity' => null,
        'quantityMinimum' => '6',
        'quantityMaximum' => '8',
        'packText' => '400 g tin',
        'unitPrice' => null,
        'lineTotal' => null,
        'discountAmount' => null,
        'taxAmount' => null,
        'boundingRegion' => [
            'x' => 0.1,
            'y' => 0.1,
            'width' => 0.6,
            'height' => 0.5,
        ],
        'confidence' => 0.93,
        'fieldConfidence' => [
            'description' => 0.98,
            'quantity' => 0.93,
            'packText' => 0.9,
            'unitPrice' => null,
            'lineTotal' => null,
        ],
        'warnings' => [],
        'unresolvedValues' => [],
    ]],
];

respond(200, [
    'choices' => [[
        'finish_reason' => 'stop',
        'message' => [
            'content' => json_encode($extraction, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ],
    ]],
    'usage' => [
        'prompt_tokens' => 40,
        'completion_tokens' => 20,
        'total_tokens' => 60,
    ],
]);
