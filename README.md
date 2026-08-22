# ownrun-server

PHP API for OwnRun. Deploy the contents of this repo so that `api/` is served at `/api` on your domain.

Does **not** require `mod_rewrite`. The front controller is `api/index.php`; routes are PATH_INFO (`/api/index.php/jobs`).

## Requirements

- PHP 8.1+
- MariaDB / MySQL
- Apache (PATH_INFO on `.php` files; typical default)

## Setup

1. Copy `config.example.php` to `config.php` and fill in database credentials and JWT secret.
2. Create the database and grant access to the configured user.
3. Run setup:

```bash
php scripts/setup.php --username=admin --password=your-password
```

Save the runner token printed by setup — you need it for `ownrun-runner`.

4. Deploy:
   - `api/` → web root `/api/`
   - `config.php` → outside web root if your host allows; otherwise keep it one level above `api/` (the default layout).

## API

All endpoints are under `/api/index.php/...`. Authenticated web requests use `Authorization: Bearer <access_jwt>`. Runner requests use `Authorization: Bearer <runner_token>`. Clients also send `X-OwnRun-Authorization` because some hosts strip the standard header.

See the design document for the full endpoint list.

## Development

For local testing with PHP's built-in server, run from the repo root:

```bash
php -S localhost:8080 -t api api/index.php
```

Then use `http://localhost:8080` as the API base (paths like `/auth/login` — no `/api` prefix when using the built-in server directly). Apache local layout uses `http://localhost/ownrun-server/api/index.php/...`.
