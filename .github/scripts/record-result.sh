#!/usr/bin/env bash
set -euo pipefail

usage="usage: record-result.sh <output.json> <outcome> [benchmark.json]"
output="${1:?$usage}"
outcome="${2:?$usage}"
benchmark="${3:-}"

: "${DB_CONNECTION:?DB_CONNECTION must name the database driver}"
: "${REDIS_CLIENT:?REDIS_CLIENT must name the Redis client}"
: "${REDIS_CONTAINER:?REDIS_CONTAINER must hold the Redis service container id}"
: "${JOB_KIND:?JOB_KIND must be tests or benchmark}"
suite="${SUITE:-}"
dependencies="${DEPENDENCIES:-locked}"
database_container="${DATABASE_CONTAINER:-}"

first_version() {
    grep -oE '[0-9]+(\.[0-9]+)+' | head -n 1
}

package_version() {
    # shellcheck disable=SC2016
    php -r 'require "vendor/autoload.php"; echo ltrim(Composer\InstalledVersions::getPrettyVersion($argv[1]) ?? throw new RuntimeException($argv[1]." is not installed"), "v");' -- "$1"
}

database_version() {
    case "$DB_CONNECTION" in
        sqlite) php -r 'echo (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn();' ;;
        mysql) docker exec "$database_container" mysqld --version | first_version ;;
        mariadb) docker exec "$database_container" mariadbd --version | first_version ;;
        pgsql) docker exec "$database_container" postgres --version | first_version ;;
        *) echo "unknown database driver $DB_CONNECTION" >&2; return 1 ;;
    esac
}

redis_client_version() {
    case "$REDIS_CLIENT" in
        phpredis) php -r 'echo phpversion("redis");' ;;
        predis) package_version predis/predis ;;
        *) echo "unknown Redis client $REDIS_CLIENT" >&2; return 1 ;;
    esac
}

if [ "$DB_CONNECTION" != sqlite ] && [ -z "$database_container" ]; then
    echo "DATABASE_CONTAINER must hold the $DB_CONNECTION service container id" >&2
    exit 1
fi

php_version="$(php -r 'echo PHP_VERSION;')"
laravel_version="$(package_version laravel/framework)"
database_version="$(database_version)"
redis_client_version="$(redis_client_version)"
redis_version="$(docker exec "$REDIS_CONTAINER" redis-server --version | grep -oE 'v=[0-9.]+' | cut -d= -f2)"

benchmark_json='null'
if [ -n "$benchmark" ]; then
    benchmark_json="$(jq -e . "$benchmark")"
fi

temporary="$output.tmp"
jq -n \
    --arg kind "$JOB_KIND" \
    --arg outcome "$outcome" \
    --arg suite "$suite" \
    --arg dependencies "$dependencies" \
    --arg php "$php_version" \
    --arg laravel "$laravel_version" \
    --arg database "$DB_CONNECTION" \
    --arg database_version "$database_version" \
    --arg redis_client "$REDIS_CLIENT" \
    --arg redis_client_version "$redis_client_version" \
    --arg redis_version "$redis_version" \
    --argjson benchmark "$benchmark_json" \
    '{kind: $kind, outcome: $outcome, suite: $suite, dependencies: $dependencies, php: $php, laravel: $laravel,
      database: $database, database_version: $database_version, redis_client: $redis_client,
      redis_client_version: $redis_client_version, redis_version: $redis_version, benchmark: $benchmark}' \
    > "$temporary"
mv "$temporary" "$output"
