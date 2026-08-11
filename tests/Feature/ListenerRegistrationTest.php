<?php

namespace Tests\Feature;

use App\Listeners\ResetTenantContextBetweenJobs;
use App\Support\TenantContext;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the one event-registration trap this codebase has.
 *
 * `bootstrap/app.php` never mentions events, so it looks like nothing is
 * auto-registered. It is: `Application::configure()` calls `->withEvents()`
 * unconditionally, and every class under `app/Listeners` with a typed
 * `handle()` is discovered. A hand-written `Event::listen` for such a class
 * therefore registers it a SECOND time and the handler runs twice per dispatch,
 * silently — no exception, no log line.
 *
 * That already happened once: `SentMasjidNotificationLitener` was registered
 * both ways and was inert only because its entire body was commented out.
 * It was deleted on 2026-08-11. This test exists so the next one is caught by
 * CI instead of by a double send in production.
 *
 * See .claude/rules/events-listeners.md.
 */
class ListenerRegistrationTest extends TestCase
{
    /**
     * Listeners allowed to appear twice for one event, and why.
     *
     * A listener earns a place here only by being BOTH a correctness invariant
     * that must survive discovery being disabled AND idempotent, so the second
     * invocation is a genuine no-op. Both properties must be stated in the
     * class docblock. Adding an entry here is a deliberate act — if you are
     * doing it to make this test pass, you almost certainly have a real bug.
     *
     * @var array<class-string, string>
     */
    private const DELIBERATE_DUPLICATES = [
        ResetTenantContextBetweenJobs::class =>
            'S1 queue-leak fix: explicit registration must not depend on discovery '
            . 'staying enabled, and forgetTenant() is idempotent.',
    ];

    #[Test]
    public function no_listener_is_registered_twice_for_the_same_event(): void
    {
        $offenders = [];

        foreach (Event::getRawListeners() as $event => $listeners) {
            $counts = [];

            foreach ((array) $listeners as $listener) {
                $class = $this->listenerClass($listener);

                // Closures and framework internals are not our concern; only
                // app listener classes can be double-registered by the trap.
                if ($class === null || ! str_starts_with($class, 'App\\')) {
                    continue;
                }

                $counts[$class] = ($counts[$class] ?? 0) + 1;
            }

            foreach ($counts as $class => $count) {
                if ($count > 1 && ! array_key_exists($class, self::DELIBERATE_DUPLICATES)) {
                    $offenders[] = sprintf('%s registered %d× for %s', $class, $count, $event);
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['A listener is registered more than once, so its handler runs once per registration:'],
            $offenders,
            [
                '',
                'Almost certainly an Event::listen was added for a class that event',
                'discovery already registers. Delete the Event::listen — see',
                '.claude/rules/events-listeners.md. If the duplicate is genuinely',
                'deliberate, it must be idempotent, say so in its docblock, and be',
                'added to self::DELIBERATE_DUPLICATES with a reason.',
            ],
        )));
    }

    #[Test]
    public function the_one_allowlisted_duplicate_is_actually_duplicated(): void
    {
        // If this ever fails, the allowlist has gone stale: either the explicit
        // Event::listen was removed (fine — drop the entry) or discovery stopped
        // finding the class (NOT fine — the invariant now rests on one
        // registration and the docblock's reasoning no longer holds).
        $count = 0;

        foreach ((array) (Event::getRawListeners()[JobProcessing::class] ?? []) as $listener) {
            if ($this->listenerClass($listener) === ResetTenantContextBetweenJobs::class) {
                $count++;
            }
        }

        $this->assertSame(
            2,
            $count,
            'ResetTenantContextBetweenJobs should be registered exactly twice — '
            . 'once explicitly in AppServiceProvider and once by discovery. '
            . 'Re-read its docblock and .claude/rules/events-listeners.md before '
            . 'changing this expectation.'
        );
    }

    #[Test]
    public function the_allowlisted_duplicate_is_genuinely_idempotent(): void
    {
        // The allowlist's whole justification is that running twice is a no-op.
        // Assert that rather than trusting the docblock.
        $tenant = app(TenantContext::class);
        $tenant->set(1);

        $listener = app(ResetTenantContextBetweenJobs::class);
        $event = $this->jobProcessingEvent();

        $listener->handle($event);
        $first = $tenant->get();

        $listener->handle($event);
        $this->assertSame($first, $tenant->get(), 'Second invocation changed state; the duplicate is not a no-op.');
    }

    #[Test]
    public function the_deleted_notification_listener_has_not_come_back(): void
    {
        // It was inert only because its body was commented out, and what it
        // would have done was already wrong: it set is_broadcasted at DISPATCH
        // time, whereas that flag means "Pusher confirmed delivery" and is owned
        // by PusherWebhookController.
        $this->assertFalse(
            class_exists(\App\Listeners\SentMasjidNotificationLitener::class),
            'SentMasjidNotificationLitener was deleted deliberately — see .claude/rules/events-listeners.md.'
        );
    }

    /**
     * Normalize a raw registered listener to its class name, or null when it is
     * a closure or otherwise not a class reference.
     */
    private function listenerClass(mixed $listener): ?string
    {
        if (is_string($listener)) {
            return explode('@', $listener)[0];
        }

        if (is_array($listener) && isset($listener[0])) {
            return is_object($listener[0]) ? $listener[0]::class : (string) $listener[0];
        }

        return null;
    }

    /** A JobProcessing event whose job is a stub — the listener only reads the connection name. */
    private function jobProcessingEvent(): JobProcessing
    {
        $job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('getConnectionName')->andReturn('database');
        $job->shouldIgnoreMissing();

        return new JobProcessing('database', $job);
    }
}
