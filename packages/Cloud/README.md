# Cloud

The code that runs the hosted business at relaticle.com. The CRM core never depends on it.

`bootstrap/providers.php` registers `Relaticle\Cloud\CloudServiceProvider` only when the class exists and `RELATICLE_FOSS_ONLY` is unset, so the app boots without this directory and boots past it with the switch on.

The Docker image excludes the package through `.dockerignore`. A manual install from a clone sets `RELATICLE_FOSS_ONLY=true`, which keeps the provider out of the app and the package's stylesheets out of `pnpm run build`.

The CI job `self-hosted-build` boots once with the switch on, then deletes this directory and runs the Arch, Smoke and Feature suites. `tests/Arch/ArchTest.php` fails any core layer that uses `Relaticle\Cloud`, and `tests/Arch/ConventionsTest.php` fails core files that name it.

`LICENSE` governs this directory: development and testing use is free, production use needs an agreement with Relaticle. The rest of the repository stays AGPL.
