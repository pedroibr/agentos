- Activate plugin.
- Settings → AgentOS: set API key source, post types, and field maps.
- Agents: ensure “Display transcript panel” toggle behaves in both enabled/disabled states.
- Add `[agentos mode="voice"]` and `[agentos mode="text"]` to test posts; confirm toolbar, composer, and transcript blocks render correctly on desktop and mobile breakpoints.
- Verify console shows no JS errors and `phpcs --standard=phpcs.xml.dist` passes.

## MCP checks (10.4.0)

Run `npm test` for the protocol, server grant, text continuation and voice tool regressions. With a public read-only MCP such as OpenAI Docs, create a connection, discover its tools and enable one tool on a test agent. In both text and voice, request a lookup, approve it, then repeat with a refusal. Confirm credentials never appear in page source, session configuration or console logs. Stop and start a new conversation during a lookup; late results must not change the new conversation. Verify the same call ID is executed once and that unselected tools are rejected. Check the approval panel at desktop and mobile widths.

Connections use a shared site credential: verify that the selected tools and upstream account permissions are appropriate for every user who can access that agent. See [MCP.md](../MCP.md).

## Usage and subscription accounting (10.5.0)

Run `npm test` for offline ledger, limiter, exact-input preflight, MCP continuation, batch retry, warning-only and voice lifecycle regressions. When a WordPress test site is available, run the optional synthetic database checks from the plugin root:

```sh
wp eval-file tests/usage-ledger-wpcli.php
wp eval-file tests/usage-rest-wpcli.php
```

The database script creates only uniquely named synthetic usage rows. The REST script creates a temporary WordPress user and post, intercepts only the Responses input-count and generation URLs with `pre_http_request` fixtures, and cleans up in `finally`; it must not be pointed at a site where those temporary operations are prohibited. It never needs a real OpenAI key or paid generation. Confirm that two messages continue within `max_sessions=1`, a new session is blocked, a bad ticket is rejected before provider calls, browser totals do not affect the ledger, and a request with under 16 output tokens is blocked before generation.

For an authorized browser test with a finite voice plan, check that the initial token grant reports the allowance, real Realtime `response.done.usage` and ASR usage appear as **Browser reported**, heartbeats run every 60 seconds even during silence, interrupted responses retain their final usage when delivered, and Stop/Save/New chat drains pending batches. Check that MCP follow-up flushes before asking the model for another response. Test a warning-only plan separately: overage should show in the status region without cutting off voice; an agent session cap should still stop it. ASR duration is stored in seconds, not converted to tokens.

The portable Realtime flow relies on client-reported provider usage because ordinary PHP requests do not observe the direct WebRTC connection. A disconnected or modified client can omit reports, and an already running response may cross the remaining quota before the next 60-second heartbeat; describe this as operational quota enforcement with a possible small overrun, never as a tamper-proof ceiling. Missing final provider usage is marked incomplete.

## Voice regression checks (10.5.0)

Run offline regressions before uploading:

```sh
npm ci
npm test
node --check assets/agentos-realtime.js
node --check assets/agentos-embed.js
```

On the intended browser and audio devices, check:

1. Start voice with headphones, then with built-in speakers at normal volume. Confirm playback and input transcription. Inspect the applied echo-cancellation settings in diagnostic logs.
2. Stay silent while the assistant speaks, including its initial greeting. Check for unintended input turns or self-interruptions.
3. Interrupt intentionally and confirm natural recovery. A cancelled response must not reappear later or be restored as fully heard speech.
4. Repeat the assistant's exact sentence, then repeat your own sentence in another turn. Both valid user turns must remain visible.
5. Speak a long sentence with pauses. Confirm a final correction updates the same message rather than creating duplicate or out-of-order bubbles.
6. Switch between current conversation and a saved conversation during voice activity. Return to the current view and verify live updates continue.
7. Pause/resume, save, and start a new chat. Verify the microphone stops when appropriate and late events cannot affect the new conversation.
8. Deny microphone permission, disconnect the network, and retry. Confirm a useful error and no lingering microphone indicator. Check browser playback restrictions when audio is blocked.
9. In hybrid mode, try sending before connecting and after disconnecting. The text must remain available when delivery is rejected.
10. Repeat key flows on mobile and with Portuguese and English speech. Refresh after deployment to load the changed assets.
11. Ask for a story and interrupt with "Stop". The received story text must stay between the original request and the interruption, labelled Interrupted; the next acknowledgement must remain a separate normal message.
12. Repeat the interruption, save, and reopen the session. Confirm both interrupted snapshots remain in chronological history, and that resuming does not replay their unconfirmed generated text as completed speech.

Synthetic events and credential/session-creation checks do not establish acoustic echo performance; record the browser, audio device, output volume, and exact turn that failed when collecting user feedback.
