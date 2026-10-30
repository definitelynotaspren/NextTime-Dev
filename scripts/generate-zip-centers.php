<?php

declare(strict_types=1);

/*
 * Builds lib/Geo/data/zip-centers.txt from the Census Bureau's ZCTA gazetteer file (public domain):
 * https://www2.census.gov/geo/docs/maps-data/data/gazetteer/2023_Gazetteer/2023_Gaz_zcta_national.zip
 *
 * Usage: php scripts/generate-zip-centers.php path/to/2023_Gaz_zcta_national.txt
 *
 * Output is one fixed-width line per ZIP, sorted, so ZipCenters can binary-search the file:
 * "ZZZZZ LLLLLLLL GGGGGGGGG RRRRR\n" (zip, latitude %8.4f, longitude %9.4f, radius %5.1f), 31 bytes
 * per line. The radius, in km, is that of a circle with the ZIP area's land area; the posting form
 * uses it to tell a pin inside a large rural ZIP from one closer to a small neighbour's center.
 */

$in = $argv[1] ?? '';
if ($in === '' || !is_readable($in)) {
	fwrite(STDERR, "Usage: php scripts/generate-zip-centers.php 2023_Gaz_zcta_national.txt\n");
	exit(1);
}

$rows = [];
$handle = fopen($in, 'r');
fgetcsv($handle, null, "\t"); // header
while (($cols = fgetcsv($handle, null, "\t")) !== false) {
	$cols = array_map('trim', $cols);
	if (count($cols) < 7 || !preg_match('/^\d{5}$/', $cols[0])) {
		continue;
	}
	$radiusKm = min(999.9, sqrt(((float)$cols[1] / 1e6) / M_PI));
	$rows[$cols[0]] = sprintf("%s %8.4f %9.4f %5.1f\n", $cols[0], (float)$cols[5], (float)$cols[6], $radiusKm);
}
fclose($handle);
ksort($rows, SORT_STRING);

$out = dirname(__DIR__) . '/lib/Geo/data/zip-centers.txt';
file_put_contents($out, implode('', $rows));
echo count($rows) . " ZIPs written to $out\n";
