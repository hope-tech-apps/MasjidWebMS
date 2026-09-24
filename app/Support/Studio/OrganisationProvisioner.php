<?php

namespace App\Support\Studio;

use App\Http\Requests\Admin\Onboarding\ProvisionMasjidRequest;
use App\Models\DonationLink;
use App\Models\IqamaTimeSetting;
use App\Models\Masjid;
use App\Models\MasjidAppPublishing;
use App\Models\MasjidDomain;
use App\Models\MasjidMobileAppFeature;
use App\Models\MasjidSocialMediaLink;
use App\Models\MasjidUser;
use App\Models\MobileAppFeature;
use App\Models\User;
use App\Support\AppFeaturePivot;
use App\Support\CapabilityWriter;
use App\Support\FormTemplates;
use App\Services\Cloudflare\CloudflareService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Creates an organisation and everything it is born with: the masjid row, its
 * theme, about, prayer, iqama, jumaa, donation and social rows, one feature
 * toggle per catalogue entry, its vertical's starter forms, its app-publishing
 * config and its owner.
 *
 * ONE BODY FOR EVERY WAY IN. The wizard's provision endpoint calls it, and
 * Studio's provision-from-draft (docs/manara-studio-w1.md, S8) is to call the
 * same body, so an organisation Studio makes is the one the wizard would have
 * made from the same answers, and each later step (capabilities, starter pages,
 * domains) is added in exactly one place, in the order it depends on (R10). This
 * is the body the endpoint used to run inline, moved without a behavioural
 * change; ProvisionResponseSnapshotTest holds it to what the inline version
 * wrote.
 *
 * IT ONLY RUNS INSIDE THE CALLER'S TRANSACTION, and refuses otherwise. The body
 * writes a dozen tables, and a failure half-way must leave none of them behind.
 * The transaction is the caller's rather than this class's because a caller may
 * need more work in the same commit (the draft path locks and marks its draft),
 * and a body that opened its own would nest as a savepoint and look committed
 * when it was not.
 * Nothing that cannot be undone happens in here: invitations are collected into
 * `$invitations` for the caller to send AFTER its commit, because an email
 * cannot be recalled by a rollback, and cache flushes are the caller's too.
 *
 * STUDIO'S OPTIONAL KEYS (S8, R10) run in one fixed order, because each reads
 * what the one before wrote:
 *
 *   1. the masjids row (with `slug` and `description` when sent), then every
 *      row the wizard has always written;
 *   2. `capabilities`: CapabilityWriter stores the departures from the
 *      defaults, then the Mobile App Features pivot is derived from those
 *      switches, replacing the wizard's key-matched loop;
 *   3. the vertical's starter forms (as always);
 *   4. `layout_preset`: the starter website, which reads the switches (a
 *      module that is off writes no section) and the forms (the admissions
 *      section points at the seeded form), then the preset's header and
 *      footer into the theme's tokens;
 *   5. the web host rows, when web is selected and a slug was given.
 *
 * With none of those keys sent, every step is the wizard's own, byte for byte.
 * What the optional steps did is reported on `$ctx` for a caller that wants it.
 */
final class OrganisationProvisioner
{
    /**
     * @param  array<int, array{0: User, 1: string}>  $invitations  appended to: [account, organisation name] per invite to send after commit
     */
    public function create(ProvisionMasjidRequest $request, array &$invitations, ProvisionContext $ctx): Masjid
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('OrganisationProvisioner::create must run inside a database transaction; the caller owns the commit.');
        }

        // ---- Masjid record (mirrors MasjidsController@store, + timezone) ----
        $masjid = Masjid::create(self::studioIdentity($request) + [
            'name' => $request->input('name'),
            'org_type' => $request->input('org_type'),
            'email' => $request->input('email'),
            'phone' => $request->input('phone'),
            'address' => $request->input('address'),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
            'timezone' => $request->input('timezone'),
            'country_id' => $request->input('country_id'),
            'city_id' => $request->input('city_id'),
            'user_id' => $request->input('user_id') ?: null,
            // AN ORGANISATION IS BORN WITH ITS CRM ON.
            //
            // `crm_enabled` defaults false at the column and was written
            // by exactly two things in the tree: the SuperAdmin toggle
            // and the demo seeder. Provisioning never touched it, so
            // every organisation this wizard created was DARK — the
            // whole CRM route group 403s, the public registration door
            // 404s, and the admin nav simply hides the screens, so
            // nobody can tell why the org has no Families, Classrooms,
            // Programs or Giving. MEASURED 2026-08-26: three of five
            // production tenants (NAFIS, MEC, Al-Razi) were sitting in
            // that state, all of them created this way.
            //
            // Overridable, because "set it up now, switch it on later"
            // is a real request — but the DEFAULT is on, because a
            // half-provisioned org is the failure this caused.
            //
            // The default is config, with the old literal as the fallback a
            // stale config cache reads (S8). A Studio request cannot send
            // `crm_enabled` (`capabilities` prohibits it), so a Studio org is
            // always born at this default and a CRM choice is a departure the
            // capability writer stores and ledgers (R26).
            'crm_enabled' => $request->has('crm_enabled')
                ? $request->boolean('crm_enabled')
                : (bool) config('capabilities.crm.provision_default', true),
            'created_by' => $ctx->actorId,
        ]);

        // ---- Theme (from chosen base colors; partial theme allowed) ----
        $brand = $request->input('brand', []);
        $masjid->themeSettings()->create([
            'primary_color' => $brand['primary_color'] ?? null,
            'secondary_color' => $brand['secondary_color'] ?? null,
            'accent_color' => $brand['accent_color'] ?? null,
            'background_color' => $brand['background_color'] ?? null,
        ]);

        // ---- About / Mission / Vision (only when prose was supplied) ----
        if ($request->filled('about') || $request->filled('mission') || $request->filled('vision')) {
            $masjid->masjidAbout()->create([
                'about' => $request->input('about', ''),
                'mission' => $request->input('mission', ''),
                'vision' => $request->input('vision', ''),
            ]);
        }

        // ---- Prayer calculation settings ----
        $masjid->prayerCalculationSettings()->create([
            'method' => $request->input('method'),
            'madhab' => $request->input('madhab'),
            'high_latitude_rule' => $request->input('high_latitude_rule'),
        ]);

        // ---- Iqama time settings (minutes-after-adhan offsets) ----
        // Studio sends show_iqama_times (iqama is shown only when the client
        // gave all five times, which the request enforces); the wizard never
        // did, and keeps its `true` and its invented 20/10/10/5/10 for any
        // offset left blank. On Studio's path nothing is invented: an offset
        // the client did not give is the column's own 0, and is never shown,
        // because iqama is then hidden.
        $iqama = $request->input('iqama', []);
        $studioIqama = $request->has('show_iqama_times');
        IqamaTimeSetting::create([
            'masjid_id' => $masjid->id,
            'iqama_type' => $request->input('iqama_type', 'minutes_after_adhan'),
            'show_iqama_times' => $studioIqama ? $request->boolean('show_iqama_times') : true,
            'fajr' => $iqama['fajr'] ?? ($studioIqama ? 0 : 20),
            'dhuhr' => $iqama['dhuhr'] ?? ($studioIqama ? 0 : 10),
            'asr' => $iqama['asr'] ?? ($studioIqama ? 0 : 10),
            'maghrib' => $iqama['maghrib'] ?? ($studioIqama ? 0 : 5),
            'isha' => $iqama['isha'] ?? ($studioIqama ? 0 : 10),
        ]);

        // ---- Jumaa settings (fixed iqama time; sensible default) ----
        $masjid->jumaaSettings()->create([
            'iqama' => $request->input('jumaa_iqama') ?: '13:30',
            'athans' => [],
        ]);

        // ---- Donation link (only when a URL was supplied) ----
        if ($request->filled('donation_link')) {
            DonationLink::create([
                'masjid_id' => $masjid->id,
                'link' => $request->input('donation_link'),
                'title' => $request->input('donation_title') ?: 'Donation Link',
                'message' => $request->input('donation_message') ?: 'Donate Now',
            ]);
        }

        // ---- Social media links (optional) ----
        $socials = [
            'Facebook' => $request->input('facebook_url'),
            'YouTube' => $request->input('youtube_url'),
            'Instagram' => $request->input('instagram_url'),
            'WhatsApp_URL' => $request->input('whatsapp_url'),
            'WhatsApp_Number' => $request->input('whatsapp_number'),
        ];
        foreach ($socials as $type => $value) {
            if (filled($value)) {
                MasjidSocialMediaLink::create([
                    'masjid_id' => $masjid->id,
                    'type' => $type,
                    'value' => $value,
                ]);
            }
        }

        // ---- Default feature toggles ----
        // The wizard signals an explicit selection with the
        // `feature_keys_provided` flag: when present, enable only the
        // chosen keys (an all-unchecked selection legitimately enables
        // none — the flag disambiguates it from an absent field, since
        // multipart serialization drops empty arrays). Without the flag
        // the tenant falls back to its VERTICAL's bundle
        // (config/verticals.php), so a school never has the worship
        // modules switched on. For a masjid that bundle is the whole
        // seeded catalog, which is the previous "everything on"
        // behaviour unchanged.
        //
        // Matched by MobileAppFeature::normaliseKey, never the raw key:
        // production's Qur'an row is keyed `qur’an` (U+2019) while the
        // bundle says `quran`, and an exact match provisioned every new
        // masjid with Qur'an off.
        //
        // Studio sends `capabilities` instead (step 2 of the order above): the
        // switches are written first and the pivot is DERIVED from them, by
        // feature id, so installed Android builds (/features) and /menu start
        // out agreeing. The request refuses `capabilities` alongside the
        // wizard's feature fields, so exactly one of these branches applies.
        if ($request->has('capabilities')) {
            $ctx->capabilitiesApplied = CapabilityWriter::applyAtCreation(
                $masjid,
                (array) $request->input('capabilities'),
                $ctx->actorId === null ? null : (int) $ctx->actorId,
            );
            AppFeaturePivot::seedFromSwitches($masjid);
        } else {
            $explicitFeatures = $request->has('feature_keys_provided');
            $selected = array_map(
                fn ($key) => MobileAppFeature::normaliseKey($key),
                $explicitFeatures
                    ? ($request->input('feature_keys') ?? [])
                    : $masjid->defaultFeatureKeys()
            );
            foreach (MobileAppFeature::all() as $feature) {
                MasjidMobileAppFeature::create([
                    'masjid_id' => $masjid->id,
                    'feature_id' => $feature->id,
                    'is_available' => in_array(MobileAppFeature::normaliseKey($feature->key), $selected, true),
                ]);
            }
        }

        // ---- Vertical form templates (T-011) ----
        // Ready-to-edit starter forms for the tenant's vertical
        // (config/form_templates.php): a school is born with its
        // Admissions Interest / Careers Application / Withdrawal
        // Request forms. Masjid and community list NO templates, so
        // this is a no-op for them — provisioning stays byte-identical.
        // Seeded rows are ordinary forms (same table, same schema
        // vocabulary), indistinguishable from admin-built ones.
        FormTemplates::applyTo($masjid);

        // ---- Starter website (Studio, S8; D8) ----
        // Facts are read back from the rows just written, so the site copies
        // exactly what the client's answers stored, and Studio's preview (which
        // reads the same answers) showed the same pages.
        if ($request->filled('layout_preset')) {
            $preset = (string) $request->input('layout_preset');

            $ctx->starterSite = StarterSite::applyTo($masjid, $preset, StarterFacts::fromMasjid($masjid));

            $theme = $masjid->themeSettings()->firstOrFail();
            $tokens = is_array($theme->tokens) ? $theme->tokens : [];
            $tokens['layout'] = LayoutPresets::find($preset)['theme_layout'];
            $theme->update(['tokens' => $tokens]);
        }

        // ---- Web host rows (Studio, S8) ----
        // Written here so they commit or vanish with the organisation. Nothing
        // is sent to Cloudflare from inside the transaction: Studio dispatches
        // AttachMasjidDomain for each row after its commit. (The wizard never
        // sends a slug; a direct POST that does is picked up by
        // domains:reconcile, which advances pending rows every five minutes.)
        if (in_array('web', (array) $request->input('platforms', []), true) && $request->filled('slug')) {
            $ctx->domains = self::webDomains($masjid, $request, $ctx);
        }

        // ---- App-publishing config (platform selection + managed/BYO) ----
        // `platforms` (the Platforms step) is the source of truth for WHICH
        // apps the masjid wants; `apps[*].account_mode` describes HOW each
        // selected platform ships. account_mode defaults to `managed`.
        $platforms = array_values($request->input('platforms', []));
        $apps = $request->input('apps', []);

        $iosMode = $apps['ios']['account_mode'] ?? 'managed';
        $androidMode = $apps['android']['account_mode'] ?? 'managed';
        $webMode = $apps['web']['account_mode'] ?? 'managed';

        $publishing = [
            'masjid_id' => $masjid->id,
            'enabled_platforms' => $platforms,
            'ios_account_mode' => $iosMode,
            'android_account_mode' => $androidMode,
            'web_account_mode' => $webMode,
        ];
        // Only persist BYO credentials for a platform that is BOTH selected
        // AND in BYO mode.
        if (in_array('ios', $platforms, true) && $iosMode === 'byo') {
            $publishing['asc_key_p8'] = $apps['ios']['asc_key_p8'] ?? null;
            $publishing['asc_key_id'] = $apps['ios']['asc_key_id'] ?? null;
            $publishing['asc_issuer_id'] = $apps['ios']['asc_issuer_id'] ?? null;
        }
        if (in_array('android', $platforms, true) && $androidMode === 'byo') {
            $publishing['play_service_account_json'] = $apps['android']['play_service_account_json'] ?? null;
        }
        MasjidAppPublishing::create($publishing);

        // ---- The organisation's own administrator ----
        //
        // AN ORG WITH NO OWNER CANNOT BE ADMINISTERED BY ANYONE BUT A
        // SUPERADMIN. With tenancy.multi_membership off, TenantResolver
        // derives a MasjidAdmin's grant from `masjids.user_id`; when it
        // is null, `soleOwnedMembership()` finds nothing and every
        // masjid-scoped route 403s with "no verified membership". The
        // wizard's Identity step has always had an optional "Admin"
        // field and, left empty, wrote `user_id => null` with no warning
        // — which is how NAFIS, MEC and Al-Razi all ended up unreachable
        // by their own staff.
        //
        // Given an address, the account is created here and INVITED: the
        // platform picks a random secret it never reads, and the person
        // sets their own from an emailed link. Nobody at Manara ever
        // knows another organisation's credential.
        if ($request->filled('admin.email') && ! $request->filled('user_id')) {
            $admin = User::create([
                'name' => $request->input('admin.name') ?: $masjid->name.' Administrator',
                'email' => $request->input('admin.email'),
                'phone' => $request->input('admin.phone') ?: $masjid->phone,
                'type' => 'MasjidAdmin',
                'password' => Str::password(40),
            ]);

            $masjid->user_id = $admin->id;
            $masjid->save();

            $invitations[] = [$admin, $masjid->name];
        }

        // Give the owner a membership row as well as `masjids.user_id`.
        //
        // Both branches above can set an owner — a `user_id` supplied by
        // the wizard, or the admin account created just now — and neither
        // wrote anything to `masjid_user`. That works only while
        // tenancy.multi_membership is shut, because the resolver still
        // falls back to ownership. Open the gate and an owner with no row
        // is 403'd out of the organisation they were just given. See
        // MasjidUser::ensureOwnerMembership.
        MasjidUser::ensureOwnerMembership((int) $masjid->id, $masjid->user_id ? (int) $masjid->user_id : null);

        return $masjid;
    }

    /**
     * `slug` and `description`, only when sent. A key written as null would
     * still change the wizard's response: a created model serializes exactly
     * the attributes it was given, so an absent key must stay absent.
     *
     * @return array<string, string>
     */
    private static function studioIdentity(ProvisionMasjidRequest $request): array
    {
        $out = [];

        foreach (['slug', 'description'] as $key) {
            if ($request->filled($key)) {
                $out[$key] = (string) $request->input($key);
            }
        }

        return $out;
    }

    /**
     * The managed `{slug}.{managed_suffix}` row, plus the client's own domain
     * when one was given. Both start `pending`; without the Cloudflare token
     * they say so (`waiting_on = token`) from the start, which is what the
     * attacher would write on its first pass, so the provision response is
     * truthful before the job has run.
     *
     * @return list<MasjidDomain>
     */
    private static function webDomains(Masjid $masjid, ProvisionMasjidRequest $request, ProvisionContext $ctx): array
    {
        $waitingOn = app(CloudflareService::class)->isConfigured() ? null : 'token';
        $actor = $ctx->actorId === null ? null : (int) $ctx->actorId;

        $rows = [MasjidDomain::create([
            'masjid_id' => $masjid->id,
            'host' => $request->input('slug') . '.' . config('cloudflare.managed_suffix'),
            'kind' => MasjidDomain::KIND_MANAGED_SUBDOMAIN,
            'zone_apex' => (string) config('cloudflare.managed_zone'),
            'status' => MasjidDomain::STATUS_PENDING,
            'waiting_on' => $waitingOn,
            'source' => MasjidDomain::SOURCE_STUDIO,
            'created_by_user_id' => $actor,
        ])];

        if ($request->filled('web_domain.custom_host')) {
            $rows[] = MasjidDomain::create([
                'masjid_id' => $masjid->id,
                'host' => (string) $request->input('web_domain.custom_host'),
                'kind' => MasjidDomain::KIND_CUSTOM,
                'zone_apex' => (string) $request->input('web_domain.custom_zone_apex'),
                'status' => MasjidDomain::STATUS_PENDING,
                'waiting_on' => $waitingOn,
                'source' => MasjidDomain::SOURCE_STUDIO,
                'created_by_user_id' => $actor,
            ]);
        }

        return $rows;
    }
}
