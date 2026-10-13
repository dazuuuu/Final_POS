<?php
// public/api/services/list.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../../../app/app.php'; $pdo = Database::pdo();

if (!class_exists('ServiceModel', false)) {
    require_once __DIR__ . '/../../../app/models/ServiceModel.php';
}

$serviceModel = new ServiceModel($pdo);
$status = $_GET['status'] ?? 'published';
$limit = $_GET['limit'] ?? 10;
$offset = $_GET['offset'] ?? 0;

$services = $serviceModel->getAllServices($status, $limit, $offset);
echo json_encode(['success' => true, 'data' => $services]);
?>