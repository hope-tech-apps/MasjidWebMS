<?php

namespace Tests\Feature;

use App\Mail\AnnualStatementMail;
use App\Mail\DonationReceiptMail;
use App\Models\Contact;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Fund;
use App\Models\Masjid;
use App\Services\Receipts\DonationReceiptPdfService;
use App\Services\Receipts\Letterhead;
use App\Services\Receipts\ReceiptService;
use App\Services\Receipts\StatementLetterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tax wording on receipts and year-end statements by organisation type.
 *
 * Only a religious organisation may tell a donor they received "intangible
 * religious benefits". Once a school or community organisation can take gifts,
 * its receipts drop that clause and state 501(c)(3) status only when a tax ID
 * is on file. A masjid's documents must not change by a single byte.
 *
 * "Byte-identical" is pinned against the blades as they stood at c0f6a72,
 * copied verbatim into tests/fixtures/receipts-c0f6a72. Each masjid document is
 * rendered twice from the SAME data, once through the live blade and once
 * through the frozen copy, and the two strings must be equal. The expectation
 * therefore comes from the old blade source, never from the new branch.
 *
 * The last test covers the queue. AnnualStatementMail is ShouldQueue, and a
 * statement queued before `religiousOrg` existed has no such key in its payload.
 * It must still unserialize and render the masjid wording.
 */
class ReceiptWordingByOrgTypeTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = 2025;

    private const FIXTURES = 'tests/fixtures/receipts-c0f6a72';

    /** DonationReceiptMail's arguments in the pre-org-type constructor shape. */
    private const RECEIPT_MAIL = [
        'masjidName' => 'Masjid Al-Noor',
        'donorName' => 'Amina Yusuf',
        'serial' => 7,
        'issueDate' => '2025-03-14',
        'fundName' => 'General Fund',
        'currency' => 'USD',
        'grossAmount' => '125.00',
        'eligibleAmount' => '125.00',
        'reference' => '9b2c4c1e-0000-4000-8000-000000000001',
        'recurring' => true,
    ];

    /** AnnualStatementMail's arguments in the pre-org-type constructor shape. */
    private const STATEMENT_MAIL = [
        'masjidName' => 'Masjid Al-Noor',
        'donorName' => 'Amina Yusuf',
        'year' => 2025,
        'currency' => 'USD',
        'totalEligible' => '325.00',
        'giftCount' => 2,
        'gifts' => [
            ['date' => 'Mar 14, 2025', 'fund' => 'General Fund', 'amount' => '125.00', 'serial' => 1],
            ['date' => 'Nov 30, 2025', 'fund' => 'Zakat', 'amount' => '200.00', 'serial' => 2],
        ],
        'byFund' => [
            ['fund' => 'General Fund', 'amount' => '125.00'],
            ['fund' => 'Zakat', 'amount' => '200.00'],
        ],
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

        config(['services.stripe.currency' => 'usd']);
    }

    // ===================== masjid: byte-identical =====================

    #[Test]
    public function a_masjid_receipt_pdf_is_byte_identical_to_the_c0f6a72_blade(): void
    {
        // Both arms of the old blade's own tax-ID conditional.
        foreach (['46-4693999', null] as $taxId) {
            [$receipt] = $this->receiptedGift($this->makeOrg(Masjid::ORG_TYPE_MASJID, $taxId));

            $data = $this->receiptDocumentData($receipt);

            $this->assertTrue($data['religiousOrg']);
            $this->assertSame(
                $this->renderFixture('pdf-donation-receipt', $data),
                view('pdf.donation-receipt', $data)->render(),
                'A masjid receipt PDF changed (tax ID: ' . ($taxId ?? 'none') . ').'
            );
        }
    }

    #[Test]
    public function a_masjid_statement_letter_is_byte_identical_to_the_c0f6a72_blade(): void
    {
        foreach (['46-4693999', null] as $taxId) {
            $org = $this->makeOrg(Masjid::ORG_TYPE_MASJID, $taxId);
            [, $donor] = $this->receiptedGift($org);

            $data = $this->statementDocumentData($org, $donor);

            $this->assertTrue($data['religiousOrg']);
            $this->assertSame(
                $this->renderFixture('pdf-annual-statement', $data),
                view('pdf.annual-statement', $data)->render(),
                'A masjid statement letter changed (tax ID: ' . ($taxId ?? 'none') . ').'
            );
        }
    }

    #[Test]
    public function a_masjid_receipt_email_is_byte_identical_to_the_c0f6a72_blade(): void
    {
        // The pre-org-type constructor shape: no religiousOrg argument at all.
        $mail = new DonationReceiptMail(...self::RECEIPT_MAIL);

        $this->assertTrue($mail->religiousOrg);
        $this->assertSame(
            $this->renderFixture('email-donation-receipt', self::RECEIPT_MAIL),
            $mail->render()
        );
    }

    #[Test]
    public function a_masjid_statement_email_is_byte_identical_to_the_c0f6a72_blade(): void
    {
        $mail = new AnnualStatementMail(...self::STATEMENT_MAIL);

        $this->assertTrue($mail->religiousOrg);
        $this->assertSame(
            $this->renderFixture('email-annual-statement', self::STATEMENT_MAIL),
            $mail->render()
        );
    }

    // ==================== school: no religious wording ====================

    #[Test]
    public function a_school_without_a_tax_id_prints_neither_religious_wording_nor_a_501c3_claim(): void
    {
        $school = $this->makeOrg(Masjid::ORG_TYPE_SCHOOL, null);
        [$receipt, $donor] = $this->receiptedGift($school);

        $receiptData = $this->receiptDocumentData($receipt);
        $this->assertFalse($receiptData['religiousOrg']);
        $receiptPdf = view('pdf.donation-receipt', $receiptData)->render();

        $letterData = $this->statementDocumentData($school, $donor);
        $this->assertFalse($letterData['religiousOrg']);
        $letterPdf = view('pdf.annual-statement', $letterData)->render();

        $receiptEmail = (new DonationReceiptMail(...array_merge(self::RECEIPT_MAIL, ['religiousOrg' => false])))->render();
        $statementEmail = (new AnnualStatementMail(...array_merge(self::STATEMENT_MAIL, ['religiousOrg' => false])))->render();

        foreach (compact('receiptPdf', 'letterPdf', 'receiptEmail', 'statementEmail') as $document => $html) {
            $this->assertStringNotContainsString('religious', $html, "{$document} still carries religious wording.");
            $this->assertStringNotContainsString('501(c)(3)', $html, "{$document} claims 501(c)(3) status with no tax ID.");
            $this->assertStringNotContainsString('tax-exempt', $html, "{$document} claims tax-exempt status with no tax ID.");
            $this->assertStringNotContainsString('tax ID number', $html, "{$document} prints a tax ID line with no tax ID.");
        }

        // The no-goods-or-services statement stays: every acknowledgment needs it.
        $this->assertStringContainsString(
            'No goods or services were provided in exchange for or in connection with this contribution. You can',
            $receiptPdf
        );
        $this->assertStringContainsString(
            'No goods or services were provided in exchange for or in connection with these contributions. You can',
            $letterPdf
        );
        $this->assertStringContainsString('No goods or services were provided in exchange for this contribution.', $receiptEmail);
        $this->assertStringContainsString('Please retain this receipt for your tax records.', $receiptEmail);
        $this->assertStringContainsString('No goods or services were provided in exchange for these contributions.', $statementEmail);
        $this->assertStringContainsString('Please retain this statement for your tax records.', $statementEmail);

        // The letter still acknowledges the total, closed off where the
        // 501(c)(3) sentence would have followed.
        $this->assertStringContainsString('during ' . self::YEAR . '.</p>', $letterPdf);
    }

    #[Test]
    public function a_school_with_a_tax_id_states_501c3_status_without_religious_wording(): void
    {
        $school = $this->makeOrg(Masjid::ORG_TYPE_SCHOOL, '12-3456789');
        [$receipt, $donor] = $this->receiptedGift($school);

        $receiptPdf = view('pdf.donation-receipt', $this->receiptDocumentData($receipt))->render();
        $letterPdf = view('pdf.annual-statement', $this->statementDocumentData($school, $donor))->render();

        foreach (compact('receiptPdf', 'letterPdf') as $document => $html) {
            $this->assertStringContainsString(
                'is a tax-exempt organization under section 501(c)(3) of the Internal Revenue Code.',
                $html,
                "{$document} dropped the 501(c)(3) sentence although a tax ID is on file."
            );
            $this->assertStringContainsString('US Federal tax ID number is 12-3456789.', $html);
            $this->assertStringNotContainsString('religious', $html, "{$document} still carries religious wording.");
        }
    }

    #[Test]
    public function only_a_masjid_is_a_religious_organisation_for_receipts(): void
    {
        $this->assertTrue(Letterhead::religiousOrg($this->makeOrg(Masjid::ORG_TYPE_MASJID, null)));
        $this->assertFalse(Letterhead::religiousOrg($this->makeOrg(Masjid::ORG_TYPE_SCHOOL, null)));
        $this->assertFalse(Letterhead::religiousOrg($this->makeOrg(Masjid::ORG_TYPE_COMMUNITY, '12-3456789')));

        // No organisation at all keeps the wording every receipt had before.
        $this->assertTrue(Letterhead::religiousOrg(null));
        $this->assertTrue(app(Letterhead::class)->forMasjid(null)['religiousOrg']);
    }

    // ============================== queue ==============================

    #[Test]
    public function a_statement_queued_before_religious_org_existed_still_unserializes_and_renders(): void
    {
        // A masjid statement's payload never names the key (it holds the
        // property's default), so it is the same shape the old class produced.
        $this->assertStringNotContainsString('religiousOrg', serialize(new AnnualStatementMail(...self::STATEMENT_MAIL)));

        // A school statement is the one payload that does carry the key, and a
        // round trip keeps it.
        $school = new AnnualStatementMail(...array_merge(self::STATEMENT_MAIL, ['religiousOrg' => false]));
        $payload = serialize($school);
        $this->assertFalse(unserialize($payload)->religiousOrg);

        // Cut the key out and fix the member count: these are the bytes a
        // statement queued by the pre-org-type class would hold.
        $entry = serialize('religiousOrg') . serialize(false);
        $this->assertSame(1, substr_count($payload, $entry));

        $old = preg_replace_callback(
            '/^O:(\d+):"([^"]+)":(\d+):\{/',
            fn (array $m) => 'O:' . $m[1] . ':"' . $m[2] . '":' . ((int) $m[3] - 1) . ':{',
            str_replace($entry, '', $payload)
        );

        $restored = unserialize($old);

        $this->assertInstanceOf(AnnualStatementMail::class, $restored);
        $this->assertTrue($restored->religiousOrg);
        $this->assertSame(
            $this->renderFixture('email-annual-statement', self::STATEMENT_MAIL),
            $restored->render()
        );
    }

    // ============================= helpers =============================

    /** Render the frozen c0f6a72 copy of a blade with the given data. */
    private function renderFixture(string $name, array $data): string
    {
        return view()->file(base_path(self::FIXTURES . "/{$name}.blade.php"), $data)->render();
    }

    /** The data the receipt PDF was rendered from, captured as the view composes. */
    private function receiptDocumentData(DonationReceipt $receipt): array
    {
        $captured = [];

        View::composer('pdf.donation-receipt', function ($view) use (&$captured) {
            $captured = $view->getData();
        });

        app(DonationReceiptPdfService::class)->pdfFor($receipt);

        $this->assertNotEmpty($captured, 'The receipt blade was never rendered.');

        return $captured;
    }

    /** The data the statement letter was rendered from, captured as the view composes. */
    private function statementDocumentData(Masjid $org, Contact $donor): array
    {
        $captured = [];

        View::composer('pdf.annual-statement', function ($view) use (&$captured) {
            $captured = $view->getData();
        });

        $pdf = app(StatementLetterService::class)->pdfFor($org->id, $donor->id, self::YEAR);

        $this->assertNotNull($pdf, 'The fixture donor had no statement for the year.');
        $this->assertNotEmpty($captured, 'The statement blade was never rendered.');

        return $captured;
    }

    /**
     * One receipted gift in YEAR from a named donor, which backs both the
     * receipt PDF and the year-end letter.
     *
     * @return array{0: DonationReceipt, 1: Contact}
     */
    private function receiptedGift(Masjid $org): array
    {
        $fund = Fund::withoutMasjidScope()->create([
            'masjid_id' => $org->id,
            'name' => 'General Fund',
            'type' => 'general',
            'receiptable' => true,
            'is_active' => true,
        ]);

        $donor = Contact::factory()->create([
            'masjid_id' => $org->id,
            'first_name' => 'Amina',
            'last_name' => 'Yusuf',
            'email' => 'amina@donor.test',
        ]);

        $donation = Donation::factory()->succeeded()->create([
            'masjid_id' => $org->id,
            'fund_id' => $fund->id,
            'contact_id' => $donor->id,
            'intended_amount' => 12500,
            'charged_amount' => 12500,
            'donated_at' => self::YEAR . '-03-14',
        ]);

        $receipt = app(ReceiptService::class)->issueFor($donation);
        $this->assertNotNull($receipt, 'The fixture donation did not issue a receipt.');

        return [$receipt, $donor];
    }

    private function makeOrg(string $orgType, ?string $taxId): Masjid
    {
        return Masjid::create([
            'name' => 'Test ' . ucfirst($orgType) . ' ' . uniqid(),
            'org_type' => $orgType,
            'email' => $orgType . '-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
            'tax_id' => $taxId,
            'statement_signatory' => 'The Board',
        ]);
    }
}
