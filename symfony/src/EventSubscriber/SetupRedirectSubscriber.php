<?php

namespace App\EventSubscriber;

use App\Controller\SetupController;
use App\Repository\SettingRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Redirects to the setup wizard until it has been finalized
 * (empty `user` table or missing `setup.completed` flag).
 *
 * Implements ResetInterface so that, in FrankenPHP worker mode, the
 * cached setup flag is re-read from DB between requests (the container
 * is otherwise kept alive for minutes and would miss a just-finished
 * wizard until the worker recycles).
 */
class SetupRedirectSubscriber implements EventSubscriberInterface, ResetInterface
{
    private const PATH_WHITELIST_PREFIXES = [
        '/setup',
        '/login',
        '/logout',
        '/api/health',
        '/_profiler',
        '/_wdt',
        '/_error',
        '/assets',
        '/static',
        '/img',
        '/favicon',
    ];

    private ?bool $setupDone = null;

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly UrlGeneratorInterface $urls,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function reset(): void
    {
        $this->setupDone = null;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        foreach (self::PATH_WHITELIST_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        if ($this->isSetupDone()) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urls->generate('app_setup_root')
        ));
    }

    private function isSetupDone(): bool
    {
        if ($this->setupDone !== null) {
            return $this->setupDone;
        }

        try {
            return $this->setupDone = $this->settings->get(SetupController::SETUP_DONE_KEY) === '1';
        } catch (\Throwable) {
            // Schema not applied yet: let it through so `make init` can create the tables.
            return $this->setupDone = true;
        }
    }
}
