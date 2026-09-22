<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The public service lists (GET /api/v1/home, GET /api/v1/services) are newest
 * first, and services that share a created_at come back in id order.
 *
 * They sorted by created_at ONLY. Imported services share timestamps (MEC's
 * 26–33 were written in one second, 34–36 in another), so MySQL chose the order
 * inside each tie, and chose differently by LIMIT: measured on production on
 * 2026-09-22, MEC's home six came back ascending by id, its /services page of 3
 * descending, and at one per page service 36 was on page 1 AND page 2. Adding
 * three older services (the partner pages) swapped two services off MEC's home
 * page inside a dry run.
 *
 * Ascending id is what every list of four or more already returned on
 * production, for every organisation, so the tie-break keeps today's lists.
 *
 * What pins what: SQLite tends to return ties in rowid order anyway, so the
 * behaviour tests below would pass on SQLite even without the fix. The last test
 * is the one that fails without it: it asserts the ORDER BY both endpoints send.
 */
class ServiceListOrderTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;

    /** @var list<int> ids in the order the lists must show them */
    private array $expected = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->masjid = Masjid::create([
            'name' => 'Service Order ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        // Like MEC: an older batch of five in one second, a newer batch of three in the next.
        $older = $this->batch(5, '2026-07-23 21:37:25');
        $newer = $this->batch(3, '2026-07-23 21:37:26');
        $this->expected = array_merge($newer, $older);
    }

    /** @return list<int> */
    private function batch(int $n, string $createdAt): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $service = new Service([
                'masjid_id' => $this->masjid->id,
                'title' => "Service {$createdAt} #{$i}",
                'summary' => 'Summary',
                'description' => 'Description',
                'text' => 'Text',
            ]);
            $service->created_at = $createdAt;
            $service->save();
            $ids[] = $service->id;
        }

        return $ids;
    }

    private function headers(): array
    {
        return ['masjid-id' => (string) $this->masjid->id];
    }

    /** @return list<int> */
    private function homeIds(): array
    {
        return collect($this->getJson('/api/v1/home', $this->headers())->assertOk()->json('data.services'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    #[Test]
    public function the_home_six_are_newest_first_with_ties_in_id_order(): void
    {
        $this->assertSame(array_slice($this->expected, 0, 6), $this->homeIds());
    }

    #[Test]
    public function adding_older_services_does_not_change_the_home_six(): void
    {
        $before = $this->homeIds();

        // What the partner-pages script does: new rows dated before every existing one.
        $this->batch(3, '2026-07-23 21:37:22');

        $this->assertSame($before, $this->homeIds());
    }

    #[Test]
    public function every_page_size_lists_each_service_exactly_once_in_order(): void
    {
        $total = count($this->expected);

        foreach ([1, 2, 3, 4, 10] as $perPage) {
            $seen = [];
            for ($page = 1; ($page - 1) * $perPage < $total; $page++) {
                $items = $this->getJson("/api/v1/services?page={$page}&per_page={$perPage}", $this->headers())
                    ->assertOk()->json('data.items');
                foreach ($items as $item) {
                    $seen[] = (int) $item['id'];
                }
            }
            $this->assertSame($this->expected, $seen, "per_page={$perPage}: no service repeated or skipped");
        }
    }

    #[Test]
    public function both_lists_ask_the_database_for_the_id_tie_break(): void
    {
        $orderBys = [];
        DB::listen(function ($query) use (&$orderBys) {
            if (preg_match('/from\s+["`]?services["`]?\s.*order by (.+?)( limit| offset|$)/is', $query->sql, $m)) {
                $orderBys[] = preg_replace('/["`]/', '', strtolower(trim($m[1])));
            }
        });

        $this->getJson('/api/v1/home', $this->headers())->assertOk();
        $this->getJson('/api/v1/services?per_page=3', $this->headers())->assertOk();

        $this->assertCount(2, $orderBys, 'one ordered services query per endpoint');
        foreach ($orderBys as $orderBy) {
            $this->assertSame('created_at desc, id asc', $orderBy);
        }
    }
}
