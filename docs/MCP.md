# MCP tools

AgentOS 10.4.0 and later can call tools on remote MCP servers from text or voice conversations. The WordPress server connects to MCP and exposes only the selected tools as OpenAI function tools. The browser receives tool definitions and a short-lived conversation grant; MCP credentials remain on the server.

## Setup

1. Open **AgentOS → MCP Connections** and save a connection with a public HTTPS Streamable HTTP endpoint. Authentication can be **None** or a manually supplied **Bearer token**.
2. Edit that connection and click **Discover tools**. Discovery initializes an MCP session and imports the tool catalog.
3. Edit an agent and select specific tools under **MCP Tools**. An empty selection grants no tools. Changes to the server endpoint or authentication clear its catalog and require discovery again.
4. Select an execution policy for that connection: **Ask for approval each time** (default) or **Run automatically** for actions you authorize every user of that agent to perform.
5. Add guidance in each connection’s **Instructions for this connection** field. It is sent only when at least one tool from that enabled connection is selected. Existing **Legacy global MCP instructions** remain stored and continue to apply to selected MCP tools, so service-specific guidance can be migrated over time without losing it.
6. Start a new conversation or resume voice to receive the updated tool configuration.

For a read-only demonstration, use `https://developers.openai.com/mcp` and select `search_openai_docs`. Use the real MCP tool name in instructions; AgentOS maps it to a unique model-facing name to avoid collisions across servers. Keep guidance for unrelated providers in their own connection fields.

### Failure diagnostics

When a tool fails, AgentOS returns a short reference and safe error code to the agent. Administrators can use **AgentOS → MCP Connections → Recent MCP failures** to see the UTC time, reference, connection, tool, phase, HTTP status, remote code, and form-schema field names. The log excludes arguments, result bodies, endpoint paths, credentials, and submitted values. Form validation field names are shown only when the form schema was read during the same conversation.

## Accounts and access

Connections use the **site's service account**. Every eligible user of an agent can invoke its selected tools with that account's access. Session ownership, post access, agent restrictions, subscriptions and the explicit tool allowlist are checked before execution. These checks do not make a shared credential represent each student's individual account.

If a tool accepts an arbitrary customer or user ID, its MCP service must enforce the required data access, or it must use an account scoped to the intended audience. User-specific OAuth and injection of server-owned identity into tool arguments are future additions. A model-supplied ID or URL context parameter is not trusted proof of identity.

Connection administration requires WordPress `manage_options` and a nonce. Tokens are encrypted using WordPress salts, never rendered back into forms, and never included in frontend configuration or OpenAI session tools. A blank token field preserves the existing token; clearing it is explicit. Rotating the WordPress salts makes stored tokens unreadable and requires entering them again.

## Conversation behavior

- The interface shows when a tool is being consulted. Calls that require approval display the service, action, parameters and **Allow / Decline** buttons.
- Declining returns an explicit refusal to the model. Failed calls return an error so the model can explain the failure instead of assuming success.
- Voice function calls execute after generation completion, once per call ID. The result is returned and one follow-up response is requested. A newer user turn suppresses obsolete follow-ups.
- Stop or a new conversation invalidates local callbacks, closes pending approval UI and revokes the tool grant. An external action already sent to MCP cannot be undone by interrupting speech.
- Text tool state and model outputs stay on the server across approval steps. The browser cannot replace the model request or supply a fabricated tool result.
- Saved transcripts retain the conversation text. MCP credentials and raw tool results are not appended to the transcript.

## Current scope and limits

- Public HTTPS **Streamable HTTP** MCP endpoints, with JSON or SSE response bodies and protocol version `2025-03-26`.
- Redirects, URL credentials, literal IP addresses and local/private destinations are rejected by endpoint validation and WordPress safe HTTP requests.
- Local `stdio`, legacy SSE endpoints requiring a separate GET connection, private-network tunneling, OAuth sign-in/refresh and per-user service credentials are not implemented in this release.
- Discovery is bounded to 5 pages / 100 tools. Each HTTP operation has a 12 second timeout and a 256 KiB response cap. Tool results supplied to the model are limited to 128 KiB.
- Tool grants expire after one hour and permit at most 48 distinct calls. Text continuations expire after 15 minutes and permit 5 tool rounds, with up to 8 calls per round. Voice tool chains use the same round bound and reset after a new user turn.
- Call receipts prevent duplicate execution of the same call ID. They do not guarantee business-level idempotency across different model calls or new sessions; services that change external records should implement that themselves.

## Validation

`npm test` covers MCP transport envelopes, encrypted credentials, schema handling, allowlists, approval and denial, session ownership, duplicate calls, server-owned text continuation, safe diagnostics, frontend confirmations and late results after voice stop. It also runs the existing transcript and voice lifecycle regressions.

The OpenAI documentation MCP endpoint is suitable for a real read-only protocol smoke test. Physical microphone, acoustic echo and spoken-interruption checks remain manual browser tests.
