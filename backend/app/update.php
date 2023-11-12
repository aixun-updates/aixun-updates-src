<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/Updater.php';

if (PHP_SAPI !== 'cli') {
	echo 'error: this is CLI script only';
	exit(1);
}

$dataDir = __DIR__ . '/data';
$wwwDir = realpath(__DIR__ . '/../');

$updater = new App\Updater($dataDir, $wwwDir);
echo $updater->run() . "\n";
exit(0);
