<?php
/**
 * Обновление профиля пользователя
 * Реализует процесс A1 - Принять и зарегистрировать данные пользователя
 * Реализует процесс A4 - Контролировать изменения (логирование)
 * Соответствует IDEF0 диаграмме уровня A-0 и A0
 */

session_start();
header('Content-Type: application/json');

// Проверка авторизации (A4 - Контроль доступов)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Пользователь не авторизован'
    ]);
    exit;
}

require_once 'config/database.php';

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Метод не разрешен'
    ]);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    if (!$conn) {
        throw new Exception('Ошибка подключения к базе данных');
    }
    
    $userId = $_SESSION['user_id'];
    
    // Получение данных из формы (A1 - Вход: Данные пользователя)
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $newPasswordConfirm = $_POST['new_password_confirm'] ?? '';
    
    // Валидация данных (Управление: ТЗ, ГОСТы, ФЗ-152)
    $errors = [];
    
    if (empty($firstName)) {
        $errors[] = 'Имя обязательно для заполнения';
    } elseif (strlen($firstName) < 2 || strlen($firstName) > 50) {
        $errors[] = 'Имя должно быть от 2 до 50 символов';
    }
    
    if (empty($lastName)) {
        $errors[] = 'Фамилия обязательна для заполнения';
    } elseif (strlen($lastName) < 2 || strlen($lastName) > 50) {
        $errors[] = 'Фамилия должна быть от 2 до 50 символов';
    }
    
    if (!empty($phone)) {
        // Очистка телефона от лишних символов
        $phoneClean = preg_replace('/[^0-9+]/', '', $phone);
        if (strlen($phoneClean) < 10) {
            $errors[] = 'Некорректный номер телефона';
        }
        $phone = $phoneClean;
    }
    
    if (!empty($email)) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Некорректный email адрес';
        }
    }
    
    // Проверка уникальности email (если изменен)
    if (!empty($email)) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $stmt->execute([$email, $userId]);
        if ($stmt->fetch()) {
            $errors[] = 'Email уже используется другим пользователем';
        }
    }
    
    // Проверка уникальности телефона (если изменен)
    if (!empty($phone)) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE phone = ? AND user_id != ?");
        $stmt->execute([$phone, $userId]);
        if ($stmt->fetch()) {
            $errors[] = 'Телефон уже используется другим пользователем';
        }
    }
    
    // Смена пароля (если запрошено)
    $passwordChanged = false;
    if (!empty($newPassword) || !empty($newPasswordConfirm)) {
        if (empty($currentPassword)) {
            $errors[] = 'Введите текущий пароль для смены';
        } else {
            // Проверка текущего пароля (A4 - Контроль доступа)
            $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                $errors[] = 'Неверный текущий пароль';
            }
            
            if (strlen($newPassword) < 6) {
                $errors[] = 'Новый пароль должен быть не менее 6 символов';
            }
            
            if ($newPassword !== $newPasswordConfirm) {
                $errors[] = 'Новые пароли не совпадают';
            }
            
            // Проверка сложности пароля
            if (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
                $errors[] = 'Пароль должен содержать буквы и цифры';
            }
            
            $passwordChanged = true;
        }
    }
    
    // Если есть ошибки - возвращаем их
    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Ошибка валидации',
            'errors' => $errors
        ]);
        exit;
    }
    
    // Начало транзакции (A1 - Атомарность операции)
    $conn->beginTransaction();
    
    try {
        // Получение текущих данных для логирования (A4)
        $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $oldData = $stmt->fetch();
        
        // Обновление данных пользователя (A1 - Выход: Обновленные данные)
        $stmt = $conn->prepare("
            UPDATE users 
            SET 
                first_name = ?,
                last_name = ?,
                middle_name = ?,
                phone = ?,
                email = ?,
                updated_at = NOW()
            WHERE user_id = ?
        ");
        $stmt->execute([
            $firstName,
            $lastName,
            $middleName,
            $phone,
            $email,
            $userId
        ]);
        
        // Смена пароля если запрошено
        if ($passwordChanged) {
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("
                UPDATE users 
                SET password_hash = ?, password_changed_at = NOW()
                WHERE user_id = ?
            ");
            $stmt->execute([$newPasswordHash, $userId]);
        }
        
        // Логирование изменений (A4 - Контроль и отчеты)
        $changes = [];
        if ($oldData['first_name'] !== $firstName) {
            $changes[] = "Имя: {$oldData['first_name']} → {$firstName}";
        }
        if ($oldData['last_name'] !== $lastName) {
            $changes[] = "Фамилия: {$oldData['last_name']} → {$lastName}";
        }
        if ($oldData['phone'] !== $phone) {
            $changes[] = "Телефон: {$oldData['phone']} → {$phone}";
        }
        if ($oldData['email'] !== $email) {
            $changes[] = "Email: {$oldData['email']} → {$email}";
        }
        if ($passwordChanged) {
            $changes[] = "Пароль: изменен";
        }
        
        if (!empty($changes)) {
            $stmt = $conn->prepare("
                INSERT INTO notification_subscriptions (
                    user_id, notification_type, recipient, is_subscribed, subscribe_date
                ) VALUES (?, 'profile_update', ?, 1, NOW())
            ");
            $stmt->execute([$userId, implode('; ', $changes)]);
            
            // Логирование в отдельную таблицу аудита (если существует)
            @ $stmt = $conn->prepare("
                INSERT INTO user_audit_log (user_id, action, old_data, new_data, ip_address, created_at)
                VALUES (?, 'profile_update', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $userId,
                json_encode($oldData, JSON_UNESCAPED_UNICODE),
                json_encode([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'middle_name' => $middleName,
                    'phone' => $phone,
                    'email' => $email
                ], JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
        }
        
        // Обновление сессионных данных
        $_SESSION['user_name'] = trim($firstName . ' ' . $lastName);
        $_SESSION['user_email'] = $email;
        $_SESSION['user_phone'] = $phone;
        
        // Фиксация транзакции
        $conn->commit();
        
        // Уведомление пользователя об изменении (A4 - Выход: Уведомление)
        if (!empty($changes)) {
            // Отправка email уведомления (можно реализовать через PHPMailer)
            // sendProfileUpdateEmail($email, $firstName, $changes);
            
            // Отправка Telegram уведомления (если подключено)
            // sendTelegramNotification($userId, 'Профиль обновлен: ' . implode(', ', $changes));
        }
        
        // Успешный ответ (A1 - Выход: Подтверждение)
        echo json_encode([
            'success' => true,
            'message' => 'Профиль успешно обновлен',
            'data' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'password_changed' => $passwordChanged
            ]
        ]);
        
    } catch (Exception $e) {
        // Откат транзакции при ошибке
        $conn->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    // Логирование ошибки БД (A4)
    error_log('Profile update PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка базы данных. Попробуйте позже.'
    ]);
    
} catch (Exception $e) {
    // Логирование общей ошибки (A4)
    error_log('Profile update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Произошла ошибка при обновлении профиля: ' . $e->getMessage()
    ]);
}
?>