#!/usr/bin/env bash
set -euo pipefail

# Display usage information
show_usage() {
  echo "Usage: $0 [patch|minor|major] [release-notes-file]" >&2
  echo "" >&2
  echo "Automatically fetches the latest release version from GitHub and increments it." >&2
  echo "" >&2
  echo "Arguments:" >&2
  echo "  patch|minor|major  Version increment type (default: patch)" >&2
  echo "  release-notes-file Optional file containing release notes" >&2
  echo "" >&2
  echo "Examples:" >&2
  echo "  $0                     # Increment patch version" >&2
  echo "  $0 minor               # Increment minor version" >&2
  echo "  $0 major notes.md      # Increment major version with release notes" >&2
  exit 1
}

# Function to increment semantic version
increment_version() {
  local version=$1
  local increment_type=$2

  # Parse version components
  IFS='.' read -r major minor patch <<< "${version}"

  case "${increment_type}" in
    major)
      major=$((major + 1))
      minor=0
      patch=0
      ;;
    minor)
      minor=$((minor + 1))
      patch=0
      ;;
    patch)
      patch=$((patch + 1))
      ;;
    *)
      echo "[lm] Invalid increment type: ${increment_type}" >&2
      exit 1
      ;;
  esac

  echo "${major}.${minor}.${patch}"
}

# Parse arguments
INCREMENT_TYPE="patch"  # Default to patch increment
NOTES_FILE=""

if [[ $# -ge 1 ]]; then
  case "$1" in
    patch|minor|major)
      INCREMENT_TYPE="$1"
      NOTES_FILE="${2:-}"
      ;;
    -h|--help|help)
      show_usage
      ;;
    *)
      # Check if it's a valid file (for backwards compatibility with release notes)
      if [[ -f "$1" ]]; then
        NOTES_FILE="$1"
      else
        echo "[lm] Invalid argument: $1" >&2
        show_usage
      fi
      ;;
  esac
fi

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CLI_DIR="${REPO_ROOT}/cli"
PLUGIN_DIR="${REPO_ROOT}/plugin"
RELEASES_DIR="${REPO_ROOT}/releases"
DIST_PHAR="${CLI_DIR}/dist/local-migrator.phar"
BOX_BIN="${CLI_DIR}/vendor/bin/box"

if ! command -v gh >/dev/null 2>&1; then
  echo "[lm] GitHub CLI (gh) is required for publishing releases." >&2
  exit 1
fi

if [[ ! -d "${CLI_DIR}" ]]; then
  echo "[lm] CLI directory not found at ${CLI_DIR}." >&2
  exit 1
fi

# Fetch the latest release version from GitHub
echo "[lm] Fetching latest release from GitHub..."
LATEST_RELEASE=$(gh release list --limit 1 --json tagName --jq '.[0].tagName' 2>/dev/null || echo "")

if [[ -z "${LATEST_RELEASE}" ]]; then
  echo "[lm] No existing releases found. Starting with v0.0.0" >&2
  CURRENT_VERSION="0.0.0"
else
  # Strip 'v' prefix if present
  CURRENT_VERSION="${LATEST_RELEASE#v}"
  echo "[lm] Latest release: v${CURRENT_VERSION}"
fi

# Validate current version format
if [[ ! "${CURRENT_VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "[lm] Invalid version format in latest release: ${CURRENT_VERSION}. Expected X.Y.Z" >&2
  exit 1
fi

# Calculate new version
VERSION=$(increment_version "${CURRENT_VERSION}" "${INCREMENT_TYPE}")
TAG="v${VERSION}"

echo "[lm] Incrementing ${INCREMENT_TYPE} version: v${CURRENT_VERSION} → ${TAG}"

# Check if the new tag already exists
if gh release view "${TAG}" >/dev/null 2>&1; then
  echo "[lm] ERROR: Release ${TAG} already exists!" >&2
  echo "[lm] Latest release is v${CURRENT_VERSION}. Please check GitHub releases." >&2
  exit 1
fi

# Confirm with user
echo ""
echo "[lm] Ready to create release ${TAG}"
echo -n "[lm] Continue? [y/N] "
read -r CONFIRM
if [[ "${CONFIRM}" != "y" && "${CONFIRM}" != "Y" ]]; then
  echo "[lm] Release cancelled."
  exit 0
fi

# Update plugin version
PLUGIN_FILE="${REPO_ROOT}/plugin/local-migrator.php"
if [[ ! -f "${PLUGIN_FILE}" ]]; then
  echo "[lm] Plugin file not found at ${PLUGIN_FILE}." >&2
  exit 1
fi

echo "[lm] Updating plugin version to ${VERSION}..."
sed -i.bak -E "s/(\\* Version: )[0-9]+\\.[0-9]+\\.[0-9]+/\\1${VERSION}/" "${PLUGIN_FILE}"
sed -i.bak -E "s/(define\\('LOCALPOC_VERSION', ')[0-9]+\\.[0-9]+\\.[0-9]+/\\1${VERSION}/" "${PLUGIN_FILE}"
rm "${PLUGIN_FILE}.bak"

# Update CLI version
CLI_FILE="${CLI_DIR}/src/Localpoc/Cli.php"
if [[ ! -f "${CLI_FILE}" ]]; then
  echo "[lm] CLI file not found at ${CLI_FILE}." >&2
  exit 1
fi

echo "[lm] Updating CLI version to ${VERSION}..."
sed -i.bak -E "s/(private const VERSION = ')[0-9]+\\.[0-9]+\\.[0-9]+/\\1${VERSION}/" "${CLI_FILE}"
rm "${CLI_FILE}.bak"

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

# Install PHAR locally
echo "[lm] Installing PHAR locally..."
TARGET_BIN="/usr/local/bin/lm"
ALT_BIN="/usr/local/bin/lm-wp"
existing_lm="$(command -v lm || true)"

if [[ -n "${existing_lm}" && "${existing_lm}" != "${TARGET_BIN}" ]]; then
  echo "[lm] Found existing 'lm' at: ${existing_lm}"
  echo "[lm] Installing as 'lm-wp' to avoid conflict."
  TARGET_BIN="${ALT_BIN}"
fi

if [[ -f "${TARGET_BIN}" ]]; then
  sudo rm "${TARGET_BIN}"
  echo "[lm] Removed previous installation at ${TARGET_BIN}."
fi

if [[ -f "/usr/local/bin/localpoc" ]]; then
  sudo rm /usr/local/bin/localpoc
  echo "[lm] Removed legacy 'localpoc' binary."
fi

sudo mkdir -p /usr/local/bin
sudo install -m 755 "${DIST_PHAR}" "${TARGET_BIN}"
echo "[lm] Installed to ${TARGET_BIN}"

INSTALLED_CMD=$(basename "${TARGET_BIN}")
"${INSTALLED_CMD}" --help >/dev/null 2>&1 || {
  echo "[lm] ERROR: Installation verification failed." >&2
  exit 1
}
echo "[lm] Installation verified."

popd >/dev/null

# Create plugin ZIP
echo "[lm] Creating plugin ZIP..."
PLUGIN_ZIP_NAME="local-migrator-plugin-${VERSION}.zip"
PLUGIN_ZIP_PATH="${REPO_ROOT}/${PLUGIN_ZIP_NAME}"

if [[ ! -d "${PLUGIN_DIR}" ]]; then
  echo "[lm] Plugin directory not found at ${PLUGIN_DIR}." >&2
  exit 1
fi

TMP_DIR="$(mktemp -d)"
cleanup_tmp() {
  rm -rf "${TMP_DIR}"
}
trap cleanup_tmp EXIT

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

PHAR_RELEASE_NAME="local-migrator-${VERSION}.phar"
PHAR_RELEASE_PATH="${RELEASES_DIR}/${PHAR_RELEASE_NAME}"

# Copy new artifacts to releases
cp "${DIST_PHAR}" "${PHAR_RELEASE_PATH}"
cp "${PLUGIN_ZIP_PATH}" "${RELEASES_DIR}/${PLUGIN_ZIP_NAME}"

echo "[lm] Releases directory updated."
ls -lh "${RELEASES_DIR}"

# Clean up temporary plugin ZIP
rm -f "${PLUGIN_ZIP_PATH}"

echo "[lm] Creating GitHub release ${TAG}..."
GH_ARGS=("${TAG}" "${PHAR_RELEASE_PATH}" "${RELEASES_DIR}/${PLUGIN_ZIP_NAME}" "--title" "Local Migrator ${TAG}")
if [[ -n "${NOTES_FILE}" ]]; then
  GH_ARGS+=("--notes-file" "${NOTES_FILE}")
else
  GH_ARGS+=("--notes" "Automated release for Local Migrator ${TAG}.")
fi

gh release create "${GH_ARGS[@]}"

echo "[lm] Release ${TAG} published with PHAR and plugin ZIP."
echo ""
"${INSTALLED_CMD}" --help
