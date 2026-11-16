composer := "composer"

phpunit := "vendor/bin/phpunit --no-coverage --do-not-cache-result"

# Host ports of the database services. They are not the container ports, so that a database
# already running on the machine does not clash with them; see docker-compose.yml.
mysql_port := "13306"
mariadb_port := "13307"
pgsql_port := "15432"
redis_port := "16379"

# What every suite runs with. The services are reached through the published host ports.
run_db := "DB_HOST=127.0.0.1 REDIS_HOST=127.0.0.1 REDIS_PORT=" + redis_port

# Print the exports a shell needs to run PHPUnit by hand.
env:
    @echo 'export DB_HOST=127.0.0.1 REDIS_PORT={{redis_port}}'
    @echo 'export DB_PORT={{mysql_port}} DB_CONNECTION=mysql   # or DB_PORT={{mariadb_port}} DB_CONNECTION=mariadb, DB_PORT={{pgsql_port}} DB_CONNECTION=pgsql'

# Run the whole suite on SQLite with phpredis, the default target.
default: test

# Install the PHP dependencies from the lock file.
deps:
    {{composer}} install --no-interaction --prefer-dist --no-progress

# Start Redis, MySQL, MariaDB and PostgreSQL and wait until they accept connections.
services:
    docker compose up -d --wait

# Stop the database services and delete their volumes.
down:
    docker compose down -v

# Run the suite on SQLite. Flags go to PHPUnit: `just test --filter=RowKeysTest`.
test *args: deps services
    just _suite sqlite '' {{args}}

# Run the suite on MySQL.
test-mysql *args: deps services
    just _suite mysql '' {{args}}

# Run the suite on MariaDB.
test-mariadb *args: deps services
    just _suite mariadb '' {{args}}

# Run the suite on PostgreSQL.
test-pgsql *args: deps services
    just _suite pgsql '' {{args}}

# Run every database with both Redis clients.
test-matrix *args: deps services
    #!/usr/bin/env bash
    set -euo pipefail
    for client in phpredis predis; do
        for driver in sqlite mysql mariadb pgsql; do
            echo "== $driver / $client"
            REDIS_CLIENT="$client" just _suite "$driver" '' {{args}}
        done
    done

# Run the differential fuzz suite on every database. Tune it with MODEL_CACHE_FUZZ_ITERATIONS.
fuzz *args: deps services
    #!/usr/bin/env bash
    set -euo pipefail
    for driver in sqlite mysql pgsql; do
        echo "== fuzz $driver"
        just _suite "$driver" Fuzz {{args}}
    done

# Run the multi-process suite, like several pods sharing one database and Redis.
concurrency *args: deps services
    #!/usr/bin/env bash
    set -euo pipefail
    for client in phpredis predis; do
        for driver in mysql pgsql; do
            echo "== concurrency $driver / $client"
            REDIS_CLIENT="$client" just _suite "$driver" Concurrency {{args}}
        done
    done

# Measure line coverage of the Unit and Integration suites over every database, and Predis on SQLite, and fail below 100%.
coverage: deps services
    #!/usr/bin/env bash
    set -euo pipefail
    out="$(mktemp -d)"
    trap 'rm -rf "$out"' EXIT
    run() {
        local driver="$1" port="$2" client="$3" redis_db="$4"
        if DB_HOST=127.0.0.1 DB_CONNECTION="$driver" DB_PORT="$port" REDIS_HOST=127.0.0.1 REDIS_PORT={{redis_port}} \
            REDIS_CLIENT="$client" REDIS_DB="$redis_db" REDIS_CACHE_DB="$((redis_db + 1))" \
            vendor/bin/phpunit --do-not-cache-result --testsuite Unit,Integration --coverage-php "$out/$driver-$client.cov" > "$out/$driver-$client.log" 2>&1; then
            echo "== $driver / $client passed"
        else
            cat "$out/$driver-$client.log"
            echo "== $driver / $client failed"
            return 1
        fi
    }
    pids=()
    run sqlite '' phpredis 2 & pids+=("$!")
    run mysql {{mysql_port}} phpredis 4 & pids+=("$!")
    run mariadb {{mariadb_port}} phpredis 6 & pids+=("$!")
    run pgsql {{pgsql_port}} phpredis 8 & pids+=("$!")
    run sqlite '' predis 10 & pids+=("$!")
    status=0
    for pid in "${pids[@]}"; do wait "$pid" || status=1; done
    [ "$status" -eq 0 ]
    php -d memory_limit=-1 vendor/bin/phpcov merge --clover "$out/clover.xml" "$out" > /dev/null
    php .github/scripts/check-coverage.php --clover="$out/clover.xml"

# Report cached versus uncached read throughput and what a cached read costs in Redis commands.
bench: deps services
    {{run_db}} DB_CONNECTION=mysql DB_PORT={{mysql_port}} {{phpunit}} tests/Benchmark

# Render the README banners from art/icon.svg, with the title and tagline in Inter as outlines.
art:
    #!/usr/bin/env bash
    set -euo pipefail
    font="$(nix build --no-link --print-out-paths nixpkgs#inter)/share/fonts/truetype/InterVariable.ttf"
    nix shell --impure --expr 'with import (builtins.getFlake "nixpkgs") {}; python3.withPackages (p: [p.fonttools])' --command python3 -I art/render-banner.py "$font"

# Run the code formatter, which rewrites files.
format:
    vendor/bin/pint

# Check the code formatter without changing anything.
lint:
    vendor/bin/pint --test

# Run PHPStan over src.
analyse:
    vendor/bin/phpstan analyse --memory-limit=1G

# Run one suite against one database. Internal: the recipes above loop over the drivers.
_suite driver testsuite *args:
    #!/usr/bin/env bash
    set -euo pipefail
    case "{{driver}}" in
        mysql) port={{mysql_port}} ;;
        mariadb) port={{mariadb_port}} ;;
        pgsql) port={{pgsql_port}} ;;
        *) port= # SQLite ignores it
    esac
    suite=()
    if [ -n "{{testsuite}}" ]; then suite=(--testsuite "{{testsuite}}"); fi
    {{run_db}} DB_CONNECTION="{{driver}}" DB_PORT="$port" {{phpunit}} "${suite[@]}" {{args}}
