#!/usr/bin/env bash
# Smoke test for ContentBase – start the built-in server and probe critical
# paths, including login, the writing dashboard slider and the language switch.
#
# Usage:
#   ./smoke-test.sh                 # fresh temp database, silent server
#   BASE_URL=http://localhost:8080 ./smoke-test.sh   # probe an already running instance
#
# Requirements: php-cli, php-sqlite3, curl; the server is bound to 127.0.0.1
# only (never exposed publicly). Exits non-zero on the first failed check.

set -euo pipefail

BASE="${BASE_URL:-}"
PORT="${PORT:-8099}"
ROOT="$(cd "$(dirname "$0")" && pwd)"
URL="$BASE"
SERVER_PID=""

cleanup() {
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi
    [ -n "${CB_DATA_DIR:-}" ] && rm -rf "$CB_DATA_DIR"
}
trap cleanup EXIT

FAILED=0
check() { # check <description> <haystack> <needle>
    local desc="$1" haystack="$2" needle="$3"
    if printf '%s' "$haystack" | grep -qi -- "$needle"; then
        echo "  ok  - $desc"
    else
        echo "  FAIL - $desc (missing: $needle)"
        FAILED=1
    fi
}

if [ -z "$BASE" ]; then
    # Fresh instance with a private database: the app derives its data dir from
    # the location of public/index.php (App.php: basePath . '/data/...').
    # PHP resolves symlinks in __DIR__, so public/ must be a real copy for the
    # basePath to land in the temp dir; everything else is symlinked.
    INSTANCE="$(mktemp -d)"
    CB_DATA_DIR="$INSTANCE"
    for entry in src modules templates lang vendor composer.json; do
        [ -e "$ROOT/$entry" ] && ln -s "$ROOT/$entry" "$INSTANCE/$entry"
    done
    mkdir -p "$INSTANCE/data"
    cp -r "$ROOT/public" "$INSTANCE/public"
    echo "Starting ContentBase on 127.0.0.1:$PORT (temp instance in $INSTANCE) ..."
    PHP_CLI_SERVER_WORKERS=2 php -d error_reporting=E_ALL -d display_errors=1 \
        -S "127.0.0.1:$PORT" -t "$INSTANCE/public" >/dev/null 2>&1 &
    SERVER_PID=$!
    URL="http://127.0.0.1:$PORT"
    for i in $(seq 1 20); do
        curl -sf -o /dev/null "$URL/login" && break
        sleep 0.25
    done
    if ! curl -sf -o /dev/null "$URL/login"; then
        echo "FAIL - server did not come up on $URL"; exit 1
    fi
fi

echo "Probing $URL ..."
echo "[1/9] First start: setup gate redirects to /setup"
CODE="$(curl -s -o /dev/null -w '%{http_code}' "$URL/login")"
if [ "$CODE" = "302" ] || [ "$CODE" = "303" ]; then
    echo "  ok  - /login redirects while no user exists ($CODE)"
else
    echo "  FAIL - /login returned $CODE (expected redirect to /setup on a fresh instance)"
    FAILED=1
fi
rm -f /tmp/cb_smoke_cookies.txt
BODY="$(curl -sf -c /tmp/cb_smoke_cookies.txt "$URL/setup")"
check "setup page renders" "$BODY" 'First-start setup'
check "setup form present" "$BODY" 'name="username"'

echo "[2/9] Create the first admin account via the setup form"
CSRF="$(printf '%s' "$BODY" | grep -o 'name="_csrf" value="[0-9a-f]*"' | head -1 | grep -o '[0-9a-f]\{64\}')"
if [ -z "$CSRF" ]; then
    echo "  FAIL - no CSRF token on setup form"; FAILED=1
else
    CODE="$(curl -s -b /tmp/cb_smoke_cookies.txt -c /tmp/cb_smoke_cookies.txt -o /dev/null -w '%{http_code}' \
        -X POST "$URL/setup" \
        --data-urlencode 'username=smoketest' \
        --data-urlencode 'password=smoke-pass-1' \
        --data-urlencode 'password_confirm=smoke-pass-1' \
        --data-urlencode "_csrf=$CSRF")"
    if [ "$CODE" = "302" ] || [ "$CODE" = "303" ]; then
        echo "  ok  - setup redirected to the login page ($CODE)"
    else
        echo "  FAIL - POST /setup returned $CODE (expected redirect)"
        FAILED=1
    fi
fi
CODE="$(curl -s -o /dev/null -w '%{http_code}' "$URL/setup")"
if [ "$CODE" = "404" ]; then
    echo "  ok  - /setup is 404 after the first account exists"
else
    echo "  FAIL - GET /setup returned $CODE (expected 404)"
    FAILED=1
fi

echo "[3/9] Login with the created credentials"
BODY="$(curl -sf -b /tmp/cb_smoke_cookies.txt -c /tmp/cb_smoke_cookies.txt -X POST "$URL/login" \
    --data-urlencode 'username=smoketest' --data-urlencode 'password=smoke-pass-1' \
    --data-urlencode "_csrf=$CSRF" \
    -o /dev/null -w '%{http_code}')"
if [ "$BODY" = "303" ] || [ "$BODY" = "302" ]; then
    echo "  ok  - login redirected ($BODY)"
else
    echo "  FAIL - login returned $BODY (expected redirect)"
    FAILED=1
fi

BODY="$(curl -sf -b /tmp/cb_smoke_cookies.txt "$URL/writing")"
echo "[4/9] Writing dashboard"
check "dashboard renders" "$BODY" 'Stories'
check "new story form" "$BODY" 'Create story'

echo "[5/9] Language switch to German"
CSRF="$(printf '%s' "$BODY" | grep -o 'name="_csrf" value="[0-9a-f]*"' | head -1 | grep -o '[0-9a-f]\{64\}')"
if [ -z "$CSRF" ]; then
    echo "  FAIL - no CSRF token found on dashboard"; FAILED=1
else
    CODE="$(curl -s -b /tmp/cb_smoke_cookies.txt -o /dev/null -w '%{http_code}' \
        -X POST "$URL/profile/language" \
        --data-urlencode "language=de" --data-urlencode "_csrf=$CSRF")"
    if [ "$CODE" = "200" ]; then
        echo "  ok  - POST /profile/language returned 200"
    else
        echo "  FAIL - POST /profile/language returned $CODE"; FAILED=1
    fi
    BODY="$(curl -sf -b /tmp/cb_smoke_cookies.txt "$URL/writing")"
    check "German nav after switch" "$BODY" 'Schreiben'
    check "German dashboard heading" "$BODY" 'Neue Story'
fi

echo "[6/9] Language dropdown on profile"
BODY="$(curl -sf -b /tmp/cb_smoke_cookies.txt "$URL/profile")"
check "language select present" "$BODY" 'name="language"'
check "German option present" "$BODY" 'Deutsch'

echo "[7/9] Switch back to English"
CSRF="$(printf '%s' "$BODY" | grep -o 'name="_csrf" value="[0-9a-f]*"' | head -1 | grep -o '[0-9a-f]\{64\}')"
CODE="$(curl -s -b /tmp/cb_smoke_cookies.txt -o /dev/null -w '%{http_code}' \
    -X POST "$URL/profile/language" \
    --data-urlencode "language=en" --data-urlencode "_csrf=$CSRF")"
if [ "$CODE" = "200" ]; then
    echo "  ok  - switched back to English"
else
    echo "  FAIL - switch back returned $CODE"; FAILED=1
fi

echo "[8/9] Pages module (admin) and 404 handling"
BODY="$(curl -sf -b /tmp/cb_smoke_cookies.txt "$URL/pages")"
check "pages list renders" "$BODY" 'Pages'
CODE="$(curl -s -b /tmp/cb_smoke_cookies.txt -o /dev/null -w '%{http_code}' "$URL/writing/does-not-exist")"
if [ "$CODE" = "404" ]; then
    echo "  ok  - unknown story yields 404"
else
    echo "  FAIL - unknown story returned $CODE"; FAILED=1
fi

echo "[9/9] Login page (guest view)"
BODY="$(curl -sf "$URL/login")"
check "login page renders" "$BODY" 'Sign in'

rm -f /tmp/cb_smoke_cookies.txt
if [ "$FAILED" = "0" ]; then
    echo ""
    echo "SMOKE TEST PASSED"
else
    echo ""
    echo "SMOKE TEST FAILED"
    exit 1
fi
