<?php
// Design Creator (freelancer) profiles: labels, validation, rate limits and the emails sent
// about registrations. Shared by register.php, login.php, api/check-email.php,
// freelancer/ and admin/. Only defines functions.

require_once __DIR__ . '/design_utils.php'; // db.php, getSettingValue()
require_once __DIR__ . '/security.php';     // password rules

const QUALIFICATIONS = [
    'b_arch' => 'B.Arch (Bachelor of Architecture)',
    'diploma_arch' => 'Diploma in Architecture',
    'm_arch' => 'M.Arch (Master of Architecture)',
    'civil_engineering' => 'Civil Engineering (B.E. / B.Tech)',
    'interior_design' => 'Interior Design',
    'student' => 'Student — currently studying',
    'other' => 'Other',
];
const EXPERIENCE_LEVELS = ['0_1' => 'Less than 1 year', '1_3' => '1–3 years', '3_5' => '3–5 years', '5_plus' => 'More than 5 years'];
const FREELANCER_STATUS = ['pending' => 'Waiting for review', 'active' => 'Active', 'rejected' => 'Not approved', 'suspended' => 'Suspended'];
const ABOUT_MIN = 50;
const ABOUT_MAX = 3000;
const PLANZAA_CONTACT_EMAIL = 'info@planzaa.in';
const PLANZAA_CONTACT_PHONE = '+91-8920218394';

function fp_q($sql, array $params = []) { return du_q($sql, $params); }

function phase8_ready() {
    static $ready = null;
    if ($ready === null) {
        $cols = fp_q("SHOW COLUMNS FROM freelancers")->fetchAll(PDO::FETCH_COLUMN);
        $tables = fp_q("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $ready = !array_diff(['phone', 'city', 'qualification', 'experience', 'about_me', 'portfolio_link', 'status', 'rejection_reason', 'reviewed_by'], $cols)
              && in_array('rate_limit_log', $tables, true);
    }
    return $ready;
}

function qualification_label(array $f) {
    if (($f['qualification'] ?? '') === 'other' && trim((string)($f['qualification_other'] ?? '')) !== '') return 'Other: ' . $f['qualification_other'];
    return QUALIFICATIONS[$f['qualification'] ?? ''] ?? '—';
}
function experience_label(array $f) { return EXPERIENCE_LEVELS[$f['experience'] ?? ''] ?? '—'; }

/** Is this email used by any login (staff or Design Creator)? $exceptFreelancer skips one creator row. */
function email_taken($email, $exceptFreelancer = 0) {
    $email = strtolower(trim((string)$email));
    if (fp_q("SELECT id FROM staff WHERE email = ?", [$email])->fetchColumn()) return true;
    return (bool)fp_q("SELECT id FROM freelancers WHERE email = ? AND id <> ?", [$email, (int)$exceptFreelancer])->fetchColumn();
}

/** Turns "behance.net/me" into "https://behance.net/me". Returns null if it is not a web address. */
function normalize_portfolio($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    if (strlen($url) > 255 || !filter_var($url, FILTER_VALIDATE_URL)) return null;
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host || strpos($host, '.') === false) return null;
    return $url;
}

/**
 * Checks the profile fields. $withAccount = true also checks email and password (registration).
 * Returns [data, errors] where errors is field => message.
 */
function validate_profile_input(array $in, $withAccount) {
    $s = function ($k) use ($in) { return trim((string)($in[$k] ?? '')); };
    $d = []; $e = [];

    $d['name'] = preg_replace('/\s+/', ' ', $s('name'));
    if ($d['name'] === '') $e['name'] = 'Please type your name.';
    elseif (mb_strlen($d['name']) > 100) $e['name'] = 'Please keep your name under 100 characters.';

    if ($withAccount) {
        $d['email'] = strtolower($s('email'));
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || strlen($d['email']) > 150) $e['email'] = 'Please type a valid email address, like name@gmail.com.';
        elseif (email_taken($d['email'])) $e['email'] = 'An account with this email already exists. Try signing in instead.';
    }

    $d['phone'] = preg_replace('/\D/', '', $s('phone'));
    if (strlen($d['phone']) === 12 && strncmp($d['phone'], '91', 2) === 0) $d['phone'] = substr($d['phone'], 2);
    if (!preg_match('/^[0-9]{10}$/', $d['phone'])) $e['phone'] = 'Please type your 10-digit mobile number.';

    $d['city'] = $s('city');
    if ($d['city'] === '') $e['city'] = 'Please type your city or town.';
    elseif (mb_strlen($d['city']) > 100) $e['city'] = 'Please keep the city name shorter.';

    if ($withAccount) {
        $pw = (string)($in['password'] ?? '');
        if ($rule = password_rule_error($pw)) $e['password'] = $rule;
        if (!isset($e['password']) && $pw !== (string)($in['password2'] ?? '')) $e['password2'] = 'The two passwords do not match.';
        $d['password'] = $pw;
    }

    $d['qualification'] = $s('qualification');
    $d['qualification_other'] = null;
    if (!isset(QUALIFICATIONS[$d['qualification']])) $e['qualification'] = 'Please choose your qualification.';
    elseif ($d['qualification'] === 'other') {
        $d['qualification_other'] = $s('qualification_other');
        if ($d['qualification_other'] === '') $e['qualification_other'] = 'Please tell us your qualification.';
        elseif (mb_strlen($d['qualification_other']) > 100) $e['qualification_other'] = 'Please keep this under 100 characters.';
    }

    $d['experience'] = $s('experience');
    if (!isset(EXPERIENCE_LEVELS[$d['experience']])) $e['experience'] = 'Please choose your years of experience.';

    $d['about_me'] = $s('about_me');
    $len = mb_strlen($d['about_me']);
    if ($len < ABOUT_MIN) $e['about_me'] = 'Please write at least ' . ABOUT_MIN . ' characters about yourself (' . $len . ' so far).';
    elseif ($len > ABOUT_MAX) $e['about_me'] = 'Please keep this under ' . ABOUT_MAX . ' characters.';

    $link = normalize_portfolio($s('portfolio_link'));
    if ($link === null) $e['portfolio_link'] = 'Please type a full web address, like https://behance.net/yourname.';
    $d['portfolio_link'] = $link ?: null;

    return [$d, $e];
}

// ---- Rate limiting (by IP address) ---------------------------------------------------------
function client_ip() {
    // REMOTE_ADDR only: forwarded-for headers can be faked by anyone.
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}
function rate_limited($action, $max, $seconds) {
    $n = (int)fp_q("SELECT COUNT(*) FROM rate_limit_log WHERE action = ? AND ip = ? AND created_at > (NOW() - INTERVAL ? SECOND)",
        [$action, client_ip(), (int)$seconds])->fetchColumn();
    return $n >= $max;
}
function rate_log($action) {
    fp_q("INSERT INTO rate_limit_log (action, ip) VALUES (?, ?)", [$action, client_ip()]);
    if (random_int(1, 50) === 1) fp_q("DELETE FROM rate_limit_log WHERE created_at < (NOW() - INTERVAL 2 DAY)");
}

// ---- Emails -------------------------------------------------------------------------------
function fp_site_url() { return rtrim((string)getSettingValue(getDB(), 'site_url', 'https://test.planzaa.in'), '/'); }

function fp_mail($to, $subject, $body) {
    $to = trim((string)$to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject)); // no header injection
    $headers = "From: noreply@planzaa.in\r\nReply-To: " . PLANZAA_CONTACT_EMAIL . "\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

function email_admin_new_registration(array $f) {
    $one = function ($s) { return trim(preg_replace('/[\r\n]+/', ' ', (string)$s)); };
    $body = "A new Design Creator has registered on Planzaa.\n\n"
        . "Name: " . $one($f['name']) . "\n"
        . "Email: " . $one($f['email']) . "\n"
        . "Phone: " . $one($f['phone']) . "\n"
        . "City: " . $one($f['city']) . "\n"
        . "Qualification: " . $one(qualification_label($f)) . "\n"
        . "Experience: " . experience_label($f) . "\n"
        . "Portfolio: " . ($f['portfolio_link'] ? $one($f['portfolio_link']) : 'Not provided') . "\n\n"
        . "Review their profile: " . fp_site_url() . "/admin/?tab=freelancers\n";
    return fp_mail(getSettingValue(getDB(), 'admin_notification_email', ''), 'New Design Creator registration — ' . $one($f['name']), $body);
}

function email_freelancer_approved(array $f) {
    $body = "Hi {$f['name']},\n\n"
        . "Your Design Creator account on Planzaa has been approved! You can now sign in and start claiming design briefs.\n\n"
        . "Sign in here: " . fp_site_url() . "/login.php?type=freelancer\n\n"
        . "Welcome to the team!\n— Planzaa\n";
    return fp_mail($f['email'], 'Welcome to Planzaa — your account is active!', $body);
}

function email_freelancer_rejected(array $f, $reason) {
    $body = "Hi {$f['name']},\n\n"
        . "Thank you for your interest in joining Planzaa as a Design Creator. After reviewing your profile, we're unable to approve your registration at this time.\n\n"
        . "Reason: {$reason}\n\n"
        . "If you think this is a mistake or you have additional qualifications to share, please reply to this email or contact us at " . PLANZAA_CONTACT_EMAIL . ".\n\n"
        . "— Planzaa\n";
    return fp_mail($f['email'], 'Planzaa registration update', $body);
}
