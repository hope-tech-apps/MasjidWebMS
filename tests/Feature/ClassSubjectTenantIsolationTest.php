<?php

namespace Tests\Feature;

use App\Models\ClassSubject;
use App\Models\Group;
use App\Models\Masjid;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClassSubjectTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeMasjid(): Masjid
    {
        return Masjid::create([
            'name' => 'Practice '.uniqid(), 'email' => uniqid().'@example.invalid', 'phone' => '+1'.random_int(1000000000, 9999999999),
            'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school',
        ]);
    }

    #[Test]
    public function a_bound_tenant_cannot_read_write_or_delete_another_organizations_class_subject(): void
    {
        $a = $this->makeMasjid();
        $b = $this->makeMasjid();
        $ga = Group::factory()->create(['masjid_id' => $a->id]);
        $gb = Group::factory()->create(['masjid_id' => $b->id]);
        $foreign = ClassSubject::create(['masjid_id' => $b->id, 'group_id' => $gb->id, 'name' => 'Science']);
        $tenant = app(TenantContext::class);
        $tenant->set($a->id);
        $this->assertNull(ClassSubject::find($foreign->id));
        $this->assertSame(0, ClassSubject::whereKey($foreign->id)->update(['name' => 'Wrong']));
        $this->assertSame(0, ClassSubject::whereKey($foreign->id)->delete());
        $mine = ClassSubject::create(['masjid_id' => $b->id, 'group_id' => $ga->id, 'name' => 'Local']);
        $this->assertSame($a->id, (int) $mine->masjid_id);
        $tenant->forgetTenant();
        $this->assertSame('Science', $foreign->fresh()->name);
    }
}
