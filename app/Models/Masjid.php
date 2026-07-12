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

    protected $fillable = [
        'user_id',
        'name',
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
        'created_by',
        'updated_by',
        'deleted_by'
    ];

    protected $searchableFields = ['name', 'email', 'address'];

    protected function casts(): array
    {
        return [
            'stripe_charges_enabled' => 'boolean',
            'stripe_payouts_enabled' => 'boolean',
            // Per-masjid CRM feature gate; default false = CRM off (SuperAdmin-only toggle).
            'crm_enabled' => 'boolean',
        ];
    }

    public function admin() {
        return $this->belongsTo(User::class, 'user_id', 'id');
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

    public function pages() {
        return $this->hasMany(Page::class);
    }

    public function sections() {
        return $this->hasMany(Section::class);
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
