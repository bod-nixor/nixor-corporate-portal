#!/usr/bin/env bash
set -euo pipefail

BASE_URL=${BASE_URL:-"https://ncp.nixorcorporate.com"}
SESSION_COOKIE=${SESSION_COOKIE:-""}
ENTITY_ID=${ENTITY_ID:-""}

if [[ -z "$SESSION_COOKIE" ]]; then
  echo "SESSION_COOKIE is required. Example: SESSION_COOKIE='PHPSESSID=...'."
  exit 1
fi

if [[ -z "$ENTITY_ID" ]]; then
  echo "ENTITY_ID is required. Example: ENTITY_ID=1"
  exit 1
fi

function check_endpoint() {
  local label=$1
  local url=$2
  echo "Checking ${label}: ${url}"
  local status
  status=$(curl -sS -o /tmp/ncp_check_body.json -w "%{http_code}" \
    -H "Accept: application/json" \
    -H "Cookie: ${SESSION_COOKIE}" \
    "${url}")
  local body
  body=$(head -c 200 /tmp/ncp_check_body.json || true)
  echo "Status: ${status}"
  echo "Body (first 200 chars): ${body}"
  if [[ "${status}" != "200" ]]; then
    echo "❌ ${label} failed"
    exit 1
  fi
  echo "✅ ${label} ok"
  echo ""
}

check_endpoint "Users list" "${BASE_URL}/api/users"
check_endpoint "Drive list" "${BASE_URL}/api/drive/list?entity_id=${ENTITY_ID}"
