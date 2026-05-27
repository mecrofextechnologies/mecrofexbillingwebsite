<?php
/**
 * ai_proxy.php
 * Server-side proxy for Anthropic API calls.
 * Place this file in your project root (same level as create_game.php).
 * 
 * Called via POST from the browser with JSON body:
 *   { "prompt": "...", "max_tokens": 4000 }
 * Returns the Anthropic API response as JSON.
 */

// ── Security: only allow logged-in creators ──────────────────────────────
include 'includes/connect.php';
$creator_id = $_SESSION['creator_id'] ?? null;
if (!$creator_id) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Please login first.']);
    exit;
}

// ── Only accept POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

header('Content-Type: application/json');

// ── Read request body ────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['prompt'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing prompt in request body.']);
    exit;
}

$prompt     = $input['prompt'];
$max_tokens = (int)($input['max_tokens'] ?? 4000);
$max_tokens = min(max($max_tokens, 100), 8000); // clamp between 100-8000

// ── Your Anthropic API key — set this in one place only ─────────────────
// Option A: Hard-code it here (simplest, keep this file out of git)
$api_key = 'YOUR_ANTHROPIC_API_KEY_HERE';

// Option B: Load from a config file outside web root (recommended)
// $config  = include __DIR__ . '/../config/secrets.php'; // returns ['anthropic_key' => '...']
// $api_key = $config['anthropic_key'];

// Option C: Environment variable (best for servers with .env support)
// $api_key = getenv('ANTHROPIC_API_KEY');

if (!$api_key || $api_key === 'YOUR_ANTHROPIC_API_KEY_HERE') {
    http_response_code(500);
    echo json_encode(['error' => 'API key not configured. Edit ai_proxy.php and set your Anthropic API key.']);
    exit;
}

// ── Call Anthropic API via cURL ──────────────────────────────────────────
$payload = json_encode([
    'model'      => 'claude-sonnet-4-20250514',
    'max_tokens' => $max_tokens,
    'messages'   => [
        ['role' => 'user', 'content' => $prompt]
    ]
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01',
    ],
]);

$response    = curl_exec($ch);
$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error  = curl_error($ch);
curl_close($ch);

// ── Handle cURL failure ──────────────────────────────────────────────────
if ($curl_error) {
    http_response_code(500);
    echo json_encode(['error' => 'cURL error: ' . $curl_error]);
    exit;
}

// ── Relay Anthropic's response back to browser ───────────────────────────
http_response_code($http_status);
echo $response;
exit;
