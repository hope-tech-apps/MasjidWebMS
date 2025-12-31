<?php

namespace App\Models;

use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Page extends Model implements HasMedia
{
    use SoftDeletes, SearchableTrait, InteractsWithMedia;

    protected $fillable = [
        'masjid_id',
        'slug',
        'title',
        'page_title',
        'is_active',
        'order',
        'show_in_menu',
        'show_as_button',
        'meta_description',
    ];

    protected $searchableFields = ['slug', 'title', 'meta_description'];

    protected $casts = [
        'is_active' => 'boolean',
        'show_in_menu' => 'boolean',
        'show_as_button' => 'boolean',
        'order' => 'integer',
    ];

    /**
     * Get the masjid that owns the page
     */
    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }

    /**
     * Get all sections for this page (many-to-many through pivot)
     */
    public function sections()
    {
        return $this->belongsToMany(Section::class, 'page_section')
            ->withPivot('order')
            ->withTimestamps()
            ->orderBy('page_section.order');
    }

    /**
     * Get only active sections for this page
     */
    public function activeSections()
    {
        return $this->belongsToMany(Section::class, 'page_section')
            ->withPivot('order')
            ->withTimestamps()
            ->where('sections.is_active', true)
            ->orderBy('page_section.order');
    }

    /**
     * Legacy: Get old page_sections (will be deprecated)
     */
    public function oldSections()
    {
        return $this->hasMany(PageSection::class)->orderBy('order');
    }

    /**
     * Scope to filter by masjid
     */
    public function scopeFilterByMasjid($query)
    {
        $masjidId = request()->header('masjid-id');
        if ($masjidId) {
            return $query->where('masjid_id', $masjidId);
        }
        return $query;
    }

    /**
     * Scope to get only active pages
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get pages that should show in menu
     */
    public function scopeShowInMenu($query)
    {
        return $query->where('show_in_menu', true);
    }

    /**
     * Get the page title background image
     */
    public function pageTitleBackgroundImage()
    {
        return $this->morphOne(\Spatie\MediaLibrary\MediaCollections\Models\Media::class, 'model')
            ->where('collection_name', 'page_title_backgrounds')
            ->orderBy('created_at', 'desc')
            ->latest();
    }

    /**
     * Register media collections
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('page_title_backgrounds')
            ->singleFile()
            ->useDisk('public');
    }
}
