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
$STATES = [
    'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat', 'Haryana',
    'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur',
    'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana',
    'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal', 'Andaman and Nicobar Islands', 'Chandigarh',
    'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry',
];
// Must match the note assets/app.js sends when the customer picks "let our architect decide".
$ARCHITECT_NOTE = 'Customer requested architect to decide';

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
$rawDetails = $input['modification_details'] ?? [];
// 'call' = the customer wants our design expert to phone them instead of picking changes.
$contactPref = ($input['contact_preference'] ?? 'self') === 'call' ? 'call' : 'self';
$state = trim((string)($input['customer_state'] ?? ''));
$district = trim((string)($input['customer_district'] ?? ''));
$callbackNotes = trim((string)($input['callback_notes'] ?? ''));

// Field-level errors so the order form can show each message next to its field.
$errors = [];
if ($name === '' || mb_strlen($name) > 100) $errors['customer_name'] = 'Please type your name.';
if (!preg_match('/^[0-9]{10}$/', $phone)) $errors['customer_phone'] = 'Please type your 10-digit mobile number.';
if ($contactPref === 'call') {
    if (!in_array($state, $STATES, true)) $errors['customer_state'] = 'Please choose your state.';
    if ($district === '' || mb_strlen($district) > 100) $errors['customer_district'] = 'Please type your district or city.';
    if (mb_strlen($callbackNotes) > 1000) $errors['callback_notes'] = 'Please make your note a little shorter.';
    $city = $district; // staff dashboards show customer_city
} elseif ($city === '' || mb_strlen($city) > 100) {
    $errors['customer_city'] = 'Please type your city or town.';
}
if ($plotWidth !== null && ($plotWidth < 1 || $plotWidth > 1000)) $errors['plot_width'] = 'Please type a plot width between 1 and 1000 feet.';
if ($plotLength !== null && ($plotLength < 1 || $plotLength > 1000)) $errors['plot_length'] = 'Please type a plot length between 1 and 1000 feet.';
if ($facing !== null && !in_array($facing, $FACINGS, true)) $errors['facing'] = 'Please choose East, West, North or South.';
if (!is_array($modIds) || count($modIds) > 20 || !is_array($rawDetails) || count($rawDetails) > 150) $errors['modifications'] = 'Something went wrong with the changes you picked. Please go back and pick them again.';

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

// ---- "Call me" request -----------------------------------------------------------
// No changes are picked yet, so nothing to price beyond the design itself: store the
// base price and mark it for the team, who will call and work out the real order.
if ($contactPref === 'call') {
    $orderCode = 'PZL-' . strtoupper(bin2hex(random_bytes(3)));
    $stmt = $pdo->prepare(
        "INSERT INTO library_orders
            (order_code, design_id, customer_name, customer_phone, customer_city, plot_width, plot_length, facing,
             structural_addon, total_price, status, needs_manual_review, estimated_delivery_days,
             customer_state, customer_district, contact_preference, callback_notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'new', 1, ?, ?, ?, 'call', ?)"
    );
    $stmt->execute([
        $orderCode, $designId, $name, $phone, $city, $plotWidth, $plotLength, $facing,
        (int)$design['base_price'], (int)$design['delivery_days'],
        $state, $district, $callbackNotes === '' ? null : $callbackNotes,
    ]);
    echo json_encode([
        'order_code' => $orderCode,
        'callback' => true,
        'design_name' => $design['name'],
    ]);
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

// ---- Room-by-room details ----------------------------------------------------
// Each room-based change (resize, move a wall, add a bathroom, door/window, room use)
// is charged once per room picked, so the rooms are checked against this design's
// own room list. Colour and "other" changes carry one note each.
$ROOM_KINDS = ['resize', 'partition', 'washroom', 'opening', 'relabel'];
$ACTIONS = ['increase', 'decrease', 'add', 'remove', 'other'];
$fail = function ($msg) {
    http_response_code(400);
    echo json_encode(['error' => $msg]);
    exit;
};

$modsById = [];
foreach ($mods as $m) $modsById[(int)$m['id']] = $m;

$byMod = [];
foreach ($rawDetails as $d) {
    $mid = is_array($d) ? (int)($d['modification_id'] ?? 0) : 0;
    if (!isset($modsById[$mid])) $fail('Something went wrong with the changes you picked. Please go back and pick them again.');
    $roomId = isset($d['room_id']) && $d['room_id'] !== '' ? (int)$d['room_id'] : null;
    $action = isset($d['action']) && $d['action'] !== '' ? (string)$d['action'] : null;
    if ($action !== null && !in_array($action, $ACTIONS, true)) $fail('Something went wrong with the changes you picked. Please go back and pick them again.');
    $note = trim((string)($d['custom_note'] ?? ''));
    if (mb_strlen($note) > 1000) $fail('One of your notes is too long. Please make it shorter.');
    $byMod[$mid][] = ['room_id' => $roomId, 'action' => $action, 'custom_note' => $note === '' ? null : $note];
}

$rooms = null; // room id => room_type, loaded only when a room-based change is picked
$qty = [];
$detailRows = [];
$hasOther = false;
foreach ($mods as $m) {
    $mid = (int)$m['id'];
    $kind = $m['detail_type'] ?? null;
    $entries = $byMod[$mid] ?? [];
    $label = $m['label'];
    if (in_array($kind, $ROOM_KINDS, true)) {
        if ($rooms === null) {
            $stmt = $pdo->prepare("SELECT id, room_type FROM design_rooms WHERE design_id = ?");
            $stmt->execute([$designId]);
            $rooms = [];
            foreach ($stmt->fetchAll() as $r) $rooms[(int)$r['id']] = $r['room_type'];
        }
        if (count($entries) === 1 && $entries[0]['room_id'] === null && $entries[0]['custom_note'] === $ARCHITECT_NOTE) {
            // "Let our architect decide": no rooms picked, charged as one room.
            $entries[0]['action'] = 'other';
        } elseif ($rooms) {
            if (!$entries) $fail('Please pick at least one room for: ' . $label);
            $seen = [];
            foreach ($entries as $i => $e) {
                $rid = $e['room_id'];
                if ($rid === null || !isset($rooms[$rid]) || isset($seen[$rid])) $fail('Please check the rooms you picked for: ' . $label);
                $seen[$rid] = true;
                if ($kind === 'washroom' && $rooms[$rid] !== 'bedroom') $fail('A bathroom can only be added to a bedroom.');
                if ($kind === 'resize' && !in_array($e['action'], ['increase', 'decrease', 'other'], true)) $fail('Please choose bigger, smaller or other for each room in: ' . $label);
                if ($kind === 'resize' && $e['action'] === 'other' && $e['custom_note'] === null) $fail('Please tell us what you want for each room in: ' . $label);
                if (in_array($kind, ['partition', 'opening', 'relabel'], true) && $e['custom_note'] === null) $fail('Please fill in the details for each room in: ' . $label);
                if ($kind === 'washroom' || $kind === 'opening') $entries[$i]['action'] = 'add';
                if ($kind === 'partition' || $kind === 'relabel') $entries[$i]['action'] = 'other';
            }
        } else {
            // This design has no room list yet: accept one written request instead.
            if (count($entries) !== 1 || $entries[0]['room_id'] !== null || $entries[0]['custom_note'] === null) {
                $fail('Please tell us which rooms you want to change for: ' . $label);
            }
            $entries[0]['action'] = 'other';
        }
        $qty[$mid] = count($entries);
    } elseif ($kind === 'colour' || $kind === 'other') {
        if (count($entries) !== 1 || $entries[0]['room_id'] !== null || $entries[0]['custom_note'] === null) {
            $fail($kind === 'colour' ? 'Please pick a colour scheme or describe the colours you want.' : 'Please tell us what other changes you want.');
        }
        if ($kind === 'other') $hasOther = true;
        $qty[$mid] = 1;
    } else {
        $entries = []; // whole-house change: nothing extra to store
        $qty[$mid] = 1;
    }
    foreach ($entries as $e) $detailRows[] = [$mid, $e['room_id'], $e['action'], $e['custom_note']];
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
        // Room-based changes are charged once per room picked.
        $effective = ((int)$m['price'] - ($structIncluded ? 0 : (int)$m['struct_portion'])) * $qty[(int)$m['id']];
        $total += $effective;
        $totalMax += $effective;
    }
}
// Decided here, never taken from the browser. "Any other changes" has no fixed
// price, so it always goes to the team for pricing.
$needsReview = $hasTier4 || $tier3Count > 2 || $hasOther;

// PZL- prefix keeps this namespaced separately from any order-numbering
// the main Planzaa app (built by Babysoft) uses, in case the two are connected later.
$orderCode = 'PZL-' . strtoupper(bin2hex(random_bytes(3)));

// For manual-review orders total_price holds the low end of the estimate.
// The order and its room details are saved together or not at all.
$pdo->beginTransaction();
try {
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
    $orderId = (int)$pdo->lastInsertId();
    if ($detailRows) {
        $stmt = $pdo->prepare("INSERT INTO order_modification_details (order_id, modification_id, room_id, action, custom_note) VALUES (?, ?, ?, ?, ?)");
        foreach ($detailRows as $row) $stmt->execute(array_merge([$orderId], $row));
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

echo json_encode([
    'order_code' => $orderCode,
    'total_price' => $total,
    'total_price_max' => $totalMax,
    'needs_manual_review' => $needsReview,
    'estimated_delivery_days' => $days,
    'design_name' => $design['name'],
]);
