<?php

namespace Tests\Feature;

use App\Mail\DonationReceiptMail;
use App\Mail\AnnualStatementMail;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Receipts\DonationReceiptPdfService;
use App\Services\Receipts\ReceiptService;
use App\Services\Receipts\StatementLetterService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PdfMailDatabaseQueueTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $org;
    private Contact $donor;
    private Fund $fund;
    private Donation $gift;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'sqlite',
            'mail.default' => 'array',
            'services.stripe.currency' => 'usd',
        ]);
        app(TenantContext::class)->forgetTenant();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->org = Masjid::create([
            'name' => 'Example Organisation', 'email' => 'office@example.test',
            'phone' => '+15555550100', 'country_id' => '1', 'city_id' => '1',
            'address' => '1 Example Street', 'latitude' => 0, 'longitude' => 0,
            'crm_enabled' => true,
        ]);
        $this->fund = Fund::withoutMasjidScope()->create([
            'masjid_id' => $this->org->id, 'name' => 'Example Fund', 'type' => 'general',
            'receiptable' => true, 'is_active' => true,
        ]);
        $this->donor = $this->donor('first@example.test', 25000);
        $this->gift = Donation::withoutMasjidScope()->where('contact_id', $this->donor->id)->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15555550101']));
    }

    public static function orgTypes(): array
    {
        return ['masjid' => ['masjid', true], 'school' => ['school', false]];
    }

    #[Test]
    #[DataProvider('orgTypes')]
    public function annual_statement_survives_the_database_queue_with_the_requested_letter(string $type, bool $religious): void
    {
        $this->org->forceFill(['org_type' => $type])->save();
        $pdf = $this->get($this->url()."/{$this->donor->id}/pdf?year=2025")->assertOk()->content();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertFalse(mb_check_encoding($pdf, 'UTF-8'), 'The fixture must reproduce binary PDF serialization.');

        // Real download renderer above; reuse precisely those bytes for the mail.
        // Any attempt to render again in the worker violates this expectation.
        $this->mock(StatementLetterService::class, function ($mock) use ($pdf) {
            $mock->shouldReceive('pdfFor')->once()->with($this->org->id, $this->donor->id, 2025)->andReturn($pdf);
            $mock->shouldReceive('filename')->once()->andReturn('2025-giving-statement-Example-Donor.pdf');
        });
        $this->post($this->url()."/{$this->donor->id}/send?year=2025")
            ->assertOk()->assertJsonPath('message', 'Statement queued for delivery.');
        $this->assertSame(1, DB::table('jobs')->count());
        $payload = json_decode(DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $queuedMail = unserialize($payload['data']['command'])->mailable;
        $this->assertInstanceOf(AnnualStatementMail::class, $queuedMail);
        $this->assertSame($this->org->id, $queuedMail->masjidId);
        $this->assertSame($pdf, $queuedMail->pdf);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());

        // The worker must not consult a later ledger, wording, recipient or tenant.
        $this->org->forceFill(['name' => 'Changed Organisation', 'org_type' => $religious ? 'school' : 'masjid'])->save();
        $this->gift->forceFill(['charged_amount' => 99900])->save();
        $this->donor->forceFill(['email' => 'changed@example.test'])->save();
        app(TenantContext::class)->set(999999);
        $this->travel(2)->days();
        $this->runOne();

        $message = $this->messageWithPdf('first@example.test', $pdf, '2025-giving-statement-Example-Donor.pdf');
        $this->assertStringContainsString('2025 Annual Giving Statement', $message->getSubject());
        $this->assertSame('Example Organisation', $message->getFrom()[0]->getName());
        $this->assertStringContainsString('250.00', $message->getHtmlBody());
        $this->assertSame($religious, str_contains($message->getHtmlBody(), 'intangible religious benefits'));
        $this->assertStringNotContainsString('Changed Organisation', $message->getHtmlBody());
        $this->assertNull(app(TenantContext::class)->get());
    }

    #[Test]
    public function an_explicitly_queued_donation_receipt_preserves_its_real_pdf_and_recipient(): void
    {
        $receipt = app(ReceiptService::class)->issueFor($this->gift);
        $pdf = app(DonationReceiptPdfService::class)->pdfFor($receipt);
        $this->assertFalse(mb_check_encoding($pdf, 'UTF-8'));
        Mail::to('first@example.test')->queue(new DonationReceiptMail(
            masjidName: 'Example Organisation', donorName: 'Example Donor', serial: $receipt->serial_number,
            issueDate: 'January 1, 2026', fundName: 'Example Fund', currency: 'USD',
            grossAmount: '250.00', eligibleAmount: '250.00', reference: $this->gift->uuid,
            pdf: $pdf, pdfName: 'donation-receipt-1.pdf', religiousOrg: false,
        ));
        $this->assertSame(1, DB::table('jobs')->count());
        app(TenantContext::class)->set(999999);
        $this->runOne();
        $message = $this->messageWithPdf('first@example.test', $pdf, 'donation-receipt-1.pdf');
        $this->assertStringContainsString('250.00', $message->getHtmlBody());
        $this->assertStringNotContainsString('intangible religious benefit', $message->getHtmlBody());
    }

    #[Test]
    #[DataProvider('bulkDonorOutcomes')]
    public function bulk_counts_each_multi_currency_donor_once(?string $email, bool $refuseQueue, int $queued, int $skipped, int $failed): void
    {
        $this->donor->forceFill(['email' => $email])->save();
        $this->gift->forceFill(['currency' => 'usd'])->save();
        Donation::factory()->create([
            'masjid_id' => $this->org->id, 'fund_id' => $this->fund->id, 'contact_id' => $this->donor->id,
            'source' => 'offline', 'payment_method' => 'check', 'donated_at' => '2025-04-14',
            'status' => 'succeeded', 'currency' => 'cad', 'intended_amount' => 15000, 'charged_amount' => 15000,
        ]);
        $this->donor('second@example.test', 10000);
        $this->get($this->url().'?year=2025')->assertOk()->assertJsonCount(3, 'data.donors');

        $refused = 0;
        if ($refuseQueue) {
            DB::connection()->beforeExecuting(function ($query, $bindings) use (&$refused) {
                if (str_starts_with(strtolower($query), 'insert into "jobs"') && str_contains(implode(' ', $bindings), 'first@example.test')) {
                    $refused++;
                    throw new \RuntimeException('Queue insert refused for first@example.test');
                }
            });
        }

        $this->post($this->url().'/send-all?year=2025')->assertOk()
            ->assertJsonPath('data.queued', $queued)->assertJsonPath('data.skipped', $skipped)->assertJsonPath('data.failed', $failed);
        $this->assertSame($refuseQueue ? 1 : 0, $refused);
        $this->assertSame($queued, DB::table('jobs')->count());
        foreach (DB::table('jobs')->pluck('payload') as $payload) {
            $mail = unserialize(json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['data']['command'])->mailable;
            $this->assertInstanceOf(AnnualStatementMail::class, $mail);
            $this->assertSame($mail->hasTo('first@example.test') ? 2 : 1, $mail->giftCount);
            $this->assertStringStartsWith('%PDF-', $mail->pdf);
            app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0, maxTries: 1));
        }
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount($queued, $messages);
        $recipients = array_map(fn ($message) => $message->getOriginalMessage()->getTo()[0]->getAddress(), $messages->all());
        $this->assertEqualsCanonicalizing($queued === 2 ? ['first@example.test', 'second@example.test'] : ['second@example.test'], $recipients);
    }

    public static function bulkDonorOutcomes(): array
    {
        return [
            'queued' => ['first@example.test', false, 2, 0, 0],
            'no email' => [null, false, 1, 1, 0],
            'queue failure' => ['first@example.test', true, 1, 0, 1],
        ];
    }

    #[Test]
    public function bulk_counts_queue_failures_separately_and_continues_to_the_next_donor(): void
    {
        $this->donor('second@example.test', 10000);
        $this->donor(null, 5000);
        // Throw at the real database insertion boundary, only for the first donor.
        $refused = 0;
        DB::connection()->beforeExecuting(function ($query, $bindings) use (&$refused) {
            if (str_starts_with(strtolower($query), 'insert into "jobs"') && str_contains(implode(' ', $bindings), 'first@example.test')) {
                $refused++;
                throw new \RuntimeException('Queue insert refused for first@example.test');
            }
        });
        $this->post($this->url().'/send-all?year=2025')->assertOk()
            ->assertJsonPath('data.queued', 1)->assertJsonPath('data.skipped', 1)->assertJsonPath('data.failed', 1);
        $this->assertSame(1, $refused, 'The queue insert failure must actually have been injected.');
        $this->assertSame(1, DB::table('jobs')->count());
        $this->runOne();
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('second@example.test', $messages[0]->getOriginalMessage()->getTo()[0]->getAddress());
    }

    #[Test]
    public function bulk_contains_a_pdf_render_failure_and_still_queues_the_next_donor(): void
    {
        $this->donor('second@example.test', 10000);
        $this->donor(null, 5000);
        $letters = app(StatementLetterService::class);
        $this->mock(StatementLetterService::class, function ($mock) use ($letters) {
            $mock->shouldReceive('pdfFor')->with($this->org->id, $this->donor->id, 2025)->once()->andThrow(new \RuntimeException('Render refused'));
            $mock->shouldReceive('pdfFor')->andReturnUsing(fn (...$args) => $letters->pdfFor(...$args));
            $mock->shouldReceive('filename')->andReturnUsing(fn (...$args) => $letters->filename(...$args));
        });
        $this->post($this->url().'/send-all?year=2025')->assertOk()
            ->assertJsonPath('data.queued', 1)->assertJsonPath('data.skipped', 1)->assertJsonPath('data.failed', 1);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->runOne();
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
    }

    #[Test]
    public function a_failed_single_render_is_reported_as_failure_without_queueing_html_only_mail(): void
    {
        $this->mock(StatementLetterService::class, function ($mock) {
            $mock->shouldReceive('pdfFor')->once()->andReturn(null);
        });
        $this->post($this->url()."/{$this->donor->id}/send?year=2025")
            ->assertStatus(500)->assertJsonPath('message', 'Failed to queue statement.');
        $this->assertSame(0, DB::table('jobs')->count());
    }

    private function donor(?string $email, int $amount): Contact
    {
        $contact = Contact::factory()->create([
            'masjid_id' => $this->org->id, 'first_name' => 'Example', 'last_name' => 'Donor', 'email' => $email,
        ]);
        Donation::factory()->create([
            'masjid_id' => $this->org->id, 'fund_id' => $this->fund->id, 'contact_id' => $contact->id,
            'source' => 'offline', 'payment_method' => 'check', 'donated_at' => '2025-03-14',
            'status' => 'succeeded', 'intended_amount' => $amount, 'charged_amount' => $amount,
        ]);
        return $contact;
    }

    private function url(): string
    {
        return "/api/admin/masjids/{$this->org->id}/annual-statements";
    }

    private function runOne(): void
    {
        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0, maxTries: 1));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    private function messageWithPdf(string $recipient, string $pdf, string $filename): \Symfony\Component\Mime\Email
    {
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $message = $messages[0]->getOriginalMessage();
        $this->assertSame([$recipient], array_map(fn ($to) => $to->getAddress(), $message->getTo()));
        $this->assertCount(1, $message->getAttachments());
        $attachment = $message->getAttachments()[0];
        $this->assertSame('application', $attachment->getMediaType());
        $this->assertSame('pdf', $attachment->getMediaSubtype());
        $this->assertSame($filename, $attachment->getFilename());
        $this->assertSame($pdf, $attachment->getBody());
        return $message;
    }
}
