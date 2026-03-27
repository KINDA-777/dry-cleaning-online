<?php
session_start();
require_once 'config/database.php';

// Проверка авторизации (A4 - Контроль доступов)
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=personal-account.php');
    exit;
}

$db = new Database();
$conn = $db->connect();
$userId = $_SESSION['user_id'];

// Получение данных пользователя
$user = null;
$orders = [];
$discount = 0;
$loyaltyLevel = 'bronze';
$totalOrders = 0;
$totalSpent = 0;

if ($conn) {
    // Данные пользователя
    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    // История заказов (A4 - Контроль статусов)
    $stmt = $conn->prepare("
        SELECT o.*, os.status_name, os.status_code,
               (SELECT COUNT(*) FROM order_items WHERE order_id = o.order_id) as items_count
        FROM orders o
        LEFT JOIN order_statuses os ON o.status_id = os.status_id
        WHERE o.user_id = ?
        ORDER BY o.order_date DESC
        LIMIT 20
    ");
    $stmt->execute([$userId]);
    $orders = $stmt->fetchAll();
    
    // Персональная скидка и лояльность
    $stmt = $conn->prepare("
        SELECT discount_percent, loyalty_level, total_orders, total_spent 
        FROM user_discounts 
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $userDiscount = $stmt->fetch();
    
    if ($userDiscount) {
        $discount = $userDiscount['discount_percent'];
        $loyaltyLevel = $userDiscount['loyalty_level'];
        $totalOrders = $userDiscount['total_orders'];
        $totalSpent = $userDiscount['total_spent'];
    }
    
    // Статистика
    $stats = [
        'active_orders' => 0,
        'completed_orders' => 0,
        'total_spent' => $totalSpent
    ];
    
    $stmt = $conn->prepare("
        SELECT 
            SUM(CASE WHEN status_id NOT IN (7, 8, 9) THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status_id IN (7, 8) THEN 1 ELSE 0 END) as completed
        FROM orders WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $orderStats = $stmt->fetch();
    $stats['active_orders'] = $orderStats['active'] ?? 0;
    $stats['completed_orders'] = $orderStats['completed'] ?? 0;
}

// Цвета для уровней лояльности
$loyaltyColors = [
    'bronze' => '#cd7f32',
    'silver' => '#c0c0c0',
    'gold' => '#ffd700',
    'platinum' => '#e5e4e2'
];
$loyaltyNames = [
    'bronze' => 'Бронзовый',
    'silver' => 'Серебряный',
    'gold' => 'Золотой',
    'platinum' => 'Платиновый'
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет - Чистота</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .account-page {
            min-height: 100vh;
            background: var(--light-gray);
            padding-bottom: 50px;
        }
        
        .account-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 40px 0;
            margin-bottom: 30px;
        }
        
        .account-header h1 {
            margin-bottom: 10px;
        }
        
        .account-container {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 30px;
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .account-sidebar {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: var(--shadow);
            height: fit-content;
            position: sticky;
            top: 100px;
        }
        
        .account-menu {
            list-style: none;
        }
        
        .account-menu li {
            margin-bottom: 5px;
        }
        
        .account-menu a {
            display: block;
            padding: 12px 15px;
            color: var(--text-color);
            text-decoration: none;
            border-radius: 5px;
            transition: all 0.3s;
        }
        
        .account-menu a:hover,
        .account-menu a.active {
            background: var(--primary-color);
            color: white;
        }
        
        .account-menu a i {
            margin-right: 10px;
        }
        
        .account-content {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: var(--shadow);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 10px;
            text-align: center;
        }
        
        .stat-card.green {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        
        .stat-card.orange {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .stat-value {
            font-size: 2.5em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.9em;
            opacity: 0.9;
        }
        
        .loyalty-card {
            background: linear-gradient(135deg, <?php echo $loyaltyColors[$loyaltyLevel]; ?>, #333);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .loyalty-card::before {
            content: '';
            position: absolute;
            top: -20px;
            right: -20px;
            font-size: 100px;
            opacity: 0.1;
        }
        
        .loyalty-level {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .loyalty-percent {
            font-size: 3em;
            font-weight: bold;
            margin: 15px 0;
        }
        
        .loyalty-progress {
            background: rgba(255,255,255,0.3);
            border-radius: 10px;
            height: 10px;
            margin-top: 20px;
            overflow: hidden;
        }
        
        .loyalty-progress-bar {
            background: white;
            height: 100%;
            border-radius: 10px;
            transition: width 0.5s;
        }
        
        .order-card {
            border: 2px solid var(--border-color);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s;
        }
        
        .order-card:hover {
            border-color: var(--primary-color);
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }
        
        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .order-number {
            font-weight: bold;
            font-size: 1.2em;
            color: var(--primary-color);
        }
        
        .order-date {
            color: var(--text-light);
            font-size: 0.9em;
        }
        
        .order-status {
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: 600;
        }
        
        .status-new { background: #fff3cd; color: #856404; }
        .status-confirmed { background: #d1ecf1; color: #0c5460; }
        .status-in_cleaning { background: #d1ecf1; color: #0c5460; }
        .status-ready { background: #d4edda; color: #155724; }
        .status-delivered { background: #c3e6cb; color: #155724; }
        .status-completed { background: #c3e6cb; color: #155724; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        
        .order-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .order-items {
            margin: 15px 0;
        }
        
        .order-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--light-gray);
        }
        
        .order-item:last-child {
            border-bottom: none;
        }
        
        .order-total {
            text-align: right;
            font-weight: bold;
            font-size: 1.3em;
            color: var(--primary-color);
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid var(--border-color);
        }
        
        .order-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        
        .btn-small {
            padding: 8px 20px;
            font-size: 0.9em;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-track {
            background: var(--secondary-color);
            color: white;
        }
        
        .btn-track:hover {
            background: var(--secondary-dark);
        }
        
        .btn-repeat {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-repeat:hover {
            background: var(--primary-dark);
        }
        
        .profile-section {
            margin-bottom: 30px;
        }
        
        .profile-section h3 {
            margin-bottom: 20px;
            color: var(--primary-color);
            padding-bottom: 10px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .profile-form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-light);
            font-weight: 500;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-size: 1em;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }
        
        .empty-state .icon {
            font-size: 4em;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .account-container {
                grid-template-columns: 1fr;
            }
            
            .account-sidebar {
                position: static;
            }
            
            .order-body {
                grid-template-columns: 1fr;
            }
            
            .profile-form {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="account-header">
        <div class="container">
            <h1> Личный кабинет</h1>
            <p>Добро пожаловать, <?php echo htmlspecialchars($user['first_name'] ?? 'Пользователь'); ?>!</p>
        </div>
    </div>

    <div class="account-container">
        <aside class="account-sidebar">
            <ul class="account-menu">
                <li><a href="#orders" class="active"> Мои заказы</a></li>
                <li><a href="#profile"> Профиль</a></li>
                <li><a href="#addresses"> Адреса</a></li>
                <li><a href="#loyalty"> Программа лояльности</a></li>
                <li><a href="#notifications"> Уведомления</a></li>
                <li><a href="logout.php"> Выйти</a></li>
            </ul>
            
            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--border-color);">
                <a href="index.php" class="btn-primary" style="width: 100%; display: block; text-align: center;">
                    🛒 Новый заказ
                </a>
            </div>
        </aside>

        <main class="account-content">
            <!-- Статистика -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['active_orders']; ?></div>
                    <div class="stat-label">Активных заказов</div>
                </div>
                <div class="stat-card green">
                    <div class="stat-value"><?php echo $stats['completed_orders']; ?></div>
                    <div class="stat-label">Выполнено</div>
                </div>
                <div class="stat-card orange">
                    <div class="stat-value"><?php echo number_format($stats['total_spent'], 0, '.', ' '); ?> ₽</div>
                    <div class="stat-label">Всего потрачено</div>
                </div>
            </div>
            
            <!-- Программа лояльности -->
            <div class="loyalty-card" id="loyalty">
                <div class="loyalty-level"> <?php echo $loyaltyNames[$loyaltyLevel]; ?> клиент</div>
                <div>Ваша персональная скидка</div>
                <div class="loyalty-percent"><?php echo $discount; ?>%</div>
                <div>Заказов: <?php echo $totalOrders; ?> | Сумма: <?php echo number_format($totalSpent, 0, '.', ' '); ?> ₽</div>
                <div class="loyalty-progress">
                    <div class="loyalty-progress-bar" style="width: <?php echo min(100, ($totalOrders / 20) * 100); ?>%"></div>
                </div>
                <div style="margin-top: 10px; font-size: 0.9em; opacity: 0.8;">
                    До следующего уровня: <?php echo max(0, 20 - $totalOrders); ?> заказов
                </div>
            </div>

            <!-- Заказы -->
            <div class="profile-section" id="orders">
                <h3> Мои заказы</h3>
                
                <?php if (empty($orders)): ?>
                <div class="empty-state">
                    <div class="icon"></div>
                    <h4>У вас пока нет заказов</h4>
                    <p>Оформите первый заказ и получите скидку 5%!</p>
                    <a href="index.php#calculator" class="btn-primary" style="margin-top: 20px; display: inline-block;">
                        Оформить заказ
                    </a>
                </div>
                <?php else: ?>
                <?php foreach ($orders as $order): ?>
                <div class="order-card">
                    <div class="order-header">
                        <div>
                            <div class="order-number">Заказ №<?php echo htmlspecialchars($order['order_number']); ?></div>
                            <div class="order-date"> <?php echo date('d.m.Y H:i', strtotime($order['order_date'])); ?></div>
                        </div>
                        <span class="order-status status-<?php echo $order['status_code'] ?? 'new'; ?>">
                            <?php echo htmlspecialchars($order['status_name'] ?? 'Новый'); ?>
                        </span>
                    </div>
                    
                    <div class="order-body">
                        <div>
                            <div style="margin-bottom: 10px;">
                                <strong> Вещей:</strong> <?php echo $order['items_count'] ?? 0; ?>
                            </div>
                            <div style="margin-bottom: 10px;">
                                <strong> Оплата:</strong> <?php 
                                    $paymentMethods = ['cash' => 'Наличные', 'card' => 'Карта', 'online' => 'Онлайн'];
                                    echo $paymentMethods[$order['payment_method'] ?? 'cash'];
                                ?>
                            </div>
                            <div>
                                <strong> Доставка:</strong> <?php echo $order['delivery_date'] ? date('d.m.Y', strtotime($order['delivery_date'])) : 'Не указана'; ?>
                            </div>
                        </div>
                        <div>
                            <div style="margin-bottom: 10px;">
                                <strong> Адрес:</strong>
                                <?php
                                $stmt = $conn->prepare("SELECT street, building, apartment FROM addresses WHERE address_id = ?");
                                $stmt->execute([$order['delivery_address_id']]);
                                $address = $stmt->fetch();
                                if ($address) {
                                    echo htmlspecialchars($address['street'] . ', ' . $address['building']);
                                    if ($address['apartment']) echo ', ' . $address['apartment'];
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="order-total">
                        Итого: <?php echo $order['final_amount']; ?> ₽
                    </div>
                    
                    <div class="order-actions">
                        <a href="track-order.php?order=<?php echo urlencode($order['order_number']); ?>" 
                           class="btn-small btn-track">
                             Отследить
                        </a>
                        <?php if ($order['status_code'] == 'completed'): ?>
                        <button class="btn-small btn-repeat" onclick="repeatOrder(<?php echo $order['order_id']; ?>)">
                             Повторить
                        </button>
                        <?php endif; ?>
                        <?php if (in_array($order['status_code'], ['new', 'confirmed'])): ?>
                        <button class="btn-small" style="background: #f44336; color: white;" 
                                onclick="cancelOrder(<?php echo $order['order_id']; ?>)">
                             Отменить
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Профиль -->
            <div class="profile-section" id="profile">
                <h3> Настройки профиля</h3>
                <form id="profileForm" class="profile-form">
                    <div class="form-group">
                        <label>Имя *</label>
                        <input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Фамилия *</label>
                        <input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Отчество</label>
                        <input type="text" name="middle_name" value="<?php echo htmlspecialchars($user['middle_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Телефон *</label>
                        <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Предпочтительный способ связи</label>
                        <select name="contact_preference">
                            <option value="phone" <?php echo ($user['contact_preference'] ?? 'phone') == 'phone' ? 'selected' : ''; ?>>Телефон</option>
                            <option value="email" <?php echo ($user['contact_preference'] ?? 'phone') == 'email' ? 'selected' : ''; ?>>Email</option>
                            <option value="telegram" <?php echo ($user['contact_preference'] ?? 'phone') == 'telegram' ? 'selected' : ''; ?>>Telegram</option>
                        </select>
                    </div>
                </form>
                <button onclick="saveProfile()" class="btn-primary" style="margin-top: 20px;">
                     Сохранить изменения
                </button>
            </div>

            <!-- Уведомления -->
            <div class="profile-section" id="notifications">
                <h3> Настройки уведомлений</h3>
                <div class="form-group">
                    <label class="checkbox-label" style="display: flex; align-items: center; gap: 10px; padding: 15px; border: 1px solid var(--border-color); border-radius: 5px; margin-bottom: 10px;">
                        <input type="checkbox" id="notifyEmail" <?php echo ($user['email_notifications'] ?? 1) ? 'checked' : ''; ?>>
                        <span> Email уведомления о статусе заказа</span>
                    </label>
                    <label class="checkbox-label" style="display: flex; align-items: center; gap: 10px; padding: 15px; border: 1px solid var(--border-color); border-radius: 5px; margin-bottom: 10px;">
                        <input type="checkbox" id="notifySms" <?php echo ($user['sms_notifications'] ?? 1) ? 'checked' : ''; ?>>
                        <span> SMS уведомления</span>
                    </label>
                    <label class="checkbox-label" style="display: flex; align-items: center; gap: 10px; padding: 15px; border: 1px solid var(--border-color); border-radius: 5px; margin-bottom: 10px;">
                        <input type="checkbox" id="notifyTelegram" <?php echo ($user['telegram_notifications'] ?? 0) ? 'checked' : ''; ?>>
                        <span> Telegram уведомления</span>
                    </label>
                </div>
                <button onclick="saveNotifications()" class="btn-primary">
                     Сохранить настройки
                </button>
            </div>
        </main>
    </div>

    <?php include 'includes/footer.php'; ?>
    
    <script>
        function saveProfile() {
            const form = document.getElementById('profileForm');
            const formData = new FormData(form);
            
            fetch('api/update-profile.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(' Профиль успешно обновлен', 'success');
                } else {
                    showNotification(' ' + (data.message || 'Ошибка'), 'error');
                }
            })
            .catch(error => {
                showNotification(' Ошибка сети', 'error');
            });
        }
        
        function saveNotifications() {
            const data = {
                email: document.getElementById('notifyEmail').checked,
                sms: document.getElementById('notifySms').checked,
                telegram: document.getElementById('notifyTelegram').checked
            };
            
            fetch('api/update-notifications.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(' Настройки сохранены', 'success');
                } else {
                    showNotification(' Ошибка', 'error');
                }
            });
        }
        
        function repeatOrder(orderId) {
            if (confirm('Повторить этот заказ?')) {
                window.location.href = `api/repeat-order.php?id=${orderId}`;
            }
        }
        
        function cancelOrder(orderId) {
            if (confirm('Вы уверены, что хотите отменить заказ?')) {
                fetch('api/cancel-order.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({order_id: orderId})
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
        
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.textContent = message;
            notification.style.cssText = `
                position: fixed;
                top: 100px;
                right: 20px;
                z-index: 3000;
                padding: 15px 25px;
                border-radius: 5px;
                color: white;
                background: ${type === 'success' ? '#4CAF50' : '#f44336'};
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                animation: slideIn 0.3s;
            `;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 5000);
        }
        
        // Плавная прокрутка к секциям
        document.querySelectorAll('.account-menu a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    </script>
</body>
</html>