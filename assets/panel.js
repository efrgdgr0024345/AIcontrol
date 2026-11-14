(() => {
  'use strict';
  document.querySelector('[data-copy-diagnostics]')?.addEventListener('click', async () => {
    const report = document.getElementById('diagnostics-report');
    const status = document.querySelector('[data-copy-diagnostics-status]');
    try {
      if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(report.value);
      else { report.focus(); report.select(); if (!document.execCommand('copy')) throw new Error('copy'); }
      status.textContent = 'Contents copied.';
    } catch { report.focus(); report.select(); status.textContent = 'Copy unavailable. The report is selected; use your device’s Copy command.'; }
  });
  document.querySelectorAll('[data-copy-report]').forEach(button => button.addEventListener('click', async () => {
    const report=document.getElementById(button.dataset.copyReport), status=button.parentElement.querySelector('[data-copy-report-status]');
    try { if(navigator.clipboard?.writeText)await navigator.clipboard.writeText(report.value);else {report.focus();report.select();if(!document.execCommand('copy'))throw new Error('copy');}status.textContent=button.dataset.copyReport==='admin-technical-report'?'Technical report copied.':'JSON copied.'; }
    catch {report.focus();report.select();status.textContent='Report selected. Use your device’s Copy command.';}
  }));
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
      const payload = new FormData(form);
    if (form.dataset.researchAction === 'research_continue') payload.set('selection_explicit', '1');
    const response = await fetch(destination, {method: 'POST', body: payload, credentials: 'same-origin', headers: {'Accept': 'application/x-ndjson'}});
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
  const csrf = () => root?.querySelector('input[name=csrf]')?.value || document.querySelector('input[name=csrf]')?.value || '';
  function diagnosticFailure(stage, failure) {
    const payload = new FormData();
    payload.set('action', 'diagnostic_client'); payload.set('csrf', csrf());
    payload.set('stage', stage); payload.set('run_id', root?.dataset.runId || '0');
    payload.set('message', String(failure.message || failure).slice(0, 1000));
    for (const field of ['http_status','content_type','redirected','response_path','error_kind']) payload.set(field, String(failure.diagnostics?.[field] ?? (field === 'error_kind' ? (failure instanceof TypeError ? 'network_error' : 'client_error') : '')).slice(0,160));
    fetch(root?.dataset.entry || location.pathname, {method:'POST',body:payload,credentials:'same-origin'}).catch(() => {});
  }
  async function researchResponse(response, stage) {
    const contentType = response.headers.get('content-type') || '';
    const details = {http_status:response.status, content_type:contentType, redirected:response.redirected,
      response_path:new URL(response.url || location.href, location.href).pathname};
    const fail = (message, kind) => {
      const failure = new Error(`${message} (HTTP ${response.status}, ${contentType.split(';')[0] || 'no content type'}${response.redirected ? ', redirected' : ''}). Inspect saved status before continuing.`);
      failure.diagnostics = {...details,error_kind:kind}; return failure;
    };
    if (!contentType.toLowerCase().includes('application/json')) throw fail(`${stage} returned an unexpected response`, 'non_json');
    let data;
    try { data = await response.json(); } catch { throw fail(`${stage} returned invalid JSON`, 'invalid_json'); }
    if (!data || typeof data !== 'object' || Array.isArray(data)) throw fail(`${stage} returned an invalid response`, 'invalid_shape');
    showBudget(data.budget);
    if (data.budget && root) root.dispatchEvent(new CustomEvent('research:budget',{detail:data.budget}));
    if (!response.ok || data.error) throw fail(data.error || `${stage} failed`, 'http_error');
    return data;
  }
  let stepping = false, halted = false, polling = false, lastSnapshot = '';
  const element = (tag, text, className) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
  };
  function error(message) {
    const node = root?.querySelector('[data-research-error]') || document.querySelector('[data-request-error]');
    if (node) node.textContent = message;
    root?.dispatchEvent(new CustomEvent('research:error',{detail:message}));
  }
  function budgetLabel(budget) {
    return `${budget.remaining} of ${budget.limit||10} requests remaining this hour · shared across search, verification and Learn` +
      (budget.remaining === 0 ? ` · Next request available in about ${Math.ceil(budget.retry_after_seconds / 60)} minutes` : '');
  }
  function showBudget(budget) {
    if (!budget) return;
    document.querySelector('[data-request-budget]').textContent = budgetLabel(budget);
  }
  function safeUrl(value) {
    try { const url = new URL(value); return ['https:', 'http:'].includes(url.protocol) && !url.username && !url.password; } catch (_) { return false; }
  }
  function claimData(node) {
    try { const data = JSON.parse(node.data_json || '{}'); if (data.claim) return data; } catch (_) { /* Legacy text below. */ }
    const data = {claim: node.title, confidence: 'unknown', uncertainty: '', verify: '', priority: 'normal'};
    for (const [label, key] of [['Confidence', 'confidence'], ['Uncertainty', 'uncertainty'], ['Verify', 'verify']]) {
      const match = node.content.match(new RegExp('^' + label + ': (.*)$', 'm')); if (match) data[key] = match[1];
    }
    return data;
  }
  function claimSources(snapshot, claim) {
    const parents = new Map(snapshot.nodes.map(node => [Number(node.id), Number(node.parent_id)]));
    return snapshot.sources.filter(source => {
      if(snapshot.ledger?.edges.some(edge=>Number(edge.claim_id)===Number(claim)&&Number(edge.source_id)===Number(source.id)))return true;
      try { if ((JSON.parse(source.meta_json || '{}').claim_ids || []).map(Number).includes(Number(claim))) return true; } catch (_) { /* Legacy ancestor lookup. */ }
      let id = Number(source.node_id); const seen = new Set();
      while (id && !seen.has(id)) { if (id === Number(claim)) return true; seen.add(id); id = parents.get(id); }
      return false;
    });
  }
  function sourceCard(source) {
    const card = element('article', undefined, 'research-source'); card.dataset.sourceId = source.id;
    const link = element('a', source.url); link.href = source.url; link.target = '_blank'; link.rel = 'noopener noreferrer';
    const captured = new Intl.DateTimeFormat('en-AU', {timeZone: 'Australia/Perth', dateStyle: 'medium', timeStyle: 'short'}).format(new Date(source.retrieved_at * 1000));
    const domain = element('div', undefined, 'source-domain'), mark = element('span', source.domain.slice(0,1).toUpperCase(), 'source-monogram'); mark.setAttribute('aria-hidden', 'true'); domain.append(mark, document.createTextNode(source.domain + ' · Cited source'));
    card.append(domain, element('h4', `Source #${source.id} · ${source.title}`), link, element('p', source.extract), element('small', `${source.domain} · Citation captured ${captured} AWST`));
    return card;
  }
  function renderClaims(snapshot, review) {
    const cards = root.querySelector('[data-dream-cards]');
    const claims = snapshot.nodes.filter(node => node.type === 'dream_claim');
    for (const claim of claims) {
      let card = cards.querySelector(`[data-claim-id="${Number(claim.id)}"]`);
      if (!card) { card = element('article', undefined, 'dream-card'); card.dataset.claimId = claim.id; cards.append(card); }
      // Review polling must not recreate checkboxes or steal focus/selection.
      const serial = JSON.stringify([claim, snapshot.nodes.filter(node => node.type !== 'learn'), snapshot.sources,snapshot.ledger]);
      if (card.dataset.serial !== serial) {
        const old = card.querySelector('input'); const checked = old ? old.checked : Number(claim.selected ?? 1) === 1;
        const focused = old === document.activeElement;
        const data = claimData(claim), label = element('label', undefined, 'claim-selection');label.hidden=Number(snapshot.run.flow_version)>=2;
        const checkbox = element('input'); checkbox.type = 'checkbox'; checkbox.name = 'claim_ids[]'; checkbox.value = claim.id; checkbox.checked = checked;
        label.append(checkbox, element('span', `Include claim #${claim.id}`));
        const badges = element('p', undefined, 'claim-badges'), confidence = element('span', `${data.confidence} prior confidence`, 'claim-badge'); confidence.dataset.confidence = data.confidence; badges.append(confidence);
        if (data.priority === 'high') badges.append(element('span', 'High priority', 'claim-badge'));
        const uncertainty = element('p'); uncertainty.append(element('strong', 'Uncertainty: '), document.createTextNode(data.uncertainty));
        const verify = element('p'); verify.append(element('strong', 'Verify: '), document.createTextNode(data.verify));
        card.replaceChildren(element('div', `PRIOR / ${String(claim.id).padStart(2, '0')}`, 'claim-number'), label, element('h4', data.claim), badges, uncertainty, verify);
        if (Number(claim.selected ?? 1) === 0) card.append(element('p', Number(snapshot.run.flow_version)>=2?'Available in the living map; outside the current grounding selection.':'Excluded from verification'));
        const trail = element('div', undefined, 'claim-trail'), descendants = new Set([Number(claim.id)]);
        for (const node of snapshot.nodes) if (descendants.has(Number(node.parent_id))) {
          descendants.add(Number(node.id));
          if (['plan_step', 'dead_end', 'synthesis'].includes(node.type)) {
            const event = element('article', undefined, 'claim-event'); event.dataset.status = node.status;
            let content = node.content;
            if (node.type === 'synthesis') { try { content = JSON.parse(node.data_json || '{}').reason || content; } catch (_) { /* Legacy text. */ } }
            event.append(element('h5', (node.type === 'dead_end' ? '⚑ Dead end · ' : '') + node.title), element('p', content)); trail.append(event);
          }
        }
        for (const source of claimSources(snapshot, claim.id).filter(source => safeUrl(source.url))) {
          trail.append(element('p', `Evidence collected to test claim #${claim.id}`, 'source-relevance'), sourceCard(source));
        }
        card.append(trail); card.dataset.serial = serial; if (focused && review) checkbox.focus();
      }
      card.querySelector('input').disabled = !review;
    }
    const context = root.querySelector('[data-prior-context]'); context.replaceChildren();
    for (const node of snapshot.nodes) {
      let data; try { data = JSON.parse(node.data_json || '{}'); } catch (_) { continue; }
      if (!data.least_confident) continue;
      context.append(element('h4', 'Prior context'));
      for (const [label, value] of [['Competing angles', data.competing_angles.join('; ')], ['Least confident', data.least_confident], ['Background assumptions', data.background_assumptions.join('; ')]]) {
        const p = element('p'); p.append(element('strong', label + ': '), document.createTextNode(value)); context.append(p);
      }
    }
    const rows = root.querySelector('[data-matrix-rows]'); rows.replaceChildren();
    for (const claim of claims) {
      const verdict = snapshot.nodes.filter(node => node.type === 'synthesis' && Number(node.parent_id) === Number(claim.id)).at(-1);
      let data = {}; try { data = JSON.parse(verdict?.data_json || '{}'); } catch (_) { /* Legacy title. */ }
      const outcome = Number(claim.selected ?? 1) === 0 && Number(snapshot.run.flow_version)<2 ? 'Excluded from verification' : (data.verdict || verdict?.title.split(' · ')[0] || 'open');
      const tr = element('tr'), th = element('th', claimData(claim).claim); th.scope = 'row';
      const sources = claimSources(snapshot, claim.id).filter(source => safeUrl(source.url) && (!data.source_ids || data.source_ids.includes(Number(source.id))));
      const td = element('td', sources.length ? '' : 'No useful source');
      for (const source of sources) { const link = element('a', source.title); link.href = source.url; link.target = '_blank'; link.rel = 'noopener noreferrer'; td.append(link, document.createTextNode(' ')); }
      for(const edge of snapshot.ledger?.edges||[])if(Number(edge.claim_id)===Number(claim.id)){const relation=element('details',undefined,'matrix-relation');relation.append(element('summary',edge.relation+' · '+(edge.strength||'unrated')),element('p',edge.rationale));const passage=snapshot.ledger.passages.find(p=>Number(p.id)===Number(edge.passage_id));relation.append(element('blockquote',passage?passage.exact_text:'Citation-only relation; no captured passage.'));if(passage)relation.append(element('small',passage.locator||'No locator supplied'));td.append(relation);}
      tr.append(th, element('td', outcome), td); rows.append(tr);
    }
    root.querySelector('[data-claim-matrix]').hidden = snapshot.run.status !== 'complete';
    root.querySelector('[data-dream-empty]').hidden = claims.length > 0;
    const generating = snapshot.run.status === 'running' && snapshot.run.phase === 'dream';
    root.querySelector('[data-dream-empty]').textContent = generating ? 'Generating prior hypotheses… Claims will appear as they are saved. No evidence has been established yet.' : 'No prior claims were saved for this run. Inspect the saved status before starting again.';
    root.querySelector('[data-dream-skeleton]').hidden = !generating || claims.length > 0;
    const plans = snapshot.nodes.filter(node => node.type === 'plan_step');
    root.querySelector('[data-live-progress]').textContent = snapshot.run.phase === 'evidence' ? `Verification search ${Math.min(Number(snapshot.run.evidence_index || 0) + 1, plans.length)} of ${plans.length} · ${snapshot.run.busy_since ? 'request in progress' : 'ready for the next step'}` : (review ? 'Dream complete — choose what to verify before continuing.' : '');
    const stages = Number(snapshot.run.flow_version)>=2?['dream','exploring','plan','evidence','verification']:['dream', 'dream_review', 'plan', 'evidence', Number(snapshot.run.rigour_version)===1?'verification':'reconciliation'];
    root.querySelectorAll('[data-phase-rail] li').forEach((item, index) => {
      item.removeAttribute('aria-current');
      if (stages[index] === snapshot.run.phase) item.setAttribute('aria-current', 'step');
      const done = snapshot.nodes.some(node => node.type === 'phase' && node.title.toLowerCase() === stages[index] && node.status === 'complete') || (stages[index] === 'dream_review' && stages.indexOf(snapshot.run.phase) > index);
      item.dataset.done = done ? '1' : '0';
      const link = item.querySelector('a');if(Number(snapshot.run.flow_version)>=2&&index===1)link.textContent='Explore (model only)'; link.setAttribute('aria-disabled', String(!done)); link.tabIndex = done ? 0 : -1;
    });
    const metrics = root.querySelector('[data-research-metrics]');
    metrics.querySelectorAll('strong').forEach((item, index) => item.textContent = [claims.length, snapshot.sources.length, snapshot.run.status][index]);
    updateSelection();
  }
  function updateSelection() {
    if (!root) return;
    const review = root.dataset.review === '1';
    const total = root.querySelectorAll('[data-dream-cards] input:checked').length;
    root.querySelector('[data-selection-count]').textContent = review ? `${total} of ${root.querySelectorAll('[data-dream-cards] input').length} claims selected · ${root.dataset.rigour === '1' ? 'up to ' + root.dataset.searchLimit + ' normal searches + 1 challenge' : 'will use up to 2 searches'}` : '';
    root.querySelector('[data-continue-claims]').disabled = review && total === 0;
    root.querySelector('[data-review-shortcuts]').hidden = !review;
  }
  root?.addEventListener('change', event => { if (event.target.matches('[name="claim_ids[]"]')) updateSelection(); });
  const usd=value=>'$'+Number(value||0).toFixed(Number(value)>0&&Number(value)<0.0001?6:4)+' USD estimated';
  const usageLabel=usage=>usd(usage.estimated_usd)+(usage.unknown_requests?' + '+usage.unknown_requests+' unpriced/unknown calls':'');
  function renderInsight(snapshot){
    const findings=snapshot.findings||[{status:'collecting',text:snapshot.sources.length+' cited sources collected.',reason:'Review source scope before treating a claim as established.',provisional:true}];
    document.querySelectorAll('[data-current-findings]:not([data-sentence-host])').forEach(list=>{list.replaceChildren();for(const finding of findings.slice(0,list.hasAttribute('data-map-findings')?3:5)){const item=element('li');item.append(element('strong',finding.status+(finding.provisional?' · provisional':'')+': '),document.createTextNode(finding.text+' '+finding.reason));list.append(item);}});
    const current=snapshot.requests.filter(q=>q.status==='pending').at(-1);
    root.dataset.requestStarted=String(current?.created_at||snapshot.run.busy_since||0);
    root.dataset.providerHeartbeat=String(current?.heartbeat_at||0);
    const activity=current?`${current.worker_role||current.kind} · ${current.model||snapshot.run.model||'model pending'} · ${current.activity||'Waiting for provider'}${current.output_chars?' · '+Number(current.output_chars).toLocaleString()+' response characters received':''}. Tokens are confirmed when the API finishes.`:snapshot.run.phase==='exploring'?'Model-only exploration is ready. Choose branches to grow or lock with Evidence.':snapshot.run.phase==='dream_review'?'Paused for your claim selection.':snapshot.run.phase==='verification_review'?'Verification finished. Review the findings, then finish or review gaps once.':snapshot.run.status==='running'?(snapshot.run.phase==='evidence'&&!Number(snapshot.run.auto_search)?'Paused for search direction. Continue the planned search or choose a path.':'Preparing the next specialist request…'):`${snapshot.run.status} · ${snapshot.run.stop_reason||'Saved findings are available.'}`;
    document.querySelectorAll('[data-live-activity]').forEach(node=>{if(node.textContent!==activity)node.textContent=activity;});
    if(!snapshot.usage)return;
    const usage=snapshot.usage,project=usage.project;
    document.querySelectorAll('[data-project-spend]').forEach(node=>node.textContent=usageLabel(project)+' · '+Number(project.total_tokens).toLocaleString()+' tokens'+(project.reserved_usd>0?' · $'+Number(project.reserved_usd).toFixed(4)+' reserved':''));
    const total=root.querySelector('[data-usage-total]');total.textContent=`${usageLabel(project)} · ${project.input_tokens.toLocaleString()} input / ${project.output_tokens.toLocaleString()} output · $${Number(project.reserved_usd).toFixed(4)} reserved or uncertain risk · $${Number(usage.cap_usd).toFixed(2)} cap`;
    const areas=root.querySelector('[data-usage-areas]');areas.replaceChildren();
    for(const area of usage.areas)areas.append(element('p',`${area.title} · priority ${area.weight} · ${usageLabel(area.usage)} · ${area.usage.total_tokens.toLocaleString()} tokens`));
    areas.append(element('p',`Shared planning, verification and Learn: ${usageLabel(usage.shared)} · ${usage.shared.total_tokens.toLocaleString()} tokens`));
    const rows=root.querySelector('[data-usage-requests]');rows.replaceChildren();
    for(const request of snapshot.requests){const row=element('tr');const label=element('th',(request.worker_role||request.kind)+(request.claim_id?' · claim #'+request.claim_id:' · shared'));label.scope='row';row.append(label);for(const value of [request.model||'Historical model unknown',request.token_input??'Unknown',request.cached_tokens??'Unknown',request.token_output??'Unknown',request.tool_calls??'Unknown',request.estimated_usd==null?'Unknown':'$'+Number(request.estimated_usd).toFixed(6),request.status])row.append(element('td',String(value)));rows.append(row);}
    const groups=root.querySelector('[data-usage-groups]');groups.replaceChildren();
    for(const [kind,buckets] of [['Phase',usage.phases],['Model',usage.models]])for(const [name,summary] of Object.entries(buckets))groups.append(element('p',`${kind}: ${name} · ${summary.total_tokens.toLocaleString()} tokens · ${usageLabel(summary)}`));
  }
  setInterval(()=>{if(!root)return;const now=Date.now(),started=Number(root.dataset.requestStarted||0)*1000,heartbeat=Number(root.dataset.providerHeartbeat||0)*1000,contact=Number(root.dataset.lastContact||0);const text=(started?'Request running '+Math.max(0,Math.floor((now-started)/1000))+'s. ':'')+(heartbeat?'Last provider activity '+Math.max(0,Math.floor((now-heartbeat)/1000))+'s ago. ':'')+(contact?'Progress connection checked '+Math.max(0,Math.floor((now-contact)/1000))+'s ago.':'Checking saved progress…');document.querySelectorAll('[data-live-clock]').forEach(node=>node.textContent=text);},1000);
  function renderPaths(snapshot){
    const container=root.querySelector('[data-research-paths]'),paths=snapshot.nodes.filter(n=>['dream_claim','question'].includes(n.type));
    const stamp=JSON.stringify([paths,snapshot.run.status,snapshot.run.busy_since,snapshot.requests.map(q=>[q.id,q.status,q.token_input,q.token_output,q.estimated_usd])]);if(container.dataset.stamp===stamp||container.contains(document.activeElement))return;container.dataset.stamp=stamp;container.replaceChildren();
    const editable=snapshot.run.status==='running'&&!snapshot.run.busy_since&&Number(snapshot.run.flow_version)===1;
    for(const node of paths){const card=element('article');card.dataset.speculative=String(Number(node.speculative||0));card.append(element('h4',node.title),element('p',`Priority ${node.path_weight||50} · ${node.model_tier||'primary'} tier · ${Number(node.speculative)?'Untested idea — may be wrong.':node.weight_reason||'Priority is not evidence strength.'}`));const direct=snapshot.requests.filter(q=>Number(q.path_node_id)===Number(node.id));const tokenTotal=direct.reduce((sum,q)=>sum+Number(q.token_input||0)+Number(q.token_output||0),0),costTotal=direct.reduce((sum,q)=>sum+Number(q.estimated_usd||0),0),unknown=direct.filter(q=>q.estimated_usd==null&&q.status!=='pending').length;card.append(element('p',`Direct path searches: ${usd(costTotal)}${unknown?' + '+unknown+' unknown calls':''} · ${tokenTotal.toLocaleString()} tokens`));if(node.type==='question')card.append(element('p',node.content));
      if(editable&&Number(node.selected??1)===1){const form=element('form',undefined,'path-priority-form');form.method='post';form.action=root.dataset.entry+'?view=research';form.dataset.researchAction='research_rank';for(const [name,value] of [['csrf',csrf()],['action','research_rank'],['run_id',snapshot.run.id],['node_id',node.id]]){const field=element('input');field.type='hidden';field.name=name;field.value=value;form.append(field);}const weightLabel=element('label','Priority weight'),weight=element('input');weight.type='number';weight.name='path_weight';weight.min=10;weight.max=100;weight.value=node.path_weight||50;weight.className='form-control';weightLabel.append(weight);const tierLabel=element('label','Model budget'),tier=element('select');tier.className='form-select';tier.name='model_tier';for(const [value,label] of [['primary','Automatic by priority'],['economy','Economy · GPT-4.1 mini']]){const option=element('option',label);option.value=value;option.selected=node.model_tier===value;tier.append(option);}tierLabel.append(tier);const button=element('button','Update path priority','btn btn-outline-primary');form.append(weightLabel,tierLabel,button);card.append(form);}container.append(card);}
  }
  function renderLedger(snapshot) {
    const ledger={...(snapshot.ledger||{}),edges:snapshot.ledger?.edges||[],passages:snapshot.ledger?.passages||[],claim_versions:snapshot.ledger?.claim_versions||[],sources:snapshot.ledger?.sources||[]};
    const panel=root.querySelector('[data-evidence-ledger]');
    const stamp=JSON.stringify([ledger,snapshot.nodes.filter(n=>n.type==='phase'&&['Verification','Repair'].includes(n.title)),snapshot.run.status,snapshot.run.phase,snapshot.run.evidence_frozen,snapshot.run.repair_count,snapshot.budget?.remaining]);
    if(panel.dataset.ledgerStamp===stamp)return;panel.dataset.ledgerStamp=stamp;panel.hidden=!Number(snapshot.run.rigour_version)&&!ledger.edges.length;
    const entries=panel.querySelector('[data-ledger-entries]');entries.replaceChildren();
    const passageView=passage=>{const details=element('details',undefined,'evidence-passage');details.append(element('summary',`Passage #${passage.id} · ${passage.fetch_scope}`),element('blockquote',passage.exact_text),element('small',`${passage.locator||'No locator supplied'} · SHA-256 ${passage.content_hash}`));return details;};
    for(const edge of ledger.edges){
      const card=element('article',undefined,'ledger-edge');card.append(element('h4',`Claim #${edge.claim_id} · ${edge.relation}`),element('p',edge.rationale),element('small',`${edge.strength||'Unrated'} strength · edge #${edge.id}`));
      const passage=ledger.passages.find(p=>Number(p.id)===Number(edge.passage_id));if(passage)card.append(passageView(passage));else card.append(element('p','Citation-only relation — no captured source passage.'));
      const source=ledger.sources.find(s=>Number(s.id)===Number(edge.source_id));if(source&&safeUrl(source.url)){const link=element('a',source.title);link.href=source.url;link.target='_blank';link.rel='noopener noreferrer';card.append(link,element('p',source.shared_origin||'Independence not assessed.'));}entries.append(card);
    }
    const history=panel.querySelector('[data-ledger-history]');history.replaceChildren();for(const version of ledger.claim_versions)history.append(element('p',`Claim #${version.claim_id} · ${version.status} · ${version.created_by} · ${version.rationale}`));
    const results=panel.querySelector('[data-verification-results]');results.replaceChildren();let high=false;
    for(const node of snapshot.nodes.filter(n=>n.type==='phase'&&['Verification','Repair'].includes(n.title))){let data={};try{data=JSON.parse(node.data_json||'{}');}catch(_){}results.append(element('h4',`${node.title} · ${node.status}`));for(const defect of data.defects||[]){results.append(element('p',`${defect.severity} · ${defect.kind} · ${defect.description}`));if(defect.severity==='high')high=true;}for(const check of data.calculations||[])results.append(element('p',`Arithmetic ${check.operation} (${check.a}, ${check.b}): reported ${check.reported}; expected ${check.expected??'undefined'} · ${check.arithmetic_correct?'passed':'failed'}. Operands still require source validation.`));if(node.status==='complete'&&!(data.defects||[]).length)results.append(element('p','No defects reported by the grouped model check. This is not independent source verification.'));}
    panel.querySelector('[data-ledger-freeze]').textContent=Number(snapshot.run.evidence_frozen)?`Frozen evidence version ${snapshot.run.evidence_version} · verification ${snapshot.run.verification_status}`:'Evidence is still provisional.';
    const reviewing=snapshot.run.status==='running'&&snapshot.run.phase==='verification_review';panel.querySelector('[data-verification-controls]').hidden=!reviewing;
    panel.querySelector('[data-research-action=research_repair] button').disabled=!reviewing||!high||Number(snapshot.run.repair_count)>0||snapshot.budget?.remaining===0;
    // Source library passages remain available without requiring a supporting edge.
    root.querySelectorAll('[data-source-id]').forEach(card=>{card.querySelector('[data-source-passages]')?.remove();const group=element('section');group.dataset.sourcePassages='1';group.append(element('h5','Captured passages'));const passages=ledger.passages.filter(p=>Number(p.source_id)===Number(card.dataset.sourceId));for(const passage of passages)group.append(passageView(passage));const source=ledger.sources.find(s=>Number(s.id)===Number(card.dataset.sourceId));if(source?.shared_origin)group.append(element('p',source.shared_origin));if(!passages.length)group.append(element('p','No exact source passage was returned.'));card.append(group);});
  }
  let livingSnapshot;
  function renderLiving(snapshot) {
    livingSnapshot=snapshot;
    const types=['dream_claim','question','model_idea','association','counter','edge_case','reframing'];
    const ideas=snapshot.nodes.filter(n=>types.includes(n.type));
    for(const form of document.querySelectorAll('[data-living-controls]')){
      const picker=form.querySelector('[data-living-nodes]'),stamp=JSON.stringify(ideas.map(n=>[n.id,n.title,n.layer,n.provenance_tag]));
      if(picker.dataset.stamp!==stamp){const selected=new Set([...picker.selectedOptions].map(o=>o.value));picker.replaceChildren();for(const node of ideas){const option=document.createElement('option');option.value=node.id;option.textContent=`#${node.id} · ${node.title} · ${['Active','Secondary','Tertiary'][Number(node.layer)||0]} · ${(node.provenance_tag||'legacy').replaceAll('_',' ')}`;option.selected=selected.has(String(node.id));picker.append(option);}picker.dataset.stamp=stamp;}
      const validate=()=>{
        const action=form.elements.action.value,chosen=[...picker.selectedOptions].map(o=>ideas.find(n=>String(n.id)===o.value)).filter(Boolean);
        const idle=snapshot.run.status==='running'&&!snapshot.run.busy_since&&['exploring','verification_review'].includes(snapshot.run.phase);
        let reason='';
        if(chosen.length>5)reason='Select at most five branches.';
        else if(action!=='research_explore'&&!chosen.length)reason='Select at least one branch.';
        else if(['research_explore','research_lock_evidence'].includes(action)&&!idle)reason='Wait for an idle exploration or verification-review stage; stopped/frozen runs cannot grow.';
        else if(action==='research_lock_evidence'&&chosen.some(n=>Number(n.layer)>1||n.locked_at!==null||n.origin!=='model_latent'))reason='Lock only unlocked Active or Secondary model branches.';
        else if(action==='research_lock_evidence'&&snapshot.budget.remaining<chosen.length+4)reason=`Lock-in needs ${chosen.length+4} available requests for planning, searches, extraction and verification.`;
        else if(action==='research_explore'&&snapshot.budget.remaining<1)reason='Hourly request limit reached.';
        form.querySelector('button').disabled=!!reason;form.querySelector('[data-living-help]').textContent=reason|| (action==='research_lock_evidence'?'This explicitly starts a bounded Evidence plan for the selected branches only. Unselected ideas stay on the map.':action==='research_explore'?'Model-only growth: provisional ideas, no web tools or sources.':'Metadata only: no AI call and no deleted nodes.');
      };
      form.onchange=validate;validate();
    }
  }
  function render(snapshot) {
    if (!root || !snapshot.run) return;
    root.dispatchEvent(new Event('research:contact'));
    const serial = JSON.stringify({...snapshot,server_time:undefined});
    if (serial === lastSnapshot) return;
    lastSnapshot = serial;
    const run = snapshot.run;
    root.dataset.rigour = String(run.rigour_version || 0); root.dataset.searchLimit = String(run.normal_search_limit || 2);
    showBudget(snapshot.budget);
    const active = ['pending', 'running'].includes(run.status);
    const review = active && run.phase === 'dream_review';
    root.dataset.review = review ? '1' : '0';
    root.dataset.autoSearch = String(run.auto_search||0);
    root.querySelector('[data-review-copy]').hidden = !review;
    root.querySelector('[data-continue-claims]').hidden = !review;
    renderClaims(snapshot, review);
    const pending = snapshot.requests.some(request => request.status === 'pending');
    root.dataset.status = run.status;
    root.dataset.phase = run.phase;
    root.dataset.busy = run.busy_since ? '1' : '0';
    root.querySelector('[data-research-summary]').textContent = `${run.status} · ${review ? 'Dream complete — review claims' : run.phase} · ${run.token_input} input / ${run.token_output} output tokens confirmed${run.usage_unknown ? ' · some usage unknown' : ''}`;
    root.querySelector('[data-research-reason]').textContent = run.stop_reason;
    root.querySelector('[data-stop-control]').hidden = !(active || pending);
    root.querySelector('[data-step-control]').hidden = !active || !!run.busy_since || review || ['verification_review','exploring'].includes(run.phase);
    renderLiving(snapshot);
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
      if (item.status === 'running' || item.status === 'pending') node.open = true;
      node.id = 'research-node-'+item.id;
      node.dataset.status = item.status;
      node.querySelector('summary > span').textContent = item.title;
      node.querySelector('summary > small').textContent = `${item.type} · ${item.status}`;
      node.querySelector('.node-content').textContent = item.content;
      parents.set(Number(item.id), node);
    }
    // Moving existing nodes preserves their expanded state and keyboard focus.
    for (const item of snapshot.nodes.filter(item => item.type !== 'learn')) {
      const node = parents.get(Number(item.id));
      let parent = item.parent_id ? parents.get(Number(item.parent_id))?.querySelector('.node-children') : tree;
      if (!parent || node.contains(parent)) parent = tree;
      if (node.parentNode !== parent) parent.append(node);
    }
    const cards = root.querySelector('[data-research-sources]');
    const saved = new Set([...cards.querySelectorAll('[data-source-id]')].map(card => Number(card.dataset.sourceId)));
    for (const source of snapshot.sources) {
      if (saved.has(Number(source.id)) || !safeUrl(source.url)) continue;
      cards.append(sourceCard(source));
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
    root.querySelector('[data-learn-submit]').disabled = active || pending || (Number(run.rigour_version)===1&&!Number(run.evidence_frozen));
    document.querySelectorAll('[data-research-action=research_start] button').forEach(button => button.disabled = active || pending);
    root.querySelector('[data-recovery]').hidden = !['interrupted','uncertain','incomplete','failed'].includes(run.status);
    root.querySelector('[data-learn-followups]').hidden = !snapshot.nodes.some(node => node.type === 'learn' && node.status === 'complete');
    root.querySelectorAll('[data-learn-followup]').forEach(button => button.disabled = active || pending || !hasSources);
    if (!active && !pending) { stepping = false; }
    renderLedger(snapshot);renderInsight(snapshot);renderPaths(snapshot);
    root.dispatchEvent(new CustomEvent('research:snapshot', {detail:snapshot}));
  }
  async function send(form) {
    const destination = new URL(form.getAttribute('action') || location.href, location.href);
    destination.searchParams.set('research_api', '1');
    const payload = new FormData(form);
    if (form.dataset.researchAction === 'research_continue') payload.set('selection_explicit', '1');
    const response = await fetch(destination, {method: 'POST', body: payload, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
    const data = await researchResponse(response, 'Research request');
    if (!response.ok || data.error) throw new Error(data.error || 'Research request failed. Inspect the saved state before continuing.');
    return data;
  }
  async function advance(manual = false) {
    if (!root || stepping || halted || root.dataset.status !== 'running' || root.dataset.busy === '1' || root.dataset.review === '1' || ['verification_review','exploring'].includes(root.dataset.phase)) return;
    if (!manual && root.dataset.phase === 'evidence' && root.dataset.autoSearch !== '1') return;
    const form = root.querySelector('[data-research-action=research_step]');
    stepping = true;
    try { render(await send(form)); }
    catch (failure) { diagnosticFailure('phase', failure); halted = true; error(failure.message); }
    finally { stepping = false; }
    if (!halted && root.dataset.status === 'running') setTimeout(advance, 200);
  }
  async function poll(force = false) {
    if (!root || polling) return;
    const pending = !root.querySelector('[data-stop-control]').hidden;
    if (!force && !['pending', 'running'].includes(root.dataset.status) && !pending) return;
    polling = true;
    try {
      const url = new URL(root.dataset.entry, location.href); url.searchParams.set('view', 'research'); url.searchParams.set('research_api', '1');
      const payload = new FormData(); payload.set('action', 'research_status'); payload.set('csrf', csrf()); payload.set('run_id', root.dataset.runId);
      const response = await fetch(url, {method:'POST',body:payload,credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});
      const data = await researchResponse(response, 'Progress check');
      root.dataset.lastContact=String(Date.now());render(data);
      if (!halted) advance();
    } catch (failure) { diagnosticFailure('poll', failure); halted = true; error(failure.message); }
    finally { polling = false; }
  }
  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.researchAction || form.dataset.researchAction === 'research_export') return;
    event.preventDefault();
    const action = form.dataset.researchAction;
    const button = event.submitter || form.querySelector('button:not([type=button])'); button.disabled = true;
    if (action === 'research_stop') halted = true;
    if (action === 'research_step' && !form.hasAttribute('data-map-focus')) { halted = false; advance(true); button.disabled = false; return; }
    if (action === 'research_learn') { root.querySelectorAll('[data-learn-followup], [data-learn-submit]').forEach(button => button.disabled = true); error('Explaining collected evidence… No web search.'); root.querySelector('[data-stop-control]').hidden = false; }
    try {
      const snapshot = await send(form);
      if(action==='research_settings'){location.reload();return;}
      if (action === 'research_start') {
        const url = new URL(form.getAttribute('action'), location.href); url.searchParams.set('project', snapshot.run.project_id); url.searchParams.set('run', snapshot.run.id); if(form.dataset.researchFromMap==='1')url.searchParams.set('map','1'); location.assign(url); return;
      }
      render(snapshot); error('');
      if (action === 'research_continue' || (action==='research_living'&&form.elements.action.value==='research_lock_evidence') || form.hasAttribute('data-map-focus')) { halted = false; advance(); }
    } catch (failure) { diagnosticFailure(action, failure); error(failure.message);  }
    finally { button.disabled = false;if(livingSnapshot)renderLiving(livingSnapshot); root?.dispatchEvent(new Event('research:controls')); }
  });
  root?.addEventListener('click', event => {
    const quick = event.target.closest('[data-keep]');
    if (quick && root.dataset.review === '1') {
      root.querySelectorAll('[data-dream-cards] input').forEach(input => {
        const card = input.closest('[data-claim-id]');
        const priority = !![...card.querySelectorAll('.claim-badge')].find(badge => badge.textContent === 'High priority');
        const confidence = card.querySelector('[data-confidence]')?.dataset.confidence;
        input.checked = quick.dataset.keep === 'all' || (quick.dataset.keep === 'priority' ? priority : confidence !== 'low');
      });
      updateSelection();
    }
    const stage = event.target.closest('[data-phase-rail] a');
    if (stage) {
      event.preventDefault(); if (stage.getAttribute('aria-disabled') === 'true') return;
      root.querySelector('section[id^=investigate-]').hidden = false; root.querySelector('[data-learn-panel]').hidden = true; root.dataset.selectedTab = 'investigate';
      const target = document.querySelector(stage.getAttribute('href')); if (target.tagName === 'DETAILS') target.open = true;
      target.tabIndex = -1; target.focus(); target.scrollIntoView({block:'start'});
    }
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
    poll(true);
  }
})();

// Admin map inspection reuses the map's pure projection, without changing saved state.
(() => {
  const root=document.querySelector('[data-project-json-inspector]'); if(!root)return;
  const bundleField=document.getElementById('project-debug-bundle'),baseBundle=JSON.parse(bundleField.value);
  function download(text,kind){const url=URL.createObjectURL(new Blob([text+'\n'],{type:'application/json'})),link=document.createElement('a');link.href=url;link.download=`research-project-${root.dataset.projectId}-run-${root.dataset.runId}-${kind}.json`;document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);}
  root.querySelectorAll('[data-inspection-download]').forEach(form=>form.addEventListener('submit',event=>{event.preventDefault();const kind=form.dataset.inspectionDownload;download(kind==='payload'?document.getElementById('project-map-payload').value:bundleField.value,kind);}));
  if(root.dataset.runId==='0'||!globalThis.AIcontrolMap)return;
  const panel=root.querySelector('[data-project-projection]'),field=document.getElementById('project-map-projection'),summary=root.querySelector('[data-project-projection-summary]');
  function update(){
    try {
      const snapshot=JSON.parse(document.getElementById('project-map-payload').value);if(!snapshot||!Array.isArray(snapshot.nodes))throw new Error('payload');
      const filter=root.querySelector('[data-project-projection-filter]').value,projection=AIcontrolMap.project(snapshot,filter),visible=new Set(projection.nodes.map(node=>String(node.id)));
      const report={project_id:Number(root.dataset.projectId),run_id:Number(root.dataset.runId),source_snapshot_server_time:snapshot.server_time,engine:'AIcontrolMap.project',filter:projection.filter,
        projection,stored_node_ids_without_same_id_card:snapshot.nodes.filter(node=>!visible.has(String(node.id))).map(node=>node.id),
        note:'Filtered/grouped records and process wrappers need not have a same-ID card. Layout, zoom and collapsed branches in the research window are separate browser state.'};
      field.value=JSON.stringify(report,null,2);bundleField.value=JSON.stringify({...baseBundle,browser_projection:report},null,2);
      summary.textContent=`${snapshot.nodes.length} input nodes → ${projection.nodes.length} map cards and ${projection.edges.length} links · ${projection.filter} filter${projection.empty?' · '+projection.empty_reason:''}`;panel.hidden=false;
    }catch{field.value='';summary.textContent='Projection could not be built. Copy the debug bundle to inspect the stored rows and payload error.';panel.hidden=false;}
  }
  root.querySelector('[data-project-projection-filter]').addEventListener('change',update);update();
  root.querySelector('[data-download-map-projection]').addEventListener('click',()=>{if(field.value)download(field.value,'projected');});
})();
