<?php
// Shared helpers for the in-house and designer (freelancer) dashboards: layout, CSRF,
// flash messages, badges, deadlines and the compact brief-parameter view.
// Only defines things; outputs nothing if opened directly.

require_once __DIR__ . '/brief_ui.php'; // similarity engine, brief form, publishing

const DASH_STAGES = ['new', 'design', 'structural', 'compliance', 'delivered'];
const DASH_STAGE_LABEL = ['new' => 'New', 'design' => 'Design', 'structural' => 'Structural', 'compliance' => 'Compliance', 'delivered' => 'Delivered'];
const DASH_BRIEF_STATUS = ['open' => 'Open', 'claimed' => 'Claimed', 'in_review' => 'In review', 'needs_revision' => 'Needs changes', 'approved' => 'Approved', 'published' => 'Published'];
const DASH_ROOM_KINDS = ['resize', 'partition', 'washroom', 'opening', 'relabel'];
// Notes the customer configurator stores for "let our architect decide" (assets/app.js).
const DASH_ARCHITECT_NOTE = 'Customer requested architect to decide';
const DASH_COLOUR_ARCHITECT_NOTE = 'Architect to suggest colour scheme';

function dh($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function dash_inr($n) {
    $n = (int)round($n);
    $s = (string)abs($n);
    if (strlen($s) > 3) $s = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($s, 0, -3)) . ',' . substr($s, -3);
    return ($n < 0 ? '-' : '') . '&#8377;' . $s;
}

function dash_date($ts, $withTime = false) {
    if (!$ts) return '&#8212;';
    $t = strtotime($ts);
    return $t ? dh(date($withTime ? 'j M Y, g:i a' : 'j M Y', $t)) : dh($ts);
}

// ---- CSRF (same pattern as the admin: one token per session, checked on every POST) ----
function dash_csrf_token() {
    if (empty($_SESSION['dash_csrf'])) $_SESSION['dash_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['dash_csrf'];
}
function dash_csrf_field() { return '<input type="hidden" name="csrf" value="' . dh(dash_csrf_token()) . '">'; }
function dash_csrf_check() {
    if (!hash_equals($_SESSION['dash_csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('This form has expired. Please go back, reload the page and try again.');
    }
}

function dash_flash($msg, $type = 'ok') { $_SESSION['dash_flash'] = [$type, $msg]; }
function dash_take_flash() { $f = $_SESSION['dash_flash'] ?? null; unset($_SESSION['dash_flash']); return $f; }
function dash_url(array $params) {
    $params = array_filter($params, function ($v) { return $v !== null && $v !== ''; });
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}
function dash_redirect(array $params) { header('Location: ' . dash_url($params)); exit; }

// Database changes these dashboards need. Empty array = ready.
function dash_setup_problems() {
    $tables = sim_q("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $problems = [];
    foreach (['modifications' => 'schema-phase3.sql', 'design_rooms' => 'schema-phase3.sql (Phase 3b)', 'order_modification_details' => 'schema-phase3.sql (Phase 3b)'] as $t => $file) {
        if (!in_array($t, $tables, true)) $problems[] = "Table $t is missing &#8212; run $file.";
    }
    if (!phase5_ready()) $problems[] = 'Design parameter columns are missing &#8212; run schema-phase5.sql.';
    if (!dash_has_preview_column()) $problems[] = 'The preview image column is missing &#8212; run schema-phase6.sql.';
    if (!phase7_ready()) $problems[] = 'The design code and file tables are missing &#8212; run schema-phase7.sql.';
    return $problems;
}
function dash_has_preview_column() {
    static $has = null;
    if ($has === null) $has = in_array('preview_path', sim_q("SHOW COLUMNS FROM submissions")->fetchAll(PDO::FETCH_COLUMN), true);
    return $has;
}

// ---- Badges ---------------------------------------------------------------------------
function dash_stage_badge($s) {
    $cls = ['new' => 'b-amber', 'delivered' => 'b-green'][$s] ?? 'b-blue';
    return '<span class="badge ' . $cls . '">' . dh(DASH_STAGE_LABEL[$s] ?? $s) . '</span>';
}
function dash_brief_badge($s) {
    $cls = ['open' => 'b-green', 'claimed' => 'b-blue', 'in_review' => 'b-amber', 'needs_revision' => 'b-rust', 'approved' => 'b-green', 'published' => 'badge-neutral'][$s] ?? 'badge-neutral';
    return '<span class="badge ' . $cls . '">' . dh(DASH_BRIEF_STATUS[$s] ?? $s) . '</span>';
}
function dash_review_badge(array $sub) {
    if (!empty($sub['published'])) return '<span class="badge b-green">&#9733; Published</span>';
    if ($sub['review_status'] === 'pending') return '<span class="badge b-amber">Pending review</span>';
    if ($sub['review_status'] === 'rejected') return '<span class="badge b-rust">Needs changes</span>';
    return '<span class="badge b-green">Approved</span>';
}
function dash_order_type(array $o) {
    if (($o['contact_preference'] ?? 'self') === 'call') return 'call';
    $m = trim((string)($o['modifications'] ?? ''));
    return ($m !== '' && $m !== '[]') ? 'modified' : 'asis';
}
function dash_type_badge(array $o) {
    $t = dash_order_type($o);
    if ($t === 'call') return '<span class="badge b-amber">Call-back</span>';
    return $t === 'modified' ? '<span class="badge b-blue">Modified</span>' : '<span class="badge badge-neutral">As-is</span>';
}

// "5 days left" / "Due tomorrow!" / "Overdue — please submit soon" with a colour tone.
function dash_deadline($date) {
    $days = (int)floor((strtotime($date . ' 00:00:00') - strtotime(date('Y-m-d') . ' 00:00:00')) / 86400);
    if ($days < 0) return ['Overdue &#8212; please submit soon', 'late', $days];
    if ($days === 0) return ['Due today!', 'late', $days];
    if ($days === 1) return ['Due tomorrow!', 'late', $days];
    return [$days . ' days left', $days <= 4 ? 'soon' : 'ok', $days];
}
function dash_deadline_badge($date) {
    [$label, $tone] = dash_deadline($date);
    return '<span class="due due-' . $tone . '" title="Due ' . dash_date($date) . '">' . $label . '</span>';
}

function dash_svg($name) {
    $p = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'orders' => '<path d="M6 3h12l1 18H5z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
        'review' => '<path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 01-2 2H6a2 2 0 01-2-2V5a2 2 0 012-2h9"/>',
        'standardize' => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-5h4v5"/>',
        'postbrief' => '<path d="M12 5v14M5 12h14"/>',
        'briefs' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'open' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
        'work' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13 7l4 4"/>',
        'submissions' => '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v4h16v-4"/>',
        'earnings' => '<path d="M7 5h10M7 9h10M9 5c4 0 5 2 5 4s-1 4-5 4H7l8 7"/>',
        'phone' => '<path d="M5 4h3l2 5-2.5 1.5a11 11 0 005 5L14 13l5 2v3a2 2 0 01-2 2A15 15 0 013 6a2 2 0 012-2"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'expert' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ][$name] ?? '';
    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function dash_stepper($status) {
    $idx = array_search($status, DASH_STAGES, true);
    $html = '<ol class="tstepper" aria-label="Order progress">';
    foreach (DASH_STAGES as $i => $s) {
        $state = ($status === 'delivered' || $i < $idx) ? 'done' : ($i === $idx ? 'current' : '');
        $html .= '<li class="tstep ' . $state . '"><span class="node">' . ($state === 'done' ? dash_svg('check') : '') . '</span><span>' . dh(DASH_STAGE_LABEL[$s]) . '</span></li>';
    }
    return $html . '</ol>';
}

// One room-level line of a customer's change (same wording as the admin order page).
function dash_detail_text(array $d, $kind) {
    $note = (string)($d['custom_note'] ?? '');
    if ($note === DASH_ARCHITECT_NOTE) return '<span class="arch-note">' . dash_svg('expert') . 'Our architect will decide</span>';
    if ($note === DASH_COLOUR_ARCHITECT_NOTE) return '<span class="arch-note">' . dash_svg('expert') . 'Our architect will suggest colours</span>';
    if ($kind === 'colour') return 'Colours: ' . dh($note);
    $what = $kind === 'resize' ? ($d['action'] === 'increase' ? 'make it bigger' : ($d['action'] === 'decrease' ? 'make it smaller' : $note))
        : ($kind === 'washroom' ? 'add an attached bathroom' : $note);
    return !empty($d['room_name']) ? '<strong>' . dh($d['room_name']) . '</strong> &#8212; ' . dh($what) : dh($what);
}

/**
 * All 24 design parameters of a brief (or design), grouped into short scannable rows,
 * plus the special features as small badges.
 */
function dash_brief_facts(array $b) {
    $row = function ($title, $tone, array $items) {
        $html = '<div class="facts-row"><span class="facts-title"><i class="dot ' . $tone . '"></i>' . dh($title) . '</span><ul>';
        foreach ($items as [$label, $value]) {
            $empty = $value === null || $value === '' || $value === '—';
            $html .= '<li' . ($empty ? ' class="unset"' : '') . '><span>' . dh($label) . '</span>' . ($empty ? 'Not given' : dh($value)) . '</li>';
        }
        return $html . '</ul></div>';
    };
    $v = function ($k) use ($b) { return param_label($k, $b[$k] ?? null); };
    $plot = (int)($b['plot_width'] ?? 0) ? (int)$b['plot_width'] . ' × ' . (int)$b['plot_length'] . ' ft' : '—';
    $html = '<div class="facts">'
        . $row('Plot', 'd-plot', [
            ['Size', $plot], ['Shape', $v('plot_shape')], ['Facing', ($b['facing'] ?? '') ?: '—'], ['Corner plot', $v('is_corner_plot')],
        ])
        . $row('House', 'd-house', [
            ['Floors', ($b['floors'] ?? '') ?: '—'], ['Bedrooms', !empty($b['bhk']) ? (int)$b['bhk'] . ' BHK' : '—'],
            ['Built-up', $v('built_up_area_sqft')], ['Ground coverage', $v('ground_coverage_pct')],
            ['Parking', !empty($b['stilt_parking']) ? 'Stilt (under the house)' : 'Ground floor'],
        ])
        . $row('Layout', 'd-layout', [
            ['Main door', $v('entrance_position')], ['Stairs', $v('staircase_position')], ['Kitchen', $v('kitchen_layout')],
            ['Pooja room', $v('pooja_room')], ['Balcony', $v('balcony_config')],
        ])
        . $row('Look', 'd-look', [
            ['Style', $v('elevation_style')], ['Material', $v('elevation_material')], ['Roof', $v('roof_type')],
            ['For', $v('target_segment')], ['Vastu', $v('vastu_level')],
        ]);
    $features = [];
    foreach (['has_courtyard' => 'Courtyard', 'has_home_office' => 'Home office', 'has_servant_quarter' => 'Servant quarter', 'has_ground_shop' => 'Shop on ground floor'] as $k => $label) {
        if (!empty($b[$k])) $features[] = '<span class="feat">' . dh($label) . '</span>';
    }
    $html .= '<div class="facts-row"><span class="facts-title"><i class="dot d-feat"></i>Special</span><div class="feats">'
        . ($features ? implode('', $features) : '<span class="muted">No special features</span>') . '</div></div>';
    return $html . '</div>';
}

/**
 * Shared page shell (sidebar + header), same look as the admin dashboard.
 * $nav: key => [label, icon, count].
 */
function dash_layout_start($brand, $title, array $nav, $active, $userName, array $extraCss = []) {
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . dh($title) . ' &#8212; Planzaa</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">'
        . '<link rel="stylesheet" href="../assets/style.css?v=20260926a"><link rel="stylesheet" href="../assets/admin.css?v=6">'
        . '<link rel="stylesheet" href="../assets/brief-form.css?v=2"><link rel="stylesheet" href="../assets/dashboard.css?v=1">';
    foreach ($extraCss as $css) $html .= '<link rel="stylesheet" href="' . dh($css) . '">';
    $html .= '</head><body class="adm-body"><div class="adm" id="adm"><aside class="adm-side" id="admSide" aria-label="Menu">'
        . '<div class="adm-brand">planzaa<span>.</span> ' . dh($brand) . '</div><nav class="adm-nav">';
    foreach ($nav as $key => [$label, $icon, $count]) {
        $on = $key === $active;
        $html .= '<a href="' . dh(dash_url(['tab' => $key])) . '"' . ($on ? ' class="active" aria-current="page"' : '') . '>' . dash_svg($icon)
            . '<span>' . dh($label) . '</span>' . ($count ? '<em class="adm-count">' . (int)$count . '</em>' : '') . '</a>';
    }
    return $html . '</nav><div class="adm-who"><span>Signed in as<br><strong>' . dh($userName) . '</strong></span><a href="../logout.php">Sign out</a></div></aside>'
        . '<div class="adm-scrim" id="admScrim" hidden></div><main class="adm-main"><header class="adm-top">'
        . '<button type="button" class="adm-burger" id="admBurger" aria-controls="admSide" aria-expanded="false" aria-label="Open menu">' . dash_svg('menu') . '</button>'
        . '<h1>' . dh($title) . '</h1></header><div class="dash-page">';
}

function dash_layout_end() {
    return '</div></main></div>'
        . '<dialog class="adm-confirm" id="admConfirm"><form method="dialog"><h2>Are you sure?</h2><p id="admConfirmText">This cannot be undone.</p>'
        . '<div class="adm-actions"><button class="btn" value="cancel">Cancel</button><button class="btn btn-danger" value="ok" id="admConfirmOk">Yes, do it</button></div></form></dialog>'
        . '<script src="../assets/admin.js?v=1"></script><script src="../assets/brief-form.js?v=3"></script></body></html>';
}

function dash_flash_html($flash) {
    if (!$flash) return '';
    return '<div class="adm-flash ' . ($flash[0] === 'err' ? 'err' : 'ok') . '" role="' . ($flash[0] === 'err' ? 'alert' : 'status') . '">' . dh($flash[1]) . '</div>';
}
