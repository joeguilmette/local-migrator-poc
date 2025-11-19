#!/usr/bin/env bash
set -euo pipefail

# Display usage information
show_usage() {
  echo "Usage: $0" >&2
  echo "" >&2
  echo "Creates a local build of the PHAR and Plugin with the current commit hash appended to the version." >&2
  echo "Does NOT create a GitHub release." >&2
  exit 1
}

if [[ $# -gt 0 ]]; then
  if [[ "$1" == "-h" || "$1" == "--help" ]]; then
    show_usage
  fi
fi

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CLI_DIR="${REPO_ROOT}/cli"
PLUGIN_DIR="${REPO_ROOT}/plugin"
RELEASES_DIR="${REPO_ROOT}/releases"
DIST_PHAR="${CLI_DIR}/dist/local-migrator.phar"
BOX_BIN="${CLI_DIR}/vendor/bin/box"

if [[ ! -d "${CLI_DIR}" ]]; then
  echo "[lm] CLI directory not found at ${CLI_DIR}." >&2
  exit 1
fi

# Get current version from CLI file
CLI_FILE="${CLI_DIR}/src/Localpoc/Cli.php"
if [[ ! -f "${CLI_FILE}" ]]; then
  echo "[lm] CLI file not found at ${CLI_FILE}." >&2
  exit 1
fi

# Extract version using grep/sed
CURRENT_VERSION=$(grep "private const VERSION =" "${CLI_FILE}" | sed -E "s/.*private const VERSION = '([0-9]+\.[0-9]+\.[0-9]+)'.*/\1/")

if [[ -z "${CURRENT_VERSION}" ]]; then
  echo "[lm] Could not detect current version from ${CLI_FILE}" >&2
  exit 1
fi

# Get short commit hash
COMMIT_HASH=$(git rev-parse --short HEAD)
BUILD_VERSION="${CURRENT_VERSION}-${COMMIT_HASH}"

echo "[lm] Building version: ${BUILD_VERSION}"

# Update plugin version
PLUGIN_FILE="${REPO_ROOT}/plugin/local-migrator.php"
if [[ ! -f "${PLUGIN_FILE}" ]]; then
  echo "[lm] Plugin file not found at ${PLUGIN_FILE}." >&2
  exit 1
fi

echo "[lm] Updating plugin version to ${BUILD_VERSION}..."
# Backup original files to restore later
cp "${PLUGIN_FILE}" "${PLUGIN_FILE}.bak"
cp "${CLI_FILE}" "${CLI_FILE}.bak"

cleanup() {
  echo "[lm] Restoring original version files..."
  mv "${PLUGIN_FILE}.bak" "${PLUGIN_FILE}"
  mv "${CLI_FILE}.bak" "${CLI_FILE}"
}
trap cleanup EXIT

sed -i.tmp -E "s/(\\* Version: )[0-9]+\\.[0-9]+\\.[0-9]+/\\1${BUILD_VERSION}/" "${PLUGIN_FILE}"
sed -i.tmp -E "s/(define\\('LOCALPOC_VERSION', ')[0-9]+\\.[0-9]+\\.[0-9]+/\\1${BUILD_VERSION}/" "${PLUGIN_FILE}"
rm "${PLUGIN_FILE}.tmp"

# Update CLI version
echo "[lm] Updating CLI version to ${BUILD_VERSION}..."
sed -i.tmp -E "s/(private const VERSION = ')[0-9]+\\.[0-9]+\\.[0-9]+/\\1${BUILD_VERSION}/" "${CLI_FILE}"
rm "${CLI_FILE}.tmp"

pushd "${CLI_DIR}" >/dev/null

echo "[lm] Installing PHP dependencies..."
composer install --prefer-dist --no-progress

if [[ ! -x "${BOX_BIN}" ]]; then
  echo "[lm] Box binary missing. Ensure humbug/box is listed in composer require-dev." >&2
  exit 1
fi

echo "[lm] Building PHAR with Box..."
"${BOX_BIN}" compile

if [[ ! -f "${DIST_PHAR}" ]]; then
  echo "[lm] Expected PHAR not found at ${DIST_PHAR}." >&2
  exit 1
fi

popd >/dev/null

# Create plugin ZIP
echo "[lm] Creating plugin ZIP..."
PLUGIN_ZIP_NAME="local-migrator-plugin-${BUILD_VERSION}.zip"
PLUGIN_ZIP_PATH="${REPO_ROOT}/${PLUGIN_ZIP_NAME}"

if [[ ! -d "${PLUGIN_DIR}" ]]; then
  echo "[lm] Plugin directory not found at ${PLUGIN_DIR}." >&2
  exit 1
fi

TMP_DIR="$(mktemp -d)"
cleanup_tmp() {
  rm -rf "${TMP_DIR}"
}
# Add to existing trap
trap 'cleanup; cleanup_tmp' EXIT

mkdir -p "${TMP_DIR}/local-migrator"
rsync -a --exclude=".DS_Store" --exclude="__MACOSX" "${PLUGIN_DIR}/" "${TMP_DIR}/local-migrator/"

pushd "${TMP_DIR}" >/dev/null
zip -r "${PLUGIN_ZIP_PATH}" "local-migrator" -x "*.DS_Store" -x "__MACOSX/*" >/dev/null
popd >/dev/null

if [[ ! -f "${PLUGIN_ZIP_PATH}" ]]; then
  echo "[lm] Failed to create plugin ZIP." >&2
  exit 1
fi
echo "[lm] Plugin ZIP created: ${PLUGIN_ZIP_PATH}"

# Create releases directory and copy artifacts
echo "[lm] Managing releases directory..."
rm -rf "${RELEASES_DIR}"
mkdir -p "${RELEASES_DIR}"

PHAR_RELEASE_NAME="local-migrator-${BUILD_VERSION}.phar"
PHAR_RELEASE_PATH="${RELEASES_DIR}/${PHAR_RELEASE_NAME}"

# Copy new artifacts to releases
cp "${DIST_PHAR}" "${PHAR_RELEASE_PATH}"
cp "${PLUGIN_ZIP_PATH}" "${RELEASES_DIR}/${PLUGIN_ZIP_NAME}"

echo "[lm] Releases directory updated."
ls -lh "${RELEASES_DIR}"

# Clean up temporary plugin ZIP
rm -f "${PLUGIN_ZIP_PATH}"

echo "[lm] Build complete!"
echo "  PHAR:   ${PHAR_RELEASE_PATH}"
echo "  Plugin: ${RELEASES_DIR}/${PLUGIN_ZIP_NAME}"
