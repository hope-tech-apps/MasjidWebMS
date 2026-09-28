<?php

namespace Tests\Feature\Studio;

use App\Http\Requests\Admin\Onboarding\ProvisionMasjidRequest;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ReadsStudioSource;
use Tests\TestCase;

/**
 * The SPA half of Step 0's fixed iqama times and Jumu'ah list, read from its
 * source (a Feature test cannot render Vue; see StudioSpaSourceTest).
 *
 * "Given" is one rule written twice, once per side: a prayer is given when it
 * has minutes after adhan or a fixed time (ProvisionMasjidRequest::iqamaGiven,
 * which StudioDraft::showsIqama asks too, and core/studio/provision.ts
 * iqamaGiven, which Step 3's blocker asks). If the SPA copy stopped counting a
 * fixed time, Step 3 would name prayers the server accepts as missing, and
 * Provision would stay disabled for NAFIS. The sentences Step 3 shows are the
 * ones the server answers with, so the operator reads the same words from
 * either side. tests/studio-provision.test.ts runs the SPA rule itself.
 */
class StudioFixedIqamaSourceTest extends TestCase
{
    use ReadsStudioSource;

    private const PROVISION = 'core/studio/provision.ts';

    private const PANEL = 'components/super/studio/foundation/PrayerPanel.vue';

    #[Test]
    public function the_completeness_blocker_counts_a_fixed_time_as_given(): void
    {
        $code = $this->spaCode(self::PROVISION);

        $this->assertMatchesRegularExpression(
            '/export function iqamaGiven\([^)]*\): boolean \{\s*return filled\(answers\.prayer\.iqama\?\.\[salah\]\) \|\| filled\(answers\.prayer\.iqama_fixed\?\.\[salah\]\);\s*\}/',
            $code,
            'the SPA counts minutes OR a fixed time as given',
        );
        $this->assertMatchesRegularExpression(
            '/export function iqamaStatus\(.*?const missing = IQAMA_PRAYERS\s*\.filter\(\(\[key\]\) => !iqamaGiven\(answers, key\)\)/s',
            $code,
            'the missing prayers are the ones iqamaGiven says were not given',
        );
        $this->assertMatchesRegularExpression('/export function iqamaBlockers\(.*?const status = iqamaStatus\(answers, asked\);/s', $code);

        // The server's copy of the same rule.
        $this->assertTrue(ProvisionMasjidRequest::iqamaGiven([], ['dhuhr' => '13:45'], 'dhuhr'), 'a fixed time is given');
        $this->assertTrue(ProvisionMasjidRequest::iqamaGiven(['dhuhr' => 0], [], 'dhuhr'), 'zero minutes is given');
        $this->assertFalse(ProvisionMasjidRequest::iqamaGiven(['dhuhr' => null], ['dhuhr' => ''], 'dhuhr'), 'blanks are not');
        $this->assertFalse(ProvisionMasjidRequest::iqamaGiven(['fajr' => 20], ['asr' => '17:30'], 'dhuhr'), 'another prayer\'s time is not this one\'s');
    }

    #[Test]
    public function step_3_says_what_the_server_refuses_in_the_servers_words(): void
    {
        $code = $this->spaCode(self::PROVISION);
        $messages = (new ProvisionMasjidRequest)->messages();

        $this->assertTrue($this->quotes($code, $messages['iqama_fixed_until.required_with']));
        $this->assertTrue($this->quotes($code, $messages['jumaa_times.*.distinct']));
        $this->assertTrue($this->quotes($code, ProvisionMasjidRequest::UTC_FIXED_REFUSAL), 'the UTC refusal is said as the server says it');

        // The window is written with the constant in the SPA; both sides' numbers agree.
        $this->assertSame($messages['iqama_fixed_until.after_or_equal'], $messages['iqama_fixed_until.before_or_equal']);
        $this->assertMatchesRegularExpression('/export const FIXED_IQAMA_MAX_DAYS = ' . ProvisionMasjidRequest::FIXED_IQAMA_MAX_DAYS . ';/', $code);
        $this->assertTrue($this->quotes($code, str_replace(
            (string) ProvisionMasjidRequest::FIXED_IQAMA_MAX_DAYS,
            '${FIXED_IQAMA_MAX_DAYS}',
            $messages['iqama_fixed_until.after_or_equal'],
        )));
    }

    /** The SPA cannot ask PHP, so its list of zones the resolver cannot place a fixed time in is read and compared. */
    #[Test]
    public function the_spa_and_the_resolver_name_the_same_utc_zones(): void
    {
        preg_match('/private const UTC_NAMES = \[(.*?)\];/s', $this->read('app/Support/IqamaResolver.php'), $php);
        preg_match('/export const UTC_ZONE_NAMES = \[(.*?)\];/s', $this->spaCode(self::PROVISION), $spa);
        $this->assertNotEmpty($php[1] ?? null, 'IqamaResolver::UTC_NAMES was found');
        $this->assertNotEmpty($spa[1] ?? null, 'UTC_ZONE_NAMES was found');

        $names = function (string $list): array {
            preg_match_all('/\'([^\']+)\'/', $list, $found);
            sort($found[1]);

            return $found[1];
        };

        $this->assertSame($names($php[1]), $names($spa[1]));
        $this->assertContains('UTC', $names($php[1]));
    }

    #[Test]
    public function the_prayer_panel_asks_for_the_until_date_and_at_most_the_servers_number_of_jumuah_times(): void
    {
        $panel = $this->spaCode(self::PANEL);

        // One date input, required, bounded as the request bounds it, with the hint.
        $this->assertMatchesRegularExpression('/<div v-if="anyFixed"[^>]*>\s*<label for="studio-iqama-fixed-until">/', $panel);
        $this->assertMatchesRegularExpression('/<input id="studio-iqama-fixed-until"[^>]*type="date"[^>]*required :min="untilMin" :max="untilMax"/s', $panel);
        $this->assertTrue($this->saysWords($panel, 'After this date these prayers use minutes after adhan. Update them in Prayer settings before then.'));
        $this->assertStringContainsString('const untilMax = computed(() => addDays(untilMin.value, FIXED_IQAMA_MAX_DAYS));', $panel);

        // Minutes or a fixed time, per prayer; the minutes stay for a fixed prayer.
        $this->assertMatchesRegularExpression('/<input type="radio" :name="`studio-iqama-mode-\$\{salah\}`"/', $panel);
        $this->assertMatchesRegularExpression('/<input :id="`studio-iqama-fixed-\$\{salah\}`"[^>]*type="time"/s', $panel);
        $this->assertMatchesRegularExpression('/<input :id="`studio-iqama-\$\{salah\}`"[^>]*type="number"/s', $panel);

        // The Jumu'ah khutbah times sit outside the "not given" tick's fieldset: they are not iqama times.
        $this->assertMatchesRegularExpression('/<\/fieldset>\s*<div class="studio-field jumuah">/', $panel);
        $this->assertStringNotContainsString('jumaa_times', substr($panel, 0, strpos($panel, '</fieldset>')));
        $this->assertTrue($this->saysWords($panel, "Jumu'ah khutbah times"));
        $this->assertTrue($this->saysWords($panel, 'Add a khutbah time'));
        $this->assertTrue($this->saysWords($panel, 'The Jumu\'ah times must all be different.'));

        // The list stops where the request does.
        $rules = (new ProvisionMasjidRequest)->rules();
        $this->assertStringContainsString('max:4', $rules['jumaa_times']);
        $this->assertStringContainsString('const MAX_JUMUAH_TIMES = 4;', $panel);
    }
}
