# App 1.0.1 server contract

## CHANGED 2026-10-13

- Bottom tabs: previously catalogue IDs or virtual `volunteer`; now also virtual `menu`, always available with `app_bottom_tabs` alone. Save `"menu"`; emit `{"id":null,"key":"menu","name":"Menu"}`. It opens the existing side menu, counts toward the four choices after Home, occurs at most once, and can be anywhere. Home remains first and omitted from `bottom_tabs`. No catalogue or pivot row is added; `menu` is reserved like `home` and `volunteer`.
- Carrier `onboarding`: **old** `{"questions":true,"occupation":[…],"help":[…]}` → **new** `{"questions":[{"kind":"field","prompt":"What field do you work in?","multiple":false,"options":[…]},…]}`. Only asked kinds appear, ordered `field`, `occupation`, `help`. The entire `onboarding` key is absent with the switch OFF or no asked kind. The option objects on carriers have `id` and `label`.
- Member GET: **old** `{"occupation":[…],"help":[…],"answers":{"occupation_id":…,"occupation_text":…,"help_ids":[…],"help_text":…}}` → **new** `{"questions":[…],"occupation":[…],"help":[…],"answers":{"field_id":…,"field_text":…,"occupation_id":…,"occupation_text":…,"help_ids":[…],"help_text":…}}`. `questions` is the authoritative ordered asked-kind array, with `is_other` in its options. The old top-level `occupation` and `help` arrays remain **deprecated** at no extra query cost; they contain active options even when the kind is not asked. They must not decide which questions to display. `occupation` now means profession.
- Member PUT: same address and flat whole-replace answers object, now with `field_id` and `field_text`. `{}` clears all six answer fields. A nonempty answer for a kind not asked is refused with a 422 sentence. Explicit nulls/empty selections can clear retained answers.
- First activation: ask field (14 choices) and profession (18 choices); do not ask help, but seed and retain its 8 choices. SuperAdmin can set each kind's asked state, prompt (required, maximum 80 characters), options/order/active state. Organisation admins still cannot edit.
- Upgrade migration: every existing `occupation` option becomes `field`, retaining its ID, label, order, active and immutable Other flag; existing `occupation_id`/encrypted `occupation_text` answers move to `field_id`/`field_text`, then occupation answers clear. New profession defaults and settings are seeded for organisations with retained lists, including switched-off organisations; help becomes not asked without deleting its list or answers. No production answers are expected. Migration rollback discards new profession answers/options and restores fields as the old occupations.
- Office: From the app adds ordered `questions` and shows only asked kinds under their saved prompts; the existing office response keys remain. Directory filters are Field, Profession, How they could help (each only if asked), plus Interested in. New Members by field/profession tables and CSV are on the contacts directory and contain option labels/counts only. The existing interests endpoints, switches, ownership, adult-only and retention rules remain unchanged.

All examples use synthetic IDs and addresses. IDs are organisation-local database identities, not fixed constants. This is the server contract for both native clients; it does not implement their first-open welcome or navigation.

## Carriers and defaults

| Response | Location | Added keys |
|---|---|---|
| `GET /api/mobile/masjids/{id}` | `data` | `bottom_tabs`, `onboarding`, `volunteer` |
| `GET /api/v1/settings` (existing `masjid-id` header) | `data` | The same three keys |
| `GET /api/mobile/masjids/{id}/features` | None | No additions, new catalogue row or new pivot row |

Every previous carrier key retains its value and encoding. `/menu`, `/orgs`, sign-in responses and contact directory rows gain no question answers or new app keys. Each organisation, including a child, uses its own switches and settings.

* `bottom_tabs` absent: use the app's standard tabs. Home is always first and is never returned. A nonempty array replaces the optional tabs, in order, with up to four choices. Skip unsupported catalogue identities. An empty array or null is never emitted.
* `onboarding` absent: no first-open questions/welcome opt-in. Present: `questions` is an ordered nonempty array of asked kinds, with the organisation's saved prompt and active options in server order. Account creation and all questions remain optional. The app must also respect its existing sign-in availability before inviting account creation. This marker gives no parent, child or office access.
* `volunteer` absent: offer no Volunteer link. Present: open the stored `url`, with the stored `title`. The grant alone is insufficient; a saved, nonempty https address is required. Neither null nor an empty address is emitted.

Literal example of the added portion of either carrier's `data`, for an organisation with shortened option lists. Home is added by the app, producing Home, the existing News/Announcements tab, Donate, Menu:

```json
{
  "bottom_tabs": [
    {"id": 8, "key": "announcements", "name": "Announcements"},
    {"id": 12, "key": "donate", "name": "Donate"},
    {"id": null, "key": "menu", "name": "Menu"}
  ],
  "onboarding": {
    "questions": [
      {"kind":"field","prompt":"What field do you work in?","multiple":false,"options":[{"id":2,"label":"Healthcare"},{"id":14,"label":"Other"}]},
      {"kind":"occupation","prompt":"What is your profession?","multiple":false,"options":[{"id":16,"label":"Physician"},{"id":32,"label":"Other"}]}
    ]
  },
  "volunteer": {"title":"Volunteer","url":"https://example.org/volunteer"}
}
```

Question option IDs are unrelated to catalogue feature IDs, even where numbers coincide. All catalogue tabs retain the catalogue's exact key/name (including Unicode punctuation). The existing News identity is `announcements`; no catalogue key or name is renamed by this build. `menu` and `volunteer` are reserved non-catalogue identities with `id:null`. Menu opens the existing side menu and has no URL or extra switch. Removing the Volunteer address or grant removes its tab immediately; renaming its title changes both Volunteer objects. At most four optional tabs, plus the app's Home.

## Member questions

`GET` and `PUT /api/mobile/masjids/{id}/member-questions` require an active signed-in **member** token, the member's organisation in the path, CRM access and `app_member_questions`. Family and staff tokens cannot write. The contact is always the token's own contact; request contact/organisation IDs cannot select somebody else. Known minors, guardian wards and student/class-member records are refused, including on GET. Unknown ages do not create a child identity or confer a family credential.

GET returns the ordered array of asked questions and the member's current answers. Fixed kinds are `field` (single choice), `occupation` (single choice, shown to members as profession) and `help` (multiple choice). Each allows optional Other text. Interests are separate and never a question kind. The server orders kinds `field`, `occupation`, `help`; skipped kinds leave no placeholder. Prompts are organisation-editable. `is_other` is an immutable option identity independent of label renaming. Inactive options are absent from member lists; retained answers can reference inactive options or a kind since turned off until replaced. All six answer keys are always returned. The deprecated top-level lists are active-option aliases, not asked-kind controls.

Example for an organisation with shortened lists and the default two asked kinds:

```json
{
  "status":"success",
  "data":{
    "questions":[
      {"kind":"field","prompt":"What field do you work in?","multiple":false,"options":[{"id":2,"label":"Healthcare","is_other":false},{"id":14,"label":"Other","is_other":true}]},
      {"kind":"occupation","prompt":"What is your profession?","multiple":false,"options":[{"id":16,"label":"Physician","is_other":false},{"id":32,"label":"Other","is_other":true}]}
    ],
    "occupation":[{"id":16,"label":"Physician","is_other":false},{"id":32,"label":"Other","is_other":true}],
    "help":[{"id":33,"label":"Teaching and tutoring","is_other":false},{"id":40,"label":"Other","is_other":true}],
    "answers":{"field_id":2,"field_text":null,"occupation_id":16,"occupation_text":null,"help_ids":[],"help_text":null}
  }
}
```

PUT takes this flat answers object and replaces all answers in one transaction. Omitted fields clear their previous value. Literal request:

```json
{"field_id":2,"field_text":null,"occupation_id":16,"occupation_text":null,"help_ids":[],"help_text":null}
```

`field_id` and `occupation_id` each accept one option or null; `help_ids` is a distinct set. Extra words require that kind's selected Other option, maximum 120 Unicode characters for field/profession and 200 for help. Unknown, inactive, wrong-kind and foreign-organisation IDs receive a 422 sentence. Nonempty IDs, selections or text for an unasked kind receive a 422 sentence: "This question is not asked by your organisation. Clear its answer before saving." Nulls/empty arrays can clear such retained answers. PUT returns the same GET shape. `{}` clears everything:

```json
{"field_id":null,"field_text":null,"occupation_id":null,"occupation_text":null,"help_ids":[],"help_text":null}
```

With no asked kind, GET still returns `questions:[]`, deprecated lists and retained/empty answers; `{}` can still clear. The carrier omits `onboarding` in this case.

Defaults on first activation (never overwrite saved settings or retained options):

- Field, asked, prompt "What field do you work in?": Education; Healthcare; Technology; Engineering; Business and finance; Legal; Trades and construction; Hospitality and food service; Public service; Nonprofit and community; Student; Homemaker; Retired; Other.
- Profession (`occupation`), asked, prompt "What is your profession?": Teacher or professor; Physician; Nurse; Dentist; Pharmacist; Software or IT professional; Engineer; Accountant or finance professional; Attorney; Business owner; Manager or administrator; Skilled trade; Driver or logistics; Chef or food service; Student; Homemaker; Retired; Other.
- Help, not asked, prompt "How could you help?": Teaching and tutoring; Events and hospitality; Youth activities; Community relief; Maintenance and facilities; Technology and media; Professional advice; Other.

The grant is default false for every organisation type; with it off, authenticated permitted callers receive 404. Existing authentication/tenant/CRM refusals still apply. Both new routes have a 60-per-minute throttle and names under `mobile.member.me.*`, so success/refusal envelopes include `data`.

## Program interests (unchanged)

`GET` and `PUT /api/mobile/masjids/{id}/interests` retain their existing member-token, tenant and CRM rules. GET returns:

```json
{"status":"success","data":[{"id":7,"title":"Practice program","summary":"Program updates","interested":true}]}
```

PUT is a whole-set replace: `{"service_ids":[7]}` or `{"service_ids":[]}` to clear. The existing treatment of foreign IDs and error envelopes is unchanged. There is no write to interests from the new question endpoint and no question field in verify-code/password responses. The app saves questions after sign-in, not during code verification.

## Ownership, retention and office access

Answers live in `member_question_answers`, keyed uniquely by organisation/contact, with `member_question_helps` for selected help IDs. They are never Contact attributes, tags, directory relations or exports. All three free-text fields use Laravel encrypted casts on TEXT columns, following the student allergy note pattern; option IDs remain plaintext so the office can filter. Staging drops both answer tables and classifies all three encrypted columns.

Only SuperAdmin edits organisation-specific asked states, prompts and option lists, through `GET/PUT /api/admin/masjids/{id}/member-question-options` in the existing organisation switch panel. First activation seeds the defaults once. The editor GET returns `field`, `occupation`, `help` lists (including inactive rows with `id`, `label`, `active`, `is_other`, `position`), `settings:{field:{active:true,prompt:"…"},occupation:{active:true,prompt:"…"},help:{active:false,prompt:"…"}}`, and `questions` for asked kinds. PUT requires all three complete lists and all three settings (`active`, required `prompt` up to 80 characters); option IDs and Other identities cannot cross kinds or organisations. New options have no Other identity. String checkbox booleans from form encoding are accepted. Responses use the same editor shape. Renaming, ordering and deactivation retain IDs and answers; omission deactivates, never deletes. An organisation administrator cannot edit lists or answers.

The office uses its existing `view contacts` permission for `GET .../contacts/{contact_id}/from-app`, a separate read, and `GET .../contacts/from-app-filters`. The directory accepts `field_id`, `occupation_id`, `help_id`, `interest_id`; unasked-kind filters are ignored; it returns the existing Contact payload without answers. The From the app block also shows that member's existing service interests. No office answer-writing endpoint exists. From-app `data` adds `{questions:[{kind:"field",prompt:"…",answer:[{id:2,label:"Healthcare"}],text:null},…],interests:[{id:7,title:"…"}]}`; only asked kinds appear in `questions`, in the same fixed order, and selected inactive options remain readable. The existing `occupation`, `occupation_text`, `help`, `help_text` and `interests` response keys remain at no extra query cost, including retained unasked answers; the updated block reads `questions` to decide what to show. Empty `answer:[]` means unanswered. Filters additionally receive all lists/settings plus the asked `questions` and interests.

The contacts directory displays "Members by field" and "Members by profession", each only if that kind is asked. `GET /api/admin/masjids/{id}/contacts/from-app-counts` returns `data:{field:[{id:2,label:"Healthcare",count:50},…],occupation:[…]}` (unasked keys absent; `data:{}` when neither kind is asked). One aggregate query per table, sorted by count descending, then label and ID. All retained options, including inactive and zero-count options, appear. Only non-deleted contacts in this organisation with a selected answer count; unanswered members do not. Revoked logins remain counted while the contact/answer remains in the directory. CSV is `GET .../contacts/from-app-counts/csv`, filename `member-question-counts.csv`, UTF-8 with a byte-order mark, two titled tables of Option, Members; unasked tables omitted. No contact IDs, names or answer free text appear. Option labels are escaped against spreadsheet formulas. Both reads require the same CRM/tenant/`view contacts`/switch fences as the existing office reads.

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

Volunteer is configured by the organisation's own admin at `/masjid/app-tabs`, using `GET/PUT /api/admin/masjids/{id}/app-volunteer`. The default title is Volunteer, maximum 30 characters; the address is nullable to clear, otherwise https, valid host, no credentials, ASCII, maximum 500 characters. A saved link can be tested in a new tab. The tab save accepts catalogue IDs and the literal strings `"volunteer"` and `"menu"`, for example `{"feature_ids":[9,"volunteer"]}`.

## Query and cache evidence

With all three grants off, the tested cold/warm mobile carriers, office list/detail, interests GET and account-deletion body match a clean 109ce744 export. Account-deletion SQL adds the explicit answer-table existence check and answer DELETE; every legacy statement remains pinned to the unchanged fixture. Tabs OFF adds zero queries. Tabs ON-empty and ON-chosen each add one indexed joined query per carrier. Questions adds one joined query for all settings/lists; Volunteer adds one settings query while enabled. The directory with a help filter executes six application SQL statements (including asked-kind validation) at both 10 and 60 contacts. Array-cache pins preserve every legacy application statement. A separate SQLite database-cache pin includes infrastructure: detail OFF is nine cold statements or one warm statement; ON-empty and ON-chosen are ten cold or two warm, exactly one extra selection query. Answer and tab writes leave capability overrides unchanged; no private presence keys or backfill are used.

Tabs, Volunteer and list saves, grant changes and catalogue renames invalidate organisation detail after commit. The database detail cache uses an invalidation revision in the same entry and a conditional publication write, avoiding a second cache lookup. Other drivers use generation addresses. A fill begun before a save cannot overwrite the current invalidation. The database cache publication statement is intentionally conditional; cold/warm counts and application SQL are pinned. V1 settings and the added data reads themselves are uncached. SQLite proves controlled interleaving and transaction rollback, not two-connection MySQL contention. Native decoding and browser geometry remain separate verification work.
