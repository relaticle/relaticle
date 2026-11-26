#!/usr/bin/env bash
# Replaces .env and storage/oauth-private.key from the secret store, or changes nothing. Run from the site root.

# shellcheck disable=SC2016
set -euo pipefail
umask 077

SECRET_READER_KEY="${SECRET_READER_KEY:-$HOME/.config/relaticle/secret-reader.json}"
SECRET_PROJECT="${SECRET_PROJECT:-relaticle-prod-secrets}"
SECRET_TOKEN_URL="https://oauth2.googleapis.com/token"
SECRET_API_BASE="https://secretmanager.googleapis.com"
SCOPE="https://www.googleapis.com/auth/cloud-platform"

# A fetch that fails blocks every deploy. Moving the reader key file aside restores the old behaviour.
if [[ ! -f "$SECRET_READER_KEY" ]]; then
    echo "→ secret store not configured, keeping the local settings"
    exit 0
fi

WORK=""
ENV_NEW=""
KEY_NEW=""
trap 'rm -rf "$WORK" "$ENV_NEW" "$KEY_NEW"' EXIT

WORK="$(mktemp -d)"
ENV_NEW="$(mktemp ./.env.fetch.XXXXXX)"
KEY_NEW="$(mktemp storage/oauth-private.key.XXXXXX)"

fail() {
    echo "✗ $1" >&2
    exit 1
}

base64url() {
    openssl base64 -A | tr '+/' '-_' | tr -d '='
}

json_field() {
    php -r '$j = json_decode((string) file_get_contents($argv[1]), true); $v = $j[$argv[2]] ?? ""; if (! is_string($v) || $v === "") { exit(1); } echo $v;' "$1" "$2"
}

access_token() {
    local email now header claims signature status
    email="$(json_field "$SECRET_READER_KEY" client_email)" || fail "secret reader key has no client_email"
    json_field "$SECRET_READER_KEY" private_key > /dev/null || fail "secret reader key has no private_key"

    now="$(date +%s)"
    header="$(printf '{"alg":"RS256","typ":"JWT"}' | base64url)"
    claims="$(printf '{"iss":"%s","scope":"%s","aud":"%s","iat":%d,"exp":%d}' \
        "$email" "$SCOPE" "$SECRET_TOKEN_URL" "$now" "$((now + 3600))" | base64url)"
    signature="$(printf '%s.%s' "$header" "$claims" | openssl dgst -sha256 -sign <(json_field "$SECRET_READER_KEY" private_key) 2>/dev/null | base64url)" \
        || fail "secret reader key cannot sign"

    status="$(printf 'data = "grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer&assertion=%s.%s.%s"\n' \
        "$header" "$claims" "$signature" \
        | curl -sS --connect-timeout 10 --max-time 30 -o "$WORK/token.json" -w '%{http_code}' -K - "$SECRET_TOKEN_URL")"

    if [[ "$status" != "200" ]]; then
        fail "secret store token request failed (HTTP $status)"
    fi

    json_field "$WORK/token.json" access_token || fail "secret store returned no access token"
}

fetch_secret() {
    local name="$1" destination="$2" status

    status="$(printf 'header = "Authorization: Bearer %s"\n' "$TOKEN" \
        | curl -sS --connect-timeout 10 --max-time 30 -o "$WORK/$name.json" -w '%{http_code}' -K - \
            "$SECRET_API_BASE/v1/projects/$SECRET_PROJECT/secrets/$name/versions/latest:access")"

    if [[ "$status" != "200" ]]; then
        fail "secret $name failed (HTTP $status)"
    fi

    if ! php -r '$j = json_decode((string) file_get_contents($argv[1]), true); $d = base64_decode($j["payload"]["data"] ?? "", true); if ($d === false || file_put_contents($argv[2], $d) !== strlen($d)) { exit(1); }' \
        "$WORK/$name.json" "$destination"; then
        fail "secret $name has an unreadable payload"
    fi

    if [[ ! -s "$destination" ]]; then
        fail "secret $name is empty"
    fi
}

verify_payloads() {
    grep -q '^APP_KEY=' "$ENV_NEW" || fail "secret prod-env has no APP_KEY"
    grep -q '^APP_ENV=production' "$ENV_NEW" || fail "secret prod-env is not a production environment"

    [[ -s storage/oauth-public.key ]] || fail "storage/oauth-public.key is missing"

    local private_public public_public
    private_public="$(openssl pkey -in "$KEY_NEW" -pubout 2>/dev/null)" || fail "secret prod-oauth-private-key is not a private key"
    public_public="$(openssl pkey -pubin -in storage/oauth-public.key -pubout 2>/dev/null)" || fail "storage/oauth-public.key is not a public key"

    [[ "$private_public" == "$public_public" ]] || fail "secret prod-oauth-private-key does not match storage/oauth-public.key"
}

TOKEN="$(access_token)" || exit 1

fetch_secret prod-env "$ENV_NEW"
fetch_secret prod-oauth-private-key "$KEY_NEW"

verify_payloads

chmod 600 "$ENV_NEW" "$KEY_NEW"
mv "$ENV_NEW" .env
mv "$KEY_NEW" storage/oauth-private.key

echo "✓ loaded production settings from the secret store"
