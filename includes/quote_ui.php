<?php
// Quotation HTML for the in-house and admin order pages. Only defines functions.
require_once __DIR__ . '/quotes.php';

function qui_date($ts, $time = false) {
    if (!$ts) return '';
    $t = strtotime($ts);
    return $t ? du_h(date($time ? 'j M Y, g:i a' : 'j M Y', $t)) : du_h($ts);
}

/** Active designs for the "Change design" search (by code or name, plus filters). */
function quote_design_search(array $in) {
    $where = ['is_active = 1'];
    $params = [];
    $text = trim((string)($in['qfind'] ?? ''));
    if ($text !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($text, 0, 100)) . '%';
        $where[] = '(design_code LIKE ? OR name LIKE ?)';
        $params[] = $like; $params[] = $like;
    }
    if (in_array((int)($in['qbhk'] ?? 0), [1, 2, 3, 4, 5], true)) { $where[] = 'bhk = ?'; $params[] = (int)$in['qbhk']; }
    if (in_array($in['qfloors'] ?? '', ['G', 'G+1', 'G+2', 'G+3'], true)) { $where[] = 'floors = ?'; $params[] = $in['qfloors']; }
    if (in_array($in['qfacing'] ?? '', ['East', 'West', 'North', 'South'], true)) { $where[] = 'facing = ?'; $params[] = $in['qfacing']; }
    return qt_q("SELECT id, design_code, name, plot_width, plot_length, bhk, floors, facing, base_price FROM designs WHERE "
        . implode(' AND ', $where) . " ORDER BY design_code IS NULL, design_code, name LIMIT 20", $params)->fetchAll();
}

/**
 * The quotation builder: design (with search), the change pickers (assets/quote-builder.js),
 * the price summary, notes and the send button.
 * $baseParams: the query params of this order page (e.g. tab + id); $urlFn(array) builds a URL from params.
 */
function render_quote_builder(array $order, array $design, $hidden, array $baseParams, callable $urlFn, $apiBase, $isOriginal) {
    $pageUrl = function (array $extra) use ($baseParams, $urlFn) { return $urlFn(array_merge($baseParams, $extra)); };
    $search = isset($_GET['qfind']) || isset($_GET['qbhk']) || isset($_GET['qfloors']) || isset($_GET['qfacing']);
    $results = $search ? quote_design_search($_GET) : [];
    $sel = function ($name, array $opts) {
        $cur = (string)($_GET[$name] ?? '');
        $h = '<select name="' . $name . '"><option value="">Any</option>';
        foreach ($opts as $v => $l) $h .= '<option value="' . du_h($v) . '"' . ($cur === (string)$v ? ' selected' : '') . '>' . du_h($l) . '</option>';
        return $h . '</select>';
    };
    $designJson = ['id' => (int)$design['id'], 'name' => $design['name'], 'base_price' => (int)$design['base_price'], 'delivery_days' => (int)$design['delivery_days'],
        'plot_width' => (int)$design['plot_width'], 'plot_length' => (int)$design['plot_length'], 'bhk' => (int)$design['bhk'], 'floors' => $design['floors'], 'facing' => $design['facing']];

    $h = '<section class="adm-card quote-builder" id="quote">'
        . '<h3>Prepare quotation</h3>'
        . '<p class="muted small-note">After the call, set up what the customer wants. The customer gets a private link to see the quotation and confirm it.</p>'
        // Step 1: design
        . '<div class="step-label"><span class="step-num">1</span><h4>Design</h4></div>'
        . '<div class="qb-design"><div><strong>' . du_h($design['name']) . '</strong> <span class="code-cell">' . du_h($design['design_code'] ?: '#' . $design['id']) . '</span>'
        . ($isOriginal ? ' <span class="badge badge-neutral">The design they asked about</span>' : ' <span class="badge b-blue">Changed for this quotation</span>')
        . '<div class="muted">' . (int)$design['plot_width'] . '×' . (int)$design['plot_length'] . ' ft · ' . du_h($design['facing']) . ' facing · ' . (int)$design['bhk'] . ' BHK · ' . du_h($design['floors'])
        . ' · Design price ' . du_h(quote_money($design['base_price'])) . '</div></div>'
        . (!$isOriginal ? '<a class="btn btn-small" href="' . du_h($pageUrl([])) . '#quote">Back to the original design</a>' : '')
        . '</div>'
        . '<details class="qb-search"' . ($search ? ' open' : '') . '><summary>Change design</summary>'
        . '<form method="get" class="qb-search-form" action="#quote">';
    foreach ($baseParams as $k => $v) $h .= '<input type="hidden" name="' . du_h($k) . '" value="' . du_h($v) . '">';
    $h .= '<label>Code or name<input name="qfind" maxlength="100" value="' . du_h($_GET['qfind'] ?? '') . '" placeholder="PZ-E-G1-3B-0001 or a name"></label>'
        . '<label>BHK' . $sel('qbhk', [1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5+']) . '</label>'
        . '<label>Floors' . $sel('qfloors', ['G' => 'Ground only', 'G+1' => 'G+1', 'G+2' => 'G+2', 'G+3' => 'G+3']) . '</label>'
        . '<label>Facing' . $sel('qfacing', ['East' => 'East', 'West' => 'West', 'North' => 'North', 'South' => 'South']) . '</label>'
        . '<button class="btn" type="submit">Search</button></form>';
    if ($search) {
        if (!$results) $h .= '<p class="empty">No designs match. Try fewer filters.</p>';
        else {
            $h .= '<ul class="qb-results">';
            foreach ($results as $r) {
                $h .= '<li><span class="code-cell">' . du_h($r['design_code'] ?: '#' . $r['id']) . '</span><strong>' . du_h($r['name']) . '</strong>'
                    . '<span class="muted">' . (int)$r['plot_width'] . '×' . (int)$r['plot_length'] . ' ft · ' . du_h($r['facing']) . ' · ' . (int)$r['bhk'] . ' BHK · ' . du_h($r['floors']) . '</span>'
                    . '<span class="num">' . du_h(quote_money($r['base_price'])) . '</span>'
                    . ((int)$r['id'] === (int)$design['id'] ? '<span class="badge b-green">Selected</span>' : '<a class="btn btn-small" href="' . du_h($pageUrl(['qdesign' => (int)$r['id']])) . '#quote">Use this design</a>') . '</li>';
            }
            $h .= '</ul>';
        }
    }
    $h .= '</details>'
        // Steps 2-3 (JS) + summary + send form
        . '<div class="config-layout qb-layout">'
        . '<div id="quoteBuilder" data-api="' . du_h($apiBase) . '" data-order="' . (int)$order['id'] . '" data-design="' . du_h(json_encode($designJson)) . '"><p class="muted">Loading the list of changes&#8230;</p></div>'
        . '<aside class="summary qb-summary"><h3>Quotation</h3><div id="qbSummary" aria-live="polite"></div>'
        . '<form method="post" id="quoteForm" class="qb-form" data-saving>' . $hidden
        . '<input type="hidden" name="action" value="quote_send"><input type="hidden" name="order_id" value="' . (int)$order['id'] . '">'
        . '<input type="hidden" name="design_id" value="' . (int)$design['id'] . '"><input type="hidden" name="config" value="">'
        . '<label for="qNotes">Notes for customer <span class="muted">(shown on the quotation)</span></label>'
        . '<textarea id="qNotes" name="notes_for_customer" rows="4" maxlength="3000" placeholder="As discussed on the call, we\'ll move the kitchen to the north side and add an attached bathroom to bedroom 2."></textarea>'
        . '<label for="qInternal">Notes for your team <span class="muted">(the customer never sees these)</span></label>'
        . '<textarea id="qInternal" name="internal_notes" rows="3" maxlength="3000" placeholder="Anything the team should know about this order"></textarea>'
        . '<label for="qEmail">Customer\'s email <span class="muted">(optional — to email them the link)</span></label>'
        . '<input id="qEmail" name="customer_email" type="email" maxlength="150" placeholder="name@gmail.com">'
        . '<p class="muted small-note">You will also get the link to send on WhatsApp or SMS to ' . du_h($order['customer_phone']) . '.</p>'
        . '<button class="btn btn-send" type="submit" id="qbSend">Send quotation to customer</button>'
        . '</form></aside></div></section>';
    return $h;
}

/**
 * Status of the order's quotation. $canManage: may resend/cancel. $isAdmin: may confirm payment.
 * $link: the customer link right after sending/resending (shown once; it is never stored).
 */
function render_quote_status(array $q, array $order, $hidden, $canManage, $isAdmin, $previewUrl, $link = null) {
    $st = $q['status'];
    $h = '<section class="adm-card quote-status status-' . du_h($st) . '" id="quote"><div class="qs-head"><h3>Quotation</h3>' . quote_status_badge($st) . '</div>';
    $h .= '<p>Sent on <strong>' . qui_date($q['sent_at'], true) . '</strong>' . ($q['created_by_name'] ?? '' ? ' by ' . du_h($q['created_by_name']) : '')
        . ' &#183; ' . du_h(quote_money($q['total_price'])) . ((int)$q['total_price_max'] > (int)$q['total_price'] ? '–' . du_h(quote_money($q['total_price_max'])) : '')
        . ' &#183; ready in ' . (int)$q['estimated_delivery_days'] . ' days</p>';
    if ($st === 'sent') $h .= '<p class="muted">The customer has not opened it yet. It works until ' . qui_date($q['expires_at']) . '.</p>';
    if ($st === 'viewed') $h .= '<p class="muted">The customer opened it on ' . qui_date($q['viewed_at'], true) . '. It works until ' . qui_date($q['expires_at']) . '.</p>';
    if ($st === 'expired') $h .= '<p class="overload-warn">This quotation expired on ' . qui_date($q['expires_at']) . '. Resend it to give the customer a new link for 7 more days.</p>';
    if ($st === 'confirmed') {
        if (($order['payment_status'] ?? 'pending') === 'received') {
            $h .= '<p class="assign-reason">Payment received on ' . qui_date($order['payment_confirmed_at']) . '. Order is active.</p>';
        } else {
            $h .= '<p class="assign-reason">Customer confirmed on ' . qui_date($q['confirmed_at'], true) . '. Awaiting payment.</p>';
            if ($isAdmin) {
                $h .= '<form method="post" data-saving data-confirm="Confirm that the payment for this order has been received? The order moves to the Design stage.">' . $hidden
                    . '<input type="hidden" name="action" value="payment_confirm"><input type="hidden" name="order_id" value="' . (int)$order['id'] . '">'
                    . '<button class="btn btn-send" type="submit">Confirm payment received</button></form>';
            } else {
                $h .= '<p class="muted small-note">The admin confirms the payment. After that the order moves to the Design stage.</p>';
            }
        }
    }
    if ($link) {
        $wa = 'https://wa.me/91' . preg_replace('/\D/', '', $order['customer_phone']) . '?text=' . rawurlencode('Hi ' . $order['customer_name'] . ', here is your house design quotation from Planzaa. Please open it to see the details and confirm: ' . $link);
        $h .= '<div class="qs-link"><label for="qsLink">Customer link (shown only now — copy it or send it on WhatsApp)</label>'
            . '<div class="qs-link-row"><input id="qsLink" readonly value="' . du_h($link) . '" onclick="this.select()">'
            . '<button type="button" class="btn btn-small" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById(\'qsLink\').value);this.textContent=\'Copied\'">Copy</button>'
            . '<a class="btn btn-small" href="' . du_h($wa) . '" target="_blank" rel="noopener">Send on WhatsApp</a></div></div>';
    }
    if (!empty($q['notes_for_customer'])) $h .= '<p class="note-text"><span class="muted">Notes for the customer:</span> ' . nl2br(du_h($q['notes_for_customer'])) . '</p>';
    if (!empty($q['internal_notes'])) $h .= '<p class="note-text"><span class="muted">Team notes:</span> ' . nl2br(du_h($q['internal_notes'])) . '</p>';
    $h .= '<div class="adm-actions left qs-actions"><a class="btn btn-small" href="' . du_h($previewUrl) . '" target="_blank" rel="noopener">View quotation</a>';
    if ($canManage && in_array($st, ['sent', 'viewed', 'expired'], true)) {
        $h .= '<form method="post" data-saving>' . $hidden . '<input type="hidden" name="action" value="quote_resend"><input type="hidden" name="quote_id" value="' . (int)$q['id'] . '"><input type="hidden" name="order_id" value="' . (int)$order['id'] . '">'
            . '<button class="btn btn-small" type="submit">Resend quotation</button></form>'
            . '<form method="post" data-saving data-confirm="Cancel this quotation? The customer\'s link stops working. You can then prepare a new one.">' . $hidden
            . '<input type="hidden" name="action" value="quote_cancel"><input type="hidden" name="quote_id" value="' . (int)$q['id'] . '"><input type="hidden" name="order_id" value="' . (int)$order['id'] . '">'
            . '<button class="btn btn-small btn-danger-ghost" type="submit">Cancel quotation</button></form>';
    }
    return $h . '</div></section>';
}
