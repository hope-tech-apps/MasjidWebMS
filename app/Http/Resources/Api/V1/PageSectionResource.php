<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\SectionType;
use App\Support\SectionContentBinder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageSectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isPaginatedSection = in_array($this->section_type, [
            SectionType::SERVICES_LIST,
            SectionType::ANNOUNCEMENTS_LIST,
        ]);

        // Phase 1: for the four entity-bound content types, inject the dedicated
        // model's data into the stored content (single source of truth), while
        // preserving the exact JSON shape the Nuxt section components expect.
        $masjidId = $this->masjid_id ?? (int) $request->header('masjid-id') ?: null;
        $content = SectionContentBinder::bind($this->resource, $this->content, $masjidId);

        return [
            'id' => $this->id,
            'section_type' => $this->section_type->value,
            'section_type_label' => $this->section_type->label(),
            'title' => $this->title,
            'content' => $content,
            'items_per_page' => $isPaginatedSection
                ? (int) ($content['items_per_page'] ?? 9)
                : null,
            'order' => $this->order,
            // Per-placement platform visibility (null pivot => web+mobile). The
            // Nuxt site filters to web+both; it never sees a null here.
            'platforms' => $this->platforms,
            'is_active' => $this->is_active,
            'settings' => $this->settings,
            'uses_external_data' => $this->usesExternalData(),
//            'created_at' => $this->created_at?->toISOString(),
//            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
