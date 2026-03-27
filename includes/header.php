<header class="header">
    <div class="container">
        <div class="header-top">
            <div class="logo">
                <h1>🧼 Чистота</h1>
                <span>Профессиональная химчистка с доставкой</span>
            </div>
            <div class="header-contacts">
                <a href="tel:88001234567">8 (800) 123-45-67</a>
                <div class="work-time">Ежедневно 9:00 - 21:00</div>
            </div>
        </div>
        <nav class="nav">
            <a href="index.php#services">Услуги</a>
            <a href="index.php#calculator">Калькулятор</a>
            <a href="index.php#how-it-works">Как заказать</a>
            <a href="index.php#zones">Зоны доставки</a>
            <?php if (isset($_SESSION['user_id'])): ?>
            <a href="personal-account.php" class="btn-login">Личный кабинет</a>
            <?php if ($_SESSION['role'] === 'admin'): ?>
            <a href="admin.php" class="btn-login">Админ-панель</a>
            <?php endif; ?>
            <?php else: ?>
            <a href="login.php" class="btn-login">Войти</a>
            <?php endif; ?>
        </nav>
    </div>
</header>