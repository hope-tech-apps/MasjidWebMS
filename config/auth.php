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
