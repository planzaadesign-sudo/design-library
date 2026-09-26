<?php
// Sign out: empty and destroy the session, delete its cookie, start over with a new session ID.
require_once __DIR__ . '/auth.php';
$type = !empty($_SESSION['freelancer_id']) && empty($_SESSION['staff_id']) ? 'freelancer' : 'staff';
sec_logout();
header('Location: login.php?type=' . $type . '&signed_out=1');
exit;
