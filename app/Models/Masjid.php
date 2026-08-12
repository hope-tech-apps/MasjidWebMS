<?php

namespace App\Models;

use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Masjid extends Model implements HasMedia
{
    use InteractsWithMedia, SoftDeletes, SearchableTrait;

    /**
     * Manara vertical discriminator values (DECISIONS.md 2026-08-10).
     *
     * This constant — not a DB enum — is the authority on the allowed set, so
     * adding a vertical never requires an ALTER on a live table. See the
     * `add_org_type_to_masjids_table` migration for why.
     */
    public const ORG_TYPE_MASJID = 'masjid';
    public const ORG_TYPE_SCHOOL = 'school';
    public const ORG_TYPE_COMMUNITY = 'community';

    public const ORG_TYPES = [
        self::ORG_TYPE_MASJID,
        self::ORG_TYPE_SCHOOL,
        self::ORG_TYPE_COMMUNITY,
    ];

    protected $fillable = [
        'user_id',
        'name',
        'org_type',
        'email',
        'email_verified_at',
        'phone',
        'phone_verified_at',
        'country_id',
        'city_id',
        'address',
        'latitude',
        'longitude',
        'timezone',
        'copyright_text',
        'app_store_link',
        'google_play_link',
        'google_maps_key',
        'stripe_account_id',
        'stripe_charges_enabled',
        'stripe_payouts_enabled',
        'crm_enabled',
        'assistant_enabled',
        'tax_id',
        'statement_signatory',
        'mailing_locale',
        'created_by',
        'updated_by',
        'deleted_by'
        // `listed_at` is deliberately ABSENT: publishing an organisation to the
        // public directory is a SuperAdmin decision made through one endpoint
        // (MasjidsController::setDirectoryListing), never something a mass
        // assignment on some other write path can turn on by accident.
    ];

    /**
     * `active_owner_user_id` is the MySQL STORED generated column that carries
     * the S0 ownership-uniqueness index — see
     * `add_owner_uniqueness_to_masjids_table`. It is derived by the database from
     * `user_id`/`deleted_at`, never written, and exists only on that driver (the
     * SQLite test suite uses a native partial index and has no such column).
     *
     * Hiding it keeps the raw masjid payload the admin SPA reads
     * (`MasjidsController::show`, no Resource) identical on both drivers, and
     * identical to what it was before this slice.
     */
    protected $hidden = [
        'active_owner_user_id',
    ];

    /**
     * Columns that must NEVER reach an unauthenticated caller.
     *
     * `Mobile/MasjidsController` returned the whole `masjids` row to anonymous
     * callers — `$hidden` held one column and everything else went out as-is.
     * Measured against production on 2026-08-12 with a bare curl and no
     * credentials: Burlington's `google_maps_key` (billable to them), its
     * `stripe_account_id`, `tax_id` and `statement_signatory`, plus every
     * organisation's internal actor ids. Pre-existing, and it survived earlier
     * passes because they reviewed the row COUNT and not the row CONTENTS.
     *
     * A DENYLIST applied at the public boundary rather than on the model,
     * because putting these in `$hidden` would also blank them for the admin
     * surfaces that legitimately read and edit them. What makes a denylist
     * defensible is the companion guard in PublicMasjidDirectoryTest, which
     * fails when a NEW column appears in the public payload without being
     * classified — the same self-enforcing shape as the tenancy meta-test,
     * and the only thing that stops a list like this going quietly stale.
     *
     * `email` and `phone` deliberately REMAIN. A masjid's contact details are
     * the point of a public directory, and the shipped iOS build decodes
     * `email: String` NON-optionally (`Masjid.swift`) — dropping it would break
     * the masjid list in every installed copy. That is a hard constraint, not a
     * preference.
     *
     * @var list<string>
     */
    public const PUBLIC_DIRECTORY_DENYLIST = [
        // Credentials and money identifiers.
        'google_maps_key',
        'stripe_account_id',
        'stripe_charges_enabled',
        'stripe_payouts_enabled',
        'tax_id',
        'statement_signatory',
        // Internal actors and lifecycle. Verified optional in BOTH the iOS and
        // Android decoders before removal.
        'user_id',
        'created_by',
        'updated_by',
        'deleted_by',
        'deleted_at',
        'email_verified_at',
        'phone_verified_at',
        // Plan/feature flags — they describe the organisation's account, not its
        // public identity.
        'crm_enabled',
        'assistant_enabled',
        // An internal delivery preference for receipts and statements. Neither
        // app decodes it and it is not part of an organisation's public
        // identity, so it stays in — classified deliberately rather than
        // published by default, which is the whole point of the guard.
        'mailing_locale',
    ];

    protected $searchableFields = ['name', 'email', 'address'];

    protected function casts(): array
    {
        return [
            'stripe_charges_enabled' => 'boolean',
            'stripe_payouts_enabled' => 'boolean',
            // Per-masjid CRM feature gate; default false = CRM off (SuperAdmin-only toggle).
            'crm_enabled' => 'boolean',
            'assistant_enabled' => 'boolean',
            // Public directory listing gate — see scopeListed() below.
            'listed_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------
    // Public directory listing
    // ------------------------------------------------------------------

    /**
     * Organisations the mobile app's picker is allowed to show.
     *
     * NULL `listed_at` means "exists, but not published to the public directory"
     * — which is how every organisation is born, so creating a tenant no longer
     * publishes it (see the add_listed_at_to_masjids_table migration). A
     * SuperAdmin lists it deliberately once it is ready.
     *
     * This gates GET /mobile/masjids (the directory) and NOT
     * GET /mobile/masjids/{id}. Unlisting is a discoverability decision, not a
     * revocation: apps already installed hold a masjid id and keep working, so
     * an operator can pull an organisation out of the picker without breaking
     * the people already using it. Anything genuinely private is scoped by
     * tenant elsewhere — this is not an authorization boundary.
     */
    public function scopeListed($query)
    {
        return $query->whereNotNull('listed_at');
    }

    /** True when this organisation appears in the public directory. */
    public function isListed(): bool
    {
        return $this->listed_at !== null;
    }

    // ------------------------------------------------------------------
    // Manara verticals
    // ------------------------------------------------------------------

    /**
     * The tenant's vertical, falling back to masjid.
     *
     * Rows predating the org_type column read as NULL through a stale model
     * cache, and an unknown value must never silently grant a different
     * vertical's behaviour — so anything unrecognized degrades to masjid, which
     * is what every existing tenant already is.
     */
    public function orgType(): string
    {
        $type = (string) ($this->attributes['org_type'] ?? '');

        return in_array($type, self::ORG_TYPES, true) ? $type : self::ORG_TYPE_MASJID;
    }

    public function isMasjid(): bool
    {
        return $this->orgType() === self::ORG_TYPE_MASJID;
    }

    public function isSchool(): bool
    {
        return $this->orgType() === self::ORG_TYPE_SCHOOL;
    }

    public function isCommunity(): bool
    {
        return $this->orgType() === self::ORG_TYPE_COMMUNITY;
    }

    /**
     * This vertical's configuration block (label, default feature bundle,
     * terminology pack) from `config/verticals.php`.
     */
    public function verticalConfig(): array
    {
        return config('verticals.' . $this->orgType(), []);
    }

    /**
     * Admin-facing label for a domain concept in this tenant's language —
     * "Congregants" for a masjid, "Families" for a school.
     *
     * Falls back to the given key humanized so a missing entry degrades to
     * something readable rather than an empty label in the UI.
     */
    public function term(string $key): string
    {
        $terms = $this->verticalConfig()['terminology'] ?? [];

        return $terms[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * Feature keys this vertical enables BY DEFAULT at provisioning time.
     *
     * Not an authorization check: the `mobile_app_features` pivot's
     * `is_available` remains the runtime source of truth for what a tenant has.
     */
    public function defaultFeatureKeys(): array
    {
        return $this->verticalConfig()['feature_keys'] ?? [];
    }

    /**
     * Attributes the ADMIN payload carries on top of the raw columns.
     *
     * Attached per-request with `append(Masjid::ADMIN_APPENDS)` by the admin
     * controllers instead of being declared in `$appends`, because `$appends`
     * would also widen the public/mobile API responses — which have no business
     * knowing about verticals in this slice.
     */
    public const ADMIN_APPENDS = ['vertical'];

    /**
     * This tenant's vertical as the admin SPA consumes it: the discriminator
     * plus the labels it should render instead of hardcoded "Masjid" /
     * "Congregants" (PLAN T-003).
     */
    public function getVerticalAttribute(): array
    {
        $config = $this->verticalConfig();

        return [
            // orgType(), not the raw column: an unrecognized stored value must
            // reach the UI as a masjid, not as the vertical it claims to be.
            'org_type' => $this->orgType(),
            'label' => $config['label'] ?? '',
            'plural' => $config['plural'] ?? '',
            'terminology' => $config['terminology'] ?? [],
        ];
    }

    /** Limit a query to one vertical. */
    public function scopeOfOrgType($query, string $orgType)
    {
        return $query->where('org_type', $orgType);
    }

    public function admin() {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Everyone holding a membership in this masjid (`masjid_user`) — the inverse
     * of `User::memberships()`.
     *
     * Distinct from `admin()` above, and not a replacement for it: `user_id`
     * remains the single ownership/billing pointer (unique among live rows since
     * S0), while memberships are the many-to-many AUTHORIZATION grants S3 will
     * resolve the tenant from. Nothing consumes this relation in S2.
     */
    public function memberships() {
        return $this->hasMany(MasjidUser::class);
    }

    public function country() {
        return $this->belongsTo(Country::class, 'country_id', 'id');
    }

    public function city() {
        return $this->belongsTo(City::class, 'city_id', 'id');
    }

    public function donationLink() {
        return $this->hasOne(DonationLink::class);
    }

    public function masjidAbout() {
        return $this->hasOne(MasjidAbout::class);
    }

    public function iqamaTimeSettings() {
        return $this->hasOne(IqamaTimeSetting::class);
    }

    public function prayerCalculationSettings() {
        return $this->hasOne(PrayerCalculationSetting::class);
    }

    public function themeSettings() {
        return $this->hasOne(ThemeSetting::class);
    }

    public function logo() {
        return $this->hasOne(Media::class, 'model_id')
            ->where('collection_name', 'logos')
            ->orderBy('created_at', 'desc')
            ->latest();
    }

    public function header_logo() {
        return $this->hasOne(Media::class, 'model_id')
            ->where('collection_name', 'header_logos')
            ->orderBy('created_at', 'desc')
            ->latest();
    }

    public function footer_logo() {
        return $this->hasOne(Media::class, 'model_id')
            ->where('collection_name', 'footer_logos')
            ->orderBy('created_at', 'desc')
            ->latest();
    }

    public function socialMediaLinks() {
        return $this->hasMany(MasjidSocialMediaLink::class);
    }

    public function announcements() {
        return $this->hasMany(Announcement::class);
    }

    public function gallery() {
        return $this->hasMany(Media::class, 'model_id')
            ->where('collection_name', 'galleries');
    }

    public function services() {
        return $this->hasMany(Service::class);
    }

    public function features() {
        return $this->belongsToMany(MobileAppFeature::class, 'masjid_mobile_app_features', 'masjid_id', 'feature_id')
            ->withPivot('is_available');
    }

    public function notifications() {
        return $this->hasMany(Notification::class);
    }

    public function mobileAppUsers() {
        return $this->hasMany(MobileAppUser::class);
    }

    public function jumaaSettings() {
        return $this->hasOne(JumaaSetting::class);
    }

    /** Per-masjid app-publishing config (managed vs BYO per platform). */
    public function appPublishing() {
        return $this->hasOne(MasjidAppPublishing::class);
    }

    /**
     * Per-masjid SMS sender identity + its A2P 10DLC registration state (T-009).
     *
     * Null — or present but not `approved` — means this organisation CANNOT send
     * text messages, and the SMS channel refuses in words rather than falling
     * back to a shared number. See App\Models\MasjidSmsSender.
     */
    public function smsSender() {
        return $this->hasOne(MasjidSmsSender::class);
    }

    public function pages() {
        return $this->hasMany(Page::class);
    }

    public function sections() {
        return $this->hasMany(Section::class);
    }

    /** Sign-up forms (event RSVPs, membership, camp registration). */
    public function forms() {
        return $this->hasMany(Form::class);
    }

    /**
     * Every submission across all of this masjid's forms.
     * `masjid_id` is denormalised onto form_responses so admin queries can scope by
     * tenant without joining `forms`.
     */
    public function formResponses() {
        return $this->hasMany(FormResponse::class);
    }

    public function funds() {
        return $this->hasMany(Fund::class);
    }

    public function donations() {
        return $this->hasMany(Donation::class);
    }

    /** True once Stripe reports the connected account can accept charges. */
    public function canAcceptDonations(): bool
    {
        return $this->stripe_account_id !== null && $this->stripe_charges_enabled;
    }

}
