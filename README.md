# Neighborhood Cut-through Analysis Tool (N-CAT)

## Pages
- `login.php`: Password-protected entry point.
- `logout.php`: Ends the session.
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
- Dashboard and details filter by study period: `Morning Study` or `Afternoon Study` for the selected day.
- `Data Collector Name` is configured once in Site Setup and auto-attached to event records in Data Entry.

## APIs
- `api/submit_event.php`
- `api/dashboard_data.php`
- `api/site_context.php`
- `api/save_setup.php`
- `api/list_distances.php`

## Database
- Auto-bootstrap runs when pages/APIs load.
- Manual schema is in `sql/schema.sql`.
- Tables used by this app:
  - `app_settings`: Global config values (speed, buffer, confidence, poll interval, policy threshold).
  - `sites`: Each study site (name, active flag, optional uploaded image path).
  - `checkpoints`: Checkpoint definitions per site (code, display name, type).
  - `checkpoint_distances`: Distance in miles for each `from -> to` checkpoint pair.
  - `traffic_events`: All captured observations (In/Out, plate/type/color, checkpoint, timestamp, notes).
- DB credentials are read from env vars if present:
  - `THRU_DB_HOST`
  - `THRU_DB_USER`
  - `THRU_DB_PASS`
  - `THRU_DB_NAME`

## Access Control
- The app is now password-protected with PHP sessions.
- Protected pages redirect to `login.php` when not authenticated.
- API endpoints return `401 Unauthorized` when not authenticated.
- Default password is `change-me-now` unless `THRU_APP_PASSWORD` is set in hosting env.
- Change password in `setup.php` under **Access Password** after first login.

## Notes
- Works on shared PHP/MySQL hosting.
- Uploads are stored in `uploads/site-images`.
