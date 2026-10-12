// Tests for ../gps_filter.js. Run from the project folder with:
//
//     node --test phone_tracker/tools/gps_filter_test.js
//
// The first part is PositionFilterTest.kt (the visitor app's tests) written in JavaScript:
// the same test names, the same readings, the same numbers. A change to either filter must
// keep both sets of tests passing. The second part covers what only the website has.
"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const GpsFilter = require("../gps_filter.js");

const HOME = { latitude: 10.7177, longitude: 122.5559 };
const METERS_PER_LONGITUDE = 111320 * Math.cos(HOME.latitude * Math.PI / 180);

/** A point `north` and `east` meters from the starting spot. */
function at(north, east) {
    return {
        latitude: HOME.latitude + north / 111320,
        longitude: HOME.longitude + (east || 0) / METERS_PER_LONGITUDE,
    };
}

function north(point) {
    return (point.latitude - HOME.latitude) * 111320;
}

function east(point) {
    return (point.longitude - HOME.longitude) * METERS_PER_LONGITUDE;
}

function apart(a, b) {
    return Math.hypot(north(a) - north(b), east(a) - east(b));
}

/** One reading; accuracy is 5 m and speed unknown unless `options` says otherwise. */
function reading(northMeters, eastMeters, second, options) {
    const settings = Object.assign({ accuracy: 5, speed: null }, options);
    const point = at(northMeters, eastMeters);
    return {
        latitude: point.latitude,
        longitude: point.longitude,
        accuracy: settings.accuracy,
        speed: settings.speed,
        time: Math.trunc(second * 1000),
    };
}

/**
 * GPS wander that is the same on every run: values from -1 to 1. Kotlin's Long wraps at 64
 * bits, so BigInt is cut to 64 bits to give the very same values.
 */
function createWander(seed) {
    let state = BigInt(seed === undefined ? 20261012 : seed);
    return {
        next: function () {
            state = BigInt.asIntN(64, state * 6364136223846793005n + 1442695040888963407n);
            return Number((BigInt.asUintN(64, state) >> 33n) % 20001n) / 10000 - 1;
        },
    };
}

/** Kotlin's `!!`: the filter must have a position here. */
function must(steady) {
    assert.notEqual(steady, null, "the filter has a position");
    return steady;
}

/** JUnit's assertEquals(expected, actual, delta). */
function assertNear(expected, actual, delta, message) {
    assert.ok(Math.abs(expected - actual) <= delta, (message ? message + ": " : "") + "expected " + expected + " within " + delta + ", got " + actual);
}

/** A filter already settled on a visitor walking north at 1.3 m/s, at `seconds`. */
function walkNorth(filter, seconds, speed) {
    let last = null;
    for (let second = 0; second <= seconds; second++) {
        last = filter.update(reading(second * 1.3, 0, second, { speed: speed === undefined ? 1.3 : speed }));
    }
    return must(last);
}

/* ---------- PositionFilterTest.kt ---------- */

test("theFirstPositionWaitsForAnAccurateReading", function () {
    const filter = GpsFilter.create();
    assert.equal(filter.update(reading(40, 30, 0, { accuracy: 45 })), null, "a Wi-Fi guess never opens the map");
    assert.equal(filter.update(reading(20, 10, 1, { accuracy: 30 })), null);
    const first = must(filter.update(reading(1, 0, 2, { accuracy: 12 })));
    assert.equal(first.quality, "used");
    assertNear(0, apart(first, at(1)), 0.01);
    assertNear(12, first.accuracy, 0.0001);
});

test("afterAShortWaitARougherFirstReadingIsTaken", function () {
    const filter = GpsFilter.create();
    for (let second = 0; second <= 5; second++) {
        assert.equal(filter.update(reading(0, 0, second, { accuracy: 40 })), null);
    }
    assert.notEqual(filter.update(reading(0, 0, 6, { accuracy: 40 })), null);
    const weak = GpsFilter.create();
    for (let second = 0; second <= 20; second++) {
        weak.update(reading(0, 0, second, { accuracy: 80 }));
    }
    assert.equal(weak.update(reading(0, 0, 21, { accuracy: 80 })), null, "weak readings never start it");
});

test("standingStillTheArrowSettlesAndStopsWandering", function () {
    const filter = GpsFilter.create();
    const wander = createWander();
    let previous = null;
    let largestStep = 0;
    let largestRawStep = 0;
    let previousRaw = null;
    for (let second = 0; second <= 90; second++) {
        // Ordinary GPS wander of up to 6 m either way while the visitor stands at the door.
        const rawNorth = 6 * wander.next();
        const rawEast = 6 * wander.next();
        const raw = reading(rawNorth, rawEast, second, { speed: 0.1 });
        const steady = must(filter.update(raw));
        if (second >= 15) {
            largestStep = Math.max(largestStep, apart(steady, must(previous)));
            largestRawStep = Math.max(largestRawStep, apart(raw, must(previousRaw)));
            assert.equal(steady.still, true);
        }
        previous = steady;
        previousRaw = raw;
    }
    assert.ok(largestRawStep > 8, "raw readings hop by meters (" + largestRawStep + ")");
    assertNear(0, largestStep, 0, "the steady position does not move at all");
    assert.ok(apart(must(previous), at(0)) < 2.5, "and sits on the true spot");
});

test("slowGpsDriftWhileStandingDoesNotMoveTheArrow", function () {
    const filter = GpsFilter.create();
    let settled = null;
    for (let second = 0; second <= 180; second++) {
        // Real GPS error drifts slowly rather than hopping: here a few meters over a minute.
        const driftNorth = 2.5 * Math.sin(2 * Math.PI * second / 60);
        const driftEast = 2.5 * Math.cos(2 * Math.PI * second / 47) - 2.5;
        const steady = must(filter.update(reading(driftNorth, driftEast, second, { speed: 0.2 })));
        if (second === 12) {
            settled = steady;
        }
        if (second > 12) {
            assert.equal(steady.still, true);
            assertNear(0, apart(steady, must(settled)), 0);
        }
    }
});

test("roughReadingsDoNotLeaveAWalkerFarBehind", function () {
    const filter = GpsFilter.create();
    let last = null;
    // A phone that is only good to 15 m, walking at 1.3 m/s.
    for (let second = 0; second <= 60; second++) {
        last = filter.update(reading(second * 1.3, 0, second, { accuracy: 15, speed: 1.3 }));
    }
    assert.ok(78 - north(must(last)) < 4.5, (78 - north(last)) + " m behind");
});

test("aSingleJumpIsLeftOut", function () {
    const filter = GpsFilter.create();
    const before = walkNorth(filter, 20);
    // A reflected signal: 40 m east, though the phone still claims 12 m accuracy.
    const jump = must(filter.update(reading(27.3, 40, 21, { accuracy: 12, speed: 1.3 })));
    assert.equal(jump.quality, "held_jump");
    assertNear(0, apart(jump, before), 0.001);
    assertNear(5, jump.accuracy, 0.0001, "the accuracy stays the last used reading's");
    const after = must(filter.update(reading(28.6, 0, 22, { speed: 1.3 })));
    assert.equal(after.quality, "used");
    assert.ok(Math.abs(east(after)) < 0.01);
    assert.ok(north(after) > north(before), "walking on is followed at once");
});

test("aRoughWifiReadingBarelyMovesTheArrow", function () {
    const filter = GpsFilter.create();
    const before = walkNorth(filter, 20);
    // The phone lost GPS for a moment and fell back to a Wi-Fi position 40 m away.
    const rough = must(filter.update(reading(26, 40, 21, { accuracy: 35 })));
    assert.ok(apart(rough, before) < 1, "moved " + apart(rough, before) + " m");
});

test("aBurstOfBadReadingsIsLeftOut", function () {
    const filter = GpsFilter.create();
    const before = walkNorth(filter, 20);
    for (let second = 21; second <= 25; second++) {
        const held = must(filter.update(reading(20, -30, second, { accuracy: 10 })));
        assert.equal(held.quality, "held_jump");
        assertNear(0, apart(held, before), 0.001);
    }
    // The readings come back to the walkway, 6.5 m farther on.
    const back = must(filter.update(reading(26 * 1.3, 0, 26, { speed: 1.3 })));
    assert.equal(back.quality, "used");
    assert.ok(Math.abs(east(back)) < 0.01);
});

test("aCloserBurstOnlyEdgesTheArrowOverAndItComesBackAtOnce", function () {
    const filter = GpsFilter.create();
    walkNorth(filter, 20);
    // Readings 20 m to the side that still claim ±7 m: close enough to pass as possible, so
    // they are only partly believed and the arrow edges over instead of jumping.
    const first = must(filter.update(reading(21 * 1.3, -20, 21, { accuracy: 7, speed: 1.3 })));
    assert.ok(-east(first) < 4, "the first moved it " + -east(first) + " m");
    for (let second = 22; second <= 25; second++) {
        filter.update(reading(second * 1.3, -20, second, { accuracy: 7, speed: 1.3 }));
    }
    let back = null;
    for (let second = 26; second <= 28; second++) {
        back = filter.update(reading(second * 1.3, 0, second, { speed: 1.3 }));
    }
    assert.equal(must(back).quality, "used");
    assert.ok(-east(back) < 4, "back within " + -east(back) + " m of the walkway");
});

test("readingsThatStayAtANewPlaceAreBelievedAfterAWhile", function () {
    const filter = GpsFilter.create();
    walkNorth(filter, 10);
    let last = null;
    for (let second = 11; second <= 16; second++) {
        last = must(filter.update(reading(13, 40, second, { accuracy: 6 })));
        assert.equal(last.quality, "held_jump", "held for the first seconds");
    }
    last = must(filter.update(reading(13, 40, 17, { accuracy: 6 })));
    assert.equal(last.quality, "used");
    assertNear(0, apart(last, at(13, 40)), 0.5);
});

test("aFarPlaceIsNotBelievedWhileThePhoneSaysItHardlyMoved", function () {
    const filter = GpsFilter.create();
    const before = walkNorth(filter, 10);
    // Beside a tall building the readings sit 32 m away for a while, yet the phone's own
    // speed says the visitor is standing: a bounced signal, not a walk.
    for (let second = 11; second <= 30; second++) {
        const held = must(filter.update(reading(13, 32, second, { accuracy: 6, speed: 0.1 })));
        assert.equal(held.quality, "held_jump");
        assertNear(0, apart(held, before), 0.001);
    }
    // If it never ends, the readings are believed after all.
    assert.equal(must(filter.update(reading(13, 32, 31, { accuracy: 6, speed: 0.1 }))).quality, "used");
});

test("roughReadingsAtANewPlaceNeedMuchLonger", function () {
    const filter = GpsFilter.create();
    const before = walkNorth(filter, 10);
    for (let second = 11; second <= 30; second++) {
        // 90 m away, and only good to 30 m: not believed for a long while.
        const held = must(filter.update(reading(13, 90, second, { accuracy: 30 })));
        assert.equal(held.quality, "held_jump");
        assertNear(0, apart(held, before), 0.001);
    }
    // But a phone that only ever gets rough readings is not left behind for good.
    const believed = must(filter.update(reading(13, 90, 31, { accuracy: 30 })));
    assert.equal(believed.quality, "used");
    assertNear(0, apart(believed, at(13, 90)), 0.5);
});

test("weakReadingsHoldThePosition", function () {
    const filter = GpsFilter.create();
    const before = walkNorth(filter, 10);
    const weak = must(filter.update(reading(100, 100, 11, { accuracy: 80 })));
    assert.equal(weak.quality, "held_weak");
    assertNear(0, apart(weak, before), 0.001);
    assert.equal(weak.time, before.time);
});

test("walkingIsFollowedCloselyAndSmoothly", function () {
    const filter = GpsFilter.create();
    const wander = createWander(7);
    let worst = 0;
    let sideways = 0;
    let rawSideways = 0;
    for (let second = 0; second <= 120; second++) {
        const truth = second * 1.3;
        const noiseEast = 4 * wander.next();
        const noiseNorth = 3 * wander.next();
        const steady = must(filter.update(reading(truth + noiseNorth, noiseEast, second, { accuracy: 4, speed: 1.3 })));
        if (second >= 10) {
            worst = Math.max(worst, apart(steady, at(truth)));
            sideways = Math.max(sideways, Math.abs(east(steady)));
            rawSideways = Math.max(rawSideways, Math.abs(noiseEast));
        }
        assert.equal(steady.still, false);
    }
    assert.ok(worst < 5, "never more than a few meters from the visitor (" + worst + ")");
    assert.ok(sideways < rawSideways * 0.8, "sideways wander " + sideways + " is less than the readings' " + rawSideways);
});

test("walkingOnAfterStandingStillIsFollowedAtOnce", function () {
    const filter = GpsFilter.create();
    for (let second = 0; second <= 30; second++) {
        filter.update(reading(0, 0, second, { speed: 0 }));
    }
    let last = null;
    for (let second = 31; second <= 36; second++) {
        last = filter.update(reading((second - 30) * 1.3, 0, second, { speed: 1.3 }));
    }
    assert.equal(must(last).still, false);
    assert.ok(north(last) > 4.5, "after 6 steps (7.8 m) the arrow has gone " + north(last) + " m");
});

test("aPhoneThatWronglyReportsNoSpeedIsNotBelievedForLong", function () {
    const filter = GpsFilter.create();
    let last = null;
    // Walking at 1.3 m/s while the phone keeps reporting a speed of zero.
    for (let second = 0; second <= 60; second++) {
        last = filter.update(reading(second * 1.3, 0, second, { speed: 0 }));
    }
    assert.equal(must(last).still, false);
    assert.ok(78 - north(last) < 5, "the arrow keeps up (" + (78 - north(last)) + " m behind)");
});

test("comingOutOfACoveredWalkIsFollowedAtOnce", function () {
    const filter = GpsFilter.create();
    const before = walkNorth(filter, 20);
    // No readings at all for 20 seconds, then the visitor reappears 26 m farther on.
    const after = must(filter.update(reading(52, 0, 41, { speed: 1.3 })));
    assert.equal(after.quality, "used");
    assert.ok(north(after) - north(before) > 20);
});

test("weakReadingsUnderARoofDoNotBlockTheNextGoodOne", function () {
    const filter = GpsFilter.create();
    walkNorth(filter, 20);
    for (let second = 21; second <= 40; second++) {
        filter.update(reading(26, 0, second, { accuracy: 70 }));
    }
    const after = must(filter.update(reading(52, 0, 41, { speed: 1.3 })));
    assert.equal(after.quality, "used");
    assert.ok(north(after) > 45);
});

test("aRideIsFollowed", function () {
    const filter = GpsFilter.create();
    let last = null;
    for (let second = 0; second <= 40; second++) {
        last = filter.update(reading(second * 6, 0, second, { speed: 6 }));
    }
    assert.equal(must(last).quality, "used");
    assert.ok(240 - north(last) < 4);

    // Even when the phone reports no speed at all.
    const silent = GpsFilter.create();
    for (let second = 0; second <= 40; second++) {
        last = silent.update(reading(second * 6, 0, second));
    }
    assert.equal(must(last).quality, "used");
    assert.ok(240 - north(last) < 10);
});

test("lateAndRepeatedReadingsChangeNothing", function () {
    const filter = GpsFilter.create();
    const before = walkNorth(filter, 10);
    assert.deepEqual(filter.update(reading(50, 50, 10)), before);
    assert.deepEqual(filter.update(reading(50, 50, 4)), before);
});

test("aLongSilenceStartsAfresh", function () {
    const filter = GpsFilter.create();
    walkNorth(filter, 10);
    // The screen was closed for two minutes; the first reading back is a coarse one.
    assert.equal(filter.update(reading(200, 0, 130, { accuracy: 40 })), null);
    const fresh = must(filter.update(reading(150, 0, 131, { accuracy: 6 })));
    assertNear(0, apart(fresh, at(150)), 0.01);
});

test("aReadingWithoutAccuracyIsUsedCautiously", function () {
    const filter = GpsFilter.create();
    const first = must(filter.update(reading(0, 0, 0, { accuracy: null })));
    assertNear(GpsFilter.RULES.UNKNOWN_ACCURACY_METERS, first.accuracy, 0.0001);
});

/* ---------- Only on the website ---------- */

test("the wander generator gives Kotlin's values", function () {
    // What the same lines give on the JVM, where Long is 64 bits.
    const standing = createWander();
    assert.deepEqual([standing.next(), standing.next(), standing.next(), standing.next()],
        [-0.29590000000000005, -0.8086, -0.732, -0.5952999999999999]);
    const walking = createWander(7);
    assert.deepEqual([walking.next(), walking.next(), walking.next(), walking.next()],
        [0.23229999999999995, -0.9377, 0.9416, -0.06110000000000004]);
});

test("the rules are the app's numbers", function (t) {
    const kotlin = path.join(__dirname, "..", "..", "visitor_app", "app", "src", "main", "java", "ph", "edu", "isatu",
        "visitor", "navigation", "PositionFilter.kt");
    if (!fs.existsSync(kotlin)) {
        t.skip("PositionFilter.kt is not here (the server image leaves the app out)");
        return;
    }
    const source = fs.readFileSync(kotlin, "utf8");
    const shared = {};
    const pattern = /^\s*(private )?const val (\w+) = (-?[\d_.]+)L?\s*$/gm;
    let match;
    while ((match = pattern.exec(source)) !== null) {
        if (!match[1]) {
            shared[match[2]] = Number(match[3].replace(/_/g, ""));
        }
    }
    assert.deepEqual(Object.assign({}, GpsFilter.RULES), shared);
});

test("the rules cannot be changed by a caller", function () {
    assert.ok(Object.isFrozen(GpsFilter.RULES));
    assert.throws(function () {
        GpsFilter.RULES.TOP_SPEED = 99;
    }, TypeError);
});

test("a reading without a time or a place changes nothing", function () {
    const filter = GpsFilter.create();
    assert.equal(filter.update({ latitude: 10.7177, longitude: 122.5559, accuracy: 5, speed: null, time: NaN }), null);
    const before = walkNorth(filter, 10);
    assert.deepEqual(filter.update({ latitude: NaN, longitude: 122.5559, accuracy: 5, speed: null, time: 11000 }), before);
    assert.deepEqual(filter.update({ latitude: 10.7177, longitude: 122.5559, accuracy: 5, speed: null, time: undefined }), before);
    // The next good reading is used as if those had never come.
    const after = must(filter.update(reading(11 * 1.3, 0, 11, { speed: 1.3 })));
    assert.equal(after.quality, "used");
    assert.ok(north(after) > north(before));
});

test("a browser's speed of NaN or -1 counts as unknown", function () {
    const unknown = GpsFilter.create();
    const invalid = GpsFilter.create();
    let expected = null;
    let actual = null;
    for (let second = 0; second <= 20; second++) {
        expected = unknown.update(reading(0, 0, second, { speed: null }));
        actual = invalid.update(reading(0, 0, second, { speed: second % 2 ? NaN : -1 }));
    }
    assert.deepEqual(actual, expected);
    assert.equal(must(actual).still, false, "standing still is only taken from a real speed");
});

/** Sets the page's clock (Date.now) for one test; the returned function moves it. */
function pageClock(t, start) {
    const real = Date.now;
    let now = start;
    Date.now = function () { return now; };
    t.after(function () { Date.now = real; });
    return function (time) { now = time; };
}

test("fromPosition keeps the reading's own time", function (t) {
    pageClock(t, 1760000000000);
    const taken = GpsFilter.fromPosition({
        coords: { latitude: 10.7177, longitude: 122.5559, accuracy: 7, speed: 1.2 },
        timestamp: 1760000000000 - 900,
    });
    assert.deepEqual(taken, { latitude: 10.7177, longitude: 122.5559, accuracy: 7, speed: 1.2, time: 1760000000000 - 900 });
});

test("fromPosition uses the arrival time when the phone's clocks disagree", function (t) {
    pageClock(t, 1760000000000);
    const coords = { latitude: 10.7177, longitude: 122.5559, accuracy: 7, speed: null };
    // A GPS clock years behind, one a minute ahead, and no usable time at all.
    [1760000000000 - 19 * 365 * 86400000, 1760000000000 + 60000, undefined, null, NaN, "soon"].forEach(function (timestamp) {
        assert.equal(GpsFilter.fromPosition({ coords: coords, timestamp: timestamp }).time, 1760000000000, String(timestamp));
    });
    // A Date, as old browsers gave, is read as its time.
    assert.equal(GpsFilter.fromPosition({ coords: coords, timestamp: new Date(1760000000000 - 500) }).time, 1760000000000 - 500);
});

test("readings stamped by a clock that is behind do not freeze the position", function (t) {
    const setClock = pageClock(t, 1760000000000);
    const filter = GpsFilter.create();
    let last = null;
    for (let second = 0; second <= 20; second++) {
        setClock(1760000000000 + second * 1000);
        const point = at(second * 1.3, 0);
        // The first reading carries the phone's own time; the GPS readings after it are an hour behind.
        last = filter.update(GpsFilter.fromPosition({
            coords: { latitude: point.latitude, longitude: point.longitude, accuracy: 5, speed: 1.3 },
            timestamp: 1760000000000 + second * 1000 - (second === 0 ? 0 : 3600000),
        }));
    }
    assert.equal(must(last).quality, "used");
    assert.ok(26 - north(last) < 3, "the position kept up (" + (26 - north(last)) + " m behind)");
});
