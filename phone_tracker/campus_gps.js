// GPS helpers for the admin Campus Map page: placing a pin from where the admin stands,
// recording a walked route, and cleaning up the recorded path. Points are [lat, lng].
// The route recorder steadies its readings with gps_filter.js, which the page loads first.
window.CampusGps = (function () {
    "use strict";

    const EARTH_RADIUS_METERS = 6371000;
    const METERS_PER_DEGREE_LATITUDE = 110540;

    /** Why GPS cannot be used on this page, or "" when it can. */
    function unavailableReason() {
        if (!window.isSecureContext) {
            return "Location only works on a secure page. Open the live site (https://) on your phone, or this computer's http://localhost address.";
        }
        if (!("geolocation" in navigator)) {
            return "This browser cannot share its location.";
        }
        return "";
    }

    function errorMessage(error) {
        if (error && error.code === 1) {
            return "Location permission was denied. Allow location for this site in the browser settings, then try again.";
        }
        if (error && error.code === 2) {
            return "Your location could not be found. Go outdoors, away from tall walls, and try again.";
        }
        if (error && error.code === 3) {
            return "Finding an accurate location took too long. Try again outdoors.";
        }
        return "Your location is not available right now.";
    }

    function toRadians(degrees) {
        return degrees * Math.PI / 180;
    }

    function distanceMeters(a, b) {
        const dLat = toRadians(b[0] - a[0]);
        const dLng = toRadians(b[1] - a[1]);
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(toRadians(a[0])) * Math.cos(toRadians(b[0])) * Math.sin(dLng / 2) ** 2;
        return 2 * EARTH_RADIUS_METERS * Math.asin(Math.min(1, Math.sqrt(h)));
    }

    function pathLength(points) {
        let total = 0;
        for (let index = 1; index < points.length; index++) {
            total += distanceMeters(points[index - 1], points[index]);
        }
        return total;
    }

    /** Compass direction from a to b, 0 = north, clockwise. */
    function bearingDegrees(a, b) {
        const lat1 = toRadians(a[0]);
        const lat2 = toRadians(b[0]);
        const dLng = toRadians(b[1] - a[1]);
        const y = Math.sin(dLng) * Math.cos(lat2);
        const x = Math.cos(lat1) * Math.sin(lat2) - Math.sin(lat1) * Math.cos(lat2) * Math.cos(dLng);
        return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
    }

    /** Flat x/y in meters around the first point; accurate enough across a campus. */
    function toLocalMeters(points) {
        const origin = points[0];
        const metersPerLng = 111320 * Math.cos(toRadians(origin[0]));
        return points.map(function (point) {
            return [(point[1] - origin[1]) * metersPerLng, (point[0] - origin[0]) * METERS_PER_DEGREE_LATITUDE];
        });
    }

    function distanceToSegment(point, start, end) {
        const dx = end[0] - start[0];
        const dy = end[1] - start[1];
        const lengthSquared = dx * dx + dy * dy;
        const t = lengthSquared > 0
            ? Math.max(0, Math.min(1, ((point[0] - start[0]) * dx + (point[1] - start[1]) * dy) / lengthSquared))
            : 0;
        return Math.hypot(point[0] - (start[0] + t * dx), point[1] - (start[1] + t * dy));
    }

    /** Drops points that lie within `toleranceMeters` of a straight line (Douglas-Peucker). */
    function simplify(points, toleranceMeters) {
        if (points.length <= 2) {
            return points.map(function (point) { return [point[0], point[1]]; });
        }
        const xy = toLocalMeters(points);
        const keep = new Uint8Array(points.length);
        keep[0] = 1;
        keep[points.length - 1] = 1;
        const stack = [[0, points.length - 1]];
        while (stack.length) {
            const range = stack.pop();
            let farthest = 0;
            let farthestIndex = -1;
            for (let index = range[0] + 1; index < range[1]; index++) {
                const distance = distanceToSegment(xy[index], xy[range[0]], xy[range[1]]);
                if (distance > farthest) {
                    farthest = distance;
                    farthestIndex = index;
                }
            }
            if (farthestIndex !== -1 && farthest > toleranceMeters) {
                keep[farthestIndex] = 1;
                stack.push([range[0], farthestIndex], [farthestIndex, range[1]]);
            }
        }
        return points.filter(function (point, index) { return keep[index] === 1; })
            .map(function (point) { return [point[0], point[1]]; });
    }

    /** Evens out GPS wobble: each inner point moves toward its neighbours; the ends stay put. */
    function smooth(points, passes) {
        let current = points.map(function (point) { return [point[0], point[1]]; });
        for (let pass = 0; pass < (passes || 1); pass++) {
            const next = current.map(function (point) { return point.slice(); });
            for (let index = 1; index < current.length - 1; index++) {
                next[index][0] = current[index - 1][0] * 0.25 + current[index][0] * 0.5 + current[index + 1][0] * 0.25;
                next[index][1] = current[index - 1][1] * 0.25 + current[index][1] * 0.5 + current[index + 1][1] * 0.25;
            }
            current = next;
        }
        return current;
    }

    /** The point along a path, and the segment it falls on, nearest to `point`. */
    function nearestOnPath(points, point) {
        if (points.length < 2) {
            return null;
        }
        const xy = toLocalMeters(points.concat([point]));
        const target = xy[xy.length - 1];
        let best = null;
        for (let index = 1; index < points.length; index++) {
            const distance = distanceToSegment(target, xy[index - 1], xy[index]);
            if (!best || distance < best.distance) {
                best = { segment: index, distance: distance };
            }
        }
        return best;
    }

    /**
     * Keeps the screen on while recording: phones stop sharing a web page's location when the
     * screen sleeps. Silently does nothing where the browser cannot.
     */
    function keepScreenOn() {
        let sentinel = null;
        let active = true;
        async function request() {
            if (!active || !("wakeLock" in navigator) || document.visibilityState !== "visible") {
                return;
            }
            try {
                sentinel = await navigator.wakeLock.request("screen");
            } catch (error) {
                sentinel = null;
            }
        }
        function onVisibilityChange() {
            if (document.visibilityState === "visible") {
                request();
            }
        }
        document.addEventListener("visibilitychange", onVisibilityChange);
        request();
        return {
            supported: "wakeLock" in navigator,
            release: function () {
                active = false;
                document.removeEventListener("visibilitychange", onVisibilityChange);
                if (sentinel) {
                    sentinel.release().catch(function () {});
                }
                sentinel = null;
            },
        };
    }

    /** The middle value of a list of numbers. */
    function median(values) {
        const sorted = values.slice().sort(function (a, b) { return a - b; });
        const middle = Math.floor(sorted.length / 2);
        return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
    }

    /**
     * Finds a steady position for a pin. Readings arrive for a few seconds; once
     * `goodReadings` of them are within `targetAccuracy` meters, their accuracy-weighted
     * average is used. A reading that sits apart from the others (more than `strayDistance`
     * meters, or twice its own accuracy, from their middle) is left out of the average.
     * accept() takes the best estimate so far; cancel() gives up.
     * The reported accuracy is the median of the readings used, not an optimistic figure.
     */
    function samplePosition(options) {
        const settings = Object.assign({ targetAccuracy: 8, goodReadings: 5, acceptAccuracy: 25, strayDistance: 10, maxWait: 45000, onProgress: null }, options);
        const readings = [];
        const startedAt = Date.now();
        let finished = false;
        let watchId = null;
        let timer = null;
        let resolveResult;
        let rejectResult;
        const promise = new Promise(function (resolve, reject) {
            resolveResult = resolve;
            rejectResult = reject;
        });

        function estimate() {
            const usable = readings.filter(function (reading) { return reading.accuracy <= settings.acceptAccuracy; });
            if (!usable.length) {
                return null;
            }
            const best = Math.min.apply(null, usable.map(function (reading) { return reading.accuracy; }));
            const sharpest = usable.filter(function (reading) {
                return reading.accuracy <= Math.max(best * 1.5, best + 2);
            }).slice(-10);
            // One reading far from where the others sit (a signal bounced off a wall) must not
            // drag the pin, so it is left out. When none of them agree there is no telling
            // which is right, and all are kept.
            const middle = [
                median(sharpest.map(function (reading) { return reading.latitude; })),
                median(sharpest.map(function (reading) { return reading.longitude; })),
            ];
            const agreeing = sharpest.filter(function (reading) {
                return distanceMeters(middle, [reading.latitude, reading.longitude]) <= Math.max(settings.strayDistance, 2 * reading.accuracy);
            });
            const chosen = agreeing.length ? agreeing : sharpest;
            let weightSum = 0;
            let latitude = 0;
            let longitude = 0;
            chosen.forEach(function (reading) {
                const weight = 1 / Math.max(1, reading.accuracy * reading.accuracy);
                weightSum += weight;
                latitude += reading.latitude * weight;
                longitude += reading.longitude * weight;
            });
            const accuracies = chosen.map(function (reading) { return reading.accuracy; }).sort(function (a, b) { return a - b; });
            return {
                latitude: latitude / weightSum,
                longitude: longitude / weightSum,
                accuracy: Math.round(accuracies[Math.floor(accuracies.length / 2)] * 10) / 10,
                readings: chosen.length,
            };
        }

        function finish(success, error) {
            if (finished) {
                return;
            }
            finished = true;
            if (watchId !== null) {
                navigator.geolocation.clearWatch(watchId);
            }
            window.clearTimeout(timer);
            const result = estimate();
            if (success && result) {
                resolveResult(result);
            } else {
                rejectResult(error || { code: 3 });
            }
        }

        watchId = navigator.geolocation.watchPosition(function (position) {
            readings.push({
                latitude: position.coords.latitude,
                longitude: position.coords.longitude,
                accuracy: position.coords.accuracy,
            });
            const good = readings.filter(function (reading) { return reading.accuracy <= settings.targetAccuracy; }).length;
            if (settings.onProgress) {
                settings.onProgress({
                    latest: readings[readings.length - 1],
                    estimate: estimate(),
                    good: good,
                    needed: settings.goodReadings,
                    count: readings.length,
                    elapsed: Date.now() - startedAt,
                });
            }
            if (good >= settings.goodReadings) {
                finish(true);
            }
        }, function (error) {
            // A denied permission ends it at once; other errors wait for more readings.
            if (error.code === 1 || !readings.length) {
                finish(false, error);
            }
        }, { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 });

        timer = window.setTimeout(function () {
            finish(Boolean(estimate()), { code: 3 });
        }, settings.maxWait);

        return {
            promise: promise,
            estimate: estimate,
            accept: function () {
                if (estimate()) {
                    finish(true);
                }
            },
            cancel: function () {
                finish(false, { code: 0, cancelled: true });
            },
        };
    }

    /**
     * Records a walked path. Every reading goes through a GpsFilter (gps_filter.js), and what
     * is kept is the steady position it gives, not the reading as it came. A point is kept
     * when the reading's accuracy is within maxAccuracy, the filter did not hold it back as a
     * jump (a GPS glitch), the admin is walking (standing still adds no points, so waiting at
     * a door leaves no knot in the line), and it is at least minStep from the last kept point.
     * Readings that stay at a new place are believed after a few seconds, in case the earlier
     * position was the glitch. Readings while paused only move the "you are here" dot.
     */
    function createRecorder(options) {
        // maxSpeed is not read any more (the filter judges jumps); it stays for callers that set it.
        const settings = Object.assign({ maxAccuracy: 20, minStep: 2, maxSpeed: 6, onUpdate: null, onError: null }, options);
        // Without gps_filter.js (an old copy of the page), readings are used as they come.
        const filter = window.GpsFilter ? window.GpsFilter.create() : null;
        let points = [];
        let current = null;
        let state = "idle";
        let watchId = null;
        let wake = null;
        let startedAt = null;
        let stoppedAt = null;
        let pausedAt = null;
        let pausedTotal = 0;
        let weak = 0;
        let lastFixAt = null;

        function elapsed() {
            if (!startedAt) {
                return 0;
            }
            const end = stoppedAt || Date.now();
            const paused = pausedTotal + (pausedAt ? end - pausedAt : 0);
            return Math.max(0, end - startedAt - paused);
        }

        function averageAccuracy() {
            if (!points.length) {
                return null;
            }
            const sum = points.reduce(function (total, point) { return total + point[2]; }, 0);
            return Math.round(sum / points.length * 10) / 10;
        }

        function status() {
            return {
                state: state,
                points: points.map(function (point) { return [point[0], point[1]]; }),
                current: current,
                distance: pathLength(points),
                elapsed: elapsed(),
                weak: weak,
                averageAccuracy: averageAccuracy(),
                lastFixAge: lastFixAt ? Date.now() - lastFixAt : null,
            };
        }

        function emit() {
            if (settings.onUpdate) {
                settings.onUpdate(status());
            }
        }

        /** The steady position after this reading, or null while the filter has none yet. */
        function steadyPosition(position) {
            if (!filter) {
                return { latitude: position.coords.latitude, longitude: position.coords.longitude, quality: "used", still: false };
            }
            return filter.update(window.GpsFilter.fromPosition(position));
        }

        function onPosition(position) {
            // Paused or weak, every reading goes to the filter, so it always knows where the admin is.
            const steady = steadyPosition(position);
            const here = steady || position.coords;
            // The steady position, with the accuracy the phone gave for this reading.
            const reading = [here.latitude, here.longitude, position.coords.accuracy, Date.now()];
            current = { latitude: reading[0], longitude: reading[1], accuracy: reading[2] };
            lastFixAt = Date.now();
            if (state !== "recording") {
                emit();
                return;
            }
            if (reading[2] > settings.maxAccuracy) {
                weak++;
                emit();
                return;
            }
            // A reading held back as a jump (or before the filter has a position) is not part of the path.
            if (!steady || steady.quality !== "used") {
                emit();
                return;
            }
            const last = points[points.length - 1];
            if (last) {
                // Standing still adds nothing after the starting point: that is what tied knots at doors.
                if (steady.still || distanceMeters(last, reading) < settings.minStep) {
                    emit();
                    return;
                }
            }
            points.push(reading);
            emit();
        }

        function watch() {
            if (watchId !== null) {
                return;
            }
            watchId = navigator.geolocation.watchPosition(onPosition, function (error) {
                if (error.code === 1 && watchId !== null) {
                    navigator.geolocation.clearWatch(watchId);
                    watchId = null;
                }
                if (settings.onError) {
                    settings.onError(error);
                }
            }, { enableHighAccuracy: true, maximumAge: 0, timeout: 30000 });
            wake = keepScreenOn();
        }

        return {
            start: function () {
                state = "recording";
                startedAt = startedAt || Date.now();
                watch();
                emit();
            },
            pause: function () {
                if (state === "recording") {
                    state = "paused";
                    pausedAt = Date.now();
                    emit();
                }
            },
            resume: function () {
                if (state === "paused") {
                    pausedTotal += Date.now() - pausedAt;
                    pausedAt = null;
                    state = "recording";
                    // The filter is not reset here: it kept following the readings while paused, so
                    // it already stands where the admin is and the way walked meanwhile is not taken
                    // for a jump. If no readings came (the screen slept), it starts afresh by itself
                    // after RESTART_GAP_MILLIS of silence, and a shorter silence only lets it move farther.
                    watch();
                    emit();
                }
            },
            /** Removes about the last `meters` of the path; the starting point stays. */
            undo: function (meters) {
                let removed = 0;
                while (points.length > 1 && removed < meters) {
                    removed += distanceMeters(points[points.length - 2], points[points.length - 1]);
                    points.pop();
                }
                emit();
                return removed;
            },
            /** Adds where the admin stands now (the steady position) as the final point, when accurate enough. */
            addCurrent: function () {
                if (!current || current.accuracy > settings.maxAccuracy) {
                    return false;
                }
                const reading = [current.latitude, current.longitude, current.accuracy, Date.now()];
                const last = points[points.length - 1];
                if (!last || distanceMeters(last, reading) >= 1) {
                    points.push(reading);
                }
                emit();
                return true;
            },
            stop: function () {
                if (pausedAt) {
                    pausedTotal += Date.now() - pausedAt;
                    pausedAt = null;
                }
                stoppedAt = Date.now();
                state = "stopped";
                if (watchId !== null) {
                    navigator.geolocation.clearWatch(watchId);
                    watchId = null;
                }
                if (wake) {
                    wake.release();
                    wake = null;
                }
                const result = status();
                emit();
                return result;
            },
            status: status,
            /** What is needed to continue after the page reloads. */
            snapshot: function () {
                return { points: points.slice(), elapsed: elapsed(), weak: weak };
            },
            /** Continues a snapshot, paused, so the admin resumes on purpose. */
            restore: function (saved) {
                points = Array.isArray(saved.points) ? saved.points.filter(function (point) {
                    return Array.isArray(point) && point.length >= 4;
                }) : [];
                weak = Number(saved.weak) || 0;
                startedAt = Date.now() - (Number(saved.elapsed) || 0);
                pausedAt = Date.now();
                state = "paused";
                // The saved points are from before the reload. The filter starts afresh, so the
                // first readings are not measured against where the admin stood back then.
                if (filter) {
                    filter.reset();
                }
                watch();
                emit();
            },
        };
    }

    return {
        unavailableReason: unavailableReason,
        errorMessage: errorMessage,
        distanceMeters: distanceMeters,
        pathLength: pathLength,
        bearingDegrees: bearingDegrees,
        simplify: simplify,
        smooth: smooth,
        nearestOnPath: nearestOnPath,
        keepScreenOn: keepScreenOn,
        samplePosition: samplePosition,
        createRecorder: createRecorder,
    };
})();
