# Changelog

## 0.1.0

### New
- API keys: an admin makes, rotates and revokes them under **Plugins → Listing import: API keys**. The secret is shown once and stored only as a hash.
- Every endpoint but ping needs a key with the right permission, and each key is limited to a number of requests a minute.
- An address that fails the key check 20 times in 15 minutes is refused for a while.
- `POST /api/v1/listings` imports one record: it creates a listing, updates the one it made before, or changes nothing when the record is the same.
- Records are placed on the site: category by id, slug, path or name; country, region and city by name; owner by id or e-mail; prices such as "1.234,50" read correctly.
- Imported listings go through core's own listing save, so validation, spam checks, expiry and custom fields apply. The site's moderation and listing limits apply too.
- Every request is a run with its own log lines, and a record that fails names each problem by its field.
- The plugin installs its five tables through core's migration runner and removes them on uninstall.
- `GET /api/v1/ping` answers with the plugin's version.
- A settings page for the request limit per key and how long import logs are kept.
