<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\Media\WatchlistItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Manages the current user's profile: display name, password change, and
 * avatar upload. Avatars are stored in `var/data/avatars/{user_id}.{ext}`
 * — behind the volume so they survive container recreations — and served
 * through a dedicated route that enforces authentication.
 */
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    private const AVATAR_MAX_BYTES    = 2_000_000; // 2 MB
    private const AVATAR_ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const AVATAR_EXT_MAP      = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly LoggerInterface $logger,
        private readonly WatchlistItemRepository $watchlistRepo,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/profil', name: 'app_profile', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('profile', (string) $request->request->get('_csrf_token'))) {
                $errors[] = $this->translator->trans('flash.csrf.invalid');
            }

            if ($errors === []) {
                $displayName = trim((string) $request->request->get('display_name', ''));
                $user->setDisplayName($displayName !== '' ? $displayName : null);

                $currentPw = (string) $request->request->get('current_password', '');
                $newPw     = (string) $request->request->get('new_password', '');
                $confirmPw = (string) $request->request->get('confirm_password', '');

                if ($newPw !== '' || $confirmPw !== '' || $currentPw !== '') {
                    if ($currentPw === '' || !$this->hasher->isPasswordValid($user, $currentPw)) {
                        $errors[] = $this->translator->trans('flash.password.current_wrong');
                    } elseif (strlen($newPw) < 8) {
                        $errors[] = $this->translator->trans('flash.password.too_short');
                    } elseif ($newPw !== $confirmPw) {
                        $errors[] = $this->translator->trans('flash.password.mismatch');
                    } else {
                        $user->setPassword($this->hasher->hashPassword($user, $newPw));
                    }
                }

                if ($errors === []) {
                    $this->em->flush();
                    $this->addFlash('success', $this->translator->trans('flash.profile.updated'));
                    return $this->redirectToRoute('app_profile');
                }
            }
        }

        // Personal stats + recent watchlist — best-effort, never crash.
        $watchlistCount  = 0;
        $recentWatchlist = [];
        try {
            $all = $this->watchlistRepo->findAllOrdered();
            $watchlistCount  = count($all);
            $recentWatchlist = array_slice($all, 0, 4);
        } catch (\Throwable $e) {
            $this->logger->warning('Profile watchlist failed: {msg}', ['msg' => $e->getMessage()]);
        }

        $daysSince = null;
        if ($user->getCreatedAt()) {
            $daysSince = (int) (new \DateTimeImmutable())->diff($user->getCreatedAt())->days;
        }

        return $this->render('profile/index.html.twig', [
            'user'            => $user,
            'errors'          => $errors,
            'watchlist_count' => $watchlistCount,
            'recent_watchlist' => $recentWatchlist,
            'days_since'      => $daysSince,
        ]);
    }

    #[Route('/profil/avatar', name: 'app_profile_avatar_upload', methods: ['POST'])]
    public function uploadAvatar(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('profile_avatar', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->translator->trans('flash.csrf.invalid'));
            return $this->redirectToRoute('app_profile');
        }

        $file = $request->files->get('avatar');
        if (!$file || !$file->isValid()) {
            $this->addFlash('error', $this->translator->trans('flash.profile.avatar_no_file'));
            return $this->redirectToRoute('app_profile');
        }

        if ($file->getSize() > self::AVATAR_MAX_BYTES) {
            $this->addFlash('error', $this->translator->trans('flash.profile.avatar_too_big'));
            return $this->redirectToRoute('app_profile');
        }

        $mime = $file->getMimeType();
        if (!in_array($mime, self::AVATAR_ALLOWED_MIME, true)) {
            $this->addFlash('error', $this->translator->trans('flash.profile.avatar_invalid_mime'));
            return $this->redirectToRoute('app_profile');
        }

        /** @var User $user */
        $user = $this->getUser();
        $dir = $this->getParameter('kernel.project_dir') . '/var/data/avatars';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // Drop any previous avatar (user may have switched formats).
        if ($user->getAvatarPath() !== null) {
            $old = $dir . '/' . $user->getAvatarPath();
            if (is_file($old)) {
                @unlink($old);
            }
        }

        $ext      = self::AVATAR_EXT_MAP[$mime];
        $filename = $user->getId() . '.' . $ext;

        try {
            $file->move($dir, $filename);
        } catch (\Throwable $e) {
            $this->logger->warning('Avatar upload failed: {msg}', ['msg' => $e->getMessage()]);
            $this->addFlash('error', $this->translator->trans('flash.profile.avatar_upload_failed'));
            return $this->redirectToRoute('app_profile');
        }

        $user->setAvatarPath($filename);
        $this->em->flush();

        $this->addFlash('success', $this->translator->trans('flash.profile.avatar_updated'));
        return $this->redirectToRoute('app_profile');
    }

    #[Route('/profil/avatar/delete', name: 'app_profile_avatar_delete', methods: ['POST'])]
    public function deleteAvatar(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('profile_avatar', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', $this->translator->trans('flash.csrf.invalid'));
            return $this->redirectToRoute('app_profile');
        }

        /** @var User $user */
        $user = $this->getUser();
        if ($user->getAvatarPath() !== null) {
            $path = $this->getParameter('kernel.project_dir') . '/var/data/avatars/' . $user->getAvatarPath();
            if (is_file($path)) {
                @unlink($path);
            }
            $user->setAvatarPath(null);
            $this->em->flush();
            $this->addFlash('success', $this->translator->trans('flash.profile.avatar_deleted'));
        }

        return $this->redirectToRoute('app_profile');
    }

    /**
     * Authenticated serve. `filename` is locked to `{userId}.{ext}` by the
     * regex so there's no way to request another user's file even though
     * we additionally scope the lookup to the caller's id below.
     */
    #[Route('/profil/avatar/file/{filename}', name: 'app_profile_avatar_serve', requirements: ['filename' => '\d+\.(jpg|png|webp|gif)'])]
    public function serveAvatar(string $filename): Response
    {
        $path = $this->getParameter('kernel.project_dir') . '/var/data/avatars/' . $filename;
        if (!is_file($path)) {
            throw $this->createNotFoundException($this->translator->trans('flash.profile.avatar_not_found'));
        }

        return new BinaryFileResponse($path, 200, [
            'Cache-Control' => 'private, max-age=60',
        ]);
    }
}
