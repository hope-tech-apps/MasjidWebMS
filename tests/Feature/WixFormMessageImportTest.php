<?php

namespace Tests\Feature;

use App\Models\ContactUsAccount;
use App\Models\ContactUsMessage;
use App\Models\ContactUsReason;
use App\Models\ContactUsReply;
use App\Models\ImportLink;
use App\Models\Masjid;
use App\Models\MobileAppUser;
use App\Services\Imports\WixFormMessageImport;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `wix:import-form-messages` — a Wix form's submission CSV into contact
 * messages, marked answered (App\Services\Imports\WixFormMessageImport).
 *
 * Fixtures are SYNTHETIC CSVs in the shape the importer is designed for; the
 * real exports do not exist yet (MEC downloads them from the Wix dashboard).
 */
class WixFormMessageImportTest extends TestCase
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
            'timezone' => 'America/New_York',
        ]);
    }

    /** @param list<list<string>> $rows */
    private function csv(array $header, array $rows, bool $bom = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wixf') . '.csv';
        $handle = fopen($path, 'w');
        if ($bom) {
            fwrite($handle, "\xEF\xBB\xBF");
        }
        fputcsv($handle, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);
        $this->files[] = $path;

        return $path;
    }

    private function contactFormCsv(): string
    {
        return $this->csv(
            ['Submission Time', 'Name', 'Email', 'Subject', 'Message'],
            [
                ['2025-04-13 10:15', 'Zaynab Quux', 'zaynab@example.test', 'Nikah booking', "Is the hall free in June?\nThanks"],
                ['2026-09-16 18:02', 'Omar Zork', 'omar@example.test', '', 'Where do I park?'],
                ['2026-09-16 18:30', 'Omar Zork', 'OMAR@example.test', 'Again', 'Found it.'],
            ],
        );
    }

    private function import(?string $file, array $options = [], ?Masjid $into = null): array
    {
        $args = ['--masjid' => ($into ?? $this->masjid)->id] + $options;
        if ($file !== null) {
            $args['file'] = $file;
        }

        $code = Artisan::call('wix:import-form-messages', $args);
        $output = Artisan::output();
        app(TenantContext::class)->forgetTenant();

        return [$code, $output];
    }

    private function messages(?Masjid $masjid = null)
    {
        return ContactUsMessage::query()->where('masjid_id', ($masjid ?? $this->masjid)->id)->orderBy('created_at');
    }

    #[Test]
    public function a_dry_run_shows_how_the_columns_were_read_and_writes_nothing(): void
    {
        [$code, $output] = $this->import($this->contactFormCsv(), ['--form' => 'Contact']);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertMatchesRegularExpression('/Submission Time\s*\|\s*submitted_at/', $output);
        $this->assertMatchesRegularExpression('/To import, marked answered\s*\|\s*3/', $output);
        foreach (['Zaynab', 'zaynab@', 'hall free', 'Omar'] as $personal) {
            $this->assertStringNotContainsString($personal, $output);
        }

        $this->assertSame(0, ContactUsMessage::count());
        $this->assertSame(0, MobileAppUser::count());
        $this->assertSame(0, ImportLink::withoutMasjidScope()->count());
    }

    #[Test]
    public function messages_are_imported_answered_on_their_own_dates_and_nobody_is_notified(): void
    {
        Mail::fake();
        Notification::fake();
        Bus::fake();

        [$code, $output] = $this->import($this->contactFormCsv(), ['--form' => 'Contact', '--execute' => true, '--batch' => 'm1']);

        $this->assertSame(0, $code, $output);
        $messages = $this->messages()->with('contacter', 'reason')->get();
        $this->assertCount(3, $messages);

        $first = $messages[0];
        $this->assertSame("Subject: Nikah booking\n\nIs the hall free in June?\nThanks", $first->message);
        $this->assertSame('2025-04-13 14:15:00', $first->created_at->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'), 'the Wix date, read in the organisation\'s timezone');
        $this->assertNotNull($first->answered_at);
        $this->assertNull($first->answered_by_user_id);
        $this->assertSame('Zaynab Quux', $first->contacter->name);
        $this->assertSame('Contact (old website)', $first->reason->text);
        $this->assertFalse((bool) $first->reason->show_to_users);

        // One sender per address, whatever its case.
        $this->assertSame(2, ContactUsAccount::count());
        $this->assertSame($messages[1]->contact_us_account_id, $messages[2]->contact_us_account_id);
        $this->assertSame(0, MobileAppUser::whereNotNull('onesignal_subscription_id')->count(), 'no push audience gains a device');

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function headers_are_matched_loosely_and_by_hand_and_unknown_columns_are_kept(): void
    {
        $file = $this->csv(
            ['Created date', 'First Name', 'Last Name', 'E-mail', 'Your Question', 'Referral'],
            [['2026-01-05 09:00', 'Hana', 'Quux', 'hana@example.test', 'Do you run Arabic classes?', 'A friend']],
            bom: true,
        );

        $this->import($file, ['--form' => 'Contact', '--map' => ['Your Question=message'], '--execute' => true, '--batch' => 'm1']);

        $message = $this->messages()->with('contacter')->firstOrFail();
        $this->assertSame("Do you run Arabic classes?\n\nOther fields:\nReferral: A friend", $message->message);
        $this->assertSame('Hana Quux', $message->contacter->name);
        $this->assertSame('hana@example.test', $message->contacter->email);
    }

    #[Test]
    public function the_header_mapping_follows_the_documented_aliases(): void
    {
        $mapping = app(WixFormMessageImport::class)->map(['Submission Date', 'Full Name', 'Email Address', 'Phone Number', 'Topic', 'Comments', 'Submission ID']);

        $this->assertSame([
            'Submission Date' => 'submitted_at',
            'Full Name' => 'name',
            'Email Address' => 'email',
            'Phone Number' => 'phone',
            'Topic' => 'subject',
            'Comments' => 'message',
            'Submission ID' => 'submission_id',
        ], $mapping);
    }

    #[Test]
    public function a_signup_row_with_no_message_still_reads_as_a_sentence(): void
    {
        $file = $this->csv(
            ['Date', 'Name', 'Phone Number', 'Email Address'],
            [['2026-09-24 12:00', 'Sami Zork', '(336) 555-0199', 'sami@example.test']],
        );

        $this->import($file, ['--form' => 'Get Subscribers 2', '--execute' => true, '--batch' => 'm1']);

        $message = $this->messages()->with('contacter')->firstOrFail();
        $this->assertSame('Submitted the "Get Subscribers 2" form on the old website.', $message->message);
        $this->assertSame('(336) 555-0199', $message->contacter->phone);
    }

    #[Test]
    public function running_the_same_file_twice_imports_each_submission_once(): void
    {
        $withIds = $this->csv(['Submission ID', 'Date', 'Email', 'Message'], [
            ['sub-1', '2026-02-01 10:00', 'a@example.test', 'One'],
            ['sub-2', '2026-02-02 10:00', 'b@example.test', 'Two'],
        ]);

        $this->import($this->contactFormCsv(), ['--form' => 'Contact', '--execute' => true, '--batch' => 'm1']);
        $this->import($withIds, ['--form' => 'Contact', '--execute' => true, '--batch' => 'm2']);
        [, $output] = $this->import($this->contactFormCsv(), ['--form' => 'Contact', '--execute' => true, '--batch' => 'm3']);
        $this->import($withIds, ['--form' => 'Contact', '--execute' => true, '--batch' => 'm4']);

        $this->assertSame(5, $this->messages()->count());
        $this->assertMatchesRegularExpression('/Already imported \(skipped\)\s*\|\s*3/', $output);
    }

    #[Test]
    public function an_unreadable_row_refuses_the_whole_write(): void
    {
        $file = $this->csv(['Date', 'Email', 'Message'], [
            ['2026-02-01 10:00', 'a@example.test', 'Fine'],
            ['not a date', 'b@example.test', 'Broken'],
        ]);

        [$code, $output] = $this->import($file, ['--form' => 'Contact', '--execute' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('row 3: the submission date cannot be read', $output);
        $this->assertSame(0, ContactUsMessage::count());
    }

    #[Test]
    public function a_file_with_no_date_column_is_refused_before_anything_else(): void
    {
        $file = $this->csv(['Name', 'Email', 'Message'], [['A', 'a@example.test', 'Hi']]);

        [$code, $output] = $this->import($file, ['--form' => 'Contact']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('No column holds the submission date', $output);
    }

    #[Test]
    public function undo_removes_the_runs_messages_and_the_senders_it_created(): void
    {
        $this->import($this->contactFormCsv(), ['--form' => 'Contact', '--execute' => true, '--batch' => 'm1']);
        $unrelated = MobileAppUser::create(['masjid_id' => $this->masjid->id, 'device_id' => 'a-real-device', 'user_agent' => 'Safari']);

        [$code, $output] = $this->import(null, ['--undo' => 'm1']);

        $this->assertSame(0, $code, $output);
        $this->assertSame(0, ContactUsMessage::count());
        $this->assertSame(0, ContactUsAccount::count());
        $this->assertSame([$unrelated->id], MobileAppUser::pluck('id')->all());
        $this->assertSame(0, ImportLink::withoutMasjidScope()->count());
    }

    #[Test]
    public function undo_is_refused_once_staff_have_replied_to_an_imported_message(): void
    {
        $this->import($this->contactFormCsv(), ['--form' => 'Contact', '--execute' => true, '--batch' => 'm1']);
        $message = $this->messages()->firstOrFail();
        ContactUsReply::forceCreate([
            'contact_us_message_id' => $message->id,
            'body' => 'Yes, it is free.',
            'sent_to' => 'zaynab@example.test',
            'sent_at' => now(),
        ]);

        [$code, $output] = $this->import(null, ['--undo' => 'm1']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString((string) $message->id, $output);
        $this->assertSame(3, ContactUsMessage::count());
    }

    #[Test]
    public function messages_are_filed_only_under_the_named_organisation(): void
    {
        $this->import($this->contactFormCsv(), ['--form' => 'Contact', '--execute' => true, '--batch' => 'm1'], $this->other);

        $this->assertSame(0, $this->messages()->count());
        $this->assertSame(3, $this->messages($this->other)->count());
        $this->assertSame(0, MobileAppUser::where('masjid_id', $this->masjid->id)->count());

        // The same file into our organisation is not "already imported" there.
        $this->import($this->contactFormCsv(), ['--form' => 'Contact', '--execute' => true, '--batch' => 'm2']);
        $this->assertSame(3, $this->messages()->count());
    }

    #[Test]
    public function a_submission_dated_in_the_future_or_before_wix_existed_refuses_the_whole_write(): void
    {
        $file = $this->csv(['Date', 'Email', 'Message'], [
            ['2026-02-01 10:00', 'a@example.test', 'Fine'],
            [now()->addYear()->format('Y-m-d H:i'), 'b@example.test', 'A misread column'],
            ['2004-05-01 10:00', 'c@example.test', 'Before Wix'],
        ]);

        [$code, $output] = $this->import($file, ['--form' => 'Contact', '--execute' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('row 3: the submission date cannot be read', $output);
        $this->assertStringContainsString('row 4: the submission date cannot be read', $output);
        $this->assertSame(0, ContactUsMessage::count());
    }

    #[Test]
    public function undo_of_one_run_keeps_a_sender_a_later_run_filed_messages_under(): void
    {
        $this->import($this->csv(['Date', 'Email', 'Message'], [['2026-02-01 10:00', 'same@example.test', 'First']]),
            ['--form' => 'Contact', '--execute' => true, '--batch' => 'm1']);
        $this->import($this->csv(['Date', 'Email', 'Message'], [['2026-03-01 10:00', 'same@example.test', 'Second']]),
            ['--form' => 'Get Subscribers 2', '--execute' => true, '--batch' => 'm2']);
        $this->assertSame(1, ContactUsAccount::count(), 'one sender across both runs');

        [$code, $output] = $this->import(null, ['--undo' => 'm1']);

        $this->assertSame(0, $code, $output);
        $this->assertSame(['Second'], $this->messages()->pluck('message')->all(), 'the later run\'s message survives');
        $this->assertSame(1, ContactUsAccount::count());
        $this->assertSame(1, ImportLink::withoutMasjidScope()->where('import_batch', 'm2')->count());
        $this->assertSame(1, ImportLink::withoutMasjidScope()->where('kind', ImportLink::KIND_CONTACT_US_ACCOUNT)->count(),
            'the sender\'s link stays, so a further run reuses it');
    }

    #[Test]
    public function undo_is_not_refused_by_a_staff_reply_to_a_message_the_run_did_not_import(): void
    {
        $this->import($this->contactFormCsv(), ['--form' => 'Contact', '--execute' => true, '--batch' => 'm1']);

        // A message that came in through the website, and was answered, in the same organisation.
        $device = MobileAppUser::create(['masjid_id' => $this->masjid->id, 'device_id' => 'a-real-device', 'user_agent' => 'Safari']);
        $account = ContactUsAccount::create(['mobile_app_user_id' => $device->id, 'email' => 'native@example.test', 'name' => 'Native Sender']);
        $native = new ContactUsMessage();
        $native->forceFill([
            'masjid_id' => $this->masjid->id, 'contact_us_account_id' => $account->id,
            'contact_us_reason_id' => ContactUsReason::firstOrCreate(['text' => 'General'])->id, 'message' => 'Hello',
        ])->save();
        ContactUsReply::forceCreate(['contact_us_message_id' => $native->id, 'body' => 'Wa alaykum', 'sent_to' => 'native@example.test', 'sent_at' => now()]);

        [$code, $output] = $this->import(null, ['--undo' => 'm1']);

        $this->assertSame(0, $code, $output);
        $this->assertSame([$native->id], ContactUsMessage::pluck('id')->all());
    }

    #[Test]
    public function imported_senders_are_keyed_so_no_address_can_be_recomputed_from_the_links_or_the_device(): void
    {
        $this->import($this->contactFormCsv(), ['--form' => 'Contact', '--execute' => true, '--batch' => 'm1']);

        $plain = hash('sha256', 'zaynab@example.test');
        $keys = ImportLink::withoutMasjidScope()->where('kind', ImportLink::KIND_CONTACT_US_ACCOUNT)->pluck('external_id')->all();
        $this->assertCount(2, $keys);
        $this->assertNotContains('sender:' . $plain, $keys);

        foreach (MobileAppUser::pluck('device_id') as $deviceId) {
            $this->assertStringStartsWith('import-wix-' . $this->masjid->id . '-', $deviceId);
            $this->assertStringNotContainsString(substr($plain, 0, 40), $deviceId);
        }

        // The key is still stable, so a later run files under the same sender.
        $this->import($this->csv(['Date', 'Email', 'Message'], [['2026-03-01 10:00', 'Zaynab@example.test', 'Again']]),
            ['--form' => 'Contact', '--execute' => true, '--batch' => 'm2']);
        $this->assertSame(2, ContactUsAccount::count());
    }

    #[Test]
    public function a_batch_name_already_used_in_the_organisation_is_refused_before_anything_is_written(): void
    {
        $this->import($this->contactFormCsv(), ['--form' => 'Contact', '--execute' => true, '--batch' => 'm1']);

        [$code, $output] = $this->import($this->csv(['Date', 'Email', 'Message'], [['2026-03-01 10:00', 'new@example.test', 'New']]),
            ['--form' => 'Contact', '--execute' => true, '--batch' => 'm1']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Batch m1 has already been used', $output);
        $this->assertSame(3, $this->messages()->count());
    }
}
