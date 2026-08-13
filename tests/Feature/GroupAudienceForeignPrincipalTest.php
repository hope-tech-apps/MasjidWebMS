<?php

namespace Tests\Feature;

use App\Models\BehaviorAward;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupThread;
use App\Models\HifzEntry;
use App\Models\Masjid;
use App\Models\User;
use App\Support\GroupAudience;
use App\Support\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Support\ForeignTokenable;
use Tests\TestCase;

/**
 * T-015b — `App\Support\GroupAudience` accepts ANY authenticatable actor.
 *
 * WHY THIS SUITE EXISTS. `docs/t015-parent-identity-design.md` gives a Contact
 * its own Sanctum guard so a parent can read their own child's record, and §4
 * makes `GroupAudience` the seam that decision passes through. But the class
 * carried `?App\Models\User` at THIRTEEN distinct signatures, so a family
 * principal could not be PASSED to the disclosure logic at all — it died on a
 * TypeError at the PHP type boundary, which is not a decision this class made
 * and not one anybody could assert on. T-015b widens all thirteen to
 * `?Illuminate\Contracts\Auth\Authenticatable`, which is what unblocks T-015c.
 *
 * A widening is only interesting if it is REAL, so this suite proves two things
 * that a green existing suite cannot prove on its own:
 *
 *   1. A non-`User` authenticatable can be handed to every one of the thirteen
 *      — including the three private ones — without a TypeError. That is the
 *      widening.
 *   2. It is nonetheless REFUSED EVERYTHING. `identitiesFor()` narrows with
 *      `instanceof User` before the email bridge, so an actor this class cannot
 *      place on a roster resolves to no identity and therefore no standing.
 *      T-015e replaces that with a real Contact branch; until then the refusal
 *      is the correct answer, not an oversight.
 *
 * THE FIXTURE IS DELIBERATELY THE HAZARD, NOT A STRAWMAN. `ForeignTokenable`
 * (added by T-015a, reused here) is given the SAME email as a contact who holds
 * a LEADER membership in the group — the standing that grants everything. If
 * the narrowing were missing, or if someone later "simplified" it away, the
 * foreign principal would inherit that leader's entire view of a classroom of
 * children. The paired control assertions run the identical calls with the real
 * staff `User` behind that email and require the full leader standing, so the
 * refusals below can never pass vacuously because the fixture was wired wrong.
 *
 * Zero existing tests were modified: for a staff caller nothing here changed,
 * and the group / messaging / behaviour / ḥifẓ suites are that proof.
 */
class GroupAudienceForeignPrincipalTest extends TestCase
{
    use RefreshDatabase;

    /** The 13 signatures the design counted, and the count itself is the pin. */
    private const WIDENED_METHODS = [
        'identitiesFor',
        'mayReceive',
        'mayReceiveThread',
        'readableThreadsQuery',
        'mayReceiveAwardsAbout',
        'mayReceiveHifzAbout',
        'mayReceiveHifzEntry',
        'mayReceiveRecordAbout',
        'mayReceiveAward',
        'readableAwardsQuery',
        'readableHifzQuery',
        'constrainToOwnStudents',
        'standingIn',
        // The fourteenth, added when the parent-portal read scope moved into
        // this class: it narrows a family principal to their guardian edges, so
        // it takes a principal and must refuse an unrecognized one exactly as
        // the other thirteen do.
        'membershipsFor',
    ];

    /** The one email shared by the staff User, the leader Contact, and the fixture. */
    private const SHARED_EMAIL = 'leader@example.test';

    private TenantContext $tenant;

    private GroupAudience $audience;

    private Masjid $masjid;

    private Group $group;

    /** A staff row whose email bridges to the leader contact. */
    private User $staff;

    /** A non-User authenticatable claiming the very same email. */
    private ForeignTokenable $foreign;

    /** A student's participant membership — the subject of the records below. */
    private GroupMembership $student;

    private GroupThread $groupThread;

    private GroupThread $participantThread;

    private BehaviorAward $award;

    private HifzEntry $hifzEntry;

    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite-in-memory regardless of phpunit.xml.
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        ForeignTokenable::createTable();

        $this->tenant = app(TenantContext::class);
        $this->tenant->forgetTenant();

        $this->masjid = Masjid::create([
            'name' => 'Test Masjid ' . uniqid(),
            'email' => 'masjid-' . uniqid() . '@test.local',
            'phone' => '+1' . random_int(1000000000, 9999999999),
            'country_id' => '1',
            'city_id' => '1',
            'address' => '1 Test St',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'crm_enabled' => true,
        ]);

        $this->group = Group::factory()->create([
            'masjid_id' => $this->masjid->id,
            'name' => 'Grade 3',
            'slug' => 'grade-3',
            'kind' => Group::KIND_CLASS,
        ]);

        // The teacher: a contact carrying the shared email, LEADER of the group.
        $teacher = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'email' => self::SHARED_EMAIL,
        ]);

        GroupMembership::create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->group->id,
            'contact_id' => $teacher->id,
            'role' => GroupMembership::ROLE_LEADER,
        ]);

        // Every contact that must NOT be the caller gets a null email, so a
        // ContactFactory collision can never flip an assertion — see tests/CLAUDE.md.
        $child = Contact::factory()->create([
            'masjid_id' => $this->masjid->id,
            'email' => null,
        ]);

        $this->student = GroupMembership::create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->group->id,
            'contact_id' => $child->id,
            'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $this->groupThread = GroupThread::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->group->id,
            'scope' => GroupThread::SCOPE_GROUP,
            'about_membership_id' => null,
        ]);

        $this->participantThread = GroupThread::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->group->id,
            'scope' => GroupThread::SCOPE_PARTICIPANT,
            'about_membership_id' => $this->student->id,
        ]);

        $this->award = BehaviorAward::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->group->id,
            'group_membership_id' => $this->student->id,
        ]);

        $this->hifzEntry = HifzEntry::factory()->create([
            'masjid_id' => $this->masjid->id,
            'group_id' => $this->group->id,
            'group_membership_id' => $this->student->id,
        ]);

        $this->staff = User::factory()->create([
            'type' => 'MasjidAdmin',
            'email' => self::SHARED_EMAIL,
            'phone' => '+1' . random_int(1000000000, 9999999999),
        ]);

        // Shaped like the real hazard: same email as the leader contact, and an
        // admin `type`, exactly as T-015a's fixture comment describes.
        $this->foreign = ForeignTokenable::create([
            'name' => 'Foreign Principal',
            'email' => self::SHARED_EMAIL,
            'type' => 'SuperAdmin',
        ]);

        // Bound, as ResolveMasjidTenant binds it for a MasjidAdmin request —
        // identitiesFor() returns nothing at all while unbound, which would
        // make every refusal below vacuous.
        $this->tenant->set((int) $this->masjid->id);

        $this->audience = app(GroupAudience::class);
    }

    // -------------------------------------------------------------- the widening

    #[Test]
    public function every_group_audience_signature_takes_a_nullable_authenticatable(): void
    {
        $seen = [];

        foreach ((new \ReflectionClass(GroupAudience::class))->getMethods() as $method) {
            $first = $method->getParameters()[0] ?? null;

            if ($first === null) {
                continue;
            }

            $type = $first->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $this->assertNotSame(
                User::class,
                $type->getName(),
                "GroupAudience::{$method->getName()}() was narrowed back to ?User. "
                    . 'T-015b widened it on purpose — see the class docblock and '
                    . 'docs/t015-parent-identity-design.md §4; narrowing it re-blocks T-015c.'
            );

            if ($type->getName() === Authenticatable::class) {
                $this->assertTrue(
                    $type->allowsNull(),
                    "GroupAudience::{$method->getName()}() must stay nullable — an "
                        . 'unauthenticated caller is a decision this class makes, not a TypeError.'
                );

                $seen[] = $method->getName();
            }
        }

        sort($seen);
        $expected = self::WIDENED_METHODS;
        sort($expected);

        // The count is load-bearing: the design review found thirteen seams
        // where every proposal had assumed one, and `membershipsFor()` made a
        // fourteenth. A new seam must be ADDED to the list above deliberately —
        // the failure this pins is one that arrives silently.
        $this->assertSame($expected, $seen);
        $this->assertCount(14, $seen);
    }

    #[Test]
    public function a_non_user_principal_reaches_every_public_signature_and_is_refused(): void
    {
        // Each call would have been a TypeError before T-015b. That it now
        // returns a REFUSAL is the whole point: the decision is made inside the
        // class, where it can be asserted on.
        $this->assertSame([], $this->audience->identitiesFor($this->foreign));

        $this->assertFalse(
            $this->audience->mayReceive($this->foreign, $this->group, GroupAudience::DISCLOSURE_FEED)
        );
        $this->assertFalse(
            $this->audience->mayReceive($this->foreign, $this->group, GroupAudience::DISCLOSURE_MEDIA)
        );

        $this->assertFalse(
            $this->audience->mayReceiveThread($this->foreign, $this->group, $this->groupThread)
        );
        $this->assertFalse(
            $this->audience->mayReceiveThread($this->foreign, $this->group, $this->participantThread)
        );

        $this->assertFalse(
            $this->audience->mayReceiveAwardsAbout($this->foreign, $this->group, $this->student)
        );
        $this->assertFalse(
            $this->audience->mayReceiveAward($this->foreign, $this->group, $this->award)
        );
        $this->assertFalse(
            $this->audience->mayReceiveHifzAbout($this->foreign, $this->group, $this->student)
        );
        $this->assertFalse(
            $this->audience->mayReceiveHifzEntry($this->foreign, $this->group, $this->hifzEntry)
        );

        // The fourteenth seam. An unrecognized principal resolves to no
        // identity, so it speaks through no roster row — which is what every
        // refusal above is then computed from.
        $this->assertTrue(
            $this->audience->membershipsFor($this->foreign, $this->group)->isEmpty()
        );

        // Null, not an empty query: "no standing in this group at all", which
        // the controllers answer with 403.
        $this->assertNull($this->audience->readableThreadsQuery($this->foreign, $this->group));
        $this->assertNull($this->audience->readableAwardsQuery($this->foreign, $this->group));
        $this->assertNull($this->audience->readableHifzQuery($this->foreign, $this->group));
    }

    #[Test]
    public function a_non_user_principal_reaches_the_private_signatures_too(): void
    {
        $this->assertFalse($this->invokePrivate(
            'mayReceiveRecordAbout',
            [$this->foreign, $this->group, $this->student]
        ));

        $this->assertNull($this->invokePrivate('constrainToOwnStudents', [
            $this->foreign,
            $this->group,
            $this->group->behaviorAwards()->getQuery(),
        ]));

        $this->assertSame(
            [
                'in_group' => false,
                'leader' => false,
                'feed' => false,
                'participant_contact_ids' => [],
                'ward_contact_ids' => [],
            ],
            $this->invokePrivate('standingIn', [$this->foreign, $this->group])
        );
    }

    // ------------------------------------------------------------- the narrowing

    #[Test]
    public function the_refusal_is_the_instanceof_narrowing_and_not_a_broken_fixture(): void
    {
        // THE CONTROL. Identical calls, identical email, identical group — the
        // only difference is that this principal IS an App\Models\User. It gets
        // the leader's whole view, which is exactly what the foreign principal
        // would inherit if the narrowing in identitiesFor() were removed.
        $this->assertNotSame([], $this->audience->identitiesFor($this->staff));

        $this->assertTrue(
            $this->audience->mayReceive($this->staff, $this->group, GroupAudience::DISCLOSURE_FEED)
        );
        $this->assertTrue(
            $this->audience->mayReceiveThread($this->staff, $this->group, $this->participantThread)
        );
        $this->assertTrue(
            $this->audience->mayReceiveAwardsAbout($this->staff, $this->group, $this->student)
        );
        $this->assertTrue(
            $this->audience->mayReceiveHifzEntry($this->staff, $this->group, $this->hifzEntry)
        );

        $awards = $this->audience->readableAwardsQuery($this->staff, $this->group);
        $this->assertInstanceOf(Builder::class, $awards);
        $this->assertSame(1, $awards->count());

        $hifz = $this->audience->readableHifzQuery($this->staff, $this->group);
        $this->assertInstanceOf(Builder::class, $hifz);
        $this->assertSame(1, $hifz->count());

        $threads = $this->audience->readableThreadsQuery($this->staff, $this->group);
        $this->assertInstanceOf(Builder::class, $threads);
        $this->assertSame(2, $threads->count());
    }

    #[Test]
    public function a_null_principal_is_still_refused_rather_than_widened_into(): void
    {
        // The nullable half of `?Authenticatable`: widening the ACCEPTED types
        // must not have widened the GRANTED ones.
        $this->assertSame([], $this->audience->identitiesFor(null));
        $this->assertFalse(
            $this->audience->mayReceive(null, $this->group, GroupAudience::DISCLOSURE_FEED)
        );
        $this->assertNull($this->audience->readableThreadsQuery(null, $this->group));
        $this->assertNull($this->audience->readableAwardsQuery(null, $this->group));
        $this->assertNull($this->audience->readableHifzQuery(null, $this->group));
    }

    /** @param array<int,mixed> $arguments */
    private function invokePrivate(string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod(GroupAudience::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->audience, $arguments);
    }
}
