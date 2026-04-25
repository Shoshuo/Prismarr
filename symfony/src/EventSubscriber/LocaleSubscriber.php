<?php

namespace App\EventSubscriber;

use App\Service\DisplayPreferencesService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Resolves the active locale on every main request.
 *
 * Priority order:
 *   1. `?_locale=xx` query param (one-off preview, never persisted)
 *   2. Admin preference `display_language` from the DB
 *   3. Hard-coded `fr` fallback
 *
 * Prismarr is a single-instance homelab tool where admin + users typically
 * share the same language — so we expose the language as a single admin
 * setting instead of a per-user override. Users who want a different UI
 * language can still use `?_locale=en` for a single request, but nothing is
 * persisted client-side.
 *
 * We accept only whitelisted locales to avoid breaking Twig/Translator when
 * someone crafts a `?_locale=zz` URL — unknown values silently fall back to
 * the next step.
 */
class LocaleSubscriber implements EventSubscriberInterface
{
    public const SUPPORTED = ['en', 'fr'];
    public const FALLBACK  = 'en';

    public function __construct(
        private readonly DisplayPreferencesService $prefs,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priority 20 — run before Symfony's own LocaleListener (priority 16)
        // and well before LastVisitedRouteSubscriber on RESPONSE.
        return [KernelEvents::REQUEST => ['onKernelRequest', 20]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $locale = $this->pickLocale($request->query->get('_locale'))
            ?? $this->pickLocale($this->safePrefLanguage())
            ?? self::FALLBACK;

        $request->setLocale($locale);
    }

    private function pickLocale(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return in_array($value, self::SUPPORTED, true) ? $value : null;
    }

    /**
     * Reading DisplayPreferencesService can hit the DB via ConfigService —
     * we wrap it so a BDD outage (e.g. during setup wizard) never bubbles
     * up as a 500 on unrelated pages.
     */
    private function safePrefLanguage(): ?string
    {
        try {
            return $this->prefs->getLanguage();
        } catch (\Throwable) {
            return null;
        }
    }
}
