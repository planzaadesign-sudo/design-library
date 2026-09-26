<?php
// ONE-TIME setup for a brand-new database: creates the first admin, one in-house account,
// one Design Creator and two sample briefs. Delete this file from the server after running it.
//
// Safety: it does nothing at all once any staff account exists, and it never changes an existing
// password. (An older version overwrote passwords, so anyone who opened this page could reset
// the admin password.) Staff accounts are created with "must change password", so the passwords
// below only work until the first sign-in.
require_once __DIR__ . '/db.php';
$pdo = getDB();
header('Content-Type: text/plain; charset=UTF-8');

if ((int)$pdo->query("SELECT COUNT(*) FROM staff")->fetchColumn() > 0) {
    http_response_code(403);
    echo "Accounts already exist, so this script did nothing.\nDelete seed_accounts.php from the server.\n";
    exit;
}

$staffCols = $pdo->query("SHOW COLUMNS FROM staff")->fetchAll(PDO::FETCH_COLUMN);
$mustChange = in_array('must_change_password', $staffCols, true);

function addStaff($pdo, $name, $email, $password, $role, $mustChange) {
    $sql = $mustChange
        ? "INSERT IGNORE INTO staff (name, email, password_hash, role, must_change_password) VALUES (?, ?, ?, ?, 1)"
        : "INSERT IGNORE INTO staff (name, email, password_hash, role) VALUES (?, ?, ?, ?)";
    $pdo->prepare($sql)->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
}
function addCreator($pdo, $name, $email, $password) {
    $pdo->prepare("INSERT IGNORE INTO freelancers (name, email, password_hash) VALUES (?, ?, ?)")
        ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
}

addStaff($pdo, 'Admin', 'admin@planzaa.in', 'admin123', 'admin', $mustChange);
addStaff($pdo, 'Aarav Mehta', 'aarav@planzaa.in', 'inhouse123', 'inhouse', $mustChange);
addCreator($pdo, 'Aman Verma', 'aman@example.com', 'free123');

// Two sample briefs so the Creator Studio has something to show,
// using the admin account's real id rather than assuming it's 1.
$adminId = $pdo->query("SELECT id FROM staff WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
$briefCount = $pdo->query("SELECT COUNT(*) FROM briefs")->fetchColumn();
if ($adminId && $briefCount == 0) {
    $stmt = $pdo->prepare("INSERT INTO briefs (title, plot_width, plot_length, facing, house_type, requirements, payout, deadline, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open', ?)");
    $stmt->execute(['30x40 East-facing budget 3BHK', 30, 40, 'East', 'G+1, 3BHK', 'Simple modern elevation, vastu-compliant kitchen in the SE corner, budget-friendly finishes.', 3500, '2026-10-15', $adminId]);
    $stmt->execute(['20x30 compact 2BHK, corner plot', 20, 30, 'West', 'G, 2BHK', 'Corner plot with setback on two sides. Compact staircase, natural light in both bedrooms.', 2800, '2026-10-12', $adminId]);
}

echo "Test accounts created:\n";
echo "Admin          -> admin@planzaa.in / admin123   (must choose a new password at first sign-in)\n";
echo "In-house       -> aarav@planzaa.in / inhouse123 (must choose a new password at first sign-in)\n";
echo "Design Creator -> aman@example.com / free123\n";
echo "\nDelete this file (seed_accounts.php) now that it has run.\n";
