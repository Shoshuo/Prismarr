<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #5 — per-instance default quality profile.
 *
 * Adds a nullable `default_quality_profile_id` column to `service_instance`.
 * Null (the default for every existing row) preserves the previous behaviour
 * where the quick-add / suggested-add flows fall back to whichever profile the
 * service returns first, so existing installs are unaffected until an admin
 * picks a default in /admin/settings.
 */
final class Version20260828000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Issue #5: add per-instance default_quality_profile_id to service_instance.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE service_instance ADD COLUMN default_quality_profile_id INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite has supported ALTER TABLE ... DROP COLUMN since 3.35 (2021);
        // the app's bundled SQLite is well past that.
        $this->addSql('ALTER TABLE service_instance DROP COLUMN default_quality_profile_id');
    }
}
