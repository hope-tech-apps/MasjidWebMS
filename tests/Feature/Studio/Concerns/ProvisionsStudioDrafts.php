<?php

namespace Tests\Feature\Studio\Concerns;

use App\Models\StudioDraft;
use App\Support\CapabilityCatalogue;
use App\Support\Studio\LayoutPresets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * What the Step 3 tests share: a complete draft a SuperAdmin could provision,
 * made with the model (the autosave endpoints have their own tests), with a
 * real PNG logo on the private disk, and the fakes that keep a provision from
 * leaving the test: mail, the queue (the attach job would otherwise run
 * synchronously and probe a real host), and outbound HTTP.
 *
 * The sentinel facts are S4's (StudioLayoutPresetsTest), distinctive enough that
 * any copy of one is unmistakable and anything else is not a fact.
 */
trait ProvisionsStudioDrafts
{
    use SeedsAppFeatureCatalogue;
    use StudioDraftFixtures;

    /** A palette whose DesignTokens inks already pass, so no auto-ink is written. */
    protected const PLAIN_BRAND = [
        'primary_color' => '#1B4D3E',
        'secondary_color' => '#1B1B2E',
        'accent_color' => '#7A3E00',
        'background_color' => '#FFFFFF',
    ];

    /** Burlington's palette: white on its green is 2.8:1, so onPrimary auto-inks. */
    protected const BURLINGTON_BRAND = [
        'primary_color' => '#01B151',
        'secondary_color' => '#1B1B2E',
        'accent_color' => '#FFBA63',
        'background_color' => '#F3F8FB',
    ];

    protected const MINIMAL = [
        'name' => 'FACT-NAME-7f3a',
        'email' => 'fact-email-7f3a@example.test',
        'phone' => '+1 555 0100 73',
        'address' => 'FACT-ADDRESS-7f3a',
    ];

    protected const MAXIMAL = self::MINIMAL + [
        'description' => 'FACT-DESCRIPTION-7f3a',
        'facebook_url' => 'https://facebook.example/fact-7f3a',
        'instagram_url' => 'https://instagram.example/fact-7f3a',
        'youtube_url' => 'https://youtube.example/fact-7f3a',
        'whatsapp_url' => 'https://wa.example/fact-7f3a',
        'about' => 'FACT-ABOUT-7f3a',
        'mission' => 'FACT-MISSION-7f3a',
        'vision' => 'FACT-VISION-7f3a',
        'donation_link' => 'https://give.example/fact-7f3a',
    ];

    protected const APPROVED_AT = '2026-09-24T10:00:00Z';

    protected int $countryId;

    protected int $cityId;

    private int $draftSerial = 0;

    /** Temporary derivative directories that existed before this test, which it must not blame itself for. */
    private array $preexistingDerivativeDirectories = [];

    protected function setUpProvisioning(): void
    {
        $this->setUpStudio();
        $this->seedAppFeatureCatalogue();

        $this->countryId = DB::table('countries')->insertGetId(['name' => 'Canada', 'code' => 'CA']);
        $this->cityId = DB::table('cities')->insertGetId(['name' => 'Burlington', 'country_id' => $this->countryId]);

        config(['cloudflare.studio_token' => '']);

        // storage_path() is a real directory shared by every run on this box,
        // and draft ids restart at 1 in each test.
        $this->preexistingDerivativeDirectories = glob(storage_path('app/private/studio-tmp/*')) ?: [];

        Mail::fake();
        Queue::fake();
        Http::preventStrayRequests();
    }

    /**
     * Every section a finished Step 0-2 leaves, for one org type: the identity
     * (with the facts given, the email and slug made unique per draft), a
     * masjid's prayer settings, a palette, the full Step 1 map at its defaults
     * as the SPA sends it (R9), the org type's default preset approved, and
     * iOS, Android and web.
     *
     * @param  array<string, string>  $facts  StarterFacts keys plus about, mission, vision, donation_link
     * @param  array<string, array<string, mixed>>  $sections  whole sections that replace the defaults
     * @return array<string, array<string, mixed>>
     */
    protected function studioAnswers(string $orgType = 'masjid', array $facts = self::MAXIMAL, array $sections = []): array
    {
        $n = ++$this->draftSerial;
        $identityFacts = array_intersect_key($facts, array_flip([
            'name', 'phone', 'address', 'description', 'donation_link', 'facebook_url', 'instagram_url', 'youtube_url', 'whatsapp_url',
        ]));

        return array_replace([
            'identity' => [
                // masjids.name and masjids.phone are unique, so every draft
                // after the first gets a suffix.
                'name' => $n === 1 ? ($facts['name'] ?? 'Studio Org') : ($facts['name'] ?? 'Studio Org') . " {$n}",
                'phone' => $n === 1 ? ($facts['phone'] ?? '+1 555 0100 00') : ($facts['phone'] ?? '+1 555 0100 00') . " {$n}",
            ] + $identityFacts + [
                'org_type' => $orgType,
                'email' => $n === 1 ? ($facts['email'] ?? 'office@example.test') : "draft{$n}-" . ($facts['email'] ?? 'office@example.test'),
                'country_id' => $this->countryId,
                'city_id' => $this->cityId,
                'latitude' => 43.3255,
                'longitude' => -79.799,
                'timezone' => 'America/Toronto',
                'admin' => ['name' => 'Studio Admin', 'email' => "admin{$n}@example.test"],
                'slug' => "studio-org-{$n}",
                'vibe' => 'VIBE-NEVER-PUBLISHED',
            ],
            'prayer' => ['method' => 'NorthAmerica', 'madhab' => 'Shafi', 'high_latitude_rule' => 'MiddleOfTheNight'],
            'brand' => self::PLAIN_BRAND,
            'content' => array_intersect_key($facts, array_flip(['about', 'mission', 'vision'])),
            'features' => ['capabilities' => CapabilityCatalogue::resolve($orgType, [])],
            'layout' => ['preset' => LayoutPresets::defaultFor($orgType), 'approved_at' => self::APPROVED_AT],
            'platforms' => ['platforms' => ['ios', 'android', 'web']],
        ], $sections);
    }

    /** A draft holding `$answers`, with a real PNG logo on the private disk unless `$logo` is false. */
    protected function draftWith(array $answers, bool $logo = true, int $width = 400, int $height = 200): StudioDraft
    {
        $draft = StudioDraft::create([
            'status' => StudioDraft::STATUS_DRAFT,
            'name' => $answers['identity']['name'] ?? null,
            'org_type' => $answers['identity']['org_type'] ?? null,
            'answers' => $answers,
        ]);

        if ($logo) {
            $disk = (string) config('studio.logo.disk');
            $path = "studio-drafts/{$draft->id}/" . str_repeat('b', 40) . '.png';
            Storage::disk($disk)->put($path, $this->pngBytes($width, $height));

            $draft->update([
                'logo_disk' => $disk, 'logo_path' => $path, 'logo_original_name' => 'logo.png', 'logo_mime_type' => 'image/png',
                'logo_size_bytes' => Storage::disk($disk)->size($path), 'logo_width' => $width, 'logo_height' => $height,
                'logo_sha256' => hash('sha256', (string) Storage::disk($disk)->get($path)),
            ]);
        }

        return $draft->fresh();
    }

    protected function provision(int $draftId, array $body = []): TestResponse
    {
        return $this->postJson(self::DRAFTS . "/{$draftId}/provision", $body);
    }

    /** The temporary directories logo derivatives were made in for this draft, during this test. */
    protected function derivativeDirectories(int $draftId): array
    {
        return array_values(array_diff(
            glob(storage_path("app/private/studio-tmp/{$draftId}-*")) ?: [],
            $this->preexistingDerivativeDirectories,
        ));
    }
}
