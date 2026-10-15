# Student sign-in build report

Implemented in this worktree on feature/student-sign-in, based on 0e0bdb51. Uncommitted. The new grant is OFF by default for every organisation; no switch-on, deploy, real data access, mail or commit was performed. No masjids columns, dependency changes, frozen-fixture edits, CAPTURE variables or CHANGELOG changes. Parent-minted child mode and its HTTP client are unchanged.

## Behavior and realm

StudentAccount is a separate Sanctum tokenable, student_accounts provider and student-bearer driver under the student guard. The driver accepts bearer credentials only, without web-session fallback, and requires the exact student ability. An explicit principal/tenant/account/switch/grade gate runs on every protected request. The token secret contains its realm prefix inside the value that is hashed; stripping it invalidates the credential. The global fence rejects that bearer on every non-student route, including web, public, member, family, staff and mobile routes. A real-token walk covers every registered outside verb.

The student page uses an independent school-specific sessionStorage slot and HTTP client, removes inherited common/method Authorization defaults, and chooses a token only for its own school and origin. Direct student loads skip staff bootstrap. Logout/expiry clears all cached records and password/file inputs. Session and request counters discard a previous student's delayed reads, errors, prepared photos and downloads after the account or selection changes. Existing parent and staff guard/provider pins remain intact.

Default eligibility is Grade 6, normalized through GradeLevel. Unknown grades or any current younger class membership fail closed. Office issuance, sign-in and every student request recheck it. The office issues/resets a random one-time password, sees it once and can immediately print per-class slips. First sign-in must choose a different password. Passwords use the application hasher, with a hashed model cast as a backstop for every model writer, and require 10 characters with a letter and digit, with a 72-byte bcrypt ceiling. Reset/change invalidates older tokens; disabling immediately revokes all student tokens. Hard contact erasure and merge also remove the separate credentials/tokens. No password is mailed or persisted as plaintext.

Student sessions: 15-minute idle and 120-minute absolute expiry, against the parent's 43,200-minute absolute window (30 days). Sign-out revokes the token. Sign-in: 5 attempts per normalized school/username and 50 per IP per 900-second window, including malformed, unknown and successful attempts. These are initial engineering limits, not measured traffic.

Own current memberships are the query boundary. Students receive class and teacher names, dated timetable lessons, work/due dates, the family marks reader's arithmetic/assignment projection and published family letter projection, and the family school-calendar projection. No classmates, notes, report comments, override provenance, reasons, messaging or posting.

## Storage and retention

Hand-ins use existing groups.resources MIME/size/private-storage policy: PDF, Word, JPEG and PNG, 8 MiB by default. Names on disk are random, directories tenant-scoped, and original names use the existing sanitizer. Public disks and URL-bearing disk configs are refused. Downloads re-resolve ownership and use private/no-store attachment responses. Photos pass through the existing browser preparation helper; the server still checks sniffed MIME and size. Links are http/https text and are never fetched.

Versions retain the child, membership/class/work, server time, lateness, link or private file metadata and previous versions. Due dates end on the school-local date. Replacement is refused after any mark, including missing/excused. The teacher's existing score route and student submission share the school/assignment mutex. Staff class/subject fences apply; office reads and archived-contact history remain available. Closing hand-ins leaves the student's existing history readable. Ineligible and withdrawn existing accounts remain visible and disableable by the office; withdrawn accounts are excluded from class-slip resets. Family status remains available while the guardian still has the child's entitlement.

Sign-in logs contain username, known child link, IP, user agent (bounded to 2,000 characters), outcome and time, never password. Daily students:prune-sign-ins deletes records older than 90 days. Credentials and identified logs disappear with login erasure. Hand-ins remain academic records; enrollment FKs RESTRICT, AcademicRecordsHeld blocks roster deletion and merging away their contact is refused. Staging nulls password hashes/last sign-in, masks usernames/disables accounts, drops IP/agent logs, and nulls hand-in links, paths and filenames. An actual in-memory staging scrub regression proves those values disappear.

Student screens are English. Family account-status labels cover the existing seven locales; new non-English text is machine-drafted.

## Verification

| Run | Printed result | Evidence |
|---|---|---|
| Entire default PHP suite ONCE | **21 failed, 7 skipped, 11328 passed (186400 assertions); 858.79 s**, exit 2 | /tmp/student-entire-php.log |
| Post-full integration/security/contracts | **251 passed (7990 assertions); 73.45 s** | /tmp/student-final-targeted.log |
| Latest student, App101 and scrub regressions | **34 passed (3523 assertions); 4.20 s** | /tmp/student-final-security-all.log |
| Roles ON selected affected suites and all expected cases | **4 failed, 266 passed (13157 assertions); 68.48 s** | /tmp/student-roles-on.log |
| Original roles checker | **tests=270 assertions=13157 failed=4 expected=4; exactly expected failures** | /tmp/student-roles-check.log |
| Final entire SPA suite, TZ=UTC | **2205 passed, 0 failed, 1 skipped (2206 tests); 225021.683959 ms** | /tmp/student-spa-shared-device-complete.log |
| Latest student HTTP/mounted checks | **11 passed, 0 failed; 672.305916 ms** | /tmp/student-shared-device-final.log |
| Production build ONCE | **passed; 11.67 s**; Vite emitted its large-chunk warning | /tmp/student-build.log |
| Build inspection | Manifest files exist; student, office, family and teacher chunks plus token/slip/hand-in strings present; public/build removed | /tmp/student-build-proof.json |
| PHP syntax and diff | **60 PHP files syntax clean; diff --check clean; ordinary and whitespace-ignored diff stats match** | /tmp/student-final-audit.json |

The build preceded the final UI/session corrections: retained history after closure, disabling ineligible/withdrawn accounts, and discarding a previous student's delayed requests/photos/downloads. Those final components were compiled/mounted-tested and the final complete SPA suite passed afterwards. The shared-device regression first reproduced the previous student's class and teacher appearing after another student signed in (/tmp/student-session-race-red.log). The model-hashing backstop also has a failing-before regression (/tmp/student-model-hash-red.log). No second bundle was made under the owner's one-build limit. The final production bundle including these UI/session corrections is therefore not verified.

### Ten unexpected failures from the full PHP run, all corrected and proved targeted

1. OneLoginTwoRolesParityTest::she_sees_what_a_teacher_of_the_same_class_sees — missing hand_in_id fixture. Added a real private hand-in. A subsequent comparison crossed a second on thread last_read_at; fixed the test clock.
2. TeacherMultiSchoolTest::a_route_list_sweep_finds_no_bleed_between_the_two_schools_in_either_direction, gate open — new write had no sweep payload.
3. The same TeacherMultiSchoolTest case, gate shut — same missing payload. Both now exercise real enabled hand-in reads/settings and foreign IDs.
4. SchoolCalendarTermsTest::off_capability_request_shapes_match_full_literal_baseline_responses_and_rows, studio shape — missing hidden catalogue/alternate-writer registration.
5. SpaTitleIsRuntimeTest::every_reader_of_the_api_url_says_what_happens_when_it_is_missing — changed the valid runtime fallback to the source contract's explicit nullish form.
6. Studio/CapabilityCatalogueEndpointTest::every_entry_the_org_type_may_see_is_served_once_in_capability_groups_order.
7. Studio/CapabilityCatalogueEndpointTest::school_features_are_never_served_to_a_masjid_or_a_community.
8. Studio/CapabilityCatalogueEndpointTest::it_agrees_with_the_switch_panel_for_a_fresh_org_of_each_type.
9. Studio/CapabilityCatalogueEndpointTest::it_survives_a_config_without_turns_on_or_provision_default. The four Studio cases are corrected by hidden visibility and matching school/hidden inventories.
10. UploadFileNameCoverageTest::every_upload_rule_the_scan_can_read_pins_the_file_name_or_says_why_it_need_not — added the private-name exception with checked disk/random-name/download facts.

### Eleven specified environment failures from the full PHP run

- Backup/BackupRunTest::an_archive_whose_headers_are_directories_is_still_a_short_archive.
- Backup/BackupRunTest::an_archive_that_really_holds_the_files_still_passes.
- ExampleTest::it_returns_a_successful_response — absent public/build/manifest.json.
- HostHeaderUrlIntegrityTest::the_test_harness_really_does_forge_the_host — absent build.
- ResendTransportTimeoutTest::a_resend_call_that_is_never_answered_gives_up_instead_of_waiting_for_ever.
- ResendTransportTimeoutTest::a_silent_resend_still_saves_a_portal_password_and_answers_ok.
- ResendTransportTimeoutTest::a_silent_resend_still_resets_an_app_password_and_signs_the_member_in.
- ResendTransportTimeoutTest::the_shipped_limits_are_set_and_well_below_nginxs_sixty_seconds.
- TenancyCanaryTest::an_origin_that_redirects_every_probe_is_not_a_clean_run.
- TenancyCanaryTest::an_origin_that_404s_every_probe_is_not_a_clean_run.
- TenancyCanaryTest::one_unreachable_endpoint_is_not_the_same_alarm_as_a_dead_origin.

The final seven are loopback socket refusals in this sandbox. No second full PHP run was made. The ten integration failures are not being described as environment failures.

## NOT verified

MySQL DDL/rollback/locking execution (the MySQL CI test is added); remote CI or the entire roles-ON suite (the selected affected suites and original expected-failure checker ran); browser geometry, accessibility, actual printing, phone/demo/guide walkthrough; scheduler execution on a server; staging/production/deployment; fluent review of the new non-English labels; and the final production bundle after the UI/session corrections described above.

## Files changed and why

| File | Purpose |
|---|---|
| ASSUMPTIONS.md | Record grade ambiguity, due-day interpretation, English student screens and the separate demo walk. |
| DECISIONS.md | Record child data, realm boundary, credentials, session limits, retention, academic history and language choices. |
| app/Http/Controllers/AdminDashboard/ContactsController.php | Refuse merging away hand-in attribution before and under the school lock. |
| app/Http/Controllers/AdminDashboard/MasjidsController.php | Filter the disabled grant and history; allow explicit operator discovery. |
| app/Http/Controllers/AdminDashboard/StudentAccountsController.php | Office threshold, account issuance/reset/disable, status and atomic class slips. |
| app/Http/Controllers/Family/GradesController.php | Reuse its existing scores/assignment reader and GradeRecord arithmetic for an allowlisted student projection; reuse published family letters. |
| app/Http/Controllers/Family/GroupsController.php | Add the account-status affordance only when the school grant is on. |
| app/Http/Controllers/Family/StudentAccountsController.php | Read account status only for the authenticated family’s own confirmed wards. |
| app/Http/Controllers/Student/AuthController.php | Student sign-in, password change and token-revoking sign-out; throttled password-free audit. |
| app/Http/Controllers/Student/PortalController.php | Own-membership classes, timetable, calendar, family mark projection and private versioned hand-in endpoints. |
| app/Http/Controllers/Teacher/GroupsController.php | Advertise hand-ins only when the school grant is on. |
| app/Http/Controllers/Teacher/StudentHandInsController.php | Class/subject-fenced settings, history and private downloads for staff. |
| app/Http/Middleware/EnsureOrgCapability.php | Refuse the disabled school grant even for operators, with a usable API sentence. |
| app/Http/Middleware/EnsureStudentAccess.php | Principal/ability, enabled account, switch, tenant, grade and first-password gate on every protected student request. |
| app/Http/Middleware/StudentMarkLock.php | Serialize the existing score-save transaction with submission replacement. |
| app/Http/Middleware/StudentRealmFence.php | Reject student bearer secrets globally outside the student prefix without OFF-reader SQL. |
| app/Models/Masjid.php | Hide the disabled grant from computed capabilities and raw overrides. |
| app/Models/StudentAccount.php | Tenant-scoped credential with hidden hashed password, student-prefixed token secret and token erasure hook. |
| app/Models/StudentHandIn.php | Tenant-scoped immutable submission version; private paths excluded from default serialization. |
| app/Models/StudentSignInLog.php | Tenant-scoped bounded sign-in audit without passwords. |
| app/Models/StudentSignInSetting.php | Tenant-scoped starting-grade setting without adding masjids columns. |
| app/Models/StudentWorkSetting.php | Tenant-scoped assignment hand-in flag and due date. |
| app/Providers/AppServiceProvider.php | Register the independent student bearer guard and contact credential-erasure hook. |
| app/Services/Member/MemberAccountDeletion.php | Classify credentials/logs as login data and hand-ins as academic office records; guard optional table reads. |
| app/Services/Student/StudentAccess.php | Resolve exact current memberships and GradeLevel eligibility; refuse unknown or younger grades. |
| app/Services/Student/StudentCredentials.php | Erase student accounts/tokens and identified sign-in logs with guarded optional-table reads. |
| app/Services/Student/StudentGuard.php | Bearer-only student principal with exact abilities, idle/absolute expiry and last-use updates. |
| app/Services/Student/StudentHandIns.php | Transactional private file/photo/link submission, lateness, immutable history and post-mark locking. |
| app/Support/AcademicRecordsHeld.php | Include restrictive hand-ins in enrollment removal/move retention. |
| app/Support/CapabilityCatalogue.php | Keep the grant hidden in Studio’s catalogue. |
| app/Support/CapabilityWriter.php | Skip disabled-to-disabled hidden no-ops. |
| app/Support/GroupResourceFiles.php | Use existing school resource disk/rules, randomized names and filename sanitizer for student attachments. |
| app/Support/SchoolCalendarCapabilityWriter.php | Apply the same hidden no-op rule through the alternate calendar writer. |
| app/Support/SchoolSettings.php | Reject enabling student sign-in on a non-school. |
| bootstrap/app.php | Mount the separate route file, global bearer fence, active gate and password flash exclusion. |
| config/auth.php | Separate StudentAccount provider and student guard. |
| config/capabilities.php | New hidden grant, false for all organisation types. |
| config/staging_scrub.php | Null hashes, last-use and private hand-in text/paths; anonymize usernames, disable accounts and drop IP/agent logs. |
| config/student.php | Centralize session, password, attempt and retention numbers. |
| database/migrations/2026_10_15_030000_create_student_sign_in.php | Five tenant tables with primary keys, case-insensitive school username uniqueness and restrictive academic foreign keys; no masjids columns. |
| docs/student-sign-in-build-report.md | Exact build/test evidence, unresolved verification limits and complete changed-file inventory. |
| docs/student-sign-in.md | Document office, student, family and teacher behavior, privacy rules and later demo walkthrough. |
| resources/vue-app/components/classes/FamilyStudentAccount.vue | Own-child account status and last sign-in in the seven existing family languages. |
| resources/vue-app/components/classes/StudentAccounts.vue | Grant-gated office threshold, create/reset/disable and transient printable class slips. |
| resources/vue-app/components/classes/StudentHandIns.vue | Grant-gated teacher/office assignment settings, version history and authenticated downloads. |
| resources/vue-app/core/helpers/studentAccountSession.ts | Select only a school/origin-specific student token in independent sessionStorage. |
| resources/vue-app/core/services/StudentAccountApiService.ts | Dedicated HTTP client strips inherited credentials, tracks current-session activity and guards stale downloads. |
| resources/vue-app/core/types/data/Capability.ts | Mirror the grant key and catalogue label. |
| resources/vue-app/main.ts | Skip staff bootstrap on direct student-page loads while preserving normal staff boot ordering. |
| resources/vue-app/router/routes/routes.ts | Standalone student page outside staff/family shells. |
| resources/vue-app/tests/class-location-visible.test.ts | Pin the one inert disabled mount anchor separately from the frozen fixture. |
| resources/vue-app/tests/class-subjects-screens.test.ts | Pin that same anchor in the second frozen office-page contract. |
| resources/vue-app/tests/family-left-class-notice.test.ts | Supply the new optional family component stub in existing mounted tests. |
| resources/vue-app/tests/student-account-http.test.ts | Exercise actual axios defaults/adapters, independent storage, form posts and stale-download rejection. |
| resources/vue-app/tests/student-sign-in.test.ts | Mount office/student/teacher flows, shared-device response/error races and delayed photo preparation. |
| resources/vue-app/views/dashboard/GroupDetailView.vue | Show office account/settings/slip controls only when granted. |
| resources/vue-app/views/dashboard/groups/GroupGradesTab.vue | Office read of hand-ins beside the existing gradebook. |
| resources/vue-app/views/family/FamilyClass.vue | Display family account status only when offered. |
| resources/vue-app/views/student/StudentPortal.vue | English student account and own-schoolwork UI; clear private state and discard delayed work across sessions. |
| resources/vue-app/views/teacher/TeacherClass.vue | Place hand-in configuration/history beside existing marking controls. |
| routes/admin.php | Office account/settings/slips and hand-in reads with existing contact permissions. |
| routes/console.php | Daily 90-day sign-in log prune command. |
| routes/family.php | Parent-only, grant-gated own-child account status read. |
| routes/student.php | The only routes that admit student credentials. |
| routes/teacher.php | Hand-in read/settings/download routes and the score-save mutex. |
| tests/Feature/App101OffContractTest.php | Pin the six new erasure/retention statements separately; retain the original fixture. |
| tests/Feature/MemberAccountDeletionCoverageTest.php | Classify the new child references and login records. |
| tests/Feature/OneLoginTwoRolesParityTest.php | Enable the new read in the real parity sweep and freeze its response clock. |
| tests/Feature/RosterMoveRosterTest.php | Account for the added restrictive academic record in the schema/count inventory. |
| tests/Feature/Student/StudentPortalTest.php | Own-reader allowlists and IDs, private file/photo/link hand-ins, lateness, versions and teacher marking. |
| tests/Feature/Student/StudentPrivacyTenantIsolationTest.php | Tenant isolation, retention/deletion, actual staging scrub, withdrawn status and model-hasher backstop. |
| tests/Feature/Student/StudentSignInTest.php | All-route token walk, username uniqueness, forced password, throttle, expiry, reset/disable and eligibility. |
| tests/Feature/Student/StudentTestCase.php | Invented tenant/student/class fixtures and real account-token setup for security regressions. |
| tests/Feature/StudentAllergiesTest.php | Include the hidden parent account-status route in the privacy walk. |
| tests/Feature/Studio/CapabilityCatalogueEndpointTest.php | Keep the school-key and hidden-key inventories in config order. |
| tests/Feature/TeacherMultiSchoolTest.php | Enable and sweep actual hand-in reads/settings against both schools and unled classes. |
| tests/Feature/TeacherRealmTest.php | Add the one authorized hand-in configuration write to the exact route inventory. |
| tests/Feature/UploadFileNameCoverageTest.php | Declare and mechanically verify why private randomized hand-in filenames cannot become public addresses. |
| tests/Mysql/RosterMoveMysqlTest.php | Expect the additional restrictive enrollment foreign key. |
| tests/Mysql/StudentSignInSchemaTest.php | CI MySQL empty rollback/forward, primary keys, short indexes, username collation and restrictive enrollment FK proof. |
| tests/Support/PlantsRosterRecords.php | Plant real hand-ins in the all-academic-record retention matrix. |
| tests/Support/TeacherRealmWorld.php | Seed an actual private hand-in and resolve its ID for route sweeps. |

Local ignored working notes STATE.md and PLAN.md were also replaced with the student build checkpoint. The pre-existing untracked artifacts/subject-report-summary-query-counts.json was restored byte for byte; 39 generated untracked artifacts were removed. No generated public/build is left. A lesson was appended to the shared lessons inbox, outside this repository.
