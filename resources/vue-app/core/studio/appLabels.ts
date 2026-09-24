/**
 * The words the native apps print, copied for Studio's device mockups.
 *
 * This is the ONLY place in the SPA that copies a native string. The apps keep
 * their labels client-side on purpose (a label has to be localisable, and
 * server-driven chrome once emptied the drawer), so the server's preview sends
 * keys (StudioPreview: `app.ios.tabs`, `app.ios.sections`, `app.android.tabs`)
 * and the mockups look the words up here. Every constant names the file and
 * lines it was copied from, so a wording change in an app has one place to be
 * followed to.
 *
 * Read at: iOS `origin/main` 8e5191f (~/Developer/NewMasjidSystem-r0), Android
 * `feat/r1-owner-answers` aeac265 (~/Developer/burlington-masjid-Android), the
 * branch that carries the R1 shell the server's Android tab list names
 * (StudioPreview::ANDROID_TABS cites android: ui/shell/BottomTabs.kt).
 *
 * The keys are AppMenu's (app/Support/AppMenu.php DEFAULT_REGISTRY):
 * StudioSpaSourceTest holds IOS_MENU_TITLES to its `items` and IOS_TAB_TITLES
 * to its `tabs`, so a registry key with no label here fails a test instead of
 * rendering blank.
 */

/** ios: Masjid/Models/Menu/AppMenu.swift:356-371 (`MenuItemKey.menuTitle`). */
export const IOS_MENU_TITLES: Record<string, string> = {
    home: "Home",
    announcements: "Announcements",
    services: "Services",
    donate: "Donate",
    quran: "Qur’an",
    hadith: "Hadith",
    adhkar: "Adhkar",
    qibla: "Qibla",
    tasbih: "Tasbih",
    about_us: "About us",
    gallery: "Gallery",
    contact: "Contact us",
};

/** ios: Masjid/Views/Shell/ShellTabBar.swift:59-67 (`AppTab.tabTitle`). */
export const IOS_TAB_TITLES: Record<string, string> = {
    home: "Home",
    announcements: "News",
    contact: "Contact",
    donate: "Donate",
};

/**
 * ios: Masjid/Models/Menu/MenuPresentation.swift:110-123 (`sectionTitle`).
 * `main` has no title; `about` carries the organisation's name.
 */
export function iosSectionTitle(sectionKey: string, organisationName: string): string | null {
    switch (sectionKey) {
        case 'worship':
            return "Worship";
        case 'about':
            return `About ${organisationName}`;
        default:
            return null;
    }
}

/** android: app/src/main/java/com/app/masajid/ui/shell/BottomTabs.kt:64-67 (`Tab` labels). */
export const ANDROID_TAB_TITLES: Record<string, string> = {
    home: "Home",
    announcements: "News",
    contact: "Contact",
    donate: "Donate",
};
