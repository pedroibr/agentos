(function(root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.AgentOSTools = api;
})(typeof window !== 'undefined' ? window : this, function() {
  'use strict';

  // Function calls are collected until response.done. Generation completion is
  // distinct from remote execution, and a later user turn supersedes continuation.
  function createRealtime(options) {
    const responses = new Map();
    const executed = new Set();
    let disposed = false;
    let chainLength = 0;
    let queue = Promise.resolve();

    function interrupt() {
      responses.forEach(response => { response.superseded = true; });
      chainLength = 0;
      if (options.cancelApproval) options.cancelApproval();
    }

    function handle(event) {
      if (disposed || !event) return;
      if (event.type === 'input_audio_buffer.speech_started') { interrupt(); return; }
      if (event.type === 'response.created') {
        const id = event.response && event.response.id;
        let superseded = false;
        responses.forEach((response, key) => { if (key !== id) { response.superseded = true; superseded = true; } });
        if (superseded && options.cancelApproval) options.cancelApproval();
        if (id && !responses.has(id)) responses.set(id, { calls: new Map(), superseded: false, running: false });
        return;
      }
      const id = event.response_id || (event.response && event.response.id);
      if (!id) return;
      let response = responses.get(id);
      if (!response) { response = { calls: new Map(), superseded: false, running: false }; responses.set(id, response); }
      if (event.type === 'response.function_call_arguments.done') {
        if (event.call_id) response.calls.set(event.call_id, { call_id: event.call_id, name: event.name, arguments: event.arguments });
        return;
      }
      if (event.type !== 'response.done' || response.running) return;
      response.running = true;
      if ((event.response || {}).status !== 'completed') { response.superseded = true; response.calls.clear(); return; }
      ((event.response || {}).output || []).forEach(item => {
        if (item && item.type === 'function_call' && item.call_id) response.calls.set(item.call_id, item);
      });
      const calls = [...response.calls.values()].filter(call => !executed.has(call.call_id));
      if (!calls.length) { chainLength = 0; return; }
      chainLength += 1;
      const limited = chainLength > 5 || calls.length > 8;
      calls.forEach(call => executed.add(call.call_id));
      queue = queue.then(async () => {
        // Flush provider usage before running tools or requesting another response.
        if (options.settle && !(await options.settle(event))) {
          if (options.error) options.error('usage_unavailable');
          return;
        }
        for (const call of calls) {
          if (disposed) return;
          let result;
          try {
            result = response.superseded || limited
              ? { output: JSON.stringify({ isError: true, message: limited ? 'Tool limit reached.' : 'The user interrupted before this tool could run.' }) }
              : await options.invoke(call);
          } catch (_) {
            result = { output: JSON.stringify({ isError: true, message: 'The tool could not be completed. Do not claim it succeeded.' }) };
          }
          if (disposed) return;
          options.send({ type: 'conversation.item.create', item: { type: 'function_call_output', call_id: call.call_id, output: String(result.output || '{}') } });
        }
        if (!disposed && !response.superseded) {
          const next = limited ? { type: 'response.create', response: { tool_choice: 'none' } } : { type: 'response.create' };
          if (options.authorizeNext && !(await options.authorizeNext(next))) {
            if (options.error) options.error('usage_limit');
            return;
          }
          options.send(next);
        }
      }).catch(() => { if (!disposed && options.error) options.error(); });
    }

    return {
      handle,
      interrupt,
      dispose() { disposed = true; interrupt(); responses.clear(); },
      settled() { return queue; },
    };
  }

  return { createRealtime };
});
