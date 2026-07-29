<?php

namespace App\Controller;

use App\Dashboard\NetworkUsageChart;
use App\Dashboard\SpeedtestChart;
use App\Service\HealthService;
use App\Service\Unifi\UnifiHistoryReader;
use App\Service\Unifi\UnifiInfraReader;
use App\Service\Unifi\UnifiLiveReader;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin-only Network tab. WAN addresses, per-client traffic and reservation
 * detail are not for regular users, so ROLE_ADMIN gates the whole controller —
 * the same stance the dashboard's network widget takes.
 *
 * Three panels, three cadences, one endpoint hit per cycle no matter how many
 * panels consume it (see the readers in App\Service\Unifi). The shell renders
 * with no upstream call at all, so first paint never waits on the console.
 *
 * Every action is read-only and every action answers 200. A monitoring page
 * that 500s because the monitored thing is sick is worse than useless.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/unifi', name: 'app_unifi_')]
class UnifiController extends AbstractController
{
    /** Group name → template. Also the whitelist for both fragment routes. */
    private const PANELS = [
        'live'    => 'unifi/_live.html.twig',
        'infra'   => 'unifi/_infra.html.twig',
        'history' => 'unifi/_history.html.twig',
    ];

    public function __construct(
        private readonly UnifiLiveReader $live,
        private readonly UnifiInfraReader $infra,
        private readonly UnifiHistoryReader $history,
        private readonly HealthService $health,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Page shell only — deliberately touches no reader, so the page paints
     * immediately and each region hydrates from its own fragment after paint.
     */
    #[Route('', name: 'index')]
    public function index(): Response
    {
        return $this->render('unifi/index.html.twig');
    }

    /**
     * Batch fragment fetch for the page poller: `?p=live,infra` → a
     * {group: html} JSON map. Declared BEFORE the {panel} route because
     * `/unifi/api/panels` would otherwise match it.
     *
     * A group that renders empty is omitted from the map rather than sent as
     * "", so the client leaves that region at its last-good content — the same
     * contract as the dashboard's /widgets route.
     */
    #[Route('/api/panels', name: 'panels')]
    public function panels(Request $request): Response
    {
        set_time_limit(60);

        $names = array_filter(array_map('trim', explode(',', (string) $request->query->get('p', ''))));
        $out = [];
        foreach (array_unique($names) as $name) {
            if (!isset(self::PANELS[$name])) continue;
            $html = (string) $this->renderPanel($name)->getContent();
            if (trim($html) === '') continue;
            $out[$name] = $html;
        }

        return $this->json($out);
    }

    /** One panel as an HTML fragment, for first hydration and for retry. */
    #[Route('/api/{panel}', name: 'panel', requirements: ['panel' => 'live|infra|history'])]
    public function panel(string $panel): Response
    {
        if (!isset(self::PANELS[$panel])) {
            // Unreachable through routing (the requirement filters it), but the
            // method is called directly in tests and could be called from
            // another action later.
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        set_time_limit(60);

        return $this->renderPanel($panel);
    }

    /**
     * Render one group. An empty body means "not applicable" and the client
     * hides the region; a rendered template with a null payload means "we
     * looked and there was nothing", which the template shows as an empty
     * state with a retry button. Those are different answers and the
     * difference is load-bearing.
     */
    private function renderPanel(string $panel): Response
    {
        if (!$this->health->isConfigured('unifi')) {
            return new Response('');
        }

        try {
            $data = match ($panel) {
                'live'    => $this->live->read(),
                'infra'   => $this->infra->read(),
                'history' => $this->history->read(),
            };
        } catch (\Throwable $e) {
            // Never propagate: this is a monitoring page.
            $this->logger->warning('UniFi panel {panel} failed: {message}', [
                'panel'   => $panel,
                'message' => $e->getMessage(),
            ]);
            $data = null;
        }

        return $this->render(self::PANELS[$panel], $this->contextFor($panel, $data));
    }

    /**
     * Twig gets one variable named after the group, plus — for history — the
     * two pre-built chart geometries. Chart building lives here rather than in
     * the template so the templates stay logic-free and the geometry stays
     * unit-testable.
     *
     * @return array<string, mixed>
     */
    private function contextFor(string $panel, ?array $data): array
    {
        if ($panel !== 'history') {
            return [$panel => $data];
        }

        return [
            'history' => $data,
            // Day name + date + hour for the 7-day view; the widget's 24h chart
            // keeps the default 'H:i'. One format feeds both the per-point hover
            // labels and the two axis labels, which rules out a bare 'D': seven
            // days apart is the same weekday, so the axis read "Sat … Sat" and
            // all 24 points in a day shared one tooltip (live-verified). The date
            // disambiguates the axis; the hour makes hovering a spike informative.
            'usageChart' => NetworkUsageChart::build($data['usage7d'] ?? null, 'D j H:i'),
            'speedChart' => SpeedtestChart::build($data['speedtests'] ?? null),
        ];
    }
}
