<?php

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Service\Media\TransmissionClient;
use App\Tests\AbstractWebTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression for the rebuilt Transmission template: nothing else in CI ever
 * actually renders `templates/transmission/index.html.twig`.
 * ControllersSmokeTest requests /transmission, but ServiceRouteGuardSubscriber
 * redirects app_transmission_* to app_setup_downloads whenever transmission_url
 * is unset (always true under AbstractWebTestCase) — the smoke test only
 * asserts status < 500 || 503, so the 302 passes and the template body is
 * never parsed. Four raw dotted translation keys (transmission.title,
 * transmission.filters.labels, transmission.table.uploaded,
 * transmission.table.completed) nearly shipped as a result; only a hand
 * check caught it.
 *
 * This test seeds transmission_url so the guard passes, swaps in a mocked
 * TransmissionClient covering every state TransmissionClient::normalizeState()
 * can emit, renders the page in both app locales, and scans the HTML for any
 * unresolved `qbittorrent.*` / `transmission.*` / `deluge.*` translation key
 * (Twig's `trans` filter renders a missing key as the literal id — that raw
 * dotted string is exactly what leaked last time).
 */
#[AllowMockObjectsWithoutExpectations]
class TransmissionRenderTest extends AbstractWebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'english' => ['en'];
        yield 'french'  => ['fr'];
    }

    #[DataProvider('locales')]
    public function testIndexRendersCleanlyWithNoLeakedTranslationKeys(string $locale): void
    {
        $this->configureTransmission();

        $this->client->request('GET', '/transmission?_locale=' . $locale);
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        if (preg_match('/\b(qbittorrent|transmission|deluge)\.[a-z_.]+/', $html, $m)) {
            $this->fail(sprintf(
                'Locale "%s": rendered page contains an unresolved translation key "%s" — it has no entry in either messages YAML and rendered as the raw id instead of translated text.',
                $locale,
                $m[0]
            ));
        }
    }

    /**
     * Seed transmission_url (so ServiceRouteGuardSubscriber's "not configured"
     * check passes) and swap the autowired TransmissionClient for a mock
     * populated with one torrent per state TransmissionClient::normalizeState()
     * can emit, so every state-dependent template branch actually executes.
     */
    private function configureTransmission(): void
    {
        $em = $this->em();
        $em->persist(new Setting('transmission_url', 'http://transmission.test:9091'));
        $em->flush();

        $mock = $this->createMock(TransmissionClient::class);
        $mock->method('getVersion')->willReturn('4.0.5');

        $states = ['error', 'paused', 'checking', 'queued', 'downloading', 'seeding', 'unknown'];
        $torrents = [];
        foreach ($states as $i => $state) {
            $torrents[] = $this->torrent($state, $i);
        }
        $mock->method('getTorrents')->willReturn($torrents);

        $mock->method('getStats')->willReturn([
            'total'        => count($torrents),
            'downloading'  => 1,
            'seeding'      => 1,
            'paused'       => 1,
            'completed'    => 0,
            'errored'      => 1,
            'stalled'      => 0,
            'dl_speed'     => 1024,
            'up_speed'     => 512,
            'connection'   => 'connected',
            'dht_nodes'    => 0,
            'dl_session'   => 10_000,
            'up_session'   => 5_000,
            'dl_alltime'   => 100_000,
            'up_alltime'   => 50_000,
            'global_ratio' => 0.5,
            'free_space'   => 1_000_000_000,
        ]);

        static::getContainer()->set(TransmissionClient::class, $mock);
    }

    /** @return array<string, mixed> */
    private function torrent(string $state, int $i): array
    {
        return [
            'hash'           => str_pad((string) $i, 40, 'a'),
            'name'           => 'Torrent.' . $state,
            'size'           => 1_000_000_000,
            'total_size'     => 1_000_000_000,
            'downloaded'     => 500_000_000,
            'uploaded'       => 250_000_000,
            'progress'       => 50.0,
            'dlspeed'        => 1024,
            'upspeed'        => 512,
            'eta'            => 3600,
            'state'          => $state,
            'raw_state'      => '0',
            'category'       => $i % 2 === 0 ? 'radarr' : 'sonarr',
            'tags'           => '',
            'ratio'          => 1.5,
            'num_seeds'      => 3,
            'num_leechs'     => 2,
            'num_complete'   => 0,
            'num_incomplete' => 0,
            'added_on'       => 1_700_000_000,
            'completion_on'  => 1_700_001_000,
            'save_path'      => '/downloads',
            'content_path'   => '',
            'tracker'        => 'tracker.example',
            'dl_limit'       => -1,
            'up_limit'       => -1,
            'seeding_time'   => 3600,
            'priority'       => 0,
            'availability'   => 1.0,
        ];
    }
}
