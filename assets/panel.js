(() => {
  'use strict';
  let installing = false;
  const statusRoot = document.querySelector('[data-update-status-url]');
  const formatTime = timestamp => new Intl.DateTimeFormat('en-AU', {timeZone: 'Australia/Perth', hour: 'numeric', minute: '2-digit'}).format(new Date(timestamp * 1000)) + ' AWST';

  async function checkUpdates(refresh = false) {
    if (!statusRoot || installing) return;
    const url = new URL(statusRoot.dataset.updateStatusUrl, location.href);
    if (refresh) url.searchParams.set('refresh', '1');
    const notice = document.querySelector('[data-update-notice]');
    const badge = document.querySelector('[data-update-badge]');
    const label = document.querySelector('[data-update-label]');
    const detail = document.querySelector('[data-update-detail]');
    const review = document.querySelector('[data-update-review]');
    const button = document.querySelector('[data-check-updates]');
    if (button) { button.disabled = true; button.textContent = 'Checking…'; }
    try {
      const response = await fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
      if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) throw new Error('status');
      const result = await response.json();
      if (notice) notice.hidden = false;
      if (badge) badge.hidden = result.state !== 'update_available';
      if (review) review.hidden = result.state !== 'update_available' && !result.integrity_changed;
      if (label) label.textContent = result.integrity_changed ? 'Loader hash needs verification' : ({update_available: 'An update is available', up_to_date: 'You’re up to date', checking: 'Checking for updates…', checks_pending: 'Latest release is waiting for checks', check_failed: 'Update check could not be completed'}[result.state] || 'Update status unavailable');
      if (notice) notice.dataset.state = result.integrity_changed ? 'warning' : result.state;
      if (detail) {
        const parts = [];
        if (result.state === 'update_available') parts.push([result.loader_update ? 'Loader' : '', result.app_update ? 'workspace' : ''].filter(Boolean).join(' and ') + ' update ready for review.');
        if (result.release_title && result.state === 'update_available') parts.push(result.release_title);
        if (result.checked_at) parts.push('Checked ' + formatTime(result.checked_at));
        if (result.message) parts.push(result.message);
        detail.textContent = parts.join(' · ');
      }
    } catch (_) {
      if (notice) { notice.hidden = false; notice.dataset.state = 'check_failed'; }
      if (label) label.textContent = 'Update status unavailable';
      if (detail) detail.textContent = 'Open application updates to check the connection or sign in again.';
    } finally {
      if (button) { button.disabled = false; button.textContent = 'Check again'; }
    }
  }

  document.querySelector('[data-check-updates]')?.addEventListener('click', () => checkUpdates(true));
  if (statusRoot) { checkUpdates(); setInterval(() => checkUpdates(), 300000); }

  function progressDialog() {
    let dialog = document.querySelector('#update-progress-dialog');
    if (dialog) return dialog;
    dialog = document.createElement('dialog');
    dialog.id = 'update-progress-dialog';
    dialog.className = 'update-dialog';
    dialog.setAttribute('aria-labelledby', 'update-progress-title');
    dialog.innerHTML = '<div class="update-dialog-body"><div class="eyebrow">VERIFIED SERVER UPDATE</div><h2 id="update-progress-title">Preparing your update</h2><p data-progress-detail aria-live="polite">Confirming the checked selection.</p><progress class="update-progress" max="9" value="0" aria-label="Completed update stages"></progress><p class="muted" data-progress-count>0 of 9 stages complete</p><ol class="update-stage-list" data-progress-stages></ol><div class="update-result" data-progress-result hidden></div><div class="update-dialog-actions" data-progress-actions hidden></div></div>';
    dialog.addEventListener('cancel', event => { if (installing) event.preventDefault(); });
    document.body.append(dialog);
    return dialog;
  }

  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-update-install]') || !window.fetch || !window.ReadableStream) return;
    event.preventDefault();
    if (installing) return;
    installing = true;
    const submit = form.querySelector('button[type=submit],button:not([type])');
    if (submit) submit.disabled = true;
    const dialog = progressDialog();
    const title = dialog.querySelector('#update-progress-title');
    const detail = dialog.querySelector('[data-progress-detail]');
    const bar = dialog.querySelector('progress');
    const count = dialog.querySelector('[data-progress-count]');
    const list = dialog.querySelector('[data-progress-stages]');
    const result = dialog.querySelector('[data-progress-result]');
    const actions = dialog.querySelector('[data-progress-actions]');
    list.replaceChildren(); result.replaceChildren(); actions.replaceChildren();
    result.hidden = true; actions.hidden = true; bar.value = 0;
    title.textContent = 'Preparing your update'; detail.textContent = 'Confirming the checked selection.'; count.textContent = '0 of 9 stages complete';
    dialog.dataset.state = 'working';
    if (!dialog.open) dialog.showModal();
    const destination = new URL(form.getAttribute('action') || location.href, location.href);
    destination.searchParams.set('stream', '1');
    let finished = false;
    const render = update => {
      if (update.event === 'progress') {
        title.textContent = update.label;
        detail.textContent = update.detail;
        bar.value = Math.max(0, update.step - 1);
        count.textContent = Math.max(0, update.step - 1) + ' of ' + update.total + ' stages complete';
        for (const item of list.children) item.dataset.state = 'complete';
        let item = list.querySelector('[data-step="' + Number(update.step) + '"]');
        if (!item) { item = document.createElement('li'); item.dataset.step = String(update.step); list.append(item); }
        item.textContent = update.label; item.dataset.state = 'working';
      } else if (update.event === 'complete' || update.event === 'error') {
        finished = true;
        const success = update.event === 'complete';
        dialog.dataset.state = success ? 'complete' : 'error';
        title.textContent = success ? 'Update installed' : 'Update stopped';
        detail.textContent = update.message;
        if (success) { bar.value = 9; count.textContent = '9 of 9 stages complete'; for (const item of list.children) item.dataset.state = 'complete'; }
        result.hidden = false;
        if (update.summary) {
          const summary = document.createElement('ul');
          const changes = update.summary.changes || {};
          for (const text of [update.summary.loader_updated ? 'Loader updated and its saved hash verified.' : 'Loader already matched the checked hash.',
            (changes.added?.length || 0) + ' files added · ' + (changes.changed?.length || 0) + ' changed · ' + (changes.removed?.length || 0) + ' removed',
            'PIN, signing keys and project data retained.']) { const line = document.createElement('li'); line.textContent = text; summary.append(line); }
          result.append(summary);
        }
        actions.hidden = false;
        const destinations = success ? [['Open panel', ''], ['Update details', '?installer=1']] : [['Return to updates', '?installer=1']];
        for (const [text, query] of destinations) {
          const link = document.createElement('a'); link.className = 'btn ' + (actions.childElementCount ? 'btn-outline-secondary' : 'btn-primary'); link.href = destination.pathname + query; link.textContent = text; actions.append(link);
        }
      }
    };
    try {
      const response = await fetch(destination, {method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: {'Accept': 'application/x-ndjson'}});
      if (!(response.headers.get('content-type') || '').includes('application/x-ndjson') || !response.body) throw new Error('Session expired or progress is unavailable. Open updates to confirm the active version.');
      const reader = response.body.getReader(), decoder = new TextDecoder();
      let buffer = '';
      while (true) {
        const chunk = await reader.read();
        buffer += decoder.decode(chunk.value || new Uint8Array(), {stream: !chunk.done});
        let end;
        while ((end = buffer.indexOf('\n')) >= 0) { const line = buffer.slice(0, end).trim(); buffer = buffer.slice(end + 1); if (line) render(JSON.parse(line)); }
        if (chunk.done) break;
      }
      if (buffer.trim()) render(JSON.parse(buffer));
      if (!finished) throw new Error('The connection ended before the update result was received. Open updates to confirm its status; do not repeat it blindly.');
    } catch (failure) {
      render({event: 'error', message: failure instanceof Error ? failure.message : 'Update progress was interrupted. Verify the installed version before retrying.'});
    } finally {
      installing = false;
    }
  });
})();
