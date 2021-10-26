<?php
namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Introduce Client Secret
 */
class Version20180517083014 extends AbstractMigration
{

    /**
     * @return string
     */
    public function getDescription(): string 
    {
        return '';
    }

    /**
     * @param Schema $schema
     * @return void
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\DBAL\Migrations\AbortMigrationException
     */
    public function up(Schema $schema): void 
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('CREATE TABLE flownative_pixxio_domain_model_clientsecret (persistence_object_identifier VARCHAR(40) NOT NULL, flowaccountidentifier VARCHAR(255) NOT NULL, refreshtoken LONGTEXT NOT NULL, accesstoken LONGTEXT DEFAULT NULL, UNIQUE INDEX flow_identity_flownative_pixxio_domain_model_clientsecret (flowaccountidentifier), PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    /**
     * @param Schema $schema
     * @return void
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\DBAL\Migrations\AbortMigrationException
     */
    public function down(Schema $schema): void 
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != 'mysql', 'Migration can only be executed safely on "mysql".');

        $this->addSql('DROP TABLE flownative_pixxio_domain_model_clientsecret');
    }
}
