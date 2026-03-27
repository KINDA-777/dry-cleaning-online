<?php
/**
 * Страница входа в систему
 * Реализует процесс A1 - Принять и зарегистрировать заказ (авторизация пользователя)
 * Соответствует IDEF0 диаграмме уровня A-0 и A0
 */

session_start();
require_once 'config/database.php';

// Если уже авторизован - редирект согласно роли (A4 - Контроль доступов)
if (isset($_SESSION['user_id'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header('Location: admin.php');
            break;
        case 'courier':
            header('Location: courier-panel.php');
            break;
        default:
            header('Location: personal-account.php');
    }
    exit;
}

$error = '';
$success = '';
$loginValue = '';

// Обработка формы входа (A1 - Принять и зарегистрировать)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    $loginValue = $login; // Сохраняем для отображения в форме
    
    // Валидация входных данных (Управление: ТЗ, ГОСТы)
    if (empty($login) || empty($password)) {
        $error = 'Введите логин и пароль';
    } else {
        try {
            $db = new Database();
            $conn = $db->connect();
            
            if ($conn) {
                // Поиск пользователя по email или телефону (Механизм: СУБД)
                $stmt = $conn->prepare("
                    SELECT u.*, c.courier_id 
                    FROM users u
                    LEFT JOIN couriers c ON u.user_id = c.user_id
                    WHERE u.email = ? OR u.phone = ?
                ");
                $stmt->execute([$login, $login]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($password, $user['password_hash'])) {
                    // Проверка активности пользователя
                    if (!$user['is_registered']) {
                        $error = 'Аккаунт не активирован. Пожалуйста, завершите регистрацию.';
                    } else {
                        // Определение роли пользователя (A4 - Контроль доступов)
                        $role = 'client'; // по умолчанию клиент
                        
                        if ($user['is_admin'] == 1) {
                            $role = 'admin';
                        } elseif ($user['courier_id'] !== null) {
                            $role = 'courier';
                            $_SESSION['courier_id'] = $user['courier_id'];
                        }
                        
                        // Успешная авторизация (A1 - Выход: Подтвержденный доступ)
                        $_SESSION['user_id'] = $user['user_id'];
                        $_SESSION['user_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['user_phone'] = $user['phone'];
                        $_SESSION['role'] = $role;
                        $_SESSION['last_login'] = date('Y-m-d H:i:s');
                        $_SESSION['login_time'] = time();
                        
                        // Обновление времени последнего входа (A4 - Логирование)
                        $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
                        $stmt->execute([$user['user_id']]);
                        
                        // Логирование входа в историю (A4 - Контроль и отчеты)
                        $stmt = $conn->prepare("
                            INSERT INTO notification_subscriptions (
                                user_id, notification_type, recipient, is_subscribed, subscribe_date
                            ) VALUES (?, 'session', 'login', 1, NOW())
                            ON DUPLICATE KEY UPDATE subscribe_date = NOW()
                        ");
                        $stmt->execute([$user['user_id']]);
                        
                        // Установка cookie для "Запомнить меня" (Механизм: Веб-сервер)
                        if ($remember) {
                            $token = bin2hex(random_bytes(32));
                            setcookie('remember_token', $token, time() + (86400 * 30), '/'); // 30 дней
                            
                            $stmt = $conn->prepare("
                                INSERT INTO user_discounts (user_id, discount_percent)
                                VALUES (?, 0)
                                ON DUPLICATE KEY UPDATE discount_percent = discount_percent
                            ");
                            $stmt->execute([$user['user_id']]);
                        }
                        
                        // Редирект в зависимости от роли (Выход A1 → A2/A3/A4)
                        switch ($role) {
                            case 'admin':
                                header('Location: admin.php');
                                break;
                            case 'courier':
                                header('Location: courier-panel.php');
                                break;
                            default:
                                // Проверка, есть ли активные заказы у клиента
                                $stmt = $conn->prepare("
                                    SELECT order_id FROM orders 
                                    WHERE user_id = ? AND status_id NOT IN (7, 8, 9)
                                    LIMIT 1
                                ");
                                $stmt->execute([$user['user_id']]);
                                if ($stmt->fetch()) {
                                    header('Location: personal-account.php#orders');
                                } else {
                                    header('Location: personal-account.php');
                                }
                        }
                        exit;
                    }
                } else {
                    // Защита от перебора паролей (Управление: ФЗ-152, безопасность)
                    sleep(1); // Задержка для защиты от brute-force
                    $error = 'Неверный логин или пароль';
                }
            } else {
                $error = 'Ошибка подключения к базе данных. Попробуйте позже.';
            }
        } catch (PDOException $e) {
            // Логирование ошибки (A4 - Контроль)
            error_log('Login error: ' . $e->getMessage());
            $error = 'Произошла ошибка при входе. Попробуйте позже.';
        } catch (Exception $e) {
            error_log('Login exception: ' . $e->getMessage());
            $error = 'Произошла ошибка при входе. Попробуйте позже.';
        }
    }
}

// Проверка cookie "Запомнить меня"
if (empty($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    // Здесь можно реализовать автоматический вход по токену
    // Для безопасности в демо-версии отключено
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Вход в личный кабинет службы химчистки Чистота">
    <meta name="robots" content="noindex, nofollow">
    <title>Вход в личный кабинет - Чистота</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <style>
        .login-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        
        .login-page::before {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            top: -100px;
            right: -100px;
            animation: float 6s ease-in-out infinite;
        }
        
        .login-page::after {
            content: '';
            position: absolute;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            bottom: -50px;
            left: -50px;
            animation: float 8s ease-in-out infinite reverse;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 450px;
            position: relative;
            z-index: 1;
            animation: slideUp 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-logo {
            font-size: 4em;
            margin-bottom: 10px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .login-header h1 {
            color: var(--primary-color);
            margin-bottom: 10px;
            font-size: 2em;
        }
        
        .login-header p {
            color: var(--text-light);
            font-size: 1em;
        }
        
        .login-form .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        
        .login-form label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-color);
            font-weight: 600;
            font-size: 0.95em;
        }
        
        .login-form .input-wrapper {
            position: relative;
        }
        
        .login-form .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 1.2em;
        }
        
        .login-form input {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 1em;
            transition: all 0.3s;
            background: var(--light-gray);
        }
        
        .login-form input:focus {
            outline: none;
            border-color: var(--primary-color);
            background: white;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.1);
        }
        
        .login-form input::placeholder {
            color: var(--text-light);
        }
        
        .login-form .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .login-form .checkbox-label {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            font-weight: normal;
            font-size: 0.95em;
            color: var(--text-light);
        }
        
        .login-form .checkbox-label input {
            width: auto;
            margin-bottom: 0;
            padding: 0;
        }
        
        .login-form .forgot-password {
            color: var(--secondary-color);
            text-decoration: none;
            font-size: 0.95em;
            transition: color 0.3s;
        }
        
        .login-form .forgot-password:hover {
            color: var(--secondary-dark);
            text-decoration: underline;
        }
        
        .login-form .btn-submit {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1.1em;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .login-form .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76, 175, 80, 0.4);
        }
        
        .login-form .btn-submit:active {
            transform: translateY(0);
        }
        
        .login-form .btn-submit::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        .login-form .btn-submit:active::after {
            width: 300px;
            height: 300px;
        }
        
        .login-form .btn-submit:disabled {
            background: var(--medium-gray);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .login-links {
            margin-top: 25px;
            text-align: center;
            padding-top: 25px;
            border-top: 1px solid var(--border-color);
        }
        
        .login-links a {
            color: var(--secondary-color);
            text-decoration: none;
            margin: 0 10px;
            font-size: 0.95em;
            transition: color 0.3s;
        }
        
        .login-links a:hover {
            color: var(--secondary-dark);
            text-decoration: underline;
        }
        
        .login-divider {
            margin: 20px 0;
            text-align: center;
            position: relative;
        }
        
        .login-divider::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            width: 100%;
            height: 1px;
            background: var(--border-color);
        }
        
        .login-divider span {
            background: white;
            padding: 0 15px;
            position: relative;
            color: var(--text-light);
            font-size: 0.9em;
        }
        
        .social-login {
            display: flex;
            gap: 15px;
            justify-content: center;
        }
        
        .social-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: 2px solid var(--border-color);
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5em;
        }
        
        .social-btn:hover {
            border-color: var(--primary-color);
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }
        
        .error-message {
            background: linear-gradient(135deg, #ffebee, #ffcdd2);
            color: #c62828;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            border-left: 4px solid #c62828;
            animation: shake 0.5s;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        .success-message {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            color: #2e7d32;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            border-left: 4px solid #2e7d32;
        }
        
        .info-box {
            background: var(--light-gray);
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 0.9em;
            color: var(--text-light);
        }
        
        .info-box h4 {
            margin-bottom: 10px;
            color: var(--text-color);
        }
        
        .info-box ul {
            list-style: none;
            padding-left: 0;
        }
        
        .info-box li {
            padding: 5px 0;
            padding-left: 20px;
            position: relative;
        }
        
        .info-box li::before {
            content: '✓';
            position: absolute;
            left: 0;
            color: var(--primary-color);
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--text-light);
            transition: color 0.3s;
        }
        
        .password-toggle:hover {
            color: var(--primary-color);
        }
        
        @media (max-width: 480px) {
            .login-container {
                padding: 30px 20px;
            }
            
            .login-header h1 {
                font-size: 1.5em;
            }
            
            .login-logo {
                font-size: 3em;
            }
            
            .login-form .form-options {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="login-page">
        <div class="login-container">
            <div class="login-header">
                <div class="login-logo">🧼</div>
                <h1>Чистота</h1>
                <p>Вход в личный кабинет</p>
            </div>
            
            <?php if ($error): ?>
            <div class="error-message">
                <strong>⚠️ Ошибка:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="success-message">
                <strong>✓</strong> <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>
            
            <form class="login-form" method="POST" action="" id="loginForm">
                <div class="form-group">
                    <label for="login">Email или телефон</label>
                    <div class="input-wrapper">
                        <span class="input-icon"></span>
                        <input 
                            type="text" 
                            id="login" 
                            name="login" 
                            required 
                            value="<?php echo htmlspecialchars($loginValue); ?>"
                            placeholder="example@mail.ru или +7 (999) 000-00-00"
                            autocomplete="username"
                            autofocus
                        >
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Пароль</label>
                    <div class="input-wrapper">
                        <span class="input-icon"></span>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            required 
                            placeholder="••••••••"
                            autocomplete="current-password"
                        >
                        <span class="password-toggle" onclick="togglePassword()">👁️</span>
                    </div>
                </div>
                
                <div class="form-options">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember" id="remember">
                        Запомнить меня (30 дней)
                    </label>
                    <a href="forgot-password.php" class="forgot-password">Забыли пароль?</a>
                </div>
                
                <button type="submit" class="btn-submit" id="submitBtn">
                    <span>Войти в кабинет</span>
                </button>
            </form>
            
            <div class="login-divider">
                <span>или войдите через</span>
            </div>
            
            <div class="social-login">
                <button class="social-btn" title="Войти через VK" onclick="socialLogin('vk')">
                </button>
                <button class="social-btn" title="Войти через Google" onclick="socialLogin('google')">
                </button>
                <button class="social-btn" title="Войти через Яндекс" onclick="socialLogin('yandex')">
                </button>
            </div>
            
            <div class="login-links">
                <p>Нет аккаунта? <a href="register.php">Зарегистрироваться</a></p>
                <p><a href="index.php">← Вернуться на главную</a></p>
            </div>
            
            <div class="info-box">
                <h4> Тестовые данные для входа:</h4>
                <ul>
                    <li><strong>Админ:</strong> admin@chistota.ru / password</li>
                    <li><strong>Курьер:</strong> courier@chistota.ru / password</li>
                    <li><strong>Клиент:</strong> client@chistota.ru / password</li>
                </ul>
            </div>
        </div>
    </div>
    
    <script src="script.js"></script>
    <script>
        // Показ/скрытие пароля
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleBtn = document.querySelector('.password-toggle');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleBtn.textContent = '';
            } else {
                passwordInput.type = 'password';
                toggleBtn.textContent = '👁️';
            }
        }
        
        // Социальный вход (заглушка)
        function socialLogin(provider) {
            alert('Вход через ' + provider + ' будет доступен в следующей версии!');
            // Здесь будет интеграция с OAuth провайдерами
        }
        
        // Защита от двойной отправки формы
        const form = document.getElementById('loginForm');
        const submitBtn = document.getElementById('submitBtn');
        
        form.addEventListener('submit', function(e) {
            if (submitBtn.disabled) {
                e.preventDefault();
                return;
            }
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span>⏳ Вход...</span>';
            
            // Автоматическая разблокировка через 5 секунд на случай ошибки
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<span>Войти в кабинет</span>';
            }, 5000);
        });
        
        // Enter в поле пароля отправляет форму
        document.getElementById('password').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                form.submit();
            }
        });
        
        // Анимация при ошибке
        <?php if ($error): ?>
        document.querySelector('.login-container').classList.add('shake');
        <?php endif; ?>
        
        // Фокус на поле ввода при загрузке
        window.addEventListener('load', function() {
            const loginInput = document.getElementById('login');
            if (!loginInput.value) {
                loginInput.focus();
            }
        });
        
        // Сохранение логина в localStorage для удобства
        const loginInput = document.getElementById('login');
        const savedLogin = localStorage.getItem('saved_login');
        if (savedLogin && !loginInput.value) {
            loginInput.value = savedLogin;
            document.getElementById('remember').checked = true;
        }
        
        loginInput.addEventListener('change', function() {
            if (document.getElementById('remember').checked) {
                localStorage.setItem('saved_login', this.value);
            } else {
                localStorage.removeItem('saved_login');
            }
        });
        
        // Отслеживание Caps Lock
        document.getElementById('password').addEventListener('keypress', function(e) {
            if (e.getModifierState('CapsLock')) {
                this.style.borderColor = '#ff9800';
                this.style.backgroundColor = '#fff3e0';
            } else {
                this.style.borderColor = '';
                this.style.backgroundColor = '';
            }
        });
    </script>
</body>
</html>