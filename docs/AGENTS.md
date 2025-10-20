# AgentOS – Working With Agents

## Quick Context
- **Project**: AgentOS – Dynamic AI agents for WordPress.
- **Goal**: Load per-post agents (voice/text/both) via ACF/meta mapping + shortcode.
- **Constraints**: No hard-coded API keys, follow WordPress security practices, issue ephemeral realtime tokens only.

## Admin Workflow
1. Navigate to **AgentOS → Settings** to choose the OpenAI key source, configure context parameters, and (optionally) enable console logging.
2. Under **AgentOS → Agents**, create/edit agents:
   - Name & slug (used by the `[agentos id="slug"]` shortcode).
   - Default mode (`voice`, `text`, or `both`) and fallback model/voice values.
   - Fallback instructions (used when no system prompt is provided).
   - Allowed post types.
   - Per-post-type field mappings (model, voice, system prompt, user prompt).
   - **Display transcript panel** toggle (hides the live transcript UI + save button when unchecked).

## Front-End Integration
- Shortcode: `[agentos id="agent-slug"]` with optional `mode="text|both"` and `height="60vh"` overrides.
- The shortcode renders a structured widget with three blocks:
  1. **Toolbar** (`.agentos-toolbar` / `.agentos-bar`) – start, stop, and (if enabled) save transcript buttons plus a live status pill.
  2. **Composer** (`.agentos-text-ui`) – hidden by default unless mode is text/both; JS toggles the `is-visible` class.
  3. **Transcript** (`.agentos-transcript`) – conditionally rendered when the agent allows transcripts.

## Design System Notes
- Styles live in `assets/agentos-embed.css`. `.agentos-wrap` exposes CSS custom properties:
  - Colors: `--agentos-bg`, `--agentos-surface`, `--agentos-accent`, `--agentos-danger`, etc.
  - Shape/spacing: `--agentos-radius`, `--agentos-border`.
  - Typography: `--agentos-font-family`, `--agentos-font-size`, `--agentos-line-height`.
  - Layout: `--agentos-transcript-height` (set per shortcode via inline custom property).
- Themes can override these variables or dequeue the default stylesheet via `wp_dequeue_style('agentos-embed')` before enqueuing their own version.
- Classes follow a predictable naming pattern (`agentos-btn`, `agentos-transcript-log .msg.assistant`, etc.) so custom CSS or theme integrations stay maintainable.

## Process Checklist
- Keep PHP under `src/` (namespaced) and templates under `templates/`.
- REST surface lives in `src/Rest/RestController.php`; front-end behavior in `assets/agentos-embed.js` + `assets/agentos-embed.css`.
- Bump plugin version (`agentos.php` header + `Config::VERSION`) and append an entry to `docs/update.md` for each release.
- Run `phpcs --standard=phpcs.xml.dist` before committing; verify the agent UI in voice/text modes on desktop & mobile.
