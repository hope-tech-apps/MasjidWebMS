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

## Related live defect (separate from this rule)

While auditing the above we found that `notifications.is_broadcasted` **does not
exist as a column**, yet `PusherWebhookController` writes it on every delivery
confirmation. Because the attribute is also absent from `Notification::$fillable`,
Eloquent's mass-assignment protection drops it silently: the webhook responds
`{"status":"success","message":"Notification broadcast confirmed."}` and records
nothing. The whole broadcast-confirmation feature is inert — the event is never
dispatched either. Finish it or remove it deliberately; do not assume the flag
means anything today.
