/* DOM-free camera. World coordinates are read, never changed. */
(function(scope,factory){const api=factory();if(typeof module==='object'&&module.exports)module.exports=api;else scope.AIcontrolCamera=api;})(typeof globalThis!=='undefined'?globalThis:this,()=>{
  'use strict';
  const clamp=(v,a,b)=>Math.max(a,Math.min(b,v)),finite=(v,fallback)=>Number.isFinite(Number(v))?Number(v):fallback;
  const turn=value=>((value+Math.PI)%(Math.PI*2)+Math.PI*2)%(Math.PI*2)-Math.PI;
  const rotate=(x,y,a)=>({x:x*Math.cos(a)-y*Math.sin(a),y:x*Math.sin(a)+y*Math.cos(a)});
  // CSS cubic-bezier(.4,0,.2,1), solved in x so travel matches the specified easing.
  function ease(t){let lo=0,hi=1,u=t;for(let i=0;i<12;i++){u=(lo+hi)/2;const x=3*(1-u)**2*u*.4+3*(1-u)*u*u*.2+u**3;if(x<t)lo=u;else hi=u;}return 3*(1-u)*u*u+u**3;}
  class LegacyCameraController {
    constructor(options={}){
      this.state={x:0,y:0,scale:1,rotation:0,depth:0,focusNodeId:'root',growthScale:1};this.viewport={width:1,height:1};this.positions=new Map();
      this.now=options.now||(()=>performance.now());this.raf=options.requestFrame||(f=>requestAnimationFrame(f));this.caf=options.cancelFrame||(id=>cancelAnimationFrame(id));
      this.reducedMotion=options.reducedMotion||(()=>false);this.onChange=options.onChange||(()=>{});this.frame=null;this.transition=null;this.manualUntil=0;this.pendingGrowth=null;this.serial=0;this.destroyed=false;
    }
    setGeometry(positions,width,height){this.positions=positions;this.viewport={width:Math.max(1,finite(width,1)),height:Math.max(1,finite(height,1))};}
    getState(){return {...this.state,transitioning:!!this.transition,transitionType:this.transition?.type||null,userNavigating:this.now()<this.manualUntil};}
    emit(extra={}){this.onChange(this.getState(),extra);}
    cancel(){if(this.frame!==null)this.caf(this.frame);this.frame=null;this.transition=null;this.serial++;}
    move(target,{animate=true,duration=600,type='focus'}={}){
      this.cancel();if(this.destroyed)return false;
      const to={...this.state,...target,scale:clamp(finite(target.scale,this.state.scale),.06,2.4),rotation:turn(finite(target.rotation,this.state.rotation))};
      if(![to.x,to.y,to.scale].every(Number.isFinite))return false;
      if(!animate||this.reducedMotion()){this.state=to;this.emit({reducedMotion:!!animate&&this.reducedMotion()});this.flushGrowth();return true;}
      const from={...this.state},start=this.now(),ticket=this.serial;
      this.transition={type,start,duration};this.state.focusNodeId=to.focusNodeId;this.emit();
      const tick=()=>{
        if(ticket!==this.serial||this.destroyed)return;
        const t=clamp((this.now()-start)/duration,0,1),p=ease(t);
        for(const field of ['x','y','scale','depth','growthScale'])this.state[field]=from[field]+(to[field]-from[field])*p;
        this.state.rotation=from.rotation+turn(to.rotation-from.rotation)*p;
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
      const corners=rows.flatMap(p=>[[p.x,p.y],[p.x+p.w,p.y],[p.x,p.y+p.h],[p.x+p.w,p.y+p.h]].map(([x,y])=>rotate(x,y,this.state.rotation)));
      const left=Math.min(...corners.map(p=>p.x)),right=Math.max(...corners.map(p=>p.x)),top=Math.min(...corners.map(p=>p.y)),bottom=Math.max(...corners.map(p=>p.y));
      const centre=rotate((left+right)/2,(top+bottom)/2,-this.state.rotation);
      const padding=1-clamp(fitPadding,0,.4)*2,scale=Math.min(1,(this.viewport.width*padding)/(right-left||1),(this.viewport.height*padding)/(bottom-top||1));
      this.manualUntil=0;this.pendingGrowth=null;return this.move({x:centre.x,y:centre.y,scale,focusNodeId:ids.includes(this.state.focusNodeId)?this.state.focusNodeId:ids[0]},{animate,type:'fit',duration:650});
    }
    worldToScreen(point){const p=rotate(point.x-this.state.x,point.y-this.state.y,this.state.rotation);return {x:this.viewport.width/2+p.x*this.state.scale,y:this.viewport.height/2+p.y*this.state.scale};}
    screenToWorld(point){const p=rotate((point.x-this.viewport.width/2)/this.state.scale,(point.y-this.viewport.height/2)/this.state.scale,-this.state.rotation);return {x:this.state.x+p.x,y:this.state.y+p.y};}
    interact(emit=true){this.cancel();this.manualUntil=this.now()+4000;if(emit)this.emit();}
    panBy(dx,dy){this.interact(false);const p=rotate(finite(dx,0),finite(dy,0),-this.state.rotation);this.state.x-=p.x/this.state.scale;this.state.y-=p.y/this.state.scale;this.emit();}
    transformBy({zoom=1,rotation=0,anchor={x:this.viewport.width/2,y:this.viewport.height/2},nextAnchor=anchor}={}){
      const point=this.screenToWorld(anchor);this.interact(false);
      this.state.scale=clamp(this.state.scale*finite(zoom,1),.06,2.4);this.state.rotation=turn(this.state.rotation+finite(rotation,0));
      const offset=rotate((nextAnchor.x-this.viewport.width/2)/this.state.scale,(nextAnchor.y-this.viewport.height/2)/this.state.scale,-this.state.rotation);
      this.state.x=point.x-offset.x;this.state.y=point.y-offset.y;this.emit();
    }
    zoomBy(factor,anchor={x:this.viewport.width/2,y:this.viewport.height/2}){this.transformBy({zoom:factor,anchor});}
    rotateBy(radians,anchor={x:this.viewport.width/2,y:this.viewport.height/2}){this.transformBy({rotation:radians,anchor});}
    resetRotation({animate=true}={}){this.interact(false);this.move({rotation:0},{animate,duration:500,type:'rotation'});}
    release(vx=0,vy=0){
      if(this.reducedMotion()||Math.hypot(vx,vy)<.03)return;
      this.cancel();let velocityX=clamp(vx,-1.5,1.5),velocityY=clamp(vy,-1.5,1.5),last=this.now(),elapsed=0;const ticket=this.serial;
      this.transition={type:'momentum'};
      const tick=()=>{if(ticket!==this.serial||this.destroyed)return;const now=this.now(),dt=Math.min(32,Math.max(1,now-last));last=now;elapsed+=dt;velocityX*=Math.exp(-dt/75);velocityY*=Math.exp(-dt/75);const delta=rotate(velocityX*dt,velocityY*dt,-this.state.rotation);this.state.x-=delta.x/this.state.scale;this.state.y-=delta.y/this.state.scale;
        if(elapsed>=300||Math.hypot(velocityX,velocityY)<.01){this.transition=null;this.frame=null;this.emit();}else{this.emit();this.frame=this.raf(tick);}};this.frame=this.raf(tick);
    }
    growthFor(nodeIds){
      const rows=[...nodeIds].map(id=>this.positions.get(String(id))).filter(Boolean);if(!rows.length)return false;
      const corners=rows.flatMap(p=>[[p.x,p.y],[p.x+p.w,p.y],[p.x,p.y+p.h],[p.x+p.w,p.y+p.h]].map(([x,y])=>rotate(x-this.state.x,y-this.state.y,this.state.rotation)));
      const rx=Math.max(...corners.map(p=>Math.abs(p.x))),ry=Math.max(...corners.map(p=>Math.abs(p.y)));
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
      if(!value||![value.x,value.y,value.scale].every(v=>typeof v==='number'&&Number.isFinite(v))||(value.rotation!==undefined&&(typeof value.rotation!=='number'||!Number.isFinite(value.rotation))))return false;
      this.manualUntil=0;this.pendingGrowth=null;return this.move({x:value.x,y:value.y,scale:value.scale,rotation:finite(value.rotation,0),depth:finite(value.depth,0),focusNodeId:typeof value.focusNodeId==='string'?value.focusNodeId:'root'},{animate:false,type:'restore'});
    }
    destroy(){this.cancel();this.destroyed=true;this.pendingGrowth=null;}
  }
  const vec=(x=0,y=0,z=0)=>({x,y,z}),add=(a,b)=>vec(a.x+b.x,a.y+b.y,a.z+b.z),sub=(a,b)=>vec(a.x-b.x,a.y-b.y,a.z-b.z),mul=(a,n)=>vec(a.x*n,a.y*n,a.z*n),dot=(a,b)=>a.x*b.x+a.y*b.y+a.z*b.z;
  class SpaceCameraController {
    constructor(options={}){
      this.focusDistance=900;this.near=35;this.far=250000;this.fov=55*Math.PI/180;
      this.state={x:0,y:0,z:900,pivotX:0,pivotY:0,pivotZ:0,yaw:.3,pitch:.16,distance:900,focusNodeId:'root',mode:'orbit'};
      this.positions=new Map();this.viewport={width:1,height:1};this.now=options.now||(()=>performance.now());this.raf=options.requestFrame||(f=>requestAnimationFrame(f));this.caf=options.cancelFrame||(id=>cancelAnimationFrame(id));this.reducedMotion=options.reducedMotion||(()=>false);this.onChange=options.onChange||(()=>{});
      this.frame=null;this.transition=null;this.serial=0;this.manualUntil=0;this.pendingGrowth=null;this.destroyed=false;this.flightInput=vec();this.boost=false;this.placeEye();
    }
    axes(state=this.state){const c=Math.cos(state.yaw),s=Math.sin(state.yaw),p=Math.sin(state.pitch),q=Math.cos(state.pitch);return {right:vec(c,0,-s),down:vec(s*p,q,c*p),forward:vec(-s*q,p,-c*q)};}
    eye(){return vec(this.state.x,this.state.y,this.state.z);}
    pivot(){return vec(this.state.pivotX,this.state.pivotY,this.state.pivotZ);}
    placeEye(){const eye=sub(this.pivot(),mul(this.axes().forward,this.state.distance));Object.assign(this.state,eye);}
    placePivot(){const p=add(this.eye(),mul(this.axes().forward,this.state.distance));Object.assign(this.state,{pivotX:p.x,pivotY:p.y,pivotZ:p.z});}
    get focal(){return this.viewport.height/(2*Math.tan(this.fov/2));}
    getState(){return {...this.state,version:'space3d-v1',scale:this.focusDistance/this.state.distance,depth:this.state.z,transitioning:!!this.transition,transitionType:this.transition?.type||null,userNavigating:this.now()<this.manualUntil};}
    setGeometry(positions,width,height){this.positions=positions;this.viewport={width:Math.max(1,finite(width,1)),height:Math.max(1,finite(height,1))};}
    centre(p){return vec(p.x+p.w/2,p.y+p.h/2,finite(p.z,0));}
    emit(extra={}){this.onChange(this.getState(),extra);}
    cancel(){if(this.frame!==null)this.caf(this.frame);this.frame=null;this.transition=null;this.serial++;this.flightInput=vec();}
    move(target,{animate=true,duration=600,type='focus'}={}){
      this.cancel();if(this.destroyed)return false;const to={...this.state,...target};
      to.distance=clamp(finite(to.distance,900),120,200000);to.yaw=turn(finite(to.yaw,0));to.pitch=clamp(finite(to.pitch,0),-Math.PI*.47,Math.PI*.47);
      if(!['x','y','z','pivotX','pivotY','pivotZ'].every(k=>Number.isFinite(to[k])))return false;
      if(!animate||this.reducedMotion()){this.state=to;this.emit({reducedMotion:!!animate&&this.reducedMotion()});this.flushGrowth();return true;}
      const from={...this.state},start=this.now(),ticket=this.serial;this.transition={type,start,duration};this.state.focusNodeId=to.focusNodeId;this.state.mode=to.mode;this.emit();
      const tick=()=>{if(ticket!==this.serial||this.destroyed)return;const t=clamp((this.now()-start)/duration,0,1),p=ease(t);for(const k of ['x','y','z','pivotX','pivotY','pivotZ','distance','pitch'])this.state[k]=from[k]+(to[k]-from[k])*p;this.state.yaw=from.yaw+turn(to.yaw-from.yaw)*p;
        if(t>=1){this.state=to;this.transition=null;this.frame=null;this.emit();this.flushGrowth();}else{this.emit();this.frame=this.raf(tick);}};this.frame=this.raf(tick);return true;
    }
    targetPose(pivot,distance,yaw=this.state.yaw,pitch=this.state.pitch){const forward=this.axes({yaw,pitch}).forward,eye=sub(pivot,mul(forward,distance));return {...eye,pivotX:pivot.x,pivotY:pivot.y,pivotZ:pivot.z,distance,yaw,pitch};}
    focus(nodeId,{animate=true,fitPadding=.2}={}){const p=this.positions.get(String(nodeId));if(!p)return false;this.pendingGrowth=null;this.manualUntil=0;const centre=this.centre(p),d=this.focusDistance;const destination=this.targetPose(centre,d),travel=Math.hypot(destination.x-this.state.x,destination.y-this.state.y,destination.z-this.state.z);return this.move({...destination,focusNodeId:String(nodeId),mode:'orbit'},{animate,type:'focus',duration:clamp(450+travel*.1,450,750)});}
    groupBounds(nodeIds){const ids=[...nodeIds].map(String).filter(id=>this.positions.has(id));if(!ids.length)return null;const centres=ids.map(id=>this.centre(this.positions.get(id))),lo=vec(Math.min(...centres.map(p=>p.x)),Math.min(...centres.map(p=>p.y)),Math.min(...centres.map(p=>p.z))),hi=vec(Math.max(...centres.map(p=>p.x)),Math.max(...centres.map(p=>p.y)),Math.max(...centres.map(p=>p.z))),centre=mul(add(lo,hi),.5);return {ids,centre,radius:Math.max(180,...centres.map(p=>Math.hypot(p.x-centre.x,p.y-centre.y,p.z-centre.z)+140))};}
    fitGroup(ids,{animate=true,fitPadding=.12}={}){const bounds=this.groupBounds(ids);if(!bounds)return false;this.manualUntil=0;this.pendingGrowth=null;const vertical=this.fov/2,horizontal=Math.atan(Math.tan(vertical)*this.viewport.width/this.viewport.height),angle=Math.atan(Math.tan(Math.min(vertical,horizontal))*(1-clamp(fitPadding,0,.4)*2)),d=bounds.radius/Math.sin(angle);return this.move({...this.targetPose(bounds.centre,d),mode:'orbit',focusNodeId:bounds.ids.includes(this.state.focusNodeId)?this.state.focusNodeId:bounds.ids[0]},{animate,type:'fit',duration:650});}
    cameraPoint(point){const p=sub(vec(finite(point.x,0),finite(point.y,0),finite(point.z,0)),this.eye()),a=this.axes();return vec(dot(p,a.right),dot(p,a.down),dot(p,a.forward));}
    projectCamera(p){const visible=p.z>=this.near&&p.z<=this.far;const depth=Math.max(this.near,p.z),factor=this.focal/depth;return {x:this.viewport.width/2+p.x*factor,y:this.viewport.height/2+p.y*factor,depth:p.z,perspective:factor,visible};}
    worldToScreen(point){return this.projectCamera(this.cameraPoint(point));}
    screenToWorld(point,depth=this.state.distance){const a=this.axes(),d=Math.max(this.near,finite(depth,this.state.distance));return add(this.eye(),add(mul(a.forward,d),add(mul(a.right,(point.x-this.viewport.width/2)*d/this.focal),mul(a.down,(point.y-this.viewport.height/2)*d/this.focal))));}
    projectSegment(from,to){let a=this.cameraPoint(from),b=this.cameraPoint(to),lo=0,hi=1;const dz=b.z-a.z;if(Math.abs(dz)<1e-10){if(a.z<this.near||a.z>this.far)return null;}else{const n=(this.near-a.z)/dz,f=(this.far-a.z)/dz;lo=Math.max(0,Math.min(n,f));hi=Math.min(1,Math.max(n,f));if(lo>hi)return null;}const delta=sub(b,a);return {from:this.projectCamera(add(a,mul(delta,lo))),to:this.projectCamera(add(a,mul(delta,hi)))};}
    interact(emit=true){this.cancel();this.manualUntil=this.now()+4000;if(emit)this.emit();}
    panBy(dx,dy){if(this.transition?.type!=='flight')this.interact(false);else this.manualUntil=this.now()+4000;const a=this.axes(),factor=this.state.distance/this.focal,delta=add(mul(a.right,-finite(dx,0)*factor),mul(a.down,-finite(dy,0)*factor));Object.assign(this.state,add(this.eye(),delta));Object.assign(this.state,{pivotX:this.state.pivotX+delta.x,pivotY:this.state.pivotY+delta.y,pivotZ:this.state.pivotZ+delta.z});this.emit();}
    turnBy(yaw,pitch=0){const flying=!!this.transition&&this.transition.type==='flight';if(!flying)this.interact(false);else this.manualUntil=this.now()+4000;this.state.yaw=turn(this.state.yaw+finite(yaw,0));this.state.pitch=clamp(this.state.pitch+finite(pitch,0),-Math.PI*.47,Math.PI*.47);this.state.mode==='fly'?this.placePivot():this.placeEye();this.emit();}
    rotateBy(yaw,pitch=0){this.turnBy(yaw,pitch);}
    resetRotation({animate=true}={}){this.interact(false);return this.move({...this.targetPose(this.pivot(),this.state.distance,0,0)},{animate,type:'orientation',duration:500});}
    setMode(mode){if(!['orbit','fly'].includes(mode))return false;this.interact(false);this.state.mode=mode;this.emit();return true;}
    transformBy({zoom=1,anchor={x:this.viewport.width/2,y:this.viewport.height/2},nextAnchor=anchor}={}){
      const point=this.screenToWorld(anchor),oldDistance=this.state.distance;if(this.transition?.type!=='flight')this.interact(false);else this.manualUntil=this.now()+4000;const factor=clamp(finite(zoom,1),.3,3);
      if(this.state.mode==='fly'){const delta=mul(this.axes().forward,oldDistance*(1-1/factor));Object.assign(this.state,add(this.eye(),delta));this.placePivot();}else{this.state.distance=clamp(oldDistance/factor,120,200000);this.placeEye();}
      const projected=this.worldToScreen(point),depth=clamp(projected.depth,this.near,this.far),at=this.screenToWorld(nextAnchor,depth),delta=sub(point,at);Object.assign(this.state,add(this.eye(),delta));Object.assign(this.state,{pivotX:this.state.pivotX+delta.x,pivotY:this.state.pivotY+delta.y,pivotZ:this.state.pivotZ+delta.z});this.emit();
    }
    zoomBy(factor,anchor){this.transformBy({zoom:factor,anchor});}
    flyBy(right=0,down=0,forward=0){this.interact(false);const a=this.axes(),delta=add(mul(a.right,finite(right,0)),add(mul(a.down,finite(down,0)),mul(a.forward,finite(forward,0))));Object.assign(this.state,add(this.eye(),delta));this.state.mode='fly';this.placePivot();this.emit();}
    setFlightInput({right=0,down=0,forward=0,boost=false}={}){
      if(this.destroyed)return;const input=vec(clamp(finite(right,0),-1,1),clamp(finite(down,0),-1,1),clamp(finite(forward,0),-1,1));if(!input.x&&!input.y&&!input.z){if(this.transition?.type==='flight'){this.cancel();this.emit();}return;}
      if(this.transition?.type==='flight'){this.flightInput=input;this.boost=boost;return;}this.interact(false);this.state.mode='fly';this.flightInput=input;this.boost=boost;let last=this.now();const ticket=this.serial;this.transition={type:'flight'};
      const tick=()=>{if(ticket!==this.serial||this.destroyed)return;const now=this.now(),dt=Math.min(.05,Math.max(0,(now-last)/1000));last=now;this.manualUntil=now+4000;const i=this.flightInput,n=Math.max(1,Math.hypot(i.x,i.y,i.z)),speed=(this.boost?1400:550)*dt/n,a=this.axes(),delta=add(mul(a.right,i.x*speed),add(mul(a.down,i.y*speed),mul(a.forward,i.z*speed)));Object.assign(this.state,add(this.eye(),delta));this.placePivot();this.emit();this.frame=this.raf(tick);};this.emit();this.frame=this.raf(tick);
    }
    release(vx=0,vy=0){if(this.reducedMotion()||Math.hypot(vx,vy)<.03)return;this.cancel();let x=clamp(vx,-1.5,1.5),y=clamp(vy,-1.5,1.5),last=this.now(),elapsed=0;const ticket=this.serial;this.transition={type:'momentum'};
      const tick=()=>{if(ticket!==this.serial||this.destroyed)return;const now=this.now(),dt=Math.min(32,Math.max(1,now-last));last=now;elapsed+=dt;x*=Math.exp(-dt/75);y*=Math.exp(-dt/75);const a=this.axes(),delta=add(mul(a.right,-x*dt*this.state.distance/this.focal),mul(a.down,-y*dt*this.state.distance/this.focal));Object.assign(this.state,add(this.eye(),delta));this.placePivot();if(elapsed>=300||Math.hypot(x,y)<.01){this.transition=null;this.frame=null;this.emit();}else{this.emit();this.frame=this.raf(tick);}};this.frame=this.raf(tick);
    }
    growthFor(ids){const rows=[...ids].map(id=>this.positions.get(String(id))).filter(Boolean);if(!rows.length)return false;const a=this.axes(),p=this.pivot(),vertical=this.fov/2,horizontal=Math.atan(Math.tan(vertical)*this.viewport.width/this.viewport.height),tan=Math.tan(Math.min(vertical,horizontal))*.8;let d=this.state.distance;for(const row of rows){const q=sub(this.centre(row),p),z=dot(q,a.forward);d=Math.max(d,(Math.max(Math.abs(dot(q,a.right)),Math.abs(dot(q,a.down)))+140)/tan-z,this.near-z+140);}return this.setGrowthScale(this.focusDistance/d);}
    setGrowthScale(factor){if(!Number.isFinite(factor)||factor<=0)return false;const d=clamp(this.focusDistance/factor,120,200000);if(d<=this.state.distance*1.02){this.pendingGrowth=null;return false;}this.pendingGrowth=d;return this.flushGrowth();}
    flushGrowth(){if(this.pendingGrowth===null||this.transition||this.now()<this.manualUntil||this.destroyed)return false;const d=this.pendingGrowth;this.pendingGrowth=null;return this.move(this.targetPose(this.pivot(),d),{animate:true,type:'growth',duration:2200});}
    restoreState(value){if(!value||!['x','y'].every(k=>typeof value[k]==='number'&&Number.isFinite(value[k])))return false;
      if(value.version==='space3d-v1'){if(!['z','pivotX','pivotY','pivotZ','yaw','pitch','distance'].every(k=>typeof value[k]==='number'&&Number.isFinite(value[k])))return false;this.manualUntil=0;this.pendingGrowth=null;return this.move({...value,mode:value.mode==='fly'?'fly':'orbit',focusNodeId:typeof value.focusNodeId==='string'?value.focusNodeId:'root'},{animate:false});}
      if(typeof value.scale!=='number'||!Number.isFinite(value.scale)||value.scale<=0)return false;const pivot=vec(value.x,value.y,0);this.manualUntil=0;this.pendingGrowth=null;return this.move({...this.targetPose(pivot,clamp(this.focusDistance/value.scale,120,200000),0,0),mode:'orbit',focusNodeId:typeof value.focusNodeId==='string'?value.focusNodeId:'root'},{animate:false});
    }
    destroy(){this.cancel();this.destroyed=true;this.pendingGrowth=null;}
  }
  return {CameraController:SpaceCameraController,SpaceCameraController,LegacyCameraController,ease};
});
