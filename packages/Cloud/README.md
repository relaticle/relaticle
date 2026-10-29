# Cloud

The code that runs the hosted business at relaticle.com. The CRM core never depends on it.

`bootstrap/providers.php` registers `Relaticle\Cloud\CloudServiceProvider` only when the class exists. The Docker image excludes this directory through `.dockerignore`, so a self-hosted install boots without it.

The CI job `self-hosted-build` deletes this directory and runs the Arch, Smoke and Feature suites. `tests/Arch/ArchTest.php` fails any core layer that uses `Relaticle\Cloud`.

`LICENSE` governs this directory. The rest of the repository stays AGPL.
