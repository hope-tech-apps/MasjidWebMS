<?php

namespace Tests\Support;

use App\Models\ArabicDailyNote;
use App\Models\BehaviorAward;
use App\Models\BehaviorSkill;
use App\Models\ClassAssignment;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupMembership;
use App\Models\GroupMessage;
use App\Models\GroupMessageAttachment;
use App\Models\GroupPost;
use App\Models\GroupPostAttachment;
use App\Models\GroupResource;
use App\Models\GroupThread;
use App\Models\HifzEntry;
use App\Models\LessonPlan;
use App\Models\Masjid;
use App\Models\ReportCard;
use App\Models\ReportCardMark;
use App\Models\User;
use App\Services\Schools\ReportCardService;
use Illuminate\Support\Facades\Storage;

/**
 * One class in one school with a REAL row behind every id the teacher realm's URLs
 * and request bodies can name: a student, an award, a Hifdh entry, a daily Arabic
 * note, a lesson plan, an assignment, a report card and one of its marks, a class
 * file, a class-story post, a conversation and a message, and a video attachment
 * on each of the last two (bytes on the fake disk, so a download really streams).
 *
 * The leak sweep in TeacherMultiSchoolTest needs these because an id that exists
 * nowhere is refused by EVERY route with a 404, whether or not the route checks
 * anything: a sweep that fills its placeholders with 999999 is vacuous (that is
 * what the first version of it did). With school B's genuine ids in school A's
 * URLs, the only thing standing between the request and B's row is the tenant
 * (or leader) check under test.
 *
 * Every string a response could echo carries `MARK-<tag>-`, and the student's
 * name `Mark<tag>Student`, so a body can be searched for another school's data.
 *
 * Build all worlds BEFORE the first request of a test: TenantContext is scoped to
 * the app, not to a request, so after a request it is still bound and its
 * `creating` hook would stamp the next fixture with that school's id.
 */
final class TeacherRealmWorld
{
    /** The report-card period the fixture card is stored under; routes are called with it explicitly. */
    public const CARD_PERIOD = ['type' => ReportCard::TYPE_REPORT_CARD, 'school_year' => '2026-2027', 'term' => 1];

    public Masjid $school;
    public Group $class;
    public string $tag;
    /** Whether the signed-in teacher leads this class. */
    public bool $led;

    public GroupMembership $student;
    public BehaviorSkill $skill;
    public BehaviorAward $award;
    public HifzEntry $hifz;
    public ArabicDailyNote $note;
    public LessonPlan $plan;
    public ClassAssignment $assignment;
    public ReportCard $card;
    public ReportCardMark $mark;
    public GroupResource $resource;
    public GroupPost $post;
    public GroupPostAttachment $postAttachment;
    public GroupThread $thread;
    public GroupMessage $message;
    public GroupMessageAttachment $messageAttachment;

    /**
     * @param BehaviorSkill $skill the school's skill, shared by every class of that school
     */
    public static function seed(Masjid $school, Group $class, User $teacher, BehaviorSkill $skill, string $tag, bool $led): self
    {
        $w = new self();
        $w->school = $school;
        $w->class = $class;
        $w->tag = $tag;
        $w->led = $led;
        $w->skill = $skill;

        $masjid = (int) $school->id;
        $group = (int) $class->id;

        // The contact has no email: GroupAudience resolves a caller's person by
        // login email, and a chance match would flip an authorization outcome.
        $child = Contact::factory()->create([
            'masjid_id' => $masjid, 'first_name' => "Mark{$tag}Student", 'last_name' => 'Child', 'email' => null,
        ]);

        $w->student = GroupMembership::create([
            'masjid_id' => $masjid, 'group_id' => $group,
            'contact_id' => $child->id, 'role' => GroupMembership::ROLE_MEMBER,
        ]);

        $w->award = BehaviorAward::factory()->create([
            'masjid_id' => $masjid, 'group_id' => $group, 'group_membership_id' => $w->student->id,
            'behavior_skill_id' => $skill->id, 'awarded_by_user_id' => $teacher->id,
            'skill_label' => $skill->label, 'note' => "MARK-{$tag}-AWARD", 'awarded_at' => now(),
        ]);

        $w->hifz = HifzEntry::factory()->create([
            'masjid_id' => $masjid, 'group_id' => $group, 'group_membership_id' => $w->student->id,
            'heard_by_user_id' => $teacher->id, 'note' => "MARK-{$tag}-HIFZ", 'recited_at' => now(),
        ]);

        $w->note = ArabicDailyNote::create([
            'masjid_id' => $masjid, 'group_id' => $group, 'group_membership_id' => $w->student->id,
            'marked_by_user_id' => $teacher->id, 'session_date' => now()->toDateString(), 'note' => "MARK-{$tag}-ARABIC",
        ]);

        $w->plan = LessonPlan::create([
            'masjid_id' => $masjid, 'group_id' => $group, 'author_user_id' => $teacher->id,
            'session_date' => now()->toDateString(), 'body' => "MARK-{$tag}-PLAN",
        ]);

        $w->assignment = ClassAssignment::create([
            'masjid_id' => $masjid, 'group_id' => $group, 'created_by_user_id' => $teacher->id,
            'title' => "MARK-{$tag}-ASSIGNMENT", 'points_possible' => 10,
            'scale' => ClassAssignment::SCALE_POINTS, 'assigned_on' => now()->toDateString(),
        ]);

        // Through the service, so the card already carries the template's rows and a
        // route that "prepares" it again writes nothing.
        $w->card = app(ReportCardService::class)->prepare(
            $w->student,
            self::CARD_PERIOD['type'],
            self::CARD_PERIOD['school_year'],
            self::CARD_PERIOD['term'],
        );
        $w->card->update(['teacher_comment' => "MARK-{$tag}-CARD"]);
        $w->mark = $w->card->marks()->firstOrFail();

        $resourceDisk = (string) config('groups.resources.disk', 'local');
        $resourcePath = "group-resources/{$masjid}/{$group}/{$tag}-file.pdf";
        Storage::disk($resourceDisk)->put($resourcePath, "MARK-{$tag}-FILE-BYTES");

        $w->resource = GroupResource::create([
            'masjid_id' => $masjid, 'group_id' => $group, 'uploaded_by_user_id' => $teacher->id,
            'title' => "MARK-{$tag}-RESOURCE", 'description' => null, 'visibility' => GroupResource::VISIBILITY_STAFF,
            'original_name' => "{$tag}-file.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 24,
            'disk' => $resourceDisk, 'path' => $resourcePath,
        ]);

        $mediaDisk = (string) config('groups.media.disk', 'local');

        $w->post = GroupPost::factory()->create([
            'masjid_id' => $masjid, 'group_id' => $group, 'author_user_id' => $teacher->id,
            'title' => "MARK-{$tag}-POST-TITLE", 'body' => "MARK-{$tag}-POST",
        ]);
        $postPath = "group-media/{$masjid}/{$group}/{$tag}-post.mp4";
        Storage::disk($mediaDisk)->put($postPath, "MARK-{$tag}-POST-VIDEO");
        $w->postAttachment = GroupPostAttachment::create([
            'masjid_id' => $masjid, 'group_post_id' => $w->post->id,
            'original_name' => "{$tag}-post.mp4", 'mime_type' => 'video/mp4', 'size_bytes' => 22,
            'disk' => $mediaDisk, 'path' => $postPath,
        ]);

        $w->thread = GroupThread::factory()->create([
            'masjid_id' => $masjid, 'group_id' => $group, 'created_by_user_id' => $teacher->id,
            'subject' => "MARK-{$tag}-THREAD-SUBJECT",
        ]);
        $w->message = GroupMessage::factory()->create([
            'masjid_id' => $masjid, 'group_thread_id' => $w->thread->id,
            'author_user_id' => $teacher->id, 'body' => "MARK-{$tag}-MESSAGE",
        ]);
        $messagePath = "group-media/{$masjid}/{$group}/{$tag}-message.mp4";
        Storage::disk($mediaDisk)->put($messagePath, "MARK-{$tag}-MESSAGE-VIDEO");
        $w->messageAttachment = GroupMessageAttachment::create([
            'masjid_id' => $masjid, 'group_message_id' => $w->message->id,
            'original_name' => "{$tag}-message.mp4", 'mime_type' => 'video/mp4', 'size_bytes' => 25,
            'disk' => $mediaDisk, 'path' => $messagePath,
        ]);

        return $w;
    }

    /**
     * The value for one URL placeholder, taken from this world.
     *
     * `attachment_id` means a post's attachment under `/posts/…` and a message's
     * under `/threads/…`, so the route's own URI decides. An unknown placeholder
     * throws: a route added with a new kind of id must teach the sweep about it,
     * not be filled with a number that exists nowhere.
     */
    public function idFor(string $placeholder, string $uri): string
    {
        return match ($placeholder) {
            'group_id' => (string) $this->class->id,
            'membership_id' => (string) $this->student->id,
            'note_id' => (string) $this->note->id,
            'award_id' => (string) $this->award->id,
            'plan_id' => (string) $this->plan->id,
            'assignment_id' => (string) $this->assignment->id,
            'resource_id' => (string) $this->resource->id,
            'entry_id' => (string) $this->hifz->id,
            'post_id' => (string) $this->post->id,
            'thread_id' => (string) $this->thread->id,
            'message_id' => (string) $this->message->id,
            'attachment_id' => str_contains($uri, '/posts/')
                ? (string) $this->postAttachment->id
                : (string) $this->messageAttachment->id,
            'reaction' => 'thumbs_up',
            default => throw new \LogicException("The sweep has no real id for the route placeholder {{$placeholder}}; add one to TeacherRealmWorld::idFor()."),
        };
    }
}
