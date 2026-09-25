<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactTag;
use App\Models\Masjid;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cross-tenant guarantee for App\Models\ContactTag and the tag assignments
 * reached through it (.claude/rules/tenant-scoping.md; enforced by
 * TenantScopingCoverageTest).
 *
 * The WRITE verbs matter most: a tag endpoint that resolved the tag but not the
 * contacts (or the reverse) would let organisation A label organisation B's
 * people, or read how many of B's people carry a label.
 */
class ContactTagTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjidA;
    private Masjid $masjidB;
    private User $adminA;

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

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        app(TenantContext::class)->forgetTenant();

        $this->masjidA = $this->makeMasjid();
        $this->masjidB = $this->makeMasjid();
        $this->adminA = $this->makeAdminFor($this->masjidA);
        $this->makeAdminFor($this->masjidB);
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

    private function makeAdminFor(Masjid $masjid): User
    {
        $admin = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);
        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    private function tagIn(Masjid $masjid, string $name): ContactTag
    {
        return ContactTag::withoutMasjidScope()->create(['masjid_id' => $masjid->id, 'name' => $name]);
    }

    #[Test]
    public function the_global_scope_hides_another_organisations_tags_and_create_stamps_the_bound_one(): void
    {
        $theirs = $this->tagIn($this->masjidB, 'Theirs');

        app(TenantContext::class)->set($this->masjidA->id);

        $this->assertNull(ContactTag::find($theirs->id));
        $this->assertSame(0, ContactTag::query()->count());

        $ours = ContactTag::create(['masjid_id' => $this->masjidB->id, 'name' => 'Ours']);
        $this->assertSame($this->masjidA->id, (int) $ours->masjid_id);
    }

    #[Test]
    public function two_organisations_may_each_have_a_tag_with_the_same_name(): void
    {
        $this->tagIn($this->masjidB, 'Volunteer');
        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/contact-tags", ['name' => 'Volunteer'])->assertStatus(201);

        $this->assertSame(2, ContactTag::withoutMasjidScope()->where('name_key', 'volunteer')->count());
    }

    #[Test]
    public function another_organisations_tag_cannot_be_renamed_deleted_or_used(): void
    {
        $theirs = $this->tagIn($this->masjidB, 'Theirs');
        $ours = Contact::factory()->create(['masjid_id' => $this->masjidA->id]);
        Sanctum::actingAs($this->adminA);

        $base = "/api/admin/masjids/{$this->masjidA->id}/contact-tags/{$theirs->id}";

        $this->putJson($base, ['name' => 'Renamed'])->assertStatus(404);
        $this->deleteJson($base)->assertStatus(404);
        $this->postJson("{$base}/contacts", ['contact_ids' => [$ours->id]])->assertStatus(404);

        $this->assertSame('Theirs', ContactTag::withoutMasjidScope()->find($theirs->id)->name);
        $this->assertSame(0, DB::table('contact_tag_links')->count());
    }

    #[Test]
    public function another_organisations_contact_cannot_be_tagged_even_mixed_with_our_own(): void
    {
        $tag = $this->tagIn($this->masjidA, 'Volunteer');
        $ours = Contact::factory()->create(['masjid_id' => $this->masjidA->id]);
        $theirs = Contact::factory()->create(['masjid_id' => $this->masjidB->id]);
        Sanctum::actingAs($this->adminA);

        $this->postJson("/api/admin/masjids/{$this->masjidA->id}/contact-tags/{$tag->id}/contacts", [
            'contact_ids' => [$ours->id, $theirs->id],
        ])->assertStatus(404);

        $this->assertSame(0, DB::table('contact_tag_links')->count());
    }

    #[Test]
    public function the_tag_list_and_the_directory_filter_never_reach_another_organisation(): void
    {
        $theirs = $this->tagIn($this->masjidB, 'Theirs');
        $theirContact = Contact::factory()->create(['masjid_id' => $this->masjidB->id]);
        $theirs->contacts()->attach([$theirContact->id]);
        Sanctum::actingAs($this->adminA);

        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/contact-tags")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/admin/masjids/{$this->masjidA->id}/contacts?tag_id={$theirs->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.data');

        // Naming the other organisation in the URL is the resolver's 403.
        $this->getJson("/api/admin/masjids/{$this->masjidB->id}/contact-tags")->assertStatus(403);
    }
}
