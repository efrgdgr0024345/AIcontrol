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
  const csrf = () => document.querySelector('input[name=csrf]')?.value || '';
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
        const data = claimData(claim), label = element('label', undefined, 'claim-selection');
        const checkbox = element('input'); checkbox.type = 'checkbox'; checkbox.name = 'claim_ids[]'; checkbox.value = claim.id; checkbox.checked = checked;
        label.append(checkbox, element('span', `Include claim #${claim.id}`));
        const badges = element('p', undefined, 'claim-badges'), confidence = element('span', `${data.confidence} prior confidence`, 'claim-badge'); confidence.dataset.confidence = data.confidence; badges.append(confidence);
        if (data.priority === 'high') badges.append(element('span', 'High priority', 'claim-badge'));
        const uncertainty = element('p'); uncertainty.append(element('strong', 'Uncertainty: '), document.createTextNode(data.uncertainty));
        const verify = element('p'); verify.append(element('strong', 'Verify: '), document.createTextNode(data.verify));
        card.replaceChildren(element('div', `PRIOR / ${String(claim.id).padStart(2, '0')}`, 'claim-number'), label, element('h4', data.claim), badges, uncertainty, verify);
        if (Number(claim.selected ?? 1) === 0) card.append(element('p', 'Excluded from verification'));
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
      const outcome = Number(claim.selected ?? 1) === 0 ? 'Excluded from verification' : (data.verdict || verdict?.title.split(' · ')[0] || 'open');
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
    const stages = ['dream', 'dream_review', 'plan', 'evidence', Number(snapshot.run.rigour_version)===1?'verification':'reconciliation'];
    root.querySelectorAll('[data-phase-rail] li').forEach((item, index) => {
      item.removeAttribute('aria-current');
      if (stages[index] === snapshot.run.phase) item.setAttribute('aria-current', 'step');
      const done = snapshot.nodes.some(node => node.type === 'phase' && node.title.toLowerCase() === stages[index] && node.status === 'complete') || (stages[index] === 'dream_review' && stages.indexOf(snapshot.run.phase) > index);
      item.dataset.done = done ? '1' : '0';
      const link = item.querySelector('a'); link.setAttribute('aria-disabled', String(!done)); link.tabIndex = done ? 0 : -1;
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
    const activity=current?`${current.worker_role||current.kind} · ${current.model||snapshot.run.model||'model pending'} · ${current.activity||'Waiting for provider'}${current.output_chars?' · '+Number(current.output_chars).toLocaleString()+' response characters received':''}. Tokens are confirmed when the API finishes.`:snapshot.run.phase==='dream_review'?'Paused for your claim selection.':snapshot.run.phase==='verification_review'?'Verification finished. Review the findings, then finish or review gaps once.':snapshot.run.status==='running'?(snapshot.run.phase==='evidence'&&!Number(snapshot.run.auto_search)?'Paused for search direction. Continue the planned search or choose a path.':'Preparing the next specialist request…'):`${snapshot.run.status} · ${snapshot.run.stop_reason||'Saved findings are available.'}`;
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
    const ledger=snapshot.ledger||{edges:[],passages:[],claim_versions:[],sources:[]};
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
  function render(snapshot) {
    if (!root || !snapshot.run) return;
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
    root.querySelector('[data-step-control]').hidden = !active || !!run.busy_since || review || run.phase === 'verification_review';
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
    if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Research session expired. Sign in again and inspect the saved run before continuing.');
    const data = await response.json();
    showBudget(data.budget);
    if (data.budget && root) root.dispatchEvent(new CustomEvent('research:budget',{detail:data.budget}));
    if (!response.ok || data.error) throw new Error(data.error || 'Research request failed. Inspect the saved state before continuing.');
    return data;
  }
  async function advance(manual = false) {
    if (!root || stepping || halted || root.dataset.status !== 'running' || root.dataset.busy === '1' || root.dataset.review === '1' || root.dataset.phase === 'verification_review') return;
    if (!manual && root.dataset.phase === 'evidence' && root.dataset.autoSearch !== '1') return;
    const form = root.querySelector('[data-research-action=research_step]');
    stepping = true;
    try { render(await send(form)); }
    catch (failure) { halted = true; error(failure.message); }
    finally { stepping = false; }
    if (!halted && root.dataset.status === 'running') setTimeout(advance, 200);
  }
  async function poll(force = false) {
    if (!root || polling) return;
    const pending = !root.querySelector('[data-stop-control]').hidden;
    if (!force && !['pending', 'running'].includes(root.dataset.status) && !pending) return;
    polling = true;
    try {
      const url = new URL(root.dataset.entry, location.href); url.searchParams.set('research_api', 'status'); url.searchParams.set('run_id', root.dataset.runId);
      const response = await fetch(url, {credentials: 'same-origin', headers: {'X-CSRF-Token': csrf(), 'Accept': 'application/json'}});
      if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Progress could not be verified. Sign in again if needed; no phase will be retried automatically.');
      const data = await response.json(); if (data.error) throw new Error(data.error);
      root.dataset.lastContact=String(Date.now());render(data);
      if (!halted) advance();
    } catch (failure) { halted = true; error(failure.message); }
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
      if (action === 'research_continue' || form.hasAttribute('data-map-focus')) { halted = false; advance(); }
    } catch (failure) { error(failure.message);  }
    finally { button.disabled = false; root?.dispatchEvent(new Event('research:controls')); }
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

// Spatial view of the same persisted nodes. No provider or database operations live here.
(() => {
  'use strict';
  const root = document.querySelector('[data-investigation]');
  if (!root) return;
  const Engine=window.AIcontrolMap;if(!Engine)return;
  const map = root.querySelector('[data-mindmap]'), world = map.querySelector('[data-map-world]');
  const viewport = map.querySelector('[data-map-viewport]'), space = map.querySelector('[data-map-space]');
  const inspector = map.querySelector('[data-map-inspector-content]');
  const collapsed = new Set(), selectedSources = new Set();
  let positions=new Map(),layoutSignature='';const reading=new Engine.ReadingQueue();
  let snapshot, nodes = [], visible = [], selected = 'root', filter = 'all', scale = 1, width = 600, height = 400, signature = '', count = -1;
  let view = new URLSearchParams(location.search).get('map') === '1' || matchMedia('(min-width: 900px)').matches ? 'map' : 'list';
  const el = (tag, text, className) => { const node = document.createElement(tag); if (text !== undefined) node.textContent = text; if (className) node.className = className; return node; };
  const data = node => { try { return JSON.parse(node.data_json || '{}'); } catch (_) { return {}; } };
  const safe = value => { try { const url = new URL(value); return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password; } catch (_) { return false; } };
  const claimInputs = () => [...root.querySelectorAll('[data-dream-cards] input')];
  const reviewing = () => snapshot?.run.status === 'running' && snapshot.run.phase === 'dream_review';
  const home = document.createComment('Map location'); map.before(home);
  const header=el('header',undefined,'map-screen-header');
  const projectName=document.querySelector('.research-project-brief h2')?.textContent||'Research map';
  const heading=el('div',undefined,'map-project-label');heading.append(el('strong',projectName),el('span','','map-screen-status'));
  heading.querySelector('span').setAttribute('role','status');
  const ticker=el('button',undefined,'map-idea-sentence');ticker.type='button';ticker.dataset.ideaSentence='1';ticker.setAttribute('aria-label','Pause idea rotation');ticker.setAttribute('aria-pressed','false');
  ticker.append(el('span','Research ideas will appear here as the map develops.'));ticker.querySelector('span').setAttribute('role','status');ticker.querySelector('span').setAttribute('aria-live','polite');
  const handle=el('button','☰','map-controls-handle btn');handle.type='button';handle.dataset.mapControls='1';handle.setAttribute('aria-label','Map controls');handle.setAttribute('aria-expanded','false');handle.setAttribute('aria-controls','map-controls-tray');
  const tray=el('div',undefined,'map-controls-tray');tray.id='map-controls-tray';tray.hidden=true;tray.setAttribute('role','group');tray.setAttribute('aria-label','Map controls and usage');
  const actions=el('div',undefined,'map-screen-actions');
  const screenButton=(label,action,style='btn-outline-secondary')=>{const button=el('button',label,'btn '+style);button.type='button';button.dataset.mapScreenAction=action;return button;};
  actions.append(screenButton('▶ Start research','start','btn-primary'),screenButton('■ Stop research','stop','btn-outline-danger'),screenButton('Continue planned search','step'),screenButton('Finish and freeze','finish','btn-primary'),screenButton('Review gaps once','repair'),screenButton('Project menu','menu'),screenButton('Details','details'),screenButton('List','list'));
  actions.children[0].setAttribute('aria-label','Start research');actions.children[1].setAttribute('aria-label','Stop research');actions.children[1].setAttribute('aria-keyshortcuts','Alt+S');
  tray.append(actions,map.querySelector('.mindmap-toolbar'),map.querySelector('[data-map-outcomes]'));
  const live=el('p');live.dataset.liveActivity='1';live.setAttribute('role','status');const clock=el('small');clock.dataset.liveClock='1';clock.setAttribute('aria-live','off');const spend=el('p');spend.dataset.projectSpend='1';
  const history=el('details',undefined,'map-idea-history'),historyTitle=el('summary','Idea history'),historyList=el('ol');history.append(historyTitle,historyList);tray.append(live,clock,spend,map.querySelector('[data-map-evidence-help]'),history);
  header.append(heading,ticker,handle,tray);map.prepend(header);
  const notification=el('button','','map-state-notice');notification.type='button';notification.hidden=true;notification.setAttribute('aria-label','Review research status');notification.addEventListener('click',()=>controls(true));map.append(notification);
  let controlsTimer,hoverTimer,firstIdeas=true;const knownIdeas=new Set();
  function controls(open){clearTimeout(controlsTimer);tray.hidden=!open;handle.setAttribute('aria-expanded',String(open));}
  handle.addEventListener('click',()=>{clearTimeout(hoverTimer);controls(tray.hidden);});handle.addEventListener('pointerenter',event=>{if(event.pointerType==='mouse')hoverTimer=setTimeout(()=>controls(true),180);});
  const leaveControls=()=>{clearTimeout(hoverTimer);controlsTimer=setTimeout(()=>{if(!tray.contains(document.activeElement)&&document.activeElement!==handle)controls(false);},450);};handle.addEventListener('pointerleave',leaveControls);tray.addEventListener('pointerenter',()=>clearTimeout(controlsTimer));tray.addEventListener('pointerleave',leaveControls);
  const tickers=[ticker,...document.querySelectorAll('[data-research-now] [data-idea-sentence]')];
  for(const button of tickers)button.addEventListener('click',()=>{reading.paused=!reading.paused;for(const item of tickers){item.setAttribute('aria-pressed',String(reading.paused));item.setAttribute('aria-label',reading.paused?'Resume idea rotation':'Pause idea rotation');}});
  setInterval(()=>{
    const next=reading.tick(Date.now(),!document.hidden);if(!next)return;historyTitle.textContent='Idea history · '+reading.items.length+' queued';
    for(const button of tickers)button.classList.add('idea-fading');const change=()=>{for(const button of tickers){button.querySelector('span').textContent=next;button.title=next;button.classList.remove('idea-fading');}};
    if(matchMedia('(prefers-reduced-motion: reduce)').matches)change();else setTimeout(change,220);
  },250);
  document.addEventListener('visibilitychange',()=>reading.tick(Date.now(),false));
  function enqueueIdeas(){
    const findings=[...(snapshot.findings||[])].filter(f=>f.status!=='collecting');
    const preview=finding=>(finding.provisional?'Provisional idea: ':({'supported':'Evidence suggests: ','weakened':'Reconsidering: ','contradicted':'Counter-evidence: ','open':'Open question: '}[finding.status]||finding.status+': '))+finding.text;
    if(firstIdeas)for(const finding of findings)reading.add(preview(finding));
    for(const node of snapshot.nodes.filter(n=>['dream_claim','question'].includes(n.type))){if(!knownIdeas.has(node.id)&&(!firstIdeas||!findings.length))reading.add((node.type==='question'?'Untested idea: ':'Idea to test: ')+(data(node).claim||node.title));knownIdeas.add(node.id);}
    if(!firstIdeas)for(const finding of findings.reverse())reading.add(preview(finding));
    firstIdeas=false;historyTitle.textContent='Idea history · '+reading.items.length+' queued';historyList.replaceChildren();for(const text of reading.history)historyList.append(el('li',text));
  }
  const side=map.querySelector('[data-map-inspector]');side.hidden=true;const closeSide=el('button','×','map-inspector-close btn');closeSide.type='button';closeSide.setAttribute('aria-label','Close details');closeSide.addEventListener('click',()=>{details(false);viewport.focus();});side.prepend(closeSide);
  let underlay=[],previousFocus=null,manualViewport=false;
  function showView(next) {
    view=next;map.hidden=view!=='map';root.querySelector('[data-investigate-list]').hidden=view!=='list';
    root.querySelectorAll('[data-investigate-view]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.investigateView===view)));
    if(view==='map'){
      controls(false);details(false);previousFocus=document.activeElement;document.body.append(map);document.body.classList.add('research-map-open');
      map.setAttribute('role','dialog');map.setAttribute('aria-modal','true');map.setAttribute('aria-label','Full screen research map');
      underlay=[...document.body.children].filter(node=>node!==map).map(node=>({node,inert:node.inert,hidden:node.getAttribute('aria-hidden')}));
      underlay.forEach(({node})=>{node.inert=true;node.setAttribute('aria-hidden','true');node.classList.add('map-underlay');});
      viewport.focus({preventScroll:true});requestAnimationFrame(()=>{if(snapshot)draw();fit();});
    }else{
      underlay.forEach(({node,inert,hidden})=>{node.inert=inert;hidden===null?node.removeAttribute('aria-hidden'):node.setAttribute('aria-hidden',hidden);node.classList.remove('map-underlay');});underlay=[];
      document.body.classList.remove('research-map-open');home.after(map);map.removeAttribute('role');map.removeAttribute('aria-modal');
      const back=previousFocus?.isConnected&&previousFocus!==document.body?previousFocus:root.querySelector('[data-investigate-view=list]');back?.focus({preventScroll:true});
    }
  }
  function screenControls(){
    if(!snapshot)return;
    const active=['pending','running'].includes(snapshot.run.status),pending=snapshot.requests.some(request=>request.status==='pending');
    actions.querySelector('[data-map-screen-action=start]').disabled=active||pending||root.dataset.connected==='0'||(snapshot.budget&&snapshot.budget.remaining<6);
    actions.querySelector('[data-map-screen-action=stop]').disabled=!(active||pending);
    actions.querySelector('[data-map-screen-action=step]').hidden=!active||!!snapshot.run.busy_since||snapshot.run.phase!=='evidence';
    const verificationReview=active&&snapshot.run.phase==='verification_review';
    for(const action of ['finish','repair']){const button=actions.querySelector(`[data-map-screen-action=${action}]`);button.hidden=!verificationReview;button.disabled=root.querySelector(`[data-research-action=research_${action}] button`).disabled||pending;}
    map.querySelector('[data-map-evidence-help]').textContent=Number(snapshot.run.rigour_version)===1?'Choose a direction or continue the planned search. The reserved Challenge always seeks counter-evidence, including when redirected.':'Choose a direction or continue the planned search. Each uses a remaining slot; at most two searches per run.';
    heading.querySelector('strong').textContent=`${projectName} · #${snapshot.run.id}`;
    notification.hidden=!['failed','uncertain','incomplete','interrupted'].includes(snapshot.run.status)&&!verificationReview;notification.textContent=verificationReview?'Verification ready · review':snapshot.run.status+' · open controls';handle.dataset.state=snapshot.run.status;handle.title=active?'Research active · open controls to stop (Alt+S)':'Map controls and usage';
    heading.querySelector('span').textContent=`${reviewing()?'Review claims to continue':verificationReview?'Review verification findings · finish or review gaps':snapshot.run.status+' · '+snapshot.run.phase} · ${snapshot.run.token_input} in / ${snapshot.run.token_output} out tokens${snapshot.run.usage_unknown?' · some usage unknown':''}${snapshot.budget?' · '+snapshot.budget.remaining+'/'+(snapshot.budget.limit||10)+' requests remaining':''}`;
  }
  function details(open){if(open)controls(false);side.hidden=!open;map.classList.toggle('map-details-open',open);actions.querySelector('[data-map-screen-action=details]').setAttribute('aria-expanded',String(open));}
  header.addEventListener('click',event=>{
    const action=event.target.closest('[data-map-screen-action]')?.dataset.mapScreenAction;if(!action)return;
    if(action==='list'){showView('list');return;}
    if(action==='menu'){showView('list');const menu=document.querySelector('[data-project-menu]');menu.open=true;menu.querySelector('summary').focus();menu.scrollIntoView({block:'start'});return;}
    if(action==='details'){details(side.hidden);if(!side.hidden)closeSide.focus();return;}
    const form=root.querySelector(`[data-research-action=research_${action}]`);
    if(action==='start'&&form)form.dataset.researchFromMap='1';
    form?.requestSubmit();
  });
  map.addEventListener('keydown',event=>{
    if(event.altKey&&event.key.toLowerCase()==='s'){event.preventDefault();root.querySelector('[data-research-action=research_stop]')?.requestSubmit();return;}
    if(event.key==='Escape'){event.preventDefault();if(!tray.hidden){controls(false);handle.focus();}else if(!side.hidden){details(false);viewport.focus();}else showView('list');return;}
    if(event.key!=='Tab')return;
    const stops=[...map.querySelectorAll('button:not(:disabled),input:not(:disabled),textarea:not(:disabled),a[href],[tabindex="0"]')].filter(node=>node.getClientRects().length);
    const first=stops[0],last=stops.at(-1);
    if(event.shiftKey&&document.activeElement===first){event.preventDefault();last?.focus();}
    else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}
  });
  viewport.addEventListener('wheel',()=>manualViewport=true,{passive:true});viewport.addEventListener('pointerdown',event=>{if(!event.target.closest('[data-map-node]'))manualViewport=true;});
  root.querySelector('[data-mindmap-switch]').hidden = false;
  root.querySelectorAll('[data-investigate-view]').forEach(button => button.addEventListener('click', () => showView(button.dataset.investigateView)));
  function normalize() {
    nodes = [{id:'root', parent_id:null, type:'root', status:snapshot.run.status, title:`Investigation #${snapshot.run.id}`, content:snapshot.run.stop_reason || 'Unchecked prior → review → plan → evidence → findings.'}, ...snapshot.nodes.map(node => ({...node,id:String(node.id),parent_id:node.parent_id === null ? 'root' : String(node.parent_id)}))];
    for (const source of snapshot.sources) {
      if (!nodes.some(node => node.id === String(source.node_id))) nodes.push({id:`source-${source.id}`,parent_id:'root',type:'source',title:source.title,content:source.extract,status:'complete',source_ids:[Number(source.id)]});
    }
    for (const node of nodes) if(node.type==='dream_claim')node.confidence ??= data(node).confidence || 'unknown';
    for(const passage of snapshot.ledger?.passages||[]){const source=snapshot.sources.find(s=>Number(s.id)===Number(passage.source_id));nodes.push({id:`passage-${passage.id}`,parent_id:source?String(source.node_id):'root',type:'passage',status:'complete',title:`Passage #${passage.id} · ${passage.fetch_scope}`,content:passage.exact_text+'\nLocator: '+(passage.locator||'not supplied')+'\nSHA-256: '+passage.content_hash,source_ids:[Number(passage.source_id)]});}
    for(const edge of snapshot.ledger?.edges||[])nodes.push({id:`edge-${edge.id}`,parent_id:String(edge.claim_id),type:'edge',status:'complete',title:edge.relation+' · '+(edge.strength||'unrated')+' model assessment',content:edge.rationale,source_ids:[Number(edge.source_id)],linked_claim_ids:[Number(edge.claim_id)]});
    for(const concept of Engine.project(snapshot).nodes){const saved=nodes.find(n=>n.id===concept.id);if(saved)Object.assign(saved,concept);}
    const ids = new Set(nodes.map(node => node.id));
    for (const node of nodes) if (node.id !== 'root' && !ids.has(node.parent_id)) node.parent_id = 'root';
  }
  function sourceIds(node) {
    return [...new Set([...(node.source_ids || []), ...snapshot.sources.filter(source => String(source.node_id) === node.id).map(source => Number(source.id))])].map(Number);
  }
  function links(node) {
    if (node.type === 'dream_claim') return [Number(node.id)];
    const ids = [...(node.linked_claim_ids || [])]; let current = node; const seen = new Set();
    while (current && !seen.has(current.id)) { seen.add(current.id); if (current.type === 'dream_claim') ids.push(Number(current.id)); current = nodes.find(parent => parent.id === current.parent_id); }
    return [...new Set(ids)].map(Number);
  }
  function setScale(value) {
    scale = Math.max(.65, Math.min(2.2, value));
    world.style.setProperty('--map-scale',scale); world.style.transform = `scale(${scale})`; space.style.width = `${width * scale}px`; space.style.height = `${height * scale}px`;
  }
  function fit() { if (!map.hidden) { setScale(Math.min(1, (viewport.clientWidth - 32) / width, (viewport.clientHeight - 32) / height)); viewport.scrollTo(0, 0); } }
  function pathIds(node) {
    const path = new Set([node.id, ...links(node).map(String)]);
    for (const id of sourceIds(node)) for (const source of snapshot.sources) if (Number(source.id) === id) path.add(String(source.node_id));
    for (const id of [...path]) { let current = nodes.find(item => item.id === id); const seen = new Set(); while (current && !seen.has(current.id)) { seen.add(current.id); path.add(current.id); current = nodes.find(parent => parent.id === current.parent_id); } }
    return path;
  }
  function syncSelection() {
    const inputs = claimInputs(), checked = new Set(inputs.filter(input => input.checked).map(input => input.value));
    for (const card of world.querySelectorAll('[data-map-node]')) {
      const node = nodes.find(item => item.id === card.dataset.mapNode)||visible.find(item=>item.id===card.dataset.mapNode);
      const input = card.querySelector('[data-map-claim]');
      if (input) { input.checked = checked.has(node.id); input.disabled = !reviewing(); }
      card.dataset.excluded = String(node.type === 'dream_claim' && !(reviewing() ? checked.has(node.id) : Number(node.selected ?? 1) === 1));
    }
    map.querySelector('[data-map-review]').hidden = !reviewing();
    map.querySelector('[data-map-selection-count]').textContent = `${checked.size} of ${inputs.length} claims selected · ${Number(snapshot.run.rigour_version)===1?'up to '+snapshot.run.normal_search_limit+' normal searches + 1 challenge':'up to two searches'}`;
    map.querySelector('[data-map-continue]').disabled = !reviewing() || !checked.size;
  }
  function draw() {
    const graph=Engine.project(snapshot,filter),byId=new Map(graph.nodes.map(n=>[n.id,n])),children=new Map(graph.nodes.map(n=>[n.id,[]]));
    for(const node of graph.nodes)if(node.parent_id&&children.has(node.parent_id))children.get(node.parent_id).push(node);
    const active=pathIds(nodes.find(n=>n.id===selected)||nodes[0]);active.add(Engine.owner(snapshot,selected));
    visible=[];const visited=new Set();
    function collect(node){if(!node||visited.has(node.id))return;visited.add(node.id);visible.push(node);if(!collapsed.has(node.id))for(const child of children.get(node.id)||[])collect(child);}
    collect(byId.get('root'));
    world.querySelector('svg')?.remove();
    for (const card of [...world.querySelectorAll('[data-map-node]')]) if (!visible.some(node => node.id === card.dataset.mapNode)) card.remove();
    for (const node of visible) {
      let card = [...world.querySelectorAll('[data-map-node]')].find(card => card.dataset.mapNode === node.id);
      if (!card) {
        card = el('article',undefined,'map-node'); card.dataset.mapNode=node.id;
        const button=el('button',undefined,'map-node-button'); button.type='button'; button.append(el('span','','map-node-type'),el('strong','','map-node-title'),el('span','','map-node-status')); card.append(button); world.append(card);
      }
      card.dataset.type=node.type;card.dataset.working=String(!!node.working);card.dataset.activity=node.activity||''; card.dataset.status=node.status; card.dataset.path=String(active.has(node.id));
      const priority=Number(node.path_weight||50);card.dataset.priority=priority>=75?'high':priority<40?'low':'normal';card.dataset.speculative=String(Number(node.speculative||0));
      const button=card.querySelector('button'); button.setAttribute('aria-pressed',String(node.id===selected));
      button.querySelector('.map-node-type').textContent=(node.type==='dream_claim'?'concept':node.type==='root'?'project':node.type.replaceAll('_',' ')) + (collapsed.has(node.id)?' · collapsed':'');
      button.querySelector('.map-node-title').textContent=node.title;
      const source=snapshot.sources.find(source => sourceIds(node).includes(Number(source.id)));
      button.querySelector('.map-node-status').textContent=source?`${source.domain} · ${links(node).map(id=>'claim #'+id).join(', ')}`:`${node.status}${node.confidence?' · '+node.confidence+' prior confidence':''}`;
      if(['dream_claim','question'].includes(node.type))button.querySelector('.map-node-status').textContent+=(node.type==='question'?' · untested':'')+' · priority '+priority;
      if(node.type==='root'&&node.working)button.querySelector('.map-node-status').textContent=({planning:'Planning concepts',evidence:'Researching',discovery:'Discovering concepts',verification:'Checking findings'}[node.activity]||'Working');
      if(['dream_claim','question'].includes(node.type)){button.querySelector('.map-node-status').textContent=(node.working?({planning:'Planning',evidence:'Researching',discovery:'Exploring',verification:'Checking'}[node.activity]||'Working')+' · ':node.verdict?node.verdict+' · ':'')+(node.source_ids.length?node.source_ids.length+' sources · ':'')+'priority '+priority+(Number(node.speculative)?' · untested':'');}
      if (node.type==='dream_claim' && !card.querySelector('[data-map-claim]')) {
        const label=el('label',undefined,'map-node-check'),input=el('input');input.type='checkbox';input.dataset.mapClaim=node.id;
        label.append(input,document.createTextNode(`Keep claim #${node.id}`));card.append(label);
      }
      if (source && node.type==='source' && !card.querySelector('[data-map-source]')) {
        const label=el('label',undefined,'map-node-check'),input=el('input');input.type='checkbox';input.dataset.mapSource=source.id;input.checked=selectedSources.has(Number(source.id));
        label.append(input,document.createTextNode(`Select source #${source.id}`));card.append(label);
      }
    }
    setScale(scale);syncSelection();updateInspector();
    const cards=new Map([...world.querySelectorAll('[data-map-node]')].map(card=>[card.dataset.mapNode,card]));
    for(const node of visible){node.w=cards.get(node.id).offsetWidth;node.h=cards.get(node.id).offsetHeight;}
    const linksToDraw=graph.edges.filter(e=>visited.has(e.from)&&visited.has(e.to));
    const nextLayout=JSON.stringify([visible.map(n=>[n.id,n.w,n.h]),linksToDraw,viewport.clientWidth,viewport.clientHeight]);
    if(nextLayout!==layoutSignature){layoutSignature=nextLayout;const packed=Engine.layout(visible,linksToDraw,Math.max(280,viewport.clientWidth-12),Math.max(260,viewport.clientHeight-(reviewing()?88:12)),positions);positions=packed.positions;width=packed.width;height=packed.height;}
    for(const node of visible){const p=positions.get(node.id);if(!p)continue;Object.assign(node,p);const card=cards.get(node.id);card.style.left=p.x+'px';card.style.top=p.y+'px';}
    visible.sort((a,b)=>a.y-b.y||a.x-b.x);world.style.width=width+'px';world.style.height=height+'px';
    const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('width',width);svg.setAttribute('height',height);svg.setAttribute('aria-hidden','true');
    for(const edge of linksToDraw){const a=positions.get(edge.from),b=positions.get(edge.to);if(!a||!b)continue;const ax=a.x+a.w/2,ay=a.y+a.h/2,bx=b.x+b.w/2,by=b.y+b.h/2,dx=bx-ax,dy=by-ay;
      const start=Math.min(Math.abs((a.w/2)/(dx||.001)),Math.abs((a.h/2)/(dy||.001))),end=Math.min(Math.abs((b.w/2)/(dx||.001)),Math.abs((b.h/2)/(dy||.001)));
      const line=document.createElementNS(svg.namespaceURI,'path');line.setAttribute('d',`M${ax+dx*start},${ay+dy*start} Q${(ax+bx)/2-dy*.04},${(ay+by)/2+dx*.04} ${bx-dx*end},${by-dy*end}`);line.style.strokeWidth=String(Math.max(1,Math.min(3,edge.weight/30)));line.setAttribute('class','map-edge'+(active.has(edge.from)&&active.has(edge.to)?' map-edge-active':'')+(edge.kind==='shared_source'?' map-edge-shared':''));svg.append(line);}
    world.prepend(svg);setScale(scale);
  }
  function updateInspector() {
    const node=nodes.find(node=>node.id===selected)||nodes[0];
    const stamp=JSON.stringify([node,snapshot.sources,snapshot.ledger,selectedSources.size,snapshot.run.status,snapshot.run.phase,snapshot.run.busy_since,snapshot.budget,snapshot.requests]);
    if (inspector.dataset.stamp!==stamp) {
      const title=el('h3',node.title),detail=el('p',`${node.type.replaceAll('_',' ')} · ${node.status}`,'map-inspector-status');
      inspector.replaceChildren(title,detail,el('p',node.dead_end_reason||node.content||'No details saved yet.','map-inspector-text'));
      if(['dream_claim','question'].includes(node.type))inspector.append(el('p',`Priority ${node.path_weight||50} · ${node.model_tier||'primary'} model tier. ${node.weight_reason||'Priority is separate from evidence strength.'}${Number(node.speculative)?' Untested idea — may be wrong.':''}`));
      const stages=snapshot.nodes.filter(n=>['plan_step','search','dead_end','synthesis'].includes(n.type)&&Engine.owner(snapshot,n.id)===node.id);if(stages.length){const history=el('details');history.append(el('summary','Planning, evidence and findings history'));for(const stage of stages)history.append(el('h4',stage.title+' · '+stage.status),el('p',stage.content));inspector.append(history);}
      const pathRequests=snapshot.requests.filter(q=>Number(q.path_node_id)===Number(node.id));if(pathRequests.length){const input=pathRequests.reduce((sum,q)=>sum+Number(q.token_input||0),0),output=pathRequests.reduce((sum,q)=>sum+Number(q.token_output||0),0),cost=pathRequests.reduce((sum,q)=>sum+Number(q.estimated_usd||0),0);inspector.append(el('p',`${input.toLocaleString()} input / ${output.toLocaleString()} output tokens · $${cost.toFixed(6)} USD known estimate; unknown calls remain in the usage ledger.`));}
      const linked=links(node);if(linked.length)inspector.append(el('p',`Linked claims: ${linked.map(id=>'#'+id).join(', ')}`));
      for(const source of snapshot.sources.filter(source=>sourceIds(node).includes(Number(source.id))&&safe(source.url))){
        const card=el('article',undefined,'research-source'),link=el('a',source.url);link.href=source.url;link.target='_blank';link.rel='noopener noreferrer';
        for(const passage of snapshot.ledger?.passages||[])if(Number(passage.source_id)===Number(source.id))card.append(el('h5',`Passage #${passage.id} · ${passage.fetch_scope}`),el('blockquote',passage.exact_text),el('small',passage.locator||'No locator supplied'));
        const choice=el('label',undefined,'map-source-choice'),checkbox=el('input');checkbox.type='checkbox';checkbox.dataset.mapSource=source.id;checkbox.checked=selectedSources.has(Number(source.id));choice.append(checkbox,document.createTextNode('Use source #'+source.id+' for Learn'));card.append(choice);
        card.append(el('h4',source.title),link,el('p',source.extract),el('small',`${source.domain} · captured ${new Date(source.retrieved_at*1000).toISOString()}`));inspector.append(card);
      }
      inspector.dataset.stamp=stamp;
    }
    const mapLedger=map.querySelector('[data-map-ledger]');mapLedger.replaceChildren();
    for(const saved of snapshot.nodes.filter(n=>n.type==='phase'&&['Verification','Repair'].includes(n.title))){const record=data(saved);const block=el('details');block.append(el('summary',saved.title+' findings'));for(const defect of record.defects||[])block.append(el('p',defect.severity+' · '+defect.kind+' · '+defect.description));for(const check of record.calculations||[])block.append(el('p',`Arithmetic ${check.operation}: ${check.arithmetic_correct?'passed':'failed'} on supplied operands; source accuracy remains unverified.`));if(saved.status==='complete'&&!(record.defects||[]).length)block.append(el('p','No defects reported by this grouped model check. This is not independent source verification.'));mapLedger.append(block);}
    if(snapshot.run.phase==='verification_review'&&snapshot.run.status==='running'){for(const [action,label] of [['research_finish','Finish and freeze evidence'],['research_repair','Review high-impact gaps once']]){const button=el('button',label,'btn btn-primary');button.type='button';const original=root.querySelector(`[data-research-action=${action}] button`);button.disabled=original.disabled;button.addEventListener('click',()=>original.form.requestSubmit());mapLedger.append(button);}}
    const rank=map.querySelector('[data-map-rank]'),pathNode=['dream_claim','question'].includes(node.type);
    rank.hidden=!pathNode||Number(node.selected??1)!==1||snapshot.run.status!=='running'||!!snapshot.run.busy_since||!Number(snapshot.run.flow_version);
    const rankStamp=JSON.stringify([selected,node.path_weight,node.model_tier]);if(rank.dataset.stamp!==rankStamp&&!rank.contains(document.activeElement)){rank.querySelector('[name=node_id]').value=node.id;rank.querySelector('[name=path_weight]').value=node.path_weight||50;rank.querySelector('[name=model_tier]').value=node.model_tier||'primary';rank.dataset.stamp=rankStamp;}
    const linked=links(node),busy=snapshot.run.busy_since!==null||snapshot.requests.some(request=>request.status==='pending');
    const capacity=root.dataset.connected!=='0'&&(!snapshot.budget||snapshot.budget.remaining>0);
    const focusable=['dream_claim','question','dead_end','synthesis'].includes(node.type) && linked.length && linked.every(id=>nodes.some(claim=>claim.id===String(id)&&Number(claim.selected??1)===1)) && (node.type!=='synthesis'||node.title.toLowerCase().startsWith('open'));
    const canSearch=focusable&&capacity&&!busy&&snapshot.run.status==='running'&&snapshot.run.phase==='evidence';
    map.querySelector('[data-map-search]').disabled=!canSearch;map.querySelector('[data-map-focus]').hidden=!canSearch;
    map.querySelector('[name=focus_node_id]').value=canSearch?node.id:'';
    const learnIds=selectedSources.size?[...selectedSources]:sourceIds(node);
    const inputs=map.querySelector('[data-map-source-inputs]');inputs.replaceChildren();
    for(const id of learnIds){const input=el('input');input.type='hidden';input.name='source_ids[]';input.value=id;inputs.append(input);}
    map.querySelector('[data-map-learn]').hidden=!learnIds.length;
    const learn=map.querySelector('[data-map-learn-button]');learn.disabled=!learnIds.length||busy||!capacity||['pending','running'].includes(snapshot.run.status)||(Number(snapshot.run.rigour_version)===1&&!Number(snapshot.run.evidence_frozen));
    learn.textContent=selectedSources.size?`Learn from ${selectedSources.size} selected source${selectedSources.size===1?'':'s'}`:'Learn from this source';
    const toggle=map.querySelector('[data-map-toggle-branch]');toggle.disabled=!nodes.some(child=>child.parent_id===node.id);toggle.hidden=toggle.disabled;toggle.textContent=collapsed.has(node.id)?'Expand branch':'Collapse branch';
    map.querySelector('[data-map-copy]').hidden=!snapshot.sources.some(source=>sourceIds(node).includes(Number(source.id))&&safe(source.url));
  }
  function inspect(id) {
    controls(false);selected=id;details(true);draw();
    const card=[...world.querySelectorAll('[data-map-node]')].find(card=>card.dataset.mapNode===id);
    if(card&&!map.hidden)card.scrollIntoView({block:'nearest',inline:'nearest'});
  }
  map.addEventListener('click',async event=>{
    const command=event.target.closest('[data-map-command]');
    if(command){const key=command.dataset.mapCommand;if(key==='fit'){manualViewport=false;fit();}if(key==='in'){manualViewport=true;setScale(scale*1.2);draw();}if(key==='out'){manualViewport=true;setScale(scale/1.2);draw();}if(key==='collapse'){nodes.filter(node=>node.id!=='root').forEach(node=>collapsed.add(node.id));draw();fit();}if(key==='expand'){collapsed.clear();draw();fit();}return;}
    const chip=event.target.closest('[data-map-filter]');if(chip){filter=chip.dataset.mapFilter;controls(false);details(false);map.querySelectorAll('[data-map-filter]').forEach(button=>button.setAttribute('aria-pressed',String(button===chip)));draw();fit();return;}
    const nodeButton=event.target.closest('.map-node-button');if(nodeButton){inspect(nodeButton.closest('[data-map-node]').dataset.mapNode);return;}
    if(event.target.closest('[data-map-toggle-branch]')){collapsed.has(selected)?collapsed.delete(selected):collapsed.add(selected);draw();return;}
    const selection=event.target.closest('[data-map-select]');if(selection){claimInputs().forEach(input=>input.checked=selection.dataset.mapSelect==='all');claimInputs()[0]?.dispatchEvent(new Event('change',{bubbles:true}));syncSelection();return;}
    if(event.target.closest('[data-map-continue]'))root.querySelector('[data-research-action=research_continue]').requestSubmit();
    const outcome=event.target.closest('[data-map-jump]');if(outcome){collapsed.clear();filter='all';inspect(Engine.owner(snapshot,outcome.dataset.mapJump));world.querySelector(`[data-map-node="${outcome.dataset.mapJump}"] button`)?.focus();}
    if(event.target.closest('[data-map-copy]')){
      const node=nodes.find(node=>node.id===selected),source=snapshot.sources.find(source=>sourceIds(node).includes(Number(source.id))&&safe(source.url));
      try {await navigator.clipboard.writeText(source.url);map.querySelector('[data-map-announcement]').textContent='Source link copied.';}catch(_){map.querySelector('[data-map-announcement]').textContent='Copy the source URL from the inspector link.';}
    }
  });
  map.addEventListener('change',event=>{
    const input=event.target;if(input.dataset.mapClaim){const original=claimInputs().find(item=>item.value===input.dataset.mapClaim);if(original){original.checked=input.checked;original.dispatchEvent(new Event('change',{bubbles:true}));}}
    if(input.dataset.mapSource){const id=Number(input.dataset.mapSource),inside=side.contains(input);input.checked?selectedSources.add(id):selectedSources.delete(id);updateInspector();if(inside)side.querySelector(`[data-map-source="${id}"]`)?.focus({preventScroll:true});}
  });
  root.addEventListener('change',event=>{if(event.target.matches('[data-dream-cards] input'))syncSelection();});
  world.addEventListener('keydown',event=>{
    const button=event.target.closest('.map-node-button');if(!button)return;
    const node=visible.find(node=>node.id===button.closest('[data-map-node]').dataset.mapNode);let next;
    if(event.key==='ArrowRight'){if(collapsed.has(node.id)){collapsed.delete(node.id);draw();}next=visible.find(child=>child.parent_id===node.id);}
    if(event.key==='ArrowLeft')next=visible.find(parent=>parent.id===node.parent_id);
    if(event.key==='ArrowDown')next=visible[visible.indexOf(node)+1];if(event.key==='ArrowUp')next=visible[visible.indexOf(node)-1];
    if(next){event.preventDefault();inspect(next.id);world.querySelector(`[data-map-node="${next.id}"] button`)?.focus();}
  });
  root.addEventListener('research:snapshot',event=>{
    snapshot=event.detail;normalize();screenControls();enqueueIdeas();
    const next=JSON.stringify([snapshot.nodes,snapshot.sources,snapshot.ledger,snapshot.run.status,snapshot.requests.map(q=>[q.id,q.status,q.kind,q.claim_id,q.path_node_id,q.node_id])]);
    if(signature!==next){signature=next;draw();if(!manualViewport)requestAnimationFrame(fit);if(count!==nodes.length){count=nodes.length;map.querySelector('[data-map-announcement]').textContent=`${snapshot.nodes.length} saved nodes · ${snapshot.sources.length} citation sources. ${snapshot.run.phase==='evidence'?'Choose the direction for your next search.':''}`;}}
    else {syncSelection();updateInspector();}
    map.querySelector('[data-map-evidence-help]').hidden=snapshot.run.status!=='running'||snapshot.run.phase!=='evidence';
    const strip=map.querySelector('[data-map-outcomes]');strip.replaceChildren();
    for(const verdict of ['supported','weakened','contradicted','open']){
      const latest=new Map();for(const node of nodes.filter(n=>n.type==='synthesis'&&n.parent_id&&nodes.some(c=>c.id===n.parent_id&&c.type==='dream_claim')))latest.set(node.parent_id,node);
      const outcomes=[...latest.values()].filter(node=>data(node).verdict===verdict||node.title.toLowerCase().startsWith(verdict+' ·'));
      const button=el('button',`${verdict}: ${outcomes.length}`,'map-outcome');button.type='button';button.disabled=!outcomes.length;if(outcomes.length)button.dataset.mapJump=outcomes[0].id;strip.append(button);
    }
  });
  root.addEventListener('research:controls',()=>{if(snapshot){updateInspector();screenControls();}});
  root.addEventListener('research:budget',event=>{if(snapshot){snapshot.budget=event.detail;updateInspector();screenControls();}});
  // Initialize after the status request; live processes are never assumed to survive navigation.
  new ResizeObserver(()=>{if(!map.hidden&&snapshot){layoutSignature='';draw();if(!manualViewport)fit();}}).observe(viewport);
  showView(view);
})();
