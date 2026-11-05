# Directions inside a building

GPS can lead a visitor to a building's door, but not up its stairs. An office may be on the
second floor, so the trip has two parts: the map and voice lead to the building, then the
administrator's words and photos lead to the office door. Only the visitor's tap counts as
arrived. Database change: `phone_tracker/indoor_directions_migration.sql` (safe to run again).
The hosted server imports it by itself.

## What the administrator writes

**Campus Map > Inside**, one department at a time. Only administrators can open it.

- **Where it is:** building, floor, and room (optional). Saving also sets the department's
  location in the Department Directory, so every screen names the office the same way. Once a
  department has them, its location is changed here and no longer in the Department dialog.
- **Description for visitors** (160 characters): shown and read aloud when they reach the
  building.
- **Steps inside the building** (optional, up to 8): one short sentence each, from the building
  door to the office door, with an optional photo. **Add a step** drafts a sentence from the
  fields above; **Copy from** starts from another department in the same building.

The phone beside the form shows what the visitor will see, and **Hear it read aloud** reads it.
A department shows as ready when it has a building, a floor, and a description or steps. Photos
are shrunk in the browser to 1280 pixels (which also removes their hidden location data) and sent
with **Save directions**, so an abandoned edit leaves nothing on the server. They are kept in
`uploads/directions`, on the persistent `/data` volume when hosted. Hold the phone sideways:
the app shows photos at 4:3.

With nothing written, an office still works: GPS leads to its pin, and the visitor taps there.

## The visitor's trip

| Where | What happens |
|---|---|
| **The pass** | The summary card adds the building, floor and room. **How to find it** opens the steps (or the description) to read ahead of time; nothing is reported from there. They are saved on the phone when the pass opens, photos included. The booking form shows the location too. |
| **Walking** | The same map, blue line and voice, but they lead to the building: "Heading to Tech Support, in the CCI Building, 2nd floor. Follow the blue line, 85 meters to walk." |
| **Near the building** | Within 25 m along the route (or in a straight line with no route), from a reading accurate to 20 m: the card with the floor, room and description opens once and is read aloud, and asks "Are you at the building now?" GPS only asks. **Not yet** closes it; **I'm here** on the banner opens it again. With the app in the background, a notification says the same. |
| **Inside** | One step at a time: photo, sentence, voice. **Next**, **Back**, **Hear again**. The map and GPS directions pause, because GPS would only mislead indoors. **I'm already there** skips the rest. |
| **Arrived** | The last step asks "Are you at Tech Support now?" Only **Yes, I'm here** records the arrival and tells the office. With a description and no steps, the card near the building asks this directly. |
| **Leaving** | **Guide me out** first says "Go back to the main door of CCI Building" and waits for **Yes, I'm outside**, then the map and voice start again. The same happens when the next stop is in another building. |

## "I can't find it"

On every step, and on the card near the building when there are no steps. It tells the office's
staff (`api/v1/direction_help.php`) which step the visitor stopped at, and the office dashboard
shows a red alert at the top of every view until someone presses **Someone is going**. If the
office has no active staff account, the visitor is told to ask a guard or someone nearby instead
of being told the office knows. A repeat within a minute tells nobody again. The activity log
records each request under Gate & visits.

## The one reminder

Without the final tap the office gets no alert and no arrival time is recorded, though nothing
else depends on it: the visit still ends at the gate. So 5 minutes after reaching the building,
a visitor who hasn't tapped gets one reminder: spoken, with the question reopened, on the map
screen, or as a notification with the app in the background. Background notifications come from
the location-sharing service, so a visitor who stopped sharing their location only gets the
on-screen ones.

## What changed from before

- **GPS no longer records an arrival at an office.** It used to at the pin, which told the office
  before the visitor had climbed the stairs. Gates still work by GPS (`VISITOR_NAVIGATION.md`).
- Older app versions keep working: they ignore the new fields and still report GPS arrivals, which
  the server still accepts. The steps need app version 0.6.0.

## Files

| File | Purpose |
|---|---|
| `phone_tracker/campus_inside_editor.js` | The Inside tab |
| `phone_tracker/admin_office_directions.php` | Loads and saves directions and photos (administrators only) |
| `phone_tracker/office_directions_service.php` | The rules, shared with the visitor API |
| `phone_tracker/api/v1/campus_map.php` | Sends each stop's directions to the app |
| `phone_tracker/api/v1/direction_help.php` | "I can't find it" |
| `phone_tracker/offices.js` | The office's alert |
| `visitor_app/.../navigation/OfficeDirections.kt` | Every sentence shown or said about the place |
| `visitor_app/.../navigation/Guidance.kt` | `AT_BUILDING`: GPS asks, the visitor answers |
| `visitor_app/.../ui/InsideDirections.kt` | The card, the steps, help, and leaving |
| `visitor_app/.../data/DirectionPhotoStore.kt` | Keeps the photos on the phone |
| `visitor_app/.../service/VisitorTrackingService.kt` | The background notification and reminder |

## Testing

`php phone_tracker/tools/office_directions_test.php` checks the rules, and the app's
`OfficeDirectionsTest`, `GuidanceEngineTest` and `RouteGuidanceTest` check the sentences and
when GPS asks. On a phone:

1. In Campus Map > Inside, write directions for a department with a pin, with two or three steps
   and a photo. Make sure it has an active Office Personnel account.
2. Book a visit there. The pass shows the place, and **How to find it** opens the steps.
3. Have Security scan the pass, and walk to the pin. The banner says the building is just ahead,
   and the card opens and is read aloud.
4. Tap **Yes, I'm here**, then **I can't find it** on a step. The office dashboard shows the alert
   within 30 seconds, naming that step.
5. Finish the steps. Only the final tap makes the office's dashboard say the visitor has arrived.
6. Tap **Guide me out**, then **Yes, I'm outside**, and the map guides you to the gate.
7. On another visit, lock the phone as you reach the building (the notification), and don't tap
   anything for 5 minutes (the reminder).
