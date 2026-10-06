// Admin Campus Map → Routes tab: record a walking route by walking it with GPS, or draw and
// edit one on the map; review it, then save. Saved routes show on the map in each
// department's colour, with arrows pointing from the start toward the department.
(function () {
    "use strict";

    const DRAFT_KEY = "isatu.campusRouteDraft";
    const DRAFT_MAX_AGE_MS = 24 * 60 * 60 * 1000;
    /** Average walking speed, for "about N min walk". */
    const WALKING_SPEED = 1.25;
    /** A route that starts this close to a gate is said to start at that gate. */
    const START_GATE_RADIUS = 40;
    /** Warn when a route ends farther than this from its department's pin. */
    const END_PIN_WARNING = 15;
    /** Points may sit this far outside the boundary (the server allows the same). */
    const EDGE_TOLERANCE = 40;
    /** Distance between direction arrows along a saved route. */
    const ARROW_SPACING = 30;
    const PALETTE = ["#d9480f", "#7048e8", "#0b7285", "#c2255c", "#5c940d", "#e67700", "#1864ab", "#862e9c"];
    /** Where the editor stacks the map above the panel (matches style.css). */
    const SMALL_SCREEN = "(max-width: 1050px)";

    const routes = {
        list: [],
        loaded: false,
        unavailable: "",
        selectedId: null,
        view: "idle",
        recorder: null,
        recordingOffice: null,
        draft: null,
        drawing: null,
        followPausedUntil: 0,
        map: null,
        layers: null,
        persistTimer: null,
        lastRecorderStatus: null,
        autoExpanded: false,
    };

    function el(id) {
        return document.getElementById(id);
    }

    function message(text, isError) {
        CampusSetup.showMessage(text, isError);
    }

    /* ---------- Formatting ---------- */

    function formatDistance(meters) {
        if (meters >= 1000) {
            return (meters / 1000).toFixed(2) + " km";
        }
        return Math.round(meters) + " m";
    }

    function formatElapsed(milliseconds) {
        const seconds = Math.floor(milliseconds / 1000);
        return Math.floor(seconds / 60) + ":" + String(seconds % 60).padStart(2, "0");
    }

    function walkMinutes(meters) {
        return Math.max(1, Math.round(meters / WALKING_SPEED / 60));
    }

    function formatDate(text) {
        const date = new Date(String(text || "").replace(" ", "T"));
        if (isNaN(date.getTime())) {
            return "";
        }
        return date.toLocaleString(undefined, { month: "short", day: "numeric", hour: "numeric", minute: "2-digit" });
    }

    function officeColor(code) {
        let hash = 0;
        for (const character of String(code)) {
            hash = (hash * 31 + character.charCodeAt(0)) >>> 0;
        }
        return PALETTE[hash % PALETTE.length];
    }

    function officeName(code) {
        return CampusSetup.officeNames()[code] || code;
    }

    /* ---------- Geometry around the campus ---------- */

    function metersFromBoundary(point) {
        const boundary = CampusSetup.boundary();
        if (boundary.length < 3) {
            return 0;
        }
        if (CampusSetup.isInsideBoundary(point)) {
            return 0;
        }
        const metersPerLng = 111320 * Math.cos(point[0] * Math.PI / 180);
        let nearest = Infinity;
        for (let i = 0, j = boundary.length - 1; i < boundary.length; j = i++) {
            const ax = (boundary[j][1] - point[1]) * metersPerLng;
            const ay = (boundary[j][0] - point[0]) * 110540;
            const bx = (boundary[i][1] - point[1]) * metersPerLng;
            const by = (boundary[i][0] - point[0]) * 110540;
            const dx = bx - ax;
            const dy = by - ay;
            const lengthSquared = dx * dx + dy * dy;
            const t = lengthSquared > 0 ? Math.max(0, Math.min(1, -(ax * dx + ay * dy) / lengthSquared)) : 0;
            nearest = Math.min(nearest, Math.hypot(ax + t * dx, ay + t * dy));
        }
        return nearest;
    }

    function nearestGate(point) {
        let best = null;
        CampusSetup.gates().forEach(function (gate) {
            const distance = CampusGps.distanceMeters(point, [gate.latitude, gate.longitude]);
            if (!best || distance < best.distance) {
                best = { name: gate.name, distance: distance };
            }
        });
        return best;
    }

    function defaultStartLabel(points) {
        const gate = points.length ? nearestGate(points[0]) : null;
        return gate && gate.distance <= START_GATE_RADIUS ? gate.name : "";
    }

    function defaultRouteName(draft, startLabel) {
        const base = startLabel
            ? startLabel + " → " + officeName(draft.officeCode)
            : "Route to " + officeName(draft.officeCode);
        return uniqueRouteName(base, draft.officeCode, draft.editingId);
    }

    function uniqueRouteName(base, officeCode, ignoreId) {
        const taken = routes.list.filter(function (route) {
            return route.office_code === officeCode && route.id !== ignoreId;
        }).map(function (route) { return route.name.toLowerCase(); });
        let name = base;
        let counter = 2;
        while (taken.indexOf(name.toLowerCase()) !== -1) {
            name = base + " (" + counter++ + ")";
        }
        return name;
    }

    /* ---------- Loading ---------- */

    async function loadRoutes() {
        try {
            const response = await fetch("campus_routes.php", { credentials: "same-origin", cache: "no-store" });
            const data = await response.json();
            if (!data || !data.success) {
                routes.unavailable = (data && data.message) || "Routes could not be loaded.";
                routes.list = [];
            } else {
                routes.unavailable = "";
                routes.list = Array.isArray(data.routes) ? data.routes : [];
            }
        } catch (error) {
            routes.unavailable = "Routes could not be loaded. Check the connection and refresh the page.";
        }
        routes.loaded = true;
        renderAll();
    }

    /* ---------- Map drawing ---------- */

    function arrowIcon(bearing, color) {
        return L.divIcon({
            className: "campus-route-arrow",
            html: '<span style="transform: rotate(' + Math.round(bearing) + 'deg); border-bottom-color: ' + color + ';"></span>',
            iconSize: [14, 14],
            iconAnchor: [7, 7],
        });
    }

    /** Small arrows along the path, every ARROW_SPACING meters, pointing toward the end. */
    function addArrows(layer, points, color) {
        let travelled = 0;
        let nextArrow = ARROW_SPACING / 2;
        for (let index = 1; index < points.length; index++) {
            const start = points[index - 1];
            const end = points[index];
            const length = CampusGps.distanceMeters(start, end);
            while (length > 0 && nextArrow <= travelled + length) {
                const fraction = (nextArrow - travelled) / length;
                const position = [start[0] + (end[0] - start[0]) * fraction, start[1] + (end[1] - start[1]) * fraction];
                L.marker(position, { icon: arrowIcon(CampusGps.bearingDegrees(start, end), color), interactive: false, keyboard: false })
                    .addTo(layer);
                nextArrow += ARROW_SPACING;
            }
            travelled += length;
        }
    }

    function startIcon(color) {
        return L.divIcon({
            className: "campus-route-start",
            html: '<span style="border-color: ' + color + ';"></span>',
            iconSize: [14, 14],
            iconAnchor: [7, 7],
        });
    }

    function renderSaved() {
        if (!routes.layers) {
            return;
        }
        const layer = routes.layers.saved;
        layer.clearLayers();
        const inRoutes = CampusSetup.mode() === "routes";
        routes.list.forEach(function (route) {
            if (!Array.isArray(route.points) || route.points.length < 2) {
                return;
            }
            // While recording, drawing, or reviewing, saved routes stay in the background.
            const busy = inRoutes && routes.view !== "idle";
            const selected = route.id === routes.selectedId;
            const faded = !inRoutes || busy || (routes.selectedId && !selected);
            const color = officeColor(route.office_code);
            L.polyline(route.points, { color: "#ffffff", weight: selected ? 10 : 8, opacity: faded ? 0.35 : 0.9, interactive: false })
                .addTo(layer);
            const line = L.polyline(route.points, {
                color: color,
                weight: selected ? 6 : 4,
                opacity: faded ? 0.35 : 0.95,
                interactive: inRoutes && !busy,
            }).addTo(layer);
            line.bindTooltip(route.name + " · " + formatDistance(route.distance_meters), { sticky: true, className: "campus-overlay-tip" });
            line.on("click", function (event) {
                L.DomEvent.stopPropagation(event);
                selectRoute(route.id, false);
            });
            if (!faded) {
                addArrows(layer, route.points, color);
                L.marker(route.points[0], { icon: startIcon(color), interactive: false, keyboard: false }).addTo(layer);
            }
        });
    }

    /* ---------- Panel ---------- */

    function showView(view) {
        routes.view = view;
        ["idle", "recording", "drawing", "review"].forEach(function (name) {
            el("campusRoute" + name.charAt(0).toUpperCase() + name.slice(1)).hidden = name !== view;
        });
        if (view === "idle") {
            CampusSetup.unlockMode();
        } else {
            CampusSetup.lockMode("routes", "Finish, save, or cancel the route in the Routes tab first.");
        }
        const mapElement = el("campusSetupMap");
        mapElement.classList.toggle("is-drawing-route", view === "drawing");
        // On a phone the map gets shorter while recording, so the controls stay in view.
        mapElement.classList.toggle("is-recording-route", view === "recording");
        mapElement.closest(".campus-setup-layout").classList.toggle("is-walking", view === "recording" || view === "review");
        // On a phone or tablet, walking a route fills the screen: the map on top, the timer and
        // I've arrived right under it. The page comes back once the route is saved or dropped.
        if (view === "recording" && !CampusSetup.isExpanded() && window.matchMedia(SMALL_SCREEN).matches) {
            routes.autoExpanded = true;
            CampusSetup.setExpanded(true);
        } else if (view === "idle" && routes.autoExpanded) {
            routes.autoExpanded = false;
            CampusSetup.setExpanded(false);
        }
        if (routes.map) {
            window.requestAnimationFrame(function () {
                routes.map.invalidateSize({ pan: false });
            });
        }
        renderSaved();
    }

    function fillOfficeSelect() {
        const select = el("campusRouteOffice");
        const current = select.value;
        const names = CampusSetup.officeNames();
        const pins = CampusSetup.officePins();
        select.replaceChildren();
        Object.keys(names).forEach(function (code) {
            const option = document.createElement("option");
            option.value = code;
            const count = routes.list.filter(function (route) { return route.office_code === code; }).length;
            option.textContent = names[code] + (pins[code] ? "" : " (no pin yet)") + (count ? " · " + count + " route" + (count === 1 ? "" : "s") : "");
            select.appendChild(option);
        });
        if (names[current]) {
            select.value = current;
        }
    }

    function renderCoverage() {
        const host = el("campusRouteCoverage");
        host.replaceChildren();
        const names = CampusSetup.officeNames();
        const pins = CampusSetup.officePins();
        const codes = Object.keys(names).filter(function (code) { return names[code].indexOf("(archived)") === -1; });
        const covered = codes.filter(function (code) {
            return routes.list.some(function (route) { return route.office_code === code; });
        });
        const title = document.createElement("strong");
        title.textContent = "Routes for " + covered.length + " of " + codes.length + " department" + (codes.length === 1 ? "" : "s");
        const chips = document.createElement("div");
        chips.className = "campus-route-chips";
        codes.forEach(function (code) {
            const chip = document.createElement("span");
            const has = covered.indexOf(code) !== -1;
            // A route without a pin still guides visitors (to where the route ends), but the
            // door may be elsewhere: shown as a warning.
            const noPin = has && !pins[code];
            chip.className = "campus-route-chip" + (has ? " has-route" : "") + (noPin ? " is-warning" : "");
            chip.textContent = (noPin ? "⚠ " : (has ? "✓ " : "")) + names[code] + (noPin ? " · no pin" : "");
            if (noPin) {
                chip.title = "Visitors are guided to where its route ends. Place the pin at the door in the Offices tab.";
            } else if (has) {
                chip.style.borderColor = officeColor(code);
            }
            chips.appendChild(chip);
        });
        host.append(title, chips);
    }

    function routeDetail(route) {
        const parts = [formatDistance(route.distance_meters), "about " + walkMinutes(route.distance_meters) + " min walk"];
        parts.push(route.method === "drawn"
            ? "drawn on map"
            : "walked" + (route.average_accuracy_meters !== null ? " (GPS ±" + Math.round(route.average_accuracy_meters) + " m)" : ""));
        const when = formatDate(route.updated_at || route.created_at);
        const who = route.updated_by || route.created_by;
        if (when || who) {
            parts.push([when, who].filter(Boolean).join(" by "));
        }
        return parts.join(" · ");
    }

    function renderList() {
        const host = el("campusRouteList");
        host.replaceChildren();
        if (!routes.list.length) {
            const empty = document.createElement("p");
            empty.className = "campus-setup-help";
            empty.textContent = "No routes yet. Record the first one from the main gate to a department.";
            host.appendChild(empty);
            return;
        }
        const names = CampusSetup.officeNames();
        const byOffice = {};
        routes.list.forEach(function (route) {
            (byOffice[route.office_code] = byOffice[route.office_code] || []).push(route);
        });
        Object.keys(byOffice).sort(function (a, b) {
            return String(names[a] || a).localeCompare(String(names[b] || b));
        }).forEach(function (code) {
            const group = document.createElement("div");
            group.className = "campus-route-group";
            const heading = document.createElement("h4");
            const swatch = document.createElement("i");
            swatch.style.background = officeColor(code);
            heading.append(swatch, document.createTextNode(names[code] || code));
            group.appendChild(heading);
            byOffice[code].forEach(function (route) {
                const row = document.createElement("div");
                row.className = "campus-route-row" + (route.id === routes.selectedId ? " is-selected" : "");
                row.dataset.routeId = String(route.id);
                const copy = document.createElement("div");
                const title = document.createElement("strong");
                title.textContent = route.name;
                const detail = document.createElement("span");
                detail.textContent = routeDetail(route);
                copy.append(title, detail);
                const actions = document.createElement("div");
                actions.className = "campus-route-row-actions";
                [["Show", function () { selectRoute(route.id, true); }],
                    ["Edit", function () { editRoute(route); }],
                    ["Delete", function () { deleteRoute(route); }]].forEach(function (item) {
                    const button = document.createElement("button");
                    button.type = "button";
                    button.textContent = item[0];
                    button.className = item[0] === "Delete" ? "is-danger" : "";
                    button.setAttribute("aria-label", item[0] + " " + route.name);
                    button.addEventListener("click", item[1]);
                    actions.appendChild(button);
                });
                row.append(copy, actions);
                group.appendChild(row);
            });
            host.appendChild(group);
        });
    }

    function renderAll() {
        if (routes.unavailable) {
            el("campusRouteUnavailable").textContent = routes.unavailable;
            el("campusRouteUnavailable").hidden = false;
            el("campusRouteRecordBtn").disabled = true;
            el("campusRouteDrawBtn").disabled = true;
        } else {
            el("campusRouteUnavailable").hidden = true;
            el("campusRouteRecordBtn").disabled = false;
            el("campusRouteDrawBtn").disabled = false;
        }
        fillOfficeSelect();
        renderCoverage();
        renderList();
        renderSaved();
    }

    function selectRoute(id, zoom) {
        routes.selectedId = routes.selectedId === id && !zoom ? null : id;
        const route = routes.list.find(function (item) { return item.id === id; });
        if (zoom && route && route.points.length) {
            routes.map.fitBounds(L.latLngBounds(route.points), { padding: [40, 40], maxZoom: 20 });
        }
        renderList();
        renderSaved();
        const row = document.querySelector('.campus-route-row[data-route-id="' + id + '"]');
        if (row && routes.selectedId === id) {
            row.scrollIntoView({ block: "nearest" });
        }
    }

    /* ---------- Recording ---------- */

    function accuracyClass(accuracy) {
        if (accuracy === null || accuracy === undefined) {
            return "";
        }
        return accuracy <= 8 ? "is-good" : (accuracy <= 15 ? "is-fair" : "is-weak");
    }

    function drawRecording(status) {
        const layer = routes.layers.work;
        layer.clearLayers();
        const color = officeColor(routes.recordingOffice);
        if (status.points.length >= 2) {
            L.polyline(status.points, { color: "#ffffff", weight: 9, opacity: 0.9, interactive: false }).addTo(layer);
            L.polyline(status.points, { color: color, weight: 5, opacity: 1, interactive: false }).addTo(layer);
        }
        if (status.points.length) {
            L.marker(status.points[0], { icon: startIcon(color), interactive: false, keyboard: false }).addTo(layer);
        }
        if (status.current) {
            const here = [status.current.latitude, status.current.longitude];
            L.circle(here, {
                radius: status.current.accuracy, color: "#2563eb", weight: 1, fillColor: "#2563eb", fillOpacity: 0.1, interactive: false,
            }).addTo(layer);
            L.marker(here, {
                icon: L.divIcon({ className: "campus-me-dot", html: "<span></span>", iconSize: [18, 18], iconAnchor: [9, 9] }),
                interactive: false,
                keyboard: false,
            }).addTo(layer);
        }
    }

    function onRecorderUpdate(status) {
        routes.lastRecorderStatus = status;
        if (routes.view !== "recording") {
            return;
        }
        el("campusRecTime").textContent = formatElapsed(status.elapsed);
        el("campusRecDistance").textContent = formatDistance(status.distance);
        const accuracyBox = el("campusRecAccuracy");
        accuracyBox.textContent = status.current ? "±" + Math.round(status.current.accuracy) + " m" : "—";
        accuracyBox.className = accuracyClass(status.current ? status.current.accuracy : null);
        let text;
        if (status.state === "paused") {
            text = "Paused. Press Resume when you are back on the path.";
        } else if (!status.current) {
            text = "Waiting for GPS… Stand outdoors at the starting point.";
        } else if (status.lastFixAge !== null && status.lastFixAge > 10000) {
            text = "No GPS update for " + Math.round(status.lastFixAge / 1000) + " s. Keep this page open and the screen on.";
        } else if (!status.points.length) {
            text = "Getting an accurate starting fix (needs ±20 m or better)…";
        } else if (status.current.accuracy > 20) {
            text = "Recording, but skipping weak GPS readings (±" + Math.round(status.current.accuracy) + " m). Keep walking.";
        } else {
            text = "Recording. Walk the way a visitor should go to " + officeName(routes.recordingOffice) + ".";
        }
        el("campusRecStatus").textContent = text;
        el("campusRecPauseBtn").textContent = status.state === "paused" ? "Resume" : "Pause";
        el("campusRecPoints").textContent = status.points.length + " point" + (status.points.length === 1 ? "" : "s")
            + (status.weak ? " · " + status.weak + " weak reading" + (status.weak === 1 ? "" : "s") + " skipped" : "");
        drawRecording(status);
        if (status.current && Date.now() > routes.followPausedUntil) {
            const here = [status.current.latitude, status.current.longitude];
            if (!routes.map.getBounds().pad(-0.25).contains(here)) {
                routes.map.panTo(here);
            }
        }
        schedulePersist();
    }

    function onRecorderError(error) {
        if (error && error.code === 1) {
            message(CampusGps.errorMessage(error), true);
            if (routes.recorder && !routes.recorder.status().points.length) {
                cancelRecording(true);
            }
        }
    }

    function startRecording(restored) {
        const reason = CampusGps.unavailableReason();
        if (reason) {
            message(reason + " On this computer you can use Draw on the map instead.", true);
            return;
        }
        const officeCode = restored ? restored.officeCode : el("campusRouteOffice").value;
        if (!officeCode) {
            message("Choose the destination department first.", true);
            return;
        }
        message("");
        routes.recordingOffice = officeCode;
        routes.selectedId = null;
        routes.recorder = CampusGps.createRecorder({ onUpdate: onRecorderUpdate, onError: onRecorderError });
        el("campusRecTitle").textContent = "Recording a route to " + officeName(officeCode);
        showView("recording");
        if (restored && restored.recording) {
            routes.recorder.restore(restored.recording);
            message("Your unfinished recording is back, paused. Walk back to where it stopped, then press Resume.", false);
        } else {
            routes.recorder.start();
            routes.map.setZoom(Math.max(routes.map.getZoom(), 19));
        }
        if (!("wakeLock" in navigator)) {
            el("campusRecStatus").textContent = "This browser cannot keep the screen on by itself; tap the screen now and then so it does not sleep.";
        }
    }

    function finishRecording() {
        const recorder = routes.recorder;
        if (!recorder) {
            return;
        }
        recorder.addCurrent();
        const status = recorder.status();
        if (status.points.length < 2 || status.distance < 2) {
            message("The route is too short so far. Walk to the department, then press I've arrived.", true);
            return;
        }
        const final = recorder.stop();
        routes.recorder = null;
        routes.layers.work.clearLayers();
        const officeCode = routes.recordingOffice;
        const raw = final.points;
        openReview({
            officeCode: officeCode,
            method: "walked",
            raw: raw,
            manual: false,
            clean: true,
            points: null,
            duration: Math.round(final.elapsed / 1000),
            accuracy: final.averageAccuracy,
            endAccuracy: final.current ? final.current.accuracy : null,
            editingId: null,
            startLabel: null,
            name: null,
        });
    }

    function cancelRecording(skipConfirm) {
        if (!skipConfirm && routes.recorder && routes.recorder.status().points.length > 1
            && !window.confirm("Discard this recording?")) {
            return;
        }
        if (routes.recorder) {
            routes.recorder.stop();
            routes.recorder = null;
        }
        routes.layers.work.clearLayers();
        clearDraft();
        showView("idle");
        renderAll();
    }

    /* ---------- Drawing and editing ---------- */

    function vertexIcon(kind) {
        return L.divIcon({ className: "campus-route-vertex " + kind, html: "<span></span>", iconSize: [16, 16], iconAnchor: [8, 8] });
    }

    function renderDrawing() {
        const drawing = routes.drawing;
        const layer = routes.layers.work;
        layer.clearLayers();
        const color = officeColor(drawing.officeCode);
        if (drawing.points.length >= 2) {
            L.polyline(drawing.points, { color: "#ffffff", weight: 9, opacity: 0.9, interactive: false }).addTo(layer);
            const line = L.polyline(drawing.points, { color: color, weight: 5, opacity: 1 }).addTo(layer);
            // Clicking the line adds a point there, to reshape a stretch.
            line.on("click", function (event) {
                L.DomEvent.stopPropagation(event);
                const point = [event.latlng.lat, event.latlng.lng];
                const nearest = CampusGps.nearestOnPath(drawing.points, point);
                if (nearest) {
                    drawing.points.splice(nearest.segment, 0, point);
                    renderDrawing();
                }
            });
            addArrows(layer, drawing.points, color);
        }
        drawing.points.forEach(function (point, index) {
            const kind = index === 0 ? "is-start" : (index === drawing.points.length - 1 ? "is-end" : "");
            const marker = L.marker(point, { icon: vertexIcon(kind), draggable: true, keyboard: false, zIndexOffset: 500 });
            marker.on("dragend", function () {
                const position = marker.getLatLng();
                drawing.points[index] = [position.lat, position.lng];
                renderDrawing();
            });
            // Clicking a point removes it.
            marker.on("click", function (event) {
                L.DomEvent.stopPropagation(event);
                drawing.points.splice(index, 1);
                renderDrawing();
            });
            marker.addTo(layer);
        });
        const length = CampusGps.pathLength(drawing.points);
        el("campusDrawCount").textContent = drawing.points.length + " point" + (drawing.points.length === 1 ? "" : "s")
            + " · " + formatDistance(length);
        el("campusDrawFinishBtn").disabled = drawing.points.length < 2;
        schedulePersist();
    }

    function startDrawing(options) {
        const officeCode = options.officeCode || el("campusRouteOffice").value;
        if (!officeCode) {
            message("Choose the destination department first.", true);
            return;
        }
        message("");
        routes.selectedId = null;
        routes.drawing = {
            officeCode: officeCode,
            points: (options.points || []).map(function (point) { return [Number(point[0]), Number(point[1])]; }),
            editingId: options.editingId || null,
            method: options.method || "drawn",
            name: options.name || null,
            startLabel: options.startLabel === undefined ? null : options.startLabel,
            duration: options.duration === undefined ? null : options.duration,
            accuracy: options.accuracy === undefined ? null : options.accuracy,
        };
        el("campusDrawTitle").textContent = (routes.drawing.editingId ? "Editing a route to " : "Drawing a route to ") + officeName(officeCode);
        showView("drawing");
        renderDrawing();
        if (routes.drawing.points.length >= 2) {
            routes.map.fitBounds(L.latLngBounds(routes.drawing.points), { padding: [40, 40], maxZoom: 20 });
        }
    }

    function editRoute(route) {
        startDrawing({
            officeCode: route.office_code,
            points: route.points,
            editingId: route.id,
            method: route.method,
            name: route.name,
            startLabel: route.start_label,
            duration: route.duration_seconds,
            accuracy: route.average_accuracy_meters,
        });
    }

    function onMapClick(event) {
        if (CampusSetup.mode() !== "routes") {
            return;
        }
        if (routes.view === "drawing" && routes.drawing) {
            routes.drawing.points.push([event.latlng.lat, event.latlng.lng]);
            renderDrawing();
        } else if (routes.view === "idle" && routes.selectedId) {
            routes.selectedId = null;
            renderList();
            renderSaved();
        }
    }

    function finishDrawing() {
        const drawing = routes.drawing;
        if (!drawing || drawing.points.length < 2) {
            message("Add at least a start and an end point.", true);
            return;
        }
        routes.layers.work.clearLayers();
        routes.drawing = null;
        openReview({
            officeCode: drawing.officeCode,
            method: drawing.method,
            raw: null,
            manual: true,
            clean: false,
            points: drawing.points,
            duration: drawing.duration,
            accuracy: drawing.accuracy,
            endAccuracy: null,
            editingId: drawing.editingId,
            startLabel: drawing.startLabel,
            name: drawing.name,
        });
    }

    function cancelDrawing() {
        if (routes.drawing && !routes.drawing.editingId && routes.drawing.points.length > 1
            && !window.confirm("Discard this drawing?")) {
            return;
        }
        routes.drawing = null;
        routes.layers.work.clearLayers();
        clearDraft();
        showView("idle");
        renderAll();
    }

    /* ---------- Review ---------- */

    /** The points to save: the walked path, optionally smoothed and thinned, or the drawn one. */
    function reviewPoints(draft) {
        if (draft.manual || !draft.raw) {
            return draft.points;
        }
        return draft.clean ? CampusGps.simplify(CampusGps.smooth(draft.raw, 1), 1) : draft.raw;
    }

    function reviewWarnings(draft, points) {
        const warnings = [];
        const name = officeName(draft.officeCode);
        const pin = CampusSetup.officePins()[draft.officeCode];
        const end = points[points.length - 1];
        if (pin) {
            const fromPin = CampusGps.distanceMeters(end, [pin.latitude, pin.longitude]);
            if (fromPin > END_PIN_WARNING) {
                warnings.push("Ends " + Math.round(fromPin) + " m from the " + name + " pin. If the pin is at the right door, the route stops early; if not, move the pin.");
            }
        } else {
            warnings.push(name + " has no pin yet: saving this route places it where the route ends. If the door is elsewhere, move the pin later in the Offices tab.");
        }
        const gates = CampusSetup.gates();
        if (!gates.length) {
            warnings.push("No gates are placed yet. Add them in the Gates tab so routes begin at a known entrance.");
        } else {
            const gate = nearestGate(points[0]);
            if (gate && gate.distance > START_GATE_RADIUS) {
                warnings.push("Starts " + Math.round(gate.distance) + " m from the nearest gate (" + gate.name + "). Visitors usually begin at a gate.");
            }
        }
        if (draft.method === "walked" && draft.accuracy !== null && draft.accuracy > 10) {
            warnings.push("GPS was weak along the way (average ±" + Math.round(draft.accuracy) + " m). If the line looks off, record it again outdoors or adjust the points.");
        }
        const outside = points.filter(function (point) { return metersFromBoundary(point) > EDGE_TOLERANCE; }).length;
        if (outside) {
            warnings.push(outside + " point" + (outside === 1 ? " is" : "s are") + " more than " + EDGE_TOLERANCE + " m outside the campus boundary; the route cannot be saved like that.");
        }
        return warnings;
    }

    function renderReview() {
        const draft = routes.draft;
        const points = reviewPoints(draft);
        const distance = CampusGps.pathLength(points);
        const layer = routes.layers.work;
        layer.clearLayers();
        const color = officeColor(draft.officeCode);
        if (draft.raw && !draft.manual && draft.clean) {
            // The raw GPS track, thin and dashed, under the cleaned line.
            L.polyline(draft.raw, { color: "#5f6b7a", weight: 2, dashArray: "4 5", opacity: 0.8, interactive: false }).addTo(layer);
        }
        L.polyline(points, { color: "#ffffff", weight: 9, opacity: 0.9, interactive: false }).addTo(layer);
        L.polyline(points, { color: color, weight: 5, opacity: 1, interactive: false }).addTo(layer);
        addArrows(layer, points, color);
        L.marker(points[0], { icon: startIcon(color), interactive: false, keyboard: false }).addTo(layer);

        el("campusReviewTitle").textContent = (draft.editingId ? "Save changes to the route to " : "New route to ") + officeName(draft.officeCode);
        el("campusReviewDistance").textContent = formatDistance(distance);
        el("campusReviewWalk").textContent = walkMinutes(distance) + " min";
        el("campusReviewPoints").textContent = String(points.length);
        el("campusReviewCleanWrap").hidden = !draft.raw || draft.manual;
        el("campusReviewClean").checked = Boolean(draft.clean);

        const warningList = el("campusReviewWarnings");
        warningList.replaceChildren();
        reviewWarnings(draft, points).forEach(function (text) {
            const item = document.createElement("li");
            item.textContent = text;
            warningList.appendChild(item);
        });
        warningList.hidden = !warningList.children.length;

        // Without a pin, saving places one where the route ends (saveReview). A walked route
        // can also move an existing pin to where the admin stopped.
        const pin = CampusSetup.officePins()[draft.officeCode];
        const end = points[points.length - 1];
        const endAccuracy = draft.endAccuracy;
        const canMovePin = Boolean(pin) && draft.method === "walked" && !draft.manual && endAccuracy !== null && endAccuracy <= 12;
        const pinWrap = el("campusReviewPinWrap");
        pinWrap.hidden = !canMovePin;
        if (canMovePin) {
            const far = CampusGps.distanceMeters(end, [pin.latitude, pin.longitude]) > 5;
            el("campusReviewPinText").textContent = "Move the " + officeName(draft.officeCode)
                + " pin to where you stopped (GPS ±" + Math.round(endAccuracy) + " m)";
            if (draft.movePin === undefined) {
                // A pin taken standing still (several GPS readings averaged) beats one reading at the end of a walk.
                const pinIsBetter = pin && pin.placed_by === "gps" && pin.accuracy_meters !== null && pin.accuracy_meters <= endAccuracy;
                draft.movePin = far && !pinIsBetter;
            }
            el("campusReviewPin").checked = Boolean(draft.movePin);
        }
        schedulePersist();
    }

    function openReview(draft) {
        routes.draft = draft;
        const points = reviewPoints(draft);
        if (draft.startLabel === null || draft.startLabel === undefined) {
            draft.startLabel = defaultStartLabel(points);
            // "Starts at" follows the line's first point (for example after Reverse direction)
            // until the admin types a start.
            draft.autoStart = true;
        }
        if (!draft.name) {
            draft.name = defaultRouteName(draft, draft.startLabel);
            // The name follows the "Starts at" field until the admin types their own.
            draft.autoName = true;
        }
        el("campusReviewName").value = draft.name;
        el("campusReviewStart").value = draft.startLabel || "";
        fillGateNames();
        showView("review");
        renderReview();
        routes.map.fitBounds(L.latLngBounds(points), { padding: [40, 40], maxZoom: 20 });
    }

    function fillGateNames() {
        const list = el("campusGateNames");
        list.replaceChildren();
        CampusSetup.gates().map(function (gate) { return gate.name; }).concat(["Guard house"]).forEach(function (name) {
            const option = document.createElement("option");
            option.value = name;
            list.appendChild(option);
        });
    }

    function adjustReview() {
        const draft = routes.draft;
        const points = reviewPoints(draft);
        startDrawing({
            officeCode: draft.officeCode,
            points: points,
            editingId: draft.editingId,
            method: draft.method,
            name: draft.autoName ? null : (el("campusReviewName").value.trim() || draft.name),
            startLabel: draft.autoStart ? null : el("campusReviewStart").value.trim(),
            duration: draft.duration,
            accuracy: draft.accuracy,
        });
        routes.draft = null;
    }

    function discardReview() {
        if (!window.confirm(routes.draft && routes.draft.editingId ? "Discard these changes?" : "Discard this route?")) {
            return;
        }
        routes.draft = null;
        routes.layers.work.clearLayers();
        clearDraft();
        showView("idle");
        renderAll();
    }

    async function saveReview() {
        const draft = routes.draft;
        const points = reviewPoints(draft);
        const name = el("campusReviewName").value.trim();
        const startLabel = el("campusReviewStart").value.trim();
        if (!name) {
            message("Give the route a name.", true);
            el("campusReviewName").focus();
            return;
        }
        const movePin = !el("campusReviewPinWrap").hidden && el("campusReviewPin").checked;
        const button = el("campusReviewSaveBtn");
        button.disabled = true;
        try {
            const response = await fetch("admin_save_campus_route.php", {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    id: draft.editingId,
                    office_code: draft.officeCode,
                    name: name,
                    start_label: startLabel,
                    method: draft.method,
                    points: points.map(function (point) { return [Number(point[0].toFixed(7)), Number(point[1].toFixed(7))]; }),
                    duration_seconds: draft.duration,
                    average_accuracy_meters: draft.accuracy,
                }),
            });
            const data = await response.json();
            if (!data || !data.success || !data.route) {
                message((data && data.message) || "Could not save the route.", true);
                return;
            }
            const saved = data.route;
            const index = routes.list.findIndex(function (route) { return route.id === saved.id; });
            if (index === -1) {
                routes.list.push(saved);
            } else {
                routes.list[index] = saved;
            }
            routes.draft = null;
            routes.selectedId = saved.id;
            routes.layers.work.clearLayers();
            clearDraft();
            showView("idle");
            renderAll();
            let text = "Saved \"" + saved.name + "\" (" + formatDistance(saved.distance_meters) + ").";
            // A department needs a pin as the visitor's destination: one without a pin gets it
            // where this route ends, the door the admin walked to.
            const hadPin = Boolean(CampusSetup.officePins()[saved.office_code]);
            const routeEnd = points[points.length - 1];
            if ((!hadPin || movePin) && !CampusSetup.isInsideBoundary(routeEnd)) {
                // A route may end a little past the boundary line; a pin must be inside it.
                message(text + " This route ends outside the campus boundary, so the " + officeName(saved.office_code)
                    + " pin was " + (hadPin ? "not moved" : "not placed") + ". Place it at the door inside the boundary in the"
                    + " Offices tab, or extend the boundary.", true);
                return;
            }
            if (!hadPin || movePin) {
                const walked = draft.method === "walked" && !draft.manual;
                CampusSetup.setOfficePin(saved.office_code, routeEnd, walked ? draft.endAccuracy : null);
                // On failure the campus map's own message (for example, outside the boundary) stays.
                if (!(await CampusSetup.save())) {
                    return;
                }
                text += hadPin
                    ? " The " + officeName(saved.office_code) + " pin now sits where you stopped."
                    : " " + officeName(saved.office_code) + " had no pin, so it was placed where this route ends;"
                        + " if the door is elsewhere, move it in the Offices tab.";
            }
            message(text, false);
        } catch (error) {
            message("Could not reach the server. The route is kept here; try Save again.", true);
        } finally {
            button.disabled = false;
        }
    }

    async function deleteRoute(route) {
        if (!window.confirm("Delete the route \"" + route.name + "\"? This cannot be undone.")) {
            return;
        }
        try {
            const response = await fetch("admin_delete_campus_route.php", {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ id: route.id }),
            });
            const data = await response.json();
            if (!data || !data.success) {
                message((data && data.message) || "Could not delete the route.", true);
                return;
            }
            routes.list = routes.list.filter(function (item) { return item.id !== route.id; });
            if (routes.selectedId === route.id) {
                routes.selectedId = null;
            }
            renderAll();
            message("Deleted \"" + route.name + "\".", false);
        } catch (error) {
            message("Could not reach the server.", true);
        }
    }

    /* ---------- Surviving a reload ---------- */

    function schedulePersist() {
        if (routes.persistTimer) {
            return;
        }
        routes.persistTimer = window.setTimeout(function () {
            routes.persistTimer = null;
            persistDraft();
        }, 1500);
    }

    function persistDraft() {
        let saved = null;
        if (routes.view === "recording" && routes.recorder) {
            saved = { view: "recording", officeCode: routes.recordingOffice, recording: routes.recorder.snapshot() };
        } else if (routes.view === "drawing" && routes.drawing) {
            saved = { view: "drawing", drawing: routes.drawing };
        } else if (routes.view === "review" && routes.draft) {
            routes.draft.name = el("campusReviewName").value;
            routes.draft.startLabel = el("campusReviewStart").value;
            saved = { view: "review", draft: routes.draft };
        }
        try {
            if (saved) {
                saved.savedAt = Date.now();
                window.localStorage.setItem(DRAFT_KEY, JSON.stringify(saved));
            }
        } catch (error) {
            // Private browsing or full storage: the route still works, it just cannot survive a reload.
        }
    }

    function clearDraft() {
        try {
            window.localStorage.removeItem(DRAFT_KEY);
        } catch (error) {
            // Nothing stored.
        }
    }

    function readDraft() {
        try {
            const saved = JSON.parse(window.localStorage.getItem(DRAFT_KEY) || "null");
            if (saved && saved.savedAt && Date.now() - saved.savedAt < DRAFT_MAX_AGE_MS) {
                return saved;
            }
        } catch (error) {
            // Unreadable draft.
        }
        clearDraft();
        return null;
    }

    function offerSavedDraft() {
        const saved = readDraft();
        const box = el("campusRouteResume");
        if (!saved) {
            box.hidden = true;
            return;
        }
        let office = "";
        let detail = "";
        if (saved.view === "recording") {
            office = saved.officeCode;
            const points = (saved.recording && saved.recording.points) || [];
            detail = "a recording with " + points.length + " points (" + formatDistance(CampusGps.pathLength(points)) + ")";
        } else if (saved.view === "drawing") {
            office = saved.drawing.officeCode;
            detail = "a drawing with " + saved.drawing.points.length + " points";
        } else if (saved.view === "review" && saved.draft) {
            office = saved.draft.officeCode;
            detail = "a route waiting to be saved";
        }
        el("campusRouteResumeText").textContent = "Unfinished: " + detail + " to " + officeName(office) + ".";
        box.hidden = false;
        el("campusRouteResumeBtn").onclick = function () {
            box.hidden = true;
            if (saved.view === "recording") {
                startRecording(saved);
            } else if (saved.view === "drawing") {
                startDrawing(saved.drawing);
            } else if (saved.view === "review") {
                openReview(saved.draft);
            }
        };
        el("campusRouteResumeDiscardBtn").onclick = function () {
            clearDraft();
            box.hidden = true;
        };
    }

    /* ---------- Wiring ---------- */

    function onModeChange(event) {
        const inRoutes = event.detail && event.detail.mode === "routes";
        el("campusSetupMap").classList.toggle("is-routes-mode", inRoutes);
        if (inRoutes) {
            renderAll();
            offerSavedDraft();
        } else {
            renderSaved();
        }
    }

    function setup(map) {
        routes.map = map;
        routes.layers = {
            saved: L.layerGroup().addTo(map),
            work: L.layerGroup().addTo(map),
        };
        map.on("click", onMapClick);
        // While recording, the map follows the walker unless the admin moves it by hand.
        map.on("dragstart", function () {
            routes.followPausedUntil = Date.now() + 15000;
        });
        loadRoutes();
    }

    el("campusRouteRecordBtn").addEventListener("click", function () { startRecording(null); });
    el("campusRouteDrawBtn").addEventListener("click", function () { startDrawing({}); });
    el("campusRouteOffice").addEventListener("change", function () {
        routes.selectedId = null;
        renderList();
        renderSaved();
    });
    el("campusRecPauseBtn").addEventListener("click", function () {
        if (!routes.recorder) {
            return;
        }
        if (routes.recorder.status().state === "paused") {
            routes.recorder.resume();
        } else {
            routes.recorder.pause();
        }
    });
    el("campusRecUndoBtn").addEventListener("click", function () {
        if (routes.recorder) {
            const removed = routes.recorder.undo(10);
            message(removed > 0 ? "Removed the last " + Math.round(removed) + " m. Walk back to the path and continue." : "Nothing to undo yet.", false);
        }
    });
    el("campusRecFinishBtn").addEventListener("click", finishRecording);
    el("campusRecCancelBtn").addEventListener("click", function () { cancelRecording(false); });
    el("campusDrawUndoBtn").addEventListener("click", function () {
        if (routes.drawing && routes.drawing.points.length) {
            routes.drawing.points.pop();
            renderDrawing();
        }
    });
    el("campusDrawReverseBtn").addEventListener("click", function () {
        if (routes.drawing && routes.drawing.points.length > 1) {
            routes.drawing.points.reverse();
            // The old start no longer applies; the review suggests one for the new first point.
            routes.drawing.startLabel = null;
            renderDrawing();
            message("Reversed: the route now starts at the green point and ends at the blue one.", false);
        }
    });
    el("campusDrawFinishBtn").addEventListener("click", finishDrawing);
    el("campusDrawCancelBtn").addEventListener("click", cancelDrawing);
    el("campusReviewClean").addEventListener("change", function () {
        if (routes.draft) {
            routes.draft.clean = el("campusReviewClean").checked;
            renderReview();
        }
    });
    el("campusReviewPin").addEventListener("change", function () {
        if (routes.draft) {
            routes.draft.movePin = el("campusReviewPin").checked;
        }
    });
    el("campusReviewName").addEventListener("input", function () {
        if (routes.draft) {
            routes.draft.autoName = false;
        }
        schedulePersist();
    });
    el("campusReviewStart").addEventListener("input", function () {
        if (routes.draft) {
            routes.draft.autoStart = false;
        }
        if (routes.draft && routes.draft.autoName) {
            routes.draft.name = defaultRouteName(routes.draft, el("campusReviewStart").value.trim());
            el("campusReviewName").value = routes.draft.name;
        }
        schedulePersist();
    });
    el("campusReviewAdjustBtn").addEventListener("click", adjustReview);
    el("campusReviewSaveBtn").addEventListener("click", saveReview);
    el("campusReviewDiscardBtn").addEventListener("click", discardReview);
    document.addEventListener("campus-setup:mode", onModeChange);
    document.addEventListener("campus-setup:changed", function () {
        if (!routes.loaded) {
            return;
        }
        if (routes.view === "idle") {
            fillOfficeSelect();
            renderCoverage();
        } else if (routes.view === "review" && routes.draft) {
            renderReview();
        }
    });
    window.addEventListener("beforeunload", function (event) {
        if (routes.view !== "idle") {
            persistDraft();
            event.preventDefault();
            event.returnValue = "";
        }
    });
    /** What the rest of the Campus Map editor may ask about routes (campus_setup.js). */
    window.CampusRoutes = {
        countFor: function (officeCode) {
            return routes.list.filter(function (route) { return route.office_code === officeCode; }).length;
        },
    };
    CampusSetup.whenReady(setup);
})();
