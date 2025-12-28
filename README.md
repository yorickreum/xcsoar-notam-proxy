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
- `locationRadius` (alias: `radius`) = search radius [units?]

Optional:
- `pageSize` = number of items per page (default/max 1000)

## Delta mode (optional)

POST JSON body with known IDs and lastUpdated timestamps:
```json
{"known":{"NOTAM_ID":"lastUpdated","NOTAM_ID_2":"lastUpdated"}}
```

Response contains only new/changed items plus `removedIds`.

## Response format

Top-level fields mirror the FAA API:
- `pageSize`
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
- `FAA_KEY`
