<?php
// Page frame for the small account pages: set-password.php, forgot-password.php, reset-password.php.
// Same look as the sign-in page. Only defines functions.

function auth_page_start($title, $noReferrer = false) {
    return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . sec_h($title) . ' &#8212; Planzaa</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
        . ($noReferrer ? '<meta name="referrer" content="no-referrer">' : '')
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">'
        . '<link rel="stylesheet" href="assets/style.css?v=20260926a"><link rel="stylesheet" href="assets/register.css?v=3"><link rel="stylesheet" href="assets/password.css?v=1">'
        . '</head><body><div class="topbar"><div class="topbar-inner"><div class="wordmark">planzaa<span>.</span> team</div></div></div>'
        . '<div class="wrap"><div class="login-box auth-box">';
}

function auth_page_end($withPasswordJs = false) {
    return '</div></div>' . ($withPasswordJs ? '<script src="assets/password.js?v=1"></script>' : '') . '</body></html>';
}

/** New password + confirm fields with the live checklist. */
function auth_new_password_fields($error = '') {
    return '<div class="field"><label for="new_password">New password</label>'
        . '<input id="new_password" name="new_password" type="password" required minlength="' . PASSWORD_MIN_LENGTH . '" maxlength="200" autocomplete="new-password"></div>'
        . password_rules_html('new_password', 'password2')
        . '<div class="field"><label for="password2">Type the new password again</label>'
        . '<input id="password2" name="password2" type="password" required maxlength="200" autocomplete="new-password"></div>'
        . ($error ? '<div class="error-note" role="alert">' . sec_h($error) . '</div>' : '');
}
