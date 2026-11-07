(function () {
    "use strict";

    if (typeof PhoneTrackerAuth === "undefined" || !PhoneTrackerAuth.requireRole(["offices"])) {
        return;
    }

    const dayNames = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
    const state = {
        summary: {},
        appointments: [],
        notifications: [],
        unread: 0,
        office: null,
        profile: null,
        profileImageVersion: 0,
        profilePreviewUrl: "",
        selectedAppointmentId: 0,
        selectedVisit: null,
        recentQuery: "",
        recentStatus: "",
        recentPage: 1,
        recentPageSize: 10,
        requestQuery: "",
        availabilityLoaded: false,
        // The weekly hours being edited: {day, enabled, start, end, breaks: [{key, start, end, label}]}.
        week: [],
        weekDirty: false,
        breakSeq: 0,
        // The day "What visitors can book" shows, 1 = Monday; today to start with.
        previewDay: ((new Date().getDay() + 6) % 7) + 1,
        status: null,
        statusTimer: null,
        toastTimer: null,
    };

    function byId(id) {
        return document.getElementById(id);
    }

    function setText(id, value) {
        const element = byId(id);
        if (element) {
            element.textContent = value == null ? "" : String(value);
        }
    }

    function showError(id, message) {
        const element = byId(id);
        if (!element) {
            return;
        }
        element.textContent = message || "";
        element.hidden = !message;
    }

    function profileImageSource(url, bustCache) {
        if (!url) {
            return "assets/icons/profile_icon.svg";
        }
        if (!bustCache) {
            return url;
        }
        return url + (url.indexOf("?") === -1 ? "?" : "&") + "v=" + String(bustCache);
    }

    function setProfileImage(id, url, bustCache) {
        const image = byId(id);
        if (!image) {
            return;
        }
        const fallback = "assets/icons/profile_icon.svg";
        image.onerror = function () {
            image.onerror = null;
            image.src = fallback;
        };
        image.src = profileImageSource(url, bustCache);
    }

    function renderProfile() {
        const profile = state.profile || {};
        const displayName = profile.display_name || PhoneTrackerAuth.getDisplayName() || PhoneTrackerAuth.getUsername() || "Office Personnel";
        const username = profile.username || PhoneTrackerAuth.getUsername() || "office";
        setText("officeProfileName", displayName);
        setText("officeProfileUsername", username);
        setProfileImage("officeProfileAvatar", profile.profile_image_url || "", state.profileImageVersion);
    }

    function clearProfilePreviewUrl() {
        if (state.profilePreviewUrl) {
            URL.revokeObjectURL(state.profilePreviewUrl);
            state.profilePreviewUrl = "";
        }
    }

    function openProfileEditor() {
        clearProfilePreviewUrl();
        const profile = state.profile || {};
        byId("officeProfileDisplayName").value = profile.display_name || PhoneTrackerAuth.getDisplayName() || "";
        byId("officeProfilePosition").value = profile.position || "";
        byId("officeProfileUsernameReadonly").value = profile.username || PhoneTrackerAuth.getUsername() || "";
        byId("officeProfileImage").value = "";
        byId("officeRemoveProfileImage").checked = false;
        byId("officeRemoveProfileRow").hidden = !profile.profile_image_url;
        setProfileImage("officeProfilePreview", profile.profile_image_url || "", state.profileImageVersion);
        showError("officeProfileError", "");
        byId("officeProfileMenu").hidden = true;
        byId("officeProfileBtn").setAttribute("aria-expanded", "false");
        byId("officeProfileDialog").showModal();
    }

    function closeProfileEditor() {
        clearProfilePreviewUrl();
        byId("officeProfileDialog").close();
    }

    async function fetchJson(url, options) {
        const response = await fetch(url, Object.assign({
            credentials: "same-origin",
            cache: "no-store",
        }, options || {}));
        let data = null;
        try {
            data = await response.json();
        } catch (error) {
            data = { success: false, message: "The server returned an unreadable response." };
        }
        if (response.status === 401 || (data && data.message === "Not authenticated")) {
            window.location.href = "login.html";
            throw new Error("Not authenticated");
        }
        return data;
    }

    function postJson(url, payload) {
        return fetchJson(url, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload),
        });
    }

    function toast(message, stateName) {
        const element = byId("officeToast");
        element.textContent = message;
        element.dataset.state = stateName || "success";
        element.hidden = false;
        if (state.toastTimer) {
            window.clearTimeout(state.toastTimer);
        }
        state.toastTimer = window.setTimeout(function () {
            element.hidden = true;
        }, 4200);
    }

    function parseServerDate(value) {
        if (!value) {
            return null;
        }
        const date = new Date(String(value).replace(" ", "T"));
        return isNaN(date.getTime()) ? null : date;
    }

    function formatDateTime(value, includeYear) {
        const date = parseServerDate(value);
        if (!date) {
            return "—";
        }
        return new Intl.DateTimeFormat("en-PH", {
            month: "short",
            day: "numeric",
            year: includeYear === false ? undefined : "numeric",
            hour: "numeric",
            minute: "2-digit",
        }).format(date);
    }

    function formatSchedule(row) {
        const start = parseServerDate(row.scheduled_start_at || row.appointment_at);
        const end = parseServerDate(row.scheduled_end_at);
        if (!start) {
            return { date: "Schedule unavailable", time: "" };
        }
        const date = new Intl.DateTimeFormat("en-PH", { month: "short", day: "numeric", year: "numeric" }).format(start);
        const startTime = new Intl.DateTimeFormat("en-PH", { hour: "numeric", minute: "2-digit" }).format(start);
        const endTime = end ? new Intl.DateTimeFormat("en-PH", { hour: "numeric", minute: "2-digit" }).format(end) : "";
        return { date: date, time: endTime ? startTime + "–" + endTime : startTime };
    }

    function statusMeta(status, appointment) {
        // A checked-in visitor whose phone confirmed they reached this office.
        if (status === "checked_in" && appointment && appointment.arrived_at) {
            return { label: "Arrived", className: "is-active" };
        }
        const map = {
            pending_approval: { label: "Pending approval", className: "is-pending" },
            reschedule_proposed: { label: "Waiting for visitor", className: "is-pending" },
            approved: { label: "Approved", className: "is-active" },
            rejected: { label: "Declined", className: "is-cancelled" },
            checked_in: { label: "Checked in", className: "is-active" },
            completed: { label: "Completed", className: "is-completed" },
            cancelled: { label: "Cancelled", className: "is-cancelled" },
            unanswered: { label: "No office response", className: "is-cancelled" },
            window_closed: { label: "Appointment done", className: "is-completed" },
        };
        return map[status] || { label: status || "Unknown", className: "is-completed" };
    }

    function purposeLabel(row) {
        return row.purpose || row.subject || "Purpose not provided";
    }

    function matchesQuery(row, query) {
        if (!query) {
            return true;
        }
        const haystack = [
            row.visitor_full_name,
            row.visitor_email,
            row.registration_code,
            row.purpose,
            row.subject,
            row.destination,
            row.contact_number,
        ].join(" ").toLowerCase();
        return haystack.indexOf(query.toLowerCase()) !== -1;
    }

    function appendTextCell(row, primary, secondary, className) {
        const cell = document.createElement("td");
        if (className) {
            cell.className = className;
        }
        const strong = document.createElement("strong");
        strong.className = "office-cell-primary";
        strong.textContent = primary || "—";
        cell.appendChild(strong);
        if (secondary) {
            const small = document.createElement("small");
            small.className = "office-cell-secondary";
            small.textContent = secondary;
            cell.appendChild(small);
        }
        row.appendChild(cell);
        return cell;
    }

    /** "Arrived 2:03 PM, confirmed by GPS (±4 m)" for a visitor at this office. */
    function arrivalText(appointment) {
        const how = appointment.arrival_method === "gps"
            ? "confirmed by GPS" + (appointment.arrival_accuracy_meters != null
                ? " (±" + Math.round(Number(appointment.arrival_accuracy_meters)) + " m)" : "")
            : "confirmed by the visitor";
        return formatDateTime(appointment.arrived_at, false) + ", " + how;
    }

    function appendStatusCell(row, status, appointment) {
        const cell = document.createElement("td");
        const meta = statusMeta(status, appointment);
        const badge = document.createElement("span");
        badge.className = "admin-status " + meta.className;
        badge.textContent = meta.label;
        if (appointment && appointment.arrived_at) {
            badge.title = "Arrived " + arrivalText(appointment);
        }
        cell.appendChild(badge);
        row.appendChild(cell);
    }

    function appendViewButton(row, appointmentId) {
        const cell = document.createElement("td");
        cell.className = "admin-row-actions";
        const button = document.createElement("button");
        button.type = "button";
        button.className = "office-view-button";
        button.dataset.appointmentId = String(appointmentId);
        button.textContent = "View";
        cell.appendChild(button);
        row.appendChild(cell);
    }

    function visitStopLabel(item) {
        return item.visit_id && item.stop_number && item.visit_stop_count
            ? "Stop " + item.stop_number + " of " + item.visit_stop_count
            : "";
    }

    function formatTimeOnly(value) {
        const date = parseServerDate(value);
        return date ? new Intl.DateTimeFormat("en-PH", { hour: "numeric", minute: "2-digit" }).format(date) : "—";
    }

    function otherStopTimesText(visit) {
        return (visit.other_stop_times || []).map(function (stop) {
            return formatTimeOnly(stop.scheduled_start_at) + "–" + formatTimeOnly(stop.scheduled_end_at);
        }).join(", ");
    }

    function makeAppointmentRow(item, kind) {
        const row = document.createElement("tr");
        const schedule = formatSchedule(item);
        const reference = item.registration_code || item.visitor_email || "";
        const stopLabel = visitStopLabel(item);
        appendTextCell(row, item.visitor_full_name, stopLabel ? reference + " · " + stopLabel : reference);
        appendTextCell(row, purposeLabel(item), item.subject && item.subject !== item.purpose ? item.subject : (item.destination || ""));
        appendTextCell(row, schedule.date, schedule.time);
        if (kind === "requests") {
            appendTextCell(row, formatDateTime(item.created_at, false), "");
        }
        appendStatusCell(row, item.status, item);
        if (kind === "history") {
            appendTextCell(row, item.processed_by || (item.status === "unanswered" || item.status === "window_closed" ? "System" : "—"), "");
            appendTextCell(row, formatDateTime(item.processed_at || item.status_updated_at, false), "");
        }
        appendViewButton(row, item.id);
        return row;
    }

    function renderRecent() {
        const body = byId("officeRecentBody");
        body.replaceChildren();
        const todayDate = new Date();
        const today = todayDate.getFullYear() + "-" + String(todayDate.getMonth() + 1).padStart(2, "0") + "-" + String(todayDate.getDate()).padStart(2, "0");
        const rows = state.appointments
            .filter(function (item) {
                const statusMatch = !state.recentStatus ||
                    (state.recentStatus === "today" ? String(item.scheduled_start_at || "").slice(0, 10) === today : item.status === state.recentStatus);
                return statusMatch && matchesQuery(item, state.recentQuery);
            })
            .sort(function (left, right) {
                return String(right.scheduled_start_at || right.created_at || "").localeCompare(String(left.scheduled_start_at || left.created_at || ""));
            });
        const totalPages = Math.max(1, Math.ceil(rows.length / state.recentPageSize));
        state.recentPage = Math.min(state.recentPage, totalPages);
        const start = (state.recentPage - 1) * state.recentPageSize;
        rows.slice(start, start + state.recentPageSize).forEach(function (item) {
            body.appendChild(makeAppointmentRow(item, "recent"));
        });
        byId("officeRecentEmpty").hidden = rows.length !== 0;
        body.parentElement.hidden = rows.length === 0;
        renderRecentPagination(rows.length, totalPages);
    }

    function renderRequests() {
        const body = byId("officeRequestsBody");
        body.replaceChildren();
        const rows = state.appointments
            .filter(function (item) { return item.status === "pending_approval" && matchesQuery(item, state.requestQuery); })
            .sort(function (left, right) { return String(left.created_at).localeCompare(String(right.created_at)); });
        rows.forEach(function (item) { body.appendChild(makeAppointmentRow(item, "requests")); });
        byId("officeRequestsEmpty").hidden = rows.length !== 0;
        body.parentElement.hidden = rows.length === 0;
        setText("officeRequestsCount", rows.length);
    }

    function renderRecentPagination(totalRows, totalPages) {
        const wrap = byId("officeAppointmentsPagination");
        wrap.replaceChildren();
        if (totalRows === 0 || totalPages <= 1) {
            return;
        }
        const summary = document.createElement("p");
        const start = (state.recentPage - 1) * state.recentPageSize + 1;
        const end = Math.min(totalRows, state.recentPage * state.recentPageSize);
        summary.textContent = "Showing " + start + "–" + end + " of " + totalRows;
        const controls = document.createElement("div");
        controls.className = "admin-page-buttons";
        for (let page = 1; page <= totalPages; page += 1) {
            const button = document.createElement("button");
            button.type = "button";
            button.textContent = String(page);
            button.classList.toggle("is-active", page === state.recentPage);
            button.addEventListener("click", function () {
                state.recentPage = page;
                renderRecent();
            });
            controls.appendChild(button);
        }
        wrap.append(summary, controls);
    }

    function renderNotifications() {
        const count = byId("officeNotificationCount");
        count.textContent = state.unread > 99 ? "99+" : String(state.unread);
        count.hidden = state.unread === 0;
        setText("officeNotificationSummary", state.unread ? state.unread + " unread update" + (state.unread === 1 ? "" : "s") : "No unread updates");
        const list = byId("officeNotificationList");
        list.replaceChildren();
        state.notifications.forEach(function (notification) {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "office-notification-item" + (notification.read_at ? "" : " is-unread");
            button.dataset.notificationId = String(notification.id);
            if (notification.appointment_id) {
                button.dataset.appointmentId = String(notification.appointment_id);
            }
            const marker = document.createElement("span");
            marker.className = "admin-notification-marker";
            const copy = document.createElement("span");
            const title = document.createElement("strong");
            title.textContent = notification.title || "Appointment update";
            const message = document.createElement("span");
            message.textContent = notification.message || "";
            const time = document.createElement("time");
            time.textContent = formatDateTime(notification.created_at, false);
            copy.append(title, message, time);
            button.append(marker, copy);
            list.appendChild(button);
        });
        byId("officeNotificationEmpty").hidden = state.notifications.length !== 0;
        byId("markOfficeNotificationsReadBtn").hidden = state.unread === 0;
        renderHelpAlerts();
    }

    /**
     * A visitor who tapped "I can't find it" is standing somewhere waiting, so the request stays
     * at the top of every view until someone takes it, not only in the bell's list.
     */
    function renderHelpAlerts() {
        const host = byId("officeHelpAlerts");
        const waiting = state.notifications.filter(function (notification) {
            return notification.notification_type === "visitor_needs_help" && !notification.read_at;
        });
        host.replaceChildren();
        host.hidden = waiting.length === 0;
        waiting.forEach(function (notification) {
            const alert = document.createElement("div");
            alert.className = "office-help-alert";
            const copy = document.createElement("div");
            const title = document.createElement("strong");
            title.textContent = notification.title || "A visitor can't find your office";
            const message = document.createElement("span");
            message.textContent = notification.message || "";
            const time = document.createElement("time");
            time.textContent = formatDateTime(notification.created_at, false);
            copy.append(title, message, time);
            const button = document.createElement("button");
            button.type = "button";
            button.className = "admin-primary-button";
            button.textContent = "Someone is going";
            button.dataset.helpNotificationId = String(notification.id);
            alert.append(copy, button);
            host.appendChild(alert);
        });
    }

    function renderDashboardData() {
        setText("officePendingCount", state.summary.pending_approvals || 0);
        setText("officeApprovedCount", state.summary.approved || 0);
        setText("officeRejectedCount", state.summary.rejected || 0);
        setText("officeTodayCount", state.summary.todays_appointments || 0);
        const navCount = byId("officeRequestNavCount");
        navCount.textContent = String(state.summary.pending_approvals || 0);
        navCount.hidden = Number(state.summary.pending_approvals || 0) === 0;
        setText("officeProfileOffice", state.office ? state.office.label : "Assigned office");
        const officeScope = state.office ? state.office.label : "your assigned office";
        setText("officeDashboardScope", "Showing " + officeScope + " records only.");
        setText("officeRequestScope", "Only requests addressed to " + officeScope + " appear here.");
        renderProfile();
        setText("officeDashboardDate", "Search and filter every appointment for " + officeScope + ".");
        renderRecent();
        renderRequests();
        renderNotifications();
    }

    async function loadOfficeData(silent) {
        if (!silent) {
            showError("officePageError", "");
        }
        try {
            const results = await Promise.all([
                fetchJson("office_dashboard.php"),
                fetchJson("list_appointments.php"),
            ]);
            const dashboard = results[0];
            const appointments = results[1];
            if (!dashboard || !dashboard.success) {
                throw new Error((dashboard && dashboard.message) || "Could not load the Office dashboard.");
            }
            if (!appointments || !appointments.success) {
                throw new Error((appointments && appointments.message) || "Could not load appointments.");
            }
            state.summary = dashboard.summary || {};
            state.notifications = Array.isArray(dashboard.notifications) ? dashboard.notifications : [];
            state.unread = Number(dashboard.unread_notifications || 0);
            state.office = dashboard.office || null;
            state.profile = dashboard.profile || state.profile;
            state.appointments = Array.isArray(appointments.data) ? appointments.data : [];
            renderDashboardData();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showError("officePageError", error.message || "Could not reach the server.");
            }
        }
    }

    function switchView(view) {
        if (view === "history") {
            view = "dashboard";
        }
        const valid = ["dashboard", "requests", "availability", "teachers"];
        if (valid.indexOf(view) === -1) {
            view = "dashboard";
        }
        document.querySelectorAll("[data-office-panel]").forEach(function (panel) {
            panel.hidden = panel.dataset.officePanel !== view;
        });
        document.querySelectorAll("[data-office-view]").forEach(function (button) {
            const active = button.dataset.officeView === view;
            button.classList.toggle("is-active", active);
            if (active) {
                button.setAttribute("aria-current", "page");
            } else {
                button.removeAttribute("aria-current");
            }
        });
        const labels = { dashboard: "Appointments", requests: "Appointment Requests", availability: "Availability", teachers: "Teachers" };
        setText("officeMobileTitle", labels[view]);
        window.history.replaceState(null, "", window.location.pathname + "#" + view);
        window.scrollTo({ top: 0, behavior: "smooth" });
        if (view === "availability" && !state.availabilityLoaded) {
            loadAvailability();
        } else if (view === "availability") {
            refreshStatus();
        }
        // The "Open now / On lunch break" chip stays current while Availability is open.
        setStatusRefresh(view === "availability");
        if (view === "teachers") {
            loadOfficeTeachers();
        }
    }

    function detailPair(label, value, full) {
        const group = document.createElement("div");
        if (full) {
            group.className = "office-detail-full";
        }
        const term = document.createElement("dt");
        term.textContent = label;
        const definition = document.createElement("dd");
        definition.textContent = value || "Not provided";
        group.append(term, definition);
        return group;
    }

    function renderProposal(proposal) {
        const wrap = byId("officeProposalSummary");
        wrap.replaceChildren();
        if (!proposal) {
            wrap.hidden = true;
            return;
        }
        const heading = document.createElement("strong");
        heading.textContent = "Proposed schedule — " + proposal.status;
        const reason = document.createElement("p");
        reason.textContent = proposal.reason || "No reason provided";
        const list = document.createElement("ul");
        (proposal.slots || []).forEach(function (slot) {
            const item = document.createElement("li");
            item.textContent = formatDateTime(slot.scheduled_start_at) + (Number(slot.is_selected) ? " — selected" : "");
            list.appendChild(item);
        });
        wrap.append(heading, reason, list);
        wrap.hidden = false;
    }

    function renderAppointmentHistory(history) {
        const wrap = byId("officeAppointmentHistory");
        wrap.replaceChildren();
        if (!history || history.length === 0) {
            const empty = document.createElement("p");
            empty.textContent = "No recorded activity.";
            wrap.appendChild(empty);
            return;
        }
        history.forEach(function (event) {
            const item = document.createElement("article");
            const meta = statusMeta(event.to_status);
            const marker = document.createElement("span");
            marker.className = "office-timeline-marker " + meta.className;
            const copy = document.createElement("div");
            const title = document.createElement("strong");
            title.textContent = meta.label;
            const note = document.createElement("p");
            note.textContent = event.note || "Status updated";
            const time = document.createElement("small");
            time.textContent = formatDateTime(event.changed_at) + " · " + (event.changed_by || "System");
            copy.append(title, note, time);
            item.append(marker, copy);
            wrap.appendChild(item);
        });
    }

    async function openAppointment(appointmentId) {
        state.selectedAppointmentId = Number(appointmentId);
        showError("officeAppointmentError", "");
        const data = await fetchJson("office_appointment.php?id=" + encodeURIComponent(appointmentId));
        if (!data || !data.success) {
            toast((data && data.message) || "Could not open appointment.", "error");
            return;
        }
        const appointment = data.appointment;
        setText("officeAppointmentTitle", appointment.visitor_full_name || "Visitor request");
        setText("officeAppointmentCode", appointment.registration_code || "No registration code");
        const meta = statusMeta(appointment.status, appointment);
        const status = byId("officeAppointmentStatus");
        status.className = "admin-status " + meta.className;
        status.textContent = meta.label;
        const schedule = formatSchedule(appointment);
        const details = byId("officeAppointmentDetails");
        details.replaceChildren(
            detailPair("Visitor's name", appointment.visitor_full_name),
            detailPair("Contact number", appointment.contact_number),
            detailPair("Email", appointment.visitor_email),
            detailPair("Visit type", appointment.visit_type === "walk_in" ? "Walk-in" : "Appointment"),
            detailPair("Purpose", appointment.purpose),
            detailPair("Destination", appointment.destination),
            detailPair("Subject / concern", appointment.subject),
            detailPair("Schedule", schedule.date + " · " + schedule.time),
            detailPair("Additional details", appointment.additional_details, true),
            detailPair("Date submitted", formatDateTime(appointment.created_at), true)
        );
        if (appointment.rejection_reason) {
            details.appendChild(detailPair("Decision reason", appointment.rejection_reason, true));
        }
        if (appointment.arrived_at) {
            details.appendChild(detailPair("Arrived at this office", arrivalText(appointment), true));
        }
        state.selectedVisit = data.visit || null;
        if (data.visit) {
            const otherTimes = otherStopTimesText(data.visit);
            details.appendChild(detailPair(
                "Multi-stop visit",
                "Stop " + data.visit.stop_number + " of " + data.visit.stop_count + " · " + data.visit.visit_code
                    + (otherTimes ? " · Visitor's other stops that day: " + otherTimes : ""),
                true
            ));
        }
        renderProposal(data.reschedule_proposal);
        renderAppointmentHistory(data.history || []);
        // Pending: decide or suggest. Approved: the office can still move the time.
        // Checked-in stop of a multi-stop visit: the office marks its meeting done.
        // Walk-in passes are issued immediately and cannot be moved to another time.
        const isPending = appointment.status === "pending_approval";
        const canReschedule = isPending || (appointment.status === "approved" && appointment.visit_type !== "walk_in");
        const canComplete = appointment.status === "checked_in" && Boolean(data.visit);
        byId("openDeclineAppointmentBtn").hidden = !isPending;
        byId("approveOfficeAppointmentBtn").hidden = !isPending;
        byId("openRescheduleAppointmentBtn").hidden = !canReschedule;
        byId("completeOfficeStopBtn").hidden = !canComplete;
        byId("officeAppointmentActions").hidden = !(canReschedule || canComplete);
        byId("officeAppointmentDialog").showModal();
    }

    async function processAppointment(action, reason) {
        showError("officeAppointmentError", "");
        const approveButton = byId("approveOfficeAppointmentBtn");
        const completeButton = byId("completeOfficeStopBtn");
        approveButton.disabled = true;
        completeButton.disabled = true;
        try {
            const data = await postJson("office_appointment_action.php", {
                appointment_id: state.selectedAppointmentId,
                action: action,
                reason: reason || "",
            });
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not update the appointment.");
            }
            [byId("officeDeclineDialog"), byId("officeAppointmentDialog")].forEach(function (dialog) {
                if (dialog.open) { dialog.close(); }
            });
            toast(data.message || "Appointment updated.");
            await loadOfficeData(true);
        } catch (error) {
            const target = action === "reject" && byId("officeDeclineDialog").open ? "officeDeclineError" : "officeAppointmentError";
            showError(target, error.message || "Could not reach the server.");
        } finally {
            approveButton.disabled = false;
            completeButton.disabled = false;
            byId("confirmOfficeDeclineBtn").disabled = false;
        }
    }

    function toInputDate(date) {
        const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
        return local.toISOString().slice(0, 16);
    }

    function setFutureMinimums() {
        const now = new Date();
        const minimum = toInputDate(now);
        document.querySelectorAll(".office-proposed-slot, #officeExceptionStart, #officeExceptionEnd").forEach(function (input) {
            input.min = minimum;
        });
    }

    // ---------- Weekly visitor hours, with breaks ----------
    // A day is its visitor hours with its breaks cut out. The server saves the result as
    // bookable windows (office_availability_service.php), so visitors are never offered a
    // time inside a break. These helpers mirror the server's rules for live feedback.

    const LUNCH = { start: "12:00", end: "13:00", label: "Lunch break" };
    const MAX_BREAKS = 4;

    function minutesOf(value) {
        const match = /^(\d{1,2}):(\d{2})/.exec(String(value || ""));
        if (!match) {
            return null;
        }
        const hours = Number(match[1]);
        const minutes = Number(match[2]);
        return hours <= 23 && minutes <= 59 ? hours * 60 + minutes : null;
    }

    function timeValue(minutes) {
        return String(Math.floor(minutes / 60)).padStart(2, "0") + ":" + String(minutes % 60).padStart(2, "0");
    }

    function clockText(minutes) {
        const hours = Math.floor(minutes / 60) % 24;
        return (hours % 12 || 12) + ":" + String(minutes % 60).padStart(2, "0") + " " + (hours < 12 ? "AM" : "PM");
    }

    function serverClock(value) {
        const date = parseServerDate(value);
        return date ? clockText(date.getHours() * 60 + date.getMinutes()) : "";
    }

    /** "at 8:00 AM" today, "tomorrow at 8:00 AM", or "Mon at 8:00 AM". */
    function whenText(value) {
        const date = parseServerDate(value);
        if (!date) {
            return "";
        }
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const day = new Date(date.getTime());
        day.setHours(0, 0, 0, 0);
        const days = Math.round((day.getTime() - today.getTime()) / 86400000);
        const time = clockText(date.getHours() * 60 + date.getMinutes());
        if (days <= 0) {
            return "at " + time;
        }
        if (days === 1) {
            return "tomorrow at " + time;
        }
        const options = days < 7 ? { weekday: "short" } : { month: "short", day: "numeric" };
        return new Intl.DateTimeFormat("en-PH", options).format(date) + " at " + time;
    }

    function newBreak(start, end, label) {
        state.breakSeq += 1;
        return {
            key: "b" + state.breakSeq,
            start: String(start || "").slice(0, 5),
            end: String(end || "").slice(0, 5),
            label: label == null ? "" : String(label),
        };
    }

    function weekFromPayload(data) {
        const rules = data.rules || [];
        let week;
        if (Array.isArray(data.week) && data.week.length === 7) {
            week = data.week.map(function (day) {
                return {
                    day: Number(day.day_of_week),
                    enabled: Boolean(day.enabled),
                    start: String(day.start_time || "08:00").slice(0, 5),
                    end: String(day.end_time || "17:00").slice(0, 5),
                    breaks: (day.breaks || []).map(function (item) {
                        return newBreak(item.start_time, item.end_time, item.label);
                    }),
                };
            });
        } else {
            // A server without breaks: one window per open day.
            week = dayNames.map(function (_, index) {
                return { day: index + 1, enabled: false, start: "08:00", end: "17:00", breaks: [] };
            });
            rules.forEach(function (rule) {
                const day = week[Number(rule.day_of_week) - 1];
                if (day && Number(rule.is_active) === 1) {
                    day.enabled = true;
                    day.start = String(rule.start_time || "08:00").slice(0, 5);
                    day.end = String(rule.end_time || "17:00").slice(0, 5);
                }
            });
        }
        if (rules.length === 0) {
            // Nothing saved yet: suggest Monday to Friday, 8:00 to 5:00.
            week.forEach(function (day) { day.enabled = day.day <= 5; });
        }
        return week;
    }

    function slotLength() {
        return Math.max(15, Number(byId("officeSlotDuration").value) || 30);
    }

    /** The day's breaks in minutes, in time order. A time that can't be read stays null. */
    function breakSpans(day) {
        return day.breaks.map(function (item) {
            return { key: item.key, label: item.label.trim() || "Break", start: minutesOf(item.start), end: minutesOf(item.end) };
        }).sort(function (left, right) {
            return (left.start === null ? 1e9 : left.start) - (right.start === null ? 1e9 : right.start);
        });
    }

    /** What is wrong with a day, in the server's words, or "" when it can be saved. */
    function dayProblem(day) {
        if (!day.enabled) {
            return "";
        }
        const open = minutesOf(day.start);
        const close = minutesOf(day.end);
        if (open === null || close === null || open >= close) {
            return "The closing time must be after the opening time.";
        }
        if (day.breaks.length > MAX_BREAKS) {
            return "A day can have at most " + MAX_BREAKS + " breaks.";
        }
        const spans = breakSpans(day);
        for (let index = 0; index < spans.length; index += 1) {
            const span = spans[index];
            if (span.start === null || span.end === null || span.start >= span.end) {
                return span.label + " needs an end time after its start time.";
            }
            if (span.start < open || span.end > close) {
                return span.label + " (" + clockText(span.start) + " – " + clockText(span.end)
                    + ") must be within the visitor hours (" + clockText(open) + " – " + clockText(close) + ").";
            }
            if (index > 0 && span.start < spans[index - 1].end) {
                return spans[index - 1].label + " and " + span.label + " overlap.";
            }
        }
        if (dayWindows(day).length === 0) {
            return "The breaks take up all of the visitor hours. Close the day instead.";
        }
        return "";
    }

    /** The day's bookable windows: its hours with the breaks cut out. */
    function dayWindows(day) {
        const open = minutesOf(day.start);
        const close = minutesOf(day.end);
        if (open === null || close === null || open >= close) {
            return [];
        }
        const windows = [];
        let cursor = open;
        breakSpans(day).forEach(function (span) {
            if (span.start === null || span.end === null || span.start >= span.end) {
                return;
            }
            if (span.start > cursor) {
                windows.push([cursor, Math.min(span.start, close)]);
            }
            cursor = Math.max(cursor, span.end);
        });
        if (close > cursor) {
            windows.push([cursor, close]);
        }
        return windows.filter(function (window) { return window[1] > window[0]; });
    }

    /** Where every slot visitors can book on the day starts, as the visitor app lists them. */
    function daySlots(day, length) {
        const slots = [];
        dayWindows(day).forEach(function (window) {
            for (let start = window[0]; start + length <= window[1]; start += length) {
                slots.push(start);
            }
        });
        return slots;
    }

    /** Time at the end of a window too short for one more slot: "30 min before Lunch break". */
    function dayLeftovers(day, length) {
        const spans = breakSpans(day);
        return dayWindows(day).map(function (window) {
            const rest = (window[1] - window[0]) % length;
            const next = spans.find(function (span) { return span.start === window[1]; });
            return rest > 0 ? rest + " min before " + (next ? next.label : "closing") : "";
        }).filter(Boolean);
    }

    /** The hours every day's timeline shows: the earliest opening to the latest closing. */
    function timelineRange() {
        let first = null;
        let last = null;
        state.week.forEach(function (day) {
            const open = minutesOf(day.start);
            const close = minutesOf(day.end);
            if (!day.enabled || open === null || close === null || open >= close) {
                return;
            }
            first = first === null ? open : Math.min(first, open);
            last = last === null ? close : Math.max(last, close);
        });
        if (first === null) {
            return { start: 7 * 60, end: 18 * 60 };
        }
        const start = Math.floor(first / 60) * 60;
        return { start: start, end: Math.min(24 * 60, Math.max(start + 4 * 60, Math.ceil(last / 60) * 60)) };
    }

    function percent(minutes, range) {
        return ((minutes - range.start) / (range.end - range.start) * 100).toFixed(3) + "%";
    }

    function scaleElement(range) {
        const scale = document.createElement("div");
        scale.className = "office-timeline-scale";
        scale.setAttribute("aria-hidden", "true");
        const step = range.end - range.start > 12 * 60 ? 120 : 60;
        for (let minute = range.start; minute <= range.end; minute += step) {
            const mark = document.createElement("span");
            const hour = Math.floor(minute / 60) % 24;
            mark.style.left = percent(minute, range);
            mark.textContent = (hour % 12 || 12) + (hour < 12 ? " AM" : " PM");
            scale.appendChild(mark);
        }
        return scale;
    }

    function renderTimeline(container, day, range, slots) {
        container.replaceChildren();
        const parts = [];
        function segment(className, start, end, title) {
            const piece = document.createElement("span");
            piece.className = "office-timeline-segment " + className;
            piece.style.left = percent(start, range);
            piece.style.width = ((end - start) / (range.end - range.start) * 100).toFixed(3) + "%";
            piece.title = title;
            container.appendChild(piece);
            parts.push({ at: start, text: title });
        }
        dayWindows(day).forEach(function (window) {
            segment("is-open", window[0], window[1], "Visitors " + clockText(window[0]) + " – " + clockText(window[1]));
        });
        breakSpans(day).forEach(function (span) {
            if (span.start !== null && span.end !== null && span.start < span.end) {
                segment("is-break", span.start, span.end, span.label + " " + clockText(span.start) + " – " + clockText(span.end));
            }
        });
        (slots || []).forEach(function (start) {
            const tick = document.createElement("span");
            tick.className = "office-timeline-tick";
            tick.style.left = percent(start, range);
            container.appendChild(tick);
        });
        parts.sort(function (left, right) { return left.at - right.at; });
        container.setAttribute("role", "img");
        container.setAttribute("aria-label", dayNames[day.day - 1] + ": " + (parts.length
            ? parts.map(function (part) { return part.text; }).join(", ")
            : "no visitor hours"));
    }

    function makeInput(type, className, value, label) {
        const input = document.createElement("input");
        input.type = type;
        input.className = className;
        input.value = value;
        input.setAttribute("aria-label", label);
        return input;
    }

    function makeButton(className, text, action, label) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = className;
        button.textContent = text;
        button.dataset.action = action;
        if (label) {
            button.setAttribute("aria-label", label);
        }
        return button;
    }

    function makeText(tag, className, text) {
        const element = document.createElement(tag);
        element.className = className;
        if (text) {
            element.textContent = text;
        }
        return element;
    }

    function breakRow(day, item) {
        const dayName = dayNames[day.day - 1];
        const what = item.label.trim() || "break";
        const row = makeText("div", "office-break-row");
        row.dataset.breakKey = item.key;
        const label = makeInput("text", "office-break-label", item.label, dayName + " break name");
        label.dataset.field = "label";
        label.placeholder = "Break name";
        label.maxLength = 60;
        label.setAttribute("list", "officeBreakNames");
        const start = makeInput("time", "office-break-start", item.start, dayName + " " + what + " starts");
        start.dataset.field = "start";
        const end = makeInput("time", "office-break-end", item.end, dayName + " " + what + " ends");
        end.dataset.field = "end";
        row.append(
            label,
            start,
            makeText("span", "office-day-to", "to"),
            end,
            makeButton("office-break-remove", "Remove", "remove-break", "Remove " + what + " on " + dayName)
        );
        return row;
    }

    function dayRow(day, range) {
        const dayName = dayNames[day.day - 1];
        const row = makeText("div", "office-schedule-row");
        row.dataset.day = String(day.day);
        row.classList.toggle("is-closed", !day.enabled);

        const main = makeText("div", "office-day-main");
        const toggle = makeText("label", "office-day-toggle");
        const checkbox = document.createElement("input");
        checkbox.type = "checkbox";
        checkbox.className = "office-day-enabled";
        checkbox.dataset.field = "enabled";
        checkbox.checked = day.enabled;
        toggle.append(checkbox, makeText("strong", "", dayName));
        const start = makeInput("time", "office-day-start", day.start, dayName + " opens");
        start.dataset.field = "start";
        start.disabled = !day.enabled;
        const end = makeInput("time", "office-day-end", day.end, dayName + " closes");
        end.dataset.field = "end";
        end.disabled = !day.enabled;
        main.append(toggle, start, makeText("span", "office-day-to", "to"), end,
            makeText("span", "office-day-summary"), makeText("span", "office-day-state"));
        row.appendChild(main);

        if (day.enabled) {
            // In time order whenever the day is drawn (not while a time is being typed).
            day.breaks.sort(function (left, right) {
                const leftStart = minutesOf(left.start);
                const rightStart = minutesOf(right.start);
                return (leftStart === null ? 1e9 : leftStart) - (rightStart === null ? 1e9 : rightStart);
            });
            const breaks = makeText("div", "office-day-breaks");
            day.breaks.forEach(function (item) { breaks.appendChild(breakRow(day, item)); });
            const tools = makeText("div", "office-day-tools");
            const add = makeButton("office-link-button", "+ Add break", "add-break", "Add a break on " + dayName);
            add.disabled = day.breaks.length >= MAX_BREAKS;
            tools.append(add, makeButton("office-link-button", "Copy to other open days", "copy-day",
                "Copy " + dayName + "'s hours and breaks to the other open days"));
            const error = makeText("p", "office-day-error");
            error.setAttribute("role", "alert");
            row.append(breaks, tools, makeText("div", "office-day-timeline"), makeText("p", "office-day-note"), error);
        }
        refreshRow(row, day, range);
        return row;
    }

    /** Updates what a day row shows about its hours: slots, timeline, notes, problems. */
    function refreshRow(row, day, range) {
        row.querySelector(".office-day-state").textContent = day.enabled ? "Open" : "Closed";
        const summary = row.querySelector(".office-day-summary");
        if (!day.enabled) {
            summary.textContent = "";
            return;
        }
        const problem = dayProblem(day);
        const error = row.querySelector(".office-day-error");
        error.textContent = problem;
        error.hidden = !problem;
        row.classList.toggle("has-problem", Boolean(problem));
        const length = slotLength();
        const slots = problem ? [] : daySlots(day, length);
        summary.textContent = problem ? "" : (slots.length === 1 ? "1 slot" : slots.length + " slots");
        renderTimeline(row.querySelector(".office-day-timeline"), day, range, slots);
        const leftovers = problem ? [] : dayLeftovers(day, length);
        const note = row.querySelector(".office-day-note");
        note.textContent = leftovers.length ? "Too short for a " + length + "-min slot: " + leftovers.join(", ") + "." : "";
        note.hidden = leftovers.length === 0;
    }

    function renderWeek() {
        const range = timelineRange();
        const wrap = byId("officeWeeklySchedule");
        wrap.replaceChildren(scaleElement(range));
        state.week.forEach(function (day) { wrap.appendChild(dayRow(day, range)); });
        renderPreview();
    }

    /** After an edit: every row's slots and timeline (the shared hour scale may change too). */
    function refreshWeek() {
        const range = timelineRange();
        const wrap = byId("officeWeeklySchedule");
        const scale = wrap.querySelector(".office-timeline-scale");
        if (scale) {
            scale.replaceWith(scaleElement(range));
        }
        wrap.querySelectorAll(".office-schedule-row").forEach(function (row) {
            refreshRow(row, state.week[Number(row.dataset.day) - 1], range);
        });
        renderPreview();
    }

    /** Rebuilds one day after its breaks or open/closed state changed, keeping keyboard focus. */
    function replaceRow(row, day, focusSelector) {
        const fresh = dayRow(day, timelineRange());
        row.replaceWith(fresh);
        const target = focusSelector ? fresh.querySelector(focusSelector) : null;
        if (target) {
            target.focus();
        }
        refreshWeek();
    }

    function renderPreview() {
        const days = byId("officePreviewDays");
        days.replaceChildren();
        state.week.forEach(function (day) {
            const button = makeText("button", "office-preview-day" + (day.enabled ? "" : " is-closed"), dayNames[day.day - 1].slice(0, 3));
            button.type = "button";
            button.dataset.previewDay = String(day.day);
            button.title = dayNames[day.day - 1] + (day.enabled ? "" : " (closed)");
            button.setAttribute("aria-pressed", String(day.day === state.previewDay));
            days.appendChild(button);
        });
        const day = state.week[state.previewDay - 1];
        const dayName = dayNames[day.day - 1];
        const list = byId("officeSlotPreview");
        list.replaceChildren();
        const summary = byId("officeSlotPreviewSummary");
        if (!day.enabled) {
            summary.textContent = dayName + " is closed, so visitors can't book it.";
            return;
        }
        const problem = dayProblem(day);
        if (problem) {
            summary.textContent = "Fix " + dayName + " above to see its times.";
            return;
        }
        const length = slotLength();
        const capacity = Math.max(1, Number(byId("officeSlotCapacity").value) || 1);
        const slots = daySlots(day, length);
        summary.textContent = dayName + ": " + (slots.length === 1 ? "1 time" : slots.length + " times") + " of " + length
            + " min, up to " + capacity + (capacity === 1 ? " visitor" : " visitors") + " each. Visitors don't see times that are full or covered by a temporary change.";
        const items = slots.map(function (start) { return { at: start }; });
        breakSpans(day).forEach(function (span) { items.push({ at: span.start, span: span }); });
        items.sort(function (left, right) { return left.at - right.at || (left.span ? -1 : 1); });
        items.forEach(function (item) {
            list.appendChild(item.span
                ? makeText("span", "office-preview-break", item.span.label + " · " + clockText(item.span.start) + " – " + clockText(item.span.end))
                : makeText("span", "office-preview-slot", clockText(item.at)));
        });
        if (!slots.length) {
            list.appendChild(makeText("span", "office-preview-empty", "No part of the day is long enough for a " + length + "-min slot."));
        }
    }

    function markDirty() {
        state.weekDirty = true;
        byId("officeUnsavedNote").hidden = false;
        // A refused save's message is out of date once something changes; rows show their own.
        showError("officeAvailabilityError", "");
    }

    function markClean() {
        state.weekDirty = false;
        byId("officeUnsavedNote").hidden = true;
    }

    /** A break that fits the day: lunch at 12:00 if free, else the first free half hour. */
    function suggestBreak(day) {
        if (day.breaks.length >= MAX_BREAKS) {
            return null;
        }
        const candidates = [[LUNCH.start, LUNCH.end, LUNCH.label]];
        const open = minutesOf(day.start);
        const close = minutesOf(day.end);
        if (open !== null && close !== null) {
            for (let start = Math.ceil(open / 30) * 30; start + 30 <= close; start += 30) {
                candidates.push([timeValue(start), timeValue(start + 30), ""]);
            }
        }
        for (let index = 0; index < candidates.length; index += 1) {
            const item = newBreak(candidates[index][0], candidates[index][1], candidates[index][2]);
            if (dayProblem(Object.assign({}, day, { breaks: day.breaks.concat([item]) })) === "") {
                return item;
            }
        }
        return null;
    }

    function copyDay(source) {
        const targets = state.week.filter(function (day) { return day.enabled && day.day !== source.day; });
        const sourceName = dayNames[source.day - 1];
        if (!targets.length) {
            toast("Turn on the other days first: " + sourceName + " is copied to open days only.", "error");
            return;
        }
        targets.forEach(function (day) {
            day.start = source.start;
            day.end = source.end;
            day.breaks = source.breaks.map(function (item) { return newBreak(item.start, item.end, item.label); });
        });
        markDirty();
        renderWeek();
        toast("Copied " + sourceName + "'s hours and breaks to " + targets.length + (targets.length === 1 ? " day" : " days") + ". Save to apply.");
    }

    function addLunchEverywhere() {
        let added = 0;
        let already = 0;
        const skipped = [];
        state.week.forEach(function (day) {
            if (!day.enabled) {
                return;
            }
            if (day.breaks.some(function (item) { return item.start === LUNCH.start && item.end === LUNCH.end; })) {
                already += 1;
                return;
            }
            const item = newBreak(LUNCH.start, LUNCH.end, LUNCH.label);
            if (day.breaks.length < MAX_BREAKS && dayProblem(Object.assign({}, day, { breaks: day.breaks.concat([item]) })) === "") {
                day.breaks.push(item);
                added += 1;
            } else {
                skipped.push(dayNames[day.day - 1]);
            }
        });
        if (added) {
            markDirty();
            renderWeek();
        }
        const notAdded = skipped.length ? " " + skipped.join(", ") + (skipped.length === 1 ? " was" : " were") + " skipped: the time overlaps a break or is outside the hours." : "";
        if (added) {
            toast("Added a 12–1 PM lunch break to " + added + (added === 1 ? " day" : " days") + ". Save to apply." + notAdded);
        } else if (already && !skipped.length) {
            toast("Every open day already has a 12–1 PM lunch break.");
        } else {
            toast("No open day had room for a 12–1 PM lunch break." + notAdded, "error");
        }
    }

    function dayOf(element) {
        const row = element.closest(".office-schedule-row");
        return row ? { row: row, day: state.week[Number(row.dataset.day) - 1] } : null;
    }

    function onWeekEdit(event) {
        const field = event.target.dataset.field;
        const found = field ? dayOf(event.target) : null;
        if (!found) {
            return;
        }
        if (field === "enabled") {
            if (event.type === "change") {
                found.day.enabled = event.target.checked;
                markDirty();
                replaceRow(found.row, found.day, ".office-day-enabled");
            }
            return;
        }
        const breakElement = event.target.closest(".office-break-row");
        if (breakElement) {
            const item = found.day.breaks.find(function (entry) { return entry.key === breakElement.dataset.breakKey; });
            if (!item) {
                return;
            }
            item[field] = event.target.value;
        } else {
            found.day[field] = event.target.value;
        }
        markDirty();
        refreshWeek();
    }

    function onWeekClick(event) {
        const button = event.target.closest("button[data-action]");
        const found = button ? dayOf(button) : null;
        if (!found) {
            return;
        }
        if (button.dataset.action === "add-break") {
            const item = suggestBreak(found.day);
            if (!item) {
                toast("There's no free time left on " + dayNames[found.day.day - 1] + " for another break.", "error");
                return;
            }
            found.day.breaks.push(item);
            markDirty();
            replaceRow(found.row, found.day, '[data-break-key="' + item.key + '"] .office-break-label');
        } else if (button.dataset.action === "remove-break") {
            const key = button.closest(".office-break-row").dataset.breakKey;
            found.day.breaks = found.day.breaks.filter(function (item) { return item.key !== key; });
            markDirty();
            replaceRow(found.row, found.day, '[data-action="add-break"]');
        } else if (button.dataset.action === "copy-day") {
            copyDay(found.day);
        }
    }

    // ---------- Live status and unplanned breaks ----------

    function renderStatus(status) {
        state.status = status || null;
        const info = status || { state: "open", label: "Open" };
        let text = "Open now";
        let tone = "is-open";
        if (info.state === "open") {
            if (info.label && info.label !== "Open") {
                text = info.label + (info.until ? " · until " + serverClock(info.until) : "");
            } else if (info.until && info.next === "Closing") {
                text = "Open now · closes at " + serverClock(info.until);
            } else if (info.until && info.next) {
                text = "Open now · " + info.next + " at " + serverClock(info.until);
            } else if (info.until) {
                text = "Open now · until " + serverClock(info.until);
            }
        } else if (info.state === "break") {
            tone = "is-break";
            text = (info.label === "Break" ? "On a break" : info.label) + (info.until ? " · back " + whenText(info.until) : "");
        } else if (info.state === "closed") {
            tone = "is-off";
            text = "Closed now" + (info.until ? " · opens " + whenText(info.until) : "");
        } else if (info.state === "paused") {
            tone = "is-paused";
            text = "Paused · " + info.label + (info.until ? " · until " + serverClock(info.until) : "");
        } else if (info.state === "not_accepting") {
            tone = "is-closed";
            text = "Not accepting visitors";
        }
        const chip = byId("officeAvailabilityChip");
        chip.textContent = text;
        chip.className = "office-availability-chip " + tone;
        const pause = byId("officePauseBtn");
        pause.hidden = info.state === "not_accepting";
        pause.dataset.mode = info.state === "paused" && info.pause_id ? "resume" : "pause";
        pause.textContent = pause.dataset.mode === "resume" ? "Resume now" : "Pause visits";
    }

    async function refreshStatus() {
        if (document.hidden) {
            return;
        }
        try {
            const data = await fetchJson("office_availability.php?status=1");
            if (data && data.success) {
                renderStatus(data.status);
            }
        } catch (error) {
            // Keep showing the last status; the next refresh tries again.
        }
    }

    function setStatusRefresh(active) {
        if (state.statusTimer) {
            window.clearInterval(state.statusTimer);
            state.statusTimer = null;
        }
        if (active) {
            state.statusTimer = window.setInterval(refreshStatus, 60000);
        }
    }

    /**
     * Shows what the server sent. With [keepEdits], unsaved changes to the weekly hours stay
     * on screen (after a pause, or a temporary change was added) and only the rest updates.
     */
    function renderAvailability(data, keepEdits) {
        const settings = data.settings || {};
        if (!(keepEdits && state.weekDirty)) {
            const accepting = Number(settings.accepting_visitors) === 1;
            byId("officeAcceptingVisitors").checked = accepting;
            byId("officeClosureFields").hidden = accepting;
            byId("officeUnavailableReason").value = settings.unavailable_reason || "";
            byId("officeAvailableAgain").value = settings.available_again_at ? String(settings.available_again_at).replace(" ", "T").slice(0, 16) : "";
            byId("officeSlotDuration").value = String(settings.slot_duration_minutes || 30);
            byId("officeSlotCapacity").value = String(settings.maximum_visitors_per_slot || 1);
            state.week = weekFromPayload(data);
            renderWeek();
            markClean();
        }
        renderExceptions(data.exceptions || []);
        renderStatus(data.status || {
            state: Number(settings.accepting_visitors) === 1 ? "open" : "not_accepting",
            label: "Open",
        });
    }

    function renderExceptions(exceptions) {
        const list = byId("officeExceptionList");
        list.replaceChildren();
        exceptions.forEach(function (exception) {
            const item = document.createElement("article");
            item.className = "office-exception-item";
            const marker = document.createElement("span");
            marker.className = Number(exception.is_available) === 1 ? "is-available" : "is-closed";
            const copy = document.createElement("div");
            const title = document.createElement("strong");
            title.textContent = Number(exception.is_available) === 1 ? "Extra visitor hours" : "Office unavailable";
            const schedule = document.createElement("span");
            schedule.textContent = formatDateTime(exception.starts_at) + " – " + formatDateTime(exception.ends_at);
            const reason = document.createElement("small");
            reason.textContent = exception.reason || "No reason provided";
            copy.append(title, schedule, reason);
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "office-remove-exception";
            remove.dataset.exceptionId = String(exception.id);
            remove.textContent = "Remove";
            item.append(marker, copy, remove);
            list.appendChild(item);
        });
        byId("officeExceptionEmpty").hidden = exceptions.length !== 0;
    }

    async function loadAvailability() {
        showError("officeAvailabilityError", "");
        try {
            const data = await fetchJson("office_availability.php");
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not load availability.");
            }
            renderAvailability(data);
            state.availabilityLoaded = true;
        } catch (error) {
            showError("officeAvailabilityError", error.message || "Could not reach the server.");
        }
    }

    function showConflicts(data) {
        const list = byId("officeConflictList");
        list.replaceChildren();
        (data.conflicts || []).forEach(function (conflict) {
            const item = makeText("li", "office-conflict-item");
            const copy = document.createElement("div");
            copy.append(
                makeText("strong", "", formatDateTime(conflict.starts_at)),
                makeText("span", "", [conflict.visitor, conflict.code, statusMeta(conflict.status).label].filter(Boolean).join(" · ")),
                makeText("small", "", conflict.reason)
            );
            const open = makeText("button", "office-secondary-button", "Open");
            open.type = "button";
            open.dataset.appointmentId = String(conflict.id);
            open.setAttribute("aria-label", "Open " + conflict.visitor + "'s visit on " + formatDateTime(conflict.starts_at));
            item.append(copy, open);
            list.appendChild(item);
        });
        const more = Number(data.conflict_total || 0) - (data.conflicts || []).length;
        if (more > 0) {
            list.appendChild(makeText("li", "office-conflict-more", "…and " + more + " more."));
        }
        setText("officeConflictTitle", data.message || "Booked visits fall outside the new schedule");
        byId("officeConflictDialog").showModal();
    }

    async function saveAvailability(event, confirmConflicts) {
        if (event) {
            event.preventDefault();
        }
        showError("officeAvailabilityError", "");
        const broken = state.week.find(function (day) { return dayProblem(day) !== ""; });
        if (broken) {
            showError("officeAvailabilityError", dayNames[broken.day - 1] + ": " + dayProblem(broken));
            const row = document.querySelector('.office-schedule-row[data-day="' + broken.day + '"]');
            if (row) {
                row.scrollIntoView({ behavior: "smooth", block: "center" });
            }
            return;
        }
        const button = byId("saveOfficeAvailabilityBtn");
        button.disabled = true;
        try {
            const data = await postJson("office_availability.php", {
                action: "save_schedule",
                accepting_visitors: byId("officeAcceptingVisitors").checked,
                slot_duration_minutes: Number(byId("officeSlotDuration").value),
                maximum_visitors_per_slot: Number(byId("officeSlotCapacity").value),
                unavailable_reason: byId("officeUnavailableReason").value.trim(),
                available_again_at: byId("officeAvailableAgain").value,
                schedules: state.week.map(function (day) {
                    return {
                        day_of_week: day.day,
                        enabled: day.enabled,
                        start_time: day.start,
                        end_time: day.end,
                        breaks: day.breaks.map(function (item) {
                            return { start_time: item.start, end_time: item.end, label: item.label.trim() };
                        }),
                    };
                }),
                confirm_conflicts: Boolean(confirmConflicts),
            });
            if (data && data.code === "schedule_conflicts") {
                showConflicts(data);
                return;
            }
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not save availability.");
            }
            markClean();
            renderAvailability(data);
            toast(data.message || "Availability saved.");
        } catch (error) {
            showError("officeAvailabilityError", error.message || "Could not reach the server.");
        } finally {
            button.disabled = false;
        }
    }

    document.querySelectorAll("[data-office-view]").forEach(function (button) {
        button.addEventListener("click", function () { switchView(button.dataset.officeView); });
    });
    document.querySelectorAll("[data-summary-view]").forEach(function (button) {
        button.addEventListener("click", function () {
            const view = button.dataset.summaryView;
            const status = button.dataset.summaryStatus || "";
            if (view === "dashboard") {
                state.recentStatus = status;
                byId("officeRecentStatus").value = status;
                state.recentPage = 1;
                renderRecent();
            }
            switchView(view);
        });
    });

    byId("officeRecentSearch").addEventListener("input", function (event) { state.recentQuery = event.target.value.trim(); state.recentPage = 1; renderRecent(); });
    byId("officeRecentStatus").addEventListener("change", function (event) { state.recentStatus = event.target.value; state.recentPage = 1; renderRecent(); });
    byId("officeRequestSearch").addEventListener("input", function (event) { state.requestQuery = event.target.value.trim(); renderRequests(); });

    document.addEventListener("click", function (event) {
        const appointmentButton = event.target.closest(".office-view-button[data-appointment-id]");
        if (appointmentButton) {
            openAppointment(Number(appointmentButton.dataset.appointmentId));
            return;
        }
        if (!byId("officeProfileMenu").hidden && !byId("officeProfileMenu").contains(event.target) && !byId("officeProfileBtn").contains(event.target)) {
            byId("officeProfileMenu").hidden = true;
            byId("officeProfileBtn").setAttribute("aria-expanded", "false");
        }
        if (!byId("officeNotificationPanel").hidden && !byId("officeNotificationPanel").contains(event.target) && !byId("officeNotificationBtn").contains(event.target)) {
            byId("officeNotificationPanel").hidden = true;
            byId("officeNotificationBtn").setAttribute("aria-expanded", "false");
        }
    });

    byId("closeOfficeAppointmentBtn").addEventListener("click", function () { byId("officeAppointmentDialog").close(); });
    byId("approveOfficeAppointmentBtn").addEventListener("click", function () {
        if (window.confirm("Approve this appointment and issue the visitor's QR pass?")) {
            processAppointment("approve", "");
        }
    });
    byId("openDeclineAppointmentBtn").addEventListener("click", function () {
        byId("officeDeclineReason").value = "";
        showError("officeDeclineError", "");
        byId("officeDeclineDialog").showModal();
    });
    byId("closeOfficeDeclineBtn").addEventListener("click", function () { byId("officeDeclineDialog").close(); });
    byId("cancelOfficeDeclineBtn").addEventListener("click", function () { byId("officeDeclineDialog").close(); });
    byId("officeDeclineForm").addEventListener("submit", function (event) {
        event.preventDefault();
        const reason = byId("officeDeclineReason").value.trim();
        if (reason.length < 5) {
            showError("officeDeclineError", "Please give the visitor a clear reason.");
            return;
        }
        byId("confirmOfficeDeclineBtn").disabled = true;
        processAppointment("reject", reason);
    });

    byId("completeOfficeStopBtn").addEventListener("click", function () {
        if (window.confirm("Mark this meeting as done? The visitor's campus visit continues until Security checks them out.")) {
            processAppointment("complete", "");
        }
    });

    byId("openRescheduleAppointmentBtn").addEventListener("click", function () {
        byId("officeRescheduleForm").reset();
        setFutureMinimums();
        showError("officeRescheduleError", "");
        const hint = byId("officeRescheduleVisitHint");
        const visit = state.selectedVisit;
        const otherTimes = visit ? otherStopTimesText(visit) : "";
        hint.textContent = visit
            ? "This request is part of a multi-stop visit"
                + (otherTimes ? " with other stops at " + otherTimes : "")
                + ". Offer times on the same day that leave 10 minutes before and after those stops. A time on another day moves this request out of the visit."
            : "";
        hint.hidden = !visit;
        byId("officeRescheduleDialog").showModal();
    });
    byId("closeOfficeRescheduleBtn").addEventListener("click", function () { byId("officeRescheduleDialog").close(); });
    byId("cancelOfficeRescheduleBtn").addEventListener("click", function () { byId("officeRescheduleDialog").close(); });
    byId("officeRescheduleForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        showError("officeRescheduleError", "");
        const slots = Array.from(document.querySelectorAll(".office-proposed-slot")).map(function (input) { return input.value; }).filter(Boolean);
        const button = byId("confirmOfficeRescheduleBtn");
        button.disabled = true;
        try {
            const data = await postJson("office_propose_reschedule.php", {
                appointment_id: state.selectedAppointmentId,
                reason: byId("officeRescheduleReason").value.trim(),
                message: byId("officeRescheduleMessage").value.trim(),
                slots: slots,
            });
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not send the proposed schedules.");
            }
            byId("officeRescheduleDialog").close();
            byId("officeAppointmentDialog").close();
            toast(data.message || "Schedule options sent.");
            await loadOfficeData(true);
        } catch (error) {
            showError("officeRescheduleError", error.message || "Could not reach the server.");
        } finally {
            button.disabled = false;
        }
    });

    byId("officeAcceptingVisitors").addEventListener("change", function (event) {
        byId("officeClosureFields").hidden = event.target.checked;
        markDirty();
    });
    byId("officeAvailabilityForm").addEventListener("submit", function (event) { saveAvailability(event, false); });
    byId("officeWeeklySchedule").addEventListener("input", onWeekEdit);
    byId("officeWeeklySchedule").addEventListener("change", onWeekEdit);
    byId("officeWeeklySchedule").addEventListener("click", onWeekClick);
    byId("officeAddLunchBtn").addEventListener("click", addLunchEverywhere);
    byId("officePreviewDays").addEventListener("click", function (event) {
        const button = event.target.closest("[data-preview-day]");
        if (button) {
            state.previewDay = Number(button.dataset.previewDay);
            renderPreview();
        }
    });
    byId("officeSlotDuration").addEventListener("change", function () { markDirty(); refreshWeek(); });
    byId("officeSlotCapacity").addEventListener("input", function () { markDirty(); renderPreview(); });
    byId("officeUnavailableReason").addEventListener("input", markDirty);
    byId("officeAvailableAgain").addEventListener("change", markDirty);
    window.addEventListener("beforeunload", function (event) {
        if (state.weekDirty) {
            event.preventDefault();
            event.returnValue = "";
        }
    });

    byId("officeConflictList").addEventListener("click", function (event) {
        const button = event.target.closest("button[data-appointment-id]");
        if (button) {
            byId("officeConflictDialog").close();
            openAppointment(Number(button.dataset.appointmentId));
        }
    });
    byId("closeOfficeConflictBtn").addEventListener("click", function () { byId("officeConflictDialog").close(); });
    byId("cancelOfficeConflictBtn").addEventListener("click", function () { byId("officeConflictDialog").close(); });
    byId("confirmOfficeConflictBtn").addEventListener("click", function () {
        byId("officeConflictDialog").close();
        saveAvailability(null, true);
    });

    byId("officePauseBtn").addEventListener("click", async function () {
        const button = byId("officePauseBtn");
        if (button.dataset.mode !== "resume") {
            byId("officePauseForm").reset();
            showError("officePauseError", "");
            byId("officePauseDialog").showModal();
            return;
        }
        if (!window.confirm("Resume visits now? Visitors can book and walk in again.")) {
            return;
        }
        button.disabled = true;
        try {
            const data = await postJson("office_availability.php", { action: "resume_now", exception_id: state.status ? state.status.pause_id : 0 });
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not resume visits.");
            }
            renderAvailability(data, true);
            toast(data.message || "Visits resumed.");
        } catch (error) {
            toast(error.message || "Could not reach the server.", "error");
            refreshStatus();
        } finally {
            button.disabled = false;
        }
    });
    byId("closeOfficePauseBtn").addEventListener("click", function () { byId("officePauseDialog").close(); });
    byId("cancelOfficePauseBtn").addEventListener("click", function () { byId("officePauseDialog").close(); });
    byId("officePauseForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        showError("officePauseError", "");
        const button = byId("confirmOfficePauseBtn");
        const length = byId("officePauseForm").querySelector('input[name="officePauseLength"]:checked');
        button.disabled = true;
        try {
            const data = await postJson("office_availability.php", {
                action: "pause_now",
                length: length ? length.value : "30",
                reason: byId("officePauseReason").value.trim(),
            });
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not pause visits.");
            }
            renderAvailability(data, true);
            byId("officePauseDialog").close();
            toast(data.message || "Visits paused.");
        } catch (error) {
            showError("officePauseError", error.message || "Could not reach the server.");
        } finally {
            button.disabled = false;
        }
    });
    byId("addOfficeExceptionBtn").addEventListener("click", function () {
        byId("officeExceptionForm").reset();
        setFutureMinimums();
        const start = new Date(Date.now() + 60 * 60 * 1000);
        start.setMinutes(0, 0, 0);
        const end = new Date(start.getTime() + 60 * 60 * 1000);
        byId("officeExceptionStart").value = toInputDate(start);
        byId("officeExceptionEnd").value = toInputDate(end);
        byId("officeExceptionCapacityField").hidden = true;
        showError("officeExceptionError", "");
        byId("officeExceptionDialog").showModal();
    });
    byId("officeExceptionType").addEventListener("change", function (event) {
        byId("officeExceptionCapacityField").hidden = event.target.value !== "available";
    });
    byId("closeOfficeExceptionBtn").addEventListener("click", function () { byId("officeExceptionDialog").close(); });
    byId("cancelOfficeExceptionBtn").addEventListener("click", function () { byId("officeExceptionDialog").close(); });
    byId("officeExceptionForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        showError("officeExceptionError", "");
        const button = byId("saveOfficeExceptionBtn");
        button.disabled = true;
        try {
            const type = byId("officeExceptionType").value;
            const data = await postJson("office_availability.php", {
                action: "add_exception",
                starts_at: byId("officeExceptionStart").value,
                ends_at: byId("officeExceptionEnd").value,
                is_available: type === "available",
                capacity_override: type === "available" ? byId("officeExceptionCapacity").value : null,
                reason: byId("officeExceptionReason").value.trim(),
            });
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not add the schedule change.");
            }
            renderAvailability(data, true);
            byId("officeExceptionDialog").close();
            toast(data.message || "Schedule change added.");
        } catch (error) {
            showError("officeExceptionError", error.message || "Could not reach the server.");
        } finally {
            button.disabled = false;
        }
    });
    byId("officeExceptionList").addEventListener("click", async function (event) {
        const button = event.target.closest("button[data-exception-id]");
        if (!button || !window.confirm("Remove this temporary schedule change?")) {
            return;
        }
        button.disabled = true;
        const data = await postJson("office_availability.php", { action: "delete_exception", exception_id: Number(button.dataset.exceptionId) });
        if (data && data.success) {
            renderAvailability(data, true);
            toast(data.message || "Schedule change removed.");
        } else {
            toast((data && data.message) || "Could not remove the schedule change.", "error");
            button.disabled = false;
        }
    });

    byId("officeNotificationBtn").addEventListener("click", function () {
        const willOpen = byId("officeNotificationPanel").hidden;
        byId("officeNotificationPanel").hidden = !willOpen;
        byId("officeNotificationBtn").setAttribute("aria-expanded", String(willOpen));
        byId("officeProfileMenu").hidden = true;
    });
    byId("closeOfficeNotificationsBtn").addEventListener("click", function () { byId("officeNotificationPanel").hidden = true; });
    byId("markOfficeNotificationsReadBtn").addEventListener("click", async function () {
        const data = await postJson("office_notifications.php", { action: "mark_all_read" });
        if (data && data.success) {
            state.unread = 0;
            state.notifications.forEach(function (item) { item.read_at = item.read_at || new Date().toISOString(); });
            renderNotifications();
        }
    });
    byId("officeHelpAlerts").addEventListener("click", async function (event) {
        const button = event.target.closest("button[data-help-notification-id]");
        if (!button) {
            return;
        }
        button.disabled = true;
        await postJson("office_notifications.php", { action: "mark_read", notification_id: Number(button.dataset.helpNotificationId) });
        loadOfficeData(true);
    });
    byId("officeNotificationList").addEventListener("click", async function (event) {
        const button = event.target.closest("button[data-notification-id]");
        if (!button) {
            return;
        }
        await postJson("office_notifications.php", { action: "mark_read", notification_id: Number(button.dataset.notificationId) });
        if (button.dataset.appointmentId) {
            byId("officeNotificationPanel").hidden = true;
            openAppointment(Number(button.dataset.appointmentId));
        }
        loadOfficeData(true);
    });

    byId("officeProfileBtn").addEventListener("click", function () {
        const willOpen = byId("officeProfileMenu").hidden;
        byId("officeProfileMenu").hidden = !willOpen;
        byId("officeProfileBtn").setAttribute("aria-expanded", String(willOpen));
        byId("officeNotificationPanel").hidden = true;
    });
    byId("editOfficeProfileBtn").addEventListener("click", openProfileEditor);
    byId("closeOfficeProfileBtn").addEventListener("click", closeProfileEditor);
    byId("cancelOfficeProfileBtn").addEventListener("click", closeProfileEditor);
    byId("officeProfileImage").addEventListener("change", function () {
        clearProfilePreviewUrl();
        const file = this.files && this.files[0];
        if (!file) {
            setProfileImage("officeProfilePreview", (state.profile && state.profile.profile_image_url) || "", state.profileImageVersion);
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            this.value = "";
            showError("officeProfileError", "Profile photo must be 2 MB or smaller.");
            return;
        }
        if (["image/jpeg", "image/png", "image/webp"].indexOf(file.type) === -1) {
            this.value = "";
            showError("officeProfileError", "Choose a JPG, PNG, or WebP image.");
            return;
        }
        showError("officeProfileError", "");
        byId("officeRemoveProfileImage").checked = false;
        state.profilePreviewUrl = URL.createObjectURL(file);
        setProfileImage("officeProfilePreview", state.profilePreviewUrl, 0);
    });
    byId("officeRemoveProfileImage").addEventListener("change", function () {
        if (this.checked) {
            clearProfilePreviewUrl();
            byId("officeProfileImage").value = "";
            setProfileImage("officeProfilePreview", "", 0);
        } else {
            setProfileImage("officeProfilePreview", (state.profile && state.profile.profile_image_url) || "", state.profileImageVersion);
        }
    });
    byId("officeProfileForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        showError("officeProfileError", "");
        const saveButton = byId("saveOfficeProfileBtn");
        const formData = new FormData();
        formData.append("remove_photo", byId("officeRemoveProfileImage").checked ? "1" : "0");
        const file = byId("officeProfileImage").files[0];
        if (file) {
            formData.append("profile_image", file);
        }
        saveButton.disabled = true;
        saveButton.textContent = "Saving…";
        try {
            const data = await fetchJson("staff_profile.php", { method: "POST", body: formData });
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not update the profile.");
            }
            state.profile = data.profile || state.profile;
            state.profileImageVersion = Date.now();
            PhoneTrackerAuth.setSessionFromLogin({
                role: PhoneTrackerAuth.getRole(),
                user_id: PhoneTrackerAuth.getUserId(),
                username: PhoneTrackerAuth.getUsername(),
                display_name: state.profile.display_name,
            });
            renderProfile();
            closeProfileEditor();
            toast(data.message || "Profile updated.");
        } catch (error) {
            showError("officeProfileError", error.message || "Could not update the profile.");
        } finally {
            saveButton.disabled = false;
            saveButton.textContent = "Save profile";
        }
    });
    byId("officeLogoutBtn").addEventListener("click", function () {
        PhoneTrackerAuth.logout().then(function () { window.location.href = "login.html"; });
    });

    ["officeAppointmentDialog", "officeDeclineDialog", "officeRescheduleDialog", "officeExceptionDialog", "officeProfileDialog"].forEach(function (id) {
        const dialog = byId(id);
        dialog.addEventListener("click", function (event) {
            if (event.target === dialog) {
                if (id === "officeProfileDialog") {
                    closeProfileEditor();
                } else {
                    dialog.close();
                }
            }
        });
    });

    setText("officeProfileName", PhoneTrackerAuth.getDisplayName() || PhoneTrackerAuth.getUsername() || "Office Personnel");
    setText("officeProfileUsername", PhoneTrackerAuth.getUsername() || "office");
    setFutureMinimums();
    /* ---------- Teachers: read-only, for when someone asks at the office ---------- */

    const teachersState = { list: [], loaded: false };

    function teacherTime(value) {
        const match = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(String(value || ""));
        if (!match) {
            return "";
        }
        const hour = Number(match[4]);
        return (hour % 12 === 0 ? 12 : hour % 12) + ":" + match[5] + " " + (hour < 12 ? "AM" : "PM");
    }

    function teacherDay(value) {
        const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ""));
        if (!match) {
            return "";
        }
        const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
        const today = new Date();
        return date.toDateString() === today.toDateString()
            ? "Today"
            : date.toLocaleDateString("en-US", { weekday: "short", month: "short", day: "numeric" });
    }

    function renderOfficeTeachers() {
        const body = byId("officeTeachersBody");
        const course = byId("officeTeachersCourse").value;
        const query = byId("officeTeachersSearch").value.trim().toLowerCase();
        const rows = teachersState.list.filter(function (teacher) {
            return (!course || teacher.course === course) && teacher.name.toLowerCase().indexOf(query) !== -1;
        });
        body.replaceChildren();
        rows.forEach(function (teacher) {
            const row = document.createElement("tr");
            const name = document.createElement("td");
            name.className = "admin-cell-strong";
            name.textContent = teacher.name;
            const courseCell = document.createElement("td");
            const tag = document.createElement("span");
            tag.className = "ot-tag";
            tag.textContent = teacher.course;
            courseCell.appendChild(tag);
            const now = document.createElement("td");
            const badge = document.createElement("span");
            const looks = {
                available: ["is-active", teacher.until ? "Available until " + teacherTime(teacher.until) : "Available"],
                not_available: ["is-pending", "Not available"],
                no_hours: ["is-cancelled", "No hours set"],
                off: ["is-cancelled", "Account off"],
            }[teacher.status];
            badge.className = "admin-status " + looks[0];
            badge.textContent = looks[1];
            now.appendChild(badge);
            const next = document.createElement("td");
            next.className = teacher.next_free_at ? "ot-next" : "ot-next is-none";
            next.textContent = teacher.next_free_at ? teacherDay(teacher.next_free_at) + ", " + teacherTime(teacher.next_free_at) : "—";
            row.append(name, courseCell, now, next);
            if (teachersState.approver) {
                // The Dean's Office turns off the account of a teacher who left, and back on.
                const account = document.createElement("td");
                const accountBadge = document.createElement("span");
                accountBadge.className = "sec-badge " + (teacher.account_active ? "is-good" : "is-muted");
                accountBadge.textContent = teacher.account_active ? "Active" : "Turned off";
                account.appendChild(accountBadge);
                const toggleCell = document.createElement("td");
                const toggle = document.createElement("button");
                toggle.type = "button";
                toggle.className = "ot-toggle";
                toggle.textContent = teacher.account_active ? "Turn off" : "Turn on";
                toggle.addEventListener("click", function () { toggleTeacherAccount(teacher, toggle); });
                toggleCell.appendChild(toggle);
                row.append(account, toggleCell);
            }
            if (teacher.status === "off") {
                row.classList.add("is-off");
            }
            body.appendChild(row);
        });
        byId("officeTeachersEmpty").hidden = rows.length > 0;
        byId("officeTeachersEmptyTitle").textContent = teachersState.list.length ? "No teacher with that name" : "No teachers yet";
        byId("officeTeachersEmptyText").textContent = teachersState.list.length ? "Try another name, or All courses." : "Teachers appear here once the administrator adds them.";
    }

    async function loadOfficeTeachers() {
        try {
            const data = await fetchJson("office_teachers.php");
            if (!data || !data.success) {
                return;
            }
            byId("officeTeachersNav").hidden = !data.applies;
            teachersState.approver = Boolean(data.approver);
            document.querySelectorAll(".ot-account-col").forEach(function (cell) { cell.hidden = !teachersState.approver; });
            teachersState.list = data.teachers || [];
            const select = byId("officeTeachersCourse");
            const current = select.value;
            const courses = Array.from(new Set(teachersState.list.map(function (teacher) { return teacher.course; })));
            select.replaceChildren(new Option("All courses", ""));
            courses.forEach(function (course) { select.appendChild(new Option(course, course)); });
            select.value = courses.indexOf(current) !== -1 ? current : "";
            renderOfficeTeachers();
        } catch (error) {
            // The tab stays as it was; the next load tries again.
        }
    }
    byId("officeTeachersCourse").addEventListener("change", renderOfficeTeachers);
    byId("officeTeachersSearch").addEventListener("input", renderOfficeTeachers);
    loadOfficeTeachers();

    /* ---------- Teacher requests: for a department's Dean's Office only (SCHOOL_ACCOUNTS.md) ---------- */

    const requestsState = { approver: false, department: "", courses: [], list: [], rejecting: null };

    function renderTeacherRequests() {
        const body = byId("teacherRequestsBody");
        body.replaceChildren();
        requestsState.list.forEach(function (request) {
            const row = document.createElement("tr");
            const who = document.createElement("td");
            const name = document.createElement("strong");
            name.className = "ot-request-name";
            name.textContent = request.name;
            const email = document.createElement("small");
            email.className = "ot-request-email";
            email.textContent = request.email + ", confirmed";
            who.append(name, email);
            const courseCell = document.createElement("td");
            const select = document.createElement("select");
            select.className = "ot-course-select";
            select.setAttribute("aria-label", "Course for " + request.name);
            requestsState.courses.forEach(function (course) { select.appendChild(new Option(course, course)); });
            select.value = requestsState.courses.indexOf(request.course_code) !== -1 ? request.course_code : (requestsState.courses[0] || "");
            courseCell.appendChild(select);
            const asked = document.createElement("td");
            asked.className = "ot-next";
            asked.textContent = teacherDay(request.asked_at) + ", " + teacherTime(request.asked_at);
            const actions = document.createElement("td");
            actions.className = "ot-request-actions";
            const approve = document.createElement("button");
            approve.type = "button";
            approve.className = "office-approve-button";
            approve.textContent = "Approve";
            approve.addEventListener("click", function () { approveTeacherRequest(request, select.value, approve); });
            const reject = document.createElement("button");
            reject.type = "button";
            reject.className = "ot-toggle is-danger";
            reject.textContent = "Reject";
            reject.addEventListener("click", function () { openRejectTeacher(request); });
            actions.append(approve, reject);
            row.append(who, courseCell, asked, actions);
            body.appendChild(row);
        });
        byId("teacherRequestsEmpty").hidden = requestsState.list.length > 0;
        const count = byId("officeTeachersCount");
        count.textContent = String(requestsState.list.length);
        count.hidden = requestsState.list.length === 0;
    }

    async function loadTeacherRequests() {
        try {
            const data = await fetchJson("office_teacher_requests.php");
            if (!data || !data.success) {
                return;
            }
            requestsState.approver = Boolean(data.approver);
            byId("teacherRequestsCard").hidden = !requestsState.approver;
            if (!requestsState.approver) {
                return;
            }
            requestsState.department = data.department;
            requestsState.courses = data.courses || [];
            requestsState.list = data.requests || [];
            byId("teacherRequestsIntro").textContent = "Teachers who asked for an account in " + data.department + ". Approve only those who teach here.";
            byId("officeRejectNotHere").textContent = "Doesn't teach in " + data.department;
            renderTeacherRequests();
        } catch (error) {
            // Tried again on the next load.
        }
    }

    async function approveTeacherRequest(request, courseCode, button) {
        button.disabled = true;
        try {
            const data = await postJson("office_teacher_requests.php", { action: "approve", request_id: request.id, course_code: courseCode });
            toast((data && data.message) || "Could not approve.", data && data.success ? "success" : "error");
            await Promise.all([loadTeacherRequests(), loadOfficeTeachers()]);
        } finally {
            button.disabled = false;
        }
    }

    function openRejectTeacher(request) {
        requestsState.rejecting = request;
        byId("officeRejectTeacherTitle").textContent = "Reject " + request.name + "?";
        byId("officeRejectTeacherTo").textContent = "The reason is emailed to " + request.email + ".";
        byId("officeRejectTeacherForm").reset();
        byId("officeRejectTeacherOther").hidden = true;
        byId("officeRejectTeacherError").hidden = true;
        byId("officeRejectTeacherDialog").showModal();
    }

    byId("officeRejectTeacherForm").addEventListener("change", function () {
        const other = byId("officeRejectTeacherForm").elements.teacherRejectReason.value === "other";
        byId("officeRejectTeacherOther").hidden = !other;
        if (other) {
            byId("officeRejectTeacherOther").focus();
        }
    });
    document.querySelectorAll("[data-close-reject]").forEach(function (button) {
        button.addEventListener("click", function () { byId("officeRejectTeacherDialog").close(); });
    });
    byId("officeRejectTeacherForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        const request = requestsState.rejecting;
        const choice = byId("officeRejectTeacherForm").elements.teacherRejectReason.value;
        const reason = choice === "other" ? byId("officeRejectTeacherOther").value.trim()
            : (choice === "department" ? "Doesn't teach in " + requestsState.department : choice);
        if (!request || reason === "") {
            byId("officeRejectTeacherError").textContent = "Write the reason. It is sent to them.";
            byId("officeRejectTeacherError").hidden = false;
            return;
        }
        const button = byId("confirmRejectTeacherBtn");
        button.disabled = true;
        try {
            const data = await postJson("office_teacher_requests.php", { action: "reject", request_id: request.id, reason: reason });
            if (!data || !data.success) {
                byId("officeRejectTeacherError").textContent = (data && data.message) || "Could not reject.";
                byId("officeRejectTeacherError").hidden = false;
                return;
            }
            byId("officeRejectTeacherDialog").close();
            toast(data.message);
            await loadTeacherRequests();
        } finally {
            button.disabled = false;
        }
    });

    async function toggleTeacherAccount(teacher, button) {
        const turnOn = !teacher.account_active;
        if (!turnOn && !window.confirm("Turn off " + teacher.name + "'s account? They can't sign in, and students and visitors can't book them. Their open visits are cancelled and each person is told. You can turn it back on.")) {
            return;
        }
        button.disabled = true;
        try {
            const data = await postJson("office_teacher_requests.php", { action: turnOn ? "turn_on" : "turn_off", user_id: teacher.user_id });
            toast((data && data.message) || "Could not save.", data && data.success ? "success" : "error");
            await loadOfficeTeachers();
        } finally {
            button.disabled = false;
        }
    }

    loadTeacherRequests();

    const initialView = String(window.location.hash || "#dashboard").slice(1);
    switchView(initialView);
    loadOfficeData(false);
    window.addEventListener("focus", function () { loadOfficeData(true); });
    document.addEventListener("visibilitychange", function () {
        if (!document.hidden) {
            loadOfficeData(true);
        }
    });
    window.setInterval(function () { loadOfficeData(true); }, 30000);
})();
