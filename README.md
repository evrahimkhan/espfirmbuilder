# ESPForge

A PHP/MySQL MVP for AI-assisted ESP32 firmware builds through GitHub Actions and browser-based ESP32 flashing.

## Included

- Responsive product landing page and authenticated workspace
- Email/password auth with secure sessions, CSRF protection, and OAuth entry points
- Repository connection, build queue/history APIs, and dashboard
- Real Web Serial flashing through `esptool-js`, including progress and board reset
- GitHub OAuth callback, encrypted token storage, repository inspection, automatic forking of read-only repositories, and workflow deployment
- PlatformIO, ESP-IDF, and Arduino project detection with framework-specific workflow generation
- Deterministic hardware-target discovery with Gemini/OpenRouter fallback for non-standard repositories
- Per-model workflow generation using active or disabled PlatformIO environments, Arduino FQBN/defines, ESP-IDF targets/config defaults, or existing matrices
- Real GitHub Actions dispatch and build-run status reconciliation
- Encrypted Google Gemini/OpenRouter credential settings
- MySQL schema for users, repositories, builds, and flash profiles
- AES-256-GCM helper for GitHub and AI credentials

## Local development

Requirements: PHP 8.1+, MySQL 8+, and the OpenSSL/PDO MySQL extensions.

```bash
mysql -u root -p -e 'CREATE DATABASE espforge CHARACTER SET utf8mb4'
mysql -u root -p espforge < database/schema.sql
cp config/config.example.php config/config.php # already present for convenience
php -S 0.0.0.0:8080 -t public
```

Open `http://localhost:8080`. Configure settings through environment variables where possible:

```text
APP_URL, APP_KEY, DB_DSN, DB_USER, DB_PASSWORD
GITHUB_CLIENT_ID, GITHUB_CLIENT_SECRET
GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET
```

Never deploy with the example `APP_KEY`. Register the exact callback URLs shown in `config/config.php` with GitHub and Google. Connecting a repository inspects its Git tree, commits `.github/workflows/espforge-build.yml`, and later dispatches that workflow. Build links currently open the corresponding GitHub run, where artifacts can be downloaded. Repository analysis is deterministic by default; stored AI keys are ready for an optional model-assisted analyzer for non-standard layouts.

## alwaysdata deployment

Follow the complete [alwaysdata deployment guide](DEPLOY_ALWAYSdata.md). Production secrets belong in `config/config.local.php`, which is intentionally ignored by Git.

## Shared hosting deployment

Point the web root to `public/`, import `database/schema.sql`, and keep `config/` outside direct HTTP access. If the host cannot change document root, deny web access to `config`, `src`, `database`, and `storage`. HTTPS is required for Web Serial. ProFreeHost capabilities vary; GitHub webhooks need a publicly reachable HTTPS PHP endpoint.

## Security notes

Secrets should only be stored using `encrypt_secret()`, never returned by APIs, and protected by a unique 32+ byte `APP_KEY`. Production should add rate limiting, verified email, strict CSP, GitHub webhook signature verification, token scope minimization, and periodic credential rotation.
