// Admin dashboard behaviour: mobile menu, clickable rows, "Saving..." feedback,
// red confirmation step for destructive actions, and small form helpers.
(function(){
  const adm = document.getElementById('adm');
  const burger = document.getElementById('admBurger');
  const scrim = document.getElementById('admScrim');
  const setMenu = open => {
    adm.classList.toggle('open', open);
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    scrim.hidden = !open;
  };
  burger.addEventListener('click', () => setMenu(!adm.classList.contains('open')));
  scrim.addEventListener('click', () => setMenu(false));
  document.addEventListener('keydown', e => { if(e.key === 'Escape') setMenu(false); });

  // Whole table rows open their record (links and buttons inside keep working).
  document.addEventListener('click', e => {
    const row = e.target.closest('tr[data-href]');
    if(!row || e.target.closest('a, button, input, select, textarea, form, label')) return;
    window.location.href = row.dataset.href;
  });

  // Selects that save as soon as they change.
  document.querySelectorAll('select[data-autosubmit]').forEach(sel => sel.addEventListener('change', () => {
    const form = sel.form;
    if(form.requestSubmit) form.requestSubmit(); else form.submit();
  }));

  // Destructive actions: ask first in a red dialog. Then show "Saving..." on the button.
  const dialog = document.getElementById('admConfirm');
  document.addEventListener('submit', e => {
    const form = e.target;
    const submitter = e.submitter || form.querySelector('[type=submit], button:not([type])');

    if(submitter && submitter.hasAttribute('data-needs-notes')){
      const notes = form.querySelector('textarea');
      if(notes && !notes.value.trim()){
        e.preventDefault();
        notes.focus();
        notes.setAttribute('placeholder', 'Please write what the freelancer should fix.');
        return;
      }
    }

    if(form.dataset.confirm && form.dataset.confirmed !== 'yes'){
      e.preventDefault();
      if(!dialog || typeof dialog.showModal !== 'function'){
        if(window.confirm(form.dataset.confirm)){ form.dataset.confirmed = 'yes'; form.requestSubmit ? form.requestSubmit(submitter) : form.submit(); }
        return;
      }
      document.getElementById('admConfirmText').textContent = form.dataset.confirm;
      dialog.returnValue = '';
      dialog.showModal();
      dialog.addEventListener('close', function onClose(){
        dialog.removeEventListener('close', onClose);
        if(dialog.returnValue === 'ok'){ form.dataset.confirmed = 'yes'; form.requestSubmit ? form.requestSubmit(submitter) : form.submit(); }
      });
      return;
    }

    if(form.hasAttribute('data-saving') && submitter){
      // Disable after the browser has read the button's name/value into the form data.
      setTimeout(() => {
        submitter.disabled = true;
        if(!submitter.classList.contains('switch-btn')) submitter.textContent = 'Saving…';
      }, 0);
    }
  });

  // Modification form: fixed price for tiers 1-3, a price range for tier 4.
  const tier = document.getElementById('modTier');
  if(tier){
    const sync = () => {
      const range = tier.value === '4';
      document.querySelectorAll('[data-tier="fixed"]').forEach(el => { el.hidden = range; });
      document.querySelectorAll('[data-tier="range"]').forEach(el => { el.hidden = !range; });
    };
    tier.addEventListener('change', sync);
    sync();
  }
})();
