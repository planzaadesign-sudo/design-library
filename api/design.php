<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM designs WHERE id = ? AND is_active = 1");
$stmt->execute([$id]);
$design = $stmt->fetch();

if (!$design) {
    http_response_code(404);
    echo json_encode(['error' => 'Design not found']);
    exit;
}

$design['floor_plan_svg'] = floorPlanArt((int)$design['variant']);
$design['elevation_svg'] = elevationArt();
$design['struct_svg'] = structArt();
$design['struct_addon_price'] = (int) (round($design['base_price'] * 0.4 / 100) * 100);

echo json_encode($design);
