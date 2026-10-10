<?php

use App\Models\{ClassSubject, Contact, Group, GroupMembership, GroupStaff, Masjid, MasjidUser, SubjectNote, SubjectPiece, SubjectPieceMark, User};
use App\Support\{CapabilityWriter, ClassSubjectMode, PerformanceLevel, SchoolSettings, TenantContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, DB, Route};

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    fake()->seed(80424);
    $this->travelTo(\Carbon\Carbon::parse('2026-10-09 16:00:00', 'UTC'));
    $this->org = Masjid::create(['name' => 'Practice School', 'email' => 'sharing@example.invalid', 'phone' => '+15555550100', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true, 'capability_overrides' => ['class_subjects' => true, 'class_subject_work' => true, 'class_subject_sharing' => true]]);
    $this->org->forceFill(['capability_overrides' => ['class_subjects' => true, 'class_subject_work' => true, 'class_subject_sharing' => true]])->save();
    $this->group = Group::factory()->create(['masjid_id' => $this->org->id, 'name' => 'Practice Class', 'slug' => 'practice-class', 'kind' => 'class']);
    $this->subject = ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'Science', 'position' => 0]);
    $this->otherSubject = ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'Arabic', 'position' => 1]);
    $this->teacher = User::factory()->create(['type' => 'Teacher', 'name' => 'Practice Teacher', 'email' => 'teacher@example.invalid', 'phone' => '+15555550101']);
    $this->office = User::factory()->create(['type' => 'SuperAdmin', 'name' => 'Practice Office', 'email' => 'office@example.invalid', 'phone' => '+15555550102']);
    MasjidUser::create(['masjid_id' => $this->org->id, 'user_id' => $this->teacher->id, 'role' => 'teacher', 'is_default' => true]);
    $this->staff = GroupStaff::withOfficeSubjectChoice(fn () => GroupStaff::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'user_id' => $this->teacher->id, 'subjects' => null, 'class_subject_ids' => null]));
    $this->parent = Contact::factory()->create(['masjid_id' => $this->org->id, 'first_name' => 'Practice', 'last_name' => 'Guardian', 'login_email' => 'guardian@example.invalid', 'login_enabled_at' => now()]);
    $this->otherParent = Contact::factory()->create(['masjid_id' => $this->org->id, 'login_email' => 'another@example.invalid', 'login_enabled_at' => now()]);
    $this->child = function (?Contact $guardian = null) {
        $contact = Contact::factory()->create(['masjid_id' => $this->org->id, 'first_name' => 'Practice', 'last_name' => 'Student']);
        $member = GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $contact->id, 'role' => 'member', 'grade_label' => '1st']);
        GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => ($guardian ?? $this->parent)->id, 'role' => 'guardian', 'guardian_of_contact_id' => $contact->id, 'provenance' => 'confirmed']);
        return $member;
    };
    $this->one = ($this->child)(); $this->two = ($this->child)(); $this->foreignChild = ($this->child)($this->otherParent);
    $this->piece = SubjectPiece::create(['masjid_id' => $this->org->id, 'class_subject_id' => $this->subject->id, 'source' => 'own', 'title' => 'Practice piece']);
    $this->teacherToken = $this->teacher->createToken('practice', ['staff'])->plainTextToken;
    $this->officeToken = $this->office->createToken('practice', ['staff'])->plainTextToken;
    $this->familyToken = $this->parent->createToken('practice', ['family'])->plainTextToken;
    $this->otherToken = $this->otherParent->createToken('practice', ['family'])->plainTextToken;
    $this->childToken = $this->parent->createStudentHandoffToken($this->one->id)->plainTextToken;
    $this->base = "/api/teacher/masjids/{$this->org->id}/groups/{$this->group->id}/subjects/{$this->subject->id}";
    $this->familyBase = "/api/family/masjids/{$this->org->id}/groups/{$this->group->id}";
    $this->subjectsUrl = fn ($member = null) => $this->familyBase.'/members/'.($member ?? $this->one)->id.'/subjects';
    $this->asToken = function ($token) { Auth::forgetGuards(); app(TenantContext::class)->forgetTenant(); return $this->withToken($token); };
    $this->saveMark = function ($shared = true, $version = 'loaded', $level = 3, $comment = 'Practice comment', $member = null) {
        $member ??= $this->one;
        $stored = SubjectPieceMark::where('subject_piece_id', $this->piece->id)->where('group_membership_id', $member->id)->first();
        return ($this->asToken)($this->teacherToken)->putJson($this->base.'/marks', ['source' => 'own', 'piece_id' => $this->piece->id, 'marks' => [[
            'group_membership_id' => $member->id, 'level' => $level, 'comment' => $comment, 'shared_with_family' => $shared,
            'updated_at' => $version === 'loaded' ? $stored?->updated_at?->toISOString() : $version,
        ]]]);
    };
    $this->note = fn ($shared = true, $member = null) => ($this->asToken)($this->teacherToken)->postJson($this->base.'/notes', ['body' => 'Practice note', 'shared_with_family' => $shared, 'group_membership_id' => $member?->id]);
});

afterEach(fn () => app(TenantContext::class)->forgetTenant());

it('walk 20 keeps unshared items out of all family payloads and their addresses are 404', function () {
    ($this->saveMark)(false)->assertOk(); $note = ($this->note)(false, $this->one)->assertCreated()->json('data.id');
    ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)())->assertOk()->assertJsonCount(0, 'data');
    ($this->asToken)($this->familyToken)->getJson($this->familyBase)->assertOk()->assertJsonCount(0, 'data.children.0.subjects');
    $mark = SubjectPieceMark::firstOrFail();
    foreach (['marks/'.$mark->id, 'notes/'.$note] as $suffix) ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)().'/'.$this->subject->id.'/'.$suffix)->assertNotFound();
});

it('walk 21 shows shared marks and child notes only to that childs guardians and isolates siblings', function () {
    ($this->saveMark)()->assertOk(); ($this->note)(true, $this->one)->assertCreated();
    ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)())->assertOk()->assertJsonPath('data.0.name', 'Science')->assertJsonPath('data.0.marks.0.title', 'Practice piece')->assertJsonPath('data.0.marks.0.level_label', 'Meets')->assertJsonPath('data.0.marks.0.comment', 'Practice comment')->assertJsonPath('data.0.notes.0.body', 'Practice note');
    ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)($this->two))->assertOk()->assertJsonCount(0, 'data');
    ($this->asToken)($this->otherToken)->getJson(($this->subjectsUrl)())->assertNotFound();
    ($this->asToken)($this->otherToken)->getJson(($this->subjectsUrl)($this->foreignChild))->assertOk()->assertJsonCount(0, 'data');
});

it('walk 22 shares whole class updates by the Class Story consent rule only', function () {
    $note = ($this->note)()->assertCreated()->json('data.id');
    ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)())->assertOk()->assertJsonCount(0, 'data');
    GroupMembership::where('contact_id', $this->parent->id)->where('role', 'guardian')->update(['consent_granted_at' => now(), 'consent_scope' => 'feed']);
    ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)())->assertOk()->assertJsonPath('data.0.notes.0.id', $note);
    ($this->asToken)($this->otherToken)->getJson(($this->subjectsUrl)($this->foreignChild))->assertOk()->assertJsonCount(0, 'data');
    GroupMembership::where('contact_id', $this->parent->id)->update(['consent_granted_at' => null, 'consent_scope' => null]);
    ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)().'/'.$this->subject->id.'/notes/'.$note)->assertNotFound();
});

it('walk 23 unticking removes marks and notes at once including warm family reads', function () {
    ($this->saveMark)()->assertOk(); $note = ($this->note)(true, $this->one)->assertCreated()->json('data.id');
    ($this->asToken)($this->familyToken)->getJson($this->familyBase)->assertOk()->assertJsonCount(1, 'data.children.0.subjects');
    $mark = SubjectPieceMark::firstOrFail(); ($this->saveMark)(false)->assertOk();
    ($this->asToken)($this->teacherToken)->putJson($this->base.'/notes/'.$note, ['body' => 'Practice note', 'shared_with_family' => false, 'version' => \App\Models\SubjectNote::versionOf(\App\Models\SubjectNote::findOrFail($note)->body, \App\Models\SubjectNote::findOrFail($note)->shared_with_family)])->assertOk();
    ($this->asToken)($this->familyToken)->getJson($this->familyBase)->assertOk()->assertJsonCount(0, 'data.children.0.subjects');
    foreach (['marks/'.$mark->id, 'notes/'.$note] as $suffix) ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)().'/'.$this->subject->id.'/'.$suffix)->assertNotFound();
});

it('walk 24 child mode carries no sharing and refuses every new family route', function () {
    ($this->saveMark)()->assertOk(); $note = ($this->note)(true, $this->one)->assertCreated()->json('data.id'); $mark = SubjectPieceMark::firstOrFail();
    $student = ($this->asToken)($this->childToken)->getJson($this->familyBase.'/members/'.$this->one->id.'/student/me')->assertOk()->json();
    expect(json_encode($student))->not->toContain('subjects', 'shared_with_family', 'Practice comment');
    foreach (['', '/'.$this->subject->id.'/marks/'.$mark->id, '/'.$this->subject->id.'/notes/'.$note] as $suffix) ($this->asToken)($this->childToken)->getJson(($this->subjectsUrl)().$suffix)->assertForbidden();
});

it('retains child history as report cards do after leaving or moving but withholds the class story', function () {
    ($this->saveMark)()->assertOk(); ($this->note)(true, $this->one)->assertCreated(); ($this->note)()->assertCreated();
    GroupMembership::where('contact_id', $this->parent->id)->update(['consent_granted_at' => now(), 'consent_scope' => 'media']);
    $this->one->markLeftByStaff($this->office, now()->toDateString())->save();
    // Withdraw the second sibling too: no remaining current edge in this class grants feed.
    $this->two->markLeftByStaff($this->office, now()->toDateString())->save();
    ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)())->assertOk()->assertJsonCount(1, 'data.0.notes')->assertJsonCount(1, 'data.0.marks');
});

it('hidden subjects disclose nothing and a limited teacher cannot share outside their subjects', function () {
    ($this->saveMark)()->assertOk(); $note = ($this->note)(true, $this->one)->assertCreated()->json('data.id');
    GroupStaff::withOfficeSubjectChoice(fn () => $this->staff->update(['class_subject_ids' => [$this->otherSubject->id]]));
    ($this->saveMark)(false)->assertForbidden();
    ($this->asToken)($this->teacherToken)->putJson($this->base.'/notes/'.$note, ['body' => 'Practice', 'shared_with_family' => false])->assertForbidden();
    $this->subject->update(['hidden_at' => now()]);
    ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)())->assertOk()->assertJsonCount(0, 'data');
    ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)().'/'.$this->subject->id.'/notes/'.$note)->assertNotFound();
});

it('tick only changes conflict at 409 and edits preserve sharing while empty marks delete', function () {
    ($this->saveMark)()->assertOk(); $version = SubjectPieceMark::firstOrFail()->updated_at->toISOString();
    ($this->saveMark)(false, $version)->assertOk(); ($this->saveMark)(true, $version)->assertStatus(409);
    ($this->saveMark)()->assertOk();
    ($this->asToken)($this->teacherToken)->putJson($this->base.'/marks', ['source' => 'own', 'piece_id' => $this->piece->id, 'marks' => [['group_membership_id' => $this->one->id, 'level' => 4, 'comment' => 'Edited', 'updated_at' => SubjectPieceMark::firstOrFail()->updated_at->toISOString()]]])->assertOk();
    $note = ($this->note)(true, $this->one)->assertCreated()->json('data.id');
    ($this->asToken)($this->teacherToken)->putJson($this->base.'/notes/'.$note, ['body' => 'Edited note', 'version' => \App\Models\SubjectNote::versionOf(\App\Models\SubjectNote::findOrFail($note)->body, \App\Models\SubjectNote::findOrFail($note)->shared_with_family)])->assertOk()->assertJsonPath('data.shared_with_family', true);
    ($this->asToken)($this->officeToken)->getJson(str_replace('/teacher/', '/admin/', $this->base).'/work')->assertOk()->assertJsonPath('data.own_pieces.0.marks.0.shared_with_family', true)->assertJsonPath('data.notes.0.shared_with_family', true);
    ($this->saveMark)(true, 'loaded', null, null)->assertOk(); expect(SubjectPieceMark::count())->toBe(0);
});

it('normalizes browser sharing booleans and rejects invalid values', function () {
    foreach (['true', 'false', '1', '0'] as $flag) ($this->saveMark)($flag)->assertOk();
    foreach (['perhaps', [], null] as $flag) ($this->saveMark)($flag)->assertUnprocessable();
    ($this->note)('true', $this->one)->assertCreated()->assertJsonPath('data.shared_with_family', true);
});

it('the sharing grant stays dark by default and refuses enable without both dependencies', function () {
    expect(SchoolSettings::classSubjectSharing($this->org))->toBeTrue();
    $this->org->forceFill(['capability_overrides' => []])->save();
    expect($this->org->capabilities)->not->toHaveKey('class_subject_sharing');
    expect(config('capabilities.class_subject_sharing.defaults'))->toBe(['masjid' => false, 'school' => false, 'community' => false]);
    ($this->asToken)($this->officeToken)->patchJson('/api/admin/masjids/'.$this->org->id.'/capabilities/class_subject_sharing', ['enabled' => true])->assertUnprocessable();
    ($this->asToken)($this->officeToken)->getJson('/api/admin/masjids/'.$this->org->id.'/capabilities')->assertOk()->assertDontSee('class_subject_sharing');
    ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)())->assertNotFound();
});

it('every new family route fences real foreign ids and a foreign tenant URL', function () {
    ($this->saveMark)()->assertOk(); $note = ($this->note)(true, $this->one)->assertCreated()->json('data.id'); $mark = SubjectPieceMark::firstOrFail();
    app(TenantContext::class)->forgetTenant();
    $otherOrg = Masjid::create(['name' => 'Other Practice School', 'email' => 'other-school@example.invalid', 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'phone' => '+15555550109', 'org_type' => 'school', 'crm_enabled' => true, 'capability_overrides' => ['class_subjects' => true, 'class_subject_work' => true, 'class_subject_sharing' => true]]);
    $otherGroup = Group::factory()->create(['masjid_id' => $otherOrg->id, 'kind' => 'class']);
    $otherSubject = ClassSubject::create(['masjid_id' => $otherOrg->id, 'group_id' => $otherGroup->id, 'name' => 'Science']);
    $otherPiece = SubjectPiece::create(['masjid_id' => $otherOrg->id, 'class_subject_id' => $otherSubject->id, 'source' => 'own', 'title' => 'Foreign piece']);
    $otherNote = SubjectNote::create(['masjid_id' => $otherOrg->id, 'class_subject_id' => $otherSubject->id, 'body' => 'Foreign note']);
    $foreignContact = Contact::factory()->create(['masjid_id' => $otherOrg->id]);
    $foreignMember = GroupMembership::create(['masjid_id' => $otherOrg->id, 'group_id' => $otherGroup->id, 'contact_id' => $foreignContact->id, 'role' => 'member']);
    $foreignMark = SubjectPieceMark::create(['masjid_id' => $otherOrg->id, 'subject_piece_id' => $otherPiece->id, 'group_membership_id' => $foreignMember->id, 'level' => 4]);
    foreach (['', '/'.$this->subject->id.'/marks/'.$mark->id, '/'.$this->subject->id.'/notes/'.$note] as $suffix) {
        ($this->asToken)($this->otherToken)->getJson(($this->subjectsUrl)().$suffix)->assertNotFound();
        ($this->asToken)($this->familyToken)->getJson(str_replace('/masjids/'.$this->org->id, '/masjids/'.$otherOrg->id, ($this->subjectsUrl)().$suffix))->assertForbidden();
        ($this->asToken)($this->familyToken)->getJson(str_replace('/groups/'.$this->group->id, '/groups/'.$otherGroup->id, ($this->subjectsUrl)().$suffix))->assertNotFound();
    }
    foreach (['marks/'.$foreignMark->id, 'notes/'.$otherNote->id] as $suffix) ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)().'/'.$this->subject->id.'/'.$suffix)->assertNotFound();
    ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)($foreignMember))->assertNotFound();
    ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)().'/'.$otherSubject->id.'/notes/'.$note)->assertNotFound();
});

it('family subject disclosure uses fixed SQL counts for 1 and 3 children and 1 and 30 shared items', function () {
    $counts = [];
    foreach ([1, 3] as $children) {
        if ($children === 1) GroupMembership::where('contact_id', $this->parent->id)->where('guardian_of_contact_id', $this->two->contact_id)->delete();
        else { ($this->child)(); GroupMembership::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'contact_id' => $this->parent->id, 'role' => 'guardian', 'guardian_of_contact_id' => $this->two->contact_id, 'provenance' => 'confirmed']); }
        foreach ([1, 30] as $items) {
            for ($i = SubjectPieceMark::count(); $i < $items; $i++) {
                $subject = $i === 0 ? $this->subject : (ClassSubject::where('group_id', $this->group->id)->where('name', 'Practice subject '.$i)->first() ?? ClassSubject::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'name' => 'Practice subject '.$i, 'position' => $i + 2]));
                $piece = $i === 0 ? $this->piece : SubjectPiece::create(['masjid_id' => $this->org->id, 'class_subject_id' => $subject->id, 'source' => 'own', 'title' => 'Practice piece '.$i]);
                SubjectPieceMark::create(['masjid_id' => $this->org->id, 'subject_piece_id' => $piece->id, 'group_membership_id' => $this->one->id, 'level' => 3])->forceFill(['shared_with_family' => true])->save();
                SubjectNote::create(['masjid_id' => $this->org->id, 'class_subject_id' => $subject->id, 'group_membership_id' => $this->one->id, 'body' => 'Practice note '.$i])->forceFill(['shared_with_family' => true])->save();
            }
            // Each measured request has a cold token last-used write; a frozen clock otherwise elides it.
            DB::table('personal_access_tokens')->where('tokenable_id', $this->parent->id)->update(['last_used_at' => null]);
            ($this->asToken)($this->familyToken); DB::flushQueryLog(); DB::enableQueryLog();
            $response = $this->getJson($this->familyBase)->assertOk()->assertJsonCount($children, 'data.children')->assertJsonCount($items, 'data.children.0.subjects');
            $counts[$children.'children-'.$items.'items'] = count(DB::getQueryLog());
            file_put_contents(base_path('artifacts/subject-sharing-query-'.$children.'-'.$items.'.json'), json_encode(array_column(DB::getQueryLog(), 'query'), JSON_PRETTY_PRINT)); DB::disableQueryLog();
            expect($response->headers->get('Cache-Control'))->toContain('no-store');
        }
        // Reset item size before the next child-size run.
        SubjectNote::query()->delete(); SubjectPieceMark::query()->delete();
    }
    file_put_contents(base_path('artifacts/subject-sharing-query-counts.json'), json_encode($counts, JSON_PRETTY_PRINT));
    expect($counts)->toBe(['1children-1items' => 20, '1children-30items' => 20, '3children-1items' => 20, '3children-30items' => 20]);
});

it('a real roster move keeps shared records and published report cards under the old class', function () {
    ($this->saveMark)()->assertOk(); ($this->note)(true, $this->one)->assertCreated();
    $card = \App\Models\ReportCard::create(['masjid_id' => $this->org->id, 'group_id' => $this->group->id, 'group_membership_id' => $this->one->id, 'type' => 'report_card', 'school_year' => '2026-2027', 'term' => 1, 'grade_label' => '1st']);
    $card->forceFill(['published_at' => now()])->save();
    $next = Group::factory()->create(['masjid_id' => $this->org->id, 'kind' => 'class', 'name' => 'Next Practice Class']);
    app(TenantContext::class)->set($this->org->id);
    app(\App\Support\RosterMove::class)->move($this->group, $this->one->fresh(), $next->id, '2026-10-09', [], $this->office);
    ($this->asToken)($this->familyToken)->getJson($this->familyBase.'/members/'.$this->one->id.'/report-cards')->assertOk()->assertJsonPath('data.0.id', $card->id);
    ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)())->assertOk()->assertJsonCount(1, 'data.0.marks')->assertJsonCount(1, 'data.0.notes');
});

it('sharing ON defaults every new mark and note to private without an explicit choice', function () {
    ($this->asToken)($this->teacherToken)->putJson($this->base.'/marks', ['source' => 'own', 'piece_id' => $this->piece->id, 'marks' => [['group_membership_id' => $this->one->id, 'level' => 3, 'updated_at' => null]]])->assertOk();
    ($this->asToken)($this->teacherToken)->postJson($this->base.'/notes', ['body' => 'Practice note', 'group_membership_id' => $this->one->id])->assertCreated()->assertJsonPath('data.shared_with_family', false);
    ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)())->assertOk()->assertJsonCount(0, 'data');
});

it('pending guardian provenance with stored consent grants no shared records', function () {
    ($this->saveMark)()->assertOk(); ($this->note)()->assertCreated();
    GroupMembership::where('contact_id', $this->parent->id)->update(['provenance' => 'self_asserted', 'consent_granted_at' => now(), 'consent_scope' => 'media']);
    ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)())->assertNotFound();
});

it('an edited shared note gets a new translation source version even within the same second', function () {
    $id = ($this->note)(true, $this->one)->assertCreated()->json('data.id');
    $before = ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)())->assertOk()->json('data.0.notes.0.translation_version');
    ($this->asToken)($this->teacherToken)->putJson($this->base.'/notes/'.$id, ['body' => 'Corrected note', 'version' => \App\Models\SubjectNote::versionOf(\App\Models\SubjectNote::findOrFail($id)->body, \App\Models\SubjectNote::findOrFail($id)->shared_with_family)])->assertOk();
    $after = ($this->asToken)($this->familyToken)->getJson(($this->subjectsUrl)())->assertOk()->json('data.0.notes.0.translation_version');
    expect($before)->toBe(hash('sha256', 'Practice note')); expect($after)->toBe(hash('sha256', 'Corrected note'));
});

it('review refuses a note edit made against an older version, so a stale tab cannot share it again', function () {
    $list = fn () => collect(($this->asToken)($this->teacherToken)->getJson($this->base.'/notes')->assertOk()->json('data'));
    $created = ($this->asToken)($this->teacherToken)->postJson($this->base.'/notes', ['body' => 'Practice note', 'group_membership_id' => $this->one->id, 'shared_with_family' => true])->assertSuccessful()->json('data');
    $stale = $list()->firstWhere('id', $created['id'])['version'];
    expect($stale)->toBeString();

    // Tab A un-shares it.
    ($this->asToken)($this->teacherToken)->putJson($this->base.'/notes/'.$created['id'], ['body' => 'Practice note', 'shared_with_family' => false, 'version' => $stale])->assertOk();
    expect(SubjectNote::findOrFail($created['id'])->shared_with_family)->toBeFalse();

    // Tab B, still holding the shared version, edits the words: refused, whatever it says about sharing.
    foreach ([['shared_with_family' => true], []] as $extra) {
        ($this->asToken)($this->teacherToken)->putJson($this->base.'/notes/'.$created['id'], ['body' => 'Edited in a stale tab', 'version' => $stale] + $extra)
            ->assertStatus(409)->assertJsonPath('message', 'Someone else changed this note. Reload to see it.');
    }
    $note = SubjectNote::findOrFail($created['id']);
    expect($note->shared_with_family)->toBeFalse();
    expect($note->body)->toBe('Practice note');

    // An edit with no version at all is refused too, and one made against the current version is accepted without touching the tick.
    ($this->asToken)($this->teacherToken)->putJson($this->base.'/notes/'.$created['id'], ['body' => 'No version'])->assertStatus(422);
    $current = $list()->firstWhere('id', $created['id'])['version'];
    ($this->asToken)($this->teacherToken)->putJson($this->base.'/notes/'.$created['id'], ['body' => 'Corrected', 'version' => $current])->assertOk();
    $note = SubjectNote::findOrFail($created['id']);
    expect($note->body)->toBe('Corrected');
    expect($note->shared_with_family)->toBeFalse();
});
