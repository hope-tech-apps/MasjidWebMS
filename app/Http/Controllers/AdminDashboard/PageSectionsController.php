<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Enums\SectionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PageSections\AttachSectionRequest;
use App\Http\Requests\Admin\PageSections\StorePageSectionRequest;
use App\Http\Requests\Admin\PageSections\UpdatePageSectionRequest;
use App\Models\Masjid;
use App\Models\Section;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PageSectionsController extends Controller
{
    /**
     * Display sections for a specific page
     */
    public function index($masjid_id, $page_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $page = $masjid->pages()->findOrFail($page_id);

            // Get sections through pivot table with order + platforms
            $sections = $page->sections()
                ->withPivot('order', 'platforms')
                ->orderBy('page_section.order')
                ->get()
                ->map(fn($section) => $this->serializeSection(
                    $section,
                    $page->id,
                    $section->pivot->order,
                    $section->pivot->platforms
                ));

            return response()->json([
                'status' => 'success',
                'data' => $sections
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Store a newly created section and attach it to the page
     */
    public function store(StorePageSectionRequest $request, $masjid_id, $page_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $page = $masjid->pages()->findOrFail($page_id);

            $validated = $request->validated();

            // Create new section in the sections library
            $sectionData = [
                'masjid_id' => $masjid->id,
                'section_type' => $validated['section_type'],
                'title' => $validated['title'] ?? null,
                'content' => $validated['content'],
                'is_active' => $validated['is_active'] ?? true,
                'settings' => $validated['settings'] ?? null,
            ];

            $section = Section::create($sectionData);

            // Handle image uploads
            $this->handleImageUploads($request, $section);

            // Reload section to get updated content with image URLs
            $section->refresh();

            // Attach section to page with order + platform visibility.
            $order = $validated['order'] ?? ($page->sections()->count() + 1);
            $platforms = $validated['platforms'] ?? null;
            $page->sections()->attach($section->id, [
                'order' => $order,
                'platforms' => $platforms,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $this->serializeSection($section, $page->id, $order, $platforms)
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified section
     */
    public function show($masjid_id, $page_id, $section_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $page = $masjid->pages()->findOrFail($page_id);
            $section = $page->sections()->withPivot('order', 'platforms')->findOrFail($section_id);

            return response()->json([
                'status' => 'success',
                'data' => $this->serializeSection($section, $page->id, $section->pivot->order, $section->pivot->platforms)
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update the specified section
     */
    public function update(UpdatePageSectionRequest $request, $masjid_id, $page_id, $section_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $page = $masjid->pages()->findOrFail($page_id);
            $section = $page->sections()->withPivot('order', 'platforms')->findOrFail($section_id);

            // Capture the current placement values before we mutate the pivot.
            $currentOrder = $section->pivot->order;
            $currentPlatforms = $section->pivot->platforms;

            $validated = $request->validated();

            // Build update set only with provided keys
            $sectionData = collect($validated)->only(['section_type', 'title', 'content', 'settings', 'is_active'])->toArray();

            if (!empty($sectionData)) {
                $section->update($sectionData);
            }

            // Update pivot order/platforms if provided (placement-level fields).
            $pivotUpdate = [];
            if (array_key_exists('order', $validated)) {
                $pivotUpdate['order'] = $validated['order'];
            }
            if (array_key_exists('platforms', $validated)) {
                $pivotUpdate['platforms'] = $validated['platforms'];
            }
            if (!empty($pivotUpdate)) {
                $page->sections()->updateExistingPivot($section->id, $pivotUpdate);
            }

            // Handle image uploads
            $this->handleImageUploads($request, $section);

            // Reload section to get updated content with image URLs
            $section->refresh();
            $section->load(['pages' => function ($query) use ($page_id) {
                $query->where('pages.id', $page_id);
            }]);

            $order = $validated['order'] ?? $currentOrder;
            $platforms = array_key_exists('platforms', $validated)
                ? $validated['platforms']
                : $currentPlatforms;

            return response()->json([
                'status' => 'success',
                'data' => $this->serializeSection($section, $page->id, $order, $platforms)
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified section from the page (detach)
     */
    public function destroy($masjid_id, $page_id, $section_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $page = $masjid->pages()->findOrFail($page_id);

            // Verify section exists on this page
            $page->sections()->findOrFail($section_id);

            // Detach section from page (doesn't delete the section itself)
            $page->sections()->detach($section_id);

            return response()->json([
                'status' => 'success',
                'message' => 'Section removed from page successfully'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Attach an existing section to a page
     */
    public function attach(AttachSectionRequest $request, $masjid_id, $page_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $page = $masjid->pages()->findOrFail($page_id);

            $sectionId = $request->input('section_id');

            // Verify section belongs to same masjid
            $masjid->sections()->findOrFail($sectionId);

            // Check if already attached
            if ($page->sections()->where('sections.id', $sectionId)->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Section is already attached to this page'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Attach section to page with order + platform visibility.
            $validated = $request->validated();
            $order = $validated['order'] ?? ($page->sections()->count() + 1);
            $platforms = $validated['platforms'] ?? null;
            $page->sections()->attach($sectionId, [
                'order' => $order,
                'platforms' => $platforms,
            ]);

            $section = $page->sections()->withPivot('order', 'platforms')->findOrFail($sectionId);

            return response()->json([
                'status' => 'success',
                'data' => $this->serializeSection($section, $page->id, $section->pivot->order, $section->pivot->platforms),
                'message' => 'Section attached to page successfully'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get available section types with their default content
     */
    public function sectionTypes()
    {
        try {
            $types = collect(SectionType::cases())->map(fn($type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
                'uses_external_data' => $type->usesExternalData(),
                'default_content' => $type->defaultContent(),
            ]);

            return response()->json([
                'status' => 'success',
                'data' => $types
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Build a uniform section payload for responses.
     *
     * @param  array<int,string>|string|null  $platforms  Raw pivot platforms (array, JSON string, or null).
     */
    private function serializeSection(Section $section, int $pageId, ?int $order, $platforms = null): array
    {
        return [
            'id' => $section->id,
            'page_id' => $pageId,
            'section_type' => $section->section_type->value,
            'section_type_label' => $section->type_label,
            'title' => $section->title,
            'content' => $section->content,
            'order' => $order,
            'platforms' => $this->normalizePlatforms($platforms),
            'is_active' => $section->is_active,
            'settings' => $section->settings,
            'uses_external_data' => $section->usesExternalData(),
            'created_at' => $section->created_at?->toISOString(),
            'updated_at' => $section->updated_at?->toISOString(),
        ];
    }

    /**
     * Normalize a raw pivot `platforms` value to an array. Null/empty => both
     * (web+mobile), matching Section::DEFAULT_PLATFORMS and the V1 serializer,
     * so the admin UI never has to special-case a null placement.
     *
     * @param  array<int,string>|string|null  $platforms
     * @return array<int,string>
     */
    private function normalizePlatforms($platforms): array
    {
        if (is_string($platforms)) {
            $decoded = json_decode($platforms, true);
            $platforms = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($platforms) || empty($platforms)) {
            return Section::DEFAULT_PLATFORMS;
        }

        return array_values($platforms);
    }

    /**
     * Handle image uploads for section content
     */
    private function handleImageUploads(Request $request, Section $section)
    {
        $content = $section->content;
        $imageFields = $this->getImageFieldsForSectionType($section->section_type);

        foreach ($imageFields as $fieldName) {
            if (strpos($fieldName, '*') !== false) {
                $this->handleArrayImageUploads($request, $section, $content, $fieldName);
            } else {
                $inputName = str_replace('.', '_', $fieldName);

                if ($request->hasFile($inputName)) {
                    $media = $section->addMediaFromRequest($inputName)
                        ->toMediaCollection('section_images');

                    $this->setNestedValue($content, $fieldName, $media->getUrl());
                }
            }
        }

        $section->update(['content' => $content]);
    }

    /**
     * Handle image uploads for array items
     */
    private function handleArrayImageUploads(Request $request, Section $section, array &$content, string $fieldPattern)
    {
        $allFiles = $request->allFiles();

        $pattern = str_replace(['.', '*'], ['_', '(\d+)'], $fieldPattern);

        foreach ($allFiles as $key => $file) {
            if (preg_match('/^' . $pattern . '$/', $key, $matches)) {
                $index = $matches[1];

                $media = $section->addMedia($file)
                    ->toMediaCollection('section_images');

                $actualFieldName = str_replace('*', $index, $fieldPattern);
                $this->setNestedValue($content, $actualFieldName, $media->getUrl());
            }
        }
    }

    /**
     * Get image field names for a specific section type
     */
    private function getImageFieldsForSectionType(SectionType $type): array
    {
        return match ($type) {
            SectionType::PAGE_TITLE => ['background_image_url'],
            SectionType::PRAYER_TIMES => ['image_url'],
            SectionType::ABOUT_US => ['image_url'],
            SectionType::IMAGE_TEXT_GRID => ['main_image_url', 'header_image_url', 'footer_image_url'],
            SectionType::GRID_CARDS => ['items.*.image_url'],
            SectionType::DONATION => ['image_url'],
            SectionType::CTA => ['background_image_url'],
            SectionType::MISSION_VISION => ['items.*.icon_url'],
            default => [],
        };
    }

    /**
     * Set a nested value in an array using dot notation
     */
    private function setNestedValue(array &$array, string $key, $value): void
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $i => $k) {
            if ($k === '*') {
                continue;
            }

            if ($i === count($keys) - 1) {
                $current[$k] = $value;
            } else {
                if (!isset($current[$k]) || !is_array($current[$k])) {
                    $current[$k] = [];
                }
                $current = &$current[$k];
            }
        }
    }
}
