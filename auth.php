<?php
// Session start (safe cookie flags, security headers, inactivity timeouts) and the page guards.
// Every page that needs a session includes this file, so the settings live in one place
// (includes/security.php).
require_once __DIR__ . '/includes/security.php';
planzaa_session_start();

// These are only called from admin/, inhouse/, or freelancer/ subfolders,
// so the login page is one level up. Using a relative path (not an absolute
// one starting with /) means this keeps working whether the app lives at
// the domain root (like it does on the test subdomain right now) or inside
// a subfolder like /design-library/ once it moves to the main site.
function requireStaff($role = null) {
    if (empty($_SESSION['staff_id'])) {
        header('Location: ../login.php?type=staff' . (($_SESSION['expired'] ?? '') === 'staff' ? '&expired=1' : ''));
        exit;
    }
    $state = sec_staff_state();
    if ($state === 'gone' || $state === 'replaced') {
        sec_logout();
        header('Location: ../login.php?type=staff' . ($state === 'replaced' ? '&replaced=1' : ''));
        exit;
    }
    if ($state === 'must_change') {
        // A temporary (or forced-reset) password: nothing else until a new one is set.
        header('Location: ../set-password.php');
        exit;
    }
    if ($role !== null && $_SESSION['staff_role'] !== $role && $_SESSION['staff_role'] !== 'admin') {
        // Admins can see everything; a non-admin role can only see their own area.
        http_response_code(403);
        echo 'You do not have access to this page.';
        exit;
    }
}

function requireFreelancer() {
    if (empty($_SESSION['freelancer_id'])) {
        header('Location: ../login.php?type=freelancer' . (($_SESSION['expired'] ?? '') === 'freelancer' ? '&expired=1' : ''));
        exit;
    }
}
