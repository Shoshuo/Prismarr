<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #131 — multiple qBittorrent instances, and Gluetun as a
 * multi-instance service a qBittorrent instance links to.
 *
 * Adds the qBittorrent-specific columns to `service_instance` (WebUI
 * username / password, id of the linked Gluetun instance), then moves the
 * legacy single-instance settings into instance #1 of each type — same
 * recipe as the v1.1.0 Radarr/Sonarr migration (Version20260503000000):
 * slug `<type>-1`, flagged is_default, so the upgrade is transparent and a
 * single-instance install keeps behaving exactly as before.
 *
 *  - `gluetun_url` / `gluetun_api_key` → `gluetun-1` (URL + API key). Seeded
 *    even when no qBittorrent is configured: a standalone Gluetun is kept.
 *  - `qbittorrent_url` / `qbittorrent_user` / `qbittorrent_password` →
 *    `qbittorrent-1`, linked to `gluetun-1` when that was seeded too — in
 *    v1.3.0 the one Gluetun fed the qBittorrent page's VPN card, the link
 *    keeps it there. An empty user or password is preserved as NULL: that
 *    is the reverse-proxy mode of issue #10 and must keep working.
 *
 * Each group of keys is DELETEd once copied (no double source of truth).
 * A group whose URL is empty is left alone: the service was never
 * configured, and deleting stray keys would silently lose them.
 *
 * Left untouched on purpose: `qbittorrent_enabled` and
 * `sidebar_hide_qbittorrent` stay service-level switches (the kill switch
 * of issue #15 still disables every qBittorrent instance).
 *
 * Fresh installs run this too — the SELECTs return nothing, no instance is
 * seeded.
 */
final class Version20261027000000 extends AbstractMigration
{
    private const QBITTORRENT_KEYS = ['qbittorrent_url', 'qbittorrent_user', 'qbittorrent_password'];
    private const GLUETUN_KEYS     = ['gluetun_url', 'gluetun_api_key'];

    public function getDescription(): string
    {
        return 'Issue #131 multi-instance qBittorrent + Gluetun: add credential + Gluetun link columns to service_instance, migrate legacy qbittorrent/gluetun settings into it.';
    }

    public function up(Schema $schema): void
    {
        // 1. New nullable columns — NULL for every existing Radarr/Sonarr row.
        $this->addSql('ALTER TABLE service_instance ADD COLUMN username VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE service_instance ADD COLUMN password VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE service_instance ADD COLUMN gluetun_instance_id INTEGER DEFAULT NULL');

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // 2. Gluetun first, so qBittorrent can link to it. Migrate only if
        //    the URL is set — an empty URL means Gluetun was never configured.
        $gluetunUrl    = $this->nonEmpty($this->fetchSetting('gluetun_url'));
        $gluetunSeeded = false;
        if ($gluetunUrl !== null && !$this->hasInstanceOfType('gluetun')) {
            $this->addSql(
                'INSERT INTO service_instance
                 (type, slug, name, url, api_key, is_default, enabled, position, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, 1, 0, ?, ?)',
                [
                    'gluetun',
                    'gluetun-1',
                    'Gluetun 1',
                    $gluetunUrl,
                    $this->nonEmpty($this->fetchSetting('gluetun_api_key')),
                    $now,
                    $now,
                ]
            );
            $gluetunSeeded = true;
        }
        if ($gluetunUrl !== null) {
            $this->deleteSettings(self::GLUETUN_KEYS);
        }

        // 3. qBittorrent, linked to the Gluetun seeded above (the sub-select
        //    runs after the INSERT, both are queued in order).
        $qbtUrl = $this->nonEmpty($this->fetchSetting('qbittorrent_url'));
        if ($qbtUrl === null) {
            return;
        }
        if (!$this->hasInstanceOfType('qbittorrent')) {
            $this->addSql(
                'INSERT INTO service_instance
                 (type, slug, name, url, api_key, username, password, gluetun_instance_id,
                  is_default, enabled, position, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NULL, ?, ?, '
                    . ($gluetunSeeded ? "(SELECT id FROM service_instance WHERE type = 'gluetun' AND slug = 'gluetun-1')" : 'NULL')
                    . ', 1, 1, 0, ?, ?)',
                [
                    'qbittorrent',
                    'qbittorrent-1',
                    'qBittorrent 1',
                    $qbtUrl,
                    $this->nonEmpty($this->fetchSetting('qbittorrent_user')),
                    $this->nonEmpty($this->fetchSetting('qbittorrent_password')),
                    $now,
                    $now,
                ]
            );
        }
        $this->deleteSettings(self::QBITTORRENT_KEYS);
    }

    public function down(Schema $schema): void
    {
        // Restore the legacy settings from the default (or first) instance of
        // each type, so a downgrade finds its config back. Gluetun comes from
        // the instance the restored qBittorrent links to, else the default
        // standalone one. Extra instances are lost on downgrade — the old
        // schema can only hold one of each.
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $qbt = $this->connection->fetchAssociative(
            "SELECT url, username, password, gluetun_instance_id FROM service_instance
             WHERE type = 'qbittorrent'
             ORDER BY is_default DESC, position ASC, id ASC
             LIMIT 1"
        );
        $gluetun = false;
        if ($qbt !== false && $qbt['gluetun_instance_id'] !== null) {
            $gluetun = $this->connection->fetchAssociative(
                "SELECT url, api_key FROM service_instance WHERE type = 'gluetun' AND id = ?",
                [(int) $qbt['gluetun_instance_id']]
            );
        }
        if ($gluetun === false) {
            $gluetun = $this->connection->fetchAssociative(
                "SELECT url, api_key FROM service_instance
                 WHERE type = 'gluetun'
                 ORDER BY is_default DESC, position ASC, id ASC
                 LIMIT 1"
            );
        }

        $map = [];
        if ($qbt !== false) {
            $map['qbittorrent_url']      = $qbt['url'];
            $map['qbittorrent_user']     = $qbt['username'];
            $map['qbittorrent_password'] = $qbt['password'];
        }
        if ($gluetun !== false) {
            $map['gluetun_url']     = $gluetun['url'];
            $map['gluetun_api_key'] = $gluetun['api_key'];
        }
        foreach ($map as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $this->addSql(
                'INSERT OR REPLACE INTO setting (name, value, updated_at) VALUES (?, ?, ?)',
                [$name, (string) $value, $now]
            );
        }

        $this->addSql("DELETE FROM service_instance WHERE type IN ('qbittorrent', 'gluetun')");
        $this->addSql('ALTER TABLE service_instance DROP COLUMN gluetun_instance_id');
        $this->addSql('ALTER TABLE service_instance DROP COLUMN password');
        $this->addSql('ALTER TABLE service_instance DROP COLUMN username');
    }

    private function hasInstanceOfType(string $type): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM service_instance WHERE type = ?',
            [$type]
        ) > 0;
    }

    /** @param list<string> $names */
    private function deleteSettings(array $names): void
    {
        $this->addSql(
            'DELETE FROM setting WHERE name IN (' . implode(', ', array_fill(0, count($names), '?')) . ')',
            $names
        );
    }

    private function fetchSetting(string $name): ?string
    {
        $value = $this->connection->fetchOne('SELECT value FROM setting WHERE name = ?', [$name]);
        return $value === false ? null : ($value === null ? null : (string) $value);
    }

    private function nonEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
