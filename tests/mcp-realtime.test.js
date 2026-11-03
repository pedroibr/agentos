'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { createRealtime } = require('../assets/agentos-tools.js');
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };
const call = (id = 'call1') => ({ type: 'function_call', call_id: id, name: 'mcp_lookup', arguments: '{}' });
const done = (id = 'r1', calls = [call()], status = 'completed') => ({ type: 'response.done', response: { id, status, output: calls } });
function setup(invoke) { const sent = [], invoked = []; const tools = createRealtime({ invoke: async c => { invoked.push(c); return invoke ? invoke(c) : { output: '{"ok":true}' }; }, send: e => sent.push(e) }); return { tools, sent, invoked }; }
test('waits for generation completion, deduplicates calls and continues once', async () => {
  const s = setup();
  s.tools.handle({ type: 'response.function_call_arguments.done', response_id: 'r1', ...call() , type: 'response.function_call_arguments.done' });
  assert.equal(s.invoked.length, 0);
  s.tools.handle(done()); s.tools.handle(done()); await s.tools.settled();
  assert.equal(s.invoked.length, 1); assert.deepEqual(s.sent.map(e => e.type), ['conversation.item.create', 'response.create']);
});
test('cancelled tool requests never execute', async () => {
  const s = setup(); s.tools.handle(done('r1', [call()], 'cancelled')); await s.tools.settled(); assert.equal(s.invoked.length, 0);
});
test('user interruption retains the result but suppresses an obsolete follow-up', async () => {
  const gate = deferred(); const s = setup(() => gate.promise); s.tools.handle(done()); await Promise.resolve();
  s.tools.handle({ type: 'input_audio_buffer.speech_started' }); gate.resolve({ output: '{"result":"arrived later"}' }); await s.tools.settled();
  assert.equal(s.sent.length, 1); assert.equal(s.sent[0].item.type, 'function_call_output');
});
test('multiple tools return matching call IDs and one continuation', async () => {
  const s = setup(); s.tools.handle(done('r1', [call('first'), call('second')])); await s.tools.settled();
  assert.deepEqual(s.sent.filter(e => e.item).map(e => e.item.call_id), ['first', 'second']); assert.equal(s.sent.filter(e => e.type === 'response.create').length, 1);
});
test('disposed sessions ignore late tool results', async () => {
  const gate = deferred(); const s = setup(() => gate.promise); s.tools.handle(done()); await Promise.resolve(); s.tools.dispose(); gate.resolve({ output: '{}' }); await s.tools.settled(); assert.equal(s.sent.length, 0);
});
test('a newer response suppresses continuation of an earlier pending lookup', async () => {
  const gate = deferred(); const s = setup(() => gate.promise); s.tools.handle(done()); await Promise.resolve(); s.tools.handle({ type: 'response.created', response: { id: 'new-response' } }); gate.resolve({ output: '{}' }); await s.tools.settled(); assert.equal(s.sent.filter(e => e.type === 'response.create').length, 0);
});
test('tool errors become explicit results and do not claim success', async () => {
  const s = setup(() => { throw new Error('private detail'); }); s.tools.handle(done()); await s.tools.settled(); assert.equal(JSON.parse(s.sent[0].item.output).isError, true); assert.equal(s.sent[0].item.output.includes('private detail'), false);
});
test('tool chains are bounded and end with tools disabled', async () => {
  const s = setup(); for (let i = 0; i < 6; i++) { s.tools.handle({ type: 'response.created', response: { id: 'r' + i } }); s.tools.handle(done('r' + i, [call('c' + i)])); await s.tools.settled(); }
  assert.equal(s.invoked.length, 5); assert.equal(s.sent.at(-1).response.tool_choice, 'none');
});
