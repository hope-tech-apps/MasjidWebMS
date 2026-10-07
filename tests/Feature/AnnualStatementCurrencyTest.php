<?php

namespace Tests\Feature;

use App\Mail\AnnualStatementMail;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\Fund;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Receipts\AnnualStatementService;
use App\Services\Receipts\ReceiptService;
use App\Services\Receipts\StatementLetterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\View;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnnualStatementCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $org;
    private Contact $donor;
    private Fund $fund;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
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
        $this->donor = Contact::factory()->create([
            'masjid_id' => $this->org->id, 'first_name' => 'Example', 'last_name' => 'Donor',
            'email' => 'donor@example.test',
        ]);
        Sanctum::actingAs(User::factory()->create(['type' => 'SuperAdmin', 'phone' => '+15555550101']));
    }

    private function gift(string $currency, int $amount, string $date = '2025-03-14'): Donation
    {
        return Donation::factory()->succeeded()->create([
            'masjid_id' => $this->org->id, 'fund_id' => $this->fund->id, 'contact_id' => $this->donor->id,
            'currency' => $currency, 'charged_amount' => $amount, 'intended_amount' => $amount,
            'donated_at' => $date, 'source' => 'offline',
        ]);
    }

    private function mixed(): void
    {
        app(ReceiptService::class)->issueFor($this->gift('usd', 25000));
        $this->gift('cad', 10000, '2025-04-15');
        $secondFund = Fund::withoutMasjidScope()->create([
            'masjid_id' => $this->org->id, 'name' => 'Second Fund', 'type' => 'general',
            'receiptable' => true, 'is_active' => true,
        ]);
        $this->gift('usd', 5000, '2025-05-16')->update(['fund_id' => $secondFund->id]);
    }

    private function url(): string
    {
        return "/api/admin/masjids/{$this->org->id}/annual-statements";
    }

    private function letterData(): array
    {
        $data = [];
        View::composer('pdf.annual-statement', function ($view) use (&$data) { $data = $view->getData(); });
        $pdf = app(StatementLetterService::class)->pdfFor($this->org->id, $this->donor->id, 2025);
        $this->assertStringStartsWith('%PDF-', $pdf);
        return $data;
    }

    #[Test]
    public function offline_entry_can_record_two_currencies_for_the_same_org_and_donor_over_time(): void
    {
        foreach (['usd', 'cad'] as $currency) {
            config(['services.stripe.currency' => $currency]);
            $this->post('/api/admin/masjids/'.$this->org->id.'/donations', [
                'fund_id' => $this->fund->id, 'contact_id' => $this->donor->id,
                'amount' => '100.00', 'payment_method' => 'cash', 'donated_at' => '2025-06-01',
            ])->assertCreated()->assertJsonPath('data.currency', $currency);
        }
        $this->assertSame(['usd', 'cad'], Donation::withoutGlobalScopes()
            ->where('masjid_id', $this->org->id)->where('contact_id', $this->donor->id)
            ->orderBy('id')->pluck('currency')->all());
    }

    #[Test]
    public function mixed_statement_has_no_combined_money_and_preserves_gifts_funds_and_serials(): void
    {
        $this->mixed();
        $data = app(AnnualStatementService::class)->forContact($this->org->id, $this->donor->id, 2025);
        $this->assertNull($data['total_eligible']);
        $this->assertNull($data['currency']);
        $this->assertSame(3, $data['gift_count']);
        $this->assertSame(['USD', 'CAD'], array_column($data['currencies'], 'currency'));
        $this->assertSame([30000, 10000], array_column($data['currencies'], 'total_eligible'));
        $this->assertSame([['Example Fund' => 25000, 'Second Fund' => 5000], ['Example Fund' => 10000]], array_column($data['currencies'], 'by_fund'));
        $this->assertSame([1, null, null], array_column($data['gifts'], 'serial'));
        $this->assertSame(['USD', 'CAD', 'USD'], array_column($data['gifts'], 'currency'));
    }

    #[Test]
    public function summary_keeps_currency_rows_and_exposes_only_currency_totals(): void
    {
        $this->mixed();
        $data = $this->getJson($this->url().'?year=2025')->assertOk()->json('data');
        $this->assertNull($data['total_eligible']);
        $this->assertCount(2, $data['donors']);
        $this->assertSame(['USD' => 30000, 'CAD' => 10000], $data['totals_by_currency']);
        $preview = $this->getJson($this->url()."/{$this->donor->id}?year=2025")->assertOk()->json('data');
        $this->assertNull($preview['total_eligible']);
        $this->assertSame(['300.00', '100.00'], array_column($preview['currencies'], 'total_eligible'));
    }

    #[Test]
    public function mixed_letter_labels_each_total_and_each_gift_with_its_currency(): void
    {
        $this->mixed();
        $html = view('pdf.annual-statement', $this->letterData())->render();
        foreach (['USD 300.00', 'CAD 100.00', 'USD 250.00', 'USD 50.00'] as $figure) {
            $this->assertStringContainsString($figure, $html);
        }
        $this->assertStringNotContainsString('USD 400.00', $html);
        $this->assertStringNotContainsString('USD 100.00', $html);
    }

    #[Test]
    public function mixed_mail_round_trips_without_losing_currency_sections_and_bulk_queues_once(): void
    {
        $this->mixed();
        config([
            'queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite',
            'mail.default' => 'array',
        ]);
        $this->post($this->url().'/send-all?year=2025')->assertOk()->assertJsonPath('data.queued', 1);
        $this->assertSame(1, DB::table('jobs')->count());
        $payload = json_decode(DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $restored = unserialize($payload['data']['command'])->mailable;
        $this->assertNull($restored->totalEligible);
        $this->assertNull($restored->currency);
        $this->assertSame(['USD', 'CAD'], array_column($restored->currencies, 'currency'));
        $this->assertSame([
            ['fund' => 'Example Fund', 'amount' => '250.00'],
            ['fund' => 'Second Fund', 'amount' => '50.00'],
        ], $restored->currencies[0]['by_fund']);
        // A later ledger edit cannot change either the email or its PDF snapshot.
        Donation::withoutGlobalScopes()->where('masjid_id', $this->org->id)->update(['charged_amount' => 99900]);
        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0, maxTries: 1));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $message = $messages[0]->getOriginalMessage();
        $this->assertSame('donor@example.test', $message->getTo()[0]->getAddress());
        $this->assertSame($restored->pdf, $message->getAttachments()[0]->getBody());
        $html = $message->getHtmlBody();
        foreach (['USD 300.00', 'CAD 100.00', 'USD 250.00', 'USD 50.00'] as $figure) {
            $this->assertStringContainsString($figure, $html);
        }
        $this->assertStringNotContainsString('USD 400.00', $html);
        $this->assertStringNotContainsString('USD 100.00', $html);
    }

    #[Test]
    public function single_currency_rendered_data_and_document_bytes_stay_identical_for_all_org_types(): void
    {
        app(ReceiptService::class)->issueFor($this->gift('usd', 25000));
        $this->gift('usd', 5000, '2025-05-16');
        foreach (['masjid', 'school', 'community'] as $type) {
            $this->org->forceFill(['org_type' => $type])->save();
            $statement = app(AnnualStatementService::class)->forContact($this->org->id, $this->donor->id, 2025);
            $this->assertSame([
                'contact', 'year', 'currency', 'total_eligible', 'gift_count', 'gifts', 'by_fund',
            ], array_keys($statement));
            $this->assertSame('USD', $statement['currency']);
            $this->assertSame(30000, $statement['total_eligible']);
            $this->assertSame([
                ['date' => 'Mar 14, 2025', 'fund' => 'Example Fund', 'amount' => 25000, 'serial' => 1],
                ['date' => 'May 16, 2025', 'fund' => 'Example Fund', 'amount' => 5000, 'serial' => null],
            ], $statement['gifts']);
            $data = $this->letterData();
            $this->assertSame('300.00', $data['totalEligible']);
            $this->assertSame([
                ['date' => 'Mar 14, 2025', 'fund' => 'Example Fund', 'amount' => '250.00'],
                ['date' => 'May 16, 2025', 'fund' => 'Example Fund', 'amount' => '50.00'],
            ], $data['gifts']);
            $this->assertSame(view()->file(base_path('tests/fixtures/statements-78ceee4c/pdf.blade.php'), $data)->render(), view('pdf.annual-statement', $data)->render());
            Mail::fake();
            $this->post($this->url()."/{$this->donor->id}/send?year=2025")->assertOk();
            Mail::assertQueued(AnnualStatementMail::class, function ($mail) {
                $this->assertSame('USD', $mail->currency);
                $this->assertSame('300.00', $mail->totalEligible);
                $data = $mail->content()->with;
                $this->assertSame([
                    'masjidName', 'donorName', 'year', 'currency', 'totalEligible',
                    'giftCount', 'gifts', 'byFund', 'religiousOrg',
                ], array_keys($data));
                $this->assertSame([['fund' => 'Example Fund', 'amount' => '300.00']], $mail->byFund);
                $this->assertSame(view()->file(base_path('tests/fixtures/statements-78ceee4c/email.blade.php'), $data)->render(), $mail->render());
                return true;
            });
        }
    }
}
