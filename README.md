# ISP Billing System

A PHP and MySQL application for managing ISP customers, billing, payments, inventory, and operations.

## Project layout

```text
app/
  Bootstrap/       Application bootstrap and shared configuration
  Support/         Reusable application helpers
  Views/           Shared page fragments
config/            Environment-based database configuration
database/
  migrations/      Additive database schema updater
public/            Web document root: PHP endpoints, assets, and uploads
cron_auto_billing.php
                   CLI-only automatic billing job
storage/
  logs/            Runtime PHP logs (ignored by Git)
```

Configure Apache's document root as `public/`. Existing page endpoint names and URLs remain unchanged when served from this document root. For local XAMPP setups that still use the repository root as the document root, enable `mod_rewrite` and `AllowOverride All`; the root `.htaccess` forwards requests into `public/`.

The root-level `config.php`, `import_helper.php`, `update_setup.php`, and shared view files remain compatibility includes for the CLI job and legacy callers. Their implementations live in the organized directories. Keep these shims until all legacy callers have been migrated.

## Local setup

1. Configure Apache and PHP with `public/` as the document root, and create the MySQL database.
2. Set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` in the PHP/Apache environment. When omitted, the application uses the local XAMPP defaults (`localhost`, `isp`, `root`, and an empty password).
3. Open `setup.php` to initialize the schema.

For production, set database credentials through the hosting environment; do not put production secrets in source control. The error log is written to `storage/logs/error_log.txt`.

## Schema and scheduled jobs

`database/migrations/update_setup.php` contains the additive schema updater. The root-level `update_setup.php` remains available for compatibility with existing callers.

Run automatic billing from the scheduler using PHP CLI, for example `php /path/to/isp-billing-sys/cron_auto_billing.php`. Keep this job outside the web document root.
