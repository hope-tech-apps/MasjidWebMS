# App 1.0.1 server contract

All examples use synthetic IDs and addresses. IDs are organisation-local database identities, not fixed constants. This is the server contract for both native clients; it does not implement their first-open welcome or navigation.

## Carriers and defaults

| Response | Location | Added keys |
|---|---|---|
| `GET /api/mobile/masjids/{id}` | `data` | `bottom_tabs`, `onboarding`, `volunteer` |
| `GET /api/v1/settings` (existing `masjid-id` header) | `data` | The same three keys |
| `GET /api/mobile/masjids/{id}/features` | None | No additions, new catalogue row or new pivot row |

Every previous carrier key retains its value and encoding. `/menu`, `/orgs`, sign-in responses and contact directory rows gain no question answers or new app keys. Each organisation, including a child, uses its own switches and settings.

* `bottom_tabs` absent: use the app's standard tabs. Home is always first and is never returned. A nonempty array replaces the optional tabs, in order, with up to four choices. Skip unsupported catalogue identities. An empty array or null is never emitted.
* `onboarding` absent: no first-open questions/welcome opt-in. Present: `questions` is literally `true`, and the two arrays are active choices in server order. Account creation and all questions remain optional. The app must also respect its existing sign-in availability before inviting account creation. This marker gives no parent, child or office access.
* `volunteer` absent: offer no Volunteer link. Present: open the stored `url`, with the stored `title`. The grant alone is insufficient; a saved, nonempty https address is required. Neither null nor an empty address is emitted.

Literal example of the added portion of either carrier's `data`:

```json
{
  "bottom_tabs": [
    {"id": 9, "key": "services", "name": "Services"},
    {"id": null, "key": "volunteer", "name": "Volunteer"}
  ],
  "onboarding": {
    "questions": true,
    "occupation": [
      {"id": 1, "label": "Education"},
      {"id": 2, "label": "Healthcare"},
      {"id": 3, "label": "Technology"},
      {"id": 4, "label": "Engineering"},
      {"id": 5, "label": "Business and finance"},
      {"id": 6, "label": "Legal"},
      {"id": 7, "label": "Trades and construction"},
      {"id": 8, "label": "Hospitality and food service"},
      {"id": 9, "label": "Public service"},
      {"id": 10, "label": "Student"},
      {"id": 11, "label": "Homemaker"},
      {"id": 12, "label": "Retired"},
      {"id": 13, "label": "Other"}
    ],
    "help": [
      {"id": 14, "label": "Teaching and tutoring"},
      {"id": 15, "label": "Events and hospitality"},
      {"id": 16, "label": "Youth activities"},
      {"id": 17, "label": "Community relief"},
      {"id": 18, "label": "Maintenance and facilities"},
      {"id": 19, "label": "Technology and media"},
      {"id": 20, "label": "Professional advice"},
      {"id": 21, "label": "Other"}
    ]
  },
  "volunteer": {"title": "Volunteer", "url": "https://example.org/volunteer"}
}
```

The IDs in the two question lists are unrelated to catalogue feature IDs, even where the numeric values coincide. `volunteer` is a reserved non-catalogue identity. Its tab has `id:null`; all catalogue tabs have integer IDs and the catalogue's exact key/name, including Unicode punctuation. Removing its address or grant removes the tab immediately. A renamed Volunteer title changes both objects.

## Member questions

`GET` and `PUT /api/mobile/masjids/{id}/member-questions` require an active signed-in **member** token, the member's organisation in the path, CRM access and `app_member_questions`. Family and staff tokens cannot write. The contact is always the token's own contact; request contact/organisation IDs cannot select somebody else. Known minors, guardian wards and student/class-member records are refused, including on GET. Unknown ages do not create a child identity or confer a family credential.

GET returns active, ordered lists and that member's current answers. Member lists additionally carry `is_other`, an immutable identity independent of a platform label rename. Inactive choices are absent from lists; an existing answer may still reference one until the member replaces it. This literal response illustrates an organisation with shortened lists:

```json
{
  "status": "success",
  "data": {
    "occupation": [
      {"id": 1, "label": "Education", "is_other": false},
      {"id": 10, "label": "Other", "is_other": true}
    ],
    "help": [
      {"id": 11, "label": "Teaching and tutoring", "is_other": false},
      {"id": 18, "label": "Other", "is_other": true}
    ],
    "answers": {
      "occupation_id": 10,
      "occupation_text": "Independent maker",
      "help_ids": [11, 18],
      "help_text": "Can translate"
    }
  }
}
```

PUT replaces both answers as one transaction. Omitted fields clear their previous value. Literal request:

```json
{"occupation_id":10,"occupation_text":"Independent maker","help_ids":[11,18],"help_text":"Can translate"}
```

`occupation_id` is one option or null; `help_ids` is a set. Extra words are accepted only for that list's Other option, with maximums of 120 and 200 Unicode characters respectively. Unknown, inactive, other-question and other-organisation IDs receive a 422 with a sentence, rather than being silently dropped. PUT returns the same GET shape. `{}` clears everything; the resulting `answers` is:

```json
{"occupation_id":null,"occupation_text":null,"help_ids":[],"help_text":null}
```

The grant is default false for every organisation type; with it off, authenticated permitted callers receive 404. Existing authentication/tenant/CRM refusals still apply. Both new routes have a 60-per-minute throttle and names under `mobile.member.me.*`, so success/refusal envelopes include `data`.

## Program interests (unchanged)

`GET` and `PUT /api/mobile/masjids/{id}/interests` retain their existing member-token, tenant and CRM rules. GET returns:

```json
{"status":"success","data":[{"id":7,"title":"Practice program","summary":"Program updates","interested":true}]}
```

PUT is a whole-set replace: `{"service_ids":[7]}` or `{"service_ids":[]}` to clear. The existing treatment of foreign IDs and error envelopes is unchanged. There is no write to interests from the new question endpoint and no question field in verify-code/password responses. The app saves questions after sign-in, not during code verification.

## Ownership, retention and office access

Answers live in `member_question_answers`, keyed uniquely by organisation/contact, with `member_question_helps` for selected help IDs. They are never Contact attributes, tags, directory relations or exports. Both free-text fields use Laravel encrypted casts on TEXT columns, following the student allergy note pattern; option IDs remain plaintext so the office can filter. Staging drops both answer tables and classifies both encrypted columns.

Only SuperAdmin edits organisation-specific option lists, through `GET/PUT /api/admin/masjids/{id}/member-question-options` in the existing organisation switch panel. First activation seeds the defaults once. Renaming, ordering and deactivation retain IDs and answers; omission deactivates, never deletes. An organisation administrator cannot edit lists or answers.

The office uses its existing `view contacts` permission for `GET .../contacts/{contact_id}/from-app`, a separate read, and `GET .../contacts/from-app-filters`. The directory accepts `occupation_id`, `help_id`, `interest_id`; it returns the existing Contact payload without answers. The From the app block also shows that member's existing service interests. No office answer-writing endpoint exists.

The single account-deletion implementation remains `MemberAccountDeletion::delete`, used by `DELETE /api/mobile/masjids/{id}/me` and the public deletion page. It explicitly deletes this organisation/contact's answer row before either **erased** or **login removed** outcome in the same transaction; help selections cascade from the answer. This also runs with disclosure off, with app credentials already absent, or when the separate family login is kept. A failed table lookup or later deletion step rolls the whole transaction back. A genuinely missing pre-migration table is skipped, using the existing strict `CartTables::existsOrFail` check. There is no database trigger. Permanent contact deletion cascades answers and selections through their foreign keys; soft deletion and restoration retain them. Merge carries absorbed answers if the survivor has none; otherwise the survivor's answer wins, without duplicate selections. Merge reads retained rows even when disclosure is off, without a private capability JSON key.

Login revocation is not account deletion: answers remain the office's to see. The other application paths follow this rule:

| Application path | Answer decision |
| --- | --- |
| `FamilyAccessService::revoke` (office login revoke; also called from `ContactsController::destroy` and `absorbOnMerge`) | Keep answers when revoking access or soft deleting. Merge separately reconciles answers before permanent source erasure. |
| `FamilyAccessService::write` via `enable`, when changing a login address and clearing password/`verified_at` | Keep answers on the same contact. |
| `FamilyAccessService::releaseAddressFrom`, after confirmed login-address reassignment | Keep the old holder's answers on that contact; do not transfer them to the new address holder. |
| `FamilyPasswordService::clear` (member/family password removal; also called by `MemberSignupService::consume` on address adoption) | Keep answers; a password clear does not request account deletion. |
| Sign-out, session revocation or password replacement | Keep answers. |

The inventory found no other application writer clearing a contact's `verified_at`, `login_enabled_at` or password. Domain verification uses different models and does not affect member login or answers.

Volunteer is configured by the organisation's own admin at `/masjid/app-tabs`, using `GET/PUT /api/admin/masjids/{id}/app-volunteer`. The default title is Volunteer, maximum 30 characters; the address is nullable to clear, otherwise https, valid host, no credentials, ASCII, maximum 500 characters. A saved link can be tested in a new tab. The tab save accepts catalogue IDs and the literal string `"volunteer"`, for example `{"feature_ids":[9,"volunteer"]}`.

## Query and cache evidence

With all three grants off, the tested cold/warm mobile carriers, office list/detail, interests GET and account-deletion body match a clean 109ce744 export. Account-deletion SQL adds the explicit answer-table existence check and answer DELETE; every legacy statement remains pinned to the unchanged fixture. Tabs OFF adds zero queries. Tabs ON-empty and ON-chosen each add one indexed joined query per carrier. Questions adds one query for both lists; Volunteer adds one settings query while enabled. The directory with a help filter executes five application SQL statements at both 10 and 60 contacts. Array-cache pins preserve every legacy application statement. A separate SQLite database-cache pin includes infrastructure: detail OFF is nine cold statements or one warm statement; ON-empty and ON-chosen are ten cold or two warm, exactly one extra selection query. Answer and tab writes leave capability overrides unchanged; no private presence keys or backfill are used.

Tabs, Volunteer and list saves, grant changes and catalogue renames invalidate organisation detail after commit. The database detail cache uses an invalidation revision in the same entry and a conditional publication write, avoiding a second cache lookup. Other drivers use generation addresses. A fill begun before a save cannot overwrite the current invalidation. The database cache publication statement is intentionally conditional; cold/warm counts and application SQL are pinned. V1 settings and the added data reads themselves are uncached. SQLite proves controlled interleaving and transaction rollback, not two-connection MySQL contention. Native decoding and browser geometry remain separate verification work.
