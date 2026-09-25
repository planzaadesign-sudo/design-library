// Brief form similarity check (admin + in-house) and review helpers.
// The score is always worked out on the server (api/similarity-check.php); this file only shows it.
(function(){
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const STATUS = {open:'Open', claimed:'Claimed', in_review:'In review', needs_revision:'Needs changes', approved:'Approved'};

  // ---- "Post brief": check for similar designs before saving ------------------------
  const form = document.getElementById('briefForm');
  if(form){
    const btn = document.getElementById('bfSubmit');
    const panel = document.getElementById('simPanel');
    const err = document.getElementById('bfError');
    const threshold = Number(form.dataset.threshold || 70);
    const notes = form.elements['differentiation_notes'];
    const setBtn = (text, busy) => { btn.textContent = text; btn.disabled = !!busy; };

    const submitNow = () => {
      form.dataset.checked = 'yes';
      setBtn('Posting…', true);
      form.submit(); // does not fire the submit event again
    };

    const tone = s => s > threshold ? 'high' : s >= 50 ? 'mid' : 'low';
    const showPanel = results => {
      const cards = results.map(r => {
        const link = r.kind === 'design'
          ? '<a href="' + esc(form.dataset.designUrl + r.id) + '" target="_blank" rel="noopener">View ' + esc(r.name) + ' →</a>'
          : (form.dataset.briefUrl ? '<a href="' + esc(form.dataset.briefUrl + r.id) + '" target="_blank" rel="noopener">View brief →</a>' : '');
        return '<div class="sim-card">'
          + '<div class="sim-card-top"><div><strong>' + esc(r.name) + '</strong><small>'
          +   (r.kind === 'design' ? 'Design in our library' : 'Brief — ' + esc(STATUS[r.status] || r.status)) + '</small></div>' + link + '</div>'
          + '<span class="sim-score ' + tone(r.score) + '"><span class="sim-bar"><i style="width:' + r.score + '%"></i></span><b>' + r.score + '% similar</b></span>'
          + (r.matched.length ? '<div class="ptags"><span>Same:</span>' + r.matched.map(m => '<i>' + esc(m) + '</i>').join('') + '</div>' : '')
          + (r.close.length ? '<div class="ptags close"><span>Close:</span>' + r.close.map(m => '<i>' + esc(m) + '</i>').join('') + '</div>' : '')
          + '</div>';
      }).join('');
      panel.innerHTML =
        '<div class="sim-head"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.5l9 16H3z"/><path d="M12 10v4M12 17v.01"/></svg>'
        + '<div><h3>Similar designs already exist</h3><p>Check these before posting. If you post anyway, make sure “What should be different” explains how this one will stand out.</p></div></div>'
        + cards
        + '<div class="sim-actions"><button type="button" class="btn" data-sim-cancel>Cancel and go back</button>'
        + '<button type="button" class="btn btn-primary" data-sim-post>Post anyway — I’ve explained what makes this different</button></div>'
        + '<p class="sim-need" hidden>Fill in “What should be different about this design?” first.</p>';
      panel.hidden = false;
      const post = panel.querySelector('[data-sim-post]');
      const need = panel.querySelector('.sim-need');
      const sync = () => { const ok = notes.value.trim() !== ''; post.disabled = !ok; need.hidden = ok; };
      notes.addEventListener('input', sync);
      sync();
      post.addEventListener('click', () => {
        if(!notes.value.trim()){ notes.focus(); return; }
        form.elements['confirm_similar'].value = '1';
        submitNow();
      });
      panel.querySelector('[data-sim-cancel]').addEventListener('click', () => { panel.hidden = true; form.elements['title'].focus(); });
      panel.scrollIntoView({behavior:'smooth', block:'start'});
    };

    form.addEventListener('submit', async e => {
      if(form.dataset.checked === 'yes') return;
      e.preventDefault();
      err.textContent = '';
      panel.hidden = true;
      if(!form.checkValidity()){ form.reportValidity(); return; }
      const params = {};
      new FormData(form).forEach((v, k) => { params[k] = v; });
      setBtn('Checking for similar designs…', true);
      let data;
      try {
        const res = await fetch(form.dataset.api, {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body:JSON.stringify({params, type:'both'})});
        data = await res.json();
        if(!res.ok) throw new Error(data.error || 'failed');
      } catch(ex) {
        setBtn('Post brief', false);
        err.textContent = 'We could not check for similar designs. Please try again.';
        return;
      }
      setBtn('Post brief', false);
      if(data.ready && data.results.some(r => r.score >= threshold)) showPanel(data.results);
      else submitNow();
    });
  }

  // ---- Review: must confirm "different enough" before approving; quick "too similar" notes ----
  document.querySelectorAll('form').forEach(f => {
    const box = f.querySelector('[data-sim-confirm]');
    if(box){
      const msg = f.querySelector('.sim-confirm-msg');
      const approve = f.querySelectorAll('[data-needs-confirm]');
      const sync = () => {
        approve.forEach(b => { b.classList.toggle('is-locked', !box.checked); b.setAttribute('aria-disabled', box.checked ? 'false' : 'true'); });
        if(box.checked && msg) msg.hidden = true;
      };
      box.addEventListener('change', sync);
      sync();
      f.addEventListener('click', e => {
        const b = e.target.closest('[data-needs-confirm]');
        if(b && !box.checked){ e.preventDefault(); e.stopPropagation(); if(msg) msg.hidden = false; box.focus(); }
      }, true);
    }
    const reason = f.querySelector('[data-reject-reason]');
    if(reason){
      reason.addEventListener('change', () => {
        if(!reason.value) return;
        const notes = f.querySelector('textarea');
        notes.value = reason.value;
        notes.focus();
      });
    }
  });
})();
