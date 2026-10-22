#!/usr/bin/env bash
#
# Build a WordPress.org–ready plugin zip and optional SVN assets archive.
#
# Output goes next to the plugin (../webba-deploy-dist/) so Plugin Check does
# not flag compressed files inside the plugin directory.
#
# Usage:
#   ./build-release.sh                 # plugin zip only
#   ./build-release.sh --all           # plugin zip + SVN assets zip
#   ./build-release.sh --tag           # create git tag v{version} after building (clean tree only)
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_SLUG="webba-deploy"
# Outside the plugin tree so PCP compressed_files does not flag the zip.
BUILD_DIR="${ROOT}/../${PLUGIN_SLUG}-dist"
DIST_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"
DISTIGNORE="${ROOT}/.distignore"
ASSETS_DIR="${ROOT}/assets/wordpress-org"
LEGACY_BUILD="${ROOT}/build"

PACKAGE_SVN_ASSETS=0
TAG_RELEASE=0

for arg in "$@"; do
	case "$arg" in
		--all)
			PACKAGE_SVN_ASSETS=1
			;;
		--tag)
			TAG_RELEASE=1
			;;
		--help|-h)
			echo "Usage: $0 [--all] [--tag]"
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

get_constant_version() {
	php -r "
		\$contents = file_get_contents( '${ROOT}/webba-deploy.php' );
		if ( preg_match( '/WEBBA_DEPLOY_VERSION\',\s*\'([^\']+)\'/', \$contents, \$m ) ) {
			echo trim( \$m[1] );
		}
	"
}

get_stable_tag() {
	sed -n 's/^Stable tag:[[:space:]]*//p' "${ROOT}/readme.txt" | head -n 1 | tr -d '\r' | sed 's/[[:space:]]*$//'
}

VERSION="$(get_version)"
if [[ -z "${VERSION}" ]]; then
	echo "Could not read plugin version from webba-deploy.php" >&2
	exit 1
fi

CONSTANT_VERSION="$(get_constant_version)"
STABLE_TAG="$(get_stable_tag)"

if [[ "${CONSTANT_VERSION}" != "${VERSION}" ]]; then
	echo "Version mismatch: webba-deploy.php header is ${VERSION}, WEBBA_DEPLOY_VERSION is ${CONSTANT_VERSION}." >&2
	exit 1
fi

if [[ "${STABLE_TAG}" != "${VERSION}" ]]; then
	echo "Version mismatch: webba-deploy.php header is ${VERSION}, readme.txt Stable tag is ${STABLE_TAG}." >&2
	exit 1
fi

if ! grep -q "^= ${VERSION} =" "${ROOT}/readme.txt"; then
	echo "Warning: readme.txt has no '= ${VERSION} =' changelog entry." >&2
fi

ZIP_PATH="${BUILD_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"
SVN_ASSETS_ZIP="${BUILD_DIR}/${PLUGIN_SLUG}-wordpress-org-assets-${VERSION}.zip"

# Drop legacy in-plugin build/ so Plugin Check stops flagging nested zips.
if [[ -d "${LEGACY_BUILD}" ]]; then
	echo "Removing legacy ${LEGACY_BUILD}/ (output is now ${BUILD_DIR}/)…"
	rm -rf "${LEGACY_BUILD}"
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
	--exclude '._*' \
	--exclude '.DS_Store' \
	"${ROOT}/" "${DIST_DIR}/"

# Belt-and-suspenders: strip macOS AppleDouble / junk if any slipped through.
find "${DIST_DIR}" -mindepth 1 \( -name '._*' -o -name '.DS_Store' -o -name '.*' \) -exec rm -rf {} + 2>/dev/null || true

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
		zip -r -q "${SVN_ASSETS_ZIP}" . -x '._*' -x '.DS_Store'
	)
	echo "SVN assets zip: ${SVN_ASSETS_ZIP}"
	echo "Upload contents to plugins.svn.wordpress.org/${PLUGIN_SLUG}/assets/"
fi

if [[ "${TAG_RELEASE}" -eq 1 ]]; then
	if git -C "${ROOT}" rev-parse -q --verify "refs/tags/v${VERSION}" >/dev/null; then
		echo "Tag v${VERSION} already exists."
	elif [[ -n "$(git -C "${ROOT}" status --porcelain)" ]]; then
		echo "Refusing to tag v${VERSION}: working tree is not clean." >&2
		exit 1
	else
		git -C "${ROOT}" tag -a "v${VERSION}" -m "${PLUGIN_SLUG} ${VERSION}"
		echo "Tagged v${VERSION}."
	fi
fi

echo "Done."
