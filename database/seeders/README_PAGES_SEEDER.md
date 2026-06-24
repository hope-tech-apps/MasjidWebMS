# Pages Seeder Documentation

## Overview

The `PagesSeeder` creates a complete set of pages and sections for masjid websites. It can seed pages for all masjids or a specific masjid.

## Features

- Seeds 7 pages per masjid (Home, About, Services, Announcements, Gallery, Donate, Contact)
- Creates 17 reusable sections per masjid
- Supports seeding all masjids at once
- Supports seeding a specific masjid by ID
- Automatically attaches sections to pages with proper ordering

## Usage

### Method 1: Using the Custom Artisan Command (Recommended)

#### Seed all masjids:
```bash
php artisan seed:pages
```

#### Seed a specific masjid:
```bash
php artisan seed:pages --masjid_id=1
```

### Method 2: Using the Standard Seeder Command

#### Seed all masjids:
```bash
php artisan db:seed --class=PagesSeeder
```

#### Seed a specific masjid using environment variable:
```bash
MASJID_ID=1 php artisan db:seed --class=PagesSeeder
```

### Method 3: Calling from Another Seeder

```php
// Seed all masjids
$this->call(PagesSeeder::class);

// Seed specific masjid
$this->call(PagesSeeder::class, false, ['masjid_id' => 1]);
```

## What Gets Created

### Pages (7 total per masjid):

1. **Home** - Main landing page with prayer times, announcements, about us, services, donation, and contact form
2. **About Us** - About page with mission/vision and contact form
3. **Services** - Services listing page
4. **Announcements** - Announcements listing page
5. **Photo Gallery** - Gallery page with photo grid
6. **Donate** - Donation page (shown as button in menu)
7. **Contact Us** - Contact page with form and map

### Sections (17 total per masjid):

1. Prayer Times Section
2. Announcements List Section
3. About Us Section
4. Services List Section (Home)
5. Donation Section
6. Contact Form Section
7. All Services Section (Services Page)
8. All Announcements Section
9. Gallery Section
10. Contact Form with Map Section
11. Get in Touch Section
12. About Page Title Section
13. About Us with Logo Section
14. Mission and Vision Section
15. Services Page Title Section
16. Announcements Page Title Section
17. Gallery Page Title Section
18. Contact Page Title Section
19. Donate Page Title Section
20. Donate Image Text Section

## Notes

- The seeder checks if masjids exist before running
- Each masjid gets its own independent set of pages and sections
- Sections are reusable and can be attached to multiple pages
- All sections support the new `button_link` field for external links
- Progress is displayed in the console during seeding

## Requirements

- Masjids must exist in the database before running this seeder
- Run `MasjidDataSeeder` first if no masjids exist

## Example Output

```
Seeding pages and sections for all masjids (3 total)

Processing masjid 1/3: Burlington Masjid (ID: 1)
Creating reusable sections...
✓ Created all reusable sections
Creating pages...
✓ Home page created
✓ About page created
✓ Services page created
✓ Announcements page created
✓ Gallery page created
✓ Donate page created
✓ Contact page created
✅ Pages and sections seeded successfully for Burlington Masjid
Total pages created: 7
Total sections created: 17

Processing masjid 2/3: Green Dome Mosque (ID: 2)
...

✅ All masjids seeded successfully!
```

