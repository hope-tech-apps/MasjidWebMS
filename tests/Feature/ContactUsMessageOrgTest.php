<?php

namespace Tests\Feature;

use App\Models\ContactUsAccount;
use App\Models\ContactUsMessage;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `contact_us_messages.masjid_id` — the organisation a message was sent to,
 * stored rather than derived.
 *
 * ## What went wrong with deriving it
 *
 * The admin inbox answered "whose message is this?" by joining
 * contacter -> mobileAppUser -> masjid_id, i.e. by asking which organisation the
 * SENDER'S HANDSET is registered with. That was the same answer as "who did they
 * write to" right up until the apps shipped an organisation switcher: a member
 * standing in a listed child writes to the child while their device stays pinned
 * to the parent. `ContactUsNotifier` was already using the organisation from the
 * route, so the message would be emailed to the child's staff and then listed
 * only for the parent's admins — delivered and unfindable at the same time,
 * with a success response at every step.
 *
 * ## What this file pins
 *
 * The column and its shape (asserted from the SCHEMA, never round-tripped — an
 * FK whose type does not match `masjids.id` is a MySQL error that SQLite will
 * never mention), the backfill that has to carry today's production rows across
 * without any of them vanishing from an inbox, and the two ways a null could
 * appear afterwards.
 */
class ContactUsMessageOrgTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
    }

    // ------------------------------------------------------------- the column

    #[Test]
    public function the_organisation_is_stored_in_a_column_of_the_right_type(): void
    {
        $this->assertTrue(Schema::hasColumn('contact_us_messages', 'masjid_id'));

        // Asserted against the key it points AT, not against a literal: a
        // foreign key declared narrower than its target is rejected outright by
        // MySQL and silently accepted by SQLite, so a round-trip test would go
        // green here and the migration would die on the deploy.
        $this->assertSame(
            Schema::getColumnType('masjids', 'id'),
            Schema::getColumnType('contact_us_messages', 'masjid_id'),
            'contact_us_messages.masjid_id must be the same type as masjids.id'
        );

        // The same shape as the column the old derivation went through, which is
        // the one this replaces.
        $this->assertSame(
            Schema::getColumnType('mobile_app_users', 'masjid_id'),
            Schema::getColumnType('contact_us_messages', 'masjid_id')
        );
    }

    #[Test]
    public function the_column_is_nullable_because_an_orphaned_sender_has_no_organisation_to_backfill_from(): void
    {
        // `mobile_app_users.masjid_id` is itself nullable and `on delete set
        // null`, so a message whose organisation was deleted has nothing to
        // derive from. Those rows are ALREADY invisible in every inbox — the old
        // whereHas skipped them — so a NOT NULL column here would buy nothing
        // and would abort the deploy over rows nobody can see.
        $columns = collect(Schema::getColumns('contact_us_messages'))->keyBy('name');

        $this->assertTrue($columns['masjid_id']['nullable']);
    }

    #[Test]
    public function the_inbox_index_is_hand_named_and_fits_mysqls_identifier_limit(): void
    {
        $indexes = collect(Schema::getIndexes('contact_us_messages'))->keyBy('name');

        $this->assertTrue(
            $indexes->has('contact_us_msgs_masjid_created_idx'),
            'the inbox reads this organisation newest-first; the index for it must be hand-named'
        );
        $this->assertSame(['masjid_id', 'created_at'], $indexes['contact_us_msgs_masjid_created_idx']['columns']);

        foreach ($indexes->keys() as $name) {
            $this->assertLessThanOrEqual(
                64,
                strlen((string) $name),
                "contact_us_messages.{$name} exceeds MySQL's 64-character identifier limit; SQLite would never say so"
            );
        }
    }

    // ------------------------------------------------------------ the backfill

    #[Test]
    public function the_backfill_files_every_message_where_the_inbox_used_to_find_it(): void
    {
        $a = $this->org('A');
        $b = $this->org('B');

        $accountA = $this->accountFor($a);
        $accountB = $this->accountFor($b);

        $preA = $this->preMigrationMessage($accountA, 'From A.');
        $preB = $this->preMigrationMessage($accountB, 'From B.');

        $this->replayTheRealMigration();

        $this->assertSame($a->id, $this->storedOrgOf($preA));
        $this->assertSame($b->id, $this->storedOrgOf($preB));
    }

    #[Test]
    public function replaying_the_migration_keeps_the_index_the_previous_one_added(): void
    {
        // Adding a foreign key to an existing table makes SQLite rebuild it, and
        // this repo has already lost an index to exactly that rebuild once. The
        // answered-state index predates this migration and must survive it.
        $this->replayTheRealMigration();

        $names = collect(Schema::getIndexes('contact_us_messages'))->pluck('name');

        $this->assertTrue($names->contains('contact_us_msgs_answered_created_idx'));
        $this->assertTrue($names->contains('contact_us_msgs_masjid_created_idx'));
        $this->assertTrue(Schema::hasColumn('contact_us_messages', 'answered_at'));
    }

    #[Test]
    public function the_backfill_leaves_alone_a_message_whose_organisation_was_already_deleted(): void
    {
        // The device outlived its organisation (`on delete set null`). There is
        // nothing to derive, and the row was invisible in every inbox before
        // this migration too — so "left null" is parity, not loss.
        $orphan = ContactUsAccount::create([
            'mobile_app_user_id' => MobileAppUser::create([
                'device_id' => 'orphaned-'.uniqid(),
                'masjid_id' => null,
                'user_agent' => 'test',
            ])->id,
            'email' => 'orphan@example.invalid',
            'name' => 'Orphan',
            'phone' => null,
        ]);

        $message = $this->preMigrationMessage($orphan, 'Nobody owns this.');

        $this->replayTheRealMigration();

        $this->assertNull($this->storedOrgOf($message));
    }

    #[Test]
    public function the_backfill_never_overwrites_an_organisation_somebody_set_deliberately(): void
    {
        // THE property that makes this migration safe to run after the org
        // switcher fix has already shipped: a message a switched member sent to
        // a listed CHILD carries the child, while their device says parent. A
        // backfill that re-derived would undo the fix on every existing row.
        $parent = $this->org('Parent');
        $child = $this->org('Child');
        $child->setParent($parent);

        $accountAtParent = $this->accountFor($parent);
        $sentToChild = $this->preMigrationMessage($accountAtParent, 'Written inside the child.', $child->id);

        $this->runTheBackfillAgain();

        $this->assertSame(
            $child->id,
            $this->storedOrgOf($sentToChild),
            'the backfill re-derived from the device and overwrote the organisation the sender chose'
        );
    }

    #[Test]
    public function running_the_backfill_twice_changes_nothing(): void
    {
        $a = $this->org('A');
        $message = $this->preMigrationMessage($this->accountFor($a), 'Once.');

        $this->replayTheRealMigration();
        $this->assertSame($a->id, $this->storedOrgOf($message));

        $this->runTheBackfillAgain();
        $this->assertSame($a->id, $this->storedOrgOf($message));
    }

    // ------------------------------------------------- no new nulls afterwards

    #[Test]
    public function a_writer_that_names_no_organisation_still_gets_one(): void
    {
        // Both intake controllers set masjid_id explicitly. This is the safety
        // net for every other writer — a console command, a seeder, a fixture —
        // because a message with no organisation is not an error anywhere, it is
        // simply a message that appears in no inbox.
        $a = $this->org('A');

        $message = ContactUsMessage::create([
            'contact_us_account_id' => $this->accountFor($a)->id,
            'contact_us_reason_id' => null,
            'message' => 'Filed by nobody in particular.',
        ]);

        $this->assertSame($a->id, $message->refresh()->masjid_id);
    }

    #[Test]
    public function an_explicit_organisation_beats_the_senders_device(): void
    {
        // The hook must never overrule a caller. The switched-member case is
        // precisely one where the device's organisation is the WRONG answer.
        $parent = $this->org('Parent');
        $child = $this->org('Child');
        $child->setParent($parent);

        $message = ContactUsMessage::create([
            'masjid_id' => $child->id,
            'contact_us_account_id' => $this->accountFor($parent)->id,
            'contact_us_reason_id' => null,
            'message' => 'Written inside the child.',
        ]);

        $this->assertSame($child->id, $message->refresh()->masjid_id);
    }

    // ------------------------------------------------------------- helpers

    /** The migration under test, loaded the way the migrator loads it. */
    private function theMigration(): Migration
    {
        $paths = glob(database_path('migrations/*_add_masjid_id_to_contact_us_messages_table.php'));

        $this->assertNotEmpty($paths, 'the masjid_id migration is missing');

        $migration = require $paths[0];

        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    /**
     * Take the schema back to before this migration — no column, no index — and
     * then migrate forward over the seeded rows. This is the actual deploy, run
     * against a stand-in for today's production data.
     */
    private function replayTheRealMigration(): void
    {
        $migration = $this->theMigration();

        $migration->down();
        $this->assertFalse(
            Schema::hasColumn('contact_us_messages', 'masjid_id'),
            'could not reach the pre-migration schema'
        );

        $migration->up();
        $this->assertTrue(Schema::hasColumn('contact_us_messages', 'masjid_id'));
    }

    /** The data half only, over a schema that already has the column. */
    private function runTheBackfillAgain(): void
    {
        $this->theMigration()->backfillFromTheDeviceTheInboxJoinedThrough();
    }

    /**
     * A message row as it exists BEFORE the column — written with the query
     * builder because the model's `creating` hook would fill in the very thing
     * the backfill is supposed to be tested on (and, mid-replay, the column is
     * not there to write to).
     *
     * @return int the message id
     */
    private function preMigrationMessage(ContactUsAccount $account, string $body, ?int $masjidId = null): int
    {
        $row = [
            'contact_us_account_id' => $account->id,
            'contact_us_reason_id' => null,
            'message' => $body,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($masjidId !== null) {
            $row['masjid_id'] = $masjidId;
        }

        return DB::table('contact_us_messages')->insertGetId($row);
    }

    private function storedOrgOf(int $messageId): ?int
    {
        $value = DB::table('contact_us_messages')->where('id', $messageId)->value('masjid_id');

        return $value === null ? null : (int) $value;
    }

    private function accountFor(Masjid $masjid): ContactUsAccount
    {
        $device = MobileAppUser::create([
            'device_id' => 'device-'.uniqid(),
            'masjid_id' => $masjid->id,
            'user_agent' => 'test',
        ]);

        return ContactUsAccount::create([
            'mobile_app_user_id' => $device->id,
            'email' => 'sender-'.uniqid().'@example.invalid',
            'name' => 'Sender',
            'phone' => null,
        ]);
    }

    private function org(string $name): Masjid
    {
        return Masjid::create([
            'name' => $name.' '.uniqid(),
            'email' => 'org-'.uniqid().'@example.invalid',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }
}
