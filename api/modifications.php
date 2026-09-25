<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

$pdo = getDB();
$rows = $pdo->query("SELECT id, tier, label, price, price_min, price_max, struct_portion, added_days
                     FROM modifications WHERE is_active = 1 ORDER BY tier, id")->fetchAll();

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
    ];
}

echo json_encode(array_values($groups));
