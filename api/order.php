<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$FACINGS = ['East', 'West', 'North', 'South'];

$designId = (int)($input['design_id'] ?? 0);
$name = trim((string)($input['customer_name'] ?? ''));
$phone = trim((string)($input['customer_phone'] ?? ''));
$city = trim((string)($input['customer_city'] ?? ''));
$plotWidth = isset($input['plot_width']) && $input['plot_width'] !== '' ? (int)$input['plot_width'] : null;
$plotLength = isset($input['plot_length']) && $input['plot_length'] !== '' ? (int)$input['plot_length'] : null;
$facing = !empty($input['facing']) ? (string)$input['facing'] : null;
$structAddon = !empty($input['structural_addon']);
$structIncluded = !empty($input['structural_included']);
$modIds = $input['modifications'] ?? [];

// Field-level errors so the order form can show each message next to its field.
$errors = [];
if ($name === '' || mb_strlen($name) > 100) $errors['customer_name'] = 'Please type your name.';
if (!preg_match('/^[0-9]{10}$/', $phone)) $errors['customer_phone'] = 'Please type your 10-digit mobile number.';
if ($city === '' || mb_strlen($city) > 100) $errors['customer_city'] = 'Please type your city or town.';
if ($plotWidth !== null && ($plotWidth < 1 || $plotWidth > 1000)) $errors['plot_width'] = 'Please type a plot width between 1 and 1000 feet.';
if ($plotLength !== null && ($plotLength < 1 || $plotLength > 1000)) $errors['plot_length'] = 'Please type a plot length between 1 and 1000 feet.';
if ($facing !== null && !in_array($facing, $FACINGS, true)) $errors['facing'] = 'Please choose East, West, North or South.';
if (!is_array($modIds) || count($modIds) > 20) $errors['modifications'] = 'Something went wrong with the changes you picked. Please go back and pick them again.';

if (!$designId) {
    http_response_code(400);
    echo json_encode(['error' => 'Please choose a design first.']);
    exit;
}
if ($errors) {
    http_response_code(400);
    echo json_encode(['error' => reset($errors), 'errors' => $errors]);
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM designs WHERE id = ? AND is_active = 1");
$stmt->execute([$designId]);
$design = $stmt->fetch();

if (!$design) {
    http_response_code(404);
    echo json_encode(['error' => 'We could not find this design. Please pick another one.']);
    exit;
}

$modIds = array_values(array_unique(array_map('intval', $modIds)));
$mods = [];
if ($modIds) {
    $placeholders = implode(',', array_fill(0, count($modIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM modifications WHERE is_active = 1 AND id IN ($placeholders)");
    $stmt->execute($modIds);
    $mods = $stmt->fetchAll();
    if (count($mods) !== count($modIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'One of the changes you picked is no longer available. Please go back and pick again.']);
        exit;
    }
}

// The price is recalculated here from the database, not taken from the request body.
// A browser can send anything it wants; only this server-side number is ever stored or charged.
// assets/app.js mirrors this logic for display only -- keep the two in step.
$total = (int)$design['base_price'];
$isCustom = count($mods) > 0;
if ($isCustom) {
    // Customised order: the structural choice decides whether each modification's
    // struct_portion is charged. The as-is structural package does not apply.
    $structAddon = false;
} else {
    // As-is purchase: the structural package is the only structural option.
    $structIncluded = $structAddon;
    if ($structAddon) {
        $total += (int) (round($design['base_price'] * 0.4 / 100) * 100);
    }
}

$totalMax = $total;
$days = (int)$design['delivery_days'];
$tier3Count = 0;
$hasTier4 = false;
foreach ($mods as $m) {
    $days += (int)$m['added_days'];
    if ((int)$m['tier'] === 4) {
        $hasTier4 = true;
        $total += (int)$m['price_min'];
        $totalMax += (int)$m['price_max'];
    } else {
        if ((int)$m['tier'] === 3) $tier3Count++;
        $effective = (int)$m['price'] - ($structIncluded ? 0 : (int)$m['struct_portion']);
        $total += $effective;
        $totalMax += $effective;
    }
}
// Decided here, never taken from the browser.
$needsReview = $hasTier4 || $tier3Count > 2;

// PZL- prefix keeps this namespaced separately from any order-numbering
// the main Planzaa app (built by Babysoft) uses, in case the two are connected later.
$orderCode = 'PZL-' . strtoupper(bin2hex(random_bytes(3)));

// For manual-review orders total_price holds the low end of the estimate.
$stmt = $pdo->prepare(
    "INSERT INTO library_orders
        (order_code, design_id, customer_name, customer_phone, customer_city, plot_width, plot_length, facing,
         structural_addon, total_price, status, modifications, structural_included, needs_manual_review, estimated_delivery_days)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, ?, ?)"
);
$stmt->execute([
    $orderCode, $designId, $name, $phone, $city, $plotWidth, $plotLength, $facing,
    $structAddon ? 1 : 0, $total,
    $isCustom ? json_encode($modIds) : null, $structIncluded ? 1 : 0, $needsReview ? 1 : 0, $days,
]);

echo json_encode([
    'order_code' => $orderCode,
    'total_price' => $total,
    'total_price_max' => $totalMax,
    'needs_manual_review' => $needsReview,
    'estimated_delivery_days' => $days,
    'design_name' => $design['name'],
]);
