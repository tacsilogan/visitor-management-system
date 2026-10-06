# Campus pins from GPS and walking routes

How an administrator places departments and gates by standing at them, and records the
walking routes visitors should take, in the admin dashboard's **Campus Map**. Database
change: `phone_tracker/campus_routes_migration.sql` (import after
`visitor_arrival_migration.sql`; safe to run again; a Railway deploy applies it by itself).

## Pin at my location

Guessing a building on the map is often off by 10–30 m. Standing at the spot is better.

- **Offices tab:** choose the department, stand at its entrance, and press
  **Pin at my location**. The page collects GPS readings and averages the accurate ones
  (±8 m or better). It places the pin after 5 of those, or press **Use this location**
  to take the best so far. Then press **Save campus map**.
- **Gates tab:** type the gate's name, stand at the gate, and press **Add gate at my location**.
- Each list shows how a pin was placed: "GPS ±4 m" or "Placed on map". Dragging a pin
  turns it back into "Placed on map".
- Removing a pin asks first, and says what visitors get instead: the end of the
  department's walking route, or no directions at all when it has no route.
- A pin must be inside the campus boundary. After redrawing the boundary, move any pin left
  outside it (drag it, or use **Pin at my location**); the save says which one.

**Set up in this order:** boundary, gates, office pins, then routes. Each step uses the one
before it: pins and gates must be inside the boundary, routes start at a gate, and the pin
is the visitor's destination, where "You have arrived" is said.

## Routes

A route is the line a visitor should walk from a start (a gate, or the guard house) to one
department: through doors and around buildings, never through a wall. A department can
have several routes, for example one from each gate.

**Record by walking (phone):**

1. In the **Routes** tab, choose the destination department, stand at the start, and press
   **Record by walking**. On a phone the map and controls fill the screen.
2. Walk the way a visitor should. The line follows you. Readings worse than ±20 m and
   sudden GPS jumps are skipped. **Pause** while waiting at a door; **Undo last 10 m**
   after a wrong turn.
3. At the department's entrance, press **I've arrived**.
4. **Review:** the cleaned line appears over the raw GPS track (dashed), with the distance,
   walking time, and any warnings. Name the start (gates are suggested). For a walked route
   you can also move the department's pin to where you stopped. Press **Save route**.

**A department without a pin gets one from its route.** Saving a route, walked or drawn,
places the department's pin where the route ends, and the message says so. The exception is
a route that ends outside the campus boundary: the route is saved, and the message asks you
to place the pin at the door inside the boundary.

**Draw on the map (laptop, or to correct a route):** click along the walkway from the start
to the department. Drag a point to move it, click a point to remove it, and click the line
to add a point. **Reverse direction** swaps the start and the end. From the review,
**Adjust points** opens a walked route for corrections.

The list groups routes by department. Use **Show** to zoom to a route, plus **Edit** and
**Delete**. The coverage line at the top shows which departments still need a route, and
flags in orange a department that has a route but no pin ("⚠ IT Department · no pin").

**Keep the screen on while recording.** Phones stop sharing a web page's location when the
screen sleeps. The page asks the browser to keep the screen on, and it saves the unfinished
route every few seconds. After a reload, **Continue** brings the route back, paused.

**Review warnings:**

- the route ends more than 15 m from the department's pin;
- it starts more than 40 m from a gate;
- the GPS was weak, averaging worse than ±10 m;
- points lie more than 40 m outside the boundary. A route like this cannot be saved.

**Server limits:**

- 2–2000 points;
- every point inside the boundary or within 40 m of it;
- no gap over 500 m between points;
- 2 m to 5 km long;
- 20 routes per department.

## GPS needs HTTPS

Browsers share the location only with HTTPS pages or `localhost`.

- **Railway (HTTPS):** works on any phone.
- **On the laptop:** use **Draw on the map**. To simulate walking, open Chrome DevTools →
  ⋮ → More tools → **Sensors** → Location.
- **Phone over local Wi-Fi** (`http://192.168.x.x`): Chrome blocks GPS there. For testing
  only, open `chrome://flags/#unsafely-treat-insecure-origin-as-secure` on the phone, add
  `http://<laptop-ip>`, and relaunch Chrome.

## Data

- **`campus_routes`:** one row per route.
  - `office_code`, `name`, `start_label`
  - `method` (`walked` or `drawn`)
  - `points_json`: `[[lat, lng], …]`, ordered from the start to the department
  - `distance_meters`, `duration_seconds`, `average_accuracy_meters`
  - who saved it, and when

  Deleting a department deletes its routes.
- **`campus_places`:** gains `placed_by` (`map` or `gps`) and `accuracy_meters`.
- **Activity log entries:** "Updated the campus map", "Added a walking route",
  "Edited a walking route", "Removed a walking route".
- **Permissions:** administrators can add, edit, and delete routes. Security can read them
  through `campus_routes.php`.

## In the visitor app

From app version 0.4.0, `api/v1/campus_map.php` sends every route (`walking_routes`), and
the app guides visitors along them instead of pointing in a straight line
(`VISITOR_NAVIGATION.md`, **Walking routes**):

- All routes are joined into one walkway network, but only where they really meet: where
  they cross, run side by side along the same walkway, start at the same gate, or where one
  ends right on another. Routes on either side of a building are never joined.
- The visitor sees a blue line with arrows from where they stand to the office, and hears
  each turn ahead ("In 25 meters, turn left") and at the turn ("Turn left now"), plus "You're
  off the path" and "You're going the wrong way" when needed.
- One route helps more than its own office: going from one office to the next, or out with
  **Guide me out** (to the gate nearest on foot), follows the routes that meet.
- An office without a route keeps the dotted straight pointer, and arrival is still judged
  at the office pin.
- **A route alone is enough.** An office with a route but no pin is placed where its newest
  route ends (`location_source: "route"` in the stop), so visitors are still guided there.
  The readiness check lists such offices so the pin can be placed at the door.
- A route's named start ("Main Gate") that no gate pin marks is sent as a gate too, so
  **Guide me out** can lead back along the route.
- New or edited routes and pins reach visitors within a minute: the app reloads the campus
  map every minute during a visit, with no app update.

## Files

| File | Purpose |
|---|---|
| `phone_tracker/campus_gps.js` | GPS sampling for pins, the route recorder, path math |
| `phone_tracker/campus_setup.js` | Campus Map editor: boundary, gates, office pins, GPS pins |
| `phone_tracker/campus_routes_editor.js` | Routes tab: record, draw, review, edit, list |
| `phone_tracker/campus_routes.php` | List routes (GET) |
| `phone_tracker/admin_save_campus_route.php` | Add or edit a route (POST, administrators) |
| `phone_tracker/admin_delete_campus_route.php` | Delete a route (POST, administrators) |
| `phone_tracker/campus_map_service.php` | Loading pins and routes; boundary math |
| `phone_tracker/campus_routes_migration.sql` | Routes table and pin placement columns |
