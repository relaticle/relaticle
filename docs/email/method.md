# How every decision is reasoned and scored

Every decision file in `decisions/` uses this method, so scores can be compared
across decisions.

## Criteria (1 to 5, 5 is best)

| Criterion | Weight | 1 means | 3 means | 5 means |
|---|---|---|---|---|
| **User value** | 3 | Rarely helps the daily job | Helps some users some days | Answers a question a seller asks every day |
| **Clarity** | 2 | Needs training or hides state | Learnable in a session | Obvious on first open, never surprises |
| **CRM and privacy fit** | 3 | Fights the record model or leaks teammate mail | Neutral | Strengthens records and respects tiers, access requests and workspace scope |
| **Future-proof** | 3 | Breaks on one of the six tests below | Survives most | Survives all six |
| **Cost** | 1 | Months of build and ongoing upkeep | Weeks, moderate upkeep | Days, little upkeep |

The weighted total is out of 60. A pick that is not the top total states why.

## The six future-proof tests

1. **Agents.** Rela and outside agents (the MCP server, Claude, ChatGPT) become a
   second user of email. Does the design expose the same facts to them through
   one owner, with the viewer's permissions?
2. **No AI key.** A self-hosted install with no model provider still gets a working
   feature. AI may improve it, never be required.
3. **Scale 10x.** The largest mailbox goes from 156,632 to 1.5 million emails, and a
   workspace from 3 to 50 mailboxes. Does it stay fast without a redesign?
4. **Providers.** Gmail and Microsoft Graph change limits, scopes or APIs, or IMAP and
   JMAP arrive. Does the design depend on one provider's behaviour?
5. **Trust and regulation.** GDPR access and erasure, the EU AI Act's transparency
   duties, and buyers asking "who can read my mail". Can we explain and audit it?
6. **Reversibility.** If we are wrong, can we undo it without a data migration or a
   broken promise to users?

## What each decision section contains

1. **The question** in one sentence.
2. **Today**: what the code and production show, with file and line or numbers.
3. **What competitors chose and why**: their business model, constraints and the
   cost they accepted. Not just what they did.
4. **Options**: five, each with how it works, who does it, strengths, failure modes.
5. **Matrix**: the five criteria, weighted total.
6. **Future-proof check**: the six tests for the pick and the runner-up.
7. **Recommendation**: the pick, why it beats the runner-up, what would change our
   mind, and the smallest first step.
8. **Risks and mitigations.**

## Evidence used

- Production aggregates of 2026-10-31 in [README.md](README.md). No record content.
- The code as of 2026-10-31, cited by file and line.
- [benchmark.md](benchmark.md) and [benchmark-modern.md](benchmark-modern.md) for
  competitor behaviour, each with its source URL.
- [competitors.md](competitors.md) for why each competitor made its choices.
