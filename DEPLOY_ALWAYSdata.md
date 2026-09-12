# Deploy ESPForge on alwaysdata

## 1. Create resources

1. Create a free account at [alwaysdata](https://www.alwaysdata.com/).
2. In **Databases > MySQL**, create a database and user. Record the exact host, database, username, and password. The host normally follows `mysql-ACCOUNT.alwaysdata.net`; use the value shown in the panel.
3. In **Remote access > SSH**, enable an SSH user and add an SSH public key (recommended).

## 2. Deploy from GitHub

Connect over SSH using the command shown by alwaysdata, then run:

```bash
cd ~
git clone --branch arena/01a07f43-espfirmbuilder \
  https://github.com/evrahimkhan/espfirmbuilder.git espforge
cd espforge
mkdir -p storage/logs storage/cache
cp config/config.local.example.php config/config.local.php
chmod 700 config
chmod 600 config/config.local.php
```

For later deployments:

```bash
cd ~/espforge
git pull --ff-only origin arena/01a07f43-espfirmbuilder
```

`config/config.local.php` is ignored by Git, so pulls do not overwrite production secrets.

## 3. Configure secrets

Generate an application encryption key over SSH:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Edit `~/espforge/config/config.local.php` and set:

- Public HTTPS URL, such as `https://ACCOUNT.alwaysdata.net`
- MySQL DSN, username, and password
- Generated encryption key
- GitHub OAuth client ID and secret
- Google OAuth client ID and secret
- `app.env` set to `production`
- A valid `mail.from` address for verification and password recovery

The DSN format is:

```text
mysql:host=mysql-ACCOUNT.alwaysdata.net;port=3306;dbname=DATABASE;charset=utf8mb4
```

Never commit `config.local.php`.

## 4. Import the database

From SSH:

```bash
mysql -h mysql-ACCOUNT.alwaysdata.net \
  -u DATABASE_USER -p DATABASE_NAME < ~/espforge/database/schema.sql
```

Alternatively, open **Databases > MySQL > phpMyAdmin**, select the database, and import `database/schema.sql`.

For an existing installation, do not re-import `schema.sql`. Back up MySQL and apply each unapplied file in `database/migrations/` in filename order **before** pulling PHP code that depends on it:

```bash
mysql -h mysql-ACCOUNT.alwaysdata.net -u DATABASE_USER -p DATABASE_NAME < database/migrations/20260912_001_hardening.sql
mysql -h mysql-ACCOUNT.alwaysdata.net -u DATABASE_USER -p DATABASE_NAME < database/migrations/20260912_002_audit_events.sql
mysql -h mysql-ACCOUNT.alwaysdata.net -u DATABASE_USER -p DATABASE_NAME < database/migrations/20260912_003_password_recovery.sql
mysql -h mysql-ACCOUNT.alwaysdata.net -u DATABASE_USER -p DATABASE_NAME < database/migrations/20260912_004_email_verification.sql
mysql -h mysql-ACCOUNT.alwaysdata.net -u DATABASE_USER -p DATABASE_NAME < database/migrations/20260912_005_flash_events.sql
mysql -h mysql-ACCOUNT.alwaysdata.net -u DATABASE_USER -p DATABASE_NAME < database/migrations/20260912_006_distributed_rate_limits.sql
```

## 5. Create the PHP site

In **Web > Sites**, add a site with:

| Setting | Value |
|---|---|
| Type | PHP |
| Address | `ACCOUNT.alwaysdata.net` or your domain |
| Root directory | `/home/ACCOUNT/espforge/public` |
| PHP version | 8.2 or newer |

The root must end in `/public`; do not expose the repository root.

Under the site's **SSL** settings:

1. Enable the automatic certificate.
2. Check **Force HTTPS**.

HTTPS is mandatory for Web Serial flashing.

## 6. Configure OAuth callbacks

Create a GitHub OAuth App with:

```text
Homepage URL: https://ACCOUNT.alwaysdata.net
Callback URL: https://ACCOUNT.alwaysdata.net/api/auth.php?action=github_callback
```

Create a Google OAuth web client with this authorized redirect URI:

```text
https://ACCOUNT.alwaysdata.net/api/auth.php?action=google_callback
```

Replace the hostname with a custom domain if one is configured. OAuth callback URLs must exactly match the URL in `config.local.php`.

## 7. Verify the installation

Run the server-side checks:

```bash
cd ~/espforge
php -v
php -m | grep -E 'curl|openssl|pdo_mysql'
find config public/api src bin tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/TargetAnalyzerParserTest.php
php bin/preflight.php
```

`bin/preflight.php` exits non-zero if required extensions, production configuration, writable temporary storage, mail delivery configuration, tables, columns, or uniqueness indexes are missing.

Then test:

1. Open the HTTPS site.
2. Register with email/password.
3. Connect GitHub from **Settings**.
4. Connect a small test repository.
5. Confirm `.github/workflows/espforge-build.yml` is committed.
6. Start a build and open its GitHub Actions run.
7. In Chrome or Edge, open **Web Flasher**, select a `.bin`, and connect an ESP32.

## 8. Schedule privacy maintenance

In alwaysdata's scheduled-jobs panel, run this command daily:

```bash
cd /home/ACCOUNT/espforge && php bin/prune-data.php
```

It removes expired recovery/verification tokens, audit events older than 180 days, flash metrics older than two years, and expired rate-limit files. The database cleanup is transactional and exits non-zero on failure.

## Operational notes

- Firmware files selected in Web Flasher stay in the browser; they are not uploaded to alwaysdata.
- Build binaries remain in GitHub Actions artifacts, reducing hosting storage usage.
- Keep periodic exports even though alwaysdata supplies backups.
- Free hosting is appropriate for development and a low-traffic MVP, not a production SLA.
- Use a custom domain before publishing widely so OAuth callback URLs do not need to change later.
