<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageResource extends JsonResource
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
            'slug' => $this->slug,
            'title' => $this->title,
            'page_title' => $this->page_title,
            'page_title_background_image_url' => $this->whenLoaded('pageTitleBackgroundImage',
                fn() => $this->pageTitleBackgroundImage?->getUrl(),
                null
            ),
            'is_active' => $this->is_active,
            'order' => $this->order,
            'show_in_menu' => $this->show_in_menu,
            'show_as_button' => $this->show_as_button,
            'meta_description' => $this->meta_description,
            'sections' => PageSectionResource::collection(
                $this->whenLoaded('activeSections',
                    $this->activeSections,
                    $this->whenLoaded('sections', $this->sections)
                )
            ),
//            'sections_count' => $this->when(isset($this->sections_count), $this->sections_count),
//            'created_at' => $this->created_at?->toISOString(),
//            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
