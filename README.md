# Listing Import

Import listings into Shopclass from other systems: a keyed REST API, and scheduled JSON,
CSV and RSS feeds. Every listing goes through the same checks as one posted by hand:
moderation, spam checks, listing limits and expiry.

> **Status: in development.** The push API, pulled feeds, images, the admin screens, the
> command line and the background queue work. The full API reference and docs come next.

## Requirements

- Shopclass 6.4.0 or later
- PHP 8.0 or later

## Install

From the admin: **Plugins → Manage plugins → Browse**, find *Listing Import*, then
**Install**. Or from the command line:

```bash
php oc-cli.php market:install listing-import
```

## Check that it works

```bash
curl https://example.com/api/v1/ping
```

With friendly URLs off, use the query form:

```bash
curl "https://example.com/index.php?page=route&route=listing-import-api&path=ping"
```

Both answer:

```json
{"data":{"plugin":"listing-import","version":"0.1.0"}}
```

## Tests

Every test runs on its own, with no database and no running site:

```bash
./tests/run.sh
```

## Licence

GPL-3.0-or-later. See `LICENSE`.
