#!/usr/bin/env bash
#
# Build a WordPress.org–ready plugin zip and optional SVN assets archive.
#
# Usage:
#   ./build-release.sh              # plugin zip only
#   ./build-release.sh --assets     # regenerate wordpress.org PNGs first
#   ./build-release.sh --all        # assets + plugin zip + SVN assets zip
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_SLUG="webba-deploy"
BUILD_DIR="${ROOT}/build"
DIST_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"
DISTIGNORE="${ROOT}/.distignore"
ASSETS_DIR="${ROOT}/assets/wordpress-org"

REGENERATE_ASSETS=0
PACKAGE_SVN_ASSETS=0

for arg in "$@"; do
	case "$arg" in
		--assets)
			REGENERATE_ASSETS=1
			;;
		--all)
			REGENERATE_ASSETS=1
			PACKAGE_SVN_ASSETS=1
			;;
		--help|-h)
			echo "Usage: $0 [--assets] [--all]"
			exit 0
			;;
		*)
			echo "Unknown option: $arg" >&2
			exit 1
			;;
	esac
done

get_version() {
	php -r "
		\$contents = file_get_contents( '${ROOT}/webba-deploy.php' );
		if ( preg_match( '/^\\s*\\*\\s*Version:\\s*(.+)$/m', \$contents, \$m ) ) {
			echo trim( \$m[1] );
		}
	"
}

VERSION="$(get_version)"
if [[ -z "${VERSION}" ]]; then
	echo "Could not read plugin version from webba-deploy.php" >&2
	exit 1
fi

ZIP_PATH="${BUILD_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"
SVN_ASSETS_ZIP="${BUILD_DIR}/${PLUGIN_SLUG}-wordpress-org-assets-${VERSION}.zip"

if [[ "${REGENERATE_ASSETS}" -eq 1 ]]; then
	echo "Generating WordPress.org assets…"
	python3 "${ROOT}/scripts/generate-wordpress-org-assets.py"
fi

if [[ ! -f "${ASSETS_DIR}/icon-256x256.png" ]]; then
	echo "WordPress.org assets missing; generating…"
	python3 "${ROOT}/scripts/generate-wordpress-org-assets.py"
fi

echo "Building ${PLUGIN_SLUG} ${VERSION}…"
rm -rf "${DIST_DIR}"
mkdir -p "${BUILD_DIR}"

RSYNC_EXCLUDES=()
while IFS= read -r line || [[ -n "$line" ]]; do
	line="${line%%#*}"
	line="$(echo -n "$line" | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
	[[ -z "$line" ]] && continue
	RSYNC_EXCLUDES+=(--exclude "$line")
done < "${DISTIGNORE}"

rsync -a \
	"${RSYNC_EXCLUDES[@]}" \
	--exclude 'build/' \
	--exclude 'scripts/' \
	--exclude 'assets/wordpress-org/' \
	--exclude 'build-release.sh' \
	"${ROOT}/" "${DIST_DIR}/"

mkdir -p "${BUILD_DIR}"
rm -f "${ZIP_PATH}"
(
	cd "${BUILD_DIR}"
	zip -r -q "${PLUGIN_SLUG}-${VERSION}.zip" "${PLUGIN_SLUG}"
)

echo "Plugin zip: ${ZIP_PATH}"
echo "Size: $(du -h "${ZIP_PATH}" | cut -f1)"

if [[ "${PACKAGE_SVN_ASSETS}" -eq 1 ]]; then
	rm -f "${SVN_ASSETS_ZIP}"
	(
		cd "${ASSETS_DIR}"
		zip -r -q "${SVN_ASSETS_ZIP}" .
	)
	echo "SVN assets zip: ${SVN_ASSETS_ZIP}"
	echo "Upload contents to plugins.svn.wordpress.org/${PLUGIN_SLUG}/assets/"
fi

echo "Done."
