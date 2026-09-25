<?php
// Brief posting and publishing shared by the admin and in-house dashboards.
// Only defines functions; outputs nothing if opened directly.

require_once __DIR__ . '/similarity.php';

const BRIEF_FLOORS = ['G', 'G+1', 'G+2', 'G+3'];
const BRIEF_FACINGS = ['East', 'West', 'North', 'South'];

/**
 * Validates the expanded brief form (or the design parameter fields when $forDesign).
 * Returns [$data, $error]; $data holds every column to store.
 */
function validate_brief_input(array $in, $forDesign = false) {
    $str = function ($k) use ($in) { return trim((string)($in[$k] ?? '')); };
    $optInt = function ($k) use ($in) { $v = trim((string)($in[$k] ?? '')); return $v === '' ? null : (int)$v; };
    $d = [];

    if (!$forDesign) {
        $d['title'] = $str('title');
        if ($d['title'] === '' || mb_strlen($d['title']) > 150) return [null, 'Please give the brief a title (up to 150 characters).'];
        $d['plot_width'] = (int)($in['plot_width'] ?? 0);
        $d['plot_length'] = (int)($in['plot_length'] ?? 0);
        if ($d['plot_width'] < 5 || $d['plot_width'] > 500 || $d['plot_length'] < 5 || $d['plot_length'] > 500) return [null, 'Plot width and length must be between 5 and 500 feet.'];
        $d['facing'] = $str('facing');
        if (!in_array($d['facing'], BRIEF_FACINGS, true)) return [null, 'Please choose which way the plot faces.'];
        $d['floors'] = $str('floors');
        if (!in_array($d['floors'], BRIEF_FLOORS, true)) return [null, 'Please choose the number of floors.'];
        $d['bhk'] = (int)($in['bhk'] ?? 0);
        if ($d['bhk'] < 1 || $d['bhk'] > 5) return [null, 'Please choose the number of bedrooms.'];
    }

    foreach (SIM_PARAMS as $key => $p) {
        if ($p[2] === 'enum') {
            $v = $str($key);
            if (!array_key_exists($v, $p[3])) return [null, 'Please answer: ' . $p[0] . '.'];
            $d[$key] = $v;
        } elseif ($p[2] === 'bool') {
            $d[$key] = !empty($in[$key]) ? 1 : 0;
        }
    }
    $d['built_up_area_sqft'] = $optInt('built_up_area_sqft');
    if ($d['built_up_area_sqft'] !== null && ($d['built_up_area_sqft'] < 100 || $d['built_up_area_sqft'] > 100000)) return [null, 'Built-up area must be between 100 and 1,00,000 sq ft.'];
    $d['ground_coverage_pct'] = $optInt('ground_coverage_pct');
    if ($d['ground_coverage_pct'] !== null && ($d['ground_coverage_pct'] < 10 || $d['ground_coverage_pct'] > 100)) return [null, 'Ground coverage must be between 10% and 100%.'];

    if (!$forDesign) {
        $d['differentiation_notes'] = $str('differentiation_notes');
        if ($d['differentiation_notes'] === '') return [null, 'Please tell the designer what should make this design different.'];
        if (mb_strlen($d['differentiation_notes']) > 3000) return [null, 'Please keep "what should be different" shorter.'];
        $extra = $str('requirements');
        if (mb_strlen($extra) > 3000) return [null, 'Please keep the extra notes shorter.'];
        $d['payout'] = (int)($in['payout'] ?? 0);
        if ($d['payout'] < 1) return [null, 'Please enter the payout.'];
        $d['deadline'] = $str('deadline');
        $dt = DateTime::createFromFormat('Y-m-d', $d['deadline']);
        if (!$dt || $dt->format('Y-m-d') !== $d['deadline']) return [null, 'Please choose a deadline date.'];
        // Older screens still read house_type and requirements, so fill both.
        $d['house_type'] = $d['floors'] . ', ' . $d['bhk'] . 'BHK';
        $d['requirements'] = brief_summary($d) . ($extra !== '' ? "\n\n" . $extra : '');
    }
    return [$d, null];
}

// Plain-English summary of the parameters, stored as the brief's requirements text.
function brief_summary(array $d) {
    $lines = [
        $d['plot_width'] . ' x ' . $d['plot_length'] . ' ft plot, ' . $d['facing'] . ' facing, ' . param_label('plot_shape', $d['plot_shape'])
            . ($d['is_corner_plot'] ? ', corner plot' : '') . '.',
        $d['floors'] . ', ' . $d['bhk'] . ' BHK' . ($d['built_up_area_sqft'] ? ', about ' . number_format($d['built_up_area_sqft']) . ' sq ft built-up' : '')
            . ($d['ground_coverage_pct'] ? ', ' . $d['ground_coverage_pct'] . '% ground coverage' : '') . '. '
            . ($d['stilt_parking'] ? 'Stilt parking.' : 'Ground-floor parking.'),
        'Main door: ' . param_label('entrance_position', $d['entrance_position']) . '. Stairs: ' . param_label('staircase_position', $d['staircase_position'])
            . '. ' . param_label('kitchen_layout', $d['kitchen_layout']) . '. Pooja room: ' . param_label('pooja_room', $d['pooja_room'])
            . '. Balcony: ' . param_label('balcony_config', $d['balcony_config']) . '.',
        'Look: ' . param_label('elevation_style', $d['elevation_style']) . ', ' . param_label('elevation_material', $d['elevation_material'])
            . ', ' . param_label('roof_type', $d['roof_type']) . ' roof. For ' . strtolower(param_label('target_segment', $d['target_segment']))
            . ' buyers. Vastu: ' . strtolower(param_label('vastu_level', $d['vastu_level'])) . '.',
    ];
    $features = [];
    foreach (['has_courtyard' => 'open-to-sky courtyard', 'has_home_office' => 'home office', 'has_servant_quarter' => 'servant quarter', 'has_ground_shop' => 'shop on the ground floor'] as $k => $label) {
        if (!empty($d[$k])) $features[] = $label;
    }
    if ($features) $lines[] = 'Special features: ' . implode(', ', $features) . '.';
    return implode("\n", $lines);
}

// Similar designs and briefs for a new brief, most similar first (both lists merged).
function brief_similar_matches(array $params, $limit = 5) {
    $all = array_merge(findSimilarDesigns($params, null, $limit), findSimilarBriefs($params, null, $limit));
    usort($all, function ($a, $b) { return $b['score'] <=> $a['score']; });
    return array_slice($all, 0, $limit);
}

/**
 * Saves a validated brief. Refuses (returns an error) when a design or brief is at least
 * SIM_WARN% similar and the poster has not confirmed -- the browser check can be skipped,
 * this one cannot.
 */
function save_brief(array $d, $staffId, $confirmedSimilar) {
    $top = brief_similar_matches($d, 1);
    if ($top && $top[0]['score'] >= SIM_WARN && !$confirmedSimilar) {
        return 'Similar designs already exist (' . $top[0]['score'] . '% similar to "' . $top[0]['name'] . '"). Please review them before posting.';
    }
    $cols = array_merge(['title', 'plot_width', 'plot_length', 'facing', 'floors', 'bhk', 'house_type', 'requirements', 'payout', 'deadline',
        'differentiation_notes'], SIM_NEW_COLUMNS);
    $vals = array_map(function ($c) use ($d) { return $d[$c]; }, $cols);
    $cols[] = 'created_by';
    $vals[] = (int)$staffId;
    sim_q("INSERT INTO briefs (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")", $vals);
    return null;
}

/**
 * "Standardise & publish": turns an approved submission into a design, copying every
 * parameter from its brief so future similarity checks can compare against it.
 * Returns the new design id, or null if the submission cannot be published.
 */
function publish_submission($subId, $staffId) {
    $ready = phase5_ready();
    $sub = sim_q("SELECT s.*, b.* , s.id AS sub_id, b.id AS brief_id_real FROM submissions s JOIN briefs b ON s.brief_id = b.id WHERE s.id = ?", [(int)$subId])->fetch();
    if (!$sub || $sub['review_status'] !== 'approved' || $sub['published']) return null;

    $price = max(15000, (int)$sub['payout'] * 6);
    // Old briefs have no floors/bhk columns yet: fall back to reading house_type ("G+1, 3BHK").
    $floors = $ready && !empty($sub['floors']) ? $sub['floors'] : (preg_match('/\bG(\+\d)?\b/', (string)$sub['house_type'], $m) ? $m[0] : 'G+1');
    $bhk = $ready && !empty($sub['bhk']) ? (int)$sub['bhk'] : (preg_match('/(\d+)\s*BHK/i', (string)$sub['house_type'], $m) ? (int)$m[1] : 3);
    $row = [
        'name' => $sub['title'], 'plot_width' => $sub['plot_width'] ?: 30, 'plot_length' => $sub['plot_length'] ?: 40,
        'facing' => $sub['facing'] ?: 'East', 'floors' => $floors, 'bhk' => $bhk, 'base_price' => $price, 'delivery_days' => 12,
        'variant' => random_int(0, 2), 'source_submission_id' => (int)$sub['sub_id'], 'standardized_by' => (int)$staffId,
    ];
    if ($ready) foreach (SIM_NEW_COLUMNS as $c) $row[$c] = $sub[$c];

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        sim_q("INSERT INTO designs (" . implode(', ', array_keys($row)) . ") VALUES (" . implode(', ', array_fill(0, count($row), '?')) . ")", array_values($row));
        $designId = (int)$pdo->lastInsertId();
        sim_q("UPDATE submissions SET published = 1 WHERE id = ?", [(int)$sub['sub_id']]);
        sim_q("UPDATE briefs SET status = 'published' WHERE id = ?", [(int)$sub['brief_id_real']]);
        // Illustrative royalty split -- the placeholder rate used since the first demo.
        sim_q("UPDATE freelancers SET earnings = earnings + ? WHERE id = ?", [(int)round($price * 0.1), (int)$sub['freelancer_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $designId;
}

// A brief's parameters as stored (for review screens and the freelancer view).
function brief_params($briefId) {
    return sim_q("SELECT * FROM briefs WHERE id = ?", [(int)$briefId])->fetch() ?: null;
}
