<?php

namespace App\Tests\Controller;

use App\Controller\UnifiController;
use App\Service\HealthService;
use App\Service\Unifi\UnifiHistoryReader;
use App\Service\Unifi\UnifiInfraReader;
use App\Service\Unifi\UnifiFetcher;
use App\Service\Unifi\UnifiLiveReader;
use App\Tests\Service\Unifi\StubUnifiFetcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

#[AllowMockObjectsWithoutExpectations]
class UnifiControllerTest extends TestCase
{
    /**
     * The three readers are `final` (per the plan's Global Constraints), so
     * they cannot be doubled. Real readers are built instead, each over its own
     * UnifiFetcher double — an interface, and the seam that exists precisely so
     * the readers run with no network. A stub answering null to every path makes
     * read() return null, which is the shape these tests need; a fetcher that
     * throws makes read() throw, since read() has no try/catch of its own.
     *
     * Payload shapes are Tasks 4-6's business, asserted in their own tests.
     * This test covers gating, dispatch and error containment only.
     *
     * @param list<string> $throwOn group names whose reader throws
     */
    private function makeController(
        bool $configured = true,
        array $throwOn = [],
    ): UnifiController {
        $fetcher = function (string $group) use ($throwOn): UnifiFetcher {
            if (!in_array($group, $throwOn, true)) {
                return new StubUnifiFetcher();
            }
            $mock = $this->createMock(UnifiFetcher::class);
            $mock->method('fetch')->willThrowException(new \RuntimeException('upstream exploded'));
            return $mock;
        };

        $health = $this->createMock(HealthService::class);
        $health->method('isConfigured')->willReturnCallback(
            fn(string $s) => $s === 'unifi' ? $configured : false
        );

        // Render the template name back out instead of running real Twig: this
        // test covers gating, dispatch and error containment, not markup. Tasks
        // 8-11 own the templates and Task 12 renders them for real on :beta.
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(
            static fn(string $tpl, array $ctx = []): string => 'RENDERED:' . $tpl
        );

        $controller = new UnifiController(
            new UnifiLiveReader($fetcher('live'), new NullLogger()),
            new UnifiInfraReader($fetcher('infra'), new NullLogger()),
            new UnifiHistoryReader($fetcher('history'), new NullLogger()),
            $health,
            new NullLogger(),
        );

        // has() answers truthfully per id rather than blanket-true: json()
        // takes its serializer branch on has('serializer') and would then call
        // serialize() on the null get() returns. Only 'twig' is provided, so
        // only 'twig' exists.
        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => $id === 'twig'
        );
        $container->method('get')->willReturnCallback(
            fn(string $id) => $id === 'twig' ? $twig : null
        );
        $controller->setContainer($container);

        return $controller;
    }

    /**
     * #[IsGranted] is enforced by Symfony's attribute listener during HTTP
     * dispatch, not by the method body, so a directly-invoked method cannot
     * demonstrate the 403. Assert the guard is declared instead; the live 403
     * is verified end-to-end in Task 12.
     */
    public function testControllerIsGuardedByRoleAdmin(): void
    {
        $attrs = (new \ReflectionClass(UnifiController::class))
            ->getAttributes(\Symfony\Component\Security\Http\Attribute\IsGranted::class);

        $this->assertCount(1, $attrs, 'UnifiController must carry a class-level #[IsGranted]');
        $this->assertSame('ROLE_ADMIN', $attrs[0]->newInstance()->attribute);
    }

    public function testUnconfiguredConsoleAnswersEmptyNotAnError(): void
    {
        // The route guard normally redirects first; this is the belt-and-braces
        // path for a fragment fetched from an already-open tab after the admin
        // deletes the config. Empty body = "not applicable", per the poller's
        // documented contract.
        $c = $this->makeController(configured: false);
        $r = $c->panel('live');

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('', $r->getContent());
    }

    public function testUnknownPanelNameIsNotFoundNotAFatal(): void
    {
        $r = $this->makeController()->panel('wat');

        $this->assertSame(404, $r->getStatusCode());
    }

    public function testEachPanelRendersItsOwnTemplate(): void
    {
        $c = $this->makeController();

        $this->assertSame('RENDERED:unifi/_live.html.twig',    $c->panel('live')->getContent());
        $this->assertSame('RENDERED:unifi/_infra.html.twig',   $c->panel('infra')->getContent());
        $this->assertSame('RENDERED:unifi/_history.html.twig', $c->panel('history')->getContent());
    }

    public function testAThrowingReaderStillAnswersTwoHundred(): void
    {
        // The whole point: a monitoring page must not 500 because the thing it
        // monitors misbehaved. The template renders its empty state from null.
        $r = $this->makeController(throwOn: ['live'])->panel('live');

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('RENDERED:unifi/_live.html.twig', $r->getContent());
    }

    public function testBatchReturnsAMapAndIgnoresUnknownNames(): void
    {
        $c = $this->makeController();
        $r = $c->panels(new Request(['p' => 'live,wat,infra']));

        $map = json_decode((string) $r->getContent(), true);
        $this->assertSame(['live', 'infra'], array_keys($map));
        $this->assertSame('RENDERED:unifi/_live.html.twig', $map['live']);
    }

    public function testBatchDeduplicatesAndToleratesAnEmptyQuery(): void
    {
        $c = $this->makeController();

        $map = json_decode((string) $c->panels(new Request(['p' => 'live, live ']))->getContent(), true);
        $this->assertSame(['live'], array_keys($map));

        $this->assertSame([], json_decode((string) $c->panels(new Request())->getContent(), true));
    }

    public function testIndexRendersTheShellWithoutTouchingAnyReader(): void
    {
        // First paint must not block on cURL. Readers that throw would surface
        // here if the shell called them; the shell must render regardless.
        $r = $this->makeController(throwOn: ['live', 'infra', 'history'])->index();

        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('RENDERED:unifi/index.html.twig', $r->getContent());
    }
}
