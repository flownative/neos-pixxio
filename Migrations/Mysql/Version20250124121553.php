<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop Client Secret
 */
final class Version20250124121553 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop Client Secret';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySqlPlatform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySqlPlatform'."
        );

        $this->addSql('DROP TABLE flownative_pixxio_domain_model_clientsecret');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySqlPlatform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySqlPlatform'."
        );

        $this->addSql('CREATE TABLE flownative_pixxio_domain_model_clientsecret (persistence_object_identifier varchar(40) NOT NULL, flowaccountidentifier varchar(255) NOT NULL, refreshtoken longtext NOT NULL, accesstoken longtext DEFAULT NULL, assetsourceidentifier varchar(255) NOT NULL, PRIMARY KEY (`persistence_object_identifier`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}
