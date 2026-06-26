<?php

namespace App\Models;

use App\Enums\SectionType;
use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Section extends Model implements HasMedia
{
    use SearchableTrait, InteractsWithMedia;

    protected $fillable = [
        'masjid_id',
        'section_type',
        'title',
        'content',
        'is_active',
        'settings',
    ];

    protected $searchableFields = ['title'];

    protected $casts = [
        'section_type' => SectionType::class,
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    protected $appends = ['order', 'platforms'];

    /**
     * Default platform visibility when a placement leaves `platforms` null.
     * Null on the pivot means "both" — exposed to clients as web + mobile.
     */
    public const DEFAULT_PLATFORMS = ['web', 'mobile'];

    /**
     * Get the masjid that owns the section
     */
    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }

    /**
     * Get all pages that use this section
     */
    public function pages()
    {
        return $this->belongsToMany(Page::class, 'page_section')
            ->using(PageSection::class)
            ->withPivot('order', 'platforms')
            ->withTimestamps()
            ->orderBy('page_section.order');
    }

    /**
     * Scope to get only active sections
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by section type
     */
    public function scopeOfType($query, SectionType $type)
    {
        return $query->where('section_type', $type);
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
     * Get the section type label
     */
    public function getTypeLabelAttribute(): string
    {
        return $this->section_type->label();
    }

    /**
     * Get the section type description
     */
    public function getTypeDescriptionAttribute(): string
    {
        return $this->section_type->description();
    }

    /**
     * Check if this section uses external API data
     */
    public function usesExternalData(): bool
    {
        return $this->section_type->usesExternalData();
    }

    /**
     * Get the order attribute from pivot table
     */
    public function getOrderAttribute()
    {
        return $this->pivot?->order ?? null;
    }

    /**
     * Get the platform-visibility array from the pivot (placement).
     *
     * The pivot stores a JSON array or null. Null means "both" — we normalize
     * it to the default web+mobile set so consumers never have to special-case
     * null. When the section is loaded outside a page context (no pivot), we
     * return the default as well.
     */
    public function getPlatformsAttribute(): array
    {
        $raw = $this->pivot?->platforms ?? null;

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($raw) || empty($raw)) {
            return self::DEFAULT_PLATFORMS;
        }

        return array_values($raw);
    }

    /**
     * Get content with resolved button page URL
     * This accessor transforms button_page_id to button_page_url
     */
    public function getContentAttribute($value)
    {
        // Decode JSON to array
        $content = is_string($value) ? json_decode($value, true) : $value;
        $content = $content ?? [];

        // If content has button_page_id, resolve it to page slug
        if (isset($content['button_page_id']) && $content['button_page_id']) {
            $page = Page::select('id', 'slug')->find($content['button_page_id']);
            $content['button_page_url'] = $page ? "/{$page->slug}" : null;
        }

        // Handle nested items array (for GRID_CARDS, etc.)
        if (isset($content['items']) && is_array($content['items'])) {
            foreach ($content['items'] as $index => $item) {
                if (isset($item['button_page_id']) && $item['button_page_id']) {
                    $page = Page::select('id', 'slug')->find($item['button_page_id']);
                    $content['items'][$index]['button_page_url'] = $page ? "/{$page->slug}" : null;
                }
            }
        }

        return $content;
    }

    /**
     * Set content attribute
     */
    public function setContentAttribute($value)
    {
        $this->attributes['content'] = is_array($value) ? json_encode($value) : $value;
    }

    /**
     * Register media collections
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('section_images')
            ->useDisk('public');
    }
}
