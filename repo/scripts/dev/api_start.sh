#!/usr/bin/env bash
set -euo pipefail

/workspace/scripts/dev/bootstrap_runtime.sh
bash /workspace/scripts/dev/ensure_backend_vendor.sh

set -a
source /workspace/runtime/dev/runtime.env
set +a

export APP_ENV="${APP_ENV:-dev}"
export APP_DEBUG="${APP_DEBUG:-1}"
export DATABASE_URL="mysql://${DB_USER}:${DB_PASSWORD}@db:3306/${DB_NAME}?serverVersion=8.4.0&charset=utf8mb4"
export MESSENGER_TRANSPORT_DSN="doctrine://default?queue_name=async"
export FIELD_ENCRYPTION_KEYRING_PATH="${FIELD_ENCRYPTION_KEYRING_PATH}"

cd /workspace/backend

php -r '
$retries = 60;
for ($i = 0; $i < $retries; $i++) {
    try {
        $dsn = getenv("DATABASE_URL");
        $dsn = preg_replace("#^mysql://#", "", $dsn);
        [$auth, $hostDb] = explode("@", $dsn, 2);
        [$user, $pass] = explode(":", $auth, 2);
        [$hostPort, $dbQuery] = explode("/", $hostDb, 2);
        [$host, $port] = explode(":", $hostPort, 2);
        [$db] = explode("?", $dbQuery, 2);
        new PDO("mysql:host=$host;port=$port;dbname=$db", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        exit(0);
    } catch (Throwable $e) {
        usleep(500000);
    }
}
fwrite(STDERR, "Database connection timed out.\n");
exit(1);
'

/workspace/init_db.sh --container

exec php -S 0.0.0.0:8000 -t public
