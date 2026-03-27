<?php
/**
 * A4 - Контролировать статусы
 * Обновление статуса заказа курьером
 */
header('Content-Type: application/json');
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$orderId = $data['order_id'] ?? 0;
$newStatusId = $data['status_id'] ?? 0;
$courierId = $data['courier_id'] ?? null;

try {
    $db = new Database();
    $conn = $db->connect();
    $conn->beginTransaction();
    
    // Обновление статуса заказа (A3 -> A4)
    $stmt = $conn->prepare("
        UPDATE orders 
        SET status_id = ?, updated_at = NOW()
        WHERE order_id = ?
    ");
    $stmt->execute([$newStatusId, $orderId]);
    
    // Запись в историю (A4)
    $stmt = $conn->prepare("
        INSERT INTO order_status_history (order_id, old_status_id, new_status_id, changed_by, changed_at, comment)
        SELECT ?, status_id, ?, ?, NOW(), 'Обновлено курьером'
        FROM orders WHERE order_id = ?
    ");
    $stmt->execute([$orderId, $newStatusId, $courierId, $orderId]);
    
    // Если заказ доставлен - обновление статистики курьера
    if ($newStatusId == 7) { // delivered
        $stmt = $conn->prepare("
            UPDATE user_discounts 
            SET total_orders = total_orders + 1
            WHERE user_id = (SELECT user_id FROM orders WHERE order_id = ?)
        ");
        $stmt->execute([$orderId]);
    }
    
    $conn->commit();
    
    // Отправка уведомления клиенту (Telegram/SMS)
    sendNotification($orderId, $newStatusId);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function sendNotification($orderId, $statusId) {
    // Здесь можно добавить отправку уведомлений
    // Для простоты - логирование
    error_log("Notification: Order {$orderId} status changed to {$statusId}");
}
?>