<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\Masjid;
use App\Models\ReportCard;
use App\Models\ReportCardMark;
use App\Services\Schools\ReportCardPdfService;
use App\Support\PerformanceLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The report card as a file a family keeps.
 *
 * ---------------------------------------------------------------------------
 * WHY THERE IS AN ARABIC CASE HERE AND NOWHERE ELSE IN THE SUITE
 * ---------------------------------------------------------------------------
 *
 * Every student fixture in this repository is Latin — DemoSchool uses "Yusuf
 * Karim" and "Aisha Ansari" — and every report-card string the school itself
 * supplies is ASCII English. So the one thing that decided which PDF engine
 * this feature uses had no coverage at all, and a renderer that mangles Arabic
 * would have gone out green.
 *
 * dompdf, which this application already uses for donation receipts, has no
 * bidi and no shaping. Its bundled font DOES carry Arabic glyphs, so it does
 * not error and does not print boxes — it prints legible-looking Arabic with
 * the words in reverse order. On the one document in this system that a family
 * keeps and may produce years later, that is the worst available failure, which
 * is why this document alone renders through mpdf.
 */
class ReportCardPdfTest extends TestCase
{
    use RefreshDatabase;

    private Masjid $school;

    private Group $class;

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

        $this->school = Masjid::create([
            'name' => 'Al-Razi School',
            'email' => 'school-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);

        $this->class = Group::factory()->create([
            'masjid_id' => $this->school->id,
            'kind' => Group::KIND_CLASS,
            'name' => 'Grade 3',
        ]);
    }

    #[Test]
    public function a_published_card_renders_as_a_pdf(): void
    {
        [$card, $membership] = $this->card('Amina', 'Ahmed');

        $bytes = app(ReportCardPdfService::class)->render($card, $membership);

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(5000, strlen($bytes), 'A card with fourteen criteria should not be a stub.');
    }

    /**
     * The case the engine was chosen for. This does not assert glyph SHAPING —
     * no assertion on compressed PDF bytes can — but it pins that an Arabic
     * name renders at all, through the engine that shapes it, rather than
     * throwing or silently falling back.
     */
    #[Test]
    public function an_arabic_name_renders_rather_than_throwing(): void
    {
        [$card, $membership] = $this->card('أحمد', 'بن علي');

        $bytes = app(ReportCardPdfService::class)->render($card, $membership);

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(5000, strlen($bytes));
    }

    /** An Arabic teacher comment is the other unconstrained field. */
    #[Test]
    public function an_arabic_teacher_comment_renders(): void
    {
        [$card, $membership] = $this->card('Amina', 'Ahmed');
        $card->forceFill(['teacher_comment' => 'ما شاء الله — تقدم ممتاز هذا الفصل.'])->save();

        $bytes = app(ReportCardPdfService::class)->render($card->refresh(), $membership);

        $this->assertStringStartsWith('%PDF-', $bytes);
    }

    /** The name comes first: it is what a parent scans a folder for. */
    #[Test]
    public function the_filename_leads_with_the_child(): void
    {
        [$card, $membership] = $this->card('Amina', 'Ahmed');

        $this->assertSame(
            'amina-ahmed-report-card-quarter-2-2026-2027.pdf',
            app(ReportCardPdfService::class)->filename($card, $membership),
        );
    }

    /** A name that slugs to nothing must still produce a usable file. */
    #[Test]
    public function a_filename_is_never_empty(): void
    {
        [$card, $membership] = $this->card('عمر', 'خالد');

        $name = app(ReportCardPdfService::class)->filename($card, $membership);

        $this->assertStringEndsWith('.pdf', $name);
        $this->assertNotSame('.pdf', $name);
    }

    // ----------------------------------------------------------- the template

    /**
     * `publish()` always writes integers, so a school that keeps no register
     * gets 0/0/0 rather than nulls. Printing "Present 0 · Absent 0" would state
     * that the child attended nothing — a different and much worse claim than
     * saying nothing at all.
     */
    #[Test]
    public function a_card_with_no_register_prints_no_attendance_claim(): void
    {
        $html = $this->renderTemplate(['attendance' => null]);

        $this->assertStringNotContainsString('Attendance', $html);
        $this->assertStringNotContainsString('Present 0', $html);
    }

    #[Test]
    public function a_real_register_prints_and_says_late_is_included(): void
    {
        $html = $this->renderTemplate(['attendance' => ['present' => 31, 'absent' => 2, 'late' => 3]]);

        $this->assertStringContainsString('Present 31', $html);
        $this->assertStringContainsString('includes 3 late', $html);
        $this->assertStringContainsString('Absent 2', $html);
    }

    /** A printed card has no console and nothing to click. The key must be on it. */
    #[Test]
    public function the_key_is_printed_on_the_document(): void
    {
        $html = $this->renderTemplate();

        foreach (PerformanceLevel::key() as $level) {
            $this->assertStringContainsString($level['label'], $html);
            $this->assertStringContainsString($level['description'], $html);
        }
    }

    #[Test]
    public function an_unassessed_criterion_prints_as_not_assessed_and_never_as_a_zero(): void
    {
        $html = $this->renderTemplate([
            'subjects' => [[
                'subject' => 'Qur\'an',
                'criteria' => [[
                    'criterion' => 'Memorisation',
                    'level' => null,
                    'levelLabel' => 'Not assessed',
                    'comment' => null,
                ]],
            ]],
        ]);

        $this->assertStringContainsString('Not assessed', $html);
        $this->assertStringNotContainsString('0 &middot;', $html);
        $this->assertStringNotContainsString('>0<', $html);
    }

    /** A teacher's per-criterion note reaches the paper, not only the card comment. */
    #[Test]
    public function per_criterion_notes_are_printed(): void
    {
        $html = $this->renderTemplate([
            'subjects' => [[
                'subject' => 'Qur\'an',
                'criteria' => [[
                    'criterion' => 'Recitation',
                    'level' => 4,
                    'levelLabel' => 'Exceeds Expectations',
                    'comment' => 'Clear makhraj; reads with confidence.',
                ]],
            ]],
        ]);

        $this->assertStringContainsString('Clear makhraj; reads with confidence.', $html);
    }

    /** Line breaks a teacher typed are part of what they wrote. */
    #[Test]
    public function the_teacher_comment_keeps_its_paragraphs(): void
    {
        $html = $this->renderTemplate(['teacherComment' => "First line.\n\nSecond line."]);

        $this->assertStringContainsString('<br />', $html);
    }

    /** A comment is escaped before nl2br, so markup in it cannot reach the page. */
    #[Test]
    public function a_comment_cannot_inject_markup(): void
    {
        $html = $this->renderTemplate(['teacherComment' => '<b>bold</b>']);

        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
    }

    // -------------------------------------------------------------- internals

    /** @return array{0: ReportCard, 1: GroupMembership} */
    private function card(string $first, string $last): array
    {
        $child = Contact::factory()->create([
            'masjid_id' => $this->school->id,
            'first_name' => $first,
            'last_name' => $last,
        ]);

        $membership = GroupMembership::create([
            'masjid_id' => $this->school->id,
            'group_id' => $this->class->id,
            'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);
        $membership->forceFill(['grade_label' => '3rd'])->save();

        $card = ReportCard::create([
            'masjid_id' => $this->school->id,
            'group_id' => $this->class->id,
            'group_membership_id' => $membership->id,
            'type' => ReportCard::TYPE_REPORT_CARD,
            'school_year' => '2026-2027',
            'term' => 2,
            'grade_label' => '3rd',
            'teacher_comment' => 'A good quarter.',
            'days_present' => 31,
            'days_absent' => 2,
            'days_late' => 3,
        ]);

        foreach ([
            [ReportCardMark::KIND_ACADEMIC, 'Qur\'an', 'Recitation', 4, 0],
            [ReportCardMark::KIND_ACADEMIC, 'Qur\'an', 'Memorisation', null, 1],
            [ReportCardMark::KIND_BEHAVIOUR, 'Learning Behaviours', 'Works well with others', 3, 2],
        ] as [$kind, $subject, $criterion, $level, $position]) {
            ReportCardMark::create([
                'masjid_id' => $this->school->id,
                'report_card_id' => $card->id,
                'kind' => $kind,
                'subject' => $subject,
                'criterion' => $criterion,
                'level' => $level,
                'position' => $position,
            ]);
        }

        $card->forceFill(['published_at' => now()])->save();

        return [$card->refresh(), $membership->fresh()->load('contact')];
    }

    /**
     * Render the Blade directly.
     *
     * The template is where the document's judgements live — what to omit, what
     * to spell out — and asserting them on the HTML is possible where asserting
     * them on compressed PDF bytes is not.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function renderTemplate(array $overrides = []): string
    {
        return view('pdf.report-card', array_merge([
            'schoolName' => 'Al-Razi School',
            'logo' => null,
            'palette' => ['color' => ['primary' => '#286C56', 'onPrimary' => '#FFFFFF']],
            'childName' => 'Amina Ahmed',
            'gradeLabel' => '3rd',
            'typeLabel' => 'Report Card',
            'periodLabel' => 'Quarter 2, 2026-2027',
            'publishedAt' => 'September 10, 2026',
            'attendance' => ['present' => 31, 'absent' => 2, 'late' => 3],
            'subjects' => [],
            'behaviours' => [],
            'teacherComment' => null,
            'levels' => PerformanceLevel::key(),
        ], $overrides))->render();
    }
}
