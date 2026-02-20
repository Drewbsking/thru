# Traffic Study Tool (Redesign)

## Pages
- `index.php`: Home page with site + checkpoint launch links.
- `entry.php`: Checkpoint-locked vehicle entry form.
- `dashboard.php`: Live dashboard (10s polling by default).
- `details.php`: Matched cut-through details and CSV export.
- `setup.php`: Site image upload, checkpoints, distances, and matching settings.

## Core Rules
- Cut-through matching uses `expected_minutes = (distance_miles / speed_mph) * 60`.
- Match window is `expected_minutes ± buffer_minutes`.
- Matching uses confidence score (plate similarity + vehicle type/color).
- One-to-one pairing: each event can be matched once.
- Unmatched `In` = local arrival (destination), not cut-through.
- Unmatched `Out` = local departure (origin), not cut-through.
- `Total Volume` is deduped: matched pairs count once; unmatched events count once.
- Policy status is based on `cut_through_percent >= 25` (configurable in setup).

## APIs
- `api/submit_event.php`
- `api/dashboard_data.php`
- `api/site_context.php`
- `api/save_setup.php`
- `api/list_distances.php`

## Database
- Auto-bootstrap runs when pages/APIs load.
- Manual schema is in `sql/schema.sql`.
- DB credentials are read from env vars if present:
  - `THRU_DB_HOST`
  - `THRU_DB_USER`
  - `THRU_DB_PASS`
  - `THRU_DB_NAME`

## Notes
- Works on shared PHP/MySQL hosting.
- Uploads are stored in `uploads/site-images`.
