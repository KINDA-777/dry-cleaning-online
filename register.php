<?php
session_start();
require_once 'config/database.php';

// Если уже авторизован - редирект
if (isset($_SESSION['user_id'])) {
    header('Location: personal-account.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    
    // Валидация (A1 - Принять и зарегистрировать)
    if (empty($firstName) || empty($lastName) || empty($phone) || empty($email) || empty($password)) {
        $error = 'Все поля обязательны для заполнения';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Пароли не совпадают';
    } elseif (strlen($password) < 6) {
        $error = 'Пароль должен быть не менее 6 символов';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Некорректный email';
    } else {
        try {
            $db = new Database();
            $conn = $db->connect();
            
            if ($conn) {
                // Проверка на существующего пользователя
                $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? OR phone = ?");
                $stmt->execute([$email, $phone]);
                
                if ($stmt->fetch()) {
                    $error = 'Пользователь с таким email или телефоном уже существует';
                } else {
                    // Хэширование пароля
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Создание пользователя (A1)
                    $stmt = $conn->prepare("
                        INSERT INTO users (
                            first_name, last_name, phone, email, 
                            password_hash, is_registered, created_at
                        ) VALUES (?, ?, ?, ?, ?, 1, NOW())
                    ");
                    
                    $stmt->execute([
                        $firstName, $lastName, $phone, $email, $passwordHash
                    ]);
                    
                    $userId = $conn->lastInsertId();
                    
                    // Создание персональной скидки (начальный уровень Bronze)
                    $stmt = $conn->prepare("
                        INSERT INTO user_discounts (user_id, discount_percent, loyalty_level)
                        VALUES (?, 0, 'bronze')
                    ");
                    $stmt->execute([$userId]);
                    
                    $success = 'Регистрация успешна! Теперь вы можете войти.';
                    
                    // Логирование регистрации (A4)
                    $stmt = $conn->prepare("
                        INSERT INTO notification_subscriptions (user_id, notification_type, recipient, is_subscribed)
                        VALUES (?, 'email', ?, 1)
                    ");
                    $stmt->execute([$userId, $email]);
                }
            }
        } catch (Exception $e) {
            $error = 'Ошибка регистрации: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация - Чистота</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .register-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }
        
        .register-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 500px;
        }
        
        .register-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .register-header h1 {
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .register-form .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .register-form .form-group {
            margin-bottom: 20px;
        }
        
        .register-form label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        
        .register-form input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-size: 1em;
            transition: border-color 0.3s;
        }
        
        .register-form input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .register-form .btn-submit {
            width: 100%;
            padding: 15px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1.1em;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .register-form .btn-submit:hover {
            background: var(--primary-dark);
        }
        
        .register-links {
            margin-top: 20px;
            text-align: center;
        }
        
        .register-links a {
            color: var(--secondary-color);
            text-decoration: none;
        }
        
        .register-links a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 600px) {
            .register-form .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="register-page">
        <div class="register-container">
            <div class="register-header">
                <h1>🧼 Чистота</h1>
                <p>Создание личного кабинета</p>
            </div>
            
            <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <form class="register-form" method="POST" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">Имя *</label>
                        <input type="text" id="first_name" name="first_name" required 
                               value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="last_name">Фамилия *</label>
                        <input type="text" id="last_name" name="last_name" required
                               value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="phone">Телефон *</label>
                    <input type="tel" id="phone" name="phone" required 
                           placeholder="+7 (999) 000-00-00"
                           value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input type="email" id="email" name="email" required
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="password">Пароль *</label>
                    <input type="password" id="password" name="password" required>
                    <small style="color: #666; font-size: 0.85em;">Минимум 6 символов</small>
                </div>
                
                <div class="form-group">
                    <label for="password_confirm">Подтвердите пароль *</label>
                    <input type="password" id="password_confirm" name="password_confirm" required>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-label" style="display: flex; align-items: flex-start; gap: 10px;">
                        <input type="checkbox" name="agree" required style="width: auto; margin-top: 3px;">
                        <span>Я согласен с <a href="#" style="color: var(--secondary-color);">условиями обработки персональных данных</a> (ФЗ-152)</span>
                    </label>
                </div>
                
                <button type="submit" class="btn-submit">Зарегистрироваться</button>
            </form>
            
            <div class="register-links">
                <p>Уже есть аккаунт? <a href="login.php">Войти</a></p>
                <p><a href="index.php">← На главную</a></p>
            </div>
        </div>
    </div>
    
    <script>
        // Маска для телефона
        document.getElementById('phone').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 0) {
                if (value[0] === '7' || value[0] === '8') {
                    value = value.substring(1);
                }
                let formattedValue = '+7';
                if (value.length > 0) {
                    formattedValue += ' (' + value.substring(0, 3);
                }
                if (value.length >= 3) {
                    formattedValue += ') ' + value.substring(3, 6);
                }
                if (value.length >= 6) {
                    formattedValue += '-' + value.substring(6, 8);
                }
                if (value.length >= 8) {
                    formattedValue += '-' + value.substring(8, 10);
                }
                e.target.value = formattedValue;
            }
        });
    </script>
</body>
</html>