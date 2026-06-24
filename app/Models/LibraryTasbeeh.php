<?php

namespace App\Models;

use App\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;

/**
 * Curated preset tasbeeh/dhikr (global library). See the library_tasbeehs migration.
 * Copied into the live Tasbih table by TasabihController::addFromLibrary.
 */
class LibraryTasbeeh extends Model
{
    use SearchableTrait;

    protected $table = 'library_tasbeehs';

    protected $fillable = [
        'slug',
        'text',
        'pronunciation',
        'reference',
        'default_count',
    ];

    protected $casts = [
        'text' => 'array',
        'default_count' => 'integer',
    ];

    protected $searchableFields = [
        'text',
        'pronunciation',
        'reference',
    ];
}
