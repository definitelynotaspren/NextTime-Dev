#!/usr/bin/env bash
# Docker-free NextTime dev environment.
#
# Clones a Nextcloud server checkout next to this repo, installs it on SQLite,
# links this app into server/apps/timebank, enables it, and serves it with
# PHP's built-in web server. Useful when Docker isn't available (or Docker Hub
# is blocked), and it's the layout the PHPUnit bootstrap expects.
#
# Usage:
#   scripts/dev-native.sh setup    # one-time: clone server, install, enable app
#   scripts/dev-native.sh serve    # start http://127.0.0.1:$PORT (foreground)
#   scripts/dev-native.sh start    # start in background, log to $NC_DIR/php-server.log
#   scripts/dev-native.sh stop     # stop the background server
#   scripts/dev-native.sh reset    # wipe the Nextcloud install and set up again
#   scripts/dev-native.sh occ ...  # run occ against the dev server
#
# Env overrides: NC_DIR (default ../nc-server), NC_BRANCH (stable31), PORT (8081)
# Requires: git, php >= 8.1 with sqlite3/pdo_sqlite/gd/intl/zip/mbstring/xml/curl.

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NC_DIR="${NC_DIR:-$(dirname "$APP_DIR")/nc-server}"
NC_BRANCH="${NC_BRANCH:-stable31}"
PORT="${PORT:-8081}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin}"
PIDFILE="$NC_DIR/php-server.pid"

occ() { php "$NC_DIR/occ" "$@"; }

need_build() {
	if [ ! -f "$APP_DIR/js/timebank-main.mjs" ]; then
		echo ">> Frontend not built yet (js/ missing) - running npm ci && npm run build"
		(cd "$APP_DIR" && npm ci && npm run build)
	fi
}

clone_server() {
	if [ ! -d "$NC_DIR/.git" ]; then
		echo ">> Cloning nextcloud/server@$NC_BRANCH into $NC_DIR (shallow)"
		git clone --depth 1 --branch "$NC_BRANCH" https://github.com/nextcloud/server.git "$NC_DIR"
		git -C "$NC_DIR" submodule update --init --depth 1 3rdparty
	fi
}

link_app() {
	# A real directory bind would be nicer, but a symlink works for the web
	# server and occ. (PHPUnit resolves the server via NEXTCLOUD_ROOT instead.)
	if [ ! -e "$NC_DIR/apps/timebank" ]; then
		ln -s "$APP_DIR" "$NC_DIR/apps/timebank"
	fi
}

install_nc() {
	if occ status --output=json 2>/dev/null | grep -q '"installed":true'; then
		echo ">> Nextcloud already installed"
	else
		echo ">> Installing Nextcloud on SQLite (admin: $ADMIN_USER / $ADMIN_PASS)"
		occ maintenance:install --database=sqlite \
			--admin-user="$ADMIN_USER" --admin-pass="$ADMIN_PASS"
	fi
	occ config:system:set trusted_domains 1 --value=127.0.0.1 >/dev/null
	occ config:system:set debug --value=true --type=boolean >/dev/null
	occ config:system:set loglevel --value=0 --type=integer >/dev/null
}

enable_app() {
	echo ">> Enabling timebank"
	occ app:enable timebank
	# Seed categories even on builds where the install repair step is missing.
	occ maintenance:repair --include-expensive >/dev/null 2>&1 || occ maintenance:repair >/dev/null
}

seed_users() {
	# Two non-admin users so the request/volunteer/vote flows can be exercised.
	for u in alice bob carol; do
		if ! occ user:info "$u" >/dev/null 2>&1; then
			OC_PASS="${u}-pass-123" occ user:add --password-from-env --display-name="${u^}" "$u" >/dev/null
			echo ">> Created user $u (password: ${u}-pass-123)"
		fi
	done
}

case "${1:-help}" in
setup)
	need_build
	clone_server
	link_app
	install_nc
	enable_app
	seed_users
	echo
	echo "Ready. Next: scripts/dev-native.sh serve   (then open http://127.0.0.1:$PORT/index.php/apps/timebank/)"
	;;
serve)
	echo ">> Serving $NC_DIR at http://127.0.0.1:$PORT  (Ctrl-C to stop)"
	exec php -S "127.0.0.1:$PORT" -t "$NC_DIR"
	;;
start)
	if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
		echo "Already running (pid $(cat "$PIDFILE")) on http://127.0.0.1:$PORT"
		exit 0
	fi
	nohup php -S "127.0.0.1:$PORT" -t "$NC_DIR" >"$NC_DIR/php-server.log" 2>&1 &
	echo $! >"$PIDFILE"
	sleep 1
	echo "Started (pid $(cat "$PIDFILE")) on http://127.0.0.1:$PORT - log: $NC_DIR/php-server.log"
	;;
stop)
	if [ -f "$PIDFILE" ]; then kill "$(cat "$PIDFILE")" 2>/dev/null || true; rm -f "$PIDFILE"; echo "Stopped"; else echo "Not running"; fi
	;;
reset)
	"$0" stop || true
	rm -rf "$NC_DIR/data" "$NC_DIR/config/config.php"
	"$0" setup
	;;
occ)
	shift
	occ "$@"
	;;
*)
	sed -n '2,20p' "$0"
	;;
esac
