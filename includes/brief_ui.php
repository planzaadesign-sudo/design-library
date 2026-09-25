<?php
// HTML for the expanded brief form, the design parameter fields and the review comparison.
// Shared by admin/ and inhouse/. Only defines functions; outputs nothing if opened directly.

require_once __DIR__ . '/briefs.php';

function bf_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Defaults for a new brief/design (same as the column defaults in schema-phase5.sql).
const BF_DEFAULTS = [
    'plot_shape' => 'rectangle', 'is_corner_plot' => 0, 'facing' => 'East', 'floors' => 'G+1', 'bhk' => 3, 'stilt_parking' => 0,
    'entrance_position' => 'front_center', 'staircase_position' => 'center', 'kitchen_layout' => 'attached_dining', 'pooja_room' => 'no',
    'balcony_config' => 'front_only', 'elevation_style' => 'modern_flat', 'elevation_material' => 'texture_paint', 'roof_type' => 'flat',
    'target_segment' => 'mid_range', 'vastu_level' => 'partial', 'has_courtyard' => 0, 'has_home_office' => 0, 'has_servant_quarter' => 0,
    'has_ground_shop' => 0,
];

const BF_SHAPE_ICONS = [
    'rectangle' => '<rect x="4" y="6" width="20" height="16" rx="1"/>',
    'l_shape' => '<path d="M4 6h10v8h10v8H4z"/>',
    'corner_cut' => '<path d="M4 6h14l6 6v10H4z"/>',
    'irregular' => '<path d="M4 8l9-3 11 4-2 13H6z"/>',
];

// Radio group. $options: value => label, or value => [label, description].
function bf_radio($name, array $options, $current, $style = 'pills', $icons = null) {
    $html = '<div class="opt-' . $style . '" role="radiogroup">';
    foreach ($options as $value => $label) {
        $desc = is_array($label) ? $label[1] : '';
        $label = is_array($label) ? $label[0] : $label;
        $icon = $icons && isset($icons[$value])
            ? '<svg viewBox="0 0 28 28" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true">' . $icons[$value] . '</svg>' : '';
        $html .= '<label><input type="radio" name="' . bf_h($name) . '" value="' . bf_h($value) . '"' . ((string)$current === (string)$value ? ' checked' : '') . ' required>'
            . '<span>' . $icon . '<strong>' . bf_h($label) . '</strong>' . ($desc !== '' ? '<small>' . bf_h($desc) . '</small>' : '') . '</span></label>';
    }
    return $html . '</div>';
}

function bf_toggle($name, $label, $checked) {
    return '<label class="yn"><input type="checkbox" name="' . bf_h($name) . '" value="1"' . ($checked ? ' checked' : '') . '>'
        . '<span class="yn-track" aria-hidden="true"></span><span class="yn-text">' . bf_h($label) . '</span></label>';
}

function bf_q($label, $control, $help = '') {
    return '<div class="bf-q"><div class="bf-label">' . $label . '</div>' . ($help !== '' ? '<p class="bf-help">' . bf_h($help) . '</p>' : '') . $control . '</div>';
}

function bf_opts($key) { return SIM_PARAMS[$key][3]; }

/**
 * The parameter questions (sections 1-5). With $base the plot size, facing, floors and BHK
 * are asked too (brief form); without it they come from the design form itself.
 */
function render_param_sections(array $v, $base = true) {
    $val = function ($k) use ($v) { return array_key_exists($k, $v) && $v[$k] !== null ? $v[$k] : (BF_DEFAULTS[$k] ?? ''); };
    $num = function ($name, $label, $min, $max, $required, $help = '') use ($val) {
        return '<label class="bf-field">' . bf_h($label) . '<input type="number" name="' . $name . '" min="' . $min . '" max="' . $max . '"'
            . ($required ? ' required' : '') . ' value="' . bf_h($val($name)) . '">' . ($help ? '<small>' . bf_h($help) . '</small>' : '') . '</label>';
    };

    $s1 = ($base ? '<div class="bf-row">'
            . $num('plot_width', 'Plot width (feet)', 5, 500, true) . $num('plot_length', 'Plot length (feet)', 5, 500, true) . '</div>' : '')
        . bf_q('Plot shape', bf_radio('plot_shape', bf_opts('plot_shape'), $val('plot_shape'), 'cards', BF_SHAPE_ICONS))
        . bf_toggle('is_corner_plot', 'Is this a corner plot?', $val('is_corner_plot'))
        . ($base ? bf_q('Which way does the plot face?', bf_radio('facing', array_combine(BRIEF_FACINGS, BRIEF_FACINGS), $val('facing'))) : '');

    $s2 = ($base
            ? bf_q('How many floors?', bf_radio('floors', ['G' => 'G (ground only)', 'G+1' => 'G+1', 'G+2' => 'G+2', 'G+3' => 'G+3'], $val('floors')))
            . bf_q('How many bedrooms (BHK)?', bf_radio('bhk', [1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5+'], $val('bhk')))
            : '')
        . '<div class="bf-row">'
        . $num('built_up_area_sqft', 'Built-up area (sq ft)', 100, 100000, false, 'How much of the plot should be built on')
        . $num('ground_coverage_pct', 'Ground coverage (%)', 10, 100, false, 'Usually 60-80% depending on local rules')
        . '</div>'
        . bf_q('Where do the cars park?', bf_radio('stilt_parking', [
            1 => ['Stilt parking', 'The house stands on pillars and cars park underneath it'],
            0 => ['Ground-floor parking', 'A parking area next to or in front of the house'],
        ], (int)$val('stilt_parking'), 'cards'));

    $s3 = bf_q('Where is the main door?', bf_radio('entrance_position', [
            'front_center' => ['Front centre', 'Door in the middle of the front wall'],
            'front_left' => ['Front left', 'Door on the left side of the front wall'],
            'front_right' => ['Front right', 'Door on the right side of the front wall'],
            'side' => ['Side entrance', 'Door on a side wall, not the front'],
        ], $val('entrance_position'), 'cards'))
        . bf_q('Where are the stairs?', bf_radio('staircase_position', bf_opts('staircase_position'), $val('staircase_position')))
        . bf_q('How should the kitchen be?', bf_radio('kitchen_layout', [
            'attached_dining' => ['Kitchen attached to dining', 'Kitchen opens into the dining area'],
            'separate' => ['Separate kitchen', 'Kitchen is a closed room of its own'],
            'open_plan' => ['Open kitchen-dining', 'Kitchen and dining are one open space'],
        ], $val('kitchen_layout'), 'cards'))
        . bf_q('Pooja room?', bf_radio('pooja_room', ['yes_ne' => 'Yes, must be in North-East', 'yes_flexible' => 'Yes, anywhere is fine', 'no' => 'No pooja room'], $val('pooja_room')))
        . bf_q('Balcony?', bf_radio('balcony_config', bf_opts('balcony_config'), $val('balcony_config')));

    $s4 = bf_q('What style should the outside have?', bf_radio('elevation_style', [
            'modern_flat' => ['Modern flat roof', 'Clean lines, flat roof, big windows'],
            'contemporary' => ['Contemporary mix', 'Modern shapes with some traditional touches'],
            'traditional' => ['Traditional', 'Sloped roofs, arches, classic Indian details'],
            'colonial' => ['Colonial', 'Columns, balanced front, white trims'],
            'minimalist' => ['Minimalist', 'Very simple, plain surfaces, few details'],
        ], $val('elevation_style'), 'cards'))
        . bf_q('Main outside material', bf_radio('elevation_material', bf_opts('elevation_material'), $val('elevation_material')))
        . bf_q('Roof type', bf_radio('roof_type', bf_opts('roof_type'), $val('roof_type')))
        . bf_q('Who is the house for?', bf_radio('target_segment', [
            'budget' => ['Budget-friendly', 'Simple finishes, lowest building cost'],
            'mid_range' => ['Mid-range', 'Good finishes at a fair cost'],
            'premium' => ['Premium', 'High-end finishes and bigger spaces'],
        ], $val('target_segment'), 'cards'))
        . bf_q('Should the house follow Vastu rules?', bf_radio('vastu_level', [
            'strict' => ['Strict', 'Follow all Vastu rules'],
            'partial' => ['Partial', 'Follow the main rules only'],
            'not_required' => ['Not required', 'Vastu does not matter'],
        ], $val('vastu_level'), 'cards'));

    $s5 = '<div class="yn-list">'
        . bf_toggle('has_courtyard', 'Open-to-sky courtyard inside the house?', $val('has_courtyard'))
        . bf_toggle('has_home_office', 'A home office or study room?', $val('has_home_office'))
        . bf_toggle('has_servant_quarter', 'A servant quarter?', $val('has_servant_quarter'))
        . bf_toggle('has_ground_shop', 'A shop or commercial space on the ground floor?', $val('has_ground_shop'))
        . '</div>';

    $sec = function ($n, $title, $body) { return '<fieldset class="bf-section"><legend><span>' . $n . '</span>' . bf_h($title) . '</legend>' . $body . '</fieldset>'; };
    return $sec(1, 'What does the plot look like?', $s1) . $sec(2, 'How big is the house?', $s2)
        . $sec(3, 'How should the rooms be arranged?', $s3) . $sec(4, 'What should the outside look like?', $s4)
        . $sec(5, 'Any special features?', $s5);
}

/**
 * The full "Post a brief" form. $opts: hidden (HTML of hidden inputs), api (similarity endpoint URL),
 * design_url / brief_url (link prefixes for matches, brief_url optional), cancel (URL).
 */
function render_brief_form(array $v, array $opts) {
    $val = function ($k, $d = '') use ($v) { return $v[$k] ?? $d; };
    return '<form method="post" class="bf" id="briefForm" data-api="' . bf_h($opts['api']) . '" data-design-url="' . bf_h($opts['design_url']) . '"'
        . ' data-brief-url="' . bf_h($opts['brief_url'] ?? '') . '" data-threshold="' . SIM_WARN . '" novalidate>'
        . $opts['hidden']
        . '<input type="hidden" name="confirm_similar" value="">'
        . '<label class="bf-field bf-title">Brief title<input name="title" required maxlength="150" value="' . bf_h($val('title')) . '" placeholder="e.g. 30x40 East-facing 3BHK with courtyard"></label>'
        . render_param_sections($v, true)
        . '<fieldset class="bf-section"><legend><span>6</span>What should be different about this design?</legend>'
        .   '<p class="bf-help">We already have similar designs in our library. Tell the designer what should make THIS one stand out &#8212; a different room arrangement, a unique outside look, a special feature, and so on.</p>'
        .   '<textarea name="differentiation_notes" rows="4" required maxlength="3000">' . bf_h($val('differentiation_notes')) . '</textarea>'
        .   '<label class="bf-field">Anything else the designer should know? <em>(optional)</em><textarea name="requirements" rows="3" maxlength="3000">' . bf_h($val('requirements')) . '</textarea></label>'
        . '</fieldset>'
        . '<fieldset class="bf-section"><legend><span>7</span>Payout and deadline</legend><div class="bf-row">'
        .   '<label class="bf-field">Payout (&#8377;)<input type="number" name="payout" min="1" required value="' . bf_h($val('payout')) . '"></label>'
        .   '<label class="bf-field">Deadline<input type="date" name="deadline" required value="' . bf_h($val('deadline')) . '"></label>'
        . '</div></fieldset>'
        . '<p class="bf-error" id="bfError" role="alert"></p>'
        . '<div class="sim-panel" id="simPanel" hidden></div>'
        . '<div class="bf-actions">' . (!empty($opts['cancel']) ? '<a class="btn" href="' . bf_h($opts['cancel']) . '">Cancel</a>' : '')
        .   '<button class="btn btn-primary" type="submit" id="bfSubmit">Post brief</button></div>'
        . '</form>';
}

function sim_bar($score) {
    $tone = $score > SIM_WARN ? 'high' : ($score >= 50 ? 'mid' : 'low');
    return '<span class="sim-score ' . $tone . '"><span class="sim-bar"><i style="width:' . max(0, min(100, (int)$score)) . '%"></i></span><b>' . (int)$score . '% similar</b></span>';
}

/**
 * Review panel: the brief's 3 closest library designs next to the submission, plus a
 * parameter-by-parameter table. Returns ['html' => ..., 'matches' => [...]].
 */
function render_similarity_review(array $brief, array $sub, $fileBase) {
    $matches = findSimilarDesigns($brief, null, 3);
    $file = (string)($sub['cad_file_path'] ?? '');
    $isImage = (bool)preg_match('/\.(png|jpe?g|webp|gif)$/i', $file);
    $html = '<div class="sim-review"><h4>Similar designs already in the library</h4>';
    if (!$matches) return ['html' => $html . '<p class="bf-help">No similar designs in the library yet.</p></div>', 'matches' => []];

    $html .= '<div class="sim-side">'
        . '<figure class="sim-fig sub"><figcaption>This submission</figcaption>'
        . ($isImage ? '<img src="' . bf_h($fileBase . $file) . '" alt="Submitted design preview" loading="lazy">'
                    : '<div class="sim-noimg">No preview image &#8212; compare with the table below' . ($file ? ' or open the file' : '') . '.</div>')
        . '</figure>';
    foreach ($matches as $m) {
        $html .= '<figure class="sim-fig"><figcaption>' . bf_h($m['name']) . '</figcaption><div class="sim-art">' . floorPlanArt((int)$m['row']['variant']) . '</div>' . sim_bar($m['score']) . '</figure>';
    }
    $html .= '</div><div class="sim-table-wrap"><table class="sim-compare"><thead><tr><th>What</th><th>This brief</th>';
    foreach ($matches as $m) $html .= '<th>' . bf_h($m['name']) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach (SIM_PARAMS as $key => $p) {
        $html .= '<tr><th scope="row">' . bf_h($p[0]) . '</th><td>' . bf_h(param_label($key, $brief[$key] ?? null)) . '</td>';
        foreach ($matches as $m) {
            $pts = sim_points($key, $brief[$key] ?? null, $m['row'][$key] ?? null);
            $mark = $pts >= $p[1] ? '<span class="same" title="Same">&#10003;</span>' : ($pts > 0 ? '<span class="near" title="Close">&#8776;</span>' : '<span class="diff" title="Different">&#10005;</span>');
            $html .= '<td>' . $mark . ' ' . bf_h(param_label($key, $m['row'][$key] ?? null)) . '</td>';
        }
        $html .= '</tr>';
    }
    return ['html' => $html . '</tbody></table></div></div>', 'matches' => $matches];
}

// Confirmation checkbox + "too similar" rejection helper, placed inside a review form.
function render_review_extras(array $matches) {
    $html = '<label class="sim-confirm"><input type="checkbox" name="confirm_different" value="1" data-sim-confirm>'
        . '<span>I confirm this design is different enough from existing designs in our library</span></label>'
        . '<p class="sim-confirm-msg" role="alert" hidden>Please check the similarity confirmation before approving.</p>';
    if ($matches) {
        $html .= '<label class="bf-field reason">Reason for sending back<select data-reject-reason><option value="">Write my own notes</option>';
        foreach ($matches as $m) {
            $what = $m['matched'] ? implode(', ', array_slice($m['matched'], 0, 6)) : 'its layout and look';
            $note = 'This design is too similar to ' . $m['name'] . '. Please review it and make your design noticeably different in: ' . $what . '.';
            $html .= '<option value="' . bf_h($note) . '">Too similar to ' . bf_h($m['name']) . ' (' . (int)$m['score'] . '%)</option>';
        }
        $html .= '</select></label>';
    }
    return $html;
}
