<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/design_utils.php';
require_once __DIR__ . '/../includes/quotes.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Accept a JSON body (from track.php) or a plain form post.
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

$code = strtoupper(trim((string)($input['order_code'] ?? '')));
$phone = trim((string)($input['phone'] ?? ''));

$notFound = 'We could not find an order with this order number and mobile number. Please check both and try again.';
if (!preg_match('/^PZL-[0-9A-F]{6}$/', $code) || !preg_match('/^[0-9]{10}$/', $phone)) {
    http_response_code(404);
    echo json_encode(['error' => $notFound]);
    exit;
}

$pdo = getDB();
// Order code + phone together act as the customer's lightweight credential,
// so both must match -- never look an order up by code alone.
$stmt = $pdo->prepare(
    // o.* so this keeps working before the phase 3c contact_preference column exists.
    "SELECT o.*, d.name AS design_name
     FROM library_orders o JOIN designs d ON o.design_id = d.id
     WHERE o.order_code = ? AND o.customer_phone = ?"
);
$stmt->execute([$code, $phone]);
$order = $stmt->fetch();

if (!$order) {
    usleep(400000); // Slow down anyone guessing codes.
    http_response_code(404);
    echo json_encode(['error' => $notFound]);
    exit;
}

$modLabels = [];
$ids = json_decode($order['modifications'] ?? '', true);
if (is_array($ids) && $ids) {
    $ids = array_map('intval', $ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    // Labels only; include inactive items so old orders still show what was bought.
    $stmt = $pdo->prepare("SELECT label FROM modifications WHERE id IN ($placeholders) ORDER BY tier, id");
    $stmt->execute($ids);
    $modLabels = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

echo json_encode([
    'order_code' => $order['order_code'],
    'design_name' => $order['design_name'],
    'design_code' => phase7_ready() ? (du_q("SELECT design_code FROM designs WHERE id = ?", [(int)$order['design_id']])->fetchColumn() ?: null) : null,
    'created_at' => $order['created_at'],
    'status' => $order['status'],
    'total_price' => (int)$order['total_price'],
    'structural' => (bool)($order['structural_addon'] || $order['structural_included']),
    'needs_manual_review' => (bool)$order['needs_manual_review'],
    'estimated_delivery_days' => $order['estimated_delivery_days'] === null ? null : (int)$order['estimated_delivery_days'],
    'modifications' => $modLabels,
    // true when the customer asked our design expert to call instead of picking changes
    'callback' => ($order['contact_preference'] ?? 'self') === 'call' && empty($order['quotation_id']),
    // Call-back orders: 'preparing' / 'sent' (quotation waiting) / 'confirmed' (payment pending); null = normal tracking
    'quote_state' => quote_tracking_state($order),
]);
