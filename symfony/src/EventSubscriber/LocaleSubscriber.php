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
 *   2. Session `_locale` (set by the setup wizard before any DB pref exists,
 *      and by the top-bar picker for one-off overrides)
 *   3. Admin preference `display_language` from the DB
 *   4. Hard-coded `en` fallback
 *
 * The session-based step lets the setup wizard offer a language picker on the
 * very first screen — at that point the DB is empty, so we cannot rely on the
 * `display_language` setting yet. Once setup is complete, that pref takes over.
 *
 * We accept only whitelisted locales to avoid breaking Twig/Translator when
 * someone crafts a `?_locale=zz` URL — unknown values silently fall back to
 * the next step.
 */
class LocaleSubscriber implements EventSubscriberInterface
{
    public const SUPPORTED      = ['en', 'fr', 'it'];
    public const FALLBACK       = 'en';
    public const SESSION_KEY    = '_locale';

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

        // Read session-stored locale only if a session already exists (cookie set
        // or session previously started). We deliberately avoid creating a fresh
        // session just to read a missing key — that would set a session cookie
        // for every cold visitor, including bots and 404 hits.
        $sessionLocale = null;
        if ($request->hasSession()) {
            $session = $request->getSession();
            if ($session->isStarted() || $request->hasPreviousSession()) {
                try {
                    $sessionLocale = $session->get(self::SESSION_KEY);
                } catch (\Throwable) {
                    $sessionLocale = null;
                }
            }
        }

        $locale = $this->pickLocale($request->query->get('_locale'))
            ?? $this->pickLocale($sessionLocale)
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
