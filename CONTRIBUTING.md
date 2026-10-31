# Contributing to Relaticle

Thank you for helping build Relaticle. This page covers the short path from a clone to a merged pull request. The full [Contributing Guide](https://relaticle.com/docs/contributing) explains the architecture and the tooling in more depth.

## Before you start

- Search the [open issues](https://github.com/relaticle/relaticle/issues) and pull requests first.
- Bugs go in a [bug report](https://github.com/relaticle/relaticle/issues/new?template=1-bug_report.yml). Ideas go in a [feature request](https://github.com/relaticle/relaticle/issues/new?template=2-feature_request.yml).
- For a change bigger than a fix, open an issue and agree on the approach before writing code.
- Security problems go to security@relaticle.com, never to a public issue. See [SECURITY.md](.github/SECURITY.md).

## Local setup

Requirements: PHP 8.5, PostgreSQL 17, Node.js 22 with pnpm, Composer 2.

```bash
git clone https://github.com/Relaticle/relaticle.git
cd relaticle
composer app-install
composer dev
```

The app runs at `http://localhost:8000`. `composer dev` starts the server, the queue worker, the log tail and Vite together.

## Where code lives

Relaticle is a modular monolith. The CRM core is in `app/`. Self-contained subsystems live in `packages/<Name>/` with the `Relaticle\<Name>` namespace: `Chat` (the AI assistant), `EmailIntegration`, `ImportWizard`, `Documentation`, `SystemAdmin` and `OnboardSeed`. A new CRM entity goes in `app/`; a new package is for a genuinely separable subsystem.

Business operations are action classes in `app/Actions/<Domain>/`. Reusable reads are query classes in `app/Queries/`. Controllers, Filament resources, Livewire components and MCP tools call them and hold no business logic of their own. `AGENTS.md` at the repository root is the authoritative description of these rules, and the architecture tests in `tests/Arch/` enforce them.

## Quality checks

While iterating:

```bash
vendor/bin/pint --dirty --format agent
composer test:affected
```

Once before you push:

```bash
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse
composer test:lint
composer test:arch
```

CI runs the full suite on every push to a pull request. Do not run the full suite locally to confirm a push.

## Tests

Tests live in `tests/Feature/`, `tests/Browser/`, `tests/Smoke/` and `tests/Arch/`. There is no unit suite on purpose: test actions, services and enums through their real entry points, such as an API endpoint, a Filament resource or a Livewire component. A bug fix comes with one regression test that fails without the fix. Extend an existing test file before creating a new one.

## Pull requests

- Branch from `main`. Name the branch for the change: `feat/opportunity-stages`, `fix/import-encoding`.
- Keep a pull request to one change. Split unrelated work.
- Write the description for a reviewer who has not read the code: what changed, why, and how you verified it. Screenshots for anything visual.
- Migrations have an `up()` method only, target PostgreSQL only, and are never covered by tests.
- Wrap user-facing strings in `__()`. PHPStan fails a hardcoded label.
- No em-dashes in copy, docs, comments or commit messages.

A maintainer reviews every pull request. Expect questions; they are part of the review, not a rejection.

## Code of Conduct

This project follows the [Contributor Covenant](CODE_OF_CONDUCT.md). Participation means agreeing to it.

## License

Contributions are licensed under the [AGPL-3.0](LICENSE), the same license as Relaticle.
