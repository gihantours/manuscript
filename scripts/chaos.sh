#!/usr/bin/env sh
set -eu

ACTION="${1:-worker}"
WAIT_SECONDS="${2:-15}"

cd "$(dirname "$0")/.."

if [ "$ACTION" = "worker" ]; then
    echo "Killing the Horizon worker mid-run..."
    docker compose kill horizon
    sleep "$WAIT_SECONDS"
    echo "Restarting the worker; in-flight jobs should be recovered."
    docker compose start horizon
    exit 0
fi

echo "Stopping the similarity/email mock..."
docker compose stop mock
sleep "$WAIT_SECONDS"
echo "Restarting the mock. Similarity jobs should back off and then resume."
docker compose start mock
