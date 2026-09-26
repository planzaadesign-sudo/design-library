<?php
// HTML for design files, the design history timeline and order notes/files.
// Shared by the admin and in-house dashboards. Only defines functions.

require_once __DIR__ . '/design_utils.php';

function dui_date($ts, $time = false) {
    if (!$ts) return '';
    $t = strtotime($ts);
    return $t ? du_h(date($time ? 'j M Y, g:i a' : 'j M Y', $t)) : du_h($ts);
}

/**
 * The file slot grid for one design.
 * $hidden: HTML of hidden inputs every form needs (CSRF token, return path);
 * $serveBase: path to serve-file.php from the current page (e.g. '../').
 */
function render_design_files(array $design, $hidden, $serveBase) {
    $files = design_files_map($design['id']);
    $required = array_keys(array_filter(FILE_SLOTS, function ($s) { return $s['required']; }));
    $have = count(array_filter($required, function ($slot) use ($files) { return !empty($files[$slot]); }));
    $pct = (int)round($have / count($required) * 100);
    $groups = [
        'deliverables' => ['Customer deliverables', 'Sent to the customer after they buy the design.'],
        'previews' => ['Preview thumbnails', 'Shown to everyone on the designs page and the design page.'],
        'internal' => ['Internal / source files', 'These files are never sent to customers.'],
    ];
    $html = '<div class="files-box" id="designFiles">'
        . '<div class="files-progress"><strong>' . $have . ' of ' . count($required) . ' required files uploaded</strong>'
        . '<span class="fp-bar"><i style="width:' . $pct . '%"></i></span></div>';
    foreach ($groups as $group => [$title, $sub]) {
        $html .= '<h4 class="files-h">' . du_h($title) . '</h4><p class="files-sub' . ($group === 'internal' ? ' internal' : '') . '">' . du_h($sub) . '</p>'
            . '<div class="slot-grid ' . $group . '">';
        foreach (FILE_SLOTS as $slot => $s) {
            if ($s['group'] !== $group) continue;
            $f = $files[$slot] ?? null;
            $missing = !$f && $s['required'];
            $html .= '<div class="slot-card' . ($f ? ' has' : '') . ($missing ? ' missing' : '') . '" id="slot-' . du_h($slot) . '">'
                . '<div class="slot-top"><strong>' . du_h($s['label']) . '</strong>' . ($s['required'] ? '<span class="badge ' . ($missing ? 'b-rust' : 'b-green') . '">Required</span>' : '') . '</div>';
            if ($f) {
                if (in_array($slot, PREVIEW_SLOTS, true) || $s['type'] === 'image') {
                    $html .= '<img class="slot-thumb" src="' . du_h($serveBase . 'serve-file.php?design_id=' . (int)$design['id'] . '&slot=' . $slot . '&v=' . strtotime($f['uploaded_at'])) . '" alt="" loading="lazy">';
                }
                $who = $f['staff_name'] ?: ($f['freelancer_name'] ? $f['freelancer_name'] . ' (Design Creator)' : '—');
                $html .= '<div class="slot-meta"><span class="fn" title="' . du_h($f['original_filename']) . '">' . du_h($f['original_filename']) . '</span>'
                    . '<span>' . du_h(human_size((int)$f['file_size'])) . ' &#183; ' . dui_date($f['uploaded_at']) . ' &#183; ' . du_h($who) . '</span></div>'
                    . '<div class="slot-actions"><a class="btn btn-small" href="' . du_h($serveBase . 'serve-file.php?design_id=' . (int)$design['id'] . '&slot=' . $slot . '&download=1') . '">Download</a>'
                    . '<form method="post" data-confirm="Delete the file in &ldquo;' . du_h($s['label']) . '&rdquo;? This cannot be undone." data-saving>' . $hidden
                    . '<input type="hidden" name="action" value="slot_delete"><input type="hidden" name="design_id" value="' . (int)$design['id'] . '"><input type="hidden" name="slot" value="' . du_h($slot) . '">'
                    . '<button class="btn btn-small btn-danger-ghost" type="submit">Delete</button></form></div>';
            } else {
                $html .= '<p class="slot-empty">No file uploaded</p>';
            }
            $accept = FILE_TYPES[$s['type']][0] ? implode(',', array_map(function ($e) { return '.' . $e; }, FILE_TYPES[$s['type']][0])) : '';
            $html .= '<form method="post" enctype="multipart/form-data" class="slot-upload" data-saving>' . $hidden
                . '<input type="hidden" name="action" value="slot_upload"><input type="hidden" name="design_id" value="' . (int)$design['id'] . '"><input type="hidden" name="slot" value="' . du_h($slot) . '">'
                . '<input type="file" name="file" required' . ($accept ? ' accept="' . $accept . '"' : '') . ' aria-label="File for ' . du_h($s['label']) . '">'
                . '<button class="btn btn-small' . ($f ? '' : ' btn-primary') . '" type="submit">' . ($f ? 'Replace' : 'Upload') . '</button>'
                . '<small>' . du_h(slot_rules_text($s['type'])) . '</small></form></div>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}

function slot_rules_text($type) {
    [$exts, $mb] = FILE_TYPES[$type];
    return ($exts ? implode(', ', array_map(function ($e) { return '.' . $e; }, $exts)) : 'Any 3D / source file') . ' · up to ' . $mb . ' MB';
}

/** The "Design History" timeline (who posted, designed, reviewed, approved, published). */
function render_design_history(array $d) {
    $names = function ($table, $id) {
        if (!$id) return null;
        return du_q("SELECT name FROM $table WHERE id = ?", [(int)$id])->fetchColumn() ?: null;
    };
    $steps = [];
    if ($d['brief_id'] || $d['designed_by']) {
        $steps[] = ['Brief posted by', $names('staff', $d['brief_created_by']), $d['brief_created_at']];
        $steps[] = ['Designed by', $d['designed_by'] ? $names('freelancers', $d['designed_by']) . ' (Design Creator)' : null, $d['designed_at'], 'submitted'];
        $steps[] = ['Reviewed by', $names('staff', $d['reviewed_by']), $d['reviewed_at']];
        $steps[] = ['Approved by', $names('staff', $d['approved_by']), $d['approved_at']];
        $steps[] = ['Published by', $names('staff', $d['standardized_by']), $d['published_at']];
    } else {
        $steps[] = ['Direct upload by admin', $names('staff', $d['brief_created_by']), $d['brief_created_at']];
        $steps[] = ['Published by', $names('staff', $d['approved_by']), $d['published_at']];
    }
    $html = '<ol class="history">';
    foreach ($steps as $s) {
        $done = !empty($s[2]) || !empty($s[1]);
        $html .= '<li class="' . ($done ? 'done' : 'todo') . '"><span class="h-what">' . du_h($s[0]) . ': <strong>' . du_h($s[1] ?: ($done ? '—' : 'Not yet')) . '</strong></span>'
            . '<span class="h-when">' . (!empty($s[2]) ? (isset($s[3]) ? du_h($s[3]) . ' ' : '') . dui_date($s[2]) : '') . '</span></li>';
    }
    $html .= '<li class="' . ($d['published_at'] ? 'done' : 'todo') . '"><span class="h-what">Published on</span><span class="h-when">'
        . ($d['published_at'] ? dui_date($d['published_at']) : 'Not published yet (draft)') . '</span></li>';
    $html .= '<li class="' . ($d['design_code'] ? 'done' : 'todo') . '"><span class="h-what">Design code: <strong>' . du_h($d['design_code'] ?: 'given when published') . '</strong></span><span class="h-when"></span></li>';
    return $html . '</ol>';
}

/** Internal notes (never shown to customers) with an "add note" form. */
function render_order_notes(array $order, $hidden) {
    $notes = order_notes($order);
    $html = '<div class="notes-list">';
    if (!$notes) $html .= '<p class="empty">No notes yet.</p>';
    foreach (array_reverse($notes) as $n) {
        $html .= '<div class="note-item"><div class="note-meta"><strong>' . du_h($n['name'] ?? '') . '</strong> &#183; ' . dui_date($n['at'] ?? '', true) . '</div>'
            . '<div class="note-body">' . nl2br(du_h($n['text'] ?? '')) . '</div></div>';
    }
    return $html . '</div><form method="post" class="note-form" data-saving>' . $hidden
        . '<input type="hidden" name="action" value="order_note"><input type="hidden" name="order_id" value="' . (int)$order['id'] . '">'
        . '<label for="noteText">Add a note <span class="muted">(only the team sees these)</span></label>'
        . '<textarea id="noteText" name="note" rows="3" maxlength="5000" required placeholder="For call-back orders: write down what the customer asked for on the call."></textarea>'
        . '<div class="adm-actions left"><button class="btn btn-primary" type="submit">Save notes</button></div></form>';
}

/** Files staff attach to an order (the modified designs delivered to the customer). */
function render_order_files(array $order, $hidden, $serveBase) {
    $files = order_files_list($order['id']);
    $html = '';
    if (!$files) $html .= '<p class="empty">No files attached yet.</p>';
    else {
        $html .= '<ul class="order-files">';
        foreach ($files as $f) {
            $html .= '<li><div><strong>' . du_h($f['file_name']) . '</strong><span class="muted">' . du_h(human_size((int)$f['file_size'])) . ' &#183; '
                . dui_date($f['uploaded_at']) . ' &#183; ' . du_h($f['staff_name']) . '</span></div>'
                . '<a class="btn btn-small" href="' . du_h($serveBase . 'serve-file.php?order_file=' . (int)$f['id']) . '">Download</a></li>';
        }
        $html .= '</ul>';
    }
    return $html . '<form method="post" enctype="multipart/form-data" class="slot-upload order-upload" data-saving>' . $hidden
        . '<input type="hidden" name="action" value="order_file"><input type="hidden" name="order_id" value="' . (int)$order['id'] . '">'
        . '<input type="file" name="file" required accept=".' . implode(',.', ORDER_FILE_EXTENSIONS) . '" aria-label="File for this order">'
        . '<button class="btn btn-small btn-primary" type="submit">Upload</button><small>.pdf, images, .dwg, .dxf or .zip · up to ' . ORDER_FILE_MAX_MB . ' MB</small></form>';
}

/** "Assigned to ... (auto-assigned — reason)" plus the overload warning. */
function render_assignment_note(array $order) {
    $html = '';
    if (!empty($order['assignment_reason'])) $html .= '<p class="assign-reason">' . du_h($order['assignment_reason']) . '</p>';
    if (!empty($order['overload_warning'])) $html .= '<p class="overload-warn">All team members have a lot of active orders right now. You might want to redistribute some work.</p>';
    return $html;
}
