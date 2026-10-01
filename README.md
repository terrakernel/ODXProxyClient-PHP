# ODXProxy Client for PHP
[![Latest Version](https://img.shields.io/packagist/v/odxproxy/client)](https://packagist.org/packages/odxproxy/client)
![PHP Version](https://img.shields.io/packagist/php-v/odxproxy/client)
![License](https://img.shields.io/packagist/l/odxproxy/client)


A high-performance, low-footprint, zero-dependency PHP client for interacting with Odoo instances via the ODXProxy Gateway.
This library is designed to be lightweight (using native curl and json extensions only) while maintaining strict type safety and security.

### Requirements
- PHP >= 8.1
- Extensions: ext-curl, ext-json

### Installation
```bash
composer require odxproxy/client
```

### Quick Start (Global Initialization)
Similar to the Android SDK, you can initialize the client once (e.g., in your index.php, bootstrap.php, or Laravel AppServiceProvider). This sets up a Request-Scoped Singleton that allows you to access the API anywhere in your code without passing configuration arrays around.

```php
use OdxProxy\Odx;

// 1. Initialize the client (do this once per request)
Odx::init([
    'gateway_url'     => 'https://gateway.odxproxy.io',
    'gateway_api_key' => 'YOUR_GATEWAY_KEY',
    'url'             => 'https://my-odoo-instance.com',
    'user_id'         => 1,
    'db'              => 'odoo_db',
    'api_key'         => 'ODOO_USER_API_KEY'
]);

// 2. Use it anywhere
try {
    // Fetch partners
    $partners = Odx::searchRead('res.partner', [['customer_rank', '>', 0]]);
    print_r($partners);
} catch (\OdxProxy\Exception\OdxException $e) {
    echo "Error {$e->getCode()}: {$e->getMessage()}";
}
```

## Usage Guide
### 1. Reading Data
#### searchRead
Combines searching and reading in one optimized call.
```php
$domain = [['type', '=', 'invoice']];
$records = Odx::searchRead('account.move', $domain);
```

#### searchCount
Get the number of records matching a domain.
```php
$count = Odx::searchCount('sale.order', [['state', '=', 'sale']]);
```

#### read
Read specific fields from specific IDs.
```php
$ids = [10, 11, 12];
$records = Odx::read('product.product', $ids);
```

### 2. Writing Data
#### create
Creates a new record and returns the created ID (or record data depending on context).
```php
$newId = Odx::create('res.partner', [
    'name' => 'John Doe',
    'email' => 'john@example.com'
]);
```

#### write
Updates existing records.
```php
$success = Odx::write('res.partner', [55], [
    'phone' => '+15550001111'
]);
```
#### unlink (Delete)
Deletes records.
```php
$success = Odx::unlink('res.partner', [55]);
```
4. Calling Methods (RPC)
Call any public method on an Odoo model.
```php 
// Example: Confirm a Sale Order
// Model: sale.order, Method: action_confirm, IDs: [100]
$result = Odx::call('sale.order', 'action_confirm', [[100]]);
```

## v2 API — Odoo 19+ (JSON-2)

ODXProxy **0.9.0+** has `/v2` endpoints that reach Odoo over its **JSON-2** API instead of `/jsonrpc`. A v2 client comes from the same configuration as v1. Use `Odx::v2()` for the global instance, or `Odx::with($config)->v2()` for one tenant:

```php
use OdxProxy\Odx;

Odx::init([
    'gateway_url'     => 'https://gateway.odxproxy.io',
    'gateway_api_key' => 'PROXY_X_API_KEY',
    'url'             => 'https://erp.example.com',
    'db'              => 'prod',
    'api_key'         => 'ODOO_USER_API_KEY',      // an Odoo API key, not a password
    'context'         => ['lang' => 'en_US', 'tz' => 'Asia/Jakarta', 'allowed_company_ids' => [1]], // optional, v2 only
    // 'user_id' is only needed for the v1 methods
]);

$v2 = Odx::v2();

$partners = $v2->searchRead('res.partner', [['is_company', '=', true]],
    fields: ['name', 'email'], limit: 20, order: 'name asc');
$ids   = $v2->search('res.partner', [['customer_rank', '>', 0]], limit: 10);   // int[]
$count = $v2->searchCount('res.partner', []);                                // int
$rows  = $v2->read('res.partner', $ids, fields: ['name']);                    // array
$schema = $v2->fieldsGet('res.partner', attributes: ['type', 'string']);

$newIds = $v2->create('res.partner', [['name' => 'Acme'], ['name' => 'Globex']]);  // [41, 42]
$one    = $v2->createOne('res.partner', ['name' => 'Initech']);                  // 43
$v2->write('res.partner', $newIds, ['comment' => 'via v2']);                     // true
$v2->unlink('res.partner', $newIds);                                             // true

$v2->call('account.move', 'action_post', ids: [7]);
$v2->call('res.partner', 'name_search', ['name' => 'Acm', 'limit' => 5]);
```

**When to use which:**

| Odoo version | v1 methods (`Odx::searchRead`, ...) | v2 (`Odx::v2()->...`) |
|---|---|---|
| 18 or older | ✅ only option | ❌ (`isJson2Unavailable()`) |
| 19 – 21 | ✅ | ✅ |
| 22 and newer | ❌ (`/jsonrpc` removed) | ✅ only option |

`$v2->isSupported()` checks once and caches the answer per URL. `$v2->version()` returns `['version_info' => [...], 'version' => '...']`.

**How v2 differs from v1:**

- **Named arguments only.** There are no positional `$args` and no `KeywordRequest`. Each argument is sent to Odoo under its Python parameter name: `domain`, `fields`, `offset`, `limit`, `order`, `ids`, `vals`, `allfields`, `attributes`. In `call()`, `ids:` is for record methods only, and every other argument goes in the `$kwargs` array under its Python name. Odoo rejects unknown names, and `ids` sent to `@api.model` methods, with `odooStatus() === 422`.
- **The domain is the list itself:** `[['is_company', '=', true]]`. v1's extra wrapping array is gone.
- **`null` arguments are omitted**, so Odoo's own defaults apply.
- **`create()` always returns a list of ids**, even for one record. Use `createOne()` for an `int`.
- **No `user_id`.** Odoo derives the user from the API key. On Odoo 20+ the key's scope must be `rpc` (the default), and keys of non-admin users expire.
- **Context:** the config's `context` is merged into every v2 call, and a call's `context:` keys win. Odoo applies no company selection unless `allowed_company_ids` is sent.
- **Multi-database hosts:** the database is selected by header and filtered by the server's `dbfilter`. If the host picks the database from its hostname, `url` must be that database's own hostname. Otherwise calls fail with `isJson2Unavailable()`.
- **Binary fields on Odoo 20+** read as `['content' => ..., 'filename' => ..., 'size' => ...]` instead of a base64 string. This is an Odoo 20 change and applies to v1 too.

## Advanced Usage
##### Pagination & Filtering (KeywordRequest)
Use the KeywordRequest helper to handle offset, limit, order, and fields (projection). This allows you to construct queries fluently.
```php
use OdxProxy\Odx;
use OdxProxy\Model\KeywordRequest;

// Create options
$options = (new KeywordRequest())
    ->setLimit(5)
    ->setOffset(0)
    ->setOrder('name asc')
    ->setFields(['id', 'name', 'email']) // Only fetch these fields
    ->setContext([
        'lang' => 'en_US',
        'tz' => 'Asia/Jakarta',
        'allowed_company_ids' => [1],
        'default_company_id' => 1
    ]); 

// Pass options as the last argument
$users = Odx::searchRead('res.users', [], $options);
```

Multi-User / SaaS Support (Odx::with)
In Android, the device usually belongs to one user. In PHP, your server might handle requests for hundreds of different Odoo instances (Multi-tenancy).
Do not use Odx::init() inside a loop, as it changes the global state. Instead, use Odx::with() to create a temporary, disposable client.
```php 
$tenants = [
    ['uid' => 1, 'db' => 'client_a', 'key' => '...'],
    ['uid' => 5, 'db' => 'client_b', 'key' => '...'],
];

foreach ($tenants as $tenant) {
    // Create a temporary client configuration
    $config = [
        'gateway_url'     => 'https://gateway.odxproxy.io',
        'gateway_api_key' => 'MASTER_KEY',
        'url'             => 'https://saas.odoo.com',
        'user_id'         => $tenant['uid'],
        'db'              => $tenant['db'],
        'api_key'         => $tenant['key']
    ];

    // Execute immediately without affecting global state
    $count = Odx::with($config)->searchCount('sale.order', []);
    
    echo "Client {$tenant['db']} has {$count} orders.\n";
}
```

### Error Handling
The library throws OdxProxy\Exception\OdxException for both Network errors (Curl) and Odoo Server errors (XML-RPC/JSON-RPC faults).
```php
use OdxProxy\Exception\OdxException;

try {
    Odx::create('res.partner', ['invalid_field' => 'value']);
} catch (OdxException $e) {
    // The HTTP Status Code (or 200 if Odoo returned a logic error)
    $code = $e->getCode(); 
    
    // The human readable message
    $msg  = $e->getMessage();
    
    // Detailed debug data (if available from Odoo)
    $data = $e->data; 
}
```

`OdxException` also has `httpStatus` (the HTTP status, or `null` when no response arrived) and these helpers:

| Helper | Meaning |
|---|---|
| `odooStatus()` | For an Odoo-side error, Odoo's HTTP status, which the proxy forwards as the code (always on v2; on v1 only when Odoo itself answered non-2xx). `401` Odoo API key invalid/expired, which is not the proxy's key. `403` access rights or private method, `404` unknown model/method or missing record, `409` lock conflict, `422` validation error or bad arguments, `5xx` server error. |
| `odooErrorName()` | Odoo's exception class from `data['name']`, e.g. `odoo.exceptions.ValidationError`. |
| `rpcCode()` | The JSON-RPC error code. For non-2xx v1 responses, `statusCode` holds the HTTP status and the envelope's code is in `data`; this reads it either way. |
| `isLicenseError()` | Proxy license invalid: code `0` **on HTTP 403**. |
| `isTransportError()` | No response from the proxy at all (curl failure). |
| `isJson2Unavailable()` | **v2:** `-32006`, meaning no JSON-2 on that Odoo (≤18, use v1), or the database isn't selectable on that host (`dbfilter`). |
| `isInvalidRequest()` | **v2:** `-32007`, meaning an invalid model/method name, or a db/api_key that isn't valid as an HTTP header. Odoo was not contacted. |
| `isRetryable()` | The proxy couldn't reach Odoo (`-32004`), or an Odoo lock conflict (`409`). |

> **Code `0` is ambiguous on its own.** A curl failure is code `0`, and Odoo 19+ also sends code `0` for every v1 `/jsonrpc` error. Use `isTransportError()` and `isLicenseError()` instead of comparing `getCode()` with `0`.

On v2, `statusCode` is always the JSON-RPC code, e.g. `-32000` for a bad proxy key, with the HTTP status in `httpStatus`. The v1 methods keep their existing behaviour, where a non-2xx response puts the HTTP status in `statusCode`.

## Helper Classes Reference
> OdxProxy\Model\KeywordRequest 

|Method |	Description|
|-------|-------------|
|setLimit(int) |	Max records to return|
|setOffset(int) |	Number of records to skip|
|setOrder(string) |	Sort order (e.g., 'id desc')
|setFields(array)|	List of fields to return (SQL SELECT)
|resetPagination()|	Returns a copy of the request with |
|limit/offset | cleared|
> OdxProxy\Utils\IdHelper

|Method	| Description |
|-------|-------------|
|generate() | Generates a 13-byte random ID (similar to ULID) for request tracking|
|normalizeId($val)	| Robustly converts Odoo responses (which can be [id, name] or false) into a safe ID string or null.|
License MIT