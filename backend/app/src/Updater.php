<?php

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use ZipArchive;


/**
 * @author dd <dd@wx.tnyzeq.icu>
 */
class Updater
{

	private $dataDir;
	private $wwwDir;
	private $changelogPath;
	private $configCache = null;
	private $fetchUseProxy = false;


	public function __construct($dataDir, $wwwDir)
	{
		$this->dataDir = $dataDir;
		$this->wwwDir = $wwwDir;
		$this->changelogPath = $this->wwwDir . '/files/changelog.json';
	}


	public function run($newModelSeed = null)
	{
		$result = 'ok';
		try {
			$this->fetchChanges($newModelSeed);
		} catch (\Exception $e) {
			$temporary = false;
			if ($e instanceof ConnectException) {
				$temporary = true;
			} else if ($e instanceof RequestException) {
				$expectedErrors = ['connection reset by peer', 'User was rejected'];
				foreach ($expectedErrors as $error) {
					if (stripos($e->getMessage(), $error) !== false) {
						$temporary = true;
						break;
					}
				}
			} else if ($e instanceof \RuntimeException) {
				if (stripos($e->getMessage(), 'Server Error') !== false) {
					$temporary = true;
				}
			}

			if ($temporary) {
				$result = 'temporary error: ' . get_class($e) . ': ' . $e->getMessage();
			} else {
				$result = $e;
			}
		}

		$this->updateLatest();

		if ($result instanceof \Throwable) {
			throw $result;
		}
		return $result;
	}


	private function fetchChanges($newModelSeed = null)
	{
		$files = [];

		// fetch latest changelogs from manufacturer
		$previousPayload = $this->getCurrentChangelog();
		$fileNames = array_keys($previousPayload);

		$this->output('fetching new products...');
		$groups = $this->queryApi(['AIXUN_Product_New'], $this->dataDir . '/remote-aixun-api-product-new.json');
		foreach ($groups as $group) {
			$url = $group['url'];
			if (!preg_match('~\.zip$~i', $url)) {
				throw new \Exception('unsupported format: ' . $url);
			}

			$zipPath = $this->dataDir . '/remote-aixun-api-product-new.zip';
			$this->fetchFile('get', $url, $zipPath);
			$zip = new ZipArchive();
			if (!$zip->open($zipPath)) {
				throw new \Exception('unable to open zip: ' . $zipPath);
			}
			$totalCount = 0;
			$unseenCount = 0;
			for ($index = 0; $index < $zip->numFiles; $index++) {
				$relativePath = $zip->getNameIndex($index);
				if (substr($relativePath, -1) == '/') {
					continue;
				}
				$totalCount++;
				$name = pathinfo($relativePath, PATHINFO_FILENAME);
				if (!in_array($name, $fileNames)) {
					$fileNames[] = $name;
					$unseenCount++;
				}
			}
			$zip->close();
			$this->output('found ' . $totalCount . ' total new and ' . $unseenCount . ' unseen');
		}

		if ($newModelSeed !== null) {
			array_unshift($fileNames, $newModelSeed);
		}

		foreach (scandir($this->dataDir) as $fileName) {
			if (stripos($fileName, 'remote-aixun-api-changelog-page-') === 0) {
				unlink($this->dataDir . '/' . $fileName);
			}
		}

		$fileNamesLeft = $fileNames;
		$groups = [];
		$chunkSize = 25;
		$page = 0;
		while (count($fileNamesLeft) > 0) {
			$page++;
			$myFileNames = [];
			for ($index = 0; $index < $chunkSize; $index++) {
				$myFileNames[] = array_shift($fileNamesLeft);
			}
			$this->output(
				'fetching changelog page ' . $page .
				' (requested ' . count($myFileNames) . ', left ' . count($fileNamesLeft) . ' results)...'
			);
			$path = $this->dataDir . '/remote-aixun-api-changelog-page-' . $page . '.json';
			$myGroups = $this->queryApi($myFileNames, $path);
			$groups = array_merge($groups, $myGroups);
			$this->output('found ' . count($myGroups) . ' results');
		}

		$this->output('processing changelog data...');
		$converted = [];
		foreach ($groups as $group) {
			$device = $group['fileName'];
			if (!isset($converted[$device])) {
				$converted[$device] = [];
			}
			foreach ($group['list'] as $item) {
				$converted[$device][] = [
					'time' => $item['updateTime'],
					'version' => $item['version'],
					'ch_note' => $item['descCn'],
					'en_note' => $item['descEn'],
					'url' => $item['url'],
					'fileName' => $item['fileName'],
				];
			}
		}
		if (count($converted)) {
			$apiChangelogPath = $this->dataDir . '/remote-aixun-api-changelog-converted.json';
			file_put_contents($apiChangelogPath, json_encode($converted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
			$files[] = $apiChangelogPath;
		}

		$payload = [];
		foreach ($files as $filePath) {
			$contents = file_get_contents($filePath);
			$decoded = $this->decodeCorruptedJson($contents);
			foreach ($decoded as $device => $versions) {
				$versions = $this->normalizePayloadVersionFormat($device, $versions);
				if (!isset($payload[$device])) {
					$payload[$device] = $versions;
				} else {
					$mapping = [];
					foreach ($payload[$device] as $index => $item) {
						$mapping[$item['version']] = $index;
					}
					foreach ($versions as $item) {
						if (isset($mapping[$item['version']])) {
							$index = $mapping[$item['version']];
							$payload[$device][$index] = $item;
						} else {
							$payload[$device][] = $item;
						}
					}
				}
			}
		}

		// rules for sorting and renaming of specific models
		$rename = [
			'AIXUN' => 'App',
		];
		$preferredPatterns = [
			'^T[0-9][A-Z]+$',
			'^T[0-9A-Z]+$',
			'^P[0-9A-Z]+$',
		];
		$detestedPatterns = [
			'^App$',
			'^AS_.+$',
		];

		// merge previous changelog with current
		foreach ($previousPayload as $name => $items) {
			$items = $this->normalizePayloadVersionFormat($name, $items);

			$index = array_search($name, $rename);
			if ($index !== false) {
				$name = $index;
			} else if (stripos($name, 'aixun_') === 0) {
				$name = substr($name, 6);
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

		// sorting and filtering of duplicates
		$excludedDevices = [
			'AIXUN_Dev_Pic',
			'AIXUN_Upgrade_Data',
			'D11_offical_standard',
			'testFileName1',
			'AAA',
			'Hulu_J2',
			'Hulu_J3',
			'Hulu_U1',
			'iPhoneX_J5800',
			'Power',
		];
		$formatted = [];
		$unique = [];
		foreach ($payload as $name => $items) {
			if (in_array($name, $excludedDevices)) {
				continue;
			}

			foreach ($items as $index => $item) {
				$date = \DateTime::createFromFormat('Y-m-d', $item['time']);
				if ($date) {
					$date->setTime(0, 0);
				} else {
					$date = \DateTime::createFromFormat('Y-m-d H:i:s', $item['time']);
				}
				if (!$date) {
					throw new \Exception('unable to parse date: ' . $item['time']);
				}
				$item['time'] = $date->format('Y-m-d');
				$item['sortKey'] = $date->getTimestamp();
				$item['fallbackSortKey'] = $item['version'];

				$key = $item['time'] . '-' . $item['version'];
				if (in_array($key, $unique)) {
					unset($items[$index]);
					continue;
				}
				$unique[] = $key;

				$items[$index] = $item;
			}

			usort($items, function ($itemA, $itemB) {
				$a = $itemA['sortKey'];
				$b = $itemB['sortKey'];
				if ($a > $b) {
					return -1;
				} else if ($a < $b) {
					return 1;
				}
				return strnatcasecmp($itemA['fallbackSortKey'], $itemB['fallbackSortKey']) * -1;
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
			$key = $name;
			if (isset($rename[$key])) {
				$key = $rename[$key];
			} else if (stripos($key, 'aixun_') === 0) {
				$key = substr($key, 6);
			}
			$sorted[$key] = $payload[$name];
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
			$sorted[$name] = $items;
			unset($payload[$name]);
		}
		foreach ($detestedPatterns as $pattern) {
			foreach ($sorted as $name => $items) {
				if (preg_match('~' . $pattern . '~', $name)) {
					unset($sorted[$name]);
					$sorted[$name] = $items;
				}
			}
		}

		$payload = $sorted;

		// download missing firmware
		$this->output('downloading firmware files...');
		$this->downloadFirmware($payload);

		// save result
		$payloadJson = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		file_put_contents($this->changelogPath, $payloadJson);
	}


	private function updateLatest()
	{
		$payload = $this->getCurrentChangelog();

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
		file_put_contents($this->wwwDir . '/files/latest.json', $latestJson);
	}


	private function queryApi($fileNames, $path, $expectedList = true, $retries = 3)
	{
		$url = 'https://api.jcidtech.com/api/v1/aixun_config_file_new_controller/query';
		$options = [
			'headers' => [
				'ContentType' => 'application/json;charset=UTF-8',
				'token' => '',
				'languageType' => 'en',
			],
			'json' => [
				'fileName' => $fileNames,
			],
		];

		$retry = 0;
		while (true) {
			try {
				$this->fetchFile('post', $url, $path, $options);
				$contents = file_get_contents($path);
				$response = $this->decodeCorruptedJson($contents);
				if ($expectedList && !is_array($response['data'])) {
					throw new \RuntimeException(
						'invalid response: ' . $contents . ', decoded: ' . json_encode($response)
					);
				}
				return $response['data'];
			} catch (\Exception $e) {
				$retry++;
				if ($retry >= $retries) {
					throw $e;
				}
				$this->output(
					'warning: failed to query api: ' . get_class($e) . ': ' . $e->getMessage() .
					', retrying (' . $retry . '/' . $retries . ')...'
				);
				sleep(10);
			}
		}
	}


	private function downloadFirmware(array $payload)
	{
		$firmwareDirectory = $this->wwwDir . '/firmware';
		foreach ($payload as $versions) {
			foreach ($versions as $version) {
				if (!isset($version['fileName'])) {
					continue;
				}
				$firmwarePath = $firmwareDirectory . '/' . $version['fileName'];
				if (!file_exists($firmwarePath)) {
					$this->fetchFile('get', $version['url'], $firmwarePath);
					$this->output('found new firmware file: ' . $version['fileName']);
				}
			}
		}
	}


	private function decodeCorruptedJson($contents)
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


	private function normalizePayloadVersionFormat($device, array $versions)
	{
		foreach ($versions as $index => $item) {
			// we expect to have original 'NAME:2.0' version format
			// but sometimes different format are used like '2.0' or 'v2.0' or 'V2.0'
			// this is workaround to normalize all formats to unified 'NAME:2.0' format
			// in order to keep backward compatibility and to avoid duplicate versions
			if (stripos($item['version'], 'aixun_') === 0) {
				$item['version'] = substr($item['version'], 6);
			}
			if (stripos($item['version'], 'v') === 0) {
				$item['version'] = substr($item['version'], 1);
			}
			if (strpos($item['version'], ':') === false) {
				$item['version'] = $device . ':' . $item['version'];
			}
			$versions[$index] = $item;
		}
		return $versions;
	}


	private function fetchFile($method, $url, $path, $options = [], $timeout = 60)
	{
		try {
			$temp = $path . '.temp';
			$myOptions = $options;
			$myOptions += [
				'verify' => false,
				'sink' => $temp,
				'timeout' => $timeout,
			];

			if ($this->fetchUseProxy) {
				$config = $this->getConfig();
				$myOptions['proxy'] = $config['proxy'];
			}

			if (!isset($myOptions['headers'])) {
				$myOptions['headers'] = [];
			}
			$myOptions['headers']['User-Agent'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_1) ' .
				'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';

			$client = new Client();
			$client->request($method, $url, $myOptions);
			if (file_exists($path)) {
				unlink($path);
			}
			rename($temp, $path);
		} catch (\Exception $e) {
			if (file_exists($temp)) {
				unlink($temp);
			}
			if (!$this->fetchUseProxy) {
				$config = $this->getConfig();
				if (isset($config['proxy']) && $config['proxy']) {
					$this->output('warning: url \'' . $url . '\' failed, retrying with proxy...');
					$this->fetchUseProxy = true;
					$this->fetchFile($method, $url, $path, $options, $timeout);
					return;
				}
			}
			throw $e;
		}
	}


	private function getCurrentChangelog()
	{
		if (!file_exists($this->changelogPath)) {
			return [];
		}
		$contents = file_get_contents($this->changelogPath);
		$payload = json_decode($contents, true);
		if (!$payload || !is_array($payload)) {
			$payload = [];
		}
		return $payload;
	}


	private function getConfig()
	{
		if ($this->configCache === null) {
			$configPath = __DIR__ . '/../config.php';
			if (file_exists($configPath)) {
				$this->configCache = require $configPath;
			} else {
				$this->configCache = [];
			}
		}
		return $this->configCache;
	}


	private function output($message)
	{
		$timestamp = date('Y-m-d H:i:s');
		echo $timestamp . ' - ' . $message . "\n";
	}
}
