<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Blocks remote access to Symfony debug tools (/_profiler, /_wdt) when
 * APP_ENV=dev. Protects public deployments where an admin accidentally
 * left APP_ENV=dev in production.
 */
class ProfilerGuardSubscriber implements EventSubscriberInterface
{
    private const DEV_PATH_PREFIXES = ['/_profiler', '/_wdt'];

    /**
     * Private RFC1918 ranges + loopback + link-local.
     * Self-hosted Prismarr is usually on LAN; anything outside these is
     * treated as potentially public and blocked.
     */
    private const TRUSTED_RANGES = [
        '127.0.0.0/8',
        '::1/128',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        'fc00::/7',
        'fe80::/10',
    ];

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $kernelEnvironment,
        private readonly TranslatorInterface $translator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 30],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($this->kernelEnvironment !== 'dev') {
            return;
        }
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();
        $isDevRoute = false;
        foreach (self::DEV_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $isDevRoute = true;
                break;
            }
        }
        if (!$isDevRoute) {
            return;
        }

        $clientIp = $event->getRequest()->getClientIp();
        if ($clientIp !== null && IpUtils::checkIp($clientIp, self::TRUSTED_RANGES)) {
            return;
        }

        throw new AccessDeniedHttpException(
            $this->translator->trans('profiler.access_denied_remote', [], 'errors')
        );
    }
}
