# Manara's sending domain: `notifications@manara.hopetechapps.com`

Manara mail (class nudges, portal invites, parent sign-in codes, receipts, broadcasts, staff mail)
goes out as **`<organisation name> <notifications@tapcraft.tech>`**. That is TapCraft's domain, a
different product. This document moves it to **`notifications@manara.hopetechapps.com`**, keeping
the organisation's name as the display name. It unblocks the Schools landing page's pre-publish
check P5 (`~/Developer/manara-marketing/docs/research/pricing/FINAL.md`). Decision:
`DECISIONS.md`, 2026-09-29, "Manara mail sends from manara.hopetechapps.com".

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
suffix (`<slug>.manara.hopetechapps.com`). Resend's return path is `send.manara.hopetechapps.com`,
so `send` is now in `config('cloudflare.reserved_labels')`. An organisation holding it would put a
CNAME where the MX and SPF records must live. No organisation held it (checked read-only on
production 2026-09-29: no `masjids.slug = send`, no `masjid_domains` host `send.%`).

## 3. DNS records (Cloudflare zone `hopetechapps.com`, id `859eddb9bce48f4f35e6197f6c0b8e15`)

All **DNS only** (grey cloud; MX and TXT cannot be proxied anyway), TTL Auto. Names are as the
Cloudflare dashboard wants them, relative to the zone.

| # | Type | Name | Content | Priority | Purpose |
|---|---|---|---|---|---|
| 1 | TXT | `resend._domainkey.manara` | `p=MIGfMA0…` (the public key Resend shows when the domain is created) | | **DKIM**: Resend signs with `d=manara.hopetechapps.com`. |
| 2 | MX | `send.manara` | `feedback-smtp.us-east-1.amazonses.com` | 10 | **Return path**: bounces and complaints go back to Resend. |
| 3 | TXT | `send.manara` | `v=spf1 include:amazonses.com ~all` | | **SPF** for the return-path domain. |

- **Record 1 does not exist until the domain is created in Resend.** It is generated per domain.
  Copy it exactly from Resend's DNS tab. It is a public key, safe to paste anywhere.
- **Rows 2 and 3 assume the `us-east-1` region**, the one tapcraft.tech uses
  (`send.tapcraft.tech MX 10 feedback-smtp.us-east-1.amazonses.com`). If another region is picked
  in Resend, use the values Resend shows.
- **Alignment.** DKIM `d=` equals the From domain (strict). The SPF domain `send.manara…` shares the
  organisational domain `hopetechapps.com` with the From domain (relaxed). Both pass DMARC.
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
   the record it offers (`_dmarc.hopetechapps.com`, `p=none`).
   **YES: DNS.** Add records 1 to 3 (above) in `hopetechapps.com`. Check with
   `dig TXT resend._domainkey.manara.hopetechapps.com +short` and the same for `send.manara`
   (MX and TXT), and `dig TXT _dmarc.hopetechapps.com +short`. Then press Verify in Resend until the
   domain reads Verified.
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
