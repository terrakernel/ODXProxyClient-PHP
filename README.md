# ODXProxy Client for PHP
![Static Badge](https://img.shields.io/badge/License-MIT-green)
![Static Badge](https://img.shields.io/badge/php-%3E%3D7.4-white?labelColor=%23474A8A&color=%23787CB5)


A high-performance, low-footprint, zero-dependency PHP client for interacting with Odoo instances via the ODXProxy Gateway.
This library is designed to be lightweight (using native curl and json extensions only) while maintaining strict type safety and security.

### Requirements
- PHP >= 7.4
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
searchCount
Get the number of records matching a domain.
code
PHP
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