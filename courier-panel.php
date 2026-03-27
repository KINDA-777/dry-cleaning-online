<?php
/**
 * A3 - Выполнить доставку и обработку вещей
 * Интерфейс для курьеров
 */
session_start();
require_once 'config/database.php';

// Проверка авторизации курьера
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'courier') {
    header('Location: login.php');
    exit;
}

$db = new Database();
$conn = $db->connect();
$courierId = $_SESSION['courier_id'];

// Получение маршрута на сегодня (A3)
$stmt = $conn->prepare("
    SELECT r.*, rz.zone_name
    FROM routes r
    LEFT JOIN delivery_zones rz ON r.zone_id = rz.zone_id
    WHERE r.courier_id = ? AND r.route_date = CURDATE()
    ORDER BY r.created_at DESC
    LIMIT 1
");
$stmt->execute([$courierId]);
$currentRoute = $stmt->fetch();

// Заказы в маршруте
$orders = [];
if ($currentRoute) {
    $stmt = $conn->prepare("
        SELECT o.*, os.status_name, os.status_code,
               a.street, a.building, a.apartment, a.phone as client_phone,
               COALESCE(u.first_name, o.guest_name) as client_name
        FROM orders o
        LEFT JOIN addresses a ON o.delivery_address_id = a.address_id
        LEFT JOIN users u ON o.user_id = u.user_id
        LEFT JOIN order_statuses os ON o.status_id = os.status_id
        WHERE o.route_id = ? AND o.courier_id = ?
        ORDER BY o.delivery_time_from
    ");
    $stmt->execute([$currentRoute['route_id'], $courierId]);
    $orders = $stmt->fetchAll();
}

// Статистика курьера
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_orders,
        SUM(final_amount) as total_revenue,
        AVG(TIMESTAMPDIFF(MINUTE, order_date, updated_at)) as avg_delivery_time
    FROM orders
    WHERE courier_id = ? AND status_id = 8
");
$stmt->execute([$courierId]);
$stats = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель курьера - Чистота</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        .courier-layout {
            display: grid;
            grid-template-columns: 350px 1fr;
            min-height: 100vh;
        }
        
        .courier-sidebar {
            background: white;
            border-right: 1px solid var(--border-color);
            padding: 20px;
            overflow-y: auto;
        }
        
        .courier-main {
            background: var(--light-gray);
            padding: 20px;
        }
        
        .courier-header {
            text-align: center;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 20px;
        }
        
        .courier-name {
            font-size: 1.3em;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .courier-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .stat-box {
            background: var(--light-gray);
            padding: 15px;
            border-radius: 5px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 1.5em;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .stat-label {
            font-size: 0.85em;
            color: var(--text-light);
        }
        
        .order-card {
            background: white;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .order-card:hover {
            border-color: var(--primary-color);
            box-shadow: var(--shadow);
        }
        
        .order-card.active {
            border-color: var(--secondary-color);
            background: #e3f2fd;
        }
        
        .order-card.completed {
            opacity: 0.6;
            border-color: var(--success-color);
        }
        
        .order-card-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        
        .order-number {
            font-weight: bold;
        }
        
        .order-address {
            color: var(--text-light);
            font-size: 0.9em;
            margin-bottom: 10px;
        }
        
        .order-client {
            font-size: 0.9em;
            margin-bottom: 5px;
        }
        
        .order-time {
            font-size: 0.85em;
            color: var(--secondary-color);
            font-weight: 600;
        }
        
        .btn-action {
            width: 100%;
            margin-top: 10px;
            padding: 10px;
        }
        
        #routeMap {
            height: calc(100vh - 40px);
            border-radius: 10px;
            box-shadow: var(--shadow);
        }
        
        .status-badge {
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.8em;
        }
        
        @media (max-width: 768px) {
            .courier-layout {
                grid-template-columns: 1fr;
            }
            
            .courier-sidebar {
                border-right: none;
                border-bottom: 2px solid var(--border-color);
                max-height: 400px;
            }
        }
    </style>
</head>
<body>
    <div class="courier-layout">
        <aside class="courier-sidebar">
            <div class="courier-header">
                <div class="courier-name"> <?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                <div style="color: var(--text-light);">Курьер</div>
            </div>
            
            <div class="courier-stats">
                <div class="stat-box">
                    <div class="stat-value"><?php echo $stats['total_orders'] ?? 0; ?></div>
                    <div class="stat-label">Заказов</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?php echo number_format($stats['total_revenue'] ?? 0, 0, '.', ' '); ?> ₽</div>
                    <div class="stat-label">Выручка</div>
                </div>
            </div>
            
            <h3 style="margin-bottom: 15px;">Маршрут на сегодня</h3>
            
            <?php if (empty($orders)): ?>
            <div style="text-align: center; color: var(--text-light); padding: 20px;">
                Нет активных заказов
            </div>
            <?php else: ?>
            <?php foreach ($orders as $index => $order): ?>
            <div class="order-card <?php echo $order['status_code'] == 'delivered' ? 'completed' : ''; ?>" 
                 onclick="selectOrder(<?php echo $order['order_id']; ?>)">
                <div class="order-card-header">
                    <span class="order-number"><?php echo htmlspecialchars($order['order_number']); ?></span>
                    <span class="status-badge status-<?php echo $order['status_code']; ?>">
                        <?php echo htmlspecialchars($order['status_name']); ?>
                    </span>
                </div>
                <div class="order-address">
                     <?php echo htmlspecialchars($order['street'] . ', ' . $order['building']); ?>
                    <?php if ($order['apartment']) echo ', ' . $order['apartment']; ?>
                </div>
                <div class="order-client">
                     <?php echo htmlspecialchars($order['client_name']); ?>
                </div>
                <div class="order-client">
                     <?php echo htmlspecialchars($order['client_phone']); ?>
                </div>
                <div class="order-time">
                     <?php echo $order['delivery_time_from']; ?> - <?php echo $order['delivery_time_to']; ?>
                </div>
                
                <?php if ($order['status_code'] != 'delivered'): ?>
                <button class="btn-primary btn-action" onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 7)">
                    ✓ Доставлен
                </button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
            
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border-color);">
                <a href="logout.php" class="btn-primary btn-full">Выйти</a>
            </div>
        </aside>
        
        <main class="courier-main">
            <div id="routeMap"></div>
        </main>
    </div>
    
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Инициализация карты
        const map = L.map('routeMap').setView([55.7558, 37.6173], 12);
        
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);
        
        // Заказы из PHP
        const orders = <?php echo json_encode($orders); ?>;
        const markers = [];
        
        // Добавление маркеров заказов
        orders.forEach((order, index) => {
            if (order.latitude && order.longitude) {
                const marker = L.marker([order.latitude, order.longitude])
                    .addTo(map)
                    .bindPopup(`
                        <b>${order.order_number}</b><br>
                        ${order.street}, ${order.building}<br>
                        ${order.client_name}<br>
                        ${order.delivery_time_from} - ${order.delivery_time_to}
                    `);
                markers.push(marker);
            }
        });
        
        // Построение маршрута
        if (markers.length > 0) {
            const points = markers.map(m => m.getLatLng());
            L.polyline(points, {
                color: '#4CAF50',
                weight: 4,
                opacity: 0.7
            }).addTo(map);
            
            // Центрирование на маршруте
            map.fitBounds(points);
        }
        
        function selectOrder(orderId) {
            // Подсветка выбранного заказа
            document.querySelectorAll('.order-card').forEach(card => {
                card.classList.remove('active');
            });
            event.currentTarget.classList.add('active');
        }
        
        function updateOrderStatus(orderId, statusId) {
            if (confirm('Подтвердить доставку заказа?')) {
                fetch('api/update-order-status.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        order_id: orderId,
                        status_id: statusId,
                        courier_id: <?php echo $courierId; ?>
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Ошибка: ' + data.message);
                    }
                });
            }
        }
        
        // Геолокация курьера
        if (navigator.geolocation) {
            navigator.geolocation.watchPosition(position => {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                
                // Обновление позиции курьера в БД
                fetch('api/update-courier-location.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        courier_id: <?php echo $courierId; ?>,
                        latitude: lat,
                        longitude: lng
                    })
                });
            }, null, {
                enableHighAccuracy: true,
                maximumAge: 10000
            });
        }
    </script>
</body>
</html>