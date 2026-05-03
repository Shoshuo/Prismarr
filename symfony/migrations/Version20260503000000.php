<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * v1.1.0 — multi-instance Radarr / Sonarr (issue #21).
 *
 * Replaces the four legacy single-instance settings (`radarr_url`,
 * `radarr_api_key`, `sonarr_url`, `sonarr_api_key`) with a proper
 * relational `service_instance` table. Existing v1.0.x installs get their
 * configured Radarr / Sonarr URLs migrated into instance #1 of each type
 * (slug `radarr-1` / `sonarr-1`, flagged is_default), so the upgrade is
 * transparent: the app continues to behave as if there were exactly one
 * Radarr and one Sonarr.
 *
 * Fresh installs run this migration too — the SELECTs return nothing, no
 * instance is seeded, and the user will create them through the wizard.
 *
 * The four legacy settings rows are then DELETEd from the `setting` table
 * to avoid drifting double sources of truth (see Phase A "Big Bang"
 * decision in v1.1.0 design notes).
 */
final class Version20260503000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'v1.1.0 multi-instance: create service_instance, migrate legacy radarr/sonarr settings into it.';
    }

    public function up(Schema $schema): void
    {
        // 1. New table.
        $this->addSql(<<<'SQL'
            CREATE TABLE service_instance (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              type VARCHAR(20) NOT NULL,
              slug VARCHAR(60) NOT NULL,
              name VARCHAR(120) NOT NULL,
              url VARCHAR(255) NOT NULL,
              api_key VARCHAR(255) DEFAULT NULL,
              is_default BOOLEAN NOT NULL DEFAULT 0,
              enabled BOOLEAN NOT NULL DEFAULT 1,
              position INTEGER NOT NULL DEFAULT 0,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_service_instance_slug ON service_instance (type, slug)');
        $this->addSql('CREATE INDEX idx_service_instance_type_pos ON service_instance (type, position)');

        // 2. Seed from legacy settings — wrap each configured service into
        //    instance #1 (slug `<type>-1`, is_default=1, enabled=1, pos=0).
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach (['radarr', 'sonarr'] as $type) {
            $url    = $this->fetchSetting($type . '_url');
            $apiKey = $this->fetchSetting($type . '_api_key');

            // Migrate only if URL is set — an empty URL means the user
            // skipped this service in the wizard, no instance to create.
            if ($url === null || $url === '') {
                continue;
            }

            $this->addSql(
                'INSERT INTO service_instance
                 (type, slug, name, url, api_key, is_default, enabled, position, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, 1, 0, ?, ?)',
                [
                    $type,
                    $type . '-1',
                    ucfirst($type) . ' 1',
                    $url,
                    ($apiKey !== null && $apiKey !== '') ? $apiKey : null,
                    $now,
                    $now,
                ]
            );
        }

        // 3. Drop the legacy settings — Big Bang, no double source of truth.
        $this->addSql(
            "DELETE FROM setting WHERE name IN ('radarr_url', 'radarr_api_key', 'sonarr_url', 'sonarr_api_key')"
        );
    }

    public function down(Schema $schema): void
    {
        // Restore the legacy settings from the default (or first) instance
        // of each type, so a downgrade to v1.0.x finds its config back.
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach (['radarr', 'sonarr'] as $type) {
            $row = $this->connection->fetchAssociative(
                'SELECT url, api_key FROM service_instance
                 WHERE type = ?
                 ORDER BY is_default DESC, position ASC, id ASC
                 LIMIT 1',
                [$type]
            );
            if ($row === false) {
                continue;
            }
            $this->addSql(
                'INSERT OR REPLACE INTO setting (name, value, updated_at) VALUES (?, ?, ?)',
                [$type . '_url', (string) $row['url'], $now]
            );
            if (!empty($row['api_key'])) {
                $this->addSql(
                    'INSERT OR REPLACE INTO setting (name, value, updated_at) VALUES (?, ?, ?)',
                    [$type . '_api_key', (string) $row['api_key'], $now]
                );
            }
        }

        $this->addSql('DROP TABLE service_instance');
    }

    private function fetchSetting(string $name): ?string
    {
        $value = $this->connection->fetchOne('SELECT value FROM setting WHERE name = ?', [$name]);
        return $value === false ? null : ($value === null ? null : (string) $value);
    }
}
