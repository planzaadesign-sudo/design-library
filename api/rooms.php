<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

$designId = (int)($_GET['design_id'] ?? 0);
if (!$designId) {
    http_response_code(400);
    echo json_encode(['error' => 'Please choose a design first.']);
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT id, room_name, room_type, current_size_sqft, floor
                       FROM design_rooms WHERE design_id = ? ORDER BY id");
$stmt->execute([$designId]);

// Grouped by floor, lowest floor first, so the configurator can show one block per floor.
$FLOOR_ORDER = ['Ground Floor' => 0, 'Ground' => 0, 'First Floor' => 1, 'Second Floor' => 2, 'Third Floor' => 3];
$floors = [];
foreach ($stmt->fetchAll() as $r) {
    $floor = $r['floor'] ?: 'Ground Floor';
    if (!isset($floors[$floor])) {
        $floors[$floor] = ['floor' => $floor, 'rooms' => []];
    }
    $floors[$floor]['rooms'][] = [
        'id' => (int)$r['id'],
        'room_name' => $r['room_name'],
        'room_type' => $r['room_type'],
        'current_size_sqft' => $r['current_size_sqft'] === null ? null : (int)$r['current_size_sqft'],
        'floor' => $floor,
    ];
}
uksort($floors, function ($a, $b) use ($FLOOR_ORDER) {
    return ($FLOOR_ORDER[$a] ?? 9) <=> ($FLOOR_ORDER[$b] ?? 9) ?: strcmp($a, $b);
});

echo json_encode(array_values($floors));
