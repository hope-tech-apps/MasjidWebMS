<?php

namespace Tests\Feature;

use App\Support\PortalLanguage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The parent portal's language table (resources/vue-app/views/family/
 * familyI18n.ts, FAMILY_LANGS) must agree with the server's
 * (App\Support\PortalLanguage + config/translation.php), and every locale file
 * must carry every label.
 *
 * There is no SPA test runner, so the PHP suite reads the files, the way
 * CapabilityTsMirrorTest does. Three things drift silently without it:
 *
 *   - DIRECTION. Urdu, Pashto and Dari are right-to-left like Arabic. The
 *     portal lays every screen out from its own `dir`, and the translation
 *     endpoint answers with the server's. Two copies that disagree is a page
 *     mirrored one way and a translated paragraph the other.
 *   - THE OFFER. A language the picker lists but the server refuses is a
 *     Translate button that can only 422; one the server offers but the
 *     picker lacks is a language nobody can choose.
 *   - COVERAGE. A key missing from a locale falls back to English mid-screen,
 *     which reads as a broken page to exactly the parent the locale is for.
 *
 * The file layout is part of the contract: one `{ code: "..", label: "..",
 * dir: "..", intl: "..", reviewed: .. }` per line in FAMILY_LANGS, and each
 * locale file an object literal with one `key: "value",` per line.
 */
class FamilyLanguagesMirrorTest extends TestCase
{
    private const I18N = 'resources/vue-app/views/family/familyI18n.ts';

    private const LOCALE_FILES = [
        'ur' => 'resources/vue-app/views/family/locales/ur.ts',
        'ps' => 'resources/vue-app/views/family/locales/ps.ts',
        'fa-AF' => 'resources/vue-app/views/family/locales/fa-AF.ts',
        'es' => 'resources/vue-app/views/family/locales/es.ts',
    ];

    private function read(string $relative): string
    {
        $path = base_path($relative);

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return array<string,array{dir:string,intl:string,reviewed:bool}> keyed by code, in file order */
    private function tsLanguages(): array
    {
        preg_match_all(
            '/\{\s*code:\s*"([^"]+)",\s*label:\s*"[^"]+",\s*dir:\s*"(ltr|rtl)",\s*intl:\s*"([^"]+)",\s*reviewed:\s*(true|false)\s*\}/',
            $this->read(self::I18N),
            $m,
            PREG_SET_ORDER,
        );

        $out = [];

        foreach ($m as [, $code, $dir, $intl, $reviewed]) {
            $out[$code] = ['dir' => $dir, 'intl' => $intl, 'reviewed' => $reviewed === 'true'];
        }

        $this->assertNotEmpty($out, 'FAMILY_LANGS could not be read from familyI18n.ts — has its layout changed?');

        return $out;
    }

    /**
     * `key: "value"` pairs from an object literal body.
     *
     * @return array<string,string>
     */
    private function pairs(string $body): array
    {
        preg_match_all('/^\s+([a-z0-9_]+):\s*"((?:[^"\\\\]|\\\\.)*)",\s*$/m', $body, $m, PREG_SET_ORDER);

        $out = [];

        foreach ($m as [, $key, $value]) {
            $this->assertArrayNotHasKey($key, $out, "Duplicate key {$key}");
            $out[$key] = $value;
        }

        return $out;
    }

    /** @return array<string,string> */
    private function englishTable(): array
    {
        $source = $this->read(self::I18N);

        $this->assertMatchesRegularExpression('/\n    en: \{\n(.*?)\n    \},\n    ar: \{/s', $source);
        preg_match('/\n    en: \{\n(.*?)\n    \},\n    ar: \{/s', $source, $m);

        $table = $this->pairs($m[1]);

        $this->assertGreaterThan(100, count($table), 'The English table read back too small to be the real one.');

        return $table;
    }

    #[Test]
    public function every_server_language_is_in_the_picker_with_the_same_direction(): void
    {
        $ts = $this->tsLanguages();

        foreach (PortalLanguage::LANGUAGES as $code => $info) {
            $this->assertArrayHasKey($code, $ts, "{$code} is offered by the server but missing from FAMILY_LANGS");
            $this->assertSame($info['dir'], $ts[$code]['dir'], "{$code}: server and portal disagree on direction");
        }

        // And nothing in the picker the server would not translate into —
        // apart from English, the language everything is written in.
        $this->assertSame(
            array_merge(['en'], array_keys(PortalLanguage::LANGUAGES)),
            array_keys($ts),
        );
    }

    #[Test]
    public function the_rtl_flag_is_right_for_each_language(): void
    {
        $expected = ['en' => 'ltr', 'ar' => 'rtl', 'ur' => 'rtl', 'ps' => 'rtl', 'fa-AF' => 'rtl', 'es' => 'ltr'];

        $ts = $this->tsLanguages();

        foreach ($expected as $code => $dir) {
            $this->assertSame($dir, $ts[$code]['dir'], "portal: {$code}");

            if ($code !== 'en') {
                $this->assertSame($dir, PortalLanguage::dir($code), "server: {$code}");
                $this->assertSame($dir === 'rtl', PortalLanguage::isRtl($code), "server isRtl: {$code}");
            }
        }

        // An unknown tag is laid out left-to-right rather than guessed at.
        $this->assertSame('ltr', PortalLanguage::dir('fr'));
    }

    #[Test]
    public function the_picker_order_is_the_config_order(): void
    {
        $this->assertSame(
            (array) config('translation.languages'),
            array_values(array_diff(array_keys($this->tsLanguages()), ['en'])),
        );
    }

    #[Test]
    public function right_to_left_dates_keep_western_digits_and_afghan_dates_stay_gregorian(): void
    {
        foreach ($this->tsLanguages() as $code => $info) {
            if ($info['dir'] === 'rtl') {
                // Counts on the same screen arrive as 25, never ٢٥.
                $this->assertStringContainsString('nu-latn', $info['intl'], "{$code} must pin Western digits");
            }
        }

        // CLDR's default calendar for Pashto and Dari is Solar Hijri; the
        // school's days, report dates and notes are Gregorian.
        foreach (['ps', 'fa-AF'] as $code) {
            $this->assertStringContainsString('ca-gregory', $this->tsLanguages()[$code]['intl'], $code);
        }
    }

    #[Test]
    public function every_locale_file_has_exactly_the_english_keys_and_keeps_every_slot(): void
    {
        $english = $this->englishTable();

        foreach (self::LOCALE_FILES as $code => $file) {
            $table = $this->pairs($this->read($file));

            $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($table))), "{$code} is missing keys");
            $this->assertSame([], array_values(array_diff(array_keys($table), array_keys($english))), "{$code} has keys English does not");

            foreach ($english as $key => $value) {
                $this->assertSame(
                    str_contains($value, '{x}'),
                    str_contains($table[$key], '{x}'),
                    "{$code}.{$key}: the {x} slot must survive translation",
                );
                $this->assertNotSame('', trim($table[$key]), "{$code}.{$key} is blank");
            }
        }
    }

    #[Test]
    public function an_unreviewed_locale_says_so_at_the_top_of_its_file(): void
    {
        $ts = $this->tsLanguages();

        foreach (self::LOCALE_FILES as $code => $file) {
            $head = substr($this->read($file), 0, 600);

            if (! $ts[$code]['reviewed']) {
                $this->assertStringContainsString('MACHINE-DRAFTED', $head, "{$code}: an unreviewed locale must carry the warning");
            }
        }

        // The four added 2026-09-21 have not been read by a fluent speaker.
        // Flipping one of these is a claim that somebody has; make it on purpose.
        foreach (array_keys(self::LOCALE_FILES) as $code) {
            $this->assertFalse($ts[$code]['reviewed'], "{$code} is marked reviewed — was it?");
        }
    }

    #[Test]
    public function religious_terms_are_kept_as_the_school_uses_them(): void
    {
        // Not a translation check — a check that the draft did not "translate"
        // the words the school deliberately keeps.
        $es = $this->pairs($this->read(self::LOCALE_FILES['es']));

        $this->assertSame("Qur'an", $es['section_quran']);
        $this->assertSame('Surah', $es['surah']);
        $this->assertStringStartsWith('Assalamu alaikum', $es['home_greeting']);

        foreach (['ur', 'ps', 'fa-AF'] as $code) {
            $table = $this->pairs($this->read(self::LOCALE_FILES[$code]));

            $this->assertSame('قرآن', $table['section_quran'], $code);
            $this->assertSame('السلام علیکم', $table['home_greeting'], $code);
        }
    }
}
