<?php

namespace Tests\Feature;

use App\Enums\GroupNotificationEvent;
use App\Jobs\SendGroupNotificationJob;
use App\Mail\GroupUpdateNudgeMail;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessage;
use App\Models\GroupMessageReaction;
use App\Models\GroupPost;
use App\Models\GroupPostReaction;
use App\Models\GroupStaff;
use App\Models\GroupThread;
use App\Models\User;
use App\Services\Groups\GroupNotificationRecipientResolver;
use App\Services\Groups\GroupPushChannel;
use App\Support\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsClassStoryFixture;
use Tests\TestCase;

/**
 * The reaction DIGEST (owner, 2026-09-29; T-002.2) and the sign-in address fix
 * in SendGroupNotificationJob.
 *
 * A tap notifies nobody; `groups:notify-reactions` (hourly) tells the AUTHOR of a
 * story or a message, once, in a content-free email. What this file pins:
 *
 *   - a tap sends nothing (GroupMessageReactionsTest pins the same for messages);
 *   - the settle window: a reaction taken back inside it is never announced, and
 *     a burst is ONE email per author per class;
 *   - the AUTHOR only, and never for the author's own reaction;
 *   - the email carries no name, no emoji and no content;
 *   - consent is re-checked at SEND time — on the reactor (in the command) and on
 *     the recipient (in the job);
 *   - at most once: rows are claimed before they are sent, dry runs claim nothing;
 *   - a teacher is sent to the STAFF sign-in, a guardian to the family portal
 *     (the job used to build the family address for everybody).
 *
 * The queue is `sync` under test, so the job runs inline and Mail::fake() sees
 * what it sends.
 */
class ReactionDigestTest extends TestCase
{
    use RefreshDatabase;
    use BuildsClassStoryFixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteInMemory();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->buildStoryWorld();
        $this->consent($this->parentA);
        $this->consent($this->parentB);

        Mail::fake();
    }

    // ------------------------------------------------------------ helpers

    private function react(string $realm, $who, GroupPost|GroupMessage $subject, string $key = 'ameen', ?GroupThread $thread = null): void
    {
        $path = $subject instanceof GroupPost
            ? "/posts/{$subject->id}/reactions/{$key}"
            : "/threads/{$thread->id}/messages/{$subject->id}/reactions/{$key}";

        $client = $who instanceof Contact ? $this->asParent($who) : $this->asTeacher($who);
        $url = $realm === 'family' ? $this->familyUrl($path) : $this->teacherUrl($path);

        $client->putJson($url)->assertOk();
    }

    private function runDigest(string ...$options): void
    {
        $this->artisan('groups:notify-reactions', array_fill_keys($options, true))->assertSuccessful();
    }

    /** Let the settle window pass. */
    private function settle(int $minutes = 11): void
    {
        $this->travel($minutes)->minutes();
    }

    private function digests(string $address): int
    {
        return Mail::sent(GroupUpdateNudgeMail::class, fn ($m) => $m->kind === 'reaction' && $m->hasTo($address))->count();
    }

    private function allDigests(): int
    {
        return Mail::sent(GroupUpdateNudgeMail::class, fn ($m) => $m->kind === 'reaction')->count();
    }

    private function unannounced(): int
    {
        return GroupPostReaction::withoutMasjidScope()->whereNull('notified_at')->count()
            + GroupMessageReaction::withoutMasjidScope()->whereNull('notified_at')->count();
    }

    private function privateThread(): GroupThread
    {
        return GroupThread::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'created_by_user_id' => $this->teacher->id, 'subject' => 'About Amina',
            'scope' => GroupThread::SCOPE_PARTICIPANT, 'about_membership_id' => $this->childA->id,
        ]);
    }

    private function classThread(): GroupThread
    {
        return GroupThread::create([
            'masjid_id' => $this->school->id, 'group_id' => $this->class->id,
            'created_by_user_id' => $this->teacher->id, 'subject' => 'Our week', 'scope' => GroupThread::SCOPE_GROUP,
        ]);
    }

    private function teacherMessage(GroupThread $thread): GroupMessage
    {
        return GroupMessage::create([
            'masjid_id' => $this->school->id, 'group_thread_id' => $thread->id,
            'author_user_id' => $this->teacher->id, 'body' => 'A private word about Amina.',
        ]);
    }

    private function parentMessage(GroupThread $thread, Contact $parent): GroupMessage
    {
        return GroupMessage::create([
            'masjid_id' => $this->school->id, 'group_thread_id' => $thread->id,
            'author_contact_id' => $parent->id, 'body' => 'Thank you, ustadh.',
        ]);
    }

    // ------------------------------------------------------------ a tap sends nothing

    #[Test]
    public function a_tap_sends_nothing_on_either_surface(): void
    {
        $post = $this->makePost();
        $thread = $this->privateThread();
        $message = $this->teacherMessage($thread);

        $this->react('family', $this->parentA, $post);
        $this->react('family', $this->parentA, $message, 'hundred', $thread);

        Mail::assertNothingSent();
        $this->assertSame(2, $this->unannounced());
    }

    // ------------------------------------------------------------ the settle window

    #[Test]
    public function the_author_hears_once_after_the_settle_window_and_nobody_else_does(): void
    {
        $post = $this->makePost();
        $colleague = $this->makeTeacher($this->school, $this->class, 'Ustadha Salma');

        $this->react('family', $this->parentA, $post);
        $this->react('family', $this->parentB, $post, 'hundred');

        // Inside the window: nothing yet.
        $this->settle(5);
        $this->runDigest();
        $this->assertSame(0, $this->allDigests());
        $this->assertSame(2, $this->unannounced(), 'a reaction still inside the window is not claimed');

        // After it: exactly one email, to the author.
        $this->settle(6);
        $this->runDigest();

        $this->assertSame(1, $this->allDigests());
        $this->assertSame(1, $this->digests($this->teacher->email));
        $this->assertSame(0, $this->digests($colleague->email), 'a colleague who did not write it is not told');
        $this->assertSame(0, $this->digests($this->parentA->login_email));
        $this->assertSame(0, $this->digests($this->parentB->login_email));
        $this->assertSame(0, $this->unannounced());

        // A second sweep announces nothing more.
        $this->runDigest();
        $this->assertSame(1, $this->allDigests());

        // A NEW reaction later is a new digest.
        $this->react('family', $this->parentA, $post, 'question');
        $this->settle();
        $this->runDigest();
        $this->assertSame(2, $this->allDigests());
    }

    #[Test]
    public function a_reaction_taken_back_inside_the_window_is_never_announced(): void
    {
        $post = $this->makePost();
        $this->react('family', $this->parentA, $post);

        // A sweep that runs while the tap is still inside the window must leave it
        // alone — that is the whole point of waiting...
        $this->settle(3);
        $this->runDigest();
        $this->assertSame(0, $this->allDigests());
        $this->assertSame(1, $this->unannounced());

        // ...because the parent may still take it back.
        $this->asParent($this->parentA)->deleteJson($this->familyUrl("/posts/{$post->id}/reactions/ameen"))->assertOk();

        $this->settle();
        $this->runDigest();

        $this->assertSame(0, $this->allDigests());
    }

    #[Test]
    public function a_burst_across_several_stories_is_one_email_per_author_per_class(): void
    {
        $posts = [$this->makePost(body: 'One'), $this->makePost(body: 'Two'), $this->makePost(body: 'Three')];

        foreach ($posts as $post) {
            $this->react('family', $this->parentA, $post);
            $this->react('family', $this->parentB, $post);
        }

        $this->settle();
        $this->runDigest();

        $this->assertSame(1, $this->allDigests());
        $this->assertSame(0, $this->unannounced(), 'all six reactions were claimed by the one digest');
    }

    #[Test]
    public function a_teacher_of_two_classes_gets_one_digest_per_class_each_naming_its_own(): void
    {
        $second = Group::factory()->create([
            'masjid_id' => $this->school->id, 'kind' => Group::KIND_CLASS,
            'name' => 'Grade 2', 'slug' => 'grade-2',
        ]);
        $second->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);
        [$parentC] = $this->makeFamily('Idris', 'Salma', 'Noor', $second);
        $this->consent($parentC, class: $second);

        $first = $this->makePost();
        $other = $this->makePost(class: $second);

        $this->react('family', $this->parentA, $first);
        $this->asParent($parentC)
            ->putJson($this->familyUrl("/posts/{$other->id}/reactions/ameen", $second))
            ->assertOk();

        $this->settle();
        $this->runDigest();

        $this->assertSame(2, $this->digests($this->teacher->email), 'one email per class, not one merged email');
        $this->assertSame(
            ['Grade 1', 'Grade 2'],
            Mail::sent(GroupUpdateNudgeMail::class, fn ($m) => $m->kind === 'reaction')
                ->map(fn ($m) => $m->groupLabel)->sort()->values()->all(),
            'each email names the class its reactions were in'
        );
        $this->assertSame(0, $this->unannounced(), 'and neither class\'s rows were claimed by the other\'s email');
    }

    #[Test]
    public function a_message_reaction_waits_out_the_settle_window_and_a_taken_back_one_is_never_announced(): void
    {
        $thread = $this->privateThread();
        $message = $this->teacherMessage($thread);
        $path = "/threads/{$thread->id}/messages/{$message->id}/reactions";

        // A sweep that runs the moment after the tap leaves it alone: unsent AND unclaimed.
        $this->react('family', $this->parentA, $message, 'ameen', $thread);
        $this->runDigest();
        $this->assertSame(0, $this->allDigests());
        $this->assertSame(1, $this->unannounced(), 'a message reaction inside the window is not claimed');

        // Once it has stood, it is announced.
        $this->settle();
        $this->runDigest();
        $this->assertSame(1, $this->digests($this->teacher->email));

        // A tap taken back inside the window is never announced.
        $this->react('family', $this->parentA, $message, 'hundred', $thread);
        $this->settle(3);
        $this->runDigest();
        $this->assertSame(1, $this->allDigests());
        $this->assertSame(1, $this->unannounced());
        $this->asParent($this->parentA)->deleteJson($this->familyUrl("{$path}/hundred"))->assertOk();
        $this->settle();
        $this->runDigest();
        $this->assertSame(1, $this->allDigests());
    }

    #[Test]
    public function the_settle_window_is_configurable_and_the_option_overrides_it(): void
    {
        $post = $this->makePost();
        $this->react('family', $this->parentA, $post);

        $this->runDigest();
        $this->assertSame(0, $this->allDigests(), 'ten minutes is the default');

        $this->artisan('groups:notify-reactions', ['--settle' => 0])->assertSuccessful();
        $this->assertSame(1, $this->allDigests());
    }

    // ------------------------------------------------------------ who hears

    #[Test]
    public function nobody_is_told_about_their_own_reaction(): void
    {
        $post = $this->makePost();
        $thread = $this->privateThread();
        $mine = $this->parentMessage($thread, $this->parentA);

        // The teacher reacts to their own story; a parent to their own message.
        $this->react('teacher', $this->teacher, $post);
        $this->react('family', $this->parentA, $mine, 'ameen', $thread);

        $this->settle();
        $this->runDigest();

        $this->assertSame(0, $this->allDigests());
        $this->assertSame(0, $this->unannounced(), 'skipped rows are claimed, not re-examined every hour');
    }

    #[Test]
    public function a_message_reaction_reaches_the_messages_author_whichever_side_they_are_on(): void
    {
        $thread = $this->privateThread();
        $fromTeacher = $this->teacherMessage($thread);
        $fromParent = $this->parentMessage($thread, $this->parentA);

        $this->react('family', $this->parentA, $fromTeacher, 'thumbs_up', $thread);
        $this->react('teacher', $this->teacher, $fromParent, 'ameen', $thread);

        $this->settle();
        $this->runDigest();

        $this->assertSame(1, $this->digests($this->teacher->email));
        $this->assertSame(1, $this->digests($this->parentA->login_email));
        $this->assertSame(2, $this->allDigests());
    }

    #[Test]
    public function the_teacher_is_sent_to_the_staff_sign_in_and_a_parent_to_the_family_portal(): void
    {
        $thread = $this->privateThread();
        $this->react('family', $this->parentA, $this->teacherMessage($thread), 'ameen', $thread);
        $this->react('teacher', $this->teacher, $this->parentMessage($thread, $this->parentA), 'ameen', $thread);

        $this->settle();
        $this->runDigest();

        $base = rtrim((string) config('app.url'), '/');

        Mail::assertSent(GroupUpdateNudgeMail::class, fn ($m) => $m->hasTo($this->teacher->email) && $m->signInUrl === $base.'/auth/sign-in');
        Mail::assertSent(GroupUpdateNudgeMail::class, fn ($m) => $m->hasTo($this->parentA->login_email)
            && $m->signInUrl === $base.'/family/'.$this->school->id.'/sign-in');
    }

    // ------------------------------------------------------------ content-free

    #[Test]
    public function the_email_carries_no_name_no_emoji_and_no_content(): void
    {
        $post = $this->makePost(body: 'Amina memorised Surah Al-Fatiha today.');
        $this->react('family', $this->parentA, $post, 'ameen');
        $this->react('family', $this->parentB, $post, 'hundred');
        $this->react('teacher', $this->makeTeacher($this->school, $this->class, 'Ustadha Salma'), $post, 'thumbs_up');

        $this->settle();
        $this->runDigest();

        $mail = Mail::sent(GroupUpdateNudgeMail::class, fn ($m) => $m->kind === 'reaction')->sole();

        $this->assertSame('You have new reactions', $mail->build()->subject);

        $html = $mail->render();
        foreach (['🤲', '👍', '💯', '❓', 'Huda', 'Yusuf', 'Maryam', 'Karimi', 'Amina', 'Zayd', 'Salma', 'Fatiha', 'Our day'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, "the digest leaked {$leak}");
        }
        $this->assertStringContainsString('Grade 1', $html);
        $this->assertStringContainsString('/auth/sign-in', $html);
    }

    // ------------------------------------------------------------ consent, re-checked at send time

    #[Test]
    public function a_reactor_who_lost_standing_between_the_tap_and_the_digest_is_not_counted(): void
    {
        $post = $this->makePost();
        $this->react('family', $this->parentA, $post);
        $this->react('family', $this->parentB, $post);

        // Between the tap and the sweep: A's consent is withdrawn, B's family leaves.
        $this->withdrawConsent($this->parentA);
        $this->familyLeaves($this->parentB);

        $this->settle();
        $this->runDigest();

        $this->assertSame(0, $this->allDigests());
        $this->assertSame(0, $this->unannounced());
    }

    #[Test]
    public function a_reactor_who_keeps_their_standing_still_counts_when_another_lost_theirs(): void
    {
        $post = $this->makePost();
        $this->react('family', $this->parentA, $post);
        $this->react('family', $this->parentB, $post);
        $this->withdrawConsent($this->parentA);

        $this->settle();
        $this->runDigest();

        $this->assertSame(1, $this->digests($this->teacher->email));
    }

    #[Test]
    public function a_recipient_who_lost_standing_is_not_mailed_and_the_row_is_still_claimed(): void
    {
        // A teacher taken off the class between the tap and the digest.
        $post = $this->makePost();
        $this->react('family', $this->parentA, $post);
        GroupStaff::withoutMasjidScope()->where('group_id', $this->class->id)->where('user_id', $this->teacher->id)->delete();

        $this->settle();
        $this->runDigest();

        $this->assertSame(0, $this->allDigests());
        $this->assertSame(0, $this->unannounced());
    }

    #[Test]
    public function an_office_administrator_who_cannot_read_the_story_back_is_not_mailed_about_it(): void
    {
        $admin = $this->makeAdmin();
        $post = $this->makePost($admin);
        $this->react('family', $this->parentA, $post);

        $this->settle();
        $this->runDigest();

        $this->assertSame(0, $this->digests($admin->email));
    }

    #[Test]
    public function a_parent_author_who_left_the_class_or_lost_their_login_is_not_mailed(): void
    {
        $thread = $this->privateThread();
        $mine = $this->parentMessage($thread, $this->parentA);
        $this->react('teacher', $this->teacher, $mine, 'ameen', $thread);

        $this->familyLeaves($this->parentA);
        $this->settle();
        $this->runDigest();
        $this->assertSame(0, $this->digests($this->parentA->login_email), 'a departed guardian is refused receiving');

        // A revoked login: nowhere to sign in.
        $classThread = $this->classThread();
        $other = $this->parentMessage($classThread, $this->parentB);
        $this->react('teacher', $this->teacher, $other, 'ameen', $classThread);
        $this->parentB->forceFill(['login_revoked_at' => now()])->save();
        $this->settle();
        $this->runDigest();
        $this->assertSame(0, $this->digests($this->parentB->login_email));
    }

    #[Test]
    public function an_archived_teacher_is_not_mailed_about_reactions_to_their_stories(): void
    {
        $post = $this->makePost();
        $this->react('family', $this->parentA, $post);

        // "Move to trash" soft-deletes the user and leaves their group_staff rows.
        $this->teacher->delete();
        $this->assertTrue($this->teacher->trashed());
        $this->assertSame(1, GroupStaff::withoutMasjidScope()->where('user_id', $this->teacher->id)->count());

        $this->settle();
        $this->runDigest();

        $this->assertSame(0, $this->digests($this->teacher->email), 'an archived account is nobody');
        $this->assertSame(0, $this->allDigests());
        $this->assertSame(0, $this->unannounced(), 'the row is still claimed, not re-examined every hour');
    }

    #[Test]
    public function an_archived_colleagues_reaction_is_not_counted_but_an_active_ones_is(): void
    {
        $post = $this->makePost();
        $archived = $this->makeTeacher($this->school, $this->class, 'Ustadha Salma');
        $active = $this->makeTeacher($this->school, $this->class, 'Ustadh Idris');

        $this->react('teacher', $archived, $post, 'thumbs_up');
        $archived->delete();

        $this->settle();
        $this->runDigest();
        $this->assertSame(0, $this->allDigests(), 'a reaction from an archived account no longer counts');
        $this->assertSame(0, $this->unannounced());

        $this->react('teacher', $active, $post, 'hundred');
        $this->settle();
        $this->runDigest();
        $this->assertSame(1, $this->digests($this->teacher->email), 'control: an active colleague\'s reaction is announced');
    }

    #[Test]
    public function a_parent_whose_one_childs_place_ended_still_counts_while_another_child_stays(): void
    {
        [, $secondEdge] = $this->addSibling($this->parentA, 'Yusuf');
        $this->consent($this->parentA);

        $post = $this->makePost();
        $this->react('family', $this->parentA, $post);

        // One child withdraws; Huda is still a current guardian of the other.
        $this->guardianEdgeLeaves($secondEdge);
        $this->settle();
        $this->runDigest();

        $this->assertSame(1, $this->digests($this->teacher->email), 'she is still a guardian in the class');
    }

    #[Test]
    public function the_job_rechecks_the_recipient_at_send_time_itself(): void
    {
        $post = $this->makePost();
        $this->react('family', $this->parentA, $post);

        // The digest was dispatched while the teacher still led the class...
        $job = new SendGroupNotificationJob(
            (int) $this->school->id, (int) $this->class->id, GroupNotificationEvent::REACTION,
            recipientUserId: $this->teacher->id, subjects: ['story'],
        );

        // ...and by the time the worker runs it, they do not.
        GroupStaff::withoutMasjidScope()->where('group_id', $this->class->id)->where('user_id', $this->teacher->id)->delete();
        $job->handle(app(GroupNotificationRecipientResolver::class), app(GroupPushChannel::class));

        $this->assertSame(0, $this->allDigests());

        // Control: with standing intact the very same job sends.
        $this->class->staff()->attach($this->teacher->id, [
            'masjid_id' => $this->school->id, 'role' => GroupStaff::ROLE_TEACHER, 'assigned_at' => now(),
        ]);
        $job->handle(app(GroupNotificationRecipientResolver::class), app(GroupPushChannel::class));

        $this->assertSame(1, $this->digests($this->teacher->email));
    }

    // ------------------------------------------------------------ at most once

    #[Test]
    public function a_dry_run_claims_and_sends_nothing(): void
    {
        $this->react('family', $this->parentA, $this->makePost());
        $this->settle();

        $this->runDigest('--dry-run');
        $this->assertSame(0, $this->allDigests());
        $this->assertSame(1, $this->unannounced());

        $this->runDigest();
        $this->assertSame(1, $this->allDigests());
    }

    #[Test]
    public function a_row_another_run_claims_first_is_not_sent_again(): void
    {
        $post = $this->makePost();
        $this->react('family', $this->parentA, $post);
        $this->settle();

        // A CONCURRENT run wins the row between this run's SELECT and its claim:
        // the resolver is the last thing the command asks before it claims, so a
        // stand-in for it stamps the row at exactly that moment.
        $this->app->bind(GroupNotificationRecipientResolver::class, fn () => new class extends GroupNotificationRecipientResolver {
            public function principalMayStillRead(\App\Models\Group $group, ?int $userId, ?int $contactId, ?int $threadId): bool
            {
                $ok = parent::principalMayStillRead($group, $userId, $contactId, $threadId);
                GroupPostReaction::withoutMasjidScope()->update(['notified_at' => now()]);

                return $ok;
            }
        });

        $this->runDigest();

        $this->assertSame(0, $this->allDigests(), 'the row was already the other run\'s to announce');
    }

    #[Test]
    public function a_row_already_stamped_is_never_selected_again(): void
    {
        $this->react('family', $this->parentA, $this->makePost());
        $this->settle();

        GroupPostReaction::withoutMasjidScope()->update(['notified_at' => now()]);
        $this->runDigest();

        $this->assertSame(0, $this->allDigests());
    }

    #[Test]
    public function a_deleted_story_or_conversation_is_claimed_without_a_send(): void
    {
        $post = $this->makePost();
        $thread = $this->privateThread();
        $message = $this->teacherMessage($thread);
        $this->react('family', $this->parentA, $post);
        $this->react('family', $this->parentA, $message, 'ameen', $thread);

        $post->delete();
        $thread->delete();

        $this->settle();
        $this->runDigest();

        $this->assertSame(0, $this->allDigests());
        $this->assertSame(0, $this->unannounced());
    }

    #[Test]
    public function the_sweep_can_be_narrowed_to_one_school(): void
    {
        $post = $this->makePost();
        $this->react('family', $this->parentA, $post);
        $this->settle();

        $this->artisan('groups:notify-reactions', ['--masjid' => $this->otherSchool->id])->assertSuccessful();
        $this->assertSame(0, $this->allDigests());
        $this->assertSame(1, $this->unannounced());

        $this->artisan('groups:notify-reactions', ['--masjid' => $this->school->id])->assertSuccessful();
        $this->assertSame(1, $this->allDigests());
    }

    #[Test]
    public function the_sweep_can_be_narrowed_to_one_school_for_message_reactions_too(): void
    {
        $thread = $this->privateThread();
        $this->react('family', $this->parentA, $this->teacherMessage($thread), 'ameen', $thread);
        $this->settle();

        $this->artisan('groups:notify-reactions', ['--masjid' => $this->otherSchool->id])->assertSuccessful();
        $this->assertSame(0, $this->allDigests(), 'another school\'s sweep does not announce this school\'s message reactions');
        $this->assertSame(1, $this->unannounced(), 'and does not claim them');

        $this->artisan('groups:notify-reactions', ['--masjid' => $this->school->id])->assertSuccessful();
        $this->assertSame(1, $this->digests($this->teacher->email));
    }

    #[Test]
    public function the_sweeps_one_log_line_is_a_warning_because_production_drops_info(): void
    {
        Log::spy();
        $this->react('family', $this->parentA, $this->makePost());
        $this->settle();

        $this->runDigest();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'groups:notify-reactions') && str_contains($message, '1 digest(s) for 1 reaction(s)'));
    }

    #[Test]
    public function the_sweep_and_the_job_leave_the_tenant_as_they_found_it(): void
    {
        $this->react('family', $this->parentA, $this->makePost());
        $this->settle();
        $tenant = app(TenantContext::class);

        // Unbound before, unbound after: a long-lived worker or the scheduler must not
        // carry this class's organisation into whatever it runs next.
        $tenant->forgetTenant();
        $this->runDigest();
        $this->assertNull($tenant->get());

        $job = new SendGroupNotificationJob(
            (int) $this->school->id, (int) $this->class->id, GroupNotificationEvent::REACTION,
            recipientUserId: $this->teacher->id, subjects: ['story'],
        );
        $tenant->forgetTenant();
        $job->handle(app(GroupNotificationRecipientResolver::class), app(GroupPushChannel::class));
        $this->assertNull($tenant->get());

        // Bound to some other organisation before, and that one is back after.
        $tenant->set((int) $this->otherSchool->id);
        app(GroupNotificationRecipientResolver::class)
            ->principalMayStillRead($this->class, $this->teacher->id, null, null);
        $this->assertSame((int) $this->otherSchool->id, $tenant->get());
    }

    #[Test]
    public function the_sweep_is_scheduled_hourly_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'groups:notify-reactions'));

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('20 * * * *', $event->expression);
        // Not the 24 h default: a killed run never releases its mutex, and one dead
        // run would then silence the digest for a day. 55 minutes lapses before the
        // next :20, so a killed run costs only its own hour.
        $this->assertSame(55, $event->expiresAt);
        $this->assertLessThan(60, $event->expiresAt, 'a lock that outlives the hour would skip the next run');
    }

    // ------------------------------------------------------------ the job's sign-in address

    #[Test]
    public function a_parents_reply_sends_the_teacher_to_the_staff_sign_in_not_the_family_portal(): void
    {
        $job = new SendGroupNotificationJob(
            (int) $this->school->id, (int) $this->class->id, GroupNotificationEvent::TEACHER_THREAD_MESSAGE,
            authorContactId: $this->parentA->id,
        );

        $job->handle(app(GroupNotificationRecipientResolver::class), app(GroupPushChannel::class));

        $base = rtrim((string) config('app.url'), '/');
        Mail::assertSent(GroupUpdateNudgeMail::class, fn ($m) => $m->hasTo($this->teacher->email)
            && $m->kind === 'message' && $m->signInUrl === $base.'/auth/sign-in');
        Mail::assertNotSent(GroupUpdateNudgeMail::class, fn ($m) => str_contains($m->signInUrl, '/family/'));
    }

    #[Test]
    public function a_teachers_message_still_sends_the_guardian_to_the_family_portal(): void
    {
        $job = new SendGroupNotificationJob(
            (int) $this->school->id, (int) $this->class->id, GroupNotificationEvent::CLASS_STORY,
            authorUserId: $this->teacher->id,
        );

        $job->handle(app(GroupNotificationRecipientResolver::class), app(GroupPushChannel::class));

        $base = rtrim((string) config('app.url'), '/');
        foreach ([$this->parentA, $this->parentB] as $parent) {
            Mail::assertSent(GroupUpdateNudgeMail::class, fn ($m) => $m->hasTo($parent->login_email)
                && $m->signInUrl === $base.'/family/'.$this->school->id.'/sign-in');
        }
        Mail::assertNotSent(GroupUpdateNudgeMail::class, fn ($m) => str_contains($m->signInUrl, '/auth/sign-in'));
    }

    // ------------------------------------------------------------ schema

    #[Test]
    public function the_schema_is_what_the_code_assumes(): void
    {
        foreach (['group_message_reactions', 'group_post_reactions'] as $table) {
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'notified_at');

            $this->assertNotNull($column, "{$table}.notified_at is missing");
            $this->assertTrue($column['nullable'], "{$table}.notified_at must be nullable: NULL is 'not yet announced'");
            $this->assertStringContainsString('datetime', strtolower($column['type']), 'a timestamp() would carry MySQL\'s implicit ON UPDATE');

            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), "MySQL caps an index name at 64: {$index['name']}");
            }
        }
    }

    #[Test]
    public function the_migration_rolls_back_and_forward_cleanly(): void
    {
        $migration = require database_path('migrations/2026_10_02_120000_add_notified_at_to_group_reaction_tables.php');

        $migration->down();
        foreach (['group_message_reactions', 'group_post_reactions'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'notified_at'));
        }

        $migration->up();
        foreach (['group_message_reactions', 'group_post_reactions'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'notified_at'));
        }
    }
}
