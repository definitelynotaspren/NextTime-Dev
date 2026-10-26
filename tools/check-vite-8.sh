#!/bin/sh
# check-vite-8.sh: does @nextcloud/vite-config admit vite 8 yet?
#
# PR #60 (vite 7 -> 8) cannot `npm ci` until the peer range that @nextcloud/vite-config
# declares for vite admits a major of 8. This asks the registry for that range and reads it.
#
# Usage:  tools/check-vite-8.sh [VERSION-OR-TAG]      default: latest
# Exit:   0  the range admits vite 8   -> PR #60 can move (see docs/UPGRADE-vite-8.md)
#         1  the range does not        -> keep holding
#         2  npm could not answer      -> offline, registry down, or npm missing
#
# The range is read as npm prints it, split on "||"; one alternative admits 8 when it is
# "*" or "x", or begins with "^8", "~8", "8", ">=N" with N <= 8, or ">N" with N < 8.
# Anything else (for example "^7.3.6") does not. Ranges this does not understand count as
# "does not admit" and are printed so a person can read them.
#
# SPDX-License-Identifier: GPL-3.0-or-later OR LGPL-3.0-or-later
# Copyright (C) 2026 Corwin Brust <corwin@bru.st>, Bryant Frankford and Contributors.

set -u

spec="@nextcloud/vite-config@${1:-latest}"

command -v npm >/dev/null 2>&1 || { echo "check-vite-8: npm is not installed" >&2; exit 2; }

range=$(npm view "$spec" peerDependencies.vite 2>/dev/null) || {
	echo "check-vite-8: npm could not read $spec from the registry (offline? no such version?)" >&2
	exit 2
}
[ -n "$range" ] || { echo "check-vite-8: $spec declares no vite peer dependency" >&2; exit 2; }

echo "$spec peerDependencies.vite = $range"

admits=1
old_ifs=$IFS
IFS='|'
for alt in $range; do
	IFS=$old_ifs
	alt=$(printf '%s' "$alt" | tr -d ' ')
	[ -n "$alt" ] || continue
	case "$alt" in
		'*' | x | X) admits=0 ;;
		'^8'* | '~8'* | 8 | '8.'*) admits=0 ;;
		'>='*)
			major=$(printf '%s' "${alt#>=}" | sed 's/[^0-9].*//')
			[ -n "$major" ] && [ "$major" -le 8 ] && admits=0
			;;
		'>'*)
			major=$(printf '%s' "${alt#>}" | sed 's/[^0-9].*//')
			[ -n "$major" ] && [ "$major" -lt 8 ] && admits=0
			;;
	esac
	IFS='|'
done
IFS=$old_ifs

if [ "$admits" -eq 0 ]; then
	echo "vite 8 is admitted: PR #60 can move (docs/UPGRADE-vite-8.md, the recipe)"
else
	echo "vite 8 is not admitted: keep holding PR #60"
fi
exit "$admits"
