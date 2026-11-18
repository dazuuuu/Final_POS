<?php
// public/api/whatsapp/save.php — a signed-in user saves (or clears) their own WhatsApp number.
require_once __DIR__ . '/../../../app/app.php';
require_once ROOT_PATH . '/app/helpers/uploads.php';
header('Content-Type: application/json; charset=utf-8');
if (!TenantContext::check() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(403); echo json_encode(['ok' => false]); exit; }
if (!csrf_verify($_POST['csrf_token'] ?? null)) { http_response_code(419); echo json_encode(['ok' => false, 'error' => 'Reload the page and try again.']); exit; }
$raw = trim((string) ($_POST['number'] ?? ''));
if (!WhatsApp::saveMine($raw)) { echo json_encode(['ok' => false, 'error' => 'That doesn\'t look like a phone number — e.g. 0712 345 678.']); exit; }
echo json_encode(['ok' => true, 'number' => WhatsApp::mine()]);
