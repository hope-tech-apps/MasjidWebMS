<?php

use App\Http\Controllers\AdminDashboard\ArabicLettersController;
use App\Http\Controllers\AdminDashboard\AuthController;
use App\Http\Controllers\AdminDashboard\BehaviorAwardsController;
use App\Http\Controllers\AdminDashboard\BehaviorSkillsController;
use App\Http\Controllers\AdminDashboard\ContactAvatarController;
use App\Http\Controllers\AdminDashboard\GroupPostsController;
use App\Http\Controllers\AdminDashboard\GroupThreadsController;
use App\Http\Controllers\AdminDashboard\HifzEntriesController;
use App\Http\Controllers\Teacher\GroupsController as TeacherGroupsController;
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
