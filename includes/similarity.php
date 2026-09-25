<?php
// Design similarity engine: scores how alike two sets of design parameters are (0-100),
// so the team does not commission or publish the same house twice.
// Used by the admin and in-house dashboards, the freelancer dashboard and api/similarity-check.php.
// This file only defines things; it outputs nothing if opened directly.

require_once __DIR__ . '/../db.php';

// A brief or design counts as "similar" from this score up (warning shown, confirmation needed).
const SIM_WARN = 70;

// Every compared parameter: [label, weight, kind, extra]
//   kind 'enum'  -> extra = [value => customer-friendly label]; full points on exact match
//   kind 'bool'  -> full points when both are the same
//   kind 'exact' -> plain values (floors, bhk) compared exactly
//   kind 'num'   -> extra = tolerance; full points within it, half within 2x, else zero
// Weights: 6 x 8 (changes the whole layout) + 7 x 5 (big parts) + 11 x 3 (the look) = 116.
const SIM_PARAMS = [
    'plot_shape' => ['Plot shape', 8, 'enum', ['rectangle' => 'Rectangle', 'l_shape' => 'L-shaped', 'corner_cut' => 'Corner cut', 'irregular' => 'Irregular']],
    'entrance_position' => ['Main door', 8, 'enum', ['front_center' => 'Front centre', 'front_left' => 'Front left', 'front_right' => 'Front right', 'side' => 'Side']],
    'staircase_position' => ['Stairs', 8, 'enum', ['front' => 'Front', 'center' => 'Centre', 'rear' => 'Back', 'side' => 'Side']],
    'has_courtyard' => ['Courtyard', 8, 'bool', null],
    'stilt_parking' => ['Stilt parking', 8, 'bool', null],
    'has_ground_shop' => ['Shop on ground floor', 8, 'bool', null],
    'plot_width' => ['Plot width', 5, 'num', 0.10],
    'plot_length' => ['Plot length', 5, 'num', 0.10],
    'floors' => ['Floors', 5, 'exact', null],
    'bhk' => ['Bedrooms', 5, 'exact', null],
    'kitchen_layout' => ['Kitchen', 5, 'enum', ['attached_dining' => 'Kitchen attached to dining', 'separate' => 'Separate kitchen', 'open_plan' => 'Open kitchen-dining']],
    'built_up_area_sqft' => ['Built-up area', 5, 'num', 0.15],
    'ground_coverage_pct' => ['Ground coverage', 5, 'num', 0.10],
    'facing' => ['Facing', 3, 'exact', null],
    'elevation_style' => ['Outside style', 3, 'enum', ['modern_flat' => 'Modern flat roof', 'contemporary' => 'Contemporary mix', 'traditional' => 'Traditional', 'colonial' => 'Colonial', 'minimalist' => 'Minimalist']],
    'elevation_material' => ['Outside material', 3, 'enum', ['texture_paint' => 'Texture paint', 'exposed_brick' => 'Exposed brick', 'stone_cladding' => 'Stone cladding', 'glass_metal' => 'Glass & metal', 'hpl_panels' => 'HPL panels', 'mix' => 'Mix of materials']],
    'roof_type' => ['Roof', 3, 'enum', ['flat' => 'Flat', 'single_slope' => 'Single slope', 'gable' => 'Gable (triangle)', 'hip' => 'Hip (four-sided slope)', 'parapet' => 'Parapet wall only']],
    'balcony_config' => ['Balcony', 3, 'enum', ['front_only' => 'Front only', 'front_rear' => 'Front and back', 'wrap' => 'Wrap-around', 'none' => 'No balcony']],
    'pooja_room' => ['Pooja room', 3, 'enum', ['yes_ne' => 'Yes, in North-East', 'yes_flexible' => 'Yes, anywhere', 'no' => 'No pooja room']],
    'target_segment' => ['Target buyer', 3, 'enum', ['budget' => 'Budget-friendly', 'mid_range' => 'Mid-range', 'premium' => 'Premium']],
    'vastu_level' => ['Vastu', 3, 'enum', ['strict' => 'Strict', 'partial' => 'Partial', 'not_required' => 'Not required']],
    'has_home_office' => ['Home office', 3, 'bool', null],
    'has_servant_quarter' => ['Servant quarter', 3, 'bool', null],
    'is_corner_plot' => ['Corner plot', 3, 'bool', null],
];

// Columns the phase 5 SQL adds (the base ones -- width, length, facing -- exist already).
const SIM_NEW_COLUMNS = [
    'plot_shape', 'is_corner_plot', 'stilt_parking', 'entrance_position', 'staircase_position', 'kitchen_layout',
    'pooja_room', 'balcony_config', 'elevation_style', 'elevation_material', 'roof_type', 'target_segment',
    'vastu_level', 'has_courtyard', 'has_home_office', 'has_servant_quarter', 'has_ground_shop',
    'built_up_area_sqft', 'ground_coverage_pct',
];

function sim_total_weight() {
    $t = 0;
    foreach (SIM_PARAMS as $p) $t += $p[1];
    return $t; // 116
}

function sim_q($sql, array $params = []) {
    $st = getDB()->prepare($sql);
    $i = 1;
    foreach ($params as $v) $st->bindValue($i++, $v, is_int($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
    $st->execute();
    return $st;
}

// True once schema-phase5.sql has been run (pages check this before using the new columns).
function phase5_ready() {
    static $ready = null;
    if ($ready === null) {
        $b = sim_q("SHOW COLUMNS FROM briefs")->fetchAll(PDO::FETCH_COLUMN);
        $d = sim_q("SHOW COLUMNS FROM designs")->fetchAll(PDO::FETCH_COLUMN);
        $ready = !array_diff(array_merge(SIM_NEW_COLUMNS, ['floors', 'bhk', 'differentiation_notes']), $b)
              && !array_diff(SIM_NEW_COLUMNS, $d);
    }
    return $ready;
}

// Customer-friendly text for one parameter value.
function param_label($key, $value) {
    $p = SIM_PARAMS[$key] ?? null;
    if ($value === null || $value === '') return '—';
    if (!$p) return (string)$value;
    if ($p[2] === 'enum') return $p[3][$value] ?? (string)$value;
    if ($p[2] === 'bool') return $value ? 'Yes' : 'No';
    if ($key === 'plot_width' || $key === 'plot_length') return (int)$value . ' ft';
    if ($key === 'built_up_area_sqft') return number_format((int)$value) . ' sq ft';
    if ($key === 'ground_coverage_pct') return (int)$value . '%';
    if ($key === 'bhk') return (int)$value . ' BHK';
    return (string)$value;
}

// Points one parameter earns (0 .. weight), comparing B against A.
function sim_points($key, $a, $b) {
    [, $weight, $kind, $extra] = SIM_PARAMS[$key];
    if ($kind === 'num') {
        if ($a === null || $b === null || $a === '' || $b === '' || (float)$a <= 0 || (float)$b <= 0) return 0.0; // unknown -> no credit
        $diff = abs((float)$a - (float)$b) / (float)$a;
        if ($diff <= $extra + 1e-9) return (float)$weight;
        if ($diff <= 2 * $extra + 1e-9) return $weight / 2;
        return 0.0;
    }
    if ($kind === 'bool') return ((int)!empty($a) === (int)!empty($b)) ? (float)$weight : 0.0;
    if ($a === null || $b === null || $a === '' || $b === '') return 0.0;
    return strtolower((string)$a) === strtolower((string)$b) ? (float)$weight : 0.0;
}

/**
 * Weighted similarity of two parameter sets, 0-100 (100 = identical on every parameter).
 * Returns ['score' => int, 'matched' => [labels], 'close' => [labels], 'matched_keys' => [keys]].
 */
function calculateSimilarity(array $designA, array $designB) {
    $got = 0.0;
    $matched = []; $close = []; $keys = [];
    foreach (SIM_PARAMS as $key => $p) {
        $pts = sim_points($key, $designA[$key] ?? null, $designB[$key] ?? null);
        $got += $pts;
        if ($pts >= $p[1]) { $matched[] = $p[0]; $keys[] = $key; }
        elseif ($pts > 0) { $close[] = $p[0]; $keys[] = $key; }
    }
    return [
        'score' => (int)round($got / sim_total_weight() * 100),
        'matched' => $matched, 'close' => $close, 'matched_keys' => $keys,
    ];
}

// Columns fetched for comparison.
function sim_select_cols($alias) {
    $cols = array_merge(['id', 'plot_width', 'plot_length', 'facing', 'floors', 'bhk'], SIM_NEW_COLUMNS);
    return implode(', ', array_map(function ($c) use ($alias) { return "$alias.$c"; }, $cols));
}

function sim_rank(array $params, array $rows, $limit, $minScore) {
    $out = [];
    foreach ($rows as $r) {
        $s = calculateSimilarity($params, $r);
        if ($s['score'] < $minScore) continue;
        $out[] = array_merge($s, ['id' => (int)$r['id'], 'row' => $r]);
    }
    usort($out, function ($x, $y) { return $y['score'] <=> $x['score'] ?: $x['id'] <=> $y['id']; });
    return array_slice($out, 0, $limit);
}

/**
 * Most similar active designs. Each result: id, name, score, matched, close, matched_keys, row.
 */
function findSimilarDesigns(array $params, $excludeDesignId = null, $limit = 5, $minScore = 0) {
    // Every active design is compared. A pre-filter on plot size/floors/bedrooms was tried but it can
    // drop real duplicates (those columns are only 20 of 116 points); a full pass over 10,000 designs
    // takes well under a second, so correctness wins.
    $bind = [];
    $where = 'd.is_active = 1';
    if ($excludeDesignId) { $where .= ' AND d.id <> ?'; $bind[] = (int)$excludeDesignId; }
    $rows = sim_q("SELECT " . sim_select_cols('d') . ", d.name, d.variant, d.base_price FROM designs d WHERE $where", $bind)->fetchAll();
    $ranked = sim_rank($params, $rows, $limit, $minScore);
    foreach ($ranked as &$r) { $r['name'] = $r['row']['name']; $r['kind'] = 'design'; }
    unset($r);
    return $ranked;
}

/**
 * Most similar briefs that are not published yet. Each result also has status.
 */
function findSimilarBriefs(array $params, $excludeBriefId = null, $limit = 5, $minScore = 0, array $statuses = []) {
    $bind = [];
    $where = "b.status <> 'published'";
    if ($excludeBriefId) { $where .= ' AND b.id <> ?'; $bind[] = (int)$excludeBriefId; }
    if ($statuses) {
        $where .= ' AND b.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        foreach ($statuses as $s) $bind[] = (string)$s;
    }
    $rows = sim_q("SELECT " . sim_select_cols('b') . ", b.title, b.status FROM briefs b WHERE $where", $bind)->fetchAll();
    $ranked = sim_rank($params, $rows, $limit, $minScore);
    foreach ($ranked as &$r) { $r['name'] = $r['row']['title']; $r['kind'] = 'brief'; $r['status'] = $r['row']['status']; }
    unset($r);
    return $ranked;
}
