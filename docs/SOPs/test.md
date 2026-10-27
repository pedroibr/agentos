- Activate plugin.
- Settings → AgentOS: set API key source, post types, and field maps.
- Agents: ensure “Display transcript panel” toggle behaves in both enabled/disabled states.
- Add `[agentos mode="voice"]` and `[agentos mode="text"]` to test posts; confirm toolbar, composer, and transcript blocks render correctly on desktop and mobile breakpoints.
- Verify console shows no JS errors and `phpcs --standard=phpcs.xml.dist` passes.

## Voice regression checks (10.3.4)

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
