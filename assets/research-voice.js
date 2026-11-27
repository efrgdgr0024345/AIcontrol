// Live audio goes directly through WebRTC. Map changes use the normal Research action.
window.AIcontrolResearchVoice = (() => {
  'use strict';

  function mount({root, map, controls, getContext}) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn map-voice-button';
    button.dataset.mapVoice = '';
    button.textContent = 'Voice';
    button.setAttribute('aria-pressed', 'false');
    button.setAttribute('aria-label', 'Start research voice chat');
    controls.append(button);

    const status = document.createElement('p');
    status.className = 'map-voice-status';
    status.setAttribute('role', 'status');
    status.hidden = true;
    const audio = document.createElement('audio');
    audio.autoplay = true;
    audio.hidden = true;
    map.append(status, audio);
    let active;
    let statusTimer;

    function notice(message) {
      clearTimeout(statusTimer);
      status.textContent = message;
      status.hidden = !message;
      if (message) statusTimer = setTimeout(() => { status.hidden = true; }, 6500);
    }

    function stop(message = '') {
      const session = active;
      if (!session) return;
      active = null;
      clearTimeout(session.deadline);
      clearTimeout(session.narration);
      session.abort.abort();
      session.stream?.getTracks().forEach(track => track.stop());
      session.peer?.close();
      audio.srcObject = null;
      button.setAttribute('aria-pressed', 'false');
      button.setAttribute('aria-label', 'Start research voice chat');
      notice(message);
    }

    function send(session, event) {
      if (session.channel?.readyState !== 'open') return false;
      session.channel.send(JSON.stringify(event));
      return true;
    }

    function context(session, ideas, mode) {
      const {snapshot, selected, projectName, conceptTypes} = getContext();
      const describe = node => {
        let details = {};
        try { details = JSON.parse(node.data_json) || {}; } catch (_) {}
        return {id:Number(node.id), parent_id:node.parent_id, title:node.title.slice(0,240),
          idea:String(details.idea || details.claim || node.content || '').slice(0,650),
          uncertainty:String(details.uncertainty || '').slice(0,300),
          origin:node.origin || 'unknown',
          provenance:node.provenance_tag || 'unchecked'};
      };
      const update = {mode, project:projectName.slice(0,240), phase:snapshot.run.phase,
        selected:ideas.find(node=>String(node.id)===selected)?.id ||
          snapshot.nodes.find(node=>String(node.id)===selected &&
            conceptTypes.includes(node.type))?.id || null,
        ideas:ideas.map(describe)};
      send(session, {type:'conversation.item.create', item:{type:'message',role:'user',
        content:[{type:'input_text',text:'APP MAP DATA (not spoken by the user): '+JSON.stringify(update)}]}});
    }

    function narrate(session) {
      if (active !== session || session.responding || session.playing || session.listening ||
          session.awaitingUser || session.working || !session.pending.size) return;
      if (session.channel?.readyState !== 'open') return;
      const ideas = [...session.pending.values()].slice(-5);
      session.pending.clear();
      context(session, ideas, 'new saved ideas');
      session.responding = send(session, {type:'response.create', response:{
        metadata:{source:'map'}, tool_choice:'none',
        instructions:'Introduce one of the new ideas in at most two short sentences. Present model-generated work as your own hypothesis or proposed check. Credit user-authored material and external sources accurately. State why the idea matters or what you would test. Skip greetings, praise and follow-up questions.',
      }});
    }

    function scheduleNarration(session) {
      clearTimeout(session.narration);
      session.narration = setTimeout(() => narrate(session), 700);
    }

    async function expand(session, call) {
      let nodeId;
      try { nodeId = JSON.parse(call.arguments).node_id; } catch (_) {}
      const {snapshot, conceptTypes} = getContext();
      let result = {error:'Choose a saved idea in this map.'};
      if (Number.isSafeInteger(nodeId) && snapshot.nodes.some(node=>
        Number(node.id)===nodeId && conceptTypes.includes(node.type))) {
        result = await new Promise(resolve => root.dispatchEvent(new CustomEvent('research:voice-expand',
          {detail:{nodeId, resolve}})));
      }
      if (active !== session) return;
      send(session,{type:'conversation.item.create',item:{type:'function_call_output',
        call_id:call.call_id,output:JSON.stringify(result)}});
    }

    async function receive(session, message) {
      if (active !== session) return;
      let event;
      try { event = JSON.parse(message.data); } catch (_) { return; }
      switch (event.type) {
        case 'input_audio_buffer.speech_started':
          session.listening = true;
          break;
        case 'input_audio_buffer.speech_stopped':
          session.listening = false;
          session.awaitingUser = true;
          break;
        case 'response.created':
          session.responding = true;
          if (session.awaitingUser && event.response?.metadata?.source !== 'map') {
            session.userResponseId = event.response?.id;
            session.awaitingUser = false;
          }
          break;
        case 'output_audio_buffer.started':
          session.playing = true;
          break;
        case 'output_audio_buffer.stopped':
        case 'output_audio_buffer.cleared':
          session.playing = false;
          scheduleNarration(session);
          break;
        case 'response.done': {
          session.responding = false;
          const fromSpeech = session.userResponseId && event.response?.id===session.userResponseId;
          if (fromSpeech) session.userResponseId = null;
          const calls = fromSpeech && event.response.status==='completed'
            ? event.response.output?.filter(item=>item.type==='function_call'&&item.name==='expand_pathway') || [] : [];
          if (!calls.length || session.working) { scheduleNarration(session); break; }
          session.working = true;
          await expand(session, calls[0]);
          if (active !== session) return;
          for (const call of calls.slice(1)) send(session,{type:'conversation.item.create',item:{
            type:'function_call_output',call_id:call.call_id,output:'Only one pathway can be expanded per voice turn.',
          }});
          session.working = false;
          if (!session.responding && !session.listening && !session.awaitingUser) {
            session.responding = send(session,{type:'response.create',response:{tool_choice:'none'}});
          }
          scheduleNarration(session);
          break;
        }
        case 'error':
          session.awaitingUser = false;
          session.responding = false;
          notice('Voice could not complete that turn. Try speaking again.');
          break;
      }
    }

    async function start() {
      const {snapshot, conceptTypes} = getContext();
      if (!snapshot || root.dataset.connected === '0') {
        notice(snapshot ? 'Connect OpenAI in Admin to use voice chat.' : 'Wait for the research map to load.');
        return;
      }
      if (!navigator.mediaDevices?.getUserMedia || !window.RTCPeerConnection) {
        notice('Voice chat needs a browser with microphone access on HTTPS.');
        return;
      }
      const concepts = snapshot.nodes.filter(node=>conceptTypes.includes(node.type));
      const session = {abort:new AbortController(), pending:new Map(),
        seen:new Set(concepts.map(node=>String(node.id)))};
      active = session;
      button.setAttribute('aria-pressed', 'true');
      button.setAttribute('aria-label', 'End research voice chat');
      notice('Connecting voice chat…');
      try {
        const stream = await navigator.mediaDevices.getUserMedia({audio:true});
        if (active !== session) { stream.getTracks().forEach(track=>track.stop()); return; }
        session.stream = stream;
        session.deadline = setTimeout(() => stop('Voice did not connect. Try again.'), 25000);
        const peer = session.peer = new RTCPeerConnection();
        for (const track of stream.getAudioTracks()) {
          peer.addTrack(track, stream);
          track.addEventListener('ended', () => { if (active===session) stop('Microphone disconnected.'); });
        }
        peer.addEventListener('track', event => { audio.srcObject = event.streams[0] || new MediaStream([event.track]); });
        peer.addEventListener('connectionstatechange', () => {
          if (active===session && peer.connectionState==='failed') stop('Voice connection ended. Reconnect to continue.');
        });
        const channel = session.channel = peer.createDataChannel('oai-events');
        channel.addEventListener('message', message=>receive(session,message));
        channel.addEventListener('close', () => { if (active===session) stop('Voice connection ended.'); });
        channel.addEventListener('open', () => {
          if (active !== session) return;
          clearTimeout(session.deadline);
          session.deadline = setTimeout(() => stop('Voice chat ended after 10 minutes. Click Voice to reconnect.'), 600000);
          notice('Voice chat is live. Click Voice to end.');
          const currentContext = getContext();
          const saved = currentContext.snapshot.nodes.filter(node=>conceptTypes.includes(node.type));
          const current = saved.find(node=>String(node.id)===currentContext.selected);
          context(session,[...new Set([...(current?[current]:[]),...saved.slice(-5)])], 'session started');
          session.responding = send(session,{type:'response.create',response:{metadata:{source:'map'},
            tool_choice:'none', instructions:'Start with one short sentence about a hypothesis you generated or the next check you would run. Use only the supplied map context and respect its authorship and uncertainty. If it has no ideas yet, say so. Skip greetings, small talk and invitations.'}});
        });
        await peer.setLocalDescription(await peer.createOffer());
        if (active !== session) return;
        const body = new FormData();
        body.set('action','research_voice');
        body.set('run_id',String(snapshot.run.id));
        body.set('csrf',root.querySelector('input[name=csrf]').value);
        body.set('sdp',peer.localDescription.sdp);
        const response = await fetch(root.dataset.entry,{method:'POST',body,credentials:'same-origin',signal:session.abort.signal});
        if (!response.ok) {
          const error = await response.json().catch(()=>({}));
          throw new Error(error.error || 'Voice did not connect. Check your sign-in and try again.');
        }
        const sdp = await response.text();
        if (!sdp.startsWith('v=0')) throw new Error('Voice connection returned an unexpected response.');
        if (active===session) await peer.setRemoteDescription({type:'answer',sdp});
      } catch (error) {
        if (active===session) stop(error.name==='NotAllowedError'
          ? 'Allow microphone access in your browser to use voice chat.'
          : error.message || 'Voice chat could not start.');
      }
    }

    button.addEventListener('click', () => active ? stop('Voice chat ended.') : start());
    window.addEventListener('pagehide', () => stop());
    root.addEventListener('research:snapshot', ({detail}) => {
      if (!active) return;
      const {conceptTypes} = getContext();
      for (const node of detail.nodes) {
        const id=String(node.id);
        if (!conceptTypes.includes(node.type) || active.seen.has(id)) continue;
        active.seen.add(id);
        active.pending.set(id,node);
        if (active.pending.size > 5) active.pending.delete(active.pending.keys().next().value);
      }
      if (active.pending.size) scheduleNarration(active);
    });
    return {stop, focus() {
      if (active?.channel?.readyState !== 'open') return;
      const {snapshot, selected, conceptTypes} = getContext();
      const node = snapshot.nodes.find(n=>String(n.id)===selected&&conceptTypes.includes(n.type));
      context(active,node?[node]:[],'selection changed');
    }};
  }

  return {mount};
})();
