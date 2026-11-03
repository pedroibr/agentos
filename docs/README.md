# agentos – Dynamic AI Agents for WordPress

## Directory Map

- `agentos.php` – bootstrap file; defines constants, registers the PSR‑4 autoloader, and boots the plugin container.
- `src/` – namespaced PHP services:
  - `Core/` – shared config, settings, and agent repository logic.
  - `Admin/` – admin controllers and view helper.
  - `Frontend/` – shortcode renderer.
  - `Rest/` – REST controllers.
  - `Assets/` – script/style registration.
  - `Database/` – transcript persistence.
- `templates/` – PHP view partials for admin pages and the public shortcode.
- `assets/` – enqueueable scripts/styles (no build step yet). `agentos-embed.css` exposes design tokens via CSS variables for easy theming.
- `docs/` – operational docs (this file, SOPs, etc.).

## Admin UX

- **AgentOS → MCP Connections**: register remote MCP services, discover tools, then select tools and approval policies on each agent. See [MCP setup and scope](MCP.md).
- **AgentOS → Settings**: choose the API key source (ENV, PHP constant, or manual), configure allowed context query parameters, and enable optional debug logging.
- **AgentOS → Settings** also controls the external Integration API: enable/disable provisioning access and generate a Bearer API key for automation tools.
- **AgentOS → Agents**: create any number of agents, set their default model/voice/mode, choose which post types they run on, map ACF/meta fields for model, voice, system prompt, and user prompt, toggle the live transcript block, and now opt into post-session analysis by supplying a default model + system prompt (with an auto-run option).
- **AgentOS → Sessions** *(new in v0.6.0)*: browse saved transcripts, filter by agent/post/status/email, inspect individual sessions (transcript + analysis feedback), and queue/re-run AI analysis with optional per-run prompt/model overrides.
- **AgentOS → Subscriptions** *(new in v0.7.x)*: define reusable usage plans with per-period token/session caps, agent allow-lists, optional per-session hard stops, and toggle whether overages block or merely warn.
- **AgentOS → Users** *(new in v0.8.x)*: browse managed users, jump into a detail view for usage history + subscriptions, create users from email (auto-linking WordPress accounts), and manage assignments without leaving the page.

## Shortcode

- `[agentos id="agent-slug"]`
- Optional overrides: `mode="text"` or `mode="both"`, `height="60vh"`

Context params (from Settings) continue to pass URL values through to the generated instructions, e.g. `?nome=...&produto=...&etapa=...`.

## Voice sessions (10.5.0)

- The default voice model is `gpt-realtime-2.1-mini`. The original `gpt-realtime-mini` alias and `gpt-realtime-mini-2025-10-06` snapshot resolve to this model when agents or mapped post settings are read. Other explicitly selected models are preserved. Text and analysis models remain configured separately.
- The WordPress server creates an ephemeral client secret; audio travels between the browser and OpenAI over WebRTC. The browser requests acoustic echo cancellation, noise suppression, and automatic gain control. Actual device/browser settings are reported when diagnostic logging is enabled.
- Voice captions use item-scoped Realtime transcription events, including authoritative final transcripts. Repeated words or phrases are valid input and are not suppressed by comparing them with the assistant's speech.
- Input remains open during assistant playback for natural interruptions. Cancellation and truncation events must be reflected in the conversation rather than using text similarity as an echo filter. Real acoustic behavior still needs testing on the intended speakers, microphone, and browser.
- For diagnostics, enable **AgentOS → Settings → Enable console logging** and inspect `[AgentOS]` connection, capture, playback, item, and error events. Client secrets and full transcript payloads must not be logged.
- Install development test dependencies with `npm ci`, then run `npm test`. These dependencies are not shipped with the WordPress plugin. See `docs/SOPs/test.md` for the physical voice test matrix.

OpenAI references: [WebRTC](https://developers.openai.com/api/docs/guides/voice-webrtc?api=realtime), [Realtime events](https://developers.openai.com/api/reference/resources/realtime/server-events), [GPT-Realtime-2.1 Mini](https://developers.openai.com/api/docs/models/gpt-realtime-2.1-mini).

### Usage accounting and limits

Text requests on a finite plan use OpenAI's Responses input-token count endpoint before generation. AgentOS reserves those exact input tokens and the remaining output budget, applies a minimum `max_output_tokens` of 16 supported by the Responses API, and records the provider's actual response usage. A request with less than 16 output tokens available is rejected before generation. MCP tool continuations follow the same path, and each model response is recorded even when it only requests a tool or returns no visible text. The browser's cumulative counters are not used for quota decisions.

Realtime voice keeps direct browser-to-OpenAI WebRTC. After each Realtime response or ASR event, the browser records the provider's reported token counts or transcription duration (seconds); it sends idempotent batches and an empty quota heartbeat every 60 seconds. It flushes before MCP follow-up and when pausing, saving, or starting a new conversation. A final interrupted response gets a short window to return its usage before teardown. Audio dictation in text mode stops after 60 seconds, and Realtime responses are capped at 2,048 output tokens.

Realtime usage is marked **Browser reported** and counted operationally for plan limits. WordPress periodically checks subscription state and returns the remaining allowance; each browser cuts off when its known allowance is used. Because an ordinary WordPress request cannot observe a direct WebRTC stream continuously, a modified or disconnected browser can omit reports, and an in-flight response can exceed the remaining quota. Treat this as an operational quota with a small possible overrun, not a tamper-proof spending ceiling. No Node runtime or separate service is required.

The ledger keeps legacy session totals as estimated usage during migration, separate from provider-measured events. Realtime/ASR usage details are retained when the provider supplies them. ASR duration remains seconds and is never converted into invented tokens. Missing final usage is marked incomplete rather than counted as zero. A plan with overage blocking disabled shows a warning and may continue; agent-level session caps remain blocking.

### Voice configuration practices

On 2026-10-27, Pedro reported a good experience with this setup in the `mcp-demo` agent. Keep it as a tested starting point for Portuguese voice agents:

| Setting | Tested value |
| --- | --- |
| Turn detection type | `semantic_vad` |
| Turn eagerness | `medium` |
| Microphone environment | **Use default** (currently resolves to `near_field`, for a headset or close microphone) |
| Speech language hint | Portuguese (`pt`) |
| Transcription hint | Empty |
| Transcription delay | `high` |

The `high` delay is the value in the AgentOS selector, not a number of milliseconds. It gives transcription more context before text is displayed and may increase caption latency. Pedro reported that the overall setup felt good in use; this is a user-reported result, not a controlled comparison or a guarantee for every microphone, browser, speaking style or agent. The microphone choice should match the actual device: use the close-mic setting for a headset/near microphone and the room/laptop setting for a more distant built-in microphone.

This record documents a successful configuration; it does not change plugin defaults or other agents' saved settings.

Set the two language controls separately: **Speech language hint** guides recognition of the user's speech; the effective **system instructions** set the assistant's response language. For example:

```text
Você é Jonny, um assistente prestativo. Responda em português brasileiro. Só mude de idioma quando o usuário pedir explicitamente. Se não entender uma fala, peça para repetir, sem adivinhar.
```

Put the response-language instruction in **Fallback Instructions**, or in the mapped system prompt when one is supplied; a mapped system prompt replaces the fallback. Choose the intended language explicitly for other agents, such as an English tutor.

`Turn eagerness` controls when a speech turn ends, rather than transcription accuracy directly. Start with the tested `medium` setting and adjust only when pauses or response timing call for it. Save changes and start a new conversation to test the updated session configuration. Keep the agent, page, microphone and spoken phrases consistent when comparing settings; include names, numbers and natural pauses. API acceptance and automated event tests do not establish real microphone accuracy.

References: [Semantic VAD](https://developers.openai.com/api/docs/guides/realtime-vad#semantic-vad), [response-language instructions](https://developers.openai.com/api/docs/guides/voice-prompting#control-language-and-accent-separately).

### Interrupted conversation history (10.3.5)

Received assistant text stays visible in its original position when speech is interrupted. Saved transcripts retain the same text with `interrupted: true` and `resumeEligible: false`; unfinished user captions use `partial: true` and `resumeEligible: false`. These flags are displayed as labels, separate from message text, when viewing either current or saved sessions. They also distinguish incomplete turns in post-session analysis.

Resuming voice or building text-response input excludes these unconfirmed entries. This preserves what was displayed without asserting that every generated word was heard. Realtime's audio truncation timestamp does not provide word-aligned captions; the interrupted caption is a snapshot of the text received before interruption, not a precise transcription of speaker playback.

## Block

- Insert the **AgentOS Agent** block from the block inserter (Widgets category) to embed an agent without memorizing the shortcode.
- Block controls surface the same attributes: agent selection, optional mode override, and transcript height.
- The block renders through the same shortcode pipeline, so the front-end experience and asset loading stay consistent.

## Design & Theming

- Front-end styling lives in `assets/agentos-embed.css`. The root `.agentos-wrap` element defines CSS custom properties (e.g. `--agentos-bg`, `--agentos-accent`, `--agentos-radius`, `--agentos-transcript-height`) that themes can override to re-skin the widget without editing plugin files.
- Each structural block has a predictable class: `.agentos-toolbar`, `.agentos-text-ui`, `.agentos-transcript`, etc. Optional panes (like the transcript) are conditionally rendered and controlled via per-agent toggles.
- Scripts and styles are registered separately, so developers can `wp_dequeue_style('agentos-embed')` or replace assets if needed.

## REST Endpoints

- POST `/wp-json/agentos/v1/realtime-token` (requires `post_id` + `agent_id`).
- POST `/wp-json/agentos/v1/transcript-db` (requires `post_id`, `agent_id`, `session_id`, transcript payload). When the owning agent has analysis auto-run enabled, the transcript is queued for background analysis automatically.
- GET `/wp-json/agentos/v1/transcript-db?post_id=...&agent_id=...` supports optional filters: `limit`, `status` (`queued|running|succeeded|failed|idle`), `anon_id`, and (admin-only) `user_email`. Non-admin requests have email/user-agent details stripped from the response.
- When a guest saves a transcript, a valid `email` query parameter on the page is stored as its contact email, independently of the agent's context parameters. This is unverified contact information, not proof of identity or an account; it does not grant access to history or subscriptions. A signed-in user's WordPress account email always takes priority. URL parameters may be visible in browser history and server logs.
- POST `/wp-json/agentos/v1/usage/session` (frontend + admin) validates the server-issued session ticket and records lifecycle status/reason even when no transcript is saved. It returns stored ledger totals and ignores client-supplied token counters.
- POST `/wp-json/agentos/v1/usage/report` (frontend session ticket + nonce) records idempotent Realtime response/ASR usage batches and accepts empty 60-second heartbeats to recheck subscription state. Its `browser_reported` provenance is operationally counted but is not independent server verification.
- GET `/wp-json/agentos/v1/subscriptions/user?user_key=...` (admin only) retrieves current subscription assignments plus usage totals for the relevant windows.
- POST `/wp-json/agentos/v1/subscriptions/user` (admin only) assigns subscriptions to a user key. Payload accepts `user_key`, optional `meta` (`label`, `email`, `notes`), an array of `{slug, expires_at, overrides}` structures, and optional `replace=true` to overwrite existing assignments.
- GET `/wp-json/agentos/v1/provision/user?user_key=...` or `?email=...` (Integration API) retrieves the AgentOS user profile, assigned subscriptions, and aggregate usage totals.
- POST `/wp-json/agentos/v1/provision/user` (Integration API) creates/syncs an AgentOS user record for an existing WordPress account. Payload accepts `user_key` or `email`, plus optional `meta` (`name`, `email`, `notes`).
- DELETE `/wp-json/agentos/v1/provision/user` (Integration API) removes the AgentOS user record, subscription assignments, usage log entries, and saved transcripts for the target `user_key`/`email`.
- POST `/wp-json/agentos/v1/provision/user/subscriptions` (Integration API) assigns subscriptions to a provisioned user. Payload accepts `user_key` or `email`, optional `meta`, an array of `{slug, expires_at, overrides}`, and optional `replace=true`.
- DELETE `/wp-json/agentos/v1/provision/user/subscriptions` (Integration API) removes one or more subscriptions from a provisioned user using `subscription_slug` or `subscription_slugs`.

Admin-only endpoints to fetch a single transcript or trigger analysis live inside the WordPress dashboard and reuse the same repository layer.

### Integration API Auth

- Enable the Integration API and generate a key under **AgentOS → Settings**.
- Send the key in the request header:

```http
Authorization: Bearer agtos_...
Content-Type: application/json
```

- Integration API endpoints are intended for server-to-server automation. They do not use `X-WP-Nonce`.

## Development Workflow

1. Install dependencies you prefer (Composer optional) and ensure PHP 8.1+ is available.
2. For linting, install WordPress Coding Standards and run `phpcs --standard=phpcs.xml.dist`.
3. When editing PHP, favor the services in `src/` and keep templates logic-light.
4. Keep translations ready via `load_plugin_textdomain()` and wrap new strings in translation functions.
