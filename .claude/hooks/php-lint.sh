#!/usr/bin/env bash
# PostToolUse hook: syntax-check a PHP file right after Claude edits it.
# Reads the tool call JSON on stdin; exit 2 feeds the error back to Claude.
file="$(jq -r '.tool_input.file_path // empty' 2>/dev/null)"
case "$file" in
*.php) ;;
*) exit 0 ;;
esac
[ -f "$file" ] || exit 0
if ! out="$(php -l "$file" 2>&1)"; then
	echo "$out" >&2
	exit 2
fi
exit 0
