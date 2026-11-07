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

// --- ODXBrain (/brain/*, /_/about) -------------------------------------------------
// Mirrors ODXProxy 0.10.0 (SYSTEM_ARCHITECTURE.md §4.4, §4.8-§4.10, §6). The
// x-api-key picks the deployment: `old-proxy` = before 0.10.0 (no `features`,
// bare 404 on /brain/*), `no-brain` = 0.10.0 without ODXBrain (501 -32008).
// Otherwise the `query` picks an error (`err.brain.*`). Each /brain request
// (body + headers) is saved to a per-port temp file the test reads.
$gatewayKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$brainEnvelope = static function (int $status, $id, ?array $error, $result = null): void {
    http_response_code($status);
    $env = ['jsonrpc' => '2.0', 'id' => $id];
    if ($error !== null) {
        $env['error'] = $error;
    } else {
        $env['result'] = $result;
    }
    echo json_encode($env);
};

if ($path === '/_/about') {
    $result = ['build' => '01JTEST', 'version' => $gatewayKey === 'old-proxy' ? '0.9.0' : '0.10.0'];
    if ($gatewayKey !== 'old-proxy') {
        $result['features'] = ['odxbrain' => $gatewayKey !== 'no-brain'];
    }
    $brainEnvelope(200, 'about', null, $result);
    return;
}

if (str_starts_with((string) $path, '/brain/')) {
    file_put_contents(sys_get_temp_dir() . '/odx-mock-brain-' . $_SERVER['SERVER_PORT'] . '.json', json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'path' => $path,
        'body' => $raw,
        'timeout' => $_SERVER['HTTP_X_REQUEST_TIMEOUT'] ?? null,
        'apiKey' => $gatewayKey,
    ]));
    if ($gatewayKey === 'old-proxy') {
        http_response_code(404);
        header('Content-Type: text/plain');
        return;
    }
    if ($gatewayKey === 'bad-key') {
        $brainEnvelope(401, null, ['code' => -32000, 'message' => 'Bad / missing x-api-key']);
        return;
    }
    if ($gatewayKey === 'no-brain') {
        $brainEnvelope(501, $id, ['code' => -32008, 'message' => 'ODXBrain is not installed on this proxy',
            'data' => ['type' => 'odxbrain_not_installed']]);
        return;
    }
    $query = $req['query'] ?? '';
    $brainErrors = [
        'err.brain.unreachable' => [502, -32009, 'ODXBrain is unreachable', null],
        'err.brain.timeout' => [504, -32010, 'ODXBrain timed out', null],
        'err.brain.proxykey' => [502, -32011, 'ODXBrain rejected the proxy key', null],
        'err.brain.license' => [403, 0, 'Invalid License', null],
        'err.brain.400' => [200, 400, 'k must be >= 1', ['message' => 'k must be >= 1', 'type' => 'invalid_request_error']],
        'err.brain.version' => [200, 400, 'unsupported', ['message' => 'unsupported', 'type' => 'unsupported_version']],
        'err.brain.503' => [200, 503, 'no SOP index', ['message' => 'no SOP index', 'type' => 'sops_unavailable']],
        'err.brain.500' => [200, 500, 'boom', ['message' => 'boom', 'type' => 'internal_error']],
    ];
    if (isset($brainErrors[$query])) {
        [$status, $code, $message, $data] = $brainErrors[$query];
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        $brainEnvelope($status, $id, $error);
        return;
    }
    if ($query === 'err.brain.body422') {
        http_response_code(422);
        header('Content-Type: text/plain');
        echo 'Failed to deserialize the JSON body: missing field `query`';
        return;
    }
    if ($path === '/brain/v1/info') {
        $brainEnvelope(200, null, null, [
            'service' => 'odxbrain',
            'model' => ['id' => 'bge-m3', 'dim' => 1024],
            'corpus' => ['loaded' => true, 'version' => '2026.09.4', 'models' => 41,
                'odoo' => ['min_version' => '14.0', 'max_version' => '19.0']],
            'sops' => ['loaded' => true, 'count' => 7, 'generation' => 1, 'root' => str_repeat('ab', 32)],
        ]);
        return;
    }
    if ($path === '/brain/v1/resolve') {
        $brainEnvelope(200, $id, null, [
            'candidates' => [
                ['model' => 'account.move', 'score' => 0.513],
                ['model' => 'account.payment', 'score' => 0.492],
                ['model' => 'account.payment.term', 'score' => 0.012, 'provisional' => true],
            ],
            'model' => 'bge-m3', 'dim' => 1024, 'corpus_version' => '2026.09.4',
            'usage' => ['prompt_tokens' => 10],
            'excluded' => [['model' => 'account.invoice', 'reason' => 'removed', 'successor' => 'account.move']],
            'version_unaudited' => '19.0',
            'future_field' => ['kept' => true],
        ]);
        return;
    }
    if ($path === '/brain/v1/sops/resolve') {
        $result = [
            'candidates' => [['id' => 'vendor-bill-from-po', 'version' => '181c84a3b7906648',
                'title' => 'Record a vendor bill from a received purchase order', 'score' => 0.80]],
            'model' => 'bge-m3', 'dim' => 1024,
            'sops' => ['generation' => 1, 'root' => str_repeat('cd', 32)],
            'usage' => ['prompt_tokens' => 11],
        ];
        if ($query === 'nothing matches') {
            $result['candidates'] = [];
            $result['match'] = 'none';
            $result['facts_missing'] = ['installed_modules'];
        }
        $brainEnvelope(200, $id, null, $result);
        return;
    }
    http_response_code(404);
    return;
}

if ($path === '/api/odoo/version') {
    $enterprise = ($req['url'] ?? '') !== 'https://community.test';
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
        'server_version' => $enterprise ? '19.0+e' : '19.0',
        'server_version_info' => [19, 0, 0, 'final', 0, $enterprise ? 'e' : ''],
        'server_serie' => '19.0', 'protocol_version' => 1,
    ]]);
    return;
}

if ($path === '/v2/odoo/version') {
    if (($req['url'] ?? '') === 'https://old-odoo.test') {
        echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32006, 'message' => 'JSON-2 is not available']]);
        return;
    }
    $major = ($req['url'] ?? '') === 'https://eighteen.test' ? 18 : 20;
    // Packaged builds append a date after the edition; the community build has no `+e`
    // (version_info[5] is still 'e' there, to prove the SDK ignores it for edition).
    $version = match ($req['url'] ?? '') {
        'https://packaged.test' => "$major.0+e-20260925",
        'https://community.test' => "$major.0",
        default => "$major.0+e",
    };
    echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
        'version_info' => [$major, 0, 0, 'final', 0, 'e'], 'version' => $version, 'raw' => $raw,
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
