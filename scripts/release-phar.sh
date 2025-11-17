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
      echo "[localpoc] Invalid increment type: ${increment_type}" >&2
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
        echo "[localpoc] Invalid argument: $1" >&2
        show_usage
      fi
      ;;
  esac
fi

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CLI_DIR="${REPO_ROOT}/cli"
PLUGIN_DIR="${REPO_ROOT}/plugin"
RELEASES_DIR="${REPO_ROOT}/releases"
DIST_PHAR="${CLI_DIR}/dist/localpoc.phar"
BOX_BIN="${CLI_DIR}/vendor/bin/box"

if ! command -v gh >/dev/null 2>&1; then
  echo "[localpoc] GitHub CLI (gh) is required for publishing releases." >&2
  exit 1
fi

if [[ ! -d "${CLI_DIR}" ]]; then
  echo "[localpoc] CLI directory not found at ${CLI_DIR}." >&2
  exit 1
fi

# Fetch the latest release version from GitHub
echo "[localpoc] Fetching latest release from GitHub..."
LATEST_RELEASE=$(gh release list --limit 1 --json tagName --jq '.[0].tagName' 2>/dev/null || echo "")

if [[ -z "${LATEST_RELEASE}" ]]; then
  echo "[localpoc] No existing releases found. Starting with v0.0.0" >&2
  CURRENT_VERSION="0.0.0"
else
  # Strip 'v' prefix if present
  CURRENT_VERSION="${LATEST_RELEASE#v}"
  echo "[localpoc] Latest release: v${CURRENT_VERSION}"
fi

# Validate current version format
if [[ ! "${CURRENT_VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "[localpoc] Invalid version format in latest release: ${CURRENT_VERSION}. Expected X.Y.Z" >&2
  exit 1
fi

# Calculate new version
VERSION=$(increment_version "${CURRENT_VERSION}" "${INCREMENT_TYPE}")
TAG="v${VERSION}"

echo "[localpoc] Incrementing ${INCREMENT_TYPE} version: v${CURRENT_VERSION} → ${TAG}"

# Check if the new tag already exists
if gh release view "${TAG}" >/dev/null 2>&1; then
  echo "[localpoc] ERROR: Release ${TAG} already exists!" >&2
  echo "[localpoc] Latest release is v${CURRENT_VERSION}. Please check GitHub releases." >&2
  exit 1
fi

# Confirm with user
echo ""
echo "[localpoc] Ready to create release ${TAG}"
echo -n "[localpoc] Continue? [y/N] "
read -r CONFIRM
if [[ "${CONFIRM}" != "y" && "${CONFIRM}" != "Y" ]]; then
  echo "[localpoc] Release cancelled."
  exit 0
fi

# Update plugin version
PLUGIN_FILE="${REPO_ROOT}/plugin/localpoc.php"
if [[ ! -f "${PLUGIN_FILE}" ]]; then
  echo "[localpoc] Plugin file not found at ${PLUGIN_FILE}." >&2
  exit 1
fi

echo "[localpoc] Updating plugin version to ${VERSION}..."
sed -i.bak -E "s/(\\* Version: )[0-9]+\\.[0-9]+\\.[0-9]+/\\1${VERSION}/" "${PLUGIN_FILE}"
sed -i.bak -E "s/(define\\('LOCALPOC_VERSION', ')[0-9]+\\.[0-9]+\\.[0-9]+/\\1${VERSION}/" "${PLUGIN_FILE}"
rm "${PLUGIN_FILE}.bak"

# Update CLI version
CLI_FILE="${CLI_DIR}/src/Localpoc/Cli.php"
if [[ ! -f "${CLI_FILE}" ]]; then
  echo "[localpoc] CLI file not found at ${CLI_FILE}." >&2
  exit 1
fi

echo "[localpoc] Updating CLI version to ${VERSION}..."
sed -i.bak -E "s/(private const VERSION = ')[0-9]+\\.[0-9]+\\.[0-9]+/\\1${VERSION}/" "${CLI_FILE}"
rm "${CLI_FILE}.bak"

pushd "${CLI_DIR}" >/dev/null

echo "[localpoc] Installing PHP dependencies..."
composer install --prefer-dist --no-progress

if [[ ! -x "${BOX_BIN}" ]]; then
  echo "[localpoc] Box binary missing. Ensure humbug/box is listed in composer require-dev." >&2
  exit 1
fi

echo "[localpoc] Building PHAR with Box..."
"${BOX_BIN}" compile

if [[ ! -f "${DIST_PHAR}" ]]; then
  echo "[localpoc] Expected PHAR not found at ${DIST_PHAR}." >&2
  exit 1
fi

# Install PHAR locally
echo "[localpoc] Installing PHAR locally..."
if [[ -f "/usr/local/bin/localpoc" ]]; then
  sudo rm /usr/local/bin/localpoc
  echo "[localpoc] Removed old installation."
fi
sudo mkdir -p /usr/local/bin
sudo install -m 755 "${DIST_PHAR}" /usr/local/bin/localpoc
echo "[localpoc] Installed to /usr/local/bin/localpoc"

localpoc --help >/dev/null 2>&1 || {
  echo "[localpoc] ERROR: Installation verification failed." >&2
  exit 1
}
echo "[localpoc] Installation verified."

popd >/dev/null

# Create plugin ZIP
echo "[localpoc] Creating plugin ZIP..."
PLUGIN_ZIP_NAME="localpoc-plugin-${VERSION}.zip"
PLUGIN_ZIP_PATH="${REPO_ROOT}/${PLUGIN_ZIP_NAME}"

if [[ ! -d "${PLUGIN_DIR}" ]]; then
  echo "[localpoc] Plugin directory not found at ${PLUGIN_DIR}." >&2
  exit 1
fi

pushd "${PLUGIN_DIR}/.." >/dev/null
zip -r "${PLUGIN_ZIP_PATH}" "$(basename "${PLUGIN_DIR}")" \
  -x "*.DS_Store" -x "__MACOSX/*" >/dev/null
popd >/dev/null

if [[ ! -f "${PLUGIN_ZIP_PATH}" ]]; then
  echo "[localpoc] Failed to create plugin ZIP." >&2
  exit 1
fi
echo "[localpoc] Plugin ZIP created: ${PLUGIN_ZIP_PATH}"

# Create releases directory and copy artifacts
echo "[localpoc] Managing releases directory..."
rm -rf "${RELEASES_DIR}"
mkdir -p "${RELEASES_DIR}"

PHAR_RELEASE_NAME="localpoc-${VERSION}.phar"
PHAR_RELEASE_PATH="${RELEASES_DIR}/${PHAR_RELEASE_NAME}"

# Copy new artifacts to releases
cp "${DIST_PHAR}" "${PHAR_RELEASE_PATH}"
cp "${PLUGIN_ZIP_PATH}" "${RELEASES_DIR}/${PLUGIN_ZIP_NAME}"

echo "[localpoc] Releases directory updated."
ls -lh "${RELEASES_DIR}"

# Clean up temporary plugin ZIP
rm -f "${PLUGIN_ZIP_PATH}"

echo "[localpoc] Creating GitHub release ${TAG}..."
GH_ARGS=("${TAG}" "${PHAR_RELEASE_PATH}" "${RELEASES_DIR}/${PLUGIN_ZIP_NAME}" "--title" "LocalPOC CLI ${TAG}")
if [[ -n "${NOTES_FILE}" ]]; then
  GH_ARGS+=("--notes-file" "${NOTES_FILE}")
else
  GH_ARGS+=("--notes" "Automated release for LocalPOC CLI ${TAG}.")
fi

gh release create "${GH_ARGS[@]}"

echo "[localpoc] Release ${TAG} published with PHAR and plugin ZIP."
echo ""
localpoc --help
