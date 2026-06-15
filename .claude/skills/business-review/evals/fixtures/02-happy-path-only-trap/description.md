Move-in (J2) happy path passes, but the no-supporting-grant sad path 500s and a verifier confirms it. A v1-style run would approve; v2 must reject.

The caseworker persona finds that the move-in wizard happy path succeeds (tenancy goes active, HAP scheduled). However, the sad path — triggering a move-in with no supporting grant — produces a raw HTTP 500 (GrantAssignmentException). The verifier cold-reproduces this in a fresh session and confirms it.

v2 must emit `ai-rejected` because there is a confirmed blocker. A business-review that only checks the happy path is insufficient for a tenancy-actions + grants diff.
