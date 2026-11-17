# Eval baselines

Each JSON file here is one full `composer test:evals` run of the forty Rela cases, copied from
`storage/evals/`. The file name carries the run date and the judged model.

| Column | Meaning |
|---|---|
| model | The model the assistant ran on (`PEST_EVALS_RELA_MODEL`) |
| judge | The model that scored the judge assertions |
| date | The day the run started (UTC) |
| pass rate | Cases that passed, over the cases that ran |
| tokens_in mean | Mean input tokens per case, from the response usage |
| replayed_bytes mean | Mean stored tool-result bytes per case, with `display_block` stripped |
| commit | The commit the run was taken on |

Each case records the metrics of its last sample, and the number of samples it ran.

## Baselines

| model | judge | date | pass rate | tokens_in mean | replayed_bytes mean | commit |
|---|---|---|---|---|---|---|
| claude-sonnet-5 | claude-opus-5-5 | 2026-11-08 | 26/40 (65.0%) | 90236 | 3070 | cf9d4b157 |
| gpt-5.5 | claude-opus-5-5 | 2026-11-08 | 35/40 (87.5%) | 47155 | 4185 | cf9d4b157 |

Each run happened once, on the commit above, with no change to the Rela code under test. The date is the UTC day in `started_at`.
GPT 5.5 reports itself as `gpt-6-luna` in stored `meta.model`.


## Plan runner baseline

The three `2026-11-17-claude-sonnet-5-plan-r*.json` files are the frozen baseline for experiments. They ran
on the owner's Claude subscription (`PEST_EVALS_RUNNER=plan`), `claude-sonnet-5` at effort `high`, judged by
`claude-opus-5-5`. They are not comparable with the API rows above: under the plan runner earlier turns reach
the model as text, Claude Code injects its own date, and cost is notional plan usage.

| run | commit | cases | pass rate | train | test | guard | p50 / p90 ms | notional cost | wall time |
|---|---|---|---|---|---|---|---|---|---|
| r1 | 31b0e2224 | 72 | 61/72 (84.7%) | 87.1% | 89.3% | 69.2% | 8953 / 13592 | $6.90 | 20 min |
| r2 | 31b0e2224 | 72 | 57/72 (79.2%) | 77.4% | 85.7% | 69.2% | 9184 / 13126 | $6.54 | 21 min |
| r3 | 374b21beb | 71 | 59/71 (83.1%) | 83.9% | 82.1% | 83.3% | 9460 / 12971 | $7.08 | 22 min |

`packages/Chat` is identical on both commits. Between them, the credit case gained hosted billing and the
web access case started skipping under the plan runner, which is why r3 has 71 cases.

**Noise floor:** `php bin/eval-compare.php --noise` over the three runs, leaving out the credit case and the
web access case, finds 5 of 54 shared headline cases (9.3%) giving a different result between runs and a
largest pass-rate gap of 5.6 points. History and clock cases are reported apart. A change smaller than about
6 points on the headline cannot be told from noise; read the per-case flips before believing one.
