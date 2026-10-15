# Admissions and re-enrolment

Two independent school grants, `school_reenrolment` and `school_applications`, ship **OFF** for every organisation type. Disabled grants disappear from capability payloads, raw overrides, switch history, Studio's catalogue and navigation. An OFF-to-OFF request materializes no override or ledger row. Switching a school on and sending real family mail remain separate owner decisions. This build does not deploy or enable anything.

## Re-enrolment

The office chooses a target year already entered in School Calendar, inclusive opening and closing dates on the school's clock, and optionally an existing form. Save dates under Re-enrolment. A campaign is unique per school/year. Current children appear once, with their classes, current grade, next grade, answer, responder/time and deposit outcome. `GradeLevel::next` supplies a proposal only when an active class at this school currently serves that grade (current roster grade labels, normalized with `GradeLevel`). Otherwise the proposal is blank, with "Choose next year's grade" for the office. Opening dates snapshots proposals for the current roster without entering any answers; later arrivals keep their proposal when first answered or edited. Class moves do not increment a saved proposal again. Unknown, final and unserved next grades start blank for office review. A saved blank proposal remains blank until the office chooses; explicit office grade choices are allowed even if no current class serves them. Filters select class, grade, answer and deposit outcome.

A confirmed guardian sees a card in each child's class. Either guardian may answer Returning next year or Not returning; the other sees the last answer, who recorded it and when. The office can record a paper/phone answer, visibly marked Recorded by the office, and change the proposed next grade. Families can change answers through closing day. The office can record or change late paper/phone answers after closing, retaining the visible office attribution. A grade-only office edit preserves the existing responder/time. No answer moves a child or creates next year's class roster.

Email unanswered families is an explicit office action with a confirmation showing the eligible guardian-email count. The screen shows the last delivered reminder batch, its school-local time and recipient count. A private reminder batch UUID distinguishes separate requests even in the same second. Emails link directly to each relevant class page's re-enrolment card (`#reenrolment`). It groups unanswered children by confirmed current guardian, requires a usable email and creates one mail per guardian. Repeating the action while that guardian's reminder is queued/sending does not duplicate it; another deliberate reminder is possible after completion. The worker checks unanswered status, enrollment and confirmed guardianship again. Children already answered or no longer reachable are omitted; an empty reminder is skipped. Nothing schedules this action automatically.

### Deposit

The optional form is a school's existing Form, using its fee rules and card/office payment choices. A form with no payment shows no deposit. A Returning answer links to an authenticated deposit screen prefilled for exactly that child and guardian; remaining required questions still need answers. Both standard repeated children (`firstName`, `lastName`, `schoolGrade`/`grade`) and flat student fields are supported. Repeatable emergency contacts are not children. The office must select a deposit form that asks for each child's separate first and last names. Existing fee-only links can still be read using their signed child/year context.

One deposit response is allowed per child per campaign, regardless of paid/unpaid state. Reopening the family deposit page reads the existing handle without requiring a card-return query, shows Paid / Not paid, and offers Pay now only when the existing Forms status permits it. It never shows another submission form for that child/campaign. A second submission with a fresh client key rolls back its response and returns 409: "A deposit response already exists for this child and school year. Open it from the family portal." A retry with the original client key still uses Forms' existing replay behavior. Existing handles remain readable after closing and retain their original form if the office changes the campaign form.

The screen renders stored form questions and quotes with `FormPayment`; it submits to the existing `FormSubmissionsController`. It does not create checkout sessions, settle cash, write payment state or send payment receipts itself. A two-hour encrypted credential names school, campaign, child membership, guardian and form. Inside Forms' existing write transaction, the server checks the live guardian guard, open campaign, Returning answer, selected form, one exact child name when the form asks for it, and the guardian identity email before linking that form response to the child/year. Failed checks roll the submission back. The signed credential never goes in a query string.

Deposit paid reads only the original linked response with its existing `isPaid()` state, excluding cancelled/refunded/disputed rows. Unlinked responses, another child, another form or another campaign cannot satisfy it. Existing unpaid responses reopen their payment/status screen instead of starting another submission. A return handle must be one of this child and campaign's linked responses before the screen reads it. Card status is read from the existing public response handle; the redirect's `paid=1` does not mark anything paid. Card returns carrying the credential are restricted to that exact family deposit path on the configured application origin or this school's configured HTTPS portal host. No public payment origin allowlist is broadened.

## Applications

Use Applications → Choose application forms, or the application setting in the existing Form Builder, to flag a form. This flag is per school/form and does not require the separate form-editing grant. Turning it off hides its responses from the admissions list while retaining history. Forms' own response status/payment/check-in behavior stays separate.

Each flagged form response starts as New, without creating a status row or emailing anyone. Office transitions are New, Accepted, Wait-listed and Declined; each actual change creates immutable history with a staff-only optional note, actor and timestamp. A repeated save of the same status sends nothing and creates no duplicate history. Mark without emailing records a correction without mail. Returning to New records history without mail, because there is no New message. A decision with no guardian email requires the explicit correction option.

Waiting-list position uses the response's original `submitted_at`, then response ID and child entry order, within `GradeLevel::key(grade)`. Grade 3 and 3rd share a queue. Accepting an earlier wait-listed application removes its children from that waiting list. Multiple children in one response share its decision but appear separately by grade.

No existing per-school email-template editor was found; the office edits three plain message texts. Only `{school}` and `{children}` are supported placeholders; unknown placeholders are refused. Text is frozen into each queued mail at the time of the decision. Staff notes never enter it. Defaults:

- Accepted: “A place at {school} has been offered for {children}. Please contact the school office about the next steps.”
- Wait-listed: “The application for {children} is on the waiting list at {school}. We will contact you if a place becomes available.”
- Declined: “We are unable to offer a place at {school} for {children}. Please contact the school office if you would like to discuss this decision.”

A response's identity-map guardian plus explicitly named parent/guardian email fields receive one message each, deduplicated by normalized email. The office sees queued, sending, sent, failed or skipped outcomes under Status and email history. A refresh reads the actual log. There is no automatic status-change scheduler.

### Add an accepted child to a roster

Choose a class on the accepted child's row and press Add to roster with guardians. The existing `GroupMembershipsController::store` adds the child and guardians, records `confirmedByStaff` attribution and uses its usual contact lock. Guardians are matched using `ContactIdentity::submittedAddress` and `keepExactMatches`, including PHP's exact check after the database shortlist so accent-insensitive collation cannot match a lookalike email. Existing adult contact details are kept.

Child matching first uses `registrations.form_response_id` → `registrants.contact_id` and exact normalized first/last names. Otherwise it looks for that exact normalized name within those guardians' recorded household. Multiple matches require office identity review. A matched child currently on any roster refuses the add and directs the office to the existing class move. An unlinked child with the same full name anywhere on a current school class roster also requires identity review; the one-step add does not guess between unrelated children with identical names. The transaction creates neither new child nor new guardian if any check refuses. Emails never identify a child.

Application readers always exclude responses linked in `school_reenrolment_deposits`, even if the same form is flagged for applications. Direct status/history/roster endpoints refuse those responses, and the worker skips previously queued application emails for them. Applying-grade fields (`gradeApplyingFor`, `applyingGrade`, `nextGrade`, then fields labelled "Grade applying...") take precedence over current-grade keys, for flat and repeated child questions. An accepted child's current class is returned as `roster_class`; the screen replaces Add to roster with "Added to <class>" immediately and after reload. Mail suppression starts unticked for every opened row and resets after every save attempt.

Office answer, reminder, submission and application-history instants are formatted server-side on the school's timezone as `Oct 14, 2026, 5:17 PM`. Deposit and roster errors display the server's validation/conflict sentence from either envelope. Unanswered and Not returning children show a dash in the office Deposit column and are excluded from its paid/unpaid filters.

## Storage and retention

No `masjids` column is added. A private `capability_overrides.school_reenrolment_records` boolean is set only after a campaign is written and stripped from every raw serialization. It retains year/merge guards after disabling, with no new SQL for an unused OFF school. All seven tables have an auto-increment primary ID, `masjid_id` and `BelongsToMasjid` tenant scoping:

| Table | Purpose |
| --- | --- |
| `school_admission_settings` | Per-school application form IDs and three editable texts |
| `school_reenrolments` | Unique target year, dates and optional deposit form |
| `school_reenrolment_answers` | Unique campaign/student, answer, grade override and last guardian/office attribution |
| `school_reenrolment_deposits` | Unique child/campaign links to existing FormResponse payment records |
| `school_applications` | Unique response and current decision |
| `school_application_histories` | Append-only status/note/actor/time and mail-request choice |
| `school_admission_mails` | Frozen recipient, children/text, office requester and delivery outcome |

There is no foreign key into `group_memberships`; these are intake office records, not academic marks. Actor/guardian references use SET NULL on explicit person erasure, preserving the office record without inventing an actor. The student references follow explicit contact erasure; member login deletion retains their office records through guarded table-exists reads in `MemberAccountDeletion`, including child IDs in reminder snapshots. SQLite explicitly extracts the child ID because its JSON containment emulation compares whole objects; MySQL uses JSON subset containment. SchoolYear uses RESTRICT: its campaign history must be kept, with a readable office refusal on year deletion. Contact merges move child answers, deposit links and reminder child IDs before source deletion; overlapping answers for one year refuse the merge. An adult's attribution/reminder FK follows only an exact matching email identity. A removed deposit form severs the optional form reference instead of deleting answers. A populated migration rollback refuses before dropping any table; an empty rollback drops child tables first and migrates forward again.

Every column of all seven tables is covered by staging's child-first `drop_rows` policy. This includes guardian emails, child names, office notes, editable message text, attribution and queued delivery data. Staging cannot replay a production intake queue. App101 pins new deletion reads separately; its original fixture is untouched.

Mail uses the existing school Mailable/reply address and `SchoolMailTransport` unsafe-transport refusal. Jobs use the `database` connection, carry IDs only and use their own named `school-admissions-mail` limiter at the same configured pace as absence mail, with a separate bucket. A committed queued-to-sending claim allows one external attempt. A crash/timeout leaves failed/sending evidence for the office; it does not retry an uncertain delivery and duplicate family mail. Sent means the configured transport accepted the message, not proof of inbox arrival.

## Synthetic walkthrough

1. In a practice school, add the next School Calendar year. Enable only the two test grants in test fixtures.
2. Select inclusive re-enrolment dates, first without a deposit, then with an existing paid form. Confirm one child and decline another as the first guardian. Read both answers as the second guardian.
3. Open the Returning child's deposit screen, review the prefilled child/guardian, fill remaining questions and choose office payment. Confirm that the response is linked but unpaid. Record its payment through the existing office Forms action; reload the family card.
4. Record a phone answer as office staff. Check its office attribution and grade override. Request reminders for the remaining unanswered children; inspect one per guardian and its delivery outcome. Answer before a queued reminder runs and check the worker omits that child.
5. Flag a practice application form. Edit the three texts. Accept one response; wait-list two in the same grade in reverse decision order and verify original arrival order; accept the earlier one later; decline another. Check history and each recipient's delivery log. Make a correction without emailing.
6. Add an accepted child to a class with an existing uppercase guardian email. Check one reused adult, confirmed guardian attribution and chosen grade. Try the same child again, including from a second application into a different class; read the refusal.
7. Repeat reads/writes with another family and another school; expect 403/404. Disable each grant independently and confirm the other still works and disabled navigation/payload fields disappear.

The guides session owns the separately authorised demo-school and guide-page walkthrough. Local tests use synthetic data only. Real mail, real payments, deployed browser behavior and fluent review of new translations are not asserted by these tests. New family words exist in all seven language dictionaries; additions to non-English dictionaries are marked machine-drafted.

### Walk-fix schema upgrade

`2026_10_14_235100_guard_school_admission_deposits` adds the unique child/campaign deposit key and nullable reminder batch UUID without rewriting the original admissions migration. Before changing schema, it refuses existing duplicate child/campaign links with a readable instruction to resolve the links and keep original payment records. It does not choose or erase a payment. Empty rollback/forward is tested; populated deposit/batch rollback refuses. Staging's whole-table admissions deletion policy covers the added batch column. MySQL execution still requires the existing CI MySQL gate.
