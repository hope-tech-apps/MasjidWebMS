<?php

namespace Tests\Support;

use App\Models\Masjid;
use Illuminate\Support\Facades\File;

/**
 * A SYNTHETIC Wix order export in the exact shape the read-only Wix pull writes
 * (stores/orders.json with named columns; events/orders.json with the compact
 * `inv` projection; events/events.json), for the order-history import tests.
 *
 * Every name, email and answer below is invented (example.test). The shapes —
 * column names, the `2x Name @10; …` line string, the Wix Events invoice tuple,
 * a coupon code with Wix's stray space before the colon — are copied from the
 * real export so the parser is tested against what it will actually read.
 *
 * Paid money in this fixture, all of it:
 *   store #10001  $49.00  3 × Zakat-ul-Fitr @13 + 1 × Individual Iftar @10  → 2 donations
 *   store #10002  $45.00  2 × Fall Festival Ticket @10 + food tickets $25    → 1 registration + order-only
 *   store #10003  $45.00  3 × Hajj bazaar @20 less a $15 coupon              → 1 registration + adjustment
 *   store #10004  $60.00  1 × NEW Prayer Rug                                  → order-only
 *   event  EVT-1  $51.25  2 × bracelet @25 + $1.25 Wix fee (PayPal)           → 1 registration
 *   event  EVT-2  (canceled at checkout, never paid)                          → 1 cancelled registration
 *                 ------
 *                $250.25
 */
trait WritesWixOrderExport
{
    /** The stores CSV-in-JSON columns, verbatim from the Wix pull. */
    protected const WIX_STORE_COLUMNS = [
        'number', 'createdDateUTC', 'fulfillment(F=FULFILLED,N=NOT_FULFILLED)', 'archived(1/0)',
        'buyerContactId', 'buyerEmail', 'billingFirstName', 'billingLastName', 'billingPhone',
        'billingCompany', 'billingAddress', 'shippingOption(R=Redeem at MEC location)',
        'lineItems(qty x name @unitPrice [options])', 'totalUSD', 'discountUSD',
        'appliedDiscounts(code:amount)', 'buyerNote',
        // Not in the 2026-09-25 pull: the reader requires both, so the pull
        // that feeds the real run must add them (DECISIONS.md 2026-09-25).
        'paymentStatus', 'refundedUSD',
    ];

    /** A value that must never reach Manara: a Wix Events checkout answer. */
    protected const WIX_SECRET_ANSWER = 'Emergency contact: Harun Invented 555-0199';

    protected function makeOrgForWixImport(): Masjid
    {
        return Masjid::create([
            'name' => 'Org ' . uniqid(),
            'email' => 'org-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
            'timezone' => 'America/New_York',
        ]);
    }

    /** @param list<array> $storeRows @param list<array>|null $eventOrders */
    protected function writeWixExport(?array $storeRows = null, ?array $eventOrders = null): string
    {
        $dir = sys_get_temp_dir() . '/wix-export-' . uniqid();
        File::ensureDirectoryExists("{$dir}/stores");
        File::ensureDirectoryExists("{$dir}/events");

        file_put_contents("{$dir}/stores/orders.json", json_encode([
            'source' => 'synthetic test fixture',
            'columns' => self::WIX_STORE_COLUMNS,
            'rows' => $storeRows ?? $this->wixStoreRows(),
        ]));

        file_put_contents("{$dir}/events/orders.json", json_encode([
            'orders' => $eventOrders ?? $this->wixEventOrders(),
        ]));

        file_put_contents("{$dir}/events/events.json", json_encode([
            'events' => [[
                'id' => 'ev-fall-2024',
                'title' => 'Fall Festival (7th Annual)',
                'status' => 'ENDED',
                'dateAndTimeSettings' => ['startDate' => '2024-10-12T15:00:00Z', 'timeZoneId' => 'America/New_York'],
            ]],
        ]));

        $this->wixExportDirs[] = $dir;

        return $dir;
    }

    /** @var list<string> */
    protected array $wixExportDirs = [];

    protected function removeWixExports(): void
    {
        foreach ($this->wixExportDirs as $dir) {
            File::deleteDirectory($dir);
        }
    }

    /** @return list<array> */
    protected function wixStoreRows(): array
    {
        return [
            // 02:05 UTC on the 29th is still the evening of the 28th in Charlotte.
            $this->storeRow(10001, '2021-04-29T02:05', 'Amina@Example.TEST', 'Amina', 'Test',
                '3x Zakat-ul-Fitr (Per Person) @13; 1x Individual Iftar @10', '49', '0', ''),
            $this->storeRow(10002, '2021-11-07T16:30', 'yusuf.new@example.test', 'Yusuf', 'Sample',
                '2x Fall Festival Ticket @10; 1x $30 Food Tickets For Only $25 @25', '45', '0', ''),
            $this->storeRow(10003, '2026-05-16T19:46', 'yusuf.new@example.test', 'Yusuf', 'Sample',
                '3x HAJJ SIMULATION & EID ADHA BAZAAR @20', '45', '15', 'Intellicore :15'),
            $this->storeRow(10004, '2021-03-12T15:00', 'released@example.test', 'Rania', 'Sample',
                '1x NEW Prayer Rug @60', '60', '0', ''),
        ];
    }

    protected function storeRow(int $number, string $at, string $email, string $first, string $last, string $lines, string $total, string $discount, string $codes): array
    {
        return [
            $number, $at, 'F', 1, 'wix-contact-' . $number, $email, $first, $last, '555-0100',
            '', '1 Invented Way, Testville', 'R', $lines, $total, $discount, $codes, 'please call first',
            'PAID', '0',
        ];
    }

    /** @return list<array> */
    protected function wixEventOrders(): array
    {
        return [
            [
                'no' => 'EVT-1', 'ev' => 'ev-fall-2024', 'cid' => 'c1', 'cr' => '2024-10-01T12:00:00.000Z',
                'fn' => 'Maryam', 'ln' => 'Example', 'em' => 'maryam@example.test',
                'st' => 'PAID', 'conf' => true, 'meth' => 'payPal', 'ch' => 'ONLINE', 'qty' => 2,
                'tot' => '51.25', 'fci' => false,
                'form' => ['custom-7b305c4ac0606006' => self::WIX_SECRET_ANSWER],
                'inv' => [[['2 Unlimited Games Bracelet ', 2, '25.00']], '50.00', '51.25',
                    [['WIX_FEE', 'FEE_ADDED_AT_CHECKOUT', '1.25']], null],
                'tk' => [],
            ],
            [
                'no' => 'EVT-2', 'ev' => 'ev-fall-2024', 'cid' => 'c2', 'cr' => '2024-10-02T12:00:00.000Z',
                'fn' => 'Maryam', 'ln' => 'Example', 'em' => 'maryam@example.test',
                'st' => 'CANCELED', 'conf' => false, 'meth' => '', 'ch' => 'ONLINE', 'qty' => 1,
                'tot' => '15.38', 'fci' => false, 'form' => [],
                'inv' => [[['FALL FESTIVAL  Games Bracelet', 1, '15.00']], '15.00', '15.38',
                    [['WIX_FEE', 'FEE_ADDED_AT_CHECKOUT', '0.38']], null],
                'tk' => [],
            ],
        ];
    }

    /**
     * Runs the command with `--without-contact-import` unless the caller sets
     * it: the fixture's new buyers would otherwise block every write, since no
     * Wix contact import runs in these tests. Pass it as false to see the block.
     */
    protected function importWix(Masjid $org, string $dir, array $options = []): int
    {
        return $this->artisan('crm:import-wix-orders', array_merge([
            'export' => $dir,
            '--masjid' => $org->id,
            '--without-contact-import' => true,
        ], $options))->run();
    }

    /**
     * Marks the Wix contact import as having run for the organisation, the way
     * it does itself: an `import_links` row (source wix, kind contact). That
     * table arrives with feat/mec-contacts-import; until it is merged the test
     * creates a stand-in with the columns the order import reads.
     */
    protected function markWixContactImportRan(Masjid $org, int $contactId): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('import_links')) {
            \Illuminate\Support\Facades\Schema::create('import_links', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->foreignId('masjid_id');
                $table->string('source', 32);
                $table->string('kind', 32);
                $table->string('external_id', 191);
                $table->unsignedBigInteger('local_id');
                $table->boolean('created_local')->default(false);
                $table->char('fingerprint', 64)->nullable();
                $table->string('import_batch', 64);
                $table->timestamps();
            });
        }

        \Illuminate\Support\Facades\DB::table('import_links')->insert([
            'masjid_id' => $org->id, 'source' => 'wix', 'kind' => 'contact', 'external_id' => 'wix-contact-x',
            'local_id' => $contactId, 'created_local' => false, 'import_batch' => 'wix-contacts-test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
