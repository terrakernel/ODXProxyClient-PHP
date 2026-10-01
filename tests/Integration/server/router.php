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
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

header('Content-Type: application/json');

// --- v2 (/v2/odoo/*) -------------------------------------------------------------
// Errors mirror what ODXProxy 0.9.0 sends (SYSTEM_ARCHITECTURE.md §4.6 / §6). v2
// results are typed (lists, ints, bools), so instead of echoing the body in the
// result, each v2 request body is saved to a per-port temp file the test reads.
if (str_starts_with((string) $path, '/v2/')) {
    file_put_contents(sys_get_temp_dir() . '/odx-mock-v2-' . $_SERVER['SERVER_PORT'] . '.json', $raw);
}

if ($path === '/v2/odoo/version') {
    if (($req['url'] ?? '') === 'https://old-odoo.test') {
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32006, 'message' => 'JSON-2 is not available']]);
        return;
    }
    $major = ($req['url'] ?? '') === 'https://eighteen.test' ? 18 : 20;
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
        'version_info' => [$major, 0, 0, 'final', 0, 'e'], 'version' => "$major.0+e", 'raw' => $raw,
    ]]);
    return;
}

if ($path === '/v2/odoo/execute') {
    $v2Errors = [
        // Odoo-side errors: HTTP 200, code = Odoo's HTTP status, data = Odoo's error object.
        'err.v2.422' => [200, 422, 'Name is required', ['name' => 'odoo.exceptions.ValidationError', 'message' => 'Name is required']],
        'err.v2.409' => [200, 409, 'Lock conflict', ['name' => 'odoo.exceptions.LockError']],
        'err.v2.401' => [200, 401, 'Invalid apikey', ['name' => 'werkzeug.exceptions.Unauthorized']],
        // Proxy-layer errors.
        'err.v2.json2' => [200, -32006, 'JSON-2 is not available', null],
        'err.v2.invalid' => [400, -32007, 'invalid Odoo model name', null],
        'err.v2.license' => [403, 0, 'Invalid License', null],
        'err.v2.connect' => [502, -32004, 'Failed to connect', null],
    ];
    if (isset($v2Errors[$model])) {
        [$status, $code, $message, $data] = $v2Errors[$model];
        http_response_code($status);
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]);
        return;
    }
    if ($model === 'err.v2.body422') {
        // axum rejecting the request body: plain text, no envelope.
        http_response_code(422);
        header('Content-Type: text/plain');
        echo 'Failed to deserialize the JSON body';
        return;
    }
    // Success: result shaped by method, plus the raw request for wire assertions.
    $method = $req['method'] ?? '';
    $results = [
        'search' => [1, 2], 'search_count' => 2, 'create' => [41, 42], 'write' => true, 'unlink' => true,
        'fields_get' => ['name' => ['type' => 'char']],
    ];
    $result = $results[$method] ?? [['id' => 1, 'name' => 'Acme']];
    if ($method === 'create') {
        $result = range(41, 40 + count($req['kwargs']['vals_list'] ?? []));
    }
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    return;
}

if ($model === 'err.odoo0') {
    // v1 against Odoo 19+: every /jsonrpc error is code 0, forwarded on HTTP 200.
    echo json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => ['code' => 0, 'message' => 'Odoo Server Error', 'data' => ['name' => 'builtins.ValueError']],
    ]);
    return;
}

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
