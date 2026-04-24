#!/usr/bin/env bash
set -euo pipefail

PORT="${PORT:-3000}"
HOST="${HOST:-0.0.0.0}"

echo "Starting PHP server at ${HOST}:${PORT}"
exec php -S "${HOST}:${PORT}"
