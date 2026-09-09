<?php

namespace Tests\Feature;

use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One organisation belonging to another — the edge that lets a service which
 * outgrew a paragraph become its own project without leaving the family.
 *
 * Three things are pinned here because each one is silent when it breaks:
 * a cycle (which hangs every tree walk), a cascade (which would delete a whole
 * other organisation's data), and the switcher showing an organisation nobody
 * published yet.
 */
class OrgHierarchyTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * `listed_at` is deliberately NOT fillable on Masjid — publishing an
     * organisation to the public directory is its own act, not a side effect of
     * creating one — so the fixture has to force it. Passing it to `create()`
     * silently does nothing, which is how the first version of this test
     * "proved" an unpublished child was hidden when in fact NOTHING was
     * published.
     */
    private function org(string $name, array $overrides = []): Masjid
    {
        $listedAt = $overrides['listed_at'] ?? null;
        unset($overrides['listed_at']);

        $org = Masjid::create(array_merge([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)) . '-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ], $overrides));

        if ($listedAt !== null) {
            $org->forceFill(['listed_at' => $listedAt])->save();
        }

        return $org->fresh();
    }

    #[Test]
    public function a_child_knows_its_parent_and_a_parent_lists_its_children(): void
    {
        $mec = $this->org('MEC');
        $school = $this->org('IntelliCor');
        $academy = $this->org('Al-Bayan');

        $school->setParent($mec);
        $academy->setParent($mec);

        $this->assertSame($mec->id, $school->fresh()->parent->id);
        $this->assertTrue($school->fresh()->isChildOrg());
        $this->assertFalse($mec->fresh()->isChildOrg());

        $this->assertEqualsCanonicalizing(
            [$school->id, $academy->id],
            $mec->fresh()->children()->pluck('id')->all()
        );
    }

    #[Test]
    public function parent_id_is_not_mass_assignable(): void
    {
        // Re-parenting moves an organisation in every directory and switcher at
        // once. It must not ride along with an ordinary profile edit.
        $mec = $this->org('MEC');
        $school = $this->org('IntelliCor');

        $school->update(['parent_id' => $mec->id, 'address' => '2 New St']);

        $this->assertNull($school->fresh()->parent_id, 'parent_id must not be fillable');
        $this->assertSame('2 New St', $school->fresh()->address, 'the legitimate edit still applied');
    }

    #[Test]
    public function an_organisation_cannot_be_its_own_parent(): void
    {
        $mec = $this->org('MEC');

        $this->expectException(InvalidArgumentException::class);
        $mec->setParent($mec);
    }

    #[Test]
    public function a_cycle_is_refused(): void
    {
        // A -> B -> A. Every walk up the tree would loop forever, including the
        // one the app does to find its way home.
        $a = $this->org('A');
        $b = $this->org('B');

        $b->setParent($a);

        $this->expectException(InvalidArgumentException::class);
        $a->setParent($b->fresh());
    }

    #[Test]
    public function a_deeper_cycle_is_refused_too(): void
    {
        $a = $this->org('A');
        $b = $this->org('B');
        $c = $this->org('C');

        $b->setParent($a);
        $c->setParent($b->fresh());

        // A under C would close A -> C -> B -> A.
        $this->expectException(InvalidArgumentException::class);
        $a->setParent($c->fresh());
    }

    #[Test]
    public function deleting_a_parent_orphans_its_children_and_never_deletes_them(): void
    {
        // The single most important property of this column. A cascade would
        // mean removing MEC also destroys IntelliCor's roster, donations and
        // media — unrecoverable. Orphaning is recoverable by setting it again.
        $mec = $this->org('MEC');
        $school = $this->org('IntelliCor');
        $school->setParent($mec);

        $mec->forceDelete();

        $survivor = Masjid::find($school->id);
        $this->assertNotNull($survivor, 'the child organisation must survive');
        $this->assertNull($survivor->parent_id, 'and become top-level');
    }

    // ---------------------------------------------------------------- the API

    #[Test]
    public function the_switcher_returns_home_first_then_its_published_children(): void
    {
        $mec = $this->org('MEC', ['listed_at' => now()]);
        $published = $this->org('IntelliCor', ['listed_at' => now()]);
        $draft = $this->org('Half Built Academy');

        $published->setParent($mec);
        $draft->setParent($mec);

        $response = $this->getJson("/api/mobile/masjids/{$mec->id}/orgs")->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data, 'an unpublished child must not appear in a switcher');

        $this->assertSame($mec->id, $data[0]['id']);
        $this->assertTrue($data[0]['is_home']);

        $this->assertSame($published->id, $data[1]['id']);
        $this->assertFalse($data[1]['is_home']);

        $this->assertSame(
            [],
            array_values(array_filter($data, fn ($o) => $o['id'] === $draft->id)),
        );
    }

    #[Test]
    public function an_organisation_with_no_children_still_returns_itself(): void
    {
        // The client must always get a home to switch back to, even for the
        // overwhelming majority of tenants that have no sub-organisations.
        $solo = $this->org('Lone Masjid', ['listed_at' => now()]);

        $data = $this->getJson("/api/mobile/masjids/{$solo->id}/orgs")->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($solo->id, $data[0]['id']);
        $this->assertTrue($data[0]['is_home']);
    }
}
