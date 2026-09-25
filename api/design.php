<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/design_utils.php';

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

// Internal columns (who designed/approved it, etc.) are never sent to the public.
foreach (['brief_id', 'brief_created_by', 'brief_created_at', 'designed_by', 'designed_at', 'reviewed_by', 'reviewed_at',
    'approved_by', 'approved_at', 'standardized_by', 'source_submission_id'] as $c) unset($design[$c]);
$previews = phase7_ready() ? preview_urls($design['id']) : ['preview_plan' => null, 'preview_elevation' => null];
$design['preview_plan_url'] = $previews['preview_plan'];
$design['preview_elevation_url'] = $previews['preview_elevation'];
$design['floor_plan_svg'] = floorPlanArt((int)$design['variant']);
$design['elevation_svg'] = elevationArt();
$design['struct_svg'] = structArt();
$design['struct_addon_price'] = (int) (round($design['base_price'] * 0.4 / 100) * 100);

echo json_encode($design);
