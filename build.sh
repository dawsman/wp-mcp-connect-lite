#!/bin/bash
#
# Build a distributable ZIP of WP MCP Connect.
# Run from the repo root: ./build.sh
#
# On success this also updates update-info.json with the SHA256 of the built
# ZIP so the self-updater will accept it. The updater refuses any release
# whose manifest is missing a 64-hex zip_sha256, so this step is mandatory.
#
set -e

PLUGIN_SLUG="wp-mcp-connect"
# Portable across macOS (BSD grep, no -P) and Linux. The bootstrap line reads:
#   define( 'WP_MCP_CONNECT_VERSION', '1.2.3' );
# Splitting on "'" puts the version string in field 4.
VERSION=$(awk -F"'" '/WP_MCP_CONNECT_VERSION/ {print $4; exit}' wp-mcp-connect.php)
if [ -z "${VERSION}" ]; then
    echo "ERROR: Could not read WP_MCP_CONNECT_VERSION from wp-mcp-connect.php." >&2
    exit 1
fi
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
BUILD_DIR=$(mktemp -d)
DEST="${BUILD_DIR}/wp-mcp-connector"
MANIFEST="update-info.json"

echo "Building ${ZIP_NAME}..."

# Sanity: plugin header version must match manifest version. Catches the
# common slip where one is bumped without the other.
MANIFEST_VERSION=$(php -r '$j = json_decode(file_get_contents($argv[1])); echo $j && !empty($j->version) ? $j->version : "";' "${MANIFEST}")
if [ -z "${MANIFEST_VERSION}" ]; then
    echo "ERROR: Could not read version from ${MANIFEST}." >&2
    exit 1
fi
if [ "${MANIFEST_VERSION}" != "${VERSION}" ]; then
    echo "ERROR: version mismatch — wp-mcp-connect.php says ${VERSION}, ${MANIFEST} says ${MANIFEST_VERSION}." >&2
    echo "       Update both before building." >&2
    exit 1
fi

# Copy plugin files
echo "Assembling plugin..."
mkdir -p "${DEST}"

cp wp-mcp-connect.php "${DEST}/"
cp uninstall.php "${DEST}/"
cp -r includes "${DEST}/"
# NOTE: update-info.json is intentionally NOT bundled in the ZIP. The updater
# fetches it from WP_MCP_CONNECT_UPDATE_URL; a local copy inside the install
# would be vestigial and would break build idempotency (the zip hash would
# depend on the zip's own manifest, which holds that hash — a chicken-and-egg).

# Remove dev-only files that may have been copied
find "${DEST}" -name "*.test.php" -delete 2>/dev/null || true

# Create ZIP
echo "Creating ZIP..."
(cd "${BUILD_DIR}" && zip -qr "${OLDPWD}/${ZIP_NAME}" wp-mcp-connector/)

# Cleanup
rm -rf "${BUILD_DIR}"

# Compute SHA256 of the built ZIP. shasum ships with macOS; sha256sum on Linux.
if command -v sha256sum >/dev/null 2>&1; then
    ZIP_SHA256=$(sha256sum "${ZIP_NAME}" | awk '{print $1}')
elif command -v shasum >/dev/null 2>&1; then
    ZIP_SHA256=$(shasum -a 256 "${ZIP_NAME}" | awk '{print $1}')
else
    echo "ERROR: need sha256sum or shasum on PATH." >&2
    exit 1
fi

if ! [[ "${ZIP_SHA256}" =~ ^[0-9a-f]{64}$ ]]; then
    echo "ERROR: computed hash '${ZIP_SHA256}' is not a 64-char hex string." >&2
    exit 1
fi

# Write the hash into update-info.json (pretty-printed, stable key order).
# Using PHP because it ships with the project already and avoids a jq dependency.
php -r '
$path = $argv[1];
$hash = $argv[2];
$data = json_decode(file_get_contents($path));
if (!is_object($data)) { fwrite(STDERR, "manifest not an object\n"); exit(1); }
$data->zip_sha256 = $hash;
$out = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($out === false) { fwrite(STDERR, "encode failed\n"); exit(1); }
file_put_contents($path, $out . "\n");
' "${MANIFEST}" "${ZIP_SHA256}"

echo "Done: ${ZIP_NAME} ($(du -h "${ZIP_NAME}" | cut -f1))"
echo "      SHA256: ${ZIP_SHA256}"
echo ""
echo "Next steps to publish this release:"
echo "  1. Commit the updated ${MANIFEST} (zip_sha256 = ${ZIP_SHA256:0:12}…)"
echo "  2. Push ${MANIFEST} to the manifest URL fetched by the updater:"
echo "       $(awk -F"'" '/WP_MCP_CONNECT_UPDATE_URL/ {print $4; exit}' wp-mcp-connect.php)"
echo "  3. Upload ${ZIP_NAME} to the release download_url declared in ${MANIFEST}."
echo "  4. Do all three — steps 1 and 2 without 3 break the updater (hash mismatch)."
