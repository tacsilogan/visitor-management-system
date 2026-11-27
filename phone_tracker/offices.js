/*
 * Office Personnel dashboard (OFFICE_MOBILE.md). Five pages share one menu: a bar at the bottom on
 * phones, a strip of icons on tablets and the blue side menu on laptops.
 *   Today     the office's status with Pause, visitors who can't find the office, the requests
 *             waiting for an answer and today's visitors in groups.
 *   Requests  needs your answer (soonest visit first) and waiting for the visitor.
 *   Visits    every record: Today, Upcoming, Past, All, with search and a status filter.
 *   Hours     the weekly hours one day at a time, each booking's length and capacity,
 *             temporary changes and "Accepting visitors". A Save bar appears after a change.
 *   Menu      the account, profile, notifications, refresh and sign out.
 * Details and forms open as sheets (from the bottom on phones, from the right on laptops), and
 * the phone's Back button closes an open sheet before it leaves the page.
 */
(function () {
    "use strict";

    if (typeof PhoneTrackerAuth === "undefined" || !PhoneTrackerAuth.requireRole(["offices"])) {
        return;
    }

    const dayNames = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
    const VIEWS = ["today", "requests", "visits", "hours", "menu"];
    // Addresses from the old page (bookmarks, the visitor emails' links) still open the right place.
    const OLD_VIEWS = { dashboard: "today", history: "visits", availability: "hours" };
    const VISITS_PAGE = 30;
    const CLOSED_STATUSES = ["completed", "rejected", "cancelled", "unanswered", "window_closed"];
    const phoneQuery = window.matchMedia("(max-width: 720px)");

    const state = {
        summary: {},
        appointments: [],
        notifications: [],
        unread: 0,
        office: null,
        profile: null,
        profileImageVersion: 0,
        profilePreviewUrl: "",
        loaded: false,
        updatedAt: null,
        view: "",
        requestTab: "answer",
        requestQuery: "",
        visitsTab: "today",
        visitsStatus: "",
        visitsQuery: "",
        visitsShown: VISITS_PAGE,
        selectedAppointmentId: 0,
        selectedAppointment: null,
        selectedVisit: null,
        picked: [],
        availabilityLoaded: false,
        // The weekly hours being edited: {day, enabled, start, end, breaks: [{key, start, end, label}]}.
        week: [],
        weekDirty: false,
        breakSeq: 0,
        dayIndex: -1,
        dayDraft: null,
        status: null,
        toastTimer: null,
    };

    const ICON = {
        people: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20"/><circle cx="9.5" cy="7.5" r="3.5"/><path d="M17 11a3 3 0 1 0 0-6M21 20v-1.5a4 4 0 0 0-3-3.9"/></svg>',
        clock: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
        check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>',
        chev: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>',
        alert: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 20h20zM12 10v4M12 17v.5"/></svg>',
        info: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.5"/></svg>',
        pin: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s7-4.5 7-12a7 7 0 1 0-14 0c0 7.5 7 12 7 12Z"/><circle cx="12" cy="10" r="2.5"/></svg>',
        x: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>',
        mail: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>',
        trash: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>',
        plus: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
    };

    // ---------- Small helpers ----------

    function byId(id) {
        return document.getElementById(id);
    }

    function setText(id, value) {
        const element = byId(id);
        if (element) {
            element.textContent = value == null ? "" : String(value);
        }
    }

    function setAll(selector, value) {
        document.querySelectorAll(selector).forEach(function (element) {
            element.textContent = value == null ? "" : String(value);
        });
    }

    function esc(value) {
        return String(value == null ? "" : value).replace(/[&<>"']/g, function (character) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[character];
        });
    }

    function showError(id, message) {
        const element = byId(id);
        if (!element) {
            return;
        }
        element.textContent = message || "";
        element.hidden = !message;
    }

    /**
     * A short message at the bottom. Shown as a popover where the browser has them, so it also
     * shows above an open sheet (sheets sit in the browser's top layer).
     */
    function toast(message, stateName) {
        const element = byId("officeToast");
        const popover = typeof element.showPopover === "function";
        element.textContent = message;
        element.dataset.state = stateName || "success";
        element.hidden = false;
        if (popover) {
            element.setAttribute("popover", "manual");
            try {
                element.hidePopover();
            } catch (error) {
                // It wasn't showing.
            }
            element.showPopover();
        }
        if (state.toastTimer) {
            window.clearTimeout(state.toastTimer);
        }
        state.toastTimer = window.setTimeout(function () {
            if (popover) {
                try {
                    element.hidePopover();
                } catch (error) {
                    // Already hidden.
                }
            }
            element.hidden = true;
        }, 4200);
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

    // ---------- Dates and times ----------

    const clockFormat = new Intl.DateTimeFormat("en-PH", { hour: "numeric", minute: "2-digit" });
    const shortDayFormat = new Intl.DateTimeFormat("en-PH", { weekday: "short", month: "short", day: "numeric" });
    const shortDayYearFormat = new Intl.DateTimeFormat("en-PH", { month: "short", day: "numeric", year: "numeric" });
    const longDayFormat = new Intl.DateTimeFormat("en-PH", { weekday: "long", month: "long", day: "numeric" });
    const monthDayFormat = new Intl.DateTimeFormat("en-PH", { month: "short", day: "numeric" });

    function parseServerDate(value) {
        if (!value) {
            return null;
        }
        const date = value instanceof Date ? value : new Date(String(value).replace(" ", "T"));
        return isNaN(date.getTime()) ? null : date;
    }

    function clock(value) {
        const date = parseServerDate(value);
        return date ? clockFormat.format(date) : "";
    }

    function daysFromToday(value) {
        const date = parseServerDate(value);
        if (!date) {
            return null;
        }
        const today = new Date();
        const start = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        const day = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        return Math.round((day.getTime() - start.getTime()) / 86400000);
    }

    function isToday(value) {
        return daysFromToday(value) === 0;
    }

    /** "Today", "Tomorrow", "Yesterday", "Fri, Nov 20", or "Nov 20, 2025" in another year. */
    function dayWord(value) {
        const date = parseServerDate(value);
        if (!date) {
            return "";
        }
        const days = daysFromToday(date);
        if (days === 0) {
            return "Today";
        }
        if (days === 1) {
            return "Tomorrow";
        }
        if (days === -1) {
            return "Yesterday";
        }
        return date.getFullYear() === new Date().getFullYear() ? shortDayFormat.format(date) : shortDayYearFormat.format(date);
    }

    /** "1:30 – 2:00 PM", or "11:30 AM – 12:00 PM" across noon. */
    function timeRange(startValue, endValue) {
        const start = clock(startValue);
        const end = clock(endValue);
        if (!start) {
            return "";
        }
        if (!end) {
            return start;
        }
        const startMark = start.slice(-2);
        if (/[AP]M$/i.test(start) && startMark === end.slice(-2)) {
            return start.slice(0, -3).trim() + " – " + end;
        }
        return start + " – " + end;
    }

    /** "just now", "12 min ago", "3 h ago", "yesterday, 3:14 PM", "Nov 15, 3:14 PM". */
    function agoText(value) {
        const date = parseServerDate(value);
        if (!date) {
            return "";
        }
        const minutes = Math.round((Date.now() - date.getTime()) / 60000);
        const days = daysFromToday(date);
        if (minutes < 1) {
            return "just now";
        }
        if (minutes < 60) {
            return minutes + " min ago";
        }
        if (days === 0) {
            return Math.floor(minutes / 60) + " h ago";
        }
        if (days === -1) {
            return "yesterday, " + clock(date);
        }
        return monthDayFormat.format(date) + ", " + clock(date);
    }

    /** "2:00 PM today", "9:30 AM tomorrow", "2:30 PM on Fri, Nov 20". */
    function deadlineText(value) {
        const date = parseServerDate(value);
        if (!date) {
            return "";
        }
        const days = daysFromToday(date);
        if (days === 0) {
            return clock(date) + " today";
        }
        if (days === 1) {
            return clock(date) + " tomorrow";
        }
        return clock(date) + " on " + dayWord(date);
    }

    function fullDate(value) {
        const date = parseServerDate(value);
        return date ? shortDayYearFormat.format(date) + ", " + clock(date) : "—";
    }

    function purposeLine(row) {
        const purpose = row.purpose || "Purpose not given";
        return row.subject && row.subject !== row.purpose ? purpose + " · " + row.subject : purpose;
    }

    function initialsOf(name) {
        const parts = String(name || "").trim().split(/\s+/).filter(Boolean);
        if (!parts.length) {
            return "OP";
        }
        return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : "")).toUpperCase();
    }

    function isGate(row) {
        return row.registration_source === "gate";
    }

    function stopLabel(row) {
        return row.visit_id && row.stop_number && row.visit_stop_count ? "Stop " + row.stop_number + " of " + row.visit_stop_count : "";
    }

    /** "At your office since 9:28 AM · GPS ±4 m", from the visitor's phone. */
    function arrivalText(row) {
        const how = row.arrival_method === "gps"
            ? "GPS" + (row.arrival_accuracy_meters != null ? " ±" + Math.round(Number(row.arrival_accuracy_meters)) + " m" : "")
            : "they tapped I'm here";
        return "At your office since " + clock(row.arrived_at) + " · " + how;
    }

    function arrivalSentence(row) {
        const how = row.arrival_method === "gps"
            ? "confirmed by GPS" + (row.arrival_accuracy_meters != null ? " (±" + Math.round(Number(row.arrival_accuracy_meters)) + " m)" : "")
            : "confirmed by the visitor";
        return "since " + clock(row.arrived_at) + ", " + how + ".";
    }

    /** How a finished visit ended, for the Done group and the details. */
    function endedText(row) {
        if (row.status === "window_closed") {
            return "Didn't check in before " + clock(row.scheduled_end_at);
        }
        const at = row.completed_at ? clock(row.completed_at) : "";
        if (row.visit_id) {
            return "Meeting done" + (at ? ", " + at : "");
        }
        switch (row.checkout_method) {
        case "scan":
            return "Checked out at the gate" + (at ? ", " + at : "");
        case "guard":
            return "Visit ended by the guard" + (at ? ", " + at : "");
        case "left_campus":
            return "Left the campus" + (at ? ", " + at : "");
        case "end_of_day":
            return "Ended at the end of the day";
        default:
            return "Completed" + (at ? ", " + at : "");
        }
    }

    function statusMeta(row) {
        switch (row.status) {
        case "pending_approval":
            return { label: "Needs your answer", className: "is-amber" };
        case "reschedule_proposed":
            return { label: "Waiting for visitor", className: "is-blue" };
        case "approved":
            return { label: "Approved", className: "is-green" };
        case "checked_in":
            return row.arrived_at ? { label: "At your office", className: "is-green" } : { label: "On campus", className: "is-blue" };
        case "completed":
            return { label: "Completed", className: "" };
        case "rejected":
            return { label: "Declined", className: "is-red" };
        case "cancelled":
            return { label: "Cancelled", className: "" };
        case "unanswered":
            return { label: "No office response", className: "is-red" };
        case "window_closed":
            return { label: "Didn't come", className: "" };
        default:
            return { label: row.status || "Unknown", className: "" };
        }
    }

    const HISTORY_LABELS = {
        pending_approval: "Request sent",
        reschedule_proposed: "Other times offered",
        approved: "Approved",
        rejected: "Declined",
        checked_in: "Checked in",
        completed: "Completed",
        cancelled: "Cancelled",
        unanswered: "No office response",
        window_closed: "Didn't come",
    };

    function byStart(left, right) {
        return String(left.scheduled_start_at || "").localeCompare(String(right.scheduled_start_at || ""));
    }

    function matches(row, query) {
        if (!query) {
            return true;
        }
        return [row.visitor_full_name, row.visitor_email, row.registration_code, row.purpose, row.subject, row.destination]
            .join(" ").toLowerCase().indexOf(query.toLowerCase()) !== -1;
    }

    function findRow(id) {
        return state.appointments.find(function (row) { return Number(row.id) === Number(id); }) || null;
    }

    /** Unread "can't find your office" alerts, by appointment. */
    function helpAlerts() {
        return state.notifications.filter(function (notification) {
            return notification.notification_type === "visitor_needs_help" && !notification.read_at;
        });
    }

    function helpFor(appointmentId) {
        return helpAlerts().find(function (notification) {
            return Number(notification.appointment_id) === Number(appointmentId);
        }) || null;
    }

    // ---------- Sheets: the phone's Back button closes them first ----------

    const sheets = [];
    let ignorePops = 0;
    let settleTimer = 0;
    const afterPops = [];

    /*
     * Closing a sheet goes back in the history, which the browser does a moment later. A new
     * history entry (another sheet, another page) made before that would be the one taken back,
     * and a later Back would then leave the page, so it waits until the back step is done.
     */
    function whenSettled(callback) {
        if (ignorePops <= 0) {
            callback();
            return;
        }
        afterPops.push(callback);
        window.clearTimeout(settleTimer);
        settleTimer = window.setTimeout(flushSettled, 800);
    }

    function flushSettled() {
        window.clearTimeout(settleTimer);
        while (afterPops.length) {
            afterPops.shift()();
        }
    }

    function openSheet(dialog) {
        if (dialog.open) {
            return;
        }
        dialog.showModal();
        sheets.push(dialog);
        whenSettled(function () {
            window.history.pushState({ officeSheet: dialog.id }, "", window.location.href);
        });
    }

    function closeSheet(dialog) {
        if (dialog && dialog.open) {
            dialog.close();
        }
    }

    /** After an action: closes several sheets with one step back in the history. */
    function closeSheets(dialogs) {
        const open = dialogs.filter(function (dialog) { return dialog && dialog.open && sheets.indexOf(dialog) !== -1; });
        open.forEach(function (dialog) {
            dialog.dataset.quiet = "1";
            dialog.close();
        });
        if (open.length) {
            ignorePops += 1;
            window.history.go(-open.length);
        }
    }

    document.querySelectorAll("dialog.ofc-sheet").forEach(function (dialog) {
        dialog.addEventListener("close", function () {
            const index = sheets.indexOf(dialog);
            if (index !== -1) {
                sheets.splice(index, 1);
            }
            const quiet = dialog.dataset.quiet === "1" || dialog.dataset.fromPop === "1";
            delete dialog.dataset.quiet;
            delete dialog.dataset.fromPop;
            if (!quiet && index !== -1) {
                ignorePops += 1;
                window.history.back();
            }
        });
        dialog.addEventListener("click", function (event) {
            if (event.target === dialog || event.target.closest("[data-close-sheet]")) {
                dialog.close();
            }
        });
    });

    // ---------- Pages ----------

    function viewFromHash() {
        const hash = window.location.hash.replace("#", "");
        if (VIEWS.indexOf(hash) !== -1) {
            return hash;
        }
        return OLD_VIEWS[hash] || "today";
    }

    function urlFor(view) {
        return window.location.pathname + window.location.search + (view === "today" ? "" : "#" + view);
    }

    function switchView(view, options) {
        const settings = options || {};
        if (VIEWS.indexOf(view) === -1) {
            view = OLD_VIEWS[view] || "today";
        }
        const changed = view !== state.view;
        state.view = view;
        document.body.dataset.officeCurrent = view;
        document.querySelectorAll("[data-office-panel]").forEach(function (panel) {
            panel.hidden = panel.dataset.officePanel !== view;
        });
        document.querySelectorAll(".office-sidebar [data-office-view], .ofc-tabbar [data-office-view]").forEach(function (button) {
            const active = button.dataset.officeView === view;
            button.classList.toggle("is-active", active);
            if (active) {
                button.setAttribute("aria-current", "page");
            } else {
                button.removeAttribute("aria-current");
            }
        });
        if (settings.history === "push" && changed) {
            whenSettled(function () {
                window.history.pushState({ officeView: view }, "", urlFor(view));
            });
        } else if (settings.history === "replace") {
            window.history.replaceState({ officeView: view }, "", urlFor(view));
        }
        if (view === "hours" && !state.availabilityLoaded) {
            loadAvailability();
        }
        updateSaveBar();
        if (changed) {
            window.scrollTo(0, 0);
        }
    }

    window.addEventListener("popstate", function () {
        if (ignorePops > 0) {
            ignorePops -= 1;
            if (ignorePops === 0) {
                flushSettled();
            }
            return;
        }
        const top = sheets[sheets.length - 1];
        if (top) {
            top.dataset.fromPop = "1";
            top.close();
            return;
        }
        switchView(viewFromHash());
    });

    // ---------- Profile and menu ----------

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
        image.onerror = function () {
            image.onerror = null;
            image.src = "assets/icons/profile_icon.svg";
        };
        image.src = profileImageSource(url, bustCache);
    }

    function renderProfile() {
        const profile = state.profile || {};
        const displayName = profile.display_name || PhoneTrackerAuth.getDisplayName() || PhoneTrackerAuth.getUsername() || "Office Personnel";
        const username = profile.username || PhoneTrackerAuth.getUsername() || "office";
        const officeName = state.office ? state.office.label : "Your office";
        setText("officeProfileName", displayName);
        setText("officeProfileUsername", username);
        setText("officeProfileOffice", officeName);
        setProfileImage("officeProfileAvatar", profile.profile_image_url || "", state.profileImageVersion);
        setText("officeMenuName", displayName);
        setText("officeMenuUsername", username);
        setText("officeMenuRole", (profile.position ? profile.position + " · " : "") + officeName);
        const avatar = byId("officeMenuAvatar");
        avatar.replaceChildren();
        if (profile.profile_image_url) {
            const image = document.createElement("img");
            image.alt = "";
            image.src = profileImageSource(profile.profile_image_url, state.profileImageVersion);
            image.onerror = function () {
                avatar.textContent = initialsOf(displayName);
            };
            avatar.appendChild(image);
        } else {
            avatar.textContent = initialsOf(displayName);
        }
        setAll("[data-office-name]", officeName);
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
        closeProfileMenu();
        byId("officeProfileDialog").showModal();
    }

    function closeProfileEditor() {
        clearProfilePreviewUrl();
        byId("officeProfileDialog").close();
    }

    function closeProfileMenu() {
        byId("officeProfileMenu").hidden = true;
        byId("officeProfileBtn").setAttribute("aria-expanded", "false");
    }

    async function signOut() {
        closeProfileMenu();
        if (await AppDialog.confirm({
            title: "Sign out?",
            message: "You'll need your password to sign in again.",
            confirmLabel: "Sign out",
        })) {
            PhoneTrackerAuth.logout().then(function () { window.location.href = "login.html"; });
        }
    }

    // ---------- Loading ----------

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
            state.loaded = true;
            state.updatedAt = new Date();
            showError("officePageError", "");
            renderAll();
            return true;
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showError("officePageError", error.message || "Could not reach the server.");
            }
            return false;
        }
    }

    function renderAll() {
        renderProfile();
        renderHelpAlerts();
        renderToday();
        renderRequests();
        renderVisits();
        renderNotifications();
        setText("officeMenuUpdated", state.updatedAt ? "Updated " + clock(state.updatedAt) : "Not updated yet");
    }

    // ---------- A visitor who can't find the office ----------

    function renderHelpAlerts() {
        const host = byId("officeHelpAlerts");
        const waiting = helpAlerts();
        host.hidden = waiting.length === 0;
        host.innerHTML = waiting.map(function (notification) {
            return '<section class="ofc-help" role="alert"><div>' +
                '<p class="ofc-help-title">' + ICON.alert + esc(notification.title || "A visitor can't find your office") + "</p>" +
                "<p>" + esc(notification.message || "") + "</p>" +
                "<small>" + esc(agoText(notification.created_at)) + "</small></div>" +
                '<button class="ofc-btn is-primary" type="button" data-help-going="' + Number(notification.id) + '">Someone is going</button></section>';
        }).join("");
    }

    async function someoneIsGoing(notificationId, button) {
        if (button) {
            button.disabled = true;
        }
        const data = await postJson("office_notifications.php", { action: "mark_read", notification_id: Number(notificationId) });
        if (data && data.success) {
            closeSheets([byId("officeAppointmentDialog")]);
            toast("Thanks. The alert is cleared.");
        } else {
            toast((data && data.message) || "Could not clear the alert.", "error");
        }
        if (button) {
            button.disabled = false;
        }
        loadOfficeData(true);
    }

    // ---------- Today ----------

    function todayGroups() {
        const rows = state.appointments.filter(function (row) { return isToday(row.scheduled_start_at); }).sort(byStart);
        return {
            here: rows.filter(function (row) { return row.status === "checked_in" && row.arrived_at; }),
            campus: rows.filter(function (row) { return row.status === "checked_in" && !row.arrived_at; }),
            later: rows.filter(function (row) { return row.status === "approved"; }),
            done: rows.filter(function (row) { return row.status === "completed" || row.status === "window_closed"; }),
        };
    }

    /*
     * The people coming with a visitor (GROUP_VISITS.md): before the gate, everyone on the list;
     * after it, those who came in and haven't left. The office sees their names, never their IDs.
     */
    function peopleWith(row) {
        return (Array.isArray(row.people) ? row.people : []).filter(function (person) {
            return person.gate_status === "expected" || (person.gate_status === "in" && !person.left_at);
        });
    }

    function namesText(people) {
        const names = people.map(function (person) {
            return person.full_name + (person.is_adult ? "" : " (under 18)");
        });
        if (names.length <= 1) {
            return names.join("");
        }
        return names.slice(0, -1).join(", ") + " and " + names[names.length - 1];
    }

    function groupChip(row) {
        const count = peopleWith(row).length;
        return count ? '<span class="ofc-chip is-blue grp-chip" title="Coming with ' + count + (count === 1 ? " other" : " others") + '">' + ICON.people + "+" + count + "</span>" : "";
    }

    function todayItem(row, group) {
        const help = helpFor(row.id);
        const start = parseServerDate(row.scheduled_start_at);
        const time = start ? clock(start) : "";
        const notes = [];
        if (help) {
            notes.push('<span class="ofc-tnote is-red">' + ICON.alert + "Can't find your office · " + esc(agoText(help.created_at)) + "</span>");
        }
        if (group === "here") {
            notes.push('<span class="ofc-tnote is-green">' + ICON.pin + esc(arrivalText(row)) + "</span>");
        } else if (group === "campus") {
            notes.push('<span class="ofc-tnote">' + esc(isGate(row)
                ? "Registered at the gate, " + clock(row.checked_in_at) + " · no app"
                : "Checked in at the gate, " + clock(row.checked_in_at)) + "</span>");
        } else if (group === "done") {
            notes.push('<span class="ofc-tnote">' + esc(endedText(row)) + "</span>");
        }
        const stop = stopLabel(row);
        if (stop && group !== "done") {
            notes.push('<span class="ofc-tnote">' + esc(stop) + "</span>");
        }
        const canFinish = row.status === "checked_in" && row.visit_id;
        const classes = ["ofc-titem"];
        if (group === "here") {
            classes.push("is-here");
        }
        if (help) {
            classes.push("is-help");
        }
        if (group === "done") {
            classes.push("is-done");
        }
        const people = group === "done"
            ? (row.people || []).filter(function (person) { return person.gate_status === "in"; })
            : peopleWith(row);
        const names = people.length
            ? '<span class="grp-tnames">' + ICON.people + "<span>With " + esc(namesText(people)) + "</span></span>"
            : "";
        return '<li class="' + classes.join(" ") + '">' +
            '<span class="ofc-ttime"><b>' + esc(time.replace(/\s?[AP]M$/i, "")) + "</b><small>" + esc((time.match(/[AP]M$/i) || [""])[0]) + "</small></span>" +
            '<button class="ofc-tbody" type="button" data-open-appointment="' + Number(row.id) + '">' +
            "<strong>" + esc(row.visitor_full_name) + (row.visit_type === "walk_in" ? '<span class="ofc-chip">Walk-in</span>' : "") + groupChip(row) + "</strong>" +
            "<span>" + esc(purposeLine(row)) + "</span>" + names + notes.join("") + "</button>" +
            (canFinish ? '<button class="ofc-btn is-small" type="button" data-meeting-done="' + Number(row.id) + '">' + ICON.check + "Meeting done</button>" : "") +
            "</li>";
    }

    function renderToday() {
        setText("officeTodayDate", longDayFormat.format(new Date()));
        const groups = todayGroups();
        const pending = state.appointments.filter(function (row) { return row.status === "pending_approval"; }).sort(byStart);
        const total = groups.here.length + groups.campus.length + groups.later.length + groups.done.length;
        setAll('[data-count="answer"]', pending.length);
        setAll('[data-count="here"]', groups.here.length);
        setAll('[data-count="campus"]', groups.campus.length);
        setAll('[data-count="later"]', groups.later.length);
        setAll('[data-count="today"]', total);
        document.querySelectorAll("[data-request-badge]").forEach(function (badge) {
            badge.textContent = pending.length > 99 ? "99+" : String(pending.length);
            badge.hidden = pending.length === 0;
        });

        const home = byId("officeHomeRequests");
        if (!state.loaded) {
            home.innerHTML = '<li class="ofc-empty">Loading…</li>';
            byId("officeTodayGroups").innerHTML = '<p class="ofc-empty">Loading…</p>';
            return;
        }
        if (!pending.length) {
            home.innerHTML = '<li class="ofc-empty">Every request has an answer.</li>';
        } else {
            const more = pending.length - 2;
            home.innerHTML = pending.slice(0, 2).map(requestCard).join("") + (more > 0
                ? '<li><button class="ofc-more-btn" type="button" data-office-view="requests">+ ' + more + " more request" + (more === 1 ? "" : "s") + "</button></li>"
                : "");
        }

        const labels = [["here", "Here now", "is-green"], ["campus", "On campus", "is-blue"], ["later", "Later today", ""], ["done", "Done", ""]];
        const html = labels.map(function (entry) {
            const rows = groups[entry[0]];
            if (!rows.length) {
                return "";
            }
            return '<div class="ofc-tgroup" id="officeGroup-' + entry[0] + '"><p class="ofc-tg-title ' + entry[2] + '"><i></i>' +
                entry[1] + " · " + rows.length + '</p><ul class="ofc-tlist">' +
                rows.map(function (row) { return todayItem(row, entry[0]); }).join("") + "</ul></div>";
        }).join("");
        byId("officeTodayGroups").innerHTML = html || '<p class="ofc-empty">No visitors today yet.</p>';
    }

    // ---------- Requests ----------

    function requestCard(row) {
        const start = parseServerDate(row.scheduled_start_at);
        const days = start ? daysFromToday(start) : null;
        const soon = days !== null && days <= 1;
        const word = start ? dayWord(start) : "No date";
        const when = (days === 0 || days === 1 ? '<b class="is-soon">' + esc(word) + "</b>" : "<b>" + esc(word) + "</b>") +
            " · " + esc(timeRange(row.scheduled_start_at, row.scheduled_end_at));
        const stop = stopLabel(row);
        const meta = "Asked " + agoText(row.created_at) + (soon ? " · expires " + deadlineText(row.scheduled_end_at) : "");
        const people = peopleWith(row);
        return '<li class="ofc-rcard' + (soon ? " is-soon" : "") + '">' +
            '<button class="ofc-rmain" type="button" data-open-appointment="' + Number(row.id) + '">' +
            '<span class="ofc-rtop"><strong>' + esc(row.visitor_full_name) + "</strong>" + ICON.chev + "</span>" +
            '<span class="ofc-rwhy">' + esc(purposeLine(row)) + "</span>" +
            '<span class="ofc-rwhen">' + ICON.clock + "<span>" + when + "</span></span>" +
            (people.length ? '<span class="ofc-rgroup">' + ICON.people + "<span>" + (people.length + 1) + " people: with " + esc(namesText(people)) + "</span></span>" : "") +
            (stop ? '<span class="ofc-rmulti">' + esc(stop) + "</span>" : "") +
            '<span class="ofc-rmeta' + (soon ? " is-warn" : "") + '">' + esc(meta) + "</span></button>" +
            '<div class="ofc-ractions">' +
            '<button class="ofc-btn is-decline" type="button" data-decline="' + Number(row.id) + '">Decline</button>' +
            '<button class="ofc-btn" type="button" data-other-time="' + Number(row.id) + '">Other time</button>' +
            '<button class="ofc-btn is-approve" type="button" data-approve="' + Number(row.id) + '">' + ICON.check + "Approve</button>" +
            "</div></li>";
    }

    function waitingCard(row) {
        const offer = row.offer || null;
        const times = offer ? offer.times.map(function (value) {
            return '<span class="ofc-chip">' + esc(dayWord(value) + " · " + clock(value)) + "</span>";
        }).join("") : "";
        return '<li class="ofc-rcard">' +
            '<button class="ofc-rmain" type="button" data-open-appointment="' + Number(row.id) + '">' +
            '<span class="ofc-rtop"><strong>' + esc(row.visitor_full_name) + '</strong><span class="ofc-chip is-blue">Waiting for visitor</span></span>' +
            '<span class="ofc-rwhy">' + esc(purposeLine(row)) + "</span>" +
            '<span class="ofc-rmeta">Asked for ' + esc(dayWord(row.scheduled_start_at) + " · " + timeRange(row.scheduled_start_at, row.scheduled_end_at)) + "</span>" +
            (offer ? '<span class="ofc-rmeta">You offered ' + esc(agoText(offer.sent_at)) +
                (offer.response_deadline ? " · they can choose until " + esc(deadlineText(offer.response_deadline)) : "") + ":</span>" +
                '<span class="ofc-offered">' + times + "</span>" : "") +
            "</button></li>";
    }

    function renderRequests() {
        const pending = state.appointments.filter(function (row) { return row.status === "pending_approval"; }).sort(byStart);
        const waiting = state.appointments.filter(function (row) { return row.status === "reschedule_proposed"; }).sort(byStart);
        setAll('[data-count="waiting"]', waiting.length);
        document.querySelectorAll("[data-request-tab]").forEach(function (button) {
            const active = button.dataset.requestTab === state.requestTab;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-selected", String(active));
        });
        const rows = (state.requestTab === "waiting" ? waiting : pending).filter(function (row) { return matches(row, state.requestQuery); });
        const list = byId("officeRequestList");
        if (!state.loaded) {
            list.innerHTML = '<li class="ofc-empty">Loading…</li>';
        } else if (rows.length) {
            list.innerHTML = rows.map(state.requestTab === "waiting" ? waitingCard : requestCard).join("");
        } else {
            list.innerHTML = '<li class="ofc-empty">' + (state.requestQuery ? "No request matches your search."
                : state.requestTab === "waiting" ? "No offers are waiting for a visitor." : "Every request has an answer.") + "</li>";
        }
        setText("officeRequestNote", state.requestTab === "waiting"
            ? "You suggested other times. The visitor picks one or declines them all."
            : "Soonest visit first. A request nobody answers expires when its time passes.");
    }

    // ---------- Visits ----------

    const VISIT_TABS = {
        today: function (row) {
            return isToday(row.scheduled_start_at) && ["approved", "checked_in", "completed", "window_closed"].indexOf(row.status) !== -1;
        },
        upcoming: function (row) {
            const days = daysFromToday(row.scheduled_start_at);
            return row.status === "approved" && days !== null && days > 0;
        },
        past: function (row) {
            return CLOSED_STATUSES.indexOf(row.status) !== -1;
        },
        all: function () {
            return true;
        },
    };

    function visitRow(row) {
        const meta = statusMeta(row);
        const start = parseServerDate(row.scheduled_start_at);
        const when = isGate(row) || row.visit_type === "walk_in"
            ? "Walk-in, " + clock(start)
            : timeRange(row.scheduled_start_at, row.scheduled_end_at);
        const reference = [row.registration_code, row.visit_type === "walk_in" ? "Walk-in" : "", stopLabel(row)].filter(Boolean).join(" · ");
        return '<button class="ofc-vrow" type="button" data-open-appointment="' + Number(row.id) + '">' +
            '<span class="ofc-v-who"><strong>' + esc(row.visitor_full_name) + "</strong><small>" + esc(reference) + "</small></span>" +
            '<span class="ofc-v-why"><strong>' + esc(row.purpose || "Purpose not given") + "</strong>" +
            (row.subject && row.subject !== row.purpose ? "<small>" + esc(row.subject) + "</small>" : "") + "</span>" +
            '<span class="ofc-v-when"><strong>' + esc(start ? dayWord(start) : "—") + "</strong><small>" + esc(when) + "</small></span>" +
            '<span class="ofc-v-status"><span class="ofc-chip ' + meta.className + '">' + esc(meta.label) + "</span></span>" +
            '<span class="ofc-v-go">' + ICON.chev + "</span></button>";
    }

    function renderVisits() {
        Object.keys(VISIT_TABS).forEach(function (tab) {
            setAll('[data-count="v-' + tab + '"]', state.appointments.filter(VISIT_TABS[tab]).length);
        });
        document.querySelectorAll("[data-visits-tab]").forEach(function (button) {
            if (!button.closest(".ofc-seg")) {
                return;
            }
            const active = button.dataset.visitsTab === state.visitsTab;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-selected", String(active));
        });
        const showStatus = state.visitsTab === "past" || state.visitsTab === "all";
        byId("officeStatusChips").hidden = !showStatus;
        document.querySelectorAll("[data-visits-status]").forEach(function (button) {
            const active = button.dataset.visitsStatus === state.visitsStatus;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-pressed", String(active));
        });
        let rows = state.appointments.filter(VISIT_TABS[state.visitsTab]).filter(function (row) { return matches(row, state.visitsQuery); });
        if (showStatus && state.visitsStatus) {
            rows = rows.filter(function (row) { return row.status === state.visitsStatus; });
        }
        rows.sort(state.visitsTab === "today" || state.visitsTab === "upcoming" ? byStart : function (left, right) { return byStart(right, left); });
        const shown = rows.slice(0, state.visitsShown);
        const head = '<div class="ofc-vrow is-head" aria-hidden="true"><span>Visitor</span><span>Purpose</span><span>When</span><span>Status</span><span></span></div>';
        byId("officeVisitTable").innerHTML = !state.loaded ? '<p class="ofc-empty">Loading…</p>'
            : rows.length ? head + shown.map(visitRow).join("")
                : '<p class="ofc-empty">' + (state.visitsQuery || state.visitsStatus ? "No visit matches." : state.visitsTab === "today" ? "No visitors today yet." : "Nothing here yet.") + "</p>";
        const more = byId("officeVisitMore");
        more.hidden = rows.length <= shown.length;
        more.textContent = "Show more (" + (rows.length - shown.length) + " left)";
    }

    // ---------- One visit or request ----------

    function banner(kind, icon, html) {
        return '<p class="ofc-banner ' + kind + '">' + icon + "<span>" + html + "</span></p>";
    }

    function fact(label, value) {
        return value ? "<div><dt>" + esc(label) + "</dt><dd>" + esc(value) + "</dd></div>" : "";
    }

    function renderAppointmentBody(appointment, data) {
        const parts = [];
        const help = helpFor(appointment.id);
        const atGate = isGate(appointment);
        switch (appointment.status) {
        case "pending_approval":
            parts.push(banner("is-amber", ICON.clock, "Answer before <b>" + esc(deadlineText(appointment.scheduled_end_at)) +
                "</b>. After that the request expires and the visitor is told nobody answered."));
            break;
        case "reschedule_proposed": {
            const proposal = data.reschedule_proposal;
            parts.push(banner("is-blue", ICON.info, "You offered other times " + esc(proposal ? agoText(proposal.created_at) : "") +
                ". The visitor picks one or declines them all." + (proposal && proposal.response_deadline
                ? " They can choose until " + esc(deadlineText(proposal.response_deadline)) + "." : "")));
            if (proposal && proposal.slots && proposal.slots.length) {
                parts.push('<div class="ofc-offered">' + proposal.slots.map(function (slot) {
                    return '<span class="ofc-chip is-blue">' + esc(dayWord(slot.scheduled_start_at) + " · " + clock(slot.scheduled_start_at)) + "</span>";
                }).join("") + "</div>");
            }
            break;
        }
        case "approved":
            parts.push(banner("is-green", ICON.check, appointment.visit_type === "walk_in"
                ? "Walk-in pass issued. Not checked in at the gate yet."
                : "Approved. The visitor's QR pass is ready."));
            break;
        case "checked_in":
            if (help) {
                parts.push(banner("is-red", ICON.alert, "<b>Can't find your office</b> (" + esc(agoText(help.created_at)) + "). " + esc(help.message || "")));
            }
            if (appointment.arrived_at) {
                parts.push(banner("is-green", ICON.pin, "<b>At your office</b> " + esc(arrivalSentence(appointment))));
            } else if (atGate) {
                parts.push(banner("is-blue", ICON.info, "Registered at the gate by Security at " + esc(clock(appointment.checked_in_at)) +
                    ". No app, so their arrival isn't tracked."));
            } else if (!help) {
                parts.push(banner("is-blue", ICON.info, "Checked in at the gate at " + esc(clock(appointment.checked_in_at)) + ". Not at your office yet."));
            }
            break;
        case "completed":
        case "window_closed":
            parts.push(banner("is-gray", ICON.info, esc(endedText(appointment)) + "."));
            break;
        case "rejected":
            parts.push(banner("is-red", ICON.x, "<b>Declined" + (appointment.processed_by ? " by " + esc(appointment.processed_by) : "") + ":</b> " +
                esc(appointment.rejection_reason || "No reason recorded.")));
            break;
        case "cancelled":
            parts.push(banner("is-gray", ICON.info, "Cancelled" + (appointment.processed_by ? " by " + esc(appointment.processed_by) : "") + "."));
            break;
        case "unanswered":
            parts.push(banner("is-red", ICON.clock, "Nobody answered before " + esc(clock(appointment.scheduled_end_at)) + ", so the request expired."));
            break;
        default:
            break;
        }

        const start = parseServerDate(appointment.scheduled_start_at);
        const days = start ? daysFromToday(start) : null;
        const near = days !== null && days >= -1 && days <= 1;
        const whenTitle = atGate ? "Walk-in, " + clock(start)
            : appointment.visit_type === "walk_in" ? dayWord(start) + " · walk-in, " + clock(start)
                : (start ? dayWord(start) : "No date") + " · " + timeRange(appointment.scheduled_start_at, appointment.scheduled_end_at);
        parts.push('<div class="ofc-when"><small>When</small><strong>' + esc(whenTitle) + "</strong>" +
            (near && start ? "<span>" + esc(longDayFormat.format(start)) + "</span>" : "") + "</div>");

        if (data.visit) {
            const others = (data.visit.other_stop_times || []).map(function (stop) {
                return timeRange(stop.scheduled_start_at, stop.scheduled_end_at);
            });
            parts.push('<p class="ofc-rmulti">Stop ' + Number(data.visit.stop_number) + " of " + Number(data.visit.stop_count) +
                " · visit " + esc(data.visit.visit_code) + (others.length ? " · their other stops: " + esc(others.join(", ")) : "") + "</p>");
        }

        parts.push('<p class="ofc-label">Why</p><dl class="ofc-facts">' + fact("Purpose", appointment.purpose || "Not given") +
            fact("Concern", appointment.subject && appointment.subject !== appointment.purpose ? appointment.subject : "") + "</dl>");
        if (appointment.additional_details) {
            parts.push('<p class="ofc-quote">' + esc(appointment.additional_details) + "</p>");
        }
        parts.push('<p class="ofc-label">Visitor</p><dl class="ofc-facts">' +
            fact("Phone", appointment.contact_number) +
            fact("Email", atGate ? "" : appointment.visitor_email) +
            fact("Visit type", atGate ? "Walk-in, registered at the gate" : appointment.visit_type === "walk_in" ? "Walk-in" : "Appointment") +
            fact("ID checked", appointment.id_type_shown || "") +
            fact(atGate ? "Registered" : "Asked", fullDate(appointment.created_at)) + "</dl>");
        if (appointment.visitor_email && !atGate) {
            parts.push('<a class="ofc-btn is-quiet ofc-mail" href="mailto:' + esc(encodeURI(appointment.visitor_email)) + '">' + ICON.mail + "Email the visitor</a>");
        }
        // The people coming with the visitor, and whether each came in (GROUP_VISITS.md).
        const people = Array.isArray(appointment.people) ? appointment.people : [];
        if (people.length) {
            const status = function (person) {
                if (person.gate_status === "absent") {
                    return "didn't come";
                }
                if (person.gate_status === "refused") {
                    return "not let in at the gate";
                }
                if (person.left_at) {
                    return "left at " + clock(person.left_at);
                }
                return person.gate_status === "in" ? "came in" : "";
            };
            parts.push('<p class="ofc-label">People with them · ' + people.length + '</p><ul class="ofc-people">' + people.map(function (person) {
                const notes = [person.is_adult ? "18 or older" : "Under 18", status(person)].filter(Boolean);
                return "<li>" + ICON.people + "<span><strong>" + esc(person.full_name) + "</strong><small>" + esc(notes.join(" · ")) + "</small></span></li>";
            }).join("") + "</ul>");
        }

        const history = Array.isArray(data.history) ? data.history : [];
        parts.push('<details class="ofc-activity"><summary>Activity</summary><ul class="ofc-tl">' + (history.length ? history.map(function (event) {
            return "<li><i></i><span><strong>" + esc(HISTORY_LABELS[event.to_status] || event.to_status || "Updated") + "</strong>" +
                (event.note ? "<em>" + esc(event.note) + "</em>" : "") +
                "<small>" + esc(fullDate(event.changed_at) + " · " + (event.changed_by || "System")) + "</small></span></li>";
        }).join("") : "<li><span><small>No recorded activity.</small></span></li>") + "</ul></details>");
        return parts.join("");
    }

    async function openAppointment(appointmentId) {
        const data = await fetchJson("office_appointment.php?id=" + encodeURIComponent(appointmentId));
        if (!data || !data.success) {
            toast((data && data.message) || "Could not open the visit.", "error");
            return;
        }
        const appointment = data.appointment;
        state.selectedAppointmentId = Number(appointment.id);
        state.selectedAppointment = appointment;
        state.selectedVisit = data.visit || null;
        setText("officeAppointmentTitle", appointment.visitor_full_name || "Visitor");
        setText("officeAppointmentCode", appointment.registration_code || "");
        const meta = statusMeta(appointment);
        const chip = byId("officeAppointmentStatus");
        chip.className = "ofc-chip " + meta.className;
        chip.textContent = meta.label;
        byId("officeAppointmentBody").innerHTML = renderAppointmentBody(appointment, data);
        showError("officeAppointmentError", "");

        // Pending: answer it. Approved (not a walk-in): the office can still move the time.
        // A checked-in stop of a multi-stop visit: the office marks its meeting done.
        const isPending = appointment.status === "pending_approval";
        const canMove = appointment.status === "approved" && appointment.visit_type !== "walk_in";
        const canFinish = appointment.status === "checked_in" && Boolean(data.visit);
        const help = appointment.status === "checked_in" ? helpFor(appointment.id) : null;
        byId("openDeclineAppointmentBtn").hidden = !isPending;
        byId("approveOfficeAppointmentBtn").hidden = !isPending;
        const move = byId("openRescheduleAppointmentBtn");
        move.hidden = !(isPending || canMove);
        move.textContent = canMove ? "Suggest another time" : "Other time";
        byId("completeOfficeStopBtn").hidden = !canFinish;
        const going = byId("officeGoingBtn");
        going.hidden = !help;
        going.dataset.notificationId = help ? String(help.id) : "";
        const footer = byId("officeAppointmentActions");
        const count = [isPending ? 3 : 0, canMove ? 1 : 0, canFinish ? 1 : 0, help ? 1 : 0].reduce(function (sum, value) { return sum + value; }, 0);
        footer.hidden = count === 0;
        footer.dataset.count = String(count);
        const dialog = byId("officeAppointmentDialog");
        if (dialog.open) {
            dialog.querySelector(".ofc-sheet-body").scrollTop = 0;
        } else {
            openSheet(dialog);
        }
    }

    // ---------- Approve, decline, other times, meeting done ----------

    async function processAppointment(action, reason) {
        showError("officeAppointmentError", "");
        showError("officeDeclineError", "");
        const buttons = [byId("approveOfficeAppointmentBtn"), byId("completeOfficeStopBtn"), byId("confirmOfficeDeclineBtn")];
        buttons.forEach(function (button) { button.disabled = true; });
        try {
            const data = await postJson("office_appointment_action.php", {
                appointment_id: state.selectedAppointmentId,
                action: action,
                reason: reason || "",
            });
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not update the appointment.");
            }
            closeSheets([byId("officeAppointmentDialog"), byId("officeDeclineDialog")]);
            toast(data.message || "Appointment updated.");
            await loadOfficeData(true);
        } catch (error) {
            const declineOpen = byId("officeDeclineDialog").open;
            const target = action === "reject" && declineOpen ? "officeDeclineError" : "officeAppointmentError";
            if (!byId(target).closest("dialog").open) {
                toast(error.message || "Could not reach the server.", "error");
            } else {
                showError(target, error.message || "Could not reach the server.");
            }
        } finally {
            buttons.forEach(function (button) { button.disabled = false; });
        }
    }

    async function approve(appointmentId) {
        const row = findRow(appointmentId) || state.selectedAppointment;
        if (!row) {
            return;
        }
        state.selectedAppointmentId = Number(row.id);
        const when = dayWord(row.scheduled_start_at) + ", " + timeRange(row.scheduled_start_at, row.scheduled_end_at);
        if (await AppDialog.confirm({
            title: "Approve this appointment?",
            message: row.visitor_full_name + ", " + when + ". This issues the visitor's QR pass.",
            confirmLabel: "Approve",
        })) {
            processAppointment("approve", "");
        }
    }

    function openDecline(appointmentId) {
        const row = findRow(appointmentId) || state.selectedAppointment;
        if (!row) {
            return;
        }
        state.selectedAppointmentId = Number(row.id);
        setText("officeDeclineTitle", "Decline " + row.visitor_full_name);
        byId("officeDeclineReason").value = "";
        document.querySelectorAll("#officeDeclineReasons [data-reason]").forEach(function (button) { button.classList.remove("is-on"); });
        showError("officeDeclineError", "");
        openSheet(byId("officeDeclineDialog"));
    }

    async function meetingDone(appointmentId) {
        state.selectedAppointmentId = Number(appointmentId);
        if (await AppDialog.confirm({
            title: "Mark this meeting as done?",
            message: "The visitor's campus visit continues until Security checks them out.",
            confirmLabel: "Mark as done",
        })) {
            processAppointment("complete", "");
        }
    }

    function updatePicked() {
        const other = byId("officeOtherDays").hidden ? "" : document.querySelector("#officeOtherDays .office-proposed-slot").value;
        const total = state.picked.length + (other ? 1 : 0);
        const labels = state.picked.map(function (slot) { return dayWord(slot.start) + " " + clock(slot.start); });
        if (other) {
            labels.push(dayWord(other) + " " + clock(other));
        }
        setText("officePickedLine", total ? total + " of 3 picked: " + labels.join(", ") : "None picked yet");
        const send = byId("confirmOfficeRescheduleBtn");
        send.disabled = total === 0;
        send.textContent = total ? "Send " + total + " time" + (total === 1 ? "" : "s") : "Send times";
        document.querySelectorAll("#officeFreeTimes [data-slot]").forEach(function (button) {
            const on = state.picked.some(function (slot) { return slot.start === button.dataset.slot; });
            button.classList.toggle("is-on", on);
            button.setAttribute("aria-pressed", String(on));
            button.disabled = !on && total >= 3;
        });
    }

    async function loadFreeTimes(appointmentId) {
        const host = byId("officeFreeTimes");
        host.innerHTML = '<p class="ofc-empty">Loading free times…</p>';
        const data = await fetchJson("office_free_times.php?appointment_id=" + encodeURIComponent(appointmentId));
        if (Number(state.selectedAppointmentId) !== Number(appointmentId)) {
            return;
        }
        if (!data || !data.success) {
            host.innerHTML = '<p class="ofc-empty">' + esc((data && data.message) || "Could not list the free times.") + "</p>";
            byId("officeOtherDays").hidden = false;
            return;
        }
        if (!data.days.length) {
            host.innerHTML = '<p class="ofc-empty">' + (data.accepting_visitors
                ? "No free times in the next three weeks. Pick a day and time below."
                : "Your office isn't accepting visitors, so there are no free times.") + "</p>";
            byId("officeOtherDays").hidden = !data.accepting_visitors;
            return;
        }
        host.innerHTML = data.days.map(function (day) {
            const date = parseServerDate(day.date + " 00:00:00");
            const label = daysFromToday(date) === 0 ? "Today, " + shortDayFormat.format(date)
                : daysFromToday(date) === 1 ? "Tomorrow, " + shortDayFormat.format(date) : shortDayFormat.format(date);
            return '<div class="ofc-slots-day"><strong>' + esc(label) + '</strong><div class="ofc-slots">' + day.times.map(function (slot) {
                return '<button class="ofc-slot" type="button" aria-pressed="false" data-slot="' + esc(slot.start) + '" data-slot-end="' + esc(slot.end) + '">' +
                    esc(clock(slot.start)) + "</button>";
            }).join("") + "</div></div>";
        }).join("");
        updatePicked();
    }

    async function openReschedule(appointmentId) {
        const row = findRow(appointmentId) || state.selectedAppointment;
        if (!row) {
            return;
        }
        state.selectedAppointmentId = Number(row.id);
        state.picked = [];
        byId("officeRescheduleForm").reset();
        byId("officeOtherDays").hidden = true;
        byId("officePickAnotherDay").hidden = false;
        const now = new Date(Date.now() + 60 * 60 * 1000);
        document.querySelector("#officeOtherDays .office-proposed-slot").min = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        setText("officeRescheduleTitle", row.status === "approved" ? "Suggest another time" : "Suggest other times");
        setText("officeRescheduleFor", "For " + row.visitor_full_name + ". Pick up to 3; the visitor chooses one or declines them all." +
            (row.status === "approved" ? " Their QR pass stops working until they choose." : ""));
        const first = document.querySelector("#officeRescheduleReasons [data-reason]");
        document.querySelectorAll("#officeRescheduleReasons [data-reason]").forEach(function (button) {
            button.classList.toggle("is-on", button === first);
        });
        byId("officeRescheduleReason").value = first ? first.dataset.reason : "";
        showError("officeRescheduleError", "");
        const hint = byId("officeRescheduleVisitHint");
        hint.hidden = true;
        updatePicked();
        openSheet(byId("officeRescheduleDialog"));
        loadFreeTimes(row.id);
        if (row.visit_id) {
            // The visitor's other stops that day, so the office offers a time that fits.
            const visit = state.selectedVisit && state.selectedAppointment && Number(state.selectedAppointment.id) === Number(row.id)
                ? state.selectedVisit
                : ((await fetchJson("office_appointment.php?id=" + encodeURIComponent(row.id))) || {}).visit;
            if (visit) {
                const others = (visit.other_stop_times || []).map(function (stop) { return timeRange(stop.scheduled_start_at, stop.scheduled_end_at); });
                hint.textContent = "Part of a visit to several offices" + (others.length ? ", with other stops at " + others.join(", ") : "") +
                    ". Times that clash with them are left out. A time on another day moves this request out of the visit.";
                hint.hidden = false;
            }
        }
    }

    // ---------- Hours: the weekly schedule with breaks ----------
    // A day is its visitor hours with its breaks cut out. The server saves the result as
    // bookable windows (office_availability_service.php), so visitors are never offered a time
    // inside a break. These helpers mirror the server's rules for live feedback.

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

    function shortClock(minutes) {
        const hours = Math.floor(minutes / 60) % 24;
        return (hours % 12 || 12) + ":" + String(minutes % 60).padStart(2, "0");
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
        const days = daysFromToday(date);
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

    function copyDay(day) {
        return {
            day: day.day,
            enabled: day.enabled,
            start: day.start,
            end: day.end,
            breaks: day.breaks.map(function (item) { return newBreak(item.start, item.end, item.label); }),
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

    function slotCapacity() {
        return Math.min(50, Math.max(1, Number(byId("officeSlotCapacity").value) || 1));
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

    /** The hours every day's bar shows: the earliest opening to the latest closing. */
    function timelineRange(days) {
        let first = null;
        let last = null;
        days.forEach(function (day) {
            const open = minutesOf(day.start);
            const close = minutesOf(day.end);
            if (!day.enabled || open === null || close === null || open >= close) {
                return;
            }
            first = first === null ? open : Math.min(first, open);
            last = last === null ? close : Math.max(last, close);
        });
        if (first === null) {
            return { start: 8 * 60, end: 17 * 60 };
        }
        return { start: first, end: Math.max(first + 60, last) };
    }

    function percent(minutes, range) {
        return ((minutes - range.start) / (range.end - range.start) * 100).toFixed(3) + "%";
    }

    function barHtml(day, range, slots) {
        if (!day.enabled) {
            return "";
        }
        const parts = [];
        dayWindows(day).forEach(function (window) {
            parts.push('<i class="is-open" style="left:' + percent(window[0], range) + ";width:" +
                ((window[1] - window[0]) / (range.end - range.start) * 100).toFixed(3) + '%"></i>');
        });
        breakSpans(day).forEach(function (span) {
            if (span.start !== null && span.end !== null && span.start < span.end) {
                parts.push('<i class="is-break" style="left:' + percent(span.start, range) + ";width:" +
                    ((span.end - span.start) / (range.end - range.start) * 100).toFixed(3) + '%"></i>');
            }
        });
        (slots || []).forEach(function (start) {
            parts.push('<i class="is-tick" style="left:' + percent(start, range) + '"></i>');
        });
        return parts.join("");
    }

    function renderWeekRows() {
        const range = timelineRange(state.week);
        const length = slotLength();
        byId("officeWeekRows").innerHTML = state.week.map(function (day, index) {
            const short = dayNames[day.day - 1].slice(0, 3);
            if (!day.enabled) {
                return '<button class="ofc-dayrow is-closed" type="button" data-day-index="' + index + '" aria-label="' + dayNames[day.day - 1] +
                    ': closed. Change it">' + '<span class="ofc-dname">' + short + '</span><span class="ofc-dtext">Closed</span><span class="ofc-bar"></span>' + ICON.chev + "</button>";
            }
            const problem = dayProblem(day);
            const breaks = breakSpans(day).map(function (span) {
                return span.start === null || span.end === null ? span.label : span.label + " " + shortClock(span.start) + "–" + shortClock(span.end);
            }).join(" · ");
            const count = problem ? 0 : daySlots(day, length).length;
            const detail = problem ? '<small class="is-problem">' + esc(problem) + "</small>"
                : "<small>" + esc((breaks ? breaks + " · " : "") + count + (count === 1 ? " time" : " times")) + "</small>";
            const open = minutesOf(day.start);
            const close = minutesOf(day.end);
            const hours = open !== null && close !== null ? clockText(open) + " – " + clockText(close) : "Hours not set";
            return '<button class="ofc-dayrow' + (problem ? " has-problem" : "") + '" type="button" data-day-index="' + index + '" aria-label="' +
                dayNames[day.day - 1] + ": " + esc(hours) + '. Change it">' +
                '<span class="ofc-dname">' + short + '</span><span class="ofc-dtext">' + esc(hours) + detail + "</span>" +
                '<span class="ofc-bar">' + barHtml(day, range, null) + "</span>" + ICON.chev + "</button>";
        }).join("");
    }

    function markDirty() {
        state.weekDirty = true;
        showError("officeAvailabilityError", "");
        updateSaveBar();
    }

    function markClean() {
        state.weekDirty = false;
        updateSaveBar();
    }

    function updateSaveBar() {
        const show = state.weekDirty && state.view === "hours";
        byId("officeSaveBar").hidden = !show;
        document.body.classList.toggle("ofc-has-savebar", show);
    }

    // ---------- Hours: one day at a time ----------

    function dayEditorPreview(day) {
        if (!day.enabled) {
            return '<p class="ofc-note">Closed. Visitors can\'t book ' + dayNames[day.day - 1] + "s.</p>";
        }
        const problem = dayProblem(day);
        if (problem) {
            return '<p class="ofc-day-problem" role="alert">' + esc(problem) + "</p>";
        }
        const length = slotLength();
        const open = minutesOf(day.start);
        const close = minutesOf(day.end);
        const range = { start: Math.floor(open / 60) * 60, end: Math.max(Math.floor(open / 60) * 60 + 60, Math.ceil(close / 60) * 60) };
        const slots = daySlots(day, length);
        const step = range.end - range.start > 360 ? 120 : 60;
        const marks = [];
        for (let minute = range.start; minute <= range.end; minute += step) {
            const at = minute + step > range.end && minute !== range.end ? range.end : minute;
            const hour = Math.floor(at / 60) % 24;
            marks.push('<span style="left:' + percent(at, range) + '">' + (hour % 12 || 12) + " " + (hour < 12 ? "AM" : "PM") + "</span>");
            if (at === range.end) {
                break;
            }
        }
        const items = slots.map(function (start) { return { at: start, html: "<span>" + shortClock(start) + "</span>" }; })
            .concat(breakSpans(day).map(function (span) {
                return { at: span.start, html: '<span class="is-break">' + esc(span.label) + " " + shortClock(span.start) + "–" + shortClock(span.end) + "</span>" };
            }))
            .sort(function (left, right) { return left.at - right.at; });
        const leftovers = dayLeftovers(day, length);
        const capacity = slotCapacity();
        return '<div class="ofc-bigbar">' + barHtml(day, range, slots) + '</div><div class="ofc-scale">' + marks.join("") + "</div>" +
            '<p class="ofc-note"><b>' + slots.length + (slots.length === 1 ? " time" : " times") + "</b> of " + length + " min, up to " +
            capacity + (capacity === 1 ? " visitor" : " visitors") + " each.</p>" +
            '<div class="ofc-pslots">' + items.map(function (item) { return item.html; }).join("") + "</div>" +
            (leftovers.length ? '<p class="ofc-note">Too short for a ' + length + "-min visit: " + esc(leftovers.join(", ")) + ".</p>" : "");
    }

    function breakRowHtml(day, item) {
        const dayName = dayNames[day.day - 1];
        const what = item.label.trim() || "break";
        return '<div class="ofc-brk" data-break-key="' + esc(item.key) + '">' +
            '<input class="ofc-brk-name" data-field="label" value="' + esc(item.label) + '" maxlength="60" list="officeBreakNames" placeholder="Break name" aria-label="' + esc(dayName + " break name") + '">' +
            '<button class="ofc-icon-btn" type="button" data-remove-break="' + esc(item.key) + '" aria-label="' + esc("Remove " + what) + '">' + ICON.trash + "</button>" +
            '<div class="ofc-times"><input type="time" data-field="start" value="' + esc(item.start) + '" aria-label="' + esc(what + " starts") + '">' +
            '<span>to</span><input type="time" data-field="end" value="' + esc(item.end) + '" aria-label="' + esc(what + " ends") + '"></div></div>';
    }

    function renderDayEditor() {
        const day = state.dayDraft;
        const dayName = dayNames[day.day - 1];
        setText("officeDayTitle", dayName);
        setText("officeDaySub", "Every " + dayName + ", until you change it.");
        byId("officeDayBody").innerHTML =
            '<div class="ofc-setting"><span><strong>Open on ' + dayName + 's</strong><small>Visitors can book this day.</small></span>' +
            '<label class="office-switch"><input type="checkbox" data-field="enabled"' + (day.enabled ? " checked" : "") + '><span aria-hidden="true"></span>' +
            '<span class="sr-only">Open on ' + dayName + "s</span></label></div>" +
            (day.enabled
                ? '<div class="ofc-field"><span>Visitor hours</span><div class="ofc-times">' +
                    '<input type="time" data-field="start" value="' + esc(day.start) + '" aria-label="' + dayName + ' opens"><span>to</span>' +
                    '<input type="time" data-field="end" value="' + esc(day.end) + '" aria-label="' + dayName + ' closes"></div></div>' +
                    '<div class="ofc-field"><span>Breaks</span><div class="ofc-brks">' +
                    day.breaks.map(function (item) { return breakRowHtml(day, item); }).join("") + "</div>" +
                    '<button class="ofc-link" type="button" data-add-break' + (day.breaks.length >= MAX_BREAKS ? " disabled" : "") + ">" + ICON.plus + "Add a break</button></div>"
                : "") +
            '<div class="ofc-field"><span>What visitors can book</span><div id="officeDayPreview">' + dayEditorPreview(day) + "</div></div>" +
            (day.enabled ? '<button class="ofc-link" type="button" data-copy-day>Copy ' + dayName + " to the other open days</button>" : "");
    }

    function openDayEditor(index) {
        if (!state.week[index]) {
            return;
        }
        state.dayIndex = index;
        state.dayDraft = copyDay(state.week[index]);
        renderDayEditor();
        openSheet(byId("officeDayDialog"));
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
            renderWeekRows();
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

    // ---------- Hours: status, pause and temporary changes ----------

    function statusParts(info) {
        switch (info.state) {
        case "break":
            return {
                tone: "is-break",
                title: info.label === "Break" ? "On a break" : info.label,
                sub: info.until ? "Back " + whenText(info.until) : "",
            };
        case "closed":
            return { tone: "is-off", title: "Closed now", sub: info.until ? "Opens " + whenText(info.until) : "" };
        case "paused":
            return {
                tone: "is-paused",
                title: "Paused" + (info.until ? " until " + serverClock(info.until) : ""),
                sub: (info.label && info.label !== "Office unavailable" ? info.label + " · " : "") + "Booked visits stay",
            };
        case "not_accepting":
            return { tone: "is-stopped", title: "Not accepting visitors", sub: "Turned off in Hours" };
        default:
            if (info.label && info.label !== "Open") {
                return { tone: "is-open", title: info.label, sub: info.until ? "Until " + serverClock(info.until) : "" };
            }
            if (info.until && info.next === "Closing") {
                return { tone: "is-open", title: "Open now", sub: "Closes at " + serverClock(info.until) };
            }
            if (info.until && info.next) {
                return { tone: "is-open", title: "Open now", sub: info.next + " at " + serverClock(info.until) };
            }
            return { tone: "is-open", title: "Open now", sub: info.until ? "Until " + serverClock(info.until) : "" };
        }
    }

    function renderStatus(status) {
        state.status = status || null;
        const info = status || { state: "open", label: "Open" };
        const parts = statusParts(info);
        document.body.dataset.officeState = parts.tone;
        document.querySelectorAll("[data-status-card]").forEach(function (card) {
            card.dataset.tone = parts.tone;
            card.querySelector("[data-status-title]").textContent = parts.title;
            card.querySelector("[data-status-sub]").textContent = parts.sub;
        });
        setText("officeTopStatus", parts.title + (parts.sub ? " · " + parts.sub : ""));
        const resume = info.state === "paused" && Boolean(info.pause_id);
        document.querySelectorAll("[data-pause-toggle]").forEach(function (button) {
            button.hidden = info.state === "not_accepting" || Boolean(info.campus_closed);
            button.dataset.mode = resume ? "resume" : "pause";
            button.textContent = resume ? "Resume" : (button.closest(".is-big") ? "Pause new visits" : "Pause");
        });
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
            renderWeekRows();
            markClean();
        }
        renderExceptions(data.exceptions || []);
        renderStatus(data.status || {
            state: Number(settings.accepting_visitors) === 1 ? "open" : "not_accepting",
            label: "Open",
        });
    }

    function changeItem(exception, removable) {
        const extra = Number(exception.is_available) === 1;
        const starts = parseServerDate(exception.starts_at);
        const ends = parseServerDate(exception.ends_at);
        const sameDay = starts && ends && starts.toDateString() === ends.toDateString();
        const when = starts && ends
            ? (sameDay ? dayWord(starts) + " · " + timeRange(starts, ends) : dayWord(starts) + ", " + clock(starts) + " – " + dayWord(ends) + ", " + clock(ends))
            : "";
        return '<li class="ofc-change"><span class="ofc-mk' + (extra ? " is-extra" : "") + '"></span><span><strong>' +
            (extra ? "Extra visitor hours" : "Office unavailable") + "</strong><small>" + esc(when + (exception.reason ? " · " + exception.reason : "")) +
            (exception.campus_closure_id ? " · set by the administrator for the whole campus" : "") +
            "</small></span>" + (removable && !exception.campus_closure_id
                ? '<button class="ofc-icon-btn" type="button" data-exception-id="' + Number(exception.id) + '" aria-label="Remove this change">' + ICON.trash + "</button>"
                : "<span></span>") + "</li>";
    }

    /** Current and coming changes; the ones that ended (such as past pauses) fold away. */
    function renderExceptions(exceptions) {
        const now = Date.now();
        const ended = function (exception) {
            const ends = parseServerDate(exception.ends_at);
            return ends !== null && ends.getTime() <= now;
        };
        const current = exceptions.filter(function (exception) { return !ended(exception); });
        const past = exceptions.filter(ended).reverse();
        byId("officeExceptionList").innerHTML = current.map(function (exception) { return changeItem(exception, true); }).join("");
        byId("officeExceptionEmpty").hidden = current.length !== 0;
        const host = byId("officeExceptionPast");
        host.hidden = past.length === 0;
        host.innerHTML = past.length
            ? "<summary>Ended in the last 30 days (" + past.length + ')</summary><ul class="ofc-changes">' +
                past.map(function (exception) { return changeItem(exception, false); }).join("") + "</ul>"
            : "";
    }

    async function loadAvailability() {
        showError("officeAvailabilityError", "");
        try {
            const data = await fetchJson("office_availability.php");
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not load the hours.");
            }
            renderAvailability(data);
            state.availabilityLoaded = true;
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showError("officeAvailabilityError", error.message || "Could not reach the server.");
            }
        }
    }

    function showConflicts(data) {
        const list = byId("officeConflictList");
        list.replaceChildren();
        (data.conflicts || []).forEach(function (conflict) {
            const item = document.createElement("li");
            item.className = "office-conflict-item";
            const copy = document.createElement("div");
            const when = document.createElement("strong");
            when.textContent = fullDate(conflict.starts_at);
            const who = document.createElement("span");
            who.textContent = [conflict.visitor, conflict.code, statusMeta({ status: conflict.status }).label].filter(Boolean).join(" · ");
            const why = document.createElement("small");
            why.textContent = conflict.reason || "";
            copy.append(when, who, why);
            const open = document.createElement("button");
            open.type = "button";
            open.className = "ofc-btn is-small";
            open.dataset.conflictAppointment = String(conflict.id);
            open.textContent = "Open";
            open.setAttribute("aria-label", "Open " + conflict.visitor + "'s visit on " + fullDate(conflict.starts_at));
            item.append(copy, open);
            list.appendChild(item);
        });
        const more = Number(data.conflict_total || 0) - (data.conflicts || []).length;
        if (more > 0) {
            const item = document.createElement("li");
            item.className = "office-conflict-more";
            item.textContent = "…and " + more + " more.";
            list.appendChild(item);
        }
        setText("officeConflictTitle", data.message || "Booked visits fall outside the new schedule");
        openSheet(byId("officeConflictDialog"));
    }

    async function saveAvailability(confirmConflicts) {
        showError("officeAvailabilityError", "");
        const broken = state.week.find(function (day) { return dayProblem(day) !== ""; });
        if (broken) {
            showError("officeAvailabilityError", dayNames[broken.day - 1] + ": " + dayProblem(broken));
            toast(dayNames[broken.day - 1] + " needs a fix before saving.", "error");
            return;
        }
        const button = byId("saveOfficeAvailabilityBtn");
        button.disabled = true;
        try {
            const data = await postJson("office_availability.php", {
                action: "save_schedule",
                accepting_visitors: byId("officeAcceptingVisitors").checked,
                slot_duration_minutes: slotLength(),
                maximum_visitors_per_slot: slotCapacity(),
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
                throw new Error((data && data.message) || "Could not save the hours.");
            }
            markClean();
            renderAvailability(data);
            toast(data.message || "Hours saved.");
        } catch (error) {
            showError("officeAvailabilityError", error.message || "Could not reach the server.");
            toast(error.message || "Could not save the hours.", "error");
        } finally {
            button.disabled = false;
        }
    }

    async function onPauseToggle() {
        const mode = document.querySelector("[data-pause-toggle]").dataset.mode;
        if (mode !== "resume") {
            byId("officePauseForm").reset();
            showError("officePauseError", "");
            openSheet(byId("officePauseDialog"));
            return;
        }
        if (!(await AppDialog.confirm({
            title: "Resume visits now?",
            message: "Visitors can book and walk in again.",
            confirmLabel: "Resume visits",
        }))) {
            return;
        }
        const buttons = document.querySelectorAll("[data-pause-toggle]");
        buttons.forEach(function (button) { button.disabled = true; });
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
            buttons.forEach(function (button) { button.disabled = false; });
        }
    }

    function toInputDate(date) {
        const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
        return local.toISOString().slice(0, 16);
    }

    // ---------- Notifications ----------

    function renderNotifications() {
        const count = byId("officeNotificationCount");
        count.textContent = state.unread > 99 ? "99+" : String(state.unread);
        count.hidden = state.unread === 0;
        document.querySelectorAll("[data-unread-badge]").forEach(function (badge) {
            badge.textContent = count.textContent;
            badge.hidden = state.unread === 0;
        });
        setText("officeNotificationSummary", state.unread ? state.unread + " unread" : "No unread updates");
        byId("officeNotificationList").innerHTML = state.notifications.map(function (notification) {
            const help = notification.notification_type === "visitor_needs_help" && !notification.read_at;
            return '<li><button class="ofc-note-item' + (notification.read_at ? "" : " is-unread") + (help ? " is-help" : "") +
                '" type="button" data-notification-id="' + Number(notification.id) + '"' +
                (notification.appointment_id ? ' data-notification-appointment="' + Number(notification.appointment_id) + '"' : "") + "><i></i><span>" +
                "<strong>" + esc(notification.title || "Appointment update") + "</strong>" +
                "<span>" + esc(notification.message || "") + "</span>" +
                "<time>" + esc(agoText(notification.created_at)) + "</time></span></button></li>";
        }).join("");
        byId("officeNotificationEmpty").hidden = state.notifications.length !== 0;
        byId("markOfficeNotificationsReadBtn").hidden = state.unread === 0;
        byId("officeNotesFoot").hidden = state.unread === 0;
    }

    // ---------- Clicks ----------

    document.addEventListener("click", function (event) {
        const target = event.target;
        if (!byId("officeProfileMenu").hidden && !byId("officeProfileMenu").contains(target) && !byId("officeProfileBtn").contains(target)) {
            closeProfileMenu();
        }
        const viewButton = target.closest("[data-office-view]");
        if (viewButton && !target.closest("dialog")) {
            const tab = viewButton.dataset.visitsTab;
            if (tab) {
                state.visitsTab = tab;
                state.visitsStatus = "";
                state.visitsShown = VISITS_PAGE;
                renderVisits();
            }
            switchView(viewButton.dataset.officeView, { history: "push" });
            return;
        }
        const openButton = target.closest("[data-open-appointment]");
        if (openButton) {
            openAppointment(Number(openButton.dataset.openAppointment));
            return;
        }
        const approveButton = target.closest("[data-approve]");
        if (approveButton) {
            approve(Number(approveButton.dataset.approve));
            return;
        }
        const declineButton = target.closest("[data-decline]");
        if (declineButton) {
            openDecline(Number(declineButton.dataset.decline));
            return;
        }
        const otherButton = target.closest("[data-other-time]");
        if (otherButton) {
            openReschedule(Number(otherButton.dataset.otherTime));
            return;
        }
        const doneButton = target.closest("[data-meeting-done]");
        if (doneButton) {
            meetingDone(Number(doneButton.dataset.meetingDone));
            return;
        }
        const goingButton = target.closest("[data-help-going]");
        if (goingButton) {
            someoneIsGoing(goingButton.dataset.helpGoing, goingButton);
            return;
        }
        const jump = target.closest("[data-jump]");
        if (jump) {
            const group = byId("officeGroup-" + jump.dataset.jump);
            if (group) {
                const top = group.getBoundingClientRect().top + window.scrollY - (phoneQuery.matches ? 70 : 96);
                window.scrollTo({ top: top, behavior: "smooth" });
            } else {
                toast("Nobody in that group right now.");
            }
            return;
        }
        if (target.closest("[data-pause-toggle]")) {
            onPauseToggle();
            return;
        }
        const dayRow = target.closest("[data-day-index]");
        if (dayRow) {
            openDayEditor(Number(dayRow.dataset.dayIndex));
        }
    });

    document.querySelectorAll("[data-request-tab]").forEach(function (button) {
        button.addEventListener("click", function () {
            state.requestTab = button.dataset.requestTab;
            renderRequests();
        });
    });
    document.querySelectorAll(".ofc-seg [data-visits-tab]").forEach(function (button) {
        button.addEventListener("click", function () {
            state.visitsTab = button.dataset.visitsTab;
            state.visitsStatus = "";
            state.visitsShown = VISITS_PAGE;
            renderVisits();
        });
    });
    document.querySelectorAll("[data-visits-status]").forEach(function (button) {
        button.addEventListener("click", function () {
            state.visitsStatus = button.dataset.visitsStatus;
            state.visitsShown = VISITS_PAGE;
            renderVisits();
        });
    });
    byId("officeVisitMore").addEventListener("click", function () {
        state.visitsShown += VISITS_PAGE;
        renderVisits();
    });
    byId("officeRequestSearch").addEventListener("input", function (event) {
        state.requestQuery = event.target.value.trim();
        renderRequests();
    });
    byId("officeVisitSearch").addEventListener("input", function (event) {
        state.visitsQuery = event.target.value.trim();
        state.visitsShown = VISITS_PAGE;
        renderVisits();
    });

    // Details sheet buttons
    byId("approveOfficeAppointmentBtn").addEventListener("click", function () { approve(state.selectedAppointmentId); });
    byId("openDeclineAppointmentBtn").addEventListener("click", function () { openDecline(state.selectedAppointmentId); });
    byId("openRescheduleAppointmentBtn").addEventListener("click", function () { openReschedule(state.selectedAppointmentId); });
    byId("completeOfficeStopBtn").addEventListener("click", function () { meetingDone(state.selectedAppointmentId); });
    byId("officeGoingBtn").addEventListener("click", function (event) {
        someoneIsGoing(event.currentTarget.dataset.notificationId, event.currentTarget);
    });

    // Decline
    byId("officeDeclineReasons").addEventListener("click", function (event) {
        const button = event.target.closest("[data-reason]");
        if (!button) {
            return;
        }
        document.querySelectorAll("#officeDeclineReasons [data-reason]").forEach(function (item) { item.classList.toggle("is-on", item === button); });
        byId("officeDeclineReason").value = button.dataset.reason;
        showError("officeDeclineError", "");
    });
    byId("officeDeclineForm").addEventListener("submit", function (event) {
        event.preventDefault();
        const reason = byId("officeDeclineReason").value.trim();
        if (reason.length < 5) {
            showError("officeDeclineError", "Please give the visitor a clear reason.");
            return;
        }
        processAppointment("reject", reason);
    });

    // Other times
    byId("officeRescheduleReasons").addEventListener("click", function (event) {
        const button = event.target.closest("[data-reason]");
        if (!button) {
            return;
        }
        document.querySelectorAll("#officeRescheduleReasons [data-reason]").forEach(function (item) { item.classList.toggle("is-on", item === button); });
        byId("officeRescheduleReason").value = button.dataset.reason;
    });
    byId("officeRescheduleReason").addEventListener("input", function () {
        document.querySelectorAll("#officeRescheduleReasons [data-reason]").forEach(function (item) {
            item.classList.toggle("is-on", item.dataset.reason === byId("officeRescheduleReason").value);
        });
    });
    byId("officeFreeTimes").addEventListener("click", function (event) {
        const button = event.target.closest("[data-slot]");
        if (!button || button.disabled) {
            return;
        }
        const index = state.picked.findIndex(function (slot) { return slot.start === button.dataset.slot; });
        if (index !== -1) {
            state.picked.splice(index, 1);
        } else if (state.picked.length < 3) {
            state.picked.push({ start: button.dataset.slot, end: button.dataset.slotEnd });
        }
        updatePicked();
    });
    byId("officePickAnotherDay").addEventListener("click", function () {
        byId("officeOtherDays").hidden = false;
        byId("officePickAnotherDay").hidden = true;
        document.querySelector("#officeOtherDays .office-proposed-slot").focus();
        updatePicked();
    });
    document.querySelector("#officeOtherDays .office-proposed-slot").addEventListener("input", updatePicked);
    byId("officeRescheduleForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        showError("officeRescheduleError", "");
        const reason = byId("officeRescheduleReason").value.trim();
        if (reason.length < 5) {
            showError("officeRescheduleError", "Give the visitor a clear reason for the change.");
            return;
        }
        const slots = state.picked.map(function (slot) { return { start: slot.start, end: slot.end }; });
        const other = byId("officeOtherDays").hidden ? "" : document.querySelector("#officeOtherDays .office-proposed-slot").value;
        if (other && slots.length < 3) {
            slots.push(other);
        }
        if (!slots.length) {
            showError("officeRescheduleError", "Pick at least one time.");
            return;
        }
        const button = byId("confirmOfficeRescheduleBtn");
        button.disabled = true;
        try {
            const data = await postJson("office_propose_reschedule.php", {
                appointment_id: state.selectedAppointmentId,
                reason: reason,
                message: byId("officeRescheduleMessage").value.trim(),
                slots: slots,
            });
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not send the times.");
            }
            closeSheets([byId("officeAppointmentDialog"), byId("officeRescheduleDialog")]);
            toast("Sent " + slots.length + (slots.length === 1 ? " time" : " times") + ". The visitor chooses one or declines.");
            await loadOfficeData(true);
        } catch (error) {
            showError("officeRescheduleError", error.message || "Could not reach the server.");
        } finally {
            button.disabled = false;
            updatePicked();
        }
    });

    // Hours: the day editor
    byId("officeDayBody").addEventListener("input", function (event) {
        const field = event.target.dataset.field;
        const day = state.dayDraft;
        if (!field || !day || field === "enabled") {
            return;
        }
        const breakElement = event.target.closest("[data-break-key]");
        if (breakElement) {
            const item = day.breaks.find(function (entry) { return entry.key === breakElement.dataset.breakKey; });
            if (item) {
                item[field] = event.target.value;
            }
        } else {
            day[field] = event.target.value;
        }
        byId("officeDayPreview").innerHTML = dayEditorPreview(day);
    });
    byId("officeDayBody").addEventListener("change", function (event) {
        if (event.target.dataset.field === "enabled" && state.dayDraft) {
            state.dayDraft.enabled = event.target.checked;
            renderDayEditor();
        }
    });
    byId("officeDayBody").addEventListener("click", function (event) {
        const day = state.dayDraft;
        if (!day) {
            return;
        }
        if (event.target.closest("[data-add-break]")) {
            const item = suggestBreak(day);
            if (!item) {
                toast("There's no free time left on " + dayNames[day.day - 1] + " for another break.", "error");
                return;
            }
            day.breaks.push(item);
            renderDayEditor();
            const input = byId("officeDayBody").querySelector('[data-break-key="' + item.key + '"] .ofc-brk-name');
            if (input) {
                input.focus();
            }
            return;
        }
        const remove = event.target.closest("[data-remove-break]");
        if (remove) {
            day.breaks = day.breaks.filter(function (item) { return item.key !== remove.dataset.removeBreak; });
            renderDayEditor();
            return;
        }
        if (event.target.closest("[data-copy-day]")) {
            const problem = dayProblem(day);
            if (problem) {
                toast("Fix " + dayNames[day.day - 1] + " first: " + problem, "error");
                return;
            }
            state.week[state.dayIndex] = copyDay(day);
            const targets = state.week.filter(function (other) { return other.enabled && other.day !== day.day; });
            if (!targets.length) {
                toast("Turn on the other days first: " + dayNames[day.day - 1] + " is copied to open days only.", "error");
                return;
            }
            targets.forEach(function (other) {
                other.start = day.start;
                other.end = day.end;
                other.breaks = day.breaks.map(function (item) { return newBreak(item.start, item.end, item.label); });
            });
            markDirty();
            renderWeekRows();
            toast("Copied " + dayNames[day.day - 1] + " to " + targets.length + (targets.length === 1 ? " day" : " days") + ". Save to apply.");
        }
    });
    byId("officeDayDoneBtn").addEventListener("click", function () {
        const day = state.dayDraft;
        if (!day) {
            return;
        }
        const problem = dayProblem(day);
        if (problem) {
            byId("officeDayPreview").innerHTML = dayEditorPreview(day);
            toast(problem, "error");
            return;
        }
        const before = JSON.stringify(state.week[state.dayIndex], ["enabled", "start", "end", "breaks", "label"]);
        const after = JSON.stringify(day, ["enabled", "start", "end", "breaks", "label"]);
        state.week[state.dayIndex] = day;
        state.dayDraft = null;
        if (before !== after) {
            markDirty();
        }
        renderWeekRows();
        closeSheet(byId("officeDayDialog"));
    });

    // Hours: the rest of the page
    byId("officeAddLunchBtn").addEventListener("click", addLunchEverywhere);
    byId("officeSlotDuration").addEventListener("change", function () {
        markDirty();
        renderWeekRows();
    });
    byId("officeSlotCapacity").addEventListener("input", markDirty);
    document.querySelectorAll("[data-capacity-step]").forEach(function (button) {
        button.addEventListener("click", function () {
            const input = byId("officeSlotCapacity");
            const next = Math.min(50, Math.max(1, slotCapacity() + Number(button.dataset.capacityStep)));
            if (String(next) !== input.value) {
                input.value = String(next);
                markDirty();
            }
        });
    });
    byId("officeAcceptingVisitors").addEventListener("change", function (event) {
        byId("officeClosureFields").hidden = event.target.checked;
        markDirty();
    });
    byId("officeUnavailableReason").addEventListener("input", markDirty);
    byId("officeAvailableAgain").addEventListener("change", markDirty);
    byId("saveOfficeAvailabilityBtn").addEventListener("click", function () { saveAvailability(false); });
    byId("officeDiscardBtn").addEventListener("click", async function () {
        if (await AppDialog.confirm({
            title: "Discard your changes?",
            message: "Your hours go back to what was last saved.",
            confirmLabel: "Discard changes",
            danger: true,
        })) {
            markClean();
            await loadAvailability();
            toast("Changes discarded.");
        }
    });
    window.addEventListener("beforeunload", function (event) {
        if (state.weekDirty) {
            event.preventDefault();
            event.returnValue = "";
        }
    });

    byId("officeConflictList").addEventListener("click", function (event) {
        const button = event.target.closest("[data-conflict-appointment]");
        if (button) {
            closeSheets([byId("officeConflictDialog")]);
            openAppointment(Number(button.dataset.conflictAppointment));
        }
    });
    byId("confirmOfficeConflictBtn").addEventListener("click", function () {
        closeSheets([byId("officeConflictDialog")]);
        saveAvailability(true);
    });

    // Pause
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
            closeSheets([byId("officePauseDialog")]);
            toast(data.message || "Visits paused.");
        } catch (error) {
            showError("officePauseError", error.message || "Could not reach the server.");
        } finally {
            button.disabled = false;
        }
    });

    // Temporary changes
    byId("addOfficeExceptionBtn").addEventListener("click", function () {
        byId("officeExceptionForm").reset();
        const minimum = toInputDate(new Date());
        byId("officeExceptionStart").min = minimum;
        byId("officeExceptionEnd").min = minimum;
        const start = new Date(Date.now() + 60 * 60 * 1000);
        start.setMinutes(0, 0, 0);
        const end = new Date(start.getTime() + 60 * 60 * 1000);
        byId("officeExceptionStart").value = toInputDate(start);
        byId("officeExceptionEnd").value = toInputDate(end);
        byId("officeExceptionCapacityField").hidden = true;
        showError("officeExceptionError", "");
        openSheet(byId("officeExceptionDialog"));
    });
    byId("officeExceptionForm").addEventListener("change", function (event) {
        if (event.target.name === "officeExceptionType") {
            byId("officeExceptionCapacityField").hidden = event.target.value !== "available";
        }
    });
    byId("officeExceptionForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        showError("officeExceptionError", "");
        const button = byId("saveOfficeExceptionBtn");
        const typeInput = byId("officeExceptionForm").querySelector('input[name="officeExceptionType"]:checked');
        const type = typeInput ? typeInput.value : "closed";
        button.disabled = true;
        try {
            const data = await postJson("office_availability.php", {
                action: "add_exception",
                starts_at: byId("officeExceptionStart").value,
                ends_at: byId("officeExceptionEnd").value,
                is_available: type === "available",
                capacity_override: type === "available" ? byId("officeExceptionCapacity").value : null,
                reason: byId("officeExceptionReason").value.trim(),
            });
            if (!data || !data.success) {
                throw new Error((data && data.message) || "Could not add the change.");
            }
            renderAvailability(data, true);
            closeSheets([byId("officeExceptionDialog")]);
            toast(data.message || "Change added.");
        } catch (error) {
            showError("officeExceptionError", error.message || "Could not reach the server.");
        } finally {
            button.disabled = false;
        }
    });
    byId("officeExceptionList").addEventListener("click", async function (event) {
        const button = event.target.closest("button[data-exception-id]");
        if (!button || !(await AppDialog.confirm({
            title: "Remove this temporary change?",
            message: "The weekly hours apply again for that time.",
            confirmLabel: "Remove change",
            danger: true,
        }))) {
            return;
        }
        button.disabled = true;
        const data = await postJson("office_availability.php", { action: "delete_exception", exception_id: Number(button.dataset.exceptionId) });
        if (data && data.success) {
            renderAvailability(data, true);
            toast(data.message || "Change removed.");
        } else {
            toast((data && data.message) || "Could not remove the change.", "error");
            button.disabled = false;
        }
    });

    // Notifications
    function openNotifications() {
        closeProfileMenu();
        renderNotifications();
        openSheet(byId("officeNotesDialog"));
    }

    byId("officeNotificationBtn").addEventListener("click", openNotifications);
    byId("officeMenuNotes").addEventListener("click", openNotifications);
    byId("markOfficeNotificationsReadBtn").addEventListener("click", async function () {
        const data = await postJson("office_notifications.php", { action: "mark_all_read" });
        if (data && data.success) {
            state.unread = 0;
            state.notifications.forEach(function (item) { item.read_at = item.read_at || new Date().toISOString(); });
            renderNotifications();
            renderHelpAlerts();
            renderToday();
        }
    });
    byId("officeNotificationList").addEventListener("click", async function (event) {
        const button = event.target.closest("button[data-notification-id]");
        if (!button) {
            return;
        }
        // A visitor who can't find the office stays on every page until someone taps
        // "Someone is going", so opening that notification doesn't mark it read.
        if (!button.classList.contains("is-help")) {
            await postJson("office_notifications.php", { action: "mark_read", notification_id: Number(button.dataset.notificationId) });
        }
        if (button.dataset.notificationAppointment) {
            closeSheets([byId("officeNotesDialog")]);
            openAppointment(Number(button.dataset.notificationAppointment));
        }
        loadOfficeData(true);
    });

    // Profile and menu
    byId("officeProfileBtn").addEventListener("click", function () {
        if (phoneQuery.matches) {
            switchView("menu", { history: "push" });
            return;
        }
        const willOpen = byId("officeProfileMenu").hidden;
        byId("officeProfileMenu").hidden = !willOpen;
        byId("officeProfileBtn").setAttribute("aria-expanded", String(willOpen));
    });
    byId("editOfficeProfileBtn").addEventListener("click", openProfileEditor);
    byId("officeMenuProfile").addEventListener("click", openProfileEditor);
    // On phones the account button opens this Menu, so Account security lives here too.
    byId("officeMenuSecurity").addEventListener("click", function () {
        if (window.PhoneTrackerSecurity) {
            window.PhoneTrackerSecurity.open();
        }
    });
    byId("officeMenuRefresh").addEventListener("click", async function () {
        const button = byId("officeMenuRefresh");
        button.disabled = true;
        const results = await Promise.all([loadOfficeData(false), refreshStatus()]);
        button.disabled = false;
        if (results[0]) {
            toast("Updated.");
        }
    });
    byId("officeLogoutBtn").addEventListener("click", signOut);
    byId("officeMenuSignOut").addEventListener("click", signOut);
    byId("closeOfficeProfileBtn").addEventListener("click", closeProfileEditor);
    byId("cancelOfficeProfileBtn").addEventListener("click", closeProfileEditor);
    byId("officeProfileDialog").addEventListener("click", function (event) {
        if (event.target === byId("officeProfileDialog")) {
            closeProfileEditor();
        }
    });
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

    // ---------- Start ----------

    renderProfile();
    renderToday();
    renderRequests();
    renderVisits();
    switchView(viewFromHash(), { history: "replace" });
    loadOfficeData(false);
    refreshStatus();
    window.setInterval(function () { loadOfficeData(true); }, 30000);
    window.setInterval(refreshStatus, 60000);
    window.addEventListener("focus", function () {
        loadOfficeData(true);
        refreshStatus();
    });
    document.addEventListener("visibilitychange", function () {
        if (!document.hidden) {
            loadOfficeData(true);
            refreshStatus();
        }
    });
})();
