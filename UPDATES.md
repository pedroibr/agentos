# Updates

## 10.5.2 - 2026-10-27

- Added opt-in, content-free input transcription diagnostics showing event order, VAD audio timestamps, completion state, and provider/ledger/archive/rendered character counts. Logs remain local to the browser console and follow the existing logging setting.
- Verified that delayed final input transcription replaces a short prefix after an assistant response. This diagnostic release does not claim to fix the reported microphone truncation and does not change delay, VAD, models, or agent settings.

## 10.5.1 - 2026-10-27

- Added an optional per-agent Transcription delay selector and passed supported values to the Realtime input transcription configuration. Existing agents retain the model default until a value is selected; turn detection and transcript rendering are unchanged.

## 10.5.0 - 2026-10-27

- Replaced browser-supplied cumulative token totals with an idempotent provider-usage ledger, preserving legacy totals as clearly marked estimates during migration.
- Counted exact Responses API input tokens before limited text requests, reserved the remaining output budget, and recorded actual response usage across MCP continuations, including tool-only responses.
- Enforced subscription assignment, expiry, per-period and per-session limits without allowing an exhausted plan to fall through to unmetered access; kept warning-only plans non-blocking and visible.
- Captured actual Realtime response and transcription usage from provider events in browser-reported batches every 60 seconds, with heartbeat checks, retries, drain-on-stop/save/New-chat, and per-session local cutoff.
- Kept transcription duration in seconds, bounded text dictation clips to 60 seconds, capped each Realtime output, and marked missing final usage as incomplete rather than zero.
- Added dedupe, ownership, reservation, migration, expiry, interrupted-response, MCP, and lifecycle regressions plus optional synthetic WP-CLI database and REST/OpenAI-mock smoke scripts.
- Voice usage is operationally counted from the browser's provider usage events. Without a persistent server-side Realtime observer, a modified or disconnected client can omit reports and a response already in progress can exceed remaining quota; this release does not claim a tamper-proof hard voice ceiling.

## 10.4.2 - 2026-10-27

- Switched live Realtime conversation transcription to `gpt-live-transcribe` and used the supported `languages` array for explicit language hints.
- Preserved automatic language detection and tutor transcription prompts while retaining existing conversation VAD and noise reduction settings.
- Kept audio file uploads on `gpt-4o-mini-transcribe`.
- Added regression coverage for Portuguese hints, automatic language detection, tutor prompts and the unchanged file upload model.

## 10.4.1 - 2026-10-27

- Added connection-specific instructions for each selected MCP group while keeping existing global MCP instructions intact.
- Added safe MCP failure references and administrator diagnostics with phase, tool, connection, HTTP/remote codes, and form-schema field names; arguments, result bodies, endpoint paths, and credentials are excluded.
- Preserved remote validation codes and gave the model a safe correction target without exposing submitted values.
- Documented incremental form conversations and international phone handling while preserving schema-version, review-token, and explicit-confirmation submission gates.
- Added save/read, selected/disabled-connection, transport-error, redaction, cross-turn schema and phone-country regression coverage.

## 10.4.0 - 2026-10-27

- Added central MCP connections with public HTTPS Streamable HTTP discovery and optional encrypted bearer credentials.
- Added explicit tool selection, execution approval policies and extra tool instructions to each agent.
- Connected MCP tools to text and voice through server-side function execution, keeping connection credentials out of browser sessions.
- Added conversation-bound grants, approval receipts, duplicate-call protection and bounded text/voice tool continuations that preserve interruption behavior.
- Added MCP protocol, access, approval, continuation and frontend regression coverage. See `docs/MCP.md` for setup and current scope.

## 10.3.5 - 2026-10-27

- Preserved interrupted assistant text in its original conversation position, including saved and reopened transcripts.
- Separated visible/archive history from resumable model context, marking interrupted or incomplete entries without deleting their received text.
- Kept regular streaming captions free of interruption labels and prevented late cancellation or item metadata from moving or replacing frozen text.
- Included interruption markers in post-session analysis while excluding unconfirmed speech from text-response context.

## 10.3.4 - 2026-10-27

- Reworked voice transcript handling around Realtime item IDs and authoritative completion events, preserving legitimate repeated speech.
- Added voice connection and playback lifecycle diagnostics, improved microphone cleanup, and prevented stale session events from affecting new conversations.
- Updated the default Realtime Mini model to `gpt-realtime-2.1-mini`; original Mini aliases and the retired October 2025 snapshot resolve to the new default while other configured models remain selectable.
- Added offline regression coverage for voice events and model selection.

## 10.3.3 - 2026-06-02

- Updated Realtime voice session initialization to use the current OpenAI client secrets and WebRTC calls endpoints.
- Preserved the existing tutor voice, transcription, noise reduction, and turn detection settings while migrating the request payload shape.

## 10.3.2 - 2026-04-28

- Reworked the mobile session layout to keep the header and footer anchored while the conversation area fills the available height and scrolls independently.
- Compacted the mobile voice controls into a single fixed row with simpler play, stop, and save icons.

## 10.3.1 - 2026-04-28

- Limited sidebar session history to authenticated users and synchronized saved sessions by WordPress account across devices.
- Kept anonymous session saves available for backend/admin review while hiding them from the frontend conversation list.

## 10.3.0 - 2026-03-21

- Fixed transcript auto-scroll behavior during long responses, stop/resume, and session view switches.
- Improved realtime voice session stability with better turn tuning, noise handling, and transcription controls.
- Added advanced voice agent settings for turn detection, eagerness, noise reduction, language hint, and transcription hint.
- Prevented transcription hints from leaking into visible user messages.
- Reduced repeated or looping assistant openings at the start of voice sessions.
