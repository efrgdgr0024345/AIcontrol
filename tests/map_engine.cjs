const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {owner, project, layout, sentence, ReadingQueue} = require('../assets/map-engine.js');
let checks = 0;
function check(name, run) { try { run(); checks++; } catch (error) { error.message = name + ': ' + error.message; throw error; } }
const fixture = name => JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/map', name + '.json'), 'utf8'));
const review = fixture('dream-review'), evidence = fixture('mid-evidence'), completed = fixture('completed-ledger'), legacy = fixture('legacy-stopped'), malformed = fixture('malformed-old-ledger');
const fixtures = [review, evidence, completed, legacy, malformed];
const filters = ['all', 'dream_claim', 'question', 'source', 'passage', 'edge', 'dead_end', 'open'];
const nodeIds = graph => graph.nodes.map(node => node.id);
const find = (graph, id) => graph.nodes.find(node => node.id === String(id));
const sized = graph => graph.nodes.map(node => ({...node, w: node.type === 'root' ? 210 : 194, h: node.type === 'question' ? 100 : 84}));
const clone = value => JSON.parse(JSON.stringify(value));
function freeze(value) { if (value && typeof value === 'object') { Object.freeze(value); Object.values(value).forEach(freeze); } return value; }
function noOverlap(packed) {
  const nodes = [...packed.positions.values()];
  for (let i = 0; i < nodes.length; i++) for (let j = i + 1; j < nodes.length; j++) {
    const a = nodes[i], b = nodes[j];
    assert.ok(!(a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y), 'Card rectangles overlap');
  }
  for (const n of nodes) {
    assert.ok(Number.isFinite(n.x) && Number.isFinite(n.y) && n.x >= 0 && n.y >= 0);
    assert.ok(n.x + n.w <= packed.width && n.y + n.h <= packed.height);
  }
}
function samePositions(before, after, ids = [...before.positions.keys()]) {
  for (const id of ids) assert.deepEqual(after.positions.get(id), before.positions.get(id), 'Position moved for ' + id);
}
check('Fixtures are explicitly synthetic contract examples', () => {
  assert.equal(fixtures.length, 5);
  for (const snapshot of fixtures) assert.equal(snapshot._fixture.synthetic, true);
});
check('Review includes unselected claims and their questions', () => {
  const graph = project(review);
  assert.deepEqual(nodeIds(graph), ['root', '2', '3', '4', '5']);
  assert.equal(find(graph, 3).parent_id, 'root');
  assert.equal(find(graph, 5).parent_id, '3');
  assert.equal(graph.concept_count, 4);
});
check('Post-review overview includes selected lineage and hides process', () => {
  const graph = project(evidence);
  assert.deepEqual(nodeIds(graph), ['root', '2', '6', '7', '15', '17', '19']);
  assert.equal(find(graph, 15).parent_id, '6');
  assert.equal(find(graph, 17).parent_id, '7');
  assert.ok(graph.nodes.every(node => ['root', 'dream_claim', 'question'].includes(node.type)));
  assert.ok(!find(graph, 9) && !find(graph, 10) && !find(graph, 16));
  // A stored, previously declined question remains inspectable; focus eligibility is a server/UI concern.
  assert.equal(find(graph, 19).selected, 0);
});
check('All explicitly declined claims do not reappear outside review', () => {
  const snapshot = clone(review); snapshot.run.phase = 'evidence';
  snapshot.nodes.filter(node => node.type === 'dream_claim').forEach(node => { node.selected = 0; });
  const graph = project(snapshot);
  assert.deepEqual(nodeIds(graph), ['root']); assert.equal(graph.empty, true);
  assert.match(graph.empty_reason, /No selected concepts/);
});
check('Projection is immutable and deterministic under reordered snapshot rows', () => {
  for (const snapshot of fixtures) {
    const original = JSON.stringify(snapshot), reversed = clone(snapshot);
    reversed.nodes.reverse(); if (reversed.sources) reversed.sources.reverse();
    if (Array.isArray(reversed.requests)) reversed.requests.reverse();
    if (reversed.ledger) for (const name of ['sources', 'passages', 'edges']) if (Array.isArray(reversed.ledger[name])) reversed.ledger[name].reverse();
    freeze(snapshot);
    for (const filter of filters) assert.deepEqual(project(reversed, filter), project(snapshot, filter));
    assert.equal(JSON.stringify(snapshot), original);
  }
});
check('Filter aliases resolve to the same graph and unknown filters use overview', () => {
  for (const [alias, filter] of Object.entries({concepts:'all', claims:'dream_claim', questions:'question', sources:'source', passages:'passage', edges:'edge', dead_ends:'dead_end'})) assert.deepEqual(project(completed, alias), project(completed, filter));
  assert.deepEqual(project(completed, 'missing'), project(completed));
});
check('Pending work is a static activity on the actual question owner', () => {
  const graph = project(evidence);
  assert.equal(owner(evidence, 12), '6'); assert.equal(owner(evidence, 13), '6');
  assert.equal(find(graph, 6).activity, 'evidence'); assert.equal(find(graph, 6).working, true);
  assert.equal(find(graph, 2).working, false); assert.equal(find(graph, 7).working, false);
  assert.equal(project(completed).nodes.some(node => node.working), false);
});
check('Global planning targets selected claims and never unrelated questions', () => {
  const snapshot = clone(evidence); snapshot.requests = [{status:'pending', kind:'plan', node_id:1}];
  const graph = project(snapshot), working = graph.nodes.filter(node => node.working);
  assert.deepEqual(working.map(node => node.id), ['2', '7']);
  assert.ok(working.every(node => node.activity === 'planning'));
});
check('Shared source associations are informational claim links', () => {
  const graph = project(evidence);
  assert.deepEqual(find(graph, 2).source_ids, [10, 11]);
  assert.deepEqual(find(graph, 6).source_ids, [11]);
  assert.deepEqual(find(graph, 7).source_ids, [10]);
  assert.deepEqual(graph.edges.filter(edge => edge.kind === 'shared_source'), [{from:'2',to:'7',kind:'shared_source',source_id:10,weight:25}]);
});
check('Source filter contains real sources and only their owning context', () => {
  const graph = project(completed, 'source');
  assert.deepEqual(nodeIds(graph), ['root', '2', '6', '7', '5', '14']);
  assert.equal(graph.detail_count, 2); assert.equal(graph.empty, false);
  assert.equal(find(graph, 5).parent_id, '2'); assert.equal(find(graph, 14).parent_id, '6');
  assert.equal(find(graph, 5).url, 'https://example.invalid/shared');
  assert.equal(find(graph, 5).display_only, false);
  assert.ok(!find(graph, 15) && !find(graph, 17) && !find(graph, 18));
});
check('Passage ownership follows its specific ledger links with exact material', () => {
  const graph = project(completed, 'passage'), passage = find(graph, 'passage-31');
  assert.deepEqual(nodeIds(graph), ['root', '2', '7', 'passage-30', 'passage-31']);
  assert.equal(passage.parent_id, '2'); assert.equal(passage.title, completed.ledger.passages[1].exact_text);
  assert.equal(passage.locator, 'Abstract'); assert.equal(passage.fetch_scope, 'abstract_only');
  assert.equal(passage.display_only, true); assert.deepEqual(passage.source_ids, [11]);
  assert.deepEqual(find(graph, 'passage-30').owner_ids, ['2', '7']);
});
check('Relation filter keeps saved rationale, IDs and exact claim ownership', () => {
  const graph = project(completed, 'edge');
  assert.deepEqual(nodeIds(graph), ['root', '2', '7', 'edge-40', 'edge-41', 'edge-42']);
  assert.equal(find(graph, 'edge-40').parent_id, '7');
  assert.equal(find(graph, 'edge-42').content, 'Delayed feedback limits this generalisation.');
  assert.equal(find(graph, 'edge-41').passage_id, 30);
  assert.ok(graph.nodes.filter(node => node.type === 'edge').every(node => node.display_only));
});
check('Dead ends retain saved IDs and resolve through process ancestry', () => {
  const graph = project(evidence, 'dead_end');
  assert.deepEqual(nodeIds(graph), ['root', '2', '11']);
  assert.equal(find(graph, 11).parent_id, '2'); assert.equal(find(graph, 11).display_only, false);
  assert.equal(find(project(malformed, 'dead_end'), 7).parent_id, '2');
});
check('Open filter uses current saved verdict before old title text', () => {
  const graph = project(completed, 'open');
  assert.deepEqual(nodeIds(graph), ['root', '7', '21']);
  assert.equal(find(project(completed), 2).verdict, 'supported');
  assert.equal(project(malformed, 'open').empty, true);
  assert.equal(find(project(legacy, 'open'), 104).parent_id, '100');
});
check('Old runs without ledger still render citations and clear empty detail states', () => {
  assert.deepEqual(nodeIds(project(legacy)), ['root', '100', '103']);
  assert.deepEqual(nodeIds(project(legacy, 'source')), ['root', '100', '102', 'source-201']);
  for (const filter of ['passage', 'edge']) {
    const graph = project(legacy, filter); assert.deepEqual(nodeIds(graph), ['root']);
    assert.equal(graph.empty, true); assert.ok(graph.empty_reason.length > 20);
  }
  assert.equal(owner(legacy, 105), '100');
});
check('Malformed arrays, JSON and source references degrade without invented focus IDs', () => {
  for (const value of [undefined, null, {}, {nodes:null,sources:{},requests:3,ledger:{passages:{},edges:null}}]) {
    for (const filter of filters) { const graph = project(value, filter); assert.deepEqual(nodeIds(graph), ['root']); assert.equal(graph.empty, true); }
  }
  const graph = project(malformed, 'source');
  assert.equal(find(graph, 'source-10').parent_id, '2');
  assert.equal(find(graph, 'source-10').display_only, true);
  assert.ok(!find(graph, 5));
  assert.equal(find(graph, 'source-12').parent_id, '2');
  assert.equal(find(graph, 6).type, 'source'); assert.equal(find(graph, 'source-14').type, 'source');
  assert.equal(new Set(nodeIds(graph)).size, graph.nodes.length);
  const relations = project(malformed, 'edge');
  assert.equal(find(relations, 'edge-40').parent_id, 'root');
  assert.equal(find(relations, 'edge-43').parent_id, 'root');
  assert.ok(!find(relations, 'edge-41'));
  assert.equal(find(project(malformed, 'passage'), 'passage-33').parent_id, 'root');
});
check('Canonical sources win over duplicate convenience ledger rows', () => {
  const snapshot = clone(evidence); snapshot.ledger.sources = [{id:10,title:'Stale convenience title',node_id:999}, {id:22,title:'Ledger-only citation',node_id:999,meta_json:'{}'}];
  const graph = project(snapshot, 'source');
  assert.equal(find(graph, 5).title, 'Shared reference'); assert.equal(find(graph, 'source-22').parent_id, 'root');
});
check('Broken parent cycles terminate and metadata can restore ownership', () => {
  assert.equal(owner({nodes:[{id:1,parent_id:2,type:'phase'},{id:2,parent_id:1,type:'search'}]}, 1), 'root');
  assert.equal(owner(malformed, 7), '2'); assert.equal(owner(malformed, 999), 'root'); assert.equal(owner(null, 1), 'root');
});
check('Initial structural layout is deterministic, layered and non-overlapping', () => {
  for (const snapshot of fixtures) for (const filter of filters) {
    const graph = project(snapshot, filter), cards = sized(graph), packed = layout(cards, graph.edges, 1280, 680);
    noOverlap(packed); assert.equal(packed.positions.size, cards.length);
    assert.deepEqual(layout(cards.slice().reverse(), graph.edges.slice().reverse(), 1280, 680), packed);
    for (const node of graph.nodes) if (node.parent_id && packed.positions.has(node.parent_id)) assert.ok(packed.positions.get(node.id).y >= packed.positions.get(node.parent_id).y + packed.positions.get(node.parent_id).h);
  }
});
check('Usage, status, titles and activity updates preserve every coordinate', () => {
  const firstGraph = project(evidence), first = layout(sized(firstGraph), firstGraph.edges, 1280, 680);
  const update = clone(evidence); update.run.total_tokens += 1000; update.run.estimated_cost_usd = 0.1;
  update.nodes[1].title = 'A longer updated label'; update.nodes[1].status = 'running'; update.nodes[1].path_weight = 25;
  update.requests[0].input_tokens += 1000; update.requests[0].activity = 'Reading the returned material';
  const graph = project(update), next = layout(sized(graph), graph.edges, 1280, 680, first.positions);
  samePositions(first, next); assert.equal(next.reused, first.positions.size); assert.equal(next.new_count, 0);
});
check('Appending a question places only new IDs and retains memory immutably', () => {
  const graph = project(evidence), first = layout(sized(graph), graph.edges, 1200, 760), before = [...first.positions];
  const update = clone(evidence); update.nodes.push({id:99,parent_id:6,type:'question',title:'A new alternative'});
  const nextGraph = project(update), next = layout(sized(nextGraph), nextGraph.edges, 1200, 760, first.positions);
  samePositions(first, next); noOverlap(next); assert.equal(next.new_count, 1); assert.equal(next.reused, before.length);
  assert.deepEqual([...first.positions], before);
});
check('Filter, removal, collapse and resize reuse remembered coordinates exactly', () => {
  const graph = project(completed), first = layout(sized(graph), graph.edges, 1280, 680);
  const sourceGraph = project(completed, 'source'), sources = layout(sized(sourceGraph), sourceGraph.edges, 1280, 680, first.positions);
  for (const id of ['root', '2', '6', '7']) assert.deepEqual(sources.positions.get(id), first.positions.get(id));
  noOverlap(sources);
  const memory = new Map([...first.positions, ...sources.positions]);
  const reopened = layout(sized(graph), graph.edges, 600, 400, memory);
  samePositions(first, reopened); noOverlap(reopened);
  const visible = sized(graph).filter(node => !['6','15'].includes(node.id));
  const collapsed = layout(visible, graph.edges, 1800, 950, memory);
  samePositions(first, collapsed, visible.map(node => node.id));
  const reset = layout(sized(graph), graph.edges, 600, 400, new Map());
  assert.notDeepEqual(reset.positions.get('root'), first.positions.get('root')); noOverlap(reset);
});
check('Large projections remain available without overlap and shared links do not move cards', () => {
  const nodes = Array.from({length:48}, (_, i) => ({id:String(i),w:194,h:84}));
  const packed = layout(nodes, [], 1100, 700); assert.equal(packed.positions.size, 48); noOverlap(packed);
  const edges = nodes.slice(1).map(node => ({from:'0',to:node.id,kind:'concept'}));
  assert.deepEqual(layout(nodes, edges, 1100, 700).positions, layout(nodes, [...edges,{from:'4',to:'20',kind:'shared_source'}], 1100, 700).positions);
  const more = [...nodes, {id:'new',parent_id:'10',w:194,h:130}];
  const next = layout(more, edges, 1100, 700, packed.positions); samePositions(packed, next); noOverlap(next);
});
check('Headlines keep a concise qualified sentence', () => {
  assert.equal(sentence('Provisional: **An alternative mechanism may explain this.** More detail follows.'), 'Provisional: An alternative mechanism may explain this.');
  assert.equal(sentence('Read https://example.invalid/source for context.'), 'Read for context.');
  assert.equal(sentence('Provisional: Completed search. A different mechanism could explain this. More detail.'), 'Provisional: A different mechanism could explain this.');
  assert.ok(sentence('A long concept '.repeat(60)).length <= 190);
});
check('Reading queue deduplicates polling updates without interrupting reading', () => {
  const queue = new ReadingQueue(); queue.add('First concept.'); queue.add('First concept.'); queue.add('Second concept.');
  assert.equal(queue.tick(1000), 'First concept.'); assert.equal(queue.tick(4000), null); assert.equal(queue.items.length, 1); assert.equal(queue.tick(6000), 'Second concept.');
});
check('Pause and hidden tabs retain the current sentence and backlog', () => {
  const queue = new ReadingQueue(); queue.add('One concept.'); queue.add('Another concept.'); queue.tick(1000);
  queue.paused = true; assert.equal(queue.tick(20000), null); queue.paused = false;
  assert.equal(queue.tick(21000, false), null); assert.equal(queue.tick(22000), null); assert.equal(queue.tick(27000), 'Another concept.');
});
check('Reading backlog is bounded with an accessible history', () => {
  const queue = new ReadingQueue(); for (let i = 0; i < 150; i++) queue.add('Concept ' + i + '.');
  assert.equal(queue.items.length, 50); assert.equal(queue.history.length, 100);
});
console.log(`PASS: ${checks} synthetic-fixture projection, ownership, stable-layout and reading-queue checks`);
