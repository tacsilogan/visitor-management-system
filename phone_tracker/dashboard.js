(function () {
    "use strict";

    if (!PhoneTrackerAuth.requireRole(["security", "admin"])) {
        return;
    }

    const dashboardState = {
        visitors: [],
        status: "",
        type: "",
        query: "",
        page: 1,
        pageSize: 15,
    };

    // Live map. Positions only ever cover the inside of the campus; see LIVE_MONITORING.md.
    const monitoringState = {
        map: null,
        initialized: false,
        /** The campus outline and pins, kept to re-apply the map limits after a resize. */
        campus: null,
        markerLayer: null,
        trailLayer: null,
        markers: new Map(),
        active: [],
        finished: [],
        rules: { trail_max_step_meters: 120, trail_max_gap_seconds: 300, overstay_grace_minutes: 30, exit_confirm_seconds: 300 },
        serverOffset: 0,
        tab: "active",
        query: "",
        selectedId: null,
        follow: false,
        fittedOnce: false,
        trail: null,
        trailLoading: false,
        trailRequest: 0,
        livePromise: null,
    };

    const LABEL_LIMIT = 15;
    const CHECKOUT_LABELS = {
        scan: { label: "Checked out", className: "is-completed" },
        guard: { label: "Ended by guard", className: "is-completed" },
        left_campus: { label: "Left campus", className: "is-cancelled" },
        end_of_day: { label: "Ended at day end", className: "is-cancelled" },
    };
    const CHECKOUT_PHRASES = {
        scan: "Checked out at the gate",
        guard: "Ended by Security",
        left_campus: "Left the campus without checking out",
        end_of_day: "Still open at the end of the day; ended automatically",
    };
    const STATE_LABELS = {
        live: "Live",
        stale: "Stale",
        offline: "Offline",
        waiting: "Waiting for GPS",
        outside: "Outside campus",
        ended: "Visit ended",
    };
    const STOP_LABELS = {
        checked_in: "Checked in",
        completed: "Meeting done",
        approved: "Not visited yet",
        pending_approval: "Waiting for approval",
        reschedule_proposed: "New time offered",
        cancelled: "Cancelled",
        rejected: "Declined",
        unanswered: "No office reply",
        window_closed: "Missed",
    };

    const visitorsBody = document.getElementById("securityVisitorsBody");
    const visitorsEmpty = document.getElementById("securityVisitorsEmpty");
    const profileButton = document.getElementById("securityProfileBtn");
    const profileMenu = document.getElementById("securityProfileMenu");
    const monitorList = document.getElementById("monitorList");
    const monitorPanel = document.getElementById("monitorPanel");

    function setText(id, value) {
        document.getElementById(id).textContent = String(value == null ? "" : value);
    }

    function showMessage(id, message) {
        const element = document.getElementById(id);
        element.textContent = message || "";
        element.hidden = !message;
    }

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text != null) {
            node.textContent = String(text);
        }
        return node;
    }

    async function fetchJson(url, options) {
        const response = await fetch(url, Object.assign({ credentials: "same-origin" }, options || {}));
        let data;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error("The server returned an invalid response.");
        }
        if (response.status === 401 || (data && data.message === "Not authenticated")) {
            window.location.href = "login.html";
            throw new Error("Not authenticated");
        }
        if (response.status === 403) {
            throw new Error("This account does not have security access.");
        }
        return data;
    }

    function parseServerDate(value) {
        if (!value) {
            return null;
        }
        const date = new Date(String(value).replace(" ", "T"));
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function formatTime(value) {
        const date = parseServerDate(value);
        if (!date) {
            return "—";
        }
        return new Intl.DateTimeFormat(undefined, {
            hour: "numeric",
            minute: "2-digit",
        }).format(date);
    }

    /** The server's clock, so a phone with a wrong clock still shows correct durations. */
    function serverNow() {
        return Date.now() + monitoringState.serverOffset;
    }

    function syncServerClock(serverTime) {
        const server = parseServerDate(serverTime);
        if (server) {
            monitoringState.serverOffset = server.getTime() - Date.now();
        }
    }

    function secondsSince(value) {
        const date = parseServerDate(value);
        return date ? Math.max(0, Math.round((serverNow() - date.getTime()) / 1000)) : null;
    }

    /** "45 s", "12 min", "1 h 05 min". */
    function formatDuration(seconds) {
        const value = Math.max(0, Math.round(Number(seconds) || 0));
        if (value < 60) {
            return value + " s";
        }
        const minutes = Math.floor(value / 60);
        if (minutes < 60) {
            return minutes + " min";
        }
        const hours = Math.floor(minutes / 60);
        return hours + " h " + String(minutes % 60).padStart(2, "0") + " min";
    }

    function formatAgo(seconds) {
        if (seconds == null) {
            return "—";
        }
        return seconds < 10 ? "just now" : formatDuration(seconds) + " ago";
    }

    function formatDistance(meters) {
        const value = Number(meters) || 0;
        return value < 1000 ? Math.round(value) + " m" : (value / 1000).toFixed(2) + " km";
    }

    /** "Slot ends in 12 min", "Past slot · 8 min", or "Overstay · 45 min" for a visitor on campus. */
    function slotStatus(visitor) {
        const end = parseServerDate(visitor.scheduled_end_at);
        if (!end || visitor.status !== "checked_in") {
            return null;
        }
        const seconds = Math.round((end.getTime() - serverNow()) / 1000);
        if (seconds > 0) {
            return { tone: seconds <= 600 ? "warn" : "info", text: "Slot ends in " + formatDuration(seconds), overstay: false };
        }
        const past = -seconds;
        const grace = (monitoringState.rules.overstay_grace_minutes || 30) * 60;
        if (past < grace) {
            return { tone: "warn", text: "Past slot · " + formatDuration(past), overstay: false };
        }
        return { tone: "danger", text: "Overstay · " + formatDuration(past), overstay: true };
    }

    function shortName(name) {
        const parts = String(name || "Visitor").trim().split(/\s+/);
        return parts.length > 1 ? parts[0] + " " + parts[parts.length - 1].charAt(0) + "." : parts[0];
    }

    function bearing(lat1, lng1, lat2, lng2) {
        const toRad = Math.PI / 180;
        const y = Math.sin((lng2 - lng1) * toRad) * Math.cos(lat2 * toRad);
        const x = Math.cos(lat1 * toRad) * Math.sin(lat2 * toRad)
            - Math.sin(lat1 * toRad) * Math.cos(lat2 * toRad) * Math.cos((lng2 - lng1) * toRad);
        return (Math.atan2(y, x) / toRad + 360) % 360;
    }

    function arrowSvg() {
        const ns = "http://www.w3.org/2000/svg";
        const svg = document.createElementNS(ns, "svg");
        svg.setAttribute("viewBox", "0 0 24 24");
        svg.setAttribute("aria-hidden", "true");
        const path = document.createElementNS(ns, "path");
        path.setAttribute("d", "M12 3 19 20 12 16 5 20Z");
        svg.appendChild(path);
        return svg;
    }

    /* ---------- Visitor records table ---------- */

    function statusMeta(visitor) {
        if (visitor.status === "checked_in") {
            return visitor.is_inside_campus
                ? { label: "Active — inside", className: "is-active" }
                : { label: "Inactive", className: "is-completed" };
        }
        if (visitor.status === "completed") {
            return CHECKOUT_LABELS[visitor.checkout_method] || { label: "Inactive", className: "is-completed" };
        }
        const map = {
            pending_approval: { label: "Pending approval", className: "is-pending" },
            approved: { label: "Approved", className: "is-active" },
            rejected: { label: "Declined", className: "is-cancelled" },
            unanswered: { label: "Office did not respond", className: "is-cancelled" },
            reschedule_proposed: { label: "Reschedule proposed", className: "is-pending" },
            window_closed: { label: "Appointment done", className: "is-completed" },
            cancelled: { label: "Cancelled", className: "is-cancelled" },
        };
        return map[visitor.status] || { label: visitor.status || "Unknown", className: "is-completed" };
    }

    function appendCell(row, value, className) {
        const cell = document.createElement("td");
        cell.textContent = value == null || value === "" ? "—" : String(value);
        if (className) {
            cell.className = className;
        }
        row.appendChild(cell);
        return cell;
    }

    function switchSecurityView(view) {
        document.querySelectorAll("[data-security-panel]").forEach(function (panel) {
            panel.hidden = panel.getAttribute("data-security-panel") !== view;
        });
        document.querySelectorAll("[data-security-view]").forEach(function (button) {
            const active = button.getAttribute("data-security-view") === view;
            button.classList.toggle("is-active", active);
            if (active) {
                button.setAttribute("aria-current", "page");
            } else {
                button.removeAttribute("aria-current");
            }
        });
        window.history.replaceState(null, "", view === "monitoring" ? "#monitoring" : window.location.pathname);
        if (view === "monitoring") {
            initializeMap();
            window.setTimeout(function () {
                if (monitoringState.map) {
                    monitoringState.map.invalidateSize();
                }
            }, 80);
            loadLiveLocations();
        }
        window.scrollTo({ top: 0, behavior: "smooth" });
    }

    function monitoringVisible() {
        return !document.getElementById("securityMonitoringView").hidden;
    }

    function filteredVisitors() {
        const query = dashboardState.query.toLowerCase();
        const today = new Date();
        const todayKey = [today.getFullYear(), String(today.getMonth() + 1).padStart(2, "0"), String(today.getDate()).padStart(2, "0")].join("-");
        return dashboardState.visitors.filter(function (visitor) {
            if (dashboardState.status && visitor.status !== dashboardState.status) {
                return false;
            }
            if (dashboardState.type) {
                const type = String(visitor.visit_type || "Appointment").toLowerCase().replace(/_/g, "-");
                const wantsWalkIn = dashboardState.type === "walk-in";
                const matchesType = wantsWalkIn ? type === "walk-in" : type !== "walk-in";
                const relevantDate = String(wantsWalkIn ? (visitor.checked_in_at || visitor.appointment_at || "") : (visitor.appointment_at || "")).slice(0, 10);
                if (!matchesType || relevantDate !== todayKey) {
                    return false;
                }
            }
            const haystack = [
                visitor.registration_id,
                visitor.visitor_full_name,
                visitor.status,
                visitor.purpose,
                visitor.office_label,
                visitor.office_code,
                visitor.subject,
                visitor.visit_type,
            ].join(" ").toLowerCase();
            return !query || haystack.indexOf(query) !== -1;
        });
    }

    function renderVisitorPages(totalPages) {
        const host = document.getElementById("securityPageButtons");
        host.replaceChildren();
        if (totalPages <= 1) {
            return;
        }
        for (let page = 1; page <= totalPages; page += 1) {
            const button = document.createElement("button");
            button.type = "button";
            button.textContent = String(page);
            button.classList.toggle("is-active", page === dashboardState.page);
            button.setAttribute("aria-label", "Page " + page);
            if (page === dashboardState.page) {
                button.setAttribute("aria-current", "page");
            }
            button.addEventListener("click", function () {
                dashboardState.page = page;
                renderVisitors();
            });
            host.appendChild(button);
        }
    }

    function createRowAction(label, className, visitor) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = className;
        button.textContent = label;
        button.dataset.appointmentId = String(visitor.id);
        return button;
    }

    function renderVisitors() {
        const visitors = filteredVisitors();
        const totalPages = Math.max(1, Math.ceil(visitors.length / dashboardState.pageSize));
        if (dashboardState.page > totalPages) {
            dashboardState.page = totalPages;
        }
        const start = (dashboardState.page - 1) * dashboardState.pageSize;
        const pageVisitors = visitors.slice(start, start + dashboardState.pageSize);
        visitorsBody.replaceChildren();

        pageVisitors.forEach(function (visitor) {
            const row = document.createElement("tr");
            const status = statusMeta(visitor);
            appendCell(row, visitor.registration_id, "security-registration-cell");
            appendCell(row, visitor.visitor_full_name || "Visitor", "admin-cell-strong");

            const statusCell = document.createElement("td");
            const statusBadge = document.createElement("span");
            statusBadge.className = "admin-status " + status.className;
            statusBadge.textContent = status.label;
            statusCell.appendChild(statusBadge);
            row.appendChild(statusCell);

            appendCell(row, visitor.purpose || "—");
            appendCell(row, visitor.office_label || visitor.office_code || "—");
            appendCell(row, visitor.subject || "—");
            appendCell(row, formatTime(visitor.checked_in_at));
            const timeOut = appendCell(
                row,
                visitor.is_inside_campus ? "Still inside" : formatTime(visitor.completed_at || visitor.cancelled_at),
                visitor.is_inside_campus ? "security-active-time" : "",
            );
            if (visitor.is_inside_campus) {
                const slot = slotStatus({ status: "checked_in", scheduled_end_at: visitor.scheduled_end_at });
                if (slot && slot.tone !== "info") {
                    timeOut.appendChild(element("small", "security-slot-note is-" + slot.tone, slot.text));
                }
            }

            const actions = document.createElement("td");
            actions.className = "security-row-actions";
            if (visitor.is_inside_campus) {
                const monitor = createRowAction("Monitor", "security-monitor-button", visitor);
                monitor.dataset.action = "monitor";
                const end = createRowAction("End visit", "security-end-button", visitor);
                end.dataset.action = "complete";
                actions.append(monitor, end);
            } else if (visitor.status === "completed") {
                const route = createRowAction("View route", "security-monitor-button", visitor);
                route.dataset.action = "monitor";
                actions.append(route);
            }
            row.appendChild(actions);
            visitorsBody.appendChild(row);
        });

        visitorsEmpty.hidden = visitors.length > 0;
        const wrap = visitorsBody.closest(".admin-table-wrap");
        if (wrap) {
            wrap.hidden = visitors.length === 0;
        }
        if (visitors.length === 0) {
            setText("securityVisitorsShowing", "Showing 0 visitors");
        } else {
            setText("securityVisitorsShowing", "Showing " + (start + 1) + "–" + (start + pageVisitors.length) + " of " + visitors.length + " visitors");
        }
        renderVisitorPages(totalPages);
    }

    async function loadSecurityDashboard() {
        showMessage("securityDashboardError", "");
        try {
            const data = await fetchJson("security_dashboard.php");
            if (!data || !data.success) {
                showMessage("securityDashboardError", (data && data.message) || "Could not load visitor records.");
                return;
            }
            const summary = data.summary || {};
            setText("insideCampusCount", summary.inside_campus || 0);
            setText("walkInTodayCount", summary.walk_in_today || 0);
            setText("appointmentTodayCount", summary.appointment_today || 0);
            setText("checkedOutTodayCount", summary.checked_out_today || 0);
            dashboardState.visitors = Array.isArray(data.visitors) ? data.visitors : [];
            renderVisitors();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("securityDashboardError", error.message || "Could not reach the server.");
            }
        }
    }

    async function completeVisit(appointmentId, name, button) {
        if (!(await AppDialog.confirm({
            title: "End the visit for " + (name || "this visitor") + "?",
            message: "Use this only if they cannot show their pass at the gate.",
            confirmLabel: "End visit",
        }))) {
            return false;
        }
        if (button) {
            button.disabled = true;
        }
        try {
            const data = await fetchJson("complete_appointment.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ id: appointmentId }),
            });
            if (!data || !data.success) {
                AppDialog.alert({ title: "Couldn't end the visit", message: (data && data.message) || "Please try again." });
                return false;
            }
            await Promise.all([loadSecurityDashboard(), loadLiveLocations(true)]);
            return true;
        } catch (error) {
            if (error.message !== "Not authenticated") {
                AppDialog.alert(error instanceof TypeError
                    ? { title: "Couldn't reach the server", message: "Check your connection and try again." }
                    : { title: "Couldn't end the visit", message: error.message || "Please try again." });
            }
            return false;
        } finally {
            if (button) {
                button.disabled = false;
            }
        }
    }

    /* ---------- Live map ---------- */

    function initializeMap() {
        if (monitoringState.initialized) {
            return;
        }
        monitoringState.initialized = true;
        if (typeof L === "undefined") {
            showMessage("securityMapError", "The map library could not load. Check your internet connection and refresh the page.");
            return;
        }
        // Zoom buttons sit top-right so they never cover the visitor panel on the left.
        const map = L.map("securityMap", CampusMap.mapOptions({ zoomControl: false })).setView([10.7177, 122.5559], 17);
        L.control.zoom({ position: "topright" }).addTo(map);
        monitoringState.map = map;
        CampusMap.addBaseLayer(map);
        CampusMap.addScale(map);
        CampusMap.enableLabels(map);
        // Full view keeps the visitor panel with the map.
        CampusMap.addExpandControl(map, document.getElementById("monitorShell"), {
            onChange: function () {
                CampusMap.lockToCampus(map, monitoringState.campus, false);
            },
        });
        const campusLayer = L.layerGroup().addTo(map);
        monitoringState.trailLayer = L.layerGroup().addTo(map);
        monitoringState.markerLayer = L.layerGroup().addTo(map);
        CampusMap.load().then(function (campus) {
            monitoringState.campus = campus;
            drawCampus(campusLayer, campus);
            // Keep the map on the ISATU campus; frame it unless visitors were already framed.
            CampusMap.lockToCampus(map, campus, !monitoringState.fittedOnce);
        });
        map.on("dragstart", function () {
            if (monitoringState.follow) {
                setFollow(false);
            }
        });
    }

    /** The campus boundary as a bold line, with everything outside it dimmed. */
    function drawCampus(layer, campus) {
        CampusMap.drawOverlay(layer, campus);
        if (!campus || !Array.isArray(campus.boundary) || campus.boundary.length < 3) {
            document.getElementById("monitorPrivacyNote").textContent =
                "No campus boundary has been set, so positions cannot be limited to the campus. An administrator can draw it in Campus map setup.";
            return;
        }
        const world = [[-85, -179.9], [-85, 179.9], [85, 179.9], [85, -179.9]];
        L.polygon([world, campus.boundary], {
            stroke: false,
            fillColor: "#0d1b2a",
            fillOpacity: 0.32,
            interactive: false,
        }).addTo(layer);
        L.polygon(campus.boundary, {
            color: "#0755b5",
            weight: 4,
            opacity: 0.55,
            fill: false,
            interactive: false,
        }).addTo(layer);
    }

    function findVisitor(id) {
        const key = Number(id);
        const inList = monitoringState.active.find(function (visitor) { return visitor.id === key; })
            || monitoringState.finished.find(function (visitor) { return visitor.id === key; });
        if (inList) {
            return inList;
        }
        const trail = monitoringState.trail;
        return trail && trail.id === key ? trail.visitor : null;
    }

    function stateText(visitor) {
        if (visitor.status !== "checked_in") {
            return STATE_LABELS.ended;
        }
        if (visitor.location_state === "outside") {
            const outside = secondsSince(visitor.outside_since);
            return "Outside campus" + (outside != null ? " · " + formatDuration(outside) : "");
        }
        if (visitor.location_state === "offline" || visitor.location_state === "stale") {
            return STATE_LABELS[visitor.location_state] + " · " + formatDuration(visitor.seconds_since_update || 0);
        }
        return STATE_LABELS[visitor.location_state] || STATE_LABELS.waiting;
    }

    function markerIcon() {
        return L.divIcon({
            className: "monitor-marker",
            html: '<span class="monitor-marker-halo"></span>'
                + '<span class="monitor-marker-body"><svg class="monitor-marker-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 19 20 12 16 5 20Z"/></svg></span>'
                + '<span class="monitor-marker-label"></span>',
            iconSize: [34, 34],
            iconAnchor: [17, 17],
        });
    }

    function styleMarker(entry, visitor) {
        const node = entry.marker.getElement();
        if (!node) {
            return;
        }
        const selected = monitoringState.selectedId === visitor.id;
        node.dataset.state = visitor.location_state;
        node.classList.toggle("is-moving", Boolean(visitor.moving && visitor.heading != null));
        node.classList.toggle("is-selected", selected);
        node.classList.toggle("is-dimmed", monitoringState.selectedId != null && !selected);
        node.querySelector(".monitor-marker-arrow").style.transform = "rotate(" + Number(visitor.heading || 0) + "deg)";
        node.querySelector(".monitor-marker-label").textContent = shortName(visitor.visitor_full_name);
        node.setAttribute("aria-label", visitor.visitor_full_name + ", " + stateText(visitor) + ". Show details");
        entry.marker.setZIndexOffset(selected ? 1000 : 0);
    }

    /** Glides a marker to its new position instead of jumping. */
    function moveMarker(entry, target) {
        window.cancelAnimationFrame(entry.animation || 0);
        const from = entry.marker.getLatLng();
        const distance = from.distanceTo(target);
        if (distance < 0.5 || distance > 250 || document.hidden) {
            entry.marker.setLatLng(target);
            return;
        }
        const started = performance.now();
        const duration = 900;
        function step(now) {
            const progress = Math.min(1, (now - started) / duration);
            const eased = 1 - Math.pow(1 - progress, 3);
            entry.marker.setLatLng([
                from.lat + (target.lat - from.lat) * eased,
                from.lng + (target.lng - from.lng) * eased,
            ]);
            if (progress < 1) {
                entry.animation = window.requestAnimationFrame(step);
            }
        }
        entry.animation = window.requestAnimationFrame(step);
    }

    function updateMarkers() {
        const map = monitoringState.map;
        if (!map) {
            return;
        }
        const seen = new Set();
        const visible = [];
        monitoringState.active.forEach(function (visitor) {
            if (!visitor.has_location) {
                return;
            }
            seen.add(visitor.id);
            const target = L.latLng(visitor.latitude, visitor.longitude);
            visible.push(target);
            let entry = monitoringState.markers.get(visitor.id);
            if (!entry) {
                const marker = L.marker(target, { icon: markerIcon(), keyboard: true, riseOnHover: true });
                marker.on("click", function () {
                    selectVisitor(visitor.id, { pan: false });
                });
                marker.addTo(monitoringState.markerLayer);
                entry = { marker: marker, animation: 0 };
                monitoringState.markers.set(visitor.id, entry);
            } else {
                moveMarker(entry, target);
            }
            styleMarker(entry, visitor);
        });
        monitoringState.markers.forEach(function (entry, id) {
            if (!seen.has(id)) {
                window.cancelAnimationFrame(entry.animation || 0);
                monitoringState.markerLayer.removeLayer(entry.marker);
                monitoringState.markers.delete(id);
            }
        });
        map.getContainer().classList.toggle("monitor-many", monitoringState.markers.size > LABEL_LIMIT);

        if (!monitoringState.fittedOnce && visible.length > 0 && monitoringState.selectedId == null) {
            map.fitBounds(L.latLngBounds(visible), mapPadding({ maxZoom: 18 }));
            monitoringState.fittedOnce = true;
        }
        const selected = monitoringState.selectedId != null ? monitoringState.markers.get(monitoringState.selectedId) : null;
        if (monitoringState.follow && selected) {
            const visitor = findVisitor(monitoringState.selectedId);
            if (visitor && visitor.has_location) {
                map.panTo([visitor.latitude, visitor.longitude], { animate: true, duration: 0.8 });
            }
        }
    }

    /** Map padding that keeps fitted content clear of the panel on wide screens. */
    function mapPadding(extra) {
        const wide = window.innerWidth > 720;
        const panelWidth = wide && monitorPanel ? monitorPanel.offsetWidth + 36 : 24;
        return Object.assign(wide
            ? { paddingTopLeft: [panelWidth, 40], paddingBottomRight: [40, 40] }
            : { paddingTopLeft: [24, 24], paddingBottomRight: [24, Math.min(window.innerHeight * 0.45, 360)] }, extra || {});
    }

    function renderSummary() {
        const host = document.getElementById("monitorSummary");
        host.replaceChildren();
        const counts = { live: 0, stale: 0, offline: 0, waiting: 0, outside: 0 };
        let overstays = 0;
        monitoringState.active.forEach(function (visitor) {
            counts[visitor.location_state] = (counts[visitor.location_state] || 0) + 1;
            const slot = slotStatus(visitor);
            if (slot && slot.overstay) {
                overstays += 1;
            }
        });
        const total = monitoringState.active.length;
        host.appendChild(element("strong", "monitor-summary-total", total + (total === 1 ? " visitor on campus" : " visitors on campus")));
        [["live", "live"], ["stale", "stale"], ["offline", "offline"], ["waiting", "waiting for GPS"], ["outside", "outside"]].forEach(function (pair) {
            if (counts[pair[0]] > 0 || pair[0] === "live") {
                const chip = element("span", "monitor-summary-chip is-" + pair[0]);
                chip.append(element("i"), document.createTextNode(counts[pair[0]] + " " + pair[1]));
                host.appendChild(chip);
            }
        });
        if (overstays > 0) {
            host.appendChild(element("span", "monitor-summary-chip is-danger", overstays + " overstaying"));
        }
    }

    function listForTab() {
        const list = monitoringState.tab === "active" ? monitoringState.active : monitoringState.finished;
        const query = monitoringState.query.trim().toLowerCase();
        if (!query) {
            return list;
        }
        return list.filter(function (visitor) {
            return [visitor.visitor_full_name, visitor.office_label, visitor.registration_id, visitor.purpose, visitor.subject]
                .join(" ").toLowerCase().indexOf(query) !== -1;
        });
    }

    /** "confirmed by GPS (±4 m)" or "confirmed by the visitor", for an arrival the phone reported. */
    function arrivalHow(arrival) {
        if (arrival.method === "gps") {
            return "confirmed by GPS" + (arrival.accuracy_meters != null ? " (±" + Math.round(arrival.accuracy_meters) + " m)" : "");
        }
        return "confirmed by the visitor";
    }

    /** The confirmed arrival at the office the visitor is at now (their current stop), if any. */
    function currentArrival(visitor) {
        const current = (visitor.stops || []).find(function (stop) { return stop.status === "checked_in"; });
        if (!current || !current.arrived_at) {
            return null;
        }
        return (visitor.arrivals || []).find(function (arrival) { return arrival.office_code === current.office_code; }) || null;
    }

    function renderList() {
        setText("monitorActiveCount", monitoringState.active.length);
        setText("monitorFinishedCount", monitoringState.finished.length);
        const focusedId = document.activeElement && document.activeElement.dataset
            ? document.activeElement.dataset.monitorId : null;
        const list = listForTab();
        monitorList.replaceChildren();
        list.forEach(function (visitor) {
            const item = element("li");
            const row = element("button", "monitor-row" + (monitoringState.selectedId === visitor.id ? " is-selected" : ""));
            row.type = "button";
            row.dataset.monitorId = String(visitor.id);
            const onCampus = visitor.status === "checked_in";
            const icon = element("span", "monitor-row-icon");
            icon.dataset.state = onCampus ? visitor.location_state : "ended";
            if (onCampus && visitor.moving && visitor.heading != null) {
                const svg = arrowSvg();
                svg.style.transform = "rotate(" + visitor.heading + "deg)";
                icon.appendChild(svg);
                icon.classList.add("is-moving");
            }
            const main = element("span", "monitor-row-main");
            main.append(
                element("strong", "", visitor.visitor_full_name || "Visitor"),
                element("small", "", (visitor.office_label || "No destination") + " · " + visitor.registration_id),
            );
            const meta = element("span", "monitor-row-meta");
            if (onCampus) {
                const tone = { live: "good", stale: "warn", offline: "muted", waiting: "info", outside: "danger" }[visitor.location_state] || "muted";
                meta.appendChild(element("span", "monitor-badge is-" + tone, stateText(visitor)));
                const arrival = currentArrival(visitor);
                if (arrival) {
                    const badge = element("span", "monitor-badge is-good", "Arrived");
                    badge.title = "Arrived at " + arrival.office_label + " " + formatTime(arrival.at) + ", " + arrivalHow(arrival);
                    meta.appendChild(badge);
                }
                const slot = slotStatus(visitor);
                if (slot && slot.tone !== "info") {
                    meta.appendChild(element("span", "monitor-badge is-" + slot.tone, slot.text));
                }
                meta.appendChild(element("small", "", formatDuration(secondsSince(visitor.checked_in_at)) + " on campus"));
            } else {
                const method = CHECKOUT_LABELS[visitor.checkout_method] || { label: "Visit ended" };
                const tone = visitor.checkout_method === "left_campus" || visitor.checkout_method === "end_of_day" ? "warn" : "muted";
                meta.appendChild(element("span", "monitor-badge is-" + tone, method.label + " " + formatTime(visitor.completed_at)));
                if (!visitor.point_count) {
                    meta.appendChild(element("small", "", "No GPS recorded"));
                }
            }
            row.append(icon, main, meta);
            item.appendChild(row);
            monitorList.appendChild(item);
            if (focusedId && focusedId === row.dataset.monitorId) {
                row.focus();
            }
        });
        const empty = document.getElementById("monitorEmpty");
        empty.hidden = list.length > 0;
        if (!list.length) {
            empty.textContent = monitoringState.query.trim()
                ? "No visitors match your search."
                : (monitoringState.tab === "active"
                    ? "No visitors on campus. Checked-in visitors appear here automatically."
                    : "Nobody has left the campus yet today.");
        }
    }

    function selectTab(tab) {
        monitoringState.tab = tab;
        document.querySelectorAll("[data-monitor-tab]").forEach(function (button) {
            const active = button.getAttribute("data-monitor-tab") === tab;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-selected", String(active));
            button.tabIndex = active ? 0 : -1;
        });
        renderList();
    }

    function setFollow(on) {
        monitoringState.follow = Boolean(on);
        const button = document.getElementById("monitorFollowBtn");
        button.setAttribute("aria-pressed", String(monitoringState.follow));
        button.classList.toggle("is-active", monitoringState.follow);
        button.querySelector("span").textContent = monitoringState.follow ? "Following" : "Follow";
        if (monitoringState.follow) {
            centerOnSelected();
        }
    }

    function setSheetOpen(open) {
        monitorPanel.classList.toggle("is-collapsed", !open);
        document.getElementById("monitorSheetToggle").setAttribute("aria-expanded", String(open));
    }

    function selectVisitor(id, options) {
        const key = Number(id);
        const changed = monitoringState.selectedId !== key;
        monitoringState.selectedId = key;
        if (changed) {
            setFollow(false);
            monitoringState.trailLayer && monitoringState.trailLayer.clearLayers();
            monitoringState.trail = null;
            const visitor = findVisitor(key);
            if (visitor) {
                selectTab(visitor.status === "checked_in" ? "active" : "finished");
            }
            loadTrail(true, options && options.pan !== false);
        }
        setSheetOpen(true);
        document.getElementById("monitorListView").hidden = true;
        document.getElementById("monitorDetail").hidden = false;
        renderDetail();
        renderList();
        monitoringState.active.forEach(function (visitor) {
            const entry = monitoringState.markers.get(visitor.id);
            if (entry) {
                styleMarker(entry, visitor);
            }
        });
        if (options && options.pan && !changed) {
            centerOnSelected();
        }
    }

    function clearSelection() {
        monitoringState.selectedId = null;
        monitoringState.trail = null;
        setFollow(false);
        if (monitoringState.trailLayer) {
            monitoringState.trailLayer.clearLayers();
        }
        document.getElementById("monitorDetail").hidden = true;
        document.getElementById("monitorListView").hidden = false;
        renderList();
        monitoringState.active.forEach(function (visitor) {
            const entry = monitoringState.markers.get(visitor.id);
            if (entry) {
                styleMarker(entry, visitor);
            }
        });
    }

    function centerOnSelected() {
        const map = monitoringState.map;
        const visitor = findVisitor(monitoringState.selectedId);
        if (!map || !visitor) {
            return;
        }
        if (visitor.status === "checked_in" && visitor.has_location) {
            map.setView([visitor.latitude, visitor.longitude], Math.max(map.getZoom(), 19), { animate: true });
            return;
        }
        const trail = monitoringState.trail;
        if (trail && trail.points.length) {
            map.fitBounds(L.latLngBounds(trail.points.map(function (point) { return [point.lat, point.lng]; })), mapPadding({ maxZoom: 20 }));
        }
    }

    function addFact(host, label, value, className) {
        if (value == null || value === "") {
            return;
        }
        const wrap = element("div", className || "");
        wrap.append(element("dt", "", label), element("dd", "", value));
        host.appendChild(wrap);
    }

    function renderDetail() {
        const visitor = findVisitor(monitoringState.selectedId);
        const detail = document.getElementById("monitorDetail");
        if (!visitor) {
            setText("monitorDetailName", "Loading visitor…");
            setText("monitorDetailMeta", "");
            document.getElementById("monitorDetailChips").replaceChildren();
            document.getElementById("monitorDetailFacts").replaceChildren();
            document.getElementById("monitorDetailStops").hidden = true;
            return;
        }
        const onCampus = visitor.status === "checked_in";
        detail.dataset.state = onCampus ? visitor.location_state : "ended";
        const icon = document.getElementById("monitorDetailIcon");
        icon.replaceChildren();
        icon.dataset.state = onCampus ? visitor.location_state : "ended";
        if (onCampus && visitor.moving && visitor.heading != null) {
            const svg = arrowSvg();
            svg.style.transform = "rotate(" + visitor.heading + "deg)";
            icon.appendChild(svg);
        }
        setText("monitorDetailName", visitor.visitor_full_name || "Visitor");
        setText("monitorDetailMeta", visitor.registration_id + " · "
            + (visitor.visit_type === "multi_stop" ? "Visit to several offices" : (visitor.visit_type === "walk_in" ? "Walk-in" : "Appointment")));

        const chips = document.getElementById("monitorDetailChips");
        chips.replaceChildren();
        if (onCampus) {
            const tone = { live: "good", stale: "warn", offline: "muted", waiting: "info", outside: "danger" }[visitor.location_state] || "muted";
            chips.appendChild(element("span", "monitor-badge is-" + tone, stateText(visitor)));
            const slot = slotStatus(visitor);
            if (slot) {
                chips.appendChild(element("span", "monitor-badge is-" + slot.tone, slot.text));
            }
        } else {
            const tone = visitor.checkout_method === "left_campus" || visitor.checkout_method === "end_of_day" ? "warn" : "muted";
            chips.appendChild(element("span", "monitor-badge is-" + tone, CHECKOUT_PHRASES[visitor.checkout_method] || "Visit ended"));
        }

        const facts = document.getElementById("monitorDetailFacts");
        facts.replaceChildren();
        addFact(facts, "Destination", visitor.office_label);
        addFact(facts, "Purpose", [visitor.purpose, visitor.subject].filter(Boolean).join(" · "));
        addFact(facts, "Checked in", formatTime(visitor.checked_in_at) + (visitor.checked_in_by ? " by " + visitor.checked_in_by : ""));
        if (onCampus) {
            addFact(facts, "On campus", formatDuration(secondsSince(visitor.checked_in_at)), "is-live-count");
            if (visitor.scheduled_start_at && visitor.scheduled_end_at) {
                addFact(facts, "Booked slot", formatTime(visitor.scheduled_start_at) + " – " + formatTime(visitor.scheduled_end_at));
            }
            if (visitor.location_state === "outside") {
                addFact(facts, "Left the boundary", formatTime(visitor.outside_since)
                    + ". The visit ends automatically after " + Math.round((monitoringState.rules.exit_confirm_seconds || 300) / 60) + " minutes outside.");
            } else if (visitor.has_location) {
                addFact(facts, "Last update", formatAgo(visitor.seconds_since_update)
                    + (visitor.accuracy != null ? " · accurate to ±" + Math.round(visitor.accuracy) + " m" : ""), "is-live-age");
            } else {
                addFact(facts, "Last update", "No position yet. The visitor's phone has not shared GPS since check-in.");
            }
        } else {
            const time = (CHECKOUT_LABELS[visitor.checkout_method] || { label: "Time out" }).label;
            addFact(facts, time, formatTime(visitor.completed_at) + (visitor.checked_out_by ? " by " + visitor.checked_out_by : ""));
            const start = parseServerDate(visitor.checked_in_at);
            const end = parseServerDate(visitor.completed_at);
            if (start && end) {
                addFact(facts, "Time on campus", formatDuration((end.getTime() - start.getTime()) / 1000));
            }
        }
        const trail = monitoringState.trail && monitoringState.trail.id === visitor.id ? monitoringState.trail : null;
        if (trail && trail.stats) {
            addFact(facts, "Walked", formatDistance(trail.stats.distance_meters));
        }
        // Arrivals are only what the visitor's phone confirmed; passing near an office is not one.
        const confirmed = {};
        (visitor.arrivals || []).forEach(function (arrival) {
            confirmed[arrival.office_code] = true;
            addFact(facts, "Arrived", arrival.office_label + " at " + formatTime(arrival.at) + ", " + arrivalHow(arrival));
        });
        if (trail && trail.stats) {
            (trail.stats.near_offices || []).forEach(function (near) {
                if (!confirmed[near.office_code]) {
                    addFact(facts, "Passed near", near.office_label + " at " + formatTime(near.at)
                        + " (within " + (monitoringState.rules.near_office_meters || 30) + " m). Arrival not confirmed.");
                }
            });
        }

        const stops = document.getElementById("monitorDetailStops");
        stops.replaceChildren();
        stops.hidden = !(visitor.stops && visitor.stops.length > 1);
        (visitor.stops || []).forEach(function (stop) {
            const item = element("li", "is-" + stop.status);
            item.append(
                element("strong", "", stop.office_label),
                element("span", "", (STOP_LABELS[stop.status] || stop.status) + " · " + formatTime(stop.scheduled_start_at)),
            );
            stops.appendChild(item);
        });

        const note = document.getElementById("monitorTrailNote");
        if (!trail) {
            note.textContent = monitoringState.trailLoading ? "Loading the walked path…" : "";
        } else if (!trail.points.length) {
            note.textContent = onCampus
                ? "No path yet. It appears here as the visitor walks around the campus."
                : "No GPS positions were recorded inside the campus during this visit.";
        } else {
            note.textContent = "The orange line shows where they walked, from the green start"
                + (onCampus ? " to where they are now." : " to the red end point.");
        }

        document.getElementById("monitorFollowBtn").hidden = !(onCampus && visitor.has_location);
        document.getElementById("monitorEndBtn").hidden = !onCampus;
        document.getElementById("monitorCenterBtn").hidden = !((onCampus && visitor.has_location) || (trail && trail.points.length));
    }

    /** Updates the "On campus" and "Last update" counters every second without a reload. */
    function tickDetail() {
        if (monitoringState.selectedId == null || !monitoringVisible()) {
            return;
        }
        const visitor = findVisitor(monitoringState.selectedId);
        if (!visitor || visitor.status !== "checked_in") {
            return;
        }
        const live = document.querySelector("#monitorDetailFacts .is-live-count dd");
        if (live) {
            live.textContent = formatDuration(secondsSince(visitor.checked_in_at));
        }
    }

    function trailBreak(previous, next) {
        if (!previous) {
            return false;
        }
        const step = L.latLng(previous.lat, previous.lng).distanceTo([next.lat, next.lng]);
        const gap = (parseServerDate(next.t) - parseServerDate(previous.t)) / 1000;
        return step > monitoringState.rules.trail_max_step_meters || gap > monitoringState.rules.trail_max_gap_seconds;
    }

    /**
     * Loads the selected visitor's walked path: everything (full) or only new points.
     * A newer request always wins, so switching visitors never shows the wrong path.
     */
    async function loadTrail(full, fit) {
        const id = monitoringState.selectedId;
        if (id == null) {
            return;
        }
        const current = monitoringState.trail && monitoringState.trail.id === id ? monitoringState.trail : null;
        if (!full) {
            if (monitoringState.trailLoading) {
                return;
            }
            if (!current) {
                full = true;
            }
        }
        const afterId = !full && current ? current.lastId : 0;
        const request = ++monitoringState.trailRequest;
        monitoringState.trailLoading = true;
        try {
            const data = await fetchJson("security_visitor_trail.php?" + new URLSearchParams({
                appointment_id: String(id),
                after_id: String(afterId),
            }).toString());
            if (request !== monitoringState.trailRequest || monitoringState.selectedId !== id) {
                return;
            }
            if (!data || !data.success) {
                document.getElementById("monitorTrailNote").textContent = (data && data.message) || "Could not load the walked path.";
                return;
            }
            if (data.rules) {
                monitoringState.rules = Object.assign(monitoringState.rules, data.rules);
            }
            if (afterId === 0 || !current) {
                monitoringState.trail = { id: id, points: data.points || [], lastId: data.last_id || 0, stats: data.stats || null, visitor: data.visitor };
                drawTrail(Boolean(fit));
            } else {
                const added = data.points || [];
                const last = current.points.length ? current.points[current.points.length - 1] : null;
                if (added.length && last && added[0].t < last.t) {
                    // A late offline batch arrived out of order: rebuild the whole path.
                    loadTrail(true, false);
                    return;
                }
                added.forEach(function (point, index) {
                    const previous = index === 0 ? last : added[index - 1];
                    if (point.brk === null) {
                        point.brk = trailBreak(previous, point);
                    }
                    if (!point.brk && previous && current.stats) {
                        current.stats.distance_meters += L.latLng(previous.lat, previous.lng).distanceTo([point.lat, point.lng]);
                    }
                    current.points.push(point);
                });
                current.lastId = data.last_id || current.lastId;
                current.visitor = data.visitor || current.visitor;
                if (added.length) {
                    drawTrail(false);
                }
            }
            renderDetail();
        } catch (error) {
            if (error.message !== "Not authenticated" && request === monitoringState.trailRequest) {
                document.getElementById("monitorTrailNote").textContent = "Could not load the walked path. It will retry automatically.";
            }
        } finally {
            if (request === monitoringState.trailRequest) {
                monitoringState.trailLoading = false;
            }
        }
    }

    function trailIcon(className, svgPath) {
        return L.divIcon({
            className: "monitor-trail-pin " + className,
            html: '<span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="' + svgPath + '"/></svg></span>',
            iconSize: [26, 26],
            iconAnchor: [13, 13],
        });
    }

    function drawTrail(fit) {
        const layer = monitoringState.trailLayer;
        const trail = monitoringState.trail;
        if (!layer) {
            return;
        }
        layer.clearLayers();
        if (!trail || !trail.points.length) {
            if (fit) {
                centerOnSelected();
            }
            return;
        }
        const segments = [];
        let segment = [];
        trail.points.forEach(function (point) {
            if (point.brk && segment.length) {
                segments.push(segment);
                segment = [];
            }
            segment.push(point);
        });
        if (segment.length) {
            segments.push(segment);
        }

        segments.forEach(function (part) {
            const latlngs = part.map(function (point) { return [point.lat, point.lng]; });
            if (latlngs.length < 2) {
                L.circleMarker(latlngs[0], { radius: 4, color: "#ffffff", weight: 2, fillColor: "#f0641c", fillOpacity: 1, interactive: false }).addTo(layer);
                return;
            }
            L.polyline(latlngs, { color: "#ffffff", weight: 9, opacity: 0.92, lineCap: "round", lineJoin: "round", interactive: false }).addTo(layer);
            L.polyline(latlngs, { color: "#f0641c", weight: 5, opacity: 0.96, lineCap: "round", lineJoin: "round", interactive: false }).addTo(layer);

            // Small arrows every ~40 m show which way the visitor walked.
            let travelled = 0;
            let nextArrow = 20;
            for (let index = 1; index < part.length; index += 1) {
                const a = part[index - 1];
                const b = part[index];
                const step = L.latLng(a.lat, a.lng).distanceTo([b.lat, b.lng]);
                while (step > 0 && travelled + step >= nextArrow) {
                    const ratio = (nextArrow - travelled) / step;
                    const at = [a.lat + (b.lat - a.lat) * ratio, a.lng + (b.lng - a.lng) * ratio];
                    L.marker(at, {
                        icon: L.divIcon({
                            className: "monitor-trail-chevron",
                            html: '<svg viewBox="0 0 24 24" aria-hidden="true" style="transform:rotate(' + bearing(a.lat, a.lng, b.lat, b.lng).toFixed(0)
                                + 'deg)"><path d="M6 15l6-6 6 6"/></svg>',
                            iconSize: [16, 16],
                            iconAnchor: [8, 8],
                        }),
                        interactive: false,
                        keyboard: false,
                    }).addTo(layer);
                    nextArrow += 40;
                }
                travelled += step;
            }
        });

        const visitor = trail.visitor || findVisitor(trail.id);
        const first = trail.points[0];
        L.marker([first.lat, first.lng], { icon: trailIcon("is-start", "M5 21V4h11l-2 4 2 4H5"), keyboard: false })
            .bindTooltip("Start · " + formatTime(first.t), { direction: "top", offset: [0, -12] })
            .addTo(layer);
        if (visitor && visitor.status !== "checked_in") {
            const last = trail.points[trail.points.length - 1];
            const label = (CHECKOUT_LABELS[visitor.checkout_method] || { label: "Visit ended" }).label;
            L.marker([last.lat, last.lng], { icon: trailIcon("is-end", "M6 6h12v12H6z"), keyboard: false })
                .bindTooltip(label + " · " + formatTime(visitor.completed_at), { direction: "top", offset: [0, -12] })
                .addTo(layer);
        }
        // Confirmed arrivals from the live list, so a new one appears without reloading the path.
        const live = findVisitor(trail.id) || visitor;
        ((live && live.arrivals) || []).forEach(function (arrival) {
            if (arrival.latitude == null || arrival.longitude == null) {
                return;
            }
            L.marker([arrival.latitude, arrival.longitude], { icon: trailIcon("is-arrival", "M4 21V8l8-5 8 5v13H4Zm6 0v-6h4v6"), keyboard: false })
                .bindTooltip("Arrived at " + arrival.office_label + " · " + formatTime(arrival.at) + ", " + arrivalHow(arrival),
                    { direction: "top", offset: [0, -12] })
                .addTo(layer);
        });

        if (fit && monitoringState.map) {
            const bounds = L.latLngBounds(trail.points.map(function (point) { return [point.lat, point.lng]; }));
            if (visitor && visitor.status === "checked_in" && visitor.has_location) {
                bounds.extend([visitor.latitude, visitor.longitude]);
            }
            monitoringState.map.fitBounds(bounds, mapPadding({ maxZoom: 20 }));
        }
    }

    /** Refreshes the live map. Calls made while a refresh is running share its result. */
    function loadLiveLocations(force) {
        if (!monitoringState.initialized || !monitoringState.map) {
            return Promise.resolve();
        }
        if (!force && (document.hidden || !monitoringVisible())) {
            return Promise.resolve();
        }
        if (!monitoringState.livePromise) {
            monitoringState.livePromise = refreshLiveLocations().finally(function () {
                monitoringState.livePromise = null;
            });
        }
        return monitoringState.livePromise;
    }

    async function refreshLiveLocations() {
        try {
            const data = await fetchJson("security_live_locations.php");
            if (!data || !data.success || !Array.isArray(data.data)) {
                showMessage("securityMapError", (data && data.message) || "Could not load live locations.");
                return;
            }
            showMessage("securityMapError", "");
            syncServerClock(data.server_time);
            if (data.rules) {
                monitoringState.rules = Object.assign(monitoringState.rules, data.rules);
            }
            monitoringState.active = data.data;
            monitoringState.finished = Array.isArray(data.finished) ? data.finished : [];
            renderSummary();
            renderList();
            updateMarkers();
            if (monitoringState.selectedId != null) {
                const visitor = findVisitor(monitoringState.selectedId);
                if (visitor) {
                    // Keep the list behind the card on the right tab ("On campus" / "Left today").
                    const tab = visitor.status === "checked_in" ? "active" : "finished";
                    if (tab !== monitoringState.tab && monitoringState.active.concat(monitoringState.finished).indexOf(visitor) !== -1) {
                        selectTab(tab);
                    }
                }
                renderDetail();
                if (visitor && visitor.status === "checked_in") {
                    loadTrail(false);
                } else if (visitor && monitoringState.trail && monitoringState.trail.visitor
                    && monitoringState.trail.visitor.status === "checked_in") {
                    // The visit just ended: reload once to draw the end point.
                    loadTrail(true, false);
                }
            }
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("securityMapError", error.message || "Could not update visitor locations.");
            }
        }
    }

    /** Opens the live map focused on one visitor (from the records table). */
    function openOnMap(appointmentId) {
        switchSecurityView("monitoring");
        loadLiveLocations(true).then(function () {
            selectVisitor(appointmentId, { pan: true });
        });
    }

    /* ---------- Events ---------- */

    document.querySelectorAll("[data-security-view]").forEach(function (button) {
        button.addEventListener("click", function () {
            switchSecurityView(button.getAttribute("data-security-view"));
        });
    });

    document.querySelectorAll("[data-security-filter]").forEach(function (card) {
        card.addEventListener("click", function () {
            dashboardState.status = card.getAttribute("data-security-filter") || "";
            dashboardState.type = "";
            dashboardState.page = 1;
            document.getElementById("securityStatusFilter").value = dashboardState.status;
            renderVisitors();
        });
    });

    document.querySelectorAll("[data-security-type-filter]").forEach(function (card) {
        card.addEventListener("click", function () {
            dashboardState.type = card.getAttribute("data-security-type-filter") || "";
            dashboardState.status = "";
            dashboardState.page = 1;
            document.getElementById("securityStatusFilter").value = "";
            renderVisitors();
        });
    });

    document.getElementById("securityVisitorSearch").addEventListener("input", function (event) {
        dashboardState.query = event.target.value.trim();
        dashboardState.page = 1;
        renderVisitors();
    });
    document.getElementById("securityStatusFilter").addEventListener("change", function (event) {
        dashboardState.status = event.target.value;
        dashboardState.type = "";
        dashboardState.page = 1;
        renderVisitors();
    });
    document.getElementById("securityRefreshBtn").addEventListener("click", loadSecurityDashboard);

    visitorsBody.addEventListener("click", function (event) {
        const button = event.target.closest("button[data-appointment-id]");
        if (!button) {
            return;
        }
        const visitor = dashboardState.visitors.find(function (item) {
            return Number(item.id) === Number(button.dataset.appointmentId);
        });
        if (!visitor) {
            return;
        }
        if (button.dataset.action === "complete") {
            completeVisit(visitor.id, visitor.visitor_full_name, button);
            return;
        }
        if (button.dataset.action === "monitor") {
            openOnMap(visitor.id);
        }
    });

    monitorList.addEventListener("click", function (event) {
        const row = event.target.closest("button[data-monitor-id]");
        if (row) {
            selectVisitor(row.dataset.monitorId, { pan: true });
        }
    });

    document.querySelectorAll("[data-monitor-tab]").forEach(function (button) {
        button.addEventListener("click", function () {
            selectTab(button.getAttribute("data-monitor-tab"));
        });
        button.addEventListener("keydown", function (event) {
            if (event.key === "ArrowRight" || event.key === "ArrowLeft") {
                const next = monitoringState.tab === "active" ? "finished" : "active";
                selectTab(next);
                document.querySelector('[data-monitor-tab="' + next + '"]').focus();
                event.preventDefault();
            }
        });
    });

    document.getElementById("monitorSearch").addEventListener("input", function (event) {
        monitoringState.query = event.target.value;
        renderList();
    });

    document.getElementById("monitorBackBtn").addEventListener("click", function () {
        clearSelection();
        const row = monitorList.querySelector("button");
        if (row) {
            row.focus();
        }
    });
    document.getElementById("monitorFollowBtn").addEventListener("click", function () {
        setFollow(!monitoringState.follow);
    });
    document.getElementById("monitorCenterBtn").addEventListener("click", centerOnSelected);
    document.getElementById("monitorEndBtn").addEventListener("click", async function (event) {
        const visitor = findVisitor(monitoringState.selectedId);
        if (visitor) {
            await completeVisit(visitor.id, visitor.visitor_full_name, event.currentTarget);
        }
    });
    document.getElementById("monitorSheetToggle").addEventListener("click", function () {
        setSheetOpen(monitorPanel.classList.contains("is-collapsed"));
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && monitoringState.selectedId != null && monitoringVisible()
            && !event.target.closest("input, select, textarea")) {
            clearSelection();
        }
    });

    document.addEventListener("visibilitychange", function () {
        if (!document.hidden) {
            loadSecurityDashboard();
            loadLiveLocations();
        }
    });

    profileButton.addEventListener("click", function () {
        const willOpen = profileMenu.hidden;
        profileMenu.hidden = !willOpen;
        profileButton.setAttribute("aria-expanded", String(willOpen));
    });
    document.addEventListener("click", function (event) {
        if (!profileMenu.hidden && !profileMenu.contains(event.target) && !profileButton.contains(event.target)) {
            profileMenu.hidden = true;
            profileButton.setAttribute("aria-expanded", "false");
        }
    });
    document.getElementById("logoutBtn").addEventListener("click", function () {
        PhoneTrackerAuth.logout().then(function () {
            window.location.href = "login.html";
        });
    });

    const role = PhoneTrackerAuth.getRole();
    setText("securityProfileName", PhoneTrackerAuth.getDisplayName() || PhoneTrackerAuth.getUsername() || "Security Desk");
    setText("securityProfileUsername", PhoneTrackerAuth.getUsername() || "security");
    setText("securityRoleLabel", role === "admin" ? "Admin" : "Security");
    document.getElementById("securityAdminLink").hidden = role !== "admin";

    switchSecurityView(window.location.hash === "#monitoring" ? "monitoring" : "dashboard");
    loadSecurityDashboard();
    window.setInterval(function () {
        if (!document.hidden) {
            loadSecurityDashboard();
        }
    }, 20000);
    window.setInterval(loadLiveLocations, 5000);
    window.setInterval(tickDetail, 1000);
})();
