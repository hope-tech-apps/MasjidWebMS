# Shipping to a live page

Three bugs reached Burlington's live Jummah-lunch page on 2026-09-09, one of them
blocking every order on a sale day until customers complained. All three passed a
2465-test green suite. They share one cause: **each was verified through a layer we
wrote, instead of the layer a customer touches.** The rules below are the specific
forms that took.

## A green suite says nothing about the transport

The suite runs **in-memory SQLite** and posts with **`postJson`**. Production runs
**MySQL** and the SPA posts **form-encoded**. Both differences silently swallow
real failures, and neither shows up as a red test.

**Booleans.** Laravel's `boolean` rule accepts `true, false, 1, 0, "1", "0"` and
**rejects the strings `"true"` and `"false"`**. `resources/vue-app/core/services/
ApiService.ts` pins a global axios `Content-Type: application/x-www-form-urlencoded`
that every axios instance inherits — including the tokenless public clients that
create their own. So a checkbox arrives as exactly the two strings the rule refuses.

Coerce in the request, never at the call site:

```php
protected function prepareForValidation(): void
{
    foreach (['cover_fees', 'notify_sms'] as $field) {
        if (! $this->has($field)) {
            continue;
        }

        // FILTER_NULL_ON_FAILURE so genuine nonsense still fails validation
        // rather than being silently read as false.
        $this->merge([
            $field => filter_var($this->input($field), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);
    }
}
```

Server-side because it is the only side that reaches a browser holding a **cached
bundle**. A frontend fix leaves those customers broken for as long as the cache lives.

**Column lengths.** SQLite does not enforce `VARCHAR(n)` at all. A 297-character
consent disclosure round-tripped green in tests and was rejected by MySQL in strict
mode on every single write. A round-trip test cannot catch this — it passes on SQLite
whatever the declared length. Assert the **type**:

```php
$this->assertSame('text', Schema::getColumnType('contacts', 'sms_consent_evidence'));
```

Any column holding content whose length is decided elsewhere — legal disclosures,
imported data, third-party identifiers — is `text`, and a test says so.

**Write at least one test in the client's own encoding.** `$this->post(...)` form-encoded,
or string `'true'`/`'false'` values through `postJson`. See
`MealOrderFeeCoverageTest::the_checkbox_answers_survive_being_sent_as_strings`.

**Why this rule exists.** On 2026-09-09 `cover_fees` shipped as `sometimes|boolean`.
Every test used `postJson` with real PHP booleans — the one encoding the browser never
sends. Every live order 422'd with *"The cover fees field must be true or false."* on
the morning of a sale, and the user found out from customers.

## A form field that is not in the serialiser is silently discarded

`resources/vue-app/stores/masjid/jummahLunchStore.ts` builds its PUT body by hand in
`menuUrlParams()`. A `v-model` in the modal that has no matching `b.append(...)` is
dropped on save, the request succeeds, and the form redisplays the stale value **as
though it had worked**. There is no error anywhere.

Adding a field to an admin form is therefore always two edits: the template and the
serialiser. Booleans go as `"1"`/`"0"` — never `"true"`/`"false"`, per the rule above.
A nullable id clears with `""`.

**Why this rule exists.** `collect_customer_email`, `allow_donation`,
`allow_fee_coverage`, `allow_sms_optin` and `notify_service_id` were all missing from
`menuUrlParams`. Creating a menu worked (that path posts the object directly); editing
one never did. The email toggle had been unsaveable-by-edit since the day it shipped
and nobody noticed, because the UI reported success.

## A swallowed failure must be logged at or above the deployed level

Production runs `LOG_LEVEL=warning`. A deliberate `catch (\Throwable)` that logs at
`info` is not resilience, it is **silence** — the feature fails, the response is a 200
by design, and nothing appears in the log, the response or the database.

Before writing a catch-and-continue, check the deployed level (`grep LOG_LEVEL .env`)
and log at or above it. And when a service returns a falsy result with no log line,
suspect the level before concluding an early return fired.

**Why this rule exists.** The lunch SMS opt-in swallows every failure so that
recording consent can never cost a customer their order — correct — and logged at
`info`. MySQL was rejecting every write and it was undiscoverable until the service
body was re-run inline with the exception echoed.

## Exercise the real page before calling it shipped

For anything a customer or admin touches, the last step is not a passing test. It is
**driving the deployed page the way they do**: open it, click the control, submit the
form, read what comes back.

This is not optional for the Jummah-lunch pages, which take real money on a deadline.
It costs about a minute. Rendering the actual output rather than reading the code that
builds it also caught, in one sitting: an SMS carrying the link, the org name and the
STOP line **twice** each (segments are billed per recipient); a Friday lunch announced
as *"Thursday, September 10"* because a calendar date was timezone-converted; and a
consent checkbox reading *"Add $0.00"* until it was ticked.

Every one of those was invisible in the source and obvious on screen.

**Deploy order for a live page.** Land a new customer-visible option **off**
(`allow_* = false` for every row), ship the assets, confirm the page is unchanged, then
enable it for the one menu that wants it. A customer mid-order must never meet a
half-deployed form. See `manara-jummah-lunch-ordering` for the sequence used on
2026-09-09.
