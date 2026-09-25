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
use App\Services\Broadcast\EmailSuppressionService;
use App\Services\Sms\SmsConsentService;
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

    /** @return list<string> what a broadcast to "everyone" would email right now; the tenant is unbound again afterwards */
    private function everyoneEmailed(): array
    {
        app(TenantContext::class)->set($this->masjid->id);
        $everyone = (new Broadcast())->forceFill(['masjid_id' => $this->masjid->id, 'audience' => 'everyone']);
        $emails = app(BroadcastAudienceResolver::class)->emailRecipients($everyone)->pluck('email')->sort()->values()->all();
        app(TenantContext::class)->forgetTenant();

        return $emails;
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
    }

    #[Test]
    public function the_owners_rule_suppresses_a_matched_contacts_address_that_wix_never_had_subscribed_or_that_bounced(): void
    {
        // "Everyone, most blocked": EVERY contact not subscribed or not
        // deliverable, bounced named among them — matched ones included.
        Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'Known@Example.test']);
        Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'bounced@example.test']);
        Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'fine@example.test']);
        $bystander = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'bystander@example.test']);

        [, $output] = $this->import($this->file([
            $this->wix('w1', ['email' => 'known@example.test', 'sub' => 'NOT_SET']),
            $this->wix('w2', ['email' => 'bounced@example.test', 'deliv' => 'BOUNCED']),
            $this->wix('w3', ['email' => 'fine@example.test']),
        ]), ['--execute' => true, '--batch' => 'b1']);

        $this->assertSame([
            'bounced@example.test' => EmailSuppression::REASON_BOUNCE,
            'known@example.test' => EmailSuppression::REASON_NOT_OPTED_IN,
        ], collect($this->suppressions())->sortKeys()->all());
        $this->assertMatchesRegularExpression('/of which precautions on contacts already in Manara\s*\|\s*2/', $output);

        app(TenantContext::class)->set($this->masjid->id);
        $everyone = (new Broadcast())->forceFill(['masjid_id' => $this->masjid->id, 'audience' => 'everyone']);
        $this->assertSame(
            ['bystander@example.test', 'fine@example.test'],
            app(BroadcastAudienceResolver::class)->emailRecipients($everyone)->pluck('email')->sort()->values()->all(),
        );
        $this->assertSame(4, $this->contacts()->count(), 'matched, not duplicated');
        $this->assertNull($bystander->fresh()->email_opted_out_at);
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
        $this->assertSame(2, ImportLink::withoutMasjidScope()->where('kind', ImportLink::KIND_CONTACT)->where('local_id', $contacts[0]->id)->count());
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
        $this->assertMatchesRegularExpression('/SUBSCRIBED on Wix, but suppressed in Manara \(kept suppressed\)\s*\|\s*1/', $output);
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
    public function undo_removes_exactly_what_the_run_created_including_its_own_precautions_and_keeps_the_wix_opt_outs(): void
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
        $this->assertSame([ImportLink::KIND_EMAIL_SUPPRESSION], ImportLink::withoutMasjidScope()->pluck('kind')->all(),
            'only the kept opt-out stays linked, so --remove-opt-outs can still find it');
        $this->assertSame([
            'created@example.test' => EmailSuppression::REASON_IMPORTED_OPT_OUT,
        ], $this->suppressions(), 'the precaution the run wrote is gone; the opt-out the person made on Wix stays');
        $this->assertSame(1, EmailSuppression::withoutMasjidScope()->count(), 'removed, not released: the import never happened');
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

    // ---------------------------------------------------------------- consent

    #[Test]
    public function wix_records_sharing_an_address_are_suppressed_when_the_older_one_opted_out_and_the_newer_is_subscribed(): void
    {
        // The mirror of the test above: the NEWER record (which names the
        // person) is mailable, the older one is not. The stricter record still
        // wins; the newest does not decide.
        $this->import($this->file([
            $this->wix('w1', ['first' => 'Older', 'email' => 'twice@example.test', 'sub' => 'UNSUBSCRIBED', 'updated' => '2020-01-01T00:00:00.000Z']),
            $this->wix('w2', ['first' => 'Newer', 'email' => 'Twice@Example.test', 'updated' => '2025-01-01T00:00:00.000Z']),
            $this->wix('w3', ['first' => 'Older', 'email' => 'thrice@example.test', 'deliv' => 'BOUNCED', 'updated' => '2020-01-01T00:00:00.000Z']),
            $this->wix('w4', ['first' => 'Newer', 'email' => 'thrice@example.test', 'updated' => '2025-01-01T00:00:00.000Z']),
        ]), ['--execute' => true, '--batch' => 'b1']);

        $this->assertSame(['Newer', 'Newer'], $this->contacts()->pluck('first_name')->all());
        $this->assertSame([
            'thrice@example.test' => EmailSuppression::REASON_BOUNCE,
            'twice@example.test' => EmailSuppression::REASON_IMPORTED_OPT_OUT,
        ], collect($this->suppressions())->sortKeys()->all());
        $this->assertSame([], $this->everyoneEmailed());
    }

    #[Test]
    public function a_rerun_whose_fresh_pull_marks_a_created_contact_bounced_or_never_subscribed_suppresses_it(): void
    {
        $this->import($this->file([
            $this->wix('w1', ['email' => 'goes-bad@example.test']),
            $this->wix('w2', ['email' => 'lapsed@example.test']),
        ]), ['--execute' => true, '--batch' => 'b1']);
        $this->assertSame(['goes-bad@example.test', 'lapsed@example.test'], $this->everyoneEmailed());

        $this->import($this->file([
            $this->wix('w1', ['email' => 'goes-bad@example.test', 'deliv' => 'BOUNCED']),
            $this->wix('w2', ['email' => 'lapsed@example.test', 'sub' => 'NOT_SET']),
        ]), ['--execute' => true, '--batch' => 'b2']);

        $this->assertSame([
            'goes-bad@example.test' => EmailSuppression::REASON_BOUNCE,
            'lapsed@example.test' => EmailSuppression::REASON_NOT_OPTED_IN,
        ], collect($this->suppressions())->sortKeys()->all());
        $this->assertSame([], $this->everyoneEmailed());
    }

    #[Test]
    public function a_rerun_never_re_suppresses_an_address_or_number_the_person_released_in_manara(): void
    {
        $file = $this->file([
            $this->wix('w1', ['email' => 'came-back@example.test', 'sub' => 'UNSUBSCRIBED']),
            $this->wix('w2', ['email' => 'texts@example.test', 'phone' => '(336) 555-0160', 'sms' => 'UNSUBSCRIBED']),
        ]);
        $this->import($file, ['--execute' => true, '--batch' => 'b1']);

        // Since then, in Manara: the subscriber's own re-subscribe link, and a START reply.
        app(EmailSuppressionService::class)->release($this->masjid->id, 'came-back@example.test');
        app(SmsConsentService::class)->release($this->masjid->id, '+13365550160', 'START');

        [, $output] = $this->import($file, ['--execute' => true, '--batch' => 'b2']);

        $this->assertSame([], $this->suppressions(), 'the older Wix opt-out does not override the newer decision');
        $this->assertSame(['came-back@example.test', 'texts@example.test'], $this->everyoneEmailed());
        $this->assertNotNull(SmsSuppression::withoutMasjidScope()->where('phone_e164', '+13365550160')->value('released_at'));
        $this->assertMatchesRegularExpression('/Released in Manara by the person or staff \(Wix status not applied\)\s*\|\s*1/', $output);
        $this->assertMatchesRegularExpression('/SMS opt-outs released in Manara \(Wix status not applied\)\s*\|\s*1/', $output);
    }

    #[Test]
    public function the_dry_run_counts_an_address_manara_already_suppresses_apart_from_the_mailable_ones(): void
    {
        Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'left-in-manara@example.test']);
        app(EmailSuppressionService::class)->suppress($this->masjid->id, 'left-in-manara@example.test');

        [, $output] = $this->import($this->file([
            $this->wix('w1', ['email' => 'left-in-manara@example.test']),
            $this->wix('w2', ['email' => 'fine@example.test']),
        ]));

        $this->assertMatchesRegularExpression('/Stay mailable \(SUBSCRIBED and VALID on Wix, not suppressed in Manara\)\s*\|\s*1/', $output);
        $this->assertMatchesRegularExpression('/SUBSCRIBED on Wix, but suppressed in Manara \(kept suppressed\)\s*\|\s*1/', $output);
    }

    // --------------------------------------------------------------- matching

    #[Test]
    public function a_placeholder_card_stub_is_never_matched_and_a_real_contact_is_created_beside_it(): void
    {
        $stub = Contact::factory()->create([
            'masjid_id' => $this->masjid->id, 'first_name' => 'Unidentified', 'last_name' => 'Card 4242',
            'email' => 'card@example.test', 'is_placeholder' => true,
        ]);
        $before = $stub->fresh()->only(['first_name', 'last_name', 'email', 'notes', 'import_batch']);

        $this->import($this->file([$this->wix('w1', ['email' => 'card@example.test', 'labels' => ['custom.volunteer']])]),
            ['--execute' => true, '--batch' => 'b1']);

        $created = $this->contacts()->where('is_placeholder', false)->sole();
        $this->assertSame('card@example.test', $created->email);
        $this->assertSame('b1', $created->import_batch);
        $this->assertSame($before, $stub->fresh()->only(['first_name', 'last_name', 'email', 'notes', 'import_batch']));
        $this->assertSame([], $stub->tags()->pluck('name')->all());
        $link = ImportLink::withoutMasjidScope()->where('kind', ImportLink::KIND_CONTACT)->sole();
        $this->assertSame([$created->id, true], [$link->local_id, $link->created_local]);
    }

    #[Test]
    public function of_two_live_contacts_sharing_the_address_the_oldest_is_the_match(): void
    {
        $older = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'shared@example.test']);
        $newer = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'Shared@Example.test']);

        $this->import($this->file([$this->wix('w1', ['email' => 'shared@example.test', 'labels' => ['custom.volunteer']])]),
            ['--execute' => true, '--batch' => 'b1']);

        $this->assertSame(['volunteer'], $older->tags()->pluck('name')->all());
        $this->assertSame([], $newer->tags()->pluck('name')->all());
        $this->assertSame($older->id, ImportLink::withoutMasjidScope()->where('kind', ImportLink::KIND_CONTACT)->sole()->local_id);
    }

    #[Test]
    public function a_tag_the_office_deleted_is_not_recreated_by_a_rerun(): void
    {
        $labels = $this->labelsFile(['custom.zoo-trip' => 'Zoo trip']);
        $this->import($this->file([$this->wix('w1', ['email' => 'a@example.test', 'labels' => ['custom.zoo-trip']])]),
            ['--labels' => $labels, '--execute' => true, '--batch' => 'b1']);
        ContactTag::withoutMasjidScope()->where('name', 'Zoo trip')->sole()->delete();

        [, $output] = $this->import($this->file([
            $this->wix('w1', ['email' => 'a@example.test', 'labels' => ['custom.zoo-trip']]),
            $this->wix('w2', ['email' => 'b@example.test', 'labels' => ['custom.zoo-trip']]),
        ]), ['--labels' => $labels, '--execute' => true, '--batch' => 'b2']);

        $this->assertSame(0, ContactTag::withoutMasjidScope()->where('name', 'Zoo trip')->count());
        $this->assertMatchesRegularExpression('/Tags deleted in Manara, not recreated\s*\|\s*1/', $output);
    }

    #[Test]
    public function a_batch_name_already_used_in_the_organisation_is_refused_before_anything_is_written(): void
    {
        $this->import($this->file([$this->wix('w1', ['email' => 'a@example.test'])]), ['--execute' => true, '--batch' => 'b1']);
        Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'roster@example.test', 'import_batch' => 'roster-2026']);
        $second = $this->file([$this->wix('w2', ['email' => 'b@example.test'])]);

        [$code, $output] = $this->import($second, ['--execute' => true, '--batch' => 'b1']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Batch b1 has already been used', $output);

        [$code] = $this->import($second, ['--execute' => true, '--batch' => 'roster-2026']);
        $this->assertSame(1, $code, 'a name another importer used counts too');
        $this->assertNull($this->contactWithEmail('b@example.test'), 'nothing was written');

        [$code] = $this->import($second, ['--execute' => true, '--batch' => 'b1'], $this->other);
        $this->assertSame(0, $code, 'another organisation\'s batch of the same name does not');
    }

    // ------------------------------------------------------------------- undo

    #[Test]
    public function undo_removes_only_the_suppressions_the_run_inserted_and_clears_their_badge(): void
    {
        $service = app(EmailSuppressionService::class);
        $service->suppress($this->masjid->id, 'office-opt-out@example.test', EmailSuppression::REASON_MANUAL);
        $service->suppress($this->masjid->id, 'earlier@example.test', EmailSuppression::REASON_NOT_OPTED_IN);
        $matched = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'matched@example.test']);

        $this->import($this->file([
            $this->wix('w1', ['email' => 'office-opt-out@example.test', 'sub' => 'NOT_SET']),
            $this->wix('w2', ['email' => 'earlier@example.test', 'sub' => 'NOT_SET']),
            $this->wix('w3', ['email' => 'matched@example.test', 'sub' => 'NOT_SET']),
            $this->wix('w4', ['email' => 'bounced@example.test', 'deliv' => 'BOUNCED']),
        ]), ['--execute' => true, '--batch' => 'b1']);
        $this->assertSame(4, count($this->suppressions()));
        $this->assertNotNull($matched->fresh()->email_opted_out_at);

        [$code, $output] = $this->import(null, ['--undo' => 'b1']);

        $this->assertSame(0, $code, $output);
        $this->assertSame([
            'earlier@example.test' => EmailSuppression::REASON_NOT_OPTED_IN,
            'office-opt-out@example.test' => EmailSuppression::REASON_MANUAL,
        ], collect($this->suppressions())->sortKeys()->all(), 'rows that existed before the run are untouched');
        $this->assertNull($matched->fresh()->email_opted_out_at, 'the badge follows the list');
        $this->assertMatchesRegularExpression('/Precaution suppressions the run wrote \(not opted in, bounced\), removed\s*\|\s*2/', $output);
    }

    #[Test]
    public function undo_with_remove_opt_outs_also_removes_the_wix_opt_outs_that_run_wrote_and_nothing_older(): void
    {
        app(EmailSuppressionService::class)->suppress($this->masjid->id, 'before@example.test');
        app(SmsConsentService::class)->suppress($this->masjid->id, '+13365550150');

        $this->import($this->file([
            $this->wix('w1', ['email' => 'left@example.test', 'sub' => 'UNSUBSCRIBED', 'phone' => '(336) 555-0102', 'sms' => 'UNSUBSCRIBED']),
            $this->wix('w2', ['email' => 'spam@example.test', 'deliv' => 'SPAM_COMPLAINT']),
            $this->wix('w3', ['email' => 'before@example.test', 'sub' => 'UNSUBSCRIBED', 'phone' => '(336) 555-0150', 'sms' => 'UNSUBSCRIBED']),
        ]), ['--execute' => true, '--batch' => 'wrong-org']);

        // A plain undo keeps them: they are real requests.
        [$code] = $this->import(null, ['--undo' => 'wrong-org']);
        $this->assertSame(0, $code);
        $this->assertCount(3, $this->suppressions());
        $this->assertSame(2, SmsSuppression::withoutMasjidScope()->count());

        // Told the run went into the wrong organisation, the same batch's copies go.
        [$code, $output] = $this->import(null, ['--undo' => 'wrong-org', '--remove-opt-outs' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertSame(['before@example.test' => EmailSuppression::REASON_UNSUBSCRIBE_LINK], $this->suppressions());
        $this->assertSame(['+13365550150'], SmsSuppression::withoutMasjidScope()->pluck('phone_e164')->all());
        $this->assertSame(0, ImportLink::withoutMasjidScope()->count());
    }

    #[Test]
    public function undo_of_an_earlier_run_also_removes_the_links_a_later_run_made_to_its_contacts(): void
    {
        $this->import($this->file([$this->wix('w1', ['email' => 'twice@example.test'])]), ['--execute' => true, '--batch' => 'b1']);
        // The later pull has a new duplicate record for the same person.
        $later = $this->file([
            $this->wix('w1', ['email' => 'twice@example.test']),
            $this->wix('w2', ['email' => 'Twice@Example.test']),
        ]);
        $this->import($later, ['--execute' => true, '--batch' => 'b2']);

        [$code, $output] = $this->import(null, ['--undo' => 'b1']);

        $this->assertSame(0, $code, $output);
        $this->assertSame(0, $this->contacts()->count());
        $this->assertSame(0, ImportLink::withoutMasjidScope()->where('kind', ImportLink::KIND_CONTACT)->count());

        [, $output] = $this->import($later);
        $this->assertMatchesRegularExpression('/New contacts to create\s*\|\s*1/', $output, 'a corrected re-run brings the person back');
        $this->assertMatchesRegularExpression('/Deleted in Manara, not recreated\s*\|\s*0/', $output);
    }

    #[Test]
    public function undo_is_refused_for_a_run_that_updated_a_contact_an_earlier_run_created(): void
    {
        $this->import($this->file([$this->wix('w1', ['first' => 'Amina', 'email' => 'amina@example.test'])]), ['--execute' => true, '--batch' => 'b1']);
        $this->import($this->file([$this->wix('w1', ['first' => 'Aminah', 'email' => 'amina@example.test'])]), ['--execute' => true, '--batch' => 'b2']);
        $contact = $this->contactWithEmail('amina@example.test');

        [$code, $output] = $this->import(null, ['--undo' => 'b2']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("contact {$contact->id}: updated by this run from a later pull", $output);
        $this->assertSame('Aminah', $contact->fresh()->first_name, 'nothing was changed');

        // Undoing the run that created it removes it, and then the later run has nothing left to refuse.
        $this->assertSame(0, $this->import(null, ['--undo' => 'b1'])[0]);
        $this->assertSame(0, $this->contacts()->count());
        $this->assertSame(0, $this->import(null, ['--undo' => 'b2'])[0]);
    }

    #[Test]
    public function undo_is_refused_once_the_office_edited_the_notes_the_name_or_the_sms_consent_of_an_imported_contact(): void
    {
        $this->import($this->file([
            $this->wix('w1', ['email' => 'notes@example.test']),
            $this->wix('w2', ['email' => 'name@example.test']),
            $this->wix('w3', ['email' => 'sms@example.test', 'phone' => '(336) 555-0170']),
            $this->wix('w4', ['email' => 'untouched@example.test']),
        ]), ['--execute' => true, '--batch' => 'b1']);

        $notes = $this->contactWithEmail('notes@example.test');
        $notes->forceFill(['notes' => $notes->notes . "
Called about the fall festival."])->save();
        $name = $this->contactWithEmail('name@example.test');
        $name->forceFill(['last_name' => 'Corrected-by-office'])->save();
        $sms = $this->contactWithEmail('sms@example.test');
        $sms->forceFill(['sms_opt_in' => true, 'sms_consent_at' => now(), 'sms_consent_source' => 'paper_form', 'sms_consent_evidence' => 'signed sheet'])->save();

        [$code, $output] = $this->import(null, ['--undo' => 'b1']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("contact {$notes->id}: contacts (edited since the import)", $output);
        $this->assertStringContainsString("contact {$name->id}: contacts (edited since the import)", $output);
        $this->assertStringContainsString("contact {$sms->id}: contacts.sms_opt_in, contacts.sms_consent_at, contacts.sms_consent_source, contacts.sms_consent_evidence", $output);
        $this->assertStringNotContainsString('untouched', $output);
        $this->assertSame(4, $this->contacts()->count(), 'nothing was removed');
    }

    #[Test]
    public function undo_is_refused_once_an_imported_contact_has_a_login(): void
    {
        $this->import($this->file([
            $this->wix('w1', ['email' => 'password@example.test']),
            $this->wix('w2', ['email' => 'login@example.test']),
        ]), ['--execute' => true, '--batch' => 'b1']);

        $withPassword = $this->contactWithEmail('password@example.test');
        $withPassword->forceFill(['password' => bcrypt('a-test-only-password'), 'verified_at' => now()])->save();
        $withLogin = $this->contactWithEmail('login@example.test');
        $withLogin->forceFill(['login_email' => 'login@example.test'])->save();

        [$code, $output] = $this->import(null, ['--undo' => 'b1']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("contact {$withPassword->id}: contacts.verified_at, contacts.password", $output);
        $this->assertStringContainsString("contact {$withLogin->id}: contacts.login_email", $output);
        $this->assertSame(2, $this->contacts()->count(), 'nothing was removed');
        $this->assertSame(2, ImportLink::withoutMasjidScope()->where('kind', ImportLink::KIND_CONTACT)->count());
    }

    #[Test]
    public function undo_is_refused_once_a_broadcast_named_an_imported_contact(): void
    {
        $this->import($this->file([$this->wix('w1', ['email' => 'named@example.test'])]), ['--execute' => true, '--batch' => 'b1']);
        $named = $this->contactWithEmail('named@example.test');
        (new Broadcast())->forceFill([
            'masjid_id' => $this->masjid->id, 'title' => 'Board meeting', 'body' => 'Tuesday',
            'audience' => 'contacts', 'audience_contact_ids' => [$named->id],
        ])->save();

        [$code, $output] = $this->import(null, ['--undo' => 'b1']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("contact {$named->id}: broadcasts", $output);
        $this->assertSame(1, $this->contacts()->count());
    }

    #[Test]
    public function undo_keeps_a_tag_it_created_that_the_office_has_since_given_to_another_contact(): void
    {
        $bystander = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'bystander@example.test']);
        $labels = $this->labelsFile(['custom.zoo-trip' => 'Zoo trip']);
        $this->import($this->file([$this->wix('w1', ['email' => 'created@example.test', 'labels' => ['custom.zoo-trip']])]),
            ['--labels' => $labels, '--execute' => true, '--batch' => 'b1']);
        ContactTag::withoutMasjidScope()->where('name', 'Zoo trip')->sole()->contacts()->attach([$bystander->id]);

        [$code, $output] = $this->import(null, ['--undo' => 'b1']);

        $this->assertSame(0, $code, $output);
        $this->assertNull($this->contactWithEmail('created@example.test'));
        $this->assertSame(['Zoo trip'], $bystander->tags()->pluck('name')->all());
        $this->assertMatchesRegularExpression('/Tags kept because contacts still carry them\s*\|\s*1/', $output);
    }
}
