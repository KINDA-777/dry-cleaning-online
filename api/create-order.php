<?php
/**
 * Создание заказа (A1 - Принять и зарегистрировать заказ)
 * Соответствует IDEF0 модели уровня A0 и A1
 */
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не разрешен']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    if (!$conn) {
        throw new Exception('Ошибка подключения к базе данных');
    }
    
    // Получение данных (A1 - Вход)
    $clientName = trim($_POST['client_name'] ?? '');
    $clientPhone = trim($_POST['client_phone'] ?? '');
    $clientEmail = trim($_POST['client_email'] ?? '');
    $city = trim($_POST['city'] ?? 'Москва');
    $street = trim($_POST['street'] ?? '');
    $building = trim($_POST['building'] ?? '');
    $apartment = trim($_POST['apartment'] ?? '');
    $entrance = trim($_POST['entrance'] ?? '');
    $floor = trim($_POST['floor'] ?? '');
    $deliveryDate = $_POST['delivery_date'] ?? null;
    $deliveryTime = $_POST['delivery_time'] ?? null;
    $paymentMethod = $_POST['payment_method'] ?? 'cash';
    $notes = trim($_POST['notes'] ?? '');
    $telegramNotify = isset($_POST['telegram_notify']);
    $smsNotify = isset($_POST['sms_notify']);
    $totalAmount = floatval($_POST['total_amount'] ?? 0);
    $deliveryCost = floatval($_POST['delivery_cost'] ?? 0);
    $orderItems = json_decode($_POST['order_items'] ?? '[]', true);
    $latitude = $_POST['latitude'] ?? null;
    $longitude = $_POST['longitude'] ?? null;
    
    // Валидация (Управление: ТЗ, ГОСТы)
    $errors = [];
    
    if (empty($clientName)) $errors[] = 'Введите имя';
    if (empty($clientPhone)) $errors[] = 'Введите телефон';
    if (empty($street)) $errors[] = 'Введите улицу';
    if (empty($building)) $errors[] = 'Введите дом';
    if (empty($orderItems) || !is_array($orderItems)) $errors[] = 'Выберите услуги';
    if ($totalAmount <= 0) $errors[] = 'Сумма заказа некорректна';
    if (empty($deliveryDate)) $errors[] = 'Выберите дату доставки';
    
    if (!empty($errors)) {
        throw new Exception(implode(', ', $errors));
    }
    
    // Генерация номера заказа
    $orderNumber = 'DC-' . date('Y') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Начало транзакции (A1 - Атомарность)
    $conn->beginTransaction();
    
    try {
        // Определение пользователя (если авторизован)
        $userId = $_SESSION['user_id'] ?? null;
        
        // Создание адреса (A1)
        $stmt = $conn->prepare("
            INSERT INTO addresses (
                city, street, building, apartment, entrance, floor, 
                latitude, longitude, address_type
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'delivery')
        ");
        $stmt->execute([
            $city, $street, $building, $apartment, $entrance, $floor,
            $latitude, $longitude
        ]);
        $addressId = $conn->lastInsertId();
        
        // Расчет итоговой суммы с учетом скидки (A1)
        $discount = 0;
        if ($userId) {
            $stmt = $conn->prepare("SELECT discount_percent FROM user_discounts WHERE user_id = ?");
            $stmt->execute([$userId]);
            $userDiscount = $stmt->fetch();
            if ($userDiscount) {
                $discount = $totalAmount * ($userDiscount['discount_percent'] / 100);
            }
        }
        
        $finalAmount = $totalAmount + $deliveryCost - $discount;
        if ($finalAmount < 0) $finalAmount = 0;
        
        // Создание заказа (A1 - Выход: Подтвержденный заказ)
        $stmt = $conn->prepare("
            INSERT INTO orders (
                order_number, user_id, guest_name, guest_phone, guest_email,
                delivery_address_id, delivery_date, delivery_time_from, delivery_time_to,
                total_amount, delivery_cost, discount_amount, final_amount,
                payment_method, payment_status, notes, status_id, order_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, 1, NOW())
        ");
        
        $timeRange = explode('-', $deliveryTime);
        $stmt->execute([
            $orderNumber, $userId, $clientName, $clientPhone, $clientEmail,
            $addressId, $deliveryDate, 
            $timeRange[0] ?? '09:00', $timeRange[1] ?? '11:00',
            $totalAmount, $deliveryCost, $discount, $finalAmount,
            $paymentMethod, $notes
        ]);
        
        $orderId = $conn->lastInsertId();
        
        // Сохранение позиций заказа (A1)
        $itemStmt = $conn->prepare("
            INSERT INTO order_items (order_id, service_id, quantity, unit_price, total_price)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($orderItems as $item) {
            $itemStmt->execute([
                $orderId,
                $item['service_id'],
                $item['quantity'],
                $item['price'],
                $item['total']
            ]);
        }
        
        // Подписка на уведомления (A4)
        if ($telegramNotify || $smsNotify) {
            $stmt = $conn->prepare("
                INSERT INTO notification_subscriptions (user_id, notification_type, recipient, is_subscribed)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE is_subscribed = 1
            ");
            
            if ($telegramNotify && $clientPhone) {
                $stmt->execute([$userId, 'telegram', $clientPhone]);
            }
            if ($smsNotify && $clientPhone) {
                $stmt->execute([$userId, 'sms', $clientPhone]);
            }
            if ($clientEmail) {
                $stmt->execute([$userId, 'email', $clientEmail]);
            }
        }
        
        // Логирование создания заказа (A4 - Контроль)
        $stmt = $conn->prepare("
            INSERT INTO order_status_history (order_id, new_status_id, changed_at, comment)
            VALUES (?, 1, NOW(), 'Заказ создан через сайт')
        ");
        $stmt->execute([$orderId]);
        
        // Обновление статистики пользователя (A4)
        if ($userId) {
            $stmt = $conn->prepare("
                UPDATE user_discounts 
                SET total_orders = total_orders + 1, total_spent = total_spent + ?
                WHERE user_id = ?
            ");
            $stmt->execute([$finalAmount, $userId]);
        }
        
        $conn->commit();
        
        // Отправка уведомлений (A4 - Выход: Уведомление клиенту)
        // sendOrderConfirmationEmail($clientEmail, $orderNumber, $finalAmount);
        // sendTelegramNotification($clientPhone, "Заказ $orderNumber подтвержден!");
        
        // Успешный ответ (A1 - Выход)
        echo json_encode([
            'success' => true,
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'total_amount' => $finalAmount,
            'message' => 'Заказ успешно оформлен!'
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    error_log('Create order error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка при создании заказа: ' . $e->getMessage()
    ]);
}
?>