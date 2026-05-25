<?php

declare(strict_types=1);

// Mock ODXProxy gateway, served via `php -S`, for the integration tests.
// It echoes the raw request body back inside `result.raw` so a test can assert the
// exact bytes that went on the wire (e.g. keyword == {} not []), and branches on
// `model_id` to exercise the two error paths from SYSTEM_ARCHITECTURE.md §6.

$raw = file_get_contents('php://input') ?: '';
$req = json_decode($raw, true) ?: [];
$id = $req['id'] ?? null;
$model = $req['model_id'] ?? '';

header('Content-Type: application/json');

if ($model === 'err.logic') {
    // Odoo-side logic error: HTTP 200 *with* an error envelope (spec §6).
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => ['code' => 200, 'message' => 'Access Denied', 'data' => ['name' => 'AccessError']],
    ]);
    return;
}

if ($model === 'err.http') {
    // Proxy-level failure: non-200 status code.
    http_response_code(401);
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => null,
        'error' => ['code' => -32000, 'message' => 'Bad / missing x-api-key'],
    ]);
    return;
}

echo json_encode([
    'jsonrpc' => '2.0',
    'id' => $id,
    'result' => [
        'raw' => $raw,                                   // exact wire payload, for assertions
        'apiKey' => $_SERVER['HTTP_X_API_KEY'] ?? null,  // gateway key travels as a header
        'path' => $_SERVER['REQUEST_URI'] ?? null,
        'records' => [['id' => 1, 'name' => 'Acme']],
    ],
]);
