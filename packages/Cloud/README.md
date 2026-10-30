# Cloud

The code that runs the hosted business at relaticle.com. The CRM core never depends on it.

`bootstrap/providers.php` registers `Relaticle\Cloud\CloudServiceProvider` only when the class exists, so the app boots without this directory.

The Docker image excludes the package through `.dockerignore`. A manual install deletes `packages/Cloud` and runs `composer dump-autoload` before it boots.

The CI job `self-hosted-build` deletes this directory and runs the Arch, Smoke and Feature suites. `tests/Arch/ArchTest.php` fails any core layer that uses `Relaticle\Cloud`, and `tests/Arch/ConventionsTest.php` fails core files that name it.

`LICENSE` governs this directory. The rest of the repository stays AGPL.
