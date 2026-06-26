<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Enums\SectionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Sections\StoreSectionRequest;
use App\Http\Requests\Admin\Sections\UpdateSectionRequest;
use App\Models\Masjid;
use App\Models\Section;
use App\Support\MobileCache;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SectionsController extends Controller
{
    /**
     * Get all sections for a masjid (sections library)
     */
    public function index($masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $sections = $masjid->sections()
                ->with('pages')
                ->orderBy('created_at', 'desc')
                ->get();

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
     * Get a single section
     */
    public function show($masjid_id, $section_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $section = $masjid->sections()->with('pages')->findOrFail($section_id);

            return response()->json([
                'status' => 'success',
                'data' => $section
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Create a new section in the library
     */
    public function store(StoreSectionRequest $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            $data = $request->validated();
            $data['masjid_id'] = $masjid->id;
            $data['is_active'] = $data['is_active'] ?? true;

            $section = Section::create($data);

            // Handle image uploads
            $this->handleImageUploads($request, $section);

            // Reload section to get updated content with image URLs
            $section->refresh();

            MobileCache::flushPages((int) $masjid_id);

            return response()->json([
                'status' => 'success',
                'data' => $section
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update a section in the library
     */
    public function update(UpdateSectionRequest $request, $masjid_id, $section_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $section = $masjid->sections()->findOrFail($section_id);

            $data = $request->validated();
            // Preserve existing is_active if the request doesn't change it explicitly
            if (!array_key_exists('is_active', $data)) {
                $data['is_active'] = $section->is_active;
            }

            $section->update($data);

            // Handle image uploads
            $this->handleImageUploads($request, $section);

            $section->refresh();

            MobileCache::flushPages((int) $masjid_id);

            return response()->json([
                'status' => 'success',
                'data' => $section
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    /**
     * Delete a section from the library
     */
    public function destroy($masjid_id, $section_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $section = $masjid->sections()->findOrFail($section_id);

            // Delete the section (will also remove pivot relationships due to cascade)
            $section->delete();

            MobileCache::flushPages((int) $masjid_id);

            return response()->json([
                'status' => 'success',
                'message' => 'Section deleted successfully'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => \App\Support\Errors::publicMessage($e)
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
                $inputName = str_replace('.', '_', $fieldName);

                if ($request->hasFile($inputName)) {
                    $media = $section->addMediaFromRequest($inputName)
                        ->toMediaCollection('section_images');

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
        $allFiles = $request->allFiles();

        // Convert pattern like "items.*.image_url" to regex
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
