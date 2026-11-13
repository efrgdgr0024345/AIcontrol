/* DOM-free camera. World coordinates are read, never changed. */
(function(scope,factory){const api=factory();if(typeof module==='object'&&module.exports)module.exports=api;else scope.AIcontrolCamera=api;})(typeof globalThis!=='undefined'?globalThis:this,()=>{
  'use strict';
  const clamp=(v,a,b)=>Math.max(a,Math.min(b,v)),finite=(v,fallback)=>Number.isFinite(Number(v))?Number(v):fallback;
  // CSS cubic-bezier(.4,0,.2,1), solved in x so travel matches the specified easing.
  function ease(t){let lo=0,hi=1,u=t;for(let i=0;i<12;i++){u=(lo+hi)/2;const x=3*(1-u)**2*u*.4+3*(1-u)*u*u*.2+u**3;if(x<t)lo=u;else hi=u;}return 3*(1-u)*u*u+u**3;}
  class CameraController {
    constructor(options={}){
      this.state={x:0,y:0,scale:1,depth:0,focusNodeId:'root',growthScale:1};this.viewport={width:1,height:1};this.positions=new Map();
      this.now=options.now||(()=>performance.now());this.raf=options.requestFrame||(f=>requestAnimationFrame(f));this.caf=options.cancelFrame||(id=>cancelAnimationFrame(id));
      this.reducedMotion=options.reducedMotion||(()=>false);this.onChange=options.onChange||(()=>{});this.frame=null;this.transition=null;this.manualUntil=0;this.pendingGrowth=null;this.serial=0;this.destroyed=false;
    }
    setGeometry(positions,width,height){this.positions=positions;this.viewport={width:Math.max(1,finite(width,1)),height:Math.max(1,finite(height,1))};}
    getState(){return {...this.state,transitioning:!!this.transition,transitionType:this.transition?.type||null,userNavigating:this.now()<this.manualUntil};}
    emit(extra={}){this.onChange(this.getState(),extra);}
    cancel(){if(this.frame!==null)this.caf(this.frame);this.frame=null;this.transition=null;this.serial++;}
    move(target,{animate=true,duration=600,type='focus'}={}){
      this.cancel();if(this.destroyed)return false;
      const to={...this.state,...target,scale:clamp(finite(target.scale,this.state.scale),.06,2.4)};
      if(![to.x,to.y,to.scale].every(Number.isFinite))return false;
      if(!animate||this.reducedMotion()){this.state=to;this.emit({reducedMotion:!!animate&&this.reducedMotion()});this.flushGrowth();return true;}
      const from={...this.state},start=this.now(),ticket=this.serial;
      this.transition={type,start,duration};this.state.focusNodeId=to.focusNodeId;this.emit();
      const tick=()=>{
        if(ticket!==this.serial||this.destroyed)return;
        const t=clamp((this.now()-start)/duration,0,1),p=ease(t);
        for(const field of ['x','y','scale','depth','growthScale'])this.state[field]=from[field]+(to[field]-from[field])*p;
        if(t>=1){this.state=to;this.transition=null;this.frame=null;this.emit();this.flushGrowth();}
        else{this.emit();this.frame=this.raf(tick);}
      };this.frame=this.raf(tick);return true;
    }
    focus(nodeId,{animate=true,fitPadding=.2}={}){
      const p=this.positions.get(String(nodeId));if(!p)return false;
      this.manualUntil=0;this.pendingGrowth=null;
      const x=p.x+p.w/2,y=p.y+p.h/2,distance=Math.hypot(this.state.x-x,this.state.y-y)*this.state.scale;
      const scale=Math.min(1,(this.viewport.width*(1-clamp(fitPadding,0,.4)*2))/p.w,(this.viewport.height*.7)/p.h);
      return this.move({x,y,scale,focusNodeId:String(nodeId)},{animate,duration:clamp(450+distance*.13,450,750),type:'focus'});
    }
    fitGroup(nodeIds,{animate=true,fitPadding=.12}={}){
      const ids=[...nodeIds].map(String),rows=ids.map(id=>this.positions.get(id)).filter(Boolean);if(!rows.length)return false;
      const left=Math.min(...rows.map(p=>p.x)),right=Math.max(...rows.map(p=>p.x+p.w)),top=Math.min(...rows.map(p=>p.y)),bottom=Math.max(...rows.map(p=>p.y+p.h));
      const padding=1-clamp(fitPadding,0,.4)*2,scale=Math.min(1,(this.viewport.width*padding)/(right-left||1),(this.viewport.height*padding)/(bottom-top||1));
      this.manualUntil=0;this.pendingGrowth=null;return this.move({x:(left+right)/2,y:(top+bottom)/2,scale,focusNodeId:ids.includes(this.state.focusNodeId)?this.state.focusNodeId:ids[0]},{animate,type:'fit',duration:650});
    }
    interact(emit=true){this.cancel();this.manualUntil=this.now()+4000;if(emit)this.emit();}
    panBy(dx,dy){this.interact(false);this.state.x-=finite(dx,0)/this.state.scale;this.state.y-=finite(dy,0)/this.state.scale;this.emit();}
    zoomBy(factor,anchor={x:this.viewport.width/2,y:this.viewport.height/2}){
      this.interact(false);const old=this.state.scale,next=clamp(old*finite(factor,1),.06,2.4),ax=finite(anchor.x,this.viewport.width/2)-this.viewport.width/2,ay=finite(anchor.y,this.viewport.height/2)-this.viewport.height/2;
      this.state.x+=ax/old-ax/next;this.state.y+=ay/old-ay/next;this.state.scale=next;this.emit();
    }
    release(vx=0,vy=0){
      if(this.reducedMotion()||Math.hypot(vx,vy)<.03)return;
      this.cancel();let velocityX=clamp(vx,-1.5,1.5),velocityY=clamp(vy,-1.5,1.5),last=this.now(),elapsed=0;const ticket=this.serial;
      this.transition={type:'momentum'};
      const tick=()=>{if(ticket!==this.serial||this.destroyed)return;const now=this.now(),dt=Math.min(32,Math.max(1,now-last));last=now;elapsed+=dt;velocityX*=Math.exp(-dt/75);velocityY*=Math.exp(-dt/75);this.state.x-=velocityX*dt/this.state.scale;this.state.y-=velocityY*dt/this.state.scale;
        if(elapsed>=300||Math.hypot(velocityX,velocityY)<.01){this.transition=null;this.frame=null;this.emit();}else{this.emit();this.frame=this.raf(tick);}};this.frame=this.raf(tick);
    }
    growthFor(nodeIds){
      const rows=[...nodeIds].map(id=>this.positions.get(String(id))).filter(Boolean);if(!rows.length)return false;
      const rx=Math.max(...rows.map(p=>Math.max(Math.abs(p.x-this.state.x),Math.abs(p.x+p.w-this.state.x)))),ry=Math.max(...rows.map(p=>Math.max(Math.abs(p.y-this.state.y),Math.abs(p.y+p.h-this.state.y))));
      const factor=Math.min(1,this.viewport.width*.45/(rx||1),this.viewport.height*.45/(ry||1));return this.setGrowthScale(factor);
    }
    setGrowthScale(factor){
      if(!Number.isFinite(factor)||factor<=0)return false;
      // No zoom-in or repeated heartbeat adjustment; each growth pass has one gentle bound.
      const target=Math.max(.06,Math.min(this.state.scale,factor));if(target>=this.state.scale*.98){this.pendingGrowth=null;return false;}
      this.pendingGrowth=target;return this.flushGrowth();
    }
    flushGrowth(){
      if(this.pendingGrowth===null||this.transition||this.now()<this.manualUntil||this.destroyed)return false;
      const scale=this.pendingGrowth;this.pendingGrowth=null;return this.move({scale,growthScale:scale},{animate:true,duration:2200,type:'growth'});
    }
    restoreState(value){
      if(!value||![value.x,value.y,value.scale].every(v=>typeof v==='number'&&Number.isFinite(v)))return false;
      this.manualUntil=0;this.pendingGrowth=null;return this.move({x:value.x,y:value.y,scale:value.scale,depth:finite(value.depth,0),focusNodeId:typeof value.focusNodeId==='string'?value.focusNodeId:'root'},{animate:false,type:'restore'});
    }
    destroy(){this.cancel();this.destroyed=true;this.pendingGrowth=null;}
  }
  return {CameraController,ease};
});
