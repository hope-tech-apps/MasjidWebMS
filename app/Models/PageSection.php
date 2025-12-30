<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot model for page-section relationship
 * This is now a simple pivot table that connects pages to sections
 */
class PageSection extends Pivot
{
    protected $table = 'page_section';

    protected $fillable = [
        'page_id',
        'section_id',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    /**
     * Get the page
     */
    public function page()
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Get the section
     */
    public function section()
    {
        return $this->belongsTo(Section::class);
    }
}
