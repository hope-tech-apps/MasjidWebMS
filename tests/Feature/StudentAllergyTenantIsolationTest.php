<?php

namespace Tests\Feature;

use App\Models\{Contact, Masjid, StudentAllergy};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StudentAllergyTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite'=>[
            'driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true,
        ]]);
    }

    #[Test]
    public function a_school_cannot_read_update_or_delete_another_schools_allergy_and_create_stamps_the_tenant(): void
    {
        $tenant=app(TenantContext::class); $tenant->forgetTenant();
        $a=$this->makeMasjid('a'); $b=$this->makeMasjid('b');
        $local=Contact::factory()->create(['masjid_id'=>$a->id]);
        $foreign=Contact::factory()->create(['masjid_id'=>$b->id]);
        $note=StudentAllergy::create(['masjid_id'=>$b->id,'contact_id'=>$foreign->id,'allergies'=>'Foreign private note','source'=>'office']);
        $tenant->set($a->id);
        try {
            $this->assertNull(StudentAllergy::find($note->id));
            $this->assertSame(0,StudentAllergy::whereKey($note->id)->update(['source'=>'form']));
            $this->assertSame(0,StudentAllergy::whereKey($note->id)->delete());
            $stamped=StudentAllergy::create(['masjid_id'=>$b->id,'contact_id'=>$local->id,'allergies'=>'Local private note','source'=>'office']);
            $this->assertSame($a->id,(int)$stamped->masjid_id);
        } finally {
            $tenant->forgetTenant();
        }
        $this->assertSame('Foreign private note',$note->fresh()->allergies);
        $this->assertSame('office',$note->fresh()->source);
        $this->assertDatabaseHas('student_allergies',['id'=>$note->id,'masjid_id'=>$b->id]);
    }

    private function makeMasjid(string $suffix): Masjid
    {
        return Masjid::create(['name'=>'Practice School '.$suffix,'email'=>$suffix.'@example.invalid','phone'=>'+1555555020'.($suffix==='a'?'2':'3'),'country_id'=>'1','city_id'=>'1','address'=>'Practice','latitude'=>0,'longitude'=>0,'org_type'=>'school']);
    }
}
