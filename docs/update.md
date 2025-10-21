[YYYY-MM-DD] Initial scaffold created via Codex.
[2025-10-20] Secured REST endpoints, fixed context param parsing, improved frontend session handling.
[2025-10-21] Added multi-agent admin UI, per-agent shortcode/REST flow, and removed hard-coded lesson fields.
[2025-10-20 14:35][v0.3.0] Added dynamic post-type mapping UI with ACF field discovery and admin script for dropdown-driven configuration.
[2025-10-20 14:37][v0.3.1] Bumped plugin version and documented the version-control workflow for future releases.
[2025-10-20 15:55][v0.3.2] Added optional `[AgentOS]` console logging (toggle in settings) for Start/session lifecycle, API key source selection, and admin field-map changes; fixed selector helper bug blocking agent startup.
[2025-10-20 16:25][v0.3.3] Fixed transcript save error reporting, ensured the table migrates automatically, and now persist the logged-in user email with each transcript.
[2025-10-20 18:10][v0.4.1] Refactored the plugin into PSR-4 namespaced services with template partials, added PHPCS configuration, removed PHPUnit scaffolding, refreshed developer docs with the new layout, and introduced per-agent control to hide the public transcript block (UI and JS updated accordingly).
[2025-10-20 19:05][v0.5.0] Shipped the front-end design system: new modular layout, CSS-based theming tokens, responsive transcript/composer panes, Start/Stop toolbar polish, and docs/SOP refresh describing the styling workflow.
[2025-10-21 10:23][v0.6.0] Introduced the Sessions admin screen, per-agent analysis settings with auto-run support, background OpenAI transcript analysis + retries, REST/JS updates for user-safe history fetches, and a front-end feedback panel surfacing recent session insights.
[2025-10-21 11:30][v0.6.1] Tweaked analyzer payloads (Responses API `input_text`), increased timeout to 60s, honored agent-provided system prompts verbatim, simplified the user message to raw transcript text, and updated docs/version for release.
