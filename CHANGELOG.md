# Changelog

## 0.1.0

### New
- API keys: an admin makes, rotates and revokes them under **Plugins → Listing import: API keys**. The secret is shown once and stored only as a hash.
- Every endpoint but ping needs a key with the right permission, and each key is limited to a number of requests a minute.
- An address that fails the key check 20 times in 15 minutes is refused for a while.
- `POST /api/v1/listings` checks a record against the v1 format and names every problem by its field.
- The plugin installs its five tables through core's migration runner and removes them on uninstall.
- `GET /api/v1/ping` answers with the plugin's version.
- A settings page for the request limit per key and how long import logs are kept.
