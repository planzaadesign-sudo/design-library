<?php
// Similar designs/briefs for a set of parameters. Staff only (admin and in-house dashboards).
// POST JSON: {params: {...}, type: 'design'|'brief'|'both', exclude_id: optional}
header('Content-Type: application/json');
require_once __DIR__ . '/../auth.php'; // starts the session
require_once __DIR__ . '/../includes/similarity.php';

if (empty($_SESSION['staff_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in again.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}
if (!phase5_ready()) {
    echo json_encode(['ready' => false, 'error' => 'Run schema-phase5.sql to turn on similarity checks.']);
    exit;
}

$started = microtime(true);
$input = json_decode(file_get_contents('php://input'), true);
$raw = is_array($input['params'] ?? null) ? $input['params'] : [];
$type = in_array($input['type'] ?? 'both', ['design', 'brief', 'both'], true) ? ($input['type'] ?? 'both') : 'both';
$exclude = isset($input['exclude_id']) ? (int)$input['exclude_id'] : null;

// Only known parameters, in the shape the engine expects.
$params = [];
foreach (SIM_PARAMS as $key => $p) {
    if (!array_key_exists($key, $raw) || $raw[$key] === '' || $raw[$key] === null) { $params[$key] = $p[2] === 'bool' ? 0 : null; continue; }
    if ($p[2] === 'bool') $params[$key] = empty($raw[$key]) ? 0 : 1;
    elseif ($p[2] === 'num' || $key === 'bhk') $params[$key] = (int)$raw[$key];
    else $params[$key] = mb_substr((string)$raw[$key], 0, 40);
}

$results = [];
if ($type !== 'brief') $results = array_merge($results, findSimilarDesigns($params, $type === 'design' ? $exclude : null, 5));
if ($type !== 'design') $results = array_merge($results, findSimilarBriefs($params, $type === 'brief' ? $exclude : null, 5));
usort($results, function ($a, $b) { return $b['score'] <=> $a['score']; });

echo json_encode([
    'ready' => true,
    'threshold' => SIM_WARN,
    'results' => array_map(function ($r) {
        return [
            'kind' => $r['kind'], 'id' => $r['id'], 'name' => $r['name'], 'score' => $r['score'],
            'matched' => $r['matched'], 'close' => $r['close'], 'status' => $r['status'] ?? null,
        ];
    }, array_slice($results, 0, 5)),
    'ms' => (int)round((microtime(true) - $started) * 1000),
]);
