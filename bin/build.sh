#!/usr/bin/env bash
#
# Build an installable plugin zip.
#
# The archive contains exactly one top-level directory, named after the slug
# WordPress installs this plugin into (tutor-learning-paths). The evidence for
# that slug is in the repository, not in its name: the main file is
# tutor-learning-paths.php, the text domain is tutor-learning-paths, the
# template override path documented in templates/ is
# your-theme/tutor-learning-paths/..., and Plugin.php loads translations from
# dirname( TLP_BASENAME ) . '/languages'. A single top-level directory with that
# name is what lets the "Replace current with uploaded" prompt overwrite the
# existing folder instead of dropping a second copy next to it.
#
# vendor/ is deliberately never shipped. composer.json requires nothing at
# runtime beyond php >= 8.1 - every package it names is under require-dev
# (phpunit, phpcs, wpcs, polyfills) - and the main file registers its own PSR-4
# loader for src/, treating vendor/autoload.php as an optional convenience for
# developer checkouts. There is no runtime dependency to bundle, and dev tooling
# has no business on a production site.
#
# Usage: bin/build.sh
# Output: dist/tutor-learning-paths.zip
#
set -euo pipefail

PLUGIN_SLUG="tutor-learning-paths"
MAIN_FILE="${PLUGIN_SLUG}.php"
DIST_DIR="dist"
BUILD_DIR="build/${PLUGIN_SLUG}"

# Runnable from anywhere.
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ ! -f "$MAIN_FILE" ]; then
	echo "Cannot find ${MAIN_FILE} — is this the plugin repository?" >&2
	exit 1
fi

# Read the header value whole. A loose "three numbers" grep would quietly turn
# 1.0.0-dev into 1.0.0 and claim the zip is a release build; here the string is
# validated as semver and then carried through unchanged, so an unstamped
# working copy is visible in the output instead of being disguised.
VERSION=$(grep -iE '^[[:space:]]*\*[[:space:]]*Version:' "$MAIN_FILE" | head -1 | sed -E 's/.*[Vv]ersion:[[:space:]]*//' | tr -d '[:space:]')

if ! printf '%s' "$VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$'; then
	echo "Could not read a semver Version header from ${MAIN_FILE} (got '${VERSION}')." >&2
	exit 1
fi

echo "Building ${PLUGIN_SLUG} ${VERSION}"

case "$VERSION" in
	*-*|*+*)
		echo "Note: this is a pre-release header. A release build is stamped first." >&2
		;;
esac

# Only remove what this script owns; build/ may also hold PHPUnit result caches.
rm -rf "$BUILD_DIR" "$DIST_DIR"
mkdir -p "$BUILD_DIR" "$DIST_DIR"

echo "Staging files..."
# -m prunes empty directory chains from the transfer. A working copy carries
# whatever empty directories it happens to have and a fresh CI checkout does
# not, so pruning them keeps the archive identical either way - and a directory
# left behind holding nothing but excluded files never reaches the zip.
rsync -a -m \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='.gitignore' \
	--exclude='.gitattributes' \
	--exclude='.editorconfig' \
	--exclude='/tests' \
	--exclude='/bin' \
	--exclude='/docs' \
	--exclude='/build' \
	--exclude='/dist' \
	--exclude='/vendor' \
	--exclude='/node_modules' \
	--exclude='composer.json' \
	--exclude='composer.lock' \
	--exclude='phpunit*.xml.dist' \
	--exclude='phpunit*.xml' \
	--exclude='phpcs.xml.dist' \
	--exclude='phpcs.xml' \
	--exclude='phpcs-report.xml' \
	--exclude='.phpunit*.cache' \
	--exclude='.phpunit.result.cache' \
	--exclude='wp-tests-config.php' \
	--exclude='*.md' \
	--exclude='*.zip' \
	--exclude='*.log' \
	--exclude='.DS_Store' \
	--exclude='Thumbs.db' \
	./ "$BUILD_DIR/"

# languages/ is empty in git (no .pot is committed yet) but the header
# advertises "Domain Path: /languages" and Plugin.php loads translations from
# there, so the directory ships regardless - ready for the .mo files a site
# operator or translation service drops in.
mkdir -p "$BUILD_DIR/languages"

echo "Creating the archive..."
(
	cd build
	zip -rq "../${DIST_DIR}/${PLUGIN_SLUG}.zip" "$PLUGIN_SLUG" -x '*.DS_Store'
)

ZIP="${DIST_DIR}/${PLUGIN_SLUG}.zip"

echo "Verifying the archive..."

# Listed once: piping into `grep -q` would SIGPIPE unzip and trip pipefail.
entries=$(unzip -Z1 "$ZIP")

# Exactly one top-level directory, and it must be the install slug.
top_level=$(awk -F/ 'NF > 0 { print $1 }' <<<"$entries" | sort -u)
if [ "$top_level" != "$PLUGIN_SLUG" ]; then
	echo "Archive must have exactly one top-level directory named ${PLUGIN_SLUG}, found:" >&2
	printf '%s\n' "$top_level" >&2
	exit 1
fi

# Nothing that belongs to development may leak onto a production site.
if grep -E "^${PLUGIN_SLUG}/(tests|bin|docs|vendor|build|dist|node_modules|\.git|\.github)/|^${PLUGIN_SLUG}/(composer\.(json|lock)|phpunit.*\.xml.*|phpcs.*\.(xml|dist).*|\.git.*|.*\.md)$" <<<"$entries"; then
	echo "The lines above should not be in a production build." >&2
	exit 1
fi

# The things a site does need.
for required in \
	"${PLUGIN_SLUG}/${MAIN_FILE}" \
	"${PLUGIN_SLUG}/uninstall.php" \
	"${PLUGIN_SLUG}/readme.txt" \
	"${PLUGIN_SLUG}/src/Plugin.php" \
	"${PLUGIN_SLUG}/src/Api/functions.php" \
	"${PLUGIN_SLUG}/src/Domain/Rule/RuleRegistry.php" \
	"${PLUGIN_SLUG}/assets/css/frontend.css" \
	"${PLUGIN_SLUG}/assets/js/admin-rules.js" \
	"${PLUGIN_SLUG}/templates/course/locked-notice.php" \
	"${PLUGIN_SLUG}/templates/admin/course-fields.php" \
	"${PLUGIN_SLUG}/languages/"; do
	if ! grep -qxF "$required" <<<"$entries"; then
		echo "Missing from the archive: ${required}" >&2
		exit 1
	fi
done

# The zip must carry the same version the header claims.
zipped_header=$(unzip -p "$ZIP" "${PLUGIN_SLUG}/${MAIN_FILE}")
zipped_version=$(grep -iE '^[[:space:]]*\*[[:space:]]*Version:' <<<"$zipped_header" | head -1 | sed -E 's/.*[Vv]ersion:[[:space:]]*//' | tr -d '[:space:]')
if [ "$zipped_version" != "$VERSION" ]; then
	echo "Archive reports version '${zipped_version}', expected '${VERSION}'." >&2
	exit 1
fi

# The runtime constant has to agree with the header, or a site will cache-bust
# its assets against a version it is not running.
zipped_constant=$(grep -E "'TLP_VERSION'" <<<"$zipped_header" | head -1 | sed -E "s/.*'TLP_VERSION',[[:space:]]*'([^']*)'.*/\1/")
if [ "$zipped_constant" != "$VERSION" ]; then
	echo "Archive TLP_VERSION is '${zipped_constant}', expected '${VERSION}'." >&2
	exit 1
fi

echo
echo "Build complete: ${ZIP} ($(du -h "$ZIP" | cut -f1), $(wc -l <<<"$entries" | tr -d ' ') entries, version ${VERSION})"
