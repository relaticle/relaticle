# Custom Fields 4.0, Phase 1: Foundation

Status: approved. Program: Custom Fields 4.0, five phases (1 foundation, 2 option semantics,
3 relationship substrate, 4 relationship UX and tables, 5 host adoption and release).
Plans for this phase: 2026-09-03-cf4-p1.1-housekeeping.md.
Tracking: relaticle/custom-fields#210.

## Why a foundation phase

4.x is a housekeeping major. Relationships are the flagship, but the major is the one chance
to break things, and the large feature diffs of later phases should land on a clean base so
reviews are not fighting noise. Everything in this phase is independent of the new storage;
cleanups that depend on it (lookup_type retirement, the reflection helper) wait for phase 3.

## Decisions

1. Version floors: PHP ^8.3, Filament ^5. Legacy shims dropped where they block it.
   MySQL-family support continues with the enforcement caveat documented in phase 3.
2. Component interface break, once: `make()` on FormComponentInterface,
   InfolistComponentInterface, TableColumnInterface, TableFilterInterface gains
   `?Model $record`; TableFilterInterface also gains `?string $through` (consumed in phase 4,
   added now so the interface breaks once in the major). The instanceof compatibility branch
   in FieldComponentFactory is deleted.
3. Contracts follow one naming convention (`*Interface`); single-implementation internal
   interfaces collapse onto final concrete classes.
4. Extension-point policy: extension happens through documented seams (BaseFieldType, the
   model-swap registry, contracts), not by un-finalizing internals. A docs page lists every
   supported seam. Everything else is final. This is the standing answer to requests like
   custom-fields#45.
5. Feature-flag defaults are reviewed at the major: mature opt-in flags flip on by default
   (e.g. UI_SECTION_WIDTH_CONTROL from #181); every keep-off call is recorded with its reason.
6. Static analysis: PHPStan rises from level 5 as far as it goes without suppressions, one
   level per commit, never baselined.
7. The v1-era upgrade script and its steps are removed; the UpgradeStep framework stays and
   carries the 4.0 migration steps. Hosts upgrade to the latest 3.x before jumping to 4.0.
8. Migrations are up-only; existing down() methods go.
9. The three 650+ line classes (FieldSchema, FrontendVisibilityService, VisibilityComponent)
   are split behavior-neutrally before later phases touch them.

## Branch and release mechanics

- There is no `main`; the default branch is `3.x`. Stale `origin/4.x` and `origin/5.x`
  branches from the March 2026 Filament transition (never tagged, 190+ commits behind) are
  deleted first, then `4.x` is cut from `origin/3.x`. Stale branches poison `4.x-dev`
  resolution on Packagist.
- Pre-release tags mark phase boundaries so the host can test against a real version:
  `4.0.0-alpha.1` after phase 1, `alpha.2` after phase 2, `alpha.3` after phase 3, `beta.1`
  after phase 4, `4.0.0` after phase 5. Nothing from this program ships on 3.x.
- One PR per plan into `4.x`; a phase ends when the package is green and tagged.

## Exit criterion

Package green on the full gate at the new PHPStan level, interface break landed, extension
points documented, flags reviewed, `4.0.0-alpha.1` tagged.
