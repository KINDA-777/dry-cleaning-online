<?php
session_start();
require_once 'config/database.php';

// Проверка прав администратора
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$db = new Database();
$conn = $db->connect();

// Статистика (A4 - Контроль и отчеты)
$stats = [
    'new_orders' => 0,
    'in_progress' => 0,
    'couriers_active' => 0,
    'revenue_today' => 0
];

if ($conn) {
    // Новые заказы
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status_id = 1");
    $stats['new_orders'] = $stmt->fetchColumn();
    
    // В работе
    $stmt = $conn->query("SELECT COUNT(*) FROM orders WHERE status_id IN (2,3,4,5)");
    $stats['in_progress'] = $stmt->fetchColumn();
    
    // Активные курьеры
    $stmt = $conn->query("SELECT COUNT(*) FROM couriers WHERE is_available = 1");
    $stats['couriers_active'] = $stmt->fetchColumn();
    
    // Выручка сегодня
    $stmt = $conn->query("SELECT COALESCE(SUM(final_amount), 0) FROM orders WHERE DATE(order_date) = CURDATE()");
    $stats['revenue_today'] = $stmt->fetchColumn();
    
    // Последние заказы (CRM)
    $stmt = $conn->query("
        SELECT o.*, 
               COALESCE(u.first_name, o.guest_name) as client_name,
               COALESCE(u.phone, o.guest_phone) as client_phone,
               os.status_name, os.status_code
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        LEFT JOIN order_statuses os ON o.status_id = os.status_id
        ORDER BY o.order_date DESC
        LIMIT 50
    ");
    $orders = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель - Чистота</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .admin-layout {
            display: grid;
            grid-template-columns: 250px 1fr;
            min-height: 100vh;
        }
        
        .admin-sidebar {
            background: #2c3e50;
            color: white;
            padding: 20px;
        }
        
        .admin-sidebar h2 {
            margin-bottom: 30px;
            color: var(--primary-color);
        }
        
        .admin-menu {
            list-style: none;
        }
        
        .admin-menu li {
            margin-bottom: 10px;
        }
        
        .admin-menu a {
            display: block;
            padding: 12px 15px;
            color: #bdc3c7;
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.3s;
        }
        
        .admin-menu a:hover,
        .admin-menu a.active {
            background: var(--primary-color);
            color: white;
        }
        
        .admin-main {
            background: var(--light-gray);
            padding: 30px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: var(--shadow);
        }
        
        .stat-value {
            font-size: 2em;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
        }
        
        .orders-table {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: var(--shadow);
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        th {
            background: var(--light-gray);
            font-weight: 600;
        }
        
        tr:hover {
            background: #f9f9f9;
        }
        
        .btn-small {
            padding: 5px 15px;
            font-size: 0.9em;
            margin-right: 5px;
        }
        
        .btn-edit {
            background: #2196F3;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .btn-status {
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            padding: 5px 10px;
        }
        
        .filter-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-bar input,
        .filter-bar select {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <aside class="admin-sidebar">
            <h2>🧼 Чистота Admin</h2>
            <ul class="admin-menu">
                <li><a href="#dashboard" class="active"> Дашборд</a></li>
                <li><a href="#orders"> Заказы</a></li>
                <li><a href="#couriers"> Курьеры</a></li>
                <li><a href="#routes"> Маршруты</a></li>
                <li><a href="#reports"> Отчеты</a></li>
                <li><a href="#clients"> Клиенты</a></li>
                <li><a href="#settings"> Настройки</a></li>
            </ul>
        </aside>

        <main class="admin-main">
            <h1 style="margin-bottom: 30px;">Панель управления</h1>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['new_orders']; ?></div>
                    <div class="stat-label">Новых заказов</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['in_progress']; ?></div>
                    <div class="stat-label">В работе</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['couriers_active']; ?></div>
                    <div class="stat-label">Курьеров на линии</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($stats['revenue_today'], 0, '.', ' '); ?> ₽</div>
                    <div class="stat-label">Выручка сегодня</div>
                </div>
            </div>

            <div class="orders-table">
                <h2 style="margin-bottom: 20px;">Новые заказы (CRM)</h2>
                
                <div class="filter-bar">
                    <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap;">
                        <input type="text" name="search" placeholder="Поиск по номеру заказа..." value="<?php echo $_GET['search'] ?? ''; ?>">
                        <select name="status">
                            <option value="">Все статусы</option>
                            <option value="1" <?php echo ($_GET['status'] ?? '') == '1' ? 'selected' : ''; ?>>Новый</option>
                            <option value="2" <?php echo ($_GET['status'] ?? '') == '2' ? 'selected' : ''; ?>>Подтвержден</option>
                            <option value="3" <?php echo ($_GET['status'] ?? '') == '3' ? 'selected' : ''; ?>>В работе</option>
                            <option value="6" <?php echo ($_GET['status'] ?? '') == '6' ? 'selected' : ''; ?>>Готов</option>
                            <option value="8" <?php echo ($_GET['status'] ?? '') == '8' ? 'selected' : ''; ?>>Завершен</option>
                        </select>
                        <input type="date" name="date_from" value="<?php echo $_GET['date_from'] ?? ''; ?>">
                        <button type="submit" class="btn-primary btn-small">Применить</button>
                    </form>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>№ заказа</th>
                            <th>Клиент</th>
                            <th>Телефон</th>
                            <th>Сумма</th>
                            <th>Дата</th>
                            <th>Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                            <td><?php echo htmlspecialchars($order['client_name']); ?></td>
                            <td><?php echo htmlspecialchars($order['client_phone']); ?></td>
                            <td><?php echo $order['final_amount']; ?> ₽</td>
                            <td><?php echo date('d.m.Y H:i', strtotime($order['order_date'])); ?></td>
                            <td>
                                <span style="color: <?php 
                                    echo $order['status_code'] == 'new' ? '#f39c12' : 
                                        ($order['status_code'] == 'in_cleaning' ? '#3498db' : '#27ae60'); 
                                ?>">
                                    <?php echo htmlspecialchars($order['status_name']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($order['status_code'] == 'new'): ?>
                                <button class="btn-status" onclick="changeStatus(<?php echo $order['order_id']; ?>, 2)">Подтвердить</button>
                                <?php endif; ?>
                                <button class="btn-edit" onclick="viewOrder(<?php echo $order['order_id']; ?>)">Подробнее</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <script>
        function changeStatus(orderId, newStatus) {
            if (confirm('Изменить статус заказа?')) {
                fetch('api/change-status.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({order_id: orderId, status_id: newStatus})
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
        
        function viewOrder(orderId) {
            window.location.href = 'order-detail.php?id=' + orderId;
        }
    </script>
</body>
</html>