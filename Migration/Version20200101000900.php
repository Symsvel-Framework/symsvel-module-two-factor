<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Portable module-owned tables; compiled by SymSvel Tooling for the selected DB. */
final class Version20200101000900 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $accounts = $schema->createTable('symsvel_2fa_accounts');
        $accounts->addColumn('id', 'integer', ['autoincrement' => true]);
        $accounts->addColumn('context', 'string', ['length' => 8]);
        $accounts->addColumn('principal_id', 'integer');
        $accounts->addColumn('secret_ciphertext', 'text');
        $accounts->addColumn('enabled', 'boolean', ['default' => false]);
        $accounts->addColumn('enabled_at', 'datetime_immutable', ['notnull' => false]);
        $accounts->addColumn('last_counter', 'bigint', ['notnull' => false]);
        $accounts->addColumn('recovery_hashes', 'json');
        $accounts->setPrimaryKey(['id']);
        $accounts->addUniqueIndex(['context', 'principal_id'], 'uniq_symsvel_2fa_principal');

        $challenges = $schema->createTable('symsvel_2fa_challenges');
        $challenges->addColumn('id', 'integer', ['autoincrement' => true]);
        $challenges->addColumn('context', 'string', ['length' => 8]);
        $challenges->addColumn('principal_id', 'integer');
        $challenges->addColumn('token_hash', 'string', ['length' => 64]);
        $challenges->addColumn('expires_at', 'datetime_immutable', ['precision' => 6]);
        $challenges->addColumn('created_at', 'datetime_immutable', ['precision' => 6]);
        $challenges->addColumn('used_at', 'datetime_immutable', ['precision' => 6, 'notnull' => false]);
        $challenges->addColumn('attempts', 'integer', ['default' => 0]);
        $challenges->setPrimaryKey(['id']);
        $challenges->addUniqueIndex(['token_hash'], 'uniq_symsvel_2fa_challenge_hash');
        $challenges->addIndex(['expires_at'], 'idx_symsvel_2fa_challenge_expiry');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('symsvel_2fa_challenges');
        $schema->dropTable('symsvel_2fa_accounts');
    }
}
