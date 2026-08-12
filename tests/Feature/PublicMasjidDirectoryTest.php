<?php

namespace Tests\Feature;

use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The public organisation directory publishes an organisation's IDENTITY and
 * nothing else.
 *
 * ## What happened
 *
 * `GET /api/mobile/masjids` and `/api/mobile/masjids/{id}` returned the whole
 * `masjids` row to anonymous callers. Measured against production on
 * 2026-08-12 with a bare curl and no credentials: Burlington Masjid's
 * `google_maps_key` — a key billable to them — together with its
 * `stripe_account_id`, `tax_id` and `statement_signatory`, and every
 * organisation's internal actor ids.
 *
 * It survived several reviews because they checked WHICH ROWS came back and
 * never read what was in them.
 *
 * ## Why a denylist, and why this test is the thing that makes it safe
 *
 * The columns are legitimately edited by admin surfaces, so `$hidden` on the
 * model is the wrong tool — it would blank them everywhere. The removal
 * therefore happens at the public boundary, which leaves the usual denylist
 * weakness: a column added next year is published by default.
 *
 * `every_masjids_column_is_deliberately_classified` closes that. It enumerates
 * the REAL table and fails on any column that is neither published-on-purpose
 * nor denied-on-purpose. A new `xero_api_secret` column cannot reach the public
 * payload without someone first deciding, in this file, which list it belongs to.
 */
class PublicMasjidDirectoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Columns an anonymous visitor is MEANT to see — an organisation's public
     * identity, i.e. what a directory is for.
     *
     * `email` and `phone` are here deliberately: a masjid's contact details are
     * the point, and the shipped iOS build decodes `email: String`
     * NON-optionally, so removing it would break the masjid list in every
     * installed copy of the app.
     *
     * @var list<string>
     */
    private const PUBLISHED = [
        'id', 'name', 'email', 'phone', 'address',
        'country_id', 'city_id', 'latitude', 'longitude',
        'website_link', 'timezone', 'org_type', 'listed_at',
        'created_at', 'updated_at',
        // Branding/storefront metadata an app or site is meant to render.
        'copyright_text', 'app_store_link', 'google_play_link',
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

    #[Test]
    public function the_directory_never_publishes_a_credential_or_a_money_identifier(): void
    {
        $masjid = $this->makeListedMasjid();

        $response = $this->getJson('/api/mobile/masjids')->assertOk();
        $body = $response->getContent();

        // Assert on the VALUES, not just the key names: a payload that dropped
        // the key but leaked the secret elsewhere would pass a key-only check.
        $this->assertStringNotContainsString('AIzaSyTESTKEY', $body, 'the Google Maps key is published');
        $this->assertStringNotContainsString('acct_TESTSTRIPE', $body, 'the Stripe account id is published');
        $this->assertStringNotContainsString('12-3456789', $body, 'the tax id is published');
        $this->assertStringNotContainsString('Signatory Person', $body, 'the statement signatory is published');

        $row = collect($response->json('data'))->firstWhere('id', $masjid->id);
        $this->assertNotNull($row, 'the listed masjid is missing from the directory');

        foreach (Masjid::PUBLIC_DIRECTORY_DENYLIST as $column) {
            $this->assertArrayNotHasKey($column, $row, "`{$column}` reached an anonymous caller");
        }
    }

    #[Test]
    public function the_single_organisation_endpoint_applies_the_same_rule(): void
    {
        $masjid = $this->makeListedMasjid();

        $row = $this->getJson("/api/mobile/masjids/{$masjid->id}")->assertOk()->json('data');

        foreach (Masjid::PUBLIC_DIRECTORY_DENYLIST as $column) {
            $this->assertArrayNotHasKey($column, $row, "`{$column}` reached an anonymous caller on show()");
        }
    }

    /**
     * What the apps actually decode must still be there. The point of the fix
     * is to stop publishing credentials, not to break the directory.
     */
    #[Test]
    public function what_the_shipped_apps_decode_is_still_published(): void
    {
        $masjid = $this->makeListedMasjid();

        $row = collect($this->getJson('/api/mobile/masjids')->assertOk()->json('data'))
            ->firstWhere('id', $masjid->id);

        // `id` + `name` are non-optional in the Android decoder; `email` is
        // non-optional in the iOS one. Any of the three going missing is a
        // crash in a build already on people's phones.
        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('name', $row);
        $this->assertArrayHasKey('email', $row);
        $this->assertSame($masjid->name, $row['name']);
    }

    /**
     * THE GUARD. Every column is on exactly one list, on purpose.
     *
     * Without this, the denylist is a snapshot of what somebody thought was
     * sensitive in August 2026 and the next credential column publishes itself.
     */
    #[Test]
    public function every_masjids_column_is_deliberately_classified(): void
    {
        $columns = Schema::getColumnListing('masjids');

        $classified = array_merge(self::PUBLISHED, Masjid::PUBLIC_DIRECTORY_DENYLIST, [
            // Already hidden on the model for its own documented reason.
            'active_owner_user_id',
        ]);

        $unclassified = array_values(array_diff($columns, $classified));

        $this->assertSame(
            [],
            $unclassified,
            "These `masjids` columns are on neither list, so they are published to anonymous callers by default:\n  - "
            .implode("\n  - ", $unclassified)
            ."\n\nDecide for each one: add it to PublicMasjidDirectoryTest::PUBLISHED if an anonymous "
            ."visitor should see it, or to Masjid::PUBLIC_DIRECTORY_DENYLIST if not. Do not delete this test."
        );
    }

    private function makeListedMasjid(): Masjid
    {
        $masjid = Masjid::create([
            'name' => 'Directory Masjid '.uniqid(),
            'email' => 'dir-'.uniqid().'@test.local',
            'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        // Sentinel values, so the assertions above can look for the SECRET
        // rather than only for the key that carried it.
        $masjid->forceFill([
            'google_maps_key' => 'AIzaSyTESTKEY',
            'stripe_account_id' => 'acct_TESTSTRIPE',
            'tax_id' => '12-3456789',
            'statement_signatory' => 'Signatory Person',
        ])->save();

        if (Schema::hasColumn('masjids', 'listed_at')) {
            $masjid->forceFill(['listed_at' => now()])->save();
        }

        return $masjid->fresh();
    }
}
