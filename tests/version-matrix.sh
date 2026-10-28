#!/usr/bin/env bash
set -euo pipefail

root=$(dirname "$(dirname "$(realpath "$0")")")
output=${1:?Usage: tests/version-matrix.sh OUTPUT_DIRECTORY [10.5 11.5 12.0 13.0]}
shift
mkdir -p "$output"
output=$(realpath "$output")
if [ "$#" -eq 0 ]; then
    set -- 10.5 11.5 12.0 13.0
fi
scratch=$(mktemp -d)
trap 'rm -rf "$scratch"' EXIT
failed=0
for version in "$@"; do
    case "$version" in
        10.5|11.5|12.0|13.0) ;;
        *) echo "Unsupported matrix version: $version" >&2; exit 2 ;;
    esac
    directory="$scratch/$version"
    mkdir -p "$directory"
    cp "$root/composer.json" "$root/composer.lock" "$root/mago.toml" "$directory/"
    cp -R "$root/src" "$root/tests" "$root/spec" "$directory/"
    history_option=--do-not-cache-result
    if [ "$version" = 13.0 ]; then
        history_option=--do-not-record-test-run-history
    fi
    if (
        composer --working-dir="$directory" update phpunit/phpunit --with-all-dependencies \
            --with="phpunit/phpunit:^$version" --no-interaction --prefer-dist --no-progress &&
        php "$directory/tests/check-spec.php" &&
        php "$directory/tests/run.php" &&
        php "$directory/vendor/bin/phpunit" --no-configuration "$history_option" "$directory/tests/runtime"
    ) > "$output/phpunit-$version.log" 2>&1; then
        echo "PASS PHPUnit ^$version"
    else
        echo "FAIL PHPUnit ^$version ($output/phpunit-$version.log)"
        failed=1
    fi
done
exit "$failed"
