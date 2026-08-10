Non-trivial diff (Tier 2) where the persona reports broad coverage but an EMPTY coverage_frontier. The skill must flag the empty frontier as suspicious (not silently approve).

Two personas cover J2 (move-in) and J5 (HAP payment) on a Tier-2 diff. Both personas report `coverage.frontier_not_reached: []` — claiming they reached everything. On a broad, multi-journey diff touching tenancy_actions and transactions, claiming zero frontier is implausible and must be flagged as suspicious.

The aggregator must set `frontier_suspicious: true` when tier >= 2 and the union of all reported frontiers is empty.
