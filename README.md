# Neighborhood Cut-through Analysis Tool (N-CAT)

## Pages
- `login.php`: Username/password entry point.
- `logout.php`: Ends the session.
- `index.php`: Home page with site + checkpoint launch links.
- `entry.php`: Checkpoint-locked vehicle entry form.
- `dashboard.php`: Live dashboard (10s polling by default).
- `details.php`: Matched cut-through details with local arrivals/departures breakdown.
- `downloads.php`: Centralized download page for AM+PM PDF report + CSV exports.
- `setup.php`: Admin-only page for site setup, collector accounts, checkpoint assignments, and matching settings.

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
- Data collectors are assigned per checkpoint in Site Setup and auto-attached to event records in Data Entry.
- Checkpoint codes are auto-numbered per site (`1`, `2`, `3`, ...); admins only set display names.
- Data Entry includes duplicate/ambiguous-plate warning prompts before final save.

## APIs
- `api/submit_event.php`
- `api/dashboard_data.php`
- `api/site_context.php`
- `api/save_setup.php`
- `api/list_distances.php`
- `api/export_events_csv.php` (exports all events for selected site + study period/date)
- `api/export_matches_csv.php` (exports matched cut-through rows for selected site + study period/date)

## Database
- Auto-bootstrap runs when pages/APIs load.
- Manual schema is in `sql/schema.sql`.
- Demo seed script is in `sql/seed_seeded_site.sql` (creates/activates `Seeded Site` with randomized AM/PM data).
- Tables used by this app:
  - `app_settings`: Global config values (speed, buffer, confidence, poll interval, policy threshold).
  - `users`: Login accounts (`admin` and `collector` roles).
  - `sites`: Each study site (name, active flag, optional uploaded image path).
  - `checkpoints`: Checkpoint definitions per site (code, display name, type).
  - `checkpoint_assignments`: Which collector can record at which checkpoint.
  - `checkpoint_distances`: Distance in miles for each `from -> to` checkpoint pair.
  - `traffic_events`: All captured observations (In/Out, plate/type/color, checkpoint, timestamp, notes, user owner).
- DB credentials are read from env vars if present:
  - `THRU_DB_HOST`
  - `THRU_DB_USER`
  - `THRU_DB_PASS`
  - `THRU_DB_NAME`
- Optional auth seed env vars:
  - `THRU_APP_ADMIN_USER` (default: `admin`)
  - `THRU_APP_ADMIN_PASSWORD` (default: `T-CAT2026`)
- Optional app timezone env var:
  - `THRU_APP_TIMEZONE` (default: `America/New_York`)

## Access Control
- The app is session-protected with role-based authorization.
- Protected pages redirect to `login.php` when not authenticated.
- API endpoints return `401 Unauthorized` when not authenticated.
- `setup.php` and `api/save_setup.php` are admin-only.
- Collector users can only submit/view/edit/delete events for assigned checkpoints.
- Collectors can only edit/delete their own events; admins can edit/delete any event.
- Write APIs are CSRF-protected (`setup`, `entry submit`, duplicate check, and entry edit/delete).
- Site-level read access is enforced in APIs (collectors can only read assigned sites; dashboard viewer can only read the active site).
- Optional dashboard viewer mode is available with a shared access code (no username); this mode is read-only and restricted to `dashboard.php` for the active site.

## Notes
- Works on shared PHP/MySQL hosting.
- Uploads are stored in `uploads/site-images`.
- To load demo data quickly in phpMyAdmin: run `sql/seed_seeded_site.sql`, then open Dashboard.
- Formal PDF report is generated client-side from live report data using `jsPDF` + `AutoTable` CDN scripts.
