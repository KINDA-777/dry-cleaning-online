<?php
/**
 * A23 - Интегрировать данные карт (Яндекс.Карты)
 * Расчет маршрутов, пробок, координат
 */
header('Content-Type: application/json');
require_once '../config/database.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $db = new Database();
    $conn = $db->connect();
    
    switch ($action) {
        case 'geocode':
            // Геокодирование адреса
            $address = $_GET['address'] ?? '';
            if (empty($address)) {
                throw new Exception('Адрес не указан');
            }
            
            // Интеграция с Yandex Geocoding API
            $apiKey = getYandexApiKey();
            $url = "https://geocode-maps.yandex.ru/1.x/?apikey={$apiKey}&geocode={$address}&format=json&lang=ru_RU";
            
            $response = file_get_contents($url);
            $data = json_decode($response, true);
            
            if (isset($data['response']['GeoObjectCollection']['featureMember'][0])) {
                $coords = $data['response']['GeoObjectCollection']['featureMember'][0]['GeoObject']['Point']['pos'];
                list($lon, $lat) = explode(' ', $coords);
                
                echo json_encode([
                    'success' => true,
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'address' => $address
                ]);
            } else {
                throw new Exception('Адрес не найден');
            }
            break;
            
        case 'routing':
            // Построение маршрута (A22, A23)
            $startLat = $_GET['start_lat'] ?? 0;
            $startLon = $_GET['start_lon'] ?? 0;
            $endLat = $_GET['end_lat'] ?? 0;
            $endLon = $_GET['end_lon'] ?? 0;
            
            $apiKey = getYandexApiKey();
            $url = "https://router.project-osrm.org/route/v1/driving/{$startLon},{$startLat};{$endLon},{$endLat}?overview=full&geometries=geojson";
            
            $response = file_get_contents($url);
            $data = json_decode($response, true);
            
            if (isset($data['routes'][0])) {
                $route = $data['routes'][0];
                echo json_encode([
                    'success' => true,
                    'distance' => $route['distance'], // метры
                    'duration' => $route['duration'], // секунды
                    'geometry' => $route['geometry']
                ]);
            } else {
                throw new Exception('Маршрут не построен');
            }
            break;
            
        case 'traffic':
            // Данные о пробках (A23)
            $lat = $_GET['lat'] ?? 55.7558;
            $lon = $_GET['lon'] ?? 37.6173;
            
            // Симуляция данных о пробках
            $trafficLevel = rand(1, 10); // 1-10 баллов
            $delayFactor = 1 + ($trafficLevel / 20); // коэффициент задержки
            
            echo json_encode([
                'success' => true,
                'traffic_level' => $trafficLevel,
                'delay_factor' => $delayFactor,
                'message' => getTrafficMessage($trafficLevel)
            ]);
            break;
            
        case 'detect_zone':
            // Определение зоны доставки (A21 - Зонирование)
            $lat = $_GET['lat'] ?? 0;
            $lon = $_GET['lon'] ?? 0;
            
            $stmt = $conn->query("SELECT * FROM delivery_zones WHERE is_active = 1");
            $zones = $stmt->fetchAll();
            
            $detectedZone = null;
            foreach ($zones as $zone) {
                $coords = json_decode($zone['zone_coordinates'], true);
                if (pointInPolygon(['lat' => $lat, 'lng' => $lon], $coords)) {
                    $detectedZone = $zone;
                    break;
                }
            }
            
            echo json_encode([
                'success' => true,
                'zone' => $detectedZone,
                'latitude' => $lat,
                'longitude' => $lon
            ]);
            break;
            
        default:
            throw new Exception('Неизвестное действие');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Вспомогательные функции
function getYandexApiKey() {
    // В реальном проекте брать из настроек
    return getenv('YANDEX_MAPS_API_KEY') ?: '';
}

function getTrafficMessage($level) {
    $messages = [
        1 => 'Дороги свободны',
        3 => 'Небольшие затруднения',
        5 => 'Средние пробки',
        7 => 'Серьезные пробки',
        9 => 'Очень сильные пробки',
        10 => 'Дороги перекрыты'
    ];
    
    foreach ($messages as $threshold => $message) {
        if ($level <= $threshold) {
            return $message;
        }
    }
    return 'Неизвестная обстановка';
}

function pointInPolygon($point, $polygon) {
    $inside = false;
    $x = $point['lng'];
    $y = $point['lat'];
    
    for ($i = 0, $j = count($polygon) - 1; $i < count($polygon); $j = $i++) {
        $xi = $polygon[$i][0];
        $yi = $polygon[$i][1];
        $xj = $polygon[$j][0];
        $yj = $polygon[$j][1];
        
        if ((($yi > $y) != ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) {
            $inside = !$inside;
        }
    }
    
    return $inside;
}
?>