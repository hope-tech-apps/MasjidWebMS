<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'api' => [
            'driver' => 'sanctum',
            'provider' => 'users'
        ],

        /*
        | SECURITY — this entry exists to PIN the provider. Do not remove it and
        | do not set `provider` to null.
        |
        | Sanctum does not require an `auth.guards.sanctum` entry: when one is
        | absent, `SanctumServiceProvider::register()` synthesises the guard with
        | `'provider' => null` (vendor/laravel/sanctum/src/SanctumServiceProvider.php:23-28),
        | and that null is handed to the guard as its provider name
        | (SanctumServiceProvider.php:105-116). `Guard::hasValidProvider()` then
        | short-circuits to `true` for a null provider
        | (vendor/laravel/sanctum/src/Guard.php:145-154), so the "does this token's
        | owner belong to this guard" check never actually runs.
        |
        | The consequence of leaving it unpinned: EVERY model that uses
        | `HasApiTokens` is admissible on EVERY `auth:sanctum` route. Today
        | `App\Models\User` is the only tokenable model in the app, so nothing is
        | exploitable — but the moment a second tokenable exists (e.g. a `Contact`
        | gaining a parent/guardian login, T-015c), its token would authenticate on
        | the admin API and be stopped only by `UserAdminMiddleware`'s `type` check.
        |
        | Pinning it to `users` makes `hasValidProvider()` compare the token's
        | tokenable against `auth.providers.users.model` (App\Models\User). A
        | non-User tokenable resolves to null — i.e. unauthenticated — inside
        | vendor code, BEFORE any application middleware runs. That is a structural
        | barrier rather than a policy one, which is why it ships on its own.
        |
        | KNOWN SIDE EFFECT, already paid for — do not rediscover it the hard way.
        | Adding this entry broke every `permission:`-gated CRM route until
        | `App\Models\User` was given an explicit `$guard_name = 'web'`.
        | `spatie/laravel-permission` derives a model's guard name from the
        | `auth.guards` entries whose provider model matches, preferring
        | `config('auth.defaults.guard')` when it is among them — and
        | `AuthManager::shouldUse()` REWRITES that config value to `sanctum` on
        | every authenticated request. Before this entry existed, `sanctum` was
        | not a declared guard and so could never match; declaring it made the
        | permission layer start looking for permissions under guard `sanctum`,
        | where none are seeded. See the comment on `User::$guard_name` and
        | tests/Feature/StaffAuthGuardPinTest.php. Any FURTHER guard pointed at
        | the users provider inherits the same hazard.
        |
        | Pinned by T-015a. See .claude/rules/auth-permissions.md and
        | docs/t015-parent-identity-design.md §5.
        */
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],

        /*
        | The parent/guardian realm (T-015c). A SEPARATE guard over a SEPARATE
        | provider — never a loosened `sanctum`.
        |
        | This is the shape .claude/rules/auth-permissions.md mandates for a
        | second realm, and it buys the separation in BOTH directions inside
        | vendor code, before any application middleware runs:
        |
        |   - a Contact token presented on an `auth:sanctum` route is compared
        |     against `auth.providers.users.model` and resolves to null;
        |   - a staff User token presented on an `auth:family` route is compared
        |     against `auth.providers.contacts.model` and resolves to null.
        |
        | Both directions matter. The reverse one is not hypothetical: staff
        | tokens were minted `['*']` until T-015a, so an ability check alone
        | would never have fenced staff out of the family surface.
        |
        | WHAT THIS GUARD DOES NOT DO, and why `family.active` exists.
        | Sanctum checks `config('sanctum.guard')` — `['web']` here — BEFORE it
        | looks at the bearer token, and returns any user it finds on those
        | guards as-is, with no provider check at all. A staff member with a
        | live admin SPA session therefore SATISFIES `auth:family`. The
        | `family.active` middleware refuses a principal that is not a Contact
        | for exactly that reason; the provider pin is not sufficient on its own
        | and must not be treated as if it were. See
        | App\Http\Middleware\EnsureFamilyLoginActive and
        | docs/t015-parent-identity-design.md §5 (Q4).
        |
        | THE SPATIE HAZARD, and why this guard does not re-open it. Declaring
        | `sanctum` cost one real regression, because spatie derives a model's
        | permission guard from the `auth.guards` entries whose provider model
        | matches that model, preferring `config('auth.defaults.guard')` — which
        | `AuthManager::shouldUse()` rewrites on every authenticated request.
        | `auth:family` rewrites it to `family`. This entry is safe from that
        | because its provider model is `Contact`, NOT `User`: it can never join
        | the candidate list for `User`, whose `$guard_name` is pinned to `web`
        | anyway. A THIRD guard pointed back at the `users` provider WOULD
        | re-open it. `App\Models\Contact` holds no spatie roles; if it is ever
        | given any, it must declare its own `$guard_name` first.
        |
        | Expiry is the one thing this cannot express: Sanctum builds every
        | guard with the single global `config('sanctum.expiration')`, so the
        | family realm shares staff's 8 hours. See Contact::createFamilyToken().
        */
        'family' => [
            'driver' => 'sanctum',
            'provider' => 'contacts',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', App\Models\User::class),
        ],

        /*
        | The parent/guardian principal (T-015c). Consumed by the `family` guard
        | above and by NOTHING else — in particular not by `sanctum`, `web` or
        | `api`, which stay pinned to `users`.
        |
        | No `env()` override on purpose. The users provider carries one for
        | historical reasons; here the model class is a security boundary (it is
        | the value `Guard::hasValidProvider()` compares a tokenable against),
        | and a boundary that a stray environment variable can move is not a
        | boundary.
        */
        'contacts' => [
            'driver' => 'eloquent',
            'model' => App\Models\Contact::class,
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the amount of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
