<?php

namespace Tests\Support;

use App\Models\{AttendanceRecord, Contact, Group, GroupMembership, Masjid, SchoolYear};
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;

trait ParentAttendanceFixtures
{
    private function makeMasjid(string $suffix = 'a'): Masjid
    {
        app(TenantContext::class)->forgetTenant();
        $school = Masjid::create(['name' => 'Practice School '.$suffix, 'email' => 'office-'.$suffix.'@example.invalid', 'phone' => '+1555555010'.($suffix === 'a' ? '1' : '2'), 'country_id' => '1', 'city_id' => '1', 'address' => 'Practice', 'latitude' => 0, 'longitude' => 0, 'org_type' => 'school', 'crm_enabled' => true, 'timezone' => 'America/New_York', 'capability_overrides' => ['school_calendar' => true, 'school_calendar_terms' => true]]);
        $school->forceFill(['capability_overrides' => ['school_calendar' => true, 'school_calendar_terms' => true]])->save();
        return $school;
    }

    private function year(Masjid $school): SchoolYear
    {
        return SchoolYear::create(['masjid_id' => $school->id, 'label' => 'Practice year', 'first_day' => '2026-08-24', 'last_day' => '2027-06-30', 'meeting_weekdays' => [1,2,3,4,5]]);
    }

    private function classroom(Masjid $school, string $name = 'Practice Class'): Group
    {
        return Group::factory()->create(['masjid_id' => $school->id, 'kind' => 'class', 'name' => $name]);
    }

    private function enrol(Group $class, ?Contact $contact = null): GroupMembership
    {
        $contact ??= Contact::factory()->create(['masjid_id' => $class->masjid_id, 'first_name' => 'Sample', 'last_name' => 'Student']);
        return GroupMembership::create(['masjid_id' => $class->masjid_id, 'group_id' => $class->id, 'contact_id' => $contact->id, 'role' => 'member']);
    }

    private function guardian(GroupMembership $child, ?Contact $contact = null, string $provenance = 'confirmed'): Contact
    {
        $contact ??= Contact::factory()->create(['masjid_id' => $child->masjid_id, 'first_name' => 'Practice', 'last_name' => 'Guardian', 'email' => 'guardian@example.invalid', 'login_email' => null, 'login_enabled_at' => null]);
        $edge = GroupMembership::create(['masjid_id' => $child->masjid_id, 'group_id' => $child->group_id, 'contact_id' => $contact->id, 'role' => 'guardian', 'guardian_of_contact_id' => $child->contact_id, 'provenance' => $provenance]);
        $edge->forceFill(['provenance' => $provenance])->save();
        return $contact;
    }

    private function mark(GroupMembership $child, string $date = '2026-10-19', string $status = 'absent'): AttendanceRecord
    {
        return AttendanceRecord::create(['masjid_id' => $child->masjid_id, 'group_id' => $child->group_id, 'group_membership_id' => $child->id, 'session_date' => $date, 'status' => $status, 'note' => 'Staff-only practice note']);
    }

    private function family(Contact $parent): void
    {
        $parent->forceFill(['login_email' => $parent->email, 'login_enabled_at' => now()])->save();
        Auth::forgetGuards(); app(TenantContext::class)->forgetTenant();
        $this->withHeader('Authorization', 'Bearer '.$parent->createFamilyToken()->plainTextToken);
    }

    private function familyUrl(GroupMembership $child): string
    {
        return "/api/family/masjids/{$child->masjid_id}/groups/{$child->group_id}/members/{$child->id}/attendance";
    }
}
