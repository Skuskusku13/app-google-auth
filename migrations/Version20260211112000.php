<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260211112000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Google OAuth token storage fields on user table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('user');

        if (!$table->hasColumn('google_access_token')) {
            $this->addSql('ALTER TABLE user ADD google_access_token LONGTEXT DEFAULT NULL');
        }
        if (!$table->hasColumn('google_refresh_token')) {
            $this->addSql('ALTER TABLE user ADD google_refresh_token LONGTEXT DEFAULT NULL');
        }
        if (!$table->hasColumn('google_token_expires_at')) {
            $this->addSql('ALTER TABLE user ADD google_token_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('user');

        if ($table->hasColumn('google_access_token')) {
            $this->addSql('ALTER TABLE user DROP google_access_token');
        }
        if ($table->hasColumn('google_refresh_token')) {
            $this->addSql('ALTER TABLE user DROP google_refresh_token');
        }
        if ($table->hasColumn('google_token_expires_at')) {
            $this->addSql('ALTER TABLE user DROP google_token_expires_at');
        }
    }
}
