<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// These are only called from admin/, inhouse/, or freelancer/ subfolders,
// so the login page is one level up. Using a relative path (not an absolute
// one starting with /) means this keeps working whether the app lives at
// the domain root (like it does on the test subdomain right now) or inside
// a subfolder like /design-library/ once it moves to the main site.
function requireStaff($role = null) {
    if (empty($_SESSION['staff_id'])) {
        header('Location: ../login.php?type=staff');
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
        header('Location: ../login.php?type=freelancer');
        exit;
    }
}
