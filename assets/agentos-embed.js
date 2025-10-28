(function(){
  const $ = (selOrRoot, maybeRoot) => {
    if (typeof selOrRoot === 'string') {
      const ctx = maybeRoot && typeof maybeRoot.querySelector === 'function' ? maybeRoot : document;
      return ctx ? ctx.querySelector(selOrRoot) : null;
    }
    if (selOrRoot && typeof selOrRoot.querySelector === 'function') {
      return selOrRoot.querySelector(maybeRoot);
    }
    return null;
  };

  function escapeHtml(s){ return (s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
  function uuidv4(){ return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c=>((crypto.getRandomValues(new Uint8Array(1))[0]&15)^(c==='x'?0:8)).toString(16)); }
  const LOG_PREFIX = '[AgentOS]';
  function createLogger(enabled){
    const safe = (method) => (...args) => {
      if (!enabled) return;
      try {
        const fn = console[method] || console.log;
        fn.call(console, LOG_PREFIX, ...args);
      } catch (_) {}
    };
    return {
      info: safe('info'),
      error: safe('error'),
      debug: safe('debug'),
    };
  }

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
  const timestampFormatter = (typeof Intl !== 'undefined' && Intl.DateTimeFormat)
    ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' })
    : null;
  function formatTimestamp(value){
    if (!value || typeof value !== 'string') return '';
    const normalized = value.replace(' ', 'T');
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return value;
    if (timestampFormatter) {
      try { return timestampFormatter.format(date); } catch (_) {}
    }
    return date.toLocaleString();
  }

  document.querySelectorAll('.agentos-wrap').forEach((wrap) => {
    let cfg = {};
    try {
      cfg = JSON.parse(wrap.dataset.config || '{}');
    } catch (_) {
      cfg = {};
    }

    const transcriptAttr = wrap.getAttribute('data-show-transcript');
    const restBase = (cfg.rest || '').replace(/\/$/, '');
    const nonce = cfg.nonce || '';
    const postId = cfg.post_id || null;
    const agentId = cfg.agent_id || '';
    const contextParams = Array.isArray(cfg.context_params) ? cfg.context_params : [];
    const loggingEnabled = !!cfg.logging;
    const transcriptEnabled = transcriptAttr !== '0' && transcriptAttr !== 'false' && cfg.show_transcript !== false;
    const analysisEnabled = !!cfg.analysis_enabled;
    const requireSubscription = !!cfg.require_subscription;
    const sessionTokenCapDefault = parseInt(cfg.session_token_cap || '0', 10) || 0;
    const { info: logInfo, error: logError, debug: logDebug } = createLogger(loggingEnabled);

    if (!restBase || !postId || !agentId) {
      return;
    }

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
    const canRenderTranscript = transcriptEnabled && !!els.log && !!els.panel;
    const historyUI = {
      container: $('.agentos-history__content', wrap),
      placeholder: $('.agentos-history__placeholder', wrap)
    };
    const historyPlaceholderDefault = historyUI.placeholder ? historyUI.placeholder.textContent : '';

    const modeAttr = wrap.getAttribute('data-mode');
    const mode = modeAttr || cfg.mode || 'voice'; // voice | text | both
    if ((mode === 'text' || mode === 'both') && els.textUI) {
      els.textUI.classList.add('is-visible');
    }

    let pc, micStream, dc, SESSION_ID=null;
    let activeModel = '', activeVoice = '';
    let activeSubscription = '';
    let activeSessionCap = sessionTokenCapDefault;
    let sessionActive = false;
    const transcript = []; // [{role,text}]
    const historyState = {
      loading: false
    };
    let sessionStats = createSessionStats();

    function createSessionStats() {
      return {
        tokensRealtime: 0,
        tokensText: 0,
        tokensTotal: 0,
        startTime: 0
      };
    }

    function resetSessionStats() {
      sessionStats = createSessionStats();
    }

    function estimateTokens(text) {
      if (!text) return 0;
      const stripped = String(text).trim();
      if (!stripped) return 0;
      return Math.max(1, Math.ceil(stripped.length / 4));
    }

    function recordTokens(kind, text) {
      const tokens = estimateTokens(text);
      if (!tokens) return;
      sessionStats.tokensTotal += tokens;
      if (kind === 'text') {
        sessionStats.tokensText += tokens;
      } else {
        sessionStats.tokensRealtime += tokens;
      }
    }

    function currentDurationSeconds() {
      if (!sessionStats.startTime) {
        return 0;
      }
      return Math.max(0, Math.round((Date.now() - sessionStats.startTime) / 1000));
    }

    function buildUsagePayload(status, reason) {
      if (!SESSION_ID) {
        return null;
      }
      return {
        session_id: SESSION_ID,
        agent_id: agentId,
        post_id: postId,
        subscription_slug: activeSubscription || '',
        anon_id: getAnonId(),
        tokens_realtime: sessionStats.tokensRealtime,
        tokens_text: sessionStats.tokensText,
        tokens_total: sessionStats.tokensTotal,
        duration_seconds: currentDurationSeconds(),
        status: status,
        reason: reason || '',
        mode: mode,
        _wpnonce: nonce
      };
    }

    function sendUsageUpdate(status, reason, final = false) {
      const payload = buildUsagePayload(status, reason);
      if (!payload) return Promise.resolve();
      const url = restBase + '/usage/session';
      const body = JSON.stringify(payload);
      if (!final && navigator.sendBeacon) {
        try {
          const blob = new Blob([body], { type: 'application/json' });
          navigator.sendBeacon(url, blob);
          return Promise.resolve();
        } catch (err) {
          logError('Beacon usage update failed', err);
        }
      }
      return fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce
        },
        body,
        keepalive: !final
      }).catch(err => logError('Usage update failed', err));
    }

    function setStatus(t){
      if (!els.status) return;
      els.status.textContent = t;
      if (!t) {
        delete els.status.dataset.status;
        return;
      }
      const normalized = t.toLowerCase();
      if (normalized.includes('record')) {
        els.status.dataset.status = 'recording';
      } else {
        delete els.status.dataset.status;
      }
    }
    function scrollToBottom(){
      if (!canRenderTranscript) return;
      els.panel.scrollTop = els.panel.scrollHeight;
    }
    function addBubble(role, text=''){
      if (!canRenderTranscript) return null;
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
      if (!canRenderTranscript) {
        assistantBuffer += delta;
        clearTimeout(assistantIdleTimer);
        assistantIdleTimer = setTimeout(commitAssistant, 1200);
        return;
      }
      if (!currentAssistantBubble) currentAssistantBubble = addBubble('assistant','');
      assistantBuffer += delta;
      if (currentAssistantBubble) {
        currentAssistantBubble.innerHTML += escapeHtml(delta);
        scrollToBottom();
      }
      clearTimeout(assistantIdleTimer);
      assistantIdleTimer = setTimeout(commitAssistant, 1200);
    }
    function commitAssistant(){
      const trimmed = assistantBuffer.trim();
      if (trimmed){
        const tokenKind = (mode === 'text') ? 'text' : 'realtime';
        recordTokens(tokenKind, trimmed);
        transcript.push({ role:'assistant', text: trimmed });
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
      const anonId = getAnonId();
      const res = await fetch(restBase + '/realtime-token', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-WP-Nonce': nonce},
        body: JSON.stringify({
          post_id: postId,
          agent_id: agentId,
          ctx: getCtxFromURL(contextParams),
          anon_id: anonId,
          session_id: SESSION_ID
        })
      });
      if (!res.ok) {
        let raw = '';
        let parsedMessage = '';
        let parsedCode = '';
        try {
          raw = await res.text();
          try {
            const parsed = JSON.parse(raw);
            parsedMessage = parsed?.message || '';
            parsedCode = parsed?.code || '';
            raw = parsed;
          } catch (_) {}
        } catch (_) {}
        logError('Token request failed', res.status, raw);
        const detail = parsedMessage || `Token request failed (${res.status})`;
        const codeHint = parsedCode ? ` (${parsedCode})` : '';
        throw new Error(detail + codeHint);
      }
      const json = await res.json();
      logDebug('Token payload', json);
      activeSubscription = json.subscription || '';
      activeSessionCap = json.session_cap || sessionTokenCapDefault;
      if (Array.isArray(json.warnings) && json.warnings.length) {
        logInfo('Subscription warnings', json.warnings);
      }
      if (json.session_id) {
        SESSION_ID = json.session_id;
      }
      return json;
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
        if (!bubble && canRenderTranscript) bubble = addBubble('user','');
        if (bubble) {
          bubble.innerHTML = escapeHtml((finalText + interim).trim());
          scrollToBottom();
        }
        if (finalText.trim()){
          const trimmed = finalText.trim();
          recordTokens('realtime', trimmed);
          transcript.push({ role:'user', text: trimmed });
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
      recordTokens('text', msg);
      if (canRenderTranscript) {
        addBubble('user', msg);
      }
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
      if (!els.textInput) return;
      sendTextMessage(els.textInput.value);
      els.textInput.value = '';
    });

    // main connect
    els.start.addEventListener('click', async () => {
      try {
        logInfo('Start clicked', { agentId, postId, mode });
        els.start.disabled = true; if (els.save) els.save.disabled = true;
        activeModel = ''; activeVoice = '';
        window._agentosModel = ''; window._agentosVoice = '';
        SESSION_ID = uuidv4();
        resetSessionStats();
        sessionStats.startTime = Date.now();
        sessionActive = false;

        // Microphone only if voice|both
        if (mode !== 'text') {
          setStatus('Requesting mic…');
          micStream = await navigator.mediaDevices.getUserMedia({ audio:true });
          startUserSTT();
        }

        setStatus('Creating session…');
        const tk = await getToken();
        const { client_secret, model, voice, user_prompt } = tk;
        if (!client_secret) throw new Error('Missing ephemeral token');
        activeModel = model || '';
        activeVoice = voice || '';
        window._agentosModel = activeModel;
        window._agentosVoice = activeVoice;

        // WebRTC peer
        pc = new RTCPeerConnection();
        pc.ontrack = (e) => { els.audio.srcObject = e.streams[0]; };
        if (mode !== 'text') micStream.getTracks().forEach(t => pc.addTrack(t, micStream));
        pc.onconnectionstatechange = () => logInfo('Peer connection state', pc.connectionState);
        pc.oniceconnectionstatechange = () => logDebug('ICE connection state', pc.iceConnectionState);

        dc = pc.createDataChannel('oai-events');
        dc.onmessage = (e) => { try{ handleRealtimeEvent(JSON.parse(e.data)); }catch(err){ logError('Failed to parse realtime event', err); } };
        dc.onopen = () => logInfo('Data channel open');
        dc.onclose = () => logInfo('Data channel closed');

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

        // Inject optional user-supplied context
        const contextBlocks = [];
        if (user_prompt) {
          contextBlocks.push(`User Prompt:\n${user_prompt}`);
        }
        if (contextBlocks.length) {
          dc.send(JSON.stringify({
            type:'conversation.item.create',
            item:{ type:'message', role:'user', content:[{type:'input_text', text: contextBlocks.join('\n\n')}] }
          }));
          dc.send(JSON.stringify({ type:'response.create' }));
        }

        els.stop.disabled = false; if (els.save) els.save.disabled = true;
        // Enable save after some content arrives
        if (els.save) {
          setTimeout(()=>{ els.save.disabled = false; }, 1500);
        }
        logInfo('Session ready', { model: activeModel, voice: activeVoice });
        sessionActive = true;
        sendUsageUpdate('running', 'started');

      } catch (e) {
        logError('Start failed', e);
        setStatus('Error: '+e.message);
        els.start.disabled = false;
        stopUserSTT();
        sendUsageUpdate('pending', 'error');
        SESSION_ID = null;
        resetSessionStats();
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
        if (els.save) els.save.disabled = transcript.length === 0;
        setStatus('Disconnected.');
        if (typeof commitAssistant === 'function') commitAssistant();
        sessionActive = false;
        sendUsageUpdate('final', 'ended', true);
      }
    });

    els.save?.addEventListener('click', async () => {
      try {
        // commit assistant buffer
        if (typeof commitAssistant === 'function') commitAssistant();
        const payload = {
          post_id: postId,
          agent_id: agentId,
          session_id: SESSION_ID,
          anon_id: getAnonId(),
          model: activeModel || '',
          voice: activeVoice || '',
          subscription_slug: activeSubscription || '',
          user_agent: navigator.userAgent,
          transcript,
          tokens_realtime: sessionStats.tokensRealtime,
          tokens_text: sessionStats.tokensText,
          tokens_total: sessionStats.tokensTotal,
          duration_seconds: currentDurationSeconds()
        };
        const res = await fetch(restBase + '/transcript-db', {
          method:'POST',
          headers:{'Content-Type':'application/json','X-WP-Nonce':nonce},
          body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data?.message || 'Save failed');
        setStatus('Transcript saved (id '+data.id+')');
        if (analysisEnabled) loadHistory();
        await sendUsageUpdate('final', 'saved', true);
        SESSION_ID = null;
        resetSessionStats();
        sessionActive = false;
      } catch (e) {
        logError('Save failed', e);
        setStatus('Save error: ' + (e && e.message ? e.message : ''));
      }
    });

    const statusLabels = {
      queued: 'Queued',
      running: 'Running',
      succeeded: 'Completed',
      failed: 'Failed',
      idle: 'Idle'
    };

    function renderHistory(items) {
      if (!historyUI.container) return;
      historyUI.container.innerHTML = '';

      if (!Array.isArray(items) || items.length === 0) {
        if (historyUI.placeholder) {
          historyUI.placeholder.textContent = historyPlaceholderDefault || 'Save a session to see feedback summaries here.';
          historyUI.placeholder.style.display = '';
        }
        return;
      }

      if (historyUI.placeholder) {
        historyUI.placeholder.style.display = 'none';
      }

      items.forEach(item => {
        const block = document.createElement('div');
        block.className = 'agentos-history__item';

        const meta = document.createElement('div');
        meta.className = 'agentos-history__meta';
        const bits = [];
        const createdLabel = formatTimestamp(item.created_at);
        if (createdLabel) bits.push('Saved ' + createdLabel);
        const status = item.analysis_status || 'idle';
        const completedLabel = formatTimestamp(item.analysis_completed_at);
        if (status === 'succeeded' && completedLabel) {
          bits.push('Analyzed ' + completedLabel);
        } else {
          bits.push(statusLabels[status] || status);
        }
        meta.textContent = bits.join(' · ');
        block.appendChild(meta);

        const feedback = document.createElement('div');
        feedback.className = 'agentos-history__feedback';
        if (item.analysis_feedback) {
          feedback.textContent = item.analysis_feedback;
        } else if (status === 'failed' && item.analysis_error) {
          feedback.textContent = 'Analysis failed: ' + item.analysis_error;
        } else if (status === 'queued' || status === 'running') {
          feedback.textContent = 'Analysis in progress…';
        } else {
          feedback.textContent = 'Analysis not requested yet.';
        }
        block.appendChild(feedback);

        historyUI.container.appendChild(block);
      });
    }

    async function loadHistory() {
      if (!analysisEnabled || !historyUI.container || historyState.loading) return;
      historyState.loading = true;
      try {
        const params = new URLSearchParams({
          post_id: String(postId),
          agent_id: agentId,
          limit: '5',
          anon_id: getAnonId()
        });
        const res = await fetch(restBase + '/transcript-db?' + params.toString(), {
          method: 'GET',
          headers: {'X-WP-Nonce': nonce}
        });
        if (!res.ok) {
          throw new Error('History request failed (' + res.status + ')');
        }
        const json = await res.json();
        renderHistory(json);
      } catch (err) {
        logError('History load failed', err);
        if (historyUI.placeholder) {
          historyUI.placeholder.textContent = historyPlaceholderDefault || 'Unable to load previous feedback right now.';
          historyUI.placeholder.style.display = '';
        }
      } finally {
        historyState.loading = false;
      }
    }

    if (analysisEnabled) {
      loadHistory();
    }
  });

  window.addEventListener('beforeunload', () => {
    if (!sessionActive || !SESSION_ID || !sessionStats.startTime) {
      return;
    }
    sendUsageUpdate('pending', 'aborted');
  });

})();
