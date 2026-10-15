<?php

namespace Tests\Feature;

use App\Models\BehaviorSkill;
use App\Models\ClassSubject;
use App\Models\GroupStaff;
use App\Models\MasjidPointsSetting;
use App\Models\MealMenu;
use App\Models\SchoolSubject;
use App\Models\User;
use App\Support\ClassSubjectInitializer;
use App\Support\StaffArea;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OneLoginWorld;
use Tests\Support\TeacherRealmWorld;
use Tests\TestCase;

/**
 * Inside an area she is what that area's people are: no more, and no less.
 *
 * About twenty places in shared code read `$user->type` to tell a teacher from the
 * office, and every one of them treats "not a Teacher" as "the office": no subject
 * limits, no write fence, hidden subjects shown, office-only fields added. A lunch
 * login admitted to the teacher area and still read as `LunchStaff` would therefore
 * see and write MORE than a real teacher of the same class, and the mirror would be
 * shown the lunch board's administrator-only field. The acting kind
 * (User::getTypeAttribute) is what prevents it, at every such site at once.
 *
 * That is proved here by difference, not by listing the sites. A login created as a
 * Teacher and the lunch login an office made a teacher are given the SAME class with
 * the SAME subject limit, and every GET route of the teacher area must answer them
 * with the same JSON. A site that failed open, today's or one written tomorrow, is a
 * difference. The class holds a real row behind every id a teacher URL can name
 * (Tests\Support\TeacherRealmWorld), written by a third teacher so that neither of
 * the two is anything's author.
 */
class OneLoginTwoRolesParityTest extends TestCase
{
    use OneLoginWorld;
    use RefreshDatabase;

    /** A teacher from birth, with one role: the control. */
    private User $plain;

    /** Who wrote every fixture row. */
    private User $colleague;

    private TeacherRealmWorld $world;

    private ClassSubject $assigned;

    private ClassSubject $unassigned;

    private ClassSubject $hidden;

    protected function setUp(): void
    {
        parent::setUp();
        // Each view stamps last_read_at; a second boundary between two reads made the bodies differ (CI 2026-10-15).
        $this->freezeSecond();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-15 16:00:00 UTC'));

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        config(['tenancy.roles_per_organisation' => true]);

        Queue::fake();
        Storage::fake('local');
        Storage::fake((string) config('groups.media.disk', 'local'));
        Storage::fake((string) config('groups.resources.disk', 'local'));
        $this->artisan('guides:install', ['folder' => base_path('tests/fixtures/guides/d1-1234abcd')])->assertExitCode(0);

        $this->buildOneLoginWorld();

        $this->plain = $this->makeLogin('Teacher', 'Plain Teacher');
        $this->giveRole($this->plain, $this->school, 'teacher', true);
        $this->lead($this->plain, $this->class);

        $this->colleague = $this->makeLogin('Teacher', 'Colleague Teacher');
        $this->giveRole($this->colleague, $this->school, 'teacher', true);
        $this->lead($this->colleague, $this->class);

        // The school as a working school: class subjects, subject notes and marks, the
        // class store, the calendar and the timetable, so that the routes compared
        // below answer with real content and not with "switched off".
        $this->school->forceFill(['capability_overrides' => ['jummah_lunch' => true, 'class_store' => true]])->save();
        foreach (["Qur'an", 'Arabic', 'ELA', 'Science'] as $name) {
            SchoolSubject::withoutMasjidScope()->create(['masjid_id' => $this->school->id, 'name' => $name]);
        }
        ClassSubjectInitializer::run($this->school, false, true);
        $on = $this->school->fresh();
        $on->forceFill(['capability_overrides' => array_merge((array) $on->capability_overrides, [
            'student_sign_in' => true, 'class_subject_work' => true, 'school_calendar' => true, 'school_timetable' => true,
        ])])->save();
        MasjidPointsSetting::withoutMasjidScope()->create(['masjid_id' => $this->school->id, 'paper_bucks_enabled' => true]);

        $subjects = ClassSubject::withoutMasjidScope()->where('group_id', $this->class->id)->orderBy('position')->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(4, $subjects->count(), 'the class should have been given its subjects');
        [$this->assigned, $this->unassigned, $this->hidden] = [$subjects[0], $subjects[1], $subjects[2]];
        $this->hidden->forceFill(['hidden_at' => now()])->save();

        // THE SAME LIMIT for the control and for both of them: the one subject the
        // fixture's rows hang off. The colleague keeps every subject.
        foreach ([$this->plain, $this->shamina, $this->mirror] as $person) {
            GroupStaff::withoutMasjidScope()
                ->where('group_id', $this->class->id)->where('user_id', $person->id)->firstOrFail()
                ->update(['class_subject_ids' => [$this->assigned->id]]);
        }

        Auth::guard()->forgetUser();
        $skill = BehaviorSkill::factory()->create(['masjid_id' => $this->school->id, 'label' => 'MARK-P-SKILL']);
        $this->world = TeacherRealmWorld::seed($this->school->fresh(), $this->class->fresh(), $this->colleague, $skill, 'P', true);
        $this->assertSame($this->assigned->id, $this->world->subject->id, 'the fixture hangs its subject rows off the subject they are limited to');

        app(TenantContext::class)->forgetTenant();
    }

    #[Test]
    public function she_sees_what_a_teacher_of_the_same_class_sees(): void
    {
        $routes = $this->teacherGetRoutes();
        $this->assertGreaterThan(45, count($routes), 'The sweep found too few teacher GET routes; it is not looking at the right list.');

        $answered = 0;

        foreach ($routes as $uri) {
            $url = $this->urlFor($uri);

            $asTeacher = $this->answerFor($this->plain, $url);

            foreach (['shamina', 'mirror'] as $who) {
                $asHer = $this->answerFor($this->{$who}, $url);

                $this->assertSame($asTeacher['status'], $asHer['status'], "GET {$url}: {$who} got a different status from a teacher of the same class");
                $this->assertSame($asTeacher['body'], $asHer['body'], "GET {$url}: {$who} is shown something a teacher of the same class is not (or the reverse)");
            }

            $answered += $asTeacher['status'] === 200 ? 1 : 0;
        }

        // A sweep in which everything was refused alike would prove nothing.
        $this->assertGreaterThan(40, $answered, 'Too few of the compared routes answered 200 for the comparison to mean much.');
    }

    #[Test]
    public function she_is_fenced_to_her_subject_exactly_as_a_teacher_is(): void
    {
        $base = $this->teacherBase($this->school).'/groups/'.$this->class->id;

        foreach (['plain', 'shamina', 'mirror'] as $who) {
            $person = $who === 'plain' ? $this->plain : $this->{$who};
            $this->actingAsPerson($person);

            // The class's subjects, as a TEACHER is shown them: the one she is assigned,
            // not the others, not the hidden one, and none of the office's `meta`.
            $subjects = $this->getJson($base.'/subjects')->assertOk();
            $this->assertSame([$this->assigned->id], collect($subjects->json('data'))->pluck('id')->all(), "{$who}: only the assigned subject");
            $this->assertArrayNotHasKey('meta', $subjects->json(), "{$who}: the office's subject manager data is not a teacher's");
            $this->assertArrayNotHasKey('guide_subjects', $subjects->json('data.0'), "{$who}: an office-only field");

            // Her class payload says what she is limited to (for the office it is null:
            // everything) and lists the subjects a teacher is shown.
            $class = $this->getJson($base)->assertOk();
            $this->assertSame([$this->assigned->id], $class->json('data.my_class_subject_ids'), "{$who}: a limited teacher's class names the limit");
            $this->assertSame([$this->assigned->id], collect($class->json('data.class_subjects'))->pluck('id')->all(), "{$who}: and lists only that subject");

            // A subject she is not assigned, and a hidden one, do not open...
            $this->getJson($base.'/subjects/'.$this->unassigned->id)->assertNotFound();
            $this->getJson($base.'/subjects/'.$this->hidden->id)->assertNotFound();
            $this->assertContains($this->getJson($base.'/subjects/'.$this->unassigned->id.'/work')->getStatusCode(), [403, 404], "{$who}: the unassigned subject's work");

            // ...and cannot be written to. The write fence is in the model
            // (HasClassSubjectWork reads Auth::user()->type), so this is the request
            // that would have gone through for a login read as "not a Teacher".
            $before = \App\Models\SubjectPiece::withoutMasjidScope()->count();
            $refused = $this->postJson($base.'/subjects/'.$this->unassigned->id.'/pieces', ['title' => "{$who} should not be able to add this"]);
            $this->assertContains($refused->getStatusCode(), [403, 404, 422], "{$who}: a piece under a subject she does not teach was accepted");
            $this->assertSame($before, \App\Models\SubjectPiece::withoutMasjidScope()->count(), "{$who}: nothing was written");

            // The same fence one layer down, where no controller stands in front of
            // it: a lesson plan is saved through the MODEL's fence (HasClassSubjectWork
            // reads Auth::user()->type, and for anybody who is not a Teacher it sets no
            // limit at all). A plan for the subject she does not teach is refused; one
            // for her own subject is saved.
            $day = now()->addDays(10 + array_search($who, ['plain', 'shamina', 'mirror'], true))->toDateString();
            $plans = \App\Models\LessonPlan::withoutMasjidScope()->count();
            $refusedPlan = $this->postJson($base.'/lesson-plans', ['session_date' => $day, 'subject' => $this->unassigned->name, 'body' => 'Not hers to write.']);
            $this->assertContains($refusedPlan->getStatusCode(), [403, 404, 422], "{$who}: a lesson plan for a subject she does not teach was accepted");
            $this->assertSame($plans, \App\Models\LessonPlan::withoutMasjidScope()->count(), "{$who}: no plan was written");
            $this->postJson($base.'/lesson-plans', ['session_date' => $day, 'subject' => $this->assigned->name, 'body' => "A plan by {$who}."])->assertSuccessful();
            $this->assertSame($plans + 1, \App\Models\LessonPlan::withoutMasjidScope()->count());

            // The assigned subject does open, and takes a piece: the fence is a fence,
            // not a wall.
            $this->getJson($base.'/subjects/'.$this->assigned->id.'/work')->assertOk();
            $this->postJson($base.'/subjects/'.$this->assigned->id.'/pieces', ['title' => "A piece by {$who}"])->assertSuccessful();
        }
    }

    #[Test]
    public function the_model_write_fence_reads_the_kind_she_is_acting_as(): void
    {
        // Over HTTP the controller's fence refuses first, so the request above cannot
        // tell whether the MODEL's fence (HasClassSubjectWork::save, which asks
        // Auth::user()->type) would have. Here nothing stands in front of it: the
        // signed-in principal is the lunch login, admitted by the teacher gate, and a
        // plan is saved directly. Read as "not a Teacher" she would have no limit.
        foreach (['shamina', 'mirror'] as $who) {
            $principal = $this->actingAsPerson($who);
            $this->assertTrue(StaffArea::admits($principal, 'Teacher'));
            app(TenantContext::class)->set($this->school->id);

            $plan = fn (ClassSubject $subject) => \App\Models\LessonPlan::create([
                'masjid_id' => $this->school->id, 'group_id' => $this->class->id, 'author_user_id' => $principal->id,
                'session_date' => now()->addDays($who === 'shamina' ? 20 : 21)->toDateString(), 'subject' => $subject->name, 'body' => 'Saved straight through the model.',
            ]);

            try {
                $plan($this->unassigned);
                $this->fail("{$who}: the model saved a plan for a subject she does not teach.");
            } catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException $refused) {
                $this->addToAssertionCount(1);
            }

            $this->assertTrue($plan($this->assigned)->exists, "{$who}: her own subject saves");

            app(TenantContext::class)->forgetTenant();
        }
    }

    #[Test]
    public function he_sees_what_lunch_staff_see_on_the_board(): void
    {
        $lunchOnly = $this->makeLogin(User::TYPE_LUNCH_STAFF, 'Lunch Only');
        $this->giveRole($lunchOnly, $this->masjid, 'lunch-staff', true);
        app(TenantContext::class)->forgetTenant();

        $base = $this->lunchBase($this->masjid).'/jummah-lunch';

        // Every GET of the board, the same for all three.
        foreach (["{$base}/menus", "{$base}/menus/{$this->masjidMenu->id}", "{$base}/menus/{$this->masjidMenu->id}/orders"] as $url) {
            $asLunch = $this->answerFor($lunchOnly, $url);
            $this->assertSame(200, $asLunch['status'], $url);

            foreach (['shamina', 'mirror'] as $who) {
                $this->assertSame($asLunch, $this->answerFor($this->{$who}, $url), "GET {$url}: {$who} and a lunch-only login are answered differently");
            }
        }

        // `notify_emails` (who is emailed about new orders) is the board's one
        // administrator-only field: MealMenusController drops it from what a lunch
        // login saves. A teacher on the board who still read as a Teacher could set it.
        $kitchen = MealMenu::factory()->forMasjid($this->masjid)->catalogue()->create(['notify_emails' => 'office@org.example.test']);
        app(TenantContext::class)->forgetTenant();

        foreach ([$lunchOnly, $this->shamina, $this->mirror] as $person) {
            $this->actingAsPerson($person);

            $this->putJson("{$base}/menus/{$kitchen->id}", [
                'title' => 'Renamed by '.$person->name,
                'notify_emails' => 'elsewhere@outside.example.test',
            ])->assertOk();

            $saved = MealMenu::withoutMasjidScope()->findOrFail($kitchen->id);
            $this->assertSame('Renamed by '.$person->name, $saved->title, "{$person->name}: the rest of the save went through");
            $this->assertSame('office@org.example.test', $saved->notify_emails, "{$person->name} changed who the orders are emailed to");
        }
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<string> every GET route the teacher area serves under a school */
    private function teacherGetRoutes(): array
    {
        $found = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/teacher/masjids/{masjid_id}') && in_array('GET', $route->methods(), true)) {
                $found[] = $route->uri();
            }
        }

        sort($found);

        return $found;
    }

    private function urlFor(string $uri): string
    {
        $url = str_replace('{masjid_id}', (string) $this->school->id, $uri);

        $url = (string) preg_replace_callback(
            '/\{([a-z_]+)\}/',
            fn (array $m): string => rawurlencode($this->world->idFor($m[1], $uri)),
            $url
        );

        return '/'.$url.(str_ends_with($uri, '/schedule') ? '?week=2026-10-12' : '');
    }

    /**
     * One GET as this person, on a model read back from the table.
     *
     * @return array{status: int, body: mixed} the decoded JSON, or a hash of the bytes for a file
     */
    private function answerFor(User $person, string $url): array
    {
        $this->actingAsPerson($person);

        // Rolled back afterwards: opening a conversation records that it was read,
        // and the next person compared must be shown the page the first one was.
        DB::beginTransaction();

        try {
            $response = $this->get($url, ['Accept' => 'application/json']);
            $content = $response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse
                || $response->baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
                ? $response->streamedContent()
                : (string) $response->getContent();
        } finally {
            DB::rollBack();
        }

        $decoded = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $body = $decoded;
        } elseif (str_contains((string) $response->headers->get('Content-Type'), 'application/pdf')) {
            // A PDF is generated per request and carries its own creation time and
            // document id, so two prints of one page are never the same bytes.
            $body = 'a PDF';
        } else {
            $body = 'bytes:'.hash('sha256', $content);
        }

        return ['status' => $response->getStatusCode(), 'body' => $body];
    }
}
