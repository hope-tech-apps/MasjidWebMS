<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\Contact;
use App\Models\EmailSuppression;
use App\Models\Masjid;
use App\Services\Broadcast\BroadcastAudienceResolver;
use App\Services\Broadcast\EmailAudience;
use App\Services\Broadcast\EmailSuppressionService;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FoldsAccentsLikeUnicodeCi;
use Tests\TestCase;

/**
 * An opt-out belongs to the ADDRESS it was written for, exactly, and a person
 * who asked to stop is always written down.
 *
 * THE FIRST DEFECT (the point's review of b9f11d4c, 2026-09-29). `suppress()`
 * and `release()` found the row with `where('email_normalized', $address)->first()`
 * on a `utf8mb4_unicode_ci` column, where `victim@gmail.com` = `victim@gmaíl.com`.
 * A resubscribe link minted for the look-alike spelling therefore RELEASED the
 * real person's unsubscribe, and an opt-out for one spelling rewrote, re-dated or
 * re-suppressed the other's row. Fixed by re-checking the row in PHP.
 *
 * THE SECOND (the point's review of the fix). The same collation made the unique
 * index over `(masjid_id, email_normalized)` refuse the real person's own row
 * once the look-alike held one. The fix logged and did NOT write, and the
 * unsubscribe page still said "done": a person who asked to stop went on being
 * mailed. The column is now `utf8mb4_bin` (migration
 * `make_email_suppression_key_byte_exact`), so both spellings hold a row and
 * nothing is refused.
 *
 * HOW THESE TESTS REACH IT ON SQLITE (Tests\Support\FoldsAccentsLikeUnicodeCi):
 * the table is built the way production WILL be, byte-exact, and the ACCENTED
 * spelling is the one that holds a row while the plain address is acted on.
 * Every test first asserts the premise (the column really is byte-exact: the
 * plain address finds no row and the accented one does), which on MySQL reads the
 * migrated column itself and fails there if the migration has not run.
 * One test rebuilds the table the way production is BEFORE the migration
 * (unicode_ci) to prove the failure is loud and never a quiet "done".
 */
class EmailSuppressionLookAlikeAddressTest extends TestCase
{
    use FoldsAccentsLikeUnicodeCi;
    use RefreshDatabase;

    /** The spelling the row was written for. */
    private const STORED = 'victim@gmaíl.com';

    /** The address the link or the import names. */
    private const TYPED = 'victim@gmail.com';

    private Masjid $masjid;

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->collateColumnLikeUtf8mb4Bin('email_suppressions', 'email_normalized');

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => '1 St',
            'latitude' => 0.0, 'longitude' => 0.0,
            'crm_enabled' => true,
        ]);

        Event::listen(MessageLogged::class, function (MessageLogged $event) {
            $this->logged[] = $event;
        });
    }

    #[Test]
    public function a_resubscribe_for_a_look_alike_spelling_does_not_release_the_real_persons_opt_out(): void
    {
        $row = $this->row(self::STORED);
        $this->assertTheColumnIsByteExact($row);
        $before = $this->stored($row);

        $released = $this->service()->release($this->masjid->id, self::TYPED);

        $this->assertNull($released);
        $this->assertSame($before, $this->stored($row), 'Another address\'s opt-out was released.');
        $this->assertTrue($this->service()->isSuppressed($this->masjid->id, self::STORED));
    }

    #[Test]
    public function a_release_for_the_exact_address_still_works_whatever_its_case(): void
    {
        $row = $this->row('victim@gmail.com');

        $released = $this->service()->release($this->masjid->id, '  Victim@GMAIL.com ');

        $this->assertSame($row->id, $released?->id);
        $this->assertNotNull($this->stored($row)['released_at']);
    }

    #[Test]
    public function an_opt_out_for_one_spelling_does_not_re_suppress_a_released_row_of_another(): void
    {
        // The look-alike's owner unsubscribed and later resubscribed: their row is released.
        $row = $this->row(self::STORED, releasedAt: Carbon::now()->subDay());
        $this->assertTheColumnIsByteExact($row);
        $before = $this->stored($row);

        $written = $this->service()->suppress($this->masjid->id, self::TYPED, EmailSuppression::REASON_UNSUBSCRIBE_LINK, 7);

        // The real address gets a row of its own, and the released row is not
        // re-dated or given the other address's reason and provenance.
        $this->assertNotNull($written);
        $this->assertNotSame($row->id, $written->id);
        $this->assertSame(self::TYPED, $written->email_normalized);
        $this->assertNull($written->released_at);
        $this->assertSame($before, $this->stored($row), 'Another address\'s row was rewritten.');
        $this->assertSame(2, EmailSuppression::withoutMasjidScope()->count());
        $this->assertNothingWasLoggedAsDropped();
    }

    #[Test]
    public function an_opt_out_for_one_spelling_does_not_replace_a_hold_written_for_another(): void
    {
        $hold = $this->row(self::STORED, reason: EmailSuppression::REASON_NOT_OPTED_IN);
        $this->assertTheColumnIsByteExact($hold);
        $before = $this->stored($hold);

        $written = $this->service()->suppress($this->masjid->id, self::TYPED, EmailSuppression::REASON_UNSUBSCRIBE_LINK);

        $after = $this->stored($hold);
        $this->assertSame($before, $after, 'A hold written for another address was replaced.');
        $this->assertNull($after['held_reason']);
        $this->assertSame(self::TYPED, $written?->email_normalized);
        $this->assertNull($written->held_reason, 'The new row inherited a hold that was never its own.');
    }

    #[Test]
    public function staff_recording_consent_does_not_lift_a_hold_written_for_another_spelling(): void
    {
        $hold = $this->row(self::STORED, reason: EmailSuppression::REASON_NOT_OPTED_IN);
        $this->assertTheColumnIsByteExact($hold);
        $before = $this->stored($hold);

        $lifted = $this->service()->liftPrecaution($this->masjid->id, self::TYPED, 'Told us in person', null);

        $this->assertNull($lifted);
        $this->assertSame($before, $this->stored($hold));
    }

    #[Test]
    public function an_opt_out_for_an_address_with_no_row_is_written_under_its_normalised_key(): void
    {
        $written = $this->service()->suppress($this->masjid->id, ' Victim@GMAIL.com ');

        $this->assertNotNull($written);
        $this->assertSame(self::TYPED, $written->email_normalized);
    }

    #[Test]
    public function every_read_of_a_suppression_names_only_the_exact_address(): void
    {
        $row = $this->row(self::STORED);
        $this->assertTheColumnIsByteExact($row);

        $service = $this->service();

        $this->assertTrue($service->isSuppressed($this->masjid->id, self::STORED));
        $this->assertFalse($service->isSuppressed($this->masjid->id, self::TYPED), 'The look-alike\'s opt-out suppressed the real address.');
        $this->assertNull($service->suppressedAt($this->masjid->id, self::TYPED));
        $this->assertNull($service->activeReason($this->masjid->id, self::TYPED));
        $this->assertSame(EmailSuppression::REASON_UNSUBSCRIBE_LINK, $service->activeReason($this->masjid->id, self::STORED));
        $this->assertSame(
            [self::STORED],
            $service->suppressedAmong($this->masjid->id, [self::TYPED, ' Victim@GMAíl.com ', 'someone@else.test']),
            'The send-time filter must name only the address that holds the row.',
        );
    }

    #[Test]
    public function the_real_person_who_unsubscribes_beside_a_look_alikes_row_is_written_and_no_longer_mailed(): void
    {
        // The look-alike's owner unsubscribed first. That row is theirs.
        $lookAlikeRow = $this->row(self::STORED);
        $this->assertTheColumnIsByteExact($lookAlikeRow);
        $before = $this->stored($lookAlikeRow);

        $this->contact(self::TYPED);
        $this->contact(self::STORED);
        $this->contact('control@test.local');
        $broadcast = $this->broadcast();

        // Before: the real person is still mailed (the look-alike's opt-out is not
        // theirs), the look-alike is not.
        $audience = $this->audience($broadcast);
        $this->assertEqualsCanonicalizing([self::TYPED, 'control@test.local'], $audience->recipients->pluck('email')->all());
        $this->assertSame(1, $audience->suppressed);

        // The real person follows the link in their own copy of the message. The
        // page says "done", and that must be true.
        $url = $this->service()->urls($this->masjid->id, self::TYPED, 7)['one_click'];
        $this->post($url)->assertOk()->assertSee('will no longer send announcement');

        // Their row is written: a row of its own, in force, with the provenance
        // of the link they followed.
        $rows = EmailSuppression::withoutMasjidScope()->where('masjid_id', $this->masjid->id)->orderBy('id')->get();
        $this->assertSame([self::STORED, self::TYPED], $rows->pluck('email_normalized')->all());
        $mine = $rows->firstWhere('email_normalized', self::TYPED);
        $this->assertNull($mine->released_at);
        $this->assertSame(EmailSuppression::REASON_UNSUBSCRIBE_LINK, $mine->reason);
        $this->assertSame(7, (int) $mine->broadcast_id);

        // The look-alike's row is untouched.
        $this->assertSame($before, $this->stored($lookAlikeRow), 'The look-alike\'s row was rewritten by another address\'s opt-out.');

        // After: the send filter no longer reaches them, and still not the look-alike.
        $this->assertTrue($this->service()->isSuppressed($this->masjid->id, self::TYPED));
        $audience = $this->audience($broadcast);
        $this->assertSame(['control@test.local'], $audience->recipients->pluck('email')->all(), 'A person who unsubscribed is still in the audience.');
        $this->assertSame(2, $audience->suppressed);
        $this->assertNothingWasLoggedAsDropped();
    }

    #[Test]
    public function an_opt_out_the_column_cannot_store_fails_loudly_instead_of_saying_it_was_recorded(): void
    {
        // The table as production is BEFORE the migration: unicode_ci, so the
        // unique index calls the two spellings one address. Nothing may pretend
        // the real person's opt-out was written: the old behaviour returned null
        // here and the page said "done".
        $this->collateColumnLikeUnicodeCi('email_suppressions', 'email_normalized');
        $this->row(self::STORED);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->service()->suppress($this->masjid->id, self::TYPED);
    }

    private function service(): EmailSuppressionService
    {
        return app(EmailSuppressionService::class);
    }

    private function row(string $key, string $reason = EmailSuppression::REASON_UNSUBSCRIBE_LINK, ?Carbon $releasedAt = null): EmailSuppression
    {
        $row = new EmailSuppression();
        $row->forceFill([
            'masjid_id' => $this->masjid->id,
            'email_normalized' => $key,
            'reason' => $reason,
            'suppressed_at' => Carbon::now()->subDays(3),
            'released_at' => $releasedAt,
        ])->save();

        return $row->refresh();
    }

    private function contact(string $email): Contact
    {
        return Contact::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'first_name' => 'Amina',
            'last_name' => 'Test',
            'email' => $email,
        ]);
    }

    private function broadcast(): Broadcast
    {
        return Broadcast::withoutMasjidScope()->create([
            'masjid_id' => $this->masjid->id,
            'title' => 'Snow closure',
            'body' => 'All programs are cancelled today because of the storm.',
            'audience' => 'everyone',
            'status' => Broadcast::STATUS_SENT,
        ]);
    }

    /** The audience the way the dispatcher resolves it: with the tenant bound. */
    private function audience(Broadcast $broadcast): EmailAudience
    {
        $tenant = app(TenantContext::class);
        $tenant->set($this->masjid->id);

        try {
            return app(BroadcastAudienceResolver::class)->emailAudience($broadcast);
        } finally {
            $tenant->forgetTenant();
        }
    }

    /** @return array<string, mixed> */
    private function stored(EmailSuppression $row): array
    {
        return EmailSuppression::withoutMasjidScope()->whereKey($row->getKey())->firstOrFail()->getAttributes();
    }

    /** No warning says an opt-out was left unwritten, and no address reached the log. */
    private function assertNothingWasLoggedAsDropped(): void
    {
        $dropped = array_filter($this->logged, fn (MessageLogged $e) => str_contains($e->message, 'not recorded'));
        $this->assertSame([], array_values($dropped), 'An opt-out was logged as not recorded.');

        foreach ($this->logged as $event) {
            $this->assertStringNotContainsString('victim', $event->message . json_encode($event->context), 'An address reached the log.');
        }
    }

    /**
     * PREMISE: the column is byte-exact. The plain address finds nothing and the
     * accented one finds the row. On MySQL this reads the migrated column, so it
     * fails there if `make_email_suppression_key_byte_exact` has not run.
     */
    private function assertTheColumnIsByteExact(EmailSuppression $row): void
    {
        $this->assertSame(
            [],
            EmailSuppression::withoutMasjidScope()->where('email_normalized', self::TYPED)->pluck('id')->all(),
            'PREMISE: where(email_normalized) = the typed address must NOT return the look-alike\'s row, or the column is still collation-equal.',
        );
        $this->assertSame(
            [$row->id],
            EmailSuppression::withoutMasjidScope()->where('email_normalized', $row->email_normalized)->pluck('id')->all(),
            'PREMISE: the row must be found by its own key.',
        );
    }
}
