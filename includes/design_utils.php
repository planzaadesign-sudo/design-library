<?php
// Design codes, audit trail, standard design files, smart order assignment, order notes/files
// and the new-order email. Used by the admin and in-house dashboards, api/order.php and
// serve-file.php. Only defines things; outputs nothing if opened directly.

require_once __DIR__ . '/../db.php';

// Every file a finished design can have. 'type' decides which files are accepted.
const FILE_SLOTS = [
    // Customer deliverables (sent after purchase)
    'plan_ground' => ['label' => 'Floor Plan — Ground Floor', 'type' => 'pdf', 'required' => true, 'customer_visible' => true, 'group' => 'deliverables'],
    'plan_first' => ['label' => 'Floor Plan — First Floor', 'type' => 'pdf', 'required' => false, 'customer_visible' => true, 'group' => 'deliverables'],
    'plan_second' => ['label' => 'Floor Plan — Second Floor', 'type' => 'pdf', 'required' => false, 'customer_visible' => true, 'group' => 'deliverables'],
    'elevation_front' => ['label' => 'Front Elevation Drawing', 'type' => 'pdf', 'required' => true, 'customer_visible' => true, 'group' => 'deliverables'],
    'elevation_side' => ['label' => 'Side Elevation Drawing', 'type' => 'pdf', 'required' => false, 'customer_visible' => true, 'group' => 'deliverables'],
    'section' => ['label' => 'Building Section Drawing', 'type' => 'pdf', 'required' => false, 'customer_visible' => true, 'group' => 'deliverables'],
    'render_front' => ['label' => '3D View — Front', 'type' => 'image', 'required' => true, 'customer_visible' => true, 'group' => 'deliverables'],
    'render_bird' => ['label' => '3D View — Top/Bird Eye', 'type' => 'image', 'required' => false, 'customer_visible' => true, 'group' => 'deliverables'],
    'interior_living' => ['label' => 'Interior — Living Room', 'type' => 'image', 'required' => false, 'customer_visible' => true, 'group' => 'deliverables'],
    'interior_bedroom' => ['label' => 'Interior — Master Bedroom', 'type' => 'image', 'required' => false, 'customer_visible' => true, 'group' => 'deliverables'],
    'interior_kitchen' => ['label' => 'Interior — Kitchen', 'type' => 'image', 'required' => false, 'customer_visible' => true, 'group' => 'deliverables'],
    // Preview thumbnails (public: shown on the browse and design pages)
    'preview_plan' => ['label' => 'Preview — Plan Thumbnail', 'type' => 'image', 'required' => true, 'customer_visible' => true, 'group' => 'previews'],
    'preview_elevation' => ['label' => 'Preview — Elevation Thumbnail', 'type' => 'image', 'required' => true, 'customer_visible' => true, 'group' => 'previews'],
    // Internal only (never sent to customers)
    'cad_architectural' => ['label' => 'CAD File — Architectural', 'type' => 'cad', 'required' => true, 'customer_visible' => false, 'group' => 'internal'],
    'cad_structural' => ['label' => 'CAD File — Structural', 'type' => 'cad', 'required' => false, 'customer_visible' => false, 'group' => 'internal'],
    'raw_3d_front' => ['label' => 'Raw 3D Source — Front View', 'type' => 'any', 'required' => false, 'customer_visible' => false, 'group' => 'internal'],
    'raw_3d_bird' => ['label' => 'Raw 3D Source — Bird View', 'type' => 'any', 'required' => false, 'customer_visible' => false, 'group' => 'internal'],
    'raw_interior' => ['label' => 'Raw 3D Source — Interior', 'type' => 'any', 'required' => false, 'customer_visible' => false, 'group' => 'internal'],
];
const PREVIEW_SLOTS = ['preview_plan', 'preview_elevation'];

// Accepted files per slot type: extensions and size limit in MB.
const FILE_TYPES = [
    'pdf' => [['pdf'], 30],
    'image' => [['jpg', 'jpeg', 'png', 'webp'], 10],
    'cad' => [['dwg', 'dxf', 'pdf', 'zip'], 30],
    'any' => [null, 50],
];
// Never accepted anywhere (could run on a server or in a browser).
const BLOCKED_EXTENSIONS = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'pht', 'phps', 'shtml', 'cgi', 'pl', 'py', 'sh',
    'exe', 'bat', 'cmd', 'com', 'msi', 'js', 'html', 'htm', 'svg', 'xml', 'htaccess', 'ini'];
// Files staff attach to an order (modified designs for the customer).
const ORDER_FILE_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'dwg', 'dxf', 'zip'];
const ORDER_FILE_MAX_MB = 30;

function du_q($sql, array $params = []) {
    $st = getDB()->prepare($sql);
    $i = 1;
    foreach ($params as $v) $st->bindValue($i++, $v, is_int($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
    $st->execute();
    return $st;
}
function du_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function design_files_root() { return realpath(__DIR__ . '/../uploads') . DIRECTORY_SEPARATOR . 'designs'; }

// True once schema-phase7.sql has been run.
function phase7_ready() {
    static $ready = null;
    if ($ready === null) {
        $tables = du_q("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $ready = !array_diff(['app_settings', 'design_files', 'order_files', 'design_code_counters'], $tables);
        if ($ready) {
            $d = du_q("SHOW COLUMNS FROM designs")->fetchAll(PDO::FETCH_COLUMN);
            $o = du_q("SHOW COLUMNS FROM library_orders")->fetchAll(PDO::FETCH_COLUMN);
            $ready = !array_diff(['design_code', 'published_at', 'approved_by', 'brief_id'], $d)
                  && !array_diff(['assignment_reason', 'overload_warning', 'auto_assigned', 'internal_notes'], $o);
        }
    }
    return $ready;
}

// ---- Settings ----------------------------------------------------------------------------
function getSettingValue($pdo, $key, $default = null) {
    $st = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}
function setSettingValue($pdo, $key, $value) {
    $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([$key, (string)$value]);
}
function max_active_orders() { return max(1, (int)getSettingValue(getDB(), 'max_active_orders_per_person', 5)); }

// ---- Design codes ---------------------------------------------------------------------------
function design_code_prefix($facing, $floors, $bhk) {
    $f = ['East' => 'E', 'West' => 'W', 'North' => 'N', 'South' => 'S'][$facing] ?? 'X';
    $fl = ['G' => 'G', 'G+1' => 'G1', 'G+2' => 'G2', 'G+3' => 'G3'][$floors] ?? 'G';
    return 'PZ-' . $f . '-' . $fl . '-' . max(1, min(5, (int)$bhk)) . 'B';
}

/**
 * Next code in the design's category, e.g. PZ-E-G1-3B-0042.
 * One atomic statement both increments and reads the category counter (LAST_INSERT_ID(expr)),
 * so two designs published at the same moment always get different numbers. Call it inside
 * the publishing transaction: if publishing fails and rolls back, the number is not used up.
 */
function generateDesignCode($pdo, $facing, $floors, $bhk) {
    $prefix = design_code_prefix($facing, $floors, $bhk);
    $pdo->prepare("INSERT INTO design_code_counters (prefix, last_seq) VALUES (?, LAST_INSERT_ID(1))
                   ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)")->execute([$prefix]);
    $seq = (int)$pdo->lastInsertId();
    return $prefix . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

// ---- Files -----------------------------------------------------------------------------------
/**
 * Checks an uploaded or existing file against a slot type by its contents (not only its name)
 * and size. Returns [$ext, null] or [null, $error].
 */
function validate_design_file($path, $originalName, $size, $type) {
    [$exts, $maxMb] = FILE_TYPES[$type];
    $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
    if ($ext === '' || in_array($ext, BLOCKED_EXTENSIONS, true)) return [null, 'This kind of file is not allowed.'];
    if ($exts !== null && !in_array($ext, $exts, true)) return [null, 'Please upload a ' . implode(', ', array_map(function ($e) { return '.' . $e; }, $exts)) . ' file.'];
    if ($size <= 0) return [null, 'That file is empty.'];
    if ($size > $maxMb * 1024 * 1024) return [null, 'Files like this must be ' . $maxMb . ' MB or smaller.'];
    $head = (string)file_get_contents($path, false, null, 0, 4096);
    if (strpos($head, '<?php') !== false || stripos($head, '<script') !== false) return [null, 'That file contains code and cannot be uploaded.'];
    $ok = true;
    if ($ext === 'pdf') $ok = strncmp($head, '%PDF', 4) === 0;
    elseif ($ext === 'zip') $ok = strncmp($head, "PK\x03\x04", 4) === 0;
    elseif ($ext === 'dwg') $ok = strncmp($head, 'AC1', 3) === 0;
    elseif ($ext === 'dxf') $ok = stripos($head, 'SECTION') !== false || strncmp($head, 'AutoCAD Binary DXF', 18) === 0;
    elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        $info = @getimagesize($path);
        $ok = $info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true);
    }
    if (!$ok) return [null, 'That file does not look like a real .' . $ext . ' file.'];
    return [$ext === 'jpeg' ? 'jpg' : $ext, null];
}

function upload_error_text($err) {
    if ($err === UPLOAD_ERR_NO_FILE) return 'Please choose a file.';
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return 'That file is too big for the server. Please make it smaller and try again.';
    return 'The upload did not finish. Please try again.';
}

function design_folder(array $design) {
    return $design['design_code'] ? $design['design_code'] : 'draft-' . (int)$design['id'];
}

/**
 * Puts a file into a design's slot: uploads/designs/{code or draft-id}/{slot}.{ext}.
 * $isUpload = true for $_FILES (moved), false to copy an existing file (a submission's).
 * Returns null or an error message.
 */
function store_slot_file(array $design, $slot, $srcPath, $originalName, $size, $isUpload, $staffId, $freelancerId = null) {
    if (!isset(FILE_SLOTS[$slot])) return 'Unknown file slot.';
    [$ext, $err] = validate_design_file($srcPath, $originalName, $size, FILE_SLOTS[$slot]['type']);
    if ($err) return $err;
    $folder = design_folder($design);
    $dir = design_files_root() . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return 'We could not create the folder for this design.';
    $old = du_q("SELECT file_path FROM design_files WHERE design_id = ? AND file_slot = ?", [(int)$design['id'], $slot])->fetchColumn();
    $rel = $folder . '/' . $slot . '.' . $ext;
    $dest = design_files_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $ok = $isUpload ? move_uploaded_file($srcPath, $dest) : copy($srcPath, $dest);
    if (!$ok) return 'We could not save the file. Please try again.';
    if ($old && $old !== $rel) @unlink(design_files_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $old));
    du_q("INSERT INTO design_files (design_id, file_slot, file_path, original_filename, file_size, uploaded_by_staff, uploaded_by_freelancer)
          VALUES (?, ?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), original_filename = VALUES(original_filename), file_size = VALUES(file_size),
            uploaded_by_staff = VALUES(uploaded_by_staff), uploaded_by_freelancer = VALUES(uploaded_by_freelancer), uploaded_at = CURRENT_TIMESTAMP",
        [(int)$design['id'], $slot, $rel, mb_substr(basename((string)$originalName), 0, 255), (int)$size, $staffId ? (int)$staffId : null, $freelancerId ? (int)$freelancerId : null]);
    return null;
}

function delete_slot_file($designId, $slot) {
    $path = du_q("SELECT file_path FROM design_files WHERE design_id = ? AND file_slot = ?", [(int)$designId, $slot])->fetchColumn();
    if (!$path) return false;
    @unlink(design_files_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
    du_q("DELETE FROM design_files WHERE design_id = ? AND file_slot = ?", [(int)$designId, $slot]);
    return true;
}

// slot => row (with uploader names), for one design.
function design_files_map($designId) {
    $out = [];
    foreach (du_q("SELECT f.*, st.name AS staff_name, fr.name AS freelancer_name FROM design_files f
                   LEFT JOIN staff st ON st.id = f.uploaded_by_staff LEFT JOIN freelancers fr ON fr.id = f.uploaded_by_freelancer
                   WHERE f.design_id = ?", [(int)$designId])->fetchAll() as $r) $out[$r['file_slot']] = $r;
    return $out;
}

function missing_required_slots($designId) {
    $have = design_files_map($designId);
    $missing = [];
    foreach (FILE_SLOTS as $slot => $s) if ($s['required'] && empty($have[$slot])) $missing[$slot] = $s['label'];
    return $missing;
}

function human_size($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    return max(1, (int)round($bytes / 1024)) . ' KB';
}

// Public URLs for a published design's preview thumbnails (null if not uploaded).
function preview_urls($designId, $base = '') {
    $urls = ['preview_plan' => null, 'preview_elevation' => null];
    foreach (du_q("SELECT file_slot, UNIX_TIMESTAMP(uploaded_at) AS t FROM design_files WHERE design_id = ? AND file_slot IN ('preview_plan', 'preview_elevation')", [(int)$designId])->fetchAll() as $r) {
        $urls[$r['file_slot']] = $base . 'serve-file.php?design_id=' . (int)$designId . '&slot=' . $r['file_slot'] . '&v=' . (int)$r['t'];
    }
    return $urls;
}

// ---- Drafts and publishing -------------------------------------------------------------------
/**
 * The draft design for an approved submission (created on approval). Copies the brief's
 * parameters and puts the Design Creator's CAD file into cad_architectural and their preview
 * image into preview_plan. Returns the draft design id (existing or new).
 */
function create_draft_from_submission($subId, $staffId) {
    $existing = du_q("SELECT id FROM designs WHERE source_submission_id = ?", [(int)$subId])->fetchColumn();
    if ($existing) return (int)$existing;
    $sub = du_q("SELECT s.*, b.*, s.id AS sub_id, b.id AS brief_real_id, b.created_by AS brief_by, b.created_at AS brief_at
                 FROM submissions s JOIN briefs b ON b.id = s.brief_id WHERE s.id = ?", [(int)$subId])->fetch();
    if (!$sub || $sub['review_status'] !== 'approved' || $sub['published']) return null;

    $floors = !empty($sub['floors']) ? $sub['floors'] : (preg_match('/\bG(\+\d)?\b/', (string)$sub['house_type'], $m) ? $m[0] : 'G+1');
    $bhk = !empty($sub['bhk']) ? (int)$sub['bhk'] : (preg_match('/(\d+)\s*BHK/i', (string)$sub['house_type'], $m) ? (int)$m[1] : 3);
    $row = [
        'name' => $sub['title'], 'plot_width' => $sub['plot_width'] ?: 30, 'plot_length' => $sub['plot_length'] ?: 40,
        'facing' => $sub['facing'] ?: 'East', 'floors' => $floors, 'bhk' => $bhk, 'base_price' => max(15000, (int)$sub['payout'] * 6),
        'delivery_days' => 12, 'variant' => random_int(0, 2), 'is_active' => 0, 'source_submission_id' => (int)$sub['sub_id'],
        'brief_id' => (int)$sub['brief_real_id'], 'brief_created_by' => $sub['brief_by'], 'brief_created_at' => $sub['brief_at'],
        'designed_by' => (int)$sub['freelancer_id'], 'designed_at' => $sub['submitted_at'],
        'reviewed_by' => $sub['reviewer_id'], 'reviewed_at' => $sub['reviewed_at'],
        'approved_by' => $sub['reviewer_id'], 'approved_at' => $sub['reviewed_at'],
    ];
    if (defined('SIM_NEW_COLUMNS')) foreach (SIM_NEW_COLUMNS as $c) if (array_key_exists($c, $sub)) $row[$c] = $sub[$c];
    du_q("INSERT INTO designs (" . implode(', ', array_keys($row)) . ") VALUES (" . implode(', ', array_fill(0, count($row), '?')) . ")", array_values($row));
    $designId = (int)getDB()->lastInsertId();
    $design = ['id' => $designId, 'design_code' => null];

    // The Design Creator's own files go straight into their slots (copies -- the submission keeps its originals).
    $uploads = realpath(__DIR__ . '/../uploads');
    foreach (['cad_file_path' => 'cad_architectural', 'preview_path' => 'preview_plan'] as $col => $slot) {
        $rel = (string)($sub[$col] ?? '');
        if ($rel === '') continue;
        $src = realpath(__DIR__ . '/../' . $rel);
        if (!$src || strpos($src, $uploads) !== 0 || !is_file($src)) continue;
        store_slot_file($design, $slot, $src, basename($rel), filesize($src), false, null, (int)$sub['freelancer_id']);
    }
    return $designId;
}

function draft_for_submission($subId) {
    return du_q("SELECT * FROM designs WHERE source_submission_id = ?", [(int)$subId])->fetch() ?: null;
}

/**
 * Publishes a draft design: every required file must be there. Assigns the design code,
 * moves the files into uploads/designs/{code}/, fills the audit trail and makes it live.
 * Returns [designId, null] or [null, error message].
 */
function publish_design_draft($designId, $staffId) {
    $pdo = getDB();
    $d = du_q("SELECT * FROM designs WHERE id = ?", [(int)$designId])->fetch();
    if (!$d || $d['published_at'] !== null) return [null, 'This design is already published.'];
    $missing = missing_required_slots($designId);
    if ($missing) return [null, "This design can't be published yet — these files are still missing: " . implode(', ', $missing) . '.'];

    $root = design_files_root() . DIRECTORY_SEPARATOR;
    $pdo->beginTransaction();
    $moved = null;
    try {
        $code = generateDesignCode($pdo, $d['facing'], $d['floors'], $d['bhk']);
        $draftDir = $root . 'draft-' . (int)$d['id'];
        if (is_dir($draftDir)) {
            if (!rename($draftDir, $root . $code)) throw new RuntimeException('Could not move the design files into their final folder.');
            $moved = [$root . $code, $draftDir];
            du_q("UPDATE design_files SET file_path = CONCAT(?, SUBSTRING(file_path, ?)) WHERE design_id = ?",
                [$code, strlen('draft-' . (int)$d['id']) + 1, (int)$d['id']]);
        }
        du_q("UPDATE designs SET design_code = ?, is_active = 1, published_at = NOW(), standardized_by = ? WHERE id = ?", [$code, (int)$staffId, (int)$d['id']]);
        if ($d['source_submission_id']) {
            $sub = du_q("SELECT brief_id, freelancer_id FROM submissions WHERE id = ?", [(int)$d['source_submission_id']])->fetch();
            du_q("UPDATE submissions SET published = 1 WHERE id = ?", [(int)$d['source_submission_id']]);
            du_q("UPDATE briefs SET status = 'published' WHERE id = ?", [(int)$sub['brief_id']]);
            // Illustrative royalty split -- the placeholder rate used since the first demo.
            du_q("UPDATE freelancers SET earnings = earnings + ? WHERE id = ?", [(int)round($d['base_price'] * 0.1), (int)$sub['freelancer_id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($moved) @rename($moved[0], $moved[1]);
        throw $e;
    }
    return [(int)$d['id'], null];
}

// ---- Smart auto-assignment ----------------------------------------------------------------
function active_order_count($staffId) {
    return (int)du_q("SELECT COUNT(*) FROM library_orders WHERE assigned_to = ? AND status NOT IN ('delivered')", [(int)$staffId])->fetchColumn();
}
function plural_orders($n) { return $n . ' active order' . ($n === 1 ? '' : 's'); }

/**
 * Picks who handles a new order and records why:
 * 1. the person who approved the design, if they are under the workload limit;
 * 2. otherwise (or for designs added directly by the admin) the in-house team member with
 *    the fewest active orders -- flagged when everyone is at or above the limit.
 * Returns ['staff_id', 'name', 'reason', 'overload'].
 */
function auto_assign_order($orderId) {
    $order = du_q("SELECT o.id, d.approved_by, d.brief_id, a.name AS approver_name
                   FROM library_orders o JOIN designs d ON d.id = o.design_id LEFT JOIN staff a ON a.id = d.approved_by
                   WHERE o.id = ?", [(int)$orderId])->fetch();
    if (!$order) return null;
    $limit = max_active_orders();
    $pick = null; $reason = ''; $overload = 0;

    if ($order['approved_by']) {
        $n = active_order_count($order['approved_by']);
        if ($n < $limit) {
            $pick = ['id' => (int)$order['approved_by'], 'name' => $order['approver_name']];
            $reason = 'Automatically assigned to ' . $pick['name'] . ' — they approved this design and have ' . plural_orders($n) . '.';
        }
    }
    if (!$pick) {
        $team = du_q("SELECT st.id, st.name, (SELECT COUNT(*) FROM library_orders o WHERE o.assigned_to = st.id AND o.status NOT IN ('delivered')) AS active
                      FROM staff st WHERE st.role = 'inhouse' ORDER BY active, st.name, st.id")->fetchAll();
        if (!$team) {
            du_q("UPDATE library_orders SET assignment_reason = ? WHERE id = ?", ['Not assigned automatically — there are no in-house team members yet.', (int)$orderId]);
            call_request_start($orderId); // still goes into the call queue (admin sees it)
            return null;
        }
        $pick = ['id' => (int)$team[0]['id'], 'name' => $team[0]['name']];
        $k = (int)$team[0]['active'];
        if ($order['approved_by']) {
            $reason = 'Automatically assigned to ' . $pick['name'] . ' — ' . $order['approver_name'] . ' approved this design but already has '
                . plural_orders(active_order_count($order['approved_by'])) . ', so it went to the person with the fewest (' . $k . ').';
        } else {
            $reason = 'Automatically assigned to ' . $pick['name'] . ' — this design was added directly by the admin, and ' . $pick['name']
                . ' has the fewest active orders (' . $k . ').';
        }
        if ($k >= $limit) {
            $overload = 1;
            $reason .= ' Everyone on the team is at or above the limit of ' . plural_orders($limit) . '.';
        }
    }
    du_q("UPDATE library_orders SET assigned_to = ?, assigned_at = NOW(), auto_assigned = 1, overload_warning = ?, assignment_reason = ? WHERE id = ?",
        [$pick['id'], $overload, $reason, (int)$orderId]);
    // A new call-back order: it now waits for a call, and the person it was given to gets an email.
    call_request_start($orderId);
    return ['staff_id' => $pick['id'], 'name' => $pick['name'], 'reason' => $reason, 'overload' => $overload];
}

// ---- New-order email ------------------------------------------------------------------------
function send_new_order_email($orderId) {
    $pdo = getDB();
    $to = trim((string)getSettingValue($pdo, 'admin_notification_email', ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $o = du_q("SELECT o.*, d.name AS design_name, d.design_code, s.name AS staff_name FROM library_orders o
               JOIN designs d ON d.id = o.design_id LEFT JOIN staff s ON s.id = o.assigned_to WHERE o.id = ?", [(int)$orderId])->fetch();
    if (!$o) return false;
    $oneLine = function ($s) { return trim(preg_replace('/[\r\n]+/', ' ', (string)$s)); }; // no header injection
    $mods = trim((string)$o['modifications']);
    $type = ($o['contact_preference'] ?? 'self') === 'call' ? 'Call-back request' : (($mods !== '' && $mods !== '[]') ? 'Modified' : 'As-is');
    $site = rtrim((string)getSettingValue($pdo, 'site_url', 'https://test.planzaa.in'), '/');
    $subject = $oneLine("New order {$o['order_code']} — {$o['design_name']}");
    $body = "A new order has been placed.\n\n"
        . "Order: {$o['order_code']}\n"
        . "Customer: " . $oneLine($o['customer_name']) . "\n"
        . "Phone: {$o['customer_phone']}\n"
        . "Design: " . $oneLine($o['design_name']) . " (" . ($o['design_code'] ?: 'no code') . ")\n"
        . "Type: {$type}\n"
        . "Total: ₹" . number_format((int)$o['total_price']) . "\n"
        . (!empty($o['needs_manual_review']) ? "⚠ Needs manual price review\n" : '')
        . "\nAssigned to: " . ($o['staff_name'] ?: 'nobody yet') . (!empty($o['overload_warning']) ? ' (OVERLOADED)' : '') . "\n"
        . "\nView in admin: {$site}/admin/?tab=orders&id=" . (int)$o['id'] . "\n";
    $headers = "From: noreply@planzaa.in\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

// ---- Order notes and files -----------------------------------------------------------------
function order_notes(array $order) {
    $list = json_decode((string)($order['internal_notes'] ?? ''), true);
    return is_array($list) ? $list : [];
}
function add_order_note($orderId, $staffId, $staffName, $text) {
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $cur = du_q("SELECT internal_notes FROM library_orders WHERE id = ? FOR UPDATE", [(int)$orderId])->fetchColumn();
        $list = json_decode((string)$cur, true);
        if (!is_array($list)) $list = [];
        $list[] = ['at' => date('Y-m-d H:i:s'), 'by' => (int)$staffId, 'name' => (string)$staffName, 'text' => $text];
        du_q("UPDATE library_orders SET internal_notes = ? WHERE id = ?", [json_encode($list, JSON_UNESCAPED_UNICODE), (int)$orderId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Saves a file staff attach to an order: uploads/designs/_orders/{order_code}/...
 * (covered by the same deny-all .htaccess; served only through serve-file.php).
 */
function store_order_file(array $order, array $upload, $staffId) {
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) return upload_error_text($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    $ext = strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ORDER_FILE_EXTENSIONS, true)) return 'Please upload a ' . implode(', ', array_map(function ($e) { return '.' . $e; }, ORDER_FILE_EXTENSIONS)) . ' file.';
    $type = $ext === 'pdf' ? 'pdf' : (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? 'image' : 'cad');
    [$ext, $err] = validate_design_file($upload['tmp_name'], $upload['name'], (int)$upload['size'], $type);
    if ($err) return $err;
    if ((int)$upload['size'] > ORDER_FILE_MAX_MB * 1024 * 1024) return 'Order files must be ' . ORDER_FILE_MAX_MB . ' MB or smaller.';
    $folder = '_orders/' . preg_replace('/[^A-Z0-9-]/', '', strtoupper((string)$order['order_code']));
    $dir = design_files_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $folder);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return 'We could not create the folder for this order.';
    $rel = $folder . '/' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($upload['tmp_name'], design_files_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel))) return 'We could not save the file. Please try again.';
    du_q("INSERT INTO order_files (order_id, file_name, file_path, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?)",
        [(int)$order['id'], mb_substr(basename((string)$upload['name']), 0, 255), $rel, (int)$upload['size'], (int)$staffId]);
    return null;
}

function order_files_list($orderId) {
    return du_q("SELECT f.*, s.name AS staff_name FROM order_files f JOIN staff s ON s.id = f.uploaded_by WHERE f.order_id = ? ORDER BY f.id DESC", [(int)$orderId])->fetchAll();
}

require_once __DIR__ . '/calls.php'; // call queue for call-back orders (uses the functions above)
