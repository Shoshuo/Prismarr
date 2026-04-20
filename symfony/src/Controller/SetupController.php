<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use App\Service\ConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/setup')]
class SetupController extends AbstractController
{
    public const SETUP_DONE_KEY = 'setup_completed';

    public function __construct(
        private readonly UserRepository $users,
        private readonly SettingRepository $settings,
        private readonly ConfigService $config,
        private readonly EntityManagerInterface $em,
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
    public function welcome(): Response
    {
        return $this->render('setup/welcome.html.twig', [
            'active_step'      => 'welcome',
            'completed_steps'  => $this->completedSteps(),
        ]);
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
                $errors[] = 'Jeton CSRF invalide, réessayez.';
            }
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Email invalide.';
            }
            if (strlen($password) < 8) {
                $errors[] = 'Le mot de passe doit faire au moins 8 caractères.';
            }
            if ($password !== $passwordConfirm) {
                $errors[] = 'Les deux mots de passe ne correspondent pas.';
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

    // ─── Step 7: Finalization ──────────────────────────────────────────────

    #[Route('/finish', name: 'app_setup_finish', methods: ['GET', 'POST'])]
    public function finish(Request $request): Response
    {
        if ($redirect = $this->guardAdminExists()) {
            return $redirect;
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('setup_finish', (string) $request->request->get('_csrf_token'))) {
                return $this->redirectToRoute('app_setup_finish');
            }
            $this->settings->set(self::SETUP_DONE_KEY, '1');
            $this->config->invalidate();
            $this->addFlash('success', 'Prismarr est prêt. Bienvenue !');
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
     * @param array<string, string> $fields Reference: populated from DB if the key exists.
     */
    private function prefill(array &$fields): void
    {
        foreach ($fields as $key => $default) {
            $stored = $this->config->get($key);
            if ($stored !== null) {
                $fields[$key] = $stored;
            }
        }
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
            $errors[] = 'Jeton CSRF invalide, réessayez.';
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
