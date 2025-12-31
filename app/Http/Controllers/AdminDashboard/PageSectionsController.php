<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Enums\SectionType;
use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Models\Page;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
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

            // Get sections through pivot table with order
            $sections = $page->sections()
                ->withPivot('order')
                ->orderBy('page_section.order')
                ->get()
                ->map(function ($section) {
                    return [
                        'id' => $section->id,
                        'page_id' => $section->pivot->page_id,
                        'section_type' => $section->section_type->value,
                        'section_type_label' => $section->type_label,
                        'title' => $section->title,
                        'content' => $section->content,
                        'order' => $section->pivot->order,
                        'is_active' => $section->is_active,
                        'settings' => $section->settings,
                        'uses_external_data' => $section->usesExternalData(),
                        'created_at' => $section->created_at?->toISOString(),
                        'updated_at' => $section->updated_at?->toISOString(),
                    ];
                });

            return response()->json([
                'status' => 'success',
                'data' => $sections
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Store a newly created section and attach it to the page
     */
    public function store(Request $request, $masjid_id, $page_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $page = $masjid->pages()->findOrFail($page_id);

            // Parse JSON fields if they come as strings (from FormData)
            $requestData = $request->all();
            if (is_string($request->input('content'))) {
                $requestData['content'] = json_decode($request->input('content'), true);
            }
            if (is_string($request->input('settings'))) {
                $requestData['settings'] = json_decode($request->input('settings'), true);
            }

            $validator = Validator::make($requestData, [
                'section_type' => ['required', new Enum(SectionType::class)],
                'title' => 'nullable|string|max:255',
                'content' => 'required|array',
                'order' => 'nullable|integer',
                'settings' => 'nullable|array',
                'image_url' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:10240', // 10MB max
                'background_image_url' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:10240',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            // Create new section in the sections library
            $sectionData = [
                'masjid_id' => $masjid->id,
                'section_type' => $requestData['section_type'],
                'title' => $requestData['title'] ?? null,
                'content' => $requestData['content'],
                'is_active' => $request->has('is_active') ? (bool) $request->input('is_active') : true,
                'settings' => $requestData['settings'] ?? null,
            ];

            $section = Section::create($sectionData);

            // Handle image uploads
            $this->handleImageUploads($request, $section);

            // Reload section to get updated content with image URLs
            $section->refresh();

            // Attach section to page with order
            $order = $request->input('order', $page->sections()->count() + 1);
            $page->sections()->attach($section->id, ['order' => $order]);

            // Return section in the same format as index
            $sectionData = [
                'id' => $section->id,
                'page_id' => $page->id,
                'section_type' => $section->section_type->value,
                'section_type_label' => $section->type_label,
                'title' => $section->title,
                'content' => $section->content,
                'order' => $order,
                'is_active' => $section->is_active,
                'settings' => $section->settings,
                'uses_external_data' => $section->usesExternalData(),
                'created_at' => $section->created_at?->toISOString(),
                'updated_at' => $section->updated_at?->toISOString(),
            ];

            return response()->json([
                'status' => 'success',
                'data' => $sectionData
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
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
            $section = $page->sections()->withPivot('order')->findOrFail($section_id);

            $sectionData = [
                'id' => $section->id,
                'page_id' => $section->pivot->page_id,
                'section_type' => $section->section_type->value,
                'section_type_label' => $section->type_label,
                'title' => $section->title,
                'content' => $section->content,
                'order' => $section->pivot->order,
                'is_active' => $section->is_active,
                'settings' => $section->settings,
                'uses_external_data' => $section->usesExternalData(),
                'created_at' => $section->created_at?->toISOString(),
                'updated_at' => $section->updated_at?->toISOString(),
            ];

            return response()->json([
                'status' => 'success',
                'data' => $sectionData
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update the specified section
     */
    public function update(Request $request, $masjid_id, $page_id, $section_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $page = $masjid->pages()->findOrFail($page_id);
            $section = $page->sections()->withPivot('order')->findOrFail($section_id);

            // Parse JSON fields if they come as strings (from FormData)
            $requestData = $request->all();
            if (is_string($request->input('content'))) {
                $requestData['content'] = json_decode($request->input('content'), true);
            }
            if (is_string($request->input('settings'))) {
                $requestData['settings'] = json_decode($request->input('settings'), true);
            }

            $validator = Validator::make($requestData, [
                'section_type' => ['sometimes', new Enum(SectionType::class)],
                'title' => 'nullable|string|max:255',
                'content' => 'sometimes|array',
                'order' => 'nullable|integer',
                'settings' => 'nullable|array',
                'image_url' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:10240', // 10MB max
                'background_image_url' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:10240',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            // Update section data
            $sectionData = [];
            if (isset($requestData['section_type'])) $sectionData['section_type'] = $requestData['section_type'];
            if (isset($requestData['title'])) $sectionData['title'] = $requestData['title'];
            if (isset($requestData['content'])) $sectionData['content'] = $requestData['content'];
            if (isset($requestData['settings'])) $sectionData['settings'] = $requestData['settings'];
            if ($request->has('is_active')) $sectionData['is_active'] = (bool) $request->input('is_active');

            if (!empty($sectionData)) {
                $section->update($sectionData);
            }

            // Update pivot order if provided
            if ($request->has('order')) {
                $page->sections()->updateExistingPivot($section->id, ['order' => $request->input('order')]);
            }

            // Handle image uploads
            $this->handleImageUploads($request, $section);

            // Reload section to get updated content with image URLs
            $section->refresh();
            $section->load(['pages' => function ($query) use ($page_id) {
                $query->where('pages.id', $page_id);
            }]);

            $sectionData = [
                'id' => $section->id,
                'page_id' => $page->id,
                'section_type' => $section->section_type->value,
                'section_type_label' => $section->type_label,
                'title' => $section->title,
                'content' => $section->content,
                'order' => $request->input('order', $section->pivot->order),
                'is_active' => $section->is_active,
                'settings' => $section->settings,
                'uses_external_data' => $section->usesExternalData(),
                'created_at' => $section->created_at?->toISOString(),
                'updated_at' => $section->updated_at?->toISOString(),
            ];

            return response()->json([
                'status' => 'success',
                'data' => $sectionData
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
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
            $section = $page->sections()->findOrFail($section_id);

            // Detach section from page (doesn't delete the section itself)
            $page->sections()->detach($section_id);

            return response()->json([
                'status' => 'success',
                'message' => 'Section removed from page successfully'
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Attach an existing section to a page
     */
    public function attach(Request $request, $masjid_id, $page_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $page = $masjid->pages()->findOrFail($page_id);

            $validator = Validator::make($request->all(), [
                'section_id' => 'required|integer|exists:sections,id',
                'order' => 'nullable|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'failed',
                    'data' => $validator->errors()
                ], Response::HTTP_BAD_REQUEST);
            }

            $sectionId = $request->input('section_id');

            // Verify section belongs to same masjid
            $section = $masjid->sections()->findOrFail($sectionId);

            // Check if already attached
            if ($page->sections()->where('sections.id', $sectionId)->exists()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Section is already attached to this page'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Attach section to page with order
            $order = $request->input('order', $page->sections()->count() + 1);
            $page->sections()->attach($sectionId, ['order' => $order]);

            // Return section data
            $section = $page->sections()->withPivot('order')->findOrFail($sectionId);

            $sectionData = [
                'id' => $section->id,
                'page_id' => $page->id,
                'section_type' => $section->section_type->value,
                'section_type_label' => $section->type_label,
                'title' => $section->title,
                'content' => $section->content,
                'order' => $section->pivot->order,
                'is_active' => $section->is_active,
                'settings' => $section->settings,
                'uses_external_data' => $section->usesExternalData(),
                'created_at' => $section->created_at?->toISOString(),
                'updated_at' => $section->updated_at?->toISOString(),
            ];

            return response()->json([
                'status' => 'success',
                'data' => $sectionData,
                'message' => 'Section attached to page successfully'
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get available section types with their default content
     */
    public function sectionTypes()
    {
        try {
            $types = collect(SectionType::cases())->map(function ($type) {
                return [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'description' => $type->description(),
                    'uses_external_data' => $type->usesExternalData(),
                    'default_content' => $type->defaultContent(),
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => $types
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Handle image uploads for section content
     */
    private function handleImageUploads(Request $request, Section $section)
    {
        $content = $section->content;
        $imageFields = $this->getImageFieldsForSectionType($section->section_type);

        foreach ($imageFields as $fieldName) {
            // Check if this is an array field (contains .*.  pattern)
            if (strpos($fieldName, '*') !== false) {
                // Handle array items (e.g., items.*.image_url)
                $this->handleArrayImageUploads($request, $section, $content, $fieldName);
            } else {
                // Handle single image field
                $inputName = str_replace('.', '_', $fieldName); // Convert dot notation to underscore

                if ($request->hasFile($inputName)) {
                    // Upload the image using Spatie Media Library
                    $media = $section->addMediaFromRequest($inputName)
                        ->toMediaCollection('section_images');

                    // Update the content with the image URL
                    $this->setNestedValue($content, $fieldName, $media->getUrl());
                }
            }
        }

        // Save updated content
        $section->update(['content' => $content]);
    }

    /**
     * Handle image uploads for array items
     */
    private function handleArrayImageUploads(Request $request, Section $section, array &$content, string $fieldPattern)
    {
        // Get all files from the request
        $allFiles = $request->allFiles();

        // Convert pattern like "items.*.image_url" to regex pattern
        // This will match items_0_image_url, items_1_image_url, etc.
        $pattern = str_replace(['.', '*'], ['_', '(\d+)'], $fieldPattern);

        foreach ($allFiles as $key => $file) {
            if (preg_match('/^' . $pattern . '$/', $key, $matches)) {
                $index = $matches[1]; // Get the index number

                // Upload the image
                $media = $section->addMedia($file)
                    ->toMediaCollection('section_images');

                // Update the content with the image URL
                // Convert items.*.image_url + index to items.0.image_url
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
        return match($type) {
            SectionType::PAGE_TITLE => ['background_image_url'],
            SectionType::PRAYER_TIMES => ['image_url'],
            SectionType::ABOUT_US => ['image_url'],
            SectionType::IMAGE_TEXT_GRID => ['main_image_url', 'header_image_url', 'footer_image_url'],
            SectionType::GRID_CARDS => ['items.*.image_url'], // Array of items
            SectionType::DONATION => ['image_url'],
            SectionType::CTA => ['background_image_url'],
            SectionType::MISSION_VISION => ['items.*.icon_url'], // Array of items
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
                // Handle array items
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
