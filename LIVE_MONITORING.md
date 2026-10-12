# Live visitor monitoring and gate check-out

How Security follows checked-in visitors on the campus map, how a visit ends, and the
privacy rules for GPS. Database changes are in
`phone_tracker/live_monitoring_migration.sql` (import after
`personnel_directory_migration.sql`; safe to re-run).

## Scan in, scan out

- **First scan** of a visitor pass checks the visitor in (unchanged).
- **Second scan** of the same pass shows a check-out card: time on campus, who checked them
  in, and warnings (for example an office stop not visited yet). One tap on
  **Check out visitor** records the time out and the guard's name, stops GPS tracking, and
  notifies the visitor.
- A scan within **2 minutes** of check-in is treated as an accidental repeat and never
  checks anyone out.
- After check-out the pass is used up; scanning it again says how and when the visit ended.
- **End visit** (dashboard table or visitor card) is for exceptions, such as a lost phone.
- A visit to several offices checks out as one visit. Stops not visited yet are cancelled
  and their offices are told the visitor left.

A checked-in visit no longer ends when its booked slot ends. It ends in one of four ways,
stored in `checkout_method` on the appointment (or the visit, for several offices):

| `checkout_method` | When | Shown as |
|---|---|---|
| `scan` | Second scan at the gate | Checked out |
| `guard` | **End visit** button | Ended by guard |
| `left_campus` | Confirmed campus exit (below) | Left campus |
| `end_of_day` | Still open when its day ended (23:59:59, or its scheduled end if later) | Ended at day end |

Visitors still inside after their slot show **Past slot** (first 30 minutes) and then a red
**Overstay** badge on the dashboard and the map.

## Privacy: the campus boundary

The boundary is the polygon an administrator draws in **Campus map setup**.

- Positions **outside** the boundary are never stored or shown. The server keeps only the
  time the visitor stepped outside (`visitor_presence`), never where they went.
- A reading up to **25 m** outside the line still counts as inside (GPS drift at gates).
- Readings less accurate than **50 m** never move a visitor or change their state.
- When a visitor steps outside they disappear from the map at once and are listed as
  **Outside campus**.
- **Confirmed exit:** at least **3** accurate readings outside, the first one **5 minutes** or
  more ago. The visit then ends as `left_campus` at the moment they stepped outside, the
  tracking session ends with `ended_reason = campus_exit`, and the next upload gets 409 so
  the app stops. Exits are also checked whenever the dashboards refresh, so a phone that
  stops sending after leaving is still handled.
- Without a saved boundary nothing is filtered, and the map says so.

## What Security sees (Visitor Monitoring)

- **Panel:** visitors on campus and visits that ended today, counts by status
  (live ≤ 45 s, stale ≤ 3 min, offline, waiting for GPS, outside, overstaying), and search.
- **Markers:** an arrow pointing the way the visitor is walking, worked out from their last
  positions (phones do not send a direction); a dot while standing still. Markers glide
  to new positions every 5 seconds. Labels hide when more than 15 visitors are shown.
- **Visitor card:** destination and stops, purpose, who checked them in, time on campus,
  booked slot, last update and accuracy, distance walked, arrivals, **Follow**, **Show on map**,
  and **End visit**.
- **Arrivals:** only what the visitor's phone confirmed (`VISITOR_NAVIGATION.md`): GPS within
  3 m of the office pin with an accurate reading, or the visitor tapping **I'm here**. The list
  shows a green **Arrived** badge, the card says how it was confirmed ("confirmed by GPS
  (±4 m)" or "confirmed by the visitor"), and the map shows an arrival pin. A path that only
  passed within 30 m of an office shows "Passed near … Arrival not confirmed".
- **Walked path:** orange line from a green start to the current position (or a red end
  point), with arrows every 40 m. Lost signal (a jump over 120 m or no reading for
  5 minutes) is never drawn as a straight line.
- **Steady positions:** the visitor app (from version 0.5.0) and the visitor web page take a
  reading every few seconds, leave out GPS jumps and weak readings, and send the steadied
  position once per upload interval (`visitor_app/.../navigation/PositionFilter.kt`,
  `phone_tracker/gps_filter.js`), so markers and walked paths no longer jump to places the
  visitor never was. Positions already stored, and uploads from older app versions, are
  shown as they were sent.
- On phones the panel is a pull-up sheet.

The map pauses updates while the browser tab is in the background.

## The staff maps (Security and Admin)

Visitor Monitoring, the admin's Route Analytics map, and Campus Map setup share these
features (`phone_tracker/campus_map.js`):

- **Zoom down to level 22**, about 4 cm of ground per screen pixel, in half steps. The map is
  OpenFreeMap (the same map as the visitor app), drawn from shapes, so buildings and labels
  stay sharp at every zoom. A browser without WebGL, or one that can't reach OpenFreeMap,
  gets OpenStreetMap images instead; past zoom 19 those are enlarged and look soft.
- **Full view:** the expand button under the zoom buttons fills the browser window. Visitor
  Monitoring keeps its visitor panel, and Campus Map setup keeps its tools beside the map.
  Press the button again or **Esc** to return. On Route Analytics the mouse wheel zooms only
  in full view, so it never hijacks page scrolling. For no browser bars at all, also press F11.
- **Scale bar** in meters and feet (bottom right), for judging distances such as the 3 m
  arrival rule.
- **Office and gate names** appear on the map from zoom 18 on; further out they show on hover.

The map libraries load from unpkg (Leaflet 1.9.4, MapLibre GL 5.24.0, and the
maplibre-gl-leaflet 0.1.4 bridge), like Leaflet already did.

## Files

| File | Purpose |
|---|---|
| `phone_tracker/presence_service.php` | Inside/outside rules and every way a visit ends (`gate_checkout()`) |
| `phone_tracker/monitoring_service.php` | Live map entries, direction of travel, walked paths, scanner check-out card |
| `phone_tracker/security_live_locations.php` | Live map data (`locations.view_live`) |
| `phone_tracker/security_visitor_trail.php` | One visitor's walked path, `after_id` for live updates (`routes.view`) |
| `phone_tracker/scan_checkout.php` | Confirms a check-out offered by the second scan (`visits.complete`) |
| `phone_tracker/api/v1/campus_map.php` | Boundary, gates, destination, and exit rules for the visitor app's map (`VISITOR_NAVIGATION.md`) |
| `phone_tracker/api/v1/locations.php`, `save_location.php` | GPS uploads; apply the boundary rules |
| `phone_tracker/appointment_maintenance.php` | Runs the confirmed-exit and end-of-day checks on every dashboard refresh |

Every check-out and boundary crossing is written to `audit_logs` and appears in the admin's
**Security → User activity** (Gate & visits). The limits above are constants at the top of
`presence_service.php` and `monitoring_service.php`.
