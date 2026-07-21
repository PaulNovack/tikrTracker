#!/bin/bash

# Shared helpers for resolving model paths from .env.
# Source from scripts under scripts/train_and_rescore.

resolve_repo_root() {
  local script_dir
  script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
  # scripts/train_and_rescore -> repo root
  cd "$script_dir/../.." >/dev/null 2>&1 && pwd
}

load_env_file() {
  local repo_root
  repo_root="$(resolve_repo_root)"
  local env_file="$repo_root/.env"

  if [[ -f "$env_file" ]]; then
    set -a
    # shellcheck disable=SC1090
    source "$env_file"
    set +a
  fi
}

get_pipeline_model_path() {
  local pipeline="$1"
  local fallback="$2"
  local upper
  upper="$(echo "$pipeline" | tr '[:lower:]' '[:upper:]')"
  local var_name="TRADING_ML_PIPELINE_${upper}_MODEL_PATH"

  load_env_file

  local value="${!var_name:-}"
  if [[ -n "$value" ]]; then
    echo "$value"
  else
    echo "$fallback"
  fi
}
