<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * cable_spool: replace vendor_product_code (duplicate of stock code) with family from cable_type.
 */
final class Version20260419200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'cable_spool: vendor_product_code -> family (denorm rack line)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE cable_spool ADD family VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER reel_number");
        $this->addSql('UPDATE cable_spool s INNER JOIN cable_type t ON t.id = s.cable_type_id SET s.family = t.family');
        $this->addSql('ALTER TABLE cable_spool DROP vendor_product_code');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cable_spool ADD vendor_product_code VARCHAR(64) DEFAULT NULL AFTER reel_number');
        $this->addSql('UPDATE cable_spool s INNER JOIN cable_type t ON t.id = s.cable_type_id SET s.vendor_product_code = t.code');
        $this->addSql('ALTER TABLE cable_spool DROP family');
    }
}
