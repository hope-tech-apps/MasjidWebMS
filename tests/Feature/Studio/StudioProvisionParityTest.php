<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\StudioDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * A draft provisions exactly what a direct POST of the same answers to the
 * wizard's endpoint provisions (docs/manara-studio-w1.md S8, R10): the draft is
 * flattened into ProvisionMasjidRequest and run through the one
 * OrganisationProvisioner body. What only Studio does is the draft-only work
 * R10 names (the logo and its derivatives in `media`, the inks, the draft's
 * status), so those are the only differences allowed.
 *
 * Two organisations cannot share an email, a name, a phone or a subdomain, so
 * the second one differs in exactly those, and they are normalised away.
 */
class StudioProvisionParityTest extends TestCase
{
    use ProvisionsStudioDrafts;
    use RefreshDatabase;

    /** Tables one provision writes rows to for its organisation. */
    private const TABLES = [
        'theme_settings', 'masjid_abouts', 'prayer_calculation_settings', 'iqama_time_settings', 'jumaa_settings',
        'donation_links', 'masjid_social_media_links', 'masjid_mobile_app_features', 'forms', 'masjid_app_publishing',
        'masjid_capability_changes', 'pages', 'sections', 'masjid_domains', 'masjid_user',
    ];

    private const VOLATILE = ['id', 'masjid_id', 'user_id', 'page_id', 'section_id', 'created_at', 'updated_at', 'password', 'remember_token'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpProvisioning();
        $this->actAsSuperAdmin();
    }

    #[Test]
    public function a_draft_and_a_direct_post_produce_the_same_rows_and_the_same_response_shape(): void
    {
        foreach (Masjid::ORG_TYPES as $orgType) {
            $map = ['gallery' => false, 'web_pages' => true] + \App\Support\CapabilityCatalogue::resolve($orgType, []);
            $sections = ['features' => ['capabilities' => $map]];

            $viaDraft = $this->studioAnswers($orgType, self::MAXIMAL, $sections);
            $viaPost = $this->studioAnswers($orgType, self::MAXIMAL, $sections);

            $studio = $this->provision($this->draftWith($viaDraft)->id)->assertCreated()->json('data');
            $direct = $this->postJson('/api/admin/onboarding/provision', (new StudioDraft(['answers' => $viaPost]))->toProvisionPayload())
                ->assertCreated()->json('data');

            $this->assertSame(
                $this->rows($studio['masjid_id'], $viaDraft),
                $this->rows($direct['masjid_id'], $viaPost),
                "{$orgType}: the draft wrote different rows from the direct POST",
            );

            $this->assertSame(array_keys($direct), array_intersect(array_keys($studio), array_keys($direct)), "{$orgType}: the wizard's keys lead Studio's body");
            $this->assertSame(['masjid_id', 'masjid', 'app_publishing'], array_keys($direct));
            $this->assertSame(array_keys($direct['masjid']), array_keys($studio['masjid']), "{$orgType}: the masjid serializes with the same keys");
            $this->assertSame($direct['app_publishing'], $studio['app_publishing']);
            $this->assertSame(
                $this->normalised(array_diff_key($direct['masjid'], ['logo' => true]), $viaPost),
                $this->normalised(array_diff_key($studio['masjid'], ['logo' => true]), $viaDraft),
                "{$orgType}: the masjid serializes the same values",
            );
        }
    }

    /** Every row the provision wrote for one organisation, with ids, times and its identity normalised. */
    private function rows(int $masjidId, array $answers): array
    {
        $masjid = (array) DB::table('masjids')->where('id', $masjidId)->first();
        $out = ['masjids' => [$this->normalised($masjid, $answers)]];

        // A section that references a form holds that org's form id; it is
        // compared as which of the org's forms it points at.
        $formOrder = DB::table('forms')->where('masjid_id', $masjidId)->orderBy('id')->pluck('id')->flip();

        foreach (self::TABLES as $table) {
            $out[$table] = DB::table($table)->where('masjid_id', $masjidId)->orderBy('id')->get()
                ->map(function ($row) use ($answers, $table, $formOrder) {
                    $row = (array) $row;

                    if ($table === 'sections') {
                        $content = json_decode($row['content'], true);
                        if (isset($content['form_id'])) {
                            $content['form_id'] = 'forms#' . $formOrder[$content['form_id']];
                        }
                        $row['content'] = $content;
                    }

                    return $this->normalised($row, $answers);
                })->all();
        }

        $pageIds = DB::table('pages')->where('masjid_id', $masjidId)->pluck('id');
        $sectionOrder = DB::table('sections')->where('masjid_id', $masjidId)->orderBy('id')->pluck('id')->flip();
        $pageOrder = $pageIds->flip();
        $out['page_section'] = DB::table('page_section')->whereIn('page_id', $pageIds)->orderBy('id')->get()
            ->map(fn ($row) => ['page' => $pageOrder[$row->page_id], 'section' => $sectionOrder[$row->section_id]] + $this->normalised((array) $row, $answers))->all();

        $out['users'] = [$this->normalised((array) DB::table('users')->where('id', $masjid['user_id'])->first(), $answers)];

        return $out;
    }

    private function normalised(array $row, array $answers): array
    {
        $identity = $answers['identity'];
        $swap = [
            $identity['admin']['email'] => 'ADMIN-EMAIL',
            $identity['email'] => 'EMAIL',
            $identity['slug'] => 'SLUG',
            $identity['name'] => 'NAME',
            $identity['phone'] => 'PHONE',
            // The connect block's Call button: the phone as a tel: link.
            'tel:' . preg_replace('/[^0-9+]/', '', $identity['phone']) => 'PHONE-TEL',
        ];

        return $this->scrub($row, $swap);
    }

    /** Ids and times removed at every depth (the masjid serializes its relations), identity swapped. */
    private function scrub(array $row, array $swap): array
    {
        $row = array_diff_key($row, array_flip(self::VOLATILE));

        foreach ($row as $key => $value) {
            if (is_array($value)) {
                $row[$key] = $this->scrub($value, $swap);
            } elseif (is_string($value)) {
                $row[$key] = strtr($value, $swap);
            }
        }

        return $row;
    }
}
