# xcsoar-notam-proxy

PHP proxy for FAA NOTAM data with optional caching and delta responses.

## Usage

GET:
```
/notam.php?locationLongitude=a&locationLatitude=b&locationRadius=c
```

Required:
- `locationLongitude` (alias: `lon`) = longitude of search center (decimal point)
- `locationLatitude` (alias: `lat`) = latitude of search center (decimal point)
- `locationRadius` (alias: `radius`) = search radius in nautical miles (0-100)

## Delta mode (optional)

POST JSON body with known IDs and lastUpdated timestamps:
```json
{"known":{"NOTAM_ID":"lastUpdated","NOTAM_ID_2":"lastUpdated"}}
```

Response contains only new/changed items plus `removedIds`.

## Response format

Top-level fields mirror the FAA API:
- `pageNum`
- `totalCount`
- `totalPages`
- `items` (GeoJSON Feature list)

Delta mode adds:
- `delta` (boolean)
- `removedIds` (array of NOTAM IDs no longer present)

## Environment variables

- `DB_SERVER`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `FAA_ID`
- `FAA_SECRET`
- `FAA_API_BASE` (default: `https://api-nms.aim.faa.gov/nmsapi`)
- `FAA_AUTH_URL` (default: `https://api-nms.aim.faa.gov/v1/auth/token`)
- `NMS_RESPONSE_FORMAT` (`GEOJSON` or `AIXM`, default: `GEOJSON`)
- `APP_ENV` (`production` by default; set to `debug` to expose error details)

Apache users can copy `.htaccess.example` to `.htaccess` and fill in real values.
+**Do not commit `.htaccess` with real credentials.**

## Database schema

Run this in the target database defined by `DB_NAME`:

```sql
CREATE TABLE notam_cache (
  cache_key VARCHAR(128) NOT NULL,
  cache_value LONGTEXT NOT NULL,
  expiration DATETIME NOT NULL,
  PRIMARY KEY (cache_key),
  KEY idx_expiration (expiration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
