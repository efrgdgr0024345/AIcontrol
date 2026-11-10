const assert=require('node:assert/strict');
const {owner,project,layout,sentence,ReadingQueue}=require('../assets/map-engine.js');
let checks=0;
function check(name,run){run();checks++;}
const snapshot={run:{status:'running',name:'Concept investigation'},nodes:[
  {id:1,parent_id:null,type:'phase',title:'Dream'},
  {id:2,parent_id:1,type:'dream_claim',title:'First concept',selected:1},
  {id:3,parent_id:2,type:'plan_step',title:'Plan first concept'},
  {id:4,parent_id:3,type:'search',title:'Evidence search'},
  {id:5,parent_id:4,type:'source',title:'Source'},
  {id:6,parent_id:2,type:'question',title:'Untested alternative',speculative:1},
  {id:7,parent_id:1,type:'dream_claim',title:'Second concept',selected:1},
  {id:8,parent_id:2,type:'synthesis',title:'Open',data_json:JSON.stringify({verdict:'open'})}
],sources:[{id:10,node_id:5,title:'Real citation',meta_json:JSON.stringify({claim_ids:[2,7]})}],
ledger:{edges:[{id:20,claim_id:2,source_id:10,passage_id:30,relation:'supports',strength:'low'}],passages:[{id:30,source_id:10,exact_text:'Captured source text'}]},
requests:[{status:'pending',kind:'evidence',path_node_id:3,activity:'Searching external sources'}]};
check('Overview is a concept projection, not a phase tree',()=>{
  const original=JSON.stringify(snapshot),graph=project(snapshot);
  assert.deepEqual(graph.nodes.map(n=>n.type),['root','dream_claim','question','dream_claim']);
  assert.equal(graph.nodes.find(n=>n.id==='2').parent_id,'root');assert.equal(JSON.stringify(snapshot),original);
});
check('Stage work belongs to its actual concept',()=>{const graph=project(snapshot);assert.equal(graph.nodes.find(n=>n.id==='2').activity,'evidence');assert.equal(graph.nodes.find(n=>n.id==='7').working,false);assert.equal(owner(snapshot,4),'2');});
check('Terminal investigations never show a live pulse',()=>{const graph=project({...snapshot,run:{status:'failed'}});assert.equal(graph.nodes.some(n=>n.working),false);});
check('Global planning highlights selected concepts only',()=>{const graph=project({...snapshot,requests:[{status:'pending',kind:'plan',node_id:1}]});assert.equal(graph.nodes.filter(n=>n.working&&n.activity==='planning').length,2);assert.equal(graph.nodes.find(n=>n.type==='question').working,false);});
check('Evidence links connect concepts without duplicating source boxes',()=>{const graph=project(snapshot);assert.ok(graph.edges.some(e=>e.kind==='shared_source'&&e.source_id===10));assert.deepEqual(graph.nodes.find(n=>n.id==='2').source_ids,[10]);});
check('Detailed filters retain exact material and original IDs',()=>{assert.equal(project(snapshot,'source').nodes.find(n=>n.type==='source').id,'5');assert.equal(project(snapshot,'passage').nodes.find(n=>n.type==='passage').title,'Captured source text');assert.equal(project(snapshot,'edge').nodes.find(n=>n.type==='edge').id,'edge-20');});
check('Broken parent cycles cannot hang projection',()=>{assert.equal(owner({nodes:[{id:1,parent_id:2,type:'phase'},{id:2,parent_id:1,type:'search'}]},1),'root');});
function noOverlap(packed){const nodes=[...packed.positions.values()];for(let i=0;i<nodes.length;i++)for(let j=i+1;j<nodes.length;j++){const a=nodes[i],b=nodes[j];assert.ok(!(a.x<b.x+b.w&&a.x+a.w>b.x&&a.y<b.y+b.h&&a.y+a.h>b.y),'Concept rectangles overlap');}for(const n of nodes){assert.ok(Number.isFinite(n.x)&&Number.isFinite(n.y)&&n.x>=0&&n.y>=0);assert.ok(n.x+n.w<=packed.width&&n.y+n.h<=packed.height);}}
check('Eighteen concepts use both axes and fit a desktop at readable scale',()=>{const nodes=Array.from({length:18},(_,i)=>({id:String(i),w:194,h:84})),edges=nodes.slice(1).map(n=>({from:'0',to:n.id,weight:50}));const packed=layout(nodes,edges,1320,680);noOverlap(packed);assert.ok(Math.min(1320/packed.width,680/packed.height)>=.85);assert.ok(new Set([...packed.positions.values()].map(p=>Math.round(p.x/50))).size>3);assert.ok(new Set([...packed.positions.values()].map(p=>Math.round(p.y/50))).size>3);});
check('New ideas settle around existing positions without losing nodes',()=>{const nodes=Array.from({length:14},(_,i)=>({id:String(i),w:194,h:84+(i%3)*10})),edges=nodes.slice(1).map(n=>({from:'0',to:n.id,weight:50}));const previous=layout(nodes,edges,1200,760);nodes.push({id:'new',w:194,h:110});const next=layout(nodes,[...edges,{from:'2',to:'new',weight:30}],1200,760,previous.positions);assert.equal(next.positions.size,15);noOverlap(next);assert.deepEqual(layout(nodes,edges,1200,760),layout(nodes,edges,1200,760));});
check('Large evidence filters keep all nodes available without overlap',()=>{const nodes=Array.from({length:48},(_,i)=>({id:String(i),w:194,h:84}));const packed=layout(nodes,[],1100,700);assert.equal(packed.positions.size,48);noOverlap(packed);});
check('Headlines use one concise sentence with qualification intact',()=>{assert.equal(sentence('Provisional: **An alternative mechanism may explain this.** More detail follows.'),'Provisional: An alternative mechanism may explain this.');assert.equal(sentence('Read https://example.com/source for context.'),'Read for context.');assert.equal(sentence('Provisional: Completed search. A different mechanism could explain this. More detail.'),'Provisional: A different mechanism could explain this.');assert.ok(sentence('A long concept '.repeat(60)).length<=190);});
check('Reading queue does not repeat polling updates or interrupt reading',()=>{const queue=new ReadingQueue();queue.add('First concept.');queue.add('First concept.');queue.add('Second concept.');assert.equal(queue.tick(1000),'First concept.');assert.equal(queue.tick(4000),null);assert.equal(queue.items.length,1);assert.equal(queue.tick(6000),'Second concept.');});
check('Pause and hidden tabs retain the current sentence and backlog',()=>{const queue=new ReadingQueue();queue.add('One concept.');queue.add('Another concept.');queue.tick(1000);queue.paused=true;assert.equal(queue.tick(20000),null);queue.paused=false;assert.equal(queue.tick(21000,false),null);assert.equal(queue.tick(22000),null);assert.equal(queue.tick(27000),'Another concept.');});
check('Backlog is bounded while keeping accessible history',()=>{const queue=new ReadingQueue();for(let i=0;i<150;i++)queue.add('Concept '+i+'.');assert.equal(queue.items.length,50);assert.equal(queue.history.length,100);});
console.log(`PASS: ${checks} concept projection, activity, 2D packing and reading-queue checks`);
