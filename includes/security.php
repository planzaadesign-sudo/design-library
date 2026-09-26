<?php
// Sign-in security shared by every page that uses a session (through auth.php):
// cookie flags, session timeouts, one admin session at a time, password rules,
// failed sign-in tracking, the "What is 12 + 7?" question and password reset tokens.
// Only defines functions and constants (plus two php.ini settings below).

require_once __DIR__ . '/../db.php';

// Errors go to the server log, never onto the page (they can reveal file paths and SQL).
ini_set('display_errors', '0');
ini_set('log_errors', '1');

const SESSION_TIMEOUT = ['admin' => 1800, 'inhouse' => 3600, 'freelancer' => 3600]; // seconds of inactivity
const PASSWORD_MIN_LENGTH = 10;
const PASSWORD_RULES = [
    'length' => 'At least 10 characters',
    'upper' => 'One uppercase letter (A-Z)',
    'lower' => 'One lowercase letter (a-z)',
    'number' => 'One number (0-9)',
    'special' => 'One special character (!@#$%...)',
];
const LOGIN_IP_MAX_FAILS = 5;        // failed sign-ins from one IP ...
const LOGIN_IP_WINDOW = 900;         // ... within 15 minutes -> that IP waits 15 minutes
const LOGIN_EMAIL_MAX_FAILS = 10;    // failed sign-ins for one email (any IP) ...
const LOGIN_EMAIL_WINDOW = 3600;     // ... within 1 hour -> that account is locked for 1 hour
const LOGIN_CHALLENGE_AFTER = 3;     // failed sign-ins before the math question appears
const RESET_TOKEN_TTL = 3600;        // reset links work for 1 hour
const RESET_PER_EMAIL_PER_HOUR = 3;
const REGISTER_CHALLENGE_AFTER = 2;  // failed registration tries (per IP, last hour) before the math question

function sec_q($sql, array $params = []) {
    $st = getDB()->prepare($sql);
    $i = 1;
    foreach ($params as $v) $st->bindValue($i++, $v, is_int($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
    $st->execute();
    return $st;
}
function sec_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function sec_ip() { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45); }

/** Have the phase 9 tables and columns been created? (Before that, sign-in works the old way.) */
function security_ready() {
    static $ready = null;
    if ($ready === null) {
        try {
            $tables = sec_q("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $cols = sec_q("SHOW COLUMNS FROM staff")->fetchAll(PDO::FETCH_COLUMN);
            $ready = !array_diff(['login_attempts', 'password_reset_tokens'], $tables) && !array_diff(['must_change_password', 'session_token'], $cols);
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

// ---- Session and cookies -------------------------------------------------------------------
function sec_is_https() {
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * Starts the session with safe cookie settings, sends the security headers and signs out
 * anyone who has been inactive for too long. Called once, from auth.php.
 */
function planzaa_session_start() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');   // reject session IDs the server did not create
    ini_set('session.use_only_cookies', '1');  // never put the session ID in URLs
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');   // JavaScript cannot read the cookie
    session_set_cookie_params([
        'lifetime' => 0,               // ends when the browser closes
        'path' => '/',
        'secure' => sec_is_https(),    // HTTPS only on the live site (a local http:// test server cannot use it)
        'httponly' => true,
        'samesite' => 'Strict',        // never sent with requests from other websites
    ]);
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
    session_start();
    sec_apply_timeouts();
}

// Signs out whoever has been idle too long (staff and Design Creator sessions are tracked separately).
function sec_apply_timeouts() {
    $now = time();
    if (!empty($_SESSION['staff_id'])) {
        $limit = SESSION_TIMEOUT[$_SESSION['staff_role'] ?? 'inhouse'] ?? SESSION_TIMEOUT['inhouse'];
        if ($now - (int)($_SESSION['staff_last'] ?? $now) > $limit) {
            sec_forget_staff();
            $_SESSION['expired'] = 'staff';
        } else {
            $_SESSION['staff_last'] = $now;
        }
    }
    if (!empty($_SESSION['freelancer_id'])) {
        if ($now - (int)($_SESSION['freelancer_last'] ?? $now) > SESSION_TIMEOUT['freelancer']) {
            sec_forget_freelancer();
            $_SESSION['expired'] = 'freelancer';
        } else {
            $_SESSION['freelancer_last'] = $now;
        }
    }
}
function sec_forget_staff() {
    foreach (['staff_id', 'staff_name', 'staff_role', 'staff_last', 'staff_token', 'staff_must_change', 'admin_csrf', 'dash_csrf'] as $k) unset($_SESSION[$k]);
}
function sec_forget_freelancer() {
    foreach (['freelancer_id', 'freelancer_name', 'freelancer_last', 'dash_csrf'] as $k) unset($_SESSION[$k]);
}

/** Signs out completely: empties and destroys the session and deletes its cookie. */
function sec_logout() {
    if (session_status() !== PHP_SESSION_ACTIVE) planzaa_session_start();
    if (!empty($_SESSION['staff_id']) && ($_SESSION['staff_role'] ?? '') === 'admin' && security_ready()) {
        sec_q("UPDATE staff SET session_token = NULL WHERE id = ? AND session_token = ?", [(int)$_SESSION['staff_id'], (string)($_SESSION['staff_token'] ?? '')]);
    }
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
    session_destroy();
    session_start();               // a fresh, empty session ...
    session_regenerate_id(true);   // ... with a brand-new ID
}

/** Called right after a correct password: new session ID, login bookkeeping, one admin session. */
function sec_sign_in_staff(array $user) {
    session_regenerate_id(true);
    sec_forget_freelancer();
    $_SESSION['staff_id'] = (int)$user['id'];
    $_SESSION['staff_name'] = $user['name'];
    $_SESSION['staff_role'] = $user['role'];
    $_SESSION['staff_last'] = time();
    if (security_ready()) {
        // Only one active session per admin: a new sign-in replaces the token, which signs out the old browser.
        if ($user['role'] === 'admin') {
            $token = bin2hex(random_bytes(32));
            sec_q("UPDATE staff SET session_token = ? WHERE id = ?", [$token, (int)$user['id']]);
            $_SESSION['staff_token'] = $token;
        }
        $_SESSION['staff_must_change'] = !empty($user['must_change_password']);
    }
}
function sec_sign_in_freelancer(array $user) {
    session_regenerate_id(true);
    sec_forget_staff();
    $_SESSION['freelancer_id'] = (int)$user['id'];
    $_SESSION['freelancer_name'] = $user['name'];
    $_SESSION['freelancer_last'] = time();
}

/**
 * Checks a signed-in staff member against the database on every page:
 * still exists, (admins) still the newest session, and whether a password change is required.
 * Returns null if all is well, or 'gone' / 'replaced' / 'must_change'.
 */
function sec_staff_state() {
    if (!security_ready()) return null;
    $row = sec_q("SELECT role, must_change_password, session_token FROM staff WHERE id = ?", [(int)$_SESSION['staff_id']])->fetch();
    if (!$row) return 'gone';
    if ($row['role'] === 'admin' && !hash_equals((string)$row['session_token'], (string)($_SESSION['staff_token'] ?? ''))) return 'replaced';
    $_SESSION['staff_role'] = $row['role'];
    $_SESSION['staff_must_change'] = (bool)$row['must_change_password'];
    return $row['must_change_password'] ? 'must_change' : null;
}

// ---- Password rules ------------------------------------------------------------------------
/** Keys of PASSWORD_RULES the password does not meet (empty = good). */
function password_problems($pw) {
    $pw = (string)$pw;
    $bad = [];
    if (mb_strlen($pw) < PASSWORD_MIN_LENGTH) $bad[] = 'length';
    if (!preg_match('/[A-Z]/', $pw)) $bad[] = 'upper';
    if (!preg_match('/[a-z]/', $pw)) $bad[] = 'lower';
    if (!preg_match('/[0-9]/', $pw)) $bad[] = 'number';
    if (!preg_match('/[^A-Za-z0-9]/', $pw)) $bad[] = 'special';
    return $bad;
}
/** One friendly sentence for a password that breaks the rules, or null. */
function password_rule_error($pw) {
    $bad = password_problems($pw);
    if (!$bad) return strlen($pw) > 200 ? 'Please use a shorter password (up to 200 characters).' : null;
    return 'Your password needs: ' . implode(', ', array_map(function ($k) { return lcfirst(PASSWORD_RULES[$k]); }, $bad)) . '.';
}
/**
 * The live checklist + strength meter shown under a new-password field (assets/password.js fills it in).
 * $inputId: the new password input; $confirmId: the "type it again" input (optional).
 */
function password_rules_html($inputId, $confirmId = '') {
    $html = '<div class="pw-help" data-pw-for="' . sec_h($inputId) . '"' . ($confirmId ? ' data-pw-confirm="' . sec_h($confirmId) . '"' : '') . '>'
        . '<div class="pw-strength" aria-live="polite"><span class="pw-track"><i></i></span><span class="pw-label">Password strength</span></div>'
        . '<ul class="pw-rules">';
    foreach (PASSWORD_RULES as $k => $label) $html .= '<li data-rule="' . $k . '"><span class="pw-tick" aria-hidden="true"></span>' . sec_h($label) . '</li>';
    return $html . '</ul></div>';
}

// ---- Failed sign-in tracking ---------------------------------------------------------------
function login_log($email, $success) {
    sec_q("INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, ?)", [mb_substr(strtolower(trim((string)$email)), 0, 150), sec_ip(), $success ? 1 : 0]);
    if (random_int(1, 100) === 1) sec_q("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 90 DAY)");
}
/** After a successful sign-in the earlier failures for this IP + email no longer count. */
function login_clear($email) {
    sec_q("UPDATE login_attempts SET cleared = 1 WHERE success = 0 AND cleared = 0 AND ip_address = ? AND email = ?", [sec_ip(), strtolower(trim((string)$email))]);
}
/** Seconds this IP must still wait (0 = not blocked). */
// (Times are compared inside the database: PHP and MySQL can run in different time zones.)
function login_ip_wait() {
    $r = sec_q("SELECT COUNT(*) AS n, TIMESTAMPDIFF(SECOND, NOW(), MAX(attempted_at) + INTERVAL ? SECOND) AS wait
                FROM login_attempts WHERE ip_address = ? AND success = 0 AND cleared = 0 AND attempted_at > (NOW() - INTERVAL ? SECOND)",
        [LOGIN_IP_WINDOW, sec_ip(), LOGIN_IP_WINDOW])->fetch();
    return (int)$r['n'] >= LOGIN_IP_MAX_FAILS ? max(1, (int)$r['wait']) : 0; // 15 minutes after the latest failure
}
/** Seconds this account stays locked (0 = not locked). */
function login_email_wait($email) {
    $email = strtolower(trim((string)$email));
    if ($email === '') return 0;
    $r = sec_q("SELECT COUNT(*) AS n, TIMESTAMPDIFF(SECOND, NOW(), MAX(attempted_at) + INTERVAL ? SECOND) AS wait
                FROM login_attempts WHERE email = ? AND success = 0 AND cleared = 0 AND attempted_at > (NOW() - INTERVAL ? SECOND)",
        [LOGIN_EMAIL_WINDOW, $email, LOGIN_EMAIL_WINDOW])->fetch();
    return (int)$r['n'] >= LOGIN_EMAIL_MAX_FAILS ? max(60, (int)$r['wait']) : 0; // an hour after the latest failure
}
/** Recent uncleared failures from this IP (used to decide when to ask the math question). */
function login_recent_ip_fails() {
    return (int)sec_q("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND success = 0 AND cleared = 0 AND attempted_at > (NOW() - INTERVAL ? SECOND)",
        [sec_ip(), LOGIN_IP_WINDOW])->fetchColumn();
}
function minutes_text($seconds) { $m = max(1, (int)ceil($seconds / 60)); return $m . ' minute' . ($m === 1 ? '' : 's'); }

// ---- "What is 12 + 7?" -----------------------------------------------------------------------
// The answer only ever lives in the session on the server; the page only shows the question.
function challenge_new($key) {
    $a = random_int(1, 20); $b = random_int(1, 20);
    $_SESSION['challenge_' . $key] = ['q' => "What is $a + $b?", 'a' => $a + $b];
    return $_SESSION['challenge_' . $key]['q'];
}
function challenge_question($key) { return $_SESSION['challenge_' . $key]['q'] ?? challenge_new($key); }
/** Checks the answer once: every check (right or wrong) uses up the question. */
function challenge_check($key, $answer) {
    $c = $_SESSION['challenge_' . $key] ?? null;
    unset($_SESSION['challenge_' . $key]);
    $answer = trim((string)$answer);
    return $c && $answer !== '' && ctype_digit($answer) && (int)$answer === (int)$c['a'];
}
function challenge_clear($key) { unset($_SESSION['challenge_' . $key]); }
function challenge_html($key, $error = '') {
    return '<div class="math-check' . ($error ? ' invalid' : '') . '"><label for="challenge">Quick check: ' . sec_h(challenge_question($key)) . '</label>'
        . '<input id="challenge" name="challenge" inputmode="numeric" pattern="[0-9]*" maxlength="3" autocomplete="off" required>'
        . '<small>This helps us stop automated sign-in attempts.</small>'
        . ($error ? '<div class="field-error" role="alert">' . sec_h($error) . '</div>' : '') . '</div>';
}

// ---- Password reset tokens -----------------------------------------------------------------
/**
 * Creates a reset token for one account and returns the link text: "{id}.{64 hex}".
 * The database keeps only a password_hash() of the secret part, so a copy of the
 * database cannot be used to reset anyone's password. The id just finds the row.
 */
function reset_token_create($userType, $userId) {
    $secret = bin2hex(random_bytes(32));
    sec_q("INSERT INTO password_reset_tokens (user_type, user_id, token_hash, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL ? SECOND)",
        [$userType, (int)$userId, password_hash($secret, PASSWORD_DEFAULT), RESET_TOKEN_TTL]);
    return getDB()->lastInsertId() . '.' . $secret;
}
/** The valid (unused, unexpired, matching) token row for a link, or null. */
function reset_token_find($token) {
    if (!preg_match('/^([0-9]{1,10})\.([0-9a-f]{64})$/', (string)$token, $m)) return null;
    $row = sec_q("SELECT * FROM password_reset_tokens WHERE id = ? AND used = 0 AND expires_at > NOW()", [(int)$m[1]])->fetch();
    if (!$row || !password_verify($m[2], $row['token_hash'])) return null;
    return $row;
}
/** Uses up this token and every other open token for the same account. */
function reset_tokens_invalidate($userType, $userId) {
    sec_q("UPDATE password_reset_tokens SET used = 1 WHERE user_type = ? AND user_id = ? AND used = 0", [$userType, (int)$userId]);
}
function reset_requests_last_hour($userType, $userId) {
    return (int)sec_q("SELECT COUNT(*) FROM password_reset_tokens WHERE user_type = ? AND user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)", [$userType, (int)$userId])->fetchColumn();
}

/** A small form token for the public sign-in / reset pages (they have no dashboard token). */
function sec_form_token($key) {
    if (empty($_SESSION['form_' . $key])) $_SESSION['form_' . $key] = bin2hex(random_bytes(32));
    return $_SESSION['form_' . $key];
}
function sec_form_ok($key) {
    return !empty($_SESSION['form_' . $key]) && hash_equals($_SESSION['form_' . $key], (string)($_POST['csrf'] ?? ''));
}
