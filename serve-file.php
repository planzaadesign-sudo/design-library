<?php
// The only way design and order files are downloaded (uploads/designs/ denies all direct access).
//
//   GET  ?design_id=&slot=preview_plan|preview_elevation   public, published designs only
//   GET  ?design_id=&slot=...  [&download=1]                any signed-in staff member
//   GET  ?order_file=ID                                     any signed-in staff member
//   POST design_id, slot, order_code, phone                 customer: customer-visible slot of a
//                                                           design on their DELIVERED order
//   POST order_file, order_code, phone                      customer: a file on their delivered order
//
// Customers have no login, so (as on the tracking page) the order code + phone number together
// prove who they are. They are sent in the POST body, never in the URL. There is no online
// payment yet, so "delivered" is the proof of purchase.
require_once __DIR__ . '/auth.php'; // starts the session
require_once __DIR__ . '/includes/design_utils.php';

function deny($code, $msg) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg);
}

if (!phase7_ready()) deny(404, 'Not found.');

$isStaff = !empty($_SESSION['staff_id']);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$in = $isPost ? $_POST : $_GET;

// A customer's delivered order, if the code + phone match (POST only).
$customerOrder = function () use ($isPost) {
    if (!$isPost) return null;
    $code = strtoupper(trim((string)($_POST['order_code'] ?? '')));
    $phone = preg_replace('/\D/', '', (string)($_POST['phone'] ?? ''));
    if (!preg_match('/^PZL-[0-9A-F]{6}$/', $code) || !preg_match('/^[0-9]{10}$/', $phone)) return null;
    $o = du_q("SELECT id, design_id, status FROM library_orders WHERE order_code = ? AND customer_phone = ?", [$code, $phone])->fetch();
    if (!$o) usleep(400000); // slow down guessing
    return $o ?: null;
};

$file = null; $downloadName = null; $inline = false;

if (!empty($in['order_file'])) {
    $f = du_q("SELECT f.*, o.status, o.order_code FROM order_files f JOIN library_orders o ON o.id = f.order_id WHERE f.id = ?", [(int)$in['order_file']])->fetch();
    if (!$f) deny(404, 'File not found.');
    if (!$isStaff) {
        $o = $customerOrder();
        if (!$o || (int)$o['id'] !== (int)$f['order_id'] || $o['status'] !== 'delivered') deny(403, 'You do not have access to this file.');
    }
    $file = $f['file_path'];
    $downloadName = $f['file_name'];
} else {
    $designId = (int)($in['design_id'] ?? 0);
    $slot = (string)($in['slot'] ?? '');
    if (!$designId || !isset(FILE_SLOTS[$slot])) deny(404, 'File not found.');
    $row = du_q("SELECT f.file_path, f.original_filename, d.is_active, d.published_at, d.design_code FROM design_files f
                 JOIN designs d ON d.id = f.design_id WHERE f.design_id = ? AND f.file_slot = ?", [$designId, $slot])->fetch();
    if (!$row) deny(404, 'File not found.');

    $publicPreview = in_array($slot, PREVIEW_SLOTS, true) && $row['is_active'] && $row['published_at'] !== null;
    if (!$publicPreview && !$isStaff) {
        // Customers: only files meant for them, only for a design on their delivered order.
        if (!FILE_SLOTS[$slot]['customer_visible']) deny(403, 'You do not have access to this file.');
        $o = $customerOrder();
        if (!$o || (int)$o['design_id'] !== $designId || $o['status'] !== 'delivered') deny(403, 'You do not have access to this file.');
    }
    $file = $row['file_path'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $downloadName = ($row['design_code'] ?: 'design-' . $designId) . '-' . $slot . '.' . $ext;
    $inline = $publicPreview && empty($in['download']);
}

// Resolve safely inside uploads/designs/ (the path comes from the database, but check anyway).
$root = design_files_root();
$path = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file));
if (!$path || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) deny(404, 'File not found.');

$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
    'zip' => 'application/zip', 'dwg' => 'application/acad', 'dxf' => 'application/dxf'];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$type = $types[$ext] ?? 'application/octet-stream';
$safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$downloadName);

header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'");
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"');
header($inline ? 'Cache-Control: public, max-age=86400' : 'Cache-Control: private, no-store');
readfile($path);
