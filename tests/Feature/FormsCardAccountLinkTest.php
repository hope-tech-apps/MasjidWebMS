<?php

namespace Tests\Feature;

use App\Models\Masjid;
use App\Models\MasjidFormsCardLinkLog;
use App\Models\MasjidUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PATCH /api/admin/masjids/{id}/forms-card-account (DECISIONS.md 2026-09-15, D11).
 *
 * Only a SuperAdmin sets or removes the link, and a non-super caller is refused
 * BEFORE validation can describe the link or the holder. Every refusal is a 422
 * that names its reason, and a refused request writes nothing.
 */
class FormsCardAccountLinkTest extends TestCase
{
    use RefreshDatabase;

    private const HOLDER_ACCOUNT = 'acct_1TestHOLD';

    private Masjid $holder;

    private Masjid $child;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->holder = $this->makeOrg('Burlington Masjid Link', 'masjid', [
            'stripe_account_id' => self::HOLDER_ACCOUNT,
            'stripe_charges_enabled' => true,
        ]);
        $this->child = $this->makeOrg('BISS Link', 'school', ['parent_id' => $this->holder->id]);
    }

    // ------------------------------------------------------------------ the link

    #[Test]
    public function a_super_admin_links_a_child_to_its_onboarded_parent(): void
    {
        $super = $this->superAdmin();
        Sanctum::actingAs($super);

        $response = $this->link($this->child, $this->validBody())->assertOk();

        $response->assertJsonPath('status', 'success')
            ->assertJsonPath('data.forms_card_via.holder.id', $this->holder->id)
            ->assertJsonPath('data.forms_card_via.holder.name', $this->holder->name)
            ->assertJsonPath('data.forms_card_via.ready', true)
            ->assertJsonPath('data.forms_card_via.problem', null);
        $this->assertStringNotContainsString('acct_', $response->getContent());

        $child = $this->child->fresh();
        $this->assertSame($this->holder->id, (int) $child->forms_card_via_masjid_id);
        $this->assertSame($super->id, (int) $child->forms_card_via_set_by);
        $this->assertNotNull($child->forms_card_via_set_at);
        // No account id is copied: the unique index keeps holding and donations
        // stay refused for the child.
        $this->assertNull($child->stripe_account_id);
        $this->assertFalse($child->canAcceptDonations());
    }

    #[Test]
    public function a_super_admin_removes_the_link_with_a_null_holder(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $this->link($this->child, $this->validBody())->assertOk();

        $this->link($this->child, ['via_masjid_id' => null])
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.forms_card_via', null);

        $this->assertNull($this->child->fresh()->forms_card_via_masjid_id);
    }

    #[Test]
    public function removing_a_link_that_does_not_exist_changes_and_records_nothing(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->link($this->child, ['via_masjid_id' => null])
            ->assertOk()
            ->assertJsonPath('data.forms_card_via', null);

        $this->assertSame(0, MasjidFormsCardLinkLog::count());
    }

    #[Test]
    public function the_link_columns_are_neither_mass_assignable_nor_public(): void
    {
        foreach (['forms_card_via_masjid_id', 'forms_card_via_set_at', 'forms_card_via_set_by'] as $column) {
            $this->assertNotContains($column, (new Masjid)->getFillable());
            $this->assertContains($column, Masjid::PUBLIC_DIRECTORY_DENYLIST);
        }

        $sneaky = Masjid::create($this->orgAttributes('Sneaky Org') + ['forms_card_via_masjid_id' => $this->holder->id]);
        $this->assertNull($sneaky->fresh()->forms_card_via_masjid_id);
    }

    // ---------------------------------------------------- 403 before validation

    #[Test]
    public function a_non_super_admin_gets_a_403_with_no_validation_keys_for_every_payload(): void
    {
        $childAdmin = $this->admin($this->child);
        Sanctum::actingAs($childAdmin);

        $payloads = [
            'empty' => [],
            'unlink' => ['via_masjid_id' => null],
            'valid link' => $this->validBody(),
            'wrong name' => $this->validBody(['typed_holder_name' => 'nope']),
            'unknown holder' => $this->validBody(['via_masjid_id' => 999999]),
            'not an integer' => ['via_masjid_id' => 'abc'],
            'missing consent' => ['via_masjid_id' => $this->holder->id, 'typed_holder_name' => $this->holder->name],
            'self' => $this->validBody(['via_masjid_id' => $this->child->id]),
        ];

        foreach ($payloads as $label => $payload) {
            $response = $this->link($this->child, $payload);

            $response->assertStatus(403);
            $response->assertJsonMissingPath('data');
            $response->assertJsonMissingPath('code');

            foreach (['via_masjid_id', 'typed_holder_name', 'consent_reference', $this->holder->name] as $leak) {
                $this->assertStringNotContainsString($leak, $response->getContent(), "payload '{$label}' leaked '{$leak}'");
            }
        }

        // Form-encoded too: the SPA's default body shape.
        $this->patch("/api/admin/masjids/{$this->child->id}/forms-card-account", [], ['Accept' => 'application/json'])
            ->assertStatus(403)
            ->assertJsonMissingPath('data');

        $this->assertNull($this->child->fresh()->forms_card_via_masjid_id);
        $this->assertSame(0, MasjidFormsCardLinkLog::count());
    }

    #[Test]
    public function the_holders_own_admin_cannot_set_a_link_either(): void
    {
        // The holder's admin names the CHILD in the route: the tenant middleware
        // refuses before the request is even authorised.
        Sanctum::actingAs($this->admin($this->holder));

        $this->link($this->child, $this->validBody())->assertStatus(403)->assertJsonMissingPath('data');
        $this->assertNull($this->child->fresh()->forms_card_via_masjid_id);
    }

    // --------------------------------------------------------- validation 422s

    #[Test]
    public function the_body_is_validated_for_a_super_admin(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->link($this->child, [])->assertStatus(422)->assertJsonStructure(['data' => ['via_masjid_id']]);
        $this->link($this->child, ['via_masjid_id' => 'abc'])->assertStatus(422)->assertJsonStructure(['data' => ['via_masjid_id']]);
        $this->link($this->child, $this->validBody(['typed_holder_name' => null]))
            ->assertStatus(422)->assertJsonStructure(['data' => ['typed_holder_name']]);
        $this->link($this->child, $this->validBody(['consent_reference' => null]))
            ->assertStatus(422)->assertJsonStructure(['data' => ['consent_reference']]);
        $this->link($this->child, $this->validBody(['consent_reference' => str_repeat('x', 1001)]))
            ->assertStatus(422)->assertJsonStructure(['data' => ['consent_reference']]);

        $this->assertNull($this->child->fresh()->forms_card_via_masjid_id);
        $this->assertSame(0, MasjidFormsCardLinkLog::count());
    }

    // ------------------------------------------------------ each refusal by name

    #[Test]
    public function an_unknown_holder_is_refused_as_holder_missing(): void
    {
        $this->assertRefused($this->validBody(['via_masjid_id' => 999999]), 'holder_missing');
    }

    #[Test]
    public function an_archived_parent_is_refused_as_holder_missing(): void
    {
        $this->holder->delete();

        $this->assertRefused($this->validBody(), 'holder_missing');
    }

    #[Test]
    public function linking_an_organisation_to_itself_is_refused_as_same_organisation(): void
    {
        $this->assertRefused($this->validBody([
            'via_masjid_id' => $this->child->id,
            'typed_holder_name' => $this->child->name,
        ]), 'same_organisation');
    }

    #[Test]
    public function an_onboarded_organisation_that_is_not_the_parent_is_refused_as_not_parent(): void
    {
        $stranger = $this->makeOrg('Stranger Masjid', 'masjid', [
            'stripe_account_id' => 'acct_1TestSTRG',
            'stripe_charges_enabled' => true,
        ]);

        $this->assertRefused($this->validBody([
            'via_masjid_id' => $stranger->id,
            'typed_holder_name' => $stranger->name,
        ]), 'not_parent');
    }

    #[Test]
    public function a_parent_that_is_itself_linked_is_refused_as_holder_linked(): void
    {
        $grandparent = $this->makeOrg('Grandparent Org', 'masjid');
        $this->holder->forceFill(['forms_card_via_masjid_id' => $grandparent->id])->save();

        $this->assertRefused($this->validBody(), 'holder_linked');
    }

    #[Test]
    public function a_parent_with_no_stripe_account_is_refused_as_holder_not_onboarded(): void
    {
        $this->holder->forceFill(['stripe_account_id' => null, 'stripe_charges_enabled' => false])->save();

        $this->assertRefused($this->validBody(), 'holder_not_onboarded');
    }

    #[Test]
    public function a_parent_whose_charges_are_off_is_refused_as_holder_charges_disabled(): void
    {
        $this->holder->forceFill(['stripe_charges_enabled' => false])->save();

        $this->assertRefused($this->validBody(), 'holder_charges_disabled');
    }

    #[Test]
    public function a_child_with_its_own_account_is_refused_as_has_own_account(): void
    {
        $this->child->forceFill(['stripe_account_id' => 'acct_1TestOWNS', 'stripe_charges_enabled' => true])->save();

        $this->assertRefused($this->validBody(), 'has_own_account');
    }

    #[Test]
    public function a_child_that_is_itself_a_holder_is_refused_as_is_holder(): void
    {
        $grandchild = $this->makeOrg('Grandchild Org', 'school', ['parent_id' => $this->child->id]);
        $grandchild->forceFill(['forms_card_via_masjid_id' => $this->child->id])->save();

        $this->assertRefused($this->validBody(), 'is_holder');
    }

    #[Test]
    public function a_holder_name_that_is_not_exact_is_refused_as_holder_name_mismatch(): void
    {
        $this->assertRefused(
            $this->validBody(['typed_holder_name' => strtolower($this->holder->name)]),
            'holder_name_mismatch',
            'typed_holder_name',
        );
    }

    #[Test]
    public function a_holder_name_stored_with_surrounding_spaces_still_links(): void
    {
        // TrimStrings trims the typed name before the controller sees it, so an
        // exact compare against an untrimmed stored name could never succeed.
        $this->holder->forceFill(['name' => '  ' . $this->holder->name . ' '])->save();
        $this->holder = $this->holder->fresh();
        $this->assertNotSame(trim($this->holder->name), $this->holder->name);

        Sanctum::actingAs($this->superAdmin());

        // What the dialog sends (typed to match the stored name exactly), and the
        // trimmed form: both are the same request once TrimStrings has run.
        $this->link($this->child, $this->validBody())->assertOk()
            ->assertJsonPath('data.forms_card_via.ready', true);
        $this->assertSame($this->holder->id, (int) $this->child->fresh()->forms_card_via_masjid_id);

        $this->link($this->child, ['via_masjid_id' => null])->assertOk();
        $this->link($this->child, $this->validBody(['typed_holder_name' => trim($this->holder->name)]))->assertOk();
        $this->assertSame($this->holder->id, (int) $this->child->fresh()->forms_card_via_masjid_id);
    }

    #[Test]
    public function trimming_does_not_loosen_the_name_match(): void
    {
        $this->assertRefused(
            $this->validBody(['typed_holder_name' => str_replace(' ', '  ', $this->holder->name)]),
            'holder_name_mismatch',
            'typed_holder_name',
        );
    }

    #[Test]
    public function a_super_admin_can_remove_the_link_of_an_archived_child(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $this->link($this->child, $this->validBody())->assertOk();

        $this->child->delete();

        $this->link($this->child, ['via_masjid_id' => null, 'consent_reference' => 'Archived; consent lapsed'])
            ->assertOk()
            ->assertJsonPath('data.forms_card_via', null);

        $this->assertNull(Masjid::withTrashed()->find($this->child->id)->forms_card_via_masjid_id);
        $row = MasjidFormsCardLinkLog::where('action', 'unlink')->sole();
        $this->assertSame($this->child->id, (int) $row->child_masjid_id);
        $this->assertSame('Archived; consent lapsed', $row->consent_reference);

        // Restoring it brings back no link: a fresh SuperAdmin link is needed.
        Masjid::withTrashed()->find($this->child->id)->restore();
        $this->assertNull($this->child->fresh()->forms_card_via_masjid_id);
    }

    // ------------------------------------------------------------------ helpers

    private function assertRefused(array $body, string $code, string $field = 'via_masjid_id'): void
    {
        Sanctum::actingAs($this->superAdmin());

        $response = $this->link($this->child, $body);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('code', $code)
            ->assertJsonStructure(['data' => [$field]]);
        $this->assertStringNotContainsString('acct_', $response->getContent());

        $this->assertNull($this->child->fresh()->forms_card_via_masjid_id, "refusal '{$code}' still wrote the link");
        $this->assertSame(0, MasjidFormsCardLinkLog::count(), "refusal '{$code}' still wrote an audit row");
    }

    private function link(Masjid $child, array $body): TestResponse
    {
        return $this->patchJson("/api/admin/masjids/{$child->id}/forms-card-account", $body);
    }

    private function validBody(array $overrides = []): array
    {
        return array_merge([
            'via_masjid_id' => $this->holder->id,
            'typed_holder_name' => $this->holder->name,
            'consent_reference' => 'Owner decision 2026-09-13: use the existing Masjid Stripe account.',
        ], $overrides);
    }

    private function orgAttributes(string $name, string $orgType = 'masjid'): array
    {
        return [
            'name' => $name . ' ' . uniqid(),
            'email' => 'link' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 Test St',
            'latitude' => 0.0, 'longitude' => 0.0, 'crm_enabled' => true, 'org_type' => $orgType,
        ];
    }

    private function makeOrg(string $name, string $orgType = 'masjid', array $forced = []): Masjid
    {
        $org = Masjid::create($this->orgAttributes($name, $orgType));

        if ($forced !== []) {
            $org->forceFill($forced)->save();
        }

        return $org->fresh();
    }

    private function admin(Masjid $masjid): User
    {
        $user = User::factory()->create([
            'type' => 'MasjidAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        MasjidUser::create([
            'masjid_id' => $masjid->id, 'user_id' => $user->id,
            'role' => 'masjid-admin', 'is_default' => true,
        ]);

        return $user->fresh();
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'type' => 'SuperAdmin',
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ])->fresh();
    }
}
