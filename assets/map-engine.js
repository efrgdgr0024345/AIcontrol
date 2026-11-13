/* Pure, display-only map projection and stable structural layout; embedded by build-ui.php. */
(function (scope, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else scope.AIcontrolMap = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';
  const isObject = value => value !== null && typeof value === 'object' && !Array.isArray(value);
  const key = value => value === null || value === undefined || String(value).trim() === '' ? null : String(value);
  const list = value => Array.isArray(value) ? value : [];
  const object = value => {
    if (isObject(value)) return value;
    try { const parsed = JSON.parse(value || '{}'); return isObject(parsed) ? parsed : {}; } catch (_) { return {}; }
  };
  const data = node => object(node && node.data_json);
  const compare = (a, b) => {
    const aa = String(a), bb = String(b), an = Number(aa), bn = Number(bb);
    return Number.isFinite(an) && Number.isFinite(bn) && an !== bn ? an - bn : aa < bb ? -1 : aa > bb ? 1 : 0;
  };
  // Sort before de-duplicating so projection never relies on transport ordering.
  const records = value => {
    const sorted = list(value).filter(row => isObject(row) && key(row.id) !== null)
      .slice().sort((a, b) => compare(a.id, b.id) || compare(JSON.stringify(a), JSON.stringify(b)));
    return sorted.filter((row, i) => i === 0 || key(row.id) !== key(sorted[i - 1].id));
  };
  const ids = value => [...new Set(list(value).map(key).filter(id => id !== null))].sort(compare);
  const sourceId = value => /^\d+$/.test(String(value)) && Number.isSafeInteger(Number(value)) ? Number(value) : value;
  const ideaTypes = ['dream_claim','question','model_idea','association','counter','edge_case','reframing'];
  const isConcept = node => node && ideaTypes.includes(node.type);
  function index(snapshot) {
    const input = isObject(snapshot) ? snapshot : {}, ledger = object(input.ledger);
    const nodes = records(input.nodes), byId = new Map(nodes.map(node => [key(node.id), node]));
    const primarySources = records(input.sources), primaryIds = new Set(primarySources.map(source => key(source.id)));
    const sources = records([...primarySources, ...records(ledger.sources).filter(source => !primaryIds.has(key(source.id)))]);
    return {run: object(input.run), nodes, byId, sources, passages: records(ledger.passages), edges: records(ledger.edges), requests: list(input.requests).filter(isObject)};
  }
  function resolveOwner(byId, id, seen = new Set()) {
    const node = byId.get(key(id));
    if (!node || seen.has(key(node.id))) return 'root';
    seen.add(key(node.id));
    if (isConcept(node)) return key(node.id);
    const parent = resolveOwner(byId, node.parent_id, seen);
    if (parent !== 'root') return parent;
    // Older records sometimes kept the path only in metadata, not parent_id.
    const meta = data(node);
    const candidates = [node.path_node_id, node.claim_id, meta.path_node_id, meta.claim_id, ...ids(node.linked_claim_ids), ...ids(meta.claim_ids)];
    for (const candidate of candidates) {
      const found = resolveOwner(byId, candidate, seen);
      if (found !== 'root') return found;
    }
    return 'root';
  }
  function owner(snapshot, id) { return resolveOwner(index(snapshot).byId, id); }
  function project(snapshot, requestedFilter = 'all') {
    const aliases = {concepts: 'all', claims: 'dream_claim', questions: 'question', sources: 'source', passages: 'passage', edges: 'edge', dead_ends: 'dead_end'};
    const supported = ['all', 'dream_claim', 'question', 'source', 'passage', 'edge', 'dead_end', 'open'];
    const named = aliases[requestedFilter] || requestedFilter;
    const filter = supported.includes(named) ? named : 'all';
    const state = index(snapshot), {run, nodes: full, byId, sources, passages} = state;
    const nearest = id => resolveOwner(byId, id);
    const review = run.phase === 'dream_review';
    const claims = full.filter(node => node.type === 'dream_claim' && (Number(run.flow_version)>=2 || review || ![0, '0', false].includes(node.selected)));
    const selectedClaims = new Set(claims.map(node => key(node.id)));
    const allConcepts = new Map(full.filter(isConcept).map(node => [key(node.id), node]));
    const edgeOwners = edge => allConcepts.has(key(edge.claim_id)) ? [key(edge.claim_id)] : [];
    function claimAncestor(id, seen = new Set()) {
      const node = byId.get(key(id));
      if (!node || seen.has(key(node.id))) return null;
      seen.add(key(node.id));
      if (node.type === 'dream_claim') return key(node.id);
      const parent = claimAncestor(node.parent_id, seen);
      if (parent) return parent;
      const meta = data(node);
      for (const candidate of [node.claim_id, meta.claim_id, ...ids(node.linked_claim_ids), ...ids(meta.claim_ids)]) {
        const found = claimAncestor(candidate, seen);
        if (found) return found;
      }
      return null;
    }
    const questions = full.filter(node => node.type !== 'dream_claim' && isConcept(node) && (Number(run.flow_version)>=2 || selectedClaims.has(claimAncestor(node.id))));
    const visibleIds = new Set([...claims, ...questions].map(node => key(node.id)));
    function parentFor(node) {
      const parent = nearest(node.parent_id);
      if (visibleIds.has(parent) && parent !== key(node.id)) return parent;
      const ancestor = claimAncestor(node.id);
      return ancestor && ancestor !== key(node.id) && visibleIds.has(ancestor) ? ancestor : 'root';
    }
    const root = {id: 'root', parent_id: null, type: 'root', title: run.name || 'Research', status: run.status || 'pending', source_ids: [], path_weight: 50, display_only: true};
    const concepts = [...claims, ...questions].map(node => ({...node, id: key(node.id), parent_id: parentFor(node), source_ids: [], activity: null, working: false}));
    const conceptsById = new Map(concepts.map(node => [node.id, node]));
    // A broken question cycle must not leave a disconnected/cyclic display graph.
    for (const node of concepts) {
      const seen = new Set([node.id]); let parent = node.parent_id;
      while (conceptsById.has(parent)) {
        if (seen.has(parent)) { node.parent_id = claimAncestor(node.id) || 'root'; if (node.parent_id === node.id) node.parent_id = 'root'; break; }
        seen.add(parent); parent = conceptsById.get(parent).parent_id;
      }
    }
    const owned = candidates => ids(candidates).map(nearest).filter(id => id !== 'root');
    const unique = values => [...new Set(values)].sort(compare);
    const sourceOwners = new Map();
    for (const source of sources) {
      const meta = object(source.meta_json);
      const linked = owned([source.node_id, meta.path_node_id, source.claim_id, meta.claim_id, ...list(meta.claim_ids), ...list(source.linked_claim_ids)]);
      for (const edge of state.edges) if (key(edge.source_id) === key(source.id)) linked.push(...edgeOwners(edge));
      sourceOwners.set(key(source.id), unique(linked));
    }
    const sourceById = new Map(sources.map(source => [key(source.id), source]));
    for (const node of concepts) node.source_ids = sources.filter(source => sourceOwners.get(key(source.id)).includes(node.id)).map(source => sourceId(source.id));
    const pending = run.status === 'running' ? state.requests.filter(request => request.status === 'pending').slice().sort((a, b) => compare(a.id || '', b.id || '')) : [];
    for (const request of pending) {
      const targetId = [request.path_node_id, request.claim_id, request.node_id].map(nearest).find(id => id !== 'root') || 'root';
      const targets = targetId === 'root' ? concepts.filter(node => node.type === 'dream_claim' && ![0, '0', false].includes(node.selected)) : [conceptsById.get(targetId)].filter(Boolean);
      const activity = ['dream', 'exploration','model_explore'].includes(request.kind) ? 'discovery' : request.kind === 'plan' ? 'planning' : request.kind === 'evidence' ? 'evidence' : 'verification';
      for (const node of targets) Object.assign(node, {working: true, activity, activity_text: request.activity || request.worker_role || request.kind});
      if (targetId === 'root' && !targets.length) Object.assign(root, {working: true, activity, activity_text: request.activity || request.worker_role || request.kind});
    }
    const verdicts = new Map();
    for (const node of full.filter(node => node.type === 'synthesis')) verdicts.set(nearest(node.id), node);
    for (const node of concepts) {
      const verdict = verdicts.get(node.id);
      if (verdict) node.verdict = data(verdict).verdict || String(verdict.title || '').split(' · ')[0].toLowerCase();
    }
    const details = [];
    const permitted = owners => !owners.length || owners.some(id => visibleIds.has(id));
    function detail(row, owners) {
      const visibleOwners = unique(owners.filter(id => visibleIds.has(id)));
      if (!permitted(owners)) return;
      const actual = nearest(row.parent_id);
      details.push({...row, parent_id: visibleOwners.includes(actual) ? actual : visibleOwners[0] || 'root', owner_ids: visibleOwners, linked_claim_ids: unique(visibleOwners.map(id => claimAncestor(id)).filter(Boolean)).map(sourceId)});
    }
    if (filter === 'source') {
      const used = new Set(visibleIds);
      for (const source of sources) {
        const persisted = byId.get(key(source.node_id));
        const persistedId = persisted && persisted.type === 'source' && !used.has(key(persisted.id)) ? key(persisted.id) : null;
        const id = persistedId || 'source-' + source.id; used.add(id);
        detail({...persisted, ...source, id, parent_id: source.node_id, type: 'source', title: source.title || source.url || 'Untitled source', content: source.extract || persisted && persisted.content || '', status: 'complete', source_ids: [sourceId(source.id)], display_only: !persistedId}, sourceOwners.get(key(source.id)));
      }
    }
    if (filter === 'passage') for (const passage of passages) {
      const linkedEdges = state.edges.filter(edge => key(edge.passage_id) === key(passage.id));
      const linked = unique(linkedEdges.flatMap(edgeOwners));
      const owners = linked.length ? linked : sourceOwners.get(key(passage.source_id)) || [];
      const source = sourceById.get(key(passage.source_id));
      detail({...passage, id: 'passage-' + passage.id, passage_id: passage.id, parent_id: source && source.node_id, type: 'passage', title: passage.exact_text || 'Stored passage with no text', content: passage.exact_text || '', status: 'complete', source_ids: source ? [sourceId(source.id)] : [], display_only: true}, owners);
    }
    if (filter === 'edge') for (const edge of state.edges) {
      // Do not turn an invalid claim reference into a relation on a different claim.
      const owners = edgeOwners(edge);
      const passage = passages.find(row => key(row.id) === key(edge.passage_id));
      const source = sourceById.get(key(edge.source_id)) || sourceById.get(key(passage && passage.source_id));
      detail({...edge, id: 'edge-' + edge.id, edge_id: edge.id, parent_id: edge.claim_id, type: 'edge', title: (edge.relation || 'Unspecified relation') + ' · ' + (edge.strength || 'unrated'), content: edge.rationale || '', status: 'complete', source_ids: source ? [sourceId(source.id)] : [], display_only: true}, owners);
    }
    if (['dead_end', 'open'].includes(filter)) for (const node of full) {
      const savedVerdict = data(node).verdict;
      const open = savedVerdict ? savedVerdict === 'open' : /^open(?:\s|$)/i.test(node.title || '');
      const latest = nearest(node.id) === 'root' || verdicts.get(nearest(node.id)) === node;
      if (!(filter === 'dead_end' ? node.type === 'dead_end' : node.type === 'synthesis' && latest && open)) continue;
      const sourceIds = unique([...list(node.source_ids), ...list(data(node).source_ids)].map(key).filter(id => sourceById.has(id))).map(sourceId);
      detail({...node, id: key(node.id), type: node.type, source_ids: sourceIds, display_only: false}, owned([node.id]));
    }
    let selectedConcepts = concepts;
    if (filter === 'dream_claim') selectedConcepts = concepts.filter(node => node.type === 'dream_claim');
    if (filter === 'question' || !['all', 'dream_claim'].includes(filter)) {
      const keep = new Set(filter === 'question' ? questions.map(node => key(node.id)) : details.flatMap(node => node.owner_ids));
      for (const id of [...keep]) { let node = conceptsById.get(id); const seen = new Set(); while (node && !seen.has(node.id)) { seen.add(node.id); keep.add(node.id); node = conceptsById.get(node.parent_id); } }
      selectedConcepts = concepts.filter(node => keep.has(node.id));
    }
    // Parent before child, siblings by durable ID, independent of snapshot row order.
    function ordered(rows) {
      const result = [], pendingRows = rows.slice().sort((a, b) => compare(a.id, b.id)), added = new Set(['root']);
      while (pendingRows.length) {
        const at = pendingRows.findIndex(node => added.has(node.parent_id));
        const node = pendingRows.splice(at < 0 ? 0 : at, 1)[0];
        if (at < 0) node.parent_id = 'root';
        result.push(node); added.add(node.id);
      }
      return result;
    }
    const projected = [root, ...ordered(selectedConcepts), ...details.sort((a, b) => compare(a.id, b.id))].map(node=>({...node,layer:Math.max(0,Math.min(2,Number(node.layer)||0)),origin:node.origin||(['source','passage','edge'].includes(node.type)?'web_evidence':null)}));
    const projectedIndex=new Map(projected.map(n=>[n.id,n]));for(const node of projected){node.knowledge_line_id=node.type==='root'?'root':familyFor(node,projectedIndex);node.weight=Math.max(.1,Math.min(4,Number(node.weight)||1));}
    const projectedIds = new Set(projected.map(node => node.id));
    const edges = projected.filter(node => node.parent_id && projectedIds.has(node.parent_id)).map(node => ({from: node.parent_id, to: node.id, kind: 'concept', weight: Number(node.path_weight) || 50}));
    for (const source of sources) {
      const related = sourceOwners.get(key(source.id)).filter(id => projectedIds.has(id) && allConcepts.get(id)?.type === 'dream_claim');
      for (let a = 0; a < related.length; a++) for (let b = a + 1; b < related.length; b++) edges.push({from: related[a], to: related[b], kind: 'shared_source', source_id: sourceId(source.id), weight: 25});
    }
    edges.sort((a, b) => compare(a.from, b.from) || compare(a.to, b.to) || compare(a.kind, b.kind) || compare(a.source_id || '', b.source_id || ''));
    const detailCount = filter === 'question' ? selectedConcepts.filter(node => node.type !== 'dream_claim').length : filter === 'dream_claim' ? selectedConcepts.length : filter === 'all' ? selectedConcepts.length : details.length;
    const reasons = {all: review ? 'No concepts have been proposed for review yet.' : 'No selected concepts are available for this run yet.', dream_claim: 'No selected claims are available for this run yet.', question: 'No questions have grown from the selected concepts yet.', source: 'No sources are stored for the selected concepts yet.', passage: 'No passages are stored for the selected concepts yet.', edge: 'No evidence relations are stored for the selected concepts yet.', dead_end: 'No dead ends are stored for the selected concepts yet.', open: 'No open verdicts are stored for the selected concepts yet.'};
    return {nodes: projected, edges, filter, empty: detailCount === 0, empty_reason: detailCount === 0 ? reasons[filter] : '', detail_count: detailCount, concept_count: selectedConcepts.length};
  }
  function layout(nodes, edges, width, height, previous = new Map(), options={}) {
    if(options.constellations)return constellationLayout(nodes,edges,width,height,previous);
    const viewportW = Math.max(1, Number(width) || 1), viewportH = Math.max(1, Number(height) || 1), margin = 24, gapX = 24, gapY = 28;
    const rows = records(nodes).map(node => ({...node, id: key(node.id), w: Math.max(1, Number(node.w) || 194), h: Math.max(1, Number(node.h) || 84)}));
    if (!rows.length) return {positions: new Map(), width: viewportW, height: viewportH, reused: 0, new_count: 0};
    const memory = previous instanceof Map ? previous : new Map();
    const byId = new Map(rows.map(node => [node.id, node])), parents = new Map();
    for (const node of rows) if (byId.has(key(node.parent_id)) && key(node.parent_id) !== node.id) parents.set(node.id, key(node.parent_id));
    for (const edge of list(edges)) if (edge && edge.kind !== 'shared_source' && byId.has(key(edge.from)) && byId.has(key(edge.to)) && key(edge.from) !== key(edge.to) && !parents.has(key(edge.to))) parents.set(key(edge.to), key(edge.from));
    const root = rows.find(node => node.id === 'root' || node.type === 'root') || rows.find(node => !parents.has(node.id)) || rows[0];
    parents.delete(root.id);
    const levels = new Map([[root.id, 0]]);
    function level(id, seen = new Set()) {
      if (levels.has(id)) return levels.get(id);
      if (seen.has(id)) return 1;
      seen.add(id); const parent = parents.get(id);
      const depth = parent ? level(parent, seen) + 1 : 1;
      levels.set(id, depth); return depth;
    }
    rows.forEach(node => level(node.id));
    const maxW = Math.max(...rows.map(node => node.w));
    const initialWidth = Math.max(viewportW, maxW + margin * 2);
    const columns = Math.max(1, Math.floor((initialWidth - margin * 2 + gapX) / (maxW + gapX)));
    const positions = new Map(), obstacles = [], fresh = [];
    for (const [id, old] of memory) if (old && [old.x, old.y, old.w, old.h].every(Number.isFinite)) obstacles.push({...old, id: String(id)});
    for (const node of rows) {
      const old = memory.get(node.id);
      if (old && Number.isFinite(old.x) && Number.isFinite(old.y)) {
        const position = {x: old.x, y: old.y, w: node.w, h: node.h}; positions.set(node.id, position);
        const at = obstacles.findIndex(item => item.id === node.id); if (at >= 0) obstacles[at] = {...position, id: node.id}; else obstacles.push({...position, id: node.id});
      } else fresh.push(node);
    }
    const reused = positions.size;
    if (!reused) {
      let y = margin;
      const depths = [...new Set(rows.map(node => levels.get(node.id)))].sort((a, b) => a - b);
      for (const depth of depths) {
        const layer = rows.filter(node => levels.get(node.id) === depth).sort((a, b) => {
          const ap = positions.get(parents.get(a.id)), bp = positions.get(parents.get(b.id));
          return (ap && bp ? ap.x - bp.x : 0) || compare(parents.get(a.id) || '', parents.get(b.id) || '') || compare(a.id, b.id);
        });
        for (let start = 0; start < layer.length; start += columns) {
          const band = layer.slice(start, start + columns), bandWidth = band.length * maxW + (band.length - 1) * gapX;
          const left = (initialWidth - bandWidth) / 2, bandHeight = Math.max(...band.map(node => node.h));
          band.forEach((node, i) => positions.set(node.id, {x: left + i * (maxW + gapX) + (maxW - node.w) / 2, y, w: node.w, h: node.h}));
          y += bandHeight + gapY;
        }
      }
    } else {
      // Only new IDs are placed. Hidden remembered cards also reserve their space.
      const collides = rect => obstacles.some(other => rect.x < other.x + other.w + gapX && rect.x + rect.w + gapX > other.x && rect.y < other.y + other.h + gapY && rect.y + rect.h + gapY > other.y);
      const usableWidth = Math.max(initialWidth, ...obstacles.map(node => node.x + node.w + margin));
      fresh.sort((a, b) => levels.get(a.id) - levels.get(b.id) || compare(parents.get(a.id) || '', parents.get(b.id) || '') || compare(a.id, b.id));
      for (const node of fresh) {
        const parent = positions.get(parents.get(node.id)) || memory.get(parents.get(node.id)) || positions.get(root.id);
        const desiredX = Math.max(margin, Math.min(usableWidth - margin - node.w, parent ? parent.x + (parent.w - node.w) / 2 : (usableWidth - node.w) / 2));
        const desiredY = parent ? parent.y + parent.h + gapY : margin;
        let chosen = null;
        const xSlots = [desiredX];
        for (let x = margin; x + node.w <= usableWidth - margin + 0.01; x += node.w + gapX) xSlots.push(x);
        const orderedX = [...new Set(xSlots)].sort((a, b) => Math.abs(a - desiredX) - Math.abs(b - desiredX) || a - b);
        // A finite scan always ends below all previous cards, without moving them.
        const bottom = Math.max(desiredY, ...obstacles.map(other => other.y + other.h + gapY));
        for (let y = desiredY; y <= bottom + node.h + gapY && !chosen; y += node.h + gapY) {
          for (const x of orderedX) { const rect = {x, y, w: node.w, h: node.h}; if (!collides(rect)) { chosen = rect; break; } }
        }
        if (!chosen) chosen = {x: desiredX, y: bottom, w: node.w, h: node.h};
        positions.set(node.id, chosen); obstacles.push({...chosen, id: node.id});
      }
    }
    return {positions, width: Math.max(viewportW, ...[...positions.values()].map(node => node.x + node.w + margin)), height: Math.max(viewportH, ...[...positions.values()].map(node => node.y + node.h + margin)), reused, new_count: fresh.length};
  }
  function familyFor(node,byId,seen=new Set()) {
    if(!node||seen.has(String(node.id)))return 'root';
    if(typeof node.knowledge_line_id==='string'&&node.knowledge_line_id.trim())return node.knowledge_line_id;
    seen.add(String(node.id));const parent=byId.get(String(node.parent_id));
    return !parent||parent.type==='root'?'line-'+node.id:familyFor(parent,byId,seen);
  }
  const hash=value=>{let n=2166136261;for(const c of String(value)){n^=c.charCodeAt(0);n=Math.imul(n,16777619);}return n>>>0;};
  function constellationLayout(nodes,edges,width,height,previous=new Map()) {
    const rows=records(nodes).map(n=>({...n,id:String(n.id),w:Number(n.w)||194,h:Number(n.h)||100})),byId=new Map(rows.map(n=>[n.id,n]));
    const root=rows.find(n=>n.id==='root'||n.type==='root')||rows[0];if(!root)return {positions:new Map(),width,height,reused:0,new_count:0};
    const memory=previous instanceof Map?previous:new Map(),positions=new Map(),buckets=new Map(),cell=280;
    const keys=p=>{const result=[];for(let x=Math.floor((p.x-16)/cell);x<=Math.floor((p.x+p.w+16)/cell);x++)for(let y=Math.floor((p.y-16)/cell);y<=Math.floor((p.y+p.h+16)/cell);y++)result.push(x+':'+y);return result;};
    const reserve=p=>{for(const k of keys(p)){if(!buckets.has(k))buckets.set(k,[]);buckets.get(k).push(p);}};
    const collides=p=>{const seen=new Set();for(const k of keys(p))for(const q of buckets.get(k)||[])if(!seen.has(q)){seen.add(q);if(p.x<q.x+q.w+22&&p.x+p.w+22>q.x&&p.y<q.y+q.h+22&&p.y+p.h+22>q.y)return true;}return false;};
    for(const [id,p]of memory)if(p&&[p.x,p.y,p.w,p.h].every(Number.isFinite)){reserve(p);if(byId.has(String(id)))positions.set(String(id),{...p,w:byId.get(String(id)).w,h:byId.get(String(id)).h});}
    const reused=positions.size;
    if(!positions.has(root.id)){const p={x:Math.max(0,width/2-root.w/2),y:Math.max(0,height/2-root.h/2),w:root.w,h:root.h};positions.set(root.id,p);reserve(p);}
    const rp=positions.get(root.id),cx=rp.x+rp.w/2,cy=rp.y+rp.h/2;
    const families=new Map();
    for(const node of rows)if(node!==root){const family=familyFor(node,byId);node._family=family;if(!families.has(family))families.set(family,[]);families.get(family).push(node);}
    const anchors=new Map();
    for(const [family,members]of families){
      const saved=members.filter(n=>positions.has(n.id)),angle=(hash(family)%360)*Math.PI/180,radius=440+hash(family+'radius')%230;
      anchors.set(family,saved.length?{x:saved.reduce((s,n)=>s+positions.get(n.id).x,0)/saved.length,y:saved.reduce((s,n)=>s+positions.get(n.id).y,0)/saved.length,fixed:true}:{x:cx+Math.cos(angle)*radius,y:cy+Math.sin(angle)*radius*.8,fixed:false});
    }
    // A fixed six-pass, heavily damped constellation separation. Never a running simulation.
    const groups=[...anchors.values()];for(let pass=0;pass<6;pass++)for(let i=0;i<groups.length;i++)for(let j=i+1;j<groups.length;j++){
      const a=groups[i],b=groups[j],dx=b.x-a.x||.1,dy=b.y-a.y||.1,d=Math.hypot(dx,dy);if(d>=380)continue;const force=(380-d)*.08;if(!a.fixed){a.x-=dx/d*force;a.y-=dy/d*force;}if(!b.fixed){b.x+=dx/d*force;b.y+=dy/d*force;}
    }
    const pending=rows.filter(n=>!positions.has(n.id));
    while(pending.length){const at=pending.findIndex(n=>!byId.has(String(n.parent_id))||positions.has(String(n.parent_id))),node=pending.splice(at<0?0:at,1)[0];
      const parent=positions.get(String(node.parent_id)),anchor=anchors.get(node._family)||{x:cx,y:cy};let x=anchor.x,y=anchor.y;
      if(parent&&String(node.parent_id)!==root.id&&byId.get(String(node.parent_id))?._family===node._family){const angle=(hash(node.id+'orbit')%360)*Math.PI/180;const attraction=Math.min(.28,.12*Math.max(.1,Math.min(4,Number(node.weight)||1)));x=(parent.x+Math.cos(angle)*270)*(1-attraction)+anchor.x*attraction;y=(parent.y+Math.sin(angle)*210)*(1-attraction)+anchor.y*attraction;}
      let chosen=null;
      for(let attempt=0;attempt<80;attempt++){const r=attempt?Math.sqrt(attempt)*130:0,a=attempt*2.3999632297;const p={x:x+Math.cos(a)*r,y:y+Math.sin(a)*r,w:node.w,h:node.h};if(!collides(p)){chosen=p;break;}}
      if(!chosen){let bottom=Math.max(cy,...[...positions.values()].map(p=>p.y+p.h))+80;chosen={x,y:bottom,w:node.w,h:node.h};while(collides(chosen))chosen.y+=node.h+40;}
      positions.set(node.id,chosen);reserve(chosen);
    }
    return {positions,width:Math.max(width,...[...positions.values()].map(p=>p.x+p.w)),height:Math.max(height,...[...positions.values()].map(p=>p.y+p.h)),reused,new_count:rows.length-reused};
  }
  function starPresentation(nodes,positions,camera,viewport,options={}) {
    const byId=new Map(nodes.map(n=>[String(n.id),n])),focus=byId.get(String(camera.focusNodeId))||byId.get('root'),line=new Set(),seen=new Set();
    let walk=focus;while(walk&&!seen.has(String(walk.id))){seen.add(String(walk.id));if(walk===focus||Number(walk.layer||0)===0)line.add(String(walk.id));walk=byId.get(String(walk.parent_id));}
    const family=focus?familyFor(focus,byId):'root',span=Math.max(200,Math.hypot(viewport.width,viewport.height)/(camera.scale||1));
    const out=new Map(),latest=Math.max(1,...nodes.map(n=>Number(n.id)||0)),zoomContext=Math.sqrt(Math.max(.04,Math.min(1,camera.scale||1)));
    for(const node of nodes){
      const id=String(node.id),p=positions.get(id);if(!p)continue;
      const current=line.has(id),related=familyFor(node,byId)===family,layer=Math.max(0,Math.min(2,Number(node.layer)||0));
      const priority=Math.max(.1,Math.min(1,(Number(node.path_weight)||50)/100)),weight=Math.max(.25,Math.min(2,Number(node.weight)||1));
      const distance=Math.hypot(p.x+p.w/2-camera.x,p.y+p.h/2-camera.y)/span;
      const falloff=1/(1+distance*distance*2.8),base=layer<=1?.82-(.82-.56)*layer:.56-(.56-.33)*(layer-1);
      const receded=options.receded?.has(id)||options.dimLayers?.has(layer),recency=.88+.12*Math.max(0,Math.min(1,(Number(node.id)||latest)/latest));
      const size=current?1:Math.max(.12,Math.min(.9,base*(.78+priority*.3)*Math.sqrt(weight)*(related?1:.82)*falloff*zoomContext*recency*(receded?.48:1)));
      const brightness=current?1:Math.max(.06,Math.min(.88,(.28+size*.68)*falloff*(receded?.62:1)));
      const colour=node.locked_at||node.origin==='web_evidence'||['source','passage','edge'].includes(node.type)?'#ffd38b':layer===2?'#b3bdcc':node.type==='association'?'#a8ebbf':['counter','edge_case','dead_end'].includes(node.type)?'#efbe7d':node.type==='reframing'?'#c8b7f0':'#9ddcec';
      out.set(id,{size_factor:size,brightness_factor:brightness,logic_line:current,related,colour,distant:!current&&size<.4});
    }
    return out;
  }
  function sentence(text) {
    const clean = String(text || '').replace(/https?:\/\/\S+/g, '').replace(/[*_#`]/g, '').replace(/\b(?:Completed search\.|No completed search reported\.)\s*/g, '').replace(/\s+/g, ' ').trim();
    const first = clean.match(/^.*?[.!?](?=\s+[A-Z]|$)/)?.[0] || clean;
    if (first.length <= 190) return first;
    return first.slice(0, 187).replace(/\s+\S*$/, '') + '…';
  }
  class ReadingQueue {
    constructor() { this.items = []; this.seen = new Set(); this.history = []; this.current = null; this.until = 0; this.last = 0; this.paused = false; }
    add(text) { text = sentence(text); if (!text || this.seen.has(text)) return; this.seen.add(text); this.items.push(text); this.history.push(text); if (this.items.length > 50) this.items.shift(); if (this.history.length > 100) this.history.shift(); }
    tick(now, visible = true) { const elapsed = this.last ? now - this.last : 0; this.last = now; if (!visible || this.paused) { this.until += elapsed; return null; } if (!this.items.length || this.current && now < this.until) return null; this.current = this.items.shift(); this.until = now + Math.max(5000, Math.min(10000, this.current.split(/\s+/).length * 260 + 2000)); return this.current; }
  }
  return {owner, project, layout, constellationLayout, starPresentation, sentence, ReadingQueue};
});
