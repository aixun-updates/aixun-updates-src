<?php

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;


/**
 * @author dd <dd@wx.tnyzeq.icu>
 */
class Updater
{

	private $dataDir;
	private $wwwDir;
	private $configCache = null;
	private $fetchUseProxy = false;


	public function __construct($dataDir, $wwwDir)
	{
		$this->dataDir = $dataDir;
		$this->wwwDir = $wwwDir;
	}


	function run()
	{
		try {
			$this->update();
			return 'ok';
		} catch (ConnectException $e) {
			return 'temporary error: ' . $e->getMessage();
		} catch (RequestException $e) {
			$expectedErrors = ['connection reset by peer'];
			$expected = false;
			foreach ($expectedErrors as $error) {
				if (stripos($e->getMessage(), $error) !== false) {
					$expected = true;
					break;
				}
			}
			if ($expected) {
				return 'temporary error: ' . $e->getMessage();
			}
			throw $e;
		}
	}


	public function update()
	{
		// fetch latest changelogs from manufacturer
		$urls = [
			'http://api.jcxxkeji.com:9000/upload/bott/JCID_dev_upgrade_note.json',
			'http://api.jcxxkeji.com:9000/upload/bott/JCID_config_note.zip', // this is fake .zip file containing JSON
		];

		$filePath = $this->dataDir . '/remote-aixun-file-list.json';
		$this->fetchFile('post', 'https://api.jcidtech.com/api/v1/aixun_config_file_controller/query', $filePath, [
			'headers' => [
				'ContentType' => 'application/json',
			],
		]);
		$contents = file_get_contents($filePath);
		$response = $this->decodeCorruptedJson($contents);
		$firmwareLinks = [];
		$firmwareTemplateFile = null;
		if (isset($response['msg']) && $response['msg'] === 'success') {
			foreach ($response['data'] as $item) {
				if ($item['fileName'] === 'JCID_dev_upgrade_note.zip') {
					$urls[] = $item['url'];
				} else if ($item['fileName'] === 'JCID_config_test.zip') {
					$firmwareTemplateFile = $item['url'];
				} else if ($this->endsWith($item['fileName'], '.bin')) {
					$firmwareLinks[$item['fileName']] = $item['url'];
				}
			}
		}

		$files = [];
		$directoriesToRemove = [];
		foreach ($urls as $url) {
			$key = sha1($url);

			// only API returns actual .zip files
			$zip = $this->endsWith($url, '.zip') && strpos($url, 'aixun-file.oss-cn-shenzhen.aliyuncs.com') !== false;

			$filePath = $this->dataDir . '/remote-' . $key . '.' . ($zip ? 'zip' : 'json');
			$this->fetchFile('get', $url, $filePath);

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
					if ($this->endsWith($item, '.json')) {
						$files[] = $tempDir . DIRECTORY_SEPARATOR . $item;
					}
				}

				unlink($filePath);
				$directoriesToRemove[] = $tempDir;
			} else {
				$files[] = $filePath;
			}
		}

		$apiChangelogPath = $this->dataDir . '/remote-aixun-api-changelog.json';
		$this->fetchFile('post', 'https://api.jcidtech.com/api/v1/aixun_config_file_new_controller/query', $apiChangelogPath, [
			'headers' => [
				'ContentType' => 'application/json',
			],
		]);
		$contents = file_get_contents($apiChangelogPath);
		$response = $this->decodeCorruptedJson($contents);
		$converted = [];
		foreach ($response['data'] as $group) {
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

		foreach ($directoriesToRemove as $path) {
			$this->rmTree($path);
		}

		// fetch and fill firmware files
		$payload = $this->fillFirmware($payload, $firmwareTemplateFile, $firmwareLinks);

		// rules for sorting and renaming of specific models
		$rename = [
			'AIXUN' => 'App',
		];
		$preferredPatterns = [
			'^T[0-9A-Z]+$',
			'^P[0-9A-Z]+$',
		];
		$detestedPatterns = [
			'^App$',
			'^AS_.+$',
		];

		// merge previous changelog with current
		$changelogPath = $this->wwwDir . '/files/changelog.json';
		if (file_exists($changelogPath)) {
			$contents = file_get_contents($changelogPath);
			$previousPayload = json_decode($contents, true);
			if ($previousPayload) {
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
		file_put_contents($this->wwwDir . '/files/latest.json', $latestJson);
	}


	public function fillFirmware($payload, $firmwareTemplateFile, array $firmwareLinks)
	{
		$cache = $this->dataDir . '/firmware.json';
		if (file_exists($cache)) {
			$list = json_decode(file_get_contents($cache), true);
		} else {
			$list = [];
		}

		$firmwareDir = $this->wwwDir . '/firmware';
		if (!file_exists($firmwareDir)) {
			mkdir($firmwareDir, 0777, true);
		}

		$templateFile = $this->dataDir . '/template.txt';
		// old url: http://api.jcxxkeji.com:9000/upload/bott/JCID_config_test.zip
		// new url is obtained dynamically from API
		$this->fetchFile('get', $firmwareTemplateFile, $templateFile);

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
				if ($this->endsWith($templateName, $parent)) {
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
					if (isset($item['url'])) {
						$url = $item['url'];
						$fileName = $item['fileName'];
					} else if (isset($firmwareLinks[$fileName])) {
						$url = $firmwareLinks[$fileName];
					} else {
						$url = 'http://api.jcxxkeji.com:9000/upload/bott/' . $fileName;
					}

					$firmwarePath = $firmwareDir . '/' . $this->sanitize($fileName);

					if (!file_exists($firmwarePath)) {
						try {
							$this->fetchFile('get', $url, $firmwarePath);
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


	public function decodeCorruptedJson($contents)
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


	public function normalizePayloadVersionFormat($device, array $versions)
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


	public function fetchFile($method, $url, $path, $options = [], $timeout = 60)
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


	public function sanitize($string)
	{
		$string = preg_replace("~[^a-z0-9_\-.]+~i", '', $string);
		$string = preg_replace("~[.]{2,}~", '', $string);
		return $string;
	}


	public function endsWith($haystack, $needle)
	{
		return substr_compare($haystack, $needle, -strlen($needle)) === 0;
	}


	public function rmTree($directory)
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


	public function getConfig()
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


	public function output($message)
	{
		echo $message . "\n";
	}
}
