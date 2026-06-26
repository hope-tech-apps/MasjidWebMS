# Deploying MasjidWebMS

Production runs on a DigitalOcean Droplet hosting `masjid.hopetechapps.com`.
This doc covers two things:

1. **First-time setup** — wiring up automatic deploy on every `git push` to `main`.
2. **Day-to-day operation** — what happens automatically, and what to do if a deploy fails.

After first-time setup, **shipping a change is: open a PR → merge it → done.** GitHub Actions takes it from there. No SSH, no manual commands, no rebuilds.

---

## First-time setup (≈ 15 minutes, one-time)

You'll do four things:

1. Generate an SSH deploy key pair on your laptop.
2. Paste the public key into the Droplet via DigitalOcean's web console.
3. Find a few values (deploy path, PHP-FPM service name) by running two commands on the Droplet.
4. Add four secrets to this GitHub repo and merge this PR.

### Step 1 — Generate an SSH deploy key on your laptop

In a terminal on your Mac:

```sh
ssh-keygen -t ed25519 -C "github-actions-masjidwebms-deploy" -f ~/.ssh/masjidwebms_deploy -N ""
```

This creates two files:
- `~/.ssh/masjidwebms_deploy` — the **private** key (goes into GitHub secrets)
- `~/.ssh/masjidwebms_deploy.pub` — the **public** key (goes onto the Droplet)

Don't reuse an existing personal SSH key — a deploy key should be dedicated, so you can rotate it without breaking anything else.

### Step 2 — Add the public key to the Droplet

1. Print the public key in your terminal:
   ```sh
   cat ~/.ssh/masjidwebms_deploy.pub
   ```
   You'll see one line starting with `ssh-ed25519 AAAA...`.

2. Open DigitalOcean → Droplets → the Droplet running `masjid.hopetechapps.com` → click **Console** (top-right). A browser terminal opens.

3. Inside the Droplet, append the public key to `authorized_keys`:
   ```sh
   mkdir -p ~/.ssh
   chmod 700 ~/.ssh
   echo 'PASTE THE PUBLIC KEY LINE HERE' >> ~/.ssh/authorized_keys
   chmod 600 ~/.ssh/authorized_keys
   ```
   Replace `PASTE THE PUBLIC KEY LINE HERE` with the literal output of `cat` from step 1.

4. (Optional but recommended) Test that SSH key auth works from your laptop:
   ```sh
   ssh -i ~/.ssh/masjidwebms_deploy YOUR_DROPLET_USER@YOUR_DROPLET_IP
   ```
   If this logs you in **without a password prompt**, the key is wired up correctly. Exit (`exit` or `Ctrl-D`).

### Step 3 — Find the deploy path + PHP-FPM service name

Still in the Droplet console (or the SSH session from step 2), run:

```sh
# Where is the MasjidWebMS checkout? Look for the `artisan` file.
find / -name "artisan" -type f 2>/dev/null | grep -v vendor | head -5

# What's the PHP-FPM service called?
systemctl list-units 'php*-fpm.service' --type=service --no-pager
```

Note:
- The directory containing `artisan` — e.g. `/var/www/masjidwebms` or `/home/forge/masjid.hopetechapps.com`. This becomes the `DEPLOY_PATH` secret.
- The service name — e.g. `php8.2-fpm.service` or `php8.3-fpm.service`. Drop the `.service` suffix — this becomes the `DEPLOY_PHP_FPM_SERVICE` secret (optional; defaults to `php8.2-fpm` if you don't set it).

### Step 4 — Allow passwordless PHP-FPM reload

The deploy workflow gracefully reloads PHP-FPM at the end so OPcache picks up changed files. By default `systemctl reload` needs root or `sudo`, which the deploy user can't use interactively. Fix this once:

In the Droplet console as root:
```sh
echo "$(whoami) ALL=(ALL) NOPASSWD: /bin/systemctl reload php8.2-fpm, /bin/systemctl restart php8.2-fpm" | sudo tee -a /etc/sudoers.d/deploy-php-fpm
sudo chmod 440 /etc/sudoers.d/deploy-php-fpm
```

Substitute your actual deploy user (the one whose `~/.ssh/authorized_keys` got the public key) and the actual PHP version if it's not 8.2. If you skip this step the deploy will still succeed — but you'll get a benign error in the logs and OPcache may serve stale code until the next manual reload.

### Step 5 — Add the GitHub secrets

In this repo on GitHub: **Settings → Secrets and variables → Actions → New repository secret**. Add these four:

| Secret name | Value |
|---|---|
| `DEPLOY_HOST` | The Droplet's public IP or `masjid.hopetechapps.com` |
| `DEPLOY_USER` | The Droplet user that owns the deploy (whatever `whoami` returned in step 4, often `root` or `deploy`) |
| `DEPLOY_SSH_KEY` | The **private** key — full contents of `~/.ssh/masjidwebms_deploy`. Include the `-----BEGIN OPENSSH PRIVATE KEY-----` and `-----END OPENSSH PRIVATE KEY-----` lines and the newline at the end. |
| `DEPLOY_PATH` | Absolute path to the MasjidWebMS checkout, e.g. `/var/www/masjidwebms` |

Optional fifth secret:
| `DEPLOY_PHP_FPM_SERVICE` | e.g. `php8.3-fpm` — only needed if you're not on PHP 8.2 |

### Step 6 — Merge this PR and verify

Once the secrets are saved:

1. Merge this PR. The very first run of the workflow will deploy the merge commit itself.
2. Open **Actions** tab in GitHub → "Deploy to production" → watch the live log.
3. The deploy should take 60-120 seconds. If it succeeds: 🎉 you're done.
4. Verify a public endpoint works:
   ```sh
   curl -s -o /dev/null -w "%{http_code}\n" https://masjid.hopetechapps.com/api/mobile/masjids/1/splash
   ```
   Expect `204` (no splash live yet) or `200` (one is live).
5. Verify the migration ran:
   ```sh
   # From the Droplet console:
   cd $DEPLOY_PATH && php artisan migrate:status | grep splash
   # Expect:  Yes  2026_05_24_000000_create_splash_announcements_table  ...
   ```

---

## Day-to-day operation

### Shipping any change

```
1. Open a PR
2. Merge to main
3. (Optional) Watch Actions tab — usually finishes in ~60s
4. Done. The change is live.
```

That's the whole flow. The same workflow applies to the splash feature, future features, hotfixes, security patches — everything backend.

### What the deploy does, in order

The workflow runs these commands on the Droplet, all in one SSH session:

```sh
php artisan down --retry=15           # graceful 503 to visitors during the deploy (~30-60s)
git fetch origin && git reset --hard origin/main
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
php artisan migrate --force           # safe: only applies NEW migrations
npm ci && npm run build               # rebuilds the Vue admin SPA into public/build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart             # signals queue workers to gracefully exit
sudo systemctl reload php8.2-fpm      # OPcache cycles
php artisan up                        # back online
```

Total visitor downtime: ~30-60 seconds while `php artisan down` is in effect. The `--retry=15` header tells well-behaved clients to retry after 15s, so the splash modal and other API consumers transparently recover.

### Manual re-deploy

If you want to redeploy without merging anything (e.g. to re-apply env var changes):

GitHub → **Actions** tab → **"Deploy to production"** → **Run workflow** → select `main` → Run.

### If a deploy fails

The workflow uses `set -e` and a trap so any failure aborts the deploy and immediately calls `php artisan up` to bring the site back. The PREVIOUS code is still live (because the workflow runs steps sequentially and any failed step exits before the build artifacts are swapped in via the cached config/route/view artifacts).

The GitHub Actions log will show exactly which step failed:

- **`composer install` failed** — usually a missing PHP extension or a network blip. Re-run the workflow.
- **`migrate --force` failed** — schema conflict. SSH in via the DO console, run `php artisan migrate:status`, investigate. Don't re-run the workflow until the DB is in a known state.
- **`npm run build` failed** — usually a TypeScript error in the admin SPA. Fix on a branch, PR, merge.
- **`systemctl reload` failed** — sudo perms. Re-do Step 4 of first-time setup.

### Rolling back

To revert a bad deploy:

```sh
# On your laptop:
git revert <bad-commit-sha>
git push origin main
```

The revert push triggers a fresh deploy of the reverted code. Same flow — no SSH needed.

### Node version

The workflow runs `npm ci && npm run build` using whatever `node` is on `PATH` on the Droplet. If you need a specific version (e.g. the admin SPA's `package.json` engines field tightens):

1. Install nvm on the Droplet (one-time):
   ```sh
   curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash
   ```
2. Set a default for the deploy user:
   ```sh
   nvm install --lts
   nvm alias default 'lts/*'
   ```
3. Make sure the deploy SSH session sources nvm. The non-interactive shell GitHub Actions opens doesn't read `~/.bashrc` by default; either add the `NVM_DIR` export to `~/.bash_profile` (which non-interactive shells DO read on most distros) or add a step before `npm ci` in `.github/workflows/deploy.yml` that sources nvm explicitly:
   ```yaml
   # in the script block, before npm ci:
   export NVM_DIR="$HOME/.nvm"; [ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
   ```

### Updating env vars

The workflow does **not** touch `.env` on the Droplet. To change env vars:

1. SSH in via the DO console.
2. `cd /var/www/masjidwebms` (or whatever your `DEPLOY_PATH` is).
3. Edit `.env`.
4. `php artisan config:clear && php artisan config:cache` to pick up the change.

This is intentional — secrets in `.env` should never live in GitHub.

---

## Frontend (Nuxt site) deploys

The Nuxt marketing site at `burlington-masjid-site` is **already auto-deployed by Vercel** on every push to its `main`. No setup needed there. If a Nuxt change isn't showing up:

1. Check Vercel dashboard → the project → Deployments → look for failures.
2. Confirm the `API_BASE_URL` env var in Vercel Settings → Environment Variables points at this backend.

---

## Why this setup

- **GitHub Actions** is free for public repos and generous for private ones, and is already where the code lives. No extra service to pay for or manage.
- **SSH-based deploy** matches your existing manual flow exactly — same commands, same Droplet, same paths. Lowest-surprise migration.
- **`php artisan down/up` bracket** gives ~30-60s of clean maintenance mode instead of users hitting half-deployed code mid-request.
- **No Docker, no container registry, no Kubernetes** — the Droplet already has the right PHP/Node versions and we keep it that way. If you ever outgrow the Droplet, App Platform or Forge become viable alternatives and this workflow can be retired in favor of their built-in deploys.
