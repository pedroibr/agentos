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

- **AgentOS → Settings**: choose the API key source (ENV, PHP constant, or manual), configure allowed context query parameters, and enable optional debug logging.
- **AgentOS → Agents**: create any number of agents, set their default model/voice/mode, choose which post types they run on, map ACF/meta fields for model, voice, system prompt, and user prompt, toggle the live transcript block, and now opt into post-session analysis by supplying a default model + system prompt (with an auto-run option).
- **AgentOS → Sessions** *(new in v0.6.0)*: browse saved transcripts, filter by agent/post/status/email, inspect individual sessions (transcript + analysis feedback), and queue/re-run AI analysis with optional per-run prompt/model overrides.
- **AgentOS → Subscriptions** *(new in v0.7.x)*: define reusable usage plans with per-period token/session caps, agent allow-lists, optional per-session hard stops, and toggle whether overages block or merely warn.
- **AgentOS → Users** *(new in v0.8.x)*: browse managed users, jump into a detail view for usage history + subscriptions, create users from email (auto-linking WordPress accounts), and manage assignments without leaving the page.

## Shortcode

- `[agentos id="agent-slug"]`
- Optional overrides: `mode="text"` or `mode="both"`, `height="60vh"`

Context params (from Settings) continue to pass URL values through to the generated instructions, e.g. `?nome=...&produto=...&etapa=...`.

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
- POST `/wp-json/agentos/v1/usage/session` (frontend + admin) records session usage metrics even when transcripts are not saved. Payload includes `session_id`, token counts, duration, optional `subscription_slug`, and `_wpnonce`.
- GET `/wp-json/agentos/v1/subscriptions/user?user_key=...` (admin only) retrieves current subscription assignments plus usage totals for the relevant windows.
- POST `/wp-json/agentos/v1/subscriptions/user` (admin only) assigns subscriptions to a user key. Payload accepts `user_key`, optional `meta` (`label`, `email`, `notes`), an array of `{slug, expires_at, overrides}` structures, and optional `replace=true` to overwrite existing assignments.

Admin-only endpoints to fetch a single transcript or trigger analysis live inside the WordPress dashboard and reuse the same repository layer.

## Development Workflow

1. Install dependencies you prefer (Composer optional) and ensure PHP 8.1+ is available.
2. For linting, install WordPress Coding Standards and run `phpcs --standard=phpcs.xml.dist`.
3. When editing PHP, favor the services in `src/` and keep templates logic-light.
4. Keep translations ready via `load_plugin_textdomain()` and wrap new strings in translation functions.
