## What changed

<!-- One paragraph for a reviewer who has not read the code. Link the issue this closes, if any. -->

## Why

<!-- The problem this solves, or the behavior it changes and for whom. -->

## How to verify

<!-- The commands you ran and what they showed. For anything visual, add before and after screenshots. -->

## Checklist

- [ ] `vendor/bin/pint --dirty --format agent` is clean
- [ ] `composer test:affected` passes
- [ ] `vendor/bin/phpstan analyse` reports no new errors
- [ ] `composer test:arch` passes
- [ ] New behavior has a test through its real entry point, and a bug fix has a regression test
- [ ] User-facing strings use `__()`
- [ ] Any migration has `up()` only and targets PostgreSQL
