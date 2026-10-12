(function () {
    if (typeof PhoneTrackerAuth === "undefined") {
        window.location.href = "login.html";
        return;
    }
    if (!PhoneTrackerAuth.requireRole(["visitor"])) {
        return;
    }

    const APPT_TOKEN_KEY = "phone_tracker_appointment_token";
    let watchId = null;
    let anchorPosition = null;
    const MIN_MOVEMENT_METERS = 10;
    const MAX_MOVEMENT_ACCURACY_METERS = 50;
    // Steadies the GPS readings before they are sent (gps_filter.js). Without that file (an
    // old copy of the page) the readings are sent as they come.
    const gpsFilter = typeof GpsFilter === "undefined" ? null : GpsFilter.create();
    let pollTimer = null;
    let autoStarted = false;
    let trackedDeviceName = "My Phone";
    let currentAppointmentToken = "";
    let locationPollTimer = null;
    let visitorMap = null;
    let visitorMarker = null;

    const checkInHint = document.getElementById("checkInHint");
    const trackingStatus = document.getElementById("trackingStatus");
    const appointmentForm = document.getElementById("appointmentForm");
    const appointmentError = document.getElementById("appointmentError");
    const appointmentSuccess = document.getElementById("appointmentSuccess");
    const appointmentSubmit = document.getElementById("appointmentSubmit");
    const qrHost = document.getElementById("qrHost");
    const tokenDisplay = document.getElementById("tokenDisplay");
    const tokenLine = document.getElementById("tokenLine");
    const appointmentStateMessage = document.getElementById("appointmentStateMessage");
    const appointmentAtInput = document.getElementById("appointmentAt");
    const visitorMapPanel = document.getElementById("visitorMapPanel");
    const visitCompleteNotice = document.getElementById("visitCompleteNotice");
    const menuBtn = document.getElementById("menuBtn");
    const visitorMenuPanel = document.getElementById("visitorMenuPanel");
    const menuCloseBtn = document.getElementById("menuCloseBtn");
    const menuOverlay = document.getElementById("menuOverlay");
    const appointmentsList = document.getElementById("appointmentsList");

    function resetTransientVisitorUi() {
        if (visitorMapPanel) {
            visitorMapPanel.hidden = true;
        }
    }

    // Reset transient map UI on script init.
    resetTransientVisitorUi();

    // Handle pages restored from browser back/forward cache.
    window.addEventListener("pageshow", function () {
        resetTransientVisitorUi();
    });

    document.getElementById("logoutBtn").addEventListener("click", function () {
        PhoneTrackerAuth.logout().then(function () {
            sessionStorage.removeItem(APPT_TOKEN_KEY);
            window.location.href = "login.html";
        });
    });

    function normalizeToken(raw) {
        if (!raw || typeof raw !== "string") {
            return "";
        }
        const t = raw.trim();
        const hexOnly = t.replace(/[^a-f0-9]/gi, "");
        if (hexOnly.length === 64) {
            return hexOnly.toLowerCase();
        }
        const m = t.match(/token=([a-f0-9]{64})/i);
        if (m) {
            return m[1].toLowerCase();
        }
        return "";
    }

    function setTrackingStatus(message) {
        if (trackingStatus) {
            trackingStatus.textContent = "Tracking status: " + message;
        }
    }

    function formatDateOnly(value) {
        if (!value) {
            return "-";
        }
        const datePart = String(value).slice(0, 10);
        const parsed = new Date(datePart + "T00:00:00");
        if (isNaN(parsed.getTime())) {
            return datePart;
        }
        return parsed.toLocaleDateString();
    }

    function statusMeta(rawStatus) {
        const s = String(rawStatus || "").toLowerCase();
        if (s === "checked_in") {
            return { label: "Active", cls: "status-active" };
        }
        if (s === "completed") {
            return { label: "Completed", cls: "status-completed" };
        }
        if (s === "cancelled") {
            return { label: "Cancelled", cls: "status-cancelled" };
        }
        if (s === "approved") {
            return { label: "Approved", cls: "status-active" };
        }
        if (s === "rejected") {
            return { label: "Declined", cls: "status-cancelled" };
        }
        if (s === "unanswered") {
            return { label: "Office Did Not Respond", cls: "status-cancelled" };
        }
        if (s === "reschedule_proposed") {
            return { label: "Reschedule Proposed", cls: "status-pending" };
        }
        if (s === "window_closed") {
            return { label: "Appointment Done", cls: "status-completed" };
        }
        return { label: "Waiting for Office Response", cls: "status-pending" };
    }

    function ensureMapReady() {
        if (visitorMap || typeof L === "undefined") {
            return;
        }
        visitorMap = L.map("visitorMap").setView([0, 0], 2);
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
            maxZoom: 19,
            attribution: "&copy; OpenStreetMap contributors",
        }).addTo(visitorMap);
    }

    function showLiveMap() {
        if (visitorMapPanel) {
            visitorMapPanel.hidden = false;
        }
        ensureMapReady();
        if (locationPollTimer) {
            clearInterval(locationPollTimer);
        }
        loadMyLatestLocation();
        locationPollTimer = setInterval(loadMyLatestLocation, 5000);
        setTimeout(function () {
            if (visitorMap) {
                visitorMap.invalidateSize();
            }
        }, 120);
    }

    async function loadMyLatestLocation() {
        if (!currentAppointmentToken || currentAppointmentToken.length !== 64) {
            return;
        }
        try {
            const response = await fetch("get_my_latest_location.php?token=" + encodeURIComponent(currentAppointmentToken), {
                credentials: "same-origin",
            });
            const result = await response.json();
            if (!result || !result.success || !result.data) {
                return;
            }
            const lat = parseFloat(result.data.latitude);
            const lng = parseFloat(result.data.longitude);
            if (isNaN(lat) || isNaN(lng)) {
                return;
            }
            ensureMapReady();
            if (!visitorMap) {
                return;
            }
            const point = [lat, lng];
            if (!visitorMarker) {
                visitorMarker = L.marker(point).addTo(visitorMap);
                visitorMap.setView(point, 16);
            } else {
                visitorMarker.setLatLng(point);
            }
            visitorMarker.bindPopup("You are here<br>Recorded: " + (result.data.recorded_at || "-"));
        } catch (error) {}
    }

    function renderAppointmentState(status, token) {
        const normalizedStatus = String(status || "pending_approval").toLowerCase();
        const qrAvailable = normalizedStatus === "approved" || normalizedStatus === "checked_in";

        if (qrHost) {
            qrHost.hidden = !qrAvailable;
            qrHost.innerHTML = "";
        }
        if (tokenLine) {
            tokenLine.hidden = !qrAvailable;
        }
        if (tokenDisplay) {
            tokenDisplay.textContent = qrAvailable ? token : "";
        }
        if (qrAvailable && qrHost && typeof QRCode !== "undefined") {
            new QRCode(qrHost, {
                text: token,
                width: 200,
                height: 200,
            });
        }

        const messages = {
            pending_approval: "<strong>Appointment request sent.</strong> Waiting for the office to respond.",
            reschedule_proposed: "<strong>The office suggested another schedule.</strong> Review the proposed time in your notifications.",
            approved: "<strong>Appointment approved.</strong> Present this QR code to security during the valid time window.",
            checked_in: "<strong>You are checked in.</strong> Show this QR code to Security again when you leave to check out.",
            rejected: "<strong>Appointment declined.</strong> Check the office response before requesting another schedule.",
            unanswered: "<strong>The office did not respond in time.</strong> Please request another appointment.",
            cancelled: "<strong>Appointment cancelled.</strong> You may create another appointment.",
            window_closed: "<strong>Appointment done.</strong> The visitor pass has expired.",
            completed: "<strong>Visit complete.</strong> The visitor pass is no longer active.",
        };
        if (appointmentStateMessage) {
            appointmentStateMessage.innerHTML = messages[normalizedStatus] || messages.pending_approval;
        }
        if (checkInHint) {
            if (normalizedStatus === "approved") {
                checkInHint.textContent = "The QR becomes scannable 30 minutes before your appointment and remains valid until its scheduled end.";
            } else if (normalizedStatus === "checked_in") {
                checkInHint.textContent = "Security checked you in. Your location is shared only while you are inside the campus.";
            } else {
                checkInHint.textContent = "A QR visitor pass is available only after office approval.";
            }
        }
    }

    function showAppointmentQr(token, status) {
        const normalized = normalizeToken(token);
        if (normalized.length !== 64) {
            return;
        }
        sessionStorage.setItem(APPT_TOKEN_KEY, normalized);
        currentAppointmentToken = normalized;
        if (appointmentForm) {
            appointmentForm.hidden = true;
        }
        if (appointmentSuccess) {
            appointmentSuccess.hidden = false;
        }
        renderAppointmentState(status, normalized);
        if (visitCompleteNotice) {
            visitCompleteNotice.hidden = true;
        }
        if (visitorMapPanel) {
            visitorMapPanel.hidden = true;
        }
        setTrackingStatus("standby.");
        if (pollTimer) {
            clearInterval(pollTimer);
        }
        pollTimer = setInterval(pollAppointmentCheckIn, 2500);
        pollAppointmentCheckIn();
    }

    // Offices come from the department directory, so newly added departments appear here.
    // The options written in index.html stay if the list cannot be loaded.
    function loadOfficeOptions() {
        const select = document.getElementById("officeCode");
        if (!select) {
            return;
        }
        fetch("office_directory.php", { credentials: "same-origin", cache: "no-store" })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.success || !Array.isArray(data.offices) || !data.offices.length) {
                    return;
                }
                const selected = select.value;
                select.replaceChildren(new Option("Select office", ""));
                data.offices.forEach(function (office) {
                    select.appendChild(new Option(office.location ? office.name + " — " + office.location : office.name, office.code));
                });
                select.value = selected;
            })
            .catch(function () {});
    }
    loadOfficeOptions();

    function openMenu() {
        if (!visitorMenuPanel || !menuOverlay || !menuBtn) {
            return;
        }
        visitorMenuPanel.hidden = false;
        menuOverlay.hidden = false;
        menuBtn.setAttribute("aria-expanded", "true");
        loadMyAppointments();
    }

    function closeMenu() {
        if (!visitorMenuPanel || !menuOverlay || !menuBtn) {
            return;
        }
        visitorMenuPanel.hidden = true;
        menuOverlay.hidden = true;
        menuBtn.setAttribute("aria-expanded", "false");
    }

    async function deleteAppointment(id) {
        const response = await fetch("delete_appointment.php", {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ id: id }),
        });
        const result = await response.json();
        return !!(result && result.success);
    }

    async function loadMyAppointments() {
        if (!appointmentsList) {
            return;
        }
        appointmentsList.innerHTML = '<p class="muted small">Loading appointments...</p>';
        try {
            const response = await fetch("visitor_appointments.php", {
                credentials: "same-origin",
            });
            const result = await response.json();
            if (!result || !result.success || !Array.isArray(result.data)) {
                appointmentsList.innerHTML = '<p class="login-error">Could not load appointments.</p>';
                return;
            }
            if (result.data.length === 0) {
                appointmentsList.innerHTML = '<p class="muted small">No appointments yet.</p>';
                return;
            }
            appointmentsList.innerHTML = "";
            result.data.forEach(function (item) {
                const row = document.createElement("div");
                row.className = "appointment-item";

                const infoBtn = document.createElement("button");
                infoBtn.type = "button";
                infoBtn.className = "appointment-open";
                const st = statusMeta(item.status);
                infoBtn.innerHTML =
                    "<strong>" +
                    (item.office_label || item.office_code || "Office") +
                    "</strong><br>" +
                    '<span class="muted small">' +
                    formatDateOnly(item.appointment_at) +
                    ' <span class="status-badge ' +
                    st.cls +
                    '">' +
                    st.label +
                    "</span>" +
                    "</span>";
                infoBtn.addEventListener("click", function () {
                    showAppointmentQr(item.public_token || "", item.status);
                    closeMenu();
                });

                const cancellableStatuses = ["pending_approval", "approved", "reschedule_proposed"];
                let removeBtn = null;
                if (cancellableStatuses.indexOf(item.status) !== -1) {
                    removeBtn = document.createElement("button");
                    removeBtn.type = "button";
                    removeBtn.className = "button-danger button-small";
                    removeBtn.textContent = "Cancel";
                    removeBtn.addEventListener("click", function (ev) {
                        ev.stopPropagation();
                        if (!window.confirm("Cancel this appointment? It will remain in your appointment history.")) {
                            return;
                        }
                        deleteAppointment(item.id).then(function (ok) {
                            if (ok) {
                                loadMyAppointments();
                            }
                        });
                    });
                }

                row.appendChild(infoBtn);
                if (removeBtn) {
                    row.appendChild(removeBtn);
                }
                appointmentsList.appendChild(row);
            });
        } catch (error) {
            appointmentsList.innerHTML = '<p class="login-error">Could not reach the server.</p>';
        }
    }

    function distanceMeters(a, b) {
        const toRad = Math.PI / 180;
        const dLat = (b.latitude - a.latitude) * toRad;
        const dLng = (b.longitude - a.longitude) * toRad;
        const h = Math.sin(dLat / 2) ** 2
            + Math.cos(a.latitude * toRad) * Math.cos(b.latitude * toRad) * Math.sin(dLng / 2) ** 2;
        return 2 * 6371000 * Math.asin(Math.sqrt(h));
    }

    // GPS readings wander by several metres even when the phone is still. Keep reporting the
    // last accepted position until the phone has moved further than the reading's accuracy radius.
    function stabilize(coords) {
        const accuracy = Number.isFinite(coords.accuracy) ? coords.accuracy : Infinity;
        const current = { latitude: coords.latitude, longitude: coords.longitude, accuracy: accuracy };
        const previous = anchorPosition;
        const moved = previous !== null
            && accuracy <= MAX_MOVEMENT_ACCURACY_METERS
            && distanceMeters(previous, current) > Math.max(MIN_MOVEMENT_METERS, accuracy);
        const sharper = previous !== null && accuracy < previous.accuracy / 2;
        if (previous === null || moved || sharper) {
            anchorPosition = current;
            return current;
        }
        return previous;
    }

    function startWatch() {
        if (!currentAppointmentToken || currentAppointmentToken.length !== 64) {
            setTrackingStatus("waiting for appointment pass.");
            return;
        }
        if (!navigator.geolocation) {
            setTrackingStatus("geolocation is not supported on this browser.");
            return;
        }
        if (watchId !== null) {
            return;
        }

        setTrackingStatus("starting...");
        if (gpsFilter) {
            gpsFilter.reset();
        }
        watchId = navigator.geolocation.watchPosition(
            async function (position) {
                // The reading is steadied first, so one that jumps away or is too rough is not
                // sent as if the phone had moved. Until there is a position good enough, nothing is sent.
                const steady = gpsFilter ? gpsFilter.update(GpsFilter.fromPosition(position)) : position.coords;
                if (!steady) {
                    return;
                }
                const reported = stabilize(steady);
                try {
                    const response = await fetch("save_location.php", {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({
                            appointment_token: currentAppointmentToken,
                            device_name: trackedDeviceName || "My Phone",
                            latitude: reported.latitude,
                            longitude: reported.longitude,
                            accuracy: Number.isFinite(reported.accuracy) ? reported.accuracy : null,
                        }),
                    });
                    const result = await response.json();
                    if (result.success) {
                        setTrackingStatus(result.outside_campus ? "paused while you are outside the campus." : "active.");
                    } else {
                        setTrackingStatus("server error: " + result.message);
                    }
                } catch (error) {
                    setTrackingStatus("failed to send location.");
                }
            },
            function (error) {
                setTrackingStatus("GPS error: " + error.message);
            },
            { enableHighAccuracy: true, maximumAge: 0, timeout: 10000 }
        );
    }

    function stopTrackingBySecurity() {
        if (watchId !== null && navigator.geolocation) {
            navigator.geolocation.clearWatch(watchId);
            watchId = null;
            anchorPosition = null;
            if (gpsFilter) {
                gpsFilter.reset();
            }
        }
        autoStarted = false;
        if (locationPollTimer) {
            clearInterval(locationPollTimer);
            locationPollTimer = null;
        }
        if (visitorMapPanel) {
            visitorMapPanel.hidden = true;
        }
        if (visitCompleteNotice) {
            visitCompleteNotice.hidden = false;
        }
        if (checkInHint) {
            checkInHint.textContent = "Visit complete.";
        }
        setTrackingStatus("stopped by security (visit completed).");
    }

    function pollAppointmentCheckIn() {
        const token = sessionStorage.getItem(APPT_TOKEN_KEY);
        if (!token || token.length !== 64) {
            return;
        }
        currentAppointmentToken = token;

        fetch("appointment_status.php?token=" + encodeURIComponent(token), {
            credentials: "same-origin",
        })
            .then(function (r) {
                return r.json();
            })
            .then(function (data) {
                if (!data || !data.success) {
                    return;
                }
                if (data.device_name) {
                    trackedDeviceName = data.device_name;
                }
                renderAppointmentState(data.status, token);
                if (data.completed) {
                    if (checkInHint) {
                        checkInHint.textContent = "Your visit has been completed by security.";
                    }
                    stopTrackingBySecurity();
                    return;
                }
                if (data.checked_in) {
                    if (visitCompleteNotice) {
                        visitCompleteNotice.hidden = true;
                    }
                    if (checkInHint) {
                        checkInHint.textContent = "Security checked you in. Your location is shared only while you are inside the campus.";
                    }
                    if (!autoStarted && watchId === null) {
                        autoStarted = true;
                        startWatch();
                    }
                    showLiveMap();
                } else {
                    setTrackingStatus("standby.");
                }
            })
            .catch(function () {});
    }

    if (appointmentAtInput) {
        const now = new Date();
        const localNow = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        appointmentAtInput.min = localNow;
    }

    if (appointmentForm) {
        appointmentForm.addEventListener("submit", function (e) {
            e.preventDefault();
            if (appointmentError) {
                appointmentError.hidden = true;
                appointmentError.textContent = "";
            }

            const officeCode = document.getElementById("officeCode").value.trim();
            const visitorFullName = document.getElementById("visitorFullName").value.trim();
            const visitorEmail = document.getElementById("visitorEmail").value.trim();
            const appointmentAt = appointmentAtInput ? appointmentAtInput.value : "";
            const deviceNameAppt = document.getElementById("deviceNameAppt").value.trim();
            const visitorConsent = document.getElementById("visitorConsent");

            if (!officeCode || !visitorFullName || !appointmentAt || !visitorConsent.checked) {
                if (appointmentError) {
                    appointmentError.textContent = "Fill in the required fields and confirm the tracking consent.";
                    appointmentError.hidden = false;
                }
                return;
            }

            appointmentSubmit.disabled = true;
            fetch("create_appointment.php", {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    office_code: officeCode,
                    visitor_full_name: visitorFullName,
                    visitor_email: visitorEmail,
                    scheduled_start_at: appointmentAt,
                    appointment_at: appointmentAt,
                    device_name: deviceNameAppt,
                    consent_granted: true,
                    consent_version: "2026-09-18",
                }),
            })
                .then(function (r) {
                    return r.json();
                })
                .then(function (data) {
                    if (!data || !data.success) {
                        if (appointmentError) {
                            appointmentError.textContent = (data && data.message) || "Could not book.";
                            appointmentError.hidden = false;
                        }
                        return;
                    }

                    const token = normalizeToken(data.public_token || "");
                    if (token.length !== 64) {
                        if (appointmentError) {
                            appointmentError.textContent = "Server returned an invalid pass.";
                            appointmentError.hidden = false;
                        }
                        return;
                    }

                    if (data.device_name) {
                        trackedDeviceName = data.device_name;
                    }
                    showAppointmentQr(token, data.status || "pending_approval");
                })
                .catch(function () {
                    if (appointmentError) {
                        appointmentError.textContent = "Could not reach the server.";
                        appointmentError.hidden = false;
                    }
                })
                .finally(function () {
                    appointmentSubmit.disabled = false;
                });
        });
    }

    const clearPassBtn = document.getElementById("clearPassBtn");
    if (clearPassBtn) {
        clearPassBtn.addEventListener("click", function () {
            sessionStorage.removeItem(APPT_TOKEN_KEY);
            currentAppointmentToken = "";
            autoStarted = false;
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
            if (locationPollTimer) {
                clearInterval(locationPollTimer);
                locationPollTimer = null;
            }
            if (visitorMap && visitorMarker) {
                visitorMap.removeLayer(visitorMarker);
                visitorMarker = null;
            }
            if (appointmentForm) {
                appointmentForm.hidden = false;
            }
            if (appointmentSuccess) {
                appointmentSuccess.hidden = true;
            }
            if (appointmentError) {
                appointmentError.hidden = true;
                appointmentError.textContent = "";
            }
            if (qrHost) {
                qrHost.innerHTML = "";
                qrHost.hidden = true;
            }
            if (tokenLine) {
                tokenLine.hidden = true;
            }
            if (checkInHint) {
                checkInHint.textContent = "Waiting for security check-in scan.";
            }
            if (visitCompleteNotice) {
                visitCompleteNotice.hidden = true;
            }
            if (visitorMapPanel) {
                visitorMapPanel.hidden = true;
            }
            setTrackingStatus("standby.");
        });
    }

    if (menuBtn) {
        menuBtn.addEventListener("click", function () {
            if (visitorMenuPanel && visitorMenuPanel.hidden === false) {
                closeMenu();
            } else {
                openMenu();
            }
        });
    }
    if (menuCloseBtn) {
        menuCloseBtn.addEventListener("click", closeMenu);
    }
    if (menuOverlay) {
        menuOverlay.addEventListener("click", closeMenu);
    }

    const savedToken = normalizeToken(sessionStorage.getItem(APPT_TOKEN_KEY) || "");
    if (savedToken.length === 64) {
        currentAppointmentToken = savedToken;
        showAppointmentQr(savedToken, "pending_approval");
        fetch("appointment_status.php?token=" + encodeURIComponent(savedToken), {
            credentials: "same-origin",
        })
            .then(function (r) {
                return r.json();
            })
            .then(function (data) {
                if (data && data.success) {
                    renderAppointmentState(data.status, savedToken);
                    if (data.device_name) {
                        trackedDeviceName = data.device_name;
                    }
                    const nameEl = document.getElementById("visitorFullName");
                    if (nameEl && data.visitor_full_name) {
                        nameEl.value = data.visitor_full_name;
                    }
                }
            })
            .catch(function () {});
    } else {
        setTrackingStatus("standby.");
    }
})();
