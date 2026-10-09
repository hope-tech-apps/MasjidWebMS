<?php

namespace Tests\Feature;

use App\Models\{ClassSubject, Contact, Group, GroupMembership, Masjid, SubjectNote, SubjectPiece, SubjectPieceMark};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubjectWorkTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeMasjid(): Masjid
    {
        return Masjid::create(['name' => 'Practice '.uniqid(), 'email' => uniqid().'@example.invalid', 'phone' => '+1'.random_int(1000000000, 9999999999), 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school']);
    }

    #[Test]
    public function every_subject_work_model_refuses_foreign_reads_updates_and_deletes_and_stamps_creates(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        $tenant = app(TenantContext::class);
        $tenant->forgetTenant();
        $a = $this->makeMasjid(); $b = $this->makeMasjid();
        $records = [];
        foreach ([$a, $b] as $org) {
            $group = Group::factory()->create(['masjid_id' => $org->id]);
            $subject = ClassSubject::create(['masjid_id' => $org->id, 'group_id' => $group->id, 'name' => 'Science']);
            $member = GroupMembership::create(['masjid_id' => $org->id, 'group_id' => $group->id, 'contact_id' => Contact::factory()->create(['masjid_id' => $org->id])->id, 'role' => 'member']);
            $piece = SubjectPiece::create(['masjid_id' => $org->id, 'class_subject_id' => $subject->id, 'source' => 'own', 'title' => 'Practice']);
            $mark = SubjectPieceMark::create(['masjid_id' => $org->id, 'subject_piece_id' => $piece->id, 'group_membership_id' => $member->id, 'level' => 3, 'comment' => 'Practice']);
            $note = SubjectNote::create(['masjid_id' => $org->id, 'class_subject_id' => $subject->id, 'body' => 'Practice']);
            $records[$org->id] = [$subject, $member, $piece, $mark, $note];
        }
        try {
            $tenant->set($a->id);
            foreach ([[SubjectPiece::class, 'title'], [SubjectPieceMark::class, 'comment'], [SubjectNote::class, 'body']] as $i => [$model, $field]) {
                $foreign = $records[$b->id][$i + 2];
                $this->assertNull($model::find($foreign->id));
                $this->assertSame(0, $model::whereKey($foreign->id)->update([$field => 'Wrong']));
                $this->assertSame(0, $model::whereKey($foreign->id)->delete());
            }
            [$subject, $member] = $records[$a->id];
            $piece = SubjectPiece::create(['masjid_id' => $b->id, 'class_subject_id' => $subject->id, 'source' => 'own', 'title' => 'Local']);
            $mark = SubjectPieceMark::create(['masjid_id' => $b->id, 'subject_piece_id' => $piece->id, 'group_membership_id' => $member->id, 'level' => 4]);
            $note = SubjectNote::create(['masjid_id' => $b->id, 'class_subject_id' => $subject->id, 'body' => 'Local']);
            foreach ([$piece, $mark, $note] as $row) $this->assertSame((int) $a->id, (int) $row->masjid_id);
        } finally {
            $tenant->forgetTenant();
        }
        foreach (array_slice($records[$b->id], 2) as $row) $this->assertNotNull($row->fresh());
    }
}
