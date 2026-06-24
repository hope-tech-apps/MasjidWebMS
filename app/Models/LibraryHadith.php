<?php

namespace App\Models;

use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;

/**
 * Curated preset hadith (global library). See the library_hadiths migration.
 * Copied into the live Hadith table by HadithsController::addFromLibrary.
 */
class LibraryHadith extends Model
{
    use SearchableTrait;

    protected $table = 'library_hadiths';

    protected $fillable = [
        'slug',
        'category',
        'source',
        'title',
        'isnad',
        'matn',
        'strength',
        'muhaddith',
        'references',
        'description',
    ];

    protected $casts = [
        'strength' => 'array',
        'muhaddith' => 'array',
        'references' => 'array',
    ];

    protected $searchableFields = [
        'title',
        'matn',
        'description',
        'source',
        'category',
    ];
}
