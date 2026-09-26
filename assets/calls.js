// Call queue forms (in-house and admin dashboards).
// Choosing "Customer decided not to proceed" closes the order, so the form asks first:
// it sets data-confirm, which the dashboard's confirmation dialog (admin.js) reads on submit.
(function () {
  const CLOSE_MSG = 'Are you sure? This will close the order.';
  document.querySelectorAll('form[data-call-form]').forEach(form => {
    form.addEventListener('change', e => {
      if (e.target.name !== 'next') return;
      if (e.target.value === 'declined') form.dataset.confirm = CLOSE_MSG;
      else { delete form.dataset.confirm; delete form.dataset.confirmed; }
    });
  });
  // Opening "I've called them" puts the cursor in the notes box.
  document.querySelectorAll('details.call-do').forEach(d => d.addEventListener('toggle', () => {
    if (d.open) { const t = d.querySelector('textarea'); if (t) t.focus({ preventScroll: true }); }
  }));
})();
