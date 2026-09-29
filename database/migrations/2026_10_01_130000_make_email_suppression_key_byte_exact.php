<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * email_suppressions.email_normalized — byte-exact on MySQL, so two spellings of
 * an address never share a row.
 *
 * ## The defect this closes
 *
 * The column was created `utf8mb4_unicode_ci` (the connection default, like
 * `contacts.email`, `contacts.login_email` and `users.email`). Under that
 * collation `'victim@gmail.com' = 'victim@gmaíl.com'`, and so does the unique
 * index over `(masjid_id, email_normalized)`. When somebody unsubscribed at the
 * look-alike spelling, the real person's own unsubscribe hit the unique index,
 * was logged and NOT written, and the page still said "done": a person who asked
 * to stop went on being mailed. Making the KEY byte-exact lets both spellings
 * hold a row of their own. Every reader and the one writer already pass the
 * address through EmailSuppressionService::normalize() (lower-case ASCII, trim),
 * so the stored string is the whole identity.
 *
 * ## What it runs, and where
 *
 * MySQL and MariaDB only:
 *
 *     ALTER TABLE email_suppressions MODIFY email_normalized VARCHAR(191) COLLATE utf8mb4_bin NOT NULL
 *
 * The type, length and nullability are the create migration's
 * (`$table->string('email_normalized', 191)`, no default, no comment), so only
 * the collation changes. SQLite is a no-op and correctly so: it compares TEXT
 * bytes unless a column says otherwise, and this one does not, so the suite's
 * table is already what production becomes. A test that needs the collation
 * spelled out rebuilds the table with `collate BINARY`.
 *
 * ## Indexes
 *
 * None is re-created. MODIFY rebuilds every index over the column under the new
 * collation and keeps its name: `email_suppressions_tenant_address_unique` (39
 * characters; `email_normalized` is its second column) and
 * `email_suppressions_address_index` (32), both well under MySQL's 64-character
 * identifier limit. On production the table held no rows when this was written
 * (read by the point, 2026-09-29), so the rebuild is instant. On a large table
 * it would rebuild the table, but it never rewrites a value.
 *
 * ## The one rule this column adds
 *
 * email_normalized is utf8mb4_bin: compare it only with PHP literals. A JOIN, a
 * subquery, `whereColumn` or `EXISTS` that sets it against a unicode_ci email
 * column (`contacts.email`, `contacts.login_email`, `users.email`, a form
 * response's address) fails on MySQL with "Illegal mix of collations", and SQLite
 * cannot show it. Pluck the suppressed keys and filter in PHP with
 * EmailSuppressionService::normalize(), as BroadcastAudienceResolver does.
 *
 * ## down()
 *
 * Restores `utf8mb4_unicode_ci`. If two rows differ only by accent or case by
 * then, MySQL refuses the ALTER with a duplicate-key error naming the pair and
 * changes nothing (the statement is atomic): collapsing them would be choosing
 * whose opt-out survives, which is an operator's decision and not a rollback's.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isMySqlFamily()) {
            return;
        }

        DB::statement('ALTER TABLE email_suppressions MODIFY email_normalized VARCHAR(191) COLLATE utf8mb4_bin NOT NULL');
    }

    public function down(): void
    {
        if (! $this->isMySqlFamily()) {
            return;
        }

        DB::statement('ALTER TABLE email_suppressions MODIFY email_normalized VARCHAR(191) COLLATE utf8mb4_unicode_ci NOT NULL');
    }

    private function isMySqlFamily(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
