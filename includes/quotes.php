<?php
// Quotations for call-back orders: pricing, the private customer link, status and confirmation.
// Used by inhouse/, admin/, quote.php, track.php and api/track.php. Only defines functions.

require_once __DIR__ . '/design_utils.php'; // db, auto_assign_order(), getSettingValue(), preview_urls()

const QUOTE_TTL_DAYS = 7;
const QUOTE_ARCHITECT_NOTE = 'Customer requested architect to decide';   // same text as api/order.php
const QUOTE_COLOUR_ARCHITECT_NOTE = 'Architect to suggest colour scheme'; // same text as assets/app.js
const QUOTE_ROOM_KINDS = ['resize', 'partition', 'washroom', 'opening', 'relabel'];
const QUOTE_STATUS_LABEL = ['draft' => 'Draft', 'sent' => 'Sent', 'viewed' => 'Viewed', 'confirmed' => 'Confirmed', 'expired' => 'Expired', 'cancelled' => 'Cancelled'];
const PLANZAA_PHONE = '+91-8920218394';

function qt_q($sql, array $params = []) { return du_q($sql, $params); }

function phase10_ready() {
    static $ready = null;
    if ($ready === null) {
        $tables = qt_q("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $cols = in_array('library_orders', $tables, true) ? qt_q("SHOW COLUMNS FROM library_orders")->fetchAll(PDO::FETCH_COLUMN) : [];
        $ready = in_array('quotations', $tables, true) && !array_diff(['quotation_id', 'payment_status', 'payment_confirmed_at', 'payment_confirmed_by'], $cols);
    }
    return $ready;
}

/** Indian number format: 1,23,456. */
function quote_money($n) {
    $n = (int)$n;
    $s = (string)abs($n);
    if (strlen($s) > 3) $s = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($s, 0, -3)) . ',' . substr($s, -3);
    return ($n < 0 ? '-' : '') . '₹' . $s;
}

// ---- Pricing (mirrors api/order.php -- keep the two in step) -------------------------------
/**
 * Prices a configuration for one design. Everything comes from the database; nothing the
 * browser sends about prices is used. $rawDetails: [{modification_id, room_id, action, custom_note}].
 * Returns [result, null] or [null, error message].
 */
function quote_price(array $design, array $modIds, array $rawDetails, $structural) {
    $modIds = array_values(array_unique(array_filter(array_map('intval', $modIds))));
    if (count($modIds) > 20 || count($rawDetails) > 150) return [null, 'Too many changes picked. Please check the list.'];
    $mods = [];
    if ($modIds) {
        $mods = qt_q("SELECT * FROM modifications WHERE is_active = 1 AND id IN (" . implode(',', array_fill(0, count($modIds), '?')) . ") ORDER BY tier, id", $modIds)->fetchAll();
        if (count($mods) !== count($modIds)) return [null, 'One of the changes is no longer available. Please pick again.'];
    }
    $modsById = [];
    foreach ($mods as $m) $modsById[(int)$m['id']] = $m;

    $byMod = [];
    foreach ($rawDetails as $d) {
        $mid = is_array($d) ? (int)($d['modification_id'] ?? 0) : 0;
        if (!isset($modsById[$mid])) return [null, 'Something went wrong with the picked changes. Please pick them again.'];
        $roomId = isset($d['room_id']) && $d['room_id'] !== '' && $d['room_id'] !== null ? (int)$d['room_id'] : null;
        $action = isset($d['action']) && $d['action'] !== '' && $d['action'] !== null ? (string)$d['action'] : null;
        if ($action !== null && !in_array($action, ['increase', 'decrease', 'add', 'remove', 'other'], true)) return [null, 'Something went wrong with the picked changes. Please pick them again.'];
        $note = trim((string)($d['custom_note'] ?? ''));
        if (mb_strlen($note) > 1000) return [null, 'One of the notes is too long. Please shorten it.'];
        $byMod[$mid][] = ['room_id' => $roomId, 'action' => $action, 'custom_note' => $note === '' ? null : $note];
    }

    $rooms = [];
    foreach (qt_q("SELECT id, room_name, room_type FROM design_rooms WHERE design_id = ?", [(int)$design['id']])->fetchAll() as $r) $rooms[(int)$r['id']] = $r;

    $qty = []; $detailRows = []; $hasOther = false; $texts = [];
    foreach ($mods as $m) {
        $mid = (int)$m['id']; $kind = $m['detail_type'] ?? null; $label = $m['label'];
        $entries = $byMod[$mid] ?? [];
        $texts[$mid] = [];
        if (in_array($kind, QUOTE_ROOM_KINDS, true)) {
            if (count($entries) === 1 && $entries[0]['room_id'] === null && $entries[0]['custom_note'] === QUOTE_ARCHITECT_NOTE) {
                $entries[0]['action'] = 'other';
                $texts[$mid][] = 'Our architect will choose the best option for this';
            } elseif ($rooms) {
                if (!$entries) return [null, 'Please pick at least one room for: ' . $label];
                $seen = [];
                foreach ($entries as $i => $e) {
                    $rid = $e['room_id'];
                    if ($rid === null || !isset($rooms[$rid]) || isset($seen[$rid])) return [null, 'Please check the rooms picked for: ' . $label];
                    $seen[$rid] = true;
                    if ($kind === 'washroom' && $rooms[$rid]['room_type'] !== 'bedroom') return [null, 'A bathroom can only be added to a bedroom.'];
                    if ($kind === 'resize' && !in_array($e['action'], ['increase', 'decrease', 'other'], true)) return [null, 'Please choose bigger, smaller or other for each room in: ' . $label];
                    if ($kind === 'resize' && $e['action'] === 'other' && $e['custom_note'] === null) return [null, 'Please say what is wanted for each room in: ' . $label];
                    if (in_array($kind, ['partition', 'opening', 'relabel'], true) && $e['custom_note'] === null) return [null, 'Please fill in the details for each room in: ' . $label];
                    if ($kind === 'washroom' || $kind === 'opening') $entries[$i]['action'] = 'add';
                    if ($kind === 'partition' || $kind === 'relabel') $entries[$i]['action'] = 'other';
                    $room = $rooms[$rid]['room_name'];
                    $what = $kind === 'resize' ? ($e['action'] === 'increase' ? 'make it bigger' : ($e['action'] === 'decrease' ? 'make it smaller' : $e['custom_note']))
                        : ($kind === 'washroom' ? 'add an attached bathroom' : ($kind === 'relabel' ? strtolower(preg_replace('/^Change to: /', 'use it as: ', $e['custom_note'])) : $e['custom_note']));
                    $texts[$mid][] = $room . ' — ' . $what;
                }
            } else {
                if (count($entries) !== 1 || $entries[0]['room_id'] !== null || $entries[0]['custom_note'] === null) return [null, 'Please describe which rooms to change for: ' . $label];
                $entries[0]['action'] = 'other';
                $texts[$mid][] = $entries[0]['custom_note'];
            }
            $qty[$mid] = count($entries);
        } elseif ($kind === 'colour' || $kind === 'other') {
            if (count($entries) !== 1 || $entries[0]['room_id'] !== null || $entries[0]['custom_note'] === null) {
                return [null, $kind === 'colour' ? 'Please pick a colour scheme or describe the colours.' : 'Please describe the other changes.'];
            }
            if ($kind === 'other') $hasOther = true;
            $note = $entries[0]['custom_note'];
            $texts[$mid][] = $note === QUOTE_COLOUR_ARCHITECT_NOTE ? 'Our architect will suggest the best colours for your house style' : ($kind === 'colour' ? 'Colours: ' . $note : $note);
            $qty[$mid] = 1;
        } else {
            $entries = [];
            $qty[$mid] = 1;
        }
        foreach ($entries as $e) $detailRows[] = ['modification_id' => $mid, 'room_id' => $e['room_id'], 'action' => $e['action'], 'custom_note' => $e['custom_note']];
    }

    // Same rules as api/order.php. Structural money is shown as its own line on the quotation.
    $base = (int)$design['base_price'];
    $isCustom = count($mods) > 0;
    $structPrice = 0;
    if (!$isCustom && $structural) $structPrice = (int)(round($base * 0.4 / 100) * 100); // as-is: the 40% package
    $modTotal = 0; $modTotalMax = 0; $days = (int)$design['delivery_days'];
    $tier3 = 0; $hasTier4 = false; $lines = [];
    foreach ($mods as $m) {
        $mid = (int)$m['id']; $days += (int)$m['added_days'];
        $line = ['modification_id' => $mid, 'label' => $m['label'], 'tier' => (int)$m['tier'], 'qty' => $qty[$mid], 'details' => $texts[$mid], 'amount' => null, 'amount_max' => null, 'price_later' => false];
        if ((int)$m['tier'] === 4) {
            $hasTier4 = true;
            $line['amount'] = (int)$m['price_min']; $line['amount_max'] = (int)$m['price_max'];
            $modTotal += (int)$m['price_min']; $modTotalMax += (int)$m['price_max'];
        } else {
            if ((int)$m['tier'] === 3) $tier3++;
            $arch = ((int)$m['price'] - (int)$m['struct_portion']) * $qty[$mid];
            $str = $structural ? (int)$m['struct_portion'] * $qty[$mid] : 0;
            $line['amount'] = $arch;
            $line['price_later'] = ($m['detail_type'] ?? '') === 'other' && (int)$m['price'] === 0;
            $modTotal += $arch; $modTotalMax += $arch; $structPrice += $str;
        }
        $lines[] = $line;
    }
    $total = $base + $modTotal + $structPrice;
    $totalMax = $base + $modTotalMax + $structPrice;
    return [[
        'design_id' => (int)$design['id'], 'mod_ids' => array_map(function ($m) { return (int)$m['id']; }, $mods),
        'detail_rows' => $detailRows, 'lines' => $lines, 'structural_included' => (bool)$structural, 'struct_addon' => !$isCustom && $structural,
        'base' => $base, 'mod_total' => $modTotal, 'struct_price' => $structPrice, 'total' => $total, 'total_max' => $totalMax,
        'days' => $days, 'needs_review' => $hasTier4 || $tier3 > 2 || $hasOther,
    ], null];
}

// ---- The private link ------------------------------------------------------------------------
/** [lookup id, secret, hash of the secret]. The link is quote.php?token=<lookup>.<secret>. */
function quote_new_token() {
    $secret = bin2hex(random_bytes(32));
    return [bin2hex(random_bytes(16)), $secret, password_hash($secret, PASSWORD_DEFAULT)];
}
function quote_link($lookup, $secret) {
    return rtrim((string)getSettingValue(getDB(), 'site_url', 'https://test.planzaa.in'), '/') . '/quote.php?token=' . $lookup . '.' . $secret;
}
/** The quotation a link belongs to (any status), or null if the link is wrong. */
function quote_find_by_token($token) {
    if (!preg_match('/^([0-9a-f]{32})\.([0-9a-f]{64})$/', (string)$token, $m)) return null;
    $row = qt_q("SELECT * FROM quotations WHERE token = ?", [$m[1]])->fetch();
    if (!$row || !password_verify($m[2], $row['token_hash'])) return null;
    return quote_refresh($row);
}
/** Marks a sent quotation as expired once its 7 days are over. Returns the fresh row. */
function quote_refresh(array $row) {
    if (in_array($row['status'], ['sent', 'viewed'], true)) {
        $st = qt_q("UPDATE quotations SET status = 'expired' WHERE id = ? AND status IN ('sent', 'viewed') AND expires_at <= NOW()", [(int)$row['id']]);
        if ($st->rowCount()) $row = qt_q("SELECT * FROM quotations WHERE id = ?", [(int)$row['id']])->fetch();
    }
    return $row;
}
/** The order's current quotation (newest one that is not cancelled), or null. */
function quote_for_order($orderId) {
    $row = qt_q("SELECT q.*, s.name AS created_by_name FROM quotations q LEFT JOIN staff s ON s.id = q.created_by
                 WHERE q.order_id = ? AND q.status <> 'cancelled' ORDER BY q.id DESC LIMIT 1", [(int)$orderId])->fetch();
    return $row ? quote_refresh($row) : null;
}
function quote_status_badge($status) {
    $cls = ['draft' => 'badge-neutral', 'sent' => 'b-blue', 'viewed' => 'b-blue', 'confirmed' => 'b-green', 'expired' => 'b-rust', 'cancelled' => 'badge-neutral'][$status] ?? 'badge-neutral';
    return '<span class="badge ' . $cls . '">' . du_h(QUOTE_STATUS_LABEL[$status] ?? $status) . '</span>';
}

// ---- Create / resend / cancel ----------------------------------------------------------------
/**
 * Saves a quotation (draft), then marks it sent with a fresh 7-day link.
 * Returns [quotation id, link, null] or [null, null, error].
 */
function quote_create(array $order, array $design, array $priced, $staffId, $notesForCustomer, $internalNotes, $email) {
    [$lookup, $secret, $hash] = quote_new_token();
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        // One live quotation per order: refuse if another one appeared in the meantime.
        qt_q("SELECT id FROM library_orders WHERE id = ? FOR UPDATE", [(int)$order['id']]);
        $open = qt_q("SELECT COUNT(*) FROM quotations WHERE order_id = ? AND status IN ('draft', 'sent', 'viewed', 'confirmed')", [(int)$order['id']])->fetchColumn();
        if ($open) { $pdo->rollBack(); return [null, null, 'This order already has a quotation. Cancel it first to prepare a new one.']; }
        qt_q("INSERT INTO quotations (order_id, design_id, created_by, structural_included, modifications, modification_details, line_items,
                base_price, modification_total, structural_price, total_price, total_price_max, estimated_delivery_days, needs_manual_pricing,
                notes_for_customer, internal_notes, customer_email, status, token, token_hash)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)",
            [(int)$order['id'], (int)$design['id'], (int)$staffId, $priced['structural_included'] ? 1 : 0, json_encode($priced['mod_ids']),
             json_encode($priced['detail_rows']), json_encode($priced['lines']), $priced['base'], $priced['mod_total'], $priced['struct_price'],
             $priced['total'], $priced['total_max'], $priced['days'], $priced['needs_review'] ? 1 : 0,
             $notesForCustomer === '' ? null : $notesForCustomer, $internalNotes === '' ? null : $internalNotes, $email === '' ? null : $email, $lookup, $hash]);
        $id = (int)$pdo->lastInsertId();
        qt_q("UPDATE quotations SET status = 'sent', sent_at = NOW(), expires_at = NOW() + INTERVAL ? DAY WHERE id = ?", [QUOTE_TTL_DAYS, $id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$id, quote_link($lookup, $secret), null];
}
/** New link (the old one stops working) and 7 more days. Returns the new link or null. */
function quote_resend($quoteId) {
    [$lookup, $secret, $hash] = quote_new_token();
    $st = qt_q("UPDATE quotations SET token = ?, token_hash = ?, status = 'sent', sent_at = NOW(), expires_at = NOW() + INTERVAL ? DAY
                WHERE id = ? AND status IN ('sent', 'viewed', 'expired')", [$lookup, $hash, QUOTE_TTL_DAYS, (int)$quoteId]);
    return $st->rowCount() ? quote_link($lookup, $secret) : null;
}
function quote_cancel($quoteId) {
    return (bool)qt_q("UPDATE quotations SET status = 'cancelled' WHERE id = ? AND status IN ('draft', 'sent', 'viewed', 'expired')", [(int)$quoteId])->rowCount();
}

// ---- Customer confirms -----------------------------------------------------------------------
/**
 * Confirms a quotation and turns it into the real order (all or nothing), then assigns the order
 * the usual way and tells the admin. Returns [true, null] or [false, message].
 */
function quote_confirm($quoteId) {
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $q = qt_q("SELECT * FROM quotations WHERE id = ? FOR UPDATE", [(int)$quoteId])->fetch();
        if (!$q || !in_array($q['status'], ['sent', 'viewed'], true)) { $pdo->rollBack(); return [false, $q && $q['status'] === 'confirmed' ? 'confirmed' : 'unavailable']; }
        if ((int)qt_q("SELECT expires_at <= NOW() FROM quotations WHERE id = ?", [(int)$quoteId])->fetchColumn()) { $pdo->rollBack(); quote_refresh($q); return [false, 'expired']; }
        $order = qt_q("SELECT * FROM library_orders WHERE id = ? FOR UPDATE", [(int)$q['order_id']])->fetch();
        if (!$order || $order['quotation_id']) { $pdo->rollBack(); return [false, 'confirmed']; }
        qt_q("UPDATE quotations SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?", [(int)$q['id']]);
        $mods = json_decode((string)$q['modifications'], true) ?: [];
        qt_q("UPDATE library_orders SET quotation_id = ?, design_id = ?, modifications = ?, structural_included = ?, structural_addon = ?,
                total_price = ?, needs_manual_review = ?, estimated_delivery_days = ?, payment_status = 'pending', status = 'new'
              WHERE id = ?",
            [(int)$q['id'], (int)$q['design_id'], $mods ? json_encode($mods) : null, (int)$q['structural_included'],
             !$mods && $q['structural_included'] ? 1 : 0, (int)$q['total_price'], (int)$q['needs_manual_pricing'], (int)$q['estimated_delivery_days'], (int)$order['id']]);
        // The room-by-room details now describe this order.
        qt_q("DELETE FROM order_modification_details WHERE order_id = ?", [(int)$order['id']]);
        foreach (json_decode((string)$q['modification_details'], true) ?: [] as $d) {
            qt_q("INSERT INTO order_modification_details (order_id, modification_id, room_id, action, custom_note) VALUES (?, ?, ?, ?, ?)",
                [(int)$order['id'], (int)$d['modification_id'], $d['room_id'] === null ? null : (int)$d['room_id'], $d['action'], $d['custom_note']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    try { auto_assign_order((int)$q['order_id']); } catch (Throwable $e) { error_log('Auto-assignment after quote failed: ' . $e->getMessage()); }
    try { quote_email_admin_confirmed((int)$q['id']); } catch (Throwable $e) { error_log('Quote confirmation email failed: ' . $e->getMessage()); }
    return [true, null];
}

/** Admin only: the money has arrived, so work starts (order moves to the Design stage). */
function quote_payment_received($orderId, $staffId) {
    return (bool)qt_q("UPDATE library_orders SET payment_status = 'received', payment_confirmed_at = NOW(), payment_confirmed_by = ?,
                         status = IF(status = 'new', 'design', status)
                       WHERE id = ? AND quotation_id IS NOT NULL AND payment_status <> 'received'", [(int)$staffId, (int)$orderId])->rowCount();
}

/** What the customer's tracking page should say about a call-back order (null = normal tracking). */
function quote_tracking_state(array $order) {
    if (!phase10_ready() || ($order['contact_preference'] ?? 'self') !== 'call') return null;
    if (!empty($order['quotation_id'])) return ($order['payment_status'] ?? 'pending') === 'received' ? null : 'confirmed';
    $q = quote_for_order($order['id']);
    return $q && in_array($q['status'], ['sent', 'viewed'], true) ? 'sent' : 'preparing';
}

// ---- Emails ------------------------------------------------------------------------------------
function quote_mail($to, $subject, $body) {
    $to = trim((string)$to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
    $headers = "From: Planzaa <noreply@planzaa.in>\r\nReply-To: info@planzaa.in\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}
function quote_floors_label($f) {
    return ['G' => 'ground floor only', 'G+1' => 'ground + 1 floor', 'G+2' => 'ground + 2 floors', 'G+3' => 'ground + 3 floors'][$f] ?? $f;
}
function quote_email_customer(array $q, array $order, array $design, $link) {
    $one = function ($s) { return trim(preg_replace('/[\r\n]+/', ' ', (string)$s)); };
    $changes = count(json_decode((string)$q['modifications'], true) ?: []);
    $body = "Hi " . $one($order['customer_name']) . ",\n\n"
        . "Thank you for speaking with us! Based on our call, here's your quotation for " . $one($design['name'])
        . ($design['design_code'] ? " (" . $design['design_code'] . ")" : '') . ".\n\n"
        . "View your quotation and confirm your order:\n" . $link . "\n\n"
        . "This link is valid for " . QUOTE_TTL_DAYS . " days.\n\n"
        . "Summary:\n"
        . "* Design: " . $one($design['name']) . " (" . (int)$design['plot_width'] . "×" . (int)$design['plot_length'] . " ft plot, " . (int)$design['bhk'] . " BHK, " . quote_floors_label($design['floors']) . ")\n"
        . "* Changes requested: " . ($changes ? $changes . ' change' . ($changes === 1 ? '' : 's') : 'none — the design as it is') . "\n"
        . "* Total: " . quote_money($q['total_price']) . ((int)$q['total_price_max'] > (int)$q['total_price'] ? ' – ' . quote_money($q['total_price_max']) . ' (estimate)' : '') . "\n"
        . "* Estimated delivery: " . (int)$q['estimated_delivery_days'] . " days\n\n"
        . "If you have any questions, call us at " . PLANZAA_PHONE . " or reply to this email.\n\n"
        . "— Planzaa Team\n";
    return quote_mail($q['customer_email'], 'Your house design quotation from Planzaa — ' . $one($design['name']), $body);
}
function quote_email_admin_confirmed($quoteId) {
    $r = qt_q("SELECT q.*, o.order_code, o.customer_name, o.customer_phone, o.id AS oid, d.name AS design_name, d.design_code, s.name AS staff_name
               FROM quotations q JOIN library_orders o ON o.id = q.order_id JOIN designs d ON d.id = q.design_id LEFT JOIN staff s ON s.id = o.assigned_to
               WHERE q.id = ?", [(int)$quoteId])->fetch();
    if (!$r) return false;
    $one = function ($s) { return trim(preg_replace('/[\r\n]+/', ' ', (string)$s)); };
    $site = rtrim((string)getSettingValue(getDB(), 'site_url', 'https://test.planzaa.in'), '/');
    $body = "A customer has confirmed their quotation.\n\n"
        . "Order: {$r['order_code']}\n"
        . "Customer: " . $one($r['customer_name']) . "\n"
        . "Phone: {$r['customer_phone']}\n"
        . "Design: " . $one($r['design_name']) . " (" . ($r['design_code'] ?: 'no code') . ")\n"
        . "Total: " . quote_money($r['total_price']) . ((int)$r['needs_manual_pricing'] ? ' (estimate — confirm the final price)' : '') . "\n"
        . "Assigned to: " . ($r['staff_name'] ?: 'nobody yet') . "\n\n"
        . "Next step: arrange payment with the customer, then click \"Confirm payment received\" on the order.\n"
        . "View in admin: {$site}/admin/?tab=orders&id=" . (int)$r['oid'] . "\n";
    return quote_mail(getSettingValue(getDB(), 'admin_notification_email', ''), 'Quotation confirmed — ' . $r['order_code'] . ' — ' . $one($r['customer_name']), $body);
}
