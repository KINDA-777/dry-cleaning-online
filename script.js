/**
 * Основной JavaScript для сайта химчистки
 * Реализует функции калькулятора, карты и оформления заказа (A1, A2, A4)
 */

// Глобальные переменные
let cart = {};
let deliveryCost = 0;
let discount = 0;
let map = null;
let selectedMarker = null;
let selectedLocation = null;

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    initCalculator();
    initMap();
    initOrderForm();
    setMinDate();
    preloadUserData();
});

// ============================================
// КАЛЬКУЛЯТОР (A1 - Принять заказ)
// ============================================

function initCalculator() {
    const serviceList = document.getElementById('serviceList');
    if (!serviceList) return;

    // Инициализация корзины для всех услуг
    document.querySelectorAll('.service-item').forEach(item => {
        const serviceId = item.dataset.serviceId;
        const price = parseFloat(item.dataset.price);
        
        if (!cart[serviceId]) {
            cart[serviceId] = {
                quantity: 0,
                price: price,
                name: item.dataset.name,
                unit: item.dataset.unit
            };
        }
    });
}

function changeQty(serviceId, delta) {
    if (!cart[serviceId]) {
        const item = document.querySelector(`[data-service-id="${serviceId}"]`);
        if (item) {
            cart[serviceId] = {
                quantity: 0,
                price: parseFloat(item.dataset.price),
                name: item.dataset.name,
                unit: item.dataset.unit
            };
        }
    }
    
    cart[serviceId].quantity += delta;
    if (cart[serviceId].quantity < 0) cart[serviceId].quantity = 0;
    
    const qtyElement = document.getElementById(`qty-${serviceId}`);
    if (qtyElement) {
        qtyElement.textContent = cart[serviceId].quantity;
    }
    
    // Анимация при изменении
    const item = document.querySelector(`[data-service-id="${serviceId}"]`);
    if (item && delta > 0) {
        item.style.transform = 'scale(1.02)';
        setTimeout(() => item.style.transform = 'scale(1)', 200);
    }
    
    updateTotal();
}

function updateTotal() {
    let servicesTotal = 0;
    let itemsCount = 0;
    
    // Подсчет стоимости услуг
    for (let serviceId in cart) {
        if (cart[serviceId].quantity > 0) {
            servicesTotal += cart[serviceId].price * cart[serviceId].quantity;
            itemsCount += cart[serviceId].quantity;
        }
    }
    
    // Применение скидки (если есть)
    discount = 0;
    if (isLoggedIn && itemsCount >= 5) {
        discount = servicesTotal * 0.1; // 10% скидка от 5 вещей
    } else if (isLoggedIn && itemsCount >= 3) {
        discount = servicesTotal * 0.05; // 5% скидка от 3 вещей
    }
    
    // Итоговая сумма
    let finalTotal = servicesTotal + deliveryCost - discount;
    if (finalTotal < 0) finalTotal = 0;
    
    // Обновление отображения
    const totalElements = document.querySelectorAll('#totalPrice');
    totalElements.forEach(el => {
        if (el) el.textContent = `${servicesTotal} ₽`;
    });
    
    const deliveryRow = document.getElementById('deliveryRow');
    const deliveryPrice = document.getElementById('deliveryPrice');
    if (deliveryRow && deliveryPrice) {
        deliveryRow.style.display = deliveryCost > 0 ? 'flex' : 'none';
        deliveryPrice.textContent = `${deliveryCost} ₽`;
    }
    
    const discountRow = document.getElementById('discountRow');
    const discountPrice = document.getElementById('discountPrice');
    if (discountRow && discountPrice) {
        discountRow.style.display = discount > 0 ? 'flex' : 'none';
        discountPrice.textContent = `-${discount} ₽`;
    }
    
    const finalPriceElements = document.querySelectorAll('#finalPrice');
    finalPriceElements.forEach(el => {
        if (el) el.textContent = `${finalTotal} ₽`;
    });
    
    // Обновление скрытых полей для отправки
    document.getElementById('hiddenTotal').value = finalTotal;
    document.getElementById('hiddenDelivery').value = deliveryCost;
    
    // Обновление модального окна
    document.getElementById('summaryServices').textContent = `${servicesTotal} ₽`;
    document.getElementById('summaryDelivery').textContent = `${deliveryCost} ₽`;
    document.getElementById('summaryDiscount').textContent = `-${discount} ₽`;
    document.getElementById('modalTotalPrice').textContent = `${finalTotal} ₽`;
    
    // Активация кнопки оформления
    const proceedBtn = document.getElementById('proceedBtn');
    if (proceedBtn) {
        proceedBtn.disabled = finalTotal <= 0;
        if (finalTotal > 0) {
            proceedBtn.style.opacity = '1';
            proceedBtn.style.cursor = 'pointer';
        } else {
            proceedBtn.style.opacity = '0.5';
            proceedBtn.style.cursor = 'not-allowed';
        }
    }
    
    // Сохранение позиций заказа
    const items = [];
    for (let serviceId in cart) {
        if (cart[serviceId].quantity > 0) {
            items.push({
                service_id: serviceId,
                name: cart[serviceId].name,
                quantity: cart[serviceId].quantity,
                price: cart[serviceId].price,
                total: cart[serviceId].price * cart[serviceId].quantity
            });
        }
    }
    document.getElementById('hiddenItems').value = JSON.stringify(items);
    
    return finalTotal;
}

// ============================================
// КАРТА И ЗОНЫ ДОСТАВКИ (A23 - Интеграция карт)
// ============================================

function initMap() {
    const mapElement = document.getElementById('map');
    if (!mapElement) return;

    // Создание карты (Leaflet + OpenStreetMap)
    map = L.map('map').setView([55.7558, 37.6173], 10);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 18
    }).addTo(map);

    // Отрисовка зон доставки (A21 - Зонирование)
    zonesData.forEach(zone => {
        const coords = JSON.parse(zone.zone_coordinates);
        L.polygon(coords, {
            color: zone.delivery_cost == 0 ? '#4CAF50' : '#2196F3',
            fillColor: zone.delivery_cost == 0 ? '#4CAF50' : '#2196F3',
            fillOpacity: 0.15,
            weight: 2
        }).addTo(map).bindPopup(`
            <b>${zone.zone_name}</b><br>
            Доставка: ${zone.delivery_cost} ₽<br>
            Мин. заказ: ${zone.min_order_amount} ₽
        `);
    });

    // Обработка клика по карте
    map.on('click', function(e) {
        selectLocation(e.latlng);
    });
}

function selectLocation(latlng) {
    // Удаление предыдущего маркера
    if (selectedMarker) {
        map.removeLayer(selectedMarker);
    }
    
    // Создание нового маркера
    selectedMarker = L.marker(latlng).addTo(map)
        .bindPopup('📍 Ваш адрес')
        .openPopup();
    
    selectedLocation = {
        lat: latlng.lat,
        lng: latlng.lng
    };
    
    // Определение зоны доставки (A21)
    const zone = detectZone(latlng);
    updateDeliveryInfo(zone);
    
    // Заполнение полей адреса
    document.getElementById('orderLatitude').value = latlng.lat;
    document.getElementById('orderLongitude').value = latlng.lng;
}

function detectZone(latlng) {
    for (let zone of zonesData) {
        const coords = JSON.parse(zone.zone_coordinates);
        if (pointInPolygon(latlng, coords)) {
            return zone;
        }
    }
    return null;
}

function pointInPolygon(point, polygon) {
    let inside = false;
    const x = point.lng;
    const y = point.lat;
    
    for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
        const xi = polygon[i][0], yi = polygon[i][1];
        const xj = polygon[j][0], yj = polygon[j][1];
        
        if (((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi)) {
            inside = !inside;
        }
    }
    return inside;
}

function updateDeliveryInfo(zone) {
    const zoneName = document.getElementById('zoneName');
    const deliveryCostEl = document.getElementById('deliveryCost');
    
    if (zone) {
        zoneName.textContent = zone.zone_name;
        zoneName.style.color = '#4CAF50';
        deliveryCost = parseFloat(zone.delivery_cost);
        deliveryCostEl.textContent = `Стоимость доставки: ${deliveryCost} ₽`;
        deliveryCostEl.style.color = deliveryCost == 0 ? '#4CAF50' : '#2196F3';
    } else {
        zoneName.textContent = 'Вне зоны доставки';
        zoneName.style.color = '#f44336';
        deliveryCost = 500; // Стандартная доставка
        deliveryCostEl.textContent = 'Стоимость доставки: 500 ₽ (стандарт)';
        deliveryCostEl.style.color = '#f44336';
    }
    
    updateTotal();
}

function geocodeAddress() {
    const address = document.getElementById('manualAddress').value;
    if (!address) {
        alert('Введите адрес');
        return;
    }
    
    // Имитация геокодирования (в реальности - API Яндекс.Карт)
    fetch(`api/yandex-maps.php?action=geocode&address=${encodeURIComponent(address)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const latlng = L.latLng(data.latitude, data.longitude);
                map.setView(latlng, 15);
                selectLocation(latlng);
                document.getElementById('manualAddress').value = data.address;
            } else {
                alert('Адрес не найден. Выберите точку на карте.');
            }
        })
        .catch(() => {
            // Фолбэк - случайная точка в Москве
            const latlng = L.latLng(55.7558 + (Math.random() - 0.5) * 0.1, 37.6173 + (Math.random() - 0.5) * 0.1);
            map.setView(latlng, 15);
            selectLocation(latlng);
        });
}

// ============================================
// МОДАЛЬНОЕ ОКНО И ОФОРМЛЕНИЕ ЗАКАЗА (A1)
// ============================================

function scrollToCalculator() {
    document.getElementById('calculator').scrollIntoView({ behavior: 'smooth' });
}

function openOrderModal() {
    const modal = document.getElementById('orderModal');
    if (modal) {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        preloadUserData();
    }
}

function closeOrderModal() {
    const modal = document.getElementById('orderModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

function proceedToOrder() {
    const total = updateTotal();
    if (total <= 0) {
        showNotification('Пожалуйста, выберите услуги', 'error');
        return;
    }
    openOrderModal();
}

function preloadUserData() {
    if (isLoggedIn && userData) {
        const nameInput = document.getElementById('clientName');
        const phoneInput = document.getElementById('clientPhone');
        const emailInput = document.getElementById('clientEmail');
        
        if (nameInput && userData.name) nameInput.value = userData.name;
        if (phoneInput && userData.phone) phoneInput.value = userData.phone;
        if (emailInput && userData.email) emailInput.value = userData.email;
    }
}

function initOrderForm() {
    const form = document.getElementById('orderForm');
    if (!form) return;

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const total = updateTotal();
        if (total <= 0) {
            showNotification('Выберите услуги для заказа', 'error');
            return;
        }
        
        const submitBtn = document.getElementById('submitOrderBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '⏳ Оформление...';
        
        const formData = new FormData(form);
        
        try {
            const response = await fetch('api/create-order.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Успешное создание заказа (A1 - Выход)
                document.getElementById('orderSuccess').style.display = 'block';
                document.getElementById('orderSuccess').innerHTML = `
                    <strong>✅ Заказ оформлен!</strong><br>
                    Номер заказа: <b>${result.order_number}</b><br>
                    Сумма: ${result.total_amount} ₽<br>
                    Мы отправили подтверждение на ${formData.get('client_email') || 'ваш email'}
                `;
                document.getElementById('orderError').style.display = 'none';
                
                // Блокировка формы
                form.querySelectorAll('input, select, textarea, button').forEach(el => {
                    el.disabled = true;
                });
                
                // Кнопка отслеживания
                setTimeout(() => {
                    const trackBtn = document.createElement('button');
                    trackBtn.className = 'btn-primary';
                    trackBtn.style.width = '100%';
                    trackBtn.style.marginTop = '15px';
                    trackBtn.textContent = '📍 Отследить заказ';
                    trackBtn.onclick = () => {
                        window.location.href = `track-order.php?order=${result.order_number}`;
                    };
                    form.appendChild(trackBtn);
                }, 1000);
                
                // Очистка корзины
                cart = {};
                document.querySelectorAll('.qty-value').forEach(el => el.textContent = '0');
                deliveryCost = 0;
                discount = 0;
                updateTotal();
                
            } else {
                throw new Error(result.message || 'Ошибка при оформлении');
            }
            
        } catch (error) {
            document.getElementById('orderError').style.display = 'block';
            document.getElementById('orderError').textContent = '❌ ' + error.message;
            document.getElementById('orderSuccess').style.display = 'none';
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '✅ Оформить заказ';
        }
    });
}

function setMinDate() {
    const dateInput = document.getElementById('pickupDate');
    if (dateInput) {
        const today = new Date();
        const tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);
        dateInput.min = tomorrow.toISOString().split('T')[0];
        
        // Установка максимального месяца вперед
        const maxDate = new Date(today);
        maxDate.setMonth(maxDate.getMonth() + 1);
        dateInput.max = maxDate.toISOString().split('T')[0];
    }
}

// ============================================
// УВЕДОМЛЕНИЯ
// ============================================

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = type === 'error' ? 'error-message' : 'success-message';
    notification.textContent = message;
    notification.style.cssText = `
        position: fixed;
        top: 100px;
        right: 20px;
        z-index: 3000;
        min-width: 300px;
        padding: 15px 20px;
        border-radius: 5px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        animation: slideIn 0.3s;
        color: white;
    `;
    
    if (type === 'error') {
        notification.style.background = '#f44336';
    } else {
        notification.style.background = '#4CAF50';
    }
    
    document.body.appendChild(notification);
    setTimeout(() => notification.remove(), 5000);
}

// Закрытие модального окна при клике вне его
window.onclick = function(event) {
    const modal = document.getElementById('orderModal');
    if (event.target === modal) {
        closeOrderModal();
    }
}

// Закрытие по ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeOrderModal();
    }
});

// Маска для телефона
document.addEventListener('DOMContentLoaded', function() {
    const phoneInput = document.getElementById('clientPhone');
    if (phoneInput) {
        phoneInput.addEventListener('input', function(e) {
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
    }
});