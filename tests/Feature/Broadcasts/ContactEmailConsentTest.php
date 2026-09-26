<?php

namespace Tests\Feature\Broadcasts;

use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\EmailSuppression;
use App\Models\Masjid;
use App\Models\User;
use App\Services\Broadcast\BroadcastAudienceResolver;
use App\Services\Broadcast\EmailSuppressionService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Staff recording email consent given in Manara
 * (POST /contacts/{id}/email-consent, ContactEmailConsentController), which
 * lifts an import's `not_opted_in` precaution and refuses every other reason.
 *
 * Why it exists: the person an import silenced in advance never receives a
 * broadcast, so the subscriber's own re-subscribe link can never reach them.
 */
class ContactEmailConsentTest extends TestCase
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
        app(TenantContext::class)->forgetTenant();

        $this->masjid = $this->makeMasjid();
        $this->admin = $this->makeAdminFor($this->masjid);
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
        $admin = User::factory()->create(['type' => 'MasjidAdmin', 'phone' => '+1' . random_int(1000000000, 9999999999)]);
        $masjid->user_id = $admin->id;
        $masjid->save();

        return $admin;
    }

    private function contactSuppressedAs(string $email, string $reason): Contact
    {
        $contact = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => $email]);
        app(EmailSuppressionService::class)->suppress($this->masjid->id, $email, $reason);

        return $contact->fresh();
    }

    private function url(Contact $contact, ?Masjid $masjid = null): string
    {
        return '/api/admin/masjids/' . ($masjid ?? $this->masjid)->id . "/contacts/{$contact->id}/email-consent";
    }

    /** @return list<string> the addresses a broadcast to everyone would email right now */
    private function everyoneEmailed(): array
    {
        app(TenantContext::class)->set($this->masjid->id);
        $everyone = (new Broadcast())->forceFill(['masjid_id' => $this->masjid->id, 'audience' => 'everyone']);
        $emails = app(BroadcastAudienceResolver::class)->emailRecipients($everyone)->pluck('email')->all();
        app(TenantContext::class)->forgetTenant();

        return $emails;
    }

    #[Test]
    public function staff_can_lift_an_imported_not_opted_in_precaution_with_evidence_and_the_row_says_who_and_why(): void
    {
        $contact = $this->contactSuppressedAs('later@example.test', EmailSuppression::REASON_NOT_OPTED_IN);
        $this->assertSame([], $this->everyoneEmailed());

        Sanctum::actingAs($this->admin);
        $this->postJson($this->url($contact), ['evidence' => 'Signed the newsletter sheet at Jumuah'])
            ->assertOk()
            ->assertJsonPath('data.email_opted_out_at', null)
            ->assertJsonPath('data.email_opt_out_reason', null);

        $row = EmailSuppression::withoutMasjidScope()->where('email_normalized', 'later@example.test')->sole();
        $this->assertNotNull($row->released_at, 'released, not deleted: the row is the record');
        $this->assertSame(EmailSuppression::RELEASE_STAFF_RECORDED_CONSENT, $row->release_source);
        $this->assertSame('Signed the newsletter sheet at Jumuah', $row->release_evidence);
        $this->assertSame($this->admin->id, (int) $row->released_by_user_id);
        $this->assertSame(['later@example.test'], $this->everyoneEmailed());
    }

    #[Test]
    public function an_opt_out_a_complaint_or_a_bounce_cannot_be_lifted_by_staff(): void
    {
        Sanctum::actingAs($this->admin);

        foreach ([
            EmailSuppression::REASON_IMPORTED_OPT_OUT,
            EmailSuppression::REASON_COMPLAINT,
            EmailSuppression::REASON_UNSUBSCRIBE_LINK,
            EmailSuppression::REASON_BOUNCE,
        ] as $reason) {
            $contact = $this->contactSuppressedAs("{$reason}@example.test", $reason);

            $this->postJson($this->url($contact), ['evidence' => 'They asked at the desk'])
                ->assertStatus(422)
                ->assertJsonPath('message', 'This address opted out or could not be delivered to. Only the person can resume email, from the link in an email they receive; staff cannot.');

            $this->assertNull(
                EmailSuppression::withoutMasjidScope()->where('email_normalized', "{$reason}@example.test")->value('released_at'),
                "{$reason} stays in force",
            );
        }

        $this->assertSame([], $this->everyoneEmailed());
    }

    #[Test]
    public function recording_consent_needs_evidence_and_the_manage_contacts_permission(): void
    {
        $contact = $this->contactSuppressedAs('later@example.test', EmailSuppression::REASON_NOT_OPTED_IN);

        Sanctum::actingAs($this->admin);
        $this->postJson($this->url($contact), ['evidence' => ''])->assertStatus(422);

        $reader = $this->makeAdminFor($readerMasjid = $this->makeMasjid());
        $reader->syncRoles([]);
        $reader->givePermissionTo('view contacts');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $theirs = Contact::factory()->create(['masjid_id' => $readerMasjid->id, 'email' => 'theirs@example.test']);
        app(EmailSuppressionService::class)->suppress($readerMasjid->id, 'theirs@example.test', EmailSuppression::REASON_NOT_OPTED_IN);

        Sanctum::actingAs($reader);
        $this->postJson($this->url($theirs, $readerMasjid), ['evidence' => 'Signed'])->assertStatus(403);

        $this->assertSame(2, EmailSuppression::withoutMasjidScope()->whereNull('released_at')->count(), 'nothing was lifted');
    }

    #[Test]
    public function another_organisations_contact_is_a_404(): void
    {
        $other = $this->makeMasjid();
        $theirs = Contact::factory()->create(['masjid_id' => $other->id, 'email' => 'theirs@example.test']);
        app(EmailSuppressionService::class)->suppress($other->id, 'theirs@example.test', EmailSuppression::REASON_NOT_OPTED_IN);

        Sanctum::actingAs($this->admin);
        $this->postJson($this->url($theirs), ['evidence' => 'Signed'])->assertStatus(404);

        $this->assertNull(EmailSuppression::withoutMasjidScope()->where('masjid_id', $other->id)->value('released_at'));
    }

    #[Test]
    public function the_member_record_says_why_the_address_is_suppressed(): void
    {
        $precaution = $this->contactSuppressedAs('later@example.test', EmailSuppression::REASON_NOT_OPTED_IN);
        $optOut = $this->contactSuppressedAs('left@example.test', EmailSuppression::REASON_UNSUBSCRIBE_LINK);
        $mailable = Contact::factory()->create(['masjid_id' => $this->masjid->id, 'email' => 'fine@example.test']);

        Sanctum::actingAs($this->admin);
        $show = fn (Contact $c) => $this->getJson("/api/admin/masjids/{$this->masjid->id}/contacts/{$c->id}")->assertOk();

        $show($precaution)->assertJsonPath('data.email_opt_out_reason', EmailSuppression::REASON_NOT_OPTED_IN);
        $show($optOut)->assertJsonPath('data.email_opt_out_reason', EmailSuppression::REASON_UNSUBSCRIBE_LINK);
        $show($mailable)->assertJsonPath('data.email_opt_out_reason', null);
    }

    #[Test]
    public function the_release_record_columns_have_the_types_the_evidence_needs(): void
    {
        $this->assertSame('varchar', Schema::getColumnType('email_suppressions', 'release_source'));
        $this->assertSame('varchar', Schema::getColumnType('email_suppressions', 'release_evidence'));
        $this->assertSame('integer', Schema::getColumnType('email_suppressions', 'released_by_user_id'));
    }
}
