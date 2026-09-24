# Changelog

## 0.1.0

### New
- API keys: an admin makes, rotates and revokes them under **Plugins → Listing import: API keys**. The secret is shown once and stored only as a hash.
- Every endpoint but ping needs a key with the right permission, and each key is limited to a number of requests a minute.
- An address that fails the key check 20 times in 15 minutes is refused for a while.
- `POST /api/v1/listings` imports one record: it creates a listing, updates the one it made before, or changes nothing when the record is the same.
- Records are placed on the site: category by id, slug, path or name; country, region and city by name; owner by id or e-mail; prices such as "1.234,50" read correctly.
- Imported listings go through core's own listing save, so validation, spam checks, expiry and custom fields apply. The site's moderation and listing limits apply too.
- Images: each address is downloaded only if every IP its host resolves to is public, on the normal port, over http or https, redirects included. At most 8 MB, and only JPEG, PNG, GIF or WebP.
- An update adds only images the listing does not already have, compared by content, not by address. An image that fails is a warning; the listing is still imported.
- Downloaded images nothing took are swept from the temp folder after two hours.
- `POST /api/v1/listings:batch` takes up to 200 records and answers at once; each record becomes one job in core's background queue, so core retries it and **Tools → Background jobs** shows it.
- `GET /api/v1/runs/{id}` says how a run went: its counts, whether it is finished, and each failed record with its reasons. A key sees only its own source's runs.
- `php oc-cli.php import:run --file=` imports a JSON or NDJSON file at once, with `--dry-run` to change nothing; `import:status` lists recent runs; `import:key:create` makes a key.
- Pulled feeds: a source can fetch a JSON, NDJSON or CSV feed, or another Shopclass site's RSS, every so many minutes. Field names that differ from ours are mapped one per line.
- A listing whose record leaves its feed is deactivated, never deleted, and comes back when the record returns. A feed where most records fail deactivates nothing.
- Sources are managed under **Plugins → Listing import: sources**: a list, an editor core draws from the plugin's declaration, a preview of what a feed would import, and Fetch now.
- A feed address must pass the same public-address check as images, and a feed declaring an XML entity is refused before it is parsed.
- Import log lines older than the retention setting are deleted once a day.
- Every request is a run with its own log lines, and a record that fails names each problem by its field.
- The plugin installs its five tables through core's migration runner and removes them on uninstall.
- `PUT`, `GET` and `DELETE /api/v1/listings/{external_id}` replace, read and delete one listing by your id.
- `GET /api/v1/openapi.json` describes the whole API in OpenAPI 3.1.
- `GET /api/v1/ping` answers with the plugin's version.
- A settings page for the request limit per key and how long import logs are kept.
