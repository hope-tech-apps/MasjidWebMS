<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\FormStaffCode;
use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cross-TENANT isolation for FormStaffCode, the festival build's one new
 * tenant-scoped model. .claude/rules/tenant-scoping.md requires this file of
 * every model using BelongsToMasjid, and TenantScopingCoverageTest fails the
 * build without it. MySQL has no row-level security: the bound tenant plus these
 * tests are the only backstop.
 *
 * Staff codes are reached two ways, so both are pinned:
 *
 *  - the ADMIN side runs bound to one masjid, so the global scope is the
 *    boundary: another masjid's codes are a miss for read, update and delete,
 *    and create stamps the bound masjid whatever the payload says;
 *  - the PUBLIC gate runs UNBOUND (no admin at the gate, only a phone and a
 *    code), so findUsable() hand-filters by the header's masjid AND the form.
 *    The same eight characters issued on two forms, or at two masjids, open
 *    exactly one door each.
 *
 * A code is also money: it decides whose cash total a registration lands on. So
 * a code from another masjid must never settle a row, and a forged pointer must
 * never put another masjid's holder on a bound admin's screen.
 */
class FormStaffCodeTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $a;
    private Masjid $b;
    private Form $formA;
    private Form $otherFormA;
    private Form $formB;

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

        $this->a = $this->makeMasjid();
        $this->b = $this->makeMasjid();

        $this->formA = $this->makeForm($this->a);
        $this->otherFormA = $this->makeForm($this->a);
        $this->formB = $this->makeForm($this->b);
    }

    // ---------------------------------------------------------------- helpers

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

    private function makeForm(Masjid $masjid): Form
    {
        return Form::create([
            'masjid_id' => $masjid->id,
            'slug' => 'festival-' . uniqid(),
            'name' => 'Fall Festival',
            'schema' => [
                'sections' => [
                    ['id' => 'attendees', 'title' => 'Attendees', 'repeatable' => true, 'minEntries' => 1, 'fields' => [
                        ['name' => 'attendeeName', 'label' => 'Name', 'type' => 'text', 'required' => true],
                    ]],
                ],
            ],
            'settings' => [
                'fee' => ['amount' => 15, 'currency' => 'USD', 'perEntryOfSection' => 'attendees'],
                'payment' => ['staffCodes' => true],
            ],
            'is_active' => true,
        ]);
    }

    private function makeResponse(Form $form): FormResponse
    {
        $response = new FormResponse([
            'form_id' => $form->id,
            'masjid_id' => $form->masjid_id,
            'data' => ['attendees' => [['attendeeName' => 'Amal']]],
            'entry_count' => 1,
            'amount_due' => 15,
            'status' => 'new',
            'submitted_at' => now(),
        ]);

        $response->forceFill(['amount_due_minor' => 1500, 'currency' => 'usd'])->save();

        return $response;
    }

    private function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }

    // ------------------------------------------------- the admin side (bound)

    #[Test]
    public function staff_codes_are_invisible_across_tenants(): void
    {
        // Issued UNBOUND, as a console caller would, so both rows genuinely exist.
        [$codeA] = FormStaffCode::issue($this->formA, 'Amal Yusuf', now()->addDay());
        [$codeB] = FormStaffCode::issue($this->formB, 'Bilal Khan', now()->addDay());

        $this->assertSame(2, FormStaffCode::withoutMasjidScope()->count());

        $this->tenant()->set($this->a->id);

        // READ: B's code is a miss, not a filtered result — even reached through
        // B's own form.
        $this->assertSame(1, FormStaffCode::count());
        $this->assertNotNull(FormStaffCode::find($codeA->id));
        $this->assertNull(FormStaffCode::find($codeB->id));
        $this->assertCount(0, $this->formB->staffCodes()->get());

        // UPDATE and DELETE reach nothing of B's either: revoking another masjid's
        // code would stop its gate, deleting it would erase its cash record.
        $this->assertSame(0, FormStaffCode::where('id', $codeB->id)->update(['revoked_at' => now()]));
        $this->assertSame(0, FormStaffCode::where('id', $codeB->id)->delete());
        $this->assertNull($codeB->fresh()->revoked_at);

        // CREATE stamps the bound tenant over whatever the payload says.
        $planted = new FormStaffCode([
            'masjid_id' => $this->b->id,
            'form_id' => $this->formA->id,
            'holder_name' => 'Planted',
            'expires_at' => now()->addDay(),
        ]);
        $planted->forceFill(['code_hash' => FormStaffCode::hashFor('PLNT-0000'), 'code_hint' => '00'])->save();

        $this->assertSame($this->a->id, (int) $planted->masjid_id);
    }

    #[Test]
    public function a_code_is_never_issued_on_another_masjids_form(): void
    {
        $this->tenant()->set($this->a->id);

        try {
            FormStaffCode::issue($this->formB, 'Bilal Khan', now()->addDay());
            $this->fail('A code was issued on another masjid\'s form.');
        } catch (LogicException) {
            // Refused before anything was written.
        }

        $this->assertSame(0, FormStaffCode::withoutMasjidScope()->count());
    }

    // ------------------------------------------------ the public gate (unbound)

    #[Test]
    public function the_same_code_opens_only_its_own_form_at_its_own_masjid(): void
    {
        $plain = 'K7QM-2XWD';

        $codeA = FormStaffCode::factory()->withCode($plain)->create(['form_id' => $this->formA->id, 'masjid_id' => $this->a->id]);
        $codeB = FormStaffCode::factory()->withCode($plain)->create(['form_id' => $this->formB->id, 'masjid_id' => $this->b->id]);

        $this->assertSame($codeA->id, FormStaffCode::findUsable($this->a->id, $this->formA->id, 'k7qm 2xwd')?->id);
        $this->assertSame($codeB->id, FormStaffCode::findUsable($this->b->id, $this->formB->id, $plain)?->id);

        // A's header with B's form, and B's header with A's form.
        $this->assertNull(FormStaffCode::findUsable($this->a->id, $this->formB->id, $plain));
        $this->assertNull(FormStaffCode::findUsable($this->b->id, $this->formA->id, $plain));

        // The right masjid, but a form the code was never issued on.
        $this->assertNull(FormStaffCode::findUsable($this->a->id, $this->otherFormA->id, $plain));
    }

    #[Test]
    public function a_code_from_another_masjid_cannot_settle_a_registration(): void
    {
        $response = $this->makeResponse($this->formA);
        [$codeB] = FormStaffCode::issue($this->formB, 'Bilal Khan', now()->addDay());

        try {
            $response->settleCash($codeB);
            $this->fail('B\'s code settled a registration on A\'s form.');
        } catch (LogicException) {
            // Refused before the row was touched.
        }

        $this->assertNull($response->fresh()->payment_status);
        $this->assertSame(0, $codeB->fresh()->use_count);
        $this->assertDatabaseMissing('form_responses', ['id' => $response->id, 'staff_code_id' => $codeB->id]);
    }

    #[Test]
    public function a_forged_holder_pointer_does_not_resolve_across_tenants(): void
    {
        [$codeB] = FormStaffCode::issue($this->formB, 'Bilal Khan', now()->addDay());
        $response = $this->makeResponse($this->formA);

        // Nothing writes this; it stands for a row edited by hand, or a bug.
        $response->forceFill(['staff_code_id' => $codeB->id])->save();

        $this->tenant()->set($this->a->id);

        $this->assertNull(
            $response->fresh()->staffCode,
            'another masjid\'s holder must never appear against A\'s cash'
        );
    }
}
