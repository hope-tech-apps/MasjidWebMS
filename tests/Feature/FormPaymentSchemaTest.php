<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Masjid;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The festival's schema, pinned where SQLite would otherwise wave it through.
 *
 * SQLite enforces neither VARCHAR lengths nor MySQL's 64-character identifier
 * cap, so a green round-trip proves nothing about either
 * (.claude/rules/shipping.md, .claude/rules/migrations.md). What CAN be pinned on
 * SQLite is pinned here:
 *
 *  - column TYPES: money is integer cents, never decimal or float; instants are
 *    datetimes; and a staff code cannot be stored without an expiry;
 *  - every index on the two tables is hand-named and fits in 64 characters;
 *  - the unique indexes refuse what they exist to refuse, and NULLs — every
 *    legacy row — never collide;
 *  - a row written before the migration means "no money leg", and down() really
 *    takes the leg off again;
 *  - every new row is minted a uuid, and a client cannot choose it.
 */
class FormPaymentSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** The migration that gave form_responses its money leg; replayed below. */
    private const MONEY_MIGRATION = '2026_09_11_120000_add_payment_columns_to_form_responses.php';

    /** Every column that migration adds, and what a row written before it holds there. */
    private const LEGACY_VALUES = [
        'uuid' => null,
        'client_submission_key' => null,
        'client_payload_hash' => null,
        'payment_method' => null,
        'payment_status' => null,
        'currency' => null,
        'amount_due_minor' => null,
        'fee_covered_minor' => 0,
        'total_minor' => null,
        'paid_at' => null,
        'marked_paid_by_user_id' => null,
        'stripe_checkout_session_id' => null,
        'stripe_payment_intent_id' => null,
        'idempotency_key' => null,
        'staff_code_id' => null,
        'collected_at' => null,
        'collected_by_user_id' => null,
        'status_changed_by_user_id' => null,
        'status_changed_at' => null,
    ];

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

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);
    }

    private function makeForm(Masjid $masjid, ?array $settings = null): Form
    {
        return Form::create([
            'masjid_id' => $masjid->id,
            'slug' => 'festival-' . uniqid(),
            'name' => 'Fall Festival',
            'schema' => [
                'sections' => [
                    ['id' => 'contact', 'title' => 'You', 'fields' => [
                        ['name' => 'fullName', 'label' => 'Full name', 'type' => 'text', 'required' => true],
                    ]],
                    ['id' => 'attendees', 'title' => 'Attendees', 'repeatable' => true, 'minEntries' => 1, 'fields' => [
                        ['name' => 'attendeeName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ]],
                ],
            ],
            'settings' => $settings ?? [
                'identity' => ['name' => 'fullName'],
                'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'attendees'],
                'payment' => ['online' => true, 'staffCodes' => true],
            ],
            'is_active' => true,
        ]);
    }

    /** One INSERT, money columns included, the way the submit path will write a row. */
    private function makeResponse(Form $form, array $money = []): FormResponse
    {
        $response = new FormResponse([
            'form_id' => $form->id,
            'masjid_id' => $form->masjid_id,
            'data' => ['fullName' => 'Amal Yusuf', 'attendees' => [['attendeeName' => 'Amal']]],
            'respondent_name' => 'Amal Yusuf',
            'entry_count' => 1,
            'amount_due' => 15,
            'status' => 'new',
            'submitted_at' => now(),
        ]);

        $response->forceFill($money)->save();

        return $response;
    }

    private function assertRefusedAsDuplicate(callable $write, string $why): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('unique', $e->getMessage(), $why);

            return;
        }

        $this->fail('Not refused: ' . $why);
    }

    #[Test]
    public function money_is_integer_cents_and_every_instant_is_a_datetime(): void
    {
        // SQLite's names for the types, since that is what the suite runs on. The
        // family is what matters: `integer` for money — never `numeric` (decimal)
        // or a float — `datetime` for instants, `varchar` for handles and digests.
        $expected = [
            'form_responses' => [
                'amount_due_minor' => 'integer',
                'fee_covered_minor' => 'integer',
                'total_minor' => 'integer',
                'staff_code_id' => 'integer',
                'marked_paid_by_user_id' => 'integer',
                'collected_by_user_id' => 'integer',
                'status_changed_by_user_id' => 'integer',
                'paid_at' => 'datetime',
                'collected_at' => 'datetime',
                'status_changed_at' => 'datetime',
                'uuid' => 'varchar',
                'client_submission_key' => 'varchar',
                'client_payload_hash' => 'varchar',
                'payment_method' => 'varchar',
                'payment_status' => 'varchar',
                'currency' => 'varchar',
                'stripe_checkout_session_id' => 'varchar',
                'stripe_payment_intent_id' => 'varchar',
                'idempotency_key' => 'varchar',
                // Kept exactly as it was: the display amount, in dollars.
                'amount_due' => 'numeric',
            ],
            'form_staff_codes' => [
                'masjid_id' => 'integer',
                'form_id' => 'integer',
                'holder_name' => 'varchar',
                'code_hash' => 'varchar',
                'code_hint' => 'varchar',
                'expires_at' => 'datetime',
                'revoked_at' => 'datetime',
                'revoked_by_user_id' => 'integer',
                'created_by_user_id' => 'integer',
                'bound_device_id' => 'varchar',
                'bound_at' => 'datetime',
                'binding_count' => 'integer',
                'binding_released_at' => 'datetime',
                'binding_released_by_user_id' => 'integer',
                'use_count' => 'integer',
                'last_used_at' => 'datetime',
            ],
        ];

        foreach ($expected as $table => $columns) {
            foreach ($columns as $column => $type) {
                $this->assertSame($type, Schema::getColumnType($table, $column), "{$table}.{$column}");
            }
        }

        $codes = collect(Schema::getColumns('form_staff_codes'))->keyBy('name');

        $this->assertFalse($codes['expires_at']['nullable'], 'a staff code that never expires must not be representable');
        $this->assertFalse($codes['code_hash']['nullable']);
    }

    #[Test]
    public function every_index_is_hand_named_and_fits_mysqls_identifier_limit(): void
    {
        $expected = [
            'form_responses' => [
                'form_responses_uuid_unique' => [['uuid'], true],
                'form_responses_idempotency_key_unique' => [['idempotency_key'], true],
                FormResponse::CLIENT_KEY_UNIQUE_INDEX => [['form_id', 'client_submission_key'], true],
                'form_resp_form_payment_idx' => [['form_id', 'payment_status'], false],
                'form_resp_form_staff_code_idx' => [['form_id', 'staff_code_id'], false],
                'form_resp_form_collected_idx' => [['form_id', 'collected_at'], false],
                'form_resp_collected_by_idx' => [['collected_by_user_id'], false],
                'form_resp_marked_paid_by_idx' => [['marked_paid_by_user_id'], false],
            ],
            'form_staff_codes' => [
                'form_staff_codes_form_hash_unique' => [['form_id', 'code_hash'], true],
                'form_staff_codes_masjid_form_idx' => [['masjid_id', 'form_id'], false],
            ],
        ];

        foreach ($expected as $table => $named) {
            $indexes = collect(Schema::getIndexes($table))->keyBy('name');

            foreach ($named as $name => [$columns, $unique]) {
                $this->assertTrue($indexes->has($name), "{$table} is missing the hand-named index {$name}");
                $this->assertSame($columns, $indexes[$name]['columns'], $name);
                $this->assertSame($unique, (bool) $indexes[$name]['unique'], $name);
            }

            foreach ($indexes->keys() as $name) {
                $this->assertLessThanOrEqual(
                    64,
                    strlen((string) $name),
                    "{$table}.{$name} is longer than MySQL's 64-character identifier limit; SQLite would never say so"
                );
            }
        }
    }

    #[Test]
    public function the_unique_indexes_refuse_a_second_row_and_never_collide_on_null(): void
    {
        $masjid = $this->makeMasjid();
        $form = $this->makeForm($masjid);
        $other = $this->makeForm($masjid);

        // Rows shaped like every row before the festival: all three handles NULL
        // except the minted uuid. Any number of them coexist.
        $this->makeResponse($form);
        $this->makeResponse($form);
        $this->assertSame(2, FormResponse::where('form_id', $form->id)->whereNull('client_submission_key')->count());

        $first = $this->makeResponse($form, ['client_submission_key' => 'render-1', 'idempotency_key' => 'form_response_a']);

        $this->assertRefusedAsDuplicate(
            fn () => $this->makeResponse($form, ['client_submission_key' => 'render-1']),
            'the same render key twice on one form is the double-tap the index exists to stop'
        );

        // The same key on another form is somebody else's render.
        $this->makeResponse($other, ['client_submission_key' => 'render-1']);

        $this->assertRefusedAsDuplicate(
            fn () => $this->makeResponse($other, ['idempotency_key' => 'form_response_a']),
            'an idempotency key names one Stripe call on one row'
        );

        $second = $this->makeResponse($form);

        $this->assertRefusedAsDuplicate(
            fn () => DB::table('form_responses')->where('id', $second->id)->update(['uuid' => $first->uuid]),
            'the uuid is a bearer handle; two rows may never share one'
        );
    }

    #[Test]
    public function a_row_written_before_the_migration_means_no_money_leg(): void
    {
        $masjid = $this->makeMasjid();
        // A free form, like every form before the festival.
        $form = $this->makeForm($masjid, ['identity' => ['name' => 'fullName']]);

        $migration = require database_path('migrations/' . self::MONEY_MIGRATION);
        $migration->down();

        $this->assertFalse(Schema::hasColumn('form_responses', 'payment_status'), 'down() takes the money leg off');
        $this->assertFalse(Schema::hasColumn('form_responses', 'uuid'));

        // Written the way every pre-festival row was: the money columns do not exist.
        $id = DB::table('form_responses')->insertGetId([
            'form_id' => $form->id,
            'masjid_id' => $masjid->id,
            'data' => json_encode(['fullName' => 'Amal Yusuf']),
            'respondent_name' => 'Amal Yusuf',
            'entry_count' => 1,
            'status' => 'confirmed',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $row = (array) DB::table('form_responses')->find($id);

        foreach (self::LEGACY_VALUES as $column => $value) {
            $this->assertArrayHasKey($column, $row, "up() did not add {$column}");

            if ($value === null) {
                $this->assertNull($row[$column], "a legacy row must hold NULL in {$column}");
            } else {
                $this->assertSame($value, (int) $row[$column], "a legacy row must hold {$value} in {$column}");
            }
        }

        $response = FormResponse::find($id);

        $this->assertNull($response->uuid, 'no uuid is back-filled, so a legacy row never enters a money path');
        $this->assertFalse($response->hasMoneyLeg());
        $this->assertNull($response->owedMinor());
        $this->assertTrue($response->isSettled(), 'a row on a free form owed nothing, so it is settled');
    }

    #[Test]
    public function every_new_row_is_minted_a_uuid_the_client_cannot_choose(): void
    {
        $form = $this->makeForm($this->makeMasjid());

        $this->assertNotContains('uuid', (new FormResponse())->getFillable(), 'the bearer handle is minted, never taken from input');

        $a = $this->makeResponse($form);
        $b = $this->makeResponse($form);

        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

        $this->assertMatchesRegularExpression($pattern, $a->uuid);
        $this->assertMatchesRegularExpression($pattern, $b->uuid);
        $this->assertNotSame($a->uuid, $b->uuid);
        $this->assertSame($a->uuid, DB::table('form_responses')->where('id', $a->id)->value('uuid'));

        // A uuid already set (a test fixture, an import) is kept, not re-minted.
        $kept = new FormResponse([
            'form_id' => $form->id,
            'masjid_id' => $form->masjid_id,
            'data' => ['fullName' => 'Zaid'],
            'status' => 'new',
            'submitted_at' => now(),
        ]);
        $kept->forceFill(['uuid' => '6f1c8a52-0b1e-4c1a-9a57-3f2d0e4b9c11'])->save();

        $this->assertSame('6f1c8a52-0b1e-4c1a-9a57-3f2d0e4b9c11', $kept->fresh()->uuid);
    }
}
