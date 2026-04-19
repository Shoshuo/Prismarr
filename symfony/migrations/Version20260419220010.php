<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Prismarr v1.0 — initial baseline schema.
 *
 * Creates the four application tables: media_watchlist, setting, user.
 * The messenger_messages table is intentionally NOT created here — the
 * Symfony Messenger Doctrine transport creates it on demand when the
 * first message is dispatched.
 */
final class Version20260419220010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial Prismarr schema (user, setting, media_watchlist)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE media_watchlist (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              tmdb_id INTEGER NOT NULL,
              media_type VARCHAR(10) NOT NULL,
              title VARCHAR(255) NOT NULL,
              poster_path VARCHAR(255) DEFAULT NULL,
              vote DOUBLE PRECISION DEFAULT NULL,
              year INTEGER DEFAULT NULL,
              added_at DATETIME NOT NULL,
              notes CLOB DEFAULT NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_watchlist_tmdb ON media_watchlist (tmdb_id, media_type)');
        $this->addSql(<<<'SQL'
            CREATE TABLE setting (
              name VARCHAR(120) NOT NULL,
              value CLOB DEFAULT NULL,
              updated_at DATETIME NOT NULL,
              PRIMARY KEY (name)
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE "user" (
              id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              email VARCHAR(180) NOT NULL,
              roles CLOB NOT NULL,
              password VARCHAR(255) NOT NULL,
              display_name VARCHAR(100) DEFAULT NULL,
              created_at DATETIME DEFAULT NULL,
              last_login_at DATETIME DEFAULT NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE media_watchlist');
        $this->addSql('DROP TABLE setting');
        $this->addSql('DROP TABLE "user"');
    }
}
