#!/usr/bin/env bash
# Updates the packages `composer audit` flags in composer.lock and writes a pull request body.
# Prints one word: clean (nothing flagged), patched, or unresolved (an advisory survives the
# update, so a constraint in composer.json has to move by hand). Run by security-patch.yml.
#
#   bin/composer-security-patch.sh <body-file>

set -euo pipefail

COMPOSER_BIN="${COMPOSER_BIN:-composer}"
BODY_FILE="${1:?usage: bin/composer-security-patch.sh <body-file>}"

audit() {
    local report

    # Matches the Composer Audit gate in security-audit.yml. A finding exits non-zero, so the JSON is the verdict.
    report="$($COMPOSER_BIN audit --locked --no-dev --abandoned=ignore --format=json 2>/dev/null || true)"

    if ! jq -e 'has("advisories")' <<<"$report" >/dev/null 2>&1; then
        echo "composer audit returned no report" >&2
        exit 1
    fi

    echo "$report"
}

flagged_packages() {
    jq -r '.advisories | if type == "object" then keys[] else empty end'
}

locked_version() {
    jq -r --arg name "$1" '[.packages[], .["packages-dev"][]] | map(select(.name == $name))[0].version // "n/a"' composer.lock
}

update() {
    $COMPOSER_BIN update "$@" --with-all-dependencies --no-install --no-scripts --no-interaction --no-progress >&2
}

advisory_list() {
    jq -r '.advisories | if type == "object" then [.[][]] else [] end | .[]
        | "- `\(.packageName)`: [\(.title)](\(.link // "https://packagist.org/packages/\(.packageName)/advisories"))"'
}

before="$(audit)"
read -r -a packages <<<"$(flagged_packages <<<"$before" | tr '\n' ' ')"

if [[ ${#packages[@]} -eq 0 ]]; then
    echo clean
    exit 0
fi

locked=()
for package in "${packages[@]}"; do
    locked+=("$(locked_version "$package")")
done

# Composer refuses versions with advisories, so an update with no safe version fails and the audit decides.
update "${packages[@]}" --patch-only || true
after="$(audit)"

if [[ -n "$(flagged_packages <<<"$after")" ]]; then
    update "${packages[@]}" --minimal-changes || true
    after="$(audit)"
fi

if [[ -n "$(flagged_packages <<<"$after")" ]]; then
    {
        echo "Packagist lists security advisories that \`composer update\` cannot fix inside the constraints in \`composer.json\`."
        echo "Raise the constraint of each package below, or of the package that requires it, by hand."
        echo
        advisory_list <<<"$after"
    } >"$BODY_FILE"

    echo unresolved
    exit 0
fi

{
    echo "Packagist published security advisories against packages in \`composer.lock\`."
    echo "This updates only those packages and what they require, trying patch releases first."
    echo
    echo "| Package | Was | Now |"
    echo "| --- | --- | --- |"

    for index in "${!packages[@]}"; do
        echo "| \`${packages[$index]}\` | ${locked[$index]} | $(locked_version "${packages[$index]}") |"
    done

    echo
    echo "### Advisories"
    echo
    advisory_list <<<"$before"
} >"$BODY_FILE"

echo patched
