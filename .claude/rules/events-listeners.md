---
paths:
  - "app/Listeners/**"
  - "app/Events/**"
  - "app/Providers/AppServiceProvider.php"
---
# Event listeners: discovery is ON, and you cannot see it from `bootstrap/app.php`

## The trap

`bootstrap/app.php` never mentions events, so it looks like nothing is
auto-registered. It is. `Illuminate\Foundation\Application::configure()` calls
`->withEvents()` unconditionally before the builder is ever returned
(verified in `vendor/laravel/framework/.../Foundation/Application.php`), and
`withEvents()` registers `AppEventServiceProvider` with discovery enabled. Every
class under `app/Listeners` whose `handle()` type-hints an event is therefore
registered **automatically**.

So a hand-written `Event::listen(SomeEvent::class, SomeListener::class)` in
`AppServiceProvider::boot()` does not *add* the listener — it registers it a
**second time**, and the handler runs twice per dispatch. There is no error and
no log line.

This was confirmed empirically: commenting out an explicit `Event::listen` left
the listener still firing (traced with a backtrace during the S0/S1 work).

## The convention

**Discovery is the default. Do not hand-register a listener that lives under
`app/Listeners` with a typed `handle()`.**

The one permitted exception is a listener that is *both*:

1. enforcing a **correctness invariant** that must not silently stop working if
   discovery is ever disabled (`->withEvents(false)`), and
2. **idempotent**, so running it twice per dispatch is a genuine no-op.

Such a listener MUST say so in its class docblock, naming both properties, and
MUST be listed in the allowlist in
`tests/Feature/ListenerRegistrationTest.php`. That test fails if any other
listener becomes doubly registered, which is the only reliable way to notice.

Today there is exactly one entry: `ResetTenantContextBetweenJobs` (S1). Read its
docblock before touching its registration — the duplicate is deliberate and
`TenantContext::forgetTenant()` is idempotent.

## Do not leave a listener with a commented-out body

`SentMasjidNotificationLitener` was deleted on 2026-08-11 for this reason. Its
entire `handle()` body was commented out, so the double registration above was
inert — right up until someone uncommented it. Worse, what it would have done
was already wrong (see below). A listener that does nothing is not harmless; it
is a loaded gun with the safety on.

If a listener has no work to do, delete it. Git remembers.

## The Pusher realtime path was removed (2026-08-11)

The audit that produced this rule found the whole broadcast-confirmation feature
inert in three independent ways, so it was deleted rather than finished:

- **No event was ever dispatched.** `SendMasjidNotificationEvent` had no
  producer, and the debug endpoint that fired `TestNotificationEvent` had already
  been removed in the security sweep.
- **The webhook could never authenticate.** `PUSHER_WEBHOOK_SECRET` was never set
  in production and the controller fails closed, so every call was rejected.
- **The flag it wrote does not exist.** `notifications.is_broadcasted` is not a
  column and was not in `$fillable`, so mass-assignment protection dropped the
  write silently while the endpoint answered *"Notification broadcast
  confirmed."*

Deleted: `SendMasjidNotificationEvent`, `TestNotificationEvent`,
`PusherWebhookController` and its route. Per-channel delivery is now recorded
properly by T-008 (`broadcasts` + `broadcast_deliveries`, see
`.claude/rules/broadcasts.md`), which supersedes this half-built mechanism.

Production still carries `BROADCAST_CONNECTION=pusher` and `PUSHER_*`
credentials; nothing reads them now, so they can be retired at leisure.
