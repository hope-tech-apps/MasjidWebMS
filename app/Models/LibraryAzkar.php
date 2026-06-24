<?php

namespace App\Models;

use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;

/**
 * Curated preset adhkar (global library). See the library_azkar migration.
 * Copied into the live Azkar table by AzkarController::addFromLibrary.
 */
class LibraryAzkar extends Model
{
    use SearchableTrait;

    protected $table = 'library_azkar';

    protected $fillable = [
        'slug',
        'category',
        'title',
        'text',
        'bless',
        'pronunciation',
        'frequency',
        'reference',
    ];

    protected $casts = [
        'title' => 'array',
        'text' => 'array',
        'bless' => 'array',
        'frequency' => 'integer',
    ];

    protected $searchableFields = [
        'title',
        'text',
        'bless',
        'pronunciation',
        'reference',
        'category',
    ];
}
