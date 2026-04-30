<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Catch PHP fatals (out-of-memory, max_execution_time exceeded) and render a
 * minimal HTML error page instead of an `ERR_EMPTY_RESPONSE` / `ob_get_clean`
 * cascade in the browser. Issue #13.
 *
 * Why a shutdown function and not the kernel exception listener: a PHP fatal
 * (E_ERROR) bypasses Symfony's exception handler entirely. Without this hook
 * the worker dies mid-render, FrankenPHP closes the connection, the user sees
 * a blank page and we lose the chance to tell them what's wrong.
 *
 * Active in every environment — fatals aren't catchable through Symfony's
 * debug screen anyway, so even devs benefit from a clean error page that
 * spells out the limit and how to bump it.
 */
class FatalErrorHandlerSubscriber implements EventSubscriberInterface
{
    private const FATAL_LEVELS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

    public static function getSubscribedEvents(): array
    {
        // The Symfony event isn't actually used to register the shutdown
        // anymore — public/index.php registers it before the kernel boot
        // so we run before Symfony's own ErrorHandler. Kept as a no-op
        // subscription so the class is still autoconfigured (and
        // discoverable for grep) without changing services.yaml.
        return [KernelEvents::REQUEST => ['onKernelRequest', 1024]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // No-op: see public/index.php.
    }

    /**
     * Static entrypoint registered very early in public/index.php, before
     * Symfony's kernel boots. Inspect the last PHP error: if it's a fatal
     * we recognize, flush whatever buffers the broken render left behind
     * and emit a self-contained HTML page. Anything else: do nothing and
     * let Symfony's own ErrorHandler take over.
     */
    public static function shutdownEntrypoint(): void
    {
        $html = self::renderForError(error_get_last());
        if ($html === null) {
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, private');
            header('X-Content-Type-Options: nosniff');
        }

        echo $html;
        // Hard exit: stop PHP shutdown here so Symfony's own
        // register_shutdown_function (which would otherwise re-render an
        // "Internal Server Error" page on top of ours) never gets to run.
        exit;
    }

    /**
     * Pure mapping from a PHP error array to either a fully-formed HTML page
     * (when the error is one we own) or `null` (let Symfony / FrankenPHP
     * handle the rest unchanged). Public for testability — handleFatal()'s
     * side effects (header/echo/buffer cleanup) aren't worth simulating in
     * a unit test, but the decision tree absolutely is.
     *
     * @param array{type:int,message:string,file?:string,line?:int}|null $err
     */
    public static function renderForError(?array $err): ?string
    {
        if ($err === null) {
            return null;
        }
        if (($err['type'] & self::FATAL_LEVELS) === 0) {
            return null;
        }

        $msg       = (string) ($err['message'] ?? '');
        $isMemory  = str_contains($msg, 'Allowed memory size');
        $isTimeout = str_contains($msg, 'Maximum execution time');
        // We only own the friendly page for the two failure modes large
        // libraries actually trigger. Other fatals stay raw — they likely
        // need the developer to debug, and a generic "Internal Server Error"
        // is more honest than guessing.
        if (!$isMemory && !$isTimeout) {
            return null;
        }

        $title = $isMemory ? 'Prismarr ran out of memory' : 'Prismarr request timed out';
        $cause = $isMemory
            ? 'The page tried to allocate more memory than the PHP limit allows (currently ' . htmlspecialchars((string) ini_get('memory_limit')) . ').'
            : 'The page took longer to render than the PHP limit allows (currently ' . htmlspecialchars((string) ini_get('max_execution_time')) . 's).';

        return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $title . ' — Prismarr</title><style>body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1rem;box-sizing:border-box}main{max-width:640px;width:100%}h1{color:#ef4444;margin:0 0 1rem;font-size:1.5rem}p{line-height:1.6;margin:.6rem 0}.hint{margin-top:1.25rem;padding:1rem 1.15rem;background:#1e293b;border:1px solid #334155;border-radius:8px;font-size:.9rem;line-height:1.55}.hint strong{color:#fbbf24}code{background:#0b1220;padding:.15em .45em;border-radius:4px;font-size:.85em;border:1px solid #334155}</style></head><body><main><h1>' . $title . '</h1><p>' . $cause . ' This usually happens with very large Radarr or Sonarr libraries (5,000+ items) where the entire library is loaded in one shot.</p><div class="hint"><p><strong>What to do</strong></p><p>Raise the limit via your <code>docker-compose.yml</code> by setting <code>PHP_MEMORY_LIMIT=2048M</code> and <code>PHP_MAX_EXECUTION_TIME=240</code> under <code>environment:</code>, then restart the container.</p></div></main></body></html>';
    }
}
