// Live password checklist + strength meter for every "new password" field.
// The page prints the checklist with password_rules_html() (includes/security.php); this ticks the
// rules as they type. The server checks the same rules again -- this is only a guide.
(function () {
  const RULES = {
    length: p => p.length >= 10,
    upper: p => /[A-Z]/.test(p),
    lower: p => /[a-z]/.test(p),
    number: p => /[0-9]/.test(p),
    special: p => /[^A-Za-z0-9]/.test(p),
  };
  function problems(p) { return Object.keys(RULES).filter(k => !RULES[k](p || '')); }
  // Weak until every rule is met; then longer = stronger.
  function strength(p) {
    if (!p) return 0;
    if (problems(p).length) return 1;
    if (p.length < 12) return 2;
    if (p.length < 16) return 3;
    return 4;
  }
  const LABELS = ['Password strength', 'Weak', 'Medium', 'Strong', 'Very strong'];

  function attach(box) {
    const input = document.getElementById(box.dataset.pwFor);
    if (!input) return;
    const confirm = box.dataset.pwConfirm ? document.getElementById(box.dataset.pwConfirm) : null;
    const label = box.querySelector('.pw-label');
    let match = null;
    if (confirm) {
      match = document.createElement('p');
      match.className = 'pw-match';
      match.setAttribute('aria-live', 'polite');
      confirm.insertAdjacentElement('afterend', match);
    }
    function update() {
      const p = input.value;
      box.querySelectorAll('.pw-rules li').forEach(li => li.classList.toggle('ok', !!RULES[li.dataset.rule] && RULES[li.dataset.rule](p)));
      const s = strength(p);
      box.dataset.level = s;
      label.textContent = LABELS[s];
      if (match) {
        const c = confirm.value;
        match.className = 'pw-match' + (c ? (c === p ? ' ok' : ' bad') : '');
        match.textContent = !c ? '' : (c === p ? '✓ Passwords match' : (c.length >= p.length ? 'The two passwords do not match.' : ''));
      }
    }
    input.addEventListener('input', update);
    if (confirm) confirm.addEventListener('input', update);
    // Stop the form from being sent while a rule is broken (the server would refuse it anyway).
    const form = input.form;
    if (form && !form.dataset.pwGuard && !form.hasAttribute('data-pw-noguard')) {
      form.dataset.pwGuard = '1';
      form.addEventListener('submit', e => {
        const bad = problems(input.value);
        const mismatch = confirm && confirm.value !== input.value;
        if (bad.length || mismatch) {
          e.preventDefault();
          e.stopImmediatePropagation();
          box.classList.add('shake');
          setTimeout(() => box.classList.remove('shake'), 400);
          (bad.length ? input : confirm).focus();
          update();
        }
      }, true);
    }
    update();
  }
  document.querySelectorAll('.pw-help[data-pw-for]').forEach(attach);
  window.PlanzaaPassword = { problems, strength };
})();
