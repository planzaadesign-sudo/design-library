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

$designId = (int)($input['design_id'] ?? 0);
$name = trim($input['customer_name'] ?? '');
$phone = trim($input['customer_phone'] ?? '');
$city = trim($input['customer_city'] ?? '');
$plotWidth = isset($input['plot_width']) && $input['plot_width'] !== '' ? (int)$input['plot_width'] : null;
$plotLength = isset($input['plot_length']) && $input['plot_length'] !== '' ? (int)$input['plot_length'] : null;
$facing = !empty($input['facing']) ? $input['facing'] : null;
$structAddon = !empty($input['structural_addon']);

if (!$designId || $name === '' || !preg_match('/^[0-9]{10}$/', $phone)) {
    http_response_code(400);
    echo json_encode(['error' => 'Please provide a design, your name, and a valid 10-digit phone number.']);
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM designs WHERE id = ? AND is_active = 1");
$stmt->execute([$designId]);
$design = $stmt->fetch();

if (!$design) {
    http_response_code(404);
    echo json_encode(['error' => 'Design not found']);
    exit;
}

// The price is recalculated here from the database, not taken from the request body.
// A browser can send anything it wants; only this server-side number is ever stored or charged.
$total = (int)$design['base_price'];
if ($structAddon) {
    $total += (int) (round($design['base_price'] * 0.4 / 100) * 100);
}

// PZL- prefix keeps this namespaced separately from any order-numbering
// the main Planzaa app (built by Babysoft) uses, in case the two are connected later.
$orderCode = 'PZL-' . strtoupper(bin2hex(random_bytes(3)));

$stmt = $pdo->prepare(
    "INSERT INTO library_orders
        (order_code, design_id, customer_name, customer_phone, customer_city, plot_width, plot_length, facing, structural_addon, total_price, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new')"
);
$stmt->execute([$orderCode, $designId, $name, $phone, $city, $plotWidth, $plotLength, $facing, $structAddon ? 1 : 0, $total]);

echo json_encode([
    'order_code' => $orderCode,
    'total_price' => $total,
    'design_name' => $design['name'],
]);
