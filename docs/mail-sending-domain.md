# Manara's sending domain: `notifications@manara.hopetechapps.com`

Manara mail (class nudges, portal invites, parent sign-in codes, receipts, broadcasts, staff mail)
goes out as **`<organisation name> <notifications@tapcraft.tech>`**. That is TapCraft's domain, a
different product. This document moves it to **`notifications@manara.hopetechapps.com`**, keeping
the organisation's name as the display name. It unblocks the Schools landing page's pre-publish
check P5 (`~/Developer/manara-marketing/docs/research/pricing/FINAL.md`). Decision:
`DECISIONS.md`, 2026-09-29, "Manara mail sends from manara.hopetechapps.com".

**Status (2026-09-30 03:42Z): LIVE in production.** Staging verified 03:36Z, production switched
03:41:56Z (config re-cache of unchanged main `b5c2f808`), verified with a real send: inbox, From
"Al-Razi School <notifications@manara.hopetechapps.com>", Reply-To the school, DKIM/SPF/DMARC pass.
Records in LOG.md. Open: this branch's code on main; revoking the old key (step 6); the DMARC ramp
(step 7).

## 1. Where the address comes from (checked 2026-09-29, origin/main `b5c2f808`)

- Every Mailable that sets a From takes the address from `config('mail.from.address')`, and the
  rest inherit the global `mail.from`. The display name is the organisation's (`orgName` /
  `masjidName`), falling back to `MAIL_FROM_NAME` ("Manara"). Replies go to the organisation's
  email when it is valid.
- `config/mail.php` reads `MAIL_FROM_ADDRESS`. No address literal exists in `app/Mail/*` or
  `OpsAlertMailHandler`. `tests/Feature/MailSendingDomainTest.php` pins both facts.
- Production `.env`: `MAIL_MAILER=resend`, `MAIL_FROM_ADDRESS="notifications@tapcraft.tech"`,
  `MAIL_FROM_NAME="Manara"`. `RESEND_KEY` is a send-only key, exposed in a transcript on
  2026-08-26 and still owed a rotation.
- Staging `.env`: `MAIL_MAILER=log`, `RESEND_KEY` blank (by rule, `.claude/rules/environments.md`),
  `MAIL_FROM_ADDRESS` still the tapcraft address from the clone.
- The transport is Resend, through `App\Mail\Transport\ResendWithTimeouts`.

**So the switch is one production `.env` line, plus a new Resend key.** No mail class changes.

## 2. The domain, and why

`manara.hopetechapps.com`, sending as `notifications@manara.hopetechapps.com`.

- **It names Manara.** Parents see the product they sign in to, not TapCraft. It is the
  platform's own host (`manara.hopetechapps.com/auth/sign-in`).
- **It keeps school mail away from the company's mailbox domain.** `hopetechapps.com` itself is
  Hope Tech's Zoho mail (MX `mx.zoho.com`, SPF `include:zohomail.com`, DKIM `zmail._domainkey`). A
  complaint spike from a school broadcast would count against the subdomain, not against the
  company's own business mail.
- **Nothing on the names Resend needs exists yet.** `send.manara`, `resend._domainkey.manara` and
  `_dmarc.manara` were all NXDOMAIN on 2026-09-29, and the zone listing agrees.
- **It moves in one line.** If Manara buys its own domain later (`manara-platform` memory: the name
  is crowded, so not yet), it is the same records under the new name plus one `.env` line.

Rejected:
- **`notifications@hopetechapps.com`.** It puts school volume on the company's mail domain, and
  that root has no DMARC today. Adding one there would govern the Zoho mail too.
- **A deeper name (`mail.manara.hopetechapps.com`).** Longer, and it isolates nothing more:
  `manara.hopetechapps.com` sends no other mail.
- **Keep tapcraft.tech.** P5 fails, and every parent sees another company's name.

**One consequence, handled in this branch.** `manara.hopetechapps.com` is also Studio's managed
suffix (`<slug>.manara.hopetechapps.com`). Resend's return paths are `send.manara` and
`rsend.manara`, so both are now in `config('cloudflare.reserved_labels')`. An organisation holding
one would claim the name the bounce and SPF records live on. No organisation held either (checked
read-only on production 2026-09-29 and 2026-09-30: no such `masjids.slug`, no such
`masjid_domains` host).

## 3. DNS records (Cloudflare zone `hopetechapps.com`, id `859eddb9bce48f4f35e6197f6c0b8e15`)

**Created by Resend itself** on 2026-09-30 at 03:08:32Z, when the domain was added with Resend's
Cloudflare auto-configure. All DNS only.

| Type | Name | Content | Purpose |
|---|---|---|---|
| TXT | `resend._domainkey.manara` | `p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCkCNFDXbTJ…IDAQAB` (1024-bit RSA; checked with `openssl pkey`) | **DKIM**: Resend signs with `d=manara.hopetechapps.com`. |
| CNAME | `send.manara` | `send.forge.rmta.net` | **Return path** on Resend's own servers. Through the CNAME it answers MX `10 feedback.forge.rmta.net` and SPF `v=spf1 ip4:52.3.252.119 ip4:44.222.39.36 ip4:199.249.231.0/24 ~all`. |
| CNAME | `rsend.manara` | `rsend.forge.rmta.net` | **Second return path**, on Amazon SES us-east-1: MX `10 feedback-smtp.us-east-1.amazonses.com`, SPF `include:amazonses.com`. |

- **This is not the record set this document first listed.** The first draft had the older
  direct MX and SPF TXT at `send.manara`, the ones tapcraft.tech still carries. A domain added
  today gets CNAMEs to `forge.rmta.net`, so Resend can move its servers without a DNS change on our
  side. A guarded write script refused to add the older pair because the CNAME was already there.
  Nothing was written by hand.
- **Alignment.** DKIM `d=` equals the From domain (strict). Both return-path names share the
  organisational domain `hopetechapps.com` with the From domain (relaxed SPF alignment). Both pass
  DMARC.
- `send` and `rsend` are reserved Studio labels for this reason (section 2).
- **DMARC comes from the zone apex, through Cloudflare DMARC Management (owner's choice,
  2026-09-29).** DMARC Management works on apex domains only
  (developers.cloudflare.com/dmarc-management/enable). Enabling it in the `hopetechapps.com` zone
  (Email → DMARC Management → Enable) adds `_dmarc.hopetechapps.com` at `p=none` with Cloudflare's
  report address. With no `_dmarc.manara` record, a receiver checking mail from
  `manara.hopetechapps.com` falls back to that organisational-domain record, and the reports land in
  the same dashboard. So there is **no `_dmarc.manara` record**: one would split the reports out of
  the dashboard. `p=none` only monitors. It changes nothing about how the company's Zoho mail or
  Resend mail is delivered, and it reports on both, which is a gain for Zoho too. Gmail's and
  Yahoo's "publish DMARC" expectation is met through the same fallback.
- **DMARC ramp.** Run `p=none` for 2 to 4 weeks. When the reports show every legitimate source
  passing, tighten. To tighten Manara alone, add `sp=quarantine` to the apex record (it governs
  every subdomain), or give `_dmarc.manara` its own record, which moves its reports out of the
  dashboard. Tightening `p=` at the apex also governs Zoho, so check Zoho's DKIM first.
- Click and open tracking stay **off** in Resend. They need a tracking CNAME, rewrite links
  (including sign-in links) through a third party, and add pixels to mail about children.

## 4. Order of work

Each step marked **YES** waits for the owner's explicit go.

1. **Code** (this branch, dark): the `mail:test-send` command, the `send` reservation, `@` allowed
   in `scripts/set-server-secret.sh`, and this document. Full suite, then main under the baton.
   It changes no mail anyone receives.
2. **Owner, in Resend:** Domains → Add domain → `manara.hopetechapps.com`, region North Virginia
   (us-east-1). Then API Keys → Create → permission **Sending access**, domain
   **manara.hopetechapps.com** only, name `manara-production`. Keep the key out of chat; it goes in
   through hidden prompts only (steps 4 and 5).
3. **Owner, in Cloudflare:** `hopetechapps.com` → Email → DMARC Management → Enable, and accept
   the record it offers (`_dmarc.hopetechapps.com`, `p=none`). **DNS:** the section 3 records
   (Resend's auto-configure writes them). Check with
   `dig TXT resend._domainkey.manara.hopetechapps.com +short`, `dig MX send.manara.hopetechapps.com
   +short`, `dig MX rsend.manara.hopetechapps.com +short` and `dig TXT _dmarc.hopetechapps.com
   +short`. Resend must read the domain as Verified before the staging send.
4. **YES: staging.** `scripts/ship.sh staging feat/manara-sending-domain` (check the box's
   `git reflog` first; it is shared). Then the owner runs, in their own terminal (`-t`, because the
   key prompt needs a TTY):

   ```sh
   ssh -t root@157.230.212.38 'cd /var/www/html/Masjids_App_Management_System/MasjidsManagementSystem && sudo -u www-data php artisan mail:test-send <owner address> --org=14 --from=notifications@manara.hopetechapps.com --prompt-key'
   ```

   Staging's `.env` is not touched: the key lives for that one process. Pass: the command prints a
   Resend id; the message arrives from "Al-Razi School <notifications@manara.hopetechapps.com>";
   Gmail's Show original reads `SPF: PASS`, `DKIM: PASS`, `DMARC: PASS`. Write the result in
   LOG.md.
5. **YES: production.**
   1. Ship the code (baton, `scripts/ship.sh production`). Still dark.
   2. The owner runs `scripts/set-server-secret.sh RESEND_KEY` and pastes the new key at the
      hidden prompt.
   3. `printf 'notifications@manara.hopetechapps.com\n' | scripts/set-server-secret.sh MAIL_FROM_ADDRESS`.
      Neither change is live yet: the app reads cached config.
   4. Tell the other shipping sessions, then re-run `scripts/ship.sh production`. `bin/deploy`
      runs `config:cache` and restarts `masjid-queue` even when the code is already up to date.
      This is the moment of the switch; queued mail picks it up through the restart.
   The switch needs no code from this branch: the deployed code already reads the address from
   config. On 2026-09-30 it went in the other order. The owner set RESEND_KEY first, so
   MAIL_FROM_ADDRESS went in within a minute, before any config:cache could pair the new key with
   the old address. Then the re-ship switched both, and the code followed later under the baton.
   Whenever one of the two keys is set, set the other straight away: any session's production
   ship re-caches config.
   5. Verify: `sudo -u www-data php artisan mail:test-send <owner address> --org=14` on production
      (no prompt; it uses the new `.env`). Check the headers as in step 4. Watch
      `storage/logs/laravel.log` for mail warnings over the next day.
6. **Owner, after a clean day:** revoke the old key in Resend. First check its "last used" there,
   in case anything besides Manara still sends with it. Keep `tapcraft.tech` in Resend for
   TapCraft's own mail.
7. **After 2 to 4 weeks of clean DMARC reports:** tighten as in section 3 (a DNS change, so YES
   again).
8. Tell the manara-marketing session that P5 is met.

## 5. Rollback

`set-server-secret.sh` leaves a byte-for-byte backup per run (`.env.bak-<stamp>-<pid>`, owner
and mode preserved). To go back to tapcraft.tech:

1. On production, write the older backup back **through the inode**:
   `cat .env.bak-<first run> > .env`. Never `mv` or `cp` without `-p`
   (`env-edit-ownership-trap` memory).
2. Re-run `scripts/ship.sh production` to re-cache config and restart the queue worker.
3. `mail:test-send` to confirm the old sender.

The old key must still be valid for this, which is why step 6 waits for a clean day. The DNS
records can stay; they send nothing on their own.

## 6. Known limits

- **`manara.hopetechapps.com` receives no mail.** It has no MX. A reply to a message whose
  organisation has no valid email (so no Reply-To) goes to `notifications@manara.hopetechapps.com`
  and bounces. Most org mail sets Reply-To to the organisation. Al-Razi's email is valid
  (checked 2026-09-29). Platform mail (two-factor reset, ops alerts) has no Reply-To.
  Whether replies to `notifications@tapcraft.tech` were ever read today is `Unknown, needs
  investigation`. If replies must land somewhere, add an MX for `manara.hopetechapps.com` (Zoho,
  or Resend inbound). That is a separate decision.
- **Deliverability is not proven by a test to one Gmail inbox.** It proves authentication. Real
  placement shows in DMARC reports and Resend's bounce and complaint numbers over the first weeks.
- **Staging keeps the tapcraft address** in its `.env`, and it delivers nothing anyway
  (`MAIL_MAILER=log`). Aligning it is cosmetic; do it with `set-server-secret.sh` and the
  `MANARA_*` overrides if wanted.
