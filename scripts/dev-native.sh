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
#   scripts/dev-native.sh upgrade  # run occ upgrade after an app version bump or new migration
#   scripts/dev-native.sh occ ...  # run occ against the dev server
#
# Env overrides: NC_DIR (default ../nc-server), NC_BRANCH (stable31), PORT (8081),
#   PHP_WORKERS (4: PHP's built-in server is single-threaded by default and stalls
#   browser tests on newer Nextcloud)
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

ensure_shipped_apps() {
	# Apps that ship in a release but live in their own repos are missing from a git
	# checkout, and `occ upgrade` refuses to run without them (viewer on stable31).
	local branch app
	branch="$(git -C "$NC_DIR" rev-parse --abbrev-ref HEAD)"
	for app in viewer notifications; do
		if grep -q "\"$app\"" "$NC_DIR/core/shipped.json" && [ ! -d "$NC_DIR/apps/$app" ]; then
			echo ">> Cloning the $app app (shipped with Nextcloud, separate repo)"
			git clone --depth 1 --branch "$branch" "https://github.com/nextcloud/$app.git" "$NC_DIR/apps/$app" ||
				echo ">> Could not clone $app; occ upgrade may fail"
		fi
	done
	# Newer branches of the notifications app need their PHP dependencies, which a git
	# checkout lacks; without them even `occ maintenance:install` fails.
	if [ -f "$NC_DIR/apps/notifications/composer/autoload.php" ] && [ ! -f "$NC_DIR/apps/notifications/vendor/autoload.php" ]; then
		(cd "$NC_DIR/apps/notifications" && COMPOSER_ALLOW_SUPERUSER=1 timeout 150 composer install --no-dev --no-scripts --no-plugins --no-interaction >/dev/null 2>&1) ||
			echo ">> Could not install the notifications app's dependencies"
	fi
	# NextTime's claim notices need the notifications app; it is enabled on a normal install.
	if [ -d "$NC_DIR/apps/notifications" ]; then
		occ app:enable notifications >/dev/null 2>&1 || true
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
	ensure_shipped_apps
	link_app
	install_nc
	enable_app
	seed_users
	echo
	echo "Ready. Next: scripts/dev-native.sh serve   (then open http://127.0.0.1:$PORT/index.php/apps/timebank/)"
	;;
serve)
	echo ">> Serving $NC_DIR at http://127.0.0.1:$PORT  (Ctrl-C to stop)"
	PHP_CLI_SERVER_WORKERS="${PHP_WORKERS:-4}" exec php -S "127.0.0.1:$PORT" -t "$NC_DIR"
	;;
start)
	if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
		echo "Already running (pid $(cat "$PIDFILE")) on http://127.0.0.1:$PORT"
		exit 0
	fi
	# setsid gives the server its own process group so stop can end the workers too.
	PHP_CLI_SERVER_WORKERS="${PHP_WORKERS:-4}" setsid nohup php -S "127.0.0.1:$PORT" -t "$NC_DIR" >"$NC_DIR/php-server.log" 2>&1 &
	echo $! >"$PIDFILE"
	sleep 1
	echo "Started (pid $(cat "$PIDFILE")) on http://127.0.0.1:$PORT - log: $NC_DIR/php-server.log"
	;;
stop)
	if [ -f "$PIDFILE" ]; then
		pid="$(cat "$PIDFILE")"
		# Worker processes outlive their parent, so end the whole process group.
		kill -- "-$pid" 2>/dev/null || { pkill -P "$pid" 2>/dev/null; kill "$pid" 2>/dev/null; } || true
		rm -f "$PIDFILE"; echo "Stopped"
	else echo "Not running"; fi
	;;
reset)
	"$0" stop || true
	rm -rf "$NC_DIR/data" "$NC_DIR/config/config.php"
	"$0" setup
	;;
upgrade)
	ensure_shipped_apps
	occ upgrade
	occ maintenance:mode --off
	;;
occ)
	shift
	occ "$@"
	;;
*)
	sed -n '2,20p' "$0"
	;;
esac
