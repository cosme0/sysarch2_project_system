<?php
require_once __DIR__ . '/auth.php';;
require_once __DIR__ . '/database.php';;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (!isset($_SESSION['email'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized']);
    exit;
}
checkSessionTimeout();

$stock = [];
$stmt = $conn->prepare("SELECT sku, stock FROM medications");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $stock[$row['sku']] = max(0, (int)$row['stock']);
}
$stmt->close();

echo json_encode(['ok' => true, 'ts' => time(), 'stock' => $stock]);