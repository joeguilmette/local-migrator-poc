#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <tag> [release-notes-file]" >&2
  exit 1
fi

TAG="$1"
NOTES_FILE="${2:-}"
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

# Parse version from tag (strip 'v' prefix)
VERSION="${TAG#v}"
if [[ ! "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "[localpoc] Invalid version format: ${VERSION}. Expected X.Y.Z" >&2
  exit 1
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
mkdir -p "${RELEASES_DIR}"

PHAR_RELEASE_NAME="localpoc-${VERSION}.phar"
PHAR_RELEASE_PATH="${RELEASES_DIR}/${PHAR_RELEASE_NAME}"

# Copy new artifacts to releases
cp "${DIST_PHAR}" "${PHAR_RELEASE_PATH}"
cp "${PLUGIN_ZIP_PATH}" "${RELEASES_DIR}/${PLUGIN_ZIP_NAME}"

echo "[localpoc] Copied artifacts to releases directory."

# Keep only 2 latest PHARs
echo "[localpoc] Cleaning up old PHAR versions..."
ls -t "${RELEASES_DIR}"/localpoc-*.phar 2>/dev/null | tail -n +3 | while read -r file; do
  rm -f "$file"
done

# Keep only 2 latest plugin ZIPs
echo "[localpoc] Cleaning up old plugin versions..."
ls -t "${RELEASES_DIR}"/localpoc-plugin-*.zip 2>/dev/null | tail -n +3 | while read -r file; do
  rm -f "$file"
done

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
