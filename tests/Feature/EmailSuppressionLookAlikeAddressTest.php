<?php

namespace Tests\Feature;

use App\Models\EmailSuppression;
use App\Models\Masjid;
use App\Services\Broadcast\EmailSuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FoldsAccentsLikeUnicodeCi;
use Tests\TestCase;

/**
 * An opt-out belongs to the ADDRESS it was written for, exactly.
 *
 * THE DEFECT (the point's review of b9f11d4c, 2026-09-29). `suppress()` and
 * `release()` found the row with `where('email_normalized', $address)->first()`.
 * `email_normalized` is utf8mb4_unicode_ci on production (read 2026-09-29), where
 * `victim@gmail.com` = `victim@gmaíl.com`. A resubscribe link minted for the
 * look-alike spelling therefore RELEASED the real person's unsubscribe, and an
 * opt-out for one spelling rewrote, re-dated or re-suppressed the other's row.
 *
 * HOW THESE TESTS REACH IT ON SQLITE (Tests\Support\FoldsAccentsLikeUnicodeCi):
 * this lookup compares with `=` and never calls LOWER, so the column itself is
 * given the collation, and with it the unique index over
 * `(masjid_id, email_normalized)`, exactly as on production. The row is stored
 * under the ACCENTED spelling and the plain address is acted on; every test
 * asserts that the raw query returns the row before asserting the service leaves
 * it alone.
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

        $this->collateColumnLikeUnicodeCi('email_suppressions', 'email_normalized');

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
        $this->assertTheSqlStillFinds($row);
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
        // The real person unsubscribed and later resubscribed: their row is released.
        $row = $this->row(self::STORED, releasedAt: Carbon::now()->subDay());
        $this->assertTheSqlStillFinds($row);
        $before = $this->stored($row);

        $written = $this->service()->suppress($this->masjid->id, self::TYPED, EmailSuppression::REASON_UNSUBSCRIBE_LINK, 7);

        // The unique index cannot hold a second row beside it, so nothing is
        // recorded; and the released row is not re-dated or given the other
        // address's reason and provenance.
        $this->assertNull($written);
        $this->assertSame($before, $this->stored($row), 'Another address\'s row was rewritten.');
        $this->assertSame(1, EmailSuppression::withoutMasjidScope()->count());

        $warnings = array_values(array_filter($this->logged, fn (MessageLogged $e) => $e->level === 'warning' && str_contains($e->message, 'not recorded')));
        $this->assertCount(1, $warnings);
        $this->assertSame($this->masjid->id, $warnings[0]->context['masjid_id']);
        foreach ($this->logged as $event) {
            $this->assertStringNotContainsString('victim', $event->message . json_encode($event->context), 'An address reached the log.');
        }
    }

    #[Test]
    public function an_opt_out_for_one_spelling_does_not_replace_a_hold_written_for_another(): void
    {
        $hold = $this->row(self::STORED, reason: EmailSuppression::REASON_NOT_OPTED_IN);
        $this->assertTheSqlStillFinds($hold);
        $before = $this->stored($hold);

        $this->service()->suppress($this->masjid->id, self::TYPED, EmailSuppression::REASON_UNSUBSCRIBE_LINK);

        $after = $this->stored($hold);
        $this->assertSame($before, $after, 'A hold written for another address was replaced.');
        $this->assertNull($after['held_reason']);
    }

    #[Test]
    public function staff_recording_consent_does_not_lift_a_hold_written_for_another_spelling(): void
    {
        $hold = $this->row(self::STORED, reason: EmailSuppression::REASON_NOT_OPTED_IN);
        $this->assertTheSqlStillFinds($hold);
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

    /** @return array<string, mixed> */
    private function stored(EmailSuppression $row): array
    {
        return EmailSuppression::withoutMasjidScope()->whereKey($row->getKey())->firstOrFail()->getAttributes();
    }

    private function assertTheSqlStillFinds(EmailSuppression $row): void
    {
        $this->assertSame(
            [$row->id],
            EmailSuppression::withoutMasjidScope()->where('email_normalized', self::TYPED)->pluck('id')->all(),
            'PREMISE: where(email_normalized) = the typed address must return the look-alike\'s row, or this test proves nothing.',
        );
    }
}
