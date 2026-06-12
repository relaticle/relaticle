<!--json
{
 "tier": 3,
 "channel": "healthy",
 "mode": "pr",
 "pr": 332,
 "tier_rationale": "computed Tier 3 (chat/credit/policy/tenant + permission/destructive signals); user emphasis: full end-2-end dogfood",
 "execution_mode": "parallel persona subagents (capacity-checked) with inline fallback",
 "regression_checks": [],
 "regression_note": "0 ledger entries matched: this diff adds match-arm handling, not a new enum case \u2014 REG-001 targets case-ADDING diffs. The Reservation rendering check is covered by S1 anyway.",
 "changed_surfaces": [
  {
   "id": "sysadmin:ai-credit-transactions",
   "kind": "consumption",
   "reachable": true,
   "primary": true
  },
  {
   "id": "sysadmin:agent-conversations",
   "kind": "consumption",
   "reachable": true,
   "primary": true
  },
  {
   "id": "sysadmin:agent-conversation-messages",
   "kind": "consumption",
   "reachable": true,
   "primary": true
  },
  {
   "id": "sysadmin:policy-guard",
   "kind": "internal",
   "reachable": true,
   "primary": false
  }
 ],
 "journeys": [
  {
   "id": "S1",
   "name": "Sysadmin audits AI credit spend incl. reservation rows",
   "synthesized": true,
   "surfaces": [
    "packages/SystemAdmin/src/Filament/Resources/AiCreditTransactions/Tables/AiCreditTransactionsTable.php",
    "packages/Chat/src/Enums/AiCreditType.php"
   ],
   "covers_surfaces": [
    "sysadmin:ai-credit-transactions"
   ],
   "subsystems": [
    "credit",
    "template"
   ],
   "value": "A sysadmin opens AI credit transactions and every row (incl. type=reservation) renders a labeled badge \u2014 the production crash class is gone.",
   "personas": [
    "sysadmin"
   ],
   "acs": [
    1
   ],
   "seams": [
    "enum case value -> Filament badge color/label match",
    "type filter -> filtered query render"
   ],
   "happy_path": [
    "login sysadmin",
    "open AI > Credit Transactions",
    "ensure a reservation-type row exists (tinker-seed as PREREQUISITE if absent)",
    "page renders 200, reservation badge labeled"
   ],
   "sad_paths": [
    "filter by every type incl. Reservation \u2014 each filtered render is error-free",
    "sort by amount/date with reservation rows present",
    "open the view/infolist of a reservation transaction"
   ]
  },
  {
   "id": "S2",
   "name": "Sysadmin inspects agent conversations",
   "synthesized": true,
   "surfaces": [
    "packages/SystemAdmin/src/Filament/Resources/AgentConversationResource.php"
   ],
   "covers_surfaces": [
    "sysadmin:agent-conversations"
   ],
   "subsystems": [
    "chat",
    "template"
   ],
   "value": "A sysadmin lists all tenants' agent conversations with message counts and opens one.",
   "personas": [
    "sysadmin"
   ],
   "acs": [
    2,
    4
   ],
   "seams": [
    "conversation -> messages_count relation render",
    "team filter -> cross-tenant listing",
    "nullable title -> placeholder"
   ],
   "happy_path": [
    "open AI > Conversations",
    "list renders with rows + message counts",
    "open a conversation view page"
   ],
   "sad_paths": [
    "no create/edit/delete affordances anywhere (AC4)",
    "conversation with NULL title renders placeholder not error",
    "filter by team then clear filter"
   ]
  },
  {
   "id": "S3",
   "name": "Sysadmin inspects conversation messages incl. edge content",
   "synthesized": true,
   "surfaces": [
    "packages/SystemAdmin/src/Filament/Resources/AgentConversationMessageResource.php"
   ],
   "covers_surfaces": [
    "sysadmin:agent-conversation-messages"
   ],
   "subsystems": [
    "chat",
    "template"
   ],
   "value": "A sysadmin lists agent messages with role badges and opens any message incl. tool-role and superseded ones.",
   "personas": [
    "sysadmin"
   ],
   "acs": [
    3,
    4
   ],
   "seams": [
    "role value -> badge fallback for non user/assistant roles",
    "superseded indicator",
    "long/empty content -> preview truncation/placeholder"
   ],
   "happy_path": [
    "open AI > Messages",
    "list renders with role badges + previews",
    "open a message view incl. its parent conversation link"
   ],
   "sad_paths": [
    "open a tool-role (or other non-user/assistant) message \u2014 badge fallback renders",
    "open a superseded message",
    "a message with empty/whitespace content renders placeholder",
    "no mutation affordances (AC4)"
   ]
  },
  {
   "id": "S4",
   "name": "Breaker probes the sysadmin boundary",
   "synthesized": true,
   "surfaces": [
    "packages/SystemAdmin/src/Policies/AgentConversationPolicy.php",
    "packages/SystemAdmin/src/Policies/AgentConversationMessagePolicy.php"
   ],
   "covers_surfaces": [
    "sysadmin:policy-guard"
   ],
   "subsystems": [
    "policy",
    "permission"
   ],
   "value": "App users and guests can never read other tenants' AI conversations through the new sysadmin pages.",
   "personas": [
    "integrity-breaker"
   ],
   "acs": [
    5
   ],
   "seams": [
    "app-panel session vs sysadmin guard",
    "direct deep-links to resource + record URLs"
   ],
   "happy_path": [
    "as an APP-panel user (team-owner creds), deep-link to the sysadmin conversations/messages/credit-transactions URLs",
    "expect login redirect or 403 \u2014 never data"
   ],
   "sad_paths": [
    "unauthenticated guest deep-links the same URLs",
    "app user deep-links a specific conversation view URL",
    "after sysadmin login in another session, confirm app session STILL denied (no guard bleed)"
   ]
  }
 ],
 "pr_number": 332,
 "sha": "32192b9ac4"
}
-->

# Plan — PR 332 dogfood (v3)

Tier 3; 4 journeys; personas: sysadmin + integrity-breaker.
