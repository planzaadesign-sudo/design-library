<?php
// Every admin POST lands here (after requireStaff('admin') and the CSRF check in index.php).
// Each branch validates, writes with prepared statements, sets a flash message and redirects.
if (!defined('PLANZAA_ADMIN')) { http_response_code(403); exit; }

$action = (string)($_POST['action'] ?? '');
$int = function ($key) { return (int)($_POST[$key] ?? 0); };
$str = function ($key) { return trim((string)($_POST[$key] ?? '')); };
$fail = function ($msg, $fallback = []) { keep_old($_POST); flash($msg, 'err'); go_back($fallback); };

switch ($action) {

// ---- Orders ----------------------------------------------------------------------
case 'order_assign':
    $orderId = $int('order_id');
    $staffId = $int('staff_id');
    if ($staffId && !q("SELECT id FROM staff WHERE id = ?", [$staffId])->fetchColumn()) $fail('That team member no longer exists.');
    q("UPDATE library_orders SET assigned_to = ?, assigned_at = " . ($staffId ? "NOW()" : "NULL") . " WHERE id = ?", [$staffId ?: null, $orderId]);
    flash($staffId ? 'Order assigned.' : 'Order unassigned.');
    go_back(['tab' => 'orders', 'id' => $orderId]);

case 'order_stage':
    $orderId = $int('order_id');
    $current = q("SELECT status FROM library_orders WHERE id = ?", [$orderId])->fetchColumn();
    $idx = array_search($current, STAGES, true);
    if ($idx === false) $fail('Order not found.', ['tab' => 'orders']);
    $to = ($_POST['dir'] ?? '') === 'prev' ? $idx - 1 : $idx + 1;
    if ($to < 0 || $to >= count(STAGES)) $fail('This order cannot move that way.');
    q("UPDATE library_orders SET status = ? WHERE id = ?", [STAGES[$to], $orderId]);
    flash('Order moved to ' . STAGE_LABEL[STAGES[$to]] . '.');
    go_back(['tab' => 'orders', 'id' => $orderId]);

case 'order_confirm_price':
    $orderId = $int('order_id');
    $raw = str_replace([',', ' ', '₹'], '', $str('price'));
    $o = q("SELECT o.needs_manual_review, d.base_price FROM library_orders o JOIN designs d ON d.id = o.design_id WHERE o.id = ?", [$orderId])->fetch();
    if (!$o) $fail('Order not found.', ['tab' => 'orders']);
    if (!ctype_digit($raw)) $fail('Please type the price as a whole number of rupees, for example 62500.');
    $price = (int)$raw;
    $limit = 10 * (int)$o['base_price'];
    // Sanity check: never store 0, and never more than 10x the design's own price (a typo guard).
    if ($price <= 0 || $price >= $limit) $fail('The price must be more than ₹0 and less than ' . inr_text($limit) . ' (10 times the design price).');
    q("UPDATE library_orders SET total_price = ?, needs_manual_review = 0 WHERE id = ?", [$price, $orderId]);
    flash('Price confirmed at ' . inr_text($price) . '. The customer will now see this price when they track the order.');
    go_back(['tab' => 'orders', 'id' => $orderId]);

// ---- Designs -----------------------------------------------------------------------
case 'design_save':
    $id = $int('id');
    $name = $str('name');
    $w = $int('plot_width'); $l = $int('plot_length');
    $facing = $str('facing'); $floors = $str('floors');
    $bhk = $int('bhk'); $price = $int('base_price'); $days = $int('delivery_days');
    $active = !empty($_POST['is_active']) ? 1 : 0;
    if ($name === '' || mb_strlen($name) > 100) $fail('Please give the design a name (up to 100 characters).');
    if ($w < 5 || $w > 500 || $l < 5 || $l > 500) $fail('Plot width and length must be between 5 and 500 feet.');
    if (!in_array($facing, FACINGS, true)) $fail('Please choose a facing.');
    if (!in_array($floors, FLOOR_OPTIONS, true)) $fail('Please choose the floors.');
    if ($bhk < 1 || $bhk > 10) $fail('BHK must be between 1 and 10.');
    if ($price < 1000 || $price > 10000000) $fail('Base price must be between ₹1,000 and ₹1,00,00,000.');
    if ($days < 1 || $days > 365) $fail('Delivery days must be between 1 and 365.');
    // The similarity parameters (plot shape, main door, stairs, style...) -- same questions as a brief.
    [$params, $err] = validate_brief_input($_POST, true);
    if ($err) $fail($err);
    $cols = array_merge(['name', 'plot_width', 'plot_length', 'facing', 'floors', 'bhk', 'base_price', 'delivery_days', 'is_active'], SIM_NEW_COLUMNS);
    $vals = array_merge([$name, $w, $l, $facing, $floors, $bhk, $price, $days, $active], array_map(function ($c) use ($params) { return $params[$c]; }, SIM_NEW_COLUMNS));
    if ($id) {
        q("UPDATE designs SET " . implode(' = ?, ', $cols) . " = ? WHERE id = ?", array_merge($vals, [$id]));
        flash('Design saved.');
    } else {
        $cols[] = 'variant';
        $vals[] = random_int(0, 2);
        q("INSERT INTO designs (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")", $vals);
        $id = (int)getDB()->lastInsertId();
        flash('Design added. Now add its rooms so customers can pick them when changing the design.');
    }
    header('Location: ' . url(['tab' => 'designs', 'rooms' => $id]) . '#rooms');
    exit;

case 'design_toggle':
    $id = $int('id');
    q("UPDATE designs SET is_active = 1 - is_active WHERE id = ?", [$id]);
    $on = (int)q("SELECT is_active FROM designs WHERE id = ?", [$id])->fetchColumn();
    flash($on ? 'Design is now visible to customers.' : 'Design hidden from customers. Existing orders still show it.');
    go_back(['tab' => 'designs']);

case 'room_save':
    $designId = $int('design_id');
    $roomId = $int('room_id');
    $name = $str('room_name'); $type = $str('room_type'); $floor = $str('floor');
    $size = $str('current_size_sqft') === '' ? null : $int('current_size_sqft');
    if (!q("SELECT id FROM designs WHERE id = ?", [$designId])->fetchColumn()) $fail('Design not found.', ['tab' => 'designs']);
    if ($name === '' || mb_strlen($name) > 100) $fail('Please give the room a name.');
    if (!in_array($type, ROOM_TYPES, true)) $fail('Please choose a room type.');
    if (!in_array($floor, ROOM_FLOORS, true)) $fail('Please choose a floor.');
    if ($size !== null && ($size < 1 || $size > 5000)) $fail('Room size must be between 1 and 5000 sq ft.');
    if ($roomId) {
        q("UPDATE design_rooms SET room_name = ?, room_type = ?, current_size_sqft = ?, floor = ? WHERE id = ? AND design_id = ?",
            [$name, $type, $size, $floor, $roomId, $designId]);
        flash('Room saved.');
    } else {
        q("INSERT INTO design_rooms (design_id, room_name, room_type, current_size_sqft, floor) VALUES (?, ?, ?, ?, ?)",
            [$designId, $name, $type, $size, $floor]);
        flash('Room added.');
    }
    header('Location: ' . url(['tab' => 'designs', 'rooms' => $designId]) . '#rooms');
    exit;

case 'room_delete':
    $roomId = $int('room_id');
    $designId = (int)q("SELECT design_id FROM design_rooms WHERE id = ?", [$roomId])->fetchColumn();
    if (q("SELECT COUNT(*) FROM order_modification_details WHERE room_id = ?", [$roomId])->fetchColumn() > 0) {
        flash('This room is part of existing orders, so it cannot be deleted. You can rename it instead.', 'err');
    } else {
        q("DELETE FROM design_rooms WHERE id = ?", [$roomId]);
        flash('Room deleted.');
    }
    header('Location: ' . url(['tab' => 'designs', 'rooms' => $designId]) . '#rooms');
    exit;

// ---- Modification catalog ------------------------------------------------------------
case 'mod_save':
    $id = $int('id');
    $label = $str('label');
    $tier = $int('tier');
    $days = $int('added_days');
    $detail = $str('detail_type');
    $active = !empty($_POST['is_active']) ? 1 : 0;
    if ($label === '' || mb_strlen($label) > 200) $fail('Please write the label customers will see (up to 200 characters).');
    if ($tier < 1 || $tier > 4) $fail('Please choose a tier.');
    if (!array_key_exists($detail, DETAIL_TYPES)) $fail('Please choose a detail type.');
    if ($days < 0 || $days > 120) $fail('Added days must be between 0 and 120.');
    if ($tier === 4) {
        $min = $int('price_min'); $max = $int('price_max');
        if ($min <= 0 || $max < $min) $fail('For tier 4, give a minimum price above 0 and a maximum at least as big as the minimum.');
        if ($detail !== '' && $detail !== 'other') $fail('Tier 4 changes are priced by the team, so they cannot use a room picker.');
        $price = null; $struct = 0;
    } else {
        $price = $int('price'); $struct = $int('struct_portion');
        $min = null; $max = null;
        if ($price < 0 || $price > 1000000) $fail('Price must be between ₹0 and ₹10,00,000.');
        if ($struct < 0 || $struct > $price) $fail('The structural part cannot be more than the price.');
    }
    $params = [$tier, $label, $price, $min, $max, $struct, $days, $detail === '' ? null : $detail, $active];
    if ($id) {
        q("UPDATE modifications SET tier = ?, label = ?, price = ?, price_min = ?, price_max = ?, struct_portion = ?, added_days = ?, detail_type = ?, is_active = ? WHERE id = ?",
            array_merge($params, [$id]));
        flash('Change saved. New prices apply to new orders only.');
    } else {
        q("INSERT INTO modifications (tier, label, price, price_min, price_max, struct_portion, added_days, detail_type, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", $params);
        flash('Change added to the catalog.');
    }
    header('Location: ' . url(['tab' => 'modifications']));
    exit;

case 'mod_toggle':
    q("UPDATE modifications SET is_active = 1 - is_active WHERE id = ?", [$int('id')]);
    flash('Updated. Hidden changes are no longer offered to customers.');
    go_back(['tab' => 'modifications']);

// ---- Briefs and submissions -------------------------------------------------------------
case 'brief_save':
    // Same expanded form and similarity rule as the in-house dashboard (includes/briefs.php).
    [$d, $err] = validate_brief_input($_POST);
    if ($err) $fail($err);
    $err = save_brief($d, $myId, !empty($_POST['confirm_similar']));
    if ($err) $fail($err);
    flash('Brief posted. Freelancers can now claim it.');
    header('Location: ' . url(['tab' => 'briefs']));
    exit;

case 'sub_review':
    // Same effect as the in-house review queue, plus "approve with in-house edits".
    $subId = $int('submission_id');
    $outcome = $str('outcome');
    $notes = $str('review_notes');
    $sub = q("SELECT brief_id, review_status FROM submissions WHERE id = ?", [$subId])->fetch();
    if (!$sub) $fail('Submission not found.', ['tab' => 'submissions']);
    if ($sub['review_status'] !== 'pending') $fail('This submission has already been reviewed.');
    if ($outcome === 'reject' && $notes === '') $fail('Please write what the freelancer should fix before sending it back.');
    if (!in_array($outcome, ['approve', 'approve_edits', 'reject'], true)) $fail('Please choose a review action.');
    if ($outcome !== 'reject' && empty($_POST['confirm_different'])) $fail('Please check the similarity confirmation before approving.');
    if ($outcome === 'approve_edits') $notes = trim('Approved. Our in-house team will make small fixes before publishing. ' . $notes);
    $status = $outcome === 'reject' ? 'rejected' : 'approved';
    q("UPDATE submissions SET review_status = ?, reviewer_id = ?, review_notes = ?, reviewed_at = NOW() WHERE id = ?", [$status, $myId, $notes, $subId]);
    q("UPDATE briefs SET status = ? WHERE id = ?", [$status === 'approved' ? 'approved' : 'needs_revision', $sub['brief_id']]);
    flash($status === 'approved' ? 'Submission approved. You can now standardise and publish it.' : 'Sent back to the freelancer with your notes.');
    go_back(['tab' => 'submissions', 'id' => $subId]);

case 'sub_publish':
    // Same routine as the in-house "Standardize & publish" button: copies every brief parameter.
    $subId = $int('submission_id');
    $designId = publish_submission($subId, $myId);
    if (!$designId) $fail('Only approved, unpublished submissions can be published.');
    flash('Published as a new design. Check its details and add its rooms.');
    header('Location: ' . url(['tab' => 'designs', 'edit' => $designId]) . '#designForm');
    exit;

// ---- Team ------------------------------------------------------------------------------
case 'staff_add':
case 'staff_edit':
    $id = $action === 'staff_edit' ? $int('id') : 0;
    $name = $str('name'); $email = strtolower($str('email')); $role = $str('role');
    if ($name === '' || mb_strlen($name) > 100) $fail('Please enter a name.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) $fail('Please enter a valid email address.');
    if (!in_array($role, ['admin', 'inhouse'], true)) $fail('Please choose a role.');
    if (q("SELECT id FROM staff WHERE email = ? AND id <> ?", [$email, $id])->fetchColumn()) $fail('Another team member already uses this email.');
    if ($id) {
        if ($id === $myId && $role !== 'admin') $fail('You cannot remove your own admin role.');
        q("UPDATE staff SET name = ?, email = ?, role = ? WHERE id = ?", [$name, $email, $role, $id]);
        flash('Team member updated.');
    } else {
        $pw = (string)($_POST['password'] ?? '');
        if (strlen($pw) < 8) $fail('The password must be at least 8 characters.');
        if ($pw !== (string)($_POST['password2'] ?? '')) $fail('The two passwords do not match.');
        q("INSERT INTO staff (name, email, password_hash, role) VALUES (?, ?, ?, ?)", [$name, $email, password_hash($pw, PASSWORD_DEFAULT), $role]);
        flash('Team member added. Share their email and password with them privately.');
    }
    header('Location: ' . url(['tab' => 'team']));
    exit;

case 'staff_delete':
    $id = $int('id');
    if ($id === $myId) $fail('You cannot remove yourself.');
    $member = q("SELECT role FROM staff WHERE id = ?", [$id])->fetch();
    if (!$member) $fail('Team member not found.');
    $active = (int)q("SELECT COUNT(*) FROM library_orders WHERE assigned_to = ? AND status <> 'delivered'", [$id])->fetchColumn();
    if ($active) $fail("This person is assigned to $active active order" . ($active === 1 ? '' : 's') . '. Reassign ' . ($active === 1 ? 'it' : 'them') . ' first.');
    if ($member['role'] === 'admin' && (int)q("SELECT COUNT(*) FROM staff WHERE role = 'admin'")->fetchColumn() <= 1) $fail('You cannot remove the last admin.');
    try {
        q("DELETE FROM staff WHERE id = ?", [$id]);
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') throw $e;
        // Foreign keys: briefs they posted, reviews they did, designs they published, past orders.
        $fail('This person has past work records (briefs, reviews, published designs or past orders), so they cannot be removed. Change their role or email instead.');
    }
    flash('Team member removed.');
    header('Location: ' . url(['tab' => 'team']));
    exit;

// ---- Freelancers -------------------------------------------------------------------------
case 'freelancer_add':
    $name = $str('name'); $email = strtolower($str('email'));
    $pw = (string)($_POST['password'] ?? '');
    if ($name === '' || mb_strlen($name) > 100) $fail('Please enter a name.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) $fail('Please enter a valid email address.');
    if (q("SELECT id FROM freelancers WHERE email = ?", [$email])->fetchColumn()) $fail('A freelancer with this email already exists.');
    if (strlen($pw) < 8) $fail('The password must be at least 8 characters.');
    if ($pw !== (string)($_POST['password2'] ?? '')) $fail('The two passwords do not match.');
    q("INSERT INTO freelancers (name, email, password_hash) VALUES (?, ?, ?)", [$name, $email, password_hash($pw, PASSWORD_DEFAULT)]);
    flash('Freelancer added. Share their email and password with them privately.');
    header('Location: ' . url(['tab' => 'freelancers']));
    exit;

// ---- Settings -------------------------------------------------------------------------------
case 'password_change':
    $hash = q("SELECT password_hash FROM staff WHERE id = ?", [$myId])->fetchColumn();
    $new = (string)($_POST['new_password'] ?? '');
    if (!$hash || !password_verify((string)($_POST['current_password'] ?? ''), $hash)) $fail('Your current password is not correct.');
    if (strlen($new) < 8) $fail('The new password must be at least 8 characters.');
    if ($new !== (string)($_POST['password2'] ?? '')) $fail('The two new passwords do not match.');
    q("UPDATE staff SET password_hash = ? WHERE id = ?", [password_hash($new, PASSWORD_DEFAULT), $myId]);
    flash('Your password has been changed.');
    header('Location: ' . url(['tab' => 'settings']));
    exit;

default:
    flash('Unknown action.', 'err');
    go_back(['tab' => 'dashboard']);
}
