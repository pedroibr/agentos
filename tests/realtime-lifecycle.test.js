'use strict';
const assert = require('node:assert/strict');
const { createLifecycle } = require('../assets/agentos-realtime.js');

const audio = { paused: 0, srcObject: {}, pause() { this.paused += 1; } };
const lifecycle = createLifecycle(audio);
const makeStream = () => {
  const track = { stopCount: 0, stop() { this.stopCount += 1; } };
  return { track, stream: { getTracks() { return [track]; } } };
};
const makeResource = (onClose) => ({ closeCount: 0, close() { this.closeCount += 1; if (onClose) onClose(); } });

const firstGeneration = lifecycle.begin();
const first = makeStream();
assert.equal(lifecycle.setStream(firstGeneration, first.stream), true);
let staleCloseWasGuarded = false;
const peer = makeResource(() => { staleCloseWasGuarded = !lifecycle.isCurrent(firstGeneration); });
const channel = makeResource(() => { staleCloseWasGuarded = staleCloseWasGuarded && !lifecycle.isCurrent(firstGeneration); });
lifecycle.setPeer(firstGeneration, peer);
lifecycle.setChannel(firstGeneration, channel);
const timer = setTimeout(() => assert.fail('disposed timer fired'), 60000);
lifecycle.addTimer(firstGeneration, timer);

// Beginning a retry invalidates old callbacks/resources and owns only the newer capture.
const nextGeneration = lifecycle.begin();
assert.ok(nextGeneration > firstGeneration);
assert.equal(lifecycle.isCurrent(firstGeneration), false);
assert.equal(lifecycle.isCurrent(nextGeneration), true);
assert.equal(first.track.stopCount, 1);
assert.equal(peer.closeCount, 1);
assert.equal(channel.closeCount, 1);
assert.equal(staleCloseWasGuarded, true);
assert.equal(audio.paused, 2);
assert.equal(audio.srcObject, null);

const stale = makeStream();
assert.equal(lifecycle.setStream(firstGeneration, stale.stream), false);
assert.equal(stale.track.stopCount, 1);
const current = makeStream();
assert.equal(lifecycle.setStream(nextGeneration, current.stream), true);
const nextPeer = makeResource();
const nextChannel = makeResource();
lifecycle.setPeer(nextGeneration, nextPeer);
lifecycle.setChannel(nextGeneration, nextChannel);

lifecycle.dispose();
assert.equal(current.track.stopCount, 1);
assert.equal(nextPeer.closeCount, 1);
assert.equal(nextChannel.closeCount, 1);
assert.equal(lifecycle.isCurrent(nextGeneration), false);

console.log('Realtime lifecycle fake-resource replay passed');
