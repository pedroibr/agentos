# agentos – Dynamic AI Agents for WordPress

- Admin → AgentOS → Settings: choose the API key source (ENV, PHP constant, or manual) and configure allowed context query parameters.
- Admin → AgentOS → Agents: create any number of agents, set their default model/voice/mode, choose which post types they run on, and map ACF/meta fields for model, voice, system prompt, and user prompt.

Shortcode:
- `[agentos id="agent-slug"]`
- Optional overrides: `mode="text"` or `mode="both"`, `height="60vh"`

Context params (from Settings) continue to pass URL values through to the generated instructions, e.g. `?nome=...&produto=...&etapa=...`.

REST:
- POST `/wp-json/agentos/v1/realtime-token` (requires `post_id` + `agent_id`)
- POST `/wp-json/agentos/v1/transcript-db` (requires `post_id` + `agent_id`)
- GET `/wp-json/agentos/v1/transcript-db?post_id=...&agent_id=...`
