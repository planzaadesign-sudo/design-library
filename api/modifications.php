<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

$pdo = getDB();
// SELECT * so this keeps working even before the phase 3b detail_type column exists.
$rows = $pdo->query("SELECT * FROM modifications WHERE is_active = 1 ORDER BY tier, id")->fetchAll();

// Grouped by tier so the configurator can render one section per tier.
$groups = [];
foreach ($rows as $r) {
    $tier = (int)$r['tier'];
    if (!isset($groups[$tier])) {
        $groups[$tier] = ['tier' => $tier, 'items' => []];
    }
    $groups[$tier]['items'][] = [
        'id' => (int)$r['id'],
        'tier' => $tier,
        'label' => $r['label'],
        'price' => $r['price'] === null ? null : (int)$r['price'],
        'price_min' => $r['price_min'] === null ? null : (int)$r['price_min'],
        'price_max' => $r['price_max'] === null ? null : (int)$r['price_max'],
        'struct_portion' => (int)$r['struct_portion'],
        'added_days' => (int)$r['added_days'],
        // Which room picker the configurator opens for this change (null = whole house).
        'detail_type' => $r['detail_type'] ?? null,
    ];
}

echo json_encode(array_values($groups));
