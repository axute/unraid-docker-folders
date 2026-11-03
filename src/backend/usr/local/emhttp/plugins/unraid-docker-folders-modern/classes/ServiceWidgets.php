<?php
/**
 * Unraid Docker Folders - service widgets
 *
 * A one-line summary of what a service holds, on its container's card:
 * "Movies 812 · Series 94" for Jellyfin. Configured with the labels
 * gethomepage reads (homepage.widget.type, .key, .url, ...), so a container
 * that already has them needs nothing else. The type names, label options,
 * endpoints and field names follow gethomepage's src/widgets/<type>/.
 *
 * The server calls the service, never the browser: the key stays on the
 * server, and the request names only container ids. The labels are read from
 * Docker here, so a client cannot point the request at a URL of its own.
 *
 * Where the request goes, when no homepage.widget.url is set: the port is the
 * container port in the WebUI template (`[PORT:8096]`), and the host is the
 * container's own address on its network. See resolveTargets().
 *
 * @package UnraidDockerModern
 */

require_once dirname(__DIR__) . '/include/config.php';
require_once __DIR__ . '/DockerClient.php';

class ServiceWidgets
{
  const LABEL_PREFIX = 'homepage.widget.';
  /** In RAM. Holds results and the address that answered, never a key. */
  const CACHE_PATH = '/var/run/' . PLUGIN_NAME . '.service-widgets.json';
  const CACHE_SECONDS = 30;
  /** A failure is retried sooner than a success is refreshed. */
  const ERROR_CACHE_SECONDS = 15;
  /** gethomepage shows at most four fields (withWidgetFields' maxFields). */
  const MAX_FIELDS = 4;
  const CONNECT_TIMEOUT = 2;
  const TIMEOUT = 4;
  const MAX_RESPONSE_BYTES = 1048576;
  /** Networks the host often cannot reach a container on (Unraid's br0). */
  const CUSTOM_NETWORK_DRIVERS = ['macvlan', 'ipvlan'];

  /**
   * Per type: the fields gethomepage allows, in its order, with their labels
   * and value formats, and which of them it shows when `fields` is not set.
   */
  const TYPES = [
    'jellyfin' => [
      'fields' => [
        'movies' => ['Movies', 'number'],
        'series' => ['Series', 'number'],
        'episodes' => ['Episodes', 'number'],
        'songs' => ['Songs', 'number'],
        'albums' => ['Albums', 'number'],
      ],
      'default' => ['movies', 'series', 'episodes', 'songs'],
    ],
    'immich' => [
      'fields' => [
        'users' => ['Users', 'number'],
        'photos' => ['Photos', 'number'],
        'videos' => ['Videos', 'number'],
        'storage' => ['Storage', 'bytes'],
      ],
      'default' => ['users', 'photos', 'videos', 'storage'],
    ],
    'paperlessngx' => [
      'fields' => [
        'total' => ['Total', 'number'],
        'inbox' => ['Inbox', 'number'],
      ],
      'default' => ['inbox', 'total'],
    ],
    'gotify' => [
      'fields' => [
        'apps' => ['Apps', 'number'],
        'clients' => ['Clients', 'number'],
        'messages' => ['Messages', 'number'],
      ],
      'default' => ['apps', 'clients', 'messages'],
    ],
    'speedtest' => [
      'fields' => [
        'download' => ['Download', 'bitrate'],
        'upload' => ['Upload', 'bitrate'],
        'ping' => ['Ping', 'ms'],
      ],
      'default' => ['download', 'upload', 'ping'],
    ],
  ];

  /** @var callable(array): array */
  private $transport;
  /** @var string|null */
  private $cachePath;

  /**
   * @param callable|null $transport Takes key => request and returns
   *   key => response, see curlTransport(). Tests pass a fake.
   * @param string|null $cachePath null turns the cache off.
   */
  public function __construct($transport = null, $cachePath = self::CACHE_PATH)
  {
    $this->transport = $transport ?: [self::class, 'curlTransport'];
    $this->cachePath = $cachePath;
  }

  // ─── Labels ───────────────────────────────────────────────────────────

  /**
   * The widget a container's labels describe, or null when they name none.
   *
   * gethomepage strips `homepage.` and reads the rest as a path, so
   * `homepage.widget.key` is `widget.key`. Labels for a named homepage
   * instance (`homepage.instance.<name>.*`) are for that instance only, and
   * the indexed form (`homepage.widgets[0].*`) is not read yet.
   *
   * @return array|null {type, url, key, username, password, version, fields}
   */
  public static function parseLabels($labels)
  {
    if (!is_array($labels)) return null;
    $read = function ($name) use ($labels) {
      $value = $labels[self::LABEL_PREFIX . $name] ?? null;
      if (!is_string($value)) return null;
      $value = trim($value);
      return $value === '' ? null : $value;
    };

    $type = $read('type');
    if ($type === null) return null;

    // gethomepage parses `fields` as JSON and drops a list it cannot read.
    $fields = null;
    $rawFields = $read('fields');
    if ($rawFields !== null) {
      $decoded = json_decode($rawFields, true);
      if (is_array($decoded)) {
        $fields = array_values(array_filter($decoded, 'is_string'));
      }
    }

    $version = $read('version');
    return [
      'type' => strtolower($type),
      'url' => $read('url'),
      'key' => $read('key'),
      'username' => $read('username'),
      'password' => $read('password'),
      // gethomepage's default is 1, so a label set for it reads the same here.
      'version' => $version !== null && ctype_digit($version) ? (int) $version : 1,
      'fields' => $fields,
    ];
  }

  /**
   * The fields to show: the configured ones this type knows, in the order
   * given, or the type's default. At most MAX_FIELDS.
   *
   * @return string[]
   */
  public static function selectFields($type, $fields)
  {
    $known = self::TYPES[$type]['fields'] ?? [];
    $picked = [];
    foreach ((array) $fields as $field) {
      $field = strtolower((string) $field);
      if (isset($known[$field]) && !in_array($field, $picked, true)) $picked[] = $field;
    }
    if (!$picked) $picked = self::TYPES[$type]['default'] ?? [];
    return array_slice($picked, 0, self::MAX_FIELDS);
  }

  /**
   * Why a widget cannot be asked at all, or null when it can.
   *
   * gethomepage fills `{{HOMEPAGE_VAR_X}}` from the environment of its own
   * container, which this plugin does not have. A value still holding one
   * must not reach the service as a key.
   *
   * @return array|null {error, message}
   */
  public static function configProblem(array $config)
  {
    $type = $config['type'];
    if (!isset(self::TYPES[$type])) {
      return self::failure('unsupported', "Widget type \"{$type}\" is not supported yet");
    }
    foreach (['url', 'key', 'username', 'password'] as $name) {
      if (is_string($config[$name]) && strpos($config[$name], '{{') !== false) {
        return self::failure(
          'placeholder',
          "homepage.widget.{$name} holds a gethomepage placeholder. Put the value in the label itself",
        );
      }
    }
    $hasKey = $config['key'] !== null;
    $hasLogin = $config['username'] !== null && $config['password'] !== null;
    $needsKey = $type === 'paperlessngx' ? !$hasKey && !$hasLogin
      : ($type === 'speedtest' ? $config['version'] >= 2 && !$hasKey : !$hasKey);
    if ($needsKey) {
      $what = $type === 'paperlessngx' ? 'homepage.widget.key, or username and password' : 'homepage.widget.key';
      return self::failure('missing_key', "Set {$what}");
    }
    return null;
  }

  // ─── Where to connect ─────────────────────────────────────────────────

  /**
   * The base URLs to try, in order, without a trailing slash.
   *
   * 1. homepage.widget.url, as given.
   * 2. Otherwise the WebUI template (net.unraid.docker.webui). Its scheme,
   *    and the container port in `[PORT:n]`. Its path is dropped: it is the
   *    page the UI opens on (`/admin/`), not where the API lives.
   *    A template with a fixed address instead of `[IP]` is used as given.
   * 3. The host is the container's address on its network. The host routes
   *    to a bridge network directly, so no published port is needed. On the
   *    host network it is 127.0.0.1. On macvlan or ipvlan (br0) it is the
   *    container's LAN address, which the host reaches only with Unraid's
   *    "Host access to custom networks" enabled.
   * 4. Then, once, the published host port for n, as a fallback for a
   *    bridge the host cannot reach.
   *
   * @param array      $config  parseLabels()
   * @param array      $labels  The container's labels, for the WebUI template
   * @param array|null $network Inspect of the container whose network it
   *   uses: itself, or the one named by `container:<id>`
   * @param array      $drivers Network name => driver
   * @return array {targets: string[], custom_network: bool} or {error, message}
   */
  public static function resolveTargets(array $config, array $labels, $network, array $drivers = [])
  {
    if ($config['url'] !== null) {
      $base = self::baseUrl($config['url']);
      return $base === null
        ? self::failure('bad_url', 'homepage.widget.url must be an http or https URL')
        : ['targets' => [$base], 'custom_network' => false];
    }

    $template = trim((string) ($labels['net.unraid.docker.webui'] ?? ''));
    if ($template === '') {
      return self::failure('no_url', 'Set a WebUI for this container, or homepage.widget.url');
    }
    if (!preg_match('#^(https?)://([^/?\#]+)#i', $template, $m)) {
      return self::failure('bad_url', 'The WebUI is not an http or https URL. Set homepage.widget.url');
    }
    $scheme = strtolower($m[1]);
    $authority = $m[2];

    if (stripos($authority, '[IP]') === false) {
      // A fixed host with a [PORT:n] placeholder names the container's port
      // on an address that is not the container's.
      if (stripos($authority, '[PORT:') !== false) {
        return self::failure('bad_url', 'The WebUI mixes a fixed host with [PORT:n]. Set homepage.widget.url');
      }
      $base = self::baseUrl($scheme . '://' . $authority);
      return $base === null
        ? self::failure('bad_url', 'The WebUI is not an http or https URL. Set homepage.widget.url')
        : ['targets' => [$base], 'custom_network' => false];
    }

    if (preg_match('/\[PORT:(\d+)\]/i', $authority, $pm)) {
      $port = (int) $pm[1];
    } elseif (preg_match('/\[IP\]:(\d+)$/i', $authority, $pm)) {
      $port = (int) $pm[1];
    } else {
      $port = $scheme === 'https' ? 443 : 80;
    }
    if ($port < 1 || $port > 65535) {
      return self::failure('bad_url', 'The WebUI port is not valid. Set homepage.widget.url');
    }

    if (!is_array($network)) {
      return self::failure('unreachable', 'The container\'s network could not be read');
    }

    $mode = (string) ($network['HostConfig']['NetworkMode'] ?? '');
    $targets = [];
    $custom = false;
    if ($mode === 'host') {
      $targets[] = "{$scheme}://127.0.0.1:{$port}";
    } else {
      $bridged = [];
      $other = [];
      foreach ((array) ($network['NetworkSettings']['Networks'] ?? []) as $name => $net) {
        $ip = is_array($net) ? (string) ($net['IPAddress'] ?? '') : '';
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;
        if (in_array($drivers[$name] ?? '', self::CUSTOM_NETWORK_DRIVERS, true)) {
          $other[] = "{$scheme}://{$ip}:{$port}";
        } else {
          $bridged[] = "{$scheme}://{$ip}:{$port}";
        }
      }
      $custom = !$bridged && (bool) $other;
      $targets = array_merge($bridged, $other);

      $published = $network['NetworkSettings']['Ports']["{$port}/tcp"] ?? null;
      foreach ((array) $published as $binding) {
        $hostPort = (int) ($binding['HostPort'] ?? 0);
        if ($hostPort < 1) continue;
        $hostIp = (string) ($binding['HostIp'] ?? '');
        if ($hostIp === '' || $hostIp === '0.0.0.0' || $hostIp === '::') {
          $hostIp = '127.0.0.1';
        } elseif (filter_var($hostIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
          $hostIp = "[{$hostIp}]";
        }
        $targets[] = "{$scheme}://{$hostIp}:{$hostPort}";
        break;
      }
    }

    $targets = array_values(array_unique($targets));
    if (!$targets) {
      return self::failure('unreachable', 'The container has no address the server can reach. Set homepage.widget.url');
    }
    return ['targets' => $targets, 'custom_network' => $custom];
  }

  /** scheme://authority[/path] without a trailing slash, or null. */
  public static function baseUrl($url)
  {
    $parts = parse_url((string) $url);
    if (!is_array($parts) || empty($parts['host'])) return null;
    $scheme = strtolower($parts['scheme'] ?? '');
    if ($scheme !== 'http' && $scheme !== 'https') return null;
    if (isset($parts['user']) || isset($parts['pass'])) return null;
    $host = strpos($parts['host'], ':') !== false && $parts['host'][0] !== '[' ? "[{$parts['host']}]" : $parts['host'];
    $base = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    return $base . rtrim($parts['path'] ?? '', '/');
  }

  // ─── What to ask ──────────────────────────────────────────────────────

  /**
   * The requests one widget needs, as name => {path, headers}. The path is
   * appended to the base URL. Endpoints and headers follow gethomepage.
   *
   * @return array
   */
  public static function requestsFor(array $config)
  {
    $key = (string) $config['key'];
    $v2 = $config['version'] >= 2;
    switch ($config['type']) {
      case 'jellyfin':
        $auth = 'Authorization: MediaBrowser Token="' . rawurlencode($key)
          . '", Client="Docker Folders", Device="Unraid", DeviceId="unraid-docker-folders", Version="1.0.0"';
        return ['counts' => [
          'path' => $v2 ? '/Items/Counts' : '/emby/Items/Counts?api_key=' . rawurlencode($key),
          'headers' => [$auth],
        ]];

      case 'immich':
        return ['statistics' => [
          'path' => $v2 ? '/api/server/statistics' : '/api/server-info/statistics',
          'headers' => ['x-api-key: ' . $key],
        ]];

      case 'paperlessngx':
        $auth = $config['key'] !== null
          ? 'Authorization: Token ' . $key
          : 'Authorization: Basic ' . base64_encode($config['username'] . ':' . $config['password']);
        return ['statistics' => ['path' => '/api/statistics/?format=json', 'headers' => [$auth]]];

      case 'gotify':
        $auth = 'X-Gotify-Key: ' . $key;
        return [
          'application' => ['path' => '/application', 'headers' => [$auth]],
          'client' => ['path' => '/client', 'headers' => [$auth]],
          'message' => ['path' => '/message', 'headers' => [$auth]],
        ];

      case 'speedtest':
        return ['latest' => [
          'path' => $v2 ? '/api/v1/results/latest' : '/api/speedtest/latest',
          // Version 1 takes no key.
          'headers' => $config['key'] !== null ? ['Authorization: Bearer ' . $key] : [],
        ]];
    }
    return [];
  }

  /**
   * The values, by field name, from the decoded responses. A field the
   * service did not report is left out. Null when a response is not the
   * shape the type expects.
   *
   * @param array $data name => decoded JSON, as requestsFor() named them
   * @return array|null field => int|float|string
   */
  public static function extractValues(array $config, array $data)
  {
    $num = function ($value) {
      return is_int($value) || is_float($value) ? $value : (is_numeric($value) ? $value + 0 : null);
    };
    $values = [];
    switch ($config['type']) {
      case 'jellyfin':
        $c = $data['counts'] ?? null;
        if (!is_array($c)) return null;
        foreach (['movies' => 'MovieCount', 'series' => 'SeriesCount', 'episodes' => 'EpisodeCount',
          'songs' => 'SongCount', 'albums' => 'AlbumCount'] as $field => $prop) {
          if (array_key_exists($prop, $c)) $values[$field] = $num($c[$prop]);
        }
        break;

      case 'immich':
        $s = $data['statistics'] ?? null;
        if (!is_array($s) || !array_key_exists('photos', $s)) return null;
        if (is_array($s['usageByUser'] ?? null)) $values['users'] = count($s['usageByUser']);
        $values['photos'] = $num($s['photos']);
        if (array_key_exists('videos', $s)) $values['videos'] = $num($s['videos']);
        if (array_key_exists('usage', $s)) {
          // Old Immich versions sent the usage preformatted, e.g. "9 GiB".
          $values['storage'] = is_string($s['usage']) && !is_numeric($s['usage']) ? $s['usage'] : $num($s['usage']);
        }
        break;

      case 'paperlessngx':
        $s = $data['statistics'] ?? null;
        if (!is_array($s) || !array_key_exists('documents_total', $s)) return null;
        $values['total'] = $num($s['documents_total']);
        if (array_key_exists('documents_inbox', $s)) $values['inbox'] = $num($s['documents_inbox']);
        break;

      case 'gotify':
        // Like gethomepage, the count of messages in the first page Gotify returns.
        $apps = $data['application'] ?? null;
        $clients = $data['client'] ?? null;
        $messages = $data['message']['messages'] ?? null;
        if (!is_array($apps) || !is_array($clients) || !is_array($messages)) return null;
        $values = ['apps' => count($apps), 'clients' => count($clients), 'messages' => count($messages)];
        break;

      case 'speedtest':
        $d = $data['latest']['data'] ?? null;
        if (!is_array($d)) return null;
        // Version 1 reports Mbit/s, version 2 bytes per second. Both become bit/s.
        $scale = $config['version'] >= 2 ? 8 : 1000000;
        foreach (['download', 'upload'] as $field) {
          $value = $num($d[$field] ?? null);
          if ($value !== null) $values[$field] = $value * $scale;
        }
        $ping = $num($d['ping'] ?? null);
        if ($ping !== null) $values['ping'] = $ping;
        break;

      default:
        return null;
    }
    return array_filter($values, function ($v) { return $v !== null; });
  }

  /** The selected fields as the frontend shows them, in order. */
  public static function buildFields(array $config, array $values)
  {
    $fields = [];
    foreach (self::selectFields($config['type'], $config['fields']) as $field) {
      if (!array_key_exists($field, $values)) continue;
      [$label, $format] = self::TYPES[$config['type']]['fields'][$field];
      $value = $values[$field];
      $fields[] = ['key' => $field, 'label' => $label, 'value' => $value, 'format' => is_string($value) ? 'text' : $format];
    }
    return $fields;
  }

  // ─── Running it ───────────────────────────────────────────────────────

  /**
   * Widgets for the given containers, keyed by the id asked for. A container
   * without a homepage.widget.type label is left out.
   *
   * @param string[]     $ids
   * @param DockerClient $docker
   * @param int|null     $now
   * @return array id => {type, fields: [{key, label, value, format}]} or {type, error, message, target?}
   */
  public function forContainers(array $ids, $docker, $now = null)
  {
    $now = $now ?? time();
    $inspects = $docker->inspectMany($ids);
    $cache = $this->loadCache();

    $results = [];
    $jobs = [];
    $driverCache = [];
    foreach ($ids as $id) {
      $inspect = $inspects[$id] ?? null;
      if (!is_array($inspect)) continue;
      $labels = (array) ($inspect['Config']['Labels'] ?? []);
      $config = self::parseLabels($labels);
      if ($config === null) continue;

      $problem = self::configProblem($config);
      if ($problem !== null) {
        $results[$id] = ['type' => $config['type']] + $problem;
        continue;
      }
      if (empty($inspect['State']['Running'])) {
        $results[$id] = ['type' => $config['type']] + self::failure('not_running', 'The container is not running');
        continue;
      }

      // `container:<id>` shares another container's network, so it is
      // reached at that one's address. One level only.
      $network = $inspect;
      $mode = (string) ($inspect['HostConfig']['NetworkMode'] ?? '');
      if (strpos($mode, 'container:') === 0) {
        $network = $docker->inspectContainerRaw(substr($mode, strlen('container:')));
      }
      $drivers = [];
      foreach (array_keys((array) ($network['NetworkSettings']['Networks'] ?? [])) as $name) {
        if (!array_key_exists($name, $driverCache)) $driverCache[$name] = $docker->getNetworkDriver($name);
        $drivers[$name] = $driverCache[$name];
      }

      $resolved = self::resolveTargets($config, $labels, $network, $drivers);
      if (isset($resolved['error'])) {
        $results[$id] = ['type' => $config['type']] + $resolved;
        continue;
      }

      $signature = self::signature($config, $resolved['targets']);
      $entry = $cache[$id] ?? null;
      if (is_array($entry) && ($entry['sig'] ?? '') === $signature) {
        $ttl = isset($entry['result']['error']) ? self::ERROR_CACHE_SECONDS : self::CACHE_SECONDS;
        if ($now - (int) ($entry['at'] ?? 0) < $ttl) {
          $results[$id] = $entry['result'];
          continue;
        }
      }

      // The address that answered last time goes first.
      $targets = $resolved['targets'];
      $last = $entry['target'] ?? null;
      if (is_array($entry) && ($entry['sig'] ?? '') === $signature && in_array($last, $targets, true)) {
        $targets = array_values(array_unique(array_merge([$last], $targets)));
      }
      $jobs[$id] = ['config' => $config, 'targets' => $targets, 'custom' => $resolved['custom_network'], 'sig' => $signature];
    }

    foreach ($this->runJobs($jobs) as $id => $outcome) {
      $results[$id] = $outcome['result'];
      $cache[$id] = ['at' => $now, 'sig' => $jobs[$id]['sig'], 'target' => $outcome['target'], 'result' => $outcome['result']];
    }

    if ($jobs) $this->saveCache($cache, $now);
    return $results;
  }

  /**
   * Ask every job's first target in one parallel round, then the next target
   * for the jobs that could not connect, until none is left.
   *
   * @return array id => {result, target}
   */
  public function runJobs(array $jobs)
  {
    $outcomes = [];
    $attempt = 0;
    $pending = $jobs;
    while ($pending) {
      $requests = [];
      foreach ($pending as $id => $job) {
        $base = $job['targets'][$attempt];
        foreach (self::requestsFor($job['config']) as $name => $req) {
          $requests[$id . "\0" . $name] = ['url' => $base . $req['path'], 'headers' => $req['headers']];
        }
      }
      $responses = call_user_func($this->transport, $requests);

      $next = [];
      foreach ($pending as $id => $job) {
        $base = $job['targets'][$attempt];
        $data = [];
        $failure = null;
        $unreachable = false;
        foreach (array_keys(self::requestsFor($job['config'])) as $name) {
          $response = $responses[$id . "\0" . $name] ?? ['status' => 0, 'body' => '', 'error' => 'connect'];
          $problem = self::responseProblem($response);
          if ($problem !== null) {
            $failure = $failure ?? $problem;
            $unreachable = $unreachable || $problem['error'] === 'unreachable';
            continue;
          }
          $data[$name] = json_decode((string) $response['body'], true);
        }

        if ($failure === null) {
          $values = self::extractValues($job['config'], $data);
          $result = $values === null
            ? self::failure('bad_response', 'The service answered with something other than its API')
            : ['fields' => self::buildFields($job['config'], $values)];
        } elseif ($unreachable && isset($job['targets'][$attempt + 1])) {
          $next[$id] = $job;
          continue;
        } else {
          $result = $failure;
          if ($failure['error'] === 'unreachable' && $job['custom']) {
            $result['message'] = 'The server cannot reach this container on its custom network. Enable "Host access to custom networks" in Settings > Docker, or set homepage.widget.url';
          }
        }
        $result = ['type' => $job['config']['type']] + $result + ['target' => $base];
        $outcomes[$id] = ['result' => $result, 'target' => $base];
      }
      $pending = $next;
      $attempt++;
    }
    return $outcomes;
  }

  /**
   * Why one response is not usable, or null.
   *
   * @param array $response {status, body, error}: error is '' on success,
   *   'connect' when no connection was made, 'tls', 'timeout', 'too_large' or 'other'
   */
  public static function responseProblem(array $response)
  {
    $error = (string) ($response['error'] ?? '');
    $status = (int) ($response['status'] ?? 0);
    if ($error === 'connect') return self::failure('unreachable', 'The service did not accept a connection');
    if ($error === 'timeout') return self::failure('timeout', 'The service did not answer in time');
    if ($error === 'tls') {
      return self::failure('tls', 'The service\'s TLS certificate is not valid for this address. Set homepage.widget.url with its hostname');
    }
    if ($error === 'too_large') return self::failure('bad_response', 'The service\'s answer is too large');
    if ($error !== '') return self::failure('http', 'The request failed');
    if ($status === 401 || $status === 403) return self::failure('auth', 'The service refused the key');
    if ($status >= 300 && $status < 400) {
      return self::failure('http', "The service redirected (HTTP {$status}). Set homepage.widget.url to the final address");
    }
    if ($status < 200 || $status >= 300) return self::failure('http', "The service answered HTTP {$status}");
    if (!is_array(json_decode((string) ($response['body'] ?? ''), true))) {
      return self::failure('bad_response', 'The service answered with something other than its API');
    }
    return null;
  }

  /**
   * Run requests in parallel with curl_multi.
   *
   * No redirects, http and https only, a hard timeout and a size cap. The
   * environment's proxy is not used: these are addresses on this server.
   *
   * @param array $requests key => {url, headers}
   * @return array key => {status, body, error}
   */
  public static function curlTransport(array $requests)
  {
    $mh = curl_multi_init();
    $handles = [];
    $bodies = [];
    $tooLarge = [];
    foreach ($requests as $key => $req) {
      $ch = curl_init();
      $bodies[$key] = '';
      curl_setopt_array($ch, [
        CURLOPT_URL => $req['url'],
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $req['headers']),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => self::TIMEOUT,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_USERAGENT => 'unraid-docker-folders',
        CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use ($key, &$bodies, &$tooLarge) {
          if (strlen($bodies[$key]) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
            $tooLarge[$key] = true;
            return 0;
          }
          $bodies[$key] .= $chunk;
          return strlen($chunk);
        },
      ]);
      curl_multi_add_handle($mh, $ch);
      $handles[$key] = $ch;
    }

    $running = null;
    do {
      curl_multi_exec($mh, $running);
      if ($running > 0) curl_multi_select($mh, 0.5);
    } while ($running > 0);

    $errors = [];
    while (($info = curl_multi_info_read($mh)) !== false) {
      $key = array_search($info['handle'], $handles, true);
      if ($key !== false) $errors[$key] = $info['result'];
    }

    $responses = [];
    foreach ($handles as $key => $ch) {
      $code = $errors[$key] ?? curl_errno($ch);
      $responses[$key] = [
        'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'body' => $bodies[$key],
        'error' => !empty($tooLarge[$key]) ? 'too_large'
          : self::curlErrorKind($code, curl_getinfo($ch, CURLINFO_CONNECT_TIME) > 0),
      ];
      curl_multi_remove_handle($mh, $ch);
      curl_close($ch);
    }
    curl_multi_close($mh);
    return $responses;
  }

  /**
   * A curl error code as responseProblem() reads it. A timeout before the
   * connection was made counts as no connection: a host that drops packets
   * to a macvlan address times out rather than refusing.
   */
  public static function curlErrorKind($code, $connected = true)
  {
    switch ((int) $code) {
      case 0: return '';
      case 6: // CURLE_COULDNT_RESOLVE_HOST
      case 7: // CURLE_COULDNT_CONNECT
        return 'connect';
      case 28: // CURLE_OPERATION_TIMEDOUT
        return $connected ? 'timeout' : 'connect';
      case 35: // CURLE_SSL_CONNECT_ERROR
      case 51: // CURLE_PEER_FAILED_VERIFICATION in older curl
      case 58: // CURLE_SSL_CERTPROBLEM
      case 60: // CURLE_PEER_FAILED_VERIFICATION
      case 77: // CURLE_SSL_CACERT_BADFILE
        return 'tls';
    }
    return 'other';
  }

  // ─── Cache ────────────────────────────────────────────────────────────

  /** Changes when anything that decides the answer changes. Keys are hashed, not stored. */
  public static function signature(array $config, array $targets)
  {
    return hash('sha256', json_encode([$config, $targets]));
  }

  private function loadCache()
  {
    if ($this->cachePath === null || !is_file($this->cachePath)) return [];
    $data = json_decode((string) @file_get_contents($this->cachePath), true);
    return is_array($data) ? $data : [];
  }

  private function saveCache(array $cache, $now)
  {
    if ($this->cachePath === null) return;
    // Entries no poll has asked for in a while belong to removed containers.
    $cache = array_filter($cache, function ($entry) use ($now) {
      return is_array($entry) && $now - (int) ($entry['at'] ?? 0) < 3600;
    });
    $tmp = $this->cachePath . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($cache)) !== false) {
      @chmod($tmp, 0600);
      @rename($tmp, $this->cachePath);
    }
  }

  private static function failure($code, $message)
  {
    return ['error' => $code, 'message' => $message];
  }
}
