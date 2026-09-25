<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';

$pdo = getDB();
$width = (isset($_GET['width']) && $_GET['width'] !== '') ? (int)$_GET['width'] : null;
$length = (isset($_GET['length']) && $_GET['length'] !== '') ? (int)$_GET['length'] : null;
$facing = (isset($_GET['facing']) && $_GET['facing'] !== '') ? $_GET['facing'] : null;

$designs = $pdo->query("SELECT * FROM designs WHERE is_active = 1 ORDER BY id")->fetchAll();

$hasPlot = ($width !== null && $length !== null && $facing !== null);
foreach ($designs as &$d) {
    $d['match'] = $hasPlot
        ? ((int)$d['plot_width'] === $width && (int)$d['plot_length'] === $length && $d['facing'] === $facing)
        : null;
    $d['floor_plan_svg'] = floorPlanArt((int)$d['variant']);
}
unset($d);

echo json_encode($designs);
