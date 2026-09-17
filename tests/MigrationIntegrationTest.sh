#!/usr/bin/env bash
set -euo pipefail
export APP_ENV=testing APP_URL=http://localhost APP_KEY=test-only-key-that-is-at-least-thirty-two-bytes
export DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=espforge;charset=utf8mb4' DB_USER=root DB_PASSWORD=root
for attempt in {1..30}; do mysqladmin ping -h127.0.0.1 -uroot -proot --silent && break; sleep 2; done
mysql -h127.0.0.1 -uroot -proot -e 'DROP DATABASE IF EXISTS espforge; CREATE DATABASE espforge CHARACTER SET utf8mb4;'
mysql -h127.0.0.1 -uroot -proot espforge < database/schema.sql
php bin/migrate.php --baseline-schema
php bin/migrate.php
if ! preflight_output=$(php bin/preflight.php 2>&1); then
  echo "::error title=Production preflight failed::${preflight_output//$'\n'/'%0A'}"
  exit 1
fi
echo "$preflight_output"
count=$(mysql -N -h127.0.0.1 -uroot -proot espforge -e 'SELECT COUNT(*) FROM schema_migrations')
expected=$(find database/migrations -maxdepth 1 -name '*.sql' | wc -l)
[[ "$count" -eq "$expected" ]]
# A conflicting partial migration must be rejected rather than silently recorded.
mysql -h127.0.0.1 -uroot -proot -e 'DROP DATABASE espforge; CREATE DATABASE espforge CHARACTER SET utf8mb4;'
mysql -h127.0.0.1 -uroot -proot espforge < database/schema.sql
mysql -h127.0.0.1 -uroot -proot espforge -e 'DELETE FROM schema_migrations; ALTER TABLE builds MODIFY source_commit_sha VARCHAR(12) NULL;'
if php bin/migrate.php --baseline-through=20260912_007_build_target_identity; then echo 'Conflicting migration unexpectedly succeeded' >&2; exit 1; fi
echo 'Migration integration tests passed'
