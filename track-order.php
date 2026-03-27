<?php
require_once 'config/database.php';

$orderNumber = $_GET['order'] ?? '';
$order = null;
$statusHistory = [];
$db = new Database();
$conn = $db->connect();

if ($conn && !empty($orderNumber)) {
    // Получение заказа (A4 - Контроль статусов)
    $stmt = $conn->prepare("
        SELECT o.*, os.status_name, os.status_code,
               COALESCE(u.first_name, o.guest_name) as client_name,
               a.street, a.building, a.apartment
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        LEFT JOIN order_statuses os ON o.status_id = os.status_id
        LEFT JOIN addresses a ON o.delivery_address_id = a.address_id
        WHERE o.order_number = ?
    ");
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch();
    
    if ($order) {
        // История изменений статуса (A4)
        $stmt = $conn->prepare("
            SELECT osh.*, os.status_name
            FROM order_status_history osh
            LEFT JOIN order_statuses os ON osh.new_status_id = os.status_id
            WHERE osh.order_id = ?
            ORDER BY osh.changed_at DESC
        ");
        $stmt->execute([$order['order_id']]);
        $statusHistory = $stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отслеживание заказа <?php echo htmlspecialchars($orderNumber); ?> - Чистота</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        .track-page {
            padding: 40px 0;
            min-height: 100vh;
            background: var(--light-gray);
        }
        
        .track-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .track-header {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            text-align: center;
        }
        
        .track-header h1 {
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .order-number-display {
            font-size: 1.5em;
            font-weight: bold;
            color: var(--text-color);
        }
        
        .status-timeline {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }
        
        .timeline {
            position: relative;
            padding: 20px 0;
        }
        
        .timeline-item {
            display: flex;
            margin-bottom: 30px;
            position: relative;
        }
        
        .timeline-item:not(:last-child)::after {
            content: '';
            position: absolute;
            left: 25px;
            top: 50px;
            width: 2px;
            height: calc(100% - 20px);
            background: var(--border-color);
        }
        
        .timeline-item.completed:not(:last-child)::after {
            background: var(--primary-color);
        }
        
        .timeline-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--light-gray);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 20px;
            position: relative;
            z-index: 1;
            flex-shrink: 0;
        }
        
        .timeline-item.completed .timeline-icon {
            background: var(--primary-color);
            color: white;
        }
        
        .timeline-item.current .timeline-icon {
            background: var(--secondary-color);
            color: white;
            animation: pulse 2s infinite;
        }
        
        .timeline-content {
            flex: 1;
        }
        
        .timeline-title {
            font-weight: bold;
            font-size: 1.1em;
            margin-bottom: 5px;
        }
        
        .timeline-date {
            color: var(--text-light);
            font-size: 0.9em;
        }
        
        .order-details {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }
        
        .detail-row {
            display: flex;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            font-weight: 600;
            width: 200px;
            color: var(--text-light);
        }
        
        .detail-value {
            flex: 1;
            color: var(--text-color);
        }
        
        .map-container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: var(--shadow);
        }
        
        #courierMap {
            height: 400px;
            border-radius: 10px;
            margin-top: 20px;
        }
        
        .search-form {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }
        
        .search-form input {
            width: 100%;
            padding: 15px;
            border: 2px solid var(--border-color);
            border-radius: 5px;
            font-size: 1.1em;
            margin-bottom: 15px;
        }
        
        .search-form input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(33, 150, 243, 0.7); }
            50% { box-shadow: 0 0 0 10px rgba(33, 150, 243, 0); }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="track-page">
        <div class="track-container">
            <?php if (empty($orderNumber)): ?>
            <!-- Форма поиска -->
            <div class="search-form">
                <h2 style="margin-bottom: 20px; text-align: center;">Отследить заказ</h2>
                <form method="GET" action="">
                    <input type="text" name="order" placeholder="Введите номер заказа (например: DC-2026-0001)" 
                           required value="<?php echo htmlspecialchars($_GET['order'] ?? ''); ?>">
                    <button type="submit" class="btn-primary btn-full">Найти заказ</button>
                </form>
            </div>
            <?php elseif (!$order): ?>
            <div class="error-message">
                Заказ №<?php echo htmlspecialchars($orderNumber); ?> не найден
            </div>
            <div class="search-form">
                <form method="GET" action="">
                    <input type="text" name="order" placeholder="Введите другой номер заказа" required>
                    <button type="submit" class="btn-primary btn-full">Поиск</button>
                </form>
            </div>
            <?php else: ?>
            <!-- Информация о заказе -->
            <div class="track-header">
                <h1>Заказ №<?php echo htmlspecialchars($order['order_number']); ?></h1>
                <div class="order-number-display">
                    <span class="status-badge status-<?php echo $order['status_code']; ?>">
                        <?php echo htmlspecialchars($order['status_name']); ?>
                    </span>
                </div>
            </div>
            
            <!-- Timeline статусов -->
            <div class="status-timeline">
                <h3 style="margin-bottom: 20px;">История выполнения</h3>
                <div class="timeline">
                    <?php
                    $statuses = [
                        ['code' => 'new', 'name' => 'Новый заказ', 'icon' => ''],
                        ['code' => 'confirmed', 'name' => 'Подтвержден', 'icon' => '✓'],
                        ['code' => 'courier_assigned', 'name' => 'Курьер назначен', 'icon' => ''],
                        ['code' => 'pickup', 'name' => 'Забор вещей', 'icon' => ''],
                        ['code' => 'in_cleaning', 'name' => 'В химчистке', 'icon' => '🧼'],
                        ['code' => 'ready', 'name' => 'Готов к выдаче', 'icon' => ''],
                        ['code' => 'delivered', 'name' => 'Доставлен', 'icon' => ''],
                        ['code' => 'completed', 'name' => 'Завершен', 'icon' => '']
                    ];
                    
                    $currentStatusCode = $order['status_code'];
                    $statusCodeOrder = array_column($statuses, 'code');
                    $currentIndex = array_search($currentStatusCode, $statusCodeOrder);
                    
                    foreach ($statuses as $index => $status): 
                        $isCompleted = $index <= $currentIndex;
                        $isCurrent = $index == $currentIndex;
                    ?>
                    <div class="timeline-item <?php echo $isCompleted ? 'completed' : ''; ?> <?php echo $isCurrent ? 'current' : ''; ?>">
                        <div class="timeline-icon">
                            <?php echo $status['icon']; ?>
                        </div>
                        <div class="timeline-content">
                            <div class="timeline-title"><?php echo $status['name']; ?></div>
                            <?php if ($isCompleted): ?>
                            <div class="timeline-date">
                                <?php
                                $stmt = $conn->prepare("
                                    SELECT changed_at FROM order_status_history 
                                    WHERE order_id = ? AND new_status_id = (
                                        SELECT status_id FROM order_statuses WHERE status_code = ?
                                    )
                                    ORDER BY changed_at DESC LIMIT 1
                                ");
                                $stmt->execute([$order['order_id'], $status['code']]);
                                $date = $stmt->fetchColumn();
                                echo $date ? date('d.m.Y H:i', strtotime($date)) : '';
                                ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Детали заказа -->
            <div class="order-details">
                <h3 style="margin-bottom: 20px;">Детали заказа</h3>
                <div class="detail-row">
                    <div class="detail-label">Клиент:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($order['client_name']); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Телефон:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($order['guest_phone']); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Адрес:</div>
                    <div class="detail-value">
                        <?php echo htmlspecialchars($order['street'] . ', ' . $order['building']); ?>
                        <?php if ($order['apartment']) echo ', кв. ' . $order['apartment']; ?>
                    </div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Дата заказа:</div>
                    <div class="detail-value"><?php echo date('d.m.Y H:i', strtotime($order['order_date'])); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Сумма:</div>
                    <div class="detail-value" style="color: var(--primary-color); font-weight: bold;">
                        <?php echo $order['final_amount']; ?> ₽
                    </div>
                </div>
            </div>
            
            <!-- Карта (если курьер назначен) -->
            <?php if ($order['courier_id']): ?>
            <div class="map-container">
                <h3 style="margin-bottom: 20px;">Местоположение курьера</h3>
                <div id="courierMap"></div>
            </div>
            <?php endif; ?>
            
            <div style="text-align: center; margin-top: 30px;">
                <a href="index.php" class="btn-primary">← На главную</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <?php if ($order && $order['courier_id']): ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Инициализация карты
        const map = L.map('courierMap').setView([55.7558, 37.6173], 13);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);
        
        // Позиция клиента
        const clientPos = [<?php echo $order['latitude'] ?? 55.7558; ?>, <?php echo $order['longitude'] ?? 37.6173; ?>];
        L.marker(clientPos).addTo(map)
            .bindPopup('📍 Ваш адрес')
            .openPopup();
        
        // Позиция курьера (симуляция)
        const courierPos = [55.76, 37.62];
        L.marker(courierPos).addTo(map)
            .bindPopup('🚗 Курьер');
        
        // Маршрут
        L.polyline([courierPos, clientPos], {
            color: '#4CAF50',
            weight: 3,
            opacity: 0.7,
            dashArray: '10, 10'
        }).addTo(map);
    </script>
    <?php endif; ?>
</body>
</html>