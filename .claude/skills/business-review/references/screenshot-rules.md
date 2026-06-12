# Screenshot rules — defer to the global skill, keep the contract

**Invoke `Skill('screenshot-with-callout')` before EVERY deliverable screenshot** — per
shot, not once per session. That skill owns the sequence (annotate → verify-crop →
shoot → read-back) and the helper JS. This file only pins the contract the review needs:

- Every deliverable shot: red callout around the proving element, label + literal
  evidence text legible inside it, nothing critical clipped, saved to
  `$REVIEW_DIR/<journey-or-case>/...png` (never `/tmp` for deliverables).
- One claim per shot — a screenshot that "covers four things" proves none.
- **Read-back is mandatory** (open the PNG and look). By shot 7 of 12, "I remember the
  rules" is the failure mode the read-back exists to catch.
- Gate 6c (`report.md`) re-inspects every PNG before publish; zero screenshots on a
  user-facing diff is a FAILURE, never a vacuous pass.
- A second full-PNG sweep runs at report time, after the adrenaline is off (Journey g0
  lesson).

Throwaway debugging shots are exempt (and must not be referenced by the report).
