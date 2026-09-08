<?php

use App\Http\Controllers\AdminDashboard\ArabicLettersController;
use App\Http\Controllers\AdminDashboard\AuthController;
use App\Http\Controllers\AdminDashboard\BehaviorAwardsController;
use App\Http\Controllers\AdminDashboard\BehaviorSkillsController;
use App\Http\Controllers\AdminDashboard\ContactAvatarController;
use App\Http\Controllers\AdminDashboard\GroupPostsController;
use App\Http\Controllers\AdminDashboard\GroupThreadsController;
use App\Http\Controllers\AdminDashboard\HifzEntriesController;
use App\Http\Controllers\Teacher\AttendanceController;
use App\Http\Controllers\Teacher\CurriculumController;
use App\Http\Controllers\Teacher\GradebookController;
use App\Http\Controllers\Teacher\GroupsController as TeacherGroupsController;
use App\Http\Controllers\Teacher\LessonPlanController;
use App\Http\Controllers\Teacher\ResourcesController;
use App\Http\Controllers\Teacher\StudentAvatarController;
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
| The ONLY writes this realm exposes: class-story create/update/delete, thread
| REPLY (storeMessage only — never store/close/reopen/destroy), arabic mark +
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
        Route::prefix('masjids/{masjid_id}')
            ->whereNumber('masjid_id')
            ->middleware('tenant')
            ->group(function () {

                // The teacher's own classes (names-only), and the behaviour-skill
                // vocabulary needed to fill the award dropdown. Neither is
                // group-scoped, so `teacher.leads` does not apply.
                Route::get('/groups', [TeacherGroupsController::class, 'index']);
                Route::get('/avatars', [ContactAvatarController::class, 'catalogue']);
                Route::get('/behavior-skills', [BehaviorSkillsController::class, 'index']);
                // Reference data, so the ḥifẓ form can offer sūrahs BY NAME and
                // bound its own āyah inputs. A GET: the realm's write list is
                // unchanged — a GET adds no write verb to the counted list.
                Route::get('/quran-surahs', [HifzEntriesController::class, 'surahs']);
                // The school's own pacing guide, so a lesson plan can offer its
                // standards instead of asking a teacher to retype them. Also a
                // GET, so the counted write list is untouched.
                Route::get('/curriculum', [CurriculumController::class, 'index']);

                // Per-class: every route below is fenced to a class the teacher
                // LEADS by `teacher.leads`.
                Route::prefix('groups/{group_id}')
                    ->whereNumber('group_id')
                    ->middleware('teacher.leads')
                    ->group(function () {

                        Route::get('/', [TeacherGroupsController::class, 'show']);

                        // Arabic letters (reused; fenced by teacher.leads).
                        Route::get('/letters', [ArabicLettersController::class, 'index']);
                        Route::put('/letters/stage', [ArabicLettersController::class, 'setStage']);
                        Route::get('/members/{membership_id}/letters', [ArabicLettersController::class, 'show']);
                        Route::put('/members/{membership_id}/letters', [ArabicLettersController::class, 'mark']);

                        // Behaviour points (reused; GroupAudience grants leader standing).
                        Route::get('/awards', [BehaviorAwardsController::class, 'index']);
                        Route::post('/awards', [BehaviorAwardsController::class, 'store']);
                        Route::delete('/awards/{award_id}', [BehaviorAwardsController::class, 'destroy']);
                        Route::get('/members/{membership_id}/awards', [BehaviorAwardsController::class, 'forMember']);
                        Route::get('/members/{membership_id}/awards/summary', [BehaviorAwardsController::class, 'summary']);

                        // The class register. The teacher realm's OWN controller,
                        // not a reused admin one: taking a register is a teacher
                        // verb, and the admin console has no equivalent screen.
                        // One PUT writes the whole class for one day — see
                        // SaveAttendanceRequest for why it is not twelve calls.
                        Route::get('/attendance', [AttendanceController::class, 'index']);
                        Route::put('/attendance', [AttendanceController::class, 'save']);
                        Route::get('/members/{membership_id}/attendance', [AttendanceController::class, 'forMember']);

                        // Lesson plans. Addressed by (class, date) — there is no
                        // {plan_id} anywhere, which is what the per-day unique
                        // index buys: saving is an upsert.
                        Route::get('/lesson-plans', [LessonPlanController::class, 'index']);
                        Route::put('/lesson-plans', [LessonPlanController::class, 'save']);
                        Route::delete('/lesson-plans', [LessonPlanController::class, 'destroy']);

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
                        Route::get('/members/{membership_id}/grades', [GradebookController::class, 'forMember']);

                        // Class resources — the realm's first file upload, and
                        // its first byte-streaming download.
                        Route::get('/resources', [ResourcesController::class, 'index']);
                        Route::post('/resources', [ResourcesController::class, 'store']);
                        Route::put('/resources/{resource_id}', [ResourcesController::class, 'update']);
                        Route::delete('/resources/{resource_id}', [ResourcesController::class, 'destroy']);
                        Route::get('/resources/{resource_id}/download', [ResourcesController::class, 'download']);

                        // Ḥifẓ (reused).
                        Route::get('/hifz', [HifzEntriesController::class, 'index']);
                        Route::post('/hifz', [HifzEntriesController::class, 'store']);
                        Route::delete('/hifz/{entry_id}', [HifzEntriesController::class, 'destroy']);
                        Route::get('/members/{membership_id}/hifz', [HifzEntriesController::class, 'forMember']);
                        Route::get('/members/{membership_id}/hifz/progress', [HifzEntriesController::class, 'progress']);

                        // Class story (reused; GroupAudience). No lifecycle beyond CRUD.
                        Route::get('/posts', [GroupPostsController::class, 'index']);
                        Route::post('/posts', [GroupPostsController::class, 'store']);
                        Route::get('/posts/{post_id}', [GroupPostsController::class, 'show']);
                        Route::get('/posts/{post_id}/attachments/{attachment_id}', [GroupPostsController::class, 'downloadAttachment']);
                        Route::put('/posts/{post_id}', [GroupPostsController::class, 'update']);
                        Route::delete('/posts/{post_id}', [GroupPostsController::class, 'destroy']);

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
                        Route::post('/threads/{thread_id}/messages', [GroupThreadsController::class, 'storeMessage']);

                        // Student avatar OVERRIDE — group-scoped (solves the
                        // ContactAvatarController {contact_id} reverse-lookup: the
                        // membership is resolved within this led class).
                        Route::put('/members/{membership_id}/avatar', [StudentAvatarController::class, 'update']);
                        Route::delete('/members/{membership_id}/avatar/override', [StudentAvatarController::class, 'destroyOverride']);
                    });
            });
    });
