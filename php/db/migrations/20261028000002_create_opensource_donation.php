<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * One donation attempt: written `open` when the Checkout Session is created,
 * moved to `paid`, `expired` or `failed` by the webhook only.
 *
 * No name, no e-mail, no card data. Stripe keeps what Stripe needs; this table
 * only answers "how much came in for which project". `ip_hash` is a salted hash
 * for the rate limit on the public donate route, never the address.
 *
 * `project_id` has no foreign key: deleting a project must not need to delete
 * its money history first, and a cross-table FK would order this module's
 * migrations against the shared ledger for nothing.
 */
final class CreateOpensourceDonation extends AbstractMigration
{
    public function change(): void
    {
        $this->table('oss_donation', ['id' => true, 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('project_id', 'integer', ['signed' => false])
            ->addColumn('stripe_session_id', 'string', ['limit' => 255])
            ->addColumn('amount', 'integer', ['signed' => false])
            ->addColumn('currency', 'string', ['limit' => 3])
            ->addColumn('lang', 'string', ['limit' => 2, 'default' => 'de'])
            ->addColumn('status', 'string', ['limit' => 12, 'default' => 'open'])
            ->addColumn('ip_hash', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('paid_at', 'datetime', ['null' => true])
            ->addIndex(['stripe_session_id'], ['unique' => true, 'name' => 'uniq_oss_donation_session'])
            ->addIndex(['project_id', 'status'], ['name' => 'idx_oss_donation_project'])
            ->addIndex(['ip_hash', 'created_at'], ['name' => 'idx_oss_donation_ip'])
            ->create();
    }
}
