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

    let pc, micStream, dc, SESSION_ID=null, sessionTicket='';
    let activeModel = '', activeVoice = '';
    let activeSubscription = '';
    let activeSessionCap = sessionTokenCapDefault;
    let activeRealtimeBudget = null;
    let activeContext = {};
    let textRequestInFlight = false;
    let inputRecorder = null;
    let inputRecorderStream = null;
    let inputRecorderChunks = [];
    let inputRecorderMime = '';
    let inputRecorderActive = false;
    let inputRecorderDiscard = false;
    let inputRecorderLimitTimer = 0;
    let inputRecorderAutoStopped = false;
    let sessionActive = false;
    let sessionGeneration = 0;
    let toolTicket = '';
    let toolManifest = {};
    let realtimeTools = null;
    let pendingToolApproval = null;
    const reportedRealtimeUsage = new Set();
    const realtimeUsageQueue = [];
    let realtimeUsageTimer = 0;
    let realtimeUsageFlush = null;
    let realtimeUsageFlushKey = '';
    let realtimeUsageFailures = 0;
    let usageStopPromise = null;
    let voiceQuotaDenied = false;
    let voiceUsageMissing = false;
    let activeUsageWarning = '';
    let currentStatus = '';
    const pendingRealtimeResponses = new Set();
    let lastRealtimeResponseId = '';
    const sessionLifecycle = window.AgentOSRealtime && window.AgentOSRealtime.createLifecycle(els.audio);
    let playbackActive = false;
    let playbackBlocked = false;
    let inputSpeechActive = false;
    let transcriptLedger = null;
    let saveEnableTimer = 0;
    const realtimeTranscriptEntries = new Map();
    const realtimeBubbles = new Map();
    const realtimeTokenCounts = new Map();
    const realtimeKeysByTranscriptEntry = new WeakMap();
    const transcriptBubbles = new WeakMap();
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
        session_ticket: sessionTicket,
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

    function localVoiceBudgetAvailable() {
      if (voiceQuotaDenied || voiceUsageMissing) return false;
      if (activeSessionCap > 0 && sessionStats.tokensTotal >= activeSessionCap) {
        voiceQuotaDenied = true;
        setStatus('This conversation reached its token limit. Voice is paused.');
        return false;
      }
      const queuedRealtimeTokens = realtimeUsageQueue.reduce((sum, event) => {
        if (!event || !event.usage || event.usage.type === 'duration') return sum;
        const total = Number.isFinite(Number(event.usage.total_tokens))
          ? Number(event.usage.total_tokens)
          : (Number(event.usage.input_tokens) || 0) + (Number(event.usage.output_tokens) || 0);
        return sum + Math.max(0, total);
      }, 0);
      if (activeRealtimeBudget !== null && queuedRealtimeTokens >= activeRealtimeBudget) {
        voiceQuotaDenied = true;
        setStatus('The remaining subscription voice budget was used. Voice is paused.');
        return false;
      }
      return true;
    }

    function quiesceRealtimeMedia() {
      try { if (micStream) micStream.getTracks().forEach(track => { track.enabled = false; }); } catch (_) {}
      try { if (els.audio) els.audio.pause(); } catch (_) {}
      inputSpeechActive = false;
      playbackActive = false;
    }

    function applyUsageWarnings(warnings) {
      const warning = Array.isArray(warnings) && warnings.some(item => item && Array.isArray(item.reasons) && item.reasons.length > 0)
        ? 'This subscription allowance has been exceeded, but the plan allows continued use.'
        : '';
      if (warning === activeUsageWarning) return;
      activeUsageWarning = warning;
      if (currentStatus) setStatus(currentStatus);
    }

    function clearRealtimeUsageTimer() {
      if (!realtimeUsageTimer) return;
      clearTimeout(realtimeUsageTimer);
      if (sessionLifecycle) sessionLifecycle.removeTimer(realtimeUsageTimer);
      realtimeUsageTimer = 0;
    }

    function stopVoiceForUsage(message) {
      voiceQuotaDenied = true;
      sessionActive = false;
      clearRealtimeUsageTimer();
      if (els.stop) els.stop.disabled = true;
      if (els.start) els.start.disabled = true;
      setStartButtonLabel('Resume voice');
      setSessionState('error');
      setStatus(message);
      if (usageStopPromise) return usageStopPromise;
      const sessionId = SESSION_ID;
      const ticket = sessionTicket;
      const generation = sessionGeneration;
      usageStopPromise = (async () => {
        quiesceRealtimeMedia();
        const finalized = await finalizePendingRealtimeResponse();
        const isSameSession = sessionId === SESSION_ID && ticket === sessionTicket && generation === sessionGeneration;
        if (!isSameSession) return false;
        if (!finalized) voiceUsageMissing = true;
        if (transcriptLedger) { transcriptLedger.markPendingInterrupted(); transcriptLedger.discardUnplayedResponses(); }
        const reported = await drainRealtimeUsage({ heartbeat: true });
        if (!isSameSession || sessionId !== SESSION_ID || ticket !== sessionTicket || generation !== sessionGeneration) return false;
        teardownRealtimeSession();
        setSessionState('error');
        if (els.start) els.start.disabled = false;
        if (!reported && realtimeUsageQueue.length) {
          setStatus('Voice is paused. Usage could not be reported; retry saving or resuming this conversation.');
        }
        await sendUsageUpdate('paused', voiceUsageMissing ? 'response_usage_missing' : 'usage_limit');
        return reported || realtimeUsageQueue.length === 0;
      })().finally(() => { usageStopPromise = null; });
      return usageStopPromise;
    }

    function scheduleRealtimeUsageFlush(generation = sessionGeneration) {
      if (realtimeUsageTimer) {
        clearTimeout(realtimeUsageTimer);
        if (sessionLifecycle) sessionLifecycle.removeTimer(realtimeUsageTimer);
      }
      realtimeUsageTimer = setTimeout(async () => {
        if (sessionLifecycle) sessionLifecycle.removeTimer(realtimeUsageTimer);
        realtimeUsageTimer = 0;
        await flushRealtimeUsage({ heartbeat: true });
        if (sessionActive && isCurrentSession(generation)) scheduleRealtimeUsageFlush(generation);
      }, 60000);
      if (sessionLifecycle && sessionActive) sessionLifecycle.addTimer(generation, realtimeUsageTimer);
    }

    function enqueueRealtimeUsage(category, eventId, usage, status) {
      if (!SESSION_ID || !sessionTicket || !eventId || !usage) return false;
      const dedupeId = category + ':' + String(eventId);
      if (reportedRealtimeUsage.has(dedupeId)) return false;
      reportedRealtimeUsage.add(dedupeId);
      realtimeUsageQueue.push({
        event_id: String(eventId),
        category,
        response_status: status || '',
        usage
      });
      return true;
    }

    async function flushRealtimeUsage(options = {}) {
      if (!SESSION_ID || !sessionTicket) return !voiceQuotaDenied && !voiceUsageMissing;
      if (!realtimeUsageQueue.length && !options.heartbeat) return !voiceQuotaDenied && !voiceUsageMissing;
      const sessionId = SESSION_ID;
      const ticket = sessionTicket;
      const generation = sessionGeneration;
      const flushKey = sessionId + ':' + ticket + ':' + generation;
      if (realtimeUsageFlush && realtimeUsageFlushKey === flushKey) return realtimeUsageFlush;
      const batch = realtimeUsageQueue.slice(0, 100);
      const batchIds = new Set(batch.map(event => event.category + ':' + event.event_id));
      const payload = {
        session_id: sessionId, session_ticket: ticket, agent_id: agentId,
        post_id: postId, anon_id: getAnonId(), events: batch
      };
      const url = restBase + '/usage/report';
      const body = JSON.stringify(payload);
      if (options.beacon && navigator.sendBeacon) {
        try {
          const blob = new Blob([JSON.stringify({ ...payload, _wpnonce: nonce })], { type: 'application/json' });
          if (navigator.sendBeacon(url, blob)) return true;
        } catch (err) {
          logError('Usage report beacon failed', err);
        }
      }
      realtimeUsageFlush = (async () => {
        realtimeUsageFlushKey = flushKey;
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        const requestTimeout = controller ? setTimeout(() => controller.abort(), 15000) : 0;
        try {
          const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            body,
            keepalive: true,
            ...(controller ? { signal: controller.signal } : {})
          });
          const data = await response.json();
          if (!response.ok) throw new Error(data?.message || ('Usage report failed (' + response.status + ')'));
          if (sessionId !== SESSION_ID || ticket !== sessionTicket || generation !== sessionGeneration) return false;
          applyUsageWarnings(data.warnings);
          realtimeUsageQueue.splice(0, realtimeUsageQueue.length,
            ...realtimeUsageQueue.filter(event => !batchIds.has(event.category + ':' + event.event_id)));
          realtimeUsageFailures = 0;
          if (data.realtime_budget === null || data.realtime_budget === undefined) {
            activeRealtimeBudget = null;
          } else if (Number.isFinite(Number(data.realtime_budget))) {
            activeRealtimeBudget = Math.max(0, Number(data.realtime_budget));
          }
          if (!data.allowed) {
            stopVoiceForUsage(data.message || 'The subscription limit was reached. Voice is paused.');
            return false;
          }
          if (sessionId === SESSION_ID && !localVoiceBudgetAvailable()) {
            stopVoiceForUsage('This conversation reached its token limit. Voice is paused.');
            return false;
          }
          return true;
        } catch (err) {
          const stillCurrent = sessionId === SESSION_ID && ticket === sessionTicket && generation === sessionGeneration;
          if (stillCurrent) realtimeUsageFailures++;
          logError('Usage report failed', { attempt: stillCurrent ? realtimeUsageFailures : 'stale', message: err && err.message || '' });
          if (stillCurrent && sessionActive && realtimeUsageFailures >= 2) {
            stopVoiceForUsage('Usage could not be confirmed for over a minute. Voice is paused until the report can be retried.');
          }
          return false;
        } finally {
          if (requestTimeout) clearTimeout(requestTimeout);
          if (realtimeUsageFlushKey === flushKey) {
            realtimeUsageFlush = null;
            realtimeUsageFlushKey = '';
            if (realtimeUsageQueue.length && sessionActive && !realtimeUsageTimer) scheduleRealtimeUsageFlush();
          }
        }
      })();
      return realtimeUsageFlush;
    }

    async function drainRealtimeUsage(options = {}) {
      if (!SESSION_ID || !sessionTicket) return !voiceQuotaDenied && !voiceUsageMissing;
      const sessionId = SESSION_ID;
      const ticket = sessionTicket;
      const generation = sessionGeneration;
      const flushKey = sessionId + ':' + ticket + ':' + generation;
      let first = true;
      while (realtimeUsageQueue.length || (first && options.heartbeat)) {
        first = false;
        if (sessionId !== SESSION_ID || ticket !== sessionTicket || generation !== sessionGeneration) return false;
        if (realtimeUsageFlush && realtimeUsageFlushKey === flushKey) {
          const pending = realtimeUsageFlush;
          await pending;
          if (sessionId !== SESSION_ID || ticket !== sessionTicket || generation !== sessionGeneration) return false;
          if (realtimeUsageFailures > 0) return false;
          continue;
        }
        const before = realtimeUsageQueue.slice(0, 100).map(event => event.category + ':' + event.event_id);
        const ok = await flushRealtimeUsage({ heartbeat: !!options.heartbeat && before.length === 0 });
        if (!ok && (realtimeUsageQueue.length || realtimeUsageFailures > 0)) return false;
        const stillPending = new Set(realtimeUsageQueue.map(event => event.category + ':' + event.event_id));
        if (before.some(id => stillPending.has(id))) return false;
        if (!realtimeUsageQueue.length) return true;
      }
      return true;
    }

    async function finalizePendingRealtimeResponse(timeoutMs = 700) {
      quiesceRealtimeMedia();
      if (!pendingRealtimeResponses.size || !dc || dc.readyState !== 'open') return pendingRealtimeResponses.size === 0;
      try { dc.send(JSON.stringify({ type: 'response.cancel' })); } catch (_) {}
      const deadline = Date.now() + timeoutMs;
      while (pendingRealtimeResponses.size && Date.now() < deadline) {
        await new Promise(resolve => setTimeout(resolve, 50));
      }
      return pendingRealtimeResponses.size === 0;
    }

    function createRealtimeResponse(channel, generation, response = {}) {
      if (!isCurrentSession(generation) || !channel || channel.readyState !== 'open') return false;
      if (!localVoiceBudgetAvailable()) {
        stopVoiceForUsage('This conversation reached its token limit. Voice is paused.');
        return false;
      }
      channel.send(JSON.stringify({ type: 'response.create', response }));
      return true;
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
          const bubble = addBubble(entry.role, entry.text || '', null, {
            forceRender: true,
            suppressScroll: true
          });
          if (bubble) {
            transcriptBubbles.set(entry, bubble);
            renderTranscriptBubble(bubble, entry);
          }
          const realtimeKey = realtimeKeysByTranscriptEntry.get(entry);
          if (realtimeKey && bubble) realtimeBubbles.set(realtimeKey, bubble);
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

    function renderTranscriptBubble(bubble, entry) {
      if (!bubble || !entry) return;
      const interrupted = entry.interrupted === true;
      const incomplete = entry.partial === true;
      bubble.classList.toggle('is-interrupted', interrupted);
      bubble.classList.toggle('is-incomplete', incomplete);
      const label = interrupted ? 'Interrupted: ' : (incomplete ? 'Incomplete: ' : '');
      bubble.textContent = label + (entry.text || (incomplete ? 'transcription unavailable' : (interrupted ? 'transcript unavailable' : '')));
    }

    function reorderTranscriptDom() {
      if (!canRenderTranscript || selectedSessionId || !transcriptTail || transcriptTail.parentNode !== els.log) return;
      transcript.forEach((entry) => {
        const bubble = transcriptBubbles.get(entry);
        if (bubble && bubble.parentNode === els.log) els.log.insertBefore(bubble, transcriptTail);
      });
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
      if (inputRecorderLimitTimer) { clearTimeout(inputRecorderLimitTimer); inputRecorderLimitTimer = 0; }
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
      formData.append('session_id', SESSION_ID || '');
      formData.append('session_ticket', sessionTicket || '');
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
      if (data.session_id) SESSION_ID = data.session_id;
      if (data.session_ticket) sessionTicket = data.session_ticket;
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
          const autoStopped = inputRecorderAutoStopped;
          inputRecorderAutoStopped = false;
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
            setStatus(autoStopped ? 'Recording stopped at the 60-second limit; the captured clip was transcribed.' : 'Ready');
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
        inputRecorderAutoStopped = false;
        inputRecorderLimitTimer = setTimeout(() => {
          inputRecorderLimitTimer = 0;
          if (inputRecorderActive && inputRecorder && inputRecorder.state !== 'inactive') {
            inputRecorderAutoStopped = true;
            setStatus('Recording reached the 60-second limit. Transcribing the captured clip…');
            try { inputRecorder.stop(); } catch (_) {}
          }
        }, 60000);
        updateTextRecordButton();
        setStatus('Listening…');
      } catch (err) {
        logError('Text recorder failed', err);
        cleanupInputRecorder(true);
        setStatus('Error: ' + (err && err.message ? err.message : 'Unable to access microphone'));
      }
    }

    function isCurrentSession(generation) {
      return sessionLifecycle ? sessionLifecycle.isCurrent(generation) : generation === sessionGeneration;
    }

    function teardownRealtimeSession() {
      if (realtimeTools) { realtimeTools.dispose(); realtimeTools = null; }
      cancelToolApproval();
      closeToolAccess();
      if (realtimeUsageTimer) {
        clearTimeout(realtimeUsageTimer);
        if (sessionLifecycle) sessionLifecycle.removeTimer(realtimeUsageTimer);
        realtimeUsageTimer = 0;
      }
      if (saveEnableTimer) { clearTimeout(saveEnableTimer); saveEnableTimer = 0; }
      if (sessionLifecycle) sessionGeneration = sessionLifecycle.dispose();
      else {
        sessionGeneration += 1;
        try { if (dc) dc.close(); } catch (_) {}
        try { if (pc) pc.close(); } catch (_) {}
        try { if (micStream) micStream.getTracks().forEach((track) => track.stop()); } catch (_) {}
        try { if (els.audio) { els.audio.pause(); els.audio.srcObject = null; } } catch (_) {}
      }
      pc = null;
      dc = null;
      micStream = null;
      playbackActive = false;
      inputSpeechActive = false;
    }

    function closeToolAccess() {
      const closingTicket = toolTicket;
      const closingSession = SESSION_ID;
      toolTicket = '';
      toolManifest = {};
      if (closingTicket) {
        fetch(restBase + '/mcp-session', {
          method: 'DELETE', keepalive: true,
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
          body: JSON.stringify({ tool_ticket: closingTicket, agent_id: agentId, post_id: postId, session_id: closingSession, session_ticket: sessionTicket, anon_id: getAnonId() })
        }).catch(() => {});
      }
    }

    function cancelToolApproval() {
      if (pendingToolApproval) {
        const pending = pendingToolApproval;
        pendingToolApproval = null;
        pending.element.remove();
        pending.resolve(false);
      }
    }

    function requestToolApproval(data, generation) {
      cancelToolApproval();
      if (!isCurrentSession(generation)) return Promise.resolve(false);
      return new Promise(resolve => {
        const panel = document.createElement('section');
        panel.className = 'agentos-tool-approval';
        panel.setAttribute('role', 'group');
        panel.setAttribute('aria-label', 'Tool approval');
        const title = document.createElement('strong');
        title.textContent = 'Allow ' + String(data.tool || 'this action') + '?';
        const detail = document.createElement('p');
        detail.textContent = String(data.connection || 'Connected service');
        const parameters = document.createElement('pre');
        parameters.textContent = JSON.stringify(data.arguments || {}, null, 2);
        const actions = document.createElement('div');
        const approve = document.createElement('button');
        approve.type = 'button'; approve.className = 'agentos-btn agentos-tool-approve'; approve.textContent = 'Allow';
        const deny = document.createElement('button');
        deny.type = 'button'; deny.className = 'agentos-btn agentos-tool-deny'; deny.textContent = 'Decline';
        const finish = decision => {
          if (!pendingToolApproval || pendingToolApproval.element !== panel) return;
          pendingToolApproval = null;
          panel.remove();
          resolve(decision);
        };
        approve.addEventListener('click', () => finish(true));
        deny.addEventListener('click', () => finish(false));
        actions.appendChild(approve); actions.appendChild(deny);
        panel.appendChild(title); panel.appendChild(detail); panel.appendChild(parameters); panel.appendChild(actions);
        const host = els.textUI && els.textUI.parentElement ? els.textUI.parentElement : (els.shell || wrap);
        host.insertBefore(panel, els.textUI && els.textUI.parentElement === host ? els.textUI : null);
        pendingToolApproval = { element: panel, resolve };
        setStatus('Waiting for your approval…');
        approve.focus();
      });
    }

    async function executeToolCall(call, generation) {
      const ticket = toolTicket;
      const session = SESSION_ID;
      const binding = toolManifest[call.name] || {};
      const body = {
        tool_ticket: ticket, session_id: session, session_ticket: sessionTicket, agent_id: agentId, post_id: postId,
        anon_id: getAnonId(), call_id: call.call_id, name: call.name, arguments: call.arguments
      };
      const request = async extra => {
        if (!isCurrentSession(generation) || toolTicket !== ticket) throw new Error('Conversation ended');
        const res = await fetch(restBase + '/mcp-call', {
          method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
          body: JSON.stringify(Object.assign({}, body, extra || {}))
        });
        const data = await res.json();
        if (!res.ok) {
          const reference = data && data.data && typeof data.data.reference === 'string' ? data.data.reference : '';
          throw new Error(reference ? 'Tool unavailable. Reference: ' + reference : 'Tool unavailable.');
        }
        return data;
      };
      try {
        setStatus('Using ' + String(binding.connection_label || 'connected service') + '…');
        let data = await request();
        if (!isCurrentSession(generation)) throw new Error('Conversation ended');
        if (data.approval_required) {
          const approve = await requestToolApproval(data, generation);
          data = await request({ approve, approval_id: data.approval_id });
        }
        if (data.is_error && isCurrentSession(generation)) {
          setStatus(data.reference ? 'Tool failed. Reference: ' + data.reference : 'Tool failed. The agent will receive a safe error.');
        }
        return data;
      } catch (error) {
        const message = error && typeof error.message === 'string' ? error.message : 'Tool unavailable.';
        if (isCurrentSession(generation)) setStatus(message);
        return { output: JSON.stringify({ isError: true, message: message + ' Do not assume an action succeeded.' }) };
      }
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

    function buildResumeContext() {
      return transcript
        .filter((entry) => entry && entry.role && entry.text && entry.interrupted !== true && entry.partial !== true && entry.resumeEligible !== false)
        .map((entry) => ({ role: entry.role, text: entry.text }));
    }

    function restoreRealtimeConversation(userPrompt) {
      if (!dc || dc.readyState !== 'open') return;
      const contextEntries = buildResumeContext();
      contextEntries.forEach((entry) => {
        if (!entry || !entry.role || !entry.text) return;
        sendRealtimeHistoryMessage(entry.role, entry.text);
      });
      if (!transcript.length && userPrompt) {
        sendRealtimeHistoryMessage('user', 'User Prompt:\n' + userPrompt);
        createRealtimeResponse(dc, sessionGeneration);
      }
    }

    function resetCurrentSession(options = {}) {
      teardownRealtimeSession();
      if (transcriptLedger) transcriptLedger.clear();
      realtimeTranscriptEntries.clear();
      realtimeBubbles.clear();
      realtimeTokenCounts.clear();
      cleanupInputRecorder(true);
      transcript.length = 0;
      SESSION_ID = null;
      sessionTicket = '';
      lastRealtimeResponseId = '';
      reportedRealtimeUsage.clear();
      realtimeUsageQueue.length = 0;
      realtimeUsageFailures = 0;
      voiceQuotaDenied = false;
      voiceUsageMissing = false;
      pendingRealtimeResponses.clear();
      activeModel = '';
      activeVoice = '';
      activeSubscription = '';
      activeSessionCap = sessionTokenCapDefault;
      activeRealtimeBudget = null;
      activeUsageWarning = '';
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
      currentStatus = String(t || '');
      const normalized = currentStatus.toLowerCase();
      const isBlockingStatus = /error|paused|limit|blocked|unavailable|could not|failed|saving|wait for|not ready|denied/.test(normalized);
      const displayStatus = activeUsageWarning && currentStatus && !isBlockingStatus
        ? currentStatus + ' · ' + activeUsageWarning
        : currentStatus;
      els.status.textContent = displayStatus;
      if (!t) {
        delete els.status.dataset.status;
        setSessionState('idle');
        return;
      }
      if (normalized.includes('error') || normalized.includes('connection ended') || normalized.includes('connection closed') || normalized.includes('not ready') || normalized.includes('blocked') || normalized.includes('could not be confirmed')) {
        els.status.dataset.status = 'error';
        setSessionState('error');
      } else if (normalized.includes('speaking')) {
        els.status.dataset.status = 'speaking';
        setSessionState('speaking');
      } else if (normalized.includes('listening')) {
        els.status.dataset.status = 'listening';
        setSessionState('listening');
      } else if (normalized.includes('record')) {
        els.status.dataset.status = 'recording';
        setSessionState('recording');
      } else if (normalized === 'connected') {
        els.status.dataset.status = 'connected';
        setSessionState('connected');
      } else if (normalized.includes('request') || normalized.includes('creating') || normalized.includes('connecting')) {
        els.status.dataset.status = 'connecting';
        setSessionState('connecting');
      } else {
        delete els.status.dataset.status;
        setSessionState(sessionActive ? 'running' : 'idle');
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

    // Realtime text and interruption metadata stay in the canonical transcript; resume context is projected separately.
    function renderRealtimeEntry(entry, meta) {
      if (!entry || !entry.key || !entry.role) return;
      let saved = realtimeTranscriptEntries.get(entry.key);
      if (!saved) {
        saved = {
          role: entry.role,
          text: entry.text || '',
          itemId: entry.itemId || '',
          responseId: entry.responseId || '',
          previousItemId: entry.previousItemId || '',
          interrupted: false,
          partial: false,
          resumeEligible: true
        };
        realtimeTranscriptEntries.set(entry.key, saved);
        realtimeKeysByTranscriptEntry.set(saved, entry.key);
        transcript.push(saved);
      }

      const alreadyFrozen = saved.interrupted === true || saved.partial === true || saved.resumeEligible === false;
      if (!alreadyFrozen || (saved.partial === true && entry.final)) {
        saved.text = typeof entry.text === 'string' ? entry.text : saved.text;
      }
      saved.role = entry.role;
      saved.itemId = entry.itemId || saved.itemId;
      saved.responseId = entry.responseId || saved.responseId;
      saved.previousItemId = entry.previousItemId || saved.previousItemId;
      if (entry.interrupted === true) saved.interrupted = true;
      if (entry.partial === true) saved.partial = true;
      if (entry.resumeEligible === false || saved.interrupted || saved.partial) saved.resumeEligible = false;

      const knownEntries = new Set(realtimeTranscriptEntries.values());
      const realtimePositions = [];
      transcript.forEach((item, index) => { if (knownEntries.has(item)) realtimePositions.push(index); });
      const orderedSaved = (meta.ordered || []).map((item) => realtimeTranscriptEntries.get(item.key)).filter(Boolean);
      realtimePositions.forEach((index, offset) => { if (orderedSaved[offset]) transcript[index] = orderedSaved[offset]; });

      if (canRenderTranscript && !selectedSessionId) {
        let bubble = realtimeBubbles.get(entry.key);
        if (!bubble || !bubble.parentNode) {
          bubble = addBubble(entry.role, '', null);
          if (bubble) {
            realtimeBubbles.set(entry.key, bubble);
            transcriptBubbles.set(saved, bubble);
          }
        }
        if (bubble) renderTranscriptBubble(bubble, saved);
        reorderTranscriptDom();
        scheduleScrollToBottom('realtimeTranscript', { streaming: !entry.final && !saved.interrupted && !saved.partial });
      }
      if (entry.final || saved.interrupted || saved.partial) {
        if (els.save) els.save.disabled = transcript.length === 0;
        renderSessionList();
      }
    }
    transcriptLedger = window.AgentOSRealtime && window.AgentOSRealtime.createLedger(renderRealtimeEntry);

    function handleRealtimeEvent(ev, generation) {
      if (!ev || !ev.type || !isCurrentSession(generation)) return;
      if (ev.type === 'error') {
        logError('Realtime event error', { code: ev.error && ev.error.code || '', type: ev.error && ev.error.type || '' });
      } else {
        logDebug('Realtime event', { type: ev.type, responseId: ev.response_id || (ev.response && ev.response.id) || '', itemId: ev.item_id || '' });
      }
      if (ev.type === 'response.created' && ev.response && ev.response.id) {
        pendingRealtimeResponses.add(String(ev.response.id));
      }
      if (ev.type === 'response.done' && ev.response && ev.response.usage
          && Number.isFinite(Number(ev.response.usage.input_tokens))
          && Number.isFinite(Number(ev.response.usage.output_tokens))) {
        const responseId = String(ev.response.id || ev.event_id || '');
        if (responseId) lastRealtimeResponseId = responseId;
        if (responseId) pendingRealtimeResponses.delete(responseId);
        const usage = ev.response.usage;
        if (responseId && !reportedRealtimeUsage.has('realtime:' + responseId)) {
          const inputTokens = Math.max(0, Number(usage.input_tokens) || 0);
          const outputTokens = Math.max(0, Number(usage.output_tokens) || 0);
          const totalTokens = Math.max(0, Number(usage.total_tokens) || inputTokens + outputTokens);
          const details = {};
          const cleanDetailValues = (values, depth = 0) => {
            if (!values || typeof values !== 'object' || Array.isArray(values) || depth >= 3) return {};
            const clean = {};
            Object.keys(values).slice(0, 20).forEach(name => {
              if (!/^[a-z][a-z0-9_]{0,63}$/i.test(name)) return;
              const value = values[name];
              if (typeof value === 'number' && Number.isFinite(value)) {
                clean[name] = Math.max(0, value);
              } else if (value && typeof value === 'object' && !Array.isArray(value)) {
                const nested = cleanDetailValues(value, depth + 1);
                if (Object.keys(nested).length) clean[name] = nested;
              }
            });
            return clean;
          };
          ['input_token_details', 'output_token_details', 'input_tokens_details', 'output_tokens_details'].forEach(key => {
            const part = usage[key];
            if (!part || typeof part !== 'object') return;
            details[key] = cleanDetailValues(part);
            if (!Object.keys(details[key]).length) delete details[key];
          });
          sessionStats.tokensRealtime += totalTokens;
          sessionStats.tokensTotal += totalTokens;
          const realtimeUsage = {
            input_tokens: inputTokens,
            output_tokens: outputTokens,
            total_tokens: totalTokens
          };
          if (Object.keys(details).length) realtimeUsage.details = details;
          enqueueRealtimeUsage('realtime', responseId, realtimeUsage, ev.response.status || '');
          if (!localVoiceBudgetAvailable()) stopVoiceForUsage('This conversation reached its token limit. Voice is paused.');
        }
      } else if (ev.type === 'response.done' && ev.response) {
        const responseId = String(ev.response.id || ev.event_id || '');
        if (responseId) pendingRealtimeResponses.delete(responseId);
        voiceUsageMissing = true;
        stopVoiceForUsage('The voice response ended without provider usage. Voice is paused so the unconfirmed usage is not treated as free.');
      } else if (ev.type === 'conversation.item.input_audio_transcription.completed' && ev.usage) {
        const transcriptionId = String(ev.event_id || ev.item_id || '');
        const usage = ev.usage;
        if (transcriptionId && !reportedRealtimeUsage.has('transcription:' + transcriptionId)) {
          const report = { type: usage.type || '' };
          if (usage.type === 'duration') {
            report.seconds = Math.max(0, Number(usage.seconds) || 0);
          } else if (usage.type === 'tokens') {
            report.input_tokens = Math.max(0, Number(usage.input_tokens) || 0);
            report.output_tokens = Math.max(0, Number(usage.output_tokens) || 0);
            report.total_tokens = Math.max(0, Number(usage.total_tokens) || report.input_tokens + report.output_tokens);
            sessionStats.tokensRealtime += report.total_tokens;
            sessionStats.tokensTotal += report.total_tokens;
          }
          if (report.type === 'duration' || report.type === 'tokens') {
            enqueueRealtimeUsage('transcription', transcriptionId, report, 'completed');
            if (!localVoiceBudgetAvailable()) stopVoiceForUsage('This conversation reached its token limit. Voice is paused.');
          } else {
            voiceUsageMissing = true;
            stopVoiceForUsage('Audio transcription usage was missing. Voice is paused until the usage can be confirmed.');
          }
        }
      }
      if (realtimeTools) realtimeTools.handle(ev);
      const result = transcriptLedger ? transcriptLedger.handle(ev) : { kind: 'ignored' };
      if (cfg.logging && (
        ev.type.startsWith('conversation.item.input_audio_transcription.') ||
        ev.type === 'input_audio_buffer.speech_started' ||
        ev.type === 'input_audio_buffer.speech_stopped' ||
        ev.type === 'input_audio_buffer.committed'
      )) {
        const entry = transcriptLedger && transcriptLedger.entries().find(item => item.role === 'user' && item.itemId === ev.item_id && item.key.endsWith(':' + (ev.content_index || 0)));
        const saved = entry && realtimeTranscriptEntries.get(entry.key);
        const bubble = entry && realtimeBubbles.get(entry.key);
        // Keep diagnostics readable in browser consoles that flatten objects. Never log speech or audio.
        logDebug('Realtime input trace ' + JSON.stringify({
          type: ev.type, eventId: ev.event_id || '', itemId: ev.item_id || '',
          contentIndex: ev.content_index || 0, result: result.kind,
          deltaChars: typeof ev.delta === 'string' ? ev.delta.length : null,
          finalChars: typeof ev.transcript === 'string' ? ev.transcript.length : null,
          ledgerChars: entry ? entry.text.length : null,
          savedChars: saved ? saved.text.length : null,
          renderedChars: bubble ? bubble.textContent.length : null,
          final: entry ? entry.final : null, frozen: entry ? entry.frozen : null,
          audioStartMs: Number.isFinite(ev.audio_start_ms) ? ev.audio_start_ms : null,
          audioEndMs: Number.isFinite(ev.audio_end_ms) ? ev.audio_end_ms : null,
          errorCode: ev.error && typeof ev.error.code === 'string' ? ev.error.code : ''
        }));
      }
      if (result.kind === 'error') {
        setStatus('Voice service error' + (result.code ? ' (' + result.code + ')' : '') + '. Try again or resume the session.');
      } else if (result.kind === 'response-done') {
        if (!result.duplicate) {
          if (!playbackActive && sessionActive) setStatus('Listening…');
          if (result.status === 'cancelled' && transcriptLedger) transcriptLedger.discardResponse(result.responseId);
          if (result.status === 'cancelled' || result.status === 'failed' || result.status === 'incomplete') {
            logInfo('Realtime response ended early', { status: result.status, responseId: result.responseId });
            if (result.status === 'failed' || result.status === 'incomplete') {
              setStatus('Voice response error. Please try again.');
            }
          }
        }
      } else if (result.kind === 'playback-started') {
        playbackActive = true;
        setStatus('Speaking…');
      } else if (result.kind === 'playback-stopped' || result.kind === 'playback-cleared') {
        playbackActive = false;
        if (sessionActive) setStatus('Listening…');
      } else if (result.kind === 'speech-started') {
        // WebRTC server VAD handles barge-in; keep the capture track live for AEC/full duplex.
        inputSpeechActive = true;
        logDebug('Input speech started');
        if (sessionActive) setStatus('Listening…');
      } else if (result.kind === 'speech-stopped') {
        inputSpeechActive = false;
        logDebug('Input speech stopped');
      } else if (result.kind === 'item-truncated') {
        logInfo('Realtime audio item truncated by server', { itemId: result.itemId, audioEndMs: result.audioEndMs });
      }
    }

    function handleRealtimeDisconnect(reason, generation) {
      if (!isCurrentSession(generation)) return;
      const hadOpenSpeech = inputSpeechActive;
      const hadUnconfirmedResponse = pendingRealtimeResponses.size > 0;
      if (hadUnconfirmedResponse) voiceUsageMissing = true;
      logWarn('Realtime connection ended', { reason });
      if (transcriptLedger) { transcriptLedger.markPendingInterrupted(); transcriptLedger.discardUnplayedResponses(); }
      sendUsageUpdate('paused', hadUnconfirmedResponse ? 'disconnected_with_unconfirmed_usage' : reason);
      teardownRealtimeSession();
      setSessionState('error');
      sessionActive = false;
      if (els.stop) els.stop.disabled = true;
      if (els.start) els.start.disabled = false;
      setStartButtonLabel('Resume voice');
      setStatus(hadUnconfirmedResponse
        ? 'Connection ended before final provider usage arrived. The usage total is marked incomplete; save this session before starting another.'
        : (hadOpenSpeech ? 'Connection ended while you were speaking. The incomplete utterance is retained and excluded from resume context.' : 'Connection ended. You can resume this session.'));
      activateCurrentSessionView();
    }

    async function getToken(generation) {
      const anonId = getAnonId();
      const res = await fetch(restBase + '/realtime-token', {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-WP-Nonce': nonce},
        body: JSON.stringify({
          post_id: postId,
          agent_id: agentId,
          ctx: getCtxFromURL(contextParams),
          anon_id: anonId,
          session_id: SESSION_ID,
          session_ticket: sessionTicket
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
            raw = '';
          } catch (_) {}
        } catch (_) {}
        logError('Token request failed', { status: res.status, code: parsedCode || 'request_error' });
        const detail = parsedMessage || `Token request failed (${res.status})`;
        const codeHint = parsedCode ? ` (${parsedCode})` : '';
        throw new Error(detail + codeHint);
      }
      const json = await res.json();
      if (!isCurrentSession(generation)) return null;
      logDebug('Realtime token response received', { hasClientSecret: !!json.client_secret, model: json.model || '', voice: json.voice || '' });
      activeSubscription = json.subscription || '';
      activeSessionCap = json.session_cap || sessionTokenCapDefault;
      activeRealtimeBudget = json.realtime_budget === null || json.realtime_budget === undefined
        ? null
        : Math.max(0, Number(json.realtime_budget) || 0);
      activeContext = json.context && typeof json.context === 'object' ? json.context : {};
      applyUsageWarnings(json.warnings);
      if (Array.isArray(json.warnings) && json.warnings.length) {
        logInfo('Subscription warnings', json.warnings);
      }
      if (json.session_id) {
        SESSION_ID = json.session_id;
      }
      sessionTicket = json.session_ticket || sessionTicket;
      toolTicket = json.tool_ticket || '';
      toolManifest = json.tool_manifest || {};
      if (toolTicket) {
        if (!window.AgentOSTools) throw new Error('Tool support failed to load. Reload the page.');
        realtimeTools = window.AgentOSTools.createRealtime({
          invoke: call => executeToolCall(call, generation),
          cancelApproval: cancelToolApproval,
          settle: () => drainRealtimeUsage(),
          authorizeNext: async () => {
            if (!(await drainRealtimeUsage({ heartbeat: true }))) return false;
            if (!localVoiceBudgetAvailable()) {
              stopVoiceForUsage('The subscription or conversation token limit was reached. Voice is paused.');
              return false;
            }
            return true;
          },
          send: event => {
            if (!isCurrentSession(generation) || !dc || dc.readyState !== 'open') return;
            dc.send(JSON.stringify(event));
            if (event.type === 'response.create') setStatus('Thinking…');
          },
          error: code => { if (isCurrentSession(generation)) setStatus(code === 'usage_limit' ? 'Usage limit reached. This conversation is paused.' : 'Unable to confirm usage after the response. Tools and follow-up responses are paused.'); }
        });
      }
      return json;
    }

    function waitForOpen(channel, generation, timeoutMs = 7000) {
      return new Promise((resolve, reject) => {
        if (channel.readyState === 'open') return resolve();
        let timer = 0;
        const cleanup = () => {
          if (timer) { clearTimeout(timer); if (sessionLifecycle) sessionLifecycle.removeTimer(timer); }
          channel.removeEventListener('open', onOpen);
          channel.removeEventListener('close', onClose);
        };
        const onOpen = () => { cleanup(); resolve(); };
        const onClose = () => { cleanup(); reject(new Error('DataChannel closed')); };
        channel.addEventListener('open', onOpen, { once: true });
        channel.addEventListener('close', onClose, { once: true });
        timer = setTimeout(() => { cleanup(); reject(new Error('DataChannel timeout')); }, timeoutMs);
        if (sessionLifecycle) sessionLifecycle.addTimer(generation, timer);
      });
    }

    // Realtime ASR events are the single source for voice transcript bubbles.

    async function sendTextMessage(txt) {
      const msg = (txt || '').trim();
      if (!msg) return false;
      if (mode === 'text') return await sendTextMessageViaResponses(msg);
      const channel = dc;
      if (!channel || channel.readyState !== 'open') {
        setStatus('Voice connection is not ready. Reconnect before sending.');
        logWarn('Hybrid text send blocked because data channel is closed');
        return false;
      }
      try {
        if (realtimeTools) realtimeTools.interrupt();
        channel.send(JSON.stringify({
          type: 'conversation.item.create',
          item: { type: 'message', role: 'user', content: [{ type: 'input_text', text: msg }] }
        }));
        if (!(await drainRealtimeUsage()) || !createRealtimeResponse(channel, sessionGeneration)) return false;
      } catch (_) {
        setStatus('Voice connection closed before the message was sent. Reconnect and try again.');
        logWarn('Hybrid text send failed because the data channel closed');
        return false;
      }
      logDebug('Text message sent', { length: msg.length });
      const sentEntry = { role: 'user', text: msg };
      transcript.push(sentEntry);
      if (canRenderTranscript) {
        const bubble = addBubble('user', msg);
        if (bubble) transcriptBubbles.set(sentEntry, bubble);
      }
      if (els.save) els.save.disabled = transcript.length === 0;
      return true;
    }

    async function sendTextMessageViaResponses(msg) {
      if (textRequestInFlight) return false;
      const generation = sessionGeneration;
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
      const sentEntry = { role: 'user', text: msg };
      transcript.push(sentEntry);
      if (canRenderTranscript) {
        const bubble = addBubble('user', msg);
        if (bubble) transcriptBubbles.set(sentEntry, bubble);
      }
      if (els.save) els.save.disabled = transcript.length === 0;
      renderSessionList();
      closeSidebarIfCompact();
      textRequestInFlight = true;
      if (els.textSend) els.textSend.disabled = true;
      if (els.textInput) els.textInput.disabled = true;
      setStatus('Thinking…');

      let responseSucceeded = false;
      try {
        const requestBody = {
            post_id: postId,
            agent_id: agentId,
            session_id: SESSION_ID,
            session_ticket: sessionTicket,
            anon_id: getAnonId(),
            ctx: getCtxFromURL(contextParams),
            message: msg,
            transcript: buildResumeContext(),
            tokens_text: sessionStats.tokensText,
            tokens_total: sessionStats.tokensTotal,
            duration_seconds: currentDurationSeconds()
        };
        let data;
        for (let round = 0; round < 7; round += 1) {
          const res = await fetch(restBase + '/text-response', {
            method: 'POST', headers: {'Content-Type':'application/json','X-WP-Nonce': nonce},
            body: JSON.stringify(requestBody)
          });
          data = await res.json();
          if (!isCurrentSession(generation)) return false;
          // A first-request preflight failure can still return the server-owned
          // identity. Keep it so retry continues the same empty session slot.
          if (data.session_id) { SESSION_ID = data.session_id; requestBody.session_id = data.session_id; }
          if (data.session_ticket) { sessionTicket = data.session_ticket; requestBody.session_ticket = data.session_ticket; }
          if (!res.ok) throw new Error(data?.message || ('Text response failed (' + res.status + ')'));
          toolTicket = data.tool_ticket || '';
          if (!data.tool_continuation) break;
          if (!toolTicket || !Array.isArray(data.tool_calls) || !data.tool_calls.length || round === 6) {
            throw new Error('Unable to continue this tool request.');
          }
          for (const call of data.tool_calls) {
            await executeToolCall(call, generation);
            if (!isCurrentSession(generation)) return false;
          }
          requestBody.tool_ticket = toolTicket;
          requestBody.tool_continuation = data.tool_continuation;
          setStatus('Thinking…');
        }

        activeModel = data.model || activeModel;
        activeSubscription = data.subscription || activeSubscription;
        activeSessionCap = data.session_cap || activeSessionCap;
        activeContext = data.context && typeof data.context === 'object' ? data.context : activeContext;
        applyUsageWarnings(data.warnings);
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
          const assistantEntry = { role: 'assistant', text: assistantText };
          transcript.push(assistantEntry);
          if (canRenderTranscript) {
            const bubble = addBubble('assistant', assistantText);
            if (bubble) transcriptBubbles.set(assistantEntry, bubble);
          }
          scheduleScrollToBottom('textResponse', { force: true });
        }

        setStatus('Ready');
        responseSucceeded = true;
        if (els.save) els.save.disabled = transcript.length === 0;
        renderSessionList();
      } catch (err) {
        logError('Text response failed', { name: err && err.name || 'Error' });
        if (isCurrentSession(generation)) setStatus('Error: ' + (err && err.message ? err.message : 'Unable to send message'));
      } finally {
        if (isCurrentSession(generation)) {
          textRequestInFlight = false;
          if (els.textSend) els.textSend.disabled = false;
          if (els.textInput) els.textInput.disabled = false;
          if (els.textInput) els.textInput.focus();
        }
      }
      return responseSucceeded;
    }

    els.textSend?.addEventListener('click', async () => {
      if (!els.textInput) return;
      const sent = await sendTextMessage(els.textInput.value);
      if (sent && els.textInput) els.textInput.value = '';
    });

    els.textRecord?.addEventListener('click', async () => {
      await toggleTextRecorder();
    });

    // main connect
    els.start.addEventListener('click', async () => {
      const isResumeAttempt = !!SESSION_ID;
      const generation = sessionLifecycle ? sessionLifecycle.begin() : sessionGeneration + 1;
      sessionGeneration = generation;
      try {
        if (mode !== 'text' && (!sessionLifecycle || !transcriptLedger)) throw new Error('Voice support failed to load. Reload the page and try again.');
        if (voiceUsageMissing) throw new Error('A prior voice response has no confirmed provider usage. Save the transcript and start a new session to continue.');
        if (SESSION_ID && sessionTicket && realtimeUsageQueue.length) {
          const reported = await drainRealtimeUsage({ heartbeat: true });
          if (!reported || realtimeUsageQueue.length) throw new Error('Pending voice usage could not be confirmed. Retry when the network is available.');
        }
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
          setStatus('Requesting mic…');
          const requestedAudio = { echoCancellation: true, noiseSuppression: true, autoGainControl: true };
          const acquiredStream = await navigator.mediaDevices.getUserMedia({ audio: requestedAudio });
          if (sessionLifecycle && !sessionLifecycle.setStream(generation, acquiredStream)) return;
          if (!isCurrentSession(generation)) { acquiredStream.getTracks().forEach((track) => track.stop()); return; }
          micStream = acquiredStream;
          const track = acquiredStream.getAudioTracks()[0];
          let settings = {};
          try { settings = track && track.getSettings ? track.getSettings() : {}; } catch (_) {}
          logInfo('Microphone acquired', { requested: requestedAudio, supported: navigator.mediaDevices.getSupportedConstraints ? navigator.mediaDevices.getSupportedConstraints() : {}, settings: { echoCancellation: settings.echoCancellation, noiseSuppression: settings.noiseSuppression, autoGainControl: settings.autoGainControl, sampleRate: settings.sampleRate, channelCount: settings.channelCount } });
        }

        setStatus('Creating session…');
        const tk = await getToken(generation);
        if (!isCurrentSession(generation) || !tk) return;
        voiceQuotaDenied = false;
        if (!localVoiceBudgetAvailable()) throw new Error('The current conversation token limit has been reached.');
        const { client_secret, model, voice, user_prompt } = tk;
        if (!client_secret) throw new Error('Missing ephemeral token');
        activeModel = model || '';
        activeVoice = voice || '';
        window._agentosModel = activeModel;
        window._agentosVoice = activeVoice;
        // WebRTC peer
        const peer = new RTCPeerConnection();
        pc = peer;
        if (sessionLifecycle) sessionLifecycle.setPeer(generation, peer);
        peer.ontrack = (e) => {
          if (!isCurrentSession(generation) || !els.audio) return;
          els.audio.srcObject = e.streams[0];
          els.audio.onplaying = () => {
            if (!isCurrentSession(generation)) return;
            playbackBlocked = false;
            if (sessionActive) setStatus('Listening…');
          };
          els.audio.play?.().catch(() => {
            if (!isCurrentSession(generation)) return;
            playbackBlocked = true;
            els.audio.controls = true;
            setStatus('Audio playback was blocked. Use the audio controls to listen.');
            logWarn('Audio autoplay blocked by browser');
          });
        };
        if (mode !== 'text') micStream.getTracks().forEach(t => peer.addTrack(t, micStream));
        peer.onconnectionstatechange = () => {
          if (!isCurrentSession(generation)) return;
          logInfo('Peer connection state', peer.connectionState);
          if (peer.connectionState === 'failed' || peer.connectionState === 'closed') handleRealtimeDisconnect('peer_' + peer.connectionState, generation);
        };
        peer.oniceconnectionstatechange = () => { if (isCurrentSession(generation)) logDebug('ICE connection state', peer.iceConnectionState); };
        peer.onerror = () => handleRealtimeDisconnect('peer_error', generation);

        const channel = peer.createDataChannel('oai-events');
        dc = channel;
        if (sessionLifecycle) sessionLifecycle.setChannel(generation, channel);
        channel.onmessage = (e) => { if (!isCurrentSession(generation)) return; try{ handleRealtimeEvent(JSON.parse(e.data), generation); }catch(err){ logError('Failed to parse realtime event', { name: err.name }); } };
        channel.onopen = () => { if (isCurrentSession(generation)) logInfo('Data channel open'); };
        channel.onclose = () => handleRealtimeDisconnect('data_channel_closed', generation);
        channel.onerror = () => handleRealtimeDisconnect('data_channel_error', generation);

        const offer = await peer.createOffer({ offerToReceiveAudio: (mode!=='text')?1:0, offerToReceiveVideo: 0 });
        if (!isCurrentSession(generation)) return;
        await peer.setLocalDescription(offer);
        if (!isCurrentSession(generation)) return;

        setStatus('Connecting model…');
        const sdpResponse = await fetch('https://api.openai.com/v1/realtime/calls', {
          method:'POST',
          headers:{ 'Authorization':'Bearer '+client_secret, 'Content-Type':'application/sdp' },
          body: offer.sdp
        });
        if (!sdpResponse.ok) throw new Error('Realtime handshake failed');
        const answerSdp = await sdpResponse.text();
        if (!isCurrentSession(generation)) return;
        await peer.setRemoteDescription({ type:'answer', sdp: answerSdp });
        if (!isCurrentSession(generation)) return;
        await waitForOpen(channel, generation);
        if (!isCurrentSession(generation)) return;

        setStatus('Connected');

        restoreRealtimeConversation(user_prompt);

        els.stop.disabled = false; if (els.save) els.save.disabled = true;
        setStartButtonLabel(startButtonDefaultLabel || 'Start voice');
        // Enable save after some content arrives
        if (els.save) {
          saveEnableTimer = setTimeout(() => {
            saveEnableTimer = 0;
            if (isCurrentSession(generation)) els.save.disabled = transcript.length === 0;
          }, 1500);
          if (sessionLifecycle) sessionLifecycle.addTimer(generation, saveEnableTimer);
        }
        logInfo('Session ready', { model: activeModel, voice: activeVoice });
        sessionActive = true;
        setSessionState('running');
        scheduleRealtimeUsageFlush(generation);
        setStatus(playbackBlocked ? 'Audio playback was blocked. Use the audio controls to listen.' : 'Listening…');
        activateCurrentSessionView();
        sendUsageUpdate('running', isResumeAttempt ? 'resumed' : 'started');

      } catch (e) {
        if (!isCurrentSession(generation)) return;
        const errorName = e && e.name ? e.name : 'Error';
        const errorMessage = errorName === 'NotAllowedError' ? 'Microphone permission was denied' : (errorName === 'NotFoundError' ? 'No microphone was found' : (errorName === 'NotReadableError' ? 'Microphone is unavailable' : (e && e.message ? e.message : 'Connection failed')));
        logError('Start failed', { name: errorName, message: errorMessage, code: e && e.code || '' });
        setStatus('Error: '+errorMessage);
        setSessionState('error');
        if (els.stop) els.stop.disabled = true;
        els.start.disabled = false;
        teardownRealtimeSession();
        sendUsageUpdate('pending', 'error');
        if (!isResumeAttempt) {
          SESSION_ID = null;
          resetSessionStats();
        }
        activateCurrentSessionView();
      }
    });

    els.stop.addEventListener('click', async () => {
      sessionActive = false;
      clearRealtimeUsageTimer();
      const finalized = await finalizePendingRealtimeResponse();
      if (!finalized) {
        voiceUsageMissing = true;
        setStatus('The interrupted response did not return final usage. Its cost remains unconfirmed; voice is paused.');
      }
      const reported = await drainRealtimeUsage({ heartbeat: true });
      if (!reported) setStatus('Voice paused. Usage could not be fully reported; retry saving or resuming this conversation.');
      const hadOpenSpeech = inputSpeechActive;
      const pendingPartials = transcriptLedger ? transcriptLedger.markPendingInterrupted() : 0;
      const unplayedResponses = transcriptLedger ? transcriptLedger.discardUnplayedResponses() : 0;
      teardownRealtimeSession();
      els.stop.disabled = true;
      els.start.disabled = false;
      setStartButtonLabel('Resume voice');
      if (els.save) els.save.disabled = transcript.length === 0;
      setStatus(pendingPartials || unplayedResponses || hadOpenSpeech ? 'Paused. Interrupted and incomplete text is retained and excluded from resume context.' : 'Paused');
      setSessionState('idle');
      activateCurrentSessionView();
      await sendUsageUpdate('paused', finalized ? 'stopped' : 'stopped_with_unconfirmed_usage');
    });

    els.save?.addEventListener('click', async () => {
      let savingGeneration = null;
      try {
        if (textRequestInFlight) { setStatus('Wait for the current response before saving.'); return; }
        sessionActive = false;
        clearRealtimeUsageTimer();
        const finalized = await finalizePendingRealtimeResponse();
        if (!finalized) {
          voiceUsageMissing = true;
          setStatus('The interrupted response did not return final usage. Its cost remains unconfirmed; save the transcript and reconnect later.');
        }
        const usageReported = await drainRealtimeUsage({ heartbeat: true });
        if (!usageReported || realtimeUsageQueue.length) throw new Error('Usage reporting is unavailable. The transcript has not been finalized; retry saving when the network is available.');
        const hadOpenSpeech = inputSpeechActive;
        const pendingPartials = transcriptLedger ? transcriptLedger.markPendingInterrupted() : 0;
        const unplayedResponses = transcriptLedger ? transcriptLedger.discardUnplayedResponses() : 0;
        if (sessionActive) await sendUsageUpdate('paused', finalized ? 'saving' : 'saving_with_unconfirmed_usage');
        teardownRealtimeSession();
        savingGeneration = sessionGeneration;
        sessionActive = false;
        setSessionState('idle');
        if (els.stop) els.stop.disabled = true;
        if (els.start) els.start.disabled = false;
        setStatus('Saving transcript…');
        if (els.save) els.save.disabled = true;
        const payload = {
          post_id: postId,
          agent_id: agentId,
          session_id: SESSION_ID,
          session_ticket: sessionTicket,
          anon_id: getAnonId(),
          model: activeModel || '',
          voice: activeVoice || '',
          subscription_slug: activeSubscription || '',
          context: activeContext || {},
          user_agent: navigator.userAgent,
          transcript: transcript.map((entry) => ({
            role: entry.role, text: entry.text, itemId: entry.itemId || '', responseId: entry.responseId || '', previousItemId: entry.previousItemId || '',
            interrupted: entry.interrupted === true, partial: entry.partial === true, resumeEligible: entry.resumeEligible !== false
          })),
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
        if (!isCurrentSession(savingGeneration)) return;
        if (!res.ok) throw new Error(data?.message || 'Save failed');
        setStatus('Transcript saved (id '+data.id+').');
        await sendUsageUpdate('final', 'saved', true);
        if (!isCurrentSession(savingGeneration)) return;
        resetCurrentSession({ status: 'Ready', activateView: false });
        await loadHistory(String(data.id));
        if (pendingPartials || unplayedResponses || hadOpenSpeech) setStatus('Transcript saved with interrupted text retained; it is excluded from resume context.');
      } catch (e) {
        if (savingGeneration !== null && !isCurrentSession(savingGeneration)) return;
        logError('Save failed', e);
        setStatus('Save error: ' + (e && e.message ? e.message : ''));
        if (els.save) els.save.disabled = transcript.length === 0;
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

    els.currentSessionButton?.addEventListener('click', async () => {
      const button = els.currentSessionButton;
      if (textRequestInFlight) {
        setStatus('Wait for the current response before starting a new conversation.');
        return;
      }
      if (button) button.disabled = true;
      try {
        if (usageStopPromise) await usageStopPromise;
        const closingSession = SESSION_ID;
        const closingTicket = sessionTicket;
        let closingGeneration = sessionGeneration;
        if (closingSession && closingTicket) {
          sessionActive = false;
          clearRealtimeUsageTimer();
          const finalized = await finalizePendingRealtimeResponse();
          if (closingSession !== SESSION_ID || closingTicket !== sessionTicket || sessionActive) return;
          closingGeneration = sessionGeneration;
          if (!finalized) voiceUsageMissing = true;
          if (transcriptLedger) { transcriptLedger.markPendingInterrupted(); transcriptLedger.discardUnplayedResponses(); }
          if (realtimeUsageQueue.length) {
            const reported = await drainRealtimeUsage();
            if (!reported || realtimeUsageQueue.length) {
              setStatus('Usage could not be reported. Retry starting a new conversation when the connection is available.');
              return;
            }
          }
          await sendUsageUpdate('paused', voiceUsageMissing ? 'new_chat_with_unconfirmed_usage' : 'new_chat', true);
          if (closingSession !== SESSION_ID || closingTicket !== sessionTicket || closingGeneration !== sessionGeneration) return;
        }
        resetCurrentSession({ status: 'Ready' });
      } catch (error) {
        logError('New conversation could not close usage session', { message: error && error.message || '' });
        setStatus('Usage could not be reported. Retry starting a new conversation when the connection is available.');
      } finally {
        if (button) button.disabled = false;
      }
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
      const sent = await sendTextMessage(value);
      if (sent && els.textInput) els.textInput.value = '';
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

    window.addEventListener('beforeunload', () => {
      if (!sessionActive || !SESSION_ID || !sessionStats.startTime) return;
      flushRealtimeUsage({ heartbeat: realtimeUsageQueue.length === 0, beacon: true });
      sendUsageUpdate('pending', 'aborted');
      teardownRealtimeSession();
      cleanupInputRecorder(true);
      cancelScheduledScroll();
      if (layoutRaf) cancelAnimationFrame(layoutRaf);
      if (transcriptObserver) transcriptObserver.disconnect();
    });

    syncResponsiveLayout(true);
    setSessionState('idle');
    activateCurrentSessionView();
    queueLayoutSync();
  });

})();
