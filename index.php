<?php
session_start();
require_once 'config/database.php';

$db = new Database();
$conn = $db->connect();

// Получение услуг из БД (A1 - Вход: Прайс-лист)
$services = [];
$categories = [];
if ($conn) {
    $stmt = $conn->query("SELECT * FROM services WHERE is_active = 1 ORDER BY category, service_name");
    $services = $stmt->fetchAll();
    
    // Группировка по категориям
    foreach ($services as $service) {
        $categories[$service['category']][] = $service;
    }
}

// Получение зон доставки
$zones = [];
if ($conn) {
    $stmt = $conn->query("SELECT * FROM delivery_zones WHERE is_active = 1");
    $zones = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Чистота - Химчистка с доставкой</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <!-- Герой секция -->
    <section class="hero">
        <div class="container">
            <div class="hero-content">
                <h2>Химчистка с бесплатной доставкой</h2>
                <p>Заберем, почистим и доставим обратно за 24 часа</p>
                <button class="btn-primary" onclick="scrollToCalculator()">Рассчитать стоимость</button>
                <div class="hero-features">
                    <div class="feature">
                        <span class="icon"></span>
                        <span>Бесплатный забор</span>
                    </div>
                    <div class="feature">
                        <span class="icon"></span>
                        <span>От 3 часов</span>
                    </div>
                    <div class="feature">
                        <span class="icon"></span>
                        <span>Гарантия качества</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Калькулятор стоимости (A1 - Принять заказ) -->
    <section id="calculator" class="calculator-section">
        <div class="container">
            <h2>🧮 Рассчитать стоимость заказа</h2>
            <div class="calculator">
                <div class="calculator-items">
                    <h3>Выберите услуги:</h3>
                    <div class="service-list" id="serviceList">
                        <?php foreach ($categories as $category => $items): ?>
                        <div class="service-category">
                            <h4 class="category-title"><?php echo htmlspecialchars($category); ?></h4>
                            <?php foreach ($items as $service): ?>
                            <div class="service-item" 
                                 data-service-id="<?php echo $service['service_id']; ?>" 
                                 data-price="<?php echo $service['unit_price']; ?>"
                                 data-name="<?php echo htmlspecialchars($service['service_name']); ?>"
                                 data-unit="<?php echo $service['unit_type']; ?>">
                                <div class="service-item-info">
                                    <div class="service-item-name"><?php echo htmlspecialchars($service['service_name']); ?></div>
                                    <div class="service-item-price"><?php echo $service['unit_price']; ?> ₽/<?php echo $service['unit_type']; ?></div>
                                </div>
                                <div class="service-item-controls">
                                    <button class="qty-btn" onclick="changeQty(<?php echo $service['service_id']; ?>, -1)">-</button>
                                    <span class="qty-value" id="qty-<?php echo $service['service_id']; ?>">0</span>
                                    <button class="qty-btn" onclick="changeQty(<?php echo $service['service_id']; ?>, 1)">+</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="calculator-total">
                        <div class="total-row">
                            <span>Итого:</span>
                            <span class="total-price" id="totalPrice">0 ₽</span>
                        </div>
                        <div class="total-row" id="deliveryRow" style="display: none;">
                            <span>Доставка:</span>
                            <span class="delivery-price" id="deliveryPrice">0 ₽</span>
                        </div>
                        <div class="total-row" id="discountRow" style="display: none;">
                            <span>Скидка:</span>
                            <span class="discount-price" id="discountPrice">0 ₽</span>
                        </div>
                        <div class="total-row final-total">
                            <span>К оплате:</span>
                            <span class="final-price" id="finalPrice">0 ₽</span>
                        </div>
                        <button class="btn-primary" onclick="proceedToOrder()" id="proceedBtn" disabled>
                            Продолжить оформление
                        </button>
                    </div>
                </div>
                
                <div class="calculator-map">
                    <h3> Выберите адрес доставки</h3>
                    <div id="map" style="height: 350px;"></div>
                    <div class="zone-info">
                        <h4>Ваша зона доставки:</h4>
                        <span id="zoneName">Кликните на карту для выбора адреса</span>
                        <div id="deliveryCost" style="margin-top: 10px; font-weight: bold;"></div>
                    </div>
                    <div class="address-input" style="margin-top: 15px;">
                        <input type="text" id="manualAddress" placeholder="Или введите адрес вручную" 
                               style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                        <button onclick="geocodeAddress()" class="btn-primary" style="margin-top: 10px; width: 100%;">
                            Найти на карте
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Услуги -->
    <section id="services" class="services-section">
        <div class="container">
            <h2>Наши услуги</h2>
            <div class="services-grid">
                <?php foreach ($categories as $category => $items): ?>
                <div class="service-card">
                    <div class="service-icon">
                        <?php 
                        $icons = [
                            'Одежда' => '', 
                            'Домашний текстиль' => '', 
                            'Свадебные платья' => '', 
                            'Обувь и аксессуары' => '',
                            'Ковры' => ''
                        ];
                        echo isset($icons[$category]) ? $icons[$category] : '🧼';
                        ?>
                    </div>
                    <h3><?php echo htmlspecialchars($category); ?></h3>
                    <ul>
                        <?php foreach (array_slice($items, 0, 5) as $service): ?>
                        <li><?php echo htmlspecialchars($service['service_name']); ?> - <?php echo $service['unit_price']; ?> ₽</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Как заказать -->
    <section id="how-it-works" class="how-it-works">
        <div class="container">
            <h2>Как это работает</h2>
            <div class="steps">
                <div class="step">
                    <div class="step-number">1</div>
                    <h3>Оставьте заявку</h3>
                    <p>Рассчитайте стоимость в калькуляторе и оформите заказ онлайн</p>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <h3>Курьер заберет вещи</h3>
                    <p>Бесплатно приедем в удобное для вас время</p>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <h3>Профессиональная чистка</h3>
                    <p>Почистим вещи с использованием безопасных средств</p>
                </div>
                <div class="step">
                    <div class="step-number">4</div>
                    <h3>Доставка обратно</h3>
                    <p>Привезем чистые вещи в оговоренное время</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Модальное окно заказа (A1 - Регистрация заказа) -->
    <div id="orderModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeOrderModal()">&times;</span>
            <h2> Оформление заказа</h2>
            
            <div id="orderSuccess" class="success-message" style="display: none;"></div>
            <div id="orderError" class="error-message" style="display: none;"></div>
            
            <form id="orderForm" class="order-form">
                <div class="form-section">
                    <h3>👤 Контактные данные</h3>
                    <input type="text" name="client_name" placeholder="Ваше имя *" required id="clientName">
                    <input type="tel" name="client_phone" placeholder="Телефон *" required id="clientPhone" 
                           pattern="[0-9+\-\(\)\s]{10,20}">
                    <input type="email" name="client_email" placeholder="Email" id="clientEmail">
                </div>
                
                <div class="form-section">
                    <h3> Адрес забора вещей</h3>
                    <input type="text" name="city" placeholder="Город *" value="Москва" required>
                    <input type="text" name="street" placeholder="Улица *" required id="pickupStreet">
                    <input type="text" name="building" placeholder="Дом *" required id="pickupBuilding">
                    <input type="text" name="apartment" placeholder="Квартира/офис" id="pickupFlat">
                    <input type="text" name="entrance" placeholder="Подъезд" id="pickupEntrance">
                    <input type="text" name="floor" placeholder="Этаж" id="pickupFloor">
                    <input type="hidden" name="latitude" id="orderLatitude">
                    <input type="hidden" name="longitude" id="orderLongitude">
                </div>

                <div class="form-section">
                    <h3> Удобное время</h3>
                    <input type="date" name="delivery_date" required id="pickupDate">
                    <select name="delivery_time" id="pickupTime" required>
                        <option value="">Выберите временное окно</option>
                        <option value="09:00-11:00">09:00 - 11:00</option>
                        <option value="11:00-13:00">11:00 - 13:00</option>
                        <option value="13:00-15:00">13:00 - 15:00</option>
                        <option value="15:00-17:00">15:00 - 17:00</option>
                        <option value="17:00-19:00">17:00 - 19:00</option>
                        <option value="19:00-21:00">19:00 - 21:00</option>
                    </select>
                </div>

                <div class="form-section">
                    <h3> Способ оплаты</h3>
                    <label class="radio-label">
                        <input type="radio" name="payment_method" value="cash" checked>
                        Наличными курьеру
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="payment_method" value="card">
                        Картой курьеру
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="payment_method" value="online">
                        Онлайн на сайте
                    </label>
                </div>

                <div class="form-section">
                    <h3> Дополнительно</h3>
                    <textarea name="notes" placeholder="Комментарий к заказу (домофон, код, особенности вещей)" 
                              id="orderNotes" rows="3"></textarea>
                    <label class="checkbox-label">
                        <input type="checkbox" name="telegram_notify" id="telegramNotify">
                        Подписаться на уведомления в Telegram
                    </label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="sms_notify" id="smsNotify">
                        Получать SMS уведомления
                    </label>
                </div>

                <div class="order-summary">
                    <h3> Итоговая сумма:</h3>
                    <div class="summary-row">
                        <span>Услуги:</span>
                        <span id="summaryServices">0 ₽</span>
                    </div>
                    <div class="summary-row" id="summaryDeliveryRow">
                        <span>Доставка:</span>
                        <span id="summaryDelivery">0 ₽</span>
                    </div>
                    <div class="summary-row" id="summaryDiscountRow">
                        <span>Скидка:</span>
                        <span id="summaryDiscount" style="color: var(--success-color);">0 ₽</span>
                    </div>
                    <div class="summary-total" id="modalTotalPrice">0 ₽</div>
                    <input type="hidden" name="total_amount" id="hiddenTotal" value="0">
                    <input type="hidden" name="order_items" id="hiddenItems" value="">
                    <input type="hidden" name="delivery_cost" id="hiddenDelivery" value="0">
                </div>

                <button type="submit" class="btn-primary btn-full" id="submitOrderBtn">
                     Оформить заказ
                </button>
            </form>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Передача данных из PHP в JS
        const servicesData = <?php echo json_encode($services); ?>;
        const zonesData = <?php echo json_encode($zones); ?>;
        const isLoggedIn = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
        const userData = <?php echo isset($_SESSION['user_id']) ? json_encode([
            'name' => $_SESSION['user_name'] ?? '',
            'email' => $_SESSION['user_email'] ?? '',
            'phone' => $_SESSION['user_phone'] ?? ''
        ]) : 'null'; ?>;
    </script>
    <script src="script.js"></script>
</body>
</html>