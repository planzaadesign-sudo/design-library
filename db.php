<?php
require_once __DIR__ . '/config.php';

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
    return $pdo;
}

// Same schematic floor-plan sketch used in the prototype, generated server-side
// so no image files are needed for the sample designs.
function floorPlanArt($variant) {
    $layouts = [
        '<rect x="6" y="6" width="50" height="38" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="56" y="6" width="38" height="20" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="6" y="44" width="88" height="20" fill="none" stroke="currentColor" stroke-width="1.5"/>',
        '<rect x="6" y="6" width="30" height="58" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="36" y="6" width="30" height="58" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="66" y="6" width="28" height="58" fill="none" stroke="currentColor" stroke-width="1.5"/>',
        '<rect x="6" y="6" width="44" height="30" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="50" y="6" width="44" height="30" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="6" y="36" width="44" height="28" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="50" y="36" width="44" height="28" fill="none" stroke="currentColor" stroke-width="1.5"/>',
    ];
    $l = $layouts[$variant % count($layouts)];
    return '<svg width="100%" height="72" viewBox="0 0 100 64" role="img" aria-label="Floor plan sketch">' . $l . '</svg>';
}

function elevationArt() {
    return '<svg width="100%" height="150" viewBox="0 0 200 150" role="img" aria-label="Elevation sketch">'
        . '<path d="M20 70 L100 20 L180 70" fill="none" stroke="currentColor" stroke-width="2"/>'
        . '<rect x="30" y="70" width="140" height="60" fill="none" stroke="currentColor" stroke-width="2"/>'
        . '<rect x="90" y="95" width="20" height="35" fill="none" stroke="currentColor" stroke-width="1.5"/>'
        . '<rect x="45" y="90" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5"/>'
        . '<rect x="135" y="90" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5"/>'
        . '<line x1="20" y1="130" x2="180" y2="130" stroke="currentColor" stroke-width="1.5"/>'
        . '</svg>';
}

function structArt() {
    return '<svg width="100%" height="190" viewBox="0 0 260 190" role="img" aria-label="Structural drawing sample">'
        . '<g stroke="currentColor" stroke-width="1.5" fill="none">'
        . '<line x1="20" y1="16" x2="20" y2="116"/><line x1="90" y1="16" x2="90" y2="116"/><line x1="160" y1="16" x2="160" y2="116"/>'
        . '<line x1="20" y1="16" x2="160" y2="16"/><line x1="20" y1="66" x2="160" y2="66"/><line x1="20" y1="116" x2="160" y2="116"/>'
        . '</g>'
        . '<g fill="currentColor">'
        . '<rect x="16" y="12" width="8" height="8"/><rect x="86" y="12" width="8" height="8"/><rect x="156" y="12" width="8" height="8"/>'
        . '<rect x="16" y="62" width="8" height="8"/><rect x="86" y="62" width="8" height="8"/><rect x="156" y="62" width="8" height="8"/>'
        . '<rect x="16" y="112" width="8" height="8"/><rect x="86" y="112" width="8" height="8"/><rect x="156" y="112" width="8" height="8"/>'
        . '</g>'
        . '<text x="90" y="134" text-anchor="middle" font-size="9" fill="currentColor">pillars (columns) &amp; beams</text>'
        . '</svg>';
}
