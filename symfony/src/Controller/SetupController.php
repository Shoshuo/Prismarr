<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use App\EventSubscriber\LocaleSubscriber;
use App\Service\ConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * First-run wizard: pick the UI language, create the admin account, then
 * land on /admin/settings (Services section) where every service is
 * configured. The wizard itself never touches service URLs or secrets, so
 * there is a single place to configure them.
 */
#[Route('/setup')]
class SetupController extends AbstractController
{
    public const SETUP_DONE_KEY = 'setup_completed';

    /** Where the wizard hands over once the admin exists. */
    public const SERVICES_FRAGMENT = '#section-services';

    public function __construct(
        private readonly UserRepository $users,
        private readonly SettingRepository $settings,
        private readonly ConfigService $config,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_setup_root')]
    public function root(Request $request): Response
    {
        if ($this->settings->get(self::SETUP_DONE_KEY) === '1') {
            return $this->redirectToRoute('app_home');
        }
        if ($this->users->count([]) === 0) {
            return $this->redirectToRoute('app_setup_welcome');
        }

        // An admin exists but setup was never marked done (an install left
        // mid-way through the old multi-step wizard): there is nothing left
        // to ask, so close the wizard and hand over to the settings page.
        // The visitor may not be the admin, so their session locale is not
        // promoted to the instance-wide language here.
        $this->completeSetup($request, promoteLocale: false);

        return $this->redirectToServices();
    }

    // ─── Step 1: Welcome ───────────────────────────────────────────────────

    #[Route('/welcome', name: 'app_setup_welcome')]
    public function welcome(Request $request): Response
    {
        if ($redirect = $this->guardSetupNotCompleted()) {
            return $redirect;
        }

        return $this->render('setup/welcome.html.twig', [
            'active_step'      => 'welcome',
            'completed_steps'  => $this->completedSteps(),
            'current_locale'   => $request->getLocale(),
            'supported_locales' => LocaleSubscriber::SUPPORTED,
        ]);
    }

    /**
     * Stores a UI locale in the session so the wizard renders in the user's
     * preferred language. Promoted to the `display_language` setting once the
     * admin is created (completeSetup()). Called from welcome.html.twig.
     */
    #[Route('/locale', name: 'app_setup_locale', methods: ['POST'])]
    public function locale(Request $request): Response
    {
        if ($redirect = $this->guardSetupNotCompleted()) {
            return $redirect;
        }
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

    // ─── Step 2: Admin account ─────────────────────────────────────────────

    #[Route('/admin', name: 'app_setup_admin', methods: ['GET', 'POST'])]
    public function admin(
        Request $request,
        UserPasswordHasherInterface $hasher,
        Security $security,
    ): Response {
        if ($this->users->count([]) > 0) {
            // Admin already created: never offer a second account form.
            return $this->redirectToRoute('app_setup_root');
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
                $this->completeSetup($request);
                $this->addFlash('success', $this->translator->trans('setup.flash.connect_services'));

                return $this->redirectToServices();
            }
        }

        return $this->render('setup/admin.html.twig', [
            'active_step'     => 'admin',
            'completed_steps' => ['welcome'],
            'errors'          => $errors,
            'email'           => $email,
            'display_name'    => $displayName,
        ]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * Marks the install as done. The session-only locale picked at
     * /setup/welcome is promoted to the permanent `display_language` pref and
     * dropped from the session, so the DB is the single source of truth
     * (otherwise a later change in /admin/settings would be shadowed by the
     * session value in LocaleSubscriber).
     */
    private function completeSetup(Request $request, bool $promoteLocale = true): void
    {
        $session = $request->getSession();
        $sessionLocale = $session->get(LocaleSubscriber::SESSION_KEY);
        if ($promoteLocale && is_string($sessionLocale) && in_array($sessionLocale, LocaleSubscriber::SUPPORTED, true)) {
            $this->settings->set('display_language', $sessionLocale);
            $session->remove(LocaleSubscriber::SESSION_KEY);
        }

        $this->settings->set(self::SETUP_DONE_KEY, '1');
        $this->config->invalidate();
    }

    private function redirectToServices(): RedirectResponse
    {
        return $this->redirect($this->generateUrl('admin_settings_index') . self::SERVICES_FRAGMENT);
    }

    /** Once setup is done the wizard pages are closed for good. */
    private function guardSetupNotCompleted(): ?RedirectResponse
    {
        if ($this->settings->get(self::SETUP_DONE_KEY) === '1') {
            return $this->redirectToRoute('app_home');
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function completedSteps(): array
    {
        return $this->users->count([]) > 0 ? ['welcome', 'admin'] : [];
    }
}
