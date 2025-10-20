# System
Project: agentos – Dynamic AI agents for WordPress
Goal: Load per-post agents (voice/text/both) via ACF/meta mapping + shortcode.
Constraints: No hardcoded keys; follow WP security; ephemeral tokens only.

# Process
- Code changes under /agentos
- REST in PHP, UI in JS
- Bump plugin version (header + VERSION constant) with each release.
- Append docs/update.md with `[YYYY-MM-DD HH:MM][vX.Y.Z]` entries after every change.

# Task
