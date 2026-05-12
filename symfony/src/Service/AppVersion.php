<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Version + release-notes provider for the /admin/settings → Updates page.
 *
 * The running version is whatever the build stamped into the `PRISMARR_VERSION`
 * env var (the release workflow passes the git tag, the beta workflow passes
 * `1.1.0-beta.N`). When that env is empty or the placeholder `dev` — i.e. a
 * local `make dev` build — it falls back to the {@see VERSION} constant. So the
 * constant only matters for local development; published images always report
 * the channel they came from.
 *
 * Release notes are fetched from the GitHub Releases API (public, no auth) and
 * cached for an hour. If the network is unavailable, the page falls back to
 * displaying just the current version.
 */
class AppVersion implements ResetInterface
{
    /** Local-dev fallback when PRISMARR_VERSION is unset or `dev`. */
    public const VERSION = '1.1.0-dev';

    private const GITHUB_API_URL = 'https://api.github.com/repos/Shoshuo/Prismarr/releases?per_page=15';
    // v2: schema bump (added `body_html` rendered from Markdown). Old v1 cache
    // entries are simply ignored; they expire on their own after 1 h.
    private const CACHE_KEY      = 'app_version.releases.v2';
    private const CACHE_TTL      = 3600; // 1 hour

    /** Resolved once in the constructor: PRISMARR_VERSION, or the constant. */
    private readonly string $version;

    /** @var array<int, array{tag:string,name:string,body:string,body_html:string,published_at:string,html_url:string}>|null */
    private ?array $releasesInProcess = null;

    public function __construct(
        private readonly CacheItemPoolInterface $cacheApp,
        private readonly LoggerInterface        $logger,
        #[Autowire(env: 'default::PRISMARR_VERSION')]
        string $runtimeVersion = '',
    ) {
        $this->version = ($runtimeVersion !== '' && $runtimeVersion !== 'dev')
            ? $runtimeVersion
            : self::VERSION;
    }

    public function reset(): void
    {
        $this->releasesInProcess = null;
    }

    public function current(): string
    {
        return $this->version;
    }

    /**
     * Latest GitHub release tag (without the leading `v`), or null if the
     * API is unreachable or returned nothing usable.
     */
    public function latest(): ?string
    {
        $first = $this->releases()[0] ?? null;
        return $first['tag'] ?? null;
    }

    /**
     * @return bool true if a strictly newer version is available on GitHub.
     */
    public function isUpdateAvailable(): bool
    {
        $latest = $this->latest();
        if ($latest === null) {
            return false;
        }
        // version_compare understands pre-release suffixes: a `1.1.0-beta.3`
        // build sees `1.1.0` as newer (nudge to the stable) but not `1.0.x`.
        return version_compare($latest, $this->version, '>');
    }

    /**
     * @return array<int, array{tag:string,name:string,body:string,body_html:string,published_at:string,html_url:string}>
     */
    public function releases(): array
    {
        if ($this->releasesInProcess !== null) {
            return $this->releasesInProcess;
        }

        $item = $this->cacheApp->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            $cached = $item->get();
            if (is_array($cached)) {
                return $this->releasesInProcess = $cached;
            }
        }

        $fetched = $this->fetchFromGithub();
        if ($fetched === null) {
            // Don't poison the cache with a failure — let the next request
            // try again (network may be intermittent). Return empty list.
            return $this->releasesInProcess = [];
        }

        $item->set($fetched);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cacheApp->save($item);

        return $this->releasesInProcess = $fetched;
    }

    /**
     * @return array<int, array{tag:string,name:string,body:string,body_html:string,published_at:string,html_url:string}>|null
     */
    private function fetchFromGithub(): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::GITHUB_API_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_NOSIGNAL       => 1,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/vnd.github+json',
                'User-Agent: Prismarr/' . $this->version,
            ],
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '' || $code !== 200) {
            $this->logger->info('AppVersion GitHub releases fetch failed', [
                'code'  => $code,
                'error' => $err,
            ]);
            return null;
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            return null;
        }

        $releases = [];
        foreach ($data as $r) {
            if (!is_array($r)) {
                continue;
            }
            $tag = (string) ($r['tag_name'] ?? '');
            // Strip leading "v" for cleaner display + version_compare.
            $tag = ltrim($tag, 'vV');
            if ($tag === '') {
                continue;
            }
            $body = (string) ($r['body'] ?? '');
            $releases[] = [
                'tag'          => $tag,
                'name'         => (string) ($r['name'] ?? $tag),
                'body'         => $body,
                'body_html'    => self::renderBody($body),
                'published_at' => (string) ($r['published_at'] ?? ''),
                'html_url'     => (string) ($r['html_url'] ?? ''),
            ];
        }

        return $releases;
    }

    /**
     * Light-weight Markdown → HTML renderer for GitHub release notes. Intentionally
     * narrow: handles headings (#/##/###), bold, italic, inline code, links and
     * bullet lists. Anything beyond that renders as plain text. We HTML-escape
     * the input first so any `<script>` or stray HTML in the upstream body is
     * neutralised before our own tags are inserted.
     *
     * Public + static so it can be unit-tested without booting the cache.
     */
    public static function renderBody(string $body): string
    {
        if ($body === '') {
            return '';
        }

        $body  = str_replace(["\r\n", "\r"], "\n", $body);
        $lines = explode("\n", $body);

        $html      = '';
        $inUl      = false;
        $paraLines = [];

        $flushPara = function () use (&$paraLines, &$html): void {
            if ($paraLines === []) {
                return;
            }
            $text = self::renderInline(implode("\n", $paraLines));
            $text = nl2br($text, false);
            $html .= '<p style="margin:.4rem 0;">' . $text . '</p>';
            $paraLines = [];
        };

        $closeList = function () use (&$inUl, &$html): void {
            if ($inUl) {
                $html .= '</ul>';
                $inUl = false;
            }
        };

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $m)) {
                $flushPara();
                $closeList();
                $level = strlen($m[1]);
                $tag   = ['h4', 'h5', 'h6'][$level - 1];
                $size  = [1 => '1.05rem', 2 => '.95rem', 3 => '.88rem'][$level];
                $html .= '<' . $tag . ' style="font-size:' . $size . ';font-weight:600;margin:.6rem 0 .2rem;">'
                    . self::renderInline($m[2])
                    . '</' . $tag . '>';
                continue;
            }
            if (preg_match('/^[\-\*]\s+(.+)$/', $line, $m)) {
                $flushPara();
                if (!$inUl) {
                    $html .= '<ul style="margin:.3rem 0 .3rem;padding-left:1.2rem;">';
                    $inUl = true;
                }
                $html .= '<li>' . self::renderInline($m[1]) . '</li>';
                continue;
            }
            if (trim($line) === '') {
                $flushPara();
                $closeList();
                continue;
            }
            if ($inUl) {
                $closeList();
            }
            $paraLines[] = $line;
        }

        $flushPara();
        $closeList();

        return $html;
    }

    private static function renderInline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = preg_replace(
            '/`([^`\n]+?)`/',
            '<code style="padding:1px 4px;background:rgba(99,102,241,.12);border-radius:3px;font-size:.85em;">$1</code>',
            $text
        );
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<!\*)\*([^\*\n]+?)\*(?!\*)/', '<em>$1</em>', $text);
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s\)]+)\)/',
            fn(array $m) => '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>',
            $text
        );

        return $text;
    }
}
