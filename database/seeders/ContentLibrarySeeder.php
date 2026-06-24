<?php

namespace Database\Seeders;

use App\Models\LibraryAzkar;
use App\Models\LibraryHadith;
use App\Models\LibraryTasbeeh;
use Illuminate\Database\Seeder;

/**
 * Seeds the curated GLOBAL content library (library_hadiths / library_tasbeehs /
 * library_azkar) with authentic, well-known content an admin can copy into a
 * masjid's live collection.
 *
 * IDEMPOTENT: every row is written with updateOrCreate keyed on a stable natural
 * `slug`, so re-running this seeder updates existing presets in place and never
 * duplicates. Safe to run repeatedly on prod via:
 *
 *   php artisan db:seed --class=Database\\Seeders\\ContentLibrarySeeder --force
 *
 * It is intentionally NOT wired into DatabaseSeeder::run(), because that seeder
 * truncates content tables (destructive) — this one only ever upserts.
 */
class ContentLibrarySeeder extends Seeder
{
    public function run(): void
    {
        $this->seedHadiths();
        $this->seedTasbeehs();
        $this->seedAzkar();
    }

    /**
     * ~15 well-known SOUND hadith: the opening hadith of an-Nawawi's Forty plus
     * selections from Riyad as-Salihin. strength/muhaddith mirror hadiths' {en,ar}.
     */
    private function seedHadiths(): void
    {
        $sahih = ['en' => 'Sahih', 'ar' => 'صحيح'];
        $bukhariMuslim = fn (string $b, string $m) => [
            ['title' => 'Sahih al-Bukhari', 'reference' => $b],
            ['title' => 'Sahih Muslim', 'reference' => $m],
        ];

        $hadiths = [
            [
                'slug' => 'nawawi-01-intentions',
                'category' => '40 Hadith Nawawi',
                'source' => 'Narrated by Umar ibn al-Khattab (RA)',
                'title' => 'Actions are by intentions',
                'isnad' => 'On the authority of Umar ibn al-Khattab (may Allah be pleased with him).',
                'matn' => 'إِنَّمَا الأَعْمَالُ بِالنِّيَّاتِ، وَإِنَّمَا لِكُلِّ امْرِئٍ مَا نَوَى',
                'description' => 'Actions are but by intention, and every man shall have only that which he intended.',
                'muhaddith' => ['en' => 'al-Bukhari & Muslim', 'ar' => 'البخاري ومسلم'],
                'references' => $bukhariMuslim('1', '1907'),
            ],
            [
                'slug' => 'nawawi-02-islam-iman-ihsan',
                'category' => '40 Hadith Nawawi',
                'source' => 'Narrated by Umar ibn al-Khattab (RA) — Hadith of Jibril',
                'title' => 'Islam, Iman and Ihsan',
                'isnad' => 'On the authority of Umar (may Allah be pleased with him).',
                'matn' => 'أَنْ تَعْبُدَ اللَّهَ كَأَنَّكَ تَرَاهُ، فَإِنْ لَمْ تَكُنْ تَرَاهُ فَإِنَّهُ يَرَاكَ',
                'description' => 'Ihsan is to worship Allah as though you see Him, and if you do not see Him, then He surely sees you.',
                'muhaddith' => ['en' => 'Muslim', 'ar' => 'مسلم'],
                'references' => [['title' => 'Sahih Muslim', 'reference' => '8']],
            ],
            [
                'slug' => 'nawawi-03-pillars-of-islam',
                'category' => '40 Hadith Nawawi',
                'source' => 'Narrated by Abdullah ibn Umar (RA)',
                'title' => 'Islam is built upon five',
                'isnad' => 'On the authority of Abdullah ibn Umar (may Allah be pleased with them both).',
                'matn' => 'بُنِيَ الإِسْلَامُ عَلَى خَمْسٍ: شَهَادَةِ أَنْ لَا إِلَهَ إِلَّا اللَّهُ وَأَنَّ مُحَمَّدًا رَسُولُ اللَّهِ، وَإِقَامِ الصَّلَاةِ، وَإِيتَاءِ الزَّكَاةِ، وَحَجِّ الْبَيْتِ، وَصَوْمِ رَمَضَانَ',
                'description' => 'Islam is built upon five: the testimony that none has the right to be worshipped but Allah and that Muhammad is the Messenger of Allah, establishing prayer, giving zakat, the pilgrimage to the House, and fasting Ramadan.',
                'muhaddith' => ['en' => 'al-Bukhari & Muslim', 'ar' => 'البخاري ومسلم'],
                'references' => $bukhariMuslim('8', '16'),
            ],
            [
                'slug' => 'nawawi-06-halal-clear-haram-clear',
                'category' => '40 Hadith Nawawi',
                'source' => 'Narrated by an-Nu`man ibn Bashir (RA)',
                'title' => 'The lawful is clear and the unlawful is clear',
                'isnad' => 'On the authority of an-Nu`man ibn Bashir (may Allah be pleased with him).',
                'matn' => 'إِنَّ الْحَلَالَ بَيِّنٌ وَإِنَّ الْحَرَامَ بَيِّنٌ، وَبَيْنَهُمَا أُمُورٌ مُشْتَبِهَاتٌ',
                'description' => 'Truly the lawful is clear and the unlawful is clear, and between them are matters that are doubtful.',
                'muhaddith' => ['en' => 'al-Bukhari & Muslim', 'ar' => 'البخاري ومسلم'],
                'references' => $bukhariMuslim('52', '1599'),
            ],
            [
                'slug' => 'nawawi-13-love-for-brother',
                'category' => '40 Hadith Nawawi',
                'source' => 'Narrated by Anas ibn Malik (RA)',
                'title' => 'Love for your brother what you love for yourself',
                'isnad' => 'On the authority of Anas (may Allah be pleased with him).',
                'matn' => 'لَا يُؤْمِنُ أَحَدُكُمْ حَتَّى يُحِبَّ لِأَخِيهِ مَا يُحِبُّ لِنَفْسِهِ',
                'description' => 'None of you truly believes until he loves for his brother what he loves for himself.',
                'muhaddith' => ['en' => 'al-Bukhari & Muslim', 'ar' => 'البخاري ومسلم'],
                'references' => $bukhariMuslim('13', '45'),
            ],
            [
                'slug' => 'nawawi-15-speak-good-or-silent',
                'category' => '40 Hadith Nawawi',
                'source' => 'Narrated by Abu Hurayrah (RA)',
                'title' => 'Speak good or remain silent',
                'isnad' => 'On the authority of Abu Hurayrah (may Allah be pleased with him).',
                'matn' => 'مَنْ كَانَ يُؤْمِنُ بِاللَّهِ وَالْيَوْمِ الآخِرِ فَلْيَقُلْ خَيْرًا أَوْ لِيَصْمُتْ',
                'description' => 'Whoever believes in Allah and the Last Day, let him speak good or remain silent.',
                'muhaddith' => ['en' => 'al-Bukhari & Muslim', 'ar' => 'البخاري ومسلم'],
                'references' => $bukhariMuslim('6018', '47'),
            ],
            [
                'slug' => 'nawawi-16-do-not-become-angry',
                'category' => '40 Hadith Nawawi',
                'source' => 'Narrated by Abu Hurayrah (RA)',
                'title' => 'Do not become angry',
                'isnad' => 'A man said to the Prophet (peace be upon him): "Advise me." He said:',
                'matn' => 'لَا تَغْضَبْ',
                'description' => 'Do not become angry. The man repeated his request several times, and each time he said: Do not become angry.',
                'muhaddith' => ['en' => 'al-Bukhari', 'ar' => 'البخاري'],
                'references' => [['title' => 'Sahih al-Bukhari', 'reference' => '6116']],
            ],
            [
                'slug' => 'nawawi-34-changing-evil',
                'category' => '40 Hadith Nawawi',
                'source' => 'Narrated by Abu Sa`id al-Khudri (RA)',
                'title' => 'Whoever sees an evil, let him change it',
                'isnad' => 'On the authority of Abu Sa`id al-Khudri (may Allah be pleased with him).',
                'matn' => 'مَنْ رَأَى مِنْكُمْ مُنْكَرًا فَلْيُغَيِّرْهُ بِيَدِهِ، فَإِنْ لَمْ يَسْتَطِعْ فَبِلِسَانِهِ، فَإِنْ لَمْ يَسْتَطِعْ فَبِقَلْبِهِ، وَذَلِكَ أَضْعَفُ الإِيمَانِ',
                'description' => 'Whoever of you sees an evil, let him change it with his hand; if he cannot, then with his tongue; and if he cannot, then with his heart — and that is the weakest of faith.',
                'muhaddith' => ['en' => 'Muslim', 'ar' => 'مسلم'],
                'references' => [['title' => 'Sahih Muslim', 'reference' => '49']],
            ],
            [
                'slug' => 'riyad-cleanliness-half-of-faith',
                'category' => 'Riyad as-Salihin',
                'source' => 'Narrated by Abu Malik al-Ash`ari (RA)',
                'title' => 'Purity is half of faith',
                'isnad' => 'On the authority of Abu Malik al-Ash`ari (may Allah be pleased with him).',
                'matn' => 'الطُّهُورُ شَطْرُ الإِيمَانِ، وَالْحَمْدُ لِلَّهِ تَمْلَأُ الْمِيزَانَ',
                'description' => 'Purity is half of faith, and "al-hamdu lillah" fills the scale.',
                'muhaddith' => ['en' => 'Muslim', 'ar' => 'مسلم'],
                'references' => [['title' => 'Sahih Muslim', 'reference' => '223']],
            ],
            [
                'slug' => 'riyad-smile-is-charity',
                'category' => 'Riyad as-Salihin',
                'source' => 'Narrated by Abu Dharr (RA)',
                'title' => 'Your smile is charity',
                'isnad' => 'On the authority of Abu Dharr (may Allah be pleased with him).',
                'matn' => 'تَبَسُّمُكَ فِي وَجْهِ أَخِيكَ لَكَ صَدَقَةٌ',
                'description' => 'Your smiling in the face of your brother is charity for you.',
                'muhaddith' => ['en' => 'at-Tirmidhi', 'ar' => 'الترمذي'],
                'references' => [['title' => 'Jami` at-Tirmidhi', 'reference' => '1956']],
            ],
            [
                'slug' => 'riyad-strong-believer',
                'category' => 'Riyad as-Salihin',
                'source' => 'Narrated by Abu Hurayrah (RA)',
                'title' => 'The strong believer',
                'isnad' => 'On the authority of Abu Hurayrah (may Allah be pleased with him).',
                'matn' => 'الْمُؤْمِنُ الْقَوِيُّ خَيْرٌ وَأَحَبُّ إِلَى اللَّهِ مِنَ الْمُؤْمِنِ الضَّعِيفِ، وَفِي كُلٍّ خَيْرٌ',
                'description' => 'The strong believer is better and more beloved to Allah than the weak believer, though in both there is good.',
                'muhaddith' => ['en' => 'Muslim', 'ar' => 'مسلم'],
                'references' => [['title' => 'Sahih Muslim', 'reference' => '2664']],
            ],
            [
                'slug' => 'riyad-mercy-to-people',
                'category' => 'Riyad as-Salihin',
                'source' => 'Narrated by Jarir ibn Abdullah (RA)',
                'title' => 'Allah is not merciful to one who is not merciful',
                'isnad' => 'On the authority of Jarir ibn Abdullah (may Allah be pleased with him).',
                'matn' => 'مَنْ لَا يَرْحَمُ النَّاسَ لَا يَرْحَمُهُ اللَّهُ',
                'description' => 'Whoever does not show mercy to people, Allah will not show mercy to him.',
                'muhaddith' => ['en' => 'al-Bukhari & Muslim', 'ar' => 'البخاري ومسلم'],
                'references' => $bukhariMuslim('7376', '2319'),
            ],
            [
                'slug' => 'riyad-best-of-you-quran',
                'category' => 'Riyad as-Salihin',
                'source' => 'Narrated by Uthman ibn Affan (RA)',
                'title' => 'The best of you are those who learn the Quran',
                'isnad' => 'On the authority of Uthman (may Allah be pleased with him).',
                'matn' => 'خَيْرُكُمْ مَنْ تَعَلَّمَ الْقُرْآنَ وَعَلَّمَهُ',
                'description' => 'The best of you are those who learn the Quran and teach it.',
                'muhaddith' => ['en' => 'al-Bukhari', 'ar' => 'البخاري'],
                'references' => [['title' => 'Sahih al-Bukhari', 'reference' => '5027']],
            ],
            [
                'slug' => 'riyad-deeds-most-beloved-consistent',
                'category' => 'Riyad as-Salihin',
                'source' => 'Narrated by Aishah (RA)',
                'title' => 'The most beloved deeds are the most consistent',
                'isnad' => 'On the authority of Aishah (may Allah be pleased with her).',
                'matn' => 'أَحَبُّ الأَعْمَالِ إِلَى اللَّهِ أَدْوَمُهَا وَإِنْ قَلَّ',
                'description' => 'The most beloved of deeds to Allah are those done consistently, even if they are few.',
                'muhaddith' => ['en' => 'al-Bukhari & Muslim', 'ar' => 'البخاري ومسلم'],
                'references' => $bukhariMuslim('6464', '783'),
            ],
            [
                'slug' => 'riyad-good-word-charity',
                'category' => 'Riyad as-Salihin',
                'source' => 'Narrated by Abu Hurayrah (RA)',
                'title' => 'A good word is charity',
                'isnad' => 'On the authority of Abu Hurayrah (may Allah be pleased with him).',
                'matn' => 'وَالْكَلِمَةُ الطَّيِّبَةُ صَدَقَةٌ',
                'description' => 'And a good word is charity.',
                'muhaddith' => ['en' => 'al-Bukhari & Muslim', 'ar' => 'البخاري ومسلم'],
                'references' => $bukhariMuslim('2989', '1009'),
            ],
        ];

        foreach ($hadiths as $h) {
            LibraryHadith::updateOrCreate(
                ['slug' => $h['slug']],
                [
                    'category' => $h['category'],
                    'source' => $h['source'],
                    'title' => $h['title'],
                    'isnad' => $h['isnad'],
                    'matn' => $h['matn'],
                    'strength' => $sahih,
                    'muhaddith' => $h['muhaddith'],
                    'references' => $h['references'],
                    'description' => $h['description'],
                ]
            );
        }
    }

    /**
     * The standard tasbeeh counters. text mirrors tasabih.text {en,ar};
     * pronunciation is the transliteration; default_count is the conventional count.
     */
    private function seedTasbeehs(): void
    {
        $tasbeehs = [
            [
                'slug' => 'tasbeeh-subhanallah',
                'ar' => 'سُبْحَانَ اللَّهِ',
                'en' => 'Glory be to Allah',
                'pronunciation' => 'SubhanAllah',
                'reference' => 'Sahih Muslim 596',
                'default_count' => 33,
            ],
            [
                'slug' => 'tasbeeh-alhamdulillah',
                'ar' => 'الْحَمْدُ لِلَّهِ',
                'en' => 'All praise is for Allah',
                'pronunciation' => 'Alhamdulillah',
                'reference' => 'Sahih Muslim 596',
                'default_count' => 33,
            ],
            [
                'slug' => 'tasbeeh-allahu-akbar',
                'ar' => 'اللَّهُ أَكْبَرُ',
                'en' => 'Allah is the Greatest',
                'pronunciation' => 'Allahu Akbar',
                'reference' => 'Sahih Muslim 596',
                'default_count' => 34,
            ],
            [
                'slug' => 'tasbeeh-la-ilaha-illa-allah',
                'ar' => 'لَا إِلَهَ إِلَّا اللَّهُ',
                'en' => 'There is no deity worthy of worship except Allah',
                'pronunciation' => 'La ilaha illa Allah',
                'reference' => 'Jami` at-Tirmidhi 3383',
                'default_count' => 100,
            ],
            [
                'slug' => 'tasbeeh-astaghfirullah',
                'ar' => 'أَسْتَغْفِرُ اللَّهَ',
                'en' => 'I seek forgiveness from Allah',
                'pronunciation' => 'Astaghfirullah',
                'reference' => 'Sahih Muslim 2702',
                'default_count' => 100,
            ],
            [
                'slug' => 'tasbeeh-subhanallah-wa-bihamdihi',
                'ar' => 'سُبْحَانَ اللَّهِ وَبِحَمْدِهِ، سُبْحَانَ اللَّهِ الْعَظِيمِ',
                'en' => 'Glory be to Allah and praise Him; glory be to Allah the Magnificent',
                'pronunciation' => "SubhanAllahi wa bihamdihi, SubhanAllahil-'Azeem",
                'reference' => 'Sahih al-Bukhari 6406',
                'default_count' => 100,
            ],
            [
                'slug' => 'tasbeeh-la-hawla-wa-la-quwwata',
                'ar' => 'لَا حَوْلَ وَلَا قُوَّةَ إِلَّا بِاللَّهِ',
                'en' => 'There is no might nor power except with Allah',
                'pronunciation' => 'La hawla wa la quwwata illa billah',
                'reference' => 'Sahih al-Bukhari 6384',
                'default_count' => 33,
            ],
        ];

        foreach ($tasbeehs as $t) {
            LibraryTasbeeh::updateOrCreate(
                ['slug' => $t['slug']],
                [
                    'text' => ['ar' => $t['ar'], 'en' => $t['en']],
                    'pronunciation' => $t['pronunciation'],
                    'reference' => $t['reference'],
                    'default_count' => $t['default_count'],
                ]
            );
        }
    }

    /**
     * Core morning/evening adhkar. title/text/bless mirror azkar's {en,ar} JSON;
     * frequency is the repeat count; category tags morning/evening.
     */
    private function seedAzkar(): void
    {
        $azkar = [
            [
                'slug' => 'azkar-ayat-al-kursi',
                'category' => 'morning',
                'title' => ['ar' => 'آيَةُ الْكُرْسِيِّ', 'en' => 'Ayat al-Kursi'],
                'text' => [
                    'ar' => 'اللَّهُ لَا إِلَهَ إِلَّا هُوَ الْحَيُّ الْقَيُّومُ ۚ لَا تَأْخُذُهُ سِنَةٌ وَلَا نَوْمٌ ۚ لَهُ مَا فِي السَّمَاوَاتِ وَمَا فِي الْأَرْضِ',
                    'en' => 'Allah — there is no deity except Him, the Ever-Living, the Sustainer of existence. Neither drowsiness overtakes Him nor sleep. To Him belongs whatever is in the heavens and the earth...',
                ],
                'bless' => ['ar' => 'مَنْ قَالَهَا حِينَ يُصْبِحُ أُجِيرَ مِنَ الْجِنِّ حَتَّى يُمْسِيَ', 'en' => 'Whoever recites it in the morning is protected from the jinn until evening.'],
                'pronunciation' => 'Allahu la ilaha illa Huwa, al-Hayyul-Qayyum...',
                'frequency' => 1,
                'reference' => 'al-Hakim — Sahih al-Jami` 2/697',
            ],
            [
                'slug' => 'azkar-ikhlas-falaq-nas-morning',
                'category' => 'morning',
                'title' => ['ar' => 'الإِخْلَاص وَالْمُعَوِّذَتَانِ', 'en' => 'Al-Ikhlas, Al-Falaq & An-Nas'],
                'text' => [
                    'ar' => 'قُلْ هُوَ اللَّهُ أَحَدٌ ... قُلْ أَعُوذُ بِرَبِّ الْفَلَقِ ... قُلْ أَعُوذُ بِرَبِّ النَّاسِ',
                    'en' => 'Recite Surah al-Ikhlas, al-Falaq and an-Nas three times each.',
                ],
                'bless' => ['ar' => 'كَفَتْهُ مِنْ كُلِّ شَيْءٍ', 'en' => 'They will suffice you against everything.'],
                'pronunciation' => 'Qul Huwa Allahu Ahad / Qul a`udhu bi-Rabbil-falaq / Qul a`udhu bi-Rabbin-nas',
                'frequency' => 3,
                'reference' => 'Abu Dawud 5082, at-Tirmidhi 3575',
            ],
            [
                'slug' => 'azkar-sayyidul-istighfar',
                'category' => 'morning',
                'title' => ['ar' => 'سَيِّدُ الِاسْتِغْفَارِ', 'en' => 'Sayyidul-Istighfar (the chief of seeking forgiveness)'],
                'text' => [
                    'ar' => 'اللَّهُمَّ أَنْتَ رَبِّي لَا إِلَهَ إِلَّا أَنْتَ، خَلَقْتَنِي وَأَنَا عَبْدُكَ، وَأَنَا عَلَى عَهْدِكَ وَوَعْدِكَ مَا اسْتَطَعْتُ، أَعُوذُ بِكَ مِنْ شَرِّ مَا صَنَعْتُ، أَبُوءُ لَكَ بِنِعْمَتِكَ عَلَيَّ، وَأَبُوءُ بِذَنْبِي فَاغْفِرْ لِي فَإِنَّهُ لَا يَغْفِرُ الذُّنُوبَ إِلَّا أَنْتَ',
                    'en' => 'O Allah, You are my Lord, there is no deity but You. You created me and I am Your servant... I acknowledge Your favour upon me and I acknowledge my sin, so forgive me, for none forgives sins but You.',
                ],
                'bless' => ['ar' => 'مَنْ قَالَهَا مُوقِنًا بِهَا حِينَ يُمْسِي فَمَاتَ دَخَلَ الْجَنَّةَ', 'en' => 'Whoever says it with certainty and dies that day enters Paradise.'],
                'pronunciation' => 'Allahumma Anta Rabbi la ilaha illa Anta...',
                'frequency' => 1,
                'reference' => 'Sahih al-Bukhari 6306',
            ],
            [
                'slug' => 'azkar-asbahna-ala-fitrah',
                'category' => 'morning',
                'title' => ['ar' => 'أَصْبَحْنَا عَلَى فِطْرَةِ الإِسْلَامِ', 'en' => 'We have entered the morning upon the fitrah of Islam'],
                'text' => [
                    'ar' => 'أَصْبَحْنَا عَلَى فِطْرَةِ الإِسْلَامِ، وَعَلَى كَلِمَةِ الإِخْلَاصِ، وَعَلَى دِينِ نَبِيِّنَا مُحَمَّدٍ ﷺ، وَعَلَى مِلَّةِ أَبِينَا إِبْرَاهِيمَ حَنِيفًا مُسْلِمًا وَمَا كَانَ مِنَ الْمُشْرِكِينَ',
                    'en' => 'We have entered the morning upon the natural religion of Islam, the word of sincere devotion, the religion of our Prophet Muhammad, and the way of our father Ibrahim, who was upright and was not of the polytheists.',
                ],
                'bless' => null,
                'pronunciation' => 'Asbahna `ala fitratil-Islam...',
                'frequency' => 1,
                'reference' => 'Ahmad 3/406',
            ],
            [
                'slug' => 'azkar-radhitu-billahi-rabban',
                'category' => 'morning',
                'title' => ['ar' => 'رَضِيتُ بِاللَّهِ رَبًّا', 'en' => 'I am pleased with Allah as my Lord'],
                'text' => [
                    'ar' => 'رَضِيتُ بِاللَّهِ رَبًّا، وَبِالإِسْلَامِ دِينًا، وَبِمُحَمَّدٍ ﷺ نَبِيًّا',
                    'en' => 'I am pleased with Allah as my Lord, with Islam as my religion, and with Muhammad (peace be upon him) as my Prophet.',
                ],
                'bless' => ['ar' => 'كَانَ حَقًّا عَلَى اللَّهِ أَنْ يُرْضِيَهُ يَوْمَ الْقِيَامَةِ', 'en' => 'Allah has promised to please whoever says it three times.'],
                'pronunciation' => 'Raditu billahi Rabban, wa bil-Islami dinan, wa bi-Muhammadin nabiyyan',
                'frequency' => 3,
                'reference' => 'Ahmad 4/337, at-Tirmidhi 3389',
            ],
            [
                'slug' => 'azkar-amsayna-ala-fitrah',
                'category' => 'evening',
                'title' => ['ar' => 'أَمْسَيْنَا عَلَى فِطْرَةِ الإِسْلَامِ', 'en' => 'We have entered the evening upon the fitrah of Islam'],
                'text' => [
                    'ar' => 'أَمْسَيْنَا عَلَى فِطْرَةِ الإِسْلَامِ، وَعَلَى كَلِمَةِ الإِخْلَاصِ، وَعَلَى دِينِ نَبِيِّنَا مُحَمَّدٍ ﷺ، وَعَلَى مِلَّةِ أَبِينَا إِبْرَاهِيمَ حَنِيفًا مُسْلِمًا وَمَا كَانَ مِنَ الْمُشْرِكِينَ',
                    'en' => 'We have entered the evening upon the natural religion of Islam... the way of our father Ibrahim, upright and Muslim, and he was not of the polytheists.',
                ],
                'bless' => null,
                'pronunciation' => 'Amsayna `ala fitratil-Islam...',
                'frequency' => 1,
                'reference' => 'Ahmad 3/406',
            ],
            [
                'slug' => 'azkar-amsayna-wa-amsal-mulku-lillah',
                'category' => 'evening',
                'title' => ['ar' => 'أَمْسَيْنَا وَأَمْسَى الْمُلْكُ لِلَّهِ', 'en' => 'We have reached the evening and so has the dominion of Allah'],
                'text' => [
                    'ar' => 'أَمْسَيْنَا وَأَمْسَى الْمُلْكُ لِلَّهِ، وَالْحَمْدُ لِلَّهِ، لَا إِلَهَ إِلَّا اللَّهُ وَحْدَهُ لَا شَرِيكَ لَهُ',
                    'en' => 'We have reached the evening and at this very time the whole dominion belongs to Allah. All praise is for Allah. None has the right to be worshipped but Allah alone, without partner.',
                ],
                'bless' => null,
                'pronunciation' => "Amsayna wa amsal-mulku lillah, wal-hamdu lillah...",
                'frequency' => 1,
                'reference' => 'Sahih Muslim 2723',
            ],
            [
                'slug' => 'azkar-bismillahilladhi-evening',
                'category' => 'evening',
                'title' => ['ar' => 'بِسْمِ اللَّهِ الَّذِي لَا يَضُرُّ مَعَ اسْمِهِ شَيْءٌ', 'en' => 'In the name of Allah with whose name nothing is harmed'],
                'text' => [
                    'ar' => 'بِسْمِ اللَّهِ الَّذِي لَا يَضُرُّ مَعَ اسْمِهِ شَيْءٌ فِي الأَرْضِ وَلَا فِي السَّمَاءِ وَهُوَ السَّمِيعُ الْعَلِيمُ',
                    'en' => 'In the name of Allah with whose name nothing on earth or in the heaven can cause harm, and He is the All-Hearing, the All-Knowing.',
                ],
                'bless' => ['ar' => 'لَمْ يَضُرَّهُ شَيْءٌ', 'en' => 'Whoever says it three times will not be harmed by anything.'],
                'pronunciation' => 'Bismillahil-ladhi la yadurru ma`asmihi shay...',
                'frequency' => 3,
                'reference' => 'Abu Dawud 5088, at-Tirmidhi 3388',
            ],
        ];

        foreach ($azkar as $a) {
            LibraryAzkar::updateOrCreate(
                ['slug' => $a['slug']],
                [
                    'category' => $a['category'],
                    'title' => $a['title'],
                    'text' => $a['text'],
                    'bless' => $a['bless'],
                    'pronunciation' => $a['pronunciation'],
                    'frequency' => $a['frequency'],
                    'reference' => $a['reference'],
                ]
            );
        }
    }
}
