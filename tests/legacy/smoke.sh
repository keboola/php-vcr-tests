#!/bin/sh
# VcrTestCaseTrait on the oldest supported stack (PHP 7.2), where the library's own suite (PHPUnit 9+) cannot run.
# Installs this checkout next to the given PHPUnit version in a temporary project and runs a hook fixture against the
# fake control port. Usage: tests/legacy/smoke.sh <phpunit constraint> <fixture file> <fixture class>
set -eu
repo=$(cd "$(dirname "$0")/../.." && pwd)
phpunit=$1
fixture=$repo/$2
class=$3
work=$(mktemp -d)
log=$work/vcr.log
port=8765

cat > "$work/composer.json" <<JSON
{
  "repositories": [{"type": "path", "url": "$repo", "options": {"symlink": false}}],
  "require": {"keboola/php-vcr-tests": "*@dev", "phpunit/phpunit": "$phpunit"},
  "config": {"policy": {"advisories": {"block": false}}}
}
JSON
# old PHPUnit releases carry security advisories; this throwaway project only runs the fixture
composer --working-dir="$work" install --no-interaction --no-progress --quiet

serve() {
  FAKE_VCR_LOG=$log FAKE_VCR_STOP=${1:-} php -S 127.0.0.1:$port "$repo/tests/fixtures/control-router.php" \
    >/dev/null 2>&1 &
  server=$!
  i=0
  until php -r "exit(@fsockopen('127.0.0.1', $port) ? 0 : 1);"; do
    i=$((i + 1)); [ $i -lt 50 ] || { echo "fake proxy did not start"; exit 1; }; sleep 0.1
  done
}
run() {
  VCR_CONTROL_URL=http://127.0.0.1:$port FAKE_VCR_LOG=$log VCR_MODE=$1 \
    php "$work/vendor/bin/phpunit" --no-configuration --bootstrap "$work/vendor/autoload.php" --filter "$2" "$fixture"
}
sequence() {
  php -r '$o = []; foreach (file($argv[1]) as $l) { $c = json_decode($l, true);
    $o[] = $c["path"] === "marker" ? "marker:" . $c["body"] : $c["path"]; } echo implode(" ", $o);' "$log"
}
fail() { echo "FAIL: $*"; exit 1; }

php -v | head -1
php "$work/vendor/bin/phpunit" --version

# 1. The cassette wraps setUp(), the test and tearDown(), and is named after the class and method.
: > "$log"; serve
run record testPlain || fail "testPlain failed in record mode"
kill $server; wait $server 2>/dev/null || true
expected="/start marker:setUp /status marker:test /status marker:tearDown /status /stop"
[ "$(sequence)" = "$expected" ] || fail "order: $(sequence)"
grep -q "\"cassette\":\".*cassettes\\\\/$class\\\\/testPlain.json\"" "$log" || fail "cassette path: $(head -1 "$log")"

# 2. A live-only test is skipped in replay and never reaches its body.
: > "$log"; serve
run replay testLiveOnly || fail "testLiveOnly failed in replay mode"
kill $server; wait $server 2>/dev/null || true
case "$(sequence)" in *live-only*) fail "live-only test ran in replay";; esac

# 3. A request with no recorded answer fails the test.
: > "$log"; serve '{"mode":"replay","cassette":"/x/testPlain.json","unmatched":["GET https://api.example.com/items"]}'
if out=$(run replay testPlain); then fail "unmatched request did not fail the test"; fi
kill $server; wait $server 2>/dev/null || true
echo "$out" | grep -q "GET https://api.example.com/items" || fail "unmatched request not reported"

echo "OK: VcrTestCaseTrait on $(php -r 'echo PHP_VERSION;') with PHPUnit $phpunit"
rm -rf "$work"
