<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

if ($_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Нет доступа']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$orderId = $data['order_id'] ?? 0;
$newStatus = $data['status_id'] ?? 0;

try {
    $db = new Database();
    $conn = $db->connect();
    
    $conn->beginTransaction();
    
    // Обновление статуса
    $stmt = $conn->prepare("UPDATE orders SET status_id = ?, updated_at = NOW() WHERE order_id = ?");
    $stmt->execute([$newStatus, $orderId]);
    
    // История изменений (A4)
    $stmt = $conn->prepare("
        INSERT INTO order_status_history (order_id, old_status_id, new_status_id, changed_by, changed_at)
        SELECT ?, status_id, ?, ?, NOW() FROM orders WHERE order_id = ?
    ");
    $stmt->execute([$orderId, $newStatus, $_SESSION['user_id'], $orderId]);
    
    $conn->commit();
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>