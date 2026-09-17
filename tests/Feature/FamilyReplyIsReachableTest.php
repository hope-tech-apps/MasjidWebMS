<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A parent must be able to REACH the reply box, not merely be entitled to it.
 *
 * WHY THIS TEST EXISTS
 * --------------------
 * Every server-side part of replying worked: the route existed, the audience
 * gate said yes for every real guardian on every real thread, the request
 * validated, and a reply posted by curl answered 201. What did not work was the
 * screen. Opening a conversation left the whole thread list in place and
 * appended the conversation BELOW it, and nothing scrolled — so on a phone the
 * tap looked inert and the reply box was off-screen under the list. Parents
 * reported that they could not answer their child's teacher, and production's
 * access log agreed: conversations were opened and not one reply was ever
 * posted, so no request ever reached the endpoint the tests cover.
 *
 * Feature tests cannot render Vue, so this pins the two structural facts that
 * make the box reachable. It is a floor, not a ceiling.
 */
class FamilyReplyIsReachableTest extends TestCase
{
    private function source(): string
    {
        $path = base_path('resources/vue-app/views/family/FamilyClass.vue');
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    #[Test]
    public function the_thread_list_gives_way_to_the_open_conversation(): void
    {
        $source = $this->source();

        // The list renders only while nothing is open. Without this the reply
        // box sits below every thread in the list.
        $this->assertMatchesRegularExpression(
            '/v-else-if="!openedThread" class="d-flex flex-column gap-2"/',
            $source,
            'the thread list must be hidden while a conversation is open, or the reply box is pushed off-screen'
        );

        // And the conversation is no longer appended under the list.
        $this->assertStringNotContainsString(
            '<div v-if="openedThread" class="card border-0 shadow-sm mt-3">',
            $source,
            'the open conversation must not be appended below the list'
        );
    }

    #[Test]
    public function opening_a_conversation_brings_it_into_view_and_there_is_a_way_back(): void
    {
        $source = $this->source();

        // Opening lands on the REPLY BOX, and re-lands once photos have loaded:
        // the first staging walk showed the box at 804px in an 812px viewport
        // because attachments finished loading after the scroll and grew the page.
        $this->assertStringContainsString('const scrollToReply = () => {', $source,
            'opening a conversation must move the viewport to the reply box');
        $this->assertStringContainsString('scrollIntoView({ block: \'end\', behavior: \'smooth\' })', $source);
        $this->assertMatchesRegularExpression('/addEventListener\(\'load\', land, \{ once: true \}\)/', $source,
            'a photo that loads after the scroll must not push the reply box back off-screen');
        $this->assertMatchesRegularExpression('/ref="replyAnchor"/', $source);
        $this->assertStringContainsString('const closeThread = () => {', $source);
        $this->assertMatchesRegularExpression('/@click="closeThread"/', $source,
            'a parent needs a visible way back to the list');
        $this->assertStringContainsString("t('threads_all')", $source);
    }

    #[Test]
    public function the_reply_box_is_still_offered_on_an_open_conversation(): void
    {
        $source = $this->source();

        // The box itself: shown unless the school closed the thread.
        $this->assertMatchesRegularExpression('/v-if="openedThread\.is_closed"/', $source);
        $this->assertMatchesRegularExpression('/v-model="replyBody"/', $source);
        $this->assertMatchesRegularExpression('/@click="sendReply"/', $source);
    }
}
