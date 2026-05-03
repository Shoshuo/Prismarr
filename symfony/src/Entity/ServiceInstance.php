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
 * Scope for v1.1.0: only Radarr and Sonarr. The other services (Jellyseerr,
 * qBittorrent, Prowlarr, Gluetun, TMDb) keep their flat settings for now.
 * The schema is intentionally extensible (free-form `type` field) so we can
 * onboard them later without another migration.
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

    /** @var list<string> Allowed type values for v1.1.0. */
    public const TYPES = [self::TYPE_RADARR, self::TYPE_SONARR];

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

    private function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}
