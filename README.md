# WP Data Migration

WordPress plugin that exposes a REST API webhook for exporting database table data with pagination. Designed for migrating data between WordPress installations.

## Installation

1. Copy the `wp-data-migration` folder into `wp-content/plugins/`
2. Activate the plugin in **WP Admin → Plugins**
3. Set your webhook password in `references.php`:
   ```php
   define('WP_MIGRATION_WEBHOOK_PASSWORD', 'your-secret-password');
   ```

## Configuration

All configuration is in `references.php`:

| Constant | Description | Default |
|----------|-------------|---------|
| `WP_MIGRATION_WEBHOOK_PASSWORD` | Password required for every API request | `pass123` |
| `WP_MIGRATION_PER_PAGE` | Default number of records per page | `500` |
| `WP_MIGRATION_TABLES` | Array of allowed table names (whitelist) | See below |

### Allowed Tables

Only tables listed in `WP_MIGRATION_TABLES` can be exported. By default:

- `wp_frm_easypost_shipment_addresses`
- `wp_frm_easypost_shipment_history`
- `wp_frm_easypost_shipment_label`
- `wp_frm_easypost_shipment_parcel`
- `wp_frm_easypost_shipment_rate`
- `wp_frm_easypost_shipments`
- `wp_frm_fields`
- `wp_frm_forms`
- `wp_frm_item_metas`
- `wp_frm_items`
- `wp_frm_midigator_preventions`
- `wp_frm_midigator_rdr`
- `wp_frm_midigator_resolve_history`
- `wp_frm_midigator_resolves`
- `wp_frm_payments`
- `wp_frm_payments_archive`
- `wp_frm_payments_authnet`
- `wp_frm_payments_failed`
- `wp_frm_refunds_authnet`
- `wp_users`

To add a new table, append its full name (with `wp_` prefix) to the `WP_MIGRATION_TABLES` array in `references.php`.

## API Endpoint

```
GET /wp-json/wp-data-migration/v1/export
POST /wp-json/wp-data-migration/v1/export
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `password` | string | Yes | Must match `WP_MIGRATION_WEBHOOK_PASSWORD` |
| `table` | string | Yes | Table name **without** `wp_` prefix |
| `page` | int | No | Page number (default: `1`) |
| `per_page` | int | No | Records per page (default: `500`, max: `5000`) |

### Example Requests

**Export Formidable forms (first page, 500 records):**
```
GET https://yoursite.com/wp-json/wp-data-migration/v1/export?password=pass123&table=frm_forms
```

**Export users with custom pagination:**
```
GET https://yoursite.com/wp-json/wp-data-migration/v1/export?password=pass123&table=users&page=2&per_page=100
```

**Export payment records:**
```
GET https://yoursite.com/wp-json/wp-data-migration/v1/export?password=pass123&table=frm_payments&page=1&per_page=1000
```

### Success Response

```json
{
  "data": [
    {
      "id": "1",
      "column_name": "value",
      "another_column": "another_value"
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 500,
    "total": 1234,
    "total_pages": 3
  }
}
```

All columns are returned as-is without any transformation.

### Error Responses

**401 — Unauthorized** (missing or wrong password):
```json
{
  "ok": false,
  "error": "Unauthorized"
}
```

**400 — Missing table parameter:**
```json
{
  "ok": false,
  "error": "Missing required parameter: table"
}
```

**403 — Table not in whitelist:**
```json
{
  "ok": false,
  "error": "Table not allowed: wp_some_table"
}
```

**404 — Table not found in database:**
```json
{
  "ok": false,
  "error": "Table not found in database: wp_some_table"
}
```

## Pagination Workflow

To export an entire table, loop through pages until `page` reaches `total_pages`:

```bash
PAGE=1
while true; do
  RESPONSE=$(curl -s "https://yoursite.com/wp-json/wp-data-migration/v1/export?password=pass123&table=frm_items&page=$PAGE&per_page=500")

  # Save or process $RESPONSE
  echo "$RESPONSE" > "frm_items_page_${PAGE}.json"

  TOTAL_PAGES=$(echo "$RESPONSE" | jq '.pagination.total_pages')

  if [ "$PAGE" -ge "$TOTAL_PAGES" ]; then
    break
  fi

  PAGE=$((PAGE + 1))
done
```

## Plugin Structure

```
wp-data-migration/
├── wp-data-migration.php              # Main entry point
├── references.php                     # Password, tables whitelist, settings
├── classes/
│   ├── WpDataMigrationInit.php        # Bootstrap loader
│   └── helpers/
│       └── WpDataMigrationHelper.php  # Export logic
├── webhooks/
│   └── WpDataMigrationWebhook.php     # REST route registration
└── logs/
```

## Security

- Every request requires the correct `password` parameter
- Only whitelisted tables in `WP_MIGRATION_TABLES` can be exported
- Table names are sanitized with `sanitize_text_field()`
- Table existence is verified before querying
- All queries use `$wpdb->prepare()` for parameterized values
- **Change the default password** in `references.php` before deploying to production
