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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Staff recording email consent given in Manara
 * (POST /contacts/{id}/email-consent, ContactEmailConsentController), which
 * lifts an import's `not_opted_in` precaution or order-history hold and
 * refuses every other reason.
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

    /**
     * Run the Wix contact import into our organisation from contacts given as
     * address => [Wix subscription status, Wix deliverability]. Returns the
     * command's output; the tenant is unbound again afterwards.
     *
     * @param  array<string, array{0: string, 1: string}>  $statuses
     */
    private function importFromWix(array $statuses, string $batch): string
    {
        $contacts = [];
        foreach ($statuses as $email => [$subscription, $deliverability]) {
            $contacts[] = [
                'id' => 'wix-' . md5($email),
                'revision' => 1,
                'createdDate' => '2019-03-02T10:00:00.000Z',
                'updatedDate' => '2024-05-01T10:00:00.000Z',
                'info' => [
                    'name' => ['first' => 'Test', 'last' => 'Person'],
                    'emails' => ['items' => [['tag' => 'UNTAGGED', 'email' => $email, 'primary' => true]]],
                ],
                'primaryEmail' => ['email' => $email, 'subscriptionStatus' => $subscription, 'deliverabilityStatus' => $deliverability],
                'primaryInfo' => ['email' => $email],
            ];
        }

        $path = tempnam(sys_get_temp_dir(), 'wixc') . '.json';
        file_put_contents($path, json_encode(['contacts' => $contacts]));

        try {
            $code = Artisan::call('wix:import-contacts', [
                'file' => $path, '--masjid' => $this->masjid->id, '--execute' => true, '--batch' => $batch,
            ]);
            $output = Artisan::output();
            $this->assertSame(0, $code, $output);

            return $output;
        } finally {
            @unlink($path);
            app(TenantContext::class)->forgetTenant();
        }
    }

    private function row(string $email): EmailSuppression
    {
        return EmailSuppression::withoutMasjidScope()->where('email_normalized', $email)->sole();
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
    public function staff_can_lift_an_order_history_hold_exactly_as_they_lift_a_not_opted_in_precaution(): void
    {
        // The Wix order-history import holds the address of a buyer it had to
        // create (WixOrderHistoryImporter::createHeldContact): no consent on
        // record, nobody's request. Recording consent lifts it with the same
        // record as a `not_opted_in`, and the row keeps saying what it was.
        $contact = $this->contactSuppressedAs('buyer@example.test', EmailSuppression::REASON_ORDER_HISTORY_HOLD);
        $this->assertSame([], $this->everyoneEmailed());

        Sanctum::actingAs($this->admin);
        $this->postJson($this->url($contact), ['evidence' => 'Ticked the email box on the festival sign-up'])
            ->assertOk()
            ->assertJsonPath('data.email_opted_out_at', null)
            ->assertJsonPath('data.email_opt_out_reason', null);

        $row = EmailSuppression::withoutMasjidScope()->where('email_normalized', 'buyer@example.test')->sole();
        $this->assertNotNull($row->released_at, 'released, not deleted: the row is the record');
        $this->assertSame(EmailSuppression::REASON_ORDER_HISTORY_HOLD, $row->reason);
        $this->assertSame(EmailSuppression::RELEASE_STAFF_RECORDED_CONSENT, $row->release_source);
        $this->assertSame('Ticked the email box on the festival sign-up', $row->release_evidence);
        $this->assertSame($this->admin->id, (int) $row->released_by_user_id);
        $this->assertSame(['buyer@example.test'], $this->everyoneEmailed());
    }

    #[Test]
    public function every_reason_but_the_two_import_precautions_is_refused_to_staff_including_any_added_later(): void
    {
        // Read from the class, so a reason added later is refused by default
        // until somebody decides otherwise here. The two liftable reasons are
        // named literally: widening what staff may lift must fail this test.
        $liftable = ['not_opted_in', 'order_history_import'];
        $reasons = collect((new \ReflectionClass(EmailSuppression::class))->getConstants())
            ->filter(fn ($value, $name) => str_starts_with($name, 'REASON_'))
            ->reject(fn ($value) => in_array($value, $liftable, true))
            ->values();

        $this->assertContains(EmailSuppression::REASON_UNSUBSCRIBE_LINK, $reasons, 'the premise: the opt-outs are in the sweep');
        $this->assertContains(EmailSuppression::REASON_COMPLAINT, $reasons);
        $this->assertContains(EmailSuppression::REASON_BOUNCE, $reasons);

        Sanctum::actingAs($this->admin);

        foreach ($reasons as $reason) {
            $contact = $this->contactSuppressedAs("{$reason}@example.test", $reason);

            $this->postJson($this->url($contact), ['evidence' => 'They asked at the desk'])->assertStatus(422);

            $this->assertNull(
                EmailSuppression::withoutMasjidScope()->where('email_normalized', "{$reason}@example.test")->value('released_at'),
                "{$reason} stays in force",
            );
        }

        $this->assertSame([], $this->everyoneEmailed());
    }

    #[Test]
    public function an_unsubscribe_on_a_held_address_replaces_the_hold_and_staff_can_no_longer_lift_it(): void
    {
        // A hold is not the person's opt-out, and staff may lift it. When the
        // person then really unsubscribes, the row must stop being liftable:
        // what staff may lift is read from the reason alone.
        Sanctum::actingAs($this->admin);

        foreach ([EmailSuppression::REASON_ORDER_HISTORY_HOLD, EmailSuppression::REASON_NOT_OPTED_IN] as $hold) {
            $this->travelTo('2026-09-01 12:00:00');
            $contact = $this->contactSuppressedAs("{$hold}@example.test", $hold);
            $this->travelTo('2026-09-20 12:00:00');

            $token = app(EmailSuppressionService::class)->token(
                $this->masjid->id, "{$hold}@example.test", null, EmailSuppressionService::PURPOSE_UNSUBSCRIBE,
            );
            $this->get(route('unsubscribe.show', ['masjid_id' => $this->masjid->id, 'token' => $token]))
                ->assertOk()
                ->assertSee('Stop announcement emails?')
                ->assertDontSee('You are already unsubscribed');
            $this->post(route('unsubscribe.store', ['masjid_id' => $this->masjid->id, 'token' => $token]))->assertOk();

            $row = $this->row("{$hold}@example.test");
            $this->assertSame(EmailSuppression::REASON_UNSUBSCRIBE_LINK, $row->reason, "{$hold}: the person's request replaces the hold");
            $this->assertSame('2026-09-20 12:00:00', $row->suppressed_at->toDateTimeString(), 'the date the person asked');
            $this->assertSame($hold, $row->held_reason, 'the hold it replaced is kept');
            $this->assertSame('2026-09-01 12:00:00', $row->held_since->toDateTimeString());

            $this->postJson($this->url($contact), ['evidence' => 'They asked at the desk'])->assertStatus(422);
            $this->assertNull($this->row("{$hold}@example.test")->released_at, "{$hold}: stays in force");
        }

        $this->assertSame([], $this->everyoneEmailed());
    }

    #[Test]
    public function a_wix_unsubscribe_complaint_or_bounce_imported_over_a_hold_cannot_be_lifted_by_staff(): void
    {
        // The order-history import ran first and held these buyers; the
        // contact import then reads Wix. Its opt-out must replace the hold, or
        // staff could lift somebody who unsubscribed on the old website.
        $contacts = [
            'unsubscribed@example.test' => $this->contactSuppressedAs('unsubscribed@example.test', EmailSuppression::REASON_ORDER_HISTORY_HOLD),
            'complained@example.test' => $this->contactSuppressedAs('complained@example.test', EmailSuppression::REASON_ORDER_HISTORY_HOLD),
            'bounced@example.test' => $this->contactSuppressedAs('bounced@example.test', EmailSuppression::REASON_ORDER_HISTORY_HOLD),
            'precaution@example.test' => $this->contactSuppressedAs('precaution@example.test', EmailSuppression::REASON_NOT_OPTED_IN),
        ];

        $this->importFromWix([
            'unsubscribed@example.test' => ['UNSUBSCRIBED', 'VALID'],
            'complained@example.test' => ['SUBSCRIBED', 'SPAM_COMPLAINT'],
            'bounced@example.test' => ['SUBSCRIBED', 'BOUNCED'],
            'precaution@example.test' => ['UNSUBSCRIBED', 'VALID'],
        ], 'b1');

        $this->assertSame(EmailSuppression::REASON_IMPORTED_OPT_OUT, $this->row('unsubscribed@example.test')->reason);
        $this->assertSame(EmailSuppression::REASON_COMPLAINT, $this->row('complained@example.test')->reason);
        $this->assertSame(EmailSuppression::REASON_BOUNCE, $this->row('bounced@example.test')->reason);
        $this->assertSame(EmailSuppression::REASON_IMPORTED_OPT_OUT, $this->row('precaution@example.test')->reason);
        $this->assertSame(EmailSuppression::REASON_NOT_OPTED_IN, $this->row('precaution@example.test')->held_reason);

        Sanctum::actingAs($this->admin);
        foreach ($contacts as $email => $contact) {
            $this->postJson($this->url($contact), ['evidence' => 'They asked at the desk'])->assertStatus(422);
            $this->assertNull($this->row($email)->released_at, "{$email} stays in force");
        }
        $this->assertSame([], $this->everyoneEmailed());
    }

    #[Test]
    public function an_order_hold_staff_lifted_before_the_contact_import_is_suppressed_again_when_wix_has_an_opt_out(): void
    {
        // Staff lifted these holds from a badge that knew nothing of Wix. The
        // outcome must be where the address would stand had the contact import
        // run first (DECISIONS.md 2026-09-27): a Wix complaint or unsubscribe
        // suppresses it again, and staff can no longer lift it; Wix with no
        // opt-out leaves the recorded consent alone. A contact-import
        // precaution staff lifted is not reopened: that import had read Wix.
        $complained = $this->contactSuppressedAs('complained@example.test', EmailSuppression::REASON_ORDER_HISTORY_HOLD);
        $unsubscribed = $this->contactSuppressedAs('unsubscribed@example.test', EmailSuppression::REASON_ORDER_HISTORY_HOLD);
        $fine = $this->contactSuppressedAs('fine@example.test', EmailSuppression::REASON_ORDER_HISTORY_HOLD);
        $precaution = $this->contactSuppressedAs('precaution@example.test', EmailSuppression::REASON_NOT_OPTED_IN);

        Sanctum::actingAs($this->admin);
        foreach ([$complained, $unsubscribed, $fine, $precaution] as $contact) {
            $this->postJson($this->url($contact), ['evidence' => 'Ticked the email box on the festival sign-up'])->assertOk();
        }

        $output = $this->importFromWix([
            'complained@example.test' => ['SUBSCRIBED', 'SPAM_COMPLAINT'],
            'unsubscribed@example.test' => ['UNSUBSCRIBED', 'VALID'],
            'fine@example.test' => ['NOT_SET', 'VALID'],
            'precaution@example.test' => ['UNSUBSCRIBED', 'VALID'],
        ], 'b1');

        $this->assertSame(EmailSuppression::REASON_COMPLAINT, $this->row('complained@example.test')->reason);
        $this->assertNull($this->row('complained@example.test')->released_at);
        $this->assertSame(EmailSuppression::REASON_ORDER_HISTORY_HOLD, $this->row('complained@example.test')->held_reason);
        $this->assertSame(EmailSuppression::REASON_IMPORTED_OPT_OUT, $this->row('unsubscribed@example.test')->reason);
        $this->assertNull($this->row('unsubscribed@example.test')->released_at);
        $this->assertNotNull($this->row('fine@example.test')->released_at, 'Wix had no opt-out: the recorded consent stands');
        $this->assertNotNull($this->row('precaution@example.test')->released_at, 'the contact import had read Wix before it held this one');
        $this->assertMatchesRegularExpression('/Order hold staff lifted in Manara, but opted out, complained or bounced on Wix \(suppressed again\)\s*\|\s*2/', $output);
        $this->assertStringContainsString('2 address(es) staff recorded consent for are suppressed again', $output);

        foreach ([$complained, $unsubscribed] as $contact) {
            $this->postJson($this->url($contact), ['evidence' => 'They asked at the desk'])->assertStatus(422);
        }
        $this->assertEqualsCanonicalizing(['fine@example.test', 'precaution@example.test'], $this->everyoneEmailed());
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
        $this->assertSame('varchar', Schema::getColumnType('email_suppressions', 'held_reason'));
        $this->assertSame('datetime', Schema::getColumnType('email_suppressions', 'held_since'));
    }
}
