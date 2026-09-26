<?php
// Shared helpers for the admin dashboard. Only ever included from admin/index.php,
// after requireStaff('admin') has run -- never serve this file on its own.
if (!defined('PLANZAA_ADMIN')) { http_response_code(403); exit; }

const STAGES = ['new', 'design', 'structural', 'compliance', 'delivered'];
const STAGE_LABEL = ['new' => 'New', 'design' => 'Design', 'structural' => 'Structural', 'compliance' => 'Compliance', 'delivered' => 'Delivered'];
const FACINGS = ['East', 'West', 'North', 'South'];
const FLOOR_OPTIONS = ['G', 'G+1', 'G+2', 'G+3'];
const ROOM_TYPES = ['bedroom', 'bathroom', 'kitchen', 'living', 'dining', 'pooja', 'balcony', 'parking', 'staircase', 'store', 'utility', 'other'];
const ROOM_FLOORS = ['Ground Floor', 'First Floor', 'Second Floor', 'Third Floor'];
const DETAIL_TYPES = [
    '' => 'None (whole house)', 'resize' => 'Resize rooms', 'partition' => 'Move a wall', 'washroom' => 'Add bathroom (bedrooms)',
    'opening' => 'Door / window', 'relabel' => 'Change room use', 'colour' => 'Colour scheme', 'other' => 'Free text (always manual review)',
];
const ROOM_KINDS = ['resize', 'partition', 'washroom', 'opening', 'relabel'];
const BRIEF_STATUS = ['open' => 'Open', 'claimed' => 'Claimed', 'in_review' => 'In review', 'needs_revision' => 'Needs revision', 'approved' => 'Approved', 'published' => 'Published'];
// Notes the customer configurator stores for "let our architect decide" (see assets/app.js).
const ARCHITECT_NOTE = 'Customer requested architect to decide';
const COLOUR_ARCHITECT_NOTE = 'Architect to suggest colour scheme';
const PER_PAGE = 20;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Rupees with Indian digit grouping: 134700 -> ₹1,34,700
function inr($n) {
    $n = (int)round($n);
    $s = (string)abs($n);
    if (strlen($s) > 3) {
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($s, 0, -3));
        $s = $rest . ',' . substr($s, -3);
    }
    return ($n < 0 ? '-' : '') . '&#8377;' . $s;
}

// Same, as plain text for flash messages (which are escaped when shown).
function inr_text($n) { return html_entity_decode(inr($n), ENT_QUOTES, 'UTF-8'); }

function fdate($ts, $withTime = false) {
    if (!$ts) return '&#8212;';
    $t = strtotime($ts);
    return $t ? h(date($withTime ? 'j M Y, g:i a' : 'j M Y', $t)) : h($ts);
}

// Every query goes through here: always prepared, ints bound as ints (so LIMIT/OFFSET work).
function q($sql, array $params = []) {
    $st = getDB()->prepare($sql);
    $i = 1;
    foreach ($params as $v) {
        $st->bindValue($i++, $v, is_int($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
    }
    $st->execute();
    return $st;
}

// ---- CSRF: every POST from the admin must carry this session's token ----------
function csrf_token() {
    if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['admin_csrf'];
}
function csrf_field() { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function csrf_check() {
    if (!hash_equals($_SESSION['admin_csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('This form has expired. Please go back, reload the page and try again.');
    }
}

// ---- Flash messages and form values that survive the redirect after a POST ----
function flash($msg, $type = 'ok') { $_SESSION['admin_flash'] = [$type, $msg]; }
function take_flash() { $f = $_SESSION['admin_flash'] ?? null; unset($_SESSION['admin_flash']); return $f; }
function keep_old(array $data) {
    unset($data['csrf'], $data['password'], $data['password2'], $data['current_password'], $data['new_password']);
    $_SESSION['admin_old'] = $data;
}
function old($key, $default = '') {
    return isset($GLOBALS['OLD'][$key]) ? $GLOBALS['OLD'][$key] : $default;
}

function url(array $params) {
    $params = array_filter($params, function ($v) { return $v !== null && $v !== ''; });
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}
// Redirect back to where the form was posted from (only ever to this page).
function go_back($fallback = []) {
    $ret = (string)($_POST['return'] ?? '');
    $to = preg_match('/^tab=[a-z]+(&[a-z_]+=[A-Za-z0-9_%.+-]*)*$/', $ret) ? 'index.php?' . $ret : url($fallback);
    header('Location: ' . $to);
    exit;
}
function return_field() {
    return '<input type="hidden" name="return" value="' . h($_SERVER['QUERY_STRING'] ?? '') . '">';
}

// ---- Badges ---------------------------------------------------------------------
function stage_badge($s) {
    $cls = ['new' => 'b-amber', 'design' => 'b-blue', 'structural' => 'b-blue', 'compliance' => 'b-blue', 'delivered' => 'b-green'][$s] ?? 'badge-neutral';
    return '<span class="badge ' . $cls . '">' . h(STAGE_LABEL[$s] ?? $s) . '</span>';
}
function order_type($o) {
    if (($o['contact_preference'] ?? 'self') === 'call') return 'call';
    $m = trim((string)($o['modifications'] ?? ''));
    return ($m !== '' && $m !== '[]') ? 'modified' : 'asis';
}
function type_badge($o) {
    $t = order_type($o);
    if ($t === 'call') return '<span class="badge b-amber">Call-back</span>';
    if ($t === 'modified') return '<span class="badge b-blue">Modified</span>';
    return '<span class="badge badge-neutral">As-is</span>';
}
function brief_badge($s) {
    $cls = ['open' => 'b-green', 'claimed' => 'b-blue', 'in_review' => 'b-amber', 'needs_revision' => 'b-rust', 'approved' => 'b-green', 'published' => 'badge-neutral'][$s] ?? 'badge-neutral';
    return '<span class="badge ' . $cls . '">' . h(BRIEF_STATUS[$s] ?? $s) . '</span>';
}
function review_badge($sub) {
    if (!empty($sub['published'])) return '<span class="badge badge-neutral">Published</span>';
    $s = $sub['review_status'];
    if ($s === 'pending') return '<span class="badge b-amber">Pending review</span>';
    if ($s === 'rejected') return '<span class="badge b-rust">Sent back</span>';
    return '<span class="badge b-green">Approved</span>';
}

// Clickable column header for sortable tables.
function sort_th($label, $key, $sort, $dir, array $params) {
    $active = $sort === $key;
    $next = $active && $dir === 'asc' ? 'desc' : 'asc';
    $arrow = $active ? ($dir === 'asc' ? ' &#9650;' : ' &#9660;') : '';
    return '<th' . ($active ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '') . '><a class="th-sort" href="'
        . h(url(array_merge($params, ['sort' => $key, 'dir' => $next, 'page' => null]))) . '">' . h($label) . $arrow . '</a></th>';
}

function pager($total, $page, array $params) {
    $pages = max(1, (int)ceil($total / PER_PAGE));
    if ($pages <= 1) return '';
    $html = '<nav class="pager" aria-label="Pages">';
    if ($page > 1) $html .= '<a href="' . h(url(array_merge($params, ['page' => $page - 1]))) . '">&larr; Previous</a>';
    $html .= '<span>Page ' . $page . ' of ' . $pages . '</span>';
    if ($page < $pages) $html .= '<a href="' . h(url(array_merge($params, ['page' => $page + 1]))) . '">Next &rarr;</a>';
    return $html . '</nav>';
}

function svg($name) {
    $p = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'orders' => '<path d="M6 3h12l1 18H5z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
        'designs' => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-5h4v5"/>',
        'modifications' => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13 7l4 4"/>',
        'briefs' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        'submissions' => '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v4h16v-4"/>',
        'team' => '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14c2.8 0 5 2 5 5"/>',
        'freelancers' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'phone' => '<path d="M5 4h3l2 5-2.5 1.5a11 11 0 005 5L14 13l5 2v3a2 2 0 01-2 2A15 15 0 013 6a2 2 0 012-2"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'expert' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/>',
    ][$name] ?? '';
    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

// Status stepper, same look as the customer tracking page.
function stepper($status) {
    $idx = array_search($status, STAGES, true);
    $html = '<ol class="tstepper" aria-label="Order progress">';
    foreach (STAGES as $i => $s) {
        $state = ($status === 'delivered' || $i < $idx) ? 'done' : ($i === $idx ? 'current' : '');
        $html .= '<li class="tstep ' . $state . '"' . ($state === 'current' ? ' aria-current="step"' : '') . '><span class="node">'
            . ($state === 'done' ? svg('check') : '') . '</span><span>' . h(STAGE_LABEL[$s]) . '</span></li>';
    }
    return $html . '</ol>';
}

// Missing tables/columns (SQL files not run yet) -> the admin explains instead of crashing.
function schema_problems() {
    $tables = q("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $problems = [];
    $needTables = [
        'modifications' => 'schema-phase3.sql (top part)',
        'design_rooms' => 'schema-phase3.sql, section "Phase 3b"',
        'order_modification_details' => 'schema-phase3.sql, section "Phase 3b"',
    ];
    foreach ($needTables as $t => $where) {
        if (!in_array($t, $tables, true)) $problems[] = "Table <code>$t</code> is missing &#8212; run $where.";
    }
    $needCols = [
        'library_orders' => [
            'modifications' => 'schema-phase3.sql (top part)', 'needs_manual_review' => 'schema-phase3.sql (top part)',
            'structural_included' => 'schema-phase3.sql (top part)', 'estimated_delivery_days' => 'schema-phase3.sql (top part)',
            'customer_state' => 'schema-phase3.sql, section "Phase 3c"', 'contact_preference' => 'schema-phase3.sql, section "Phase 3c"',
            'customer_district' => 'schema-phase3.sql, section "Phase 3c"', 'callback_notes' => 'schema-phase3.sql, section "Phase 3c"',
            'assigned_at' => 'schema-phase4.sql',
        ],
        'modifications' => ['detail_type' => 'schema-phase3.sql, section "Phase 3b"'],
    ];
    foreach ($needCols as $t => $cols) {
        if (!in_array($t, $tables, true)) continue;
        $have = q("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $c => $where) {
            if (!in_array($c, $have, true)) $problems[] = "Column <code>$t.$c</code> is missing &#8212; run $where.";
        }
    }
    if (!phase7_ready()) {
        $problems[] = 'Design codes, design files and auto-assignment need the database update &#8212; run schema-phase7.sql.';
    }
    if (!security_ready()) {
        $problems[] = 'Password rules, sign-in protection and password reset need the database update &#8212; run schema-phase9.sql.';
    }
    if (!phase8_ready()) {
        $problems[] = 'Design Creator registration and approval need the database update &#8212; run schema-phase8.sql.';
    }
    if (!phase5_ready()) {
        $problems[] = 'The design similarity columns (plot shape, main door, stairs, style&#8230;) are missing &#8212; run schema-phase5.sql.';
    }
    return array_values(array_unique($problems));
}

// One line of room-level detail for an order, in the admin's words.
function detail_text(array $d, $kind) {
    $note = (string)($d['custom_note'] ?? '');
    if ($note === ARCHITECT_NOTE) return '<span class="arch-note">' . svg('expert') . 'Our architect will decide</span>';
    if ($note === COLOUR_ARCHITECT_NOTE) return '<span class="arch-note">' . svg('expert') . 'Architect to suggest colours</span>';
    $room = $d['room_name'] ?? null;
    $what = '';
    if ($kind === 'resize') {
        $what = $d['action'] === 'increase' ? 'make it bigger' : ($d['action'] === 'decrease' ? 'make it smaller' : $note);
    } elseif ($kind === 'washroom') {
        $what = 'add an attached bathroom';
    } else {
        $what = $note;
    }
    if ($kind === 'colour') return 'Colours: ' . h($note);
    return $room ? '<strong>' . h($room) . '</strong> &#8212; ' . h($what) : h($what);
}

// Review box for one Design Creator submission (used on the brief and submission pages).
function review_block(array $s) {
    // Brief parameters + the 3 closest library designs, for the side-by-side check.
    $brief = brief_params($s['brief_id']);
    $sim = $brief ? render_similarity_review($brief, $s, '../') : ['html' => '', 'matches' => []];
    $file = $s['cad_file_path'] ? '<a class="btn btn-small" href="../' . h($s['cad_file_path']) . '" download>Download file</a>' : '<span class="muted">No file attached</span>';
    $html = '<div class="review-box' . ($s['review_status'] === 'pending' && !$s['published'] ? ' pending' : '') . '">'
        . '<div class="review-top"><div><strong>' . h($s['freelancer_name']) . '</strong> <span class="muted">submitted ' . fdate($s['submitted_at'], true) . '</span></div>'
        . review_badge($s) . '</div>'
        . '<div class="review-file">' . $file . '</div>';
    if ($s['notes']) $html .= '<p class="note-text"><span class="muted">Creator&#8217;s note:</span> ' . nl2br(h($s['notes'])) . '</p>';
    if ($s['review_notes']) $html .= '<p class="note-text"><span class="muted">Review notes' . ($s['reviewer_name'] ? ' by ' . h($s['reviewer_name']) : '') . ':</span> ' . nl2br(h($s['review_notes'])) . '</p>';
    if ($s['review_status'] === 'pending') {
        $html .= $sim['html'];
        $html .= '<form method="post" class="review-form" data-saving>' . csrf_field() . return_field()
            . '<input type="hidden" name="action" value="sub_review"><input type="hidden" name="submission_id" value="' . (int)$s['id'] . '">'
            . render_review_extras($sim['matches'])
            . '<label for="rn' . (int)$s['id'] . '">Notes for the Design Creator <span class="muted">(needed when sending back)</span></label>'
            . '<textarea id="rn' . (int)$s['id'] . '" name="review_notes" rows="3" maxlength="2000"></textarea>'
            . '<div class="adm-actions left">'
            . '<button class="btn btn-primary" name="outcome" value="approve" data-needs-confirm>Approve</button>'
            . '<button class="btn" name="outcome" value="approve_edits" data-needs-confirm>Approve &#8212; in-house will make small fixes</button>'
            . '<button class="btn btn-warn" name="outcome" value="reject" data-needs-notes>Send back for changes</button>'
            . '</div></form>';
    } elseif ($s['review_status'] === 'approved' && !$s['published']) {
        // Approved: its draft design needs the required files before it can be published.
        $draft = draft_for_submission($s['id']);
        if ($draft) {
            $missing = missing_required_slots($draft['id']);
            $html .= '<p class="note-text">' . ($missing ? 'Still missing before publishing: ' . h(implode(', ', $missing)) . '.' : 'All required files are uploaded.')
                . ' <a href="' . h(url(['tab' => 'designs', 'edit' => $draft['id']])) . '#designFiles">Upload or check the design files &rarr;</a></p>';
        }
        $html .= '<form method="post" data-saving>' . csrf_field() . return_field()
            . '<input type="hidden" name="action" value="sub_publish"><input type="hidden" name="submission_id" value="' . (int)$s['id'] . '">'
            . '<button class="btn btn-primary">Standardise &amp; publish as a design</button></form>';
    }
    return $html . '</div>';
}

// Submissions with everything review_block() needs.
const SUBMISSION_SELECT = "SELECT s.*, b.title, f.name AS freelancer_name, st.name AS reviewer_name
    FROM submissions s JOIN briefs b ON b.id = s.brief_id JOIN freelancers f ON f.id = s.freelancer_id
    LEFT JOIN staff st ON st.id = s.reviewer_id";
