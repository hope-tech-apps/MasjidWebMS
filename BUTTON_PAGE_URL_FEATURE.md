# Button Page URL Feature

## Overview

The Section model now automatically resolves `button_page_id` to `button_page_url` when sections are retrieved from the database. This provides the frontend with the actual page URL/slug instead of just the page ID.

## Changes Made

### 1. Section Model (`app/Models/Section.php`)

Added custom accessor and mutator for the `content` attribute:

- **`getContentAttribute()`**: Automatically adds `button_page_url` field to content when `button_page_id` is present
- **`setContentAttribute()`**: Handles JSON encoding when saving content
- Removed `'content' => 'array'` from `$casts` to allow custom accessor/mutator to work

### 2. TypeScript Types (`resources/vue-app/core/types/data/masjid-related/PageSection.ts`)

Added `button_page_url` field to `ImageTextGridSectionContent`:

```typescript
export type ImageTextGridSectionContent = {
    // ... other fields
    button_page_id: number | null;
    button_page_url?: string | null; // Auto-generated from button_page_id
    button_link: string | null;
    // ... other fields
};
```

### 3. Pages Seeder (`database/seeders/PagesSeeder.php`)

- Refactored to create pages first, then sections that reference them
- Updated `button_page_id` values to use actual created page IDs instead of hardcoded values
- Now supports seeding all masjids or a specific masjid

## How It Works

### Backend (Automatic Resolution)

When a section is retrieved from the database:

1. The `getContentAttribute()` accessor is called
2. It checks if `content` has a `button_page_id` field
3. If found, it queries the `pages` table to get the page slug
4. It adds `button_page_url` field with the value `/{slug}`
5. It also handles nested items arrays (for GRID_CARDS sections)

**Example:**

```php
// Database stores:
{
    "button_page_id": 5,
    "button_text": "View Gallery"
}

// Frontend receives:
{
    "button_page_id": 5,
    "button_page_url": "/gallery",
    "button_text": "View Gallery"
}
```

### Frontend Usage

The frontend can now use `button_page_url` for internal navigation:

```typescript
// Check if it's an internal link (button_page_url) or external (button_link)
const link = section.content.button_link || section.content.button_page_url;

if (section.content.button_page_url) {
    // Internal navigation using Vue Router
    router.push(section.content.button_page_url);
} else if (section.content.button_link) {
    // External link
    window.open(section.content.button_link, '_blank');
}
```

## Benefits

1. **No API Changes Required**: The transformation happens automatically in the model
2. **Backward Compatible**: `button_page_id` is still available if needed
3. **Frontend Friendly**: Frontend gets the actual URL path ready to use
4. **Efficient**: Uses `select('id', 'slug')` to minimize database queries
5. **Flexible**: Works with both single buttons and nested items arrays

## Testing

Test the feature using Tinker:

```bash
php artisan tinker
```

```php
// Get a section with button_page_id
$section = App\Models\Section::whereRaw('JSON_EXTRACT(content, "$.button_page_id") IS NOT NULL')->first();

// View the content
print_r($section->content);

// You should see both button_page_id and button_page_url
```

## Example Output

```json
{
    "text": "It is a long established fact...",
    "title": "Donate to Burlington Masjid",
    "subtitle": "",
    "button_link": null,
    "button_text": "Donate",
    "show_button": true,
    "button_page_id": 6,
    "button_page_url": "/donate",
    "main_image_url": null,
    "background_color": "#ffffff",
    "footer_image_url": null,
    "header_image_url": null,
    "content_direction": "ltr"
}
```

## Notes

- The `button_page_url` field is read-only and auto-generated
- It's not stored in the database, only computed when retrieved
- If the page is deleted, `button_page_url` will be `null`
- The feature works for both API responses and admin panel responses

