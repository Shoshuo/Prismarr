<?php

namespace App\Tests\Controller;

use App\Controller\SetupController;
use App\Entity\Setting;
use App\Entity\User;
use App\Service\Media\JellyseerrClient;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Renders the read-only Seerr Issues page (upstream #133) for real.
 *
 * Without seeded jellyseerr_url/jellyseerr_api_key, ServiceRouteGuardSubscriber
 * 302s before the controller runs, so nothing else in CI ever parses
 * templates/jellyseerr/issues.html.twig. And since /jellyseerr/issues is not
 * the section index, the guard ALSO health-checks Seerr via
 * JellyseerrClient::ping() — so the client stub has to be in place before the
 * container builds the real one. Like BazarrFrameRenderTest (whose docblock
 * records why), this class therefore does its own setup instead of extending
 * AbstractWebTestCase, installing the stub before the first Doctrine flush.
 */
#[AllowMockObjectsWithoutExpectations]
class JellyseerrIssuesRenderTest extends WebTestCase
{
    private KernelBrowser $client;

    /** @param callable(JellyseerrClient&MockObject): void $configure */
    private function boot(callable $configure): void
    {
        $this->client = static::createClient();

        $em = static::getContainer()->get('doctrine')->getManager();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $seerr = $this->createMock(JellyseerrClient::class);
        $seerr->method('ping')->willReturn(true);
        $configure($seerr);
        static::getContainer()->set(JellyseerrClient::class, $seerr);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = new User();
        $admin->setEmail('admin@test.local');
        $admin->setDisplayName('Admin Test');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($hasher->hashPassword($admin, 'admin-password'));
        $em->persist($admin);
        $em->persist(new Setting(SetupController::SETUP_DONE_KEY, '1'));
        $em->persist(new Setting('jellyseerr_url', 'http://seerr.test:5055'));
        $em->persist(new Setting('jellyseerr_api_key', 'k'));
        $em->flush();

        $this->client->loginUser($admin);
        $this->client->disableReboot();
    }

    /** @return array<string, mixed> */
    private static function issue(int $id, array $overrides = []): array
    {
        return array_replace([
            'id'             => $id,
            'issueType'      => 1,
            'status'         => 1,
            'problemSeason'  => 0,
            'problemEpisode' => 0,
            'createdAt'      => '2026-11-01T10:00:00.000Z',
            'updatedAt'      => '2026-11-02T10:00:00.000Z',
            'media'          => ['id' => 9, 'tmdbId' => 550, 'mediaType' => 'movie'],
            'createdBy'      => ['id' => 2, 'displayName' => 'Alice', 'avatar' => '/avatarproxy/abc'],
            'comments'       => [['id' => 1, 'message' => 'Picture freezes at 20 min']],
        ], $overrides);
    }

    /** @param list<array<string, mixed>> $issues */
    private function seerrWith(array $issues, ?callable $extra = null): callable
    {
        return function (JellyseerrClient&MockObject $seerr) use ($issues, $extra): void {
            $seerr->method('getAbout')->willReturn(['version' => '2.0.0']);
            $seerr->method('getIssueCount')->willReturn([
                'total' => 5, 'video' => 2, 'audio' => 1, 'subtitles' => 1, 'others' => 1, 'open' => 3, 'closed' => 2,
            ]);
            $seerr->method('searchMovie')->willReturn(['title' => 'Fight Club', 'posterPath' => '/p.jpg', 'releaseDate' => '1999-10-15']);
            $seerr->method('searchTv')->willReturn(['name' => 'Severance', 'posterPath' => null, 'firstAirDate' => '2022-02-18']);
            if ($extra !== null) {
                $extra($seerr);
            } else {
                $seerr->method('getIssues')->willReturn([
                    'pageInfo' => ['pages' => 1, 'pageSize' => 20, 'results' => count($issues), 'page' => 1],
                    'results'  => $issues,
                ]);
            }
        };
    }

    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'english' => ['en'];
        yield 'french'  => ['fr'];
    }

    #[DataProvider('locales')]
    public function testRendersEveryIssueShapeWithNoLeakedTranslationKeys(string $locale): void
    {
        $this->boot($this->seerrWith([
            self::issue(1),
            self::issue(2, [
                'issueType' => 3, 'status' => 2, 'problemSeason' => 2, 'problemEpisode' => 5,
                'media'     => ['tmdbId' => 95396, 'mediaType' => 'tv'],
                'createdBy' => ['displayName' => 'Bob', 'avatar' => 'https://plex.tv/users/x/avatar'],
                'comments'  => [['id' => 7, 'message' => 'Subs out of sync'], ['id' => 8, 'message' => 'Fixed'], ['id' => 9, 'message' => 'Thanks']],
            ]),
            self::issue(3, ['issueType' => 2, 'problemSeason' => 1, 'media' => ['tmdbId' => 95396, 'mediaType' => 'tv'], 'comments' => []]),
            self::issue(4, ['issueType' => 4, 'media' => [], 'createdBy' => null]),
        ]));

        $this->client->request('GET', '/jellyseerr/issues?_locale=' . $locale);
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        // Scoped to this page's own namespaces: base.html.twig ships a JS
        // i18n dict keyed by raw ids ('dashboard.quicklook.manage': ...).
        if (preg_match('/\bjellyseerr\.(issues|nav|common)\.[a-z_]+/', $html, $m)) {
            $this->fail(sprintf('Locale "%s": unresolved translation key "%s" rendered as its raw id.', $locale, $m[0]));
        }
        $this->assertStringContainsString('Fight Club', $html);
        $this->assertStringContainsString('Severance', $html);
        $this->assertStringContainsString('Picture freezes at 20 min', $html, 'the first comment is the description');
        $this->assertStringContainsString('S2 · E5', $html);
        $this->assertStringContainsString('http://seerr.test:5055/issues/1', $html, 'each card links to the issue in Seerr');
        $this->assertStringContainsString('data-ql-id="550"', $html, 'the title opens the global quick-look');
        $this->assertStringNotContainsString('plex.tv/users', $html, 'off-origin avatars are dropped (CSP would block them)');
    }

    public function testReporterTextIsEscaped(): void
    {
        $this->boot($this->seerrWith([
            self::issue(1, ['comments' => [['id' => 1, 'message' => '<script>alert(1)</script>']]]),
        ]));

        $this->client->request('GET', '/jellyseerr/issues');
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testEachTitleIsLookedUpOncePerPage(): void
    {
        $this->boot(function (JellyseerrClient&MockObject $seerr): void {
            $seerr->method('getAbout')->willReturn(['version' => '2.0.0']);
            $seerr->method('getIssueCount')->willReturn(['open' => 3]);
            $seerr->method('getIssues')->willReturn(['pageInfo' => ['pages' => 1], 'results' => [
                self::issue(1), self::issue(2), self::issue(3),
            ]]);
            $seerr->expects($this->once())->method('searchMovie')->with(550)->willReturn(['title' => 'Fight Club']);
        });

        $this->client->request('GET', '/jellyseerr/issues');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(3, '[data-issue-id]');
    }

    public function testUnknownFilterFallsBackToOpen(): void
    {
        $this->boot($this->seerrWith([], function (JellyseerrClient&MockObject $seerr): void {
            $seerr->expects($this->once())->method('getIssues')
                ->with(20, 20, 'open', 'added')
                ->willReturn(['pageInfo' => ['pages' => 1], 'results' => []]);
        }));

        $this->client->request('GET', '/jellyseerr/issues?filter=bogus&sort=bogus&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('No open issues.', (string) $this->client->getResponse()->getContent());
    }

    #[DataProvider('locales')]
    public function testDashboardRequestsCardShowsTheOpenIssueRow(string $locale): void
    {
        $this->boot(function (JellyseerrClient&MockObject $seerr): void {
            $seerr->method('getRequests')->willReturn(['pageInfo' => [], 'results' => []]);
            $seerr->method('getIssueCount')->willReturn(['open' => 3, 'closed' => 1, 'total' => 4]);
        });

        $this->client->request('GET', '/tableau-de-bord/widget/requests?_locale=' . $locale);
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('a[data-dash-open-issues][href$="/jellyseerr/issues"]');
        $this->assertStringContainsString($locale === 'fr' ? '3 signalements ouverts' : '3 open issues reported', $html);
        if (preg_match('/\bdashboard\.requests\.[a-z_]+/', $html, $m)) {
            $this->fail(sprintf('Locale "%s": unresolved translation key "%s".', $locale, $m[0]));
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function noOpenIssues(): iterable
    {
        yield 'zero open'      => [['open' => 0, 'closed' => 2, 'total' => 2]];
        yield 'seerr failed'   => [[]];
        yield 'client threw'   => [new \RuntimeException('boom')];
    }

    #[DataProvider('noOpenIssues')]
    public function testDashboardRequestsCardHidesTheRowWithNothingOpen(mixed $counts): void
    {
        $this->boot(function (JellyseerrClient&MockObject $seerr) use ($counts): void {
            $seerr->method('getRequests')->willReturn(['pageInfo' => [], 'results' => []]);
            $counts instanceof \Throwable
                ? $seerr->method('getIssueCount')->willThrowException($counts)
                : $seerr->method('getIssueCount')->willReturn($counts);
        });

        $this->client->request('GET', '/tableau-de-bord/widget/requests');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-dash-open-issues]');
        $this->assertSelectorExists('.empty', 'the usual empty state still renders');
    }

    public function testUnreachableSeerrShowsTheServiceBanner(): void
    {
        $this->boot(function (JellyseerrClient&MockObject $seerr): void {
            $seerr->method('getAbout')->willReturn(null);
            $seerr->expects($this->never())->method('getIssues');
        });

        $this->client->request('GET', '/jellyseerr/issues');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-issue-id]');
    }
}
