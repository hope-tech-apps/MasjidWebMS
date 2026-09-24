<?php

namespace Tests\Feature\Studio;

use App\Models\Masjid;
use App\Support\Studio\StarterFacts;
use App\Support\Studio\StarterPlan;
use App\Support\Studio\StarterSite;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * StarterSite::plan()'s activation rules (docs/manara-studio-w1.md S4; the
 * layouts recon's §B): what a switched-off module, a missing fact and a page
 * that will not be served do to the starter site. Pure, so no database.
 */
class StarterPlanTest extends TestCase
{
    private const FACTS = [
        'name' => 'Al-Noor Centre',
        'email' => 'office@alnoor.test',
        'phone' => '+1 555 0100',
        'address' => '1 Test St',
    ];

    #[Test]
    public function a_module_that_is_off_omits_its_sections_and_an_emptied_page(): void
    {
        $facts = StarterFacts::fromArray(self::FACTS + ['about' => 'We are a centre.', 'donation_link' => 'https://give.example/alnoor']);

        $before = StarterSite::plan($this->org('masjid'), 'masjid.classic', $facts);
        $this->assertNotNull($before->page('events'), 'the premise: masjid.classic writes an events page');
        $this->assertNotNull($before->section('home/donation'));
        $this->assertNotNull($before->page('donate'));

        // Events off: its page had nothing else, so the page goes with it.
        $plan = StarterSite::plan($this->org('masjid', ['events' => false]), 'masjid.classic', $facts);
        $this->assertNull($plan->page('events'), 'an events page was written for an organisation with Events off');
        $this->assertNotContains('events', $this->types($plan));
        $this->assertNotNull($plan->page('gallery'), 'a page whose module is on was dropped with it');

        // Donation link off: the home section and the Donate page both go.
        $plan = StarterSite::plan($this->org('masjid', ['donation_link' => false]), 'masjid.classic', $facts);
        $this->assertNull($plan->section('home/donation'));
        $this->assertNull($plan->page('donate'));
        $this->assertNotContains('donation', $this->types($plan));

        // A school is never offered Donation link, so it is never written there.
        $school = StarterSite::plan($this->org('school'), 'school.community', $facts);
        $this->assertNotContains('donation', $this->types($school));

        // About Us off empties the About page; home keeps its other sections.
        $plan = StarterSite::plan($this->org('masjid', ['about_us' => false]), 'masjid.classic', $facts);
        $this->assertNull($plan->page('about'));
        $this->assertNull($plan->section('home/about_teaser'));
        $this->assertTrue($plan->section('home/hero')['is_active']);
    }

    #[Test]
    public function a_section_is_published_only_when_the_fact_it_binds_was_given(): void
    {
        $minimal = StarterSite::plan($this->org('masjid'), 'masjid.classic', StarterFacts::fromArray(self::FACTS));

        foreach (['home/about_teaser', 'about/about', 'about/mission', 'home/donation', 'donate/donation'] as $slot) {
            $section = $minimal->section($slot);
            $this->assertFalse($section['is_active'], "{$slot} was published with nothing to bind");
            $this->assertTrue($section['placeholders'][0]['open']);
            $this->assertSame('bound', $section['placeholders'][0]['kind']);
        }

        // Nothing but the banner could publish, so these pages are written
        // inactive, and Donate is not a button.
        $this->assertFalse($minimal->page('about')['is_active']);
        $this->assertFalse($minimal->page('donate')['is_active']);
        $this->assertFalse($minimal->page('donate')['show_as_button']);

        $full = StarterSite::plan($this->org('masjid'), 'masjid.classic', StarterFacts::fromArray(self::FACTS + [
            'about' => 'We are a centre.', 'mission' => 'To serve.', 'donation_link' => 'https://give.example/alnoor',
        ]));

        foreach (['home/about_teaser', 'about/about', 'about/mission', 'home/donation', 'donate/donation'] as $slot) {
            $this->assertTrue($full->section($slot)['is_active'], "{$slot} stayed unpublished although its fact was given");
            // Bound, never copied: the binder fills it at serve time.
            $this->assertStringNotContainsString('We are a centre.', json_encode($full->section($slot)['content']));
        }

        $this->assertTrue($full->page('donate')['show_as_button']);
        $this->assertTrue($full->page('about')['is_active']);
    }

    #[Test]
    public function a_button_never_opens_a_page_the_site_will_not_serve(): void
    {
        $facts = StarterFacts::fromArray(self::FACTS);

        // Contact Requests off: no contact page, so the hero keeps its place
        // and loses the button that would open it.
        $plan = StarterSite::plan($this->org('masjid', ['contact_requests' => false]), 'masjid.essentials', $facts);
        $this->assertNull($plan->page('contact'));
        $hero = $plan->section('home/hero');
        $this->assertTrue($hero['is_active']);
        $this->assertSame('', $hero['content']['button_link']);
        $this->assertSame('', $hero['content']['button_text']);

        // A call to action whose page was omitted has no purpose and is omitted.
        $plan = StarterSite::plan($this->org('community', ['contact_requests' => false]), 'community.services', $facts);
        $this->assertNull($plan->section('home/cta_contact'));

        // Admissions is written but unpublished (its tuition and form await the
        // school), so the Apply button is held back with it, and says why.
        $plan = StarterSite::plan($this->org('school'), 'school.essentials', $facts);
        $this->assertFalse($plan->page('admissions')['is_active']);
        $cta = $plan->section('home/cta_admissions');
        $this->assertSame('/admissions', $cta['content']['button_link']);
        $this->assertFalse($cta['is_active']);
        $this->assertContains(StarterSite::LINKED_PAGE_HINT, array_column($cta['placeholders'], 'hint'));

        // The hero's link to a live page is kept.
        $this->assertSame('/contact', $plan->section('home/hero')['content']['button_link']);
    }

    #[Test]
    public function the_admissions_form_is_a_reference_the_writer_resolves_and_is_never_published_unread(): void
    {
        $plan = StarterSite::plan($this->org('school'), 'school.essentials', StarterFacts::fromArray(self::FACTS));
        $form = $plan->section('admissions/admissions_form');

        $this->assertNull($form['content']['form_id'], 'a plan has no form id to give until the organisation exists');
        $this->assertSame([['field' => 'form_id', 'form_template' => 'admissions-interest']], $form['refs']);
        $this->assertFalse($form['is_active']);
    }

    #[Test]
    public function a_preset_for_another_vertical_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StarterSite::plan($this->org('school'), 'masjid.classic', StarterFacts::fromArray(self::FACTS));
    }

    /** @return list<string> every section type the plan writes */
    private function types(StarterPlan $plan): array
    {
        $types = [];

        foreach ($plan->pages as $page) {
            $types = array_merge($types, array_column($page['sections'], 'section_type'));
        }

        return array_values(array_unique($types));
    }

    /** @param array<string, bool> $overrides */
    private function org(string $orgType, array $overrides = []): Masjid
    {
        $org = new Masjid(['name' => self::FACTS['name'], 'org_type' => $orgType, 'crm_enabled' => true]);
        $org->forceFill(['capability_overrides' => $overrides === [] ? null : $overrides]);

        return $org;
    }
}
