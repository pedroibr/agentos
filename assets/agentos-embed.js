(function(){
  const $ = (root, sel) => root.querySelector(sel);

  function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
  function uuidv4(){ return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c=>((crypto.getRandomValues(new Uint8Array(1))[0]&15)^(c==='x'?0:8)).toString(16)); }

  function getCtxFromURL(allowed) {
    const u = new URL(window.location.href);
    const out = {};
    (allowed||[]).forEach(k => {
      const v = u.searchParams.get(k);
      if (v) out[k] = v;
    });
    return out;
  }
  function getAnonId(){ try{ const k='agentos_anon_id'; let v=localStorage.getItem(k); if(!v){ v=uuidv4(); localStorage.setItem(k,v);} return v; }catch(_){ return ''; } }

  document.querySelectorAll('.agentos-wrap').forEach((wrap) => {
    const els = {
      start: $('.agentos-bar', wrap).querySelector('.agentos-start'),
      stop:  $('.agentos-bar', wrap).querySelector('.agentos-stop'),
      save:  $('.agentos-bar', wrap).querySelector('.agentos-save'),
      status: $('.agentos-bar', wrap).querySelector('.agentos-status'),
      audio: $('.agentos-audio', wrap),
      textUI: $('.agentos-text-ui', wrap),
      textInput: $('.agentos-text-input', wrap),
      textSend: $('.agentos-text-send', wrap),
      log: $('.agentos-transcript .agentos-transcript-log', wrap),
      panel: $('.agentos-transcript', wrap)
    };

    const modeAttr = wrap.getAttribute('data-mode');
    const mode = modeAttr || (AgentOSCfg?.default_mode || 'voice'); // voice | text | both
    if (mode === 'text' || mode === 'both') {
      els.textUI.style.display = 'block';
    }

    let pc, micStream, dc, SESSION_ID=null;
    let activeModel = '', activeVoice = '';
    const transcript = []; // [{role,text}]

    function setStatus(t){ els.status.textContent = t; }
    function scrollToBottom(){ els.panel.scrollTop = els.panel.scrollHeight; }
    function addBubble(role, text=''){
      const div = document.createElement('div');
      div.className = 'msg ' + (role==='assistant'?'assistant':'user');
      div.style.cssText = 'max-width:90%;margin:6px 0;padding:10px 12px;border-radius:12px;white-space:pre-wrap;'+
                          (role==='assistant'?'background:#f6f8ff;border:1px solid #e7ebff;margin-left:auto':'background:#f7f7f7;border:1px solid #eee');
      div.innerHTML = escapeHtml(text);
      els.log.appendChild(div); scrollToBottom();
      return div;
    }

    // Assistant streaming
    let assistantBuffer = '';
    let currentAssistantBubble = null;
    let assistantIdleTimer = null;
    function updateAssistant(delta){
      if (!currentAssistantBubble) currentAssistantBubble = addBubble('assistant','');
      assistantBuffer += delta;
      currentAssistantBubble.innerHTML += escapeHtml(delta);
      scrollToBottom();
      clearTimeout(assistantIdleTimer);
      assistantIdleTimer = setTimeout(commitAssistant, 1200);
    }
    function commitAssistant(){
      if (assistantBuffer.trim()){
        transcript.push({ role:'assistant', text: assistantBuffer });
      }
      assistantBuffer = ''; currentAssistantBubble = null;
    }

    function handleRealtimeEvent(ev){
      if (!ev || !ev.type) return;
      if (ev.type === 'response.output_text.delta' && typeof ev.delta === 'string') {
        updateAssistant(ev.delta); return;
      }
      if (ev.type === 'response.output_text.done' || ev.type === 'response.completed') {
        commitAssistant(); return;
      }
      if (ev.type === 'response.message' && Array.isArray(ev.content)) {
        const text = ev.content.filter(p => p.type === 'output_text' && typeof p.text === 'string').map(p => p.text).join('');
        if (text) { updateAssistant(text); commitAssistant(); }
        return;
      }
      if (typeof ev.delta === 'string' && ev.delta) { updateAssistant(ev.delta); return; }
    }

    async function getToken() {
      const res = await fetch(AgentOSCfg.rest + '/realtime-token', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-WP-Nonce': AgentOSCfg.nonce},
        body: JSON.stringify({ post_id: AgentOSCfg.post_id, ctx: getCtxFromURL(AgentOSCfg.context_params) })
      });
      if (!res.ok) throw new Error('Token request failed');
      return res.json();
    }

    function waitForOpen(channel, timeoutMs = 7000) {
      return new Promise((resolve, reject) => {
        if (channel.readyState === 'open') return resolve();
        const t = setTimeout(()=>reject(new Error('DataChannel timeout')), timeoutMs);
        channel.onopen = ()=>{ clearTimeout(t); resolve(); };
        channel.onclose = ()=>{ clearTimeout(t); reject(new Error('DataChannel closed')); };
      });
    }

    // (A) VOICE path (WebRTC + optional client STT)
    let recog = null, recogActive = false;
    function startUserSTT(){
      if (mode === 'text') return; // no mic mode
      const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
      if (!SR) return;
      recog = new SR(); recog.lang = 'en-US'; recog.continuous = true; recog.interimResults = true;
      let bubble = null, finalText = '';
      recog.onresult = (e) => {
        let interim = '';
        for (let i=e.resultIndex; i<e.results.length; i++){
          const r = e.results[i];
          if (r.isFinal) finalText += r[0].transcript;
          else interim += r[0].transcript;
        }
        if (!bubble) bubble = addBubble('user','');
        bubble.innerHTML = escapeHtml((finalText + interim).trim());
        scrollToBottom();
        if (finalText.trim()){
          transcript.push({ role:'user', text: finalText.trim() });
          finalText = ''; bubble = null;
        }
      };
      recog.onerror = () => {};
      recog.onend = () => { if (recogActive) try{recog.start();}catch(_){} };
      try{ recog.start(); recogActive = true; }catch(_){}
    }
    function stopUserSTT(){ try{ recogActive=false; if (recog) recog.stop(); }catch(_){} }

    // (B) TEXT send path (send text as conversation.item to the realtime DC)
    function sendTextMessage(txt) {
      const msg = (txt||'').trim();
      if (!msg) return;
      addBubble('user', msg);
      transcript.push({ role:'user', text: msg });
      if (dc && dc.readyState === 'open') {
        dc.send(JSON.stringify({
          type: 'conversation.item.create',
          item: { type:'message', role:'user', content:[{ type:'input_text', text: msg }] }
        }));
        dc.send(JSON.stringify({ type: 'response.create' }));
      }
    }

    els.textSend?.addEventListener('click', () => {
      sendTextMessage(els.textInput.value);
      els.textInput.value = '';
    });

    // main connect
    els.start.addEventListener('click', async () => {
      try {
        els.start.disabled = true; els.save.disabled = true;
        activeModel = ''; activeVoice = '';
        window._agentosModel = ''; window._agentosVoice = '';
        SESSION_ID = uuidv4();

        // Microphone only if voice|both
        if (mode !== 'text') {
          setStatus('Requesting mic…');
          micStream = await navigator.mediaDevices.getUserMedia({ audio:true });
          startUserSTT();
        }

        setStatus('Creating session…');
        const tk = await getToken();
        const { client_secret, model, voice, lesson, user_prompt } = tk;
        if (!client_secret) throw new Error('Missing ephemeral token');
        activeModel = model || '';
        activeVoice = voice || '';
        window._agentosModel = activeModel;
        window._agentosVoice = activeVoice;

        // WebRTC peer
        pc = new RTCPeerConnection();
        pc.ontrack = (e) => { els.audio.srcObject = e.streams[0]; };
        if (mode !== 'text') micStream.getTracks().forEach(t => pc.addTrack(t, micStream));

        dc = pc.createDataChannel('oai-events');
        dc.onmessage = (e) => { try{ handleRealtimeEvent(JSON.parse(e.data)); }catch{} };

        const offer = await pc.createOffer({ offerToReceiveAudio: (mode!=='text')?1:0, offerToReceiveVideo: 0 });
        await pc.setLocalDescription(offer);

        setStatus('Connecting model…');
        const sdpResponse = await fetch('https://api.openai.com/v1/realtime?model='+encodeURIComponent(model||'gpt-realtime-mini-2025-10-06'), {
          method:'POST',
          headers:{ 'Authorization':'Bearer '+client_secret, 'Content-Type':'application/sdp' },
          body: offer.sdp
        });
        if (!sdpResponse.ok) throw new Error('Realtime handshake failed');
        const answerSdp = await sdpResponse.text();
        await pc.setRemoteDescription({ type:'answer', sdp: answerSdp });
        await waitForOpen(dc);

        setStatus('Connected ✅');

        // Inject optional user_prompt/lesson context
        const contextBlocks = [];
        if (user_prompt) {
          contextBlocks.push(`User Prompt:\n${user_prompt}`);
        }
        if (lesson && (lesson.title || lesson.story || (lesson.questions||[]).length)) {
          const lines = [];
          lines.push(`Lesson Title: ${lesson.title||'(untitled)'}`);
          lines.push(`Story:\n${lesson.story||'(none)'}\n`);
          if ((lesson.questions||[]).length) {
            lines.push('Comprehension Questions:');
            (lesson.questions||[]).forEach((q,i)=>lines.push(`${i+1}. ${q}`));
          }
          contextBlocks.push(lines.join('\n'));
        }
        if (contextBlocks.length) {
          dc.send(JSON.stringify({
            type:'conversation.item.create',
            item:{ type:'message', role:'user', content:[{type:'input_text', text: contextBlocks.join('\n\n')}] }
          }));
          dc.send(JSON.stringify({ type:'response.create' }));
        }

        els.stop.disabled = false; els.save.disabled = true;
        // Enable save after some content arrives
        setTimeout(()=>{ els.save.disabled = false; }, 1500);

      } catch (e) {
        console.error(e);
        setStatus('Error: '+e.message);
        els.start.disabled = false;
        stopUserSTT();
      }
    });

    els.stop.addEventListener('click', () => {
      try {
        if (pc) pc.close();
        if (micStream) micStream.getTracks().forEach(t => t.stop());
      } finally {
        stopUserSTT();
        pc = null; dc = null; micStream = null;
        els.stop.disabled = true;
        els.start.disabled = false;
        els.save.disabled = transcript.length === 0;
        setStatus('Disconnected.');
      }
    });

    els.save.addEventListener('click', async () => {
      try {
        // commit assistant buffer
        if (typeof commitAssistant === 'function') commitAssistant();
        const payload = {
          post_id: AgentOSCfg.post_id,
          session_id: SESSION_ID,
          anon_id: getAnonId(),
          model: activeModel || '',
          voice: activeVoice || '',
          user_agent: navigator.userAgent,
          transcript
        };
        const res = await fetch(AgentOSCfg.rest + '/transcript-db', {
          method:'POST',
          headers:{'Content-Type':'application/json','X-WP-Nonce':AgentOSCfg.nonce},
          body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data?.message || 'Save failed');
        setStatus('Transcript saved (id '+data.id+')');
      } catch (e) {
        console.error(e);
        setStatus('Save error');
      }
    });
  });

})();
