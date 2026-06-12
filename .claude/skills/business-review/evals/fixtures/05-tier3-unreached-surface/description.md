# 05 — Tier-3 run with an unreached changed surface → ai-needs-human

Real dogfood of PR #332 (sysadmin AI resources + AiCreditType match-arm fix). S1/S2/S3
all delivered (production crash fix verified, both new resources render with data,
read-only confirmed), but the S4 security cross-guard (app-authenticated user → sysadmin
pages) was unreached because the local agent-browser daemon was resource-exhausted by
other workspaces' sessions.

Locks the anti-rubber-stamp behavior: on a Tier-3 diff, three delivered journeys do NOT
earn ai-approved while a changed security surface remains unverified. The aggregator must
emit ai-needs-human with a populated decision_needed — never ai-approved, never blocked
(the channel was healthy; this is a coverage gap, not a degraded channel).
