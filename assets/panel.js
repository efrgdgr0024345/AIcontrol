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

document.addEventListener('submit', event => {
  const form = event.target;
  if (!form.matches('[data-research-chat]')) return;
  const button = form.querySelector('button');
  button.disabled = true;
  form.querySelector('[data-research-status]').textContent = 'Researching… This may take up to a minute.';
});

// Investigation requests advance one persisted phase at a time. Stop/poll use
// separate requests; the server releases its session lock before provider I/O.
(() => {
  'use strict';
  const root = document.querySelector('[data-investigation]');
  const csrf = () => document.querySelector('input[name=csrf]')?.value || '';
  let stepping = false, halted = false, polling = false, lastSnapshot = '';
  const element = (tag, text, className) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
  };
  function error(message) {
    const node = root?.querySelector('[data-research-error]');
    if (node) node.textContent = message;
  }
  function safeUrl(value) {
    try { const url = new URL(value); return ['https:', 'http:'].includes(url.protocol) && !url.username && !url.password; } catch (_) { return false; }
  }
  function render(snapshot) {
    if (!root || !snapshot.run) return;
    const serial = JSON.stringify(snapshot);
    if (serial === lastSnapshot) return;
    lastSnapshot = serial;
    const run = snapshot.run;
    const active = ['pending', 'running'].includes(run.status);
    const pending = snapshot.requests.some(request => request.status === 'pending');
    root.dataset.status = run.status;
    root.dataset.busy = run.busy_since ? '1' : '0';
    root.querySelector('[data-research-summary]').textContent = `${run.status} · ${run.phase} · ${snapshot.nodes.length} nodes · ${run.token_input} input / ${run.token_output} output tokens confirmed${run.usage_unknown ? ' · some usage unknown' : ''}`;
    root.querySelector('[data-research-reason]').textContent = run.stop_reason;
    root.querySelector('[data-stop-control]').hidden = !(active || pending);
    root.querySelector('[data-step-control]').hidden = !active || !!run.busy_since;
    const tree = root.querySelector('[data-research-tree]');
    const existing = new Map([...tree.querySelectorAll('[data-node-id]')].map(node => [Number(node.dataset.nodeId), node]));
    const parents = new Map();
    for (const item of snapshot.nodes.filter(item => item.type !== 'learn')) {
      let node = existing.get(Number(item.id));
      if (!node) {
        node = element('details', undefined, 'investigation-node'); node.open = true; node.dataset.nodeId = item.id;
        const summary = element('summary'); summary.append(element('span'), document.createTextNode(' '), element('small'));
        node.append(summary, element('div', '', 'node-content'), element('div', undefined, 'node-children'));
      }
      node.dataset.status = item.status;
      node.querySelector('summary > span').textContent = item.title;
      node.querySelector('summary > small').textContent = `${item.type} · ${item.status}`;
      node.querySelector('.node-content').textContent = item.content;
      parents.set(Number(item.id), node);
    }
    // Moving existing nodes preserves their expanded state and keyboard focus.
    for (const item of snapshot.nodes.filter(item => item.type !== 'learn')) {
      const node = parents.get(Number(item.id));
      const parent = item.parent_id ? parents.get(Number(item.parent_id))?.querySelector('.node-children') : tree;
      if (parent && node.parentNode !== parent) parent.append(node);
    }
    const cards = root.querySelector('[data-research-sources]');
    const saved = new Set([...cards.querySelectorAll('[data-source-id]')].map(card => Number(card.dataset.sourceId)));
    for (const source of snapshot.sources) {
      if (saved.has(Number(source.id)) || !safeUrl(source.url)) continue;
      const card = element('article', undefined, 'research-source'); card.dataset.sourceId = source.id;
      const link = element('a', source.url); link.href = source.url; link.target = '_blank'; link.rel = 'noopener noreferrer';
      const captured = new Intl.DateTimeFormat('en-AU', {timeZone: 'Australia/Perth', dateStyle: 'medium', timeStyle: 'short'}).format(new Date(source.retrieved_at * 1000));
      card.append(element('h4', `Source #${source.id} · ${source.title}`), link, element('p', source.extract), element('small', `${source.domain} · Citation captured ${captured} AWST`)); cards.append(card);
    }
    const learning = root.querySelector('[data-learn-results]');
    for (const item of snapshot.nodes.filter(item => item.type === 'learn')) {
      let article = learning.querySelector(`[data-learning-id="${Number(item.id)}"]`);
      if (!article) { article = element('article', undefined, 'research-message'); article.dataset.learningId = item.id; article.append(element('h4'), element('p')); learning.append(article); }
      article.querySelector('h4').textContent = `${item.title} · ${item.status}`;
      article.querySelector('p').textContent = item.content || 'Waiting for the explanation outcome.';
    }
    const hasSources = snapshot.sources.length > 0;
    root.querySelector('[data-learn-link]').hidden = !hasSources;
    root.querySelector('[data-learn-panel]').hidden = !hasSources || root.dataset.selectedTab !== 'learn';
    root.querySelector('[data-source-empty]').hidden = hasSources;
    root.querySelector('[data-learn-submit]').disabled = active || pending;
    document.querySelector('[data-research-action=research_start] button').disabled = active || pending;
    if (!active && !pending) { stepping = false; }
  }
  async function send(form) {
    const destination = new URL(form.getAttribute('action') || location.href, location.href);
    destination.searchParams.set('research_api', '1');
    const response = await fetch(destination, {method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: {'Accept': 'application/json'}});
    if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Research session expired. Sign in again and inspect the saved run before continuing.');
    const data = await response.json();
    if (!response.ok || data.error) throw new Error(data.error || 'Research request failed. Inspect the saved state before continuing.');
    return data;
  }
  async function advance() {
    if (!root || stepping || halted || root.dataset.status !== 'running' || root.dataset.busy === '1') return;
    const form = root.querySelector('[data-research-action=research_step]');
    stepping = true;
    try { render(await send(form)); }
    catch (failure) { halted = true; error(failure.message); }
    finally { stepping = false; }
    if (!halted && root.dataset.status === 'running') setTimeout(advance, 200);
  }
  async function poll() {
    if (!root || polling) return;
    const pending = !root.querySelector('[data-stop-control]').hidden;
    if (!['pending', 'running'].includes(root.dataset.status) && !pending) return;
    polling = true;
    try {
      const url = new URL(root.dataset.entry, location.href); url.searchParams.set('research_api', 'status'); url.searchParams.set('run_id', root.dataset.runId);
      const response = await fetch(url, {credentials: 'same-origin', headers: {'X-CSRF-Token': csrf(), 'Accept': 'application/json'}});
      if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Progress could not be verified. Sign in again if needed; no phase will be retried automatically.');
      const data = await response.json(); if (data.error) throw new Error(data.error);
      render(data);
      if (!halted) advance();
    } catch (failure) { halted = true; error(failure.message); }
    finally { polling = false; }
  }
  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.researchAction || form.dataset.researchAction === 'research_export') return;
    event.preventDefault();
    const action = form.dataset.researchAction;
    const button = form.querySelector('button'); button.disabled = true;
    if (action === 'research_stop') halted = true;
    if (action === 'research_step') { halted = false; advance(); button.disabled = false; return; }
    if (action === 'research_learn') { error('Explaining collected evidence… No web search.'); root.querySelector('[data-stop-control]').hidden = false; }
    try {
      const snapshot = await send(form);
      if (action === 'research_start') {
        const url = new URL(form.getAttribute('action'), location.href); url.searchParams.set('project', snapshot.run.project_id); url.searchParams.set('run', snapshot.run.id); location.assign(url); return;
      }
      render(snapshot); error('');
    } catch (failure) { error(failure.message); if (!root) alert(failure.message); }
    finally { button.disabled = false; }
  });
  if (root) {
    root.dataset.selectedTab = 'investigate';
    root.querySelector('[data-learn-panel]').hidden = true;
    root.querySelectorAll('.research-subtabs a').forEach(link => link.addEventListener('click', event => {
      event.preventDefault();
      const learn = link.hasAttribute('data-learn-link'); root.dataset.selectedTab = learn ? 'learn' : 'investigate';
      root.querySelector('section[id^=investigate-]').hidden = learn;
      root.querySelector('[data-learn-panel]').hidden = !learn;
      root.querySelectorAll('.research-subtabs a').forEach(item => item.removeAttribute('aria-current'));
      link.setAttribute('aria-current', 'page');
    }));
    setInterval(poll, 1500);
    poll();
  }
})();
