// Brief form similarity check (admin + in-house) and review helpers.
// The score is always worked out on the server (api/similarity-check.php); this file only shows it.
(function(){
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const STATUS = {open:'Open', claimed:'Claimed', in_review:'In review', needs_revision:'Needs changes', approved:'Approved'};

  // ---- Two-step brief posting ---------------------------------------------------------
  // Step 1 "Check Similarity" only asks api/similarity-check.php and shows the results below
  // the form. Step 2 "Post this brief" (inside the results) is the only thing that saves.
  const form = document.getElementById('briefForm');
  if(form){
    const btn = document.getElementById('bfSubmit');
    const panel = document.getElementById('simPanel');
    const err = document.getElementById('bfError');
    const threshold = Number(form.dataset.threshold || 70);
    const notes = form.elements['differentiation_notes'];
    // Fields that are not design parameters -- editing them does not need a new check.
    const NOT_PARAMS = ['csrf', 'action', 'return', 'post_brief', 'confirm_similar', 'similarity_checked',
      'title', 'differentiation_notes', 'requirements', 'payout', 'deadline'];
    const tone = s => s >= threshold ? 'high' : s >= 50 ? 'mid' : 'low';
    const setBtn = (text, busy) => { btn.textContent = text; btn.disabled = !!busy; };

    // Checking needs every answer except "what should be different" (that is needed to post).
    const readyToCheck = () => {
      notes.required = false;
      const ok = form.reportValidity();
      notes.required = true;
      return ok;
    };

    const resetCheck = () => {
      form.elements['similarity_checked'].value = '';
      form.elements['confirm_similar'].value = '';
    };

    const render = results => {
      const shown = results.filter(r => r.score > 0);
      const top = shown.length ? shown[0].score : 0;
      let html;
      if(shown.length){
        html = '<div class="sim-head"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3.5l9 16H3z"/><path d="M12 10v4M12 17v.01"/></svg>'
          + '<div><h3>Here are the designs already in our library that look similar</h3>'
          + '<p>Green = a little similar, amber = fairly similar, red = very similar. Review them, then post or go back and change the brief.</p></div></div>'
          + (top > 85 ? '<p class="sim-strong">This is very close to an existing design. Make sure you clearly explain what should be different about this one.</p>' : '')
          + shown.map(r => {
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
        panel.classList.remove('unique');
      } else {
        html = '<div class="sim-head ok"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>'
          + '<div><h3>No similar designs found — this brief is unique</h3><p>Nothing in the library or in other briefs looks like this one.</p></div></div>';
        panel.classList.add('unique');
      }
      html += '<div class="sim-actions"><button type="button" class="btn" data-sim-back>Go back and change</button>'
        + '<button type="button" class="btn btn-primary" data-sim-post>Post this brief</button></div>'
        + '<p class="sim-need" hidden>Fill in “What should be different about this design?” above to post this brief.</p>';
      panel.innerHTML = html;
      panel.hidden = false;

      const post = panel.querySelector('[data-sim-post]');
      const need = panel.querySelector('.sim-need');
      const sync = () => { const ok = notes.value.trim() !== ''; post.disabled = !ok; need.hidden = ok; };
      notes.addEventListener('input', sync);
      sync();
      post.addEventListener('click', () => {
        if(!notes.value.trim()){ notes.focus(); return; }
        if(!form.reportValidity()) return;
        // The poster has seen the results: mark the check done and the matches reviewed.
        form.elements['similarity_checked'].value = '1';
        form.elements['confirm_similar'].value = '1';
        post.disabled = true;
        post.textContent = 'Posting…';
        form.submit(); // the only place a brief is actually saved
      });
      panel.querySelector('[data-sim-back]').addEventListener('click', () => {
        form.scrollIntoView({behavior:'smooth', block:'start'});
        form.elements['title'].focus({preventScroll:true});
      });
      panel.scrollIntoView({behavior:'smooth', block:'start'});
    };

    form.addEventListener('submit', async e => {
      e.preventDefault(); // this button never saves -- it only checks
      err.textContent = '';
      if(!readyToCheck()) return;
      resetCheck();
      const params = {};
      new FormData(form).forEach((v, k) => { if(!NOT_PARAMS.includes(k)) params[k] = v; });
      setBtn('Checking…', true);
      try {
        const res = await fetch(form.dataset.api, {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body:JSON.stringify({params, type:'both'})});
        const data = await res.json();
        if(!res.ok || !data.ready) throw new Error(data.error || 'failed');
        render(data.results);
      } catch(ex) {
        panel.hidden = true;
        err.textContent = 'We could not check for similar designs. Please try again.';
      } finally {
        setBtn('Check Similarity', false);
      }
    });

    // Changing a design answer after checking makes the results out of date.
    const stale = e => {
      if(!e.target.name || NOT_PARAMS.includes(e.target.name) || panel.hidden) return;
      resetCheck();
      panel.hidden = true;
      err.textContent = 'You changed the brief — click “Check Similarity” again to see up-to-date results.';
    };
    form.addEventListener('input', stale);
    form.addEventListener('change', stale);
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
