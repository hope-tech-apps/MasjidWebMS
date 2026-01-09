<?php

namespace App\Http\Controllers\AdminDashboard;

use App\Enums\SectionType;
use App\Http\Controllers\Controller;
use App\Models\Masjid;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

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
                'message' => $e->getMessage()
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
                'message' => $e->getMessage()
            ], Response::HTTP_NOT_FOUND);
        }
    }

    /**
     * Create a new section in the library
     */
    public function store(Request $request, $masjid_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);

            // Parse JSON fields if they come as strings (from FormData)
            $requestData = $request->all();
            if (is_string($request->input('content'))) {
                $requestData['content'] = json_decode($request->input('content'), true);
            }
            if (is_string($request->input('settings'))) {
                $requestData['settings'] = json_decode($request->input('settings'), true);
            }

            // Build validation rules with dynamic image field validation
            $validationRules = [
                'section_type' => 'required|string',
                'title' => 'nullable|string|max:255',
                'content' => 'required|array',
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
            ];

            // Add validation for all possible image fields (2MB max)
            $this->addImageFieldValidation($validationRules, $request);

            $validator = Validator::make($requestData, $validationRules);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'data' => $validator->errors()
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Validate that content doesn't contain base64 images
            $base64ValidationError = $this->validateNoBase64Images($requestData['content']);
            if ($base64ValidationError) {
                return response()->json([
                    'status' => 'error',
                    'message' => $base64ValidationError
                ], Response::HTTP_BAD_REQUEST);
            }

            // Prepare data
            $data = $requestData;
            $data['masjid_id'] = $masjid->id;
            $data['is_active'] = $request->has('is_active') ? (bool) $request->input('is_active') : true;

            $section = Section::create($data);

            // Handle image uploads
            $this->handleImageUploads($request, $section);

            // Reload section to get updated content with image URLs
            $section->refresh();

            return response()->json([
                'status' => 'success',
                'data' => $section
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Update a section in the library
     */
    public function update(Request $request, $masjid_id, $section_id)
    {
        try {
            $masjid = Masjid::findOrFail($masjid_id);
            $section = $masjid->sections()->findOrFail($section_id);

            // Parse JSON fields if they come as strings (from FormData)
            $requestData = $request->all();
            if (is_string($request->input('content'))) {
                $requestData['content'] = json_decode($request->input('content'), true);
            }
            if (is_string($request->input('settings'))) {
                $requestData['settings'] = json_decode($request->input('settings'), true);
            }

            // Build validation rules with dynamic image field validation
            $validationRules = [
                'section_type' => 'sometimes|required|string',
                'title' => 'nullable|string|max:255',
                'content' => 'sometimes|required|array',
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
            ];

            // Add validation for all possible image fields (2MB max)
            $this->addImageFieldValidation($validationRules, $request);

            $validator = Validator::make($requestData, $validationRules);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'data' => $validator->errors()
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Validate that content doesn't contain base64 images (if content is being updated)
            if (isset($requestData['content'])) {
                $base64ValidationError = $this->validateNoBase64Images($requestData['content']);
                if ($base64ValidationError) {
                    return response()->json([
                        'status' => 'error',
                        'message' => $base64ValidationError
                    ], Response::HTTP_BAD_REQUEST);
                }
            }

            // Prepare data
            $data = $requestData;
            $data['is_active'] = $request->has('is_active') ? (bool) $request->input('is_active') : $section->is_active;

            $section->update($data);

            // Handle image uploads
            $this->handleImageUploads($request, $section);

            // Reload section to get updated content with image URLs
            $section->refresh();

            return response()->json([
                'status' => 'success',
                'data' => $section
            ], Response::HTTP_OK);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
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

            return response()->json([
                'status' => 'success',
                'message' => 'Section deleted successfully'
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
     * Add validation rules for image fields
     */
    private function addImageFieldValidation(array &$validationRules, Request $request): void
    {
        // Get all file inputs from the request
        $allFiles = $request->allFiles();

        foreach ($allFiles as $fieldName => $file) {
            // Add validation for each image field (2MB max)
            $validationRules[$fieldName] = 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp|max:2048';
        }
    }

    /**
     * Validate that content doesn't contain base64 encoded images
     * Returns error message if base64 images found, null otherwise
     */
    private function validateNoBase64Images(array $content): ?string
    {
        // Recursively check all values in the content array
        foreach ($content as $key => $value) {
            if (is_array($value)) {
                // Recursively check nested arrays
                $error = $this->validateNoBase64Images($value);
                if ($error) {
                    return $error;
                }
            } elseif (is_string($value)) {
                // Check if the value looks like a base64 encoded image
                // Base64 images typically start with "data:image/"
                if (preg_match('/^data:image\/[a-zA-Z]+;base64,/', $value)) {
                    return 'Images must be uploaded as files, not base64 encoded strings. Please use the file upload feature.';
                }
            }
        }

        return null;
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
