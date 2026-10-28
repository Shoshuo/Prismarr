<?php

namespace App\Entity;

use App\Repository\ServiceInstanceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A configured connection to an upstream service (Radarr, Sonarr, …).
 *
 * Replaces the legacy single-instance settings (`radarr_url`, `radarr_api_key`,
 * `sonarr_url`, `sonarr_api_key`) introduced in v1.0. Multiple instances of
 * the same service can coexist (e.g. "Radarr 1080p" + "Radarr 4K"), each with
 * its own URL, API key and sidebar entry.
 *
 * Radarr and Sonarr since v1.1.0. Issue #131 added qBittorrent, with the
 * optional per-instance credentials (`username` / `password`), and Gluetun
 * control servers (`url` + optional `apiKey`), which a qBittorrent instance
 * links to through `gluetunInstanceId`. The other services (Jellyseerr,
 * Prowlarr, TMDb, …) keep their flat settings for now. The `type` field is
 * free-form so more services can be onboarded without reshaping the table.
 *
 * Slug is the URL-safe identifier carried in routes (e.g. `/radarr/{slug}/films`).
 * It's auto-generated from the name on create and stays fixed by default; users
 * can rewrite it manually in /admin (with a warning that bookmarks may break).
 */
#[ORM\Entity(repositoryClass: ServiceInstanceRepository::class)]
#[ORM\Table(name: 'service_instance')]
#[ORM\UniqueConstraint(name: 'uniq_service_instance_slug', columns: ['type', 'slug'])]
#[ORM\Index(name: 'idx_service_instance_type_pos', columns: ['type', 'position'])]
class ServiceInstance
{
    public const TYPE_RADARR = 'radarr';
    public const TYPE_SONARR = 'sonarr';
    public const TYPE_QBITTORRENT = 'qbittorrent';
    public const TYPE_GLUETUN = 'gluetun';

    /** @var list<string> Allowed type values. */
    public const TYPES = [self::TYPE_RADARR, self::TYPE_SONARR, self::TYPE_QBITTORRENT, self::TYPE_GLUETUN];

    /**
     * Brand label per type, used to name auto-created instances ("Radarr 1",
     * "qBittorrent 1") — ucfirst() would turn qBittorrent into "Qbittorrent".
     *
     * @var array<string, string>
     */
    public const TYPE_LABELS = [
        self::TYPE_RADARR      => 'Radarr',
        self::TYPE_SONARR      => 'Sonarr',
        self::TYPE_QBITTORRENT => 'qBittorrent',
        self::TYPE_GLUETUN     => 'Gluetun',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(length: 60)]
    private string $slug;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $url;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $apiKey = null;

    /**
     * Preferred quality profile id for this instance's quick-add / suggested-add
     * flows (issue #5). Null = fall back to the previous behaviour (the first
     * profile the service returns), so existing installs are unaffected. The id
     * is only meaningful within THIS instance's Radarr/Sonarr — profiles are not
     * shared across instances, which is why the default lives per-instance.
     */
    #[ORM\Column(nullable: true)]
    private ?int $defaultQualityProfileId = null;

    /**
     * qBittorrent WebUI credentials (issue #131). Both null/empty is the
     * reverse-proxy mode of issue #10: the proxy in front of qBittorrent (qui,
     * forward auth, …) injects authentication, so the client skips
     * /auth/login. Unused by Radarr/Sonarr, which authenticate with $apiKey.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $password = null;

    /**
     * Gluetun instance whose control server feeds the VPN / public IP /
     * forwarded-port panel of this qBittorrent instance (issue #131).
     * Null = no Gluetun, the panel shows its empty state. Plain id rather
     * than a Doctrine association: SQLite foreign keys are not enforced in
     * this app, so ServiceInstanceProvider owns the integrity (validates the
     * target is a Gluetun instance, unlinks on delete) either way.
     */
    #[ORM\Column(nullable: true)]
    private ?int $gluetunInstanceId = null;

    #[ORM\Column]
    private bool $isDefault = false;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $type, string $slug, string $name, string $url, ?string $apiKey = null)
    {
        $this->type      = $type;
        $this->slug      = $slug;
        $this->name      = $name;
        $this->url       = $url;
        $this->apiKey    = $apiKey;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this->touch(); }

    public function getSlug(): string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this->touch(); }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this->touch(); }

    public function getUrl(): string { return $this->url; }
    public function setUrl(string $url): static { $this->url = $url; return $this->touch(); }

    public function getApiKey(): ?string { return $this->apiKey; }
    public function setApiKey(?string $apiKey): static { $this->apiKey = $apiKey; return $this->touch(); }

    public function getDefaultQualityProfileId(): ?int { return $this->defaultQualityProfileId; }
    public function setDefaultQualityProfileId(?int $id): static { $this->defaultQualityProfileId = $id; return $this->touch(); }

    public function getUsername(): ?string { return $this->username; }
    public function setUsername(?string $username): static { $this->username = self::emptyToNull($username); return $this->touch(); }

    public function getPassword(): ?string { return $this->password; }
    public function setPassword(?string $password): static { $this->password = self::emptyToNull($password); return $this->touch(); }

    public function getGluetunInstanceId(): ?int { return $this->gluetunInstanceId; }
    public function setGluetunInstanceId(?int $id): static { $this->gluetunInstanceId = $id; return $this->touch(); }

    /**
     * Reverse-proxy mode (issue #10): an empty username OR password means
     * the proxy in front of qBittorrent handles auth, so no /auth/login.
     */
    public function usesProxyAuth(): bool
    {
        return ($this->username ?? '') === '' || ($this->password ?? '') === '';
    }

    public function isDefault(): bool { return $this->isDefault; }
    public function setIsDefault(bool $isDefault): static { $this->isDefault = $isDefault; return $this->touch(); }

    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): static { $this->enabled = $enabled; return $this->touch(); }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this->touch(); }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /**
     * Slugify a free-form name into a URL-safe identifier:
     * lowercase ASCII, alphanumerics + dashes, no leading/trailing dash.
     * Falls back to "instance" if the input contains no usable character.
     */
    public static function slugify(string $name): string
    {
        $slug = trim($name);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
            if ($converted !== false) {
                $slug = $converted;
            }
        }
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return $slug === '' ? 'instance' : $slug;
    }

    /**
     * Default display name for an auto-created instance of $type.
     */
    public static function defaultName(string $type, int $n = 1): string
    {
        return (self::TYPE_LABELS[$type] ?? ucfirst($type)) . ' ' . $n;
    }

    private static function emptyToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
