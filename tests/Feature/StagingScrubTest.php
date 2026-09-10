<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Donation;
use App\Models\Form;
use App\Models\Fund;
use App\Models\Group;
use App\Models\GroupPost;
use App\Models\Masjid;
use App\Models\MealMenu;
use App\Models\MealOrder;
use App\Models\User;
use App\Support\ScrubStrategies;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * End-to-end cover for `php artisan staging:scrub`.
 *
 * ## What is being pinned, and why each one
 *
 * The command is destructive and one-way. Every assertion below corresponds to
 * a way it could appear to work while leaving real personal data on a box that
 * more people can reach than production:
 *
 *  - **It refuses.** Three of the four guards are exercised by name, because a
 *    refusal that does not say WHICH condition failed is a refusal an operator
 *    starts disabling one condition at a time.
 *  - **A bound tenant does not narrow it.** The scrub runs with `TenantContext`
 *    bound to masjid A. If the command ever grew an Eloquent query, the global
 *    `BelongsToMasjid` scope would silently clean one organisation and leave
 *    every other tenant's data sitting there — with no error, because a
 *    scoped UPDATE that matched fewer rows still succeeds.
 *  - **Soft-deleted rows are scrubbed.** A `deleted_at` is a UI state, not a
 *    deletion; the bytes are still on disk and the person is still a person.
 *  - **Unique indexes survive.** `contacts(masjid_id, login_email)`,
 *    `masjids.phone`, `masjids.email`, `users.email` and
 *    `contact_cards(contact_id, last4)` are UNIQUE. One constant fake would
 *    abort the UPDATE on the second row, and the whole run would roll back.
 *  - **The kept estate is untouched.** `donation_receipts.serial_number` is a
 *    gap-free per-masjid sequence, `media` is the logo estate that empties the
 *    mobile feature drawer when it goes, `masjids.name` is what makes staging
 *    legible. A scrub that is too aggressive is a different bug, not a safer one.
 *
 * ## How this test pretends to be staging
 *
 * `APP_ENV` is flipped to `staging` and the default connection's configured
 * database NAME is set to `masjids_staging`. Only the name changes: the PDO is
 * already open on the suite's `:memory:` sqlite and keeps serving the schema
 * `RefreshDatabase` built. That is exactly the pair of facts guard 1 and guard 2
 * read, so the guards are exercised for real rather than stubbed out.
 */
class StagingScrubTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;

    private Masjid $masjidB;

    /** @var array<string, mixed> the pre-scrub values every assertion compares against */
    private array $before = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->masjidA = $this->makeMasjid('Masjid Al-Noor');
        $this->masjidB = $this->makeMasjid('Islamic Center of Elm');

        foreach ([$this->masjidA, $this->masjidB] as $masjid) {
            $this->seedTenant($masjid);
        }

        $this->seedGlobalRuntimeState();

        $this->before = [
            'contact_emails' => $this->column('contacts', 'email'),
            'contact_phones' => $this->column('contacts', 'phone'),
            'contact_logins' => $this->column('contacts', 'login_email'),
            'user_emails' => $this->column('users', 'email'),
            'masjid_emails' => $this->column('masjids', 'email'),
            'masjid_phones' => $this->column('masjids', 'phone'),
            'order_emails' => $this->column('meal_orders', 'customer_email'),
            'order_phones' => $this->column('meal_orders', 'customer_phone'),
            'response_emails' => $this->column('form_responses', 'respondent_email'),
            'receipt_serials' => DB::table('donation_receipts')->orderBy('id')->pluck('serial_number')->all(),
            'masjid_names' => $this->column('masjids', 'name'),
            'fund_names' => $this->column('funds', 'name'),
            'group_names' => $this->column('groups', 'name'),
            'masjid_owner_ids' => DB::table('masjids')->orderBy('id')->pluck('user_id')->all(),
        ] + $this->before;
    }

    /*
    |--------------------------------------------------------------------------
    | The guards
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_refuses_on_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.env' => 'production', 'database.connections.sqlite.database' => 'masjids_staging']);

        $this->artisan('staging:scrub', ['--i-understand-this-destroys-personal-data' => true])
            ->expectsOutputToContain('refused to run')
            ->expectsOutputToContain('app-env-is-staging')
            ->assertFailed();

        $this->assertNothingWasScrubbed();
    }

    #[Test]
    public function it_refuses_when_the_booted_and_configured_environments_disagree(): void
    {
        // The shell says staging; the compiled config (what `config:cache`
        // froze, and production IS config-cached) still says production. Picking
        // a winner here is how a stale bootstrap cache gets to decide whether
        // real data is destroyed, so both must agree or the command refuses.
        $this->app->detectEnvironment(fn () => 'staging');
        config(['app.env' => 'production', 'database.connections.sqlite.database' => 'masjids_staging']);

        $this->artisan('staging:scrub', ['--i-understand-this-destroys-personal-data' => true])
            ->expectsOutputToContain('app-env-is-staging')
            ->assertFailed();

        $this->assertNothingWasScrubbed();
    }

    #[Test]
    public function it_refuses_when_the_database_name_does_not_contain_staging(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');
        config(['app.env' => 'staging', 'database.connections.sqlite.database' => 'masjids_production']);

        $this->artisan('staging:scrub', ['--i-understand-this-destroys-personal-data' => true])
            ->expectsOutputToContain('database-name-contains-staging')
            ->assertFailed();

        $this->assertNothingWasScrubbed();
    }

    #[Test]
    public function it_refuses_when_the_host_is_the_managed_production_cluster(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');
        config([
            'app.env' => 'staging',
            'database.connections.sqlite.database' => 'masjids_staging',
            // Production's only database lives on DigitalOcean's managed MySQL.
            // A managed hostname means this connection is production's, whatever
            // the database is called.
            'database.connections.sqlite.host' => 'db-mysql-nyc1-00000-do-user-1-0.k.db.ondigitalocean.com',
        ]);

        $this->artisan('staging:scrub', ['--i-understand-this-destroys-personal-data' => true])
            ->expectsOutputToContain('db-host-is-not-the-managed-cluster')
            ->assertFailed();

        $this->assertNothingWasScrubbed();
    }

    #[Test]
    public function it_refuses_without_the_explicit_destroy_flag(): void
    {
        $this->enterStaging();

        $this->artisan('staging:scrub')
            ->expectsOutputToContain('explicit-destroy-flag')
            ->assertFailed();

        $this->assertNothingWasScrubbed();
    }

    #[Test]
    public function a_dry_run_prints_the_plan_and_writes_nothing(): void
    {
        $this->enterStaging();

        // No destroy flag: --dry-run is exempt from that guard alone, precisely
        // so "look before you leap" does not require typing the dangerous flag.
        $this->artisan('staging:scrub', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertNothingWasScrubbed();
        $this->assertGreaterThan(0, DB::table('sessions')->count(), 'A dry run must not delete rows.');
    }

    /*
    |--------------------------------------------------------------------------
    | The real thing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_scrubs_every_tenant_even_with_a_tenant_bound(): void
    {
        // Bind masjid A. If the command ever reached for Eloquent, the global
        // BelongsToMasjid scope would clean A and leave B — silently.
        app(TenantContext::class)->set($this->masjidA->id);

        $this->runScrub();

        foreach ([$this->masjidA, $this->masjidB] as $masjid) {
            $emails = DB::table('contacts')->where('masjid_id', $masjid->id)->whereNotNull('email')->pluck('email');

            $this->assertGreaterThan(0, $emails->count(), "masjid {$masjid->id} should still have contacts with an email");

            foreach ($emails as $email) {
                $this->assertStringEndsWith('@'.ScrubStrategies::EMAIL_DOMAIN, $email);
            }
        }
    }

    #[Test]
    public function no_original_email_or_phone_survives(): void
    {
        $this->runScrub();

        $pairs = [
            ['contacts', 'email', 'contact_emails'],
            ['contacts', 'phone', 'contact_phones'],
            ['contacts', 'login_email', 'contact_logins'],
            ['users', 'email', 'user_emails'],
            ['masjids', 'email', 'masjid_emails'],
            ['masjids', 'phone', 'masjid_phones'],
            ['meal_orders', 'customer_email', 'order_emails'],
            ['meal_orders', 'customer_phone', 'order_phones'],
            ['form_responses', 'respondent_email', 'response_emails'],
        ];

        foreach ($pairs as [$table, $column, $key]) {
            $originals = array_values(array_filter($this->before[$key], static fn ($v) => $v !== null && $v !== ''));

            $this->assertNotEmpty($originals, "the fixture should have seeded {$table}.{$column}");

            $survivors = DB::table($table)->whereIn($column, $originals)->count();

            $this->assertSame(0, $survivors, "{$table}.{$column} still holds {$survivors} production value(s).");
        }

        // And positively: the replacements are unreachable by construction.
        foreach (DB::table('contacts')->whereNotNull('email')->pluck('email') as $email) {
            $this->assertStringEndsWith('.invalid', $email, 'RFC 2606 .invalid can never resolve.');
        }

        foreach (DB::table('contacts')->whereNotNull('phone')->pluck('phone') as $phone) {
            $this->assertStringStartsWith(ScrubStrategies::PHONE_PREFIX, $phone, 'NANP 555-01xx is the fictional range.');
        }
    }

    #[Test]
    public function unique_indexes_survive_because_every_fake_is_row_unique(): void
    {
        $this->runScrub();

        foreach ([
            ['contacts', 'login_email'],
            ['users', 'email'],
            ['masjids', 'email'],
            ['masjids', 'phone'],
            ['contact_cards', 'last4'],
        ] as [$table, $column]) {
            $values = DB::table($table)->whereNotNull($column)->pluck($column)->all();

            $this->assertGreaterThan(1, count($values), "the fixture should have seeded more than one {$table}.{$column}");
            $this->assertSame(
                count($values),
                count(array_unique($values)),
                "{$table}.{$column} is UNIQUE; the scrub produced a duplicate, which would abort the real UPDATE on MySQL."
            );
        }
    }

    #[Test]
    public function soft_deleted_rows_are_scrubbed_too(): void
    {
        $trashedId = DB::table('contacts')
            ->where('masjid_id', $this->masjidA->id)
            ->whereNotNull('deleted_at')
            ->value('id');

        $this->assertNotNull($trashedId, 'the fixture should have seeded a soft-deleted contact');

        $this->runScrub();

        $row = DB::table('contacts')->where('id', $trashedId)->first();

        $this->assertNotNull($row->deleted_at, 'the scrub must not resurrect a soft-deleted row');
        $this->assertStringEndsWith('@'.ScrubStrategies::EMAIL_DOMAIN, $row->email);
        $this->assertStringStartsWith(ScrubStrategies::PHONE_PREFIX, $row->phone);
        $this->assertNull($row->notes);
    }

    #[Test]
    public function encrypted_columns_are_nulled_never_rewritten(): void
    {
        $this->runScrub();

        // Rewriting ciphertext with a literal makes every later read throw
        // DecryptException; NULL is the only value SQL can safely write.
        $this->assertSame(0, DB::table('users')->whereNotNull('two_factor_secret')->count());
        $this->assertSame(0, DB::table('users')->whereNotNull('two_factor_confirmed_at')->count());
        $this->assertSame(0, DB::table('contact_credentials')->whereNotNull('identifier')->count());

        foreach (['asc_key_p8', 'asc_key_id', 'asc_issuer_id', 'play_service_account_json', 'onesignal_rest_api_key'] as $column) {
            $this->assertSame(0, DB::table('masjid_app_publishing')->whereNotNull($column)->count(), "masjid_app_publishing.{$column}");
        }

        // The kept columns on the same rows are still there, so the row is not
        // a half-scrubbed shell.
        $this->assertSame(2, DB::table('contact_credentials')->whereNotNull('issuing_body')->count());
    }

    #[Test]
    public function drop_rows_tables_are_emptied(): void
    {
        $this->runScrub();

        foreach (array_keys(config('staging_scrub.drop_rows')) as $table) {
            $this->assertSame(0, DB::table($table)->count(), "`{$table}` is in drop_rows but still has rows.");
        }

        // And the fixture really did seed some of them, so the assertion above
        // is not vacuous.
        $this->assertGreaterThan(0, count($this->before['seeded_drop_tables']));
    }

    #[Test]
    public function the_kept_estate_is_untouched(): void
    {
        $this->runScrub();

        $this->assertSame($this->before['masjid_names'], $this->column('masjids', 'name'));
        $this->assertSame($this->before['fund_names'], $this->column('funds', 'name'));
        $this->assertSame($this->before['group_names'], $this->column('groups', 'name'));

        // The gap-free per-masjid receipt sequence. Renumbering or deleting a
        // row leaves a hole that ReceiptService's allocator then fights.
        $this->assertSame($this->before['receipt_serials'], DB::table('donation_receipts')->orderBy('id')->pluck('serial_number')->all());
        $this->assertSame(4, DB::table('donation_receipts')->count());

        // masjids.user_id is the source of the MySQL VIRTUAL generated column
        // `active_owner_user_id`, which backs masjids_active_owner_unique and
        // cannot be written. The command must leave the source alone.
        $this->assertSame($this->before['masjid_owner_ids'], DB::table('masjids')->orderBy('id')->pluck('user_id')->all());

        // Money maths is the point of staging.
        $this->assertSame(4, DB::table('donations')->where('intended_amount', 5000)->count(), 'two gifts per tenant, amounts untouched');
    }

    #[Test]
    public function form_response_data_keeps_its_keys_and_loses_its_answers(): void
    {
        $this->runScrub();

        $rows = DB::table('form_responses')->orderBy('id')->get();

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $decoded = json_decode($row->data, true);

            // The keys survive so the admin response table and the CSV export
            // still render a column per question.
            $this->assertSame(['full_name', 'guardian', 'medical'], array_keys($decoded));
            $this->assertSame(ScrubStrategies::JSON_PLACEHOLDER, $decoded['full_name']);
            $this->assertSame(ScrubStrategies::JSON_PLACEHOLDER, $decoded['guardian']['phone']);
            $this->assertSame(['phone', 'name'], array_keys($decoded['guardian']));

            $this->assertNull($row->ip_address);
            $this->assertNull($row->user_agent);
            $this->assertNull($row->admin_notes);
        }

        // forms.settings keeps its configuration and loses only the addresses.
        $settings = json_decode(DB::table('forms')->orderBy('id')->value('settings'), true);
        $this->assertTrue($settings['notify']);
        $this->assertStringEndsWith('@'.ScrubStrategies::EMAIL_DOMAIN, $settings['notify_email']);
    }

    #[Test]
    public function free_text_about_people_is_replaced(): void
    {
        $this->runScrub();

        $this->assertSame(ScrubStrategies::FREE_TEXT, DB::table('group_posts')->orderBy('id')->value('body'));
        $this->assertSame(0, DB::table('contacts')->whereNotNull('notes')->count());
        $this->assertSame(0, DB::table('donations')->whereNotNull('note')->count());
        $this->assertSame(0, DB::table('donations')->whereNotNull('stripe_charge_id')->count());
        $this->assertSame(0, DB::table('meal_orders')->whereNotNull('customer_notes')->count());

        // The classroom feed's TITLE is kept — it is the teacher's own heading,
        // and staging is unreadable if every post is called the same thing.
        $this->assertSame('Field trip Friday', DB::table('group_posts')->orderBy('id')->value('title'));
    }

    #[Test]
    public function outbound_capability_is_forced_off(): void
    {
        $this->runScrub();

        $masjid = DB::table('masjids')->where('id', $this->masjidA->id)->first();

        $this->assertSame(0, (int) $masjid->stripe_charges_enabled, 'staging must not believe it may charge');
        $this->assertSame(0, (int) $masjid->stripe_payouts_enabled);
        $this->assertNull($masjid->stripe_account_id, 'NULL is excluded from masjids_active_stripe_account_unique, so nulling every row is safe');
        $this->assertNull($masjid->tax_id);

        $sender = DB::table('masjid_sms_senders')->first();
        $this->assertSame('unregistered', $sender->registration_status, 'staging must not believe it may text');
        $this->assertNull($sender->phone_number);
        $this->assertNull($sender->messaging_service_sid);

        // The only push-address store in the schema.
        $this->assertSame(0, DB::table('mobile_app_users')->whereNotNull('onesignal_subscription_id')->count());
        $this->assertStringStartsWith('staging-device-', DB::table('mobile_app_users')->value('device_id'));
    }

    #[Test]
    public function only_the_whatsapp_social_rows_are_treated_as_phone_numbers(): void
    {
        $this->runScrub();

        $links = DB::table('masjid_social_media_links')->orderBy('id')->get()->keyBy('type');

        $this->assertStringStartsWith(ScrubStrategies::PHONE_PREFIX, $links['WhatsApp_Number']->value);
        $this->assertSame('https://facebook.com/alnoor', $links['Facebook']->value, 'a public page link is not personal data');
    }

    #[Test]
    public function running_it_twice_changes_nothing(): void
    {
        $this->runScrub();

        $first = DB::table('contacts')->orderBy('id')->get()->toJson();

        $this->runScrub();

        $this->assertSame($first, DB::table('contacts')->orderBy('id')->get()->toJson(), 'every fake is derived from the row id, so a re-run is a no-op');
    }

    #[Test]
    public function the_verification_pass_fails_loudly_when_an_address_survives(): void
    {
        $this->enterStaging();

        // Simulate the failure the verification pass exists to catch: a column
        // the config does not know about, holding a real address on `contacts`.
        // `signup_source` is a kept provenance flag, so nothing rewrites it.
        DB::table('contacts')->limit(1)->update(['signup_source' => 'imported-from someone@example.com']);

        $this->artisan('staging:scrub', ['--i-understand-this-destroys-personal-data' => true])
            ->expectsOutputToContain('VERIFICATION FAILED')
            ->expectsOutputToContain('contacts.signup_source')
            ->assertFailed();
    }

    #[Test]
    public function the_fake_phone_never_truncates_and_so_never_collides(): void
    {
        // Two row ids past the five-digit pad width. The obvious spelling of
        // the padding — MySQL's LPAD(id, 5, '0') — truncates here and hands
        // both rows the SAME number. On contacts.phone that silently gives two
        // people one number; on masjids.phone, which is UNIQUE, it aborts the
        // UPDATE and rolls the whole table back.
        foreach ([123456, 123457] as $id) {
            DB::table('contacts')->insert([
                'id' => $id,
                'masjid_id' => $this->masjidA->id,
                'first_name' => 'Big',
                'last_name' => 'Id',
                'phone' => '+1704555'.$id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->runScrub();

        $phones = DB::table('contacts')->whereIn('id', [123456, 123457])->orderBy('id')->pluck('phone')->all();

        $this->assertCount(2, $phones);
        $this->assertNotSame($phones[0], $phones[1], 'the pad must widen, not truncate');
        $this->assertSame(ScrubStrategies::PHONE_PREFIX.'123456', $phones[0]);
        $this->assertSame(ScrubStrategies::PHONE_PREFIX.'123457', $phones[1]);

        // And a small id still pads to the full width.
        $small = DB::table('contacts')->orderBy('id')->value('phone');
        $this->assertMatchesRegularExpression('/^\\+155501\\d{5}$/', $small);
    }

    /**
     * `date_shift` has no column in this schema — the only date of birth,
     * `appointment_requests.date_of_birth`, is an `encrypted` cast on a table
     * that is dropped wholesale. It stays implemented for the first plain-date
     * birthday column to land, so its two dialects are pinned here rather than
     * discovered under deadline.
     */
    #[Test]
    public function the_date_shift_strategy_compiles_for_both_drivers(): void
    {
        $this->assertSame(
            'DATE_ADD(dob, INTERVAL ((id % 61) - 30) DAY)',
            ScrubStrategies::expression('date_shift', 'people', 'dob', 'id', 'mysql'),
        );

        $this->assertSame(
            "datetime(dob, CAST(((id % 61) - 30) AS TEXT) || ' days')",
            ScrubStrategies::expression('date_shift', 'people', 'dob', 'id', 'sqlite'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures and helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Flip the facts guards 1 and 2 read. The PDO is untouched.
     *
     * Both environment names are set, because the guard checks both: the value
     * the framework booted with AND `config('app.env')`, which is what a
     * `config:cache` artefact would have frozen.
     */
    private function enterStaging(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');
        config(['app.env' => 'staging', 'database.connections.sqlite.database' => 'masjids_staging']);
    }

    private function runScrub(): void
    {
        $this->enterStaging();

        $this->artisan('staging:scrub', ['--i-understand-this-destroys-personal-data' => true])
            ->assertSuccessful();
    }

    /** @return list<mixed> */
    private function column(string $table, string $column): array
    {
        return DB::table($table)->orderBy('id')->pluck($column)->all();
    }

    private function assertNothingWasScrubbed(): void
    {
        $this->assertSame($this->before['contact_emails'], $this->column('contacts', 'email'));
        $this->assertSame($this->before['user_emails'], $this->column('users', 'email'));
        $this->assertSame($this->before['masjid_phones'], $this->column('masjids', 'phone'));
    }

    private function makeMasjid(string $name): Masjid
    {
        return Masjid::create([
            'name' => $name,
            'email' => 'office@'.strtolower(str_replace(' ', '', $name)).'.org',
            'phone' => '+1919'.random_int(1000000, 9999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '400 Real Street, Durham NC',
            'latitude' => 35.99,
            'longitude' => -78.9,
            'crm_enabled' => true,
            'tax_id' => '56-1234567',
            'statement_signatory' => 'Dr. Yusuf Abdullah',
            'stripe_account_id' => 'acct_'.uniqid(),
            'stripe_charges_enabled' => true,
            'stripe_payouts_enabled' => true,
            'google_maps_key' => 'AIzaSyRealLookingKey'.uniqid(),
        ]);
    }

    /** Everything one tenant owns, seeded through the real factories where they exist. */
    private function seedTenant(Masjid $masjid): void
    {
        $owner = User::factory()->create([
            'type' => 'MasjidAdmin',
            'name' => 'Yusuf Abdullah',
            'email' => 'yusuf-'.$masjid->id.'@realdomain.org',
            'phone' => '+1919'.random_int(1000000, 9999999),
            'two_factor_secret' => 'ciphertext-that-only-prods-APP_KEY-can-read',
            'two_factor_confirmed_at' => now(),
            'remember_token' => 'remember-me-'.uniqid(),
        ]);

        $masjid->user_id = $owner->id;
        $masjid->save();

        $contacts = Contact::factory()->count(3)->create([
            'masjid_id' => $masjid->id,
            'email' => fn () => 'family'.uniqid().'@realdomain.org',
            'phone' => '+1704'.random_int(1000000, 9999999),
            'notes' => 'Prefers to be called after Maghrib.',
            'sms_consent_evidence' => 'web form response #4182',
        ]);

        // login_email is UNIQUE(masjid_id, login_email); two rows per masjid
        // prove the fake stays row-unique.
        foreach ($contacts->take(2) as $i => $contact) {
            DB::table('contacts')->where('id', $contact->id)->update([
                'login_email' => 'parent'.$contact->id.'@realdomain.org',
                'password' => bcrypt('parent-password'),
                'password_set_at' => now(),
            ]);
        }

        // A soft-deleted contact is still a person.
        DB::table('contacts')->where('id', $contacts->last()->id)->update(['deleted_at' => now()]);

        foreach ([1, 2] as $n) {
            DB::table('contact_cards')->insert([
                'masjid_id' => $masjid->id,
                'contact_id' => $contacts->first()->id,
                'last4' => (string) (4000 + $n + $masjid->id),
                'brand' => 'visa',
                'note' => 'Sister Aisha usually pays with this one',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('contact_credentials')->insert([
            'masjid_id' => $masjid->id,
            'contact_id' => $contacts->first()->id,
            'kind' => 'medical_licence',
            'label' => 'RN',
            'issuing_body' => 'State of North Carolina',
            'identifier' => 'ciphertext-licence-number',
            'notes' => 'Volunteers at the clinic',
            'document_disk' => 'local',
            'document_path' => 'credentials/scan.pdf',
            'document_original_name' => 'Aisha-Rahman-RN.pdf',
            'document_mime_type' => 'application/pdf',
            'document_size_bytes' => 12345,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fund = Fund::factory()->create(['masjid_id' => $masjid->id, 'name' => 'Zakat']);

        $donations = Donation::factory()->count(2)->create([
            'masjid_id' => $masjid->id,
            'fund_id' => $fund->id,
            'contact_id' => $contacts->first()->id,
            'status' => 'succeeded',
            'stripe_charge_id' => 'ch_'.uniqid(),
            'stripe_payment_intent_id' => 'pi_'.uniqid(),
            'check_number' => '10'.random_int(10, 99),
            'note' => 'Cheque handed in by Br. Omar after Jummah',
        ]);

        foreach ($donations as $i => $donation) {
            DB::table('donation_receipts')->insert([
                'masjid_id' => $masjid->id,
                'donation_id' => $donation->id,
                'serial_number' => $i + 1,
                'issue_date' => now()->toDateString(),
                'gross_amount' => 5000,
                'eligible_amount' => 5000,
                'currency' => 'usd',
                'jurisdiction' => 'US',
                'status' => 'issued',
                'payment_method' => 'cheque',
                'payment_reference' => '10'.random_int(10, 99),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $menu = MealMenu::factory()->create(['masjid_id' => $masjid->id]);

        MealOrder::factory()->create([
            'masjid_id' => $masjid->id,
            'meal_menu_id' => $menu->id,
            'customer_name' => 'Omar Farouk',
            'customer_email' => 'omar'.$masjid->id.'@realdomain.org',
            'customer_phone' => '+1980'.random_int(1000000, 9999999),
            'customer_notes' => 'Severe peanut allergy',
        ]);

        $group = Group::factory()->create(['masjid_id' => $masjid->id, 'name' => 'Hifz Level 2 - '.$masjid->id]);

        GroupPost::factory()->create([
            'masjid_id' => $masjid->id,
            'group_id' => $group->id,
            'title' => 'Field trip Friday',
            'body' => 'Zayd and Maryam still need their permission slips signed.',
        ]);

        $form = Form::factory()->create([
            'masjid_id' => $masjid->id,
            'settings' => ['notify' => true, 'notify_email' => 'registrar@realdomain.org'],
        ]);

        DB::table('form_responses')->insert([
            'form_id' => $form->id,
            'masjid_id' => $masjid->id,
            'data' => json_encode([
                'full_name' => 'Maryam Siddiqui',
                'guardian' => ['phone' => '+19195551234', 'name' => 'Sara Siddiqui'],
                'medical' => 'asthma inhaler in her bag',
            ]),
            'respondent_name' => 'Sara Siddiqui',
            'respondent_email' => 'sara'.$masjid->id.'@realdomain.org',
            'respondent_phone' => '+1919'.random_int(1000000, 9999999),
            'admin_notes' => 'Family asked about the fee waiver',
            'ip_address' => '73.12.44.9',
            'user_agent' => 'Mozilla/5.0 (iPhone)',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('masjid_sms_senders')->insert([
            'masjid_id' => $masjid->id,
            'provider' => 'twilio',
            'phone_number' => '+1980'.random_int(1000000, 9999999),
            'messaging_service_sid' => 'MG'.uniqid(),
            'sender_label' => 'Masjid Office',
            'registration_status' => 'approved',
            'brand_registration_id' => 'BN'.uniqid(),
            'notes' => 'Registered under the imam\'s name',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('masjid_social_media_links')->insert([
            ['masjid_id' => $masjid->id, 'type' => 'Facebook', 'value' => 'https://facebook.com/alnoor', 'created_at' => now(), 'updated_at' => now()],
            ['masjid_id' => $masjid->id, 'type' => 'WhatsApp_Number', 'value' => '+19195559876', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('masjid_app_publishing')->insert([
            'masjid_id' => $masjid->id,
            'asc_key_p8' => 'ciphertext-p8',
            'asc_key_id' => 'ciphertext-key-id',
            'asc_issuer_id' => 'ciphertext-issuer',
            'play_service_account_json' => 'ciphertext-json',
            'onesignal_rest_api_key' => 'ciphertext-rest-key',
            'onesignal_app_id' => 'real-onesignal-app-id',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('mobile_app_users')->insert([
            'masjid_id' => $masjid->id,
            'device_id' => 'real-device-'.uniqid(),
            'onesignal_subscription_id' => 'os-sub-'.uniqid(),
            'user_agent' => 'Manara/1.4 (iPhone 15)',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('properties')->insert([
            'masjid_id' => $masjid->id,
            'name' => 'Unit B',
            'tenant_name' => 'Gregory Palmer',
            'address' => '18 Willow Lane, Durham NC 27703',
            'notes' => 'Pays late most months',
            'monthly_rent' => 1200,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The tables that hold framework runtime state and live send logs. All of
     * them are on the drop list; seeding them is what makes
     * `drop_rows_tables_are_emptied` a real assertion rather than a tautology.
     */
    private function seedGlobalRuntimeState(): void
    {
        DB::table('sessions')->insert([
            'id' => 'sess-'.uniqid(),
            'user_id' => DB::table('users')->min('id'),
            'ip_address' => '73.12.44.9',
            'user_agent' => 'Mozilla/5.0',
            'payload' => base64_encode(serialize(['email' => 'someone@realdomain.org'])),
            'last_activity' => time(),
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => 'someone@realdomain.org',
            'token' => hash('sha256', 'live-reset'),
            'created_at' => now(),
        ]);

        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id' => DB::table('users')->min('id'),
            'name' => 'mobile',
            'token' => hash('sha256', 'live-bearer'),
            'abilities' => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('appointment_requests')->insert([
            'masjid_id' => $this->masjidA->id,
            'applicant_name' => 'Hafsa Nur',
            'phone' => '+19195550000',
            'email' => 'hafsa@realdomain.org',
            'date_of_birth' => 'ciphertext-dob',
            'reason' => 'ciphertext-reason',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('notifications')->insert([
            'masjid_id' => $this->masjidA->id,
            'title' => 'Jummah reminder',
            'message' => 'Sent to 412 people',
            'onesignal_message_id' => 'os-'.uniqid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cache')->insert([
            'key' => 'masjid.1.announcements',
            'value' => serialize(['someone@realdomain.org']),
            'expiration' => time() + 600,
        ]);

        $this->before['seeded_drop_tables'] = ['sessions', 'password_reset_tokens', 'personal_access_tokens', 'appointment_requests', 'notifications', 'cache'];
    }
}
