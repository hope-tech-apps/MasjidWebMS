<?php

namespace Tests\Feature;

use App\Jobs\SendGroupNotificationJob;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessageReaction;
use App\Models\GroupPost;
use App\Models\GroupPostReaction;
use App\Models\Masjid;
use App\Support\Reactions;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsClassStoryFixture;
use Tests\TestCase;

/**
 * Reactions (🤲 👍 💯 ❓) on CLASS STORY posts (owner, 2026-09-29; T-002.1).
 *
 * What this file pins:
 *
 *   - the set is the ONE list in App\Support\Reactions, shared with message
 *     reactions, and the server refuses anything else in every realm;
 *   - one of each per person per post; PUT adds, DELETE removes, both idempotent;
 *   - the gate is the FEED READ gate: a guardian with no consent, withdrawn
 *     consent, or a family that has left the class is refused with nothing
 *     written; a foreign, other-class or soft-deleted post is a miss;
 *   - the NAMES rule: staff see every name; a parent sees staff names, their own
 *     reaction as `mine`, and other families as a COUNT only. The test walks the
 *     serialized JSON for another family's name rather than trusting a field;
 *   - a tap dispatches nothing (the author hears once, in the digest).
 */
class GroupPostReactionsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsClassStoryFixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteInMemory();
        Bus::fake([SendGroupNotificationJob::class]);
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->buildStoryWorld();
        $this->consent($this->parentA);
        $this->consent($this->parentB);
    }

    // ------------------------------------------------------------ the set

    #[Test]
    public function the_set_is_one_list_shared_with_message_reactions(): void
    {
        $this->assertSame(Reactions::REACTIONS, GroupMessageReaction::REACTIONS);
        $this->assertSame(['ameen', 'thumbs_up', 'hundred', 'question'], array_keys(Reactions::REACTIONS));
        $this->assertSame(['🤲', '👍', '💯', '❓'], array_values(Reactions::REACTIONS));
    }

    #[Test]
    public function every_realms_story_payload_carries_all_four_reactions_in_order(): void
    {
        $post = $this->makePost();
        $keys = ['ameen', 'thumbs_up', 'hundred', 'question'];

        $teacher = $this->asTeacher()->getJson($this->teacherUrl('/posts'))->assertOk();
        $this->assertSame($keys, $teacher->json('data.data.0.reactions.*.key'));
        $this->assertSame($keys, $teacher->json('meta.reactions.*.key'));

        $parent = $this->asParent($this->parentA)->getJson($this->familyUrl('/posts'))->assertOk();
        $this->assertSame($keys, $parent->json('data.data.0.reactions.*.key'));
        $this->assertSame($keys, $parent->json('meta.reactions.*.key'));

        $show = $this->asParent($this->parentA)->getJson($this->familyUrl("/posts/{$post->id}"))->assertOk();
        $this->assertSame($keys, $show->json('data.reactions.*.key'));

        // A freshly written post answers with the four empty buttons too.
        $created = $this->asTeacher()
            ->postJson($this->teacherUrl('/posts'), ['body' => 'Fresh'])
            ->assertCreated();
        $this->assertSame($keys, $created->json('data.reactions.*.key'));
        $this->assertSame([0, 0, 0, 0], $created->json('data.reactions.*.count'));
    }

    #[Test]
    public function a_reaction_outside_the_four_is_refused_in_every_realm(): void
    {
        $post = $this->makePost();
        $admin = $this->makeLeadingAdmin();

        foreach (['heart', 'AMEEN', '🤲', '🎉', 'ameen ', 'thumbs-up'] as $bad) {
            $enc = rawurlencode($bad);

            $this->asTeacher()
                ->putJson($this->teacherUrl("/posts/{$post->id}/reactions/{$enc}"))
                ->assertStatus(422)
                ->assertJsonStructure(['data' => ['reaction']]);

            $this->asParent($this->parentA)
                ->putJson($this->familyUrl("/posts/{$post->id}/reactions/{$enc}"))
                ->assertStatus(422);

            $this->asUser($admin)
                ->putJson($this->adminUrl("/posts/{$post->id}/reactions/{$enc}"))
                ->assertStatus(422);
        }

        $this->assertSame(0, GroupPostReaction::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_model_refuses_a_key_outside_the_set_and_a_reaction_with_no_person_or_two(): void
    {
        $post = $this->makePost();

        try {
            GroupPostReaction::create([
                'masjid_id' => $this->school->id, 'group_post_id' => $post->id,
                'reaction' => 'heart', 'user_id' => $this->teacher->id,
            ]);
            $this->fail('A key outside the four was stored.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }

        foreach ([[null, null], [$this->teacher->id, $this->parentA->id]] as [$userId, $contactId]) {
            try {
                GroupPostReaction::create([
                    'masjid_id' => $this->school->id, 'group_post_id' => $post->id,
                    'reaction' => 'ameen', 'user_id' => $userId, 'contact_id' => $contactId,
                ]);
                $this->fail('A reaction with none or both principals was stored.');
            } catch (\LogicException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, GroupPostReaction::withoutMasjidScope()->count());
    }

    // ------------------------------------------------------------ toggling

    #[Test]
    public function a_teacher_reacts_once_and_can_take_it_back_and_nothing_is_dispatched(): void
    {
        $post = $this->makePost();
        $url = $this->teacherUrl("/posts/{$post->id}/reactions/ameen");

        $this->asTeacher()->putJson($url)
            ->assertOk()
            ->assertJsonPath('data.post_id', $post->id)
            ->assertJsonPath('data.reactions.0.key', 'ameen')
            ->assertJsonPath('data.reactions.0.count', 1)
            ->assertJsonPath('data.reactions.0.mine', true);

        // A second tap (or a second tab) does not add a second row.
        $this->asTeacher()->putJson($url)->assertOk()->assertJsonPath('data.reactions.0.count', 1);
        $this->assertSame(1, GroupPostReaction::withoutMasjidScope()->count());

        $row = GroupPostReaction::withoutMasjidScope()->sole();
        $this->assertSame($this->teacher->id, (int) $row->user_id);
        $this->assertNull($row->contact_id);
        // Stamped from the bound tenant, never from the client.
        $this->assertSame($this->school->id, (int) $row->masjid_id);

        $this->asTeacher()->deleteJson($url)
            ->assertOk()
            ->assertJsonPath('data.reactions.0.count', 0)
            ->assertJsonPath('data.reactions.0.mine', false);
        $this->asTeacher()->deleteJson($url)->assertOk();

        $this->assertSame(0, GroupPostReaction::withoutMasjidScope()->count());

        // A tap notifies nobody; the author hears once, in the digest.
        Bus::assertNotDispatched(SendGroupNotificationJob::class);
    }

    #[Test]
    public function one_person_may_use_several_different_reactions_on_one_post(): void
    {
        $post = $this->makePost();

        foreach (['ameen', 'hundred'] as $key) {
            $this->asParent($this->parentA)
                ->putJson($this->familyUrl("/posts/{$post->id}/reactions/{$key}"))
                ->assertOk();
        }

        $this->assertSame(2, GroupPostReaction::withoutMasjidScope()->where('contact_id', $this->parentA->id)->count());
        Bus::assertNotDispatched(SendGroupNotificationJob::class);
    }

    #[Test]
    public function the_unique_keys_settle_a_race_between_two_taps(): void
    {
        $post = $this->makePost();
        $attrs = [
            'masjid_id' => $this->school->id, 'group_post_id' => $post->id,
            'reaction' => 'ameen', 'contact_id' => $this->parentA->id,
        ];

        GroupPostReaction::create($attrs);

        $this->expectException(QueryException::class);
        GroupPostReaction::create($attrs);
    }

    #[Test]
    public function a_parent_reacts_and_the_teacher_sees_who_by_name(): void
    {
        $post = $this->makePost();

        // Sent the way the portal's axios instance can send it: a bare PUT, no
        // JSON body at all.
        $this->asParent($this->parentA)
            ->put($this->familyUrl("/posts/{$post->id}/reactions/ameen"))
            ->assertOk()
            ->assertJsonPath('data.reactions.0.mine', true);

        $row = GroupPostReaction::withoutMasjidScope()->sole();
        $this->assertSame($this->parentA->id, (int) $row->contact_id);
        $this->assertNull($row->user_id);

        $this->asTeacher()
            ->getJson($this->teacherUrl('/posts'))
            ->assertOk()
            ->assertJsonPath('data.data.0.reactions.0.count', 1)
            ->assertJsonPath('data.data.0.reactions.0.mine', false)
            ->assertJsonPath('data.data.0.reactions.0.by.0.name', 'Huda Yusuf')
            ->assertJsonPath('data.data.0.reactions.0.by.0.is_parent', true);
    }

    // ------------------------------------------------------------ the names rule

    #[Test]
    public function a_parent_is_shown_staff_names_and_never_another_familys(): void
    {
        $post = $this->makePost();
        $colleague = $this->makeTeacher($this->school, $this->class, 'Ustadha Salma');

        $this->asTeacher()->putJson($this->teacherUrl("/posts/{$post->id}/reactions/thumbs_up"))->assertOk();
        $this->asTeacher($colleague)->putJson($this->teacherUrl("/posts/{$post->id}/reactions/thumbs_up"))->assertOk();
        $this->asParent($this->parentA)->putJson($this->familyUrl("/posts/{$post->id}/reactions/thumbs_up"))->assertOk();
        $this->asParent($this->parentB)->putJson($this->familyUrl("/posts/{$post->id}/reactions/thumbs_up"))->assertOk();
        $this->asParent($this->parentB)->putJson($this->familyUrl("/posts/{$post->id}/reactions/question"))->assertOk();

        // Every surface a parent can read a story through: the list, one post,
        // and the answer to their own tap.
        $responses = [
            $this->asParent($this->parentA)->getJson($this->familyUrl('/posts'))->assertOk(),
            $this->asParent($this->parentA)->getJson($this->familyUrl("/posts/{$post->id}"))->assertOk(),
            $this->asParent($this->parentA)->putJson($this->familyUrl("/posts/{$post->id}/reactions/thumbs_up"))->assertOk(),
        ];

        foreach ($responses as $response) {
            $json = $response->json();
            $reactions = $json['data']['data'][0]['reactions'] ?? $json['data']['reactions'];
            $thumbs = $reactions[1];

            $this->assertSame('thumbs_up', $thumbs['key']);
            // Four rows: two staff, parent A (mine), parent B (counted).
            $this->assertSame(4, $thumbs['count']);
            $this->assertTrue($thumbs['mine']);
            $this->assertEqualsCanonicalizing(
                ['Ustadh Bilal', 'Ustadha Salma'],
                array_column($thumbs['by'], 'name'),
            );
            $this->assertSame([false, false], array_column($thumbs['by'], 'is_parent'));

            // Another family's reaction is counted on `question` too, and named nowhere.
            $this->assertSame(1, $reactions[3]['count']);
            $this->assertFalse($reactions[3]['mine']);
            $this->assertSame([], $reactions[3]['by']);

            // WALK THE WHOLE PAYLOAD rather than trusting a field: no trace of
            // the other family, their child, or their login address anywhere.
            $raw = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            foreach (['Maryam', 'Karimi', 'Zayd', $this->parentB->login_email] as $secret) {
                $this->assertStringNotContainsString($secret, $raw, "another family's {$secret} reached a parent");
            }
        }
    }

    #[Test]
    public function the_teacher_and_the_office_are_shown_every_name(): void
    {
        $post = $this->makePost();
        $admin = $this->makeLeadingAdmin();

        $this->asParent($this->parentA)->putJson($this->familyUrl("/posts/{$post->id}/reactions/ameen"))->assertOk();
        $this->asParent($this->parentB)->putJson($this->familyUrl("/posts/{$post->id}/reactions/ameen"))->assertOk();

        foreach ([
            $this->asTeacher()->getJson($this->teacherUrl('/posts')),
            $this->asUser($admin)->getJson($this->adminUrl('/posts')),
        ] as $response) {
            $response->assertOk();
            $this->assertEqualsCanonicalizing(
                ['Huda Yusuf', 'Maryam Karimi'],
                $response->json('data.data.0.reactions.0.by.*.name'),
            );
            $this->assertSame(2, $response->json('data.data.0.reactions.0.count'));
        }
    }

    // ------------------------------------------------ who may react at all

    #[Test]
    public function a_guardian_who_never_consented_cannot_react_and_learns_nothing(): void
    {
        $post = $this->makePost(body: 'A private note about the class trip.');
        [$stranger] = $this->makeFamily('Layla', 'Noor', 'Haddad');
        $this->asTeacher()->putJson($this->teacherUrl("/posts/{$post->id}/reactions/question"))->assertOk();

        foreach (['putJson', 'deleteJson'] as $verb) {
            $response = $this->asParent($stranger)
                ->{$verb}($this->familyUrl("/posts/{$post->id}/reactions/ameen"))
                ->assertStatus(403);

            $response->assertJsonMissingPath('data');
            $this->assertStringNotContainsString('class trip', $response->getContent());
            $this->assertStringNotContainsString('Ustadh Bilal', $response->getContent());
        }

        $this->assertSame(0, GroupPostReaction::withoutMasjidScope()->where('contact_id', $stranger->id)->count());
        $this->assertSame(1, GroupPostReaction::withoutMasjidScope()->count());
    }

    #[Test]
    public function withdrawn_consent_and_a_departed_family_are_refused_at_the_moment_they_happen(): void
    {
        $post = $this->makePost();
        $url = $this->familyUrl("/posts/{$post->id}/reactions/ameen");

        $this->asParent($this->parentA)->putJson($url)->assertOk();
        $this->asParent($this->parentB)->putJson($url)->assertOk();

        $this->withdrawConsent($this->parentA);
        $this->asParent($this->parentA)->putJson($url)->assertStatus(403);
        $this->asParent($this->parentA)->deleteJson($url)->assertStatus(403);

        $this->familyLeaves($this->parentB);
        $this->asParent($this->parentB)->putJson($this->familyUrl("/posts/{$post->id}/reactions/hundred"))->assertStatus(403);
        $this->asParent($this->parentB)->deleteJson($url)->assertStatus(403);

        // Nothing new was written, and the refused DELETEs took nothing away.
        $this->assertSame(2, GroupPostReaction::withoutMasjidScope()->count());
    }

    #[Test]
    public function withdrawing_a_child_takes_their_guardians_off_the_story(): void
    {
        $post = $this->makePost();

        // The office withdraws the CHILD; the guardian edge follows.
        $this->childA->markLeftByStaff(null)->save();

        $this->asParent($this->parentA)
            ->putJson($this->familyUrl("/posts/{$post->id}/reactions/ameen"))
            ->assertStatus(403);
        $this->assertSame(0, GroupPostReaction::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_post_of_another_class_school_or_a_deleted_one_is_a_miss(): void
    {
        $mine = $this->makePost();

        // Another class in THIS school, where parent A has no standing.
        $otherClass = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 2', 'slug' => 'grade-2',
        ]);
        $inOtherClass = $this->makePost(class: $otherClass);

        // The mix-up: their post id under OUR class's URL is a 404, never a write.
        $this->asParent($this->parentA)
            ->putJson($this->familyUrl("/posts/{$inOtherClass->id}/reactions/ameen"))
            ->assertNotFound();
        $this->asTeacher()
            ->putJson($this->teacherUrl("/posts/{$inOtherClass->id}/reactions/ameen"))
            ->assertNotFound();

        // And the other class's own URL is a 403 for a family that is not in it.
        $this->asParent($this->parentA)
            ->putJson($this->familyUrl("/posts/{$inOtherClass->id}/reactions/ameen", $otherClass))
            ->assertStatus(403);

        // A soft-deleted post is gone from every realm.
        $gone = $this->makePost(body: 'Deleted story');
        $gone->delete();
        $this->asParent($this->parentA)->putJson($this->familyUrl("/posts/{$gone->id}/reactions/ameen"))->assertNotFound();
        $this->asTeacher()->putJson($this->teacherUrl("/posts/{$gone->id}/reactions/ameen"))->assertNotFound();

        $this->assertSame(0, GroupPostReaction::withoutMasjidScope()->count());
        $this->assertSame(1, GroupPost::withoutMasjidScope()->where('id', $mine->id)->count());
    }

    #[Test]
    public function another_schools_post_is_a_miss_and_another_schools_reaction_is_invisible(): void
    {
        $foreignClass = Group::factory()->create([
            'masjid_id' => $this->otherSchool->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 1', 'slug' => 'grade-1',
        ]);
        $foreignTeacher = $this->makeTeacher($this->otherSchool, $foreignClass, 'Ustadh Elsewhere');
        $foreignPost = $this->makePost($foreignTeacher, class: $foreignClass);
        $foreignReaction = GroupPostReaction::create([
            'masjid_id' => $this->otherSchool->id, 'group_post_id' => $foreignPost->id,
            'reaction' => 'ameen', 'user_id' => $foreignTeacher->id,
        ]);

        // Their ids under OUR school's URL: nothing resolves.
        $this->asParent($this->parentA)
            ->putJson("/api/family/masjids/{$this->school->id}/groups/{$foreignClass->id}/posts/{$foreignPost->id}/reactions/ameen")
            ->assertNotFound();
        $status = $this->asTeacher()
            ->putJson("/api/teacher/masjids/{$this->school->id}/groups/{$foreignClass->id}/posts/{$foreignPost->id}/reactions/ameen")
            ->status();
        // teacher.leads refuses a class the teacher does not lead in this school.
        $this->assertContains($status, [403, 404]);

        // The model layer: a bound tenant cannot see, load or count the other
        // school's reaction.
        app(TenantContext::class)->set($this->school->id);
        $this->assertNull(GroupPostReaction::find($foreignReaction->id));
        $this->assertSame(0, GroupPostReaction::query()->count());
        app(TenantContext::class)->forgetTenant();

        $this->assertSame(1, GroupPostReaction::withoutMasjidScope()->count());
    }

    #[Test]
    public function a_bound_tenant_cannot_read_update_or_delete_another_organizations_group_post_reaction(): void
    {
        // Two schools of their own, so this reads on its own: the model layer of
        // the isolation rule, the boundary MySQL will not enforce for us.
        $a = $this->makeMasjid();
        $b = $this->makeMasjid();

        $mk = function (Masjid $masjid, string $name) {
            $group = Group::factory()->create([
                'masjid_id' => $masjid->id, 'kind' => Group::KIND_CLASS, 'name' => $name, 'slug' => strtolower($name),
            ]);
            $teacher = $this->makeTeacher($masjid, $group, 'Teacher '.$name);
            $post = $this->makePost($teacher, class: $group);

            return GroupPostReaction::create([
                'masjid_id' => $masjid->id, 'group_post_id' => $post->id,
                'reaction' => 'ameen', 'user_id' => $teacher->id,
            ]);
        };

        $inA = $mk($a, 'Alpha');
        $inB = $mk($b, 'Beta');

        app(TenantContext::class)->set($a->id);

        $this->assertNull(GroupPostReaction::find($inB->id));
        $this->assertSame(0, GroupPostReaction::query()->where('id', $inB->id)->update(['reaction' => 'hundred']));
        $this->assertSame(0, GroupPostReaction::query()->where('id', $inB->id)->delete());
        $this->assertSame(1, GroupPostReaction::query()->count());

        // create() stamps the BOUND tenant over a client-supplied masjid_id.
        $post = GroupPost::query()->where('masjid_id', $a->id)->firstOrFail();
        $stamped = GroupPostReaction::create([
            'masjid_id' => $b->id, 'group_post_id' => $post->id,
            'reaction' => 'thumbs_up', 'user_id' => $inA->user_id,
        ]);
        $this->assertSame($a->id, (int) $stamped->masjid_id);

        app(TenantContext::class)->forgetTenant();

        $this->assertSame('ameen', GroupPostReaction::withoutMasjidScope()->findOrFail($inB->id)->reaction);
    }

    #[Test]
    public function a_teacher_cannot_react_in_a_class_they_do_not_lead(): void
    {
        $otherClass = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 2', 'slug' => 'grade-2',
        ]);
        $post = $this->makePost(class: $otherClass);

        $this->asTeacher()
            ->putJson($this->teacherUrl("/posts/{$post->id}/reactions/ameen", $otherClass))
            ->assertStatus(403);

        $this->assertSame(0, GroupPostReaction::withoutMasjidScope()->count());
    }

    // ------------------------------------------------------------ the office

    #[Test]
    public function the_office_reacts_through_the_admin_realm_when_it_may_read_the_class(): void
    {
        $post = $this->makePost();
        $admin = $this->makeLeadingAdmin();

        $this->asUser($admin)
            ->putJson($this->adminUrl("/posts/{$post->id}/reactions/hundred"))
            ->assertOk()
            ->assertJsonPath('data.reactions.2.mine', true);

        $this->asUser($admin)
            ->deleteJson($this->adminUrl("/posts/{$post->id}/reactions/hundred"))
            ->assertOk()
            ->assertJsonPath('data.reactions.2.count', 0);
    }

    #[Test]
    public function an_office_admin_who_cannot_read_the_feed_cannot_react_to_it(): void
    {
        $post = $this->makePost();
        $admin = $this->makeAdmin();

        $this->asUser($admin)
            ->putJson($this->adminUrl("/posts/{$post->id}/reactions/ameen"))
            ->assertStatus(403);

        $this->assertSame(0, GroupPostReaction::withoutMasjidScope()->count());
    }

    // ------------------------------------------------------------ teardown + schema

    #[Test]
    public function deleting_the_post_for_good_takes_its_reactions_with_it(): void
    {
        $post = $this->makePost();
        $this->asParent($this->parentA)->putJson($this->familyUrl("/posts/{$post->id}/reactions/ameen"))->assertOk();
        $this->asTeacher()->putJson($this->teacherUrl("/posts/{$post->id}/reactions/ameen"))->assertOk();

        $post->purge();

        $this->assertSame(0, GroupPostReaction::withoutMasjidScope()->count());
    }

    #[Test]
    public function the_schema_is_what_the_code_assumes(): void
    {
        $this->assertTrue(Schema::hasColumns('group_post_reactions', [
            'masjid_id', 'group_post_id', 'reaction', 'user_id', 'contact_id',
        ]));

        // SQLite ignores varchar lengths, so pin the declared TYPE, not a value.
        $reaction = collect(Schema::getColumns('group_post_reactions'))->firstWhere('name', 'reaction');
        $this->assertStringContainsString('varchar', strtolower($reaction['type']));
        // The declared ceiling is 32; the keys must sit under it, or MySQL (which
        // enforces it) would truncate or refuse what SQLite let through.
        foreach (array_keys(Reactions::REACTIONS) as $key) {
            $this->assertLessThanOrEqual(32, strlen($key));
        }

        foreach (Schema::getIndexes('group_post_reactions') as $index) {
            $this->assertLessThanOrEqual(64, strlen($index['name']), "MySQL caps an index name at 64: {$index['name']}");
        }
    }
}
