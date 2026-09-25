<?php
// Library designs similar to one of the signed-in freelancer's claimed briefs, plus how many
// other briefs with similar requirements are being worked on (count only -- no names or details).
// GET ?brief_id=  Freelancers only.
header('Content-Type: application/json');
require_once __DIR__ . '/../auth.php'; // starts the session
require_once __DIR__ . '/../includes/similarity.php';

if (empty($_SESSION['freelancer_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in again.']);
    exit;
}
if (!phase5_ready()) {
    echo json_encode(['ready' => false]);
    exit;
}

$briefId = (int)($_GET['brief_id'] ?? 0);
$brief = sim_q("SELECT * FROM briefs WHERE id = ? AND claimed_by = ?", [$briefId, (int)$_SESSION['freelancer_id']])->fetch();
if (!$brief) {
    http_response_code(404);
    echo json_encode(['error' => 'Brief not found.']);
    exit;
}

$designs = array_map(function ($m) {
    $r = $m['row'];
    return [
        'id' => $m['id'], 'name' => $m['name'], 'score' => $m['score'],
        'floor_plan_svg' => floorPlanArt((int)$r['variant']),
        'plot' => (int)$r['plot_width'] . ' x ' . (int)$r['plot_length'] . ' ft',
        'bhk' => (int)$r['bhk'] . ' BHK', 'floors' => $r['floors'],
        'style' => param_label('elevation_style', $r['elevation_style']),
        'entrance' => param_label('entrance_position', $r['entrance_position']),
    ];
}, findSimilarDesigns($brief, null, 3));

$others = count(findSimilarBriefs($brief, $briefId, 1000, SIM_WARN, ['claimed', 'in_review', 'needs_revision']));

echo json_encode(['ready' => true, 'designs' => $designs, 'similar_briefs' => $others]);
