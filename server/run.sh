#!/usr/bin/env -S bash -e

SCRIPT_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$(readlink -f -- "${1:-env}")"
cd -- "$SCRIPT_PATH"

set -o allexport
source -- "$SCRIPT_PATH/env.example"
if ! [[ -f "$ENV_FILE" ]]; then
  echo "Usage: $0 <env-file>"
  exit 1
fi
source -- "$ENV_FILE"
set +o allexport

TEMP_DIR="$(mktemp -d)"
export FIFO_OUTPUT_DYNAMIC_CONFIG="$TEMP_DIR/docker-compose.dynamic.fifo"
mkfifo -- "$FIFO_OUTPUT_DYNAMIC_CONFIG"

COMPOSE_STATIC_YAML_FILE="$SCRIPT_PATH/docker-compose.static.yml"
COMPOSE_DYNAMIC_YAML_FILE="$TEMP_DIR/docker-compose.dynamic.yml"

COMPOSE_STATIC_PID_FILE="$TEMP_DIR/docker-compose.static.pid"
COMPOSE_DYNAMIC_PID_FILE="$TEMP_DIR/docker-compose.dynamic.pid"

function execute_compose() {
  PID_FILE="$1"
  ACTION="$2"
  if [[ -f "$PID_FILE" ]]; then
    PID="$(cat -- "$PID_FILE")"
    pkill -SIGKILL -g "$PID" || true
    wait "$PID" 2>/dev/null || true
    $COMPOSE "${@:3}" down
    rm -- "$PID_FILE"
  fi
  if [[ $ACTION == "up" ]]; then
    setpgid $COMPOSE "${@:3}" up --force-recreate &
    echo "$!" > "$PID_FILE"
  fi
}

function compose_static() {
  execute_compose $COMPOSE_STATIC_PID_FILE "$1" -f "$COMPOSE_STATIC_YAML_FILE"
}

function compose_dynamic() {
  execute_compose $COMPOSE_DYNAMIC_PID_FILE "$1" -f "$COMPOSE_DYNAMIC_YAML_FILE"
}

function poll_dynamic_config() {
  if ! [[ -e "$FIFO_OUTPUT_DYNAMIC_CONFIG" ]]; then
    return 1
  fi
  DATA="$(cat < "$FIFO_OUTPUT_DYNAMIC_CONFIG")"
  # Debounce
  DEBOUNCE_DURATION="5s"
  while true; do
    NEW_DATA="$(timeout "$DEBOUNCE_DURATION" cat "$FIFO_OUTPUT_DYNAMIC_CONFIG" || true)"
    if [[ $NEW_DATA == "" ]]; then
      echo "$DATA" > "$COMPOSE_DYNAMIC_YAML_FILE"
      return 0
    else
      DATA="$NEW_DATA"
    fi
  done
}

function cleanup() {
  compose_dynamic down || true
  compose_static down || true
  rm -rf -- "$TEMP_DIR" || true
}
trap cleanup EXIT

compose_static up

while true; do
  if poll_dynamic_config; then
    ls -lah -- "$COMPOSE_DYNAMIC_YAML_FILE"
    compose_dynamic down
    compose_dynamic up
  else
    break
  fi
done
