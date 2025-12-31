<?php

namespace App\Http\Resources\Api\V1;

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
        return [
            'id' => $this->id,
            'section_type' => $this->section_type->value,
            'section_type_label' => $this->section_type->label(),
            'title' => $this->title,
            'content' => $this->content,
            'order' => $this->order,
            'is_active' => $this->is_active,
            'settings' => $this->settings,
            'uses_external_data' => $this->usesExternalData(),
//            'created_at' => $this->created_at?->toISOString(),
//            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
