<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\ContactTag;
use App\Models\EmailSuppression;
use App\Models\ImportLink;
use App\Models\Masjid;
use App\Models\SmsSuppression;
use App\Services\Broadcast\BroadcastAudienceResolver;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `wix:import-contacts` — the staged import of a Wix contacts export
 * (App\Services\Imports\WixContactImport).
 *
 * Every fixture is SYNTHETIC: invented names and @example.test addresses in
 * the shape of the Wix Contacts v4 objects the MEC export holds. No real record
 * is, or may ever be, copied into this file.
 */
class WixContactImportTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private Masjid $other;

    /** @var list<string> */
    private array $files = [];

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

        app(TenantContext::class)->forgetTenant();

        $this->masjid = $this->makeMasjid();
        $this->other = $this->makeMasjid();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
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
            'crm_enabled' => true,
        ]);
    }

    /** One synthetic Wix Contacts v4 object. */
    private function wix(string $id, array $o = []): array
    {
        $email = $o['email'] ?? null;
        $phone = $o['phone'] ?? null;

        $contact = [
            'id' => $id,
            'revision' => $o['revision'] ?? 1,
            'source' => ['sourceType' => 'IMPORT'],
            'createdDate' => $o['created'] ?? '2019-03-02T10:00:00.000Z',
            'updatedDate' => $o['updated'] ?? '2024-05-01T10:00:00.000Z',
            'info' => [
                'name' => ['first' => $o['first'] ?? 'Test', 'last' => $o['last'] ?? 'Person'],
                'labelKeys' => ['items' => $o['labels'] ?? []],
                'extendedFields' => ['items' => []],
            ],
        ];

        if ($email !== null) {
            $contact['info']['emails'] = ['items' => [['tag' => 'UNTAGGED', 'email' => $email, 'primary' => true]]];
            $contact['primaryEmail'] = [
                'email' => $email,
                'subscriptionStatus' => $o['sub'] ?? 'SUBSCRIBED',
                'deliverabilityStatus' => $o['deliv'] ?? 'VALID',
            ];
            $contact['primaryInfo']['email'] = $email;
        }

        if ($phone !== null) {
            $contact['info']['phones'] = ['items' => [['tag' => 'MOBILE', 'phone' => $phone, 'formattedPhone' => $phone, 'primary' => true]]];
            $contact['primaryPhone'] = [
                'phone' => $phone,
                'formattedPhone' => $phone,
                'subscriptionStatus' => $o['sms'] ?? 'NO_SUBSCRIPTION_STATUS',
                'deliverabilityStatus' => 'NOT_SET',
            ];
            $contact['primaryInfo']['phone'] = $phone;
        }

        foreach ($o['extra_emails'] ?? [] as $extra) {
            $contact['info']['emails']['items'][] = ['tag' => 'WORK', 'email' => $extra, 'primary' => false];
        }
        foreach ($o['extra_phones'] ?? [] as $extra) {
            $contact['info']['phones']['items'][] = ['tag' => 'HOME', 'phone' => $extra, 'formattedPhone' => $extra, 'primary' => false];
        }
        foreach ($o['addresses'] ?? [] as $i => $address) {
            $contact['info']['addresses']['items'][] = ['id' => "a{$i}", 'tag' => 'BILLING', 'address' => ['formattedAddress' => $address]];
        }

        if ($o['member'] ?? false) {
            $contact['memberInfo'] = ['memberId' => $id, 'status' => 'APPROVED'];
        }

        return $contact;
    }

    private function file(array $contacts): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wixc') . '.json';
        file_put_contents($path, json_encode(['source' => 'test', 'count' => count($contacts), 'contacts' => $contacts]));
        $this->files[] = $path;

        return $path;
    }

    private function labelsFile(array $names): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wixl') . '.json';
        $labels = [];
        foreach ($names as $key => $name) {
            $labels[] = ['key' => $key, 'displayName' => $name, 'labelType' => 'USER_DEFINED'];
        }
        file_put_contents($path, json_encode(['labels' => $labels]));
        $this->files[] = $path;

        return $path;
    }

    /** @return array{0: int, 1: string} exit code and output; the tenant is unbound again afterwards. */
    private function import(?string $file, array $options = [], ?Masjid $into = null): array
    {
        $args = ['--masjid' => ($into ?? $this->masjid)->id] + $options;
        if ($file !== null) {
            $args['file'] = $file;
        }

        $code = Artisan::call('wix:import-contacts', $args);
        $output = Artisan::output();
        app(TenantContext::class)->forgetTenant();

        return [$code, $output];
    }

    private function contacts(?Masjid $masjid = null)
    {
        return Contact::withoutMasjidScope()->withTrashed()->where('masjid_id', ($masjid ?? $this->masjid)->id);
    }

    private function contactWithEmail(string $email): ?Contact
    {
        return $this->contacts()->where('email', $email)->first();
    }

    /** @return array<string, string> active suppressions of our organisation: address => reason */
    private function suppressions(?Masjid $masjid = null): array
    {
        return EmailSuppression::withoutMasjidScope()->where('masjid_id', ($masjid ?? $this->masjid)->id)
            ->whereNull('released_at')->pluck('reason', 'email_normalized')->all();
    }

    #[Test]
    public function a_dry_run_prints_counts_only_and_writes_nothing(): void
    {
        $file = $this->file([
            $this->wix('w1', ['first' => 'Amina', 'last' => 'Quux', 'email' => 'amina.quux@example.test', 'phone' => '(336) 555-0111', 'labels' => ['custom.volunteer']]),
            $this->wix('w2', ['first' => 'Bilal', 'last' => 'Zork', 'email' => 'bilal.zork@example.test', 'sub' => 'NOT_SET']),
        ]);

        [$code, $output] = $this->import($file);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertMatchesRegularExpression('/New contacts to create\s*\|\s*2/', $output);

        foreach (['Amina', 'Quux', 'amina.quux', 'Bilal', 'Zork', 'bilal.zork', '555-0111', '5550111'] as $personal) {
            $this->assertStringNotContainsString($personal, $output, 'the output names nobody');
        }

        $this->assertSame(0, $this->contacts()->count());
        $this->assertSame(0, EmailSuppression::withoutMasjidScope()->count());
        $this->assertSame(0, ContactTag::withoutMasjidScope()->count());
        $this->assertSame(0, ImportLink::withoutMasjidScope()->count());
    }

    #[Test]
    public function everyone_is_imported_and_only_subscribed_valid_addresses_stay_mailable(): void
    {
        $file = $this->file([
            $this->wix('w1', ['email' => 'mailable@example.test']),
            $this->wix('w2', ['email' => 'neverset@example.test', 'sub' => 'NOT_SET']),
            $this->wix('w3', ['email' => 'unsubscribed@example.test', 'sub' => 'UNSUBSCRIBED']),
            $this->wix('w4', ['email' => 'bounced@example.test', 'deliv' => 'BOUNCED']),
            $this->wix('w5', ['email' => 'inactive@example.test', 'deliv' => 'INACTIVE']),
            $this->wix('w6', ['email' => 'spam@example.test', 'deliv' => 'SPAM_COMPLAINT']),
            $this->wix('w7', ['email' => 'unknown@example.test', 'deliv' => 'NOT_SET']),
            $this->wix('w8', ['email' => 'pending@example.test', 'sub' => 'PENDING']),
            $this->wix('w9', ['phone' => '(336) 555-0199']),
        ]);

        [$code] = $this->import($file, ['--execute' => true, '--batch' => 'b1']);

        $this->assertSame(0, $code);
        $this->assertSame(9, $this->contacts()->count(), 'every Wix contact is imported');
        $this->assertSame([
            'bounced@example.test' => EmailSuppression::REASON_BOUNCE,
            'inactive@example.test' => EmailSuppression::REASON_NOT_OPTED_IN,
            'neverset@example.test' => EmailSuppression::REASON_NOT_OPTED_IN,
            'pending@example.test' => EmailSuppression::REASON_NOT_OPTED_IN,
            'spam@example.test' => EmailSuppression::REASON_COMPLAINT,
            'unknown@example.test' => EmailSuppression::REASON_NOT_OPTED_IN,
            'unsubscribed@example.test' => EmailSuppression::REASON_IMPORTED_OPT_OUT,
        ], collect($this->suppressions())->sortKeys()->all());

        // What a broadcast to "everyone" would actually email.
        app(TenantContext::class)->set($this->masjid->id);
        $everyone = (new Broadcast())->forceFill(['masjid_id' => $this->masjid->id, 'audience' => 'everyone']);
        $this->assertSame(['mailable@example.test'], app(BroadcastAudienceResolver::class)->emailRecipients($everyone)->pluck('email')->all());

        // No SMS consent is invented for anybody, and the badge mirrors the list.
        $this->assertSame(0, $this->contacts()->where('sms_opt_in', true)->count());
        $this->assertNotNull($this->contactWithEmail('neverset@example.test')->email_opted_out_at);
        $this->assertNull($this->contactWithEmail('mailable@example.test')->email_opted_out_at);
        $this->assertSame('b1', $this->contactWithEmail('mailable@example.test')->import_batch);
    }

    #[Test]
    public function an_existing_contact_is_matched_by_email_and_left_exactly_as_it_was(): void
    {
        $existing = Contact::factory()->create([
            'masjid_id' => $this->masjid->id, 'first_name' => 'Office', 'last_name' => 'Typed',
            'email' => 'Known@Example.test', 'phone' => null, 'notes' => 'kept',
        ]);
        $before = $existing->fresh()->only(['first_name', 'last_name', 'email', 'phone', 'notes', 'import_batch']);

        $file = $this->file([$this->wix('w1', [
            'first' => 'Different', 'email' => 'known@example.test', 'phone' => '(336) 555-0100',
            'sub' => 'NOT_SET', 'labels' => ['custom.volunteer'],
        ])]);

        $this->import($file, ['--execute' => true, '--batch' => 'b1']);

        $this->assertSame(1, $this->contacts()->count());
        $this->assertSame($before, $existing->fresh()->only(['first_name', 'last_name', 'email', 'phone', 'notes', 'import_batch']));
        $this->assertSame(['volunteer'], $existing->tags()->pluck('name')->all());
        $this->assertSame([], $this->suppressions(), 'a Wix "never subscribed" does not silence somebody already on the Manara list');
    }

    #[Test]
    public function a_wix_unsubscribe_or_spam_complaint_suppresses_an_existing_contacts_address(): void
    {
        Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'left@example.test']);
        Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'complained@example.test']);

        $this->import($this->file([
            $this->wix('w1', ['email' => 'left@example.test', 'sub' => 'UNSUBSCRIBED']),
            $this->wix('w2', ['email' => 'complained@example.test', 'deliv' => 'SPAM_COMPLAINT']),
        ]), ['--execute' => true, '--batch' => 'b1']);

        $this->assertSame([
            'complained@example.test' => EmailSuppression::REASON_COMPLAINT,
            'left@example.test' => EmailSuppression::REASON_IMPORTED_OPT_OUT,
        ], collect($this->suppressions())->sortKeys()->all());
        $this->assertSame(2, $this->contacts()->count());
    }

    #[Test]
    public function a_phone_only_wix_contact_matches_the_one_existing_contact_with_that_number(): void
    {
        $existing = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'x@example.test', 'phone' => '336.555.0199']);

        $this->import($this->file([$this->wix('w1', ['phone' => '+1 (336) 555-0199', 'labels' => ['custom.volunteer']])]),
            ['--execute' => true, '--batch' => 'b1']);

        $this->assertSame(1, $this->contacts()->count());
        $this->assertSame(['volunteer'], $existing->tags()->pluck('name')->all());
    }

    #[Test]
    public function a_phone_several_existing_contacts_share_is_not_guessed_at(): void
    {
        Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'one@example.test', 'phone' => '(336) 555-0150']);
        Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'two@example.test', 'phone' => '336-555-0150']);

        [, $output] = $this->import($this->file([$this->wix('w1', ['phone' => '(336) 555-0150'])]), ['--execute' => true, '--batch' => 'b1']);

        $this->assertSame(3, $this->contacts()->count());
        $this->assertMatchesRegularExpression('/a phone matched several contacts, so created separately\s*\|\s*1/', $output);
    }

    #[Test]
    public function a_shared_household_phone_does_not_merge_two_people_with_different_emails(): void
    {
        Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'parent@example.test', 'phone' => '(336) 555-0142']);

        $this->import($this->file([$this->wix('w1', ['email' => 'spouse@example.test', 'phone' => '(336) 555-0142'])]),
            ['--execute' => true, '--batch' => 'b1']);

        $this->assertSame(2, $this->contacts()->count());
        $this->assertNotNull($this->contactWithEmail('spouse@example.test'));
    }

    #[Test]
    public function wix_records_sharing_an_address_become_one_contact_and_the_stricter_status_wins(): void
    {
        $this->import($this->file([
            $this->wix('w1', ['first' => 'Older', 'email' => 'twice@example.test', 'updated' => '2020-01-01T00:00:00.000Z', 'phone' => '(336) 555-0101']),
            $this->wix('w2', ['first' => 'Newer', 'email' => 'Twice@Example.test', 'sub' => 'UNSUBSCRIBED', 'updated' => '2025-01-01T00:00:00.000Z']),
        ]), ['--execute' => true, '--batch' => 'b1']);

        $contacts = $this->contacts()->get();
        $this->assertCount(1, $contacts);
        $this->assertSame('Newer', $contacts[0]->first_name, 'the most recently updated record names the person');
        $this->assertSame(['twice@example.test' => EmailSuppression::REASON_IMPORTED_OPT_OUT], $this->suppressions());
        $this->assertSame(2, ImportLink::withoutMasjidScope()->where('local_id', $contacts[0]->id)->count());
        $this->assertSame('+13365550101', $contacts[0]->phone, 'the older record\'s phone fills the one the newer record lacks');
    }

    #[Test]
    public function a_wix_id_exported_twice_is_imported_once_from_its_later_revision(): void
    {
        [$code, $output] = $this->import($this->file([
            $this->wix('w1', ['first' => 'Later', 'email' => 'moved@example.test', 'revision' => 7]),
            $this->wix('w1', ['first' => 'Earlier', 'email' => 'old@example.test', 'revision' => 6]),
        ]), ['--execute' => true, '--batch' => 'b1']);

        $this->assertSame(0, $code, $output);
        $this->assertSame(['Later'], $this->contacts()->pluck('first_name')->all());
        $this->assertSame(1, ImportLink::withoutMasjidScope()->count());
    }

    #[Test]
    public function what_the_contact_row_cannot_hold_is_kept_in_its_notes(): void
    {
        $this->import($this->file([$this->wix('w1', [
            'email' => 'main@example.test', 'extra_emails' => ['work@example.test'],
            'phone' => '(336) 555-0101', 'extra_phones' => ['(336) 555-0177'],
            'addresses' => ["12 Test Lane\nCharlotte, NC 28200"], 'member' => true,
        ])]), ['--execute' => true, '--batch' => 'b1']);

        $this->assertSame(
            "Imported from the old Wix website (Wix contact since 2019-03-02).\n"
            . "Other emails: work@example.test\n"
            . "Other phones: (336) 555-0177\n"
            . "Address: 12 Test Lane, Charlotte, NC 28200\n"
            . 'Had a login on the old website (not carried over).',
            $this->contactWithEmail('main@example.test')->notes,
        );
    }

    #[Test]
    public function wix_labels_become_tags_and_an_existing_tag_of_the_same_name_is_reused(): void
    {
        $existingTag = ContactTag::withoutMasjidScope()->create(['masjid_id' => $this->masjid->id, 'name' => 'VOLUNTEER']);
        $labels = $this->labelsFile(['custom.volunteer' => 'Volunteer', 'custom.mec-emaillist-1' => 'MEC emaillist 1']);

        $this->import($this->file([
            $this->wix('w1', ['email' => 'one@example.test', 'labels' => ['custom.volunteer', 'custom.mec-emaillist-1']]),
            $this->wix('w2', ['email' => 'two@example.test', 'labels' => ['custom.mec-emaillist-1']]),
        ]), ['--labels' => $labels, '--execute' => true, '--batch' => 'b1']);

        $this->assertSame(['MEC emaillist 1', 'VOLUNTEER'], ContactTag::withoutMasjidScope()->orderBy('name')->pluck('name')->all());
        $this->assertSame(['MEC emaillist 1'], $this->contactWithEmail('two@example.test')->tags()->pluck('name')->all());
        $one = $this->contactWithEmail('one@example.test');
        $this->assertSame(['MEC emaillist 1', 'VOLUNTEER'], $one->tags()->orderBy('name')->pluck('name')->all());
        $this->assertTrue($one->tags()->whereKey($existingTag->id)->exists(), 'the office\'s own tag was reused, not duplicated');
        $this->assertSame(['b1'], DB::table('contact_tag_links')->distinct()->pluck('import_batch')->all());
    }

    #[Test]
    public function a_rerun_from_a_fresh_pull_updates_the_contact_it_created_instead_of_duplicating_it(): void
    {
        $this->import($this->file([$this->wix('w1', ['first' => 'Amina', 'email' => 'amina@example.test'])]), ['--execute' => true, '--batch' => 'b1']);

        // The fresh pull: same Wix id, corrected name, a new address.
        $this->import($this->file([$this->wix('w1', ['first' => 'Aminah', 'email' => 'aminah@example.test'])]), ['--execute' => true, '--batch' => 'b2']);

        $contacts = $this->contacts()->get();
        $this->assertCount(1, $contacts);
        $this->assertSame('Aminah', $contacts[0]->first_name);
        $this->assertSame('aminah@example.test', $contacts[0]->email);
    }

    #[Test]
    public function a_rerun_keeps_an_edit_made_in_manara_since_the_import(): void
    {
        $this->import($this->file([$this->wix('w1', ['first' => 'Amina', 'email' => 'amina@example.test'])]), ['--execute' => true, '--batch' => 'b1']);
        $this->contactWithEmail('amina@example.test')->forceFill(['last_name' => 'Corrected-by-office'])->save();

        $this->import($this->file([$this->wix('w1', ['first' => 'Wix-changed', 'email' => 'amina@example.test'])]), ['--execute' => true, '--batch' => 'b2']);

        $contact = $this->contactWithEmail('amina@example.test');
        $this->assertSame('Amina', $contact->first_name);
        $this->assertSame('Corrected-by-office', $contact->last_name);
        $this->assertSame(1, $this->contacts()->count());
    }

    #[Test]
    public function a_rerun_never_releases_a_precaution_when_wix_now_says_subscribed(): void
    {
        $this->import($this->file([$this->wix('w1', ['email' => 'later@example.test', 'sub' => 'NOT_SET'])]), ['--execute' => true, '--batch' => 'b1']);

        [, $output] = $this->import($this->file([$this->wix('w1', ['email' => 'later@example.test'])]), ['--execute' => true, '--batch' => 'b2']);

        $this->assertSame(['later@example.test' => EmailSuppression::REASON_NOT_OPTED_IN], $this->suppressions());
        $this->assertMatchesRegularExpression('/SUBSCRIBED on Wix now \(not released\)\s*\|\s*1/', $output);
    }

    #[Test]
    public function a_contact_the_office_deleted_is_not_recreated(): void
    {
        // Deleted before the first run…
        Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'gone@example.test'])->delete();
        // …and deleted between two runs.
        $this->import($this->file([$this->wix('w2', ['email' => 'later-gone@example.test'])]), ['--execute' => true, '--batch' => 'b1']);
        $this->contactWithEmail('later-gone@example.test')->delete();

        $this->import($this->file([
            $this->wix('w1', ['email' => 'gone@example.test', 'sub' => 'UNSUBSCRIBED']),
            $this->wix('w2', ['email' => 'later-gone@example.test']),
        ]), ['--execute' => true, '--batch' => 'b2']);

        $this->assertSame(2, $this->contacts()->count());
        $this->assertSame(0, Contact::withoutMasjidScope()->where('masjid_id', $this->masjid->id)->count(), 'both stay deleted');
        $this->assertSame(['gone@example.test' => EmailSuppression::REASON_IMPORTED_OPT_OUT], $this->suppressions(), 'their opt-out still applies');
    }

    #[Test]
    public function undo_removes_exactly_what_the_run_created_and_keeps_every_opt_out(): void
    {
        $matched = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'matched@example.test']);
        $bystander = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'bystander@example.test']);
        $officeTag = ContactTag::withoutMasjidScope()->create(['masjid_id' => $this->masjid->id, 'name' => 'Volunteer']);
        $officeTag->contacts()->attach([$bystander->id]);
        $labels = $this->labelsFile(['custom.volunteer' => 'Volunteer', 'custom.zoo-trip' => 'Zoo trip']);

        $this->import($this->file([
            $this->wix('w1', ['email' => 'matched@example.test', 'labels' => ['custom.volunteer', 'custom.zoo-trip']]),
            $this->wix('w2', ['email' => 'created@example.test', 'sub' => 'UNSUBSCRIBED', 'labels' => ['custom.zoo-trip']]),
            $this->wix('w3', ['email' => 'precaution@example.test', 'sub' => 'NOT_SET']),
        ]), ['--labels' => $labels, '--execute' => true, '--batch' => 'b1']);

        $this->assertSame(4, $this->contacts()->count());

        [$code, $output] = $this->import(null, ['--undo' => 'b1']);

        $this->assertSame(0, $code, $output);
        $this->assertSame(
            collect([$matched->id, $bystander->id])->sort()->values()->all(),
            $this->contacts()->orderBy('id')->pluck('id')->all(),
            'the created contacts are erased, not archived; the matched and untouched ones remain',
        );
        $this->assertSame(['Volunteer'], ContactTag::withoutMasjidScope()->pluck('name')->all(), 'the office tag stays, the created tag goes');
        $this->assertSame([], $matched->tags()->pluck('name')->all(), 'the import\'s tag on a matched contact is removed');
        $this->assertSame(['Volunteer'], $bystander->tags()->pluck('name')->all(), 'an office tag assignment is untouched');
        $this->assertSame(0, ImportLink::withoutMasjidScope()->count());
        $this->assertSame([
            'created@example.test' => EmailSuppression::REASON_IMPORTED_OPT_OUT,
            'precaution@example.test' => EmailSuppression::REASON_NOT_OPTED_IN,
        ], collect($this->suppressions())->sortKeys()->all(), 'no suppression is ever deleted');
    }

    #[Test]
    public function undo_is_refused_in_full_once_an_imported_contact_is_the_offices_record(): void
    {
        $this->import($this->file([
            $this->wix('w1', ['email' => 'kept@example.test']),
            $this->wix('w2', ['email' => 'other@example.test']),
        ]), ['--execute' => true, '--batch' => 'b1']);

        $kept = $this->contactWithEmail('kept@example.test');
        ContactTag::withoutMasjidScope()->create(['masjid_id' => $this->masjid->id, 'name' => 'Donor'])->contacts()->attach([$kept->id]);

        [$code, $output] = $this->import(null, ['--undo' => 'b1']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("contact {$kept->id}: contact_tag_links", $output);
        $this->assertStringNotContainsString('kept@example.test', $output);
        $this->assertSame(2, $this->contacts()->count(), 'nothing was removed');
        $this->assertSame(2, ImportLink::withoutMasjidScope()->where('kind', ImportLink::KIND_CONTACT)->count());
    }

    #[Test]
    public function the_import_sends_no_email_text_push_or_notification(): void
    {
        Mail::fake();
        Notification::fake();
        Bus::fake();
        Http::preventStrayRequests();

        $this->import($this->file([
            $this->wix('w1', ['email' => 'a@example.test', 'member' => true, 'phone' => '(336) 555-0101']),
            $this->wix('w2', ['email' => 'b@example.test', 'sub' => 'UNSUBSCRIBED', 'phone' => '(336) 555-0102', 'sms' => 'UNSUBSCRIBED']),
        ]), ['--execute' => true, '--batch' => 'b1']);

        $this->assertSame(2, $this->contacts()->count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Bus::assertNothingDispatched();
        $this->assertSame(0, DB::table('contact_portal_invites')->count(), 'site members get no invitation');
    }

    #[Test]
    public function a_wix_sms_unsubscribe_is_recorded_and_no_sms_consent_is_written(): void
    {
        $this->import($this->file([
            $this->wix('w1', ['email' => 'a@example.test', 'phone' => '(336) 555-0102', 'sms' => 'UNSUBSCRIBED']),
            $this->wix('w2', ['email' => 'b@example.test', 'phone' => '(336) 555-0103', 'sms' => 'SUBSCRIBED']),
        ]), ['--execute' => true, '--batch' => 'b1']);

        $this->assertSame(['+13365550102'], SmsSuppression::withoutMasjidScope()->where('masjid_id', $this->masjid->id)->pluck('phone_e164')->all());
        $this->assertSame(0, $this->contacts()->where('sms_opt_in', true)->count());
        $this->assertSame(0, $this->contacts()->whereNotNull('sms_consent_at')->count());
    }

    #[Test]
    public function the_import_reads_and_writes_only_the_named_organisation(): void
    {
        $theirs = Contact::factory()->create(['masjid_id' => $this->other->id, 'email' => 'shared@example.test', 'first_name' => 'Theirs']);

        $this->import($this->file([
            $this->wix('w1', ['email' => 'shared@example.test', 'sub' => 'UNSUBSCRIBED', 'labels' => ['custom.volunteer']]),
        ]), ['--execute' => true, '--batch' => 'b1']);

        $this->assertSame(1, $this->contacts()->count(), 'the other organisation\'s contact was not matched');
        $this->assertSame('Theirs', $theirs->fresh()->first_name);
        $this->assertSame(0, $theirs->tags()->count());
        $this->assertSame([], $this->suppressions($this->other));
        $this->assertSame(0, ContactTag::withoutMasjidScope()->where('masjid_id', $this->other->id)->count());
        $this->assertSame(0, ImportLink::withoutMasjidScope()->where('masjid_id', $this->other->id)->count());
    }
}
