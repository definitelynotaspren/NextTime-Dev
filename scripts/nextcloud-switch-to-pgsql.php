<?php

// Points a Nextcloud config.php that uses SQLite at a PostgreSQL database, in one write.
//
// `occ db:convert-type` does this itself when it finishes, but it can stop just before that step
// (after it has copied every table). Doing it with `occ config:system:set` does not work: with SQLite
// the setting `dbname` is the name of the database FILE, so changing it first points Nextcloud at an
// empty file, and changing `dbtype` first leaves it connecting to the wrong database.
//
// Usage (the password comes from the environment so it is not left in the process list or shell history):
//   NEXTTIME_DB_PASSWORD=... php nextcloud-switch-to-pgsql.php /var/www/html/config/config.php HOST DBNAME USER
//
// The original is kept as config.php.before-pgsql; the script will not overwrite an existing backup.
// To undo, copy that file back over config.php. The SQLite database file is never touched.

if (PHP_SAPI !== 'cli') {
	exit(1);
}

$path = $argv[1] ?? '';
$host = $argv[2] ?? '';
$name = $argv[3] ?? '';
$user = $argv[4] ?? '';
$password = getenv('NEXTTIME_DB_PASSWORD');

if ($path === '' || $host === '' || $name === '' || $user === '' || $password === false || $password === '') {
	fwrite(STDERR, "Usage: NEXTTIME_DB_PASSWORD=... php nextcloud-switch-to-pgsql.php CONFIG_PATH HOST DBNAME USER\n");
	exit(2);
}
if (!is_file($path) || !is_writable($path)) {
	fwrite(STDERR, "$path is not a writable file\n");
	exit(2);
}

$CONFIG = [];
include $path;
if (!is_array($CONFIG) || ($CONFIG['dbtype'] ?? '') !== 'sqlite3') {
	fwrite(STDERR, "$path does not use SQLite (dbtype is '" . ($CONFIG['dbtype'] ?? '') . "'); nothing changed\n");
	exit(1);
}

$backup = $path . '.before-pgsql';
if (file_exists($backup)) {
	fwrite(STDERR, "$backup already exists; move it away first so the original is not lost\n");
	exit(1);
}
if (!copy($path, $backup)) {
	fwrite(STDERR, "could not write $backup\n");
	exit(1);
}
chmod($backup, fileperms($path) & 0777);

$CONFIG['dbtype'] = 'pgsql';
$CONFIG['dbname'] = $name;
$CONFIG['dbhost'] = $host;
$CONFIG['dbuser'] = $user;
$CONFIG['dbpassword'] = $password;

if (file_put_contents($path, "<?php\n\$CONFIG = " . var_export($CONFIG, true) . ";\n", LOCK_EX) === false) {
	fwrite(STDERR, "could not write $path; the original is still at $backup\n");
	exit(1);
}

echo "Switched $path to PostgreSQL database $name on $host. The original is at $backup.\n";
