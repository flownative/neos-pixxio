<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add asset source identifier to ClientSecret
 */
final class Version20240910102102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add asset source identifier to ClientSecret';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySqlPlatform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySqlPlatform'."
        );

        $this->addSql('DROP INDEX flow_identity_flownative_pixxio_domain_model_clientsecret ON flownative_pixxio_domain_model_clientsecret');
        $this->addSql('ALTER TABLE flownative_pixxio_domain_model_clientsecret ADD assetsourceidentifier VARCHAR(255) NOT NULL');
        $this->addSql("UPDATE flownative_pixxio_domain_model_clientsecret SET assetsourceidentifier = 'flownative-pixxio' WHERE assetsourceidentifier = ''");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySqlPlatform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MySqlPlatform'."
        );

        $this->addSql('ALTER TABLE flownative_pixxio_domain_model_clientsecret DROP assetsourceidentifier');
        $this->addSql('CREATE UNIQUE INDEX flow_identity_flownative_pixxio_domain_model_clientsecret ON flownative_pixxio_domain_model_clientsecret (flowaccountidentifier)');
    }
}
