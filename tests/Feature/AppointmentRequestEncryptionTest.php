<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\AppointmentRequestNote;
use App\Models\Masjid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Encryption-at-rest guardrail for the appointment slice (PLAN T-021).
 *
 * The point of this feature is that a clinic's intake stops living in
 * plaintext (Gmail today). These tests read the RAW column values with the
 * query builder — below the Eloquent casts — and assert the plaintext is not
 * there. If someone ever removes an `encrypted` cast, this is the suite that
 * says so; nothing else would, because the model API keeps returning
 * plaintext either way.
 */
class AppointmentRequestEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private const DOB = '1980-04-12';
    private const REASON = 'Persistent cough for two weeks';
    private const NOTE = 'Needs an interpreter for the follow-up call.';

    private Masjid $masjid;
    private AppointmentRequest $request;

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

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
        ]);

        $this->request = AppointmentRequest::factory()->create([
            'masjid_id' => $this->masjid->id,
            'date_of_birth' => self::DOB,
            'reason' => self::REASON,
        ]);
    }

    /** The raw stored value, read below the cast layer. */
    private function raw(string $table, int $id, string $column): string
    {
        return (string) DB::table($table)->where('id', $id)->value($column);
    }

    #[Test]
    public function date_of_birth_is_ciphertext_at_rest(): void
    {
        $raw = $this->raw('appointment_requests', $this->request->id, 'date_of_birth');

        $this->assertNotSame(self::DOB, $raw);
        $this->assertStringNotContainsString(self::DOB, $raw);
    }

    #[Test]
    public function reason_is_ciphertext_at_rest(): void
    {
        $raw = $this->raw('appointment_requests', $this->request->id, 'reason');

        $this->assertNotSame(self::REASON, $raw);
        $this->assertStringNotContainsString(self::REASON, $raw);
        // Even a fragment must not survive in the clear.
        $this->assertStringNotContainsString('cough', $raw);
    }

    #[Test]
    public function note_bodies_are_ciphertext_at_rest(): void
    {
        $note = AppointmentRequestNote::create([
            'masjid_id' => $this->masjid->id,
            'appointment_request_id' => $this->request->id,
            'user_id' => null,
            'body' => self::NOTE,
        ]);

        $raw = $this->raw('appointment_request_notes', $note->id, 'body');

        $this->assertNotSame(self::NOTE, $raw);
        $this->assertStringNotContainsString('interpreter', $raw);
    }

    #[Test]
    public function the_casts_round_trip_back_to_plaintext(): void
    {
        // Ciphertext at rest is only useful if staff still read plaintext.
        $fresh = AppointmentRequest::find($this->request->id);

        $this->assertSame(self::DOB, $fresh->date_of_birth);
        $this->assertSame(self::REASON, $fresh->reason);
    }

    #[Test]
    public function contact_identity_columns_remain_readable(): void
    {
        // Deliberate boundary of the design: name/phone/email are how staff
        // find and call a person, so they are NOT encrypted. Pinning this
        // stops a well-meaning "encrypt everything" change from silently
        // breaking the queue's search/ordering semantics.
        $raw = $this->raw('appointment_requests', $this->request->id, 'applicant_name');

        $this->assertSame($this->request->applicant_name, $raw);
    }
}
