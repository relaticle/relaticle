# Citation panel

Monthly tracker for whether AI answer engines mention or cite Relaticle in
response to a fixed set of prompts. Re-run the same 10 prompts, verbatim,
against the same 3 engines every month and append a new dated block of rows —
never edit past rows.

## Prompts (fixed, reused every month)

1. best open source CRM
2. self-hosted CRM
3. open source Attio alternative
4. CRM with MCP server
5. connect Claude to CRM
6. AI agent CRM
7. self-hosted CRM with AI
8. Laravel CRM
9. flat-price CRM unlimited users
10. Twenty alternatives

## Method

- **Engines**: ChatGPT, Claude, Perplexity.
- **Mentioned**: the engine's answer names "Relaticle" anywhere in the text.
- **Cited**: `relaticle.com` or `github.com/relaticle` appears among the
  answer's listed sources (whether or not the source is a clickable link).
- **Linked**: a clickable hyperlink to `relaticle.com` or
  `github.com/relaticle` appears in the rendered answer.
- Each prompt is run **fresh** for each engine — a new conversation/search
  with no prior context, never a follow-up turn.
- If an engine cannot be reached (no login session, blocked by a bot check,
  etc.), the row is recorded as `n/a` for mentioned/cited/linked with the
  reason in notes. Results are never fabricated or inferred.

## Results

| date | prompt | engine | mentioned? | cited? | linked? | notes |
|---|---|---|---|---|---|---|
| 2026-08-13 | best open source CRM | ChatGPT | n/a | n/a | n/a | chatgpt.com not reachable: no logged-in session on this machine, and the automated browser is blocked at a Cloudflare bot-check page before any UI loads. Per task constraints, login was not attempted. |
| 2026-08-13 | best open source CRM | Claude | n/a | n/a | n/a | claude.ai not reachable: no logged-in session on this machine, and the automated browser is blocked at a Cloudflare bot-check page before any UI loads. Per task constraints, login was not attempted. |
| 2026-08-13 | best open source CRM | Perplexity | n/a | n/a | n/a | perplexity.ai blocked the automated browser at a Cloudflare Turnstile challenge ("Verify you are human") on every attempt, including after clicking the checkbox and retrying across two fresh sessions; a one-shot curl fallback also returned the same Cloudflare challenge (HTTP 403). No answer was ever rendered. |
| 2026-08-13 | self-hosted CRM | ChatGPT | n/a | n/a | n/a | Same reachability issue as above — chatgpt.com not reachable on this machine. |
| 2026-08-13 | self-hosted CRM | Claude | n/a | n/a | n/a | Same reachability issue as above — claude.ai not reachable on this machine. |
| 2026-08-13 | self-hosted CRM | Perplexity | n/a | n/a | n/a | Same reachability issue as above — perplexity.ai blocked by Cloudflare Turnstile for both agent-browser and curl. |
| 2026-08-13 | open source Attio alternative | ChatGPT | n/a | n/a | n/a | Same reachability issue as above — chatgpt.com not reachable on this machine. |
| 2026-08-13 | open source Attio alternative | Claude | n/a | n/a | n/a | Same reachability issue as above — claude.ai not reachable on this machine. |
| 2026-08-13 | open source Attio alternative | Perplexity | n/a | n/a | n/a | Same reachability issue as above — perplexity.ai blocked by Cloudflare Turnstile for both agent-browser and curl. |
| 2026-08-13 | CRM with MCP server | ChatGPT | n/a | n/a | n/a | Same reachability issue as above — chatgpt.com not reachable on this machine. |
| 2026-08-13 | CRM with MCP server | Claude | n/a | n/a | n/a | Same reachability issue as above — claude.ai not reachable on this machine. |
| 2026-08-13 | CRM with MCP server | Perplexity | n/a | n/a | n/a | Same reachability issue as above — perplexity.ai blocked by Cloudflare Turnstile for both agent-browser and curl. |
| 2026-08-13 | connect Claude to CRM | ChatGPT | n/a | n/a | n/a | Same reachability issue as above — chatgpt.com not reachable on this machine. |
| 2026-08-13 | connect Claude to CRM | Claude | n/a | n/a | n/a | Same reachability issue as above — claude.ai not reachable on this machine. |
| 2026-08-13 | connect Claude to CRM | Perplexity | n/a | n/a | n/a | Same reachability issue as above — perplexity.ai blocked by Cloudflare Turnstile for both agent-browser and curl. |
| 2026-08-13 | AI agent CRM | ChatGPT | n/a | n/a | n/a | Same reachability issue as above — chatgpt.com not reachable on this machine. |
| 2026-08-13 | AI agent CRM | Claude | n/a | n/a | n/a | Same reachability issue as above — claude.ai not reachable on this machine. |
| 2026-08-13 | AI agent CRM | Perplexity | n/a | n/a | n/a | Same reachability issue as above — perplexity.ai blocked by Cloudflare Turnstile for both agent-browser and curl. |
| 2026-08-13 | self-hosted CRM with AI | ChatGPT | n/a | n/a | n/a | Same reachability issue as above — chatgpt.com not reachable on this machine. |
| 2026-08-13 | self-hosted CRM with AI | Claude | n/a | n/a | n/a | Same reachability issue as above — claude.ai not reachable on this machine. |
| 2026-08-13 | self-hosted CRM with AI | Perplexity | n/a | n/a | n/a | Same reachability issue as above — perplexity.ai blocked by Cloudflare Turnstile for both agent-browser and curl. |
| 2026-08-13 | Laravel CRM | ChatGPT | n/a | n/a | n/a | Same reachability issue as above — chatgpt.com not reachable on this machine. |
| 2026-08-13 | Laravel CRM | Claude | n/a | n/a | n/a | Same reachability issue as above — claude.ai not reachable on this machine. |
| 2026-08-13 | Laravel CRM | Perplexity | n/a | n/a | n/a | Same reachability issue as above — perplexity.ai blocked by Cloudflare Turnstile for both agent-browser and curl. |
| 2026-08-13 | flat-price CRM unlimited users | ChatGPT | n/a | n/a | n/a | Same reachability issue as above — chatgpt.com not reachable on this machine. |
| 2026-08-13 | flat-price CRM unlimited users | Claude | n/a | n/a | n/a | Same reachability issue as above — claude.ai not reachable on this machine. |
| 2026-08-13 | flat-price CRM unlimited users | Perplexity | n/a | n/a | n/a | Same reachability issue as above — perplexity.ai blocked by Cloudflare Turnstile for both agent-browser and curl. |
| 2026-08-13 | Twenty alternatives | ChatGPT | n/a | n/a | n/a | Same reachability issue as above — chatgpt.com not reachable on this machine. |
| 2026-08-13 | Twenty alternatives | Claude | n/a | n/a | n/a | Same reachability issue as above — claude.ai not reachable on this machine. |
| 2026-08-13 | Twenty alternatives | Perplexity | n/a | n/a | n/a | Same reachability issue as above — perplexity.ai blocked by Cloudflare Turnstile for both agent-browser and curl. |

## Reachability notes for next run

- ChatGPT and Claude require a founder-logged-in `agent-browser` session
  (`--session gtm-panel`) reused across runs; this machine had none and none
  was created, per task constraints (never attempt login on the founder's
  behalf).
- Perplexity does not require login, but on this run it served a Cloudflare
  Turnstile challenge to the automated browser on every attempt (fresh
  sessions, checkbox click, and a `curl` fallback all hit the same
  challenge/403). Next run: try from a session with an established
  human-browsing history/cookie jar, or run manually and transcribe results.
