<?php
/**
 * A21 - Зонировать заказы (Кластеризация)
 * Группировка заказов по географическим зонам
 */
header('Content-Type: application/json');
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->connect();
    
    // Получение всех активных заказов без назначенного курьера (A21)
    $stmt = $conn->query("
        SELECT o.*, a.latitude, a.longitude
        FROM orders o
        LEFT JOIN addresses a ON o.delivery_address_id = a.address_id
        WHERE o.status_id IN (1, 2) AND o.courier_id IS NULL
        ORDER BY o.order_date
    ");
    $orders = $stmt->fetchAll();
    
    // Получение зон доставки
    $stmt = $conn->query("SELECT * FROM delivery_zones WHERE is_active = 1");
    $zones = $stmt->fetchAll();
    
    // Кластеризация заказов по зонам (A21)
    $clusters = [];
    foreach ($zones as $zone) {
        $zoneCoords = json_decode($zone['zone_coordinates'], true);
        $clusters[$zone['zone_id']] = [
            'zone' => $zone,
            'orders' => [],
            'center' => calculateCenter($zoneCoords),
            'total_weight' => 0
        ];
    }
    
    // Распределение заказов по зонам
    foreach ($orders as $order) {
        if (!$order['latitude'] || !$order['longitude']) {
            continue;
        }
        
        $point = ['lat' => $order['latitude'], 'lng' => $order['longitude']];
        
        foreach ($clusters as $zoneId => $cluster) {
            $zoneCoords = json_decode($cluster['zone']['zone_coordinates'], true);
            if (pointInPolygon($point, $zoneCoords)) {
                $clusters[$zoneId]['orders'][] = $order;
                $clusters[$zoneId]['total_weight'] += 1; // Можно учитывать вес вещей
                break;
            }
        }
    }
    
    // Сортировка кластеров по приоритету (A22)
    uasort($clusters, function($a, $b) {
        // Приоритет: больше заказов, меньше время
        return count($b['orders']) - count($a['orders']);
    });
    
    // Логирование кластеризации (A4)
    $stmt = $conn->prepare("
        INSERT INTO reports (report_type, report_name, date_from, date_to, data_json, generated_by)
        VALUES ('custom', 'Кластеризация заказов', CURDATE(), CURDATE(), ?, 1)
    ");
    $stmt->execute([json_encode($clusters)]);
    
    echo json_encode([
        'success' => true,
        'clusters' => array_values($clusters),
        'total_orders' => count($orders),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function calculateCenter($polygon) {
    $x = 0;
    $y = 0;
    $count = count($polygon);
    
    foreach ($polygon as $coord) {
        $x += $coord[0];
        $y += $coord[1];
    }
    
    return [$x / $count, $y / $count];
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