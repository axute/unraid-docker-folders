<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/backend/usr/local/emhttp/plugins/unraid-docker-folders-modern/include/config.php';
require_once __DIR__ . '/../../src/backend/usr/local/emhttp/plugins/unraid-docker-folders-modern/classes/ServiceWidgets.php';

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * ServiceWidgets: the homepage.widget.* labels, where a widget connects, what
 * it asks, and how the answers become fields. The transport and Docker are
 * fakes, so nothing here opens a connection.
 */
final class ServiceWidgetsTest extends TestCase
{
    private const KEY = 'secret-key-1234';

    private static function labels(array $widget, ?string $webui = 'http://[IP]:[PORT:8096]/'): array
    {
        $labels = [];
        foreach ($widget as $name => $value) {
            $labels['homepage.widget.' . $name] = $value;
        }
        if ($webui !== null) $labels['net.unraid.docker.webui'] = $webui;
        return $labels;
    }

    private static function inspect(array $labels, array $overrides = []): array
    {
        return array_replace_recursive([
            'Config' => ['Labels' => $labels],
            'State' => ['Running' => true],
            'HostConfig' => ['NetworkMode' => 'bridge'],
            'NetworkSettings' => [
                'Networks' => ['bridge' => ['IPAddress' => '172.17.0.5']],
                'Ports' => ['8096/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '18096']]],
            ],
        ], $overrides);
    }

    private static function config(array $widget): array
    {
        return ServiceWidgets::parseLabels(self::labels($widget));
    }

    private static function docker(array $inspects, array $drivers = []): object
    {
        return new class ($inspects, $drivers) {
            public function __construct(private array $inspects, private array $drivers) {}
            public function inspectMany(array $ids): array
            {
                return array_intersect_key($this->inspects, array_flip($ids));
            }
            public function inspectContainerRaw(string $id): ?array
            {
                return $this->inspects[$id] ?? null;
            }
            public function getNetworkDriver(string $name): string
            {
                return $this->drivers[$name] ?? 'bridge';
            }
        };
    }

    private static function ok(array $body): array
    {
        return ['status' => 200, 'body' => json_encode($body), 'error' => ''];
    }

    // ─── Labels ───────────────────────────────────────────────────────────

    #[Test]
    public function parsesTheGethomepageLabels(): void
    {
        $config = ServiceWidgets::parseLabels([
            'homepage.widget.type' => 'Jellyfin',
            'homepage.widget.key' => ' abc ',
            'homepage.widget.version' => '2',
            'homepage.widget.fields' => '["series", "movies"]',
            'homepage.name' => 'Jellyfin',
        ]);
        $this->assertSame('jellyfin', $config['type']);
        $this->assertSame('abc', $config['key']);
        $this->assertSame(2, $config['version']);
        $this->assertSame(['series', 'movies'], $config['fields']);
        $this->assertNull($config['url']);
    }

    #[Test]
    public function noTypeLabelMeansNoWidget(): void
    {
        $this->assertNull(ServiceWidgets::parseLabels(['homepage.widget.key' => 'x']));
        $this->assertNull(ServiceWidgets::parseLabels(['homepage.instance.home.widget.type' => 'jellyfin']));
        $this->assertNull(ServiceWidgets::parseLabels(null));
    }

    #[Test]
    public function versionDefaultsToOneLikeGethomepage(): void
    {
        $this->assertSame(1, self::config(['type' => 'immich', 'key' => 'k'])['version']);
        $this->assertSame(1, self::config(['type' => 'immich', 'key' => 'k', 'version' => 'two'])['version']);
    }

    #[Test]
    public function unreadableFieldsFallBackToTheDefault(): void
    {
        $config = self::config(['type' => 'jellyfin', 'key' => 'k', 'fields' => 'movies,series']);
        $this->assertNull($config['fields']);
        $this->assertSame(['movies', 'series', 'episodes', 'songs'], ServiceWidgets::selectFields('jellyfin', null));
    }

    #[Test]
    public function selectFieldsKeepsKnownFieldsInOrderUpToFour(): void
    {
        $this->assertSame(['albums', 'movies'], ServiceWidgets::selectFields('jellyfin', ['albums', 'nope', 'movies', 'albums']));
        $this->assertSame(
            ['movies', 'series', 'episodes', 'songs'],
            ServiceWidgets::selectFields('jellyfin', ['movies', 'series', 'episodes', 'songs', 'albums']),
        );
        $this->assertSame(['inbox', 'total'], ServiceWidgets::selectFields('paperlessngx', ['bogus']));
    }

    #[Test]
    public function configProblems(): void
    {
        $this->assertSame('unsupported', ServiceWidgets::configProblem(self::config(['type' => 'sonarr', 'key' => 'k']))['error']);
        $this->assertSame('missing_key', ServiceWidgets::configProblem(self::config(['type' => 'jellyfin']))['error']);
        $this->assertSame('placeholder', ServiceWidgets::configProblem(
            self::config(['type' => 'jellyfin', 'key' => '{{HOMEPAGE_VAR_JELLYFIN_KEY}}']),
        )['error']);
        // Paperless takes a key or a login; Speedtest needs a key from version 2 only.
        $this->assertNull(ServiceWidgets::configProblem(self::config(['type' => 'paperlessngx', 'username' => 'u', 'password' => 'p'])));
        $this->assertSame('missing_key', ServiceWidgets::configProblem(self::config(['type' => 'paperlessngx', 'username' => 'u']))['error']);
        $this->assertNull(ServiceWidgets::configProblem(self::config(['type' => 'speedtest'])));
        $this->assertSame('missing_key', ServiceWidgets::configProblem(self::config(['type' => 'speedtest', 'version' => '2']))['error']);
    }

    // ─── Where to connect ─────────────────────────────────────────────────

    #[Test]
    public function aUrlLabelWinsAndLosesItsTrailingSlash(): void
    {
        $config = self::config(['type' => 'jellyfin', 'key' => 'k', 'url' => 'https://media.example.com/jellyfin/']);
        $resolved = ServiceWidgets::resolveTargets($config, [], null);
        $this->assertSame(['https://media.example.com/jellyfin'], $resolved['targets']);
    }

    #[Test]
    public function aUrlLabelMustBeHttpWithoutCredentials(): void
    {
        foreach (['file:///etc/passwd', 'gopher://x', 'http://user:pw@host', 'not a url'] as $url) {
            $config = self::config(['type' => 'jellyfin', 'key' => 'k', 'url' => $url]);
            $this->assertSame('bad_url', ServiceWidgets::resolveTargets($config, [], null)['error'] ?? null, $url);
        }
    }

    #[Test]
    public function bridgeUsesTheContainerIpAndWebuiContainerPortThenThePublishedPort(): void
    {
        $labels = self::labels(['type' => 'jellyfin', 'key' => 'k'], 'http://[IP]:[PORT:8096]/web/index.html');
        $resolved = ServiceWidgets::resolveTargets(ServiceWidgets::parseLabels($labels), $labels, self::inspect($labels));
        $this->assertSame(['http://172.17.0.5:8096', 'http://127.0.0.1:18096'], $resolved['targets']);
        $this->assertFalse($resolved['custom_network']);
    }

    #[Test]
    public function aPublishBoundToOneHostAddressUsesThatAddress(): void
    {
        $labels = self::labels(['type' => 'jellyfin', 'key' => 'k']);
        $inspect = self::inspect($labels, ['NetworkSettings' => ['Ports' => ['8096/tcp' => [['HostIp' => '192.168.1.10', 'HostPort' => '8096']]]]]);
        $resolved = ServiceWidgets::resolveTargets(ServiceWidgets::parseLabels($labels), $labels, $inspect);
        $this->assertSame(['http://172.17.0.5:8096', 'http://192.168.1.10:8096'], $resolved['targets']);
    }

    #[Test]
    public function hostNetworkUsesLoopback(): void
    {
        $labels = self::labels(['type' => 'jellyfin', 'key' => 'k']);
        $inspect = self::inspect($labels, ['HostConfig' => ['NetworkMode' => 'host']]);
        $inspect['NetworkSettings'] = ['Networks' => ['host' => ['IPAddress' => '']], 'Ports' => []];
        $resolved = ServiceWidgets::resolveTargets(ServiceWidgets::parseLabels($labels), $labels, $inspect);
        $this->assertSame(['http://127.0.0.1:8096'], $resolved['targets']);
    }

    #[Test]
    public function macvlanUsesTheLanAddressAndSaysSo(): void
    {
        $labels = self::labels(['type' => 'jellyfin', 'key' => 'k']);
        $inspect = self::inspect($labels, ['HostConfig' => ['NetworkMode' => 'br0']]);
        $inspect['NetworkSettings'] = ['Networks' => ['br0' => ['IPAddress' => '192.168.1.50']], 'Ports' => []];
        $resolved = ServiceWidgets::resolveTargets(ServiceWidgets::parseLabels($labels), $labels, $inspect, ['br0' => 'macvlan']);
        $this->assertSame(['http://192.168.1.50:8096'], $resolved['targets']);
        $this->assertTrue($resolved['custom_network']);
    }

    #[Test]
    public function aBridgeAddressGoesBeforeACustomNetworkOne(): void
    {
        $labels = self::labels(['type' => 'jellyfin', 'key' => 'k']);
        $inspect = self::inspect($labels);
        $inspect['NetworkSettings']['Networks'] = [
            'br0' => ['IPAddress' => '192.168.1.50'],
            'proxynet' => ['IPAddress' => '172.18.0.3'],
        ];
        $resolved = ServiceWidgets::resolveTargets(
            ServiceWidgets::parseLabels($labels), $labels, $inspect, ['br0' => 'macvlan', 'proxynet' => 'bridge'],
        );
        $this->assertSame(['http://172.18.0.3:8096', 'http://192.168.1.50:8096', 'http://127.0.0.1:18096'], $resolved['targets']);
        $this->assertFalse($resolved['custom_network']);
    }

    #[Test]
    public function webuiVariants(): void
    {
        $cases = [
            // A literal port next to [IP] is used for both the container and the host.
            ['http://[IP]:7878', ['http://172.17.0.5:7878']],
            ['https://[IP]:[PORT:8443]/', ['https://172.17.0.5:8443']],
            ['http://[IP]/admin', ['http://172.17.0.5:80']],
            // A fixed address is taken as given, without its path.
            ['http://192.168.1.5:8096/web', ['http://192.168.1.5:8096']],
        ];
        foreach ($cases as [$webui, $expected]) {
            $labels = self::labels(['type' => 'jellyfin', 'key' => 'k'], $webui);
            $inspect = self::inspect($labels);
            $inspect['NetworkSettings']['Ports'] = [];
            $resolved = ServiceWidgets::resolveTargets(ServiceWidgets::parseLabels($labels), $labels, $inspect);
            $this->assertSame($expected, $resolved['targets'] ?? $resolved, $webui);
        }
    }

    #[Test]
    public function noWebuiAndNoUrlIsAnError(): void
    {
        $labels = self::labels(['type' => 'jellyfin', 'key' => 'k'], null);
        $resolved = ServiceWidgets::resolveTargets(ServiceWidgets::parseLabels($labels), $labels, self::inspect($labels));
        $this->assertSame('no_url', $resolved['error']);

        $labels = self::labels(['type' => 'jellyfin', 'key' => 'k'], 'http://192.168.1.5:[PORT:8096]/');
        $resolved = ServiceWidgets::resolveTargets(ServiceWidgets::parseLabels($labels), $labels, self::inspect($labels));
        $this->assertSame('bad_url', $resolved['error']);
    }

    // ─── What to ask, and the answers ─────────────────────────────────────

    #[Test]
    public function requestsFollowGethomepage(): void
    {
        $jf1 = ServiceWidgets::requestsFor(self::config(['type' => 'jellyfin', 'key' => 'a b']));
        $this->assertSame('/emby/Items/Counts?api_key=a%20b', $jf1['counts']['path']);
        $this->assertStringStartsWith('Authorization: MediaBrowser Token="a%20b"', $jf1['counts']['headers'][0]);
        $jf2 = ServiceWidgets::requestsFor(self::config(['type' => 'jellyfin', 'key' => 'k', 'version' => '2']));
        $this->assertSame('/Items/Counts', $jf2['counts']['path']);

        $im = ServiceWidgets::requestsFor(self::config(['type' => 'immich', 'key' => 'k', 'version' => '2']));
        $this->assertSame(['path' => '/api/server/statistics', 'headers' => ['x-api-key: k']], $im['statistics']);

        $pl = ServiceWidgets::requestsFor(self::config(['type' => 'paperlessngx', 'username' => 'u', 'password' => 'p']));
        $this->assertSame(['Authorization: Basic ' . base64_encode('u:p')], $pl['statistics']['headers']);
        $pk = ServiceWidgets::requestsFor(self::config(['type' => 'paperlessngx', 'key' => 't', 'username' => 'u', 'password' => 'p']));
        $this->assertSame(['Authorization: Token t'], $pk['statistics']['headers']);

        $go = ServiceWidgets::requestsFor(self::config(['type' => 'gotify', 'key' => 'c']));
        $this->assertSame(['application', 'client', 'message'], array_keys($go));
        $this->assertSame(['X-Gotify-Key: c'], $go['message']['headers']);

        $st1 = ServiceWidgets::requestsFor(self::config(['type' => 'speedtest']));
        $this->assertSame(['path' => '/api/speedtest/latest', 'headers' => []], $st1['latest']);
        $st2 = ServiceWidgets::requestsFor(self::config(['type' => 'speedtest', 'version' => '2', 'key' => 'k']));
        $this->assertSame(['path' => '/api/v1/results/latest', 'headers' => ['Authorization: Bearer k']], $st2['latest']);
    }

    #[Test]
    public function extractsEachTypesValues(): void
    {
        $this->assertSame(
            ['movies' => 812, 'series' => 94, 'episodes' => 4021, 'songs' => 0, 'albums' => 0],
            ServiceWidgets::extractValues(self::config(['type' => 'jellyfin', 'key' => 'k']), ['counts' => [
                'MovieCount' => 812, 'SeriesCount' => 94, 'EpisodeCount' => 4021, 'SongCount' => 0, 'AlbumCount' => 0,
            ]]),
        );
        $this->assertSame(
            ['users' => 2, 'photos' => 15000, 'videos' => 300, 'storage' => 123456789],
            ServiceWidgets::extractValues(self::config(['type' => 'immich', 'key' => 'k']), ['statistics' => [
                'photos' => 15000, 'videos' => 300, 'usage' => 123456789, 'usageByUser' => [[], []],
            ]]),
        );
        $this->assertSame(
            ['total' => 1204],
            ServiceWidgets::extractValues(self::config(['type' => 'paperlessngx', 'key' => 'k']), ['statistics' => ['documents_total' => 1204]]),
        );
        $this->assertSame(
            ['apps' => 2, 'clients' => 1, 'messages' => 3],
            ServiceWidgets::extractValues(self::config(['type' => 'gotify', 'key' => 'k']), [
                'application' => [[], []], 'client' => [[]], 'message' => ['messages' => [[], [], []], 'paging' => []],
            ]),
        );
        // Version 1 reports Mbit/s, version 2 bytes per second.
        $this->assertSame(
            ['download' => 250000000, 'upload' => 40000000, 'ping' => 12.5],
            ServiceWidgets::extractValues(self::config(['type' => 'speedtest']), ['latest' => ['data' => ['download' => 250, 'upload' => 40, 'ping' => 12.5]]]),
        );
        $this->assertSame(
            ['download' => 200000000, 'upload' => 80000000, 'ping' => 9],
            ServiceWidgets::extractValues(self::config(['type' => 'speedtest', 'version' => '2', 'key' => 'k']), ['latest' => ['data' => ['download' => 25000000, 'upload' => 10000000, 'ping' => 9]]]),
        );
    }

    #[Test]
    public function aWrongShapeIsNotAWidget(): void
    {
        $this->assertNull(ServiceWidgets::extractValues(self::config(['type' => 'jellyfin', 'key' => 'k']), ['counts' => null]));
        $this->assertNull(ServiceWidgets::extractValues(self::config(['type' => 'paperlessngx', 'key' => 'k']), ['statistics' => ['detail' => 'x']]));
        $this->assertNull(ServiceWidgets::extractValues(self::config(['type' => 'speedtest']), ['latest' => ['message' => 'none']]));
    }

    #[Test]
    public function buildFieldsUsesTheSelectedOrderAndSkipsMissingValues(): void
    {
        $config = self::config(['type' => 'paperlessngx', 'key' => 'k']);
        $this->assertSame(
            [['key' => 'total', 'label' => 'Total', 'value' => 1204, 'format' => 'number']],
            ServiceWidgets::buildFields($config, ['total' => 1204]),
        );
        $immich = self::config(['type' => 'immich', 'key' => 'k', 'fields' => '["storage"]']);
        $this->assertSame('text', ServiceWidgets::buildFields($immich, ['storage' => '9 GiB'])[0]['format']);
    }

    #[Test]
    public function responseProblems(): void
    {
        $this->assertSame('unreachable', ServiceWidgets::responseProblem(['status' => 0, 'body' => '', 'error' => 'connect'])['error']);
        $this->assertSame('tls', ServiceWidgets::responseProblem(['status' => 0, 'body' => '', 'error' => 'tls'])['error']);
        $this->assertSame('auth', ServiceWidgets::responseProblem(['status' => 401, 'body' => '{}', 'error' => ''])['error']);
        $this->assertSame('http', ServiceWidgets::responseProblem(['status' => 302, 'body' => '', 'error' => ''])['error']);
        $this->assertSame('bad_response', ServiceWidgets::responseProblem(['status' => 200, 'body' => '<html>', 'error' => ''])['error']);
        $this->assertNull(ServiceWidgets::responseProblem(['status' => 200, 'body' => '[]', 'error' => '']));
    }

    #[Test]
    public function aConnectTimeoutCountsAsUnreachable(): void
    {
        $this->assertSame('connect', ServiceWidgets::curlErrorKind(28, false));
        $this->assertSame('timeout', ServiceWidgets::curlErrorKind(28, true));
        $this->assertSame('tls', ServiceWidgets::curlErrorKind(60));
        $this->assertSame('', ServiceWidgets::curlErrorKind(0));
    }

    // ─── End to end, with fakes ───────────────────────────────────────────

    #[Test]
    public function fallsBackToThePublishedPortWhenTheContainerIpDoesNotConnect(): void
    {
        $labels = self::labels(['type' => 'jellyfin', 'key' => self::KEY, 'version' => '2']);
        $asked = [];
        $transport = function (array $requests) use (&$asked) {
            $out = [];
            foreach ($requests as $key => $req) {
                $asked[] = $req['url'];
                $out[$key] = str_starts_with($req['url'], 'http://172.17.0.5')
                    ? ['status' => 0, 'body' => '', 'error' => 'connect']
                    : self::ok(['MovieCount' => 5, 'SeriesCount' => 2, 'EpisodeCount' => 20, 'SongCount' => 0]);
            }
            return $out;
        };
        $widgets = (new ServiceWidgets($transport, null))->forContainers(['jf'], self::docker(['jf' => self::inspect($labels)]));

        $this->assertSame(['http://172.17.0.5:8096/Items/Counts', 'http://127.0.0.1:18096/Items/Counts'], $asked);
        $this->assertSame('jellyfin', $widgets['jf']['type']);
        $this->assertSame('http://127.0.0.1:18096', $widgets['jf']['target']);
        $this->assertSame([5, 2, 20, 0], array_column($widgets['jf']['fields'], 'value'));
    }

    #[Test]
    public function theKeyNeverReachesTheResultOrTheCache(): void
    {
        $cache = tempnam(sys_get_temp_dir(), 'sw');
        try {
            $labels = self::labels(['type' => 'jellyfin', 'key' => self::KEY]);
            $transport = fn (array $requests) => array_map(fn () => ['status' => 401, 'body' => '{}', 'error' => ''], $requests);
            $widgets = (new ServiceWidgets($transport, $cache))->forContainers(['jf'], self::docker(['jf' => self::inspect($labels)]), 1000);

            $this->assertSame('auth', $widgets['jf']['error']);
            $this->assertStringNotContainsString(self::KEY, json_encode($widgets));
            $this->assertStringNotContainsString(self::KEY, (string) file_get_contents($cache));
            $this->assertStringNotContainsString(rawurlencode(self::KEY), (string) file_get_contents($cache));
        } finally {
            @unlink($cache);
        }
    }

    #[Test]
    public function aCachedAnswerIsReusedUntilItExpiresOrTheLabelsChange(): void
    {
        $cache = tempnam(sys_get_temp_dir(), 'sw');
        try {
            $calls = 0;
            $transport = function (array $requests) use (&$calls) {
                $calls++;
                return array_map(fn () => self::ok(['documents_total' => 7]), $requests);
            };
            $widgets = new ServiceWidgets($transport, $cache);
            $labels = self::labels(['type' => 'paperlessngx', 'key' => 'k'], 'http://[IP]:[PORT:8000]/');
            $docker = self::docker(['pl' => self::inspect($labels)]);

            $widgets->forContainers(['pl'], $docker, 1000);
            $widgets->forContainers(['pl'], $docker, 1000 + ServiceWidgets::CACHE_SECONDS - 1);
            $this->assertSame(1, $calls);

            $widgets->forContainers(['pl'], $docker, 1000 + ServiceWidgets::CACHE_SECONDS);
            $this->assertSame(2, $calls);

            $changed = self::labels(['type' => 'paperlessngx', 'key' => 'other'], 'http://[IP]:[PORT:8000]/');
            $widgets->forContainers(['pl'], self::docker(['pl' => self::inspect($changed)]), 1000 + ServiceWidgets::CACHE_SECONDS + 1);
            $this->assertSame(3, $calls);
        } finally {
            @unlink($cache);
        }
    }

    #[Test]
    public function containersWithoutLabelsAreLeftOutAndStoppedOnesAreNotAsked(): void
    {
        $transport = function () {
            throw new RuntimeException('no request expected');
        };
        $docker = self::docker([
            'plain' => self::inspect(['net.unraid.docker.webui' => 'http://[IP]:[PORT:80]/']),
            'off' => self::inspect(self::labels(['type' => 'gotify', 'key' => 'k']), ['State' => ['Running' => false]]),
        ]);
        $widgets = (new ServiceWidgets($transport, null))->forContainers(['plain', 'off'], $docker);
        $this->assertSame(['off'], array_keys($widgets));
        $this->assertSame('not_running', $widgets['off']['error']);
    }

    #[Test]
    public function aContainerOnAnotherContainersNetworkUsesThatAddress(): void
    {
        $labels = self::labels(['type' => 'speedtest'], 'http://[IP]:[PORT:80]/');
        $vpn = self::inspect([]);
        $vpn['NetworkSettings']['Networks'] = ['bridge' => ['IPAddress' => '172.17.0.9']];
        $vpn['NetworkSettings']['Ports'] = [];
        $docker = self::docker([
            'st' => self::inspect($labels, ['HostConfig' => ['NetworkMode' => 'container:vpn']]),
            'vpn' => $vpn,
        ]);
        $asked = [];
        $transport = function (array $requests) use (&$asked) {
            $asked = array_column($requests, 'url');
            return array_map(fn () => self::ok(['data' => ['download' => 1, 'upload' => 1, 'ping' => 1]]), $requests);
        };
        (new ServiceWidgets($transport, null))->forContainers(['st'], $docker);
        $this->assertSame(['http://172.17.0.9:80/api/speedtest/latest'], $asked);
    }

    #[Test]
    public function aCustomNetworkThatDoesNotConnectNamesTheUnraidSetting(): void
    {
        $labels = self::labels(['type' => 'gotify', 'key' => 'k'], 'http://[IP]:[PORT:80]/');
        $inspect = self::inspect($labels, ['HostConfig' => ['NetworkMode' => 'br0']]);
        $inspect['NetworkSettings'] = ['Networks' => ['br0' => ['IPAddress' => '192.168.1.50']], 'Ports' => []];
        $transport = fn (array $requests) => array_map(fn () => ['status' => 0, 'body' => '', 'error' => 'connect'], $requests);
        $widgets = (new ServiceWidgets($transport, null))->forContainers(['g'], self::docker(['g' => $inspect], ['br0' => 'macvlan']));
        $this->assertSame('unreachable', $widgets['g']['error']);
        $this->assertStringContainsString('Host access to custom networks', $widgets['g']['message']);
    }
}
