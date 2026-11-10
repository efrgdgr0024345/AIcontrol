/* Pure map projection, packing and reading queue; embedded by build-ui.php. */
(function (scope, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else scope.AIcontrolMap = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';
  const data = node => { try { return JSON.parse(node.data_json || '{}'); } catch (_) { return {}; } };
  function owner(snapshot, id) {
    const byId = new Map(snapshot.nodes.map(n => [String(n.id),n])), seen = new Set();
    let node = byId.get(String(id));
    while (node && !seen.has(String(node.id))) {
      seen.add(String(node.id));
      if (['dream_claim','question'].includes(node.type)) return String(node.id);
      node = byId.get(String(node.parent_id));
    }
    return 'root';
  }
  function project(snapshot, filter = 'all') {
    const full = snapshot.nodes, core = full.filter(n => ['dream_claim','question'].includes(n.type));
    const nodes = [{id:'root',parent_id:null,type:'root',title:snapshot.run.name || 'Research',status:snapshot.run.status,source_ids:[],path_weight:50}];
    const sourceClaims = new Map();
    for (const source of snapshot.sources) {
      const meta = data({data_json:source.meta_json});
      const claims = new Set([...(meta.claim_ids || []), ...(meta.claim_id ? [meta.claim_id] : []), ...snapshot.ledger?.edges.filter(e=>Number(e.source_id)===Number(source.id)).map(e=>e.claim_id)||[]].map(String));
      const parent = owner(snapshot,source.node_id); if (parent!=='root') claims.add(parent);
      sourceClaims.set(Number(source.id),claims);
    }
    for (const node of core) {
      const parent = owner(snapshot,node.parent_id), id=String(node.id);
      const sources=snapshot.sources.filter(s=>sourceClaims.get(Number(s.id))?.has(id)).map(s=>Number(s.id));
      nodes.push({...node,id,parent_id:parent,source_ids:sources,activity:null,working:false});
    }
    const byId = new Map(nodes.map(n=>[n.id,n]));
    // Work is shown on the concept; phase/plan/search rows are never canvas boxes.
    const pending = snapshot.run.status==='running' ? snapshot.requests.filter(q=>q.status==='pending') : [];
    for (const request of pending) {
      const id=owner(snapshot,request.path_node_id || request.claim_id || request.node_id);
      const targets=id!=='root' ? [byId.get(id)] : nodes.filter(n=>n.type==='dream_claim'&&Number(n.selected??1)===1);
      const activity=['dream','exploration'].includes(request.kind)?'discovery':request.kind==='plan'?'planning':request.kind==='evidence'?'evidence':'verification';
      for (const target of targets) if (target) {target.working=true;target.activity=activity;target.activity_text=request.activity||request.worker_role||request.kind;}
      if(!targets.length){nodes[0].working=true;nodes[0].activity=activity;}
    }
    const latest=new Map();for(const node of full.filter(n=>n.type==='synthesis'))latest.set(owner(snapshot,node.id),node);
    for(const node of nodes){const verdict=latest.get(node.id);if(verdict)node.verdict=data(verdict).verdict || verdict.title.split(' · ')[0].toLowerCase();}
    // Explicit detail filters reveal evidence records; the overview remains concept-only.
    if(filter==='source') for(const source of snapshot.sources)nodes.push({...full.find(n=>Number(n.id)===Number(source.node_id)),id:String(source.node_id),parent_id:owner(snapshot,source.node_id),type:'source',title:source.title,status:'complete',source_ids:[Number(source.id)]});
    if(filter==='passage') for(const passage of snapshot.ledger?.passages||[]){const source=snapshot.sources.find(s=>Number(s.id)===Number(passage.source_id));nodes.push({id:'passage-'+passage.id,parent_id:source?owner(snapshot,source.node_id):'root',type:'passage',title:passage.exact_text,status:'complete',source_ids:[Number(passage.source_id)]});}
    if(filter==='edge') for(const edge of snapshot.ledger?.edges||[])nodes.push({id:'edge-'+edge.id,parent_id:String(edge.claim_id),type:'edge',title:edge.relation+' · '+edge.strength,status:'complete',source_ids:[Number(edge.source_id)]});
    if(['dead_end','open'].includes(filter))for(const node of full.filter(n=>filter==='dead_end'?n.type==='dead_end':n.type==='synthesis'&&data(n).verdict==='open'))nodes.push({...node,id:String(node.id),parent_id:owner(snapshot,node.id),source_ids:data(node).source_ids||[]});
    let selected=nodes;
    if(filter==='dream_claim')selected=nodes.filter(n=>n.type==='root'||n.type==='dream_claim');
    if(filter==='question')selected=nodes.filter(n=>n.type==='root'||n.type==='question'||nodes.some(q=>q.type==='question'&&q.parent_id===n.id));
    const ids=new Set(selected.map(n=>n.id)), edges=selected.filter(n=>n.parent_id&&ids.has(n.parent_id)).map(n=>({from:n.parent_id,to:n.id,kind:'concept',weight:Number(n.path_weight||50)}));
    for(const [source,claims] of sourceClaims){const related=[...claims].filter(id=>ids.has(id));for(let a=0;a<related.length;a++)for(let b=a+1;b<related.length;b++)edges.push({from:related[a],to:related[b],kind:'shared_source',source_id:source,weight:25});}
    return {nodes:selected,edges};
  }
  function hash(value) { let n=2166136261;for(const c of String(value))n=Math.imul(n^c.charCodeAt(0),16777619);return n>>>0; }
  function layout(nodes, edges, width, height, previous = new Map()) {
    if(!nodes.length)return {positions:new Map(),width,height};
    const cx=width/2,cy=height/2;
    const items=nodes.map((node,i)=>{
      const old=previous.get(node.id),angle=i*2.399963+hash(node.id)%100/100;
      return {id:node.id,w:node.w||194,h:node.h||84,x:old?old.x+(node.w||194)/2:cx+Math.cos(angle)*Math.sqrt(i)*95,y:old?old.y+(node.h||84)/2:cy+Math.sin(angle)*Math.sqrt(i)*95,vx:0,vy:0};
    });
    const byId=new Map(items.map(n=>[n.id,n]));
    for(let step=0;step<180;step++){
      for(const n of items){n.vx+=(cx-n.x)*.006*Math.max(1,height/width)**2;n.vy+=(cy-n.y)*.006*Math.max(1,width/height)**2;}
      for(const edge of edges){const a=byId.get(edge.from),b=byId.get(edge.to);if(!a||!b)continue;let dx=b.x-a.x,dy=b.y-a.y,d=Math.max(1,Math.hypot(dx,dy));const ideal=Math.min((a.w+b.w)/2/Math.max(.01,Math.abs(dx/d)),(a.h+b.h)/2/Math.max(.01,Math.abs(dy/d)))+30,f=(d-ideal)*(edge.kind==='shared_source'?.008:.018);a.vx+=dx/d*f;a.vy+=dy/d*f;b.vx-=dx/d*f;b.vy-=dy/d*f;}
      for(let i=0;i<items.length;i++)for(let j=i+1;j<items.length;j++){
        const a=items[i],b=items[j];let dx=b.x-a.x,dy=b.y-a.y;if(Math.abs(dx)+Math.abs(dy)<.01){dx=1;dy=.5;}
        const ox=(a.w+b.w)/2+22-Math.abs(dx),oy=(a.h+b.h)/2+22-Math.abs(dy),d=Math.max(25,Math.hypot(dx,dy)),f=Math.min(8,1800/(d*d));
        a.vx-=dx/d*f;b.vx+=dx/d*f;a.vy-=dy/d*f;b.vy+=dy/d*f;
        if(ox>0&&oy>0){if(ox*(height/width)**2<oy){const push=Math.sign(dx)*Math.min(20,ox*.3);a.vx-=push;b.vx+=push;}else{const push=Math.sign(dy)*Math.min(20,oy*.3);a.vy-=push;b.vy+=push;}}
      }
      for(const n of items){n.vx*=.62;n.vy*=.62;n.x+=n.vx;n.y+=n.vy;}
    }
    // Resolve the last few pixels deterministically, including very long concept cards.
    for(let step=0;step<100;step++){
      let collisions=0;for(let i=0;i<items.length;i++)for(let j=i+1;j<items.length;j++){
        const a=items[i],b=items[j],dx=b.x-a.x||.01,dy=b.y-a.y||.01,ox=(a.w+b.w)/2+18-Math.abs(dx),oy=(a.h+b.h)/2+18-Math.abs(dy);
        if(ox>0&&oy>0){collisions++;if(ox*(height/width)**2<oy){const d=Math.sign(dx)*(ox/2+.1);a.x-=d;b.x+=d;}else{const d=Math.sign(dy)*(oy/2+.1);a.y-=d;b.y+=d;}}
      }if(!collisions)break;
    }
    const left=Math.min(...items.map(n=>n.x-n.w/2)),top=Math.min(...items.map(n=>n.y-n.h/2));
    const boxW=Math.max(...items.map(n=>n.x+n.w/2))-left+48,boxH=Math.max(...items.map(n=>n.y+n.h/2))-top+48;
    const worldW=Math.max(width,boxW),worldH=Math.max(height,boxH),offsetX=(worldW-boxW)/2+24-left,offsetY=(worldH-boxH)/2+24-top;
    return {width:worldW,height:worldH,positions:new Map(items.map(n=>[n.id,{x:n.x-n.w/2+offsetX,y:n.y-n.h/2+offsetY,w:n.w,h:n.h}]))};
  }
  function sentence(text) {
    const clean=String(text||'').replace(/https?:\/\/\S+/g,'').replace(/[*_#`]/g,'').replace(/\b(?:Completed search\.|No completed search reported\.)\s*/g,'').replace(/\s+/g,' ').trim();
    const first=clean.match(/^.*?[.!?](?=\s+[A-Z]|$)/)?.[0]||clean;
    if(first.length<=190)return first;
    return first.slice(0,187).replace(/\s+\S*$/,'')+'…';
  }
  class ReadingQueue {
    constructor(){this.items=[];this.seen=new Set();this.history=[];this.current=null;this.until=0;this.last=0;this.paused=false;}
    add(text){text=sentence(text);if(!text||this.seen.has(text))return;this.seen.add(text);this.items.push(text);this.history.push(text);if(this.items.length>50)this.items.shift();if(this.history.length>100)this.history.shift();}
    tick(now,visible=true){const elapsed=this.last?now-this.last:0;this.last=now;if(!visible||this.paused){this.until+=elapsed;return null;}if(!this.items.length||this.current&&now<this.until)return null;this.current=this.items.shift();this.until=now+Math.max(5000,Math.min(10000,this.current.split(/\s+/).length*260+2000));return this.current;}
  }
  return {owner,project,layout,sentence,ReadingQueue};
});
