<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use App\EventSubscriber\LocaleSubscriber;
use App\Service\ConfigService;
use App\Service\HealthService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/setup')]
class SetupController extends AbstractController
{
    public const SETUP_DONE_KEY = 'setup_completed';

    public function __construct(
        private readonly UserRepository $users,
        private readonly SettingRepository $settings,
        private readonly ConfigService $config,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_setup_root')]
    public function root(): Response
    {
        if ($this->settings->get(self::SETUP_DONE_KEY) === '1') {
            return $this->redirectToRoute('app_home');
        }
        return $this->redirectToRoute($this->users->count([]) === 0
            ? 'app_setup_welcome'
            : 'app_setup_tmdb');
    }

    // ─── Step 1: Welcome ───────────────────────────────────────────────────

    #[Route('/welcome', name: 'app_setup_welcome')]
    public function welcome(Request $request): Response
    {
        return $this->render('setup/welcome.html.twig', [
            'active_step'      => 'welcome',
            'completed_steps'  => $this->completedSteps(),
            'current_locale'   => $request->getLocale(),
            'supported_locales' => LocaleSubscriber::SUPPORTED,
        ]);
    }

    /**
     * Stores a UI locale in the session so the wizard (and the rest of the app
     * until DB-backed `display_language` takes over) renders in the user's
     * preferred language. Called via fetch from welcome.html.twig.
     */
    #[Route('/locale', name: 'app_setup_locale', methods: ['POST'])]
    public function locale(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('setup_locale', (string) $request->request->get('_csrf_token'))) {
            return new Response('', 400);
        }

        $picked = (string) $request->request->get('locale', '');
        if (!in_array($picked, LocaleSubscriber::SUPPORTED, true)) {
            return new Response('', 400);
        }

        $request->getSession()->set(LocaleSubscriber::SESSION_KEY, $picked);

        return $this->redirectToRoute('app_setup_welcome');
    }

    // ─── Step 2: Admin account (required) ──────────────────────────────────

    #[Route('/admin', name: 'app_setup_admin', methods: ['GET', 'POST'])]
    public function admin(
        Request $request,
        UserPasswordHasherInterface $hasher,
        Security $security,
    ): Response {
        if ($this->users->count([]) > 0) {
            // Admin already created: move on without going through this step again.
            return $this->redirectToRoute('app_setup_tmdb');
        }

        $errors = [];
        $email = trim((string) $request->request->get('email', ''));
        $displayName = trim((string) $request->request->get('display_name', ''));
        $password = (string) $request->request->get('password', '');
        $passwordConfirm = (string) $request->request->get('password_confirm', '');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('setup_admin', (string) $request->request->get('_csrf_token'))) {
                $errors[] = $this->translator->trans('setup.error.csrf');
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = $this->translator->trans('setup.error.email_invalid');
            }
            if (strlen($password) < 8) {
                $errors[] = $this->translator->trans('setup.error.password_too_short');
            }
            if ($password !== $passwordConfirm) {
                $errors[] = $this->translator->trans('setup.error.password_mismatch');
            }

            if ($errors === []) {
                $user = new User();
                $user->setEmail($email);
                $user->setDisplayName($displayName !== '' ? $displayName : null);
                $user->setRoles(['ROLE_ADMIN']);
                $user->setPassword($hasher->hashPassword($user, $password));

                try {
                    $this->em->persist($user);
                    $this->em->flush();
                } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                    return $this->redirectToRoute('app_login');
                }

                $security->login($user, 'form_login', 'main');

                return $this->redirectToRoute('app_setup_tmdb');
            }
        }

        return $this->render('setup/admin.html.twig', [
            'active_step'     => 'admin',
            'completed_steps' => $this->completedSteps(),
            'errors'          => $errors,
            'email'           => $email,
            'display_name'    => $displayName,
        ]);
    }

    // ─── Step 3: TMDb (optional) ───────────────────────────────────────────

    #[Route('/tmdb', name: 'app_setup_tmdb', methods: ['GET', 'POST'])]
    public function tmdb(Request $request): Response
    {
        if ($redirect = $this->guardSetupNotCompleted()) {
            return $redirect;
        }
        if ($redirect = $this->guardAdminExists()) {
            return $redirect;
        }

        $fields = ['tmdb_api_key' => ''];
        $this->prefill($fields);
        $errors = [];

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('_action', 'save');
            $this->validateCsrf($request, 'setup_tmdb', $errors);
            $fields['tmdb_api_key'] = trim((string) $request->request->get('tmdb_api_key', ''));

            if ($errors === []) {
                $this->save($fields, skip: $action === 'skip');
                return $this->redirectToRoute($this->nextRoute($action, 'app_setup_managers', 'app_setup_admin'));
            }
        }

        return $this->render('setup/tmdb.html.twig', [
            'active_step'     => 'tmdb',
            'completed_steps' => $this->completedSteps(),
            'errors'          => $errors,
            'values'          => $fields,
        ]);
    }

    // ─── Step 4: Media managers (Radarr + Sonarr) ──────────────────────────

    #[Route('/managers', name: 'app_setup_managers', methods: ['GET', 'POST'])]
    public function managers(Request $request): Response
    {
        if ($redirect = $this->guardSetupNotCompleted()) {
            return $redirect;
        }
        if ($redirect = $this->guardAdminExists()) {
            return $redirect;
        }

        $fields = [
            'radarr_url' => 'http://host.docker.internal:7878',
            'radarr_api_key' => '',
            'sonarr_url' => 'http://host.docker.internal:8989',
            'sonarr_api_key' => '',
        ];
        $this->prefill($fields);
        $errors = [];

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('_action', 'save');
            $this->validateCsrf($request, 'setup_managers', $errors);
            foreach (array_keys($fields) as $k) {
                $fields[$k] = trim((string) $request->request->get($k, ''));
            }

            if ($errors === []) {
                $this->save($fields, skip: $action === 'skip');
                return $this->redirectToRoute($this->nextRoute($action, 'app_setup_indexers', 'app_setup_tmdb'));
            }
        }

        return $this->render('setup/managers.html.twig', [
            'active_step'     => 'managers',
            'completed_steps' => $this->completedSteps(),
            'errors'          => $errors,
            'values'          => $fields,
        ]);
    }

    // ─── Step 5: Indexers & requests (Prowlarr + Jellyseerr) ───────────────

    #[Route('/indexers', name: 'app_setup_indexers', methods: ['GET', 'POST'])]
    public function indexers(Request $request): Response
    {
        if ($redirect = $this->guardSetupNotCompleted()) {
            return $redirect;
        }
        if ($redirect = $this->guardAdminExists()) {
            return $redirect;
        }

        $fields = [
            'prowlarr_url' => 'http://host.docker.internal:9696',
            'prowlarr_api_key' => '',
            'jellyseerr_url' => 'http://host.docker.internal:5055',
            'jellyseerr_api_key' => '',
        ];
        $this->prefill($fields);
        $errors = [];

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('_action', 'save');
            $this->validateCsrf($request, 'setup_indexers', $errors);
            foreach (array_keys($fields) as $k) {
                $fields[$k] = trim((string) $request->request->get($k, ''));
            }

            if ($errors === []) {
                $this->save($fields, skip: $action === 'skip');
                return $this->redirectToRoute($this->nextRoute($action, 'app_setup_downloads', 'app_setup_managers'));
            }
        }

        return $this->render('setup/indexers.html.twig', [
            'active_step'     => 'indexers',
            'completed_steps' => $this->completedSteps(),
            'errors'          => $errors,
            'values'          => $fields,
        ]);
    }

    // ─── Step 6: Downloads (qBittorrent + Gluetun) ─────────────────────────

    #[Route('/downloads', name: 'app_setup_downloads', methods: ['GET', 'POST'])]
    public function downloads(Request $request): Response
    {
        if ($redirect = $this->guardSetupNotCompleted()) {
            return $redirect;
        }
        if ($redirect = $this->guardAdminExists()) {
            return $redirect;
        }

        $fields = [
            'qbittorrent_url' => 'http://host.docker.internal:8080',
            'qbittorrent_user' => 'admin',
            'qbittorrent_password' => '',
            'gluetun_url' => '',
            'gluetun_api_key' => '',
            'gluetun_protocol' => '',
        ];
        $this->prefill($fields);
        $errors = [];

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('_action', 'save');
            $this->validateCsrf($request, 'setup_downloads', $errors);
            foreach (array_keys($fields) as $k) {
                $fields[$k] = trim((string) $request->request->get($k, ''));
            }

            if ($errors === []) {
                $this->save($fields, skip: $action === 'skip');
                return $this->redirectToRoute($this->nextRoute($action, 'app_setup_finish', 'app_setup_indexers'));
            }
        }

        return $this->render('setup/downloads.html.twig', [
            'active_step'     => 'downloads',
            'completed_steps' => $this->completedSteps(),
            'errors'          => $errors,
            'values'          => $fields,
        ]);
    }

    // ─── AJAX endpoint: Test connection (called from each step's "Test" button) ───

    /**
     * Probe a single service against the values currently typed in the wizard
     * form (no DB write). Returns a strict JSON envelope `{ok, category}` —
     * deliberately minimal to prevent leaking the upstream response body, the
     * URL probed, or the API key submitted.
     *
     * Defense-in-depth:
     *  - IsGranted ROLE_USER: closes the small window between image start and
     *    `setup_completed=1` where /setup/* is otherwise PUBLIC_ACCESS. The
     *    test buttons only appear from step 3 onward (after the admin is
     *    created at step 2 + auto-logged-in via $security->login), so this
     *    never blocks a legitimate user.
     *  - guardSetupNotCompleted(): 403 once setup is finished, so a logged-in
     *    user can't keep scanning the LAN through this endpoint after install
     *  - CSRF token bound to the service name: prevents cross-origin form posts
     *  - service whitelist via Symfony route requirements
     *  - field whitelist per service inside collectTestFields()
     *  - rate limiter (30/min per IP): neuters scripted port-scan attempts
     *  - HealthService::httpProbe() applies CURLOPT_PROTOCOLS + link-local
     *    blocklist so the actual cURL can't reach file:// / cloud-metadata
     *
     * Trade-off: a legitimate user typing fast may hit the rate limit. The
     * limit is generous (30/min, sliding window) and the JSON envelope tells
     * the front-end exactly which category triggered it.
     */
    #[Route(
        '/test/{service}',
        name: 'app_setup_test',
        requirements: ['service' => 'tmdb|radarr|sonarr|prowlarr|jellyseerr|qbittorrent'],
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_USER')]
    public function testService(
        string $service,
        Request $request,
        HealthService $health,
        // Bound explicitly to the framework.yaml-declared limiter. Symfony
        // 7+ does not auto-resolve `RateLimiterFactory $xxxLimiter` against
        // `limiter.xxx` without an explicit Autowire / alias.
        #[Autowire(service: 'limiter.setup_test')] RateLimiterFactory $setupTestLimiter,
    ): JsonResponse {
        if ($this->guardSetupNotCompleted() !== null) {
            return $this->testResponse(false, 'forbidden', 403);
        }
        if (!$this->isCsrfTokenValid('setup_test_' . $service, (string) $request->request->get('_csrf_token'))) {
            return $this->testResponse(false, 'csrf', 400);
        }

        // One bucket per (client IP × service) so a stuck Radarr probe doesn't
        // burn the budget for the user's still-valid Sonarr probe.
        $limiter = $setupTestLimiter->create(($request->getClientIp() ?? 'unknown') . '|' . $service);
        if (!$limiter->consume(1)->isAccepted()) {
            return $this->testResponse(false, 'rate_limited', 429);
        }

        $overrides = $this->collectTestFields($service, $request);
        $result = $health->diagnose($service, $overrides);

        return $this->testResponse((bool) $result['ok'], (string) $result['category'], 200);
    }

    /**
     * Build a minimal JSON envelope with strict no-store cache headers. We
     * never echo back the URL or the API key; the front-end only learns
     * whether the probe succeeded and which broad category failed.
     */
    private function testResponse(bool $ok, string $category, int $status): JsonResponse
    {
        $resp = new JsonResponse(['ok' => $ok, 'category' => $category], $status);
        $resp->headers->set('Cache-Control', 'no-store, no-cache, private, max-age=0');
        $resp->headers->set('Pragma', 'no-cache');
        $resp->headers->set('X-Content-Type-Options', 'nosniff');
        return $resp;
    }

    /**
     * Whitelist of form fields read for each service. We deliberately do NOT
     * use $request->request->all() to avoid surprises from unexpected fields
     * sneaking into HealthService::probeFor().
     *
     * @return array<string, string>
     */
    private function collectTestFields(string $service, Request $request): array
    {
        $fieldsPerService = [
            'tmdb'        => ['tmdb_api_key'],
            'radarr'      => ['radarr_url', 'radarr_api_key'],
            'sonarr'      => ['sonarr_url', 'sonarr_api_key'],
            'prowlarr'    => ['prowlarr_url', 'prowlarr_api_key'],
            'jellyseerr'  => ['jellyseerr_url', 'jellyseerr_api_key'],
            'qbittorrent' => ['qbittorrent_url', 'qbittorrent_user', 'qbittorrent_password'],
        ];
        $out = [];
        foreach ($fieldsPerService[$service] ?? [] as $f) {
            $out[$f] = trim((string) $request->request->get($f, ''));
        }
        return $out;
    }

    // ─── Step 7: Finalization ──────────────────────────────────────────────

    #[Route('/finish', name: 'app_setup_finish', methods: ['GET', 'POST'])]
    public function finish(Request $request): Response
    {
        if ($redirect = $this->guardSetupNotCompleted()) {
            return $redirect;
        }
        if ($redirect = $this->guardAdminExists()) {
            return $redirect;
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('setup_finish', (string) $request->request->get('_csrf_token'))) {
                return $this->redirectToRoute('app_setup_finish');
            }

            // Promote the session-only locale picked at /setup/welcome into the
            // permanent admin pref `display_language`, then drop the session key
            // so the DB becomes the single source of truth from now on.
            // Without this, changing the language from /admin/settings would not
            // appear to take effect until the session expires (the session-stored
            // locale takes priority over the DB pref in LocaleSubscriber).
            $session = $request->getSession();
            $sessionLocale = $session->get(LocaleSubscriber::SESSION_KEY);
            if (is_string($sessionLocale) && in_array($sessionLocale, LocaleSubscriber::SUPPORTED, true)) {
                $this->settings->set('display_language', $sessionLocale);
                $session->remove(LocaleSubscriber::SESSION_KEY);
            }

            $this->settings->set(self::SETUP_DONE_KEY, '1');
            $this->config->invalidate();
            $this->addFlash('success', $this->translator->trans('setup.flash.welcome'));
            return $this->redirectToRoute('app_home');
        }

        return $this->render('setup/finish.html.twig', [
            'active_step'     => 'finish',
            'completed_steps' => $this->completedSteps(),
            'services'        => $this->serviceSummary(),
        ]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function guardAdminExists(): ?RedirectResponse
    {
        if ($this->users->count([]) === 0) {
            return $this->redirectToRoute('app_setup_admin');
        }

        return null;
    }

    /**
     * Once setup_completed=1, the wizard pages must NOT be reachable anymore:
     * they pre-render API keys / passwords in <input value="..."> for the
     * "Back" button UX, so leaving them publicly accessible after the install
     * leaks credentials. Anyone who legitimately needs to re-configure goes
     * through /admin/settings (auth-protected by ROLE_ADMIN).
     */
    private function guardSetupNotCompleted(): ?RedirectResponse
    {
        if ($this->settings->get(self::SETUP_DONE_KEY) === '1') {
            return $this->redirectToRoute('app_home');
        }

        return null;
    }

    /**
     * Field name suffixes that must NEVER be pre-rendered into a wizard
     * <input value="...">. Defense-in-depth on top of guardSetupNotCompleted():
     * even if the redirect ever gets bypassed, the HTML emitted by the wizard
     * never contains the actual secret. The user has to re-paste them when
     * navigating back through the wizard, which is acceptable on a one-time
     * install flow.
     */
    private const SENSITIVE_KEY_SUFFIXES = ['_api_key', '_password', '_secret', '_token'];

    /**
     * @param array<string, string> $fields Reference: populated from DB if the key exists.
     */
    private function prefill(array &$fields): void
    {
        foreach ($fields as $key => $default) {
            if ($this->isSensitiveKey($key)) {
                continue;
            }
            $stored = $this->config->get($key);
            if ($stored !== null) {
                $fields[$key] = $stored;
            }
        }
    }

    private function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEY_SUFFIXES as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Persists to DB; `skip` = write nulls to intentionally mark as empty.
     * @param array<string, string> $fields
     */
    private function save(array $fields, bool $skip): void
    {
        $payload = [];
        foreach ($fields as $key => $value) {
            if ($skip) {
                $payload[$key] = null;
            } else {
                $payload[$key] = $value !== '' ? $value : null;
            }
        }
        $this->settings->setMany($payload);
        $this->config->invalidate();
    }

    private function validateCsrf(Request $request, string $id, array &$errors): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_csrf_token'))) {
            $errors[] = $this->translator->trans('setup.error.csrf');
        }
    }

    private function nextRoute(string $action, string $forward, string $back): string
    {
        return $action === 'back' ? $back : $forward;
    }

    /**
     * @return list<string>
     */
    private function completedSteps(): array
    {
        $done = [];
        if ($this->users->count([]) > 0) {
            $done[] = 'welcome';
            $done[] = 'admin';
        }
        $checks = [
            'tmdb'      => ['tmdb_api_key'],
            'managers'  => ['radarr_api_key', 'sonarr_api_key'],
            'indexers'  => ['prowlarr_api_key', 'jellyseerr_api_key'],
            'downloads' => ['qbittorrent_url'],
        ];
        foreach ($checks as $step => $keys) {
            foreach ($keys as $k) {
                if ($this->config->has($k)) { $done[] = $step; break; }
            }
        }
        return $done;
    }

    /**
     * @return list<array{name: string, configured: bool, detail: ?string}>
     */
    private function serviceSummary(): array
    {
        return [
            $this->summaryRow('TMDb',        'tmdb_api_key'),
            $this->summaryRow('Radarr',      'radarr_url'),
            $this->summaryRow('Sonarr',      'sonarr_url'),
            $this->summaryRow('Prowlarr',    'prowlarr_url'),
            $this->summaryRow('Jellyseerr',  'jellyseerr_url'),
            $this->summaryRow('qBittorrent', 'qbittorrent_url'),
            $this->summaryRow('Gluetun',     'gluetun_url'),
        ];
    }

    /**
     * @return array{name: string, configured: bool, detail: ?string}
     */
    private function summaryRow(string $name, string $key): array
    {
        $value = $this->config->get($key);
        return [
            'name'       => $name,
            'configured' => $value !== null,
            'detail'     => $value,
        ];
    }
}
