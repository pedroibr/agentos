'use strict';
const assert = require('node:assert/strict');
const { createLedger } = require('../assets/agentos-realtime.js');

const changes = [];
const ledger = createLedger((entry, meta) => changes.push({ entry, meta }));
const send = (event) => ledger.handle(event);

// Placeholder metadata is retained without UI output; restored/text/tool items do not become speech bubbles.
const beforePlaceholders = changes.length;
assert.equal(send({ type: 'conversation.item.added', item: { id: 'typed', type: 'message', role: 'user', content: [{ type: 'input_text', text: 'hello there' }] } }).kind, 'ignored-non-audio-user-item');
assert.equal(send({ type: 'conversation.item.added', item: { id: 'tool', type: 'function_call', role: 'assistant' } }).kind, 'ignored-item-type');
assert.equal(changes.length, beforePlaceholders);

// Legitimate repetition remains two distinct user items.
send({ type: 'conversation.item.added', item: { id: 'u1', type: 'message', role: 'user', content: [{ type: 'input_audio' }] }, previous_item_id: 'root' });
send({ type: 'conversation.item.added', item: { id: 'u2', type: 'message', role: 'user', content: [{ type: 'input_audio' }] }, previous_item_id: 'u1' });
send({ type: 'conversation.item.input_audio_transcription.completed', item_id: 'u1', transcript: 'hello there' });
send({ type: 'conversation.item.input_audio_transcription.completed', item_id: 'u2', transcript: 'hello there' });
assert.deepEqual(ledger.entries().map((item) => item.text), ['hello there', 'hello there']);

// ASR final may arrive after another item's final. Order comes from item metadata, not ASR events.
send({ type: 'conversation.item.added', item: { id: 'u3', type: 'message', role: 'user', content: [{ type: 'input_audio' }] }, previous_item_id: 'u2' });
send({ type: 'conversation.item.added', item: { id: 'u4', type: 'message', role: 'user', content: [{ type: 'input_audio' }] }, previous_item_id: 'u3' });
send({ type: 'input_audio_buffer.committed', item_id: 'u3' });
send({ type: 'conversation.item.input_audio_transcription.completed', item_id: 'u4', transcript: 'second' });
send({ type: 'conversation.item.input_audio_transcription.delta', item_id: 'u3', delta: 'go to the ' });
send({ type: 'conversation.item.input_audio_transcription.completed', item_id: 'u3', transcript: 'go to the store' });
const ordered = ledger.entries();
assert.ok(ordered.findIndex((item) => item.itemId === 'u3') < ordered.findIndex((item) => item.itemId === 'u4'));
assert.equal(ordered.find((item) => item.itemId === 'u3').text, 'go to the store');

// Delta plus authoritative done is one item; tool argument events are ignored.
send({ type: 'response.created', response: { id: 'r1' } });
send({ type: 'conversation.item.added', item: { id: 'a1', type: 'message', role: 'assistant', content: [{ type: 'output_audio' }] }, response_id: 'r1', previous_item_id: 'u4' });
send({ type: 'response.output_audio_transcript.delta', item_id: 'a1', response_id: 'r1', delta: 'Good ' });
send({ type: 'response.function_call_arguments.delta', item_id: 'tool1', delta: '{"secret":1}' });
send({ type: 'response.output_audio_transcript.done', item_id: 'a1', response_id: 'r1', transcript: 'Good morning.' });
assert.equal(ledger.entries().filter((item) => item.itemId === 'a1').length, 1);
assert.equal(ledger.entries().find((item) => item.itemId === 'a1').text, 'Good morning.');
assert.equal(ledger.entries().some((item) => item.itemId === 'tool1'), false);

// A server event replay is idempotent.
const beforeDuplicate = changes.length;
send({ type: 'conversation.item.input_audio_transcription.completed', event_id: 'evt-final-u4', item_id: 'u4', transcript: 'second corrected' });
send({ type: 'conversation.item.input_audio_transcription.completed', event_id: 'evt-final-u4', item_id: 'u4', transcript: 'second corrected' });
assert.equal(changes.length, beforeDuplicate + 1);

// Cancelled story text remains in its original item and is frozen against late events.
send({ type: 'response.created', response: { id: 'story-r' } });
send({ type: 'response.output_item.added', response_id: 'story-r', item: { id: 'story-a', type: 'message', role: 'assistant', content: [{ type: 'output_audio' }] } });
send({ type: 'response.output_audio_transcript.delta', item_id: 'story-a', response_id: 'story-r', delta: 'The library was quiet. ' });
send({ type: 'input_audio_buffer.committed', item_id: 'stop-u' });
send({ type: 'conversation.item.input_audio_transcription.delta', item_id: 'stop-u', delta: 'Stop' });
send({ type: 'response.cancelled', response: { id: 'story-r' } });
const frozenStory = ledger.entries().find((item) => item.itemId === 'story-a');
assert.equal(frozenStory.text, 'The library was quiet. ');
assert.equal(frozenStory.interrupted, true);
assert.equal(frozenStory.resumeEligible, false);
const textBeforeLateEvents = frozenStory.text;
send({ type: 'conversation.item.truncated', item_id: 'story-a', audio_end_ms: 400 });
send({ type: 'conversation.item.done', item: { id: 'story-a', type: 'message', role: 'assistant', content: [] }, previous_item_id: 'stop-u' });
send({ type: 'response.output_audio_transcript.done', item_id: 'story-a', response_id: 'story-r', transcript: 'a late final that must not replace the snapshot' });
assert.equal(ledger.entries().find((item) => item.itemId === 'story-a').text, textBeforeLateEvents);
assert.equal(ledger.entries().filter((item) => item.itemId === 'story-a').length, 1);

// A pending user final may arrive after Stop. Keep its position and mark it incomplete, not resumable.
assert.equal(ledger.markPendingInterrupted() >= 1, true);
const pendingUser = ledger.entries().find((item) => item.itemId === 'stop-u');
assert.equal(pendingUser.partial, true);
assert.equal(pendingUser.resumeEligible, false);
send({ type: 'conversation.item.input_audio_transcription.completed', item_id: 'stop-u', transcript: 'Stop now please' });
assert.equal(ledger.entries().find((item) => item.itemId === 'stop-u').text, 'Stop now please');
assert.equal(ledger.entries().find((item) => item.itemId === 'stop-u').partial, true);
assert.equal(ledger.entries().find((item) => item.itemId === 'stop-u').interrupted, false);

// A fresh acknowledgement does not inherit interruption state; completed audio can later be truncated.
send({ type: 'response.created', response: { id: 'ack-r' } });
send({ type: 'response.output_item.added', response_id: 'ack-r', item: { id: 'ack-a', type: 'message', role: 'assistant' } });
send({ type: 'response.output_audio_transcript.delta', item_id: 'ack-a', response_id: 'ack-r', delta: 'I heard you.' });
send({ type: 'response.output_audio_transcript.done', item_id: 'ack-a', response_id: 'ack-r', transcript: 'I heard you.' });
send({ type: 'response.done', response: { id: 'ack-r', status: 'completed' } });
assert.equal(ledger.entries().find((item) => item.itemId === 'ack-a').interrupted, false);
assert.equal(ledger.entries().find((item) => item.itemId === 'ack-a').resumeEligible, true);
send({ type: 'conversation.item.truncated', item_id: 'ack-a', audio_end_ms: 200 });
assert.equal(ledger.entries().find((item) => item.itemId === 'ack-a').text, 'I heard you.');
assert.equal(ledger.entries().find((item) => item.itemId === 'ack-a').interrupted, true);

// Generation completion and playback drain are separate; Stop preserves the text but excludes it from resume.
send({ type: 'response.created', response: { id: 'r4' } });
send({ type: 'response.output_audio_transcript.done', item_id: 'a4', response_id: 'r4', transcript: 'not heard yet' });
assert.equal(send({ type: 'response.done', response: { id: 'r4', status: 'completed' } }).kind, 'response-done');
assert.equal(send({ type: 'response.done', response: { id: 'r4', status: 'completed' } }).duplicate, true);
ledger.discardUnplayedResponses();
assert.equal(ledger.entries().find((item) => item.itemId === 'a4').text, 'not heard yet');
assert.equal(ledger.entries().find((item) => item.itemId === 'a4').resumeEligible, false);
send({ type: 'response.created', response: { id: 'r5' } });
assert.equal(send({ type: 'output_audio_buffer.started', response_id: 'r5' }).kind, 'playback-started');
assert.equal(send({ type: 'output_audio_buffer.stopped', response_id: 'r5' }).kind, 'playback-stopped');
assert.equal(send({ type: 'input_audio_buffer.speech_started' }).kind, 'speech-started');
assert.equal(send({ type: 'input_audio_buffer.speech_stopped' }).kind, 'speech-stopped');
console.log('Realtime ledger replay passed');
