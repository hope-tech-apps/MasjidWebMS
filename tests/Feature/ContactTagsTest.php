<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\ContactTag;
use App\Models\Masjid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Contact tags: an organisation's admins create tags, tag and untag contacts one
 * at a time or in bulk, and filter the directory by a tag.
 *
 * The bulk requests are sent FORM-ENCODED (`contact_ids[]=…`), the way the SPA
 * sends them (.claude/rules/shipping.md: a green suite that only ever posts JSON
 * says nothing about the transport). Cross-tenant refusals live in
 * ContactTagTenantIsolationTest.
 */
class ContactTagsTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $masjid;
    private User $admin;

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

        $this->masjid = $this->makeMasjid();
        $this->admin = $this->makeAdminFor($this->masjid);
    }

    private function makeMasjid(bool $crm = true): Masjid
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
            'crm_enabled' => $crm,
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

    private function url(string $suffix = ''): string
    {
        return "/api/admin/masjids/{$this->masjid->id}/contact-tags{$suffix}";
    }

    private function contact(array $attributes = []): Contact
    {
        return Contact::factory()->create(['masjid_id' => $this->masjid->id] + $attributes);
    }

    private function tag(string $name): ContactTag
    {
        return ContactTag::withoutMasjidScope()->create(['masjid_id' => $this->masjid->id, 'name' => $name]);
    }

    /** Form-encoded, as the SPA's ApiService posts. */
    private function postForm(string $url, array $data)
    {
        return $this->post($url, $data, ['Accept' => 'application/json']);
    }

    private function tagIdsOf(Contact $contact): array
    {
        return $contact->tags()->pluck('contact_tags.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    #[Test]
    public function the_tag_tables_hold_what_the_feature_writes(): void
    {
        $this->assertSame('varchar', Schema::getColumnType('contact_tags', 'name'));
        $this->assertSame('varchar', Schema::getColumnType('contact_tags', 'name_key'));
        $this->assertSame('varchar', Schema::getColumnType('contact_tag_links', 'import_batch'));
        $this->assertSame('integer', Schema::getColumnType('broadcasts', 'audience_tag_id'));
    }

    #[Test]
    public function an_admin_creates_a_tag_in_their_own_organisation(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postForm($this->url(), ['name' => '  Volunteer   team '])->assertStatus(201);

        $this->assertSame('Volunteer team', $response->json('data.name'));
        $this->assertArrayNotHasKey('name_key', $response->json('data'));
        $this->assertDatabaseHas('contact_tags', [
            'masjid_id' => $this->masjid->id,
            'name' => 'Volunteer team',
            'name_key' => 'volunteer team',
        ]);
    }

    #[Test]
    public function a_second_tag_with_the_same_name_in_another_case_is_refused(): void
    {
        $this->tag('Volunteer');
        Sanctum::actingAs($this->admin);

        $this->postForm($this->url(), ['name' => ' VOLUNTEER'])
            ->assertStatus(422)
            ->assertJsonPath('data.name_key.0', 'A tag with this name already exists.');

        $this->assertSame(1, ContactTag::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_tag_can_be_renamed_to_its_own_name_in_a_new_case(): void
    {
        $tag = $this->tag('volunteer');
        Sanctum::actingAs($this->admin);

        $this->put($this->url("/{$tag->id}"), ['name' => 'Volunteer'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Volunteer');
    }

    #[Test]
    public function an_admin_tags_several_contacts_at_once_and_repeating_it_adds_nothing(): void
    {
        $tag = $this->tag('Volunteer');
        [$a, $b, $c] = [$this->contact(), $this->contact(), $this->contact()];
        Sanctum::actingAs($this->admin);

        $this->postForm($this->url("/{$tag->id}/contacts"), ['contact_ids' => [$a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('data.added', 2)
            ->assertJsonPath('data.tag.contacts_count', 2);

        $this->postForm($this->url("/{$tag->id}/contacts"), ['contact_ids' => [$a->id, $b->id]])
            ->assertOk()
            ->assertJsonPath('data.added', 0);

        $this->assertSame([$tag->id], $this->tagIdsOf($a));
        $this->assertSame([], $this->tagIdsOf($c));
        $this->assertSame(2, \DB::table('contact_tag_links')->count());
    }

    #[Test]
    public function an_admin_untags_contacts_in_bulk_and_the_contacts_stay(): void
    {
        $tag = $this->tag('Volunteer');
        [$a, $b] = [$this->contact(), $this->contact()];
        $tag->contacts()->attach([$a->id, $b->id]);
        Sanctum::actingAs($this->admin);

        $this->postForm($this->url("/{$tag->id}/contacts/remove"), ['contact_ids' => [$a->id]])
            ->assertOk()
            ->assertJsonPath('data.removed', 1)
            ->assertJsonPath('data.tag.contacts_count', 1);

        $this->assertSame([], $this->tagIdsOf($a));
        $this->assertSame([$tag->id], $this->tagIdsOf($b));
        $this->assertNotNull(Contact::withoutMasjidScope()->find($a->id));
    }

    #[Test]
    public function the_directory_filters_by_tag_and_lists_each_contacts_tags(): void
    {
        $volunteer = $this->tag('Volunteer');
        $donor = $this->tag('Donor');
        $both = $this->contact(['last_name' => 'Aaa']);
        $one = $this->contact(['last_name' => 'Bbb']);
        $none = $this->contact(['last_name' => 'Ccc']);
        $volunteer->contacts()->attach([$both->id, $one->id]);
        $donor->contacts()->attach([$both->id]);
        Sanctum::actingAs($this->admin);

        $filtered = $this->getJson("/api/admin/masjids/{$this->masjid->id}/contacts?tag_id={$donor->id}")->assertOk();
        $this->assertSame([$both->id], array_column($filtered->json('data.data'), 'id'));

        $all = $this->getJson("/api/admin/masjids/{$this->masjid->id}/contacts")->assertOk();
        $rows = collect($all->json('data.data'))->keyBy('id');
        $this->assertSame(['Donor', 'Volunteer'], array_column($rows[$both->id]['tags'], 'name'));
        $this->assertSame([], $rows[$none->id]['tags']);
        $this->assertArrayNotHasKey('pivot', $rows[$both->id]['tags'][0]);

        $this->getJson("/api/admin/masjids/{$this->masjid->id}/contacts/{$both->id}")
            ->assertOk()
            ->assertJsonPath('data.tags.1.name', 'Volunteer');
    }

    #[Test]
    public function the_tag_list_counts_only_contacts_that_are_not_deleted(): void
    {
        $tag = $this->tag('Volunteer');
        [$a, $b] = [$this->contact(), $this->contact()];
        $tag->contacts()->attach([$a->id, $b->id]);
        $b->delete();
        Sanctum::actingAs($this->admin);

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.0.contacts_count', 1);
    }

    #[Test]
    public function deleting_a_tag_removes_the_label_and_keeps_the_contacts(): void
    {
        $tag = $this->tag('Volunteer');
        $a = $this->contact();
        $tag->contacts()->attach([$a->id]);
        Sanctum::actingAs($this->admin);

        $this->deleteJson($this->url("/{$tag->id}"))->assertOk();

        $this->assertSame(0, ContactTag::withoutMasjidScope()->count());
        $this->assertSame(0, \DB::table('contact_tag_links')->count());
        $this->assertNotNull(Contact::withoutMasjidScope()->find($a->id));
    }

    #[Test]
    public function a_tag_a_scheduled_broadcast_is_addressed_to_cannot_be_deleted(): void
    {
        $tag = $this->tag('Volunteer');
        Broadcast::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'title' => 'Iftar rota',
            'body' => 'See you Friday.',
            'audience' => 'tag',
            'audience_tag_id' => $tag->id,
            'scheduled_at' => now()->addDay(),
            'status' => Broadcast::STATUS_SCHEDULED,
        ]);
        Sanctum::actingAs($this->admin);

        $this->deleteJson($this->url("/{$tag->id}"))->assertStatus(422);

        $this->assertNotNull(ContactTag::withoutMasjidScope()->find($tag->id));
    }

    #[Test]
    public function tagging_needs_manage_contacts_and_reading_tags_needs_only_view_contacts(): void
    {
        $tag = $this->tag('Volunteer');
        $a = $this->contact();
        Role::findByName('masjid-admin', 'web')->revokePermissionTo('manage contacts');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($this->admin->fresh());

        $this->getJson($this->url())->assertOk();
        $this->postForm($this->url(), ['name' => 'Donor'])->assertStatus(403);
        $this->postForm($this->url("/{$tag->id}/contacts"), ['contact_ids' => [$a->id]])->assertStatus(403);
        $this->deleteJson($this->url("/{$tag->id}"))->assertStatus(403);

        $this->assertSame([], $this->tagIdsOf($a));
    }

    #[Test]
    public function tags_are_part_of_the_crm_and_closed_while_it_is_off(): void
    {
        $this->masjid->forceFill(['crm_enabled' => false])->save();
        Sanctum::actingAs($this->admin);

        $this->getJson($this->url())->assertStatus(403);
        $this->postForm($this->url(), ['name' => 'Volunteer'])->assertStatus(403);
    }

    #[Test]
    public function merging_a_contact_carries_its_tags_to_the_survivor(): void
    {
        $volunteer = $this->tag('Volunteer');
        $donor = $this->tag('Donor');
        $source = $this->contact();
        $target = $this->contact();
        $volunteer->contacts()->attach([$source->id]);
        $donor->contacts()->attach([$source->id, $target->id]);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/masjids/{$this->masjid->id}/contacts/{$source->id}/merge", [
            'target_contact_id' => $target->id,
        ])->assertOk();

        $this->assertSame(collect([$volunteer->id, $donor->id])->sort()->values()->all(), $this->tagIdsOf($target));
    }
}
