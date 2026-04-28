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
      warn: safe('warn'),
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
    const viewerIsAuthenticated = !!cfg.viewer_is_authenticated;
    const contextParams = Array.isArray(cfg.context_params) ? cfg.context_params : [];
    const loggingEnabled = !!cfg.logging;
    const transcriptEnabled = transcriptAttr !== '0' && transcriptAttr !== 'false' && cfg.show_transcript !== false;
    const analysisEnabled = !!cfg.analysis_enabled;
    const requireSubscription = !!cfg.require_subscription;
    const sessionTokenCapDefault = parseInt(cfg.session_token_cap || '0', 10) || 0;
    const transcriptionHint = String(cfg.transcription_hint || '').trim();
    const { info: logInfo, error: logError, debug: logDebug, warn: logWarn } = createLogger(loggingEnabled);

    if (!restBase || !postId || !agentId) {
      return;
    }

    const els = {
      shell: $('.agentos-shell', wrap),
      sidebarToggle: $('.agentos-sidebar-toggle', wrap),
      sidebarDismiss: $('.agentos-sidebar__dismiss', wrap),
      sidebarBackdrop: $('.agentos-sidebar-backdrop', wrap),
      start: $('.agentos-bar', wrap).querySelector('.agentos-start'),
      stop:  $('.agentos-bar', wrap).querySelector('.agentos-stop'),
      save:  $('.agentos-bar', wrap).querySelector('.agentos-save'),
      status: $('.agentos-bar', wrap).querySelector('.agentos-status'),
      audio: $('.agentos-audio', wrap),
      textUI: $('.agentos-text-ui', wrap),
      textInput: $('.agentos-text-input', wrap),
      textRecord: $('.agentos-text-record', wrap),
      textSend: $('.agentos-text-send', wrap),
      log: $('.agentos-transcript .agentos-transcript-log', wrap),
      panel: $('.agentos-transcript', wrap),
      transcriptHint: $('.agentos-transcript__hint-text', wrap),
      sessionList: $('.agentos-session-list', wrap),
      currentSessionButton: $('.agentos-sidebar__new', wrap),
      workspaceTitle: $('.agentos-voice-stage__workspace-title', wrap),
      transcriptStatus: $('.agentos-transcript__status', wrap),
      transcriptTitle: $('.agentos-transcript__title', wrap),
      sessionSummary: $('.agentos-session-meta__summary', wrap),
      feedbackContent: $('.agentos-feedback__content', wrap)
    };
    const canRenderTranscript = transcriptEnabled && !!els.log && !!els.panel;
    if (!transcriptEnabled) {
      logInfo('Transcript disabled', { dataAttr: transcriptAttr, config: cfg.show_transcript });
    } else if (!canRenderTranscript) {
      logWarn('Transcript UI missing; bubbles will not render', { hasLog: !!els.log, hasPanel: !!els.panel });
    }
    const feedbackPlaceholderDefault = els.feedbackContent ? els.feedbackContent.textContent : '';
    const transcriptHintDefault = els.transcriptHint ? els.transcriptHint.textContent : feedbackPlaceholderDefault;
    const transcriptTail = canRenderTranscript ? document.createElement('div') : null;
    let transcriptObserver = null;

    if (transcriptTail) {
      transcriptTail.className = 'agentos-transcript-tail';
      els.log.appendChild(transcriptTail);
    }

    const modeAttr = wrap.getAttribute('data-mode');
    const mode = modeAttr || cfg.mode || 'voice'; // voice | text | both
    if ((mode === 'text' || mode === 'both') && els.textUI) {
      els.textUI.classList.add('is-visible');
    }

    let pc, micStream, dc, SESSION_ID=null;
    let activeModel = '', activeVoice = '';
    let activeSubscription = '';
    let activeSessionCap = sessionTokenCapDefault;
    let activeContext = {};
    let textRequestInFlight = false;
    let inputRecorder = null;
    let inputRecorderStream = null;
    let inputRecorderChunks = [];
    let inputRecorderMime = '';
    let inputRecorderActive = false;
    let inputRecorderDiscard = false;
    let sessionActive = false;
    let bootstrapResponsePending = false;
    const transcript = []; // [{role,text}]
    const historyState = {
      loading: false
    };
    let savedSessions = [];
    let selectedSessionId = null;
    let sessionStats = createSessionStats();
    const compactLayoutQuery = window.matchMedia ? window.matchMedia('(max-width: 1100px)') : null;
    let sidebarOpen = compactLayoutQuery ? !compactLayoutQuery.matches : true;
    let lastCompactLayout = compactLayoutQuery ? compactLayoutQuery.matches : false;
    let layoutRaf = 0;
    const startButtonDefaultLabel = els.start ? (els.start.textContent || '').trim() : '';

    function setStartButtonLabel(nextLabel) {
      if (!els.start) return;
      const label = els.start.querySelector('.agentos-btn__label');
      if (label) {
        label.textContent = nextLabel;
      } else {
        els.start.textContent = nextLabel;
      }
    }

    function isCompactLayout() {
      return compactLayoutQuery ? compactLayoutQuery.matches : window.innerWidth <= 1100;
    }

    function getShellMinHeight() {
      const width = window.innerWidth || document.documentElement.clientWidth || 0;
      if (width <= 720) return 440;
      if (width <= 1100) return 520;
      return 620;
    }

    function syncShellHeight() {
      if (!els.shell) return;
      const width = window.innerWidth || document.documentElement.clientWidth || 0;
      if (width <= 720) {
        wrap.style.removeProperty('--agentos-shell-height');
        return;
      }
      const viewportHeight = Math.round(
        (window.visualViewport && window.visualViewport.height) ||
        window.innerHeight ||
        document.documentElement.clientHeight ||
        0
      );
      const wrapRect = wrap.getBoundingClientRect();
      const parent = wrap.parentElement;
      const minHeight = getShellMinHeight();
      const bottomGap = 0;
      const viewportAvailable = Math.max(0, Math.floor(viewportHeight - Math.max(wrapRect.top, 0) - bottomGap));
      let targetHeight = Math.max(minHeight, viewportAvailable);

      if (parent && parent !== document.body) {
        const parentRect = parent.getBoundingClientRect();
        const shellRect = els.shell.getBoundingClientRect();
        if (parentRect.height > shellRect.height + 32) {
          const parentBoundedHeight = viewportAvailable > 0
            ? Math.min(parentRect.height, viewportAvailable)
            : parentRect.height;
          targetHeight = Math.max(minHeight, parentBoundedHeight);
        }
      }

      if (!Number.isFinite(targetHeight) || targetHeight <= 0) {
        wrap.style.removeProperty('--agentos-shell-height');
        return;
      }

      wrap.style.setProperty('--agentos-shell-height', Math.round(targetHeight) + 'px');
    }

    function queueLayoutSync() {
      if (layoutRaf) {
        cancelAnimationFrame(layoutRaf);
      }
      layoutRaf = requestAnimationFrame(() => {
        layoutRaf = 0;
        syncShellHeight();
      });
    }

    function updateSidebarControls() {
      if (!els.sidebarToggle) return;
      const expanded = sidebarOpen ? 'true' : 'false';
      els.sidebarToggle.setAttribute('aria-expanded', expanded);
      els.sidebarToggle.setAttribute(
        'aria-label',
        sidebarOpen ? 'Hide conversations sidebar' : 'Show conversations sidebar'
      );
    }

    function setSidebarOpen(nextOpen) {
      sidebarOpen = !!nextOpen;
      wrap.dataset.sidebarOpen = sidebarOpen ? '1' : '0';
      updateSidebarControls();
      queueLayoutSync();
    }

    function syncResponsiveLayout(forceReset = false) {
      const compact = isCompactLayout();
      if (forceReset || compact !== lastCompactLayout) {
        sidebarOpen = !compact;
        lastCompactLayout = compact;
      }
      setSidebarOpen(sidebarOpen);
    }

    function closeSidebarIfCompact() {
      if (isCompactLayout()) {
        setSidebarOpen(false);
      }
    }

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

    function setSessionState(state) {
      wrap.dataset.sessionState = state || 'idle';
    }

    function sessionDisplayTitle(item) {
      if (!item || !item.created_at) return 'Saved session';
      return formatTimestamp(item.created_at) || 'Saved session';
    }

    function sessionSummaryText(item) {
      if (!item) return 'Current live session';
      const bits = [];
      if (item.analysis_status) bits.push(item.analysis_status);
      if (item.tokens_total) bits.push(item.tokens_total + ' tokens');
      if (item.duration_seconds) bits.push(item.duration_seconds + 's');
      return bits.join(' · ') || 'Saved session';
    }

    function renderFeedback(item) {
      if (!els.feedbackContent) return;
      els.feedbackContent.innerHTML = '';
      const content = document.createElement('p');
      content.className = item && item.analysis_feedback ? 'agentos-feedback__body' : 'agentos-feedback__placeholder';
      if (item && item.analysis_feedback) {
        content.textContent = item.analysis_feedback;
      } else if (item && item.analysis_status === 'failed' && item.analysis_error) {
        content.textContent = 'Analysis failed: ' + item.analysis_error;
      } else if (item && (item.analysis_status === 'queued' || item.analysis_status === 'running')) {
        content.textContent = 'Analysis in progress…';
      } else {
        content.textContent = feedbackPlaceholderDefault || 'Save a session to review transcript analysis here.';
      }
      els.feedbackContent.appendChild(content);
    }

    function setFeedbackMode(mode) {
      wrap.dataset.feedbackMode = mode === 'panel' ? 'panel' : 'inline';
      if (mode !== 'panel' && els.transcriptHint) {
        els.transcriptHint.textContent = transcriptHintDefault || 'Save a session to review transcript analysis here.';
      }
    }

    function clearTranscriptLog(options = {}) {
      if (!canRenderTranscript) return;
      cancelScheduledScroll();
      Array.from(els.log.querySelectorAll('.msg')).forEach((node) => node.remove());
      if (options.scrollMode === 'top') {
        autoScrollPinned = false;
        els.log.scrollTop = 0;
        return;
      }
      if (options.suppressScroll) return;
      autoScrollPinned = true;
      scheduleScrollToBottom('clearTranscript', { force: true });
    }

    function renderTranscriptEntries(entries, options = {}) {
      if (!canRenderTranscript) return;
      const scrollMode = options.scrollMode || 'bottom';
      const instant = options.instant !== false;
      suppressTranscriptMutationScroll();
      clearTranscriptLog({ scrollMode, suppressScroll: true });
      if (Array.isArray(entries)) {
        entries.forEach((entry) => {
          if (!entry || !entry.role) return;
          addBubble(entry.role, entry.text || '', null, {
            forceRender: true,
            suppressScroll: true
          });
        });
      }
      if (scrollMode === 'top') {
        autoScrollPinned = false;
        cancelScheduledScroll();
        els.log.scrollTop = 0;
        return;
      }
      autoScrollPinned = true;
      scheduleScrollToBottom('renderTranscriptEntries', { force: true, instant });
    }

    function renderSessionList() {
      if (!els.sessionList) return;
      els.sessionList.innerHTML = '';

      const currentButton = document.createElement('button');
      currentButton.type = 'button';
      currentButton.className = 'agentos-session-item' + (!selectedSessionId ? ' is-active' : '');
      currentButton.innerHTML =
        '<span class="agentos-session-item__title">Current session</span>' +
        '<span class="agentos-session-item__meta">' + (sessionActive ? 'Live now' : 'Ready to start') + '</span>' +
        '<span class="agentos-session-item__summary">' + (transcript.length ? transcript.length + ' messages in progress' : 'No active transcript yet') + '</span>';
      currentButton.addEventListener('click', activateCurrentSessionView);
      els.sessionList.appendChild(currentButton);

      if (!savedSessions.length) {
        const empty = document.createElement('p');
        empty.className = 'agentos-session-list__empty';
        empty.textContent = viewerIsAuthenticated
          ? 'Saved sessions will appear here.'
          : 'Sign in to view saved sessions.';
        els.sessionList.appendChild(empty);
        return;
      }

      savedSessions.forEach((item) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'agentos-session-item' + (selectedSessionId === String(item.id) ? ' is-active' : '');
        button.innerHTML =
          '<span class="agentos-session-item__title">' + escapeHtml(sessionDisplayTitle(item)) + '</span>' +
          '<span class="agentos-session-item__meta">' + escapeHtml(sessionSummaryText(item)) + '</span>' +
          '<span class="agentos-session-item__summary">' + escapeHtml((item.analysis_feedback || '').slice(0, 96) || 'Open to review transcript and feedback') + '</span>';
        button.addEventListener('click', () => activateSavedSession(item.id));
        els.sessionList.appendChild(button);
      });
    }

    function activateCurrentSessionView(options = {}) {
      const wasViewingSavedSession = !!selectedSessionId;
      selectedSessionId = null;
      setFeedbackMode('inline');
      if (els.workspaceTitle) els.workspaceTitle.textContent = 'Current session';
      if (els.transcriptTitle) els.transcriptTitle.textContent = 'Conversation';
      if (els.transcriptStatus) els.transcriptStatus.textContent = sessionActive ? 'Live' : 'Ready';
      if (els.sessionSummary) els.sessionSummary.textContent = 'Current live session';
      renderFeedback(null);
      const needsTranscriptRender = canRenderTranscript && (
        !!options.forceRender ||
        wasViewingSavedSession ||
        transcript.length === 0 ||
        !els.log.querySelector('.msg')
      );
      if (needsTranscriptRender) {
        renderTranscriptEntries(transcript, {
          scrollMode: 'bottom',
          instant: options.instant !== false
        });
      }
      renderSessionList();
      closeSidebarIfCompact();
    }

    function supportsInputRecorder() {
      return !!(mode === 'text' && navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
    }

    function updateTextRecordButton() {
      if (!els.textRecord) return;
      if (!supportsInputRecorder()) {
        els.textRecord.disabled = true;
        els.textRecord.textContent = 'Record unavailable';
        return;
      }
      els.textRecord.disabled = textRequestInFlight;
      els.textRecord.textContent = inputRecorderActive ? 'Stop recording' : 'Record';
    }

    function appendTranscribedText(text) {
      if (!els.textInput) return;
      const current = els.textInput.value || '';
      const trimmed = String(text || '').trim();
      if (!trimmed) return;
      els.textInput.value = current
        ? current.replace(/\s*$/, '') + '\n' + trimmed
        : trimmed;
      els.textInput.focus();
    }

    function cleanupInputRecorder(discard = false) {
      inputRecorderDiscard = discard;
      try {
        if (inputRecorder && inputRecorder.state !== 'inactive') {
          inputRecorder.stop();
        }
      } catch (_) {}
      try {
        if (inputRecorderStream) {
          inputRecorderStream.getTracks().forEach((track) => track.stop());
        }
      } catch (_) {}
      inputRecorder = null;
      inputRecorderStream = null;
      inputRecorderChunks = [];
      inputRecorderMime = '';
      inputRecorderActive = false;
      updateTextRecordButton();
    }

    async function transcribeRecordedAudio(blob) {
      const filename = blob.type && blob.type.indexOf('mp4') !== -1 ? 'recording.m4a' : 'recording.webm';
      const formData = new FormData();
      formData.append('post_id', String(postId));
      formData.append('agent_id', agentId);
      formData.append('anon_id', getAnonId());
      if (navigator.language) {
        formData.append('language', String(navigator.language).split('-')[0]);
      }
      formData.append('audio', blob, filename);

      const res = await fetch(restBase + '/text-transcription', {
        method: 'POST',
        headers: {'X-WP-Nonce': nonce},
        body: formData
      });
      const data = await res.json();
      if (!res.ok) {
        throw new Error(data?.message || ('Transcription failed (' + res.status + ')'));
      }
      return String(data.text || '').trim();
    }

    function preferredRecorderMimeType() {
      if (!window.MediaRecorder || typeof window.MediaRecorder.isTypeSupported !== 'function') {
        return '';
      }
      const candidates = [
        'audio/webm;codecs=opus',
        'audio/webm',
        'audio/mp4',
      ];
      for (const candidate of candidates) {
        if (window.MediaRecorder.isTypeSupported(candidate)) {
          return candidate;
        }
      }
      return '';
    }

    async function toggleTextRecorder() {
      if (!supportsInputRecorder() || textRequestInFlight) {
        return;
      }

      if (inputRecorderActive) {
        setStatus('Transcribing…');
        cleanupInputRecorder();
        return;
      }

      try {
        inputRecorderChunks = [];
        inputRecorderMime = preferredRecorderMimeType();
        inputRecorderStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        inputRecorder = inputRecorderMime ? new MediaRecorder(inputRecorderStream, { mimeType: inputRecorderMime }) : new MediaRecorder(inputRecorderStream);
        inputRecorderMime = inputRecorder.mimeType || inputRecorderMime || 'audio/webm';
        inputRecorder.ondataavailable = (event) => {
          if (event.data && event.data.size > 0) {
            inputRecorderChunks.push(event.data);
          }
        };
        inputRecorder.onstop = async () => {
          const blob = inputRecorderChunks.length ? new Blob(inputRecorderChunks, { type: inputRecorderMime || 'audio/webm' }) : null;
          inputRecorder = null;
          if (inputRecorderStream) {
            inputRecorderStream.getTracks().forEach((track) => track.stop());
          }
          inputRecorderStream = null;
          inputRecorderActive = false;
          updateTextRecordButton();
          const discardResult = inputRecorderDiscard;
          inputRecorderDiscard = false;
          if (discardResult) {
            inputRecorderChunks = [];
            setStatus('Ready');
            return;
          }
          if (!blob || !blob.size) {
            setStatus('Ready');
            return;
          }
          try {
            if (els.textRecord) els.textRecord.disabled = true;
            const text = await transcribeRecordedAudio(blob);
            appendTranscribedText(text);
            setStatus('Ready');
          } catch (err) {
            logError('Text transcription failed', err);
            setStatus('Error: ' + (err && err.message ? err.message : 'Unable to transcribe audio'));
          } finally {
            inputRecorderChunks = [];
            updateTextRecordButton();
          }
        };
        inputRecorder.start();
        inputRecorderActive = true;
        updateTextRecordButton();
        setStatus('Listening…');
      } catch (err) {
        logError('Text recorder failed', err);
        cleanupInputRecorder(true);
        setStatus('Error: ' + (err && err.message ? err.message : 'Unable to access microphone'));
      }
    }

    function teardownRealtimeSession() {
      bootstrapResponsePending = false;
      try {
        if (pc) pc.close();
      } catch (_) {}
      try {
        if (micStream) micStream.getTracks().forEach((track) => track.stop());
      } catch (_) {}
      stopUserSTT();
      pc = null;
      dc = null;
      micStream = null;
    }

    function setMicTracksEnabled(enabled) {
      if (!micStream) return;
      try {
        micStream.getAudioTracks().forEach((track) => {
          track.enabled = !!enabled;
        });
      } catch (_) {}
    }

    function finalizeBootstrapResponse() {
      if (!bootstrapResponsePending) return;
      bootstrapResponsePending = false;
      setMicTracksEnabled(true);
    }

    function sendRealtimeHistoryMessage(role, text) {
      if (!dc || dc.readyState !== 'open') return;
      const normalizedRole = role === 'assistant' ? 'assistant' : 'user';
      const normalizedText = String(text || '').trim();
      if (!normalizedText) return;
      dc.send(JSON.stringify({
        type: 'conversation.item.create',
        item: {
          type: 'message',
          role: normalizedRole,
          content: [
            normalizedRole === 'assistant'
              ? { type: 'output_text', text: normalizedText }
              : { type: 'input_text', text: normalizedText }
          ]
        }
      }));
    }

    function restoreRealtimeConversation(userPrompt) {
      if (!dc || dc.readyState !== 'open') return;
      const transcriptHasHistory = Array.isArray(transcript) && transcript.length > 0;
      transcript.forEach((entry) => {
        if (!entry || !entry.role || !entry.text) return;
        sendRealtimeHistoryMessage(entry.role, entry.text);
      });
      if (!transcriptHasHistory && userPrompt) {
        sendRealtimeHistoryMessage('user', 'User Prompt:\n' + userPrompt);
        dc.send(JSON.stringify({ type:'response.create' }));
      }
    }

    function resetCurrentSession(options = {}) {
      teardownRealtimeSession();
      cleanupInputRecorder(true);
      transcript.length = 0;
      assistantBuffer = '';
      currentAssistantBubble = null;
      userBuffer = '';
      currentUserBubble = null;
      lastAssistantText = '';
      SESSION_ID = null;
      activeModel = '';
      activeVoice = '';
      activeSubscription = '';
      activeSessionCap = sessionTokenCapDefault;
      activeContext = {};
      textRequestInFlight = false;
      sessionActive = false;
      resetSessionStats();
      if (els.textInput && options.clearInput !== false) {
        els.textInput.value = '';
        els.textInput.disabled = false;
      }
      if (els.textSend) els.textSend.disabled = false;
      if (els.start) els.start.disabled = false;
      setStartButtonLabel(startButtonDefaultLabel || 'Start voice');
      if (els.stop) els.stop.disabled = true;
      if (els.save) els.save.disabled = true;
      setStatus(options.status || 'Ready');
      if (options.activateView !== false) {
        activateCurrentSessionView();
      } else {
        renderSessionList();
      }
    }

    function activateSavedSession(sessionId) {
      const item = savedSessions.find((entry) => String(entry.id) === String(sessionId));
      if (!item) {
        activateCurrentSessionView();
        return;
      }
      selectedSessionId = String(item.id);
      setFeedbackMode('panel');
      if (els.workspaceTitle) els.workspaceTitle.textContent = sessionDisplayTitle(item);
      if (els.transcriptTitle) els.transcriptTitle.textContent = 'Saved transcript';
      if (els.transcriptStatus) els.transcriptStatus.textContent = item.analysis_status || 'Saved';
      if (els.sessionSummary) els.sessionSummary.textContent = sessionSummaryText(item);
      renderFeedback(item);
      renderTranscriptEntries(item.transcript || [], { scrollMode: 'top', instant: true });
      renderSessionList();
      closeSidebarIfCompact();
    }

    function setStatus(t){
      if (!els.status) return;
      els.status.textContent = t;
      if (!t) {
        delete els.status.dataset.status;
        setSessionState('idle');
        return;
      }
      const normalized = t.toLowerCase();
      if (normalized.includes('record')) {
        els.status.dataset.status = 'recording';
        setSessionState('recording');
      } else if (normalized.includes('connect')) {
        els.status.dataset.status = 'connected';
        setSessionState('connected');
      } else if (normalized.includes('request') || normalized.includes('creating')) {
        delete els.status.dataset.status;
        setSessionState('connecting');
      } else {
        delete els.status.dataset.status;
        setSessionState('idle');
      }
      if (!selectedSessionId && els.transcriptStatus) {
        els.transcriptStatus.textContent = t || 'Ready';
      }
    }
    let scrollRaf = 0;
    let scrollFlushTimer = 0;
    let scrollTarget = 0;
    let scrollSource = 'manual';
    let scrollForcePending = false;
    let scrollAnimationActive = false;
    let autoScrollPinned = true;
    let lastScrollScheduleAt = 0;
    let transcriptMutationScrollSuppressed = false;
    let transcriptMutationScrollResumeTimer = 0;
    const STREAM_SCROLL_THROTTLE_MS = 120;
    const DEFAULT_SCROLL_THROTTLE_MS = 40;
    const SCROLL_BOTTOM_THRESHOLD = 72;

    function logScrollMetrics(source) {
      if (!canRenderTranscript || !loggingEnabled) return;
      logDebug('Transcript scroll metrics', {
        source,
        scrollTop: els.log.scrollTop,
        clientHeight: els.log.clientHeight,
        scrollHeight: els.log.scrollHeight,
        childCount: els.log.children.length
      });
    }

    function isNearTranscriptBottom(threshold = SCROLL_BOTTOM_THRESHOLD) {
      if (!canRenderTranscript) return true;
      const remaining = els.log.scrollHeight - (els.log.scrollTop + els.log.clientHeight);
      return remaining <= threshold;
    }

    function updateAutoScrollPinned() {
      autoScrollPinned = isNearTranscriptBottom();
    }

    function suppressTranscriptMutationScroll() {
      transcriptMutationScrollSuppressed = true;
      if (transcriptMutationScrollResumeTimer) {
        clearTimeout(transcriptMutationScrollResumeTimer);
      }
      transcriptMutationScrollResumeTimer = setTimeout(() => {
        transcriptMutationScrollResumeTimer = 0;
        transcriptMutationScrollSuppressed = false;
      }, 0);
    }

    function applyScrollPosition(nextTop) {
      if (!canRenderTranscript) return;
      scrollAnimationActive = true;
      els.log.scrollTop = nextTop;
    }

    function cancelScheduledScroll(options = {}) {
      if (scrollFlushTimer) {
        clearTimeout(scrollFlushTimer);
        scrollFlushTimer = 0;
      }
      if (scrollRaf) {
        cancelAnimationFrame(scrollRaf);
        scrollRaf = 0;
      }
      scrollAnimationActive = false;
      scrollForcePending = false;
      lastScrollScheduleAt = 0;
      if (options.updatePinned) {
        updateAutoScrollPinned();
      }
    }

    function finishScrollAnimation() {
      cancelScheduledScroll();
      updateAutoScrollPinned();
      logScrollMetrics(scrollSource);
    }

    function animateScrollStep() {
      if (!canRenderTranscript) return;
      const current = els.log.scrollTop;
      const diff = scrollTarget - current;
      if (Math.abs(diff) <= 1.5) {
        applyScrollPosition(scrollTarget);
        finishScrollAnimation();
        return;
      }
      const step = Math.sign(diff) * Math.max(1.5, Math.min(Math.abs(diff) * 0.22, 34));
      applyScrollPosition(current + step);
      scrollRaf = requestAnimationFrame(animateScrollStep);
    }

    function flushScrollToBottom(source = 'manual', force = false, instant = false) {
      if (!canRenderTranscript) return;
      if (!force && !autoScrollPinned) return;
      if (scrollFlushTimer) {
        clearTimeout(scrollFlushTimer);
        scrollFlushTimer = 0;
      }
      scrollSource = source;
      scrollTarget = Math.max(0, els.log.scrollHeight - els.log.clientHeight);
      if (instant) {
        applyScrollPosition(scrollTarget);
        finishScrollAnimation();
        return;
      }
      if (Math.abs(scrollTarget - els.log.scrollTop) <= 1.5) {
        applyScrollPosition(scrollTarget);
        finishScrollAnimation();
        return;
      }
      if (!scrollRaf) {
        scrollRaf = requestAnimationFrame(animateScrollStep);
      }
    }

    function scheduleScrollToBottom(source = 'manual', options = {}){
      if (!canRenderTranscript) return;
      const force = !!options.force;
      const instant = !!options.instant;
      const streaming = !!options.streaming;
      if (!force && !autoScrollPinned) return;
      scrollSource = source;
      scrollForcePending = scrollForcePending || force;
      if (instant) {
        flushScrollToBottom(source, scrollForcePending, true);
        scrollForcePending = false;
        return;
      }
      const throttleMs = streaming ? STREAM_SCROLL_THROTTLE_MS : DEFAULT_SCROLL_THROTTLE_MS;
      const now = Date.now();
      const elapsed = now - lastScrollScheduleAt;
      if (elapsed >= throttleMs) {
        lastScrollScheduleAt = now;
        flushScrollToBottom(source, scrollForcePending);
        scrollForcePending = false;
        return;
      }
      if (scrollFlushTimer) {
        clearTimeout(scrollFlushTimer);
      }
      scrollFlushTimer = setTimeout(() => {
        scrollFlushTimer = 0;
        lastScrollScheduleAt = Date.now();
        flushScrollToBottom(scrollSource, scrollForcePending);
        scrollForcePending = false;
      }, throttleMs - elapsed);
    }

    function addBubble(role, text='', beforeEl, options = {}){
      if (!canRenderTranscript) return null;
      if (selectedSessionId && !options.forceRender) return null;
      const div = document.createElement('div');
      div.className = 'msg ' + (role==='assistant'?'assistant':'user');
      div.innerHTML = escapeHtml(text);
      const insertTarget = transcriptTail && transcriptTail.parentNode === els.log ? transcriptTail : null;
      if (beforeEl && beforeEl.parentNode === els.log) {
        els.log.insertBefore(div, beforeEl);
      } else if (insertTarget) {
        els.log.insertBefore(div, insertTarget);
      } else {
        els.log.appendChild(div);
      }
      if (!options.suppressScroll) {
        scheduleScrollToBottom('addBubble', { force: !!options.forceRender });
      }
      return div;
    }

    // Assistant streaming
    let assistantBuffer = '';
    let currentAssistantBubble = null;
    let assistantIdleTimer = null;
    let lastAssistantBubble = null;
    let lastAssistantAt = 0;
    function updateAssistant(delta){
      if (!canRenderTranscript) {
        assistantBuffer += delta;
        clearTimeout(assistantIdleTimer);
        assistantIdleTimer = setTimeout(commitAssistant, 1200);
        return;
      }
      if (!currentAssistantBubble) {
        currentAssistantBubble = addBubble('assistant','');
        lastAssistantBubble = currentAssistantBubble;
        lastAssistantAt = Date.now();
      }
      assistantBuffer += delta;
      if (currentAssistantBubble) {
        currentAssistantBubble.innerHTML += escapeHtml(delta);
        scheduleScrollToBottom('assistantDelta', { streaming: true });
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
        lastAssistantText = trimmed;
      }
      scheduleScrollToBottom('assistantCommit');
      assistantBuffer = ''; currentAssistantBubble = null;
    }

    // User streaming (realtime transcription)
    let userBuffer = '';
    let currentUserBubble = null;
    let userIdleTimer = null;
    let useRealtimeUserTranscript = false;
    let userTranscriptionState = { itemId: '', hasDelta: false };
    let lastAssistantText = '';
    function normalizeText(input){
      return String(input || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
    }
    function looksLikeTranscriptionHintLeak(text){
      const clean = normalizeText(text);
      const hintClean = normalizeText(transcriptionHint);
      if (!clean || !hintClean || hintClean.length < 12) return false;
      if (clean === hintClean) return true;
      if (clean.startsWith(hintClean) || clean.endsWith(hintClean)) {
        return Math.abs(clean.length - hintClean.length) <= 12;
      }
      return false;
    }
    function shouldIgnoreUserText(text){
      const clean = normalizeText(text);
      if (!clean || clean.length < 6) return false;
      if (looksLikeTranscriptionHintLeak(text)) {
        return true;
      }
      const assistantClean = normalizeText(lastAssistantText);
      if (!assistantClean) return false;
      return assistantClean.includes(clean) || clean.includes(assistantClean);
    }
    function updateUserFromRealtime(delta){
      if (!delta) return;
      if (!canRenderTranscript) {
        userBuffer += delta;
        clearTimeout(userIdleTimer);
        userIdleTimer = setTimeout(commitUserFromRealtime, 1200);
        return;
      }
      if (!currentUserBubble) {
        let beforeEl = null;
        const recentAssistant = lastAssistantBubble && (Date.now() - lastAssistantAt) < 8000;
        if (currentAssistantBubble) {
          beforeEl = currentAssistantBubble;
        } else if (recentAssistant) {
          beforeEl = lastAssistantBubble;
        }
        currentUserBubble = addBubble('user','', beforeEl);
      }
      userBuffer += delta;
      if (currentUserBubble) {
        currentUserBubble.innerHTML += escapeHtml(delta);
        scheduleScrollToBottom('userRealtimeDelta', { streaming: true });
      }
      clearTimeout(userIdleTimer);
      userIdleTimer = setTimeout(commitUserFromRealtime, 1200);
    }
    function commitUserFromRealtime(){
      const trimmed = userBuffer.trim();
      if (trimmed){
        if (shouldIgnoreUserText(trimmed)) {
          if (currentUserBubble && currentUserBubble.parentNode) {
            currentUserBubble.parentNode.removeChild(currentUserBubble);
          }
        } else {
          recordTokens('realtime', trimmed);
          transcript.push({ role:'user', text: trimmed });
        }
      }
      scheduleScrollToBottom('userCommit');
      userBuffer = ''; currentUserBubble = null;
    }

    function handleRealtimeEvent(ev){
      if (!ev || !ev.type) return;
      if (ev.type !== 'response.output_text.delta') {
        logDebug('Realtime event', { type: ev.type });
      }
      if (useRealtimeUserTranscript && String(ev.type).includes('input_audio_transcription')) {
        const itemId = ev.item_id || (ev.item && ev.item.id) || ev.id || '';
        if (itemId && itemId !== userTranscriptionState.itemId) {
          if (userBuffer) commitUserFromRealtime();
          userTranscriptionState = { itemId, hasDelta: false };
        }
        const delta = ev.delta || '';
        const finalText = ev.text || ev.transcript || '';
        if (delta) {
          userTranscriptionState.hasDelta = true;
          updateUserFromRealtime(delta);
        } else if (finalText) {
          if (!userTranscriptionState.hasDelta && !userBuffer && !currentUserBubble) {
            updateUserFromRealtime(finalText);
          }
        }
        if (String(ev.type).includes('completed') || String(ev.type).includes('done')) {
          commitUserFromRealtime();
        }
        return;
      }
      if (ev.type === 'response.output_text.delta' && typeof ev.delta === 'string') {
        if (userBuffer) commitUserFromRealtime();
        updateAssistant(ev.delta); return;
      }
      if (ev.type === 'response.output_text.done' || ev.type === 'response.completed') {
        commitAssistant();
        return;
      }
      if (ev.type === 'response.output_audio.done') {
        finalizeBootstrapResponse();
        return;
      }
      if (ev.type === 'response.output_item.done') {
        if (ev.item && ev.item.role === 'assistant' && ev.item.status === 'completed') {
          finalizeBootstrapResponse();
        }
        return;
      }
      if (ev.type === 'response.created') {
        if (userBuffer) commitUserFromRealtime();
        return;
      }
      if (ev.type === 'response.message' && Array.isArray(ev.content)) {
        if (assistantBuffer || currentAssistantBubble) {
          return;
        }
        const text = ev.content.filter(p => p.type === 'output_text' && typeof p.text === 'string').map(p => p.text).join('');
        if (text && normalizeText(text) !== normalizeText(lastAssistantText)) {
          updateAssistant(text);
          commitAssistant();
        }
        return;
      }
      if (ev.type && String(ev.type).startsWith('response.') && typeof ev.delta === 'string' && ev.delta) {
        updateAssistant(ev.delta); return;
      }
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
      activeContext = json.context && typeof json.context === 'object' ? json.context : {};
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
      if (useRealtimeUserTranscript) {
        logInfo('Browser STT disabled; using realtime transcription for user bubbles');
        return;
      }
      const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
      if (!SR) {
        logWarn('SpeechRecognition unavailable; user bubbles depend on browser STT support');
        return;
      }
      recog = new SR(); recog.lang = 'en-US'; recog.continuous = true; recog.interimResults = true;
      let bubble = null, finalText = '';
      recog.onresult = (e) => {
        if (useRealtimeUserTranscript) return;
        let interim = '';
        for (let i=e.resultIndex; i<e.results.length; i++){
          const r = e.results[i];
          if (r.isFinal) finalText += r[0].transcript;
          else interim += r[0].transcript;
        }
        if (!bubble && canRenderTranscript) bubble = addBubble('user','');
        if (bubble) {
          bubble.innerHTML = escapeHtml((finalText + interim).trim());
          scheduleScrollToBottom('browserStt', { streaming: true });
        }
        if (finalText.trim()){
          const trimmed = finalText.trim();
          logDebug('STT final', { text: trimmed });
          recordTokens('realtime', trimmed);
          transcript.push({ role:'user', text: trimmed });
          finalText = ''; bubble = null;
        }
      };
      recog.onerror = (e) => { logWarn('SpeechRecognition error', e); };
      recog.onend = () => {
        logDebug('SpeechRecognition ended');
        if (recogActive) try{recog.start();}catch(_){} };
      try{ recog.start(); recogActive = true; }catch(_){}
    }
    function stopUserSTT(){ try{ recogActive=false; if (recog) recog.stop(); }catch(_){} }

    async function sendTextMessage(txt) {
      const msg = (txt||'').trim();
      if (!msg) return;
      if (mode === 'text') {
        await sendTextMessageViaResponses(msg);
        return;
      }
      recordTokens('text', msg);
      logDebug('Text message sent', { length: msg.length });
      if (canRenderTranscript) {
        addBubble('user', msg);
      }
      transcript.push({ role:'user', text: msg });
      if (els.save) els.save.disabled = transcript.length === 0;
      if (dc && dc.readyState === 'open') {
        dc.send(JSON.stringify({
          type: 'conversation.item.create',
          item: { type:'message', role:'user', content:[{ type:'input_text', text: msg }] }
        }));
        dc.send(JSON.stringify({ type: 'response.create' }));
      } else {
        logWarn('DataChannel not open; text message not sent to realtime API');
      }
    }

    async function sendTextMessageViaResponses(msg) {
      if (textRequestInFlight) return;
      if (selectedSessionId) {
        activateCurrentSessionView();
      }
      if (!SESSION_ID) {
        SESSION_ID = uuidv4();
        resetSessionStats();
        sessionStats.startTime = Date.now();
      } else if (!sessionStats.startTime) {
        sessionStats.startTime = Date.now();
      }
      sessionActive = true;
      activeVoice = '';
      setSessionState('running');
      activateCurrentSessionView();
      if (canRenderTranscript) {
        addBubble('user', msg);
      }
      transcript.push({ role:'user', text: msg });
      if (els.save) els.save.disabled = transcript.length === 0;
      renderSessionList();
      closeSidebarIfCompact();
      textRequestInFlight = true;
      if (els.textSend) els.textSend.disabled = true;
      if (els.textInput) els.textInput.disabled = true;
      setStatus('Thinking…');

      try {
        const res = await fetch(restBase + '/text-response', {
          method: 'POST',
          headers: {'Content-Type':'application/json','X-WP-Nonce': nonce},
          body: JSON.stringify({
            post_id: postId,
            agent_id: agentId,
            session_id: SESSION_ID,
            anon_id: getAnonId(),
            ctx: getCtxFromURL(contextParams),
            message: msg,
            transcript,
            tokens_text: sessionStats.tokensText,
            tokens_total: sessionStats.tokensTotal,
            duration_seconds: currentDurationSeconds()
          })
        });
        const data = await res.json();
        if (!res.ok) {
          throw new Error(data?.message || ('Text response failed (' + res.status + ')'));
        }

        activeModel = data.model || activeModel;
        activeSubscription = data.subscription || activeSubscription;
        activeSessionCap = data.session_cap || activeSessionCap;
        activeContext = data.context && typeof data.context === 'object' ? data.context : activeContext;
        if (data.session_id) {
          SESSION_ID = data.session_id;
        }
        if (Array.isArray(data.warnings) && data.warnings.length) {
          logInfo('Subscription warnings', data.warnings);
        }
        if (data.usage && typeof data.usage === 'object') {
          sessionStats.tokensText = Math.max(sessionStats.tokensText, parseInt(data.usage.session_text_tokens || '0', 10) || 0);
          sessionStats.tokensTotal = Math.max(sessionStats.tokensTotal, parseInt(data.usage.session_total_tokens || '0', 10) || 0);
        }

        const assistantText = String(data.text || '').trim();
        if (assistantText) {
          if (canRenderTranscript) {
            addBubble('assistant', assistantText);
          }
          transcript.push({ role:'assistant', text: assistantText });
          lastAssistantText = assistantText;
          scheduleScrollToBottom('textResponse', { force: true });
        }

        setStatus('Ready');
        if (els.save) els.save.disabled = transcript.length === 0;
        renderSessionList();
      } catch (err) {
        logError('Text response failed', err);
        setStatus('Error: ' + (err && err.message ? err.message : 'Unable to send message'));
      } finally {
        textRequestInFlight = false;
        if (els.textSend) els.textSend.disabled = false;
        if (els.textInput) els.textInput.disabled = false;
        if (els.textInput) els.textInput.focus();
      }
    }

    els.textSend?.addEventListener('click', async () => {
      if (!els.textInput) return;
      await sendTextMessage(els.textInput.value);
      els.textInput.value = '';
    });

    els.textRecord?.addEventListener('click', async () => {
      await toggleTextRecorder();
    });

    // main connect
    els.start.addEventListener('click', async () => {
      const isResumeAttempt = !!SESSION_ID;
      try {
        logInfo('Start clicked', { agentId, postId, mode });
        els.start.disabled = true; if (els.save) els.save.disabled = true;
        activeModel = ''; activeVoice = '';
        window._agentosModel = ''; window._agentosVoice = '';
        if (!SESSION_ID) {
          SESSION_ID = uuidv4();
          resetSessionStats();
          sessionStats.startTime = Date.now();
        } else if (!sessionStats.startTime) {
          sessionStats.startTime = Date.now();
        }
        sessionActive = false;

        // Microphone only if voice|both
        if (mode !== 'text') {
          useRealtimeUserTranscript = true;
          setStatus('Requesting mic…');
          micStream = await navigator.mediaDevices.getUserMedia({
            audio: {
              echoCancellation: true,
              noiseSuppression: true,
              autoGainControl: true
            }
          });
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
        bootstrapResponsePending = !isResumeAttempt && transcript.length === 0 && !!String(user_prompt || '').trim();
        if (bootstrapResponsePending) {
          setMicTracksEnabled(false);
        } else {
          setMicTracksEnabled(true);
        }

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

        setStatus('Connected');

        restoreRealtimeConversation(user_prompt);

        els.stop.disabled = false; if (els.save) els.save.disabled = true;
        setStartButtonLabel(startButtonDefaultLabel || 'Start voice');
        // Enable save after some content arrives
        if (els.save) {
          setTimeout(()=>{ els.save.disabled = false; }, 1500);
        }
        logInfo('Session ready', { model: activeModel, voice: activeVoice });
        sessionActive = true;
        activateCurrentSessionView();
        sendUsageUpdate('running', isResumeAttempt ? 'resumed' : 'started');

      } catch (e) {
        logError('Start failed', e);
        setStatus('Error: '+e.message);
        els.start.disabled = false;
        stopUserSTT();
        sendUsageUpdate('pending', 'error');
        if (!isResumeAttempt) {
          SESSION_ID = null;
          resetSessionStats();
        }
        activateCurrentSessionView();
      }
    });

    els.stop.addEventListener('click', () => {
      try {
        if (userBuffer) commitUserFromRealtime();
        if (assistantBuffer) commitAssistant();
        if (pc) pc.close();
        if (micStream) micStream.getTracks().forEach(t => t.stop());
      } finally {
        teardownRealtimeSession();
        els.stop.disabled = true;
        els.start.disabled = false;
        setStartButtonLabel('Resume voice');
        if (els.save) els.save.disabled = transcript.length === 0;
        setStatus('Paused');
        sessionActive = false;
        activateCurrentSessionView();
        sendUsageUpdate('paused', 'stopped');
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
          context: activeContext || {},
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
        await sendUsageUpdate('final', 'saved', true);
        resetCurrentSession({ status: 'Ready', activateView: false });
        await loadHistory(String(data.id));
      } catch (e) {
        logError('Save failed', e);
        setStatus('Save error: ' + (e && e.message ? e.message : ''));
      }
    });

    async function loadHistory(preferredSessionId = null) {
      if (!els.sessionList || historyState.loading) return;
      if (!viewerIsAuthenticated) {
        savedSessions = [];
        selectedSessionId = null;
        renderSessionList();
        return;
      }

      historyState.loading = true;
      try {
        const params = new URLSearchParams({
          post_id: String(postId),
          agent_id: agentId,
          limit: '20'
        });
        const res = await fetch(restBase + '/transcript-db?' + params.toString(), {
          method: 'GET',
          headers: {'X-WP-Nonce': nonce}
        });
        if (!res.ok) {
          throw new Error('History request failed (' + res.status + ')');
        }
        const json = await res.json();
        savedSessions = Array.isArray(json) ? json : [];
        if (preferredSessionId) {
          activateSavedSession(preferredSessionId);
        } else if (selectedSessionId) {
          activateSavedSession(selectedSessionId);
        } else {
          renderSessionList();
        }
      } catch (err) {
        logError('History load failed', err);
        renderSessionList();
      } finally {
        historyState.loading = false;
      }
    }

    els.currentSessionButton?.addEventListener('click', () => {
      resetCurrentSession({ status: 'Ready' });
    });
    els.sidebarToggle?.addEventListener('click', () => {
      setSidebarOpen(!sidebarOpen);
    });
    els.sidebarDismiss?.addEventListener('click', () => {
      setSidebarOpen(false);
    });
    els.sidebarBackdrop?.addEventListener('click', () => {
      setSidebarOpen(false);
    });
    window.addEventListener('resize', () => {
      syncResponsiveLayout();
      queueLayoutSync();
    });
    window.visualViewport?.addEventListener('resize', queueLayoutSync);
    if (typeof ResizeObserver !== 'undefined' && wrap.parentElement) {
      const layoutObserver = new ResizeObserver(() => {
        queueLayoutSync();
      });
      layoutObserver.observe(wrap.parentElement);
    }
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && sidebarOpen && isCompactLayout()) {
        setSidebarOpen(false);
      }
    });

    els.textInput?.addEventListener('keydown', async (event) => {
      if (event.key !== 'Enter' || event.shiftKey) return;
      event.preventDefault();
      const value = els.textInput.value;
      if (!String(value || '').trim()) return;
      await sendTextMessage(value);
      els.textInput.value = '';
    });

    loadHistory();

    if (canRenderTranscript && typeof MutationObserver !== 'undefined') {
      const cancelScrollFromUserIntent = () => {
        if (!scrollAnimationActive && !scrollRaf && !scrollFlushTimer) return;
        cancelScheduledScroll({ updatePinned: true });
      };
      els.log.addEventListener('pointerdown', cancelScrollFromUserIntent, { passive: true });
      els.log.addEventListener('wheel', cancelScrollFromUserIntent, { passive: true });
      els.log.addEventListener('touchstart', cancelScrollFromUserIntent, { passive: true });
      els.log.addEventListener('scroll', () => {
        if (scrollAnimationActive) return;
        updateAutoScrollPinned();
      }, { passive: true });
      transcriptObserver = new MutationObserver(() => {
        if (transcriptMutationScrollSuppressed) return;
        scheduleScrollToBottom('mutationObserver', { streaming: true });
      });
      transcriptObserver.observe(els.log, {
        childList: true,
        subtree: true,
        characterData: true
      });
      logDebug('Transcript observer attached');
    }

    if (mode === 'text') {
      setStatus('Ready');
      if (els.stop) els.stop.disabled = true;
      if (els.start) els.start.disabled = false;
      if (els.save) els.save.disabled = transcript.length === 0;
      updateTextRecordButton();
    }

    syncResponsiveLayout(true);
    setSessionState('idle');
    activateCurrentSessionView();
    queueLayoutSync();
  });

  window.addEventListener('beforeunload', () => {
    if (!sessionActive || !SESSION_ID || !sessionStats.startTime) {
      return;
    }
    sendUsageUpdate('pending', 'aborted');
  });

})();
