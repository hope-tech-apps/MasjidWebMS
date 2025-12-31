<?php

namespace Database\Seeders;

use App\Enums\SectionType;
use App\Models\Masjid;
use App\Models\Page;
use App\Models\Section;
use Illuminate\Database\Seeder;

class PagesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * This seeder creates pages and sections based on the actual database structure
     *
     * Usage:
     * - Seed all masjids: php artisan db:seed --class=PagesSeeder
     * - Seed specific masjid: MASJID_ID=1 php artisan db:seed --class=PagesSeeder
     *
     * Or call from another seeder:
     * - $this->call(PagesSeeder::class, false, ['masjid_id' => 1]);
     */
    public function run(?int $masjid_id = null): void
    {
        // Check if a specific masjid ID was provided via parameter or environment variable
        $masjidId = $masjid_id ?? env('MASJID_ID');

        if ($masjidId) {
            // Seed for specific masjid
            $masjid = Masjid::find($masjidId);

            if (!$masjid) {
                $this->command->error("Masjid with ID {$masjidId} not found.");
                return;
            }

            $this->command->info("Seeding pages and sections for masjid: {$masjid->name} (ID: {$masjid->id})");
            $this->seedMasjid($masjid);
        } else {
            // Seed for all masjids
            $masjids = Masjid::all();

            if ($masjids->isEmpty()) {
                $this->command->warn('No masjids found. Please run MasjidDataSeeder first.');
                return;
            }

            $this->command->info("Seeding pages and sections for all masjids ({$masjids->count()} total)");
            $this->command->newLine();

            foreach ($masjids as $index => $masjid) {
                $this->command->info("Processing masjid " . ($index + 1) . "/{$masjids->count()}: {$masjid->name} (ID: {$masjid->id})");
                $this->seedMasjid($masjid);
                $this->command->newLine();
            }

            $this->command->info("✅ All masjids seeded successfully!");
        }
    }

    /**
     * Seed pages and sections for a specific masjid
     */
    private function seedMasjid(Masjid $masjid): void
    {

        // Create all reusable sections first
        $this->command->info('Creating reusable sections...');

        // Section 1: Prayer Times (used in Home page)
        $prayerTimesSection = $masjid->sections()->create([
            'section_type' => SectionType::PRAYER_TIMES,
            'title' => 'Prayer Times',
            'content' => [
                'title' => 'And establish prayer and give zakah and obey the Messenger - that you may receive mercy.',
                'subtitle' => 'Surah An-Nur, Verse 56',
                'image_url' => null,
            ],
            'is_active' => true,
        ]);

        // Section 2: Announcements List (used in Home page)
        $announcementsListSection = $masjid->sections()->create([
            'section_type' => SectionType::ANNOUNCEMENTS_LIST,
            'title' => 'Announcements',
            'content' => [
                'title' => 'Latest Announcements',
                'subtitle' => 'Stay updated with our latest news and events',
                'button_text' => 'View All Announcements',
            ],
            'is_active' => true,
        ]);

        // Section 3: About Us (used in Home page)
        $aboutUsSection = $masjid->sections()->create([
            'section_type' => SectionType::ABOUT_US,
            'title' => 'About Us',
            'content' => [
                'title' => 'About Us',
                'subtitle' => '',
                'text' => 'Al-Fateh Mosque has been a cornerstone of our community for over three decades. We are dedicated to providing a welcoming space for worship, education, and community engagement. Our mission is to foster spiritual growth, promote Islamic values, and serve the needs of our diverse community.',
                'image_url' => null,
                'button_text' => 'Read More',
            ],
            'is_active' => true,
        ]);

        // Section 4: Services List (used in Home page)
        $servicesListSection = $masjid->sections()->create([
            'section_type' => SectionType::SERVICES_LIST,
            'title' => 'Services',
            'content' => [
                'layout' => 'grid',
                'columns' => 3,
                'heading' => 'Services',
                'description' => null,
                'items_per_page' => 6,
                'show_pagination' => false,
            ],
            'is_active' => true,
        ]);

        // Section 5: Donation (used in multiple pages)
        $donationSection = $masjid->sections()->create([
            'section_type' => SectionType::DONATION,
            'title' => 'Donate Now',
            'content' => [
                'title' => 'Donate Now',
                'subtitle' => 'Donate to our mosque now to help us and help other muslims in the region, donation to mosques is the best Sadaqa',
                'image_url' => null,
                'button_text' => 'Donate Now',
            ],
            'is_active' => true,
        ]);

        // Section 6: Contact Form (used in multiple pages)
        $contactFormSection = $masjid->sections()->create([
            'section_type' => SectionType::CONTACT_FORM,
            'title' => 'Contact Us',
            'content' => [
                'title' => 'Contact Us',
                'subtitle' => 'Want to learn more about our masjid and about our activities across the region? dont hesitate and contact for further information',
                'button_text' => 'Send Message',
                'show_map' => false,
            ],
            'is_active' => true,
        ]);

        // Section 8: All Services (used in Services page)
        $allServicesSection = $masjid->sections()->create([
            'section_type' => SectionType::SERVICES_LIST,
            'title' => 'All Services',
            'content' => [
                'layout' => 'grid',
                'columns' => 3,
                'heading' => 'Our Services',
                'description' => 'Explore all the services we offer',
                'items_per_page' => 9,
                'show_pagination' => true,
            ],
            'is_active' => true,
        ]);

        // Section 10: All Announcements (used in Announcements page)
        $allAnnouncementsSection = $masjid->sections()->create([
            'section_type' => SectionType::ANNOUNCEMENTS_LIST,
            'title' => 'All Announcements',
            'content' => [
                'title' => 'All Announcements',
                'subtitle' => 'Browse all our announcements and updates',
                'button_text' => '',
            ],
            'is_active' => true,
        ]);

        // Section 12: Gallery (used in Gallery page)
        $gallerySection = $masjid->sections()->create([
            'section_type' => SectionType::GALLERY,
            'title' => 'Gallery',
            'content' => [
                'layout' => 'masonry',
                'columns' => 4,
                'heading' => 'Photo Gallery',
                'description' => 'Explore our mosque through photos',
                'items_per_page' => 12,
                'enable_lightbox' => true,
            ],
            'is_active' => true,
        ]);

        // Section 15: Contact Form with Map (used in About page)
        $contactFormWithMapSection = $masjid->sections()->create([
            'section_type' => SectionType::CONTACT_FORM,
            'title' => 'Contact Us',
            'content' => [
                'heading' => 'Contact Us',
                'description' => 'Want to learn more about our masjid and our activities? Don\'t hesitate to contact us.',
                'form_fields' => ['name', 'email', 'phone', 'reason', 'message'],
                'submit_button_text' => 'Send Message',
                'show_map' => true,
            ],
            'is_active' => true,
        ]);

        // Section 17: Get in Touch (used in Contact page)
        $getInTouchSection = $masjid->sections()->create([
            'section_type' => SectionType::CONTACT_FORM,
            'title' => 'Get in Touch',
            'content' => [
                'title' => '',
                'subtitle' => 'Want to learn more about our masjid and about our activities across the region? dont hesitate and contact for further information',
                'button_text' => 'Send Message',
                'show_map' => true,
            ],
            'is_active' => true,
        ]);

        // Section 19: About Us Page Title
        $aboutPageTitleSection = $masjid->sections()->create([
            'section_type' => SectionType::PAGE_TITLE,
            'title' => 'About us Title',
            'content' => [
                'title' => 'About Us',
                'background_image_url' => null,
            ],
            'is_active' => true,
        ]);

        $this->command->info('✓ Created all reusable sections');

        // Now create pages and attach sections
        $this->command->info('Creating pages...');

        // Page 1: Home
        $homePage = $masjid->pages()->create([
            'slug' => 'home',
            'title' => 'Home',
            'is_active' => true,
            'order' => 1,
            'show_in_menu' => true,
            'meta_description' => 'Welcome to Al-Fateh Mosque',
        ]);

        $this->command->info('✓ Home page created');

        // Page 2: About Us
        $aboutPage = $masjid->pages()->create([
            'slug' => 'about',
            'title' => 'About Us',
            'is_active' => true,
            'order' => 2,
            'show_in_menu' => true,
            'meta_description' => 'Learn more about Al-Fateh Mosque',
        ]);

        $this->command->info('✓ About page created');

        // Page 3: Services
        $servicesPage = $masjid->pages()->create([
            'slug' => 'services',
            'title' => 'Services',
            'is_active' => true,
            'order' => 3,
            'show_in_menu' => true,
            'meta_description' => 'Explore our services',
        ]);

        $this->command->info('✓ Services page created');

        // Page 4: Announcements
        $announcementsPage = $masjid->pages()->create([
            'slug' => 'announcements',
            'title' => 'Announcements',
            'is_active' => true,
            'order' => 4,
            'show_in_menu' => true,
            'meta_description' => 'Latest announcements',
        ]);

        $this->command->info('✓ Announcements page created');

        // Page 5: Photo Gallery
        $galleryPage = $masjid->pages()->create([
            'slug' => 'gallery',
            'title' => 'Photo Gallery',
            'is_active' => true,
            'order' => 5,
            'show_in_menu' => true,
            'meta_description' => 'Explore our photo gallery',
        ]);

        $this->command->info('✓ Gallery page created');

        // Page 6: Donate
        $donatePage = $masjid->pages()->create([
            'slug' => 'donate',
            'title' => 'Donate',
            'is_active' => true,
            'order' => 6,
            'show_in_menu' => false,
            'show_as_button' => true,
            'meta_description' => 'Support our mosque',
        ]);

        $this->command->info('✓ Donate page created');

        // Page 7: Contact Us
        $contactPage = $masjid->pages()->create([
            'slug' => 'contact',
            'title' => 'Contact Us',
            'is_active' => true,
            'order' => 7,
            'show_in_menu' => true,
            'meta_description' => 'Get in touch with us',
        ]);

        $this->command->info('✓ Contact page created');

        // Now create sections that reference pages (after pages are created)

        // Section 20: About Us with Logo (Image Text Grid)
        $aboutUsWithLogoSection = $masjid->sections()->create([
            'section_type' => SectionType::IMAGE_TEXT_GRID,
            'title' => 'About us with logo',
            'content' => [
                'title' => '',
                'subtitle' => '',
                'text' => 'Contrary to popular belief, Lorem Ipsum is not simply random text. It has roots in a piece of classical Latin literature from 45 BC, making it over 2000 years old. Richard McClintock, a Latin professor at Hampden-Sydney College in Virginia, looked up one of the more obscure Latin words, consectetur, from a Lorem Ipsum passage, and going through the cites of the word in classical literature, discovered the undoubtable source. Lorem Ipsum comes from sections 1.10.32 and 1.10.33 of "de Finibus Bonorum et Malorum" (The Extremes of Good and Evil) by Cicero, written in 45 BC. This book is a treatise on the theory of ethics, very popular during the Renaissance. The first line of Lorem Ipsum, "Lorem ipsum dolor sit amet..", comes from a line in section.',
                'button_text' => 'Test Button',
                'show_button' => true,
                'button_page_id' => $galleryPage->id,
                'button_link' => null,
                'main_image_url' => null,
                'header_image_url' => null,
                'footer_image_url' => null,
                'background_color' => '#ffffff',
                'content_direction' => 'rtl',
            ],
            'is_active' => true,
        ]);

        // Section 21: Mission and Vision (Grid Cards)
        $missionVisionSection = $masjid->sections()->create([
            'section_type' => SectionType::GRID_CARDS,
            'title' => 'Mission and Vision',
            'content' => [
                'items_per_row' => 2,
                'items' => [
                    [
                        'title' => 'Our Mission',
                        'text' => 'There are many variations of passages of Lorem Ipsum available, but the majority have suffered alteration in some form, by injected humour, or randomised words which don\'t look even slightly believable.',
                        'image_url' => null,
                    ],
                    [
                        'title' => 'Our Vision',
                        'text' => 'There are many variations of passages of Lorem Ipsum available, but the majority have suffered alteration in some form, by injected humour, or randomised words which don\'t look even slightly believable.',
                        'image_url' => null,
                    ],
                ],
            ],
            'is_active' => true,
        ]);

        // Section 22: Services Page Title
        $servicesPageTitleSection = $masjid->sections()->create([
            'section_type' => SectionType::PAGE_TITLE,
            'title' => 'Services Page Title',
            'content' => [
                'title' => 'Our Services',
                'background_image_url' => null,
            ],
            'is_active' => true,
        ]);

        // Section 23: Announcements Page Title
        $announcementsPageTitleSection = $masjid->sections()->create([
            'section_type' => SectionType::PAGE_TITLE,
            'title' => 'Announcements Page Title',
            'content' => [
                'title' => 'Announcements',
                'background_image_url' => null,
            ],
            'is_active' => true,
        ]);

        // Section 24: Gallery Page Title
        $galleryPageTitleSection = $masjid->sections()->create([
            'section_type' => SectionType::PAGE_TITLE,
            'title' => 'Gallery page title',
            'content' => [
                'title' => 'Photo Gallery',
                'background_image_url' => null,
            ],
            'is_active' => true,
        ]);

        // Section 25: Contact Us Page Title
        $contactPageTitleSection = $masjid->sections()->create([
            'section_type' => SectionType::PAGE_TITLE,
            'title' => 'Contact us Page title',
            'content' => [
                'title' => 'Contact us',
                'background_image_url' => null,
            ],
            'is_active' => true,
        ]);

        // Section 26: Donate Page Title
        $donatePageTitleSection = $masjid->sections()->create([
            'section_type' => SectionType::PAGE_TITLE,
            'title' => 'Donate Page Title',
            'content' => [
                'title' => 'Donate Now',
                'background_image_url' => null,
            ],
            'is_active' => true,
        ]);

        // Section 27: Donate to Al-Fateh Mosque (Image Text Grid)
        $donateImageTextSection = $masjid->sections()->create([
            'section_type' => SectionType::IMAGE_TEXT_GRID,
            'title' => 'Donate to Al-Fateh Mosque',
            'content' => [
                'title' => 'Donate to Al-Fateh Mosque',
                'subtitle' => '',
                'text' => 'It is a long established fact that a reader will be by the readable content of a page when looking at its layout. The point of using Lorem Ipsum is that it has a more-or-less normal distribution of letters, as opposed to using \'Content here, content here\', making it look like readable English.',
                'button_text' => 'Donate',
                'show_button' => true,
                'button_page_id' => $donatePage->id,
                'button_link' => null,
                'main_image_url' => null,
                'header_image_url' => null,
                'footer_image_url' => null,
                'background_color' => '#ffffff',
                'content_direction' => 'ltr',
            ],
            'is_active' => true,
        ]);

        // Now attach sections to pages

        $homePage->sections()->attach([
            $prayerTimesSection->id => ['order' => 2],
            $announcementsListSection->id => ['order' => 3],
            $aboutUsSection->id => ['order' => 4],
            $servicesListSection->id => ['order' => 5],
            $donationSection->id => ['order' => 6],
            $contactFormSection->id => ['order' => 7],
        ]);

        $aboutPage->sections()->attach([
            $aboutPageTitleSection->id => ['order' => 1],
            $aboutUsWithLogoSection->id => ['order' => 2],
            $missionVisionSection->id => ['order' => 3],
            $donationSection->id => ['order' => 4],
            $contactFormWithMapSection->id => ['order' => 6],
        ]);

        $servicesPage->sections()->attach([
            $servicesPageTitleSection->id => ['order' => 1],
            $allServicesSection->id => ['order' => 2],
            $donationSection->id => ['order' => 3],
            $contactFormSection->id => ['order' => 4],
        ]);

        $announcementsPage->sections()->attach([
            $announcementsPageTitleSection->id => ['order' => 1],
            $allAnnouncementsSection->id => ['order' => 2],
            $donationSection->id => ['order' => 3],
            $contactFormSection->id => ['order' => 4],
        ]);

        $galleryPage->sections()->attach([
            $galleryPageTitleSection->id => ['order' => 1],
            $gallerySection->id => ['order' => 2],
            $donationSection->id => ['order' => 3],
            $contactFormSection->id => ['order' => 4],
        ]);

        $donatePage->sections()->attach([
            $donatePageTitleSection->id => ['order' => 1],
            $donateImageTextSection->id => ['order' => 2],
            $contactFormSection->id => ['order' => 3],
        ]);

        $contactPage->sections()->attach([
            $contactPageTitleSection->id => ['order' => 1],
            $getInTouchSection->id => ['order' => 2],
            $donationSection->id => ['order' => 3],
        ]);

        $this->command->info('✓ Contact page created');

        $this->command->info('✅ Pages and sections seeded successfully for ' . $masjid->name);
        $this->command->info("Total pages created: 7");
        $this->command->info("Total sections created: 17");
    }
}

