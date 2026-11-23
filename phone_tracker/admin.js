/*
 * The Admin dashboard (admin.html), redesigned 2026-11-22: Home (one search, Right now, Needs your
 * attention, offices, the last 7 days, latest activity), Offices, People, Activity, Reports, the
 * Campus map and a Menu, in the same look as the Office Personnel and Security pages. On phones
 * the menu is a bar at the bottom and details open as sheets from the bottom; on laptops a side
 * menu and panels from the right.
 *
 * Also: closing the whole campus (admin_campus_closure.php), the guided office setup, automatic
 * request reminders and the morning summary email (admin_settings.php), adding several staff at
 * once with printable password slips, checks before risky account changes, the printable report,
 * and keyboard shortcuts on a laptop. Server parts: admin_overview.php, admin_visits.php,
 * admin_search.php, admin_report.php, admin_remind_office.php, admin_office_pin.php.
 */
(function () {
    "use strict";

    if (!PhoneTrackerAuth.requireRole(["admin"])) {
        return;
    }

    const UI = window.PhoneTrackerAuthUI;
    const Security = window.PhoneTrackerSecurity;
    const VIEWS = ["home", "offices", "people", "activity", "reports", "campus", "menu"];
    const OLD_VIEWS = { dashboard: "home", users: "people", security: "activity", analytics: "reports" };
    const SHORTCUT_VIEWS = ["home", "offices", "people", "activity", "reports", "campus"];
    const FLOORS = ["Ground floor", "2nd floor", "3rd floor", "4th floor", "5th floor"];
    const SETUP_STEPS = [["hours", "Hours"], ["staff", "Staff"], ["pin", "Map pin"], ["route", "Route"], ["inside", "Inside"]];
    const CLOSE_REASONS = ["Class suspension", "Holiday", "Bad weather", "Campus event"];
    const CLOSE_MESSAGE = "Classes and office work are suspended. Please choose another day for your visit.";
    const ROLE_LABELS = { admin: "Admin", security: "Security", offices: "Office Personnel", visitor: "Visitor" };
    const STATE_CHIPS = {
        open: ["Open", "is-green"],
        break: ["On break", "is-amber"],
        paused: ["Paused", "is-amber"],
        closed: ["Closed now", ""],
        not_accepting: ["Not accepting", "is-red"],
        nostaff: ["No staff", "is-red"],
        archived: ["Archived", ""],
    };

    const S = {
        view: "",
        me: { id: PhoneTrackerAuth.getUserId(), name: PhoneTrackerAuth.getDisplayName() || "Administrator", username: PhoneTrackerAuth.getUsername() || "" },
        overview: null,
        users: [],
        usersLoaded: false,
        attAll: false,
        officeFilter: "",
        people: { tab: "staff", filter: "", q: "", shown: 40 },
        activity: { cat: "all", days: 7, userId: 0, userName: "", entries: [], nextBefore: null, loaded: false, request: 0 },
        security: null,
        report: { period: "month", data: null, request: 0 },
        routes: { office: "", data: null, map: null, layer: null, campusLayer: null, campus: null, campusStale: false, fittedKey: "" },
        searchTimer: 0,
        searchRequest: 0,
        toastTimer: 0,
        updatedAt: null,
    };

    /* ---------- Small helpers ---------- */

    const $ = function (selector, root) { return (root || document).querySelector(selector); };
    const $$ = function (selector, root) { return Array.from((root || document).querySelectorAll(selector)); };
    const byId = function (id) { return document.getElementById(id); };

    function esc(value) {
        return String(value == null ? "" : value).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
    }

    function icon(paths, className) {
        return '<svg' + (className ? ' class="' + className + '"' : "") + ' viewBox="0 0 24 24" aria-hidden="true">' + paths + "</svg>";
    }

    const ICON = {
        x: icon('<path d="M6 6l12 12M18 6 6 18"/>'),
        chev: icon('<path d="m9 6 6 6-6 6"/>', "ofc-chev"),
        people: icon('<path d="M15 20v-1.5a4 4 0 0 0-4-4H6.5a4 4 0 0 0-4 4V20"/><circle cx="8.8" cy="7.5" r="3.6"/><path d="M19 8v6M16 11h6"/>'),
        lock: icon('<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>'),
        clock: icon('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'),
        shield: icon('<path d="M12 3 5 6v5c0 4.5 3 8.4 7 9.5 4-1.1 7-5 7-9.5V6l-7-3Z"/><path d="M12 9v4M12 16v.5"/>'),
        map: icon('<path d="M12 22s7-4.5 7-12a7 7 0 1 0-14 0c0 7.5 7 12 7 12Z"/><circle cx="12" cy="10" r="2.5"/>'),
        key: icon('<circle cx="8" cy="15" r="4"/><path d="m11 12 8-8M16 7l3 3"/>'),
        trend: icon('<path d="M3 17 9 11l4 4 8-8M15 7h6v6"/>'),
        warn: icon('<path d="M12 3 2 20h20zM12 10v4M12 17v.5"/>'),
        office: icon('<path d="M4 21V7l8-4 8 4v14M9 21v-5h6v5"/>'),
        user: icon('<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>'),
        check: icon('<path d="m5 12.5 4.5 4.5L19 7.5"/>'),
        dot: icon('<path d="M12 8v5M12 16v.5"/>'),
        calendar: icon('<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M9.5 13.5l5 5M14.5 13.5l-5 5"/>'),
        bell: icon('<path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.6 2H4.4z"/><path d="M10 21h4"/>'),
        file: icon('<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>'),
        table: icon('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M9 10v9M15 10v9"/>'),
        plus: icon('<path d="M12 5v14M5 12h14"/>'),
        print: icon('<path d="M7 9V3h10v6M7 17H4v-7h16v7h-3"/><rect x="7" y="14" width="10" height="7"/>'),
        copy: icon('<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>'),
        mail: icon('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>'),
        download: icon('<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>'),
        upload: icon('<path d="M12 21V9M7 14l5-5 5 5M5 3h14"/>'),
        edit: icon('<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m14 6 4 4"/>'),
        activity: icon('<path d="M3 12h4l3-8 4 16 3-8h4"/>'),
        signout: icon('<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/>'),
        pin: icon('<path d="M12 22s7-4.5 7-12a7 7 0 1 0-14 0c0 7.5 7 12 7 12Z"/><circle cx="12" cy="10" r="2.5"/>'),
        gps: icon('<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="7"/>'),
    };

    function initials(name) {
        return String(name || "?").split(/\s+/).filter(Boolean).slice(0, 2).map(function (part) { return part.charAt(0); }).join("").toUpperCase() || "?";
    }

    function plural(count, one, many) {
        return Number(count || 0).toLocaleString() + " " + (Number(count) === 1 ? one : many);
    }

    function firstName(name) {
        return String(name || "").trim().split(/\s+/)[0] || "them";
    }

    function parseDate(value) {
        if (!value) {
            return null;
        }
        const date = new Date(String(value).replace(" ", "T"));
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function sameDay(a, b) {
        return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    function timeText(value) {
        const date = parseDate(value);
        return date ? date.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" }) : "";
    }

    /** "10:12 AM", "Yesterday, 5:48 PM", "Thu, 3:10 PM", "Nov 2, 9:00 AM". */
    function whenText(value) {
        const date = parseDate(value);
        if (!date) {
            return "";
        }
        const now = new Date();
        const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
        const tomorrow = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1);
        const time = timeText(value);
        if (sameDay(date, now)) {
            return time;
        }
        if (sameDay(date, yesterday)) {
            return "Yesterday, " + time;
        }
        if (sameDay(date, tomorrow)) {
            return "Tomorrow, " + time;
        }
        const days = Math.abs(date - now) / 86400000;
        if (days < 6) {
            return date.toLocaleDateString([], { weekday: "short" }) + ", " + time;
        }
        return date.toLocaleDateString([], { month: "short", day: "numeric", year: date.getFullYear() === now.getFullYear() ? undefined : "numeric" }) + ", " + time;
    }

    function dayText(value) {
        const date = parseDate(value);
        if (!date) {
            return "";
        }
        return date.toLocaleDateString([], { weekday: "short", month: "short", day: "numeric", year: date.getFullYear() === new Date().getFullYear() ? undefined : "numeric" });
    }

    function lastSeenText(user) {
        if (!user.is_active) {
            return "Turned off";
        }
        if (user.online) {
            return "Active now";
        }
        if (user.last_seen_at) {
            return "Active " + whenText(user.last_seen_at).replace(/^Yesterday/, "yesterday");
        }
        return user.last_login_at ? "Signed in " + whenText(user.last_login_at) : "Never signed in";
    }

    function durationText(minutes) {
        const value = Math.max(0, Math.round(Number(minutes || 0)));
        if (value < 60) {
            return value + " min";
        }
        const hours = Math.floor(value / 60);
        const rest = value % 60;
        return hours >= 10 ? Math.round(value / 60) + " h" : hours + " h" + (rest ? " " + rest + " min" : "");
    }

    function showMessage(id, message) {
        const element = byId(id);
        if (element) {
            element.textContent = message || "";
            element.hidden = !message;
        }
    }

    /**
     * A short message at the bottom. Shown as a popover where the browser has them, so it also
     * shows above an open sheet (sheets sit in the browser's top layer).
     */
    function toast(message, stateName) {
        const element = byId("admToast");
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
        window.clearTimeout(S.toastTimer);
        S.toastTimer = window.setTimeout(function () {
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

    const NO_CONNECTION = "Couldn't reach the server. Check your connection and try again.";

    /** GET or POST JSON. A session that ended sends the page to the sign-in page (auth.js). */
    async function api(url, body, options) {
        const settings = options || {};
        const run = async function () {
            const init = { credentials: "same-origin", cache: "no-store" };
            if (body !== undefined && body !== null) {
                init.method = "POST";
                init.headers = { "Content-Type": "application/json" };
                init.body = JSON.stringify(body);
            }
            let response;
            try {
                response = await fetch(url, init);
            } catch (error) {
                return { success: false, message: NO_CONNECTION, offline: true };
            }
            let data = null;
            try {
                data = await response.json();
            } catch (error) {
                return { success: false, message: "The server sent an answer this page can't read. Refresh and try again." };
            }
            if (response.status === 401 || (data && data.message === "Not authenticated")) {
                window.location.href = "login.html";
                return { success: false, cancelled: true, message: "" };
            }
            return data || { success: false, message: "Something went wrong. Please try again." };
        };
        return settings.stepUp ? Security.withStepUp(run) : run();
    }

    /** Runs a change and reports a failure in a dialog (not when the step-up was cancelled). */
    async function act(url, body, failTitle, options) {
        const data = await api(url, body, options);
        if (!data.success && !data.cancelled) {
            await AppDialog.alert({ title: failTitle, message: data.message || "Please try again." });
        }
        return data;
    }

    function setBusy(button, busy, label) {
        if (!button) {
            return;
        }
        if (busy) {
            button.dataset.label = button.innerHTML;
            button.disabled = true;
            if (label) {
                button.textContent = label;
            }
        } else {
            button.disabled = false;
            if (button.dataset.label) {
                button.innerHTML = button.dataset.label;
                delete button.dataset.label;
            }
        }
    }

    /* ---------- Sheets: the phone's Back button closes them first ---------- */

    const sheets = [];
    let ignorePops = 0;
    let settleTimer = 0;
    const afterPops = [];

    /*
     * Closing a sheet goes back in the history, which the browser does a moment later. A new
     * history entry (another sheet, another page) made before that would be the one taken back,
     * and a later Back would then leave the dashboard, so it waits until the back step is done.
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

    function goBack(steps) {
        ignorePops += 1;
        window.history.go(-steps);
    }

    function trackSheet(dialog) {
        if (dialog.dataset.tracked) {
            return;
        }
        dialog.dataset.tracked = "1";
        dialog.addEventListener("close", function () {
            const index = sheets.indexOf(dialog);
            if (index !== -1) {
                sheets.splice(index, 1);
            }
            const quiet = dialog.dataset.quiet === "1" || dialog.dataset.fromPop === "1";
            delete dialog.dataset.quiet;
            delete dialog.dataset.fromPop;
            if (!quiet && index !== -1) {
                goBack(1);
            }
            if (typeof dialog.onSheetClose === "function") {
                dialog.onSheetClose();
            }
            if (dialog.dataset.temporary) {
                window.setTimeout(function () { dialog.remove(); }, 0);
            }
        });
        dialog.addEventListener("click", function (event) {
            if (event.target === dialog || event.target.closest("[data-close-sheet]")) {
                dialog.close();
            }
        });
    }

    function showSheet(dialog) {
        trackSheet(dialog);
        if (dialog.open) {
            return;
        }
        dialog.showModal();
        sheets.push(dialog);
        whenSettled(function () {
            window.history.pushState({ admSheet: dialog.id || "sheet" }, "", window.location.href);
        });
    }

    /**
     * Builds and opens a sheet. o: title (HTML allowed: escape it), sub, head (extra HTML under the
     * title), body, foot, drawer (from the right on laptops), wide, onClose, init(dialog).
     * Returns the dialog; sheet.set({...}) redraws its parts in place.
     */
    function openSheet(o) {
        const dialog = document.createElement("dialog");
        dialog.className = "ofc-sheet adm-sheet" + (o.drawer ? " is-drawer" : "") + (o.wide ? " adm-wide-sheet" : "");
        dialog.dataset.temporary = "1";
        const titleId = "admSheetTitle" + Date.now() + Math.floor(Math.random() * 1000);
        dialog.setAttribute("aria-labelledby", titleId);
        dialog.innerHTML = '<header class="ofc-sheet-head"><div class="adm-sheet-headtext"></div>'
            + '<button class="ofc-x" type="button" data-close-sheet aria-label="Close">' + ICON.x + "</button></header>"
            + '<div class="ofc-sheet-body"></div><footer class="ofc-sheet-foot"></footer>';
        dialog.set = function (parts) {
            if ("title" in parts || "sub" in parts || "head" in parts) {
                o = Object.assign(o, parts);
                $(".adm-sheet-headtext", dialog).innerHTML = '<h2 id="' + titleId + '">' + (o.title || "") + "</h2>"
                    + (o.sub ? '<p class="ofc-note">' + o.sub + "</p>" : "") + (o.head || "");
            }
            if ("body" in parts) {
                const bodyHost = $(".ofc-sheet-body", dialog);
                bodyHost.innerHTML = parts.body || "";
                bodyHost.scrollTop = 0;
            }
            if ("foot" in parts) {
                const foot = $(".ofc-sheet-foot", dialog);
                foot.innerHTML = parts.foot || "";
                foot.hidden = !parts.foot;
            }
        };
        dialog.set({ title: o.title, sub: o.sub, head: o.head, body: o.body, foot: o.foot });
        if (o.onClose) {
            dialog.onSheetClose = o.onClose;
        }
        document.body.appendChild(dialog);
        showSheet(dialog);
        if (o.init) {
            o.init(dialog);
        }
        return dialog;
    }

    /** After an action: closes these sheets (default: all) with one step back in the history. */
    function closeSheets(list) {
        const open = (list || sheets.slice()).filter(function (dialog) { return dialog && dialog.open && sheets.indexOf(dialog) !== -1; });
        open.forEach(function (dialog) {
            dialog.dataset.quiet = "1";
            dialog.close();
        });
        if (open.length) {
            goBack(open.length);
        }
    }

    function topSheet() {
        return sheets[sheets.length - 1] || null;
    }

    function sheetError(dialog, message) {
        let element = $(".adm-sheet-error", dialog);
        if (!element) {
            element = document.createElement("p");
            element.className = "admin-message is-error adm-sheet-error";
            element.setAttribute("role", "alert");
            $(".ofc-sheet-body", dialog).appendChild(element);
        }
        element.textContent = message || "";
        element.hidden = !message;
        if (message) {
            element.scrollIntoView({ block: "nearest" });
        }
    }

    /* ---------- Pages ---------- */

    function viewFromHash() {
        const hash = window.location.hash.replace("#", "");
        if (VIEWS.indexOf(hash) !== -1) {
            return hash;
        }
        return OLD_VIEWS[hash] || "home";
    }

    function urlFor(view) {
        return window.location.pathname + window.location.search + (view === "home" ? "" : "#" + view);
    }

    function go(view, options) {
        const settings = options || {};
        if (VIEWS.indexOf(view) === -1) {
            view = OLD_VIEWS[view] || "home";
        }
        const changed = view !== S.view;
        S.view = view;
        document.body.dataset.admView = view;
        $$("[data-adm-panel]").forEach(function (panel) {
            panel.hidden = panel.dataset.admPanel !== view;
        });
        $$(".office-sidebar [data-adm-go], .ofc-tabbar [data-adm-go]").forEach(function (button) {
            const active = button.dataset.admGo === view;
            button.classList.toggle("is-active", active);
            if (active) {
                button.setAttribute("aria-current", "page");
            } else {
                button.removeAttribute("aria-current");
            }
        });
        if (settings.history === "push" && changed) {
            whenSettled(function () {
                window.history.pushState({ admView: view }, "", urlFor(view));
            });
        } else if (settings.history === "replace") {
            window.history.replaceState({ admView: view }, "", urlFor(view));
        }
        byId("admResults").hidden = true;
        if (changed) {
            window.scrollTo(0, 0);
        }
        if (view === "activity" && !S.activity.loaded) {
            loadActivity(true);
            loadSecurity();
        }
        if (view === "reports" && !S.report.data) {
            loadReport();
        }
        if (view === "people" && !S.usersLoaded) {
            loadUsers();
        }
        document.dispatchEvent(new CustomEvent("admin:viewchange", { detail: { view: view } }));
    }

    window.addEventListener("popstate", function () {
        if (ignorePops > 0) {
            ignorePops -= 1;
            if (ignorePops === 0) {
                flushSettled();
            }
            return;
        }
        const top = topSheet();
        if (top) {
            top.dataset.fromPop = "1";
            top.close();
            return;
        }
        go(viewFromHash());
    });

    /* ---------- Loading ---------- */

    async function loadOverview() {
        const data = await api("admin_overview.php");
        if (!data.success) {
            if (!data.cancelled) {
                showMessage("admPageError", data.offline ? NO_CONNECTION : (data.message || "Could not load the dashboard."));
            }
            return;
        }
        showMessage("admPageError", "");
        S.overview = data;
        S.updatedAt = new Date();
        byId("admMenuUpdated").textContent = "Updated " + S.updatedAt.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
        renderHome();
        renderOffices();
        renderCampusChecklist();
        renderMenuNotes();
    }

    async function loadUsers() {
        const data = await api("list_users.php");
        if (!data.success || !Array.isArray(data.data)) {
            return;
        }
        S.users = data.data;
        S.usersLoaded = true;
        const me = userById(S.me.id);
        if (me) {
            S.me.name = me.display_name || S.me.name;
            S.me.username = me.username;
            S.me.position = me.position || "System Administrator";
            S.me.email = me.email || "";
        }
        renderMe();
        renderPeople();
    }

    function userById(id) {
        return S.users.find(function (user) { return Number(user.id) === Number(id); }) || null;
    }

    function offices() {
        return S.overview ? S.overview.offices : [];
    }

    function officeByCode(code) {
        return offices().find(function (office) { return office.code === code; }) || null;
    }

    function activeOffices() {
        return offices().filter(function (office) { return office.is_active; });
    }

    function staffOf(code) {
        return S.users.filter(function (user) { return user.role === "offices" && user.office_code === code && Number(user.is_active) === 1; });
    }

    function activeAdmins() {
        return S.users.filter(function (user) { return user.role === "admin" && Number(user.is_active) === 1; });
    }

    function renderMe() {
        $$("[data-my-name]").forEach(function (element) { element.textContent = S.me.name; });
        $$("[data-my-initials]").forEach(function (element) { element.textContent = initials(S.me.name); });
        $$("[data-my-username]").forEach(function (element) { element.textContent = S.me.username; });
        $$("[data-my-position]").forEach(function (element) { element.textContent = S.me.position || "System Administrator"; });
    }

    /* ---------- Home ---------- */

    function attentionItem(item) {
        const actions = (item.actions || []).map(function (action) {
            return '<button class="ofc-btn is-small' + (action[2] ? " is-primary" : "") + '" type="button" data-act="' + esc(action[1]) + '">' + esc(action[0]) + "</button>";
        }).join("");
        return '<li class="adm-aitem is-' + esc(item.severity) + '"><span class="adm-aico">' + (ICON[item.icon] || ICON.warn) + "</span>"
            + "<strong>" + esc(item.title) + "</strong>"
            + "<div><p>" + esc(item.text) + '</p><p class="adm-why">' + esc(item.why) + "</p></div>"
            + (actions ? '<div class="adm-aacts">' + actions + "</div>" : "") + "</li>";
    }

    function attentionList(all) {
        const items = S.overview ? S.overview.attention : [];
        if (!items.length) {
            return '<li class="adm-allclear">' + ICON.check + "<span>All clear. Nothing needs you right now.</span></li>";
        }
        const shown = all || S.attAll ? items : items.slice(0, 3);
        const more = items.length - shown.length;
        return shown.map(attentionItem).join("")
            + (more > 0 ? '<li><button class="ofc-btn is-quiet is-full" type="button" data-att-more>Show ' + more + " more (less urgent)</button></li>" : "");
    }

    function renderHome() {
        const data = S.overview;
        const now = data.right_now;
        byId("admHomeDate").textContent = new Date().toLocaleDateString([], { weekday: "long", month: "long", day: "numeric" })
            + " · " + new Date().toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
        $$("[data-now]").forEach(function (element) {
            element.textContent = Number(now[element.dataset.now] || 0).toLocaleString();
        });
        const change = byId("admVisitsChange");
        if (now.visits_prev_7 > 0) {
            const percent = percentChange(now.visits_7, now.visits_prev_7);
            change.textContent = percent === 0 ? "Same as the 7 days before" : (percent > 0 ? "▲ " : "▼ ") + Math.abs(percent) + "% vs the 7 days before";
            change.className = percent >= 0 ? "is-up" : "is-down";
        } else {
            change.textContent = "";
        }

        const count = data.attention.length;
        byId("admAttention").innerHTML = attentionList(false);
        $$("[data-att-count]").forEach(function (element) { element.textContent = count; element.hidden = !count; });
        $$("[data-att-badge]").forEach(function (element) { element.textContent = count; element.hidden = !count; });
        byId("admTopStatus").textContent = count ? (count === 1 ? "1 thing needs you" : count + " things need you") : "All clear";
        document.body.classList.toggle("is-clear", !count);
        const locks = data.attention.filter(function (item) { return item.id.indexOf("lock-") === 0; }).length;
        $$("[data-lock-badge]").forEach(function (element) { element.textContent = locks; element.hidden = !locks; });

        const rows = activeOffices();
        byId("admHomeOffices").innerHTML = rows.length ? rows.map(officeRow).join("")
            : '<li class="ofc-empty">No offices yet. <button class="ofc-link" type="button" data-open="wizard">Add the first office</button></li>';

        renderWeekChart(data.last_days);
        renderClosureBanner();
    }

    function stateChip(office) {
        const chip = STATE_CHIPS[office.state] || STATE_CHIPS.closed;
        return '<span class="ofc-chip adm-dotchip ' + chip[1] + '"><i></i>' + chip[0] + "</span>";
    }

    function officeRow(office) {
        const waitingClass = office.waiting > 3 ? ' class="is-red"' : "";
        const nums = office.state === "nostaff"
            ? "<span>Visitors can't book it</span>"
            : "<span>Waiting <b" + waitingClass + ">" + office.waiting + "</b>" + (office.waiting && office.oldest_minutes !== null ? " · oldest " + durationText(office.oldest_minutes) : "") + "</span>"
                + "<span>Today <b>" + office.today + "</b></span>"
                + (office.answer_label ? "<span>Answers in <b>" + esc(office.answer_label) + "</b></span>" : "");
        return '<li><button class="adm-orow" type="button" data-office="' + esc(office.code) + '"><strong>' + esc(office.name) + "</strong>"
            + stateChip(office) + '<span class="adm-nums">' + nums + "</span></button></li>";
    }

    /* A bar per day; the bars and their tooltips follow the dataviz rules: one hue, gray weekends. */
    function vbars(host, items, options) {
        const settings = options || {};
        const max = Math.max(1, ...items.map(function (item) { return item.value; }));
        host.innerHTML = '<div class="adm-vbars" role="img" aria-label="' + esc(settings.label || "Visits per day") + '">'
            + items.map(function (item, index) {
                return '<i class="' + (item.weekend ? "is-wk" : "") + (item.future ? " is-future" : "") + '" style="height:' + Math.max(2, item.value / max * 100)
                    + '%" data-tip="' + esc(item.full + ": " + plural(item.value, settings.one || "visit", settings.many || "visits")) + '" data-i="' + index + '" tabindex="-1"></i>';
            }).join("") + "</div>"
            + '<div class="adm-vlabels" aria-hidden="true">' + items.map(function (item) { return "<span>" + esc(item.short) + "</span>"; }).join("") + "</div>";
        if (!host.dataset.tips) {
            host.dataset.tips = "1";
            const show = function (event) {
                const bar = event.target.closest(".adm-vbars i");
                if (!bar) {
                    return;
                }
                let tip = $(".adm-tip", host);
                if (!tip) {
                    tip = document.createElement("div");
                    tip.className = "adm-tip";
                    host.appendChild(tip);
                }
                const hostBox = host.getBoundingClientRect();
                const barBox = bar.getBoundingClientRect();
                tip.textContent = bar.dataset.tip;
                const left = barBox.left - hostBox.left + barBox.width / 2;
                tip.style.left = Math.min(Math.max(left, 60), hostBox.width - 60) + "px";
                tip.style.top = (barBox.top - hostBox.top) + "px";
            };
            host.addEventListener("mouseover", show);
            host.addEventListener("click", show);
            host.addEventListener("mouseleave", function () {
                const tip = $(".adm-tip", host);
                if (tip) {
                    tip.remove();
                }
            });
        }
    }

    function renderWeekChart(days) {
        vbars(byId("admWeekChart"), days.map(function (day) {
            return { short: day.label, full: day.label === "Today" ? "Today" : dayText(day.date), value: day.visits, weekend: day.weekend };
        }), { label: "Visits on each of the last 7 days" });
        const today = days[days.length - 1];
        const total = days.reduce(function (sum, day) { return sum + day.visits; }, 0);
        byId("admWeekNote").textContent = plural(total, "visit", "visits") + " in 7 days. Today so far: " + today.visits + ". Weekends in gray.";
    }

    function closureBox(closure) {
        return '<div class="adm-closure"><span class="adm-aico">' + ICON.calendar + "</span>"
            + "<strong>" + (closure.in_effect ? "Campus closed to visitors now" : "Campus closes to visitors") + ": " + esc(closure.when) + "</strong>"
            + "<div><p>" + esc(closure.reason) + ". " + (closure.cancelled_visits ? plural(closure.cancelled_visits, "booked visit was", "booked visits were") + " cancelled and "
                + (closure.cancelled_visits === 1 ? "the visitor was" : "the visitors were") + " told. " : "")
            + "New requests and walk-ins for that time are refused.</p></div>"
            + '<div class="adm-aacts"><button class="ofc-btn is-small" type="button" data-closure-change="' + closure.id + '">Change</button>'
            + '<button class="ofc-btn is-small is-decline" type="button" data-closure-reopen="' + closure.id + '">Reopen the campus</button></div></div>';
    }

    function renderClosureBanner() {
        const closures = S.overview ? S.overview.closures : [];
        const html = closures.map(closureBox).join("");
        byId("admClosureBanner").innerHTML = html;
        byId("admOfficeClosure").innerHTML = html;
    }

    /* ---------- Offices ---------- */

    function officeCard(office) {
        const staff = office.staff.length
            ? '<div class="adm-staff"><span class="adm-faces">' + office.staff.slice(0, 4).map(function (person) {
                return '<span class="adm-ini is-sm' + (person.online ? " is-online" : "") + '">' + esc(initials(person.name)) + "</span>";
            }).join("") + "</span><span>" + esc(office.staff.map(function (person) { return person.name; }).join(", ")) + "</span></div>"
            : (office.is_active ? '<div class="adm-staff is-none">' + ICON.warn + "No Office Personnel yet</div>" : "");
        const setup = office.is_active
            ? '<div class="adm-setup"><div class="adm-setup-top"><span>Setup</span><b>' + office.setup_done + ' of 5 ready</b></div><div class="adm-segs">'
                + SETUP_STEPS.map(function (step) { return '<i class="' + (office.setup[step[0]] ? "on" : "") + '"></i>'; }).join("")
                + '</div><div class="adm-steps">' + SETUP_STEPS.map(function (step) {
                    return '<span class="' + (office.setup[step[0]] ? "ok" : "no") + '">' + (office.setup[step[0]] ? "✓" : "✗") + " " + step[1] + "</span>";
                }).join("") + "</div></div>"
            : "";
        return '<button class="adm-ocard' + (office.state === "nostaff" ? " is-nostaff" : "") + (office.is_active ? "" : " is-archived") + '" type="button" data-office="' + esc(office.code) + '">'
            + '<div class="adm-ohead"><span><strong>' + esc(office.name) + "</strong><small>" + esc(office.state_line) + "</small></span>" + stateChip(office) + "</div>"
            + staff
            + (office.is_active ? '<div class="adm-ostats"><div><b class="' + (office.waiting > 3 ? "is-red" : "") + '">' + office.waiting + "</b><span>Waiting"
                + (office.waiting && office.oldest_minutes !== null ? " · " + durationText(office.oldest_minutes) : "") + "</span></div>"
                + "<div><b>" + office.today + "</b><span>Visits today</span></div>"
                + "<div><b>" + (office.answer_label ? esc(office.answer_label) : "–") + "</b><span>To answer</span></div></div>" : "")
            + setup + "</button>";
    }

    function officeMatches(office, filter) {
        switch (filter) {
            case "setup":
                return office.is_active && office.setup_done < 5;
            case "nostaff":
                return office.state === "nostaff";
            case "waiting":
                return office.is_active && office.waiting > 0;
            case "archived":
                return !office.is_active;
            default:
                return office.is_active;
        }
    }

    function renderOffices() {
        const all = offices();
        const counts = {};
        ["", "setup", "nostaff", "waiting", "archived"].forEach(function (key) {
            counts[key] = all.filter(function (office) { return officeMatches(office, key); }).length;
        });
        if (S.officeFilter && !counts[S.officeFilter]) {
            S.officeFilter = "";
        }
        const chips = [["", "All"], ["setup", "Setup not finished"], ["nostaff", "No staff"], ["waiting", "Requests waiting"], ["archived", "Archived"]];
        byId("admOfficeChips").innerHTML = chips.filter(function (chip) { return chip[0] === "" || counts[chip[0]] > 0; }).map(function (chip) {
            return '<button type="button" data-office-filter="' + chip[0] + '" class="' + (S.officeFilter === chip[0] ? "is-active" : "") + '" aria-pressed="' + (S.officeFilter === chip[0]) + '">'
                + chip[1] + " " + counts[chip[0]] + "</button>";
        }).join("");
        const rows = all.filter(function (office) { return officeMatches(office, S.officeFilter); });
        byId("admOfficeGrid").innerHTML = rows.length ? rows.map(officeCard).join("")
            : '<p class="ofc-empty">' + (all.length ? "No office matches." : "No offices yet. Add the first one with Add office.") + "</p>";
        renderReminderCard();
    }

    function renderReminderCard() {
        const settings = S.overview ? S.overview.reminders : null;
        if (!settings) {
            return;
        }
        const on = settings.on;
        byId("admRemCard").innerHTML = '<section class="ofc-card adm-rem-card' + (on ? "" : " is-off") + '"><span class="adm-aico">' + ICON.bell + "</span>"
            + "<span><strong>Automatic reminders · " + (on ? "On" : "Off") + '</strong><small class="ofc-note">' + (on
                ? "Answer within " + durationText(settings.target_minutes).replace("9 h", "1 working day") + " of working time. "
                    + (settings.at_target ? "Offices are reminded when they pass it" : "") + (settings.at_target && settings.before_expiry ? ", and " : "")
                    + (settings.before_expiry ? (settings.at_target ? "again " : "Offices are reminded ") + "1 hour before a request expires" : "") + "."
                : "Offices are reminded only when you tap Remind the office.") + "</small></span>"
            + '<button class="ofc-btn is-small" type="button" data-open="reminders">' + (on ? "Change" : "Turn on") + "</button></section>";
    }

    /* ---------- People ---------- */

    function userFlags(user) {
        const flags = [];
        if (Number(user.is_active) !== 1) {
            flags.push('<span class="ofc-chip">Turned off</span>');
        }
        if (user.locked) {
            flags.push('<span class="ofc-chip is-red">Locked</span>');
        }
        if (user.role !== "visitor") {
            if (user.temp_password_expired) {
                flags.push('<span class="ofc-chip is-red">Temporary password expired</span>');
            } else if (user.must_change_password && user.temp_password_expires_at) {
                flags.push('<span class="ofc-chip is-amber">Temporary password</span>');
            }
            if (!user.two_factor_enabled && Number(user.is_active) === 1) {
                flags.push('<span class="ofc-chip">2-step off</span>');
            }
            if (!user.has_name) {
                flags.push('<span class="ofc-chip is-amber">No name on file</span>');
            }
        }
        return flags.join("");
    }

    function needsAttention(user) {
        return Number(user.is_active) === 1 && (user.locked || user.temp_password_expired || (user.must_change_password && user.temp_password_expires_at) || !user.two_factor_enabled || !user.has_name);
    }

    function personRow(user) {
        const me = Number(user.id) === Number(S.me.id);
        const where = user.role === "visitor" ? (user.email || user.username)
            : (user.role === "security" || !user.department ? ROLE_LABELS[user.role] : ROLE_LABELS[user.role] + " · " + user.department);
        return '<li><button class="adm-prow" type="button" data-person="' + user.id + '"><span class="adm-ini' + (user.online ? " is-online" : "") + '">' + esc(initials(user.display_name || user.username)) + "</span>"
            + "<span><strong>" + esc(user.display_name || user.username) + (me ? ' <span class="ofc-chip is-blue">You</span>' : "") + "</strong>"
            + "<small>" + esc(where) + " · " + esc(user.role === "visitor" ? "joined " + dayText(user.created_at) : lastSeenText(user)) + "</small>"
            + '<span class="adm-flags">' + userFlags(user) + "</span></span>" + ICON.chev + "</button></li>";
    }

    function staffFilter(user, filter) {
        switch (filter) {
            case "attention":
                return needsAttention(user);
            case "twostep":
                return Number(user.is_active) === 1 && !user.two_factor_enabled;
            case "off":
                return Number(user.is_active) !== 1;
            case "":
                return true;
            default:
                return user.role === filter;
        }
    }

    function renderPeople() {
        if (!S.usersLoaded) {
            return;
        }
        const staff = S.users.filter(function (user) { return user.role !== "visitor"; });
        const visitors = S.users.filter(function (user) { return user.role === "visitor"; });
        $$("[data-people-count]").forEach(function (element) {
            element.textContent = (element.dataset.peopleCount === "staff" ? staff : visitors).length;
        });
        $$("[data-ptab]").forEach(function (button) {
            const active = button.dataset.ptab === S.people.tab;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-selected", String(active));
        });
        const chipsHost = byId("admPeopleChips");
        chipsHost.hidden = S.people.tab !== "staff";
        if (S.people.tab === "staff") {
            const chips = [["", "Everyone"], ["attention", "Needs attention"], ["offices", "Office Personnel"], ["security", "Security"], ["admin", "Admin"], ["twostep", "2-step off"], ["off", "Turned off"]];
            chipsHost.innerHTML = chips.map(function (chip) {
                const count = staff.filter(function (user) { return staffFilter(user, chip[0]); }).length;
                if (chip[0] && !count) {
                    return "";
                }
                return '<button type="button" data-people-filter="' + chip[0] + '" class="' + (S.people.filter === chip[0] ? "is-active" : "") + '">' + chip[1] + (chip[0] ? " " + count : "") + "</button>";
            }).join("");
        }
        const query = S.people.q.toLowerCase();
        const list = (S.people.tab === "staff" ? staff : visitors).filter(function (user) {
            const haystack = [user.display_name, user.username, user.email, user.department, user.position, ROLE_LABELS[user.role]].join(" ").toLowerCase();
            return (!query || haystack.indexOf(query) !== -1) && (S.people.tab !== "staff" || staffFilter(user, S.people.filter));
        });
        if (S.people.tab === "visitors") {
            list.sort(function (a, b) { return String(b.created_at).localeCompare(String(a.created_at)); });
        }
        const shown = list.slice(0, S.people.shown);
        byId("admPeopleList").innerHTML = shown.length ? shown.map(personRow).join("")
            : '<li class="ofc-empty">' + (query ? "No one matches \"" + esc(S.people.q) + "\"." : "No one here yet.") + "</li>";
        const more = byId("admPeopleMore");
        more.hidden = list.length <= shown.length;
        more.textContent = "Show more (" + (list.length - shown.length) + " left)";
    }

    /* ---------- Activity ---------- */

    function activityRange() {
        const to = new Date();
        const from = new Date(to.getFullYear(), to.getMonth(), to.getDate() - (S.activity.days - 1));
        const iso = function (date) {
            return date.getFullYear() + "-" + String(date.getMonth() + 1).padStart(2, "0") + "-" + String(date.getDate()).padStart(2, "0");
        };
        return { from: iso(from), to: iso(to) };
    }

    function activityParams(extra) {
        const range = activityRange();
        const params = new URLSearchParams({ from: range.from, to: range.to, category: S.activity.cat });
        if (S.activity.userId) {
            params.set("user_id", String(S.activity.userId));
        }
        Object.keys(extra || {}).forEach(function (key) { params.set(key, extra[key]); });
        return params;
    }

    function lowerFirst(text) {
        return text ? text.charAt(0).toLowerCase() + text.slice(1) : "";
    }

    function activityItem(entry) {
        const actor = entry.actor;
        const sentence = entry.text || entry.title;
        const body = actor ? "<b>" + esc(actor.name) + "</b> " + esc(lowerFirst(sentence)) : esc(sentence);
        const system = !actor && entry.category === "system";
        const sub = [entry.title !== sentence ? entry.title : "", actor ? actor.department : (system ? "Automatic" : "Account removed"), entry.device].filter(Boolean).join(" · ");
        const alert = entry.tone === "danger";
        const tag = entry.appointment_id ? "button" : "div";
        return '<li><' + tag + ' class="adm-titem' + (alert ? " is-alert" : "") + '"' + (entry.appointment_id ? ' type="button" data-visit="' + entry.appointment_id + '"' : "") + ">"
            + '<span class="adm-ini' + (actor ? "" : " is-sys") + '">' + (actor ? esc(initials(actor.name)) : (system ? "SYS" : "–")) + "</span>"
            + "<p>" + body + "</p><time>" + esc(timeText(entry.created_at)) + "</time><small>" + esc(sub) + "</small></" + tag + "></li>";
    }

    function dayHeading(value) {
        const date = parseDate(value);
        const now = new Date();
        const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
        if (sameDay(date, now)) {
            return "Today";
        }
        if (sameDay(date, yesterday)) {
            return "Yesterday";
        }
        return date.toLocaleDateString([], { weekday: "long", month: "short", day: "numeric" });
    }

    function renderTimeline() {
        const entries = S.activity.entries;
        const host = byId("admTimeline");
        if (!entries.length) {
            host.innerHTML = '<p class="ofc-empty">Nothing of this kind in this time.</p>';
        } else {
            let html = "";
            let day = "";
            let open = false;
            entries.forEach(function (entry) {
                const heading = dayHeading(entry.created_at);
                if (heading !== day) {
                    if (open) {
                        html += "</ul>";
                    }
                    html += '<p class="adm-tday">' + esc(heading) + '</p><ul class="adm-tlist">';
                    open = true;
                    day = heading;
                }
                html += activityItem(entry);
            });
            host.innerHTML = html + (open ? "</ul>" : "");
        }
        byId("admActMore").hidden = !S.activity.nextBefore;
        $$("[data-act-cat]").forEach(function (button) {
            button.classList.toggle("is-active", button.dataset.actCat === S.activity.cat);
        });
        const person = byId("admActPerson");
        person.hidden = !S.activity.userId;
        person.innerHTML = S.activity.userId ? '<span class="ofc-chip is-blue">Showing ' + esc(S.activity.userName) + '</span><button class="ofc-link" type="button" data-act-clear-person>Show everyone</button>' : "";
    }

    async function loadActivity(reset) {
        const request = ++S.activity.request;
        const params = activityParams({ limit: "40" });
        if (!reset && S.activity.nextBefore) {
            params.set("before_id", String(S.activity.nextBefore));
        }
        if (reset) {
            byId("admTimeline").innerHTML = '<p class="ofc-empty">Loading…</p>';
        }
        const data = await api("admin_activity.php?" + params.toString());
        if (request !== S.activity.request) {
            return;
        }
        if (!data.success) {
            byId("admTimeline").innerHTML = '<p class="ofc-empty">' + esc(data.message || "Could not load the activity.") + "</p>";
            return;
        }
        S.activity.loaded = true;
        S.activity.entries = reset ? data.entries : S.activity.entries.concat(data.entries);
        S.activity.nextBefore = data.next_before_id || null;
        renderTimeline();
    }

    async function loadHomeActivity() {
        const today = new Date();
        const from = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 6);
        const iso = function (date) {
            return date.getFullYear() + "-" + String(date.getMonth() + 1).padStart(2, "0") + "-" + String(date.getDate()).padStart(2, "0");
        };
        const data = await api("admin_activity.php?" + new URLSearchParams({ from: iso(from), to: iso(today), limit: "6" }).toString());
        const host = byId("admHomeActivity");
        if (!data.success) {
            host.innerHTML = '<li class="ofc-empty">' + esc(data.message || "Could not load the activity.") + "</li>";
            return;
        }
        host.innerHTML = data.entries.length ? data.entries.map(activityItem).join("") : '<li class="ofc-empty">Nothing happened in the last 7 days.</li>';
    }

    async function loadSecurity() {
        const data = await api("admin_security.php");
        if (!data.success) {
            return;
        }
        S.security = data;
        renderSecurity();
    }

    function renderSecurity() {
        const data = S.security;
        const stats = data.stats || {};
        const locked = (data.lockouts || []).length;
        const chip = byId("admSigninChip");
        chip.className = "ofc-chip adm-dotchip " + (locked ? "is-red" : "is-green");
        chip.innerHTML = "<i></i>" + (locked ? plural(locked, "lock", "locks") + " now" : "No locks");
        byId("admSecStats").innerHTML = [
            [stats.failed_24h || 0, "Failed sign-ins (24 h)", stats.failed_24h > 5],
            [locked, "Locked right now", locked > 0],
            [(stats.staff_two_factor || 0) + " of " + (stats.staff_total || 0), "Staff with 2-step", false],
            [stats.must_change || 0, "Must change password", false],
        ].map(function (cell) {
            return "<div><b" + (cell[2] ? ' class="is-red"' : "") + ">" + esc(cell[0]) + "</b><span>" + esc(cell[1]) + "</span></div>";
        }).join("");
        byId("admLockList").innerHTML = (data.lockouts || []).map(function (lock) {
            const who = lock.display_name || lock.identifier || "Any account";
            const where = lock.scope === "account" ? "from several networks" : "from " + UI.formatIp(lock.ip);
            return '<li class="adm-aitem is-red adm-flat"><span class="adm-aico">' + ICON.lock + "</span>"
                + "<strong>" + esc(who) + " · locked at " + esc(timeText(lock.created_at)) + "</strong>"
                + "<div><p>" + esc(plural(lock.failure_count, "wrong password", "wrong passwords") + " " + where + ". Unlocks by itself at " + timeText(lock.locked_until) + ".") + "</p></div>"
                + '<div class="adm-aacts"><button class="ofc-btn is-small is-primary" type="button" data-unlock-lock="' + lock.id + '">Unlock now</button>'
                + (lock.user_id ? '<button class="ofc-btn is-small" type="button" data-person="' + lock.user_id + '">See ' + esc(firstName(who)) + "</button>" : "") + "</div></li>";
        }).join("");
        byId("admAttemptList").innerHTML = (data.attempts || []).slice(0, 15).map(function (attempt) {
            const ok = attempt.outcome === "success";
            return '<li><div class="adm-titem' + (ok ? "" : " is-alert") + '"><span class="adm-ini' + (attempt.display_name ? "" : " is-sys") + '">' + esc(initials(attempt.display_name || attempt.identifier)) + "</span>"
                + "<p><b>" + esc(attempt.display_name || attempt.identifier || "Unknown") + "</b> " + esc(ok ? "signed in" : "could not sign in: " + lowerFirst(UI.describeAttempt ? UI.describeAttempt(attempt) : attempt.reason)) + "</p>"
                + "<time>" + esc(whenText(attempt.created_at)) + "</time><small>" + esc([attempt.device, UI.formatIp(attempt.ip)].filter(Boolean).join(" · ")) + "</small></div></li>";
        }).join("") || '<li class="ofc-empty">No sign-in attempts yet.</li>';
    }

    /* ---------- Reports ---------- */

    function reportRangeText(range) {
        const start = parseDate(range.start);
        const end = parseDate(range.end);
        if (!start || !end) {
            return "";
        }
        const options = { month: "long", day: "numeric" };
        return sameDay(start, end) ? start.toLocaleDateString([], { weekday: "long", month: "long", day: "numeric" })
            : start.toLocaleDateString([], options) + " – " + end.toLocaleDateString([], options);
    }

    /** The same rounding as the server's findings (half away from zero). */
    function percentChange(now, before) {
        const raw = (now - before) * 100 / before;
        return Math.sign(raw) * Math.round(Math.abs(raw));
    }

    function kpi(label, value, delta, direction) {
        return '<div class="adm-kpi"><span>' + esc(label) + "</span><strong>" + esc(value) + '</strong><em class="' + direction + '">' + esc(delta) + "</em></div>";
    }

    function renderReport() {
        const data = S.report.data;
        if (!data) {
            return;
        }
        $$("#admPeriodTabs [data-period]").forEach(function (button) {
            const active = button.dataset.period === S.report.period;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-selected", String(active));
        });
        byId("admPeriodNote").textContent = reportRangeText(data.range) + ", compared with " + data.previous_label + ".";
        const now = data.current;
        const before = data.previous;
        const visitDelta = before.visits > 0 ? percentChange(now.visits, before.visits) : null;
        const kpis = [
            kpi("Visits", now.visits.toLocaleString(),
                visitDelta === null ? (now.visits ? "none before to compare" : "—") : (visitDelta === 0 ? "same as before" : (visitDelta > 0 ? "▲ " : "▼ ") + Math.abs(visitDelta) + "% (" + before.visits + " before)"),
                visitDelta === null || visitDelta === 0 ? "flat" : (visitDelta > 0 ? "up" : "down")),
            kpi("Average visit", now.average_minutes !== null ? durationText(now.average_minutes) : "—",
                now.average_minutes !== null && before.average_minutes !== null ? (now.average_minutes === before.average_minutes ? "same as before"
                    : (now.average_minutes > before.average_minutes ? "▲ " : "▼ ") + durationText(Math.abs(now.average_minutes - before.average_minutes))) : "—", "flat"),
            kpi("Answered in time", now.answered_in_time !== null ? now.answered_in_time + "%" : "—",
                now.answered_in_time !== null && before.answered_in_time !== null ? (now.answered_in_time === before.answered_in_time ? "same as before"
                    : (now.answered_in_time > before.answered_in_time ? "▲ " : "▼ ") + Math.abs(now.answered_in_time - before.answered_in_time) + " points")
                    : (now.expired ? plural(now.expired, "request", "requests") + " expired" : "no request expired"),
                now.answered_in_time === null || before.answered_in_time === null ? "flat" : (now.answered_in_time >= before.answered_in_time ? "up" : "down")),
            kpi("Didn't come", String(now.no_shows), now.approved > 0 ? Math.round(now.no_shows * 100 / Math.max(now.approved, now.no_shows)) + "% of approved" : "—",
                now.no_shows > before.no_shows ? "down" : "flat"),
        ];
        byId("admKpis").innerHTML = kpis.join("");
        const iconFor = { trend: ICON.trend, clock: ICON.clock, office: ICON.office, warn: ICON.warn, map: ICON.map };
        byId("admInsights").innerHTML = data.findings.length
            ? data.findings.map(function (finding) {
                return '<li class="' + esc(finding.tone) + '"><span class="adm-ico">' + (iconFor[finding.icon] || ICON.trend) + "</span><span>" + finding.html + "</span></li>";
            }).join("")
            : '<li><span class="adm-ico">' + ICON.trend + "</span><span>Not enough visits in this period to show patterns yet.</span></li>";

        const series = data.series.map(function (item, index) {
            const sparse = data.series.length > 14;
            return { short: sparse && index % 7 !== 0 && index !== data.series.length - 1 ? "" : item.label, full: item.full, value: item.visits, weekend: item.weekend, future: item.future };
        });
        const unit = S.report.period === "today" ? "hour" : (S.report.period === "quarter" ? "week" : "day");
        byId("admTrendTitle").textContent = "Visits per " + unit;
        vbars(byId("admTrendChart"), series, { label: "Visits per " + unit });
        byId("admTrendNote").textContent = (unit === "day" ? "Weekdays in blue, weekends in gray. " : "") + "Hover or tap a bar for the number.";
        byId("admTrendTable").innerHTML = '<table class="adm-data"><thead><tr><th>' + (unit === "hour" ? "Hour" : unit === "week" ? "Week" : "Day") + "</th><th>Visits</th></tr></thead><tbody>"
            + data.series.map(function (item) { return "<tr><td>" + esc(item.full) + "</td><td>" + item.visits + "</td></tr>"; }).join("") + "</tbody></table>";

        const maxOffice = Math.max(1, ...data.offices.map(function (office) { return office.visits; }));
        byId("admOfficeBars").innerHTML = data.offices.length ? data.offices.map(function (office) {
            const notes = [];
            if (!office.staff) {
                notes.push("no staff yet");
            }
            if (office.answer_label) {
                notes.push("answers in " + office.answer_label);
            }
            if (office.expired) {
                notes.push(plural(office.expired, "expired", "expired"));
            }
            return '<div class="adm-hbar"><span>' + esc(office.name) + (notes.length ? "<br><small>" + esc(notes.join(" · ")) + "</small>" : "") + "</span><b>" + office.visits
                + '</b><div class="adm-track"><i style="width:' + (office.visits / maxOffice * 100) + '%"></i></div></div>';
        }).join("") : '<p class="ofc-note">No offices yet.</p>';
        const total = data.purposes.reduce(function (sum, item) { return sum + item.visits; }, 0);
        const maxPurpose = Math.max(1, ...data.purposes.map(function (item) { return item.visits; }));
        byId("admPurposeBars").innerHTML = data.purposes.length ? data.purposes.map(function (item) {
            return '<div class="adm-hbar"><span>' + esc(item.label) + "</span><b>" + item.visits + " · " + Math.round(item.visits * 100 / Math.max(1, total)) + '%</b><div class="adm-track"><i style="width:'
                + (item.visits / maxPurpose * 100) + '%"></i></div></div>';
        }).join("") : '<p class="ofc-note">No visits with a purpose in this period.</p>';
        renderHeat(data.heat);
    }

    function renderHeat(heat) {
        const values = heat.values;
        const max = Math.max(0, ...values.flat());
        const rows = [0, 1, 2, 3, 4].concat([5, 6].filter(function (day) { return values[day].some(function (value) { return value > 0; }); }));
        const level = function (value) {
            if (!value || !max) {
                return 0;
            }
            return Math.max(1, Math.ceil(value / max * 4));
        };
        const hourLabels = heat.hours;
        let html = '<span></span>' + hourLabels.map(function (hour) { return "<span>" + esc(hour) + "</span>"; }).join("");
        rows.forEach(function (day) {
            html += "<span>" + esc(heat.days[day]) + "</span>" + values[day].map(function (value, hour) {
                return '<i class="is-' + level(value) + '" title="' + esc(heat.days[day] + " " + hourLabels[hour] + ":00 · " + plural(value, "check-in", "check-ins")) + '"></i>';
            }).join("");
        });
        const host = byId("admHeat");
        host.style.gridTemplateColumns = "34px repeat(" + hourLabels.length + ", minmax(0, 1fr))";
        host.innerHTML = max ? html : '<p class="ofc-note adm-span">No check-ins in this period yet.</p>';
    }

    async function loadReport() {
        const request = ++S.report.request;
        byId("admPeriodNote").textContent = "Loading…";
        const data = await api("admin_report.php?period=" + encodeURIComponent(S.report.period));
        if (request !== S.report.request) {
            return;
        }
        if (!data.success) {
            byId("admPeriodNote").textContent = data.message || "Could not load the report.";
            return;
        }
        S.report.data = data;
        renderReport();
    }

    /* ---------- Campus map checklist ---------- */

    function renderCampusChecklist() {
        const setup = S.overview.campus_setup;
        const chip = byId("admCampusChip");
        chip.className = "ofc-chip adm-dotchip " + (setup.done === setup.total ? "is-green" : "is-amber");
        chip.innerHTML = "<i></i>" + setup.done + " of " + setup.total + " steps done";
        const modes = { boundary: "boundary", gates: "gates", pins: "offices", routes: "routes", inside: "inside" };
        byId("admCampusChecks").innerHTML = setup.steps.map(function (step) {
            const label = step.total !== undefined ? step.label + " · " + step.count + " of " + step.total : step.label;
            return '<li><span class="' + (step.done ? "ok" : "no") + '">' + (step.done ? ICON.check : ICON.dot) + "</span>"
                + "<span><b>" + esc(label) + '</b><br><small class="ofc-note">' + esc(step.detail) + "</small></span>"
                + '<button class="ofc-btn is-small' + (step.done ? " is-quiet" : "") + '" type="button" data-campus-step="' + modes[step.key] + '">' + (step.done ? "Open" : "Next") + "</button></li>";
        }).join("");
        const list = activeOffices();
        const mark = function (done) { return done ? '<td class="y">✓</td>' : '<td class="n">–</td>'; };
        byId("admCampusTable").innerHTML = list.length
            ? "<thead><tr><th>Office</th><th>Pin</th><th>Route</th><th>Inside</th></tr></thead><tbody>" + list.map(function (office) {
                return "<tr><td>" + esc(office.name) + "</td>" + mark(office.setup.pin) + mark(office.setup.route) + mark(office.setup.inside) + "</tr>";
            }).join("") + "</tbody>"
            : "";
    }

    function renderMenuNotes() {
        const data = S.overview;
        const setup = data.campus_setup;
        byId("admMenuCampus").textContent = setup.done + " of " + setup.total + " setup steps done";
        byId("admMenuClosure").textContent = data.closures.length ? "Closed " + data.closures[0].when : "Class suspension, holiday, bad weather";
        byId("admMenuReminders").textContent = data.reminders.on
            ? "On: answer within " + durationText(data.reminders.target_minutes).replace("9 h", "1 working day")
            : "Off: offices are reminded only when you tap Remind";
    }

    /* ---------- Needs your attention (the bell) ---------- */

    function openAttention() {
        openSheet({
            title: "Needs your attention",
            sub: "Most urgent first. Each one goes away when it's fixed.",
            drawer: true,
            body: '<ul class="adm-alist">' + attentionList(true) + "</ul>",
        });
    }

    /* ---------- An office ---------- */

    function openOffice(code) {
        const office = officeByCode(code);
        if (!office) {
            return;
        }
        const people = staffOf(code);
        const chip = stateChip(office);
        const setupRows = SETUP_STEPS.map(function (step) {
            const done = office.setup[step[0]];
            let button = "";
            if (!done) {
                if (step[0] === "hours") {
                    button = '<button class="ofc-btn is-small is-quiet" type="button" data-hours-info>How</button>';
                } else if (step[0] === "staff") {
                    button = '<button class="ofc-btn is-small" type="button" data-add-person="' + esc(code) + '">Add</button>';
                } else if (step[0] === "route") {
                    button = '<button class="ofc-btn is-small" type="button" data-campus-step="routes">Record</button>';
                } else {
                    button = '<button class="ofc-btn is-small" type="button" data-wizard="' + esc(code) + '" data-wizard-step="' + (step[0] === "pin" ? 3 : 4) + '">Set up</button>';
                }
            }
            return '<li><span class="' + (done ? "ok" : "no") + '">' + (done ? ICON.check : ICON.dot) + "</span><span><b>" + step[1] + "</b>"
                + (step[0] === "hours" && !done ? '<br><small class="ofc-note">Set by the office\'s own staff</small>' : "") + "</span>" + (button || "<span></span>") + "</li>";
        }).join("");
        const staffHtml = people.length
            ? '<ul class="adm-plist">' + people.map(personRow).join("") + "</ul>"
            : (office.is_active ? '<p class="ofc-banner is-red">' + ICON.warn + "<span><b>No Office Personnel.</b> Visitors can't book " + esc(office.name) + " until someone is assigned.</span></p>" : '<p class="ofc-note">No staff.</p>');
        const slow = office.waiting > 0 && (office.waiting > 3 || (office.oldest_minutes || 0) > 240);
        const foot = office.is_active
            ? (office.setup_done < 5 ? '<button class="ofc-btn" type="button" data-wizard="' + esc(code) + '">Continue setup</button>' : "")
                + (office.waiting ? '<button class="ofc-btn is-primary" type="button" data-remind="' + esc(code) + '">Remind</button>' : "")
                + '<button class="ofc-btn is-quiet" type="button" data-edit-office="' + esc(code) + '">Edit</button>'
            : '<button class="ofc-btn is-primary" type="button" data-restore-office="' + esc(code) + '">Make it active again</button>'
                + '<button class="ofc-btn is-decline" type="button" data-delete-office="' + esc(code) + '">Delete</button>';
        openSheet({
            title: esc(office.name),
            head: '<div class="ofc-dstatus">' + chip + '<span class="ofc-note">' + esc(office.state_line) + "</span></div>",
            drawer: true,
            body: (slow ? '<p class="ofc-banner">' + ICON.clock + "<span><b>" + plural(office.waiting, "request is", "requests are") + " waiting</b>, the oldest for "
                + durationText(office.oldest_minutes) + "." + (office.expired_recent ? " " + plural(office.expired_recent, "request", "requests") + " expired without an answer in the last 30 days." : "") + "</span></p>" : "")
                + (office.is_active ? '<div class="adm-ostats"><div><b>' + office.waiting + "</b><span>Waiting</span></div><div><b>" + office.today + "</b><span>Visits today</span></div><div><b>"
                    + office.visits_month + "</b><span>This month</span></div></div>" : "")
                + '<div class="ofc-sec-head"><h3>Office Personnel</h3>' + (office.is_active ? '<button class="ofc-btn is-small" type="button" data-add-person="' + esc(code) + '">' + ICON.people + "Add</button>" : "") + "</div>"
                + staffHtml
                + (office.is_active ? '<p class="ofc-label">Setup</p><ul class="adm-checks">' + setupRows + "</ul>" : "")
                + '<p class="ofc-label">Details</p><dl class="ofc-facts adm-facts">'
                + "<div><dt>Short code</dt><dd>" + esc(office.code) + "</dd></div>"
                + "<div><dt>Where</dt><dd>" + esc(office.where || "Not set") + "</dd></div>"
                + (office.description ? "<div><dt>About</dt><dd>" + esc(office.description) + "</dd></div>" : "")
                + "<div><dt>Answers in</dt><dd>" + (office.answer_label ? esc(office.answer_label) + " on average (working hours, last 30 days)" : "No answers yet") + "</dd></div>"
                + (office.answered_in_time !== null ? "<div><dt>In time</dt><dd>" + office.answered_in_time + "% of requests answered before they expired</dd></div>" : "")
                + "</dl>",
            foot: foot,
        });
    }

    function openEditOffice(code) {
        const office = officeByCode(code);
        if (!office) {
            return;
        }
        const fromDirections = office.setup.inside && office.where && office.where !== office.location;
        openSheet({
            title: "Edit " + esc(office.name),
            sub: "The short code " + esc(office.code) + " stays: visits and accounts use it.",
            body: '<label class="ofc-field"><span>Office name</span><input id="admEditOfficeName" maxlength="100" value="' + esc(office.name) + '"></label>'
                + (fromDirections ? '<p class="ofc-note">Where it is comes from its directions inside: ' + esc(office.where) + ". Change it in the setup (Directions inside).</p>"
                    : '<label class="ofc-field"><span>Where it is (optional)</span><input id="admEditOfficeWhere" maxlength="150" value="' + esc(office.location) + '" placeholder="e.g. CCI Building, 2nd floor"></label>')
                + '<label class="ofc-field"><span>About (optional)</span><input id="admEditOfficeAbout" maxlength="255" value="' + esc(office.description) + '"></label>'
                + '<div class="adm-danger-zone"><p class="ofc-note"><b>Archive this office</b> when it closes: visitors can\'t book it anymore and its records stay. You can make it active again.</p>'
                + '<button class="ofc-btn is-small is-decline" type="button" data-archive-office="' + esc(code) + '">Archive office</button></div>',
            foot: '<button class="ofc-btn is-quiet" type="button" data-close-sheet>Cancel</button><button class="ofc-btn is-primary" type="button" data-save-office="' + esc(code) + '">Save</button>',
        });
    }

    async function saveOffice(code, button) {
        const office = officeByCode(code);
        const sheet = topSheet();
        const where = byId("admEditOfficeWhere");
        setBusy(button, true, "Saving…");
        const data = await api("admin_departments.php", {
            action: "update",
            code: code,
            name: byId("admEditOfficeName").value.trim(),
            location: where ? where.value.trim() : office.location,
            description: byId("admEditOfficeAbout").value.trim(),
        });
        setBusy(button, false);
        if (!data.success) {
            if (!data.cancelled) {
                sheetError(sheet, data.message || "Could not save the office.");
            }
            return;
        }
        closeSheets();
        toast(data.message || "Saved.");
        afterOfficeChange();
    }

    async function setOfficeArchived(code, archive) {
        const office = officeByCode(code);
        if (archive) {
            const waiting = office ? office.waiting : 0;
            if (!(await AppDialog.confirm({
                title: "Archive " + office.name + "?",
                message: "Visitors can't book it anymore." + (waiting ? " Its " + plural(waiting, "waiting request", "waiting requests") + " will expire unless someone answers them." : "")
                    + "\n\nIts records stay, and you can make it active again.",
                confirmLabel: "Archive office",
                danger: true,
            }))) {
                return;
            }
        }
        const data = await act("admin_departments.php", { action: archive ? "archive" : "restore", code: code }, archive ? "Couldn't archive the office" : "Couldn't make the office active", { stepUp: true });
        if (data.success) {
            closeSheets();
            toast(data.message || "Done.");
            afterOfficeChange();
        }
    }

    async function deleteOffice(code) {
        const office = officeByCode(code);
        if (!(await AppDialog.confirm({
            title: "Delete " + office.name + "?",
            message: "Only an office that was never used can be deleted. This can't be undone.",
            confirmLabel: "Delete office",
            danger: true,
        }))) {
            return;
        }
        const data = await act("admin_departments.php", { action: "delete", code: code }, "Couldn't delete the office", { stepUp: true });
        if (data.success) {
            closeSheets();
            toast(data.message || "Deleted.");
            afterOfficeChange();
        }
    }

    function afterOfficeChange() {
        loadOverview();
        loadUsers();
        document.dispatchEvent(new CustomEvent("departments:updated"));
    }

    async function remindOffice(code) {
        const office = officeByCode(code);
        const people = office ? office.staff : [];
        if (!office || !(await AppDialog.confirm({
            title: "Remind " + office.name + "?",
            message: (people.length ? people.map(function (person) { return person.name; }).join(", ") + (people.length === 1 ? " gets" : " get") : "Its staff get")
                + " a notification: " + plural(office.waiting, "request is", "requests are") + " waiting" + (office.oldest_minutes !== null ? ", the oldest for " + durationText(office.oldest_minutes) : "") + ".",
            confirmLabel: "Send reminder",
        }))) {
            return;
        }
        const data = await act("admin_remind_office.php", { office_code: code }, "Couldn't send the reminder");
        if (data.success) {
            closeSheets();
            toast(data.message || "Reminder sent.");
            loadOverview();
            loadHomeActivity();
        }
    }

    /* ---------- A person (staff) ---------- */

    function openPerson(id) {
        const user = userById(id);
        if (!user) {
            if (!S.usersLoaded) {
                loadUsers().then(function () { if (userById(id)) { openPerson(id); } });
            }
            return;
        }
        if (user.role === "visitor") {
            openVisitor({ userId: user.id });
            return;
        }
        const me = Number(user.id) === Number(S.me.id);
        const name = user.display_name || user.username;
        const onlyAdmin = user.role === "admin" && Number(user.is_active) === 1 && activeAdmins().length <= 1;
        const banners = [];
        if (user.locked) {
            banners.push('<p class="ofc-banner is-red">' + ICON.lock + "<span><b>Sign-in locked</b> after " + plural(user.failed_24h, "failed sign-in", "failed sign-ins") + " today. It unlocks by itself, or unlock it now.</span></p>");
        }
        if (user.temp_password_expired) {
            banners.push('<p class="ofc-banner is-red">' + ICON.key + "<span><b>The temporary password expired.</b> Make a new one and give it to them.</span></p>");
        } else if (user.must_change_password && user.temp_password_expires_at) {
            banners.push('<p class="ofc-banner">' + ICON.key + "<span><b>Temporary password</b>, works until " + esc(whenText(user.temp_password_expires_at)) + ". They choose their own at the first sign-in.</span></p>");
        }
        if (Number(user.is_active) !== 1) {
            banners.push('<p class="ofc-banner is-gray">' + ICON.lock + "<span><b>This account is turned off.</b> They can't sign in until you turn it on.</span></p>");
        }
        if (!user.has_name) {
            banners.push('<p class="ofc-banner">' + ICON.user + "<span><b>No name on file.</b> Add it with Edit details, so every action can be traced to them.</span></p>");
        }
        const twoStep = user.two_factor_enabled ? "On" : (user.two_factor_required ? "Off: they set it up at the next sign-in" : "Off");
        const forgot = user.email
            ? 'Resets it with a code by email (' + esc(user.email) + ")"
            : 'Asks you: no email on file. <button class="ofc-link" type="button" data-edit-person="' + user.id + '">Add email</button>';
        const actions = [];
        if (me) {
            actions.push('<button class="ofc-btn" type="button" data-account-security>' + ICON.shield + "Account security</button>");
            actions.push('<button class="ofc-btn" type="button" data-edit-person="' + user.id + '">' + ICON.edit + "Edit details</button>");
            actions.push('<button class="ofc-btn" type="button" data-person-activity="' + user.id + '">' + ICON.activity + "See activity</button>");
        } else {
            if (user.locked) {
                actions.push('<button class="ofc-btn is-primary" type="button" data-unlock-user="' + user.id + '">' + ICON.lock + "Unlock now</button>");
            }
            actions.push('<button class="ofc-btn" type="button" data-reset-password="' + user.id + '">' + ICON.key + "New password</button>");
            actions.push('<button class="ofc-btn" type="button" data-edit-person="' + user.id + '">' + ICON.edit + "Edit details</button>");
            actions.push('<button class="ofc-btn" type="button" data-person-activity="' + user.id + '">' + ICON.activity + "See activity</button>");
            if (user.two_factor_enabled) {
                actions.push('<button class="ofc-btn" type="button" data-reset-twostep="' + user.id + '">' + ICON.shield + "Reset 2-step</button>");
            }
            actions.push('<button class="ofc-btn" type="button" data-force-logout="' + user.id + '">' + ICON.signout + "Sign out everywhere</button>");
            if (!onlyAdmin) {
                actions.push(Number(user.is_active) === 1
                    ? '<button class="ofc-btn is-decline" type="button" data-turn-off="' + user.id + '">Turn off account</button>'
                    : '<button class="ofc-btn is-primary" type="button" data-turn-on="' + user.id + '">Turn on account</button>');
            }
        }
        openSheet({
            title: esc(name),
            head: '<div class="ofc-dstatus"><span class="ofc-chip is-blue">' + esc(ROLE_LABELS[user.role]) + '</span><span class="ofc-note">' + esc(user.department || "") + "</span></div>",
            drawer: true,
            body: banners.join("")
                + '<dl class="ofc-facts adm-facts">'
                + "<div><dt>Position</dt><dd>" + esc(user.position || "Not set") + "</dd></div>"
                + "<div><dt>Username</dt><dd>" + esc(user.username) + "</dd></div>"
                + (user.email ? "<div><dt>Email</dt><dd>" + esc(user.email) + "</dd></div>" : "")
                + (user.contact_number ? "<div><dt>Phone</dt><dd>" + esc(user.contact_number) + "</dd></div>" : "")
                + "<div><dt>Last seen</dt><dd>" + esc(lastSeenText(user)) + (user.last_login_at ? " · signed in " + esc(whenText(user.last_login_at)) : "") + "</dd></div>"
                + "<div><dt>Two-step</dt><dd>" + esc(twoStep) + "</dd></div>"
                + "<div><dt>Forgot password</dt><dd>" + forgot + "</dd></div>"
                + (onlyAdmin ? "<div><dt>Only admin</dt><dd>This account can't be turned off or removed while it is the only active admin.</dd></div>" : "")
                + "</dl>"
                + '<div class="adm-actions">' + actions.join("") + "</div>"
                + (me || onlyAdmin ? "" : '<button class="ofc-link adm-remove" type="button" data-remove-user="' + user.id + '">Remove account</button>'),
        });
    }

    function openEditPerson(id) {
        const user = userById(id);
        if (!user) {
            return;
        }
        const officeOptions = activeOffices().map(function (office) {
            return '<option value="' + esc(office.code) + '"' + (office.code === user.office_code ? " selected" : "") + ">" + esc(office.name) + "</option>";
        }).join("") + (user.role === "offices" && !officeByCode(user.office_code) ? '<option value="' + esc(user.office_code) + '" selected>' + esc(user.department) + " (current)</option>" : "");
        openSheet({
            title: "Edit " + esc(user.display_name || user.username),
            sub: "The username stays: " + esc(user.username) + ".",
            body: '<div class="adm-two"><label class="ofc-field"><span>First name</span><input id="admEditFirst" maxlength="60" value="' + esc(user.first_name) + '"></label>'
                + '<label class="ofc-field"><span>Last name</span><input id="admEditLast" maxlength="60" value="' + esc(user.last_name) + '"></label></div>'
                + '<label class="ofc-field"><span>Position</span><input id="admEditPosition" maxlength="100" value="' + esc(user.position) + '"></label>'
                + '<label class="ofc-field"><span>Email (optional)</span><input id="admEditEmail" type="email" maxlength="190" value="' + esc(user.email) + '" placeholder="name@isatu.edu.ph"><small class="ofc-note">With an email, they can reset a forgotten password themselves.</small></label>'
                + '<label class="ofc-field"><span>Phone (optional)</span><input id="admEditPhone" maxlength="32" inputmode="tel" value="' + esc(user.contact_number) + '"></label>'
                + (user.role === "offices" ? '<label class="ofc-field"><span>Office</span><select id="admEditOffice">' + officeOptions + "</select></label>" : ""),
            foot: '<button class="ofc-btn is-quiet" type="button" data-close-sheet>Cancel</button><button class="ofc-btn is-primary" type="button" data-save-person="' + user.id + '">Save</button>',
        });
    }

    async function savePerson(id, button) {
        const user = userById(id);
        const sheet = topSheet();
        const payload = {
            user_id: user.id,
            first_name: byId("admEditFirst").value.trim(),
            last_name: byId("admEditLast").value.trim(),
            position: byId("admEditPosition").value.trim(),
            contact_number: byId("admEditPhone").value.trim(),
            email: byId("admEditEmail").value.trim(),
        };
        if (user.role === "offices") {
            payload.office_code = byId("admEditOffice").value;
        }
        if (!payload.first_name || !payload.last_name) {
            sheetError(sheet, "Enter their first and last name.");
            return;
        }
        if (user.role === "offices" && payload.office_code !== user.office_code) {
            const leaving = staffOf(user.office_code).length === 1 && Number(user.is_active) === 1;
            if (!(await AppDialog.confirm({
                title: "Move " + (user.display_name || user.username) + " to " + ((officeByCode(payload.office_code) || {}).name || payload.office_code) + "?",
                message: "They are signed out and will only see that office's requests."
                    + (leaving ? "\n\nThey are " + user.department + "'s only staff: visitors can't book " + user.department + " until someone else is added." : ""),
                confirmLabel: "Move",
                danger: leaving,
            }))) {
                return;
            }
        }
        setBusy(button, true, "Saving…");
        const data = await api("update_user.php", payload, { stepUp: true });
        setBusy(button, false);
        if (!data.success) {
            if (!data.cancelled) {
                sheetError(sheet, data.message || "Could not save the details.");
            }
            return;
        }
        closeSheets();
        toast(data.message || "Saved.");
        await loadUsers();
        loadOverview();
    }

    /** Safety check before turning off or removing someone: the office's only staff, or the only admin. */
    async function safetyCheck(user, action) {
        const name = user.display_name || user.username;
        if (user.role === "admin" && activeAdmins().length <= 1 && Number(user.is_active) === 1) {
            await AppDialog.alert({ title: name + " is the only admin", message: "Add another administrator first. The system always keeps at least one active admin." });
            return false;
        }
        if (user.role === "offices" && Number(user.is_active) === 1 && staffOf(user.office_code).length === 1) {
            const office = officeByCode(user.office_code);
            const waiting = office ? office.waiting : 0;
            const answer = await AppDialog.confirm({
                title: name + " is " + (user.department || "the office") + "'s only staff",
                message: "If you " + action + " this account, visitors can't book " + (user.department || "the office") + " anymore"
                    + (waiting ? ", and its " + plural(waiting, "waiting request", "waiting requests") + " will expire unless someone answers them" : "") + ".",
                confirmLabel: action === "remove" ? "Remove anyway" : "Turn off anyway",
                altLabel: "Add someone first",
                danger: true,
            });
            if (answer === "alt") {
                closeSheets();
                openAdd({ office: user.office_code });
                return false;
            }
            return answer === true ? "checked" : false;
        }
        return true;
    }

    async function setUserActive(id, active) {
        const user = userById(id);
        const name = user.display_name || user.username;
        if (!active) {
            const check = await safetyCheck(user, "turn off");
            if (!check) {
                return;
            }
            if (check === true && !(await AppDialog.confirm({
                title: "Turn off " + name + "'s account?",
                message: "They are signed out everywhere and can't sign in until you turn it on again. Their records stay.",
                confirmLabel: "Turn off",
                danger: true,
            }))) {
                return;
            }
        }
        const data = await act("toggle_user_status.php", { id: Number(id), is_active: active }, "Couldn't change " + name + "'s account");
        if (data.success) {
            closeSheets();
            toast(active ? name + "'s account is on again." : name + "'s account is off.");
            await loadUsers();
            loadOverview();
        }
    }

    async function removeUser(id) {
        const user = userById(id);
        const name = user.display_name || user.username;
        const check = await safetyCheck(user, "remove");
        if (!check) {
            return;
        }
        if (!(await AppDialog.confirm({
            title: "Remove " + name + "?",
            message: (user.role === "visitor" ? "Their upcoming visits are cancelled, and each office is told. Past visits stay in the records under their name."
                : "What they did stays in the records.") + "\n\nThey are signed out everywhere. This can't be undone.",
            confirmLabel: "Remove account",
            danger: true,
        }))) {
            return;
        }
        const data = await act("delete_user.php", { id: Number(id) }, "Couldn't remove " + name, { stepUp: true });
        if (data.success) {
            closeSheets();
            toast(data.message || "Removed " + name + ".");
            await loadUsers();
            loadOverview();
            if (S.activity.loaded) {
                loadSecurity();
            }
        }
    }

    async function securityAction(action, id, question) {
        const user = userById(id);
        if (question && !(await AppDialog.confirm(question))) {
            return null;
        }
        const data = await act("admin_security.php", { action: action, user_id: Number(id) }, "That didn't work", { stepUp: action !== "unlock_user" });
        if (!data.success) {
            return null;
        }
        if (action === "reset_password") {
            closeSheets();
            openPasswordResult({ name: data.display_name || (user && user.display_name), username: data.username, password: data.temporary_password, expires: data.expires_at, title: "New password for " + (data.display_name || data.username) });
        } else {
            closeSheets();
            toast(data.message || "Done.");
        }
        await loadUsers();
        loadOverview();
        if (S.activity.loaded) {
            loadSecurity();
        }
        return data;
    }

    function resetPassword(id) {
        const user = userById(id);
        const name = user ? user.display_name || user.username : "this person";
        return securityAction("reset_password", id, {
            title: "Make a new password for " + name + "?",
            message: "Their old password stops working and they are signed out everywhere. You'll see a temporary one to give them; they choose their own at the next sign-in.",
            confirmLabel: "Make new password",
        });
    }

    /* ---------- A temporary password, once (and its slip) ---------- */

    function slipFor(person) {
        const site = new URL("login.html", window.location.href).href.replace(/^https?:\/\//, "");
        return '<div class="adm-slip"><span class="adm-slip-title"><img src="assets/images/isatu-logo.png" alt="">ISATU Visitor Management · your account</span>'
            + "<span><b>" + esc(person.name) + "</b>" + (person.role ? " · " + esc(person.role) : "") + "</span>"
            + "<span>Sign in at <b>" + esc(site) + "</b></span>"
            + "<span>Username: <b>" + esc(person.username) + "</b></span><span>Temporary password:</span><code>" + esc(person.password) + "</code>"
            + '<span class="adm-slip-note">Works until ' + esc(whenText(person.expires) || "it expires") + ". You'll choose your own password when you first sign in"
            + (person.twoStep ? ", then set up two-step sign-in" : "") + ".</span></div>";
    }

    function printSlips(people) {
        const host = byId("admPrint");
        host.innerHTML = '<div class="adm-slips">' + people.map(slipFor).join("") + "</div>";
        printNow("slips");
    }

    function printNow(kind) {
        document.body.dataset.print = kind;
        const done = function () {
            delete document.body.dataset.print;
            window.removeEventListener("afterprint", done);
        };
        window.addEventListener("afterprint", done);
        window.setTimeout(function () { window.print(); }, 60);
    }

    function openPasswordResult(info) {
        openSheet({
            title: esc(info.title || "Account created"),
            sub: "Give them this temporary password. It is shown only now.",
            body: '<div class="adm-temp"><span class="ofc-note">' + esc(info.name || info.username) + " · username <b>" + esc(info.username) + "</b></span><code>" + esc(info.password) + "</code>"
                + '<span class="ofc-note">Works until ' + esc(whenText(info.expires)) + ". They choose their own at the first sign-in" + (info.twoStep ? ", then set up two-step sign-in" : "") + ".</span></div>"
                + '<div class="adm-actions"><button class="ofc-btn" type="button" data-copy="' + esc(info.password) + '">' + ICON.copy + "Copy</button>"
                + '<button class="ofc-btn" type="button" data-print-one>' + ICON.print + "Print a slip</button></div>",
            foot: '<button class="ofc-btn is-primary is-full" type="button" data-close-sheet>Done</button>',
            init: function (dialog) {
                dialog.addEventListener("click", function (event) {
                    if (event.target.closest("[data-print-one]")) {
                        printSlips([{ name: info.name || info.username, username: info.username, password: info.password, expires: info.expires, role: info.role, twoStep: info.twoStep }]);
                    }
                });
            },
        });
    }

    /* ---------- A visitor, and their visits ---------- */

    const VISIT_TONES = { green: "is-green", amber: "is-amber", red: "is-red", blue: "is-blue", gray: "" };

    function visitRow(visit) {
        return '<li><button class="adm-vrow" type="button" data-visit="' + visit.id + '"><span><b>' + esc(visit.visitor) + "</b><small>" + esc(visit.office)
            + (visit.purpose ? " · " + esc(visit.purpose) : "") + "</small><small>" + esc(dayText(visit.starts_at)) + ", " + esc(timeText(visit.starts_at)) + " · " + esc(visit.code)
            + (visit.at_gate ? " · registered at the gate" : "") + '</small></span><span class="ofc-chip ' + (VISIT_TONES[visit.tone] || "") + '">' + esc(visit.status_label) + "</span></button></li>";
    }

    async function openVisitor(key) {
        const sheet = openSheet({ title: "Visitor", sub: "Loading…", drawer: true, body: '<p class="ofc-empty">Loading…</p>' });
        const data = await api("admin_visits.php?" + (key.userId ? "visitor=" + encodeURIComponent(key.userId) : "appointment=" + encodeURIComponent(key.appointmentId)));
        if (!sheet.open) {
            return;
        }
        if (!data.success) {
            sheet.set({ sub: "", body: '<p class="ofc-empty">' + esc(data.message || "Could not load this visitor.") + "</p>" });
            return;
        }
        const visitor = data.visitor;
        const account = visitor.account;
        sheet.set({
            title: esc(visitor.name || "Visitor"),
            sub: account ? "Visitor · " + esc(account.email || account.username) + (account.is_active ? "" : " · account turned off") : "Registered at the gate, no app account",
            body: '<div class="adm-ostats"><div><b>' + visitor.visits + "</b><span>Visits</span></div><div><b>" + visitor.completed + "</b><span>Came</span></div><div><b>"
                + visitor.no_shows + "</b><span>Didn't come</span></div></div>"
                + (account ? '<dl class="ofc-facts adm-facts"><div><dt>Joined</dt><dd>' + esc(dayText(account.created_at)) + "</dd></div>"
                    + (account.contact_number ? "<div><dt>Phone</dt><dd>" + esc(account.contact_number) + "</dd></div>" : "")
                    + "<div><dt>Last sign-in</dt><dd>" + esc(account.last_login_at ? whenText(account.last_login_at) : "Never") + "</dd></div></dl>" : "")
                + '<p class="ofc-label">Visits</p>'
                + (data.visits.length ? '<ul class="adm-vlist">' + data.visits.map(visitRow).join("") + "</ul>" : '<p class="ofc-empty">No visits yet.</p>')
                + '<p class="ofc-banner is-blue">' + ICON.shield + "<span>Where they are on campus isn't shown here: only Security sees the live map.</span></p>",
            foot: account ? (account.is_active
                ? '<button class="ofc-btn is-quiet" type="button" data-turn-off="' + account.id + '">Turn off account</button>'
                : '<button class="ofc-btn is-primary" type="button" data-turn-on="' + account.id + '">Turn on account</button>')
                + '<button class="ofc-btn is-decline" type="button" data-remove-user="' + account.id + '">Remove</button>' : "",
        });
    }

    async function openVisit(id) {
        const sheet = openSheet({ title: "Visit", sub: "Loading…", drawer: true, body: '<p class="ofc-empty">Loading…</p>' });
        const data = await api("admin_activity.php?" + new URLSearchParams({ view: "appointment", appointment_id: String(id) }).toString());
        if (!sheet.open) {
            return;
        }
        if (!data.success) {
            sheet.set({ sub: "", body: '<p class="ofc-empty">' + esc(data.message || "Could not load this visit.") + "</p>" });
            return;
        }
        const visit = data.appointment;
        const handled = (data.handled_by || []).map(function (item) {
            return "<div><dt>" + esc(item.label) + "</dt><dd>" + esc(item.person ? item.person.name : "Unknown") + (item.at ? " · " + esc(whenText(item.at)) : "") + "</dd></div>";
        }).join("");
        const events = (data.events && data.events.length ? data.events.map(function (entry) {
            return "<li><i></i><div><strong>" + esc(entry.title) + "</strong><em>" + esc(entry.text) + "</em><small>" + esc((entry.actor ? entry.actor.name : "Automatic") + " · " + whenText(entry.created_at)) + "</small></div></li>";
        }) : (data.history || []).map(function (row) {
            return "<li><i></i><div><strong>" + esc((row.from ? row.from + " → " : "") + row.to) + "</strong>" + (row.note ? "<em>" + esc(row.note) + "</em>" : "")
                + "<small>" + esc((row.by ? row.by.name : "Automatic") + " · " + whenText(row.at)) + "</small></div></li>";
        })).join("");
        sheet.set({
            title: esc(visit.visitor_name || "Visitor"),
            sub: "Visit #" + visit.id + " · " + esc(visit.office),
            body: '<dl class="ofc-facts adm-facts">'
                + "<div><dt>Status</dt><dd>" + esc(visit.status.charAt(0).toUpperCase() + visit.status.slice(1)) + "</dd></div>"
                + "<div><dt>When</dt><dd>" + esc(dayText(visit.scheduled_start_at)) + ", " + esc(timeText(visit.scheduled_start_at)) + "</dd></div>"
                + "<div><dt>Purpose</dt><dd>" + esc([visit.purpose, visit.subject].filter(Boolean).join(" · ") || "—") + "</dd></div>"
                + "<div><dt>Requested</dt><dd>" + esc(whenText(visit.created_at)) + "</dd></div>"
                + (visit.rejection_reason ? "<div><dt>Declined</dt><dd>" + esc(visit.rejection_reason) + "</dd></div>" : "")
                + handled + "</dl>"
                + '<p class="ofc-label">What happened</p><ul class="ofc-tl">' + (events || '<li><i></i><div><em>No recorded actions yet.</em></div></li>') + "</ul>",
        });
    }

    const VISIT_LISTS = {
        campus: ["On campus now", "Visitors checked in at the gate who haven't left yet."],
        today: ["Today's visits", "Booked for today, in time order."],
        waiting: ["Waiting for an office", "Requests no one has answered yet, oldest first."],
        recent: ["Newest requests", "The latest 60, newest first."],
    };

    async function openVisits(list) {
        const tabs = function (current) {
            return '<div class="ofc-seg adm-sheet-seg">' + Object.keys(VISIT_LISTS).map(function (key) {
                return '<button type="button" data-visits-tab="' + key + '" class="' + (key === current ? "is-active" : "") + '">' + VISIT_LISTS[key][0] + "</button>";
            }).join("") + "</div>";
        };
        const sheet = openSheet({ title: VISIT_LISTS[list][0], sub: VISIT_LISTS[list][1], head: tabs(list), drawer: true, body: '<p class="ofc-empty">Loading…</p>' });
        const load = async function (key) {
            sheet.set({ title: VISIT_LISTS[key][0], sub: VISIT_LISTS[key][1], head: tabs(key), body: '<p class="ofc-empty">Loading…</p>' });
            const data = await api("admin_visits.php?list=" + key);
            if (!sheet.open) {
                return;
            }
            sheet.set({
                body: data.success
                    ? (data.visits.length ? '<ul class="adm-vlist">' + data.visits.map(visitRow).join("") + "</ul>" : '<p class="ofc-empty">None right now.</p>')
                        + '<p class="ofc-note">Where visitors are on campus isn\'t shown here: only Security sees the live map.</p>'
                    : '<p class="ofc-empty">' + esc(data.message || "Could not load the visits.") + "</p>",
            });
        };
        sheet.addEventListener("click", function (event) {
            const tab = event.target.closest("[data-visits-tab]");
            if (tab) {
                load(tab.dataset.visitsTab);
            }
        });
        load(list);
    }

    /* ---------- Add people: one, or several at once ---------- */

    function cleanName(value) {
        return String(value || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().replace(/[^a-z0-9]/g, "");
    }

    /** "Ma. Theresa" + "Dela Cruz" -> "mdelacruz" (a free variation if it is taken). */
    function suggestUsername(first, last, taken) {
        const a = cleanName(first);
        const b = cleanName(last);
        let base = (a.charAt(0) + b).slice(0, 30);
        if (base.length < 3) {
            base = (a + b).slice(0, 30);
        }
        if (base.length < 3) {
            return "";
        }
        const used = taken || new Set(S.users.map(function (user) { return String(user.username).toLowerCase(); }));
        let candidate = base;
        for (let n = 2; used.has(candidate); n += 1) {
            candidate = base + n;
        }
        return candidate;
    }

    const NAME_PARTICLES = ["de", "del", "dela", "delos", "de la", "de los", "san", "sta", "sta.", "santa", "van", "von", "la"];

    /** "Ana Marie Dela Cruz" -> ["Ana Marie", "Dela Cruz"]. */
    function splitName(full) {
        const words = String(full || "").trim().split(/\s+/).filter(Boolean);
        if (words.length < 2) {
            return [words[0] || "", ""];
        }
        let start = words.length - 1;
        while (start > 1 && NAME_PARTICLES.indexOf(words[start - 1].toLowerCase()) !== -1) {
            start -= 1;
        }
        return [words.slice(0, start).join(" "), words.slice(start).join(" ")];
    }

    function findOfficeByText(text) {
        const value = String(text || "").trim().toLowerCase();
        if (!value) {
            return null;
        }
        return activeOffices().find(function (office) { return office.code.toLowerCase() === value || office.name.toLowerCase() === value; })
            || activeOffices().find(function (office) { return office.name.toLowerCase().indexOf(value) !== -1; }) || null;
    }

    function parseBulk(text) {
        const used = new Set(S.users.map(function (user) { return String(user.username).toLowerCase(); }));
        const names = new Set(S.users.filter(function (user) { return user.role !== "visitor"; }).map(function (user) { return String(user.display_name || "").toLowerCase(); }));
        const emails = new Set(S.users.map(function (user) { return String(user.email || "").toLowerCase(); }).filter(Boolean));
        return String(text || "").split(/\r?\n/).map(function (line) { return line.trim(); }).filter(Boolean).map(function (line) {
            const cells = line.split(/\t|,/).map(function (cell) { return cell.trim(); });
            const row = { line: line, name: cells[0] || "", position: cells[1] || "", where: cells[2] || "", email: (cells[3] || "").toLowerCase(), ok: false, reason: "" };
            [row.first, row.last] = splitName(row.name);
            const where = row.where.toLowerCase();
            if (/^(security|guard|security guard|gate)$/.test(where)) {
                row.role = "security";
                row.roleLabel = "Security";
            } else if (/^(admin|administrator)$/.test(where)) {
                row.role = "admin";
                row.reason = "Add administrators one at a time";
            } else {
                const office = findOfficeByText(row.where);
                row.role = "offices";
                row.office = office;
                row.roleLabel = office ? "Office Personnel · " + office.name : "";
                if (!office) {
                    row.reason = row.where ? "No office called \"" + row.where + "\"" : "Write the office, or Security";
                }
            }
            if (!row.reason && (!row.first || !row.last)) {
                row.reason = "Write the first and last name";
            }
            if (!row.reason && names.has(row.name.toLowerCase())) {
                row.reason = "Already has an account";
            }
            if (!row.reason && row.email && (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(row.email) || emails.has(row.email))) {
                row.reason = emails.has(row.email) ? "That email is already used" : "The email doesn't look right";
            }
            if (!row.reason) {
                row.username = suggestUsername(row.first, row.last, used);
                used.add(row.username);
                names.add(row.name.toLowerCase());
                if (row.email) {
                    emails.add(row.email);
                }
                row.ok = Boolean(row.username);
                if (!row.ok) {
                    row.reason = "Name too short for a username";
                }
            }
            return row;
        });
    }

    function bulkTable(rows) {
        return '<div class="adm-table-wrap"><table class="adm-data"><thead><tr><th>Name</th><th>Role · office</th><th>Username</th><th></th></tr></thead><tbody>'
            + rows.map(function (row) {
                return "<tr><td>" + esc(row.name || row.line) + "</td><td>" + esc(row.roleLabel || "—") + "</td><td>" + esc(row.username || "—") + "</td>"
                    + '<td class="' + (row.result ? (row.result.ok ? "adm-ok" : "adm-skip") : (row.ok ? "adm-ok" : "adm-skip")) + '">'
                    + esc(row.result ? (row.result.ok ? "✓ Created" : row.result.message) : (row.ok ? "✓ Ready" : "Skip: " + row.reason)) + "</td></tr>";
            }).join("") + "</tbody></table></div>";
    }

    function downloadTemplate() {
        const office = activeOffices()[0];
        const text = "﻿Name,Position,Office (or Security),Email (optional)\r\n"
            + "Ana Cruz,Office Clerk," + (office ? office.name : "CCI IT Office") + ",ana.cruz@isatu.edu.ph\r\n"
            + "Lea Ramos,Security Guard,Security,\r\n";
        const link = document.createElement("a");
        link.href = URL.createObjectURL(new Blob([text], { type: "text/csv;charset=utf-8" }));
        link.download = "add-staff-template.csv";
        document.body.appendChild(link);
        link.click();
        window.setTimeout(function () { URL.revokeObjectURL(link.href); link.remove(); }, 1000);
    }

    function openAdd(options) {
        const settings = options || {};
        let mode = settings.mode || "one";
        let role = settings.role || "offices";
        let usernameEdited = false;
        let rows = [];
        let created = [];
        const officeOptions = function (selected) {
            return '<option value="">Choose the office</option>' + activeOffices().map(function (office) {
                return '<option value="' + esc(office.code) + '"' + (office.code === selected ? " selected" : "") + ">" + esc(office.name) + (office.staff.length ? "" : " · no staff yet") + "</option>";
            }).join("");
        };
        const tabs = function () {
            return '<div class="ofc-seg adm-sheet-seg"><button type="button" data-add-mode="one" class="' + (mode === "one" ? "is-active" : "") + '">One person</button>'
                + '<button type="button" data-add-mode="many" class="' + (mode === "many" ? "is-active" : "") + '">Several at once</button></div>';
        };
        const oneBody = function () {
            return '<div class="ofc-field"><span>Role</span><div class="adm-roles">'
                + '<button type="button" data-role="offices" class="' + (role === "offices" ? "is-on" : "") + '">Office Personnel<small>Answers requests</small></button>'
                + '<button type="button" data-role="security" class="' + (role === "security" ? "is-on" : "") + '">Security<small>Runs the gate</small></button>'
                + '<button type="button" data-role="admin" class="' + (role === "admin" ? "is-on" : "") + '">Admin<small>Manages all</small></button></div></div>'
                + '<div class="adm-two"><label class="ofc-field"><span>First name</span><input id="admNewFirst" maxlength="60" placeholder="e.g. Ana" autocomplete="off"></label>'
                + '<label class="ofc-field"><span>Last name</span><input id="admNewLast" maxlength="60" placeholder="e.g. Cruz" autocomplete="off"></label></div>'
                + '<label class="ofc-field"><span>Position</span><input id="admNewPosition" maxlength="100" placeholder="e.g. Office Clerk"></label>'
                + '<label class="ofc-field" id="admNewOfficeField"' + (role === "offices" ? "" : " hidden") + '><span>Office</span><select id="admNewOffice">' + officeOptions(settings.office || "") + "</select></label>"
                + '<label class="ofc-field"><span>Username</span><input id="admNewUsername" maxlength="64" placeholder="Made from the name" autocomplete="off" spellcheck="false"></label>'
                + '<label class="ofc-field"><span>Email (optional)</span><input id="admNewEmail" type="email" maxlength="190" placeholder="name@isatu.edu.ph"><small class="ofc-note">With an email, they can reset a forgotten password themselves.</small></label>'
                + '<label class="ofc-field"><span>Phone (optional)</span><input id="admNewPhone" maxlength="32" inputmode="tel"></label>'
                + (role === "admin" ? '<p class="ofc-banner is-blue">' + ICON.shield + "<span>An admin can change everything. You'll be asked to confirm it's you, and they must use two-step sign-in.</span></p>" : "");
        };
        const manyBody = function () {
            if (created.length) {
                const ok = created.filter(function (row) { return row.result && row.result.ok; });
                return '<p class="ofc-banner ' + (ok.length ? "is-green" : "is-red") + '">' + (ok.length ? ICON.check : ICON.warn) + "<span><b>" + plural(ok.length, "account", "accounts") + " created.</b> "
                    + (ok.length ? "Their temporary passwords are on the slips, shown this once." : "") + "</span></p>"
                    + bulkTable(created)
                    + (ok.length ? '<button class="ofc-btn is-primary is-full" type="button" data-bulk-print>' + ICON.print + "Print password slips</button>" : "");
            }
            return '<label class="ofc-field"><span>One person per line: name, position, office (or Security), email (optional)</span>'
                + '<textarea id="admBulkText" class="adm-bulk-ta" rows="6" placeholder="Ana Cruz, Office Clerk, CCI IS Office, ana.cruz@isatu.edu.ph&#10;Lea Ramos, Security Guard, Security"></textarea></label>'
                + '<div class="adm-actions is-row"><button class="ofc-btn is-small" type="button" data-bulk-template>' + ICON.table + "Template for Excel</button>"
                + '<label class="ofc-btn is-small adm-file">' + ICON.upload + 'Upload filled-in file<input type="file" accept=".csv,text/csv,text/plain" data-bulk-file></label></div>'
                + '<p class="ofc-note">In Excel, save the template as "CSV (Comma delimited)" before uploading. Administrators are added one at a time.</p>'
                + (rows.length ? '<p class="ofc-label">Check before creating</p>' + bulkTable(rows)
                    + (rows.some(function (row) { return !row.ok; }) ? '<p class="ofc-banner">' + ICON.warn + "<span>Lines marked Skip are left out. Fix them above to include them.</span></p>" : "") : "");
        };
        const foot = function () {
            if (mode === "one") {
                return '<button class="ofc-btn is-quiet" type="button" data-close-sheet>Cancel</button><button class="ofc-btn is-primary" type="button" data-add-create>Create account</button>';
            }
            if (created.length) {
                return '<button class="ofc-btn is-quiet is-full" type="button" data-close-sheet>Done</button>';
            }
            const ready = rows.filter(function (row) { return row.ok; }).length;
            return '<button class="ofc-btn is-quiet" type="button" data-close-sheet>Cancel</button>'
                + (rows.length && ready ? '<button class="ofc-btn is-primary" type="button" data-bulk-create>Create ' + plural(ready, "account", "accounts") + "</button>"
                    : '<button class="ofc-btn is-primary" type="button" data-bulk-check>Check the list</button>');
        };
        const sheet = openSheet({
            title: "Add people",
            sub: "They get a temporary password and choose their own at the first sign-in.",
            head: tabs(),
            body: mode === "one" ? oneBody() : manyBody(),
            foot: foot(),
        });
        const draw = function () {
            const text = byId("admBulkText") ? byId("admBulkText").value : "";
            sheet.set({ head: tabs(), body: mode === "one" ? oneBody() : manyBody(), foot: foot() });
            if (byId("admBulkText")) {
                byId("admBulkText").value = text || rows.map(function (row) { return row.line; }).join("\n");
            }
        };
        const suggest = function () {
            if (!usernameEdited && byId("admNewUsername")) {
                byId("admNewUsername").value = suggestUsername(byId("admNewFirst").value, byId("admNewLast").value);
            }
        };
        sheet.addEventListener("input", function (event) {
            if (event.target.id === "admNewFirst" || event.target.id === "admNewLast") {
                suggest();
            } else if (event.target.id === "admNewUsername") {
                usernameEdited = event.target.value.trim() !== "";
            } else if (event.target.id === "admBulkText" && rows.length) {
                rows = [];
                sheet.set({ foot: foot() });
            }
        });
        sheet.addEventListener("change", function (event) {
            const file = event.target.closest("[data-bulk-file]");
            if (file && file.files && file.files[0]) {
                const reader = new FileReader();
                reader.onload = function () {
                    const lines = String(reader.result || "").replace(/^﻿/, "").split(/\r?\n/).filter(function (line, index) {
                        return line.trim() && !(index === 0 && /^name\s*,/i.test(line.trim()));
                    });
                    byId("admBulkText").value = lines.join("\n");
                    rows = parseBulk(byId("admBulkText").value);
                    draw();
                };
                reader.readAsText(file.files[0]);
            }
        });
        sheet.addEventListener("click", async function (event) {
            const modeButton = event.target.closest("[data-add-mode]");
            if (modeButton) {
                mode = modeButton.dataset.addMode;
                created = [];
                draw();
                return;
            }
            const roleButton = event.target.closest("[data-role]");
            if (roleButton) {
                const keep = { first: byId("admNewFirst").value, last: byId("admNewLast").value, position: byId("admNewPosition").value, username: byId("admNewUsername").value,
                    email: byId("admNewEmail").value, phone: byId("admNewPhone").value, office: byId("admNewOffice").value };
                role = roleButton.dataset.role;
                settings.office = keep.office;
                sheet.set({ body: oneBody() });
                byId("admNewFirst").value = keep.first;
                byId("admNewLast").value = keep.last;
                byId("admNewPosition").value = keep.position;
                byId("admNewUsername").value = keep.username;
                byId("admNewEmail").value = keep.email;
                byId("admNewPhone").value = keep.phone;
                return;
            }
            if (event.target.closest("[data-bulk-template]")) {
                downloadTemplate();
                return;
            }
            if (event.target.closest("[data-bulk-check]")) {
                rows = parseBulk(byId("admBulkText").value);
                if (!rows.length) {
                    sheetError(sheet, "Write one person per line first.");
                    return;
                }
                draw();
                return;
            }
            if (event.target.closest("[data-bulk-print]")) {
                printSlips(created.filter(function (row) { return row.result && row.result.ok; }).map(function (row) {
                    return { name: row.name, username: row.result.username, password: row.result.password, expires: row.result.expires, role: row.roleLabel, twoStep: row.result.twoStep };
                }));
                return;
            }
            const bulkCreate = event.target.closest("[data-bulk-create]");
            if (bulkCreate) {
                setBusy(bulkCreate, true, "Creating…");
                const ready = rows.filter(function (row) { return row.ok; });
                for (const row of ready) {
                    const data = await api("add_user.php", {
                        first_name: row.first, last_name: row.last, position: row.position, email: row.email, contact_number: "",
                        username: row.username, role: row.role, office_code: row.office ? row.office.code : "",
                    }, { stepUp: true });
                    row.result = data.success
                        ? { ok: true, username: data.user.username, password: data.temporary_password, expires: data.expires_at, twoStep: data.two_factor_required }
                        : { ok: false, message: data.cancelled ? "Not created" : (data.message || "Not created") };
                    if (data.cancelled) {
                        break;
                    }
                }
                created = rows.filter(function (row) { return row.result; });
                draw();
                const made = created.filter(function (row) { return row.result.ok; }).length;
                toast(plural(made, "account", "accounts") + " created.");
                loadUsers();
                loadOverview();
                return;
            }
            const create = event.target.closest("[data-add-create]");
            if (create) {
                const payload = {
                    first_name: byId("admNewFirst").value.trim(),
                    last_name: byId("admNewLast").value.trim(),
                    position: byId("admNewPosition").value.trim(),
                    username: byId("admNewUsername").value.trim(),
                    email: byId("admNewEmail").value.trim(),
                    contact_number: byId("admNewPhone").value.trim(),
                    role: role,
                    office_code: role === "offices" ? byId("admNewOffice").value : "",
                };
                if (!payload.first_name || !payload.last_name) {
                    sheetError(sheet, "Enter their first and last name so every action can be traced to them.");
                    return;
                }
                if (!/^[A-Za-z0-9][A-Za-z0-9._@-]{2,63}$/.test(payload.username)) {
                    sheetError(sheet, "The username needs 3 to 64 letters, numbers, dots, dashes or underscores.");
                    return;
                }
                if (role === "offices" && !payload.office_code) {
                    sheetError(sheet, "Choose the office they work in.");
                    return;
                }
                setBusy(create, true, "Creating…");
                const data = await api("add_user.php", payload, { stepUp: true });
                setBusy(create, false);
                if (!data.success) {
                    if (!data.cancelled) {
                        sheetError(sheet, data.message || "Could not create the account.");
                    }
                    return;
                }
                closeSheets([sheet]);
                openPasswordResult({
                    title: "Account created",
                    name: data.user.display_name,
                    username: data.user.username,
                    password: data.temporary_password,
                    expires: data.expires_at,
                    twoStep: data.two_factor_required,
                    role: role === "offices" ? "Office Personnel · " + ((officeByCode(payload.office_code) || {}).name || "") : ROLE_LABELS[role],
                });
                loadUsers();
                loadOverview();
            }
        });
        if (mode === "one") {
            window.setTimeout(function () { if (byId("admNewFirst")) { byId("admNewFirst").focus(); } }, 50);
        }
    }

    /* ---------- Guided office setup: Office, Staff, Map pin, Directions inside, Done ---------- */

    const WIZARD_STEPS = ["Office", "Staff", "Map pin", "Inside", "Done"];

    async function openWizard(code, startStep) {
        let office = code ? officeByCode(code) : null;
        let step = startStep || (office ? (!office.setup.staff ? 2 : !office.setup.pin ? 3 : !office.setup.inside ? 4 : 5) : 1);
        let directions = null;
        let pinMap = null;
        let pinMarker = null;
        let created = null;
        const done = { staff: office ? office.setup.staff : false, pin: office ? office.setup.pin : false, inside: office ? office.setup.inside : false };
        const draft = { name: "", code: "", building: "", floor: "", room: "", message: "", staffMode: "now", first: "", last: "", username: "", email: "", position: "" };
        let codeEdited = false;

        if (office) {
            const all = await api("admin_office_directions.php");
            directions = all.success && all.directions ? all.directions[office.code] || null : null;
            if (directions) {
                draft.building = directions.building;
                draft.floor = directions.floor;
                draft.room = directions.room;
                draft.message = directions.visitor_message;
            }
        }
        const title = function () {
            return office && !created ? "Finish setting up " + esc(office.name) : (office ? "Set up " + esc(office.name) : "Set up a new office");
        };
        const head = function () {
            return '<div class="adm-wz-steps">' + WIZARD_STEPS.map(function (name, index) {
                return '<i class="' + (index + 1 < step ? "done" : index + 1 === step ? "now" : "") + '"></i>';
            }).join("") + '</div><div class="adm-wz-names">' + WIZARD_STEPS.map(function (name, index) {
                return '<span class="' + (index + 1 === step ? "now" : "") + '">' + name + "</span>";
            }).join("") + "</div>";
        };
        const floorOptions = function (selected) {
            return '<option value="">Choose</option>' + FLOORS.concat(selected && FLOORS.indexOf(selected) === -1 ? [selected] : []).map(function (floor) {
                return '<option' + (floor === selected ? " selected" : "") + ">" + esc(floor) + "</option>";
            }).join("");
        };
        const bodies = {
            1: function () {
                return '<label class="ofc-field"><span>Office name</span><input id="admWzName" maxlength="100" value="' + esc(draft.name) + '" placeholder="e.g. CCI Student Council Office"></label>'
                    + '<div class="adm-two"><label class="ofc-field"><span>Short code</span><input id="admWzCode" maxlength="16" value="' + esc(draft.code) + '" placeholder="Made from the name" spellcheck="false"></label>'
                    + '<label class="ofc-field"><span>Building</span><input id="admWzBuilding" maxlength="60" value="' + esc(draft.building) + '" placeholder="e.g. CCI Building"></label></div>'
                    + '<div class="adm-two"><label class="ofc-field"><span>Floor</span><select id="admWzFloor">' + floorOptions(draft.floor) + "</select></label>"
                    + '<label class="ofc-field"><span>Room (optional)</span><input id="admWzRoom" maxlength="30" value="' + esc(draft.room) + '" placeholder="e.g. Room 104"></label></div>'
                    + '<p class="ofc-note">Visitors see the name in the app. The short code is for reports and can\'t be changed later.</p>';
            },
            2: function () {
                const current = office ? staffOf(office.code) : [];
                if (current.length) {
                    return '<p class="ofc-banner is-green">' + ICON.check + "<span><b>" + esc(current.map(function (user) { return user.display_name || user.username; }).join(", ")) + "</b> "
                        + (current.length === 1 ? "answers" : "answer") + " this office's requests.</span></p><p class=\"ofc-note\">You can add more people later from the office or People.</p>";
                }
                return '<p class="ofc-note">Someone has to answer this office\'s requests, or visitors can\'t book it.</p>'
                    + '<label class="adm-pickrow"><input type="radio" name="admWzStaff" value="now"' + (draft.staffMode === "now" ? " checked" : "") + '><span><b>Create their account now</b><small>They get a temporary password to sign in</small></span></label>'
                    + '<div class="adm-wz-staff"' + (draft.staffMode === "now" ? "" : " hidden") + '>'
                    + '<div class="adm-two"><label class="ofc-field"><span>First name</span><input id="admWzFirst" maxlength="60" value="' + esc(draft.first) + '"></label>'
                    + '<label class="ofc-field"><span>Last name</span><input id="admWzLast" maxlength="60" value="' + esc(draft.last) + '"></label></div>'
                    + '<label class="ofc-field"><span>Position</span><input id="admWzPosition" maxlength="100" value="' + esc(draft.position) + '" placeholder="e.g. Office Clerk"></label>'
                    + '<div class="adm-two"><label class="ofc-field"><span>Username</span><input id="admWzUsername" maxlength="64" value="' + esc(draft.username) + '" spellcheck="false"></label>'
                    + '<label class="ofc-field"><span>Email (optional)</span><input id="admWzEmail" type="email" maxlength="190" value="' + esc(draft.email) + '"></label></div></div>'
                    + '<label class="adm-pickrow"><input type="radio" name="admWzStaff" value="later"' + (draft.staffMode === "later" ? " checked" : "") + '><span><b>Later</b><small>The office stays hidden from visitors until someone is assigned</small></span></label>';
            },
            3: function () {
                return '<p class="ofc-note">Drag the pin to the entrance of the building. The app walks visitors to this point.</p>'
                    + '<div class="adm-wz-map" id="admWzMap"></div>'
                    + '<p class="ofc-note">The walking route from the gate can be recorded later, on a laptop, in Campus map.</p>';
            },
            4: function () {
                return '<div class="adm-two"><label class="ofc-field"><span>Building</span><input id="admWzBuilding" maxlength="60" value="' + esc(draft.building) + '"></label>'
                    + '<label class="ofc-field"><span>Floor</span><select id="admWzFloor">' + floorOptions(draft.floor) + "</select></label></div>"
                    + '<label class="ofc-field"><span>Room (optional)</span><input id="admWzRoom" maxlength="30" value="' + esc(draft.room) + '"></label>'
                    + '<label class="ofc-field"><span>Description for visitors</span><textarea id="admWzMessage" rows="3" maxlength="160" placeholder="e.g. Enter the main door and turn left. Our office is the second door on the right.">' + esc(draft.message) + "</textarea></label>"
                    + '<p class="ofc-note">Shown and read aloud in the app when visitors reach the building. Step-by-step photos can be added later in Campus map › Inside.</p>';
            },
            5: function () {
                const name = office ? office.name : draft.name;
                const staffNames = office ? staffOf(office.code).map(function (user) { return user.display_name || user.username; }) : [];
                const line = function (ok, label, detail, later) {
                    return "<li><span class=\"" + (ok ? "ok" : "") + "\">" + (ok ? "✓" : "○") + "</span><span><b>" + label + "</b>" + (detail ? " · " + esc(detail) : "") + "</span>"
                        + (!ok && later ? '<span class="adm-later">' + later + "</span>" : "") + "</li>";
                };
                return '<p class="ofc-banner ' + (done.staff ? "is-green" : "") + '">' + (done.staff ? ICON.check : ICON.warn) + "<span><b>" + esc(name) + (done.staff ? " is ready.</b> Visitors can book it once its staff set the visitor hours on their Hours page."
                        : " is saved.</b> Visitors can book it once someone is assigned to it.") + "</span></p>"
                    + '<ul class="adm-wz-done">'
                    + line(true, "Office", name)
                    + line(done.staff, "Staff", staffNames.join(", ") || (created ? created.name : ""), "Add later")
                    + line(done.pin, "Map pin", done.pin ? "at the entrance" : "", "Later")
                    + line(done.inside, "Directions inside", "", "Later")
                    + line(office ? office.setup.route : false, "Walking route", "", "Later, on a laptop")
                    + line(office ? office.setup.hours : false, "Visitor hours", "", "Set by the office")
                    + "</ul>"
                    + (created ? '<div class="adm-temp"><span class="ofc-note">' + esc(created.name) + "'s temporary password · username <b>" + esc(created.username) + "</b></span><code>" + esc(created.password)
                        + '</code><span class="ofc-note">Shown only now. Works until ' + esc(whenText(created.expires)) + '.</span><div class="adm-actions is-row"><button class="ofc-btn is-small" type="button" data-copy="'
                        + esc(created.password) + '">' + ICON.copy + 'Copy</button><button class="ofc-btn is-small" type="button" data-wz-slip>' + ICON.print + "Print a slip</button></div></div>" : "");
            },
        };
        const foot = function () {
            return (step > 1 && step < 5 && !(step === 2 && office && !created && startStep === 2) ? '<button class="ofc-btn is-quiet" type="button" data-wz-back>Back</button>' : "")
                + (step === 3 || step === 4 ? '<button class="ofc-btn is-quiet" type="button" data-wz-skip>Skip for now</button>' : "")
                + (step < 5 ? '<button class="ofc-btn is-primary" type="button" data-wz-next>' + (step === 1 ? "Save and continue" : "Next") + "</button>"
                    : '<button class="ofc-btn is-primary" type="button" data-wz-finish>Finish</button>');
        };
        const sheet = openSheet({
            title: title(),
            head: head(),
            body: bodies[step](),
            foot: foot(),
            onClose: function () {
                if (pinMap) {
                    pinMap.remove();
                    pinMap = null;
                }
            },
        });
        const draw = function () {
            if (pinMap) {
                pinMap.remove();
                pinMap = null;
            }
            sheet.set({ title: title(), head: head(), body: bodies[step](), foot: foot() });
            if (step === 3) {
                window.setTimeout(drawMap, 60);
            }
        };
        const keep = function () {
            ["Name", "Code", "Building", "Floor", "Room", "Message", "First", "Last", "Position", "Username", "Email"].forEach(function (key) {
                const input = byId("admWz" + key);
                if (input) {
                    draft[key.charAt(0).toLowerCase() + key.slice(1)] = input.value.trim();
                }
            });
        };
        const drawMap = async function () {
            const host = byId("admWzMap");
            if (!host || typeof L === "undefined") {
                if (host) {
                    host.innerHTML = '<p class="ofc-empty">The map could not load. Check your internet connection, or skip this step.</p>';
                }
                return;
            }
            pinMap = L.map(host, CampusMap.mapOptions({ attributionControl: false, preferCanvas: true })).setView([10.7177, 122.5559], 17);
            CampusMap.addBaseLayer(pinMap);
            const campus = await CampusMap.load();
            if (!pinMap) {
                return;
            }
            const overlay = L.layerGroup().addTo(pinMap);
            CampusMap.drawOverlay(overlay, campus);
            const existing = campus && (campus.offices || []).find(function (pin) { return office && pin.code === office.code; });
            const bounds = CampusMap.boundaryBounds(campus);
            let start = existing ? [existing.latitude, existing.longitude] : null;
            if (!start && bounds) {
                const center = bounds.getCenter();
                start = [center.lat, center.lng];
            }
            start = start || [10.7177, 122.5559];
            if (bounds && !existing) {
                pinMap.fitBounds(bounds, { padding: [14, 14] });
            } else {
                pinMap.setView(start, 19);
            }
            pinMarker = L.marker(start, {
                draggable: true,
                icon: L.divIcon({ className: "", html: '<span class="adm-pin"></span>', iconSize: [18, 18], iconAnchor: [9, 18] }),
            }).addTo(pinMap).bindTooltip(office ? office.name : draft.name, { permanent: true, direction: "right", offset: [8, -10] });
            pinMap.on("click", function (event) { pinMarker.setLatLng(event.latlng); });
            window.setTimeout(function () { if (pinMap) { pinMap.invalidateSize(); } }, 200);
        };
        const saveDirections = async function () {
            const payload = {
                building: draft.building, floor: draft.floor, room: draft.room, visitor_message: draft.message,
                steps: directions ? directions.steps.map(function (item) { return { text: item.text, photo: item.photo }; }) : [],
            };
            const form = new FormData();
            form.append("office_code", office.code);
            form.append("payload", JSON.stringify(payload));
            let data;
            try {
                const response = await fetch("admin_office_directions.php", { method: "POST", body: form, credentials: "same-origin" });
                data = await response.json();
            } catch (error) {
                data = { success: false, message: NO_CONNECTION };
            }
            return data;
        };
        const next = async function (button) {
            sheetError(sheet, "");
            keep();
            if (step === 1) {
                if (draft.name.length < 2) {
                    sheetError(sheet, "Enter the office name.");
                    return;
                }
                setBusy(button, true, "Saving…");
                const data = await api("admin_departments.php", {
                    action: "create", name: draft.name, code: draft.code.toUpperCase(),
                    location: [draft.building, draft.floor, draft.room].filter(Boolean).join(", "), description: "",
                });
                if (!data.success) {
                    setBusy(button, false);
                    if (!data.cancelled) {
                        sheetError(sheet, data.message || "Could not add the office.");
                    }
                    return;
                }
                await loadOverview();
                office = officeByCode(data.code);
                document.dispatchEvent(new CustomEvent("departments:updated"));
                if (office && (draft.building || draft.floor || draft.room)) {
                    const saved = await saveDirections();
                    done.inside = Boolean(saved && saved.success);
                }
                setBusy(button, false);
                step = 2;
                draw();
                return;
            }
            if (step === 2) {
                const radio = $("input[name='admWzStaff']:checked", sheet);
                if (radio && radio.value === "now") {
                    if (!draft.first || !draft.last) {
                        sheetError(sheet, "Enter their first and last name, or choose Later.");
                        return;
                    }
                    const username = draft.username || suggestUsername(draft.first, draft.last);
                    setBusy(button, true, "Creating…");
                    const data = await api("add_user.php", {
                        first_name: draft.first, last_name: draft.last, position: draft.position, email: draft.email, contact_number: "",
                        username: username, role: "offices", office_code: office.code,
                    }, { stepUp: true });
                    setBusy(button, false);
                    if (!data.success) {
                        if (!data.cancelled) {
                            sheetError(sheet, data.message || "Could not create the account.");
                        }
                        return;
                    }
                    created = { name: data.user.display_name, username: data.user.username, password: data.temporary_password, expires: data.expires_at, twoStep: data.two_factor_required };
                    done.staff = true;
                    await loadUsers();
                }
                step = 3;
                draw();
                return;
            }
            if (step === 3) {
                if (!pinMarker) {
                    step = 4;
                    draw();
                    return;
                }
                const point = pinMarker.getLatLng();
                setBusy(button, true, "Saving…");
                const data = await api("admin_office_pin.php", { office_code: office.code, latitude: point.lat, longitude: point.lng, placed_by: "map" });
                setBusy(button, false);
                if (!data.success) {
                    if (!data.cancelled) {
                        sheetError(sheet, data.message || "Could not save the pin.");
                    }
                    return;
                }
                done.pin = true;
                document.dispatchEvent(new CustomEvent("admin:campuschanged"));
                step = 4;
                draw();
                return;
            }
            if (step === 4) {
                if (!draft.building && !draft.floor && !draft.room && !draft.message) {
                    step = 5;
                    draw();
                    return;
                }
                setBusy(button, true, "Saving…");
                const data = await saveDirections();
                setBusy(button, false);
                if (!data || !data.success) {
                    sheetError(sheet, (data && data.message) || "Could not save the directions.");
                    return;
                }
                done.inside = true;
                await loadOverview();
                office = officeByCode(office.code) || office;
                step = 5;
                draw();
            }
        };
        sheet.addEventListener("input", function (event) {
            if (event.target.id === "admWzName" && !codeEdited) {
                window.clearTimeout(sheet.suggestTimer);
                const name = event.target.value.trim();
                sheet.suggestTimer = window.setTimeout(async function () {
                    if (name.length < 2) {
                        return;
                    }
                    const data = await api("admin_departments.php?suggest_code=" + encodeURIComponent(name));
                    if (data.success && byId("admWzCode") && !codeEdited) {
                        byId("admWzCode").value = data.code;
                    }
                }, 350);
            } else if (event.target.id === "admWzCode") {
                codeEdited = event.target.value.trim() !== "";
                event.target.value = event.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, "");
            } else if ((event.target.id === "admWzFirst" || event.target.id === "admWzLast") && byId("admWzUsername")) {
                byId("admWzUsername").value = suggestUsername(byId("admWzFirst").value, byId("admWzLast").value);
            }
        });
        sheet.addEventListener("change", function (event) {
            if (event.target.name === "admWzStaff") {
                draft.staffMode = event.target.value;
                $(".adm-wz-staff", sheet).hidden = draft.staffMode !== "now";
            }
        });
        sheet.addEventListener("click", function (event) {
            const nextButton = event.target.closest("[data-wz-next]");
            if (nextButton) {
                next(nextButton);
            } else if (event.target.closest("[data-wz-skip]")) {
                keep();
                step += 1;
                draw();
            } else if (event.target.closest("[data-wz-back]")) {
                keep();
                step = Math.max(office ? 2 : 1, step - 1);
                draw();
            } else if (event.target.closest("[data-wz-slip]") && created) {
                printSlips([{ name: created.name, username: created.username, password: created.password, expires: created.expires, role: "Office Personnel · " + office.name, twoStep: created.twoStep }]);
            } else if (event.target.closest("[data-wz-finish]")) {
                closeSheets();
                toast((office ? office.name : "The office") + (done.staff ? " is set up." : " is saved. Add its staff when you can."));
                afterOfficeChange();
            }
        });
        if (step === 3) {
            window.setTimeout(drawMap, 320);
        }
    }

    /* ---------- Close the whole campus ---------- */

    function isoDay(date) {
        return date.getFullYear() + "-" + String(date.getMonth() + 1).padStart(2, "0") + "-" + String(date.getDate()).padStart(2, "0");
    }

    function openClosure(closureId) {
        const existing = closureId && S.overview ? S.overview.closures.find(function (item) { return item.id === Number(closureId); }) : null;
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        const state = {
            allDay: existing ? existing.all_day : true,
            from: existing ? existing.starts_at.slice(0, 10) : isoDay(tomorrow),
            to: existing ? isoDay(new Date(parseDate(existing.ends_at) - (existing.all_day ? 1000 : 0))) : isoDay(tomorrow),
            start: existing && !existing.all_day ? existing.starts_at.slice(11, 16) : "13:00",
            end: existing && !existing.all_day ? existing.ends_at.slice(11, 16) : "17:00",
            reason: existing ? existing.reason : CLOSE_REASONS[0],
            message: existing ? existing.message : CLOSE_MESSAGE,
            timer: 0,
            preview: null,
        };
        const when = function () {
            return state.allDay ? { from: state.from, to: state.to, all_day: true } : { from: state.from, all_day: false, start_time: state.start, end_time: state.end };
        };
        const body = function () {
            return '<div class="ofc-seg adm-sheet-seg"><button type="button" data-closure-mode="day" class="' + (state.allDay ? "is-active" : "") + '">All day</button>'
                + '<button type="button" data-closure-mode="hours" class="' + (state.allDay ? "" : "is-active") + '">Only some hours</button></div>'
                + (state.allDay
                    ? '<div class="adm-two"><label class="ofc-field"><span>From</span><input type="date" data-closure-field="from" value="' + esc(state.from) + '" min="' + isoDay(new Date()) + '"></label>'
                        + '<label class="ofc-field"><span>To</span><input type="date" data-closure-field="to" value="' + esc(state.to) + '" min="' + isoDay(new Date()) + '"></label></div>'
                    : '<label class="ofc-field"><span>Day</span><input type="date" data-closure-field="from" value="' + esc(state.from) + '" min="' + isoDay(new Date()) + '"></label>'
                        + '<div class="adm-two"><label class="ofc-field"><span>Closed from</span><input type="time" data-closure-field="start" value="' + esc(state.start) + '"></label>'
                        + '<label class="ofc-field"><span>Until</span><input type="time" data-closure-field="end" value="' + esc(state.end) + '"></label></div>')
                + '<div class="ofc-field"><span>Reason visitors see</span><div class="ofc-qchips adm-qchips">' + CLOSE_REASONS.map(function (reason) {
                    return '<button type="button" data-closure-reason="' + esc(reason) + '" class="' + (reason === state.reason ? "is-on" : "") + '">' + esc(reason) + "</button>";
                }).join("") + '</div><input data-closure-field="reason" maxlength="120" value="' + esc(state.reason) + '" aria-label="Reason visitors see"></div>'
                + '<label class="ofc-field"><span>Message to visitors with a booking</span><textarea data-closure-field="message" rows="3" maxlength="500">' + esc(state.message) + "</textarea></label>"
                + '<div class="adm-affects" id="admClosureAffects"><span>Checking who this affects…</span></div>';
        };
        const sheet = openSheet({
            title: existing ? "Change the campus closure" : "Close the campus to visitors",
            sub: "Every office at once: a class suspension, a holiday, bad weather.",
            body: body(),
            foot: '<button class="ofc-btn is-quiet" type="button" data-close-sheet>Cancel</button><button class="ofc-btn is-danger" type="button" data-closure-go>' + (existing ? "Save the change" : "Close the campus") + "</button>",
        });
        const renderPreview = function (data) {
            const host = byId("admClosureAffects");
            if (!host) {
                return;
            }
            if (!data.success) {
                host.innerHTML = '<span class="adm-bad">' + esc(data.message || "Check the dates.") + "</span>";
                state.preview = null;
                return;
            }
            const preview = data.preview;
            state.preview = preview;
            host.innerHTML = "<span><b>" + esc(preview.when) + "</b> · all " + plural(preview.offices, "office", "offices") + "</span>"
                + (preview.visits
                    ? "<span><b>" + plural(preview.visits, "booked visit is", "booked visits are") + " cancelled</b>: " + esc(preview.by_office.map(function (row) { return row.visits + " at " + row.office; }).join(", ")) + ".</span>"
                        + "<span><b>" + plural(preview.with_app, "visitor gets", "visitors get") + "</b> a notification with your message and can book another day."
                        + (preview.visitors > preview.with_app ? " " + plural(preview.visitors - preview.with_app, "visitor", "visitors") + " registered at the gate can't be told by the app." : "") + "</span>"
                    : "<span>No visits are booked in that time.</span>")
                + "<span>New requests and walk-ins for that time are refused. Office staff see it on their Hours page.</span>";
        };
        const refreshPreview = function () {
            window.clearTimeout(state.timer);
            state.timer = window.setTimeout(async function () {
                const data = await api("admin_campus_closure.php", Object.assign({ action: "preview" }, when()));
                renderPreview(data);
            }, 250);
        };
        sheet.addEventListener("input", function (event) {
            const field = event.target.dataset.closureField;
            if (!field) {
                return;
            }
            state[field] = event.target.value;
            if (field === "from" && state.allDay && state.to < state.from) {
                state.to = state.from;
                const to = $("[data-closure-field='to']", sheet);
                if (to) {
                    to.value = state.to;
                }
            }
            if (field === "reason") {
                $$("[data-closure-reason]", sheet).forEach(function (chip) { chip.classList.toggle("is-on", chip.dataset.closureReason === state.reason); });
            }
            if (field !== "reason" && field !== "message") {
                refreshPreview();
            }
        });
        sheet.addEventListener("click", async function (event) {
            const mode = event.target.closest("[data-closure-mode]");
            if (mode) {
                state.allDay = mode.dataset.closureMode === "day";
                sheet.set({ body: body() });
                refreshPreview();
                return;
            }
            const reason = event.target.closest("[data-closure-reason]");
            if (reason) {
                state.reason = reason.dataset.closureReason;
                $("[data-closure-field='reason']", sheet).value = state.reason;
                $$("[data-closure-reason]", sheet).forEach(function (chip) { chip.classList.toggle("is-on", chip === reason); });
                return;
            }
            const go = event.target.closest("[data-closure-go]");
            if (go) {
                if (!state.preview) {
                    sheetError(sheet, "Check the dates first.");
                    return;
                }
                if (!(await AppDialog.confirm({
                    title: (existing ? "Change the closure to " : "Close the campus ") + state.preview.when + "?",
                    message: (state.preview.visits ? plural(state.preview.visits, "booked visit is", "booked visits are") + " cancelled and their visitors told. " : "No booked visits are affected. ")
                        + "You can reopen the campus later; cancelled visits stay cancelled.",
                    confirmLabel: existing ? "Save the change" : "Close the campus",
                    danger: true,
                }))) {
                    return;
                }
                setBusy(go, true, "Saving…");
                const data = await api("admin_campus_closure.php", Object.assign({ action: "close", reason: state.reason, message: state.message, closure_id: existing ? existing.id : 0 }, when()), { stepUp: true });
                setBusy(go, false);
                if (!data.success) {
                    if (!data.cancelled) {
                        sheetError(sheet, data.message || "Could not close the campus.");
                    }
                    return;
                }
                closeSheets();
                toast(data.message || "Campus closed.");
                loadOverview();
                loadHomeActivity();
            }
        });
        refreshPreview();
    }

    async function reopenCampus(closureId) {
        const closure = S.overview.closures.find(function (item) { return item.id === Number(closureId); });
        if (!closure || !(await AppDialog.confirm({
            title: "Reopen the campus for " + closure.when + "?",
            message: "Visitors can book that time again. The cancelled visits stay cancelled; those visitors were already told they can book again.",
            confirmLabel: "Reopen",
        }))) {
            return;
        }
        const data = await act("admin_campus_closure.php", { action: "reopen", closure_id: closure.id }, "Couldn't reopen the campus");
        if (data.success) {
            toast(data.message || "The campus is open again.");
            loadOverview();
            loadHomeActivity();
        }
    }

    /* ---------- Request reminders and the morning summary email ---------- */

    const TARGET_LABELS = { 120: "2 hours", 240: "4 hours", 480: "8 hours", 540: "1 working day" };

    async function openReminders() {
        const sheet = openSheet({ title: "Request reminders", sub: "So offices answer before requests expire, without you chasing them.", body: '<p class="ofc-empty">Loading…</p>' });
        const data = await api("admin_settings.php");
        if (!sheet.open) {
            return;
        }
        if (!data.success) {
            sheet.set({ body: '<p class="ofc-empty">' + esc(data.message || "Could not load the settings.") + "</p>" });
            return;
        }
        const settings = data.reminders;
        const preview = function (target) {
            return (data.reminder_preview[String(target)] || []).map(function (line) { return "<span>" + esc(line) + "</span>"; }).join("");
        };
        sheet.set({
            body: '<div class="adm-setting"><span><strong>Automatic reminders</strong><small>For every office</small></span><label class="office-switch"><input id="admRemOn" type="checkbox"' + (settings.on ? " checked" : "")
                + '><span aria-hidden="true"></span><span class="sr-only">Automatic reminders</span></label></div>'
                + '<div class="adm-setting"><span><strong>Answer within</strong><small>Counted Monday to Friday, 8 AM to 5 PM</small></span><select class="adm-sel" id="admRemTarget">'
                + data.reminder_targets.map(function (target) { return '<option value="' + target + '"' + (target === settings.target_minutes ? " selected" : "") + ">" + TARGET_LABELS[target] + "</option>"; }).join("") + "</select></div>"
                + '<p class="ofc-label">When a request waits</p>'
                + '<label class="adm-checkline"><input type="checkbox" id="admRemTargetOn"' + (settings.at_target ? " checked" : "") + "><span><b>Remind the office's staff</b> when it passes the target.</span></label>"
                + '<label class="adm-checkline"><input type="checkbox" id="admRemExpiry"' + (settings.before_expiry ? " checked" : "") + "><span><b>Remind them again</b> 1 hour before it expires.</span></label>"
                + '<label class="adm-checkline"><input type="checkbox" id="admRemTell"' + (settings.tell_admin ? " checked" : "") + "><span><b>Tell me</b> under Needs your attention if it's still not answered after that.</span></label>"
                + '<div class="adm-affects" id="admRemPreview">' + preview(settings.target_minutes) + "</div>"
                + '<p class="ofc-note">Offices get one notification for all their waiting requests, on their dashboard. "Remind the office" still works any time.</p>',
            foot: '<button class="ofc-btn is-quiet" type="button" data-close-sheet>Cancel</button><button class="ofc-btn is-primary" type="button" data-rem-save>Save</button>',
        });
        sheet.addEventListener("change", function (event) {
            if (event.target.id === "admRemTarget") {
                byId("admRemPreview").innerHTML = preview(Number(event.target.value));
            }
        });
        sheet.addEventListener("click", async function (event) {
            const save = event.target.closest("[data-rem-save]");
            if (!save) {
                return;
            }
            setBusy(save, true, "Saving…");
            const result = await api("admin_settings.php", {
                kind: "reminders",
                on: byId("admRemOn").checked,
                target_minutes: Number(byId("admRemTarget").value),
                at_target: byId("admRemTargetOn").checked,
                before_expiry: byId("admRemExpiry").checked,
                tell_admin: byId("admRemTell").checked,
            });
            setBusy(save, false);
            if (!result.success) {
                if (!result.cancelled) {
                    sheetError(sheet, result.message || "Could not save.");
                }
                return;
            }
            closeSheets();
            toast(result.message);
            loadOverview();
        });
    }

    async function openDigest() {
        const sheet = openSheet({ title: "Morning summary email", sub: "What needs you and the last day's numbers, before you open the dashboard.", body: '<p class="ofc-empty">Loading…</p>' });
        const data = await api("admin_settings.php");
        if (!sheet.open) {
            return;
        }
        if (!data.success) {
            sheet.set({ body: '<p class="ofc-empty">' + esc(data.message || "Could not load the settings.") + "</p>" });
            return;
        }
        const settings = data.digest;
        const hourLabel = function (hour) { return (hour > 12 ? hour - 12 : hour) + ":00 " + (hour >= 12 ? "PM" : "AM"); };
        sheet.set({
            body: '<div class="adm-setting"><span><strong>Send me a summary</strong><small>Monday to Friday</small></span><label class="office-switch"><input id="admDigestOn" type="checkbox"' + (settings.on ? " checked" : "")
                + '><span aria-hidden="true"></span><span class="sr-only">Send me a summary</span></label></div>'
                + '<div class="adm-setting"><span><strong>At</strong></span><select class="adm-sel" id="admDigestHour">'
                + data.digest_hours.map(function (hour) { return '<option value="' + hour + '"' + (hour === settings.hour ? " selected" : "") + ">" + hourLabel(hour) + "</option>"; }).join("") + "</select></div>"
                + '<div class="adm-setting"><span><strong>To</strong><small>' + esc(data.digest_email || "No email on your account yet") + "</small></span>"
                + '<button class="ofc-btn is-small" type="button" data-edit-person="' + S.me.id + '">' + (data.digest_email ? "Change" : "Add email") + "</button></div>"
                + '<p class="ofc-label">Include</p>'
                + '<label class="adm-checkline"><input type="checkbox" id="admDigestAttention"' + (settings.attention ? " checked" : "") + "><span>Needs your attention</span></label>"
                + '<label class="adm-checkline"><input type="checkbox" id="admDigestNumbers"' + (settings.numbers ? " checked" : "") + "><span>The last working day's numbers</span></label>"
                + '<label class="adm-checkline"><input type="checkbox" id="admDigestSlowest"' + (settings.slowest ? " checked" : "") + "><span>The office slowest to answer</span></label>"
                + '<label class="adm-checkline"><input type="checkbox" id="admDigestAlways"' + (settings.always ? " checked" : "") + "><span>Send it even when nothing needs me</span></label>"
                + '<p class="ofc-banner is-blue">' + ICON.shield + "<span>The email never includes visitors' contact details or where they went.</span></p>"
                + (data.mail_ready ? "" : '<p class="ofc-banner">' + ICON.warn + "<span>This server can't send email yet, so nothing is sent until email is set up (DEPLOYMENT.md).</span></p>")
                + (data.digest_last_sent ? '<p class="ofc-note">Last checked for sending on ' + esc(dayText(data.digest_last_sent)) + ".</p>" : ""),
            foot: '<button class="ofc-btn is-quiet" type="button" data-digest-example>See an example</button><button class="ofc-btn is-primary" type="button" data-digest-save>Save</button>',
        });
        sheet.addEventListener("click", async function (event) {
            if (event.target.closest("[data-digest-example]")) {
                openDigestExample(Number(byId("admDigestHour").value));
                return;
            }
            const save = event.target.closest("[data-digest-save]");
            if (!save) {
                return;
            }
            setBusy(save, true, "Saving…");
            const result = await api("admin_settings.php", {
                kind: "digest",
                on: byId("admDigestOn").checked,
                hour: Number(byId("admDigestHour").value),
                attention: byId("admDigestAttention").checked,
                numbers: byId("admDigestNumbers").checked,
                slowest: byId("admDigestSlowest").checked,
                always: byId("admDigestAlways").checked,
            });
            setBusy(save, false);
            if (!result.success) {
                if (!result.cancelled) {
                    sheetError(sheet, result.message || "Could not save.");
                }
                return;
            }
            S.digest = result.digest;
            closeSheets();
            toast(result.message);
            renderDigestNote();
        });
    }

    function renderDigestNote() {
        if (S.digest) {
            byId("admMenuDigest").textContent = S.digest.on ? "On: weekdays at " + (S.digest.hour > 12 ? S.digest.hour - 12 : S.digest.hour) + ":00 AM" : "Off";
        }
    }

    function openDigestExample(hour) {
        const items = S.overview ? S.overview.attention : [];
        const days = S.overview ? S.overview.last_days : [];
        const yesterday = days.length > 1 ? days[days.length - 2] : null;
        openSheet({
            title: "Example of the email",
            sub: "With today's numbers. The real one comes at " + hour + ":00 AM.",
            drawer: true,
            body: '<div class="adm-email"><div class="adm-email-head"><img src="assets/images/isatu-logo.png" alt=""><div><strong>Good morning, ' + esc(firstName(S.me.name)) + "</strong><span>Your summary for "
                + esc(new Date().toLocaleDateString([], { weekday: "long", month: "long", day: "numeric" })) + "</span></div></div>"
                + '<div class="adm-email-body"><h3>Needs your attention (' + items.length + ")</h3>"
                + (items.length ? "<ol>" + items.map(function (item) { return "<li><b>" + esc(item.title) + ".</b> " + esc(item.text) + "</li>"; }).join("") + "</ol>" : "<p>Nothing. All clear.</p>")
                + '<span class="adm-email-cta">Open the dashboard</span>'
                + (yesterday ? "<h3>Last working day</h3><p>" + plural(yesterday.visits, "visit", "visits") + " on " + esc(dayText(yesterday.date)) + ", with requests answered in time, visitors who didn't come and the average visit.</p>" : "")
                + '</div><div class="adm-email-foot">It never includes visitors\' contact details or where they went.</div></div>',
        });
    }

    /* ---------- Exports and the printable report ---------- */

    function openReportExport() {
        const period = S.report.period;
        const label = { today: "Today", week: "This week", month: "This month", quarter: "This quarter" }[period];
        openSheet({
            title: "Export the report",
            sub: esc(label) + (S.report.data ? " · " + esc(reportRangeText(S.report.data.range)) : ""),
            body: '<div class="adm-options">'
                + '<button class="adm-option is-main" type="button" data-print-report><span class="ofc-menu-ico">' + ICON.file + "</span><span><strong>Report to print or save as PDF</strong>"
                + "<small>One page: the numbers, what stands out, visits per day, the offices and the reasons, with Prepared by and Noted by lines</small></span>" + ICON.chev + "</button>"
                + '<a class="adm-option" href="admin_analytics.php?period=' + encodeURIComponent(period) + '&amp;format=xlsx" download><span class="ofc-menu-ico">' + ICON.table + "</span><span><strong>Excel</strong>"
                + "<small>Every visit, with a summary sheet</small></span>" + ICON.chev + "</a>"
                + '<a class="adm-option" href="admin_analytics.php?period=' + encodeURIComponent(period) + '&amp;format=csv" download><span class="ofc-menu-ico">' + ICON.table + "</span><span><strong>CSV</strong>"
                + "<small>Plain data for other programs</small></span>" + ICON.chev + "</a></div>"
                + '<p class="ofc-note">In the print window, choose "Save as PDF" to keep a file.</p>',
        });
    }

    function printReport() {
        const data = S.report.data;
        if (!data) {
            return;
        }
        const start = parseDate(data.range.start);
        const titles = { today: "Daily Visitor Report · " + start.toLocaleDateString([], { month: "long", day: "numeric", year: "numeric" }),
            week: "Weekly Visitor Report · week of " + start.toLocaleDateString([], { month: "long", day: "numeric", year: "numeric" }),
            month: "Monthly Visitor Report · " + start.toLocaleDateString([], { month: "long", year: "numeric" }),
            quarter: "Quarterly Visitor Report · " + start.toLocaleDateString([], { month: "long" }) + " to " + new Date(start.getFullYear(), start.getMonth() + 2, 1).toLocaleDateString([], { month: "long", year: "numeric" }) };
        const now = data.current;
        const max = Math.max(1, ...data.series.map(function (item) { return item.visits; }));
        const visitDelta = data.previous.visits > 0 ? percentChange(now.visits, data.previous.visits) : null;
        byId("admPrint").innerHTML = '<article class="adm-pdf">'
            + '<div class="adm-pdf-head"><img src="assets/images/isatu-logo.png" alt=""><div><h2>' + esc(titles[S.report.period]) + "</h2>"
            + "<p>ISATU Visitor Management · " + esc(reportRangeText(data.range)) + "<br>Prepared on " + esc(new Date().toLocaleDateString([], { month: "long", day: "numeric", year: "numeric" }))
            + " by " + esc(S.me.name) + ", " + esc(S.me.position || "System Administrator") + "</p></div></div>"
            + '<div class="adm-pdf-kpis">'
            + "<div><b>" + now.visits + "</b><span>Visits" + (visitDelta !== null ? " (" + (visitDelta >= 0 ? "▲ " : "▼ ") + Math.abs(visitDelta) + "% vs " + esc(data.previous_label) + ")" : "") + "</span></div>"
            + "<div><b>" + (now.average_minutes !== null ? durationText(now.average_minutes) : "—") + "</b><span>Average visit</span></div>"
            + "<div><b>" + (now.answered_in_time !== null ? now.answered_in_time + "%" : "—") + "</b><span>Requests answered in time</span></div>"
            + "<div><b>" + now.no_shows + "</b><span>Didn't come" + (now.approved ? " (" + Math.round(now.no_shows * 100 / Math.max(now.approved, now.no_shows)) + "% of approved)" : "") + "</span></div></div>"
            + "<h3>What stands out</h3><ul>" + (data.findings.length ? data.findings.map(function (finding) { return "<li>" + finding.html + "</li>"; }).join("") : "<li>Not enough visits in this period to show patterns yet.</li>") + "</ul>"
            + "<h3>Visits per " + (S.report.period === "today" ? "hour" : S.report.period === "quarter" ? "week" : "day") + '</h3><div class="adm-pdf-bars">'
            + data.series.map(function (item) {
                return '<div><i style="height:' + Math.max(1, item.visits / max * 100) + '%"></i><span>' + esc(item.label) + "</span></div>";
            }).join("") + "</div>"
            + "<h3>Offices</h3><table><thead><tr><th>Office</th><th>Visits</th><th>Answered in time</th><th>Average answer</th><th>Expired</th></tr></thead><tbody>"
            + data.offices.map(function (office) {
                return "<tr><td>" + esc(office.name) + "</td><td>" + office.visits + "</td><td>" + (office.answered_in_time !== null ? office.answered_in_time + "%" : "—") + "</td><td>"
                    + esc(office.answer_label || "—") + "</td><td>" + (office.staff ? office.expired : "No staff yet") + "</td></tr>";
            }).join("") + "</tbody></table>"
            + "<h3>Why people visit</h3><table><thead><tr><th>Purpose</th><th>Visits</th><th>Share</th></tr></thead><tbody>"
            + (data.purposes.length ? data.purposes.map(function (item) {
                return "<tr><td>" + esc(item.label) + "</td><td>" + item.visits + "</td><td>" + Math.round(item.visits * 100 / Math.max(1, now.visits)) + "%</td></tr>";
            }).join("") : '<tr><td colspan="3">No visits in this period.</td></tr>') + "</tbody></table>"
            + '<div class="adm-pdf-sign"><div>Prepared by<br><b>' + esc(S.me.name) + "</b><br>" + esc(S.me.position || "System Administrator") + "</div><div>Noted by<br><b>&nbsp;</b><br>Dean</div></div>"
            + "</article>";
        printNow("report");
    }

    function openActivityExport() {
        const params = activityParams();
        openSheet({
            title: "Export the activity",
            sub: "What the page shows now: " + esc(S.activity.days === 1 ? "today" : "the last " + S.activity.days + " days") + (S.activity.userId ? ", " + esc(S.activity.userName) : "") + ".",
            body: '<div class="adm-options">'
                + '<a class="adm-option is-main" href="admin_activity.php?' + esc(params.toString()) + '&amp;format=xlsx" download><span class="ofc-menu-ico">' + ICON.table + "</span><span><strong>Excel</strong><small>A summary sheet and every entry</small></span>" + ICON.chev + "</a>"
                + '<a class="adm-option" href="admin_activity.php?' + esc(params.toString()) + '&amp;format=csv" download><span class="ofc-menu-ico">' + ICON.table + "</span><span><strong>CSV</strong><small>Plain data for other programs</small></span>" + ICON.chev + "</a></div>"
                + '<p class="ofc-note">Exporting is written in the activity log.</p>',
        });
    }

    function openKeys() {
        openSheet({
            title: "Keyboard shortcuts",
            sub: "On a laptop. They don't fire while you're typing in a box.",
            body: '<dl class="adm-keys">'
                + '<div><dt>Search everything</dt><dd><span class="adm-kbd">/</span></dd></div>'
                + '<div><dt>Add a person</dt><dd><span class="adm-kbd">N</span></dd></div>'
                + '<div><dt>Home, Offices, People, Activity, Reports, Campus map</dt><dd><span class="adm-kbd">1</span>to<span class="adm-kbd">6</span></dd></div>'
                + '<div><dt>Close a panel</dt><dd><span class="adm-kbd">Esc</span></dd></div>'
                + '<div><dt>Show these shortcuts</dt><dd><span class="adm-kbd">?</span></dd></div></dl>',
        });
    }

    function openStaffReset() {
        const staff = S.users.filter(function (user) { return user.role !== "visitor" && Number(user.is_active) === 1; });
        const without = staff.filter(function (user) { return !user.email; });
        openSheet({
            title: "Staff who forget their password",
            sub: "The staff sign-in page has \"Forgot password?\".",
            body: '<ol class="adm-howto"><li><b>Forgot password?</b> on the sign-in page, then their username or email.</li>'
                + "<li>A <b>6-digit code</b> comes to the email on their account. It works for 15 minutes.</li>"
                + "<li>They choose a <b>new password</b>. They are signed out everywhere else, and two-step sign-in is still asked.</li></ol>"
                + '<p class="ofc-note">Activity shows each reset as "Reset their password by email". The reply never says whether an account exists.</p>'
                + '<p class="ofc-label">Who can use it</p>'
                + '<p class="ofc-note">' + (staff.length - without.length) + " of " + staff.length + " staff have an email on file." + (without.length ? " These still ask you:" : "") + "</p>"
                + (without.length ? '<ul class="adm-plist">' + without.map(function (user) {
                    return '<li><button class="adm-prow" type="button" data-edit-person="' + user.id + '"><span class="adm-ini">' + esc(initials(user.display_name || user.username)) + "</span><span><strong>"
                        + esc(user.display_name || user.username) + "</strong><small>" + esc(ROLE_LABELS[user.role] + " · " + (user.department || "")) + '</small></span><span class="ofc-link">Add email</span></button></li>';
                }).join("") + "</ul>" : ""),
        });
    }

    function openHoursInfo() {
        AppDialog.alert({
            title: "Visitor hours are set by the office",
            message: "Each office's own staff set their weekly hours, breaks and closed days on their Hours page. Once an office has Office Personnel, ask them to set it at their first sign-in.",
        });
    }

    /* ---------- Walking routes (the route map, as on the old Visitor Analytics page) ---------- */

    const routeState = S.routes;

    function formatDistance(meters) {
        const value = Number(meters || 0);
        if (value <= 0) {
            return "—";
        }
        return value < 1000 ? Math.round(value) + " m" : (value / 1000).toFixed(1) + " km";
    }

    function routeUsageColor(ratio) {
        if (ratio >= 0.67) {
            return "#e2471d";
        }
        return ratio >= 0.34 ? "#f0a01c" : "#5b9bf0";
    }

    function officeName(code) {
        const office = officeByCode(code);
        return office ? office.name : code;
    }

    function renderRouteMetrics(summary) {
        const data = summary || {};
        const routes = Number(data.routes || 0);
        const busiest = Number(data.busiest_segment_visitors || 0);
        byId("routeAnalyticsMetrics").innerHTML = [
            ["Routes analyzed", routes, "is-blue"],
            ["Avg. walking distance", formatDistance(data.average_distance_meters), "is-green"],
            ["Routes on busiest path", routes > 0 ? Math.round((busiest / routes) * 100) + "%" : "—", "is-orange"],
            ["GPS points used", Number(data.points_used || 0), "is-purple"],
        ].map(function (metric) {
            return '<div class="analytics-location-metric ' + metric[2] + '"><strong>' + esc(metric[1]) + "</strong><span>" + esc(metric[0]) + "</span></div>";
        }).join("");
    }

    function describeWalkToOffice(item) {
        if (!item.has_pin) {
            return "Place this office's pin in Campus map to measure the walk to it.";
        }
        const arrivals = Number(item.arrivals || 0);
        if (arrivals === 0) {
            return "No tracked visitor reached the office pin yet.";
        }
        const walked = Number(item.average_walk_to_office_meters || 0);
        const direct = Number(item.average_direct_meters || 0);
        const detour = direct > 0 ? " (" + (walked / direct).toFixed(1) + "× the direct distance)" : "";
        return "To office: " + formatDistance(walked) + detour + " · " + Number(item.average_minutes_to_office || 0) + " min · " + plural(arrivals, "arrival", "arrivals");
    }

    function renderRouteDestinations(destinations) {
        const rows = Array.isArray(destinations) ? destinations : [];
        const maxRoutes = rows.reduce(function (max, item) { return Math.max(max, Number(item.routes || 0)); }, 0);
        byId("routeDestinationList").innerHTML = rows.map(function (item) {
            const routes = Number(item.routes || 0);
            return '<button type="button" class="analytics-route-destination' + (item.code === routeState.office ? " is-active" : "") + '" data-route-office="' + esc(item.code || "") + '">'
                + "<strong>" + esc(item.label || officeName(item.code) || "Unknown destination") + "</strong>"
                + "<span>" + esc(plural(routes, "route", "routes") + " · avg " + formatDistance(item.average_distance_meters) + " walked") + "</span>"
                + '<span class="analytics-route-walk">' + esc(describeWalkToOffice(item)) + "</span>"
                + '<div class="analytics-route-bar"><i style="width:' + (maxRoutes > 0 ? Math.round((routes / maxRoutes) * 100) : 0) + '%"></i></div></button>';
        }).join("");
        byId("routeDestinationEmpty").hidden = rows.length > 0;
    }

    function renderRouteGates(gates, routes) {
        const rows = Array.isArray(gates) ? gates : [];
        byId("routeGateList").innerHTML = rows.map(function (gate) {
            const entries = Number(gate.entries || 0);
            const exits = Number(gate.exits || 0);
            return '<div class="analytics-route-destination is-static"><strong>' + esc(gate.name) + "</strong><span>"
                + esc(entries + " entr" + (entries === 1 ? "y" : "ies") + (routes > 0 ? " (" + Math.round((entries / routes) * 100) + "%)" : "") + " · " + plural(exits, "exit", "exits"))
                + '</span><div class="analytics-route-bar is-gate"><i style="width:' + (routes > 0 ? Math.round((entries / routes) * 100) : 0) + '%"></i></div></div>';
        }).join("");
        byId("routeGateEmpty").hidden = rows.length > 0;
    }

    async function applyRouteCampus() {
        routeState.campus = await CampusMap.load();
        if (!routeState.map) {
            return;
        }
        CampusMap.drawOverlay(routeState.campusLayer, routeState.campus);
        CampusMap.lockToCampus(routeState.map, routeState.campus, !routeState.fittedKey);
    }

    function drawRouteMap() {
        const host = byId("routeAnalyticsMap");
        const data = routeState.data;
        if (!data || host.offsetWidth === 0) {
            return;
        }
        if (typeof L === "undefined") {
            showMessage("routeAnalyticsError", "The map library could not load. Check your internet connection and refresh the page.");
            return;
        }
        if (!routeState.map) {
            const map = L.map(host, CampusMap.mapOptions({ preferCanvas: true, scrollWheelZoom: false })).setView([10.7177, 122.5559], 17);
            routeState.map = map;
            CampusMap.addBaseLayer(map);
            CampusMap.addScale(map);
            CampusMap.enableLabels(map);
            CampusMap.addExpandControl(map, host.parentElement, {
                onChange: function (expanded) {
                    if (expanded) {
                        map.scrollWheelZoom.enable();
                    } else {
                        map.scrollWheelZoom.disable();
                    }
                    CampusMap.lockToCampus(map, routeState.campus, false);
                },
            });
            routeState.campusLayer = L.layerGroup().addTo(map);
            routeState.layer = L.layerGroup().addTo(map);
            applyRouteCampus();
        } else if (routeState.campusStale) {
            routeState.campusStale = false;
            applyRouteCampus();
        }
        routeState.map.invalidateSize();
        routeState.layer.clearLayers();
        const segments = Array.isArray(data.segments) ? data.segments : [];
        const routes = Number((data.summary && data.summary.routes) || 0);
        const maxVisitors = segments.reduce(function (max, segment) { return Math.max(max, Number(segment.visitors || 0)); }, 1);
        segments.slice().reverse().forEach(function (segment) {
            const ratio = Number(segment.visitors || 0) / maxVisitors;
            L.polyline([segment.from, segment.to], { color: routeUsageColor(ratio), weight: 3 + ratio * 7, opacity: 0.55 + ratio * 0.4, lineCap: "round" })
                .bindTooltip(segment.visitors + " of " + plural(routes, "visitor route", "visitor routes") + " used this path", { sticky: true })
                .addTo(routeState.layer);
        });
        byId("routeAnalyticsEmpty").hidden = segments.length > 0;
        const key = S.report.period + "|" + routeState.office;
        if (segments.length > 0 && routeState.fittedKey !== key) {
            const bounds = L.latLngBounds([]);
            segments.forEach(function (segment) {
                bounds.extend(segment.from);
                bounds.extend(segment.to);
            });
            routeState.map.fitBounds(bounds, { padding: [30, 30], maxZoom: 20 });
            routeState.fittedKey = key;
        }
    }

    async function loadRouteAnalytics() {
        showMessage("routeAnalyticsError", "");
        const params = new URLSearchParams({ period: S.report.period });
        if (routeState.office) {
            params.set("office", routeState.office);
        }
        const data = await api("admin_route_analytics.php?" + params.toString());
        if (!data.success) {
            showMessage("routeAnalyticsError", data.message || "Could not load the routes.");
            return;
        }
        routeState.data = data;
        renderRouteMetrics(data.summary);
        renderRouteDestinations(data.destinations);
        renderRouteGates(data.gates, Number((data.summary && data.summary.routes) || 0));
        byId("routeCampusHint").hidden = Boolean(data.campus_configured);
        drawRouteMap();
    }

    function openRoutes() {
        const select = byId("routeOfficeFilter");
        select.innerHTML = '<option value="">All destinations</option>' + offices().map(function (office) {
            return '<option value="' + esc(office.code) + '">' + esc(office.name) + "</option>";
        }).join("");
        select.value = routeState.office;
        byId("admRoutesPeriod").textContent = ({ today: "Today", week: "This week", month: "This month", quarter: "This quarter" })[S.report.period] + ": the paths visitors walk most often.";
        showSheet(byId("admRoutesSheet"));
        loadRouteAnalytics();
        window.setTimeout(drawRouteMap, 250);
    }

    /* ---------- Reset visitor data (Menu): type RESET and give an authenticator code ---------- */

    const resetDialog = byId("analyticsResetDialog");
    const resetState = { counts: null, twoFactor: true, backupCodes: 0, method: "authenticator", busy: false };

    function resetScope() {
        const checked = $("input[name='analyticsResetScope']:checked");
        return checked ? checked.value : "all";
    }

    function renderResetImpact() {
        const host = byId("analyticsResetImpact");
        const live = byId("analyticsResetLive");
        const scope = resetScope();
        const counts = resetState.counts ? resetState.counts[scope] || null : null;
        live.hidden = true;
        if (!counts) {
            host.innerHTML = '<p class="acct-loading">Counting records…</p>';
            updateResetSubmit();
            return;
        }
        host.innerHTML = '<p class="reset-impact-title">This will permanently delete:</p><div class="reset-impact-grid">' + [
            [counts.appointments, "Visit record", "Visit records"],
            [counts.gps_points, "GPS point", "GPS points"],
            [counts.tracking_sessions, "Tracking session", "Tracking sessions"],
            [counts.notifications, "Notification", "Notifications"],
        ].map(function (item) {
            return '<div class="reset-impact-item' + (Number(item[0]) > 0 ? "" : " is-zero") + '"><strong>' + Number(item[0] || 0).toLocaleString() + "</strong><span>" + (Number(item[0]) === 1 ? item[1] : item[2]) + "</span></div>";
        }).join("") + "</div>";
        const notes = [];
        if (scope === "all" && counts.upcoming > 0) {
            notes.push(plural(counts.upcoming, "upcoming booking", "upcoming bookings"));
        }
        if (counts.visits > 0) {
            notes.push(plural(counts.visits, "multi-office visit", "multi-office visits"));
        }
        if (counts.oldest && counts.newest) {
            notes.push("dated " + dayText(counts.oldest) + " to " + dayText(counts.newest));
        }
        if (notes.length) {
            host.insertAdjacentHTML("beforeend", '<p class="reset-impact-note">Includes ' + esc(notes.join(" · ")) + "</p>");
        }
        if (scope === "all" && counts.on_campus > 0) {
            live.textContent = plural(counts.on_campus, "visitor is", "visitors are") + " on campus right now. Their visit and live tracking end and are deleted too; Security will no longer see them.";
            live.hidden = false;
        }
        updateResetSubmit();
    }

    function resetCodeValid() {
        const code = byId("analyticsResetCode").value.trim();
        return resetState.method === "backup_code" ? code.replace(/[^A-Za-z0-9]/g, "").length === 10 : /^\d{6}$/.test(code);
    }

    function updateResetSubmit() {
        const counts = resetState.counts ? resetState.counts[resetScope()] || null : null;
        const nothing = Boolean(counts) && !counts.appointments && !counts.gps_points && !counts.visits;
        const confirmed = byId("analyticsResetConfirm").value.trim() === "RESET";
        const submit = byId("analyticsResetSubmit");
        submit.disabled = resetState.busy || !resetState.twoFactor || !counts || nothing || !confirmed || !resetCodeValid();
        submit.textContent = resetState.busy ? "Deleting…" : (nothing ? "Nothing to delete" : (resetScope() === "all" ? "Delete all visitor data" : "Delete finished visits"));
    }

    function setResetMethod(method) {
        resetState.method = method;
        const input = byId("analyticsResetCode");
        const backup = method === "backup_code";
        input.value = "";
        input.maxLength = backup ? 12 : 6;
        input.inputMode = backup ? "text" : "numeric";
        input.placeholder = backup ? "Backup code" : "6-digit code";
        byId("analyticsResetCodeLabel").textContent = backup ? "Backup code" : "Authenticator code";
        byId("analyticsResetCodeHelp").textContent = backup
            ? "One of the backup codes you saved when you set up two-step verification. Each code works once."
            : "The same 6-digit code you enter when you sign in.";
        const toggle = byId("analyticsResetMethodBtn");
        toggle.textContent = backup ? "Use my authenticator app instead" : "Use a backup code instead";
        toggle.hidden = !backup && resetState.backupCodes === 0;
        updateResetSubmit();
    }

    async function openResetDialog() {
        resetState.counts = null;
        resetState.busy = false;
        byId("analyticsResetForm").reset();
        byId("analyticsResetNo2fa").hidden = true;
        showMessage("analyticsResetError", "");
        setResetMethod("authenticator");
        renderResetImpact();
        resetDialog.showModal();
        const data = await api("admin_analytics_reset.php");
        if (!data.success) {
            showMessage("analyticsResetError", data.message || "Could not count the visit records.");
            return;
        }
        resetState.counts = data.scopes || null;
        resetState.twoFactor = Boolean(data.two_factor_enabled);
        resetState.backupCodes = Number(data.backup_codes_remaining || 0);
        byId("analyticsResetNo2fa").hidden = resetState.twoFactor;
        byId("analyticsResetCodeField").hidden = !resetState.twoFactor;
        setResetMethod("authenticator");
        renderResetImpact();
        byId("analyticsResetConfirm").focus();
    }

    function closeResetDialog() {
        if (resetState.busy) {
            return;
        }
        byId("analyticsResetCode").value = "";
        if (resetDialog.open) {
            resetDialog.close();
        }
    }

    async function submitReset(event) {
        event.preventDefault();
        updateResetSubmit();
        if (byId("analyticsResetSubmit").disabled) {
            return;
        }
        const scope = resetScope();
        resetState.busy = true;
        updateResetSubmit();
        showMessage("analyticsResetError", "");
        const data = await api("admin_analytics_reset.php", {
            scope: scope,
            confirm: byId("analyticsResetConfirm").value.trim(),
            method: resetState.method,
            code: byId("analyticsResetCode").value.trim(),
        });
        resetState.busy = false;
        updateResetSubmit();
        if (!data.success) {
            let message = data.message || "The reset did not go through.";
            if (data.attempts_remaining != null && data.attempts_remaining <= 3) {
                message += " " + plural(data.attempts_remaining, "try", "tries") + " left before sign-in is paused.";
            }
            showMessage("analyticsResetError", message);
            byId("analyticsResetCode").value = "";
            byId("analyticsResetCode").focus();
            return;
        }
        const deleted = data.deleted || {};
        const parts = [plural(deleted.appointments, "visit record", "visit records")];
        if (deleted.gps_points) {
            parts.push(plural(deleted.gps_points, "GPS point", "GPS points"));
        }
        if (deleted.tracking_sessions) {
            parts.push(plural(deleted.tracking_sessions, "tracking session", "tracking sessions"));
        }
        closeResetDialog();
        AppDialog.alert({ title: "Visitor data reset", message: "Deleted " + parts.join(", ") + ". The reset is recorded in the activity log." });
        refreshAll();
    }

    /* ---------- One search for everything ---------- */

    function searchLocal(query) {
        const q = query.toLowerCase();
        const people = S.users.filter(function (user) {
            return user.role !== "visitor" && [user.display_name, user.username, user.email, user.department, user.position].join(" ").toLowerCase().indexOf(q) !== -1;
        }).slice(0, 5);
        const found = offices().filter(function (office) { return (office.name + " " + office.code).toLowerCase().indexOf(q) !== -1; }).slice(0, 5);
        return { people: people, offices: found };
    }

    function resultButton(attrs, iconHtml, title, sub) {
        return '<button class="adm-result" type="button" role="option" ' + attrs + '><span class="adm-ini">' + iconHtml + "</span><span><strong>" + esc(title) + "</strong><small>" + esc(sub) + "</small></span>" + ICON.chev + "</button>";
    }

    function renderSearch(query, visitors) {
        const box = byId("admResults");
        const local = searchLocal(query);
        const group = function (title, items) { return items.length ? "<p>" + title + "</p>" + items.join("") : ""; };
        const html = group("Visitors", (visitors || []).map(function (visitor) {
            return resultButton(visitor.user_id ? 'data-visitor-user="' + visitor.user_id + '"' : 'data-visitor-appointment="' + visitor.appointment_id + '"',
                esc(initials(visitor.name)), visitor.name, (visitor.matched_code ? visitor.code + " · " : "") + (visitor.last || "") + (visitor.visits > 1 ? " · " + visitor.visits + " visits" : ""));
        }))
            + group("People", local.people.map(function (user) {
                return resultButton('data-person="' + user.id + '"', esc(initials(user.display_name || user.username)), user.display_name || user.username, ROLE_LABELS[user.role] + " · " + (user.department || ""));
            }))
            + group("Offices", local.offices.map(function (office) {
                return resultButton('data-office="' + esc(office.code) + '"', ICON.office, office.name, office.state_line);
            }));
        box.innerHTML = html || (visitors ? '<div class="adm-none">Nothing found for "' + esc(query) + '".</div>' : '<div class="adm-none">Searching…</div>');
        box.hidden = false;
    }

    function search(value) {
        const query = value.trim();
        window.clearTimeout(S.searchTimer);
        if (!query) {
            byId("admResults").hidden = true;
            return;
        }
        renderSearch(query, null);
        if (query.length < 2) {
            renderSearch(query, []);
            return;
        }
        S.searchTimer = window.setTimeout(async function () {
            const request = ++S.searchRequest;
            const data = await api("admin_search.php?q=" + encodeURIComponent(query));
            if (request === S.searchRequest && byId("admSearch").value.trim() === query) {
                renderSearch(query, data.success ? data.visitors : []);
            }
        }, 220);
    }

    /* ---------- Clicks, everywhere (the page and every sheet) ---------- */

    async function doAction(action) {
        const parts = action.split(":");
        const kind = parts[0];
        const arg = parts.slice(1).join(":");
        if (kind === "add") {
            closeSheets();
            openAdd({ office: arg });
        } else if (kind === "go") {
            closeSheets();
            const target = parts[1];
            const filter = parts[2] || "";
            if (target === "people") {
                S.people.tab = "staff";
                S.people.filter = filter;
                renderPeople();
            } else if (target === "offices") {
                S.officeFilter = filter;
                renderOffices();
            }
            go(target, { history: "push" });
        } else if (kind === "unlock") {
            const user = userById(arg);
            securityAction("unlock_user", arg, { title: "Unlock " + (user ? user.display_name || user.username : "this account") + "'s sign-in now?", message: "They can sign in again right away. The failed attempts stay in the activity log.", confirmLabel: "Unlock" });
        } else if (kind === "person") {
            openPerson(Number(arg));
        } else if (kind === "office") {
            openOffice(arg);
        } else if (kind === "remind") {
            remindOffice(arg);
        } else if (kind === "reset") {
            resetPassword(Number(arg));
        }
    }

    const CLICKS = [
        ["[data-adm-go]", function (el) { closeSheets(); go(el.dataset.admGo, { history: "push" }); }],
        ["[data-act]", function (el) { doAction(el.dataset.act); }],
        ["[data-att-more]", function () { S.attAll = true; byId("admAttention").innerHTML = attentionList(false); }],
        ["[data-office]", function (el) { byId("admResults").hidden = true; openOffice(el.dataset.office); }],
        ["[data-person]", function (el) { byId("admResults").hidden = true; openPerson(Number(el.dataset.person)); }],
        ["[data-visitor-user]", function (el) { byId("admResults").hidden = true; openVisitor({ userId: Number(el.dataset.visitorUser) }); }],
        ["[data-visitor-appointment]", function (el) { byId("admResults").hidden = true; openVisitor({ appointmentId: Number(el.dataset.visitorAppointment) }); }],
        ["[data-visit]", function (el) { openVisit(Number(el.dataset.visit)); }],
        ["[data-visits]", function (el) { openVisits(el.dataset.visits); }],
        ["[data-office-filter]", function (el) { S.officeFilter = el.dataset.officeFilter; renderOffices(); }],
        ["[data-ptab]", function (el) { S.people.tab = el.dataset.ptab; S.people.shown = 40; renderPeople(); }],
        ["[data-people-filter]", function (el) { S.people.filter = el.dataset.peopleFilter; S.people.shown = 40; renderPeople(); }],
        ["[data-act-cat]", function (el) { S.activity.cat = el.dataset.actCat; loadActivity(true); }],
        ["[data-act-clear-person]", function () { S.activity.userId = 0; S.activity.userName = ""; loadActivity(true); }],
        ["[data-period]", function (el) { S.report.period = el.dataset.period; routeState.fittedKey = ""; loadReport(); }],
        ["[data-add-person]", function (el) { closeSheets(); openAdd({ office: el.dataset.addPerson }); }],
        ["[data-wizard]", function (el) { closeSheets(); openWizard(el.dataset.wizard, Number(el.dataset.wizardStep) || 0); }],
        ["[data-remind]", function (el) { remindOffice(el.dataset.remind); }],
        ["[data-edit-office]", function (el) { openEditOffice(el.dataset.editOffice); }],
        ["[data-save-office]", function (el) { saveOffice(el.dataset.saveOffice, el); }],
        ["[data-archive-office]", function (el) { setOfficeArchived(el.dataset.archiveOffice, true); }],
        ["[data-restore-office]", function (el) { setOfficeArchived(el.dataset.restoreOffice, false); }],
        ["[data-delete-office]", function (el) { deleteOffice(el.dataset.deleteOffice); }],
        ["[data-hours-info]", function () { openHoursInfo(); }],
        ["[data-edit-person]", function (el) { openEditPerson(Number(el.dataset.editPerson)); }],
        ["[data-save-person]", function (el) { savePerson(Number(el.dataset.savePerson), el); }],
        ["[data-person-activity]", function (el) {
            const user = userById(el.dataset.personActivity);
            S.activity.userId = Number(el.dataset.personActivity);
            S.activity.userName = user ? user.display_name || user.username : "";
            closeSheets();
            go("activity", { history: "push" });
            loadActivity(true);
            if (!S.security) {
                loadSecurity();
            }
        }],
        ["[data-account-security]", function () { closeSheets(); Security.open(); }],
        ["[data-unlock-user]", function (el) { doAction("unlock:" + el.dataset.unlockUser); }],
        ["[data-reset-password]", function (el) { resetPassword(Number(el.dataset.resetPassword)); }],
        ["[data-reset-twostep]", function (el) {
            const user = userById(el.dataset.resetTwostep);
            securityAction("reset_two_factor", el.dataset.resetTwostep, {
                title: "Reset two-step sign-in for " + (user.display_name || user.username) + "?",
                message: user.two_factor_required ? "For a lost phone. They set it up again at their next sign-in." : "For a lost phone. This turns two-step sign-in off for this account.",
                confirmLabel: "Reset 2-step",
            });
        }],
        ["[data-force-logout]", function (el) {
            const user = userById(el.dataset.forceLogout);
            securityAction("force_logout", el.dataset.forceLogout, { title: "Sign " + (user.display_name || user.username) + " out on every device?", message: "This ends their open sessions everywhere.", confirmLabel: "Sign out" });
        }],
        ["[data-turn-off]", function (el) { setUserActive(Number(el.dataset.turnOff), false); }],
        ["[data-turn-on]", function (el) { setUserActive(Number(el.dataset.turnOn), true); }],
        ["[data-remove-user]", function (el) { removeUser(Number(el.dataset.removeUser)); }],
        ["[data-unlock-lock]", async function (el) {
            if (await AppDialog.confirm({ title: "Unlock this sign-in now?", message: "It can be used again right away. The failed attempts stay in the activity log.", confirmLabel: "Unlock" })) {
                const data = await act("admin_security.php", { action: "unlock", lockout_id: Number(el.dataset.unlockLock) }, "Couldn't unlock it");
                if (data.success) {
                    toast(data.message || "Unlocked.");
                    loadSecurity();
                    loadOverview();
                    loadUsers();
                }
            }
        }],
        ["[data-closure-change]", function (el) { openClosure(el.dataset.closureChange); }],
        ["[data-closure-reopen]", function (el) { reopenCampus(el.dataset.closureReopen); }],
        ["[data-campus-step]", function (el) {
            closeSheets();
            go("campus", { history: "push" });
            const mode = el.dataset.campusStep;
            window.setTimeout(function () {
                const button = $("[data-campus-mode='" + mode + "']");
                if (button) {
                    button.click();
                }
                const editor = $(".campus-setup-layout");
                if (editor) {
                    editor.scrollIntoView({ behavior: "smooth", block: "start" });
                }
            }, 120);
        }],
        ["[data-copy]", function (el) {
            UI.copyText(el.dataset.copy).then(function (ok) { toast(ok === false ? "Couldn't copy. Select it and copy it by hand." : "Copied.", ok === false ? "error" : "success"); });
        }],
        ["[data-print-report]", function () { closeSheets(); printReport(); }],
        ["[data-open-campus]", function () { closeSheets(); go("campus", { history: "push" }); }],
        ["[data-open]", function (el) {
            const what = el.dataset.open;
            if (what === "closure") {
                openClosure(S.overview && S.overview.closures.length ? S.overview.closures[0].id : null);
            } else if (what === "wizard") {
                openWizard(null);
            } else if (what === "add") {
                openAdd({});
            } else if (what === "reminders") {
                openReminders();
            } else if (what === "digest") {
                openDigest();
            } else if (what === "export") {
                openReportExport();
            } else if (what === "activity-export") {
                openActivityExport();
            } else if (what === "keys") {
                openKeys();
            } else if (what === "staff-reset") {
                openStaffReset();
            } else if (what === "routes") {
                openRoutes();
            }
        }],
    ];

    document.addEventListener("click", function (event) {
        if (!event.target.closest || event.defaultPrevented) {
            return;
        }
        if (!event.target.closest("#admSearchBox")) {
            byId("admResults").hidden = true;
        }
        for (const [selector, handler] of CLICKS) {
            const element = event.target.closest(selector);
            if (element && !element.disabled) {
                if (element.tagName === "A") {
                    if (selector === "[data-adm-go]" || selector === "[data-open-campus]") {
                        event.preventDefault();
                    } else {
                        continue;
                    }
                }
                handler(element, event);
                return;
            }
        }
    });

    /* ---------- Keyboard shortcuts on a laptop ---------- */

    document.addEventListener("keydown", function (event) {
        const typing = event.target.closest && event.target.closest("input, textarea, select, [contenteditable]");
        if (typing || event.ctrlKey || event.metaKey || event.altKey) {
            if (event.key === "Escape" && event.target.id === "admSearch") {
                byId("admResults").hidden = true;
                event.target.blur();
            }
            return;
        }
        const dialogOpen = Boolean(document.querySelector("dialog[open]"));
        if (event.key === "/" && !dialogOpen) {
            event.preventDefault();
            go("home", { history: "push" });
            window.setTimeout(function () { byId("admSearch").focus(); }, 30);
        } else if ((event.key === "n" || event.key === "N") && !dialogOpen) {
            event.preventDefault();
            openAdd({});
        } else if (event.key === "?" && !dialogOpen) {
            openKeys();
        } else if (/^[1-6]$/.test(event.key) && !dialogOpen) {
            go(SHORTCUT_VIEWS[Number(event.key) - 1], { history: "push" });
        }
    });

    /* ---------- The rest of the page ---------- */

    byId("admBellBtn").addEventListener("click", openAttention);
    byId("admSearch").addEventListener("input", function (event) { search(event.target.value); });
    byId("admSearch").addEventListener("focus", function (event) {
        if (event.target.value.trim()) {
            search(event.target.value);
        }
    });
    byId("admPeopleSearch").addEventListener("input", function (event) {
        S.people.q = event.target.value.trim();
        S.people.shown = 40;
        renderPeople();
    });
    byId("admPeopleMore").addEventListener("click", function () {
        S.people.shown += 60;
        renderPeople();
    });
    byId("admActMore").addEventListener("click", function () { loadActivity(false); });
    byId("admActPeriod").addEventListener("change", function (event) {
        S.activity.days = Number(event.target.value) || 7;
        loadActivity(true);
    });
    byId("admTrendTableBtn").addEventListener("click", function (event) {
        const table = byId("admTrendTable");
        table.hidden = !table.hidden;
        event.currentTarget.textContent = table.hidden ? "Show as table" : "Hide table";
        event.currentTarget.setAttribute("aria-expanded", String(!table.hidden));
    });
    byId("admMenuSecurity").addEventListener("click", function () { Security.open(); });
    byId("admMenuRefresh").addEventListener("click", function () {
        refreshAll();
        toast("Refreshing…");
    });
    byId("openAnalyticsResetBtn").addEventListener("click", openResetDialog);
    byId("admSignOut").addEventListener("click", async function () {
        if (await AppDialog.confirm({ title: "Sign out?", message: "You'll need your password and authenticator code to sign in again.", confirmLabel: "Sign out" })) {
            PhoneTrackerAuth.logout().then(function () { window.location.href = "login.html"; });
        }
    });

    byId("analyticsResetForm").addEventListener("submit", submitReset);
    $$("input[name='analyticsResetScope']").forEach(function (input) { input.addEventListener("change", renderResetImpact); });
    byId("analyticsResetConfirm").addEventListener("input", updateResetSubmit);
    byId("analyticsResetCode").addEventListener("input", function (event) {
        if (resetState.method === "authenticator") {
            event.target.value = event.target.value.replace(/\D/g, "").slice(0, 6);
        }
        updateResetSubmit();
    });
    byId("analyticsResetMethodBtn").addEventListener("click", function () {
        setResetMethod(resetState.method === "authenticator" ? "backup_code" : "authenticator");
        byId("analyticsResetCode").focus();
    });
    $$("[data-reset-cancel]").forEach(function (button) { button.addEventListener("click", closeResetDialog); });
    resetDialog.addEventListener("cancel", function (event) {
        if (resetState.busy) {
            event.preventDefault();
        }
        byId("analyticsResetCode").value = "";
    });

    byId("routeOfficeFilter").addEventListener("change", function (event) {
        routeState.office = event.target.value;
        loadRouteAnalytics();
    });
    byId("routeDestinationList").addEventListener("click", function (event) {
        const button = event.target.closest("[data-route-office]");
        if (button) {
            const office = button.dataset.routeOffice;
            routeState.office = office === routeState.office ? "" : office;
            byId("routeOfficeFilter").value = routeState.office;
            loadRouteAnalytics();
        }
    });
    document.addEventListener("campus:updated", function () {
        routeState.campusStale = true;
        loadOverview();
    });

    /* ---------- Start ---------- */

    function refreshAll() {
        loadOverview();
        loadUsers();
        loadHomeActivity();
        if (S.activity.loaded) {
            loadActivity(true);
            loadSecurity();
        }
        if (S.report.data) {
            loadReport();
        }
    }

    renderMe();
    go(viewFromHash(), { history: "replace" });
    loadOverview();
    loadUsers();
    loadHomeActivity();
    api("admin_settings.php").then(function (data) {
        if (data.success) {
            S.digest = data.digest;
            renderDigestNote();
        }
    });
    window.setInterval(function () {
        if (document.visibilityState !== "visible") {
            return;
        }
        loadOverview();
        if (S.view === "home") {
            loadHomeActivity();
        }
        if (S.view === "activity") {
            loadSecurity();
        }
    }, 60000);
    window.setInterval(function () {
        if (document.visibilityState === "visible") {
            loadUsers();
        }
    }, 180000);
})();
