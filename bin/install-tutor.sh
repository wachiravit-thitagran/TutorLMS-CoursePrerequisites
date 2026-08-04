#!/usr/bin/env bash
#
# Install Tutor LMS (free) for the test suites.
#
# Integration tests run against the real plugin, not a stub. That is a
# deliberate trade: CI will break when Tutor ships a breaking change, which is
# the entire point - the alternative is finding out from a customer.
#
# Usage:
#   bin/install-tutor.sh [version]
#
# version accepts:
#   latest       the current release from wordpress.org (default)
#   3.8.1        a released version from wordpress.org
#   dev          Tutor's development branch, from GitHub
#   4.0.0-dev    any other GitHub branch
#   v4.0.4       a GitHub tag
#
# Environment:
#   TUTOR_VERSION      same as the positional argument
#   TUTOR_SOURCE       auto (default) | wporg | github
#   TUTOR_PLUGIN_DIR   where to unpack, defaults to the WordPress core's
#                      wp-content/plugins. Set it to install Tutor on its own,
#                      without a WordPress checkout - the hook contract suite
#                      reads Tutor's source and needs nothing else.
#   WP_CORE_DIR        WordPress core, defaults to $TMPDIR/wordpress
#
# Where the download comes from, and why it is not only wordpress.org:
#
#   wordpress.org serves released versions of the free plugin and nothing else.
#   It cannot serve a branch, so the "does Tutor's unreleased work still provide
#   the hooks we attach to" leg of CI has to come from GitHub. GitHub is not the
#   default for numbered versions, because themeum/tutor does not tag every
#   release - 4.0.1 and 4.0.3 are both on wordpress.org with no tag on GitHub -
#   so wordpress.org remains the more complete source for anything released.
#
#   A GitHub checkout is the source tree: it has Tutor's PHP but not necessarily
#   its built front-end assets. Good enough to read hook declarations out of,
#   not necessarily good enough to run a browser against.

set -euo pipefail

TUTOR_VERSION=${1-${TUTOR_VERSION-latest}}
TUTOR_SOURCE=${TUTOR_SOURCE-auto}
TUTOR_REPO=${TUTOR_REPO-https://github.com/themeum/tutor.git}
TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}

# Default destination is the WordPress core the integration suite boots, and
# that has to exist first. An explicit TUTOR_PLUGIN_DIR opts out of needing one.
if [ -n "${TUTOR_PLUGIN_DIR-}" ]; then
	PLUGIN_DIR=$TUTOR_PLUGIN_DIR
else
	PLUGIN_DIR="$WP_CORE_DIR/wp-content/plugins"

	if [ ! -d "$WP_CORE_DIR" ]; then
		echo "WordPress core not found at $WP_CORE_DIR." >&2
		echo "Run bin/install-wp-tests.sh first, or set TUTOR_PLUGIN_DIR to install Tutor on its own." >&2
		exit 1
	fi
fi

TUTOR_DIR="$PLUGIN_DIR/tutor"

# Report what is on disk, on every path out of this script, so a failing build
# always says which Tutor version it was looking at.
report() {
	local installed
	installed=$(grep -m1 -i "Version:" "$TUTOR_DIR/tutor.php" | sed 's/.*Version:[[:space:]]*//' | tr -d '\r')

	echo "Tutor LMS $installed at $TUTOR_DIR"

	if [ -n "${GITHUB_ENV-}" ]; then
		echo "TUTOR_INSTALLED_VERSION=$installed" >>"$GITHUB_ENV"
		echo "TUTOR_DIR=$TUTOR_DIR" >>"$GITHUB_ENV"
	fi

	if [ -n "${GITHUB_STEP_SUMMARY-}" ]; then
		echo "- Tutor LMS: \`$installed\` (requested \`$TUTOR_VERSION\`)" >>"$GITHUB_STEP_SUMMARY"
	fi
}

mkdir -p "$PLUGIN_DIR"

if [ -d "$TUTOR_DIR" ]; then
	if [ ! -f "$TUTOR_DIR/tutor.php" ]; then
		echo "$TUTOR_DIR exists but holds no tutor.php. Remove it and try again." >&2
		exit 1
	fi

	echo "Tutor LMS already installed at $TUTOR_DIR"
	report
	exit 0
fi

# "latest" or a plain dotted version number is a wordpress.org release. Anything
# else - "dev", "4.0.0-dev", "v4.0.4" - is a git ref.
is_release() {
	if [ "$1" = "latest" ]; then
		return 0
	fi

	[[ "$1" =~ ^[0-9]+(\.[0-9]+)+$ ]]
}

download_from_wporg() {
	local url

	if [ "$TUTOR_VERSION" = "latest" ]; then
		url="https://downloads.wordpress.org/plugin/tutor.zip"
	else
		url="https://downloads.wordpress.org/plugin/tutor.${TUTOR_VERSION}.zip"
	fi

	echo "Downloading Tutor LMS from $url"

	if command -v curl >/dev/null 2>&1; then
		curl -fsSL -o "$TMPDIR/tutor.zip" "$url" || return 1
	else
		wget -nv -O "$TMPDIR/tutor.zip" "$url" || return 1
	fi

	unzip -q -o "$TMPDIR/tutor.zip" -d "$PLUGIN_DIR"
}

clone_from_github() {
	local ref=$1

	echo "Cloning Tutor LMS $ref from $TUTOR_REPO"

	rm -rf "$TUTOR_DIR"

	git clone --depth 1 --single-branch --quiet --branch "$ref" "$TUTOR_REPO" "$TUTOR_DIR" || return 1

	# The history is 200MB of front-end churn nobody here reads.
	rm -rf "$TUTOR_DIR/.git"
}

case "$TUTOR_SOURCE" in
	wporg)
		download_from_wporg
		;;

	github)
		clone_from_github "$TUTOR_VERSION"
		;;

	auto)
		if is_release "$TUTOR_VERSION"; then
			if ! download_from_wporg; then
				echo "wordpress.org did not serve $TUTOR_VERSION; trying GitHub." >&2
				# themeum tags releases as v3.8.1.
				clone_from_github "v${TUTOR_VERSION#v}"
			fi
		else
			clone_from_github "$TUTOR_VERSION"
		fi
		;;

	*)
		echo "Unknown TUTOR_SOURCE '$TUTOR_SOURCE'. Expected auto, wporg or github." >&2
		exit 1
		;;
esac

if [ ! -f "$TUTOR_DIR/tutor.php" ]; then
	echo "Tutor LMS did not unpack as expected: no $TUTOR_DIR/tutor.php" >&2
	exit 1
fi

report
