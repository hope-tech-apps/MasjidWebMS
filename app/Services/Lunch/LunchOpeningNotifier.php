<?php

namespace App\Services\Lunch;

use App\Enums\BroadcastChannel;
use App\Models\Broadcast;
use App\Models\Masjid;
use App\Models\MealMenu;
use App\Services\Broadcast\BroadcastComposer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The text that goes out when a week's lunch opens for ordering.
 *
 * ## Once, and only once
 *
 * "Open" is a status an admin toggles, not an event that happens once. Fixing a
 * typo is open -> draft -> open; so is closing early and reopening. Each of
 * those must not be a fresh text to the entire list — the fastest way to collect
 * STOPs and get a 10DLC campaign suspended is to message people twice for the
 * same lunch. `opening_notified_at` is claimed inside a row lock BEFORE anything
 * is sent, so two concurrent saves cannot both win it.
 *
 * ## It sends nothing on its own
 *
 * Everything downstream belongs to the broadcast stack: BroadcastComposer writes
 * the record, SmsChannel refuses to send unless this masjid has a carrier-
 * APPROVED 10DLC sender, SmsAudience filters to contacts who actually consented
 * and drops anyone on the suppression list, and the reply keywords are handled
 * by the inbound webhook. Nothing here reimplements any of that, which is the
 * point: there is one door to sending a text and this walks through it.
 *
 * A failure to notify is never a failure to open the menu. Ordering working is
 * worth more than the announcement, and the announcement can be re-sent by hand.
 */
class LunchOpeningNotifier
{
    public function __construct(private BroadcastComposer $composer)
    {
    }

    /**
     * Announce this menu if it just became orderable and has not been announced.
     *
     * @return Broadcast|null the broadcast, or null when nothing was sent
     */
    public function notifyOpened(MealMenu $menu, ?int $authorId = null): ?Broadcast
    {
        if (! $this->claim($menu)) {
            return null;
        }

        try {
            $masjid = Masjid::withoutGlobalScopes()->find($menu->masjid_id);

            if (! $masjid) {
                return null;
            }

            return $this->composer->send(
                masjid: $masjid,
                attributes: [
                    'title' => $this->title($menu),
                    'body' => $this->body($menu),
                    'link' => $this->orderUrl($menu),
                    'audience' => 'service',
                    'service_id' => $menu->notify_service_id,
                ],
                channels: [BroadcastChannel::SMS],
                image: null,
                authorId: $authorId,
            );
        } catch (\Throwable $e) {
            // The menu is open and orderable; that is the part that matters.
            Log::error('Lunch opening notification failed.', [
                'meal_menu_id' => $menu->id,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Win the right to announce this menu, exactly once.
     *
     * The lock and the re-read are the whole method: two admins hitting Save at
     * the same moment, or a retried request, must produce ONE text. The claim is
     * committed before a single message is composed, so a crash mid-send costs
     * the announcement rather than duplicating it — the safer way to fail when
     * the alternative is texting a list twice.
     */
    private function claim(MealMenu $menu): bool
    {
        if ($menu->status !== MealMenu::STATUS_OPEN
            || $menu->notify_service_id === null
            || $menu->opening_notified_at !== null) {
            return false;
        }

        return DB::transaction(function () use ($menu) {
            $fresh = MealMenu::withoutMasjidScope()
                ->whereKey($menu->id)
                ->lockForUpdate()
                ->first();

            if (! $fresh || $fresh->opening_notified_at !== null) {
                return false;
            }

            $fresh->forceFill(['opening_notified_at' => now()])->save();
            $menu->opening_notified_at = $fresh->opening_notified_at;

            return true;
        });
    }

    private function title(MealMenu $menu): string
    {
        return trim(($menu->title ?: 'Jummah Lunch')) . ' is open for orders';
    }

    /**
     * The message body.
     *
     * Short on purpose: every segment past 160 characters is billed again to the
     * organisation, and a link the recipient has to hunt for defeats the point.
     * The organisation's name leads, because a text from an unknown number that
     * does not identify its sender is the definition of the thing carriers
     * filter. STOP is not appended here — the carrier-level reply handling and
     * the platform's own footer own that.
     */
    private function body(MealMenu $menu): string
    {
        $when = optional($menu->service_date)->format('D M j');

        return trim(sprintf(
            '%s is open for orders%s. Order here: %s',
            $menu->title ?: 'Jummah Lunch',
            $when ? ' for ' . $when : '',
            $this->orderUrl($menu),
        ));
    }

    private function orderUrl(MealMenu $menu): string
    {
        return rtrim((string) config('app.url'), '/') . '/jummah-lunch/' . $menu->masjid_id;
    }
}
