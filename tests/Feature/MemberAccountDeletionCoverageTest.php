<?php

namespace Tests\Feature;

use App\Services\Member\MemberAccountDeletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The META-TEST for account deletion, in the shape of StagingScrubCoverageTest.
 *
 * MemberAccountDeletion hard-deletes a contact only when nothing the office holds
 * points at it, and several foreign keys CASCADE from `contacts`. Its lists of
 * "office data" are a transcription of today's schema. Nothing about a constant
 * notices a migration tomorrow that adds `payments.payer_contact_id` or
 * `contacts.date_of_birth`, and the result would be a member pressing "Delete
 * account" and silently taking an office record with them.
 *
 * So the schema is the source of truth: every column that can hold a contact id,
 * and every column on `contacts`, must be classified. When this goes red on a new
 * migration, DECIDE. Office data keeps a contact; that is the safe direction.
 */
class MemberAccountDeletionCoverageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_column_that_points_at_a_contact_is_classified(): void
    {
        $classified = array_merge(MemberAccountDeletion::OFFICE_RECORDS, MemberAccountDeletion::LOGIN_RECORDS);

        $tables = array_map(static fn (array $table) => (string) $table['name'], Schema::getTables());
        $this->assertGreaterThan(50, count($tables), 'The schema walk found almost no tables — the migrations did not run.');

        $unclassified = [];

        foreach ($tables as $table) {
            if ($table === 'contacts') {
                continue;
            }

            foreach (Schema::getColumnListing($table) as $column) {
                if ($column !== 'contact_id' && ! str_ends_with($column, '_contact_id')) {
                    continue;
                }

                if (! in_array($column, $classified[$table] ?? [], true)) {
                    $unclassified[] = "{$table}.{$column}";
                }
            }
        }

        $this->assertSame(
            [],
            $unclassified,
            'These columns can hold a contact id but MemberAccountDeletion does not know them. Add each to '
            . 'OFFICE_RECORDS (keeps the contact) or LOGIN_RECORDS (cleared by the deletion).'
        );
    }

    #[Test]
    public function every_contacts_column_is_either_written_by_sign_up_or_is_office_data(): void
    {
        $known = array_merge(MemberAccountDeletion::SIGNUP_COLUMNS, MemberAccountDeletion::OFFICE_COLUMNS);

        $this->assertSame(
            [],
            array_values(array_diff(Schema::getColumnListing('contacts'), $known)),
            'A contacts column is unclassified. Add it to OFFICE_COLUMNS unless app sign-up itself writes it.'
        );

        $this->assertSame(
            [],
            array_values(array_intersect(MemberAccountDeletion::SIGNUP_COLUMNS, MemberAccountDeletion::OFFICE_COLUMNS)),
            'A column cannot be both written by sign-up and office data.'
        );
    }

    /**
     * WHICH list a table is in, pinned. The schema walk above only asks that a
     * column be classified somewhere, so moving `registrations` from office data
     * to login plumbing would pass it, and the next member to press Delete
     * account would take a registrant's record with them. Changing either list
     * must be a decision made in this file too.
     */
    #[Test]
    public function the_office_and_login_lists_are_exactly_these_tables(): void
    {
        $this->assertSame([
            'contact_cards' => ['contact_id'],
            'contact_credentials' => ['contact_id'],
            'contact_login_events' => ['contact_id'],
            'donations' => ['contact_id'],
            'donation_subscriptions' => ['contact_id'],
            'group_memberships' => ['contact_id', 'guardian_of_contact_id'],
            'group_messages' => ['author_contact_id'],
            // 2026-09-21: a parent's reaction in a class conversation. Office
            // record, like the read bookmark below; only a guardian can write
            // one, and their guardian edge already keeps the contact.
            'group_message_reactions' => ['contact_id'],
            'group_thread_reads' => ['contact_id'],
            'group_threads' => ['created_by_contact_id'],
            // 2026-09-25: an order from the organisation's old Wix site,
            // imported as history. The office's record of a sale, like the
            // donations the same order produced.
            'historical_orders' => ['contact_id'],
            'meal_orders' => ['contact_id'],
            'registrants' => ['contact_id'],
            'registrations' => ['contact_id'],
        ], MemberAccountDeletion::OFFICE_RECORDS);

        $this->assertSame([
            'contact_login_codes' => ['contact_id'],
            // 2026-09-24: the office's 7-day portal invite links. Login
            // plumbing — a keyed digest, an address and three timestamps, and
            // nothing the office is keeping about the person. The GRANT it
            // followed is office data and sits in `contact_login_events` above,
            // which is what keeps the contact; a contact cannot have a row here
            // without having one there, so this line changes no outcome.
            'contact_portal_invites' => ['contact_id'],
            'contact_service_interests' => ['contact_id'],
            'mobile_app_users' => ['contact_id'],
        ], MemberAccountDeletion::LOGIN_RECORDS);

        $this->assertSame([
            'form_responses' => 'respondent_email',
            'appointment_requests' => 'email',
        ], MemberAccountDeletion::OFFICE_RECORDS_BY_ADDRESS);
    }

    #[Test]
    public function every_classified_table_and_column_still_exists(): void
    {
        foreach ([MemberAccountDeletion::OFFICE_RECORDS, MemberAccountDeletion::LOGIN_RECORDS] as $list) {
            foreach ($list as $table => $columns) {
                $this->assertTrue(Schema::hasTable($table), "MemberAccountDeletion names `{$table}`, which no longer exists.");

                foreach ($columns as $column) {
                    $this->assertTrue(Schema::hasColumn($table, $column), "MemberAccountDeletion names `{$table}.{$column}`, which no longer exists.");
                }
            }
        }

        foreach (MemberAccountDeletion::OFFICE_RECORDS_BY_ADDRESS as $table => $column) {
            $this->assertTrue(Schema::hasColumn($table, $column), "MemberAccountDeletion names `{$table}.{$column}`, which no longer exists.");
            $this->assertTrue(Schema::hasColumn($table, 'masjid_id'), "`{$table}` must be scoped by masjid_id to be matched by address.");
        }

        foreach (array_merge(MemberAccountDeletion::SIGNUP_COLUMNS, MemberAccountDeletion::OFFICE_COLUMNS) as $column) {
            $this->assertTrue(Schema::hasColumn('contacts', $column), "MemberAccountDeletion names `contacts.{$column}`, which no longer exists.");
        }

        $this->assertTrue(Schema::hasColumn('broadcasts', 'audience_contact_ids'));

        $this->assertSame(
            [],
            array_values(array_intersect(
                array_keys(MemberAccountDeletion::OFFICE_RECORDS),
                array_keys(MemberAccountDeletion::LOGIN_RECORDS),
            )),
            'A table cannot be both office data and login plumbing.'
        );
    }
}
