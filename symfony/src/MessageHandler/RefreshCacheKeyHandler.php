<?php

namespace App\MessageHandler;

use App\Message\RefreshCacheKey;
use App\Service\Cache\CacheRefresherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Routes a RefreshCacheKey to every CacheRefresherInterface whose supports()
 * claims the key.
 *
 * This deliberately does NOT stop at the first match. The tagged iterator's
 * order is container registration order, which is not a stable API — a
 * "first match wins" handler would let one refresher's supports() prefix
 * silently shadow another's (e.g. a hypothetical "media." refresher
 * swallowing every key meant for a "media.movies." refresher) depending on
 * registration order alone. CacheRefresherInterface instead requires
 * supports() domains to be mutually exclusive; calling every match and
 * warning when more than one fires makes an accidental overlap visible
 * instead of silently picking a winner.
 */
#[AsMessageHandler]
final class RefreshCacheKeyHandler
{
    /** @param iterable<CacheRefresherInterface> $refreshers */
    public function __construct(
        #[AutowireIterator('app.cache_refresher')]
        private readonly iterable $refreshers,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(RefreshCacheKey $message): void
    {
        $matches = 0;
        foreach ($this->refreshers as $refresher) {
            if ($refresher->supports($message->key)) {
                ++$matches;
                $refresher->refresh($message->key);
            }
        }

        if ($matches === 0) {
            // Deliberately NOT thrown: a throw costs three retries and then a
            // row in the `failed` transport nobody monitors. A warning is the
            // right amount of noise for a key whose owner was removed.
            $this->logger->warning('RefreshCacheKey: no refresher claims this key', ['key' => $message->key]);

            return;
        }

        if ($matches > 1) {
            // supports() domains are supposed to be mutually exclusive
            // (CacheRefresherInterface); more than one match means two
            // refreshers' domains collide. Still ack — both already ran and
            // refreshers are required to be idempotent — but this should
            // never happen in a correctly configured app, so it is worth
            // surfacing rather than silently tolerating.
            $this->logger->warning('RefreshCacheKey: multiple refreshers claim this key', [
                'key'   => $message->key,
                'count' => $matches,
            ]);
        }
    }
}
