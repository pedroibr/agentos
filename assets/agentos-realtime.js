(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.AgentOSRealtime = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  function createLedger(onChange) {
    const items = new Map();
    const order = [];
    const emittedResponses = new Set();
    const seenEvents = new Set();
    const seenEventOrder = [];
    const tombstonedItems = new Set();
    const tombstonedResponses = new Set();
    const unplayedResponses = new Set();
    let currentPlaybackResponseId = '';
    const orderInsert = (key, entry) => {
      const previousIndex = entry.previousItemId ? order.findIndex((candidate) => items.get(candidate)?.itemId === entry.previousItemId) : -1;
      const successorIndex = entry.itemId ? order.findIndex((candidate) => items.get(candidate)?.previousItemId === entry.itemId) : -1;
      const index = previousIndex >= 0 ? previousIndex + 1 : (successorIndex >= 0 ? successorIndex : order.length);
      order.splice(index, 0, key);
    };
    const keyFor = (event, role) => {
      const itemId = event.item_id || (event.item && event.item.id) || '';
      if (itemId) return role + ':' + itemId + ':' + (event.content_index || 0);
      const responseId = event.response_id || (event.response && event.response.id) || '';
      return responseId ? role + ':' + responseId + ':' + (event.content_index || 0) : role + ':unkeyed';
    };
    const ensure = (key, role, event) => {
      const itemId = event.item_id || (event.item && event.item.id) || '';
      const responseId = event.response_id || (event.response && event.response.id) || '';
      let entry = items.get(key);
      if (!entry) {
        entry = { key, role, text: '', final: false, responseId, itemId, previousItemId: event.previous_item_id || '', pendingAudio: false, pendingOutput: false, interrupted: false, partial: false, resumeEligible: true, frozen: false, awaitingFinal: false };
        items.set(key, entry);
        orderInsert(key, entry);
      } else {
        if (!entry.previousItemId && event.previous_item_id) {
          entry.previousItemId = event.previous_item_id;
          const previousIndex = order.indexOf(key);
          if (previousIndex !== -1) order.splice(previousIndex, 1);
          orderInsert(key, entry);
        }
        if (!entry.responseId && responseId) entry.responseId = responseId;
      }
      if (event.type === 'conversation.item.input_audio_transcription.delta' || event.type === 'conversation.item.input_audio_transcription.completed' || event.type === 'input_audio_buffer.committed') entry.pendingAudio = true;
      if (event.type === 'response.output_audio_transcript.delta' || event.type === 'response.output_audio_transcript.done' || event.type === 'response.output_text.delta' || event.type === 'response.output_text.done') entry.pendingOutput = true;
      return entry;
    };
    const snapshot = () => order.map((key) => ({ ...items.get(key) }));
    const publish = (entry) => { if (onChange) onChange({ ...entry }, { ordered: snapshot() }); };
    const interruptWhere = (predicate, options = {}) => {
      let changed = 0;
      Array.from(items.values()).forEach((entry) => {
        if (!predicate(entry) || entry.interrupted) return;
        const incompleteUserInput = !!(options.awaitUserFinal && entry.role === 'user' && entry.pendingAudio && !entry.final);
        entry.interrupted = !incompleteUserInput;
        entry.partial = incompleteUserInput;
        entry.resumeEligible = false;
        entry.frozen = true;
        entry.awaitingFinal = incompleteUserInput;
        if (entry.itemId) tombstonedItems.add(entry.itemId);
        if (entry.responseId) tombstonedResponses.add(entry.responseId);
        publish(entry);
        changed += 1;
      });
      return changed;
    };
    const interruptResponse = (responseId) => {
      if (!responseId) return 0;
      tombstonedResponses.add(responseId);
      unplayedResponses.delete(responseId);
      if (currentPlaybackResponseId === responseId) currentPlaybackResponseId = '';
      return interruptWhere((entry) => entry.role === 'assistant' && entry.responseId === responseId);
    };
    const applyText = (event, role, text, final) => {
      const itemId = event.item_id || (event.item && event.item.id) || '';
      const responseId = event.response_id || (event.response && event.response.id) || '';
      const key = keyFor(event, role);
      const entry = items.get(key);
      const frozenUserFinal = !!(final && role === 'user' && entry && entry.partial && entry.awaitingFinal);
      if (((itemId && tombstonedItems.has(itemId)) || (responseId && tombstonedResponses.has(responseId))) && !frozenUserFinal) return false;
      if (!entry && (itemId && tombstonedItems.has(itemId) || responseId && tombstonedResponses.has(responseId))) return false;
      if (typeof text !== 'string') return false;
      const target = entry || ensure(key, role, event);
      if (target.frozen && !frozenUserFinal) return false;
      if (target.final && (!final || target.text === text)) return false;
      if (final) { target.text = text; target.final = true; target.awaitingFinal = false; }
      else target.text += text;
      publish(target);
      return true;
    };
    const roleForItem = (event) => {
      const role = event.item_role || (event.item && event.item.role) || '';
      return role === 'assistant' || role === 'user' ? role : '';
    };
    return {
      handle(event) {
        if (!event || typeof event.type !== 'string') return { kind: 'ignored' };
        if (event.event_id) {
          if (seenEvents.has(event.event_id)) return { kind: 'duplicate-event' };
          seenEvents.add(event.event_id);
          seenEventOrder.push(event.event_id);
          if (seenEventOrder.length > 4096) seenEvents.delete(seenEventOrder.shift());
        }
        const type = event.type;
        if (type === 'response.output_item.added' || type === 'response.output_item.done') {
          const item = event.item || {};
          const itemId = event.item_id || item.id || '';
          const responseId = event.response_id || '';
          if (!itemId || item.type !== 'message') return { kind: 'ignored-output-item' };
          const key = keyFor({ ...event, item_id: itemId }, 'assistant');
          if (tombstonedItems.has(itemId) || (responseId && tombstonedResponses.has(responseId))) {
            const known = items.get(key);
            if (known) { const metadata = ensure(key, 'assistant', { ...event, item_id: itemId }); if (metadata.text) publish(metadata); }
            return { kind: 'ignored-output-item' };
          }
          const entry = ensure(key, 'assistant', { ...event, item_id: itemId });
          if (type === 'response.output_item.done') entry.itemDone = true;
          if (entry.text) publish(entry);
          return { kind: 'output-item', itemId, responseId };
        }
        if (type === 'conversation.item.added' || type === 'conversation.item.done') {
          const item = event.item || {};
          const role = roleForItem(event);
          if (!role) return { kind: 'ignored-item-role' };
          if (item.type && item.type !== 'message') return { kind: 'ignored-item-type' };
          if (role === 'user' && !(Array.isArray(item.content) && item.content.some((part) => part && part.type === 'input_audio'))) return { kind: 'ignored-non-audio-user-item' };
          const itemId = event.item_id || item.id || '';
          const responseId = event.response_id || '';
          const key = keyFor({ ...event, item_id: itemId }, role);
          if ((itemId && tombstonedItems.has(itemId)) || (responseId && tombstonedResponses.has(responseId))) {
            const known = items.get(key);
            if (known) { const metadata = ensure(key, role, { ...event, item_id: itemId }); if (metadata.text) publish(metadata); }
            return { kind: 'tombstoned-item', itemId, responseId };
          }
          const entry = ensure(key, role, { ...event, item_id: itemId });
          if (role === 'user') entry.pendingAudio = true;
          if (type === 'conversation.item.done') entry.itemDone = true;
          if (entry.text) publish(entry);
          return { kind: 'item-placeholder', itemId, role };
        }
        if (type === 'input_audio_buffer.committed') {
          const itemId = event.item_id || '';
          if (!itemId || tombstonedItems.has(itemId)) return { kind: 'input-committed', itemId };
          const key = keyFor({ ...event, item_id: itemId }, 'user');
          const entry = ensure(key, 'user', event);
          if (entry.text) publish(entry);
          return { kind: 'input-committed', itemId };
        }
        if (type === 'conversation.item.input_audio_transcription.delta') {
          applyText(event, 'user', event.delta || '', false);
          return { kind: 'user-delta' };
        }
        if (type === 'conversation.item.input_audio_transcription.completed') {
          applyText(event, 'user', event.transcript || '', true);
          return { kind: 'user-final' };
        }
        if (type === 'response.output_audio_transcript.delta') {
          applyText(event, 'assistant', event.delta || '', false);
          return { kind: 'assistant-delta' };
        }
        if (type === 'response.output_audio_transcript.done') {
          applyText(event, 'assistant', event.transcript || '', true);
          return { kind: 'assistant-final' };
        }
        if (type === 'response.output_text.delta') {
          applyText(event, 'assistant', event.delta || '', false);
          return { kind: 'assistant-delta' };
        }
        if (type === 'response.output_text.done') {
          applyText(event, 'assistant', event.text || '', true);
          return { kind: 'assistant-final' };
        }
        if (type === 'response.done') {
          const response = event.response || {};
          const id = response.id || event.response_id || '';
          const status = response.status || '';
          if (id && emittedResponses.has(id)) return { kind: 'response-done', duplicate: true, status };
          if (id) emittedResponses.add(id);
          if (id && (status === 'cancelled' || status === 'failed')) {
            interruptResponse(id);
          }
          return { kind: 'response-done', status, responseId: id };
        }
        if (type === 'response.cancelled') {
          const response = event.response || {};
          const responseId = response.id || event.response_id || '';
          if (responseId) interruptResponse(responseId);
          return { kind: 'response-cancelled', responseId };
        }
        if (type === 'response.created') {
          const responseId = event.response && event.response.id || event.response_id || '';
          if (responseId) unplayedResponses.add(responseId);
          return { kind: 'response-created', responseId };
        }
        if (type === 'output_audio_buffer.started') {
          currentPlaybackResponseId = event.response_id || currentPlaybackResponseId;
          return { kind: 'playback-started', responseId: currentPlaybackResponseId };
        }
        if (type === 'output_audio_buffer.stopped') {
          const responseId = event.response_id || currentPlaybackResponseId;
          if (responseId) unplayedResponses.delete(responseId);
          currentPlaybackResponseId = '';
          return { kind: 'playback-stopped', responseId };
        }
        if (type === 'output_audio_buffer.cleared') {
          const responseId = event.response_id || currentPlaybackResponseId;
          if (responseId) interruptResponse(responseId);
          return { kind: 'playback-cleared', responseId };
        }
        if (type === 'input_audio_buffer.speech_started') {
          const responses = Array.from(unplayedResponses);
          const responseId = currentPlaybackResponseId || responses[responses.length - 1] || '';
          if (responseId) interruptResponse(responseId);
          return { kind: 'speech-started', responseId };
        }
        if (type === 'input_audio_buffer.speech_stopped') return { kind: 'speech-stopped' };
        if (type === 'conversation.item.truncated') {
          const itemId = event.item_id || '';
          if (itemId) tombstonedItems.add(itemId);
          interruptWhere((entry) => entry.role === 'assistant' && entry.itemId === itemId);
          return { kind: 'item-truncated', itemId, audioEndMs: event.audio_end_ms };
        }
        if (type === 'error') return { kind: 'error', code: event.error && event.error.code || '', message: event.error && event.error.message || '' };
        return { kind: 'ignored' };
      },
      entries() { return snapshot(); },
      discardResponse(responseId) {
        interruptResponse(responseId);
      },
      discardUnplayedResponses() {
        let discarded = 0;
        Array.from(unplayedResponses).forEach((responseId) => {
          discarded += interruptResponse(responseId);
        });
        unplayedResponses.clear();
        currentPlaybackResponseId = '';
        return discarded;
      },
      markPendingInterrupted() {
        return interruptWhere((entry) => !entry.final && (entry.pendingAudio || entry.pendingOutput), { awaitUserFinal: true });
      },
      clear() {
        items.clear(); order.length = 0; emittedResponses.clear(); seenEvents.clear(); seenEventOrder.length = 0;
        tombstonedItems.clear(); tombstonedResponses.clear();
        unplayedResponses.clear(); currentPlaybackResponseId = '';
      }
    };
  }
  function createLifecycle(audioElement) {
    let generation = 0;
    let stream = null;
    let peer = null;
    let channel = null;
    const timers = new Set();
    const stopStream = (value) => {
      try { if (value && value.getTracks) value.getTracks().forEach((track) => track.stop()); } catch (_) {}
    };
    const disposeResources = () => {
      timers.forEach((timer) => clearTimeout(timer));
      timers.clear();
      try { if (channel) channel.close(); } catch (_) {}
      try { if (peer) peer.close(); } catch (_) {}
      stopStream(stream);
      channel = null; peer = null; stream = null;
      try { if (audioElement) { audioElement.onplaying = null; audioElement.pause(); audioElement.srcObject = null; } } catch (_) {}
    };
    return {
      begin() { generation += 1; disposeResources(); return generation; },
      current() { return generation; },
      isCurrent(id) { return id === generation; },
      setStream(id, value) {
        if (!this.isCurrent(id)) { stopStream(value); return false; }
        stream = value; return true;
      },
      setPeer(id, value) {
        if (!this.isCurrent(id)) { try { value.close(); } catch (_) {} return false; }
        peer = value; return true;
      },
      setChannel(id, value) {
        if (!this.isCurrent(id)) { try { value.close(); } catch (_) {} return false; }
        channel = value; return true;
      },
      addTimer(id, timer) {
        if (!this.isCurrent(id)) { clearTimeout(timer); return false; }
        timers.add(timer); return true;
      },
      removeTimer(timer) { timers.delete(timer); },
      dispose() { generation += 1; disposeResources(); return generation; }
    };
  }
  return { createLedger, createLifecycle };
});
