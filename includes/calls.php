<?php
// Call queue for call-back orders: status, notes, the "call request" email to the assigned team
// member, transfers, and the HTML for the in-house and admin dashboards.
// Loaded by includes/design_utils.php (so auto-assignment can start a call request). Only defines functions.

const CALL_OVERDUE_MINUTES = 240; // a request waiting longer than 4 hours is shown in red
const CALL_STATUS_LABEL = ['pending' => 'Waiting for a call', 'in_progress' => 'Follow up needed', 'contacted' => 'Called — preparing quotation', 'completed' => 'Customer decided not to proceed'];

function phase10b_ready() {
    static $ready = null;
    if ($ready === null) {
        $cols = du_q("SHOW COLUMNS FROM library_orders")->fetchAll(PDO::FETCH_COLUMN);
        $ready = !array_diff(['call_status', 'called_at', 'called_by', 'call_notes'], $cols);
    }
    return $ready;
}

/** "just now" / "25 minutes ago" / "2 hours ago" / "yesterday" / "3 days ago" (from minutes). */
function call_ago($minutes) {
    $m = max(0, (int)$minutes);
    if ($m < 1) return 'just now';
    if ($m < 60) return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
    $h = intdiv($m, 60);
    if ($h < 24) return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    $d = intdiv($h, 24);
    return $d === 1 ? 'yesterday' : $d . ' days ago';
}
function call_phone_pretty($p) { $p = preg_replace('/\D/', '', (string)$p); return strlen($p) === 10 ? substr($p, 0, 5) . ' ' . substr($p, 5) : $p; }

// ---- Notes (JSON list, newest last in storage) ------------------------------------------------
function call_notes_list(array $order) {
    $list = json_decode((string)($order['call_notes'] ?? ''), true);
    return is_array($list) ? array_reverse($list) : []; // newest first for display
}
function call_note_append($orderId, $staffId, $staffName, $note, $outcome = null) {
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $cur = du_q("SELECT call_notes FROM library_orders WHERE id = ? FOR UPDATE", [(int)$orderId])->fetchColumn();
        $list = json_decode((string)$cur, true);
        if (!is_array($list)) $list = [];
        $entry = ['staff_id' => (int)$staffId, 'staff_name' => (string)$staffName, 'note' => (string)$note, 'timestamp' => date('Y-m-d H:i:s')];
        if ($outcome) $entry['outcome'] = $outcome;
        $list[] = $entry;
        du_q("UPDATE library_orders SET call_notes = ? WHERE id = ?", [json_encode($list, JSON_UNESCAPED_UNICODE), (int)$orderId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---- Starting a call request (from auto-assignment) --------------------------------------------
/** A new call-back order: mark it 'pending' and email the person it was given to. Runs once per order. */
function call_request_start($orderId) {
    if (!phase10b_ready()) return;
    $st = du_q("UPDATE library_orders SET call_status = 'pending'
                WHERE id = ? AND contact_preference = 'call' AND call_status IS NULL AND quotation_id IS NULL", [(int)$orderId]);
    if ($st->rowCount()) {
        try { call_email_staff($orderId); } catch (Throwable $e) { error_log('Call request email failed for order ' . $orderId . ': ' . $e->getMessage()); }
    }
}

/** "Call request — ..." email to the team member the order is assigned to. */
function call_email_staff($orderId) {
    $o = du_q("SELECT o.*, d.name AS design_name, d.design_code, s.name AS staff_name, s.email AS staff_email
               FROM library_orders o JOIN designs d ON d.id = o.design_id LEFT JOIN staff s ON s.id = o.assigned_to WHERE o.id = ?", [(int)$orderId])->fetch();
    if (!$o || empty($o['staff_email']) || !filter_var($o['staff_email'], FILTER_VALIDATE_EMAIL)) return false;
    $one = function ($s) { return trim(preg_replace('/[\r\n]+/', ' ', (string)$s)); };
    $site = rtrim((string)getSettingValue(getDB(), 'site_url', 'https://test.planzaa.in'), '/');
    $body = "Hi " . $one($o['staff_name']) . ",\n\n"
        . "A customer has requested a call to discuss design changes.\n\n"
        . "Customer: " . $one($o['customer_name']) . "\n"
        . "Phone: " . call_phone_pretty($o['customer_phone']) . "\n"
        . "Design: " . $one($o['design_name']) . " (" . ($o['design_code'] ?: 'no code') . ")\n"
        . "Their notes: " . (trim((string)$o['callback_notes']) !== '' ? $one($o['callback_notes']) : 'No notes') . "\n\n"
        . "Please call them as soon as possible and prepare their quotation in your dashboard:\n"
        . $site . "/inhouse/index.php?tab=orders&id=" . (int)$o['id'] . "\n\n"
        . "— Planzaa\n";
    $subject = trim(preg_replace('/[\r\n]+/', ' ', 'Call request — ' . $o['customer_name'] . ' wants to discuss ' . $o['design_name']));
    $headers = "From: Planzaa <noreply@planzaa.in>\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($o['staff_email'], '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

// ---- After a call ------------------------------------------------------------------------------
/**
 * "I've called them": saves the note and the next step.
 *   quote    -> contacted (they prepare the quotation now)
 *   later    -> in_progress (the card stays as "Follow up needed")
 *   declined -> completed, and the order is closed (stage Delivered); open quotations are cancelled
 * Returns null or an error message.
 */
function call_log(array $order, $staffId, $staffName, $note, $next) {
    if (($order['contact_preference'] ?? '') !== 'call') return 'This is not a call-back order.';
    if (!in_array($next, ['quote', 'later', 'declined'], true)) return 'Please choose the next step.';
    $note = trim((string)$note);
    if ($note === '') return 'Please write a few words about how the call went.';
    if (mb_strlen($note) > 3000) return 'Please keep the notes shorter.';
    if (in_array($order['call_status'], ['completed'], true) || $order['status'] === 'delivered') return 'This order is already closed.';
    $status = ['quote' => 'contacted', 'later' => 'in_progress', 'declined' => 'completed'][$next];
    call_note_append($order['id'], $staffId, $staffName, $note, $status);
    du_q("UPDATE library_orders SET call_status = ?, called_at = NOW(), called_by = ?" . ($next === 'declined' ? ", status = 'delivered'" : '') . " WHERE id = ?",
        [$status, (int)$staffId, (int)$order['id']]);
    if ($next === 'declined' && function_exists('phase10_ready') && phase10_ready()) {
        du_q("UPDATE quotations SET status = 'cancelled' WHERE order_id = ? AND status IN ('draft', 'sent', 'viewed', 'expired')", [(int)$order['id']]);
    }
    return null;
}

/** Gives the order to another team member (and emails them if the customer still needs a call). */
function call_transfer(array $order, $toStaffId, $byStaffId, $byName) {
    $to = du_q("SELECT id, name FROM staff WHERE id = ?", [(int)$toStaffId])->fetch();
    if (!$to) return 'Please choose who should take this call.';
    if ((int)$to['id'] === (int)$order['assigned_to']) return 'This order is already with ' . $to['name'] . '.';
    du_q("UPDATE library_orders SET assigned_to = ?, assigned_at = NOW(), auto_assigned = 0, overload_warning = 0, assignment_reason = ? WHERE id = ?",
        [(int)$to['id'], 'Transferred to ' . $to['name'] . ' by ' . $byName . ' on ' . date('j M Y') . '.', (int)$order['id']]);
    call_note_append($order['id'], $byStaffId, $byName, 'Transferred this call to ' . $to['name'] . '.', 'transferred');
    if (in_array($order['call_status'], ['pending', 'in_progress'], true)) {
        try { call_email_staff($order['id']); } catch (Throwable $e) { error_log('Call transfer email failed: ' . $e->getMessage()); }
    }
    return null;
}

/** Orders waiting for a call (or a follow-up), oldest first. $staffId = null: everyone's. */
function call_queue($staffId = null, array $statuses = ['pending', 'in_progress']) {
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $params = $statuses;
    $mine = '';
    if ($staffId !== null) { $mine = ' AND o.assigned_to = ?'; $params[] = (int)$staffId; }
    return du_q("SELECT o.*, d.name AS design_name, d.design_code, s.name AS staff_name,
                        TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS waited_min,
                        TIMESTAMPDIFF(MINUTE, o.called_at, NOW()) AS since_call_min
                 FROM library_orders o JOIN designs d ON d.id = o.design_id LEFT JOIN staff s ON s.id = o.assigned_to
                 WHERE o.call_status IN ($in) AND o.status <> 'delivered'$mine
                 ORDER BY (o.call_status = 'pending') DESC, o.created_at", $params)->fetchAll();
}

// ---- HTML ----------------------------------------------------------------------------------------
function call_icon($name) {
    $p = ['phone' => '<path d="M5 4h3l2 5-2.5 1.5a11 11 0 005 5L14 13l5 2v3a2 2 0 01-2 2A15 15 0 013 6a2 2 0 012-2"/>',
          'warn' => '<path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18h.01"/>'][$name] ?? '';
    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}
function call_tel($phone, $cls = 'call-tel') {
    $d = preg_replace('/\D/', '', (string)$phone);
    return '<a class="' . $cls . '" href="tel:+91' . du_h($d) . '">' . call_icon('phone') . '<span>' . du_h(call_phone_pretty($d)) . '</span></a>';
}
function call_when($ts) { $t = strtotime((string)$ts); return $t ? du_h(date('j M Y, g:i a', $t)) : ''; }

/** The coloured strip at the top of a call-back order. */
function render_call_banner(array $o) {
    $s = $o['call_status'] ?? null;
    if (!$s || ($o['contact_preference'] ?? '') !== 'call') return '';
    if ($s === 'pending') return '<div class="call-banner pending">' . call_icon('phone') . '<span><strong>This customer is waiting for your call</strong> &#8212; ' . call_tel($o['customer_phone'], 'call-tel inline') . '</span></div>';
    if ($s === 'in_progress') return '<div class="call-banner followup">' . call_icon('phone') . '<span><strong>Follow up needed</strong> &#8212; last called ' . call_when($o['called_at']) . ' &#183; ' . call_tel($o['customer_phone'], 'call-tel inline') . '</span></div>';
    if ($s === 'contacted') return '<div class="call-banner done">' . call_icon('phone') . '<span><strong>Called on ' . call_when($o['called_at']) . '</strong> &#8212; preparing quotation</span></div>';
    return '<div class="call-banner closed"><span><strong>Customer decided not to proceed</strong>' . ($o['called_at'] ? ' &#183; ' . call_when($o['called_at']) : '') . '</span></div>';
}

/** "I've called them" form: notes + next step. $id makes the field ids unique on a page with several cards. */
function render_call_log_form(array $o, $hidden, $id) {
    $opt = function ($value, $title, $sub) use ($id) {
        return '<label class="next-opt"><input type="radio" name="next" value="' . $value . '" required>'
            . '<span class="next-card"><strong>' . $title . '</strong><small>' . $sub . '</small></span></label>';
    };
    return '<form method="post" class="call-log-form" data-saving data-call-form>' . $hidden
        . '<input type="hidden" name="action" value="call_log"><input type="hidden" name="order_id" value="' . (int)$o['id'] . '">'
        . '<label class="call-q" for="callNote' . $id . '">How did the call go?</label>'
        . '<textarea id="callNote' . $id . '" name="note" rows="5" maxlength="3000" required placeholder="For example: Customer wants to add one bedroom on the first floor, make the kitchen bigger, and change the elevation to modern style. She prefers light grey and white colour scheme. Budget is around ₹80,000 including structural."></textarea>'
        . '<fieldset class="next-step"><legend class="call-q">What\'s the next step?</legend>'
        . $opt('quote', 'I\'ll prepare the quotation now', 'Takes you to the quotation builder for this order.')
        . $opt('later', 'I need to call them again later', 'The card stays on your dashboard as "Follow up needed".')
        . $opt('declined', 'Customer decided not to proceed', 'Closes the order.')
        . '</fieldset>'
        . '<div class="adm-actions left"><button class="btn btn-primary" type="submit">Save</button></div></form>';
}

function render_call_transfer_form(array $o, $hidden, array $staff, $myId) {
    $opts = '';
    foreach ($staff as $s) if ((int)$s['id'] !== (int)$o['assigned_to']) $opts .= '<option value="' . (int)$s['id'] . '">' . du_h($s['name']) . ($s['role'] === 'admin' ? ' (admin)' : '') . '</option>';
    if ($opts === '') return '<p class="muted small-note">There is nobody else on the team to transfer to.</p>';
    return '<form method="post" class="call-transfer-form" data-saving>' . $hidden
        . '<input type="hidden" name="action" value="call_transfer"><input type="hidden" name="order_id" value="' . (int)$o['id'] . '">'
        . '<label>Give this call to<select name="to_staff" required><option value="">Choose a team member</option>' . $opts . '</select></label>'
        . '<button class="btn" type="submit">Transfer</button></form>';
}

/** One card in "Customers waiting for your call" (in-house dashboard). */
function render_call_card(array $o, $hidden, array $staff, $myId, callable $orderUrl) {
    $pending = $o['call_status'] === 'pending';
    $overdue = $pending && (int)$o['waited_min'] > CALL_OVERDUE_MINUTES;
    $h = '<article class="call-card' . ($overdue ? ' overdue' : '') . ($pending ? '' : ' followup') . '" id="call' . (int)$o['id'] . '">'
        . '<div class="call-top"><span class="call-flag">' . call_icon('phone') . ($pending ? 'CALL NOW' : 'Follow up needed') . '</span>'
        . '<span class="call-age' . ($overdue ? ' late' : '') . '">' . ($overdue ? call_icon('warn') : '')
        . 'Requested ' . du_h(call_ago($o['waited_min'])) . (!$pending && $o['called_at'] ? ' &#183; last called ' . du_h(call_ago($o['since_call_min'])) : '') . '</span></div>'
        . '<h3 class="call-name">' . du_h($o['customer_name']) . '</h3>'
        . call_tel($o['customer_phone'])
        . '<p class="call-design">' . du_h($o['design_name']) . ($o['design_code'] ? ' <span class="code-cell">' . du_h($o['design_code']) . '</span>' : '')
        . ' &#183; <a href="' . du_h($orderUrl($o['id'])) . '">Open order ' . du_h($o['order_code']) . '</a></p>';
    if (trim((string)$o['callback_notes']) !== '') $h .= '<blockquote class="call-cnotes"><span>Their notes</span>' . nl2br(du_h($o['callback_notes'])) . '</blockquote>';
    $last = call_notes_list($o);
    if ($last) $h .= '<p class="call-last"><span class="muted">Last note (' . du_h($last[0]['staff_name']) . '):</span> ' . du_h(mb_strimwidth($last[0]['note'], 0, 180, '…')) . '</p>';
    $h .= '<div class="call-actions">'
        . '<details class="call-do"><summary class="btn btn-primary">I\'ve called them</summary>' . render_call_log_form($o, $hidden, (int)$o['id']) . '</details>'
        . '<details class="call-move"><summary class="btn">Transfer to someone else</summary>' . render_call_transfer_form($o, $hidden, $staff, $myId) . '</details>'
        . '</div></article>';
    return $h;
}

/** Call notes timeline (newest first) + "Add notes"; plus the "I've called them" form while a call is due. */
function render_call_panel(array $o, $hidden, $canLog, $logLabel = 'I\'ve called them') {
    $notes = call_notes_list($o);
    $h = '<section class="adm-card call-panel" id="callNotes"><h3>Call notes</h3>';
    if (!$notes) $h .= '<p class="empty">No call notes yet.</p>';
    else {
        $h .= '<ol class="call-timeline">';
        foreach ($notes as $n) {
            $h .= '<li><div class="ct-meta"><strong>' . du_h($n['staff_name'] ?? '') . '</strong> &#8212; ' . call_when($n['timestamp'] ?? '')
                . (!empty($n['outcome']) && isset(CALL_STATUS_LABEL[$n['outcome']]) ? ' <span class="badge badge-neutral">' . du_h(CALL_STATUS_LABEL[$n['outcome']]) . '</span>' : '')
                . '</div><div class="ct-note">' . nl2br(du_h($n['note'] ?? '')) . '</div></li>';
        }
        $h .= '</ol>';
    }
    $h .= '<div class="call-actions">';
    if ($canLog && in_array($o['call_status'], ['pending', 'in_progress'], true) && $o['status'] !== 'delivered') {
        $h .= '<details class="call-do"><summary class="btn btn-primary">' . du_h($logLabel) . '</summary>' . render_call_log_form($o, $hidden, 'P' . (int)$o['id']) . '</details>';
    }
    $h .= '<details class="call-add"><summary class="btn">Add notes</summary>'
        . '<form method="post" class="call-note-form" data-saving>' . $hidden
        . '<input type="hidden" name="action" value="call_note"><input type="hidden" name="order_id" value="' . (int)$o['id'] . '">'
        . '<label class="call-q" for="callAdd' . (int)$o['id'] . '">Add to the call notes</label>'
        . '<textarea id="callAdd' . (int)$o['id'] . '" name="note" rows="4" maxlength="3000" required></textarea>'
        . '<div class="adm-actions left"><button class="btn btn-primary" type="submit">Save note</button></div></form></details>'
        . '</div></section>';
    return $h;
}

/** Checks for the "call_note" action (both dashboards). Returns null or an error. */
function call_add_note(array $order, $staffId, $staffName, $note) {
    $note = trim((string)$note);
    if ($note === '') return 'Please write the note first.';
    if (mb_strlen($note) > 3000) return 'Please keep the note shorter.';
    call_note_append($order['id'], $staffId, $staffName, $note);
    return null;
}
