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
repository root    Existing PHP endpoints and browser assets
storage/
  logs/            Runtime PHP logs (ignored by Git)
uploads/           Existing user-uploaded files
```

The project is still served from its repository root to preserve existing XAMPP URLs. The root-level `config.php`, `import_helper.php`, `update_setup.php`, and shared view files are compatibility includes; their implementations live in the organized directories. Keep these shims until all legacy callers have been migrated. Do not change Apache's document root to a `public/` directory until the page endpoints and assets have been migrated in a later phase.

## Local setup

1. Configure Apache and PHP with the project root as the current document root, and create the MySQL database.
2. Set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` in the PHP/Apache environment. When omitted, the application uses the local XAMPP defaults (`localhost`, `isp`, `root`, and an empty password).
3. Open `setup.php` to initialize the schema.

For production, set database credentials through the hosting environment; do not put production secrets in source control. The error log is written to `storage/logs/error_log.txt`.

## Schema updates

`database/migrations/update_setup.php` contains the additive schema updater. The root-level `update_setup.php` remains available for compatibility with existing deployments and callers.
