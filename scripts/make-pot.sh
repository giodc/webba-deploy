#!/usr/bin/env bash
#
# Regenerate languages/webba-deploy.pot from PHP sources.
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
POT="${ROOT}/languages/webba-deploy.pot"
WP_CLI="${WP_CLI:-/tmp/wp-cli.phar}"

if [[ ! -f "${WP_CLI}" ]]; then
	echo "Downloading WP-CLI…"
	curl -fsSL -o "${WP_CLI}" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
fi

mkdir -p "${ROOT}/languages"

php "${WP_CLI}" i18n make-pot "${ROOT}" "${POT}" \
	--domain=webba-deploy \
	--exclude=build,vendor,scripts \
	--headers='{"Report-Msgid-Bugs-To":"https://github.com/giodc/webba-deploy/issues"}'

echo "Wrote ${POT}"
