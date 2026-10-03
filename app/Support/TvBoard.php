<?php

namespace App\Support;

use App\Http\Controllers\Mobile\TvConfigController;
use App\Models\Masjid;
use App\Models\MasjidTvSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * What an organisation's lobby TV board is told to show: the six settings of
 * the "TV Display" page, resolved against what the organisation IS.
 *
 * ONE resolver, two callers. The public tv-config endpoint puts these values
 * in the payload the tvOS app decodes, and the admin page shows them as "on the
 * screen now". If the two computed them separately the page would sooner or
 * later promise something the board does not get.
 *
 * ## A null is "not chosen", and resolves to what the board got before
 *
 * No row, or a null column, gives exactly the value TvConfigController served
 * when it had no storage: its own public constants, and the two values it
 * derives per request. tests/Feature/Studio/TvConfigSnapshotTest.php compares
 * raw bytes for an organisation with nothing stored and must pass unedited.
 *
 * ## The two derived switches can be turned OFF, never forced ON
 *
 *  - The prayer panel is for a masjid. A school or community organisation has
 *    no worship modules; forced on, the board would sit on "Loading prayer
 *    times" for ever. So it is `isMasjid() && not turned off`.
 *  - The donation code needs a donation link. So it is `a link exists && not
 *    turned off`. This switch is also the ONLY way to hide the code for an
 *    organisation that has a link: with no URL in the payload the board falls
 *    back to the organisation's own link.
 *
 * ## Whatever the row holds, the board can decode the answer
 *
 * The tvOS decoder is strict and failure is silent: a null where a string is
 * required, or a number out of the board's range, and the screen keeps its
 * previous settings while the server logs a 200. The admin request validates,
 * but a row can be edited by hand or outlive a rule change, so resolve() never
 * trusts it: a blank or missing caption is the default caption, an interval
 * outside the allowed range is the default interval, a blank title is null
 * (the board then shows the organisation's name; an EMPTY string would hide
 * the title), and over-long text is cut to its limit.
 *
 * Not here on purpose: the theme (light is unreadable on the TV build released
 * 2026-08-07), which announcements are shown, and a donation URL override.
 * See DECISIONS.md, 2026-10-03.
 */
final class TvBoard
{
    /** The longest title the page accepts. An estimate from the board's layout, not a measurement. */
    public const HEADER_TITLE_MAX = 60;

    /** The longest caption under the donation code. An estimate, as above: a long one shrinks the prayer times. */
    public const DONATE_CAPTION_MAX = 40;

    /** The board itself never turns a slide faster than this (AnnouncementCarouselView). */
    public const CAROUSEL_INTERVAL_MIN = 3;

    /** The board has no ceiling; two minutes on one slide is ours. */
    public const CAROUSEL_INTERVAL_MAX = 120;

    /** The settings an organisation can choose, by the tv-config key each one feeds. */
    public const SETTINGS = [
        'is_enabled',
        'header_title',
        'carousel_interval_seconds',
        'show_prayer_panel',
        'show_qr',
        'donate_caption',
    ];

    /** The three switches. Stored only as `false` or null. */
    public const SWITCHES = ['is_enabled', 'show_prayer_panel', 'show_qr'];

    /**
     * What may not be in a title or a caption: anything that breaks the one line, or
     * turns the reading direction of the text around it.
     *
     *  - every control character (\p{Cc}: tab, line feed, carriage return, NEL U+0085 ...);
     *  - the Unicode line and paragraph separators U+2028 and U+2029, which are line
     *    breaks that are not control characters;
     *  - the bidirectional embedding, override and isolate controls U+202A to U+202E and
     *    U+2066 to U+2069. One of those in a title would reverse how the rest of the
     *    board line reads.
     *
     * NOT refused: the zero-width joiner and non-joiner (U+200C, U+200D) and the
     * left-to-right and right-to-left marks (U+200E, U+200F). Arabic, Persian and Urdu
     * text uses them, and a title is often written in one of those.
     *
     * ONE definition: the request refuses these, the resolver removes them from a row it
     * does not trust, and resources/vue-app/views/dashboard/tvDisplay.ts says the same to
     * the administrator before the request is sent.
     */
    public const NOT_ONE_LINE = '/[\p{Cc}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u';

    private function __construct(
        public readonly bool $isEnabled,
        public readonly ?string $headerTitle,
        public readonly int $carouselIntervalSeconds,
        public readonly bool $showPrayerPanel,
        public readonly bool $showQr,
        public readonly string $donateCaption,
    ) {
    }

    /**
     * @param string|null $donateUrl the organisation's donation link as tv-config sends it (null when there is none)
     */
    public static function resolve(Masjid $masjid, ?string $donateUrl, ?MasjidTvSetting $stored): self
    {
        $interval = (int) ($stored?->carousel_interval_seconds ?? 0);

        return new self(
            isEnabled: $stored?->is_enabled !== false,
            headerTitle: self::text($stored?->header_title, self::HEADER_TITLE_MAX),
            carouselIntervalSeconds: $interval >= self::CAROUSEL_INTERVAL_MIN && $interval <= self::CAROUSEL_INTERVAL_MAX
                ? $interval
                : TvConfigController::CAROUSEL_INTERVAL_SECONDS,
            showPrayerPanel: $masjid->isMasjid() && $stored?->show_prayer_panel !== false,
            showQr: $donateUrl !== null && $stored?->show_qr !== false,
            donateCaption: self::text($stored?->donate_caption, self::DONATE_CAPTION_MAX)
                ?? TvConfigController::DONATE_CAPTION,
        );
    }

    /**
     * The organisation's donation link as the board is given it: trimmed, and
     * null when there is none or it is blank. Needs `donationLink` loaded or
     * loadable on the model.
     */
    public static function donateUrl(Masjid $masjid): ?string
    {
        $url = trim((string) ($masjid->donationLink->link ?? ''));

        return $url === '' ? null : $url;
    }

    /**
     * This organisation's stored row, for a caller with NO tenant bound.
     *
     * The filter is explicit because the public route binds no tenant: the
     * model's scope adds nothing there and a bare first() would hand one
     * organisation another's settings.
     */
    public static function storedFor(int $masjidId): ?MasjidTvSetting
    {
        return MasjidTvSetting::withoutMasjidScope()->where('masjid_id', $masjidId)->first();
    }

    /**
     * storedFor(), for the public endpoint during a deploy.
     *
     * The deploy puts new code in place seconds before it runs `migrate`, and
     * every board polls about every three minutes. In that window the table
     * does not exist yet. No organisation can have chosen anything before the
     * table existed, so "nothing stored" is the true answer; `$unreadable` is
     * set so the caller does not keep that answer in the cache.
     *
     * ANY OTHER database failure is rethrown. A 500 leaves a board on its last
     * good settings; answering defaults would un-pause or re-title a lobby
     * screen because of a database hiccup.
     */
    public static function storedDuringDeploy(int $masjidId, bool &$unreadable): ?MasjidTvSetting
    {
        try {
            return self::storedFor($masjidId);
        } catch (QueryException $e) {
            if (Schema::hasTable((new MasjidTvSetting())->getTable())) {
                throw $e;
            }

            $unreadable = true;
            Log::warning('tv-config: the TV settings table is not there yet; serving the defaults, uncached.', [
                'masjid_id' => $masjidId,
            ]);

            return null;
        }
    }

    /**
     * The six values, by tv-config key, in tv-config's own types.
     *
     * @return array{is_enabled: bool, header_title: ?string, carousel_interval_seconds: int, show_prayer_panel: bool, show_qr: bool, donate_caption: string}
     */
    public function toArray(): array
    {
        return [
            'is_enabled' => $this->isEnabled,
            'header_title' => $this->headerTitle,
            'carousel_interval_seconds' => $this->carouselIntervalSeconds,
            'show_prayer_panel' => $this->showPrayerPanel,
            'show_qr' => $this->showQr,
            'donate_caption' => $this->donateCaption,
        ];
    }

    /** One line of text, trimmed, with no control characters, cut to its limit; null when nothing is left. */
    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // Text that is not valid UTF-8 makes preg_replace answer null: nothing is left,
        // and the caller falls back to its default rather than send bytes no client can read.
        $clean = trim((string) preg_replace(self::NOT_ONE_LINE, ' ', $value));
        // Blanks that are not ASCII (a no-break space, an ideographic space) are still blank.
        $clean = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $clean);

        return $clean === '' ? null : rtrim(mb_substr($clean, 0, $max));
    }
}
