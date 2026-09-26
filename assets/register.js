// Live checks for the Design Creator registration form (register.php).
// The server checks everything again; this only helps people fix mistakes early.
(function () {
  const form = document.getElementById('regForm');
  if (!form) return;
  const $ = id => document.getElementById(id);
  const ABOUT_MIN = parseInt($('about_me').getAttribute('minlength'), 10) || 50;

  function setState(id, state, msg) {
    const f = $('f_' + id), e = $('e_' + id), input = $(id);
    if (!f) return;
    f.classList.toggle('valid', state === 'valid');
    f.classList.toggle('invalid', state === 'invalid');
    if (e) e.textContent = state === 'invalid' ? (msg || '') : '';
    if (input) input.setAttribute('aria-invalid', state === 'invalid' ? 'true' : 'false');
  }

  // ---- Plain required text fields ----
  const simple = { name: 'Please type your name.', city: 'Please type your city or town.' };
  Object.keys(simple).forEach(id => {
    const el = $(id);
    el.addEventListener('input', () => { el.dataset.touched = '1'; if (el.value.trim()) setState(id, 'valid'); });
    // Only complain about an empty field after something was typed in it (or on submit).
    el.addEventListener('blur', () => { if (el.dataset.touched) setState(id, el.value.trim() ? 'valid' : 'invalid', simple[id]); });
  });
  const checkSimple = id => { const ok = $(id).value.trim() !== ''; setState(id, ok ? 'valid' : 'invalid', simple[id]); return ok; };

  // ---- Email: format, then "is it free?" from api/check-email.php ----
  const email = $('email');
  const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  let emailTaken = false, emailTimer = null, lastChecked = '';
  async function checkEmailFree() {
    const val = email.value.trim().toLowerCase();
    if (!EMAIL_RE.test(val) || val === lastChecked) return;
    lastChecked = val;
    try {
      const res = await fetch('api/check-email.php?email=' + encodeURIComponent(val), { headers: { Accept: 'application/json' } });
      if (!res.ok) return; // rate limited or offline: the server checks again on submit
      const data = await res.json();
      if (email.value.trim().toLowerCase() !== val) return; // they kept typing
      emailTaken = data.available === false;
      setState('email', emailTaken ? 'invalid' : 'valid', 'An account with this email already exists. Try signing in instead.');
    } catch (e) { /* offline: ignore */ }
  }
  function checkEmailFormat() {
    const ok = EMAIL_RE.test(email.value.trim());
    if (!ok) { emailTaken = false; lastChecked = ''; setState('email', 'invalid', 'Please type a valid email address, like name@gmail.com.'); }
    return ok;
  }
  email.addEventListener('input', () => {
    emailTaken = false;
    clearTimeout(emailTimer);
    if (EMAIL_RE.test(email.value.trim())) emailTimer = setTimeout(checkEmailFree, 600);
    else if (email.dataset.touched) setState('email', '');
  });
  email.addEventListener('blur', () => { if (!email.value) return; email.dataset.touched = '1'; if (checkEmailFormat()) { clearTimeout(emailTimer); checkEmailFree(); } });

  // ---- Phone: digits only, shown as XXXXX XXXXX ----
  const phone = $('phone');
  const phoneDigits = () => phone.value.replace(/\D/g, '').replace(/^91(?=\d{10}$)/, '');
  function formatPhone(keepCaret) {
    const caretDigits = phone.value.slice(0, phone.selectionStart || 0).replace(/\D/g, '').length;
    const d = phone.value.replace(/\D/g, '').slice(0, 10);
    phone.value = d.length > 5 ? d.slice(0, 5) + ' ' + d.slice(5) : d;
    if (keepCaret) { const pos = Math.min(caretDigits, 10) + (caretDigits > 5 ? 1 : 0); phone.setSelectionRange(pos, pos); }
  }
  const checkPhone = () => { const ok = /^[0-9]{10}$/.test(phoneDigits()); setState('phone', ok ? 'valid' : 'invalid', 'Please type your 10-digit mobile number.'); return ok; };
  phone.addEventListener('input', () => {
    formatPhone(true);
    if (phoneDigits().length === 10) setState('phone', 'valid');
    else if (phone.dataset.touched) setState('phone', 'invalid', 'Please type your 10-digit mobile number.');
  });
  phone.addEventListener('blur', () => { if (phone.value) { phone.dataset.touched = '1'; checkPhone(); } });
  if (phone.value) formatPhone(false);

  // ---- Passwords: show/hide, strength, live match ----
  document.querySelectorAll('.pw-toggle').forEach(btn => btn.addEventListener('click', () => {
    const input = $(btn.dataset.for);
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? 'Hide' : 'Show';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    btn.setAttribute('aria-label', (show ? 'Hide' : 'Show') + ' password');
  }));
  // The checklist and strength meter under the field come from assets/password.js.
  const pw = $('password'), pw2 = $('password2');
  const pwOk = () => !(window.PlanzaaPassword && window.PlanzaaPassword.problems(pw.value).length) && pw.value.length >= 10;
  const checkPw = () => { const ok = pwOk(); setState('password', ok ? 'valid' : 'invalid', 'Your password does not meet all the rules below yet.'); return ok; };
  const checkPw2 = () => {
    if (!pw2.value) { setState('password2', 'invalid', 'Please type the password again.'); return false; }
    const ok = pw2.value === pw.value; setState('password2', ok ? 'valid' : 'invalid', 'The two passwords do not match.'); return ok;
  };
  pw.addEventListener('input', () => {
    if (pwOk()) setState('password', 'valid'); else if (pw.dataset.touched) setState('password', '');
    if (pw2.value && pw2.value.length >= pw.value.length) checkPw2();
  });
  pw.addEventListener('blur', () => { if (pw.value) { pw.dataset.touched = '1'; checkPw(); } });
  // While typing: say "match" as soon as it does; only say "no match" once it is as long as the first one.
  pw2.addEventListener('input', () => {
    if (pw2.value && pw2.value === pw.value) setState('password2', 'valid');
    else if (pw2.value.length >= pw.value.length && pw2.value) checkPw2();
    else setState('password2', '');
  });
  pw2.addEventListener('blur', () => { if (pw2.value) checkPw2(); });

  // ---- Qualification: "Other" asks for details ----
  const qual = $('qualification'), otherBox = $('f_qualification_other'), other = $('qualification_other');
  function syncOther() {
    const on = qual.value === 'other';
    otherBox.hidden = !on;
    other.required = on;
    if (on && document.activeElement === qual) other.focus();
  }
  qual.addEventListener('change', () => { setState('qualification', qual.value ? 'valid' : 'invalid', 'Please choose your qualification.'); syncOther(); });
  syncOther();
  const checkQual = () => {
    let ok = !!qual.value;
    setState('qualification', ok ? 'valid' : 'invalid', 'Please choose your qualification.');
    if (qual.value === 'other') { const o = other.value.trim() !== ''; setState('qualification_other', o ? 'valid' : 'invalid', 'Please tell us your qualification.'); ok = ok && o; }
    return ok;
  };
  other.addEventListener('input', () => { if (other.value.trim()) setState('qualification_other', 'valid'); });

  // ---- Experience pills ----
  const expBox = $('f_experience');
  const checkExp = () => { const ok = !!form.querySelector('input[name="experience"]:checked'); expBox.classList.toggle('invalid', !ok); $('e_experience').textContent = ok ? '' : 'Please choose your years of experience.'; return ok; };
  form.querySelectorAll('input[name="experience"]').forEach(r => r.addEventListener('change', checkExp));

  // ---- About me: live character count ----
  const about = $('about_me'), count = $('aboutCount'), aboutBox = $('f_about_me');
  function syncCount() {
    const n = about.value.trim().length;
    count.textContent = n < ABOUT_MIN ? n + ' / ' + ABOUT_MIN + ' minimum' : n + ' characters ✓';
    count.classList.toggle('ok', n >= ABOUT_MIN);
    if (n >= ABOUT_MIN) { aboutBox.classList.remove('invalid'); $('e_about_me').textContent = ''; }
  }
  const checkAbout = () => {
    const n = about.value.trim().length, ok = n >= ABOUT_MIN;
    aboutBox.classList.toggle('invalid', !ok);
    $('e_about_me').textContent = ok ? '' : 'Please write at least ' + ABOUT_MIN + ' characters about yourself (' + n + ' so far).';
    return ok;
  };
  about.addEventListener('input', syncCount);
  about.addEventListener('blur', () => { if (about.value.trim()) checkAbout(); });
  syncCount();

  // ---- Portfolio (optional) ----
  const link = $('portfolio_link');
  const checkLink = () => {
    const val = link.value.trim();
    if (!val) { setState('portfolio_link', ''); return true; }
    const ok = /^(https?:\/\/)?[^\s\/.]+(\.[^\s\/.]+)+(\/\S*)?$/i.test(val);
    setState('portfolio_link', ok ? 'valid' : 'invalid', 'Please type a full web address, like https://behance.net/yourname.');
    return ok;
  };
  link.addEventListener('blur', checkLink);

  // ---- Submit ----
  form.addEventListener('submit', e => {
    const results = [checkSimple('name'), checkEmailFormat() && !emailTaken, checkPhone(), checkSimple('city'), checkPw(), checkPw2(), checkQual(), checkExp(), checkAbout(), checkLink()];
    if (emailTaken) setState('email', 'invalid', 'An account with this email already exists. Try signing in instead.');
    const bad = results.filter(ok => !ok).length;
    if (bad) {
      e.preventDefault();
      $('formError').textContent = 'Please fix the ' + (bad === 1 ? 'highlighted field' : bad + ' highlighted fields') + ' below.';
      const first = form.querySelector('.invalid input, .invalid select, .invalid textarea');
      if (first) first.focus();
      return;
    }
    $('formError').textContent = '';
    const btn = $('submitBtn');
    btn.disabled = true;
    btn.setAttribute('aria-busy', 'true');
    btn.insertAdjacentHTML('afterbegin', '<span class="spinner" aria-hidden="true"></span>');
    btn.querySelector('.btn-label').textContent = 'Sending…';
  });
})();
