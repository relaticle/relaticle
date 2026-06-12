Browser channel degraded at preflight (0-byte screenshot, blank snapshot). Skill must emit label:blocked, write no verdict label, and not publish.

The plan.md frontmatter declares `channel: degraded` — simulating the scenario where the orchestrator set the channel flag after preflight failed. The aggregator must derive `label: blocked` by top precedence (overrides everything, including any confirmed blocker that might be present).

This fixture tests that a degraded channel must never produce a reviewable verdict, regardless of project domain.
