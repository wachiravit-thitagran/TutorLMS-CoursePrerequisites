#!/usr/bin/env bash
#
# Install Tutor LMS (free) into the WordPress core used by the test suite.
#
# Integration tests run against the real plugin, not a stub. That is a
# deliberate trade: CI will break when Tutor ships a breaking change, which is
# the entire point - the alternative is finding out from a customer.
#
# Usage:
#   bin/install-tutor.sh [version]
#
# version accepts a release number (3.8.1) or "latest".

set -euo pipefail

TUTOR_VERSION=${1-${TUTOR_VERSION-latest}}
TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}
PLUGIN_DIR="$WP_CORE_DIR/wp-content/plugins"

if [ ! -d "$WP_CORE_DIR" ]; then
	echo "WordPress core not found at $WP_CORE_DIR. Run bin/install-wp-tests.sh first." >&2
	exit 1
fi

mkdir -p "$PLUGIN_DIR"

if [ -d "$PLUGIN_DIR/tutor" ]; then
	echo "Tutor LMS already installed at $PLUGIN_DIR/tutor"
	exit 0
fi

if [ "$TUTOR_VERSION" = "latest" ]; then
	URL="https://downloads.wordpress.org/plugin/tutor.zip"
else
	URL="https://downloads.wordpress.org/plugin/tutor.${TUTOR_VERSION}.zip"
fi

echo "Downloading Tutor LMS from $URL"

if command -v curl >/dev/null 2>&1; then
	curl -fsSL -o "$TMPDIR/tutor.zip" "$URL"
else
	wget -nv -O "$TMPDIR/tutor.zip" "$URL"
fi

unzip -q -o "$TMPDIR/tutor.zip" -d "$PLUGIN_DIR"

if [ ! -f "$PLUGIN_DIR/tutor/tutor.php" ]; then
	echo "Tutor LMS did not unpack as expected." >&2
	exit 1
fi

# Record what we actually got, so a failing build says which version broke it.
INSTALLED=$(grep -m1 -i "Version:" "$PLUGIN_DIR/tutor/tutor.php" | sed 's/.*Version:[[:space:]]*//' | tr -d '\r')
echo "Installed Tutor LMS $INSTALLED"

if [ -n "${GITHUB_ENV-}" ]; then
	echo "TUTOR_INSTALLED_VERSION=$INSTALLED" >>"$GITHUB_ENV"
fi

if [ -n "${GITHUB_STEP_SUMMARY-}" ]; then
	echo "- Tutor LMS: \`$INSTALLED\`" >>"$GITHUB_STEP_SUMMARY"
fi
