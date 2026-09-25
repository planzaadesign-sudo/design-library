<?php
require_once __DIR__ . '/db.php';
$pdo = getDB();

function upsertStaff($pdo, $name, $email, $password, $role) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO staff (name, email, password_hash, role) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = VALUES(role)");
    $stmt->execute([$name, $email, $hash, $role]);
}
function upsertFreelancer($pdo, $name, $email, $password) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO freelancers (name, email, password_hash) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)");
    $stmt->execute([$name, $email, $hash]);
}

upsertStaff($pdo, 'Admin', 'admin@planzaa.in', 'admin123', 'admin');
upsertStaff($pdo, 'Aarav Mehta', 'aarav@planzaa.in', 'inhouse123', 'inhouse');
upsertFreelancer($pdo, 'Aman Verma', 'aman@example.com', 'free123');

// Seed two sample briefs so the freelancer dashboard has something to show,
// using the admin account's real id rather than assuming it's 1.
$adminId = $pdo->query("SELECT id FROM staff WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
$briefCount = $pdo->query("SELECT COUNT(*) FROM briefs")->fetchColumn();
if ($adminId && $briefCount == 0) {
    $stmt = $pdo->prepare("INSERT INTO briefs (title, plot_width, plot_length, facing, house_type, requirements, payout, deadline, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open', ?)");
    $stmt->execute(['30x40 East-facing budget 3BHK', 30, 40, 'East', 'G+1, 3BHK', 'Simple modern elevation, vastu-compliant kitchen in the SE corner, budget-friendly finishes.', 3500, '2026-10-15', $adminId]);
    $stmt->execute(['20x30 compact 2BHK, corner plot', 20, 30, 'West', 'G, 2BHK', 'Corner plot with setback on two sides. Compact staircase, natural light in both bedrooms.', 2800, '2026-10-12', $adminId]);
}

echo "Test accounts created:\n";
echo "Admin      -> admin@planzaa.in / admin123\n";
echo "In-house   -> aarav@planzaa.in / inhouse123\n";
echo "Freelancer -> aman@example.com / free123\n";
echo "\nDelete this file (seed_accounts.php) now that it has run.\n";
