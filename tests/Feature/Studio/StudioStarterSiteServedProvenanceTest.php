<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Models\Page;
use App\Support\Studio\LayoutPresets;
use App\Support\Studio\StarterFacts;
use App\Support\Studio\StarterSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Feature\Studio\Concerns\ProvisionsStudioDrafts;
use Tests\TestCase;

/**
 * The end-to-end no-invention check (docs/manara-studio.md D8,
 * docs/manara-studio-w1.md S8). S4's lint walks the plan; this walks what the
 * public API SERVES for a provisioned Studio org, because SectionContentBinder
 * rewrites content at serve time and the lint cannot see that.
 *
 * Every non-empty string in a page's `title`, `page_title` and
 * `meta_description`, and in a section's `title` and `content`, must be one of:
 *
 *  - a fact the client gave, or its tel:/mailto: derivation, or the prose and
 *    link the binder draws from the rows those facts created;
 *  - a starter label, a page path of the preset, or a STRUCTURAL value;
 *  - a URL of the organisation's own media;
 *  - the binder's own words and item types (SectionContentBinder's mission and
 *    vision cards: "Our Mission", "Our Vision", `mission`, `vision`);
 *  - a provisioner default DECISIONS.md records: the donation labels
 *    "Donation Link" and "Donate Now", interface words the wizard has always
 *    written when a link came without wording.
 *
 * Anything else fails, and needs a DECISIONS.md entry before it may be added.
 */
class StudioStarterSiteServedProvenanceTest extends TestCase
{
    use ProvisionsStudioDrafts;
    use RefreshDatabase;

    private const BINDER_WORDS = ['Our Mission', 'Our Vision', 'mission', 'vision'];

    private const PROVISIONER_DEFAULTS = ['Donation Link', 'Donate Now'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpProvisioning();
        $this->actAsSuperAdmin();
    }

    #[Test]
    public function every_string_the_public_api_serves_for_a_studio_org_is_provenanced(): void
    {
        $labels = array_values((array) config('studio_layouts.labels.en'));
        $structural = [];
        foreach (StarterSite::STRUCTURAL as $keys) {
            foreach ($keys as $allowed) {
                if (is_array($allowed)) {
                    $structural = array_merge($structural, array_filter($allowed, 'is_string'));
                }
            }
        }

        $walked = 0;

        foreach (Masjid::ORG_TYPES as $orgType) {
            foreach (LayoutPresets::keysFor($orgType) as $preset) {
                foreach (['minimal' => self::MINIMAL, 'maximal' => self::MAXIMAL] as $fixture => $input) {
                    $answers = $this->studioAnswers($orgType, $input, ['layout' => ['preset' => $preset, 'approved_at' => self::APPROVED_AT]]);
                    $id = $this->provision($this->draftWith($answers)->id)->assertCreated()->json('data.masjid_id');

                    $allowed = array_merge(
                        $this->factStrings($answers),
                        $labels,
                        array_map(fn (array $page) => '/' . $page['slug'], LayoutPresets::pagesOf(LayoutPresets::find($preset))),
                        $structural,
                        Media::where('model_type', Masjid::class)->where('model_id', $id)->get()->map(fn (Media $m) => $m->original_url)->all(),
                        self::BINDER_WORDS,
                        self::PROVISIONER_DEFAULTS,
                    );

                    foreach ($this->servedStrings($id) as $where => $string) {
                        $walked++;
                        $this->assertContains($string, $allowed, "{$preset} ({$fixture}) serves \"{$string}\" at {$where}, which has no provenance");
                    }

                    // The facts did reach the page, so the walk was not over empty pages.
                    $home = $this->getJson('/api/v1/pages/home', ['masjid-id' => (string) $id])->assertOk()->json('data');
                    $this->assertSame($answers['identity']['name'], $home['sections'][0]['content']['title'], "{$preset} ({$fixture})");
                }
            }
        }

        $this->assertGreaterThan(200, $walked, 'the walk covered the served pages');
    }

    /**
     * The client's facts as this draft gave them, their two derivations, and
     * the prose and link the binder serves from the rows they created.
     *
     * @return list<string>
     */
    private function factStrings(array $answers): array
    {
        $flat = array_intersect_key($answers['identity'], array_flip(['name', 'description', 'email', 'phone', 'address', 'facebook_url', 'instagram_url', 'youtube_url', 'whatsapp_url', 'donation_link']))
            + ($answers['content'] ?? []);
        $facts = StarterFacts::fromArray($flat);

        $out = [];
        foreach (StarterFacts::KEYS as $key) {
            $out[] = $facts->get($key);
        }

        foreach (['about', 'mission', 'vision', 'donation_link', 'phone', 'email', 'address'] as $raw) {
            if (isset($flat[$raw])) {
                $out[] = (string) $flat[$raw];
            }
        }

        return array_values(array_filter($out, fn (string $s) => $s !== ''));
    }

    /** @return \Generator<string, string> */
    private function servedStrings(int $masjidId): \Generator
    {
        $headers = ['masjid-id' => (string) $masjidId];
        $bodies = ['/api/v1/pages' => $this->getJson('/api/v1/pages', $headers)->assertOk()->json('data')];

        foreach (Page::where('masjid_id', $masjidId)->where('is_active', true)->pluck('slug') as $slug) {
            $bodies["/api/v1/pages/{$slug}"] = [$this->getJson("/api/v1/pages/{$slug}", $headers)->assertOk()->json('data')];
        }

        foreach ($bodies as $url => $pages) {
            foreach ($pages as $page) {
                foreach (['title', 'page_title', 'meta_description'] as $field) {
                    if (is_string($page[$field]) && $page[$field] !== '') {
                        yield "{$url} {$page['slug']}.{$field}" => $page[$field];
                    }
                }

                foreach ($page['sections'] as $i => $section) {
                    if (is_string($section['title']) && $section['title'] !== '') {
                        yield "{$url} {$page['slug']}.sections.{$i}.title" => $section['title'];
                    }

                    $strings = [];
                    array_walk_recursive($section['content'], function ($value, $key) use (&$strings) {
                        if (is_string($value) && $value !== '') {
                            $strings[] = [$key, $value];
                        }
                    });

                    foreach ($strings as $n => [$key, $value]) {
                        yield "{$url} {$page['slug']}.sections.{$i}.content.{$key}#{$n}" => $value;
                    }
                }
            }
        }
    }
}
