<?php
// public/api/v1/_api.php — shared by every Website API endpoint (see app/helpers/ShopApi.php).
//   api_start('products')  checks the key (X-Api-Key header, Bearer token or ?key=),
//                          its scope, the website it is called from and the rate
//                          limit; answers CORS pre-flight. Returns [key row, shop id].
//   api_ok($data) / api_fail($code, $message)  JSON responses.
// Every query in the endpoints is filtered by the key's shop (tenant_id).
define('DAZU_API', true);
require_once __DIR__ . '/../../../app/app.php';

function api_send(int $status, array $body): void
{
    while (ob_get_level() > 1) { @ob_end_clean(); }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function api_ok($data, array $meta = []): void
{
    api_send(200, ['ok' => true, 'data' => $data] + ($meta ? ['meta' => $meta] : []));
}

function api_fail(int $status, string $message, string $code = 'error'): void
{
    api_send($status, ['ok' => false, 'error' => ['code' => $code, 'message' => $message]]);
}

/** JSON body (or form fields) of a POST. */
function api_input(): array
{
    $raw = (string) file_get_contents('php://input');
    $j = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($j) ? $j : $_POST;
}

function api_start(string $scope, array $methods = ['GET']): array
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Content-Type, X-Api-Key, Authorization');
    header('Access-Control-Allow-Methods: ' . implode(', ', array_merge($methods, ['OPTIONS'])));
    header('Access-Control-Max-Age: 600');
    if ($method === 'OPTIONS') { // pre-flight: the key is checked on the real request
        if ($origin !== '') header('Access-Control-Allow-Origin: ' . $origin);
        http_response_code(204);
        exit;
    }
    $key = ShopApi::findKey(ShopApi::keyFromRequest());
    if (!$key) {
        if ($origin !== '') header('Access-Control-Allow-Origin: ' . $origin);
        api_fail(401, 'Missing or invalid API key. Send it in the X-Api-Key header.', 'unauthorized');
    }
    if (!ShopApi::originAllowed($key, $origin)) {
        api_fail(403, 'This key is not allowed on ' . $origin . '. Add the website to the key in your POS.', 'origin_not_allowed');
    }
    header('Access-Control-Allow-Origin: ' . ($origin !== '' ? $origin : '*'));
    if (!in_array($method, $methods, true)) api_fail(405, 'Method not allowed. Use ' . implode(' or ', $methods) . '.', 'method_not_allowed');
    if (!in_array($scope, $key['scope_list'], true)) api_fail(403, "This key does not have the \"$scope\" permission.", 'scope_missing');
    if (!ShopApi::hit($key)) {
        header('Retry-After: 60');
        api_fail(429, 'Too many requests — limit is ' . ShopApi::RATE_PER_MINUTE . ' per minute per key.', 'rate_limited');
    }
    return [$key, (int) $key['tenant_id']];
}

/** One active product of this shop with its category names, or null. */
function api_product(int $tid, int $id): ?array
{
    $st = Database::pdo()->prepare(
        "SELECT p.*, c.name AS category_name, s.name AS subcategory_name FROM products p
           LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN subcategories s ON s.id = p.subcategory_id
          WHERE p.id = ? AND p.tenant_id = ? AND p.status = 'active'"
    );
    $st->execute([$id, $tid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Feature switches of the key's account. */
function api_settings(array $key): array
{
    return ShopApi::settings((int) $key['account_id']);
}
