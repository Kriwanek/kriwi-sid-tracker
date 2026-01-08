#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TOOLS_DIR="${ROOT_DIR}/vendor"
GOAT_REPO="https://github.com/leafo/goattracker2.git"
GOAT_DIR="${TOOLS_DIR}/goattracker2"

mkdir -p "${TOOLS_DIR}"

if [[ -d "${GOAT_DIR}/.git" ]]; then
  echo "Updating GoatTracker 2 in ${GOAT_DIR}..."
  git -C "${GOAT_DIR}" pull --ff-only
else
  echo "Cloning GoatTracker 2 into ${GOAT_DIR}..."
  git clone --depth 1 "${GOAT_REPO}" "${GOAT_DIR}"
fi
