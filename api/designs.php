<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/design_utils.php';

$pdo = getDB();
$width = (isset($_GET['width']) && $_GET['width'] !== '') ? (int)$_GET['width'] : null;
$length = (isset($_GET['length']) && $_GET['length'] !== '') ? (int)$_GET['length'] : null;
$facing = (isset($_GET['facing']) && $_GET['facing'] !== '') ? $_GET['facing'] : null;

$designs = $pdo->query("SELECT * FROM designs WHERE is_active = 1 ORDER BY id")->fetchAll();

// Uploaded preview thumbnails (public), by design id.
$previews = [];
if (phase7_ready()) {
    foreach (du_q("SELECT design_id, file_slot, UNIX_TIMESTAMP(uploaded_at) AS t FROM design_files WHERE file_slot IN ('preview_plan', 'preview_elevation')")->fetchAll() as $p) {
        $previews[(int)$p['design_id']][$p['file_slot']] = 'serve-file.php?design_id=' . (int)$p['design_id'] . '&slot=' . $p['file_slot'] . '&v=' . (int)$p['t'];
    }
}
// Internal columns (who designed/approved it, etc.) are never sent to the public.
const INTERNAL_DESIGN_COLUMNS = ['brief_id', 'brief_created_by', 'brief_created_at', 'designed_by', 'designed_at', 'reviewed_by', 'reviewed_at',
    'approved_by', 'approved_at', 'standardized_by', 'source_submission_id'];

$hasPlot = ($width !== null && $length !== null && $facing !== null);
foreach ($designs as &$d) {
    foreach (INTERNAL_DESIGN_COLUMNS as $c) unset($d[$c]);
    $d['preview_plan_url'] = $previews[(int)$d['id']]['preview_plan'] ?? null;
    $d['preview_elevation_url'] = $previews[(int)$d['id']]['preview_elevation'] ?? null;
    $d['match'] = $hasPlot
        ? ((int)$d['plot_width'] === $width && (int)$d['plot_length'] === $length && $d['facing'] === $facing)
        : null;
    $d['floor_plan_svg'] = floorPlanArt((int)$d['variant']);
}
unset($d);

echo json_encode($designs);
