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
    // A manual choice replaces the automatic one (and its reason / overload flag).
    $manual = $staffId ? 'Assigned by ' . $_SESSION['staff_name'] . ' on ' . date('j M Y') . '.' : null;
    q("UPDATE library_orders SET assigned_to = ?, assigned_at = " . ($staffId ? "NOW()" : "NULL") . ", auto_assigned = 0, overload_warning = 0, assignment_reason = ? WHERE id = ?",
        [$staffId ?: null, $manual, $orderId]);
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
        // A draft only goes live through "Standardize & publish" (which checks its files).
        $isDraft = q("SELECT published_at IS NULL FROM designs WHERE id = ?", [$id])->fetchColumn();
        if ($isDraft) $vals[array_search('is_active', $cols, true)] = 0;
        q("UPDATE designs SET " . implode(' = ?, ', $cols) . " = ? WHERE id = ?", array_merge($vals, [$id]));
        flash($isDraft ? 'Draft saved. Upload its files, then publish it.' : 'Design saved. (Its design code stays the same.)');
    } else {
        // Added directly by the admin: no brief or Design Creator; the admin is the whole audit trail.
        // It has no files yet, so it stays hidden until they are uploaded (then use the switch).
        $vals[array_search('is_active', $cols, true)] = 0;
        $pdo = getDB();
        $pdo->beginTransaction();
        try {
            $code = generateDesignCode($pdo, $facing, $floors, $bhk);
            $cols = array_merge($cols, ['variant', 'design_code', 'brief_created_by', 'brief_created_at', 'reviewed_by', 'reviewed_at', 'approved_by', 'approved_at', 'published_at']);
            $vals = array_merge($vals, [random_int(0, 2), $code, $myId, date('Y-m-d H:i:s'), $myId, date('Y-m-d H:i:s'), $myId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            q("INSERT INTO designs (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")", $vals);
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        flash('Design added as ' . $code . '. It stays hidden from customers until its required files are uploaded — upload them below, then turn it on in the designs list.');
        header('Location: ' . url(['tab' => 'designs', 'edit' => $id]) . '#designFiles');
        exit;
    }
    header('Location: ' . url(['tab' => 'designs', 'rooms' => $id]) . '#rooms');
    exit;

case 'design_toggle':
    $id = $int('id');
    if (q("SELECT published_at IS NULL FROM designs WHERE id = ?", [$id])->fetchColumn()) $fail('This design is still a draft. Upload its files and publish it first.');
    // Showing a design to customers needs its required files (the same rule as publishing).
    if (!q("SELECT is_active FROM designs WHERE id = ?", [$id])->fetchColumn() && ($missing = missing_required_slots($id))) {
        $fail('This design can\'t be shown to customers yet — these files are still missing: ' . implode(', ', $missing) . '.', ['tab' => 'designs', 'edit' => $id]);
    }
    q("UPDATE designs SET is_active = 1 - is_active WHERE id = ? AND published_at IS NOT NULL", [$id]);
    $on = (int)q("SELECT is_active FROM designs WHERE id = ?", [$id])->fetchColumn();
    flash($on ? 'Design is now visible to customers.' : 'Design hidden from customers. Existing orders still show it.');
    go_back(['tab' => 'designs']);

// ---- Design files (standard slots) -------------------------------------------------------
case 'slot_upload':
    $design = q("SELECT * FROM designs WHERE id = ?", [$int('design_id')])->fetch();
    $slot = $str('slot');
    if (!$design) $fail('Design not found.', ['tab' => 'designs']);
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) $fail(upload_error_text($f['error'] ?? UPLOAD_ERR_NO_FILE), ['tab' => 'designs', 'edit' => $design['id']]);
    $err = store_slot_file($design, $slot, $f['tmp_name'], $f['name'], (int)$f['size'], true, $myId);
    if ($err) $fail(FILE_SLOTS[$slot]['label'] . ': ' . $err, ['tab' => 'designs', 'edit' => $design['id']]);
    flash(FILE_SLOTS[$slot]['label'] . ' uploaded.');
    go_back(['tab' => 'designs', 'edit' => $design['id']]);

case 'slot_delete':
    $designId = $int('design_id');
    $slot = $str('slot');
    flash(isset(FILE_SLOTS[$slot]) && delete_slot_file($designId, $slot) ? FILE_SLOTS[$slot]['label'] . ' deleted.' : 'There was no file to delete.');
    go_back(['tab' => 'designs', 'edit' => $designId]);

case 'publish_draft':
    $designId = $int('design_id');
    [$ok, $err] = publish_design_draft($designId, $myId);
    if ($err) $fail($err, ['tab' => 'designs', 'edit' => $designId]);
    $code = q("SELECT design_code FROM designs WHERE id = ?", [$designId])->fetchColumn();
    flash('Published as ' . $code . '. It is now live on the website.');
    header('Location: ' . url(['tab' => 'designs', 'edit' => $designId]) . '#history');
    exit;

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
    flash('Brief posted. Design Creators can now claim it.');
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
    if ($outcome === 'reject' && $notes === '') $fail('Please write what the Design Creator should fix before sending it back.');
    if (!in_array($outcome, ['approve', 'approve_edits', 'reject'], true)) $fail('Please choose a review action.');
    if ($outcome !== 'reject' && empty($_POST['confirm_different'])) $fail('Please check the similarity confirmation before approving.');
    if ($outcome === 'approve_edits') $notes = trim('Approved. Our in-house team will make small fixes before publishing. ' . $notes);
    $status = $outcome === 'reject' ? 'rejected' : 'approved';
    q("UPDATE submissions SET review_status = ?, reviewer_id = ?, review_notes = ?, reviewed_at = NOW() WHERE id = ?", [$status, $myId, $notes, $subId]);
    q("UPDATE briefs SET status = ? WHERE id = ?", [$status === 'approved' ? 'approved' : 'needs_revision', $sub['brief_id']]);
    // Approval creates the draft design; the Design Creator's files go into their slots straight away.
    if ($status === 'approved') create_draft_from_submission($subId, $myId);
    flash($status === 'approved' ? 'Approved. A draft design was created — upload its files, then publish it.' : 'Sent back to the Design Creator with your notes.');
    go_back(['tab' => 'submissions', 'id' => $subId]);

case 'sub_publish':
    // Same routine as the in-house "Standardize & publish" button: copies every brief parameter.
    $subId = $int('submission_id');
    [$designId, $err] = publish_submission($subId, $myId);
    if ($err) {
        $draft = draft_for_submission($subId);
        if ($draft) { keep_old([]); flash($err, 'err'); header('Location: ' . url(['tab' => 'designs', 'edit' => $draft['id']]) . '#designFiles'); exit; }
        $fail($err);
    }
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
        if (q("SELECT id FROM freelancers WHERE email = ?", [$email])->fetchColumn()) $fail('A Design Creator already uses this email.');
        q("UPDATE staff SET name = ?, email = ?, role = ? WHERE id = ?", [$name, $email, $role, $id]);
        // Optional: give them a new temporary password (e.g. they forgot theirs and email is not working).
        $pw = (string)($_POST['password'] ?? '');
        if ($pw !== '' && $id !== $myId) {
            if ($rule = password_rule_error($pw)) $fail('Temporary password: ' . $rule);
            if ($pw !== (string)($_POST['password2'] ?? '')) $fail('The two passwords do not match.');
            q("UPDATE staff SET password_hash = ?, must_change_password = 1, session_token = NULL WHERE id = ?", [password_hash($pw, PASSWORD_DEFAULT), $id]);
            flash('Team member updated. They will be asked to choose their own password when they next sign in.');
        } else {
            flash('Team member updated.');
        }
    } else {
        if (q("SELECT id FROM freelancers WHERE email = ?", [$email])->fetchColumn()) $fail('A Design Creator already uses this email.');
        $pw = (string)($_POST['password'] ?? '');
        if ($rule = password_rule_error($pw)) $fail('Temporary password: ' . $rule);
        if ($pw !== (string)($_POST['password2'] ?? '')) $fail('The two passwords do not match.');
        // The temporary password only works until their first sign-in, when they must choose their own.
        q("INSERT INTO staff (name, email, password_hash, role, must_change_password) VALUES (?, ?, ?, ?, 1)", [$name, $email, password_hash($pw, PASSWORD_DEFAULT), $role]);
        flash('Team member added. Share their email and temporary password with them privately — they will choose their own password when they first sign in.');
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
    if (email_taken($email)) $fail('An account with this email already exists.');
    if ($rule = password_rule_error($pw)) $fail($rule);
    if ($pw !== (string)($_POST['password2'] ?? '')) $fail('The two passwords do not match.');
    q("INSERT INTO freelancers (name, email, password_hash) VALUES (?, ?, ?)", [$name, $email, password_hash($pw, PASSWORD_DEFAULT)]);
    flash('Design Creator added. Share their email and password with them privately.');
    header('Location: ' . url(['tab' => 'freelancers']));
    exit;

// Design Creator accounts: approve / reject registrations, suspend / reactivate. The admin never edits
// the profile itself -- that stays the Design Creator's own. Each UPDATE only runs from the right status.
case 'freelancer_approve':
case 'freelancer_reject':
case 'freelancer_suspend':
case 'freelancer_reactivate':
    $id = $int('id');
    $fr = q("SELECT * FROM freelancers WHERE id = ?", [$id])->fetch();
    if (!$fr) $fail('Design Creator not found.', ['tab' => 'freelancers']);
    $back = ['tab' => 'freelancers', 'id' => $id];
    $mailNote = function ($sent) { return $sent ? ' We emailed them.' : ' (The email could not be sent — please let them know yourself.)'; };
    if ($action === 'freelancer_approve') {
        $st = q("UPDATE freelancers SET status = 'active', rejection_reason = NULL, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status IN ('pending', 'rejected')", [$myId, $id]);
        if (!$st->rowCount()) $fail('This registration has already been reviewed.', $back);
        flash($fr['name'] . ' is approved and can now sign in.' . $mailNote(email_freelancer_approved($fr)));
        go_back(['tab' => 'freelancers']);
    }
    if ($action === 'freelancer_reject') {
        $reason = $str('reason');
        if ($reason === '') $fail('Please write the reason for not approving them. It is included in the email.', ['tab' => 'freelancers']);
        if (mb_strlen($reason) > 1000) $fail('Please keep the reason under 1000 characters.', ['tab' => 'freelancers']);
        $st = q("UPDATE freelancers SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'", [$reason, $myId, $id]);
        if (!$st->rowCount()) $fail('This registration has already been reviewed.', $back);
        flash($fr['name'] . "'s registration was not approved." . $mailNote(email_freelancer_rejected($fr, $reason)));
        go_back(['tab' => 'freelancers']);
    }
    if ($action === 'freelancer_suspend') {
        $st = q("UPDATE freelancers SET status = 'suspended', reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'active'", [$myId, $id]);
        if (!$st->rowCount()) $fail('Only active accounts can be suspended.', $back);
        flash($fr['name'] . ' is suspended and cannot sign in. Their past work stays as it is.');
        header('Location: ' . url($back));
        exit;
    }
    $st = q("UPDATE freelancers SET status = 'active', reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'suspended'", [$myId, $id]);
    if (!$st->rowCount()) $fail('Only suspended accounts can be reactivated.', $back);
    flash($fr['name'] . ' is active again and can sign in.');
    header('Location: ' . url($back));
    exit;

// ---- Settings -------------------------------------------------------------------------------
// ---- Order notes and files ----------------------------------------------------------------
case 'payment_confirm':
    // Only the admin can say the money has arrived; the order then moves to the Design stage.
    $orderId = $int('order_id');
    if (!phase10_ready() || !quote_payment_received($orderId, $myId)) $fail('This order has no confirmed quotation waiting for payment.', ['tab' => 'orders', 'id' => $orderId]);
    flash('Payment confirmed. The order has moved to the Design stage.');
    header('Location: ' . url(['tab' => 'orders', 'id' => $orderId]) . '#quote');
    exit;

case 'order_note':
    $orderId = $int('order_id');
    $note = $str('note');
    if ($note === '') $fail('Please write the note first.', ['tab' => 'orders', 'id' => $orderId]);
    if (mb_strlen($note) > 5000) $fail('Please keep the note shorter.', ['tab' => 'orders', 'id' => $orderId]);
    if (!q("SELECT id FROM library_orders WHERE id = ?", [$orderId])->fetchColumn()) $fail('Order not found.', ['tab' => 'orders']);
    add_order_note($orderId, $myId, $_SESSION['staff_name'], $note);
    flash('Note saved.');
    go_back(['tab' => 'orders', 'id' => $orderId]);

case 'order_file':
    $order = q("SELECT id, order_code FROM library_orders WHERE id = ?", [$int('order_id')])->fetch();
    if (!$order) $fail('Order not found.', ['tab' => 'orders']);
    $err = store_order_file($order, $_FILES['file'] ?? [], $myId);
    if ($err) $fail($err, ['tab' => 'orders', 'id' => $order['id']]);
    flash('File attached to the order.');
    go_back(['tab' => 'orders', 'id' => $order['id']]);

case 'settings_save':
    $max = $int('max_active_orders_per_person');
    $email = $str('admin_notification_email');
    $site = rtrim($str('site_url'), '/');
    if ($max < 1 || $max > 100) $fail('The workload limit must be between 1 and 100 orders.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $fail('Please enter a valid email address (or leave it empty).');
    if (!preg_match('#^https?://[A-Za-z0-9.-]+(:[0-9]+)?(/[A-Za-z0-9._/-]*)?$#', $site)) $fail('Please enter the website address, like https://test.planzaa.in');
    setSettingValue(getDB(), 'max_active_orders_per_person', $max);
    setSettingValue(getDB(), 'admin_notification_email', $email);
    setSettingValue(getDB(), 'site_url', $site);
    flash('Settings saved.');
    header('Location: ' . url(['tab' => 'settings']));
    exit;

case 'password_change':
    $hash = q("SELECT password_hash FROM staff WHERE id = ?", [$myId])->fetchColumn();
    $new = (string)($_POST['new_password'] ?? '');
    if (!$hash || !password_verify((string)($_POST['current_password'] ?? ''), $hash)) $fail('Your current password is not correct.');
    if ($rule = password_rule_error($new)) $fail($rule, ['tab' => 'settings']);
    if ($new !== (string)($_POST['password2'] ?? '')) $fail('The two new passwords do not match.', ['tab' => 'settings']);
    if (password_verify($new, $hash)) $fail('Your new password must be different from your current one.', ['tab' => 'settings']);
    q("UPDATE staff SET password_hash = ?, must_change_password = 0 WHERE id = ?", [password_hash($new, PASSWORD_DEFAULT), $myId]);
    session_regenerate_id(true);
    flash('Your password has been changed.');
    header('Location: ' . url(['tab' => 'settings']) . '#password');
    exit;

case 'force_password_change':
    // Everyone else on the team must choose a new password the next time they open a page.
    $n = q("UPDATE staff SET must_change_password = 1 WHERE id <> ?", [$myId])->rowCount();
    flash($n ? $n . ' team member' . ($n === 1 ? '' : 's') . ' will be asked to set a new password the next time they open the dashboard.' : 'Everyone else was already asked to change their password.');
    header('Location: ' . url(['tab' => 'settings']) . '#security');
    exit;

case 'security_unlock':
    // Lets a locked-out person (or a blocked internet connection) try again straight away.
    $email = strtolower($str('email')); $ip = $str('ip');
    if ($email !== '') q("UPDATE login_attempts SET cleared = 1 WHERE email = ? AND success = 0 AND cleared = 0", [$email]);
    if ($ip !== '') q("UPDATE login_attempts SET cleared = 1 WHERE ip_address = ? AND success = 0 AND cleared = 0", [$ip]);
    flash($email !== '' ? $email . ' is unlocked and can sign in again.' : 'That internet connection can try signing in again.');
    header('Location: ' . url(['tab' => 'settings']) . '#security');
    exit;

default:
    flash('Unknown action.', 'err');
    go_back(['tab' => 'dashboard']);
}
