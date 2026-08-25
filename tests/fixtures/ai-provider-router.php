<?php

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- executable router fixture by design.

/**
 * Deterministic, network-local OpenAI-compatible fixture used only by the
 * deployed headless acceptance lane. It validates the privacy-critical request
 * shape before returning one stock-count proposal with a bounded quantity.
 */

/** @param array<string, mixed> $body */
function respond(int $status, array $body): never
{
    $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    json_decode($encoded, true, 128, JSON_THROW_ON_ERROR);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($encoded));
    header('X-Acceptance-Body-Length: ' . strlen($encoded));
    header('X-Acceptance-Body-Sha256: ' . hash('sha256', $encoded));
    header('Connection: close');
    echo $encoded;
    exit;
}

function reject(string $code, string $detail, int $status = 422): never
{
    if (preg_match('/^[a-z0-9_]{1,64}$/D', $code) !== 1) {
        throw new LogicException('The acceptance rejection code is not bounded.');
    }
    header('X-Acceptance-Rejection-Code: ' . $code);
    error_log('AI_FIXTURE_REJECTION code=' . $code);
    respond($status, [
        'error' => [
            'type' => $code,
            'message' => $detail,
        ],
    ]);
}

/** @return array<string, mixed> */
function deterministicProviderResponse(): array
{
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

    $extractionJson = json_encode($extraction, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    // Fail inside the fixture instead of sending content that cannot be parsed
    // by the same strict JSON boundary exercised by the production adapter.
    json_decode($extractionJson, true, 128, JSON_THROW_ON_ERROR);

    return [
        'choices' => [[
            'finish_reason' => 'stop',
            'message' => [
                'content' => $extractionJson,
            ],
        ]],
        'usage' => [
            'prompt_tokens' => 40,
            'completion_tokens' => 20,
            'total_tokens' => 60,
        ],
    ];
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');

if ($method === 'GET' && $path === '/health') {
    respond(200, ['status' => 'ready']);
}
// This fixture has no published host port. The self-test route is reachable
// only from the isolated acceptance network and deliberately bypasses request
// validation so transport/framing can be proven independently.
if ($method === 'GET' && $path === '/self-test') {
    respond(200, deterministicProviderResponse());
}
if ($method !== 'POST' || $path !== '/v1/chat/completions') {
    reject(
        $method !== 'POST' ? 'route_method_mismatch' : 'route_path_mismatch',
        'Fixture route not found.',
        404,
    );
}

$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (! hash_equals('Bearer acceptance-ai-token-replacement-2222', $authorization)) {
    reject('authentication_failed', 'Credential rejected.', 401);
}

$raw = file_get_contents('php://input');
if (! is_string($raw) || $raw === '' || strlen($raw) > 1048576) {
    reject('invalid_body_size', 'The request body is missing or too large.');
}
try {
    $request = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    reject('invalid_json', 'The request body is not JSON.');
}
if (! is_array($request) || array_is_list($request)) {
    reject('invalid_object', 'The request body must be an object.');
}
if (($request['model'] ?? null) !== 'acceptance-vision' || ($request['stream'] ?? null) !== false) {
    reject('invalid_model_or_stream', 'The deterministic model and non-streaming mode are required.');
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
    reject('invalid_response_schema', 'The API 1.18 strict quantity-range response schema is required.');
}

$content = $request['messages'][0]['content'] ?? null;
if (! is_array($content) || ! array_is_list($content)) {
    reject('invalid_multimodal_content', 'The provider request has no multimodal content list.');
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
    reject('missing_disclosure_or_png', 'The request must contain the review disclosure and one inline PNG.');
}

error_log('AI_FIXTURE_REQUEST result=accepted');
respond(200, deterministicProviderResponse());
