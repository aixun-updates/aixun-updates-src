<?php

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;


if (PHP_SAPI !== 'cli') {
	echo 'error: this is CLI script only';
	exit(1);
}

require __DIR__ . '/vendor/autoload.php';

$wwwDir = realpath(__DIR__ . '/../');
$dataDir = __DIR__ . '/data';

function endsWith($haystack, $needle)
{
	return substr_compare($haystack, $needle, -strlen($needle)) === 0;
}

function fetchFile($url, $path)
{
	try {
		$temp = $path . '.temp';
		$client = new Client();
		$client->request('get', $url, [
			'sink' => $temp,
			'timeout' => 600,
		]);
		if (file_exists($path)) {
			unlink($path);
		}
		rename($temp, $path);
	} catch (\Exception $e) {
		if (file_exists($temp)) {
			unlink($temp);
		}
		throw $e;
	}
}

function sanitize($string)
{
	$string = preg_replace("~[^a-z0-9_\-.]+~i", '', $string);
	$string = preg_replace("~[.]{2,}~", '', $string);
	return $string;
}

function fillFirmware($payload)
{
	global $wwwDir, $dataDir;

	$cache = $dataDir . '/firmware.json';
	if (file_exists($cache)) {
		$list = json_decode(file_get_contents($cache), true);
	} else {
		$list = [];
	}

	$firmwareDir = $wwwDir . '/firmware';
	if (!file_exists($firmwareDir)) {
		mkdir($firmwareDir, 0777, true);
	}

	$templateFile = $dataDir . '/template.txt';
	fetchFile('http://api.jcxxkeji.com:9000/upload/bott/JCID_config_test.zip', $templateFile);

	$section = null;
	$sections = [];
	$template = file_get_contents($templateFile);
	foreach (explode("\n", $template) as $line) {
		$line = trim($line);
		if (preg_match('~^\[([^]]+)\]$~i', $line, $matches)) {
			$section = $matches[1];
		} else if ($section) {
			if (!isset($sections[$section])) {
				$sections[$section] = [];
			}
			$parts = explode('=', $line, 2);
			$parts = array_map('trim', $parts);
			if (count($parts) === 2) {
				[$name, $value] = $parts;
				$sections[$section][$name] = $value;
			}
		}
	}
	$template = $sections;

	foreach ($payload as $parent => $items) {
		$prefix = 'JC_M_' . $parent;
		foreach ($template as $templateName => $properties) {
			if (endsWith($templateName, $parent)) {
				$prefix = substr($properties['Bin_Name'], 0, -4);
				break;
			}
		}

		foreach ($items as $index => $item) {
			$parts = explode(':', $item['version'], 2);
			[$name, $version] = $parts;
			if (isset($list[$name][$version])) {
				$fileName = $list[$name][$version];
			} else {
				$url = 'http://api.jcxxkeji.com:9000/upload/bott/' . $prefix . '_' . $version . '.bin';

				$fileName = basename($url);
				$firmwarePath = $firmwareDir . '/' . sanitize($fileName);

				if (!file_exists($firmwarePath)) {
					try {
						fetchFile($url, $firmwarePath);
					} catch (ClientException $e) {
						if ($e->getCode() === 404) {
							$fileName = false;
							if (file_exists($firmwarePath)) {
								unlink($firmwarePath);
							}
						} else {
							throw $e;
						}
					}
				}

				if (!isset($list[$name])) {
					$list[$name] = [];
				}
				$list[$name][$version] = $fileName;
			}

			$payload[$parent][$index]['fileName'] = $fileName;
		}
	}

	file_put_contents($cache, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

	return $payload;
}

function decodeCorruptedJson($contents)
{
	$contents = (string) $contents;
	if (preg_match_all('~\s+"(?:en|ch)_note":\s*"(.*)"$~im', $contents, $matches)) {
		foreach ($matches[1] as $content) {
			$escaped = preg_replace('~([^\\\])"~', '\\1\\"', $content);
			$contents = str_replace($content, $escaped, $contents);
		}
	}

	$payload = json_decode($contents, true);
	if (!$payload) {
		throw new \Exception('corrupted json: ' . $contents);
	}
	return $payload;
}

// fetch latest changelogs from manufacturer
$urls = [
	'http://api.jcxxkeji.com:9000/upload/bott/JCID_dev_upgrade_note.json',
	'http://api.jcxxkeji.com:9000/upload/bott/JCID_config_note.zip', // yes - this is fake .zip file containing JSON
];

$payload = [];
foreach ($urls as $url) {
	$key = sha1($url);
	$filePath = $dataDir . '/remote-' . $key . '.json';
	fetchFile($url, $filePath);

	$contents = file_get_contents($filePath);
	$decoded = decodeCorruptedJson($contents);
	$payload = array_merge_recursive($payload, $decoded);
}

// fetch and fill firmware files
$payload = fillFirmware($payload);

// rules for sorting and renaming of specific models
$preferredPatterns = [
	'^T[0-9A-Z]+$',
];
$last = ['AIXUN'];
$rename = [
	'AIXUN' => 'App',
];

// merge previous changelog with current
$changelogPath = $wwwDir . '/files/changelog.json';
if (file_exists($changelogPath)) {
	$contents = file_get_contents($changelogPath);
	$previousPayload = json_decode($contents, true);
	if ($previousPayload) {
		foreach ($previousPayload as $name => $items) {
			$index = array_search($name, $rename);
			if ($index !== false) {
				$name = $index;
			}

			if (isset($payload[$name])) {
				$currentVersions = [];
				foreach ($payload[$name] as $item) {
					$currentVersions[] = $item['version'];
				}
				foreach ($items as $item) {
					if (!in_array($item['version'], $currentVersions)) {
						$payload[$name][] = $item;
					}
				}
			} else {
				$payload[$name] = $items;
			}
		}
	}
}

// sorting and filtering of duplicates
$formatted = [];
$unique = [];
foreach ($payload as $name => $items) {
	foreach ($items as $index => $item) {
		$date = \DateTime::createFromFormat('Y-m-d', $item['time']);
		if (!$date) {
			throw new \Exception('unable to parse date: ' . $item['time']);
		}
		$item['time'] = $date->format('Y-m-d');
		$item['sortKey'] = $date->getTimestamp();

		$key = $item['time'] . '-' . $item['version'];
		if (in_array($key, $unique)) {
			unset($items[$index]);
			continue;
		}
		$unique[] = $key;

		$items[$index] = $item;
	}

	usort($items, function ($a, $b) {
		$a = $a['sortKey'];
		$b = $b['sortKey'];
		if ($a > $b) {
			return -1;
		} else if ($a < $b) {
			return 1;
		}
		return 0;
	});

	foreach ($items as $index => $item) {
		unset($item['sortKey']);
		$items[$index] = $item;
	}

	$formatted[$name] = $items;
}
$payload = $formatted;

$names = array_keys($payload);
natcasesort($names);

$sorted = [];
foreach ($names as $name) {
	$sorted[$name] = $payload[$name];
}

$payload = $sorted;
$sorted = [];
foreach ($payload as $name => $items) {
	foreach ($preferredPatterns as $pattern) {
		if (preg_match('~' . $pattern . '~', $name)) {
			$sorted[$name] = $payload[$name];
			unset($payload[$name]);
		}
	}
}
foreach ($payload as $name => $items) {
	if (!in_array($name, $last)) {
		$sorted[$name] = $items;
		unset($payload[$name]);
	}
}
foreach ($payload as $name => $items) {
	if (isset($rename[$name])) {
		$name = $rename[$name];
	}
	$sorted[$name] = $items;
}

$payload = $sorted;

// save result
$payloadJson = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
file_put_contents($changelogPath, $payloadJson);

echo 'ok' . "\n";
