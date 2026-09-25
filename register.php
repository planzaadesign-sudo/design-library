<?php
// Public page: designers sign up here. New accounts are saved as 'pending' and can only
// sign in after the admin approves them (Admin -> Freelancers).
require_once __DIR__ . '/includes/freelancer_profile.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function rh($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
if (empty($_SESSION['reg_csrf'])) $_SESSION['reg_csrf'] = bin2hex(random_bytes(32));

const REG_PER_IP_PER_DAY = 3;
$ready = phase8_ready();
$values = [];
$errors = [];
$formError = '';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $values = $_POST;
    unset($values['password'], $values['password2']); // never echoed back into the page
    if (!hash_equals($_SESSION['reg_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $formError = 'This page was open for too long. Please try again — your details are still filled in.';
    } elseif (trim((string)($_POST['website'] ?? '')) !== '') {
        // Hidden field that only bots fill in: act as if it worked, save nothing.
        $_SESSION['reg_done'] = true;
        header('Location: register.php?done=1');
        exit;
    } elseif (rate_limited('register', REG_PER_IP_PER_DAY, 86400)) {
        $formError = 'Too many registrations have been sent from this internet connection today. Please try again tomorrow, or write to us at ' . PLANZAA_CONTACT_EMAIL . '.';
    } else {
        [$d, $errors] = validate_profile_input($_POST, true);
        if (!$errors) {
            try {
                fp_q("INSERT INTO freelancers (name, email, password_hash, phone, city, qualification, qualification_other, experience, about_me, portfolio_link, status)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
                    [$d['name'], $d['email'], password_hash($d['password'], PASSWORD_DEFAULT), $d['phone'], $d['city'], $d['qualification'],
                     $d['qualification_other'], $d['experience'], $d['about_me'], $d['portfolio_link']]);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
                $errors['email'] = 'An account with this email already exists. Try signing in instead.'; // two clicks at once
            }
            if (!$errors) {
                rate_log('register');
                try { email_admin_new_registration($d); } catch (Throwable $e) { error_log('Registration email failed: ' . $e->getMessage()); }
                $_SESSION['reg_done'] = true;
                $_SESSION['reg_csrf'] = bin2hex(random_bytes(32));
                header('Location: register.php?done=1'); // a refresh cannot send the form twice
                exit;
            }
        }
        $formError = 'Please fix the ' . (count($errors) === 1 ? 'highlighted field' : count($errors) . ' highlighted fields') . ' below.';
    }
}

$done = !empty($_GET['done']) && !empty($_SESSION['reg_done']);
if ($done) unset($_SESSION['reg_done']);

$v = function ($k) use ($values) { return rh($values[$k] ?? ''); };
$err = function ($k) use ($errors) { return '<div class="ff-error" id="e_' . $k . '" aria-live="polite">' . rh($errors[$k] ?? '') . '</div>'; };
$cls = function ($k, $extra = '') use ($errors) { return 'ff' . ($extra ? ' ' . $extra : '') . (isset($errors[$k]) ? ' invalid' : ''); };
$aria = function ($k) use ($errors) { return isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="e_' . $k . '"' : ' aria-describedby="e_' . $k . '"'; };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Join our design team &#8212; Planzaa</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Architects, designers and architecture students: register to design house plans with Planzaa.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=20260926a">
<link rel="stylesheet" href="assets/register.css?v=2">
</head>
<body class="site reg-body">
<main class="reg-wrap page-enter">
  <header class="reg-head">
    <a class="brand" href="index.php">planzaa<span>.</span></a>
    <p>Join our design team</p>
  </header>

<?php if ($done): ?>
  <div class="success reg-success" role="status">
    <div class="confetti" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
    <svg class="check-anim" viewBox="0 0 80 80" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <circle cx="40" cy="40" r="36" transform="rotate(-90 40 40)"/><path d="M25 41l10 10 20-22"/></svg>
    <h1>Registration successful!</h1>
    <p class="lead">Our team will review your profile and activate your account within 48 hours.</p>
    <p>You'll be able to <a href="login.php?type=freelancer">sign in here</a> once your account is approved.</p>
    <p class="small">If you have questions, reach us at <a href="mailto:<?= PLANZAA_CONTACT_EMAIL ?>"><?= PLANZAA_CONTACT_EMAIL ?></a> or <a href="tel:<?= str_replace('-', '', PLANZAA_CONTACT_PHONE) ?>"><?= PLANZAA_CONTACT_PHONE ?></a></p>
  </div>

<?php elseif (!$ready): ?>
  <div class="form-card"><h1 class="reg-title">Registration opens soon</h1>
    <p>We are getting the sign-up page ready. Please check back in a little while, or write to us at <a href="mailto:<?= PLANZAA_CONTACT_EMAIL ?>"><?= PLANZAA_CONTACT_EMAIL ?></a>.</p></div>

<?php else: ?>
  <h1 class="reg-title">Design houses with Planzaa</h1>
  <p class="reg-intro">We work with talented architects, designers, and architecture students across India. Register below and our team will review your profile within 48 hours.</p>

  <form class="form-card reg-form" id="regForm" method="post" action="register.php" novalidate>
    <input type="hidden" name="csrf" value="<?= rh($_SESSION['reg_csrf']) ?>">
    <div class="reg-hp" aria-hidden="true"><label>Leave this empty <input name="website" tabindex="-1" autocomplete="off"></label></div>
    <p class="form-error" id="formError" role="alert"><?= rh($formError) ?></p>

    <section class="reg-section">
      <h2>Your details</h2>
      <div class="<?= $cls('name') ?>" id="f_name"><input id="name" name="name" placeholder=" " autocomplete="name" maxlength="100" required value="<?= $v('name') ?>"<?= $aria('name') ?>><label for="name">Your full name</label><?= $err('name') ?></div>
      <div class="<?= $cls('email') ?>" id="f_email"><input id="email" name="email" type="email" placeholder=" " autocomplete="email" maxlength="150" required value="<?= $v('email') ?>"<?= $aria('email') ?>><label for="email">Email address</label><?= $err('email') ?></div>
      <div class="<?= $cls('phone') ?>" id="f_phone"><input id="phone" name="phone" type="tel" inputmode="numeric" placeholder=" " autocomplete="tel-national" maxlength="11" required value="<?= $v('phone') ?>"<?= $aria('phone') ?>><label for="phone">Mobile number</label><?= $err('phone') ?></div>
      <div class="<?= $cls('city') ?>" id="f_city"><input id="city" name="city" placeholder=" " autocomplete="address-level2" maxlength="100" required value="<?= $v('city') ?>"<?= $aria('city') ?>><label for="city">Your city or town</label><?= $err('city') ?></div>
      <div class="<?= $cls('password', 'pw') ?>" id="f_password"><input id="password" name="password" type="password" placeholder=" " autocomplete="new-password" minlength="8" maxlength="200" required<?= $aria('password') ?>><label for="password">Password (at least 8 characters)</label>
        <button type="button" class="pw-toggle" data-for="password" aria-pressed="false">Show</button>
        <div class="pw-meter" id="pwMeter" hidden><span class="pw-bar"><i></i></span><span class="pw-word" id="pwWord"></span></div><?= $err('password') ?></div>
      <div class="<?= $cls('password2', 'pw') ?>" id="f_password2"><input id="password2" name="password2" type="password" placeholder=" " autocomplete="new-password" maxlength="200" required<?= $aria('password2') ?>><label for="password2">Type the password again</label>
        <button type="button" class="pw-toggle" data-for="password2" aria-pressed="false">Show</button><?= $err('password2') ?></div>
    </section>

    <section class="reg-section">
      <h2>Your background</h2>
      <div class="<?= $cls('qualification', 'always') ?>" id="f_qualification"><select id="qualification" name="qualification" required<?= $aria('qualification') ?>>
        <option value="">Choose one</option>
        <?php foreach (QUALIFICATIONS as $k => $label): ?><option value="<?= $k ?>"<?= ($values['qualification'] ?? '') === $k ? ' selected' : '' ?>><?= rh($label) ?></option><?php endforeach; ?>
      </select><label for="qualification">Qualification</label><?= $err('qualification') ?></div>
      <div class="<?= $cls('qualification_other') ?>" id="f_qualification_other"<?= ($values['qualification'] ?? '') === 'other' ? '' : ' hidden' ?>><input id="qualification_other" name="qualification_other" placeholder=" " maxlength="100" value="<?= $v('qualification_other') ?>"<?= $aria('qualification_other') ?>><label for="qualification_other">Please specify your qualification</label><?= $err('qualification_other') ?></div>

      <fieldset class="reg-pills<?= isset($errors['experience']) ? ' invalid' : '' ?>" id="f_experience" aria-describedby="e_experience">
        <legend>Years of experience</legend>
        <div class="pill-row">
        <?php foreach (EXPERIENCE_LEVELS as $k => $label): ?>
          <label class="pill-opt"><input type="radio" name="experience" value="<?= $k ?>" required<?= ($values['experience'] ?? '') === $k ? ' checked' : '' ?>><span><?= rh($label) ?></span></label>
        <?php endforeach; ?>
        </div><?= $err('experience') ?>
      </fieldset>

      <div class="ff-area<?= isset($errors['about_me']) ? ' invalid' : '' ?>" id="f_about_me">
        <label for="about_me">Tell us about yourself</label>
        <p class="reg-q">What kind of designs are you good at? What's your design style?</p>
        <textarea id="about_me" name="about_me" rows="6" minlength="<?= ABOUT_MIN ?>" maxlength="<?= ABOUT_MAX ?>" required<?= $aria('about_me') ?>><?= $v('about_me') ?></textarea>
        <div class="reg-count"><span class="hint">For example: I specialize in modern residential designs for small plots. I've designed 15+ houses in Lucknow and surrounding areas.</span><span id="aboutCount" class="count">0 / <?= ABOUT_MIN ?> minimum</span></div>
        <?= $err('about_me') ?>
      </div>

      <div class="<?= $cls('portfolio_link') ?>" id="f_portfolio_link"><input id="portfolio_link" name="portfolio_link" type="url" inputmode="url" placeholder=" " maxlength="255" value="<?= $v('portfolio_link') ?>"<?= $aria('portfolio_link') ?>><label for="portfolio_link">Portfolio link (optional)</label><?= $err('portfolio_link') ?>
        <p class="reg-help">Link to your portfolio, Behance, Instagram, or any website showing your work. Optional but helps us review your profile faster.</p></div>
    </section>

    <button class="btn btn-primary btn-submit" type="submit" id="submitBtn"><span class="btn-label">Register as a designer</span></button>
    <p class="fine-print">Already registered? <a href="login.php?type=freelancer">Sign in</a></p>
  </form>
  <p class="reg-contact">Questions? <a href="mailto:<?= PLANZAA_CONTACT_EMAIL ?>"><?= PLANZAA_CONTACT_EMAIL ?></a> &#183; <a href="tel:<?= str_replace('-', '', PLANZAA_CONTACT_PHONE) ?>"><?= PLANZAA_CONTACT_PHONE ?></a></p>
<?php endif; ?>
</main>
<?php if ($ready && !$done): ?><script src="assets/register.js?v=2"></script><?php endif; ?>
</body>
</html>
