<?php
// CSV of every order. Reached only through admin/index.php?export=orders (admin session required).
if (!defined('PLANZAA_ADMIN')) { http_response_code(403); exit; }

// Spreadsheet apps run cells that start with these characters as formulas ("CSV injection").
// Prefixing a single quote makes them plain text.
function csv_cell($v) {
    $v = (string)$v;
    return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
}

$labels = [];
foreach (q("SELECT id, label FROM modifications")->fetchAll() as $m) $labels[(int)$m['id']] = $m['label'];

$rows = q("SELECT o.*, d.name AS design_name FROM library_orders o JOIN designs d ON d.id = o.design_id ORDER BY o.id")->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="planzaa-orders-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 marker so Excel shows names correctly
fputcsv($out, ['Order code', 'Date', 'Customer name', 'Phone', 'City', 'State', 'District', 'Design name', 'Modifications', 'Structural', 'Total price', 'Status', 'Contact preference']);
foreach ($rows as $o) {
    $ids = array_map('intval', (array)json_decode((string)$o['modifications'], true));
    $mods = implode('; ', array_map(function ($id) use ($labels) { return $labels[$id] ?? ('#' . $id); }, array_filter($ids)));
    $cells = [
        $o['order_code'], $o['created_at'], $o['customer_name'], $o['customer_phone'], $o['customer_city'],
        $o['customer_state'], $o['customer_district'], $o['design_name'], $mods,
        ($o['structural_included'] || $o['structural_addon']) ? 'Yes' : 'No',
        (int)$o['total_price'] . ($o['needs_manual_review'] ? ' (estimate)' : ''),
        STAGE_LABEL[$o['status']] ?? $o['status'],
        ($o['contact_preference'] ?? 'self') === 'call' ? 'Call-back' : 'Self',
    ];
    fputcsv($out, array_map('csv_cell', $cells));
}
fclose($out);
