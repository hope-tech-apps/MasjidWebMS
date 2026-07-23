<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ThemeSetting extends Model
{
    protected $fillable = [
        'masjid_id',
        'primary_color',
        'secondary_color',
        'accent_color',
        'background_color',
        'tokens',
    ];

    protected $casts = [
        'tokens' => 'array',
    ];

    public function masjid()
    {
        return $this->belongsTo(Masjid::class);
    }

    /**
     * The full resolved design-token tree (semantic colors + typography + shape
     * + spacing), derived from the base colors and overlaid with any stored
     * `tokens` overrides. See App\Support\DesignTokens.
     */
    public function resolvedTokens(): array
    {
        return \App\Support\DesignTokens::resolve($this);
    }
}
