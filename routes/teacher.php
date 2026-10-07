<?php

use App\Http\Controllers\AdminDashboard\ArabicLettersController;
use App\Http\Controllers\AdminDashboard\AuthController;
use App\Http\Controllers\AdminDashboard\BehaviorAwardsController;
use App\Http\Controllers\AdminDashboard\BehaviorSkillsController;
use App\Http\Controllers\AdminDashboard\ContactAvatarController;
use App\Http\Controllers\AdminDashboard\GroupMessageSchedulesController;
use App\Http\Controllers\AdminDashboard\GroupPostsController;
use App\Http\Controllers\AdminDashboard\GroupThreadsController;
use App\Http\Controllers\AdminDashboard\HifzEntriesController;
use App\Http\Controllers\Teacher\AttendanceController;
use App\Http\Controllers\Teacher\ClassStoreController;
use App\Http\Controllers\Teacher\CurriculumController;
use App\Http\Controllers\Teacher\GradebookController;
use App\Http\Controllers\Teacher\GroupsController as TeacherGroupsController;
use App\Http\Controllers\Teacher\LessonPlanController;
use App\Http\Controllers\Teacher\PointsPeriodController;
use App\Http\Controllers\Teacher\ReportCardController;
use App\Http\Controllers\Teacher\ResourcesController;
use App\Http\Controllers\Teacher\SchoolController;
use App\Http\Controllers\Teacher\StudentAvatarController;
use App\Http\Middleware\EchoResolvedTenant;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| The teacher realm
|------------------------------------------------------------------------------
|
| A school-teacher staff login (users.type='Teacher') scoped to the classes they
| lead. In its OWN file, never a sibling inside routes/admin.php, for the reason
| family.php is: admin.php has exactly one `auth:sanctum` group and it always
| carries `admin`, which REJECTS a Teacher. This realm rides `auth:sanctum` too
| but with the `teacher` gate (not `admin`) and a Teacher-aware `tenant` branch.
|
| Two layers of authority, and they are different questions:
|   `teacher`       — is the caller a Teacher at all? (whole-realm gate)
|   `teacher.leads` — do they lead THIS {group_id}? (per-class gate)
|
| It deliberately does NOT carry `permission:` (a teacher holds none — their
| authority is per-class via group_staff/GroupAudience, not a masjid-wide grant)
| and NOT `crm` (a school's class tools must not depend on the giving/CRM flag).
|
| The reused admin controllers (ArabicLetters/BehaviorAwards/Hifz/GroupPosts/
| GroupThreads) are mounted UNCHANGED: four of them authorize through
| GroupAudience, which now grants a group_staff teacher leader standing, and the
| fifth (ArabicLetters) is fenced by `teacher.leads`. The {masjid_id} segment
| matches the family route shape and lines the reused controllers' positional
| ($masjid_id, $group_id, …) signatures up; the tenant is still bound from the
| principal, and ResolveMasjidTenant verifies the URL id against the teacher's
| own membership (a foreign id 403s).
|
| The ONLY writes this realm exposes: class-story create/update/delete (which
| also schedules and reschedules: `send_at`), scheduled NEW conversations
| (create/edit/cancel; T-002.4), thread
| REPLY (storeMessage, and updateMessage on a message the teacher wrote — never
| store/close/reopen/destroy), arabic mark +
| stage, behaviour award store/destroy, hifz store/destroy, and a student avatar
| override. Roster mutation, contacts, donations, funds, properties and the
| thread lifecycle are all absent by construction.
*/

Route::prefix('teacher')
    ->middleware(['auth:sanctum', 'teacher'])
    ->group(function () {

        // Session endpoints — no tenant needed, and reusing AuthController's
        // Teacher branch (which attaches the teacher's school as user.masjid).
        // The admin /user route is `admin`-gated and rejects a Teacher, which is
        // exactly why the teacher shell needs its own.
        Route::get('/user', [AuthController::class, 'user']);
        Route::post('/logout', [AuthController::class, 'logout']);

        // Everything below is bound to the teacher's school.
        //
        // STANDING RULE: every route inside this group carries `{masjid_id}`, and a
        // new one must too. A teacher may belong to several schools, and on a
        // `tenant` route that names none the resolver cannot choose between them:
        // it answers 403 "several memberships and no masjid in the route" for every
        // multi-school teacher, silently, while every single-school teacher (and
        // every test written for one) keeps passing. `/user` and `/logout` above
        // are outside `tenant` for exactly this reason. A route-list test pins it
        // (TeacherMultiSchoolTest). TenantResolver::UNSCOPED_ADMIN_ROUTES is
        // admin-only and must stay that way.
        //
        // EchoResolvedTenant is FIRST so it wraps `tenant` and stamps `X-Tenant-Id`
        // with the school the server actually bound. The shell compares it with the
        // school it believes it selected and stops on a mismatch, instead of
        // painting one school's name over another's rows.
        Route::prefix('masjids/{masjid_id}')
            ->whereNumber('masjid_id')
            ->middleware([EchoResolvedTenant::class, 'tenant'])
            ->group(function () {

                // The school this request is bound to, for the shell's header.
                // `/user` names the DEFAULT membership; this names the SELECTED
                // one, verified by `tenant` for this very request.
                Route::get('/school', [SchoolController::class, 'show']);

                // The teacher's own classes (names-only), and the behaviour-skill
                // vocabulary needed to fill the award dropdown. Neither is
                // group-scoped, so `teacher.leads` does not apply.
                Route::get('/groups', [TeacherGroupsController::class, 'index']);
                Route::get('/avatars', [ContactAvatarController::class, 'catalogue']);
                Route::get('/behavior-skills', [BehaviorSkillsController::class, 'index']);

                // A teacher ADDS to the vocabulary. The realm's first
                // school-level create, and the exception is deliberate: what a
                // school chooses to notice about a child is a pedagogical
                // decision, and the person holding it is the teacher in the room,
                // not whoever has `manage contacts`. Al-Razi ran a whole term on
                // a single skill because adding one meant asking the office.
                //
                // SCHOOL-WIDE, not class-scoped — `behavior_skills` has no
                // group_id and every teacher's picker reads the same list. That
                // is a real consequence and the screen says so out loud rather
                // than letting a teacher discover it from a colleague's dropdown.
                // Editing and retiring a skill stay with the office, because
                // those touch a vocabulary other people are already using.
                Route::post('/behavior-skills', [BehaviorSkillsController::class, 'store']);
                // Reference data, so the ḥifẓ form can offer sūrahs BY NAME and
                // bound its own āyah inputs. A GET: the realm's write list is
                // unchanged — a GET adds no write verb to the counted list.
                Route::get('/quran-surahs', [HifzEntriesController::class, 'surahs']);
                // The school's own pacing guide, so a lesson plan can offer its
                // standards instead of asking a teacher to retype them. Also a
                // GET, so the counted write list is untouched.
                Route::get('/curriculum', [CurriculumController::class, 'index']);
                // Type-to-find over the same guide, for the plan's Standard field.
                // Throttled because it is called per keystroke; the SPA waits
                // 200 ms between keys, so a teacher typing never reaches it.
                Route::get('/curriculum/standards', [CurriculumController::class, 'standards'])
                    ->middleware('throttle:curriculum-standards');
                // The school calendar: which days the school meets, which are
                // closed and why. A GET, and not capability-gated — a gate decides
                // what is offered, never what is readable; a school with no
                // calendar answers `years: []`.
                Route::get('/school-calendar', [\App\Http\Controllers\Teacher\SchoolCalendarController::class, 'index']);

                // Per-class: every route below is fenced to a class the teacher
                // LEADS by `teacher.leads`.
                Route::prefix('groups/{group_id}')
                    ->whereNumber('group_id')
                    ->middleware('teacher.leads')
                    ->group(function () {

                        Route::get('/', [TeacherGroupsController::class, 'show']);

                        // ARABIC — the letters tracker and the daily Arabic notes.
                        // `teacher.teaches:arabic` refuses a teacher whose
                        // assignment to this class does not include Arabic
                        // (owner, 2026-09-21: "only access specific to the
                        // subject they're teaching"). An assignment with no
                        // subjects recorded teaches everything, as before.
                        Route::middleware('teacher.teaches:arabic')->group(function () {
                        // Arabic letters (reused; fenced by teacher.leads).
                        Route::get('/letters', [ArabicLettersController::class, 'index']);
                        Route::put('/letters/stage', [ArabicLettersController::class, 'setStage']);
                        Route::get('/members/{membership_id}/letters', [ArabicLettersController::class, 'show']);
                        Route::put('/members/{membership_id}/letters', [ArabicLettersController::class, 'mark']);
                        // Every drill at the class's stage mastered in one go,
                        // for a child who already knows them (BISS teachers,
                        // 2026-09-21). The same cells the single mark writes;
                        // already-mastered drills and notes are left alone. PUT
                        // because a second call changes nothing.
                        Route::put('/members/{membership_id}/letters/master-all', [ArabicLettersController::class, 'masterAll']);

                        // The daily Arabic note: one child, one day, the
                        // teacher's own words. Per-student by the owner's
                        // decision (2026-09-16), and the ONLY new write this
                        // feature adds to the realm — the per-drill note and the
                        // hifz note both ride endpoints a teacher already has,
                        // so neither widens what a teacher may do.
                        Route::get('/members/{membership_id}/arabic-notes', [ArabicLettersController::class, 'dailyNotes']);
                        Route::put('/members/{membership_id}/arabic-notes', [ArabicLettersController::class, 'saveDailyNote']);
                        Route::delete('/members/{membership_id}/arabic-notes/{note_id}', [ArabicLettersController::class, 'deleteDailyNote']);
                        }); // teacher.teaches:arabic

                        // Behaviour points (reused; GroupAudience grants leader standing).
                        Route::get('/awards', [BehaviorAwardsController::class, 'index']);
                        // Every current student's running total, in roster
                        // order — the teacher's overview groups.md allows, never
                        // a ranking. Leaders only. A GET: the write list is unchanged.
                        Route::get('/awards/totals', [BehaviorAwardsController::class, 'totals']);
                        Route::post('/awards', [BehaviorAwardsController::class, 'store']);
                        Route::delete('/awards/{award_id}', [BehaviorAwardsController::class, 'destroy']);
                        Route::get('/members/{membership_id}/awards', [BehaviorAwardsController::class, 'forMember']);
                        Route::get('/members/{membership_id}/awards/summary', [BehaviorAwardsController::class, 'summary']);
                        // How this class's points READ: one running total, or a
                        // week at a time (T-003.2). A view choice that changes no
                        // award; it applies to every teacher of the class. The
                        // realm's +1 write verb for the points reset.
                        Route::put('/points-period', [PointsPeriodController::class, 'update']);

                        // THE CLASS STORE (T-003.4, W6): Manara Bucks a week of positive points
                        // turns into, spent on prizes the class's teachers give. Every route
                        // here sits behind `capability:class_store`, OFF for every organisation
                        // until a SuperAdmin decides, so a school without it answers 403 to all
                        // of them and is otherwise unchanged. Reads (bucks, prizes, a student's
                        // history, the paper hand-out) are GETs and add no write verb; the five
                        // writes are the realm's +5. EVERY balance is read through GroupAudience.
                        // There is NO route that edits or deletes a ledger entry: a correction
                        // is a reversal, a new row. Cash-out to paper is built and OFF behind
                        // the school's `paper_bucks_enabled` (refused in ClassStore as well).
                        Route::middleware('capability:class_store')->group(function () {
                            Route::get('/bucks', [ClassStoreController::class, 'index']);
                            Route::get('/bucks/handout', [ClassStoreController::class, 'handout']);
                            Route::get('/prizes', [ClassStoreController::class, 'prizes']);
                            Route::get('/members/{membership_id}/bucks', [ClassStoreController::class, 'forMember']);
                            // Write 1: this class's OWN prize (the school-wide list is the office's).
                            Route::post('/prizes', [ClassStoreController::class, 'storePrize']);
                            // Write 2: edit or retire this class's own prize.
                            Route::put('/prizes/{prize_id}', [ClassStoreController::class, 'updatePrize']);
                            // Write 3: give a student a prize (locks the student and the prize).
                            Route::post('/members/{membership_id}/prizes/redeem', [ClassStoreController::class, 'redeem']);
                            // Write 4: pay bucks out as paper notes (built, OFF).
                            Route::post('/members/{membership_id}/prizes/cash-out', [ClassStoreController::class, 'cashOut']);
                            // Write 5: correct a prize given or Bucks paid out, as a new entry.
                            Route::post('/prize-entries/{entry_id}/reverse', [ClassStoreController::class, 'reverse']);
                        });

                        // The class register. The teacher realm's OWN controller,
                        // not a reused admin one: taking a register is a teacher
                        // verb, and the admin console has no equivalent screen.
                        // One PUT writes the whole class for one day — see
                        // SaveAttendanceRequest for why it is not twelve calls.
                        Route::get('/attendance', [AttendanceController::class, 'index']);
                        Route::put('/attendance', [AttendanceController::class, 'save']);
                        Route::get('/members/{membership_id}/attendance', [AttendanceController::class, 'forMember']);

                        // Lesson plans: one per class, per day, per subject.
                        // The day view addresses a plan by its id — POST refuses
                        // a subject the day already has. The two id-less writes
                        // are the (day, subject) address: an upsert that "copy to
                        // the rest of this week" uses, and the address an older
                        // screen still open in a tab knows. See the controller.
                        Route::get('/lesson-plans', [LessonPlanController::class, 'index']);
                        Route::post('/lesson-plans', [LessonPlanController::class, 'store']);
                        Route::put('/lesson-plans', [LessonPlanController::class, 'save']);
                        Route::delete('/lesson-plans', [LessonPlanController::class, 'destroy']);
                        Route::put('/lesson-plans/{plan_id}', [LessonPlanController::class, 'update'])->whereNumber('plan_id');
                        Route::delete('/lesson-plans/{plan_id}', [LessonPlanController::class, 'destroyPlan'])->whereNumber('plan_id');

                        // The gradebook. The first CLASS-LEVEL create and delete
                        // this realm allows — still not roster mutation: a
                        // teacher says what the class was asked to do, never who
                        // belongs in the room.
                        Route::get('/assignments', [GradebookController::class, 'index']);
                        Route::post('/assignments', [GradebookController::class, 'store']);
                        Route::get('/assignments/{assignment_id}', [GradebookController::class, 'show']);
                        Route::put('/assignments/{assignment_id}', [GradebookController::class, 'update']);
                        Route::delete('/assignments/{assignment_id}', [GradebookController::class, 'destroy']);
                        Route::put('/assignments/{assignment_id}/scores', [GradebookController::class, 'saveScores']);
                        // How much each TYPE of work counts for in THIS class
                        // (T-001.2), all five types or clear them. A class-level
                        // setting that moves every subject's average, so it is for
                        // a teacher of ALL the subjects only: a teacher limited to
                        // some is refused, setting or clearing (review F5): see
                        // GradebookController::saveWeights. Registered ONCE: the
                        // router keeps one route per verb and URI, so a second
                        // copy is invisible to every test but the source scan in
                        // TeacherRoutesRegisteredOnceTest.
                        Route::put('/grade-weights', [GradebookController::class, 'saveWeights']);
                        Route::get('/members/{membership_id}/grades', [GradebookController::class, 'forMember']);

                        // Report cards and progress reports. Both are the same
                        // document at different points in the quarter, so they
                        // share these routes and differ by `?type=`.
                        //
                        // `show` may CREATE — an empty card with no judgements in
                        // it — because making a teacher press "start" before they
                        // can fill one in is a step that exists only to satisfy a
                        // rule about verbs. It is idempotent and never resets a
                        // mark. `index` deliberately does not: a class list is a
                        // read, and creating twelve draft documents because
                        // somebody opened a tab makes the audit trail meaningless.
                        //
                        // Publishing is its own verb, separate from saving, because
                        // it is the moment a document becomes visible to a family.
                        Route::get('/report-cards', [ReportCardController::class, 'index']);
                        Route::get('/members/{membership_id}/report-card', [ReportCardController::class, 'show']);

                        // The printable copy. A teacher needs this for the
                        // family without a printer at home, and for the paper
                        // file the office keeps.
                        Route::get('/members/{membership_id}/report-card/pdf', [ReportCardController::class, 'pdf']);
                        Route::put('/members/{membership_id}/report-card', [ReportCardController::class, 'save']);
                        Route::post('/members/{membership_id}/report-card/publish', [ReportCardController::class, 'publish']);
                        Route::delete('/members/{membership_id}/report-card/publish', [ReportCardController::class, 'unpublish']);

                        // Class resources — the realm's first file upload, and
                        // its first byte-streaming download.
                        Route::get('/resources', [ResourcesController::class, 'index']);
                        Route::post('/resources', [ResourcesController::class, 'store']);
                        Route::put('/resources/{resource_id}', [ResourcesController::class, 'update']);
                        Route::delete('/resources/{resource_id}', [ResourcesController::class, 'destroy']);
                        Route::get('/resources/{resource_id}/download', [ResourcesController::class, 'download']);

                        // Ḥifẓ (reused).
                        // QUR'AN — hifdh, refused to a teacher who does not teach
                        // Qur'an in this class (owner, 2026-09-21).
                        Route::middleware('teacher.teaches:quran')->group(function () {
                        Route::get('/hifz', [HifzEntriesController::class, 'index']);
                        Route::post('/hifz', [HifzEntriesController::class, 'store']);
                        // The NOTE alone (owner, 2026-10-07). What was heard is
                        // still corrected by striking and recording again.
                        Route::put('/hifz/{entry_id}', [HifzEntriesController::class, 'updateNote']);
                        // A recorded line corrected in place, the old line kept
                        // as a struck copy (owner, 2026-10-07). Never the student.
                        Route::post('/hifz/{entry_id}/correct', [HifzEntriesController::class, 'correct']);
                        Route::delete('/hifz/{entry_id}', [HifzEntriesController::class, 'destroy']);
                        Route::get('/members/{membership_id}/hifz', [HifzEntriesController::class, 'forMember']);
                        Route::get('/members/{membership_id}/hifz/progress', [HifzEntriesController::class, 'progress']);
                        }); // teacher.teaches:quran

                        // Class story (reused; GroupAudience). No lifecycle beyond CRUD.
                        Route::get('/posts', [GroupPostsController::class, 'index']);
                        Route::post('/posts', [GroupPostsController::class, 'store']);
                        Route::get('/posts/{post_id}', [GroupPostsController::class, 'show']);
                        Route::get('/posts/{post_id}/attachments/{attachment_id}', [GroupPostsController::class, 'downloadAttachment']);
                        // Playback ticket for a class-story VIDEO — the teacher
                        // realm's copy of the admin route. POST, so the minted
                        // URL never lands in a history entry.
                        Route::post('/posts/{post_id}/attachments/{attachment_id}/playback', [GroupPostsController::class, 'playbackTicket']);
                        Route::put('/posts/{post_id}', [GroupPostsController::class, 'update']);
                        Route::delete('/posts/{post_id}', [GroupPostsController::class, 'destroy']);
                        // 🤲 👍 💯 ❓ on a story post (2026-09-29): the admin
                        // controller again. `teacher.leads` has proven the caller
                        // leads THIS class; the controller then asks the FEED read
                        // gate. Two idempotent verbs; no notification at the tap.
                        Route::put('/posts/{post_id}/reactions/{reaction}', [GroupPostsController::class, 'react']);
                        Route::delete('/posts/{post_id}/reactions/{reaction}', [GroupPostsController::class, 'unreact']);

                        // Messages — READ + REPLY ONLY. storeMessage is the single
                        // write; store/close/reopen/destroy are deliberately absent
                        // (a teacher joins the parent conversation, they do not run
                        // its lifecycle).
                        Route::get('/threads', [GroupThreadsController::class, 'index']);
                        // OPEN a conversation. Until this existed no teacher and
                        // no parent could start one — only the office could, from
                        // the admin console, which made "message a family" a thing
                        // a teacher had to request rather than do.
                        //
                        // Reused verbatim from the admin realm: store() takes its
                        // author from the authenticated user, and refuses an
                        // `about_membership_id` that is not a participant of THIS
                        // group. `teacher.leads` has already proven the caller
                        // leads the class, which is the gate the admin route got
                        // from `permission:manage contacts`.
                        //
                        // Both scopes are allowed. A group-scoped thread reaches
                        // the same audience as the class story, which a teacher
                        // can already post to — so it grants no new reach.
                        Route::post('/threads', [GroupThreadsController::class, 'store']);
                        Route::get('/threads/{thread_id}', [GroupThreadsController::class, 'show']);
                        // storeMessage (and store, above) accept photos as well as
                        // text — the same private-disk pipeline as the class
                        // story. The download is a GET, so the counted write list
                        // does not change.
                        Route::post('/threads/{thread_id}/messages', [GroupThreadsController::class, 'storeMessage']);
                        // Change the WORDS of a message YOU sent (W7, 2026-10-01): the
                        // same controller method as the admin realm, `teacher.leads`
                        // then the read gate, the author gate and "not closed". One new
                        // write verb. The office's `edits` history has no teacher twin.
                        Route::put('/threads/{thread_id}/messages/{message_id}', [GroupThreadsController::class, 'updateMessage']);
                        // 🤲 👍 💯 ❓ (2026-09-21) — the same controller and the
                        // same gate as the reply above: `teacher.leads`, then
                        // mayReceiveThread() and "not closed". Add and remove are
                        // two idempotent verbs, not one toggle a double-tap undoes.
                        Route::put('/threads/{thread_id}/messages/{message_id}/reactions/{reaction}', [GroupThreadsController::class, 'react']);
                        Route::delete('/threads/{thread_id}/messages/{message_id}/reactions/{reaction}', [GroupThreadsController::class, 'unreact']);
                        Route::get('/threads/{thread_id}/messages/{message_id}/attachments/{attachment_id}', [GroupThreadsController::class, 'downloadAttachment']);
                        Route::post('/threads/{thread_id}/messages/{message_id}/attachments/{attachment_id}/playback', [GroupThreadsController::class, 'playbackTicket']);

                        // "Send later" for a NEW conversation (T-002.4, 2026-09-29), and
                        // the Scheduled list beside it. The words wait in
                        // group_message_schedules and become a real thread at their time
                        // through the same writer `POST /threads` uses; until then no
                        // reader, receipt or family endpoint can see them. `teacher.leads`
                        // has proven the caller leads the class (co-teachers see each
                        // other's items); the controller lets only the AUTHOR (here, the
                        // office is not a teacher) edit, send now (PUT with send_now) or
                        // cancel. +3 write verbs; the GET adds none. Story scheduling has
                        // no verb of its own: it is `send_at` on the post routes above.
                        Route::get('/scheduled-messages', [GroupMessageSchedulesController::class, 'index']);
                        Route::post('/scheduled-messages', [GroupMessageSchedulesController::class, 'store']);
                        Route::put('/scheduled-messages/{schedule_id}', [GroupMessageSchedulesController::class, 'update'])->whereNumber('schedule_id');
                        Route::delete('/scheduled-messages/{schedule_id}', [GroupMessageSchedulesController::class, 'destroy'])->whereNumber('schedule_id');

                        // Student avatar OVERRIDE — group-scoped (solves the
                        // ContactAvatarController {contact_id} reverse-lookup: the
                        // membership is resolved within this led class).
                        Route::put('/members/{membership_id}/avatar', [StudentAvatarController::class, 'update']);
                        Route::delete('/members/{membership_id}/avatar/override', [StudentAvatarController::class, 'destroyOverride']);
                    });
            });
    });
