<?php
// public/api/print/network.php — send ESC/POS bytes to a Wi-Fi/LAN receipt
// printer (raw port 9100). Only works when this server can reach the printer,
// i.e. the system runs on a computer in the shop. Restricted to private
// network addresses and printer ports so it can't be used to reach anything
// else.
require_once __DIR__ . '/../../../app/app.php';
require_once __DIR__ . '/../_lib/respond.php';
require_once ROOT_PATH . '/app/helpers/uploads.php';
PageGuard::auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST only.'], 405);
}
if (Features::isBasic()) {
    json_response(['ok' => false, 'error' => 'Wi-Fi printing is a Pro feature. Your Basic package prints over Bluetooth or USB.'], 403);
}
$body = read_json_body();
if (!csrf_verify($body['csrf_token'] ?? null)) {
    json_response(['ok' => false, 'error' => 'Session expired — reload the page.'], 403);
}

$ip   = trim((string) ($body['ip'] ?? ''));
$port = (int) ($body['port'] ?? 9100);
$data = base64_decode((string) ($body['data'] ?? ''), true);

$isPrivate = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
    && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE);
if (!$isPrivate) {
    json_response(['ok' => false, 'error' => 'Enter the printer\'s local IP address (e.g. 192.168.1.50).'], 422);
}
if ($port < 9100 || $port > 9109) {
    json_response(['ok' => false, 'error' => 'Printer port must be between 9100 and 9109.'], 422);
}
if ($data === false || $data === '' || strlen($data) > 65536) {
    json_response(['ok' => false, 'error' => 'Nothing to print.'], 422);
}

$sock = @fsockopen($ip, $port, $errno, $errstr, 4);
if (!$sock) {
    json_response(['ok' => false, 'error' => "Can't reach the printer at $ip:$port. Check it's on and on the same network as the server."], 502);
}
stream_set_timeout($sock, 5);
$written = fwrite($sock, $data);
fclose($sock);

if ($written !== strlen($data)) {
    json_response(['ok' => false, 'error' => 'The printer stopped responding mid-print.'], 502);
}
json_response(['ok' => true]);
