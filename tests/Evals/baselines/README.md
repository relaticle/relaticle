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

