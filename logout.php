<?php
session_start();

// Логирование выхода (A4 - Контроль и отчеты)
if (isset($_SESSION['user_id'])) {
    require_once 'config/database.php';
    
    try {
        $db = new Database();
        $conn = $db->connect();
        
        if ($conn) {
            // Можно записать время выхода в лог
            $stmt = $conn->prepare("
                INSERT INTO notification_subscriptions (user_id, notification_type, recipient, is_subscribed, unsubscribe_date)
                VALUES (?, 'session', 'logout', 0, NOW())
                ON DUPLICATE KEY UPDATE unsubscribe_date = NOW()
            ");
            $stmt->execute([$_SESSION['user_id']]);
        }
    } catch (Exception $e) {
        // Логирование ошибки (не критично для выхода)
        error_log('Logout error: ' . $e->getMessage());
    }
}

// Уничтожение всех переменных сессии
$_SESSION = array();

// Удаление куки сессии
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Уничтожение сессии
session_destroy();

// Редирект на главную страницу
header('Location: index.php');
exit;
?>