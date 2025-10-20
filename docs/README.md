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

- **AgentOS → Settings**: choose the API key source (ENV, PHP constant, or manual) and configure allowed context query parameters.
- **AgentOS → Agents**: create any number of agents, set their default model/voice/mode, choose which post types they run on, map ACF/meta fields for model, voice, system prompt, and user prompt, and toggle whether the live transcript block appears on the front end.

## Shortcode

- `[agentos id="agent-slug"]`
- Optional overrides: `mode="text"` or `mode="both"`, `height="60vh"`

Context params (from Settings) continue to pass URL values through to the generated instructions, e.g. `?nome=...&produto=...&etapa=...`.

## Design & Theming

- Front-end styling lives in `assets/agentos-embed.css`. The root `.agentos-wrap` element defines CSS custom properties (e.g. `--agentos-bg`, `--agentos-accent`, `--agentos-radius`, `--agentos-transcript-height`) that themes can override to re-skin the widget without editing plugin files.
- Each structural block has a predictable class: `.agentos-toolbar`, `.agentos-text-ui`, `.agentos-transcript`, etc. Optional panes (like the transcript) are conditionally rendered and controlled via per-agent toggles.
- Scripts and styles are registered separately, so developers can `wp_dequeue_style('agentos-embed')` or replace assets if needed.

## REST Endpoints

- POST `/wp-json/agentos/v1/realtime-token` (requires `post_id` + `agent_id`)
- POST `/wp-json/agentos/v1/transcript-db` (requires `post_id` + `agent_id`)
- GET `/wp-json/agentos/v1/transcript-db?post_id=...&agent_id=...`

## Development Workflow

1. Install dependencies you prefer (Composer optional) and ensure PHP 8.1+ is available.
2. For linting, install WordPress Coding Standards and run `phpcs --standard=phpcs.xml.dist`.
3. When editing PHP, favor the services in `src/` and keep templates logic-light.
4. Keep translations ready via `load_plugin_textdomain()` and wrap new strings in translation functions.
