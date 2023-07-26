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

function rmTree($directory)
{
	$files = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
		\RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($files as $fileinfo) {
		if ($fileinfo->isDir()) {
			rmdir($fileinfo->getRealPath());
		} else {
			unlink($fileinfo->getRealPath());
		}
	}
	rmdir($directory);
}

function fetchFile($method, $url, $path, $options = [])
{
	try {
		$temp = $path . '.temp';
		$options += [
			'verify' => false,
			'sink' => $temp,
			'timeout' => 600,
		];
		$client = new Client();
		$client->request($method, $url, $options);
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

function fillFirmware($payload, $firmwareTemplateFile, array $firmwareLinks)
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
	// old url: http://api.jcxxkeji.com:9000/upload/bott/JCID_config_test.zip
	// new url is obtained dynamically from API
	fetchFile('get', $firmwareTemplateFile, $templateFile);

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
				$fileName = $prefix . '_' . $version . '.bin';
				if (isset($firmwareLinks[$fileName])) {
					$url = $firmwareLinks[$fileName];
				} else {
					$url = 'http://api.jcxxkeji.com:9000/upload/bott/' . $fileName;
				}

				$firmwarePath = $firmwareDir . '/' . sanitize($fileName);

				if (!file_exists($firmwarePath)) {
					try {
						fetchFile('get', $url, $firmwarePath);
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

function normalizePayloadVersionFormat(array $payload)
{
	foreach ($payload as $name => $items) {
		foreach ($items as $index => $item) {
			// we expect to have original 'NAME:2.0' version format
			// but sometimes different format are used like '2.0' or 'v2.0' or 'V2.0'
			// this is workaround to normalize all formats to unified 'NAME:2.0' format
			// in order to keep backward compatibility and to avoid duplicate versions
			if (stripos($item['version'], 'v') === 0) {
				$item['version'] = substr($item['version'], 1);
			}
			if (strpos($item['version'], ':') === false) {
				$item['version'] = $name . ':' . $item['version'];
			}
			$payload[$name][$index] = $item;
		}
	}
	return $payload;
}

// fetch latest changelogs from manufacturer
$urls = [
	'http://api.jcxxkeji.com:9000/upload/bott/JCID_dev_upgrade_note.json',
	'http://api.jcxxkeji.com:9000/upload/bott/JCID_config_note.zip', // yes - this is fake .zip file containing JSON
];

$filePath = $dataDir . '/remote-aixun-file-list.json';
fetchFile('post', 'https://api.jcidtech.com/api/v1/aixun_config_file_controller/query', $filePath, [
	'headers' => [
		'ContentType' => 'application/json',
	],
]);
$contents = file_get_contents($filePath);
$response = decodeCorruptedJson($contents);
$firmwareLinks = [];
$firmwareTemplateFile = null;
if (isset($response['msg']) && $response['msg'] === 'success') {
	foreach ($response['data'] as $item) {
		if ($item['fileName'] === 'JCID_dev_upgrade_note.zip') {
			$urls[] = $item['url'];
		} else if ($item['fileName'] === 'JCID_config_test.zip') {
			$firmwareTemplateFile = $item['url'];
		} else if (endsWith($item['fileName'], '.bin')) {
			$firmwareLinks[$item['fileName']] = $item['url'];
		}
	}
}

$files = [];
$directoriesToRemove = [];
foreach ($urls as $url) {
	$key = sha1($url);

	// only API returns actual .zip files
	$zip = endsWith($url, '.zip') && strpos($url, 'aixun-file.oss-cn-shenzhen.aliyuncs.com') !== false;

	$filePath = $dataDir . '/remote-' . $key . '.' . ($zip ? 'zip' : 'json');
	fetchFile('get', $url, $filePath);

	if ($zip) {
		$zip = new \ZipArchive();
		$zip->open($filePath);
		$tempDir = $filePath . '-extracted';
		if (!file_exists($tempDir)) {
			mkdir($tempDir, 0777, true);
		}
		$zip->extractTo($tempDir);
		$zip->close();

		foreach (scandir($tempDir) as $item) {
			if (endsWith($item, '.json')) {
				$files[] = $tempDir . DIRECTORY_SEPARATOR . $item;
			}
		}

		unlink($filePath);
		$directoriesToRemove[] = $tempDir;
	} else {
		$files[] = $filePath;
	}
}

$payload = [];
foreach ($files as $filePath) {
	$contents = file_get_contents($filePath);
	$decoded = decodeCorruptedJson($contents);
	$payload = array_merge_recursive($payload, $decoded);
}

foreach ($directoriesToRemove as $path) {
	rmTree($path);
}

$payload = normalizePayloadVersionFormat($payload);

// fetch and fill firmware files
$payload = fillFirmware($payload, $firmwareTemplateFile, $firmwareLinks);

// rules for sorting and renaming of specific models
$preferredPatterns = [
	'^T[0-9A-Z]+$',
	'^P[0-9A-Z]+$',
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
		$previousPayload = normalizePayloadVersionFormat($previousPayload);
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
foreach ($preferredPatterns as $pattern) {
	foreach ($payload as $name => $items) {
		if (preg_match('~' . $pattern . '~', $name)) {
			$sorted[$name] = $items;
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

// latest
$flattened = [];
foreach ($payload as $name => $versions) {
	foreach ($versions as $item) {
		if ($name === 'App') {
			continue;
		}
		$date = \DateTime::createFromFormat('Y-m-d', $item['time']);
		$date->setTime(0, 0);
		$flattened[] = [
			'name' => $name,
			'version' => $item['version'],
			'date' => $date,
			'timestamp' => $date->getTimestamp(),
		];
	}
}

usort($flattened, function ($a, $b) {
	$a = $a['timestamp'];
	$b = $b['timestamp'];
	if ($a > $b) {
		return 1;
	} else if ($a < $b) {
		return -1;
	}
	return 0;
});

$latest = [];
$mostRecentDate = null;
for ($number = 1; $number <= 5; $number++) {
	$item = array_pop($flattened);
	if ($item) {
		if (preg_match('~[a-z0-9]+:v?([a-z0-9.]+)~i', $item['version'], $matches)) {
			$item['version'] = $matches[1];
		}
		if ($mostRecentDate === null) {
			$mostRecentDate = $item['date'];
		}
		$date = $item['date']->format('Y-m-d');
		$latest[] = $item['name'] . '-' . $item['version'] . '@' . $date;
	}
}
$latest = implode(', ', $latest);
if ($mostRecentDate !== null) {
	$now = new \DateTime();
	$now->setTime(0, 0);
	$diff = $now->getTimestamp() - $mostRecentDate->getTimestamp();
	$days = (int) round($diff / 86400);
	$latest = $days . ' ' . ($days === 1 ? 'day' : 'days') . ' ago (' . $latest . ')';
}
$latest = [
	'message' => $latest,
];
$latestJson = json_encode($latest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
file_put_contents($wwwDir . '/files/latest.json', $latestJson);

echo 'ok' . "\n";
