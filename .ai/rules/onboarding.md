# Onboarding steps (spatie/laravel-onboard)

`AppServiceProvider::register()` rebinds `Spatie\Onboard\OnboardingSteps` as a
**non-singleton**. Do not "optimise" that back to `singleton()`.

The package binds it as a singleton, so every model shares one `OnboardingStep`
instance. `OnboardingStep::complete()` memoizes via Laravel's `once()`, keyed on
that shared object and the call site — the model is not part of the key. With the
package's own binding, the first team evaluated in a process decides the answer
for every later team: wrong onboarding state in any request or Horizon worker
that touches two workspaces. Verified against 2.6.3; unreported upstream.

Because a fresh registry starts empty, step registration lives inside that
binding (`ActivationSteps::registerOn($steps)`), not in `boot()`.

**Never register steps through the `Spatie\Onboard\Facades\Onboard` facade**
(what the package README shows). A facade caches its resolved instance, so with
a non-singleton binding it holds an orphan registry no `$team->onboarding()`
call ever reads: `Onboard::addStep()` would silently register a step that never
appears. Add steps in `ActivationSteps::registerOn()` only.

Completion truth comes from `App\Services\WorkspaceActivationFacts`, which caches
per team id and is `scoped` (reset per request/job). Call `forget($team)` after
writing records inside one request/test before re-reading a step.

`tests/Feature/Onboarding/ActivationStepsTest.php` has a leak regression test:
if it starts failing with "false is true", check whether the binding reverted.

## Do not let `boost:update` eat these rules

`php artisan boost:update` regenerates `.ai/rules/boost/*` and `.ai/rules/index.md`
from its own templates. That strips the hand-added `packages/*` globs and the row
pointing at this file. After running it, `git checkout HEAD -- .ai/rules/boost
.ai/rules/index.md` and keep only the `.ai/guidelines/` change you intended.
