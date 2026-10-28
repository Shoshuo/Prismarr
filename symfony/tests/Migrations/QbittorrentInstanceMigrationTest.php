<?php

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20261027000000;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Issue #131 — the upgrade from the flat qBittorrent / Gluetun settings to
 * the first qBittorrent and Gluetun service_instance rows (linked) must be
 * transparent. Replays every
 * migration in order on an in-memory SQLite database (the production
 * engine), seeds the legacy settings just before the #131 migration, and
 * checks what lands in service_instance / setting.
 */
final class QbittorrentInstanceMigrationTest extends TestCase
{
    private const DIR = __DIR__ . '/../../migrations';

    private Connection $db;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (glob(self::DIR . '/Version*.php') ?: [] as $file) {
            require_once $file;
        }
    }

    /** Run every migration older than the #131 one. */
    private function migrateToBeforeQbittorrentInstances(): void
    {
        foreach ($this->versions() as $class) {
            if ($class === Version20261027000000::class) {
                return;
            }
            $this->runUp($class);
        }
    }

    /** @return list<class-string<AbstractMigration>> */
    private function versions(): array
    {
        $classes = [];
        foreach (glob(self::DIR . '/Version*.php') ?: [] as $file) {
            $classes[] = 'DoctrineMigrations\\' . basename($file, '.php');
        }
        sort($classes);
        return $classes;
    }

    /** @param class-string<AbstractMigration> $class */
    private function runUp(string $class): void
    {
        $migration = new $class($this->db, new NullLogger());
        $migration->up(new Schema());
        $this->execute($migration);
    }

    private function runDown(): void
    {
        $migration = new Version20261027000000($this->db, new NullLogger());
        $migration->down(new Schema());
        $this->execute($migration);
    }

    private function execute(AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $this->db->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @param array<string, string> $settings */
    private function seedSettings(array $settings): void
    {
        foreach ($settings as $name => $value) {
            $this->db->insert('setting', ['name' => $name, 'value' => $value, 'updated_at' => '2026-01-01 00:00:00']);
        }
    }

    /** @return array<string, ?string> */
    private function settings(): array
    {
        $out = [];
        foreach ($this->db->fetchAllAssociative('SELECT name, value FROM setting') as $row) {
            $out[$row['name']] = $row['value'];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function qbtRows(): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, slug, name, url, api_key, username, password, gluetun_instance_id, is_default, enabled, position
             FROM service_instance WHERE type = 'qbittorrent' ORDER BY id"
        );
    }

    /** @return list<array<string, mixed>> */
    private function gluetunRows(): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, slug, name, url, api_key, username, password, gluetun_instance_id, is_default, enabled, position
             FROM service_instance WHERE type = 'gluetun' ORDER BY id"
        );
    }

    public function testFlatSettingsBecomeLinkedDefaultInstances(): void
    {
        $this->migrateToBeforeQbittorrentInstances();
        $this->seedSettings([
            'qbittorrent_url'      => 'http://10.0.0.2:8080 ',
            'qbittorrent_user'     => 'admin',
            'qbittorrent_password' => 'secret',
            'gluetun_url'          => 'http://10.0.0.3:8000',
            'gluetun_api_key'      => 'gluetun-key',
            'gluetun_protocol'     => 'wireguard',
            'qbittorrent_enabled'  => '0',
            'sidebar_hide_qbittorrent' => '1',
            'tmdb_api_key'         => 'tmdb',
        ]);

        $this->runUp(Version20261027000000::class);

        $gluetun = $this->gluetunRows();
        self::assertCount(1, $gluetun);
        self::assertSame('gluetun-1', $gluetun[0]['slug']);
        self::assertSame('Gluetun 1', $gluetun[0]['name']);
        self::assertSame('http://10.0.0.3:8000', $gluetun[0]['url']);
        self::assertSame('gluetun-key', $gluetun[0]['api_key']);
        self::assertNull($gluetun[0]['gluetun_instance_id']);
        self::assertSame(1, (int) $gluetun[0]['is_default']);
        self::assertSame(1, (int) $gluetun[0]['enabled']);

        $rows = $this->qbtRows();
        self::assertCount(1, $rows);
        self::assertSame('qbittorrent-1', $rows[0]['slug']);
        self::assertSame('qBittorrent 1', $rows[0]['name']);
        self::assertSame('http://10.0.0.2:8080', $rows[0]['url']);
        self::assertNull($rows[0]['api_key']);
        self::assertSame('admin', $rows[0]['username']);
        self::assertSame('secret', $rows[0]['password']);
        self::assertSame((int) $gluetun[0]['id'], (int) $rows[0]['gluetun_instance_id'], 'qbittorrent-1 must link to gluetun-1');
        self::assertSame(1, (int) $rows[0]['is_default']);
        self::assertSame(1, (int) $rows[0]['enabled']);

        $settings = $this->settings();
        foreach (['qbittorrent_url', 'qbittorrent_user', 'qbittorrent_password', 'gluetun_url', 'gluetun_api_key'] as $moved) {
            self::assertArrayNotHasKey($moved, $settings, "$moved must be deleted once copied");
        }
        // Service-level switches and unrelated settings are untouched.
        self::assertSame('0', $settings['qbittorrent_enabled']);
        self::assertSame('1', $settings['sidebar_hide_qbittorrent']);
        self::assertSame('wireguard', $settings['gluetun_protocol']);
        self::assertSame('tmdb', $settings['tmdb_api_key']);
    }

    public function testUrlOnlyReverseProxySetupKeepsEmptyCredentials(): void
    {
        // Issue #10 — empty user/password = reverse-proxy mode; must survive
        // as "no credentials", not as a half-filled pair.
        $this->migrateToBeforeQbittorrentInstances();
        $this->seedSettings([
            'qbittorrent_url'      => 'http://10.0.0.2:8080',
            'qbittorrent_user'     => '',
            'qbittorrent_password' => '',
        ]);

        $this->runUp(Version20261027000000::class);

        $rows = $this->qbtRows();
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['username']);
        self::assertNull($rows[0]['password']);
        // No Gluetun configured → no Gluetun instance, no link.
        self::assertNull($rows[0]['gluetun_instance_id']);
        self::assertSame([], $this->gluetunRows());
    }

    public function testStandaloneGluetunIsKeptWithoutQbittorrent(): void
    {
        // Upstream #52 — Gluetun without qBittorrent is a valid setup: it
        // becomes gluetun-1 on its own, nothing qBittorrent is seeded.
        $this->migrateToBeforeQbittorrentInstances();
        $this->seedSettings([
            'qbittorrent_url' => '  ',
            'gluetun_url'     => 'http://10.0.0.3:8000',
            'gluetun_api_key' => ' ',
        ]);

        $this->runUp(Version20261027000000::class);

        self::assertSame([], $this->qbtRows());
        $gluetun = $this->gluetunRows();
        self::assertCount(1, $gluetun);
        self::assertSame('gluetun-1', $gluetun[0]['slug']);
        self::assertSame('http://10.0.0.3:8000', $gluetun[0]['url']);
        self::assertNull($gluetun[0]['api_key'], 'A blank API key is stored as none');
        self::assertSame(1, (int) $gluetun[0]['is_default']);
        $settings = $this->settings();
        self::assertArrayNotHasKey('gluetun_url', $settings);
        self::assertArrayNotHasKey('gluetun_api_key', $settings);
        // The empty qBittorrent URL was never configured: left alone.
        self::assertSame('  ', $settings['qbittorrent_url']);
    }

    public function testFreshInstallSeedsNothing(): void
    {
        $this->migrateToBeforeQbittorrentInstances();
        $this->runUp(Version20261027000000::class);

        self::assertSame([], $this->qbtRows());
        self::assertSame([], $this->gluetunRows());
        // The new columns exist and are NULL on Radarr/Sonarr rows.
        $this->db->insert('service_instance', [
            'type' => 'radarr', 'slug' => 'radarr-1', 'name' => 'Radarr 1', 'url' => 'http://10.0.0.4:7878',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $row = $this->db->fetchAssociative("SELECT username, password, gluetun_instance_id FROM service_instance WHERE slug = 'radarr-1'");
        self::assertSame(['username' => null, 'password' => null, 'gluetun_instance_id' => null], $row);
    }

    public function testExistingRadarrAndSonarrInstancesAreUntouched(): void
    {
        $this->migrateToBeforeQbittorrentInstances();
        $this->db->insert('service_instance', [
            'type' => 'radarr', 'slug' => 'radarr-1', 'name' => 'Radarr 1', 'url' => 'http://10.0.0.4:7878',
            'api_key' => 'k', 'is_default' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->seedSettings(['qbittorrent_url' => 'http://10.0.0.2:8080', 'gluetun_url' => 'http://10.0.0.3:8000']);

        $this->runUp(Version20261027000000::class);

        $radarr = $this->db->fetchAssociative("SELECT slug, api_key, is_default, gluetun_instance_id FROM service_instance WHERE type = 'radarr'");
        self::assertSame('radarr-1', $radarr['slug']);
        self::assertSame('k', $radarr['api_key']);
        self::assertSame(1, (int) $radarr['is_default']);
        self::assertNull($radarr['gluetun_instance_id']);
        self::assertCount(1, $this->qbtRows());
        self::assertCount(1, $this->gluetunRows());
    }

    public function testDownRestoresTheFlatSettingsFromTheLinkedInstances(): void
    {
        $this->migrateToBeforeQbittorrentInstances();
        $this->seedSettings([
            'qbittorrent_url'      => 'http://10.0.0.2:8080',
            'qbittorrent_user'     => 'admin',
            'qbittorrent_password' => 'secret',
            'gluetun_url'          => 'http://10.0.0.3:8000',
            'gluetun_api_key'      => 'gluetun-key',
        ]);
        $this->runUp(Version20261027000000::class);
        // A second, default Gluetun that qbittorrent-1 does NOT link to: the
        // downgrade must restore the linked one, the one the VPN card used.
        $this->db->executeStatement("UPDATE service_instance SET is_default = 0 WHERE slug = 'gluetun-1'");
        $this->db->insert('service_instance', [
            'type' => 'gluetun', 'slug' => 'gluetun-2', 'name' => 'Gluetun 2', 'url' => 'http://10.0.0.5:8000',
            'is_default' => 1, 'position' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        $this->runDown();

        $settings = $this->settings();
        self::assertSame('http://10.0.0.2:8080', $settings['qbittorrent_url']);
        self::assertSame('admin', $settings['qbittorrent_user']);
        self::assertSame('secret', $settings['qbittorrent_password']);
        self::assertSame('http://10.0.0.3:8000', $settings['gluetun_url']);
        self::assertSame('gluetun-key', $settings['gluetun_api_key']);
        $columns = array_column($this->db->fetchAllAssociative('PRAGMA table_info(service_instance)'), 'name');
        self::assertNotContains('username', $columns);
        self::assertNotContains('password', $columns);
        self::assertNotContains('gluetun_instance_id', $columns);
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM service_instance WHERE type IN ('qbittorrent', 'gluetun')"));
    }

    public function testDownRestoresAStandaloneGluetun(): void
    {
        $this->migrateToBeforeQbittorrentInstances();
        $this->seedSettings(['gluetun_url' => 'http://10.0.0.3:8000']);
        $this->runUp(Version20261027000000::class);

        $this->runDown();

        $settings = $this->settings();
        self::assertSame('http://10.0.0.3:8000', $settings['gluetun_url']);
        self::assertArrayNotHasKey('gluetun_api_key', $settings);
        self::assertArrayNotHasKey('qbittorrent_url', $settings);
    }

    public function testUpDownUpRoundTripIsStable(): void
    {
        $this->migrateToBeforeQbittorrentInstances();
        $this->seedSettings([
            'qbittorrent_url' => 'http://10.0.0.2:8080',
            'gluetun_url'     => 'http://10.0.0.3:8000',
        ]);
        $this->runUp(Version20261027000000::class);
        $this->runDown();
        $this->runUp(Version20261027000000::class);

        $rows    = $this->qbtRows();
        $gluetun = $this->gluetunRows();
        self::assertCount(1, $rows);
        self::assertCount(1, $gluetun);
        self::assertSame((int) $gluetun[0]['id'], (int) $rows[0]['gluetun_instance_id']);
    }
}
