# Visitor map and campus-exit handoff

The Android visitor app opens `TrackingMapScreen.kt` automatically after Security changes
an appointment to `checked_in`. It shows the live campus map with walking directions and
voice prompts to the visitor's office; the full behaviour is in `VISITOR_NAVIGATION.md`.

## Backend endpoint (done)

`GET /phone_tracker/api/v1/campus_map.php?appointment_id={id}` (Bearer, the visitor's own
appointment; any stop of a multi-office visit) returns:

```json
{
  "appointment_id": 12,
  "campus_configured": true,
  "campus_updated_at": "2026-10-04 13:47:05",
  "campus_boundary": [
    { "latitude": 10.7159413, "longitude": 122.5661373 }
  ],
  "gates": [
    { "name": "Main Gate", "latitude": 10.7160000, "longitude": 122.5660000 }
  ],
  "destination": {
    "appointment_id": 12,
    "office_code": "IT",
    "label": "IT Department",
    "location": "CCI building, second floor, room 201",
    "description": "",
    "latitude": 10.0000000,
    "longitude": 122.0000000,
    "status": "checked_in",
    "visit_type": "appointment",
    "scheduled_start_at": "2026-10-04 10:00:00",
    "scheduled_end_at": "2026-10-04 10:30:00",
    "arrived_at": null,
    "arrival_method": null
  },
  "stops": [],
  "exit_policy": {
    "minimum_accuracy_meters": 50,
    "outside_confirmation_points": 3,
    "outside_confirmation_seconds": 300,
    "boundary_buffer_meters": 25
  },
  "arrival_distance_meters": 3,
  "arrival_max_accuracy_meters": 8,
  "arrival_radius_meters": 3,
  "server_time": "2026-10-04 10:05:12"
}
```

An arrival is confirmed only when GPS places the visitor within `arrival_distance_meters`
of the office pin from readings accurate to `arrival_max_accuracy_meters`, or when the
visitor taps **I'm here**; the app reports it to `POST /api/v1/arrival.php`
(`VISITOR_NAVIGATION.md`). `arrival_radius_meters` repeats the distance for app 0.3.0.

The boundary, gates, and office pins come from the administrator's **Campus map setup**,
and `location`/`description` from the **Department Directory**, so the app needs no update
when a pin moves. An office without a pin has `null` coordinates. `destination` is the
first stop still to visit; once every stop is done it is `null` and the app guides the
visitor to a gate. `stops` lists every stop of a visit.

## Android map (done)

Decided on 2026-10-04: MapLibre (`org.maplibre.gl:android-sdk`) with OpenFreeMap tiles,
which need no API key, account, or billing and use the same OpenStreetMap data as the
Security dashboard. `ISATU_MAP_STYLE_URL` in `visitor_app/local.properties` can point the
app at another MapLibre style later (for example MapTiler with an institutional key).

The visitor map displays:

- the visitor's current position and heading;
- the destination office pin (and, for a multi-office visit, the other stops, numbered);
- gate pins;
- the campus boundary, with the outside dimmed; and
- tracking and campus-exit status.

The visitor's historical trail is still never shown on this screen; route history stays
with authorized Security/Admin. There is still no suggested route: the dotted line from
the visitor to the office is a straight pointer, and the screen tells visitors to follow
walkways and signs.

## Server-authoritative campus exit (done)

`phone_tracker/api/v1/locations.php` checks every GPS point against the boundary, with a
25 m buffer for drift at gates and
building edges. A single outside reading never ends a visit: an exit is confirmed by at
least three accurate (≤ 50 m) readings outside, the first one five or more minutes ago.

A confirmed exit then, in one transaction:

1. marks the visit `completed` (`checkout_method = left_campus`) with an exit-specific
   history note and audit entry, timed at the moment the visitor stepped outside;
2. ends the tracking session with `ended_reason = 'campus_exit'`;
3. notifies the visitor; and
4. makes later uploads return 409, so the app stops tracking.

Outside coordinates are never stored: they only update the inside/outside state in
`visitor_presence`. The app warns the visitor on the same rule (same buffer and accuracy)
and counts down the five minutes. Rules and file names are in `LIVE_MONITORING.md`.
