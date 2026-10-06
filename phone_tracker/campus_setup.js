// Admin "Campus Map" page: draw the campus boundary and place gates and office pins, on the
// map or from where the admin stands (GPS). The Routes tab lives in campus_routes_editor.js
// and uses window.CampusSetup below.
(function () {
    "use strict";

    const officeNames = {
        IT: "IT Department",
        IS: "IS Department",
        CS: "CS Department",
        DEANS: "Dean's Office",
        TECH_SUPPORT: "Tech Support",
    };
    const state = {
        map: null,
        editLayer: null,
        locateLayer: null,
        mode: "boundary",
        lockedMode: null,
        lockReason: "",
        boundary: [],
        // Gates: { name, latitude, longitude, placed_by: "map"|"gps", accuracy_meters }.
        gates: [],
        // Offices: code -> [lat, lng]; officePlacement: code -> { placed_by, accuracy_meters }.
        offices: {},
        officePlacement: {},
        dirty: false,
        loaded: false,
        campus: null,
        sampler: null,
        expand: null,
    };
    const readyCallbacks = [];

    function element(id) {
        return document.getElementById(id);
    }

    function showMessage(message, isError) {
        const box = element("campusSetupMessage");
        box.textContent = message || "";
        box.hidden = !message;
        box.classList.toggle("is-error", Boolean(isError));
        box.classList.toggle("is-success", Boolean(message) && !isError);
    }

    function markDirty() {
        state.dirty = true;
        element("campusSetupStatus").textContent = "Unsaved changes";
        showMessage("");
    }

    function vertexIcon(className) {
        return L.divIcon({ className: className, html: "<span></span>", iconSize: [14, 14], iconAnchor: [7, 7] });
    }

    function draggableMarker(latLng, icon, label, onMove) {
        const marker = L.marker(latLng, { icon: icon, draggable: true, keyboard: false });
        if (label) {
            marker.bindTooltip(label, { permanent: true, direction: "top", offset: [0, -8], className: "campus-setup-label" });
        }
        marker.on("dragend", function () {
            const position = marker.getLatLng();
            onMove([position.lat, position.lng]);
            markDirty();
            render();
        });
        // In the Routes tab a click on a pin counts as a click on the map exactly there, so a
        // route can start on a gate and end on a department's pin (pins keep clicks otherwise).
        marker.on("click", function (event) {
            if (state.mode === "routes") {
                L.DomEvent.stopPropagation(event);
                state.map.fire("click", { latlng: marker.getLatLng(), originalEvent: event.originalEvent });
            }
        });
        return marker;
    }

    /** "GPS ±4 m" for pins placed where the admin stood, "Placed on map" otherwise. */
    function placementText(placedBy, accuracy) {
        return placedBy === "gps"
            ? "GPS" + (accuracy !== null && accuracy !== undefined ? " ±" + Math.round(accuracy) + " m" : "")
            : "Placed on map";
    }

    function render() {
        if (!state.map) {
            return;
        }
        state.editLayer.clearLayers();

        if (state.boundary.length >= 3) {
            L.polygon(state.boundary, {
                color: CampusMap.OFFICE_COLOR, weight: 2, fillOpacity: 0.06, interactive: false,
            }).addTo(state.editLayer);
        } else if (state.boundary.length === 2) {
            L.polyline(state.boundary, { color: CampusMap.OFFICE_COLOR, weight: 2, interactive: false }).addTo(state.editLayer);
        }
        // Pins can only be dragged on their own tab, so routes and corners are not moved by accident.
        const editingPins = state.mode !== "routes";
        state.boundary.forEach(function (corner, index) {
            const marker = draggableMarker(corner, vertexIcon("campus-vertex-pin"), null, function (point) {
                state.boundary[index] = point;
            });
            if (!editingPins) {
                marker.options.draggable = false;
            }
            marker.addTo(state.editLayer);
        });
        state.gates.forEach(function (gate, index) {
            const marker = draggableMarker([gate.latitude, gate.longitude], vertexIcon("campus-gate-pin"), gate.name, function (point) {
                state.gates[index].latitude = point[0];
                state.gates[index].longitude = point[1];
                state.gates[index].placed_by = "map";
                state.gates[index].accuracy_meters = null;
            });
            if (!editingPins) {
                marker.options.draggable = false;
            }
            marker.addTo(state.editLayer);
        });
        Object.keys(state.offices).forEach(function (code) {
            const marker = draggableMarker(state.offices[code], CampusMap.officeIcon(), officeNames[code] || code, function (point) {
                state.offices[code] = point;
                state.officePlacement[code] = { placed_by: "map", accuracy_meters: null };
            });
            if (!editingPins) {
                marker.options.draggable = false;
            }
            marker.addTo(state.editLayer);
        });

        element("campusCornerCount").textContent = state.boundary.length + " corner" + (state.boundary.length === 1 ? "" : "s")
            + (state.boundary.length > 0 && state.boundary.length < 3 ? " — add at least 3" : "");
        renderGateList();
        renderOfficeList();
        document.dispatchEvent(new CustomEvent("campus-setup:changed"));
    }

    function listRow(title, detail, onRemove) {
        const row = document.createElement("div");
        row.className = "campus-setup-row";
        const copy = document.createElement("div");
        const name = document.createElement("strong");
        name.textContent = title;
        const info = document.createElement("span");
        info.textContent = detail;
        copy.append(name, info);
        row.appendChild(copy);
        if (onRemove) {
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "campus-setup-remove";
            remove.setAttribute("aria-label", "Remove " + title);
            remove.textContent = "×";
            remove.addEventListener("click", function () {
                // onRemove returns false when the admin changes their mind.
                if (onRemove() === false) {
                    return;
                }
                markDirty();
                render();
            });
            row.appendChild(remove);
        }
        return row;
    }

    function renderGateList() {
        const host = element("campusGateList");
        host.replaceChildren();
        if (state.gates.length === 0) {
            host.appendChild(listRow("No gates yet", "Add each campus entrance visitors can use.", null));
            return;
        }
        state.gates.forEach(function (gate, index) {
            host.appendChild(listRow(
                gate.name,
                placementText(gate.placed_by, gate.accuracy_meters) + " · " + gate.latitude.toFixed(5) + ", " + gate.longitude.toFixed(5),
                function () { state.gates.splice(index, 1); }
            ));
        });
    }

    function renderOfficeList() {
        const host = element("campusOfficeList");
        host.replaceChildren();
        Object.keys(officeNames).forEach(function (code) {
            const point = state.offices[code];
            const placement = state.officePlacement[code] || { placed_by: "map", accuracy_meters: null };
            host.appendChild(listRow(
                officeNames[code],
                point
                    ? placementText(placement.placed_by, placement.accuracy_meters) + " · " + point[0].toFixed(5) + ", " + point[1].toFixed(5)
                    : "Not placed yet",
                point ? function () {
                    // The pin is the visitor's destination; say what removing it changes.
                    const routeCount = window.CampusRoutes ? window.CampusRoutes.countFor(code) : 0;
                    const consequence = routeCount > 0
                        ? "Visitors will be guided to where its walking route ends instead."
                        : officeNames[code] + " has no walking route either, so visitors will get no directions to it.";
                    if (!window.confirm("Remove the " + officeNames[code] + " pin? " + consequence)) {
                        return false;
                    }
                    delete state.offices[code];
                    delete state.officePlacement[code];
                    return true;
                } : null
            ));
        });
    }

    function setMode(mode) {
        if (state.lockedMode && mode !== state.lockedMode) {
            showMessage(state.lockReason || "Finish what you are doing first.", true);
            return;
        }
        if (state.sampler) {
            stopLocating();
        }
        state.mode = mode;
        document.querySelectorAll("[data-campus-mode]").forEach(function (button) {
            button.classList.toggle("is-active", button.getAttribute("data-campus-mode") === mode);
        });
        document.querySelectorAll("[data-campus-panel]").forEach(function (panel) {
            panel.hidden = panel.getAttribute("data-campus-panel") !== mode;
        });
        render();
        document.dispatchEvent(new CustomEvent("campus-setup:mode", { detail: { mode: mode } }));
    }

    function nextUnplacedOffice() {
        return Object.keys(officeNames).find(function (code) { return !state.offices[code]; });
    }

    function handleMapClick(event) {
        if (state.mode === "routes" || state.sampler) {
            return;
        }
        const point = [event.latlng.lat, event.latlng.lng];
        if (state.mode === "boundary") {
            state.boundary.push(point);
        } else if (state.mode === "gates") {
            const input = element("campusGateName");
            const name = input.value.trim();
            if (!name) {
                showMessage("Type the gate name first, then click where the gate is.", true);
                input.focus();
                return;
            }
            if (state.gates.some(function (gate) { return gate.name.toLowerCase() === name.toLowerCase(); })) {
                showMessage("A gate named \"" + name + "\" already exists.", true);
                return;
            }
            state.gates.push({ name: name, latitude: point[0], longitude: point[1], placed_by: "map", accuracy_meters: null });
            input.value = "";
        } else {
            const select = element("campusOfficeSelect");
            state.offices[select.value] = point;
            state.officePlacement[select.value] = { placed_by: "map", accuracy_meters: null };
            // Move on to the next office that still needs a pin.
            const next = nextUnplacedOffice();
            if (next) {
                select.value = next;
            }
        }
        markDirty();
        render();
    }

    /* ---------- Pin from where the admin stands ---------- */

    function insideBoundary(point) {
        if (state.boundary.length < 3) {
            return true;
        }
        let inside = false;
        for (let i = 0, j = state.boundary.length - 1; i < state.boundary.length; j = i++) {
            const a = state.boundary[i];
            const b = state.boundary[j];
            if ((a[0] > point[0]) !== (b[0] > point[0])
                && point[1] < (b[1] - a[1]) * (point[0] - a[0]) / (b[0] - a[0]) + a[1]) {
                inside = !inside;
            }
        }
        return inside;
    }

    function stopLocating() {
        if (state.sampler) {
            state.sampler.cancel();
            state.sampler = null;
        }
        if (state.locateLayer) {
            state.locateLayer.clearLayers();
        }
        element("campusLocateBox").hidden = true;
        document.querySelectorAll("[data-campus-locate]").forEach(function (button) { button.disabled = false; });
    }

    function drawLocation(latitude, longitude, accuracy) {
        state.locateLayer.clearLayers();
        L.circle([latitude, longitude], {
            radius: accuracy, color: "#2563eb", weight: 1, fillColor: "#2563eb", fillOpacity: 0.12, interactive: false,
        }).addTo(state.locateLayer);
        L.marker([latitude, longitude], {
            icon: L.divIcon({ className: "campus-me-dot", html: "<span></span>", iconSize: [18, 18], iconAnchor: [9, 9] }),
            interactive: false,
            keyboard: false,
        }).addTo(state.locateLayer);
    }

    /**
     * Collects a few GPS readings and averages the accurate ones. `label` names what is being
     * placed; onPlaced(point, accuracy) runs with the result.
     */
    function locateAndPlace(label, onPlaced) {
        const reason = CampusGps.unavailableReason();
        if (reason) {
            showMessage(reason, true);
            return;
        }
        stopLocating();
        showMessage("");
        const box = element("campusLocateBox");
        const status = element("campusLocateStatus");
        const useButton = element("campusLocateUseBtn");
        element("campusLocateTitle").textContent = "Finding your location for " + label;
        status.textContent = "Stand still at the spot. Waiting for GPS…";
        useButton.disabled = true;
        useButton.textContent = "Use this location";
        box.hidden = false;
        document.querySelectorAll("[data-campus-locate]").forEach(function (button) { button.disabled = true; });
        let centered = false;
        const sampler = CampusGps.samplePosition({
            onProgress: function (progress) {
                const latest = progress.latest;
                drawLocation(latest.latitude, latest.longitude, latest.accuracy);
                if (!centered) {
                    centered = true;
                    state.map.setView([latest.latitude, latest.longitude], Math.max(state.map.getZoom(), 19));
                }
                const estimate = progress.estimate;
                status.textContent = "GPS ±" + Math.round(latest.accuracy) + " m now · " + progress.good + " of "
                    + progress.needed + " accurate readings (±8 m or better). Stay still.";
                useButton.disabled = !estimate;
                useButton.textContent = estimate ? "Use this location (±" + Math.round(estimate.accuracy) + " m)" : "Use this location";
            },
        });
        state.sampler = sampler;
        sampler.promise.then(function (result) {
            state.sampler = null;
            stopLocating();
            const point = [result.latitude, result.longitude];
            onPlaced(point, result.accuracy);
            markDirty();
            render();
            state.map.setView(point, Math.max(state.map.getZoom(), 19));
            let message = label + " placed where you stand (GPS ±" + Math.round(result.accuracy) + " m, "
                + result.readings + " readings). Press Save campus map to keep it.";
            if (!insideBoundary(point)) {
                message += " It is outside the campus boundary; adjust the boundary or the pin before saving.";
            }
            showMessage(message, !insideBoundary(point));
        }).catch(function (error) {
            state.sampler = null;
            stopLocating();
            if (!error || !error.cancelled) {
                showMessage(CampusGps.errorMessage(error), true);
            }
        });
    }

    function locateOffice() {
        const code = element("campusOfficeSelect").value;
        locateAndPlace(officeNames[code] || code, function (point, accuracy) {
            state.offices[code] = point;
            state.officePlacement[code] = { placed_by: "gps", accuracy_meters: accuracy };
        });
    }

    function locateGate() {
        const input = element("campusGateName");
        const name = input.value.trim();
        if (!name) {
            showMessage("Type the gate name first, then stand at the gate and press Add gate at my location.", true);
            input.focus();
            return;
        }
        if (state.gates.some(function (gate) { return gate.name.toLowerCase() === name.toLowerCase(); })) {
            showMessage("A gate named \"" + name + "\" already exists.", true);
            return;
        }
        locateAndPlace(name, function (point, accuracy) {
            state.gates.push({ name: name, latitude: point[0], longitude: point[1], placed_by: "gps", accuracy_meters: accuracy });
            input.value = "";
        });
    }

    /* ---------- Loading and saving ---------- */

    function applyCampus(campus) {
        state.campus = campus;
        state.boundary = campus && Array.isArray(campus.boundary) ? campus.boundary.map(function (p) { return [Number(p[0]), Number(p[1])]; }) : [];
        state.gates = campus && Array.isArray(campus.gates)
            ? campus.gates.map(function (g) {
                return {
                    name: g.name,
                    latitude: Number(g.latitude),
                    longitude: Number(g.longitude),
                    placed_by: g.placed_by === "gps" ? "gps" : "map",
                    accuracy_meters: g.accuracy_meters === null || g.accuracy_meters === undefined ? null : Number(g.accuracy_meters),
                };
            })
            : [];
        state.offices = {};
        state.officePlacement = {};
        (campus && campus.offices || []).forEach(function (office) {
            state.offices[office.code] = [Number(office.latitude), Number(office.longitude)];
            state.officePlacement[office.code] = {
                placed_by: office.placed_by === "gps" ? "gps" : "map",
                accuracy_meters: office.accuracy_meters === null || office.accuracy_meters === undefined ? null : Number(office.accuracy_meters),
            };
        });
        state.dirty = false;
        element("campusSetupStatus").textContent = campus && campus.updated_at ? "Saved " + campus.updated_at : "Not set up yet";
        render();
    }

    async function initialize() {
        if (state.map) {
            state.map.invalidateSize();
            return;
        }
        if (typeof L === "undefined") {
            showMessage("The map library could not load. Check your internet connection and refresh the page.", true);
            return;
        }
        state.map = L.map("campusSetupMap", CampusMap.mapOptions({ doubleClickZoom: false })).setView([10.7177, 122.5559], 17);
        CampusMap.addBaseLayer(state.map);
        CampusMap.addScale(state.map);
        // Full view keeps the tools panel beside the map, for placing pins precisely.
        state.expand = CampusMap.addExpandControl(state.map, document.querySelector(".campus-setup-layout"));
        state.editLayer = L.layerGroup().addTo(state.map);
        state.locateLayer = L.layerGroup().addTo(state.map);
        state.map.on("click", handleMapClick);
        render();
        const campus = await CampusMap.load();
        state.loaded = true;
        applyCampus(campus);
        const bounds = CampusMap.boundaryBounds(campus);
        if (bounds) {
            state.map.fitBounds(bounds, { padding: [30, 30] });
        }
        while (readyCallbacks.length) {
            readyCallbacks.shift()(state.map);
        }
        document.dispatchEvent(new CustomEvent("campus-setup:ready", { detail: { map: state.map } }));
    }

    /** Saves the boundary, gates, and office pins. Resolves true when saved. */
    async function save() {
        if (state.boundary.length < 3) {
            setMode("boundary");
            showMessage("Draw the campus boundary with at least 3 corners before saving.", true);
            return false;
        }
        const button = element("campusSaveBtn");
        button.disabled = true;
        try {
            const response = await fetch("admin_save_campus_map.php", {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    boundary: state.boundary,
                    gates: state.gates,
                    offices: Object.keys(state.offices).map(function (code) {
                        const placement = state.officePlacement[code] || { placed_by: "map", accuracy_meters: null };
                        return {
                            code: code,
                            latitude: state.offices[code][0],
                            longitude: state.offices[code][1],
                            placed_by: placement.placed_by,
                            accuracy_meters: placement.accuracy_meters,
                        };
                    }),
                }),
            });
            const data = await response.json();
            if (!data || !data.success) {
                showMessage((data && data.message) || "Could not save the campus map.", true);
                return false;
            }
            applyCampus(data.campus);
            showMessage("Campus map saved. Maps and route analytics now use this boundary.", false);
            document.dispatchEvent(new CustomEvent("campus:updated"));
            return true;
        } catch (error) {
            showMessage("Could not reach the server.", true);
            return false;
        } finally {
            button.disabled = false;
        }
    }

    function fillOfficeSelect() {
        const select = element("campusOfficeSelect");
        const current = select.value;
        select.replaceChildren();
        Object.keys(officeNames).forEach(function (code) {
            const option = document.createElement("option");
            option.value = code;
            option.textContent = officeNames[code];
            select.appendChild(option);
        });
        if (officeNames[current]) {
            select.value = current;
        }
    }

    // Departments come from the directory, so new ones can get a pin. The list above
    // stays if the directory cannot be loaded.
    function loadOfficeNames() {
        fetch("office_directory.php?all=1", { credentials: "same-origin", cache: "no-store" })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.success || !Array.isArray(data.offices) || !data.offices.length) {
                    return;
                }
                Object.keys(officeNames).forEach(function (code) { delete officeNames[code]; });
                data.offices.forEach(function (office) {
                    officeNames[office.code] = office.name + (office.is_active ? "" : " (archived)");
                });
                fillOfficeSelect();
                render();
            })
            .catch(function () {});
    }

    /** What the Routes tab (campus_routes_editor.js) can read and change. */
    window.CampusSetup = {
        whenReady: function (callback) {
            if (state.map && state.loaded) {
                callback(state.map);
            } else {
                readyCallbacks.push(callback);
            }
        },
        map: function () { return state.map; },
        mode: function () { return state.mode; },
        officeNames: function () { return Object.assign({}, officeNames); },
        officePins: function () {
            const pins = {};
            Object.keys(state.offices).forEach(function (code) {
                const placement = state.officePlacement[code] || { placed_by: "map", accuracy_meters: null };
                pins[code] = { latitude: state.offices[code][0], longitude: state.offices[code][1], placed_by: placement.placed_by, accuracy_meters: placement.accuracy_meters };
            });
            return pins;
        },
        gates: function () { return state.gates.map(function (gate) { return Object.assign({}, gate); }); },
        boundary: function () { return state.boundary.map(function (point) { return point.slice(); }); },
        isInsideBoundary: insideBoundary,
        /** Keeps the editor on one tab (while recording or drawing a route). */
        lockMode: function (mode, reason) {
            state.lockedMode = mode;
            state.lockReason = reason || "";
        },
        unlockMode: function () {
            state.lockedMode = null;
            state.lockReason = "";
        },
        setOfficePin: function (code, point, accuracy) {
            state.offices[code] = [point[0], point[1]];
            state.officePlacement[code] = { placed_by: accuracy === null || accuracy === undefined ? "map" : "gps", accuracy_meters: accuracy === undefined ? null : accuracy };
            markDirty();
            render();
        },
        /** Full view: the map and this panel fill the screen (used while walking a route on a phone). */
        setExpanded: function (expanded) {
            if (state.expand) {
                state.expand.toggle(Boolean(expanded));
            }
        },
        isExpanded: function () { return Boolean(state.expand && state.expand.isExpanded()); },
        isDirty: function () { return state.dirty; },
        save: save,
        showMessage: showMessage,
    };

    fillOfficeSelect();
    loadOfficeNames();
    document.addEventListener("departments:updated", loadOfficeNames);
    document.querySelectorAll("[data-campus-mode]").forEach(function (button) {
        button.addEventListener("click", function () {
            setMode(button.getAttribute("data-campus-mode"));
        });
    });
    element("campusUndoCornerBtn").addEventListener("click", function () {
        if (state.boundary.length > 0) {
            state.boundary.pop();
            markDirty();
            render();
        }
    });
    element("campusClearBoundaryBtn").addEventListener("click", function () {
        if (state.boundary.length > 0 && window.confirm("Remove all boundary corners?")) {
            state.boundary = [];
            markDirty();
            render();
        }
    });
    element("campusOfficeLocateBtn").addEventListener("click", locateOffice);
    element("campusGateLocateBtn").addEventListener("click", locateGate);
    element("campusLocateUseBtn").addEventListener("click", function () {
        if (state.sampler) {
            state.sampler.accept();
        }
    });
    element("campusLocateCancelBtn").addEventListener("click", stopLocating);
    element("campusSaveBtn").addEventListener("click", save);
    window.addEventListener("beforeunload", function (event) {
        if (state.dirty) {
            event.preventDefault();
            event.returnValue = "";
        }
    });
    document.addEventListener("admin:viewchange", function (event) {
        if (event.detail && event.detail.view === "campus") {
            initialize();
        }
    });
})();
