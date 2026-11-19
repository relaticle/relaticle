#!/usr/bin/env bash
# Replaces .env and storage/oauth-private.key from the secret store, or changes nothing. Run from the site root.

# shellcheck disable=SC2016
set -euo pipefail
umask 077

SECRET_READER_KEY="${SECRET_READER_KEY:-$HOME/.config/relaticle/secret-reader.json}"
SECRET_PROJECT="${SECRET_PROJECT:-relaticle-prod-secrets}"
SECRET_TOKEN_URL="${SECRET_TOKEN_URL:-https://oauth2.googleapis.com/token}"
SECRET_API_BASE="${SECRET_API_BASE:-https://secretmanager.googleapis.com}"
SCOPE="https://www.googleapis.com/auth/cloud-platform"

if [[ ! -f "$SECRET_READER_KEY" ]]; then
    echo "→ secret store not configured, keeping the local settings"
    exit 0
fi

WORK="$(mktemp -d)"
ENV_NEW="$(mktemp ./.env.XXXXXX)"
KEY_NEW="$(mktemp storage/oauth-private.key.XXXXXX)"
trap 'rm -rf "$WORK" "$ENV_NEW" "$KEY_NEW"' EXIT

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
    json_field "$SECRET_READER_KEY" private_key > "$WORK/key.pem" || fail "secret reader key has no private_key"

    now="$(date +%s)"
    header="$(printf '{"alg":"RS256","typ":"JWT"}' | base64url)"
    claims="$(printf '{"iss":"%s","scope":"%s","aud":"%s","iat":%d,"exp":%d}' \
        "$email" "$SCOPE" "$SECRET_TOKEN_URL" "$now" "$((now + 3600))" | base64url)"
    signature="$(printf '%s.%s' "$header" "$claims" | openssl dgst -sha256 -sign "$WORK/key.pem" 2>/dev/null | base64url)" \
        || fail "secret reader key cannot sign"

    status="$(printf 'data = "grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer&assertion=%s.%s.%s"\n' \
        "$header" "$claims" "$signature" \
        | curl -sS -o "$WORK/token.json" -w '%{http_code}' -K - "$SECRET_TOKEN_URL")"

    if [[ "$status" != "200" ]]; then
        fail "secret store token request failed (HTTP $status)"
    fi

    json_field "$WORK/token.json" access_token || fail "secret store returned no access token"
}

fetch_secret() {
    local name="$1" destination="$2" status

    status="$(printf 'header = "Authorization: Bearer %s"\n' "$TOKEN" \
        | curl -sS -o "$WORK/$name.json" -w '%{http_code}' -K - \
            "$SECRET_API_BASE/v1/projects/$SECRET_PROJECT/secrets/$name/versions/latest:access")"

    if [[ "$status" != "200" ]]; then
        fail "secret $name failed (HTTP $status)"
    fi

    if ! php -r '$j = json_decode((string) file_get_contents($argv[1]), true); $d = base64_decode($j["payload"]["data"] ?? "", true); if ($d === false) { exit(1); } file_put_contents($argv[2], $d);' \
        "$WORK/$name.json" "$destination"; then
        fail "secret $name has an unreadable payload"
    fi

    if [[ ! -s "$destination" ]]; then
        fail "secret $name is empty"
    fi
}

TOKEN="$(access_token)" || exit 1

fetch_secret prod-env "$ENV_NEW"
fetch_secret prod-oauth-private-key "$KEY_NEW"

chmod 600 "$ENV_NEW" "$KEY_NEW"
mv "$ENV_NEW" .env
mv "$KEY_NEW" storage/oauth-private.key

echo "✓ loaded production settings from the secret store"
