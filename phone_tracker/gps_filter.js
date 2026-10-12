// Turns raw GPS readings into a position steady enough to follow on a map. A phone's
// readings wander by several meters, and now and then one lands tens of meters away (a
// Wi-Fi or cell-tower position, or a signal bounced off a building). Used as they come, the
// position jumps. The filter keeps it where the person can really be:
//
// - Start. The first position waits for a reading accurate to START_ACCURACY_METERS (after
//   START_PATIENCE_MILLIS, any reading up to WEAK_ACCURACY_METERS), so it never opens on a
//   coarse guess.
// - Weak readings (worse than WEAK_ACCURACY_METERS) never move the position.
// - Jumps. A reading farther away than the person could have gone since the last one, after
//   allowing for both readings' error, is left out. The position only follows when the
//   readings stay at the new place for REACQUIRE_MILLIS, and only if the person could have
//   walked there in that time going by the phone's own speed; otherwise, and for rough
//   readings, they must stay there for ROUGH_REACQUIRE_MILLIS. So one bad reading, or a
//   burst of them beside a building, never moves it.
// - Smoothing. Every other reading pulls the position toward it by its accuracy: a reading
//   good to 3 m counts for much more than one good to 30 m, and one farther off than walking
//   and ordinary error explain pulls only a little, so the position edges over instead of
//   jumping. While the phone reports walking speed, it is kept within about MAX_LAG_METERS
//   of the person.
// - Standing still. When the phone reports no speed for STILL_READINGS readings in a row,
//   the position settles on the average of the next SETTLE_READINGS readings and then does
//   not move at all; it lets go as soon as the person walks on.
//
// It only steadies what GPS reports and never invents accuracy: the accuracy it gives back
// is the phone's own figure.
//
// This is the visitor app's PositionFilter.kt (visitor_app, navigation package) written in
// JavaScript: the same steps, the same numbers, in the same order, so the website and the
// app place people alike. Change the two together. tools/gps_filter_test.js holds the same
// tests as PositionFilterTest.kt; run it with: node --test phone_tracker/tools/gps_filter_test.js
//
// Used by campus_gps.js (the admin's route recorder) and app.js (the visitor's web page).
// In the browser it is the global GpsFilter; Node gets the same object from require().
(function () {
    "use strict";

    /** The numbers the filter works by, the same as in PositionFilter.kt. */
    const RULES = Object.freeze({
        /** The first position waits for a reading at least this accurate... */
        START_ACCURACY_METERS: 25,
        /** ...for this long, then takes any reading that is not weak. */
        START_PATIENCE_MILLIS: 6000,
        /** Readings worse than this never move the position. */
        WEAK_ACCURACY_METERS: 50,
        /** No reading for this long (the screen was closed): start afresh. */
        RESTART_GAP_MILLIS: 30000,

        /** Phones sometimes claim 1 m; no reading is trusted closer than this. */
        MIN_ACCURACY_METERS: 3,
        UNKNOWN_ACCURACY_METERS: 20,

        /** The fastest a person on foot is taken to move, in meters per second. */
        TOP_SPEED: 4,
        /** A reported speed allows steps this many times as long. */
        SPEED_HEADROOM: 1.5,
        SPEED_SLACK: 0.5,
        /** The fastest anyone moves on a campus (a vehicle). */
        FAST_SPEED: 20,
        JUMP_SLACK_METERS: 2,

        /** How fast the position grows unsure while walking: about 3 m after one second. */
        WALK_PACE: 3,
        /** While standing still it does not grow unsure at all. */
        STILL_PACE: 0,
        SPEED_PACE: 1.2,
        /** While the phone reports walking speed, the position trails the person by at most this... */
        MAX_LAG_METERS: 4,
        /** ...as long as the readings are at least this accurate. */
        LAG_ACCURACY_METERS: 20,

        /** Standing still: the phone reports this speed or less... */
        STILL_SPEED: 0.4,
        /** ...for this many readings in a row. */
        STILL_READINGS: 3,
        /** Standing still, the position is the average of this many readings, then fixed. */
        SETTLE_READINGS: 8,
        /** Walking again: this speed or more, or readings well away from the spot... */
        MOVING_SPEED: 0.8,
        AWAY_METERS: 6,
        /** ...for this many readings in a row. */
        WAKE_READINGS: 2,
        STILL_GAP_MILLIS: 5000,
        SPEED_DOUBT_MILLIS: 60000,

        /** Readings at a new place are believed when this accurate... */
        REACQUIRE_ACCURACY_METERS: 25,
        /** ...and this many of them, each close to the one before... */
        REACQUIRE_READINGS: 5,
        /** ...have kept it up this long, within walking reach of the position... */
        REACQUIRE_MILLIS: 6000,
        /** ...or this long when they are rougher than that, or farther than the person walked. */
        ROUGH_REACQUIRE_MILLIS: 20000,
        RUN_METERS: 8,
        RUN_SLACK_METERS: 5,
    });

    const MAX_VARIANCE = 2500;
    const MIN_STEP_SECONDS = 0.2;
    const METERS_PER_DEGREE = 111320;
    const NEVER = -Infinity;
    /** Browsers only (fromPosition): how far a reading's time may be from the page's clock. */
    const CLOCK_SLIP_MILLIS = 10000;

    function isNumber(value) {
        return typeof value === "number" && Number.isFinite(value);
    }

    /**
     * A new filter. update(reading) takes one reading and returns the steady position, or
     * null while there is none yet; reset() forgets everything, so the next reading starts
     * afresh.
     *
     * A reading is { latitude, longitude, accuracy, speed, time }: accuracy in meters and
     * speed in meters per second as the phone reported them (null when it did not), time in
     * milliseconds, the reading's own.
     *
     * The steady position is { latitude, longitude, accuracy, quality, still, time }:
     * accuracy is the phone's figure for the last reading that was used, and time is when
     * that reading was taken. quality says what was done with the latest reading: "used",
     * "held_weak" (too rough to place the person, so the last good position is kept), or
     * "held_jump" (an impossible jump, so the last good position is kept). still is true
     * while the person is standing still.
     */
    function create() {
        let started = false;
        let waitingSince = NEVER;
        let seenAt = NEVER;

        let originLatitude = 0;
        let originLongitude = 0;
        let metersPerLongitude = 0;

        let x = 0;
        let y = 0;
        /** How unsure the position is, in square meters. */
        let variance = 0;
        let accuracy = 0;
        let usedAt = 0;
        /** Up to when `variance` has been widened for the time that passed. */
        let widenedAt = 0;
        let quality = "used";

        let still = false;
        let stillReadings = 0;
        let slowReadings = 0;
        let movingReadings = 0;
        let awayReadings = 0;
        /** Until then the phone's speed is not believed: it said "still" while the person walked. */
        let speedDoubtedUntil = NEVER;

        // A run of readings that agree with each other but not with the position.
        let runReadings = 0;
        let runSince = 0;
        let runX = 0;
        let runY = 0;
        /** How far the person could have walked during the run, by the phone's own speed. */
        let runReach = 0;

        function reset() {
            started = false;
            waitingSince = NEVER;
            seenAt = NEVER;
            still = false;
            slowReadings = 0;
            movingReadings = 0;
            awayReadings = 0;
            speedDoubtedUntil = NEVER;
            runReadings = 0;
        }

        function update(reading) {
            const now = reading.time;
            // Kotlin's types rule this out: a reading without a time or a place says nothing.
            if (!isNumber(now) || !isNumber(reading.latitude) || !isNumber(reading.longitude)) {
                return current();
            }
            // A repeated or late reading says nothing new.
            if (seenAt !== NEVER && now <= seenAt) {
                return current();
            }
            const sinceSeen = seenAt === NEVER ? 0 : now - seenAt;
            if (started && sinceSeen > RULES.RESTART_GAP_MILLIS) {
                reset();
            }
            seenAt = now;

            const claimed = isNumber(reading.accuracy) && reading.accuracy > 0 ? reading.accuracy : RULES.UNKNOWN_ACCURACY_METERS;
            const radius = Math.max(RULES.MIN_ACCURACY_METERS, claimed);
            const speed = isNumber(reading.speed) && reading.speed >= 0 ? reading.speed : null;

            if (!started) {
                if (waitingSince === NEVER) {
                    waitingSince = now;
                }
                const limit = now - waitingSince < RULES.START_PATIENCE_MILLIS ? RULES.START_ACCURACY_METERS : RULES.WEAK_ACCURACY_METERS;
                if (claimed > limit) {
                    return null;
                }
                originLatitude = reading.latitude;
                originLongitude = reading.longitude;
                metersPerLongitude = METERS_PER_DEGREE * Math.cos(originLatitude * Math.PI / 180);
                started = true;
                place(0, 0, radius, claimed, now);
                return current();
            }

            if (claimed > RULES.WEAK_ACCURACY_METERS) {
                quality = "held_weak";
                return current();
            }

            // Without a used reading for a while, standing still is no longer known.
            if (still && now - usedAt > RULES.STILL_GAP_MILLIS) {
                wake(radius);
            }

            const readingX = (reading.longitude - originLongitude) * metersPerLongitude;
            const readingY = (reading.latitude - originLatitude) * METERS_PER_DEGREE;
            const distance = Math.hypot(readingX - x, readingY - y);
            const seconds = Math.max(MIN_STEP_SECONDS, sinceSeen / 1000);
            const reach = Math.min(RULES.FAST_SPEED, Math.max(RULES.TOP_SPEED, (speed === null ? 0 : speed) * RULES.SPEED_HEADROOM)) * seconds;

            // Farther than the person could have gone, even with both positions off by their error.
            const spread = Math.sqrt(radius * radius + 2 * widen(now, speed));
            if (distance > reach + 2 * spread + RULES.JUMP_SLACK_METERS) {
                return heldJump(readingX, readingY, radius, claimed, reach, distance, speed, seconds, now);
            }
            runReadings = 0;

            judgeStillness(distance, radius, speed, now);
            // The pull toward the reading: strong when the position is unsure or the reading sharp.
            const unsure = widen(now, speed);
            let gain = unsure / (unsure + radius * radius);
            // Farther off than walking (since the last reading used) and ordinary error explain, the
            // reading is only partly believed: a burst of bad readings beside a building then cannot
            // drag the position away, while the first good reading after a lost signal still counts.
            const walkable = reach / seconds * Math.max(MIN_STEP_SECONDS, (now - usedAt) / 1000) + spread;
            const doubtful = distance > walkable;
            if (doubtful) {
                gain *= (walkable / distance) ** 2;
            }
            if (still) {
                // Settled on the average of the first readings, the position stays exactly there.
                if (stillReadings >= RULES.SETTLE_READINGS) {
                    gain = 0;
                }
                stillReadings += 1;
            } else if (!doubtful && speed !== null && speed >= RULES.MOVING_SPEED && radius <= RULES.LAG_ACCURACY_METERS) {
                // Walking: never left more than MAX_LAG_METERS behind, however rough the readings.
                gain = Math.max(gain, speed / (speed + RULES.MAX_LAG_METERS));
            }
            x += gain * (readingX - x);
            y += gain * (readingY - y);
            variance = (1 - gain) * unsure;
            accuracy = claimed;
            usedAt = now;
            widenedAt = now;
            quality = "used";
            return current();
        }

        /** `variance` after the time since it was last widened: the person may have moved. */
        function widen(now, speed) {
            const pace = still ? RULES.STILL_PACE : Math.max(RULES.WALK_PACE, (speed === null ? 0 : speed) * RULES.SPEED_PACE);
            const seconds = Math.max(0, (now - widenedAt) / 1000);
            return Math.min(MAX_VARIANCE, variance + pace * pace * seconds);
        }

        /** Standing still is taken from the phone's own speed, and given up when it walks on. */
        function judgeStillness(distance, radius, speed, now) {
            const away = distance > Math.max(RULES.AWAY_METERS, 1.5 * radius);
            if (still) {
                movingReadings = speed !== null && speed >= RULES.MOVING_SPEED ? movingReadings + 1 : 0;
                awayReadings = away ? awayReadings + 1 : 0;
                if (movingReadings >= RULES.WAKE_READINGS) {
                    wake(radius);
                } else if (awayReadings >= RULES.WAKE_READINGS) {
                    // The readings walked off while the phone still reported no speed.
                    speedDoubtedUntil = now + RULES.SPEED_DOUBT_MILLIS;
                    wake(radius);
                }
            } else {
                const believed = speedDoubtedUntil === NEVER || now >= speedDoubtedUntil;
                slowReadings = believed && speed !== null && speed <= RULES.STILL_SPEED && !away ? slowReadings + 1 : 0;
                if (slowReadings >= RULES.STILL_READINGS) {
                    still = true;
                    stillReadings = 0;
                    movingReadings = 0;
                    awayReadings = 0;
                }
            }
        }

        /** Walking again: readings count fully from the first step. */
        function wake(radius) {
            still = false;
            slowReadings = 0;
            movingReadings = 0;
            awayReadings = 0;
            variance = Math.max(variance, radius * radius / 2);
        }

        function heldJump(readingX, readingY, radius, claimed, reach, distance, speed, seconds, now) {
            quality = "held_jump";
            // A jump says nothing about how far the person walked, so it does not make room for itself.
            widenedAt = now;

            const walked = (speed !== null ? Math.min(RULES.FAST_SPEED, speed * RULES.SPEED_HEADROOM + RULES.SPEED_SLACK) : RULES.TOP_SPEED) * seconds;
            const together = runReadings > 0
                && Math.hypot(readingX - runX, readingY - runY) <= reach + Math.max(RULES.RUN_METERS, 2 * radius);
            if (together) {
                runReadings += 1;
                runReach += walked;
            } else {
                runReadings = 1;
                runSince = now;
                runReach = walked;
            }
            runX = readingX;
            runY = readingY;

            // Readings that keep agreeing with each other for a while are where the person really
            // is. They are believed soon only when accurate and within walking reach by the phone's
            // own speed: a signal bounced off a building moves the position, not the speed.
            const walkable = claimed <= RULES.REACQUIRE_ACCURACY_METERS && distance <= runReach + 2 * radius + RULES.RUN_SLACK_METERS;
            const patience = walkable ? RULES.REACQUIRE_MILLIS : RULES.ROUGH_REACQUIRE_MILLIS;
            if (runReadings >= RULES.REACQUIRE_READINGS && now - runSince >= patience) {
                still = false;
                slowReadings = 0;
                place(readingX, readingY, radius, claimed, now);
            }
            return current();
        }

        function place(atX, atY, radius, claimed, now) {
            x = atX;
            y = atY;
            variance = radius * radius;
            accuracy = claimed;
            usedAt = now;
            widenedAt = now;
            quality = "used";
            runReadings = 0;
        }

        function current() {
            if (!started) {
                return null;
            }
            return {
                latitude: originLatitude + y / METERS_PER_DEGREE,
                longitude: originLongitude + x / metersPerLongitude,
                accuracy: accuracy,
                quality: quality,
                still: still,
                time: usedAt,
            };
        }

        return { update: update, reset: reset };
    }

    /**
     * The reading to give update() from what the browser hands a page (a GeolocationPosition).
     * Its time is the reading's own (position.timestamp), so a late or repeated reading is
     * known for what it is. A phone may stamp GPS readings from another clock than its own;
     * a time more than CLOCK_SLIP_MILLIS from the page's clock is replaced by the time the
     * reading arrived, or every reading after it could look late and be left out.
     */
    function fromPosition(position) {
        const coords = position.coords;
        const arrived = Date.now();
        const taken = Number(position.timestamp);
        return {
            latitude: coords.latitude,
            longitude: coords.longitude,
            accuracy: coords.accuracy,
            speed: coords.speed,
            time: Number.isFinite(taken) && Math.abs(arrived - taken) <= CLOCK_SLIP_MILLIS ? taken : arrived,
        };
    }

    const api = Object.freeze({ RULES: RULES, create: create, fromPosition: fromPosition });
    if (typeof window !== "undefined") {
        window.GpsFilter = api;
    }
    if (typeof module !== "undefined" && module.exports) {
        module.exports = api;
    }
})();
