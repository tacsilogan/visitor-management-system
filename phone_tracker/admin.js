(function () {
    "use strict";

    if (!PhoneTrackerAuth.requireRole(["admin"])) {
        return;
    }

    const officeNames = {
        IT: "IT Department",
        IS: "IS Department",
        CS: "CS Department",
        DEANS: "Dean's Office",
        TECH_SUPPORT: "Tech Support",
    };
    const userState = {
        users: [],
        role: "",
        department: "",
        query: "",
        sortDirection: 1,
        page: 1,
        pageSize: 10,
    };
    const visitorState = {
        rows: [],
        status: "",
        query: "",
    };
    const analyticsState = {
        period: "month",
        loaded: false,
    };
    const routeState = {
        office: "",
        data: null,
        map: null,
        layer: null,
        campusLayer: null,
        campus: null,
        campusStale: false,
        fittedKey: "",
    };

    const profileMenu = document.getElementById("profileMenu");
    const profileBtn = document.getElementById("profileBtn");
    const notificationPanel = document.getElementById("notificationPanel");
    const notificationBtn = document.getElementById("notificationBtn");
    const notificationList = document.getElementById("notificationList");
    const notificationEmpty = document.getElementById("notificationEmpty");
    const usersBody = document.getElementById("usersBody");
    const usersEmpty = document.getElementById("usersEmpty");
    const recentVisitorsBody = document.getElementById("recentVisitorsBody");
    const recentVisitorsEmpty = document.getElementById("recentVisitorsEmpty");
    const recentActivitiesList = document.getElementById("recentActivitiesList");
    const recentActivitiesEmpty = document.getElementById("recentActivitiesEmpty");
    const addUserDialog = document.getElementById("addUserDialog");
    const manageUserDialog = document.getElementById("manageUserDialog");
    const tempPasswordDialog = document.getElementById("tempPasswordDialog");
    const UI = window.PhoneTrackerAuthUI;
    const Security = window.PhoneTrackerSecurity;
    const securityState = {
        filter: "all",
        data: null,
        receivedAt: 0,
        attemptLimit: 20,
    };
    let manageUserId = null;

    // Department directory (all departments, archived ones included; see office_directory.php).
    const directoryState = { offices: [] };
    const departmentState = { departments: [], editingCode: "", codeEdited: false, suggestTimer: 0 };
    const feedState = { category: "all", department: "" };
    const activityState = {
        tab: "signin",
        loaded: false,
        period: "7",
        department: "",
        userId: "",
        q: "",
        category: "all",
        nextBeforeId: null,
        requestId: 0,
        searchTimer: 0,
        lastDay: "",
    };
    let personDialogUserId = null;

    function activeOffices() {
        return directoryState.offices.filter(function (office) { return office.is_active; });
    }

    /** Fills every <select data-department-select> with Administration, Security, and each office. */
    function fillDepartmentSelects() {
        document.querySelectorAll("[data-department-select]").forEach(function (select) {
            const current = select.value;
            select.replaceChildren(new Option("All departments", ""));
            select.appendChild(new Option("Administration", "admin"));
            select.appendChild(new Option("Security", "security"));
            directoryState.offices.forEach(function (office) {
                select.appendChild(new Option(office.name + (office.is_active ? "" : " (archived)"), office.code));
            });
            if (select.hasAttribute("data-include-other")) {
                select.appendChild(new Option("Visitors", "visitor"));
                select.appendChild(new Option("Automatic (system)", "system"));
            }
            select.value = Array.from(select.options).some(function (option) { return option.value === current; }) ? current : "";
        });
        const officeSelects = [
            [document.getElementById("newUserOfficeCode"), "Select department"],
            [document.getElementById("editDepartment"), ""],
        ];
        officeSelects.forEach(function (pair) {
            const select = pair[0];
            const current = select.value;
            select.replaceChildren();
            if (pair[1]) {
                select.appendChild(new Option(pair[1], ""));
            }
            activeOffices().forEach(function (office) {
                select.appendChild(new Option(office.name, office.code));
            });
            select.value = Array.from(select.options).some(function (option) { return option.value === current; }) ? current : (pair[1] ? "" : select.value);
        });
        const routeSelect = document.getElementById("routeOfficeFilter");
        const routeCurrent = routeSelect.value;
        routeSelect.replaceChildren(new Option("All destinations", ""));
        directoryState.offices.forEach(function (office) {
            routeSelect.appendChild(new Option(office.name, office.code));
        });
        routeSelect.value = routeCurrent;
    }

    async function loadDirectory() {
        try {
            const data = await fetchJson("office_directory.php?all=1");
            if (!data || !data.success || !Array.isArray(data.offices)) {
                return;
            }
            directoryState.offices = data.offices;
            Object.keys(officeNames).forEach(function (code) { delete officeNames[code]; });
            data.offices.forEach(function (office) { officeNames[office.code] = office.name; });
            fillDepartmentSelects();
            renderUsers();
        } catch (error) {}
    }

    function departmentKey(user) {
        return user.role === "offices" ? user.office_code : user.role;
    }

    function initials(name) {
        return String(name || "?").split(/\s+/).filter(Boolean).slice(0, 2).map(function (part) {
            return part.charAt(0);
        }).join("").toUpperCase() || "?";
    }

    function element(tag, className, textValue) {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (textValue != null) {
            node.textContent = String(textValue);
        }
        return node;
    }

    function text(id, value) {
        document.getElementById(id).textContent = String(value == null ? "" : value);
    }

    function showMessage(id, message) {
        const element = document.getElementById(id);
        element.textContent = message || "";
        element.hidden = !message;
    }

    async function fetchJson(url, options) {
        const response = await fetch(url, Object.assign({ credentials: "same-origin" }, options || {}));
        let data = null;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error("The server returned an invalid response.");
        }
        if (response.status === 401 || (data && data.message === "Not authenticated")) {
            window.location.href = "login.html";
            throw new Error("Not authenticated");
        }
        return data;
    }

    function roleLabel(role) {
        const labels = {
            admin: "Admin",
            security: "Security",
            offices: "Office",
            visitor: "Visitor",
        };
        return labels[role] || role || "—";
    }

    function statusMeta(status) {
        const statuses = {
            checked_in: { label: "Active", className: "is-active" },
            pending_approval: { label: "Pending approval", className: "is-pending" },
            approved: { label: "Approved", className: "is-active" },
            rejected: { label: "Declined", className: "is-cancelled" },
            unanswered: { label: "Office did not respond", className: "is-cancelled" },
            reschedule_proposed: { label: "Reschedule proposed", className: "is-pending" },
            window_closed: { label: "Appointment done", className: "is-completed" },
            completed: { label: "Completed", className: "is-completed" },
            cancelled: { label: "Cancelled", className: "is-cancelled" },
        };
        return statuses[status] || { label: status || "Unknown", className: "is-completed" };
    }

    function parseServerDate(value) {
        if (!value) {
            return null;
        }
        const normalized = String(value).replace(" ", "T");
        const date = new Date(normalized);
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

    function formatDateTime(value) {
        const date = parseServerDate(value);
        if (!date) {
            return "—";
        }
        return new Intl.DateTimeFormat(undefined, {
            month: "short",
            day: "numeric",
            year: "numeric",
            hour: "numeric",
            minute: "2-digit",
        }).format(date);
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

    function switchView(view) {
        document.querySelectorAll("[data-view-panel]").forEach(function (panel) {
            panel.hidden = panel.getAttribute("data-view-panel") !== view;
        });
        document.querySelectorAll("[data-admin-view]").forEach(function (button) {
            const active = button.getAttribute("data-admin-view") === view;
            button.classList.toggle("is-active", active);
            if (active) {
                button.setAttribute("aria-current", "page");
            } else {
                button.removeAttribute("aria-current");
            }
        });
        if (view === "analytics") {
            // Leaflet cannot measure a hidden container, so draw once the view is visible.
            drawRouteMap();
        }
        let target = window.location.pathname;
        if (view === "users") {
            target += "#users";
        } else if (view === "analytics") {
            target += "#analytics";
        } else if (view === "campus") {
            target += "#campus";
        } else if (view === "security") {
            target += "#security";
        }
        window.history.replaceState(null, "", target);
        window.scrollTo({ top: 0, behavior: "smooth" });
        document.dispatchEvent(new CustomEvent("admin:viewchange", { detail: { view: view } }));
    }

    function renderVisitors() {
        const query = visitorState.query.toLowerCase();
        const rows = visitorState.rows.filter(function (visitor) {
            const matchesStatus = !visitorState.status || visitor.status === visitorState.status;
            const haystack = [
                visitor.visitor_full_name,
                visitor.visit_type,
                visitor.purpose,
                visitor.office_label,
                visitor.office_code,
                visitor.subject,
                visitor.status,
            ].join(" ").toLowerCase();
            return matchesStatus && (!query || haystack.indexOf(query) !== -1);
        });

        recentVisitorsBody.replaceChildren();
        rows.forEach(function (visitor) {
            const row = document.createElement("tr");
            const status = statusMeta(visitor.status);
            appendCell(row, visitor.visitor_full_name || "Visitor", "admin-cell-strong");
            appendCell(row, visitor.visit_type || "Appointment");
            appendCell(row, visitor.purpose || "—");
            appendCell(row, visitor.office_label || visitor.office_code || "—");
            appendCell(row, visitor.subject || "—");
            appendCell(row, formatTime(visitor.activity_at || visitor.appointment_at));
            const statusCell = document.createElement("td");
            const badge = document.createElement("span");
            badge.className = "admin-status " + status.className;
            badge.textContent = status.label;
            statusCell.appendChild(badge);
            const handled = handledByText(visitor);
            if (handled) {
                statusCell.appendChild(element("small", "ua-handled-by", handled));
            }
            row.appendChild(statusCell);
            const historyCell = document.createElement("td");
            historyCell.className = "admin-row-actions";
            const historyButton = element("button", "sec-manage-button", "History");
            historyButton.type = "button";
            historyButton.dataset.appointmentId = String(visitor.id);
            historyButton.title = "Who approved, checked in, or changed this appointment";
            historyCell.appendChild(historyButton);
            row.appendChild(historyCell);
            recentVisitorsBody.appendChild(row);
        });
        recentVisitorsEmpty.hidden = rows.length > 0;
        const wrap = recentVisitorsBody.closest(".admin-table-wrap");
        if (wrap) {
            wrap.hidden = rows.length === 0;
        }
    }

    /** The person responsible for the appointment's current state, e.g. "Approved by Maria Santos". */
    function handledByText(visitor) {
        if (visitor.status === "rejected" && visitor.rejected_by_name) {
            return "Declined by " + visitor.rejected_by_name;
        }
        if (visitor.status === "completed" && visitor.completed_by_name) {
            return "Ended by " + visitor.completed_by_name;
        }
        if (visitor.status === "checked_in" && visitor.checked_in_by_name) {
            return "Checked in by " + visitor.checked_in_by_name;
        }
        if (visitor.approved_by_name && ["approved", "checked_in", "completed", "window_closed"].indexOf(visitor.status) !== -1) {
            return "Approved by " + visitor.approved_by_name;
        }
        return "";
    }

    function renderNotifications(totalPending) {
        const pendingRows = visitorState.rows.filter(function (visitor) {
            return visitor.status === "pending_approval";
        }).slice(0, 5);
        notificationList.replaceChildren();
        pendingRows.forEach(function (visitor) {
            const item = document.createElement("article");
            item.className = "admin-notification-item";
            const marker = document.createElement("span");
            marker.className = "admin-notification-marker";
            marker.setAttribute("aria-hidden", "true");
            const copy = document.createElement("div");
            const name = document.createElement("strong");
            name.textContent = visitor.visitor_full_name || "Visitor";
            const detail = document.createElement("span");
            detail.textContent = visitor.office_label || visitor.office_code || "No destination";
            const time = document.createElement("time");
            time.textContent = formatDateTime(visitor.appointment_at);
            copy.append(name, detail, time);
            item.append(marker, copy);
            notificationList.appendChild(item);
        });
        notificationEmpty.hidden = pendingRows.length > 0;
        notificationEmpty.textContent = Number(totalPending || 0) > 0
            ? "Pending appointments are available. Open the dashboard to view all of them."
            : "No pending appointments right now.";
        text("notificationSummary", Number(totalPending || 0) === 0
            ? "Nothing needs attention"
            : totalPending + " appointment" + (Number(totalPending) === 1 ? " is" : "s are") + " awaiting check-in");
        document.getElementById("viewPendingBtn").hidden = Number(totalPending || 0) === 0;
    }

    /* ---------- User activity entries (dashboard feed, activity log, person view) ---------- */

    function isoDate(date) {
        return date.getFullYear() + "-" + String(date.getMonth() + 1).padStart(2, "0") + "-" + String(date.getDate()).padStart(2, "0");
    }

    function rangeForDays(days) {
        const to = new Date();
        const from = new Date();
        from.setDate(to.getDate() - (days - 1));
        return { from: isoDate(from), to: isoDate(to) };
    }

    function dayLabel(value) {
        const date = parseServerDate(value);
        if (!date) {
            return "";
        }
        const today = isoDate(new Date());
        const yesterday = new Date();
        yesterday.setDate(yesterday.getDate() - 1);
        const day = isoDate(date);
        if (day === today) {
            return "Today";
        }
        if (day === isoDate(yesterday)) {
            return "Yesterday";
        }
        return new Intl.DateTimeFormat(undefined, { weekday: "long", month: "long", day: "numeric", year: "numeric" }).format(date);
    }

    function renderActivityEntry(entry, compact) {
        const tones = { good: "is-good", warn: "is-warn", danger: "is-danger", info: "is-info", muted: "is-muted" };
        const item = element("article", "ua-entry " + (tones[entry.tone] || "is-muted"));
        const avatar = element("span", "ua-avatar", entry.actor ? initials(entry.actor.name) : "SYS");
        avatar.setAttribute("aria-hidden", "true");
        const body = element("div", "ua-entry-body");
        const who = element("div", "ua-entry-who");
        if (entry.actor) {
            const name = element("button", "ua-name-link", entry.actor.name);
            name.type = "button";
            name.dataset.personId = String(entry.actor.id);
            name.title = "Show " + entry.actor.name + "'s activity";
            who.appendChild(name);
            if (!entry.actor.has_name && entry.actor.role !== "visitor") {
                who.appendChild(element("span", "sec-badge is-warn", "No name on file"));
            }
            who.appendChild(element("span", "ua-entry-dept", entry.actor.department + (entry.actor.position ? " · " + entry.actor.position : "")));
        } else {
            who.appendChild(element("strong", "ua-system", "System"));
            who.appendChild(element("span", "ua-entry-dept", "Automatic"));
        }
        body.append(who, element("p", "ua-entry-title", entry.title));
        if (entry.text) {
            body.appendChild(element("p", "ua-entry-text", entry.text));
        }
        const meta = element("div", "ua-entry-meta");
        if (entry.appointment_id) {
            const link = element("button", "ua-chip", "Appointment #" + entry.appointment_id);
            link.type = "button";
            link.dataset.appointmentId = String(entry.appointment_id);
            link.title = "Show this appointment's full history";
            meta.appendChild(link);
        }
        if (!compact && entry.device) {
            meta.appendChild(element("span", "ua-meta-item", entry.device));
        }
        if (!compact && entry.ip) {
            meta.appendChild(element("span", "ua-meta-item", UI.formatIp(entry.ip)));
        }
        if (meta.childNodes.length) {
            body.appendChild(meta);
        }
        const time = element("time", "ua-entry-time", compact ? UI.formatRelative(entry.created_at) : formatTime(entry.created_at));
        time.title = UI.formatDateTime(entry.created_at);
        item.append(avatar, body, time);
        return item;
    }

    async function loadFeed() {
        const range = rangeForDays(7);
        const params = new URLSearchParams({
            from: range.from,
            to: range.to,
            category: feedState.category,
            department: feedState.department,
            limit: "8",
        });
        try {
            const data = await fetchJson("admin_activity.php?" + params.toString());
            recentActivitiesList.replaceChildren();
            const entries = data && data.success && Array.isArray(data.entries) ? data.entries : [];
            entries.forEach(function (entry) {
                recentActivitiesList.appendChild(renderActivityEntry(entry, true));
            });
            recentActivitiesEmpty.hidden = entries.length > 0;
        } catch (error) {}
    }

    async function loadDashboard() {
        showMessage("dashboardError", "");
        try {
            const data = await fetchJson("admin_dashboard.php");
            if (!data || !data.success) {
                showMessage("dashboardError", (data && data.message) || "Could not load dashboard data.");
                return;
            }
            const summary = data.summary || {};
            text("activeVisitorCount", summary.active_visitors || 0);
            text("pendingApprovalCount", summary.pending_approvals || 0);
            text("recentActivitySummary", (summary.recent_activities || 0) + " recent updates");

            const pending = Number(summary.pending_approvals || 0);
            const notification = document.getElementById("notificationCount");
            notification.textContent = pending > 99 ? "99+" : String(pending);
            notification.hidden = pending === 0;

            visitorState.rows = Array.isArray(data.recent_visitors) ? data.recent_visitors : [];
            renderVisitors();
            renderNotifications(pending);
            loadFeed();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("dashboardError", "Could not reach the server. Please try again.");
            }
        }
    }

    function filteredUsers() {
        const query = userState.query.toLowerCase();
        return userState.users
            .filter(function (user) {
                if (userState.role && user.role !== userState.role) {
                    return false;
                }
                if (userState.department && departmentKey(user) !== userState.department) {
                    return false;
                }
                const haystack = [
                    user.username, user.display_name, user.first_name, user.last_name, user.position,
                    user.email, user.contact_number, roleLabel(user.role), user.department,
                ].join(" ").toLowerCase();
                return !query || haystack.indexOf(query) !== -1;
            })
            .sort(function (a, b) {
                const nameA = String(a.display_name || a.username || "");
                const nameB = String(b.display_name || b.username || "");
                return nameA.localeCompare(nameB) * userState.sortDirection;
            });
    }

    function renderUserPages(totalPages) {
        const host = document.getElementById("userPageButtons");
        host.replaceChildren();
        if (totalPages <= 1) {
            return;
        }
        for (let page = 1; page <= totalPages; page += 1) {
            const button = document.createElement("button");
            button.type = "button";
            button.textContent = String(page);
            button.classList.toggle("is-active", page === userState.page);
            button.setAttribute("aria-label", "Page " + page);
            if (page === userState.page) {
                button.setAttribute("aria-current", "page");
            }
            button.addEventListener("click", function () {
                userState.page = page;
                renderUsers();
            });
            host.appendChild(button);
        }
    }

    function renderUsers() {
        const users = filteredUsers();
        const totalPages = Math.max(1, Math.ceil(users.length / userState.pageSize));
        if (userState.page > totalPages) {
            userState.page = totalPages;
        }
        const start = (userState.page - 1) * userState.pageSize;
        const pageUsers = users.slice(start, start + userState.pageSize);
        usersBody.replaceChildren();

        pageUsers.forEach(function (user) {
            const row = document.createElement("tr");
            const userCell = document.createElement("td");
            userCell.className = "admin-user-cell";
            const avatar = document.createElement("span");
            avatar.className = "admin-user-initials" + (user.online ? " is-online" : "");
            const displayName = user.display_name || user.username || "User";
            avatar.textContent = initials(displayName);
            avatar.title = user.online ? "Online now" : "";
            const identity = document.createElement("span");
            const strong = document.createElement("strong");
            strong.textContent = displayName;
            if (!user.has_name && user.role !== "visitor") {
                strong.appendChild(element("span", "sec-badge is-warn person-no-name", "No name on file"));
            }
            const small = document.createElement("small");
            small.textContent = (user.username || "") + (user.position ? " · " + user.position : "");
            identity.append(strong, small);
            userCell.append(avatar, identity);
            row.appendChild(userCell);

            appendCell(row, roleLabel(user.role));
            appendCell(row, user.role === "visitor" ? "—" : (user.department || "—"));

            const statusCell = document.createElement("td");
            const status = document.createElement("span");
            status.className = "admin-status " + (Number(user.is_active) === 1 ? "is-active" : "is-cancelled");
            status.textContent = Number(user.is_active) === 1 ? "Active" : "Suspended";
            statusCell.appendChild(status);
            row.appendChild(statusCell);
            row.appendChild(securityCell(user));

            const actionCell = document.createElement("td");
            actionCell.className = "admin-row-actions";
            const manage = document.createElement("button");
            manage.type = "button";
            manage.className = "sec-manage-button";
            manage.textContent = "Manage";
            manage.dataset.userId = String(user.id);
            manage.dataset.action = "manage";
            manage.title = "Security and recovery options";
            const statusButton = document.createElement("button");
            statusButton.type = "button";
            statusButton.className = "admin-status-button " + (Number(user.is_active) === 1 ? "is-suspend" : "is-activate");
            statusButton.textContent = Number(user.is_active) === 1 ? "Suspend" : "Activate";
            statusButton.dataset.userId = String(user.id);
            statusButton.dataset.action = "status";
            statusButton.dataset.nextActive = Number(user.is_active) === 1 ? "0" : "1";
            statusButton.disabled = Number(user.id) === Number(PhoneTrackerAuth.getUserId());
            statusButton.title = statusButton.disabled ? "You cannot suspend your own account" : statusButton.textContent + " this account";
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "admin-remove-button";
            remove.textContent = "Remove";
            remove.dataset.userId = String(user.id);
            remove.dataset.action = "remove";
            remove.disabled = Number(user.id) === Number(PhoneTrackerAuth.getUserId());
            remove.title = remove.disabled ? "You cannot remove your own account" : "Remove this account";
            actionCell.append(manage, statusButton, remove);
            row.appendChild(actionCell);
            usersBody.appendChild(row);
        });

        usersEmpty.hidden = users.length > 0;
        const noVisitorsYet = users.length === 0 && userState.role === "visitor" && !userState.query && !userState.department;
        text("usersEmptyTitle", noVisitorsYet ? "No visitor accounts yet" : "No matching users");
        text("usersEmptyText", noVisitorsYet
            ? "Visitors appear here once they create an account in the ISATU Visitor app or on the visitor website."
            : "Try a different role or search term.");
        const wrap = usersBody.closest(".admin-table-wrap");
        if (wrap) {
            wrap.hidden = users.length === 0;
        }
        if (users.length === 0) {
            text("usersShowing", "Showing 0 users");
        } else {
            text("usersShowing", "Showing " + (start + 1) + "–" + (start + pageUsers.length) + " of " + users.length + " users");
        }
        renderUserPages(totalPages);
    }

    /** How many accounts each role filter tab shows. */
    function renderRoleCounts() {
        document.querySelectorAll("[data-role-count]").forEach(function (badge) {
            const role = badge.getAttribute("data-role-count");
            const count = userState.users.filter(function (user) { return !role || user.role === role; }).length;
            badge.textContent = count > 999 ? "999+" : String(count);
            badge.hidden = false;
        });
    }

    function updateUserSummary() {
        const total = userState.users.length;
        const active = userState.users.filter(function (user) { return Number(user.is_active) === 1; }).length;
        text("totalUserCount", total);
        text("activeUserCount", active);
        text("suspendedUserCount", total - active);
    }

    async function loadUsers() {
        showMessage("adminListError", "");
        try {
            const data = await fetchJson("list_users.php");
            if (!data || !data.success) {
                showMessage("adminListError", (data && data.message) || "Could not load users.");
                return;
            }
            userState.users = Array.isArray(data.data) ? data.data : [];
            updateUserSummary();
            renderRoleCounts();
            renderUsers();
            if (activityState.loaded) {
                fillPersonSelect();
            }
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("adminListError", "Could not reach the server. Please try again.");
            }
        }
    }

    function formatDuration(minutes) {
        const value = Number(minutes || 0);
        if (value <= 0) {
            return "—";
        }
        const hours = Math.floor(value / 60);
        const remaining = value % 60;
        if (hours > 0 && remaining > 0) {
            return hours + "h " + remaining + "m";
        }
        return hours > 0 ? hours + "h" : remaining + "m";
    }

    function formatAnalyticsRange(range) {
        if (!range || !range.start || !range.end) {
            return "Selected reporting period";
        }
        const start = parseServerDate(range.start);
        const end = parseServerDate(range.end);
        if (!start || !end) {
            return "Selected reporting period";
        }
        const formatter = new Intl.DateTimeFormat(undefined, {
            month: "short",
            day: "numeric",
            year: "numeric",
        });
        return formatter.format(start) + " – " + formatter.format(end);
    }

    function createSvgElement(name, attributes, content) {
        const element = document.createElementNS("http://www.w3.org/2000/svg", name);
        Object.keys(attributes || {}).forEach(function (key) {
            element.setAttribute(key, String(attributes[key]));
        });
        if (content != null) {
            element.textContent = String(content);
        }
        return element;
    }

    function renderTrendChart(trend) {
        const svg = document.getElementById("visitorTrendChart");
        const empty = document.getElementById("visitorTrendEmpty");
        const labels = trend && Array.isArray(trend.labels) ? trend.labels : [];
        const checkIns = trend && Array.isArray(trend.check_ins) ? trend.check_ins.map(Number) : [];
        const checkOuts = trend && Array.isArray(trend.check_outs) ? trend.check_outs.map(Number) : [];
        const allValues = checkIns.concat(checkOuts);
        const hasData = allValues.some(function (value) { return value > 0; });
        const maximum = Math.max.apply(null, [1].concat(allValues));
        const width = 680;
        const height = 270;
        const margin = { top: 22, right: 20, bottom: 42, left: 46 };
        const plotWidth = width - margin.left - margin.right;
        const plotHeight = height - margin.top - margin.bottom;
        svg.replaceChildren(createSvgElement("title", {}, "Visitor check-in and check-out trend"));

        for (let step = 0; step <= 4; step += 1) {
            const y = margin.top + (plotHeight / 4) * step;
            const value = Math.round(maximum - (maximum / 4) * step);
            svg.appendChild(createSvgElement("line", {
                x1: margin.left,
                y1: y,
                x2: width - margin.right,
                y2: y,
                class: "analytics-grid-line",
            }));
            svg.appendChild(createSvgElement("text", {
                x: margin.left - 9,
                y: y + 4,
                class: "analytics-axis-label",
                "text-anchor": "end",
            }, value));
        }

        const xFor = function (index) {
            return labels.length <= 1
                ? margin.left + plotWidth / 2
                : margin.left + (index / (labels.length - 1)) * plotWidth;
        };
        const yFor = function (value) {
            return margin.top + plotHeight - (Number(value) / maximum) * plotHeight;
        };
        const labelStep = Math.max(1, Math.ceil(labels.length / 8));
        labels.forEach(function (label, index) {
            if (index % labelStep !== 0 && index !== labels.length - 1) {
                return;
            }
            svg.appendChild(createSvgElement("text", {
                x: xFor(index),
                y: height - 15,
                class: "analytics-axis-label",
                "text-anchor": "middle",
            }, label));
        });

        function drawSeries(values, className) {
            if (values.length === 0) {
                return;
            }
            const points = values.map(function (value, index) {
                return xFor(index) + "," + yFor(value);
            }).join(" ");
            svg.appendChild(createSvgElement("polyline", {
                points: points,
                class: "analytics-trend-line " + className,
            }));
            values.forEach(function (value, index) {
                if (value <= 0) {
                    return;
                }
                const point = createSvgElement("circle", {
                    cx: xFor(index),
                    cy: yFor(value),
                    r: 3.5,
                    class: "analytics-trend-point " + className,
                });
                const title = createSvgElement("title", {}, labels[index] + ": " + value);
                point.appendChild(title);
                svg.appendChild(point);
            });
        }

        drawSeries(checkIns, "is-checkin");
        drawSeries(checkOuts, "is-checkout");
        svg.classList.toggle("is-empty", !hasData);
        empty.hidden = hasData;
    }

    function renderPurposeDistribution(items, purposeCollected) {
        const donut = document.getElementById("analyticsPurposeDonut");
        const legend = document.getElementById("analyticsPurposeLegend");
        const empty = document.getElementById("analyticsPurposeEmpty");
        const colors = ["#377fec", "#806fe8", "#13bfd1", "#2ac79b", "#f0a72e", "#ed657a"];
        const rows = Array.isArray(items) ? items : [];
        const total = rows.reduce(function (sum, item) { return sum + Number(item.count || 0); }, 0);
        text("analyticsPurposeTotal", total);
        legend.replaceChildren();

        if (total === 0) {
            donut.style.background = "#e9edf2";
            empty.hidden = false;
            empty.textContent = purposeCollected
                ? "No visit-purpose data was recorded during this period."
                : "Purpose analytics will activate when a purpose field is collected with appointments.";
            return;
        }

        let running = 0;
        const stops = [];
        rows.forEach(function (item, index) {
            const start = (running / total) * 100;
            running += Number(item.count || 0);
            const end = (running / total) * 100;
            stops.push(colors[index % colors.length] + " " + start + "% " + end + "%");

            const row = document.createElement("div");
            const labelWrap = document.createElement("span");
            const swatch = document.createElement("i");
            swatch.style.background = colors[index % colors.length];
            const label = document.createElement("span");
            label.textContent = item.label;
            labelWrap.append(swatch, label);
            const percent = document.createElement("strong");
            percent.textContent = Math.round((Number(item.count || 0) / total) * 100) + "%";
            row.append(labelWrap, percent);
            legend.appendChild(row);
        });
        donut.style.background = "conic-gradient(" + stops.join(", ") + ")";
        empty.hidden = true;
    }

    function renderHeatmap(heatmap) {
        const host = document.getElementById("analyticsHeatmap");
        const days = heatmap && Array.isArray(heatmap.days) ? heatmap.days : [];
        const hours = heatmap && Array.isArray(heatmap.hours) ? heatmap.hours : [];
        const values = heatmap && Array.isArray(heatmap.values) ? heatmap.values : [];
        const flat = values.reduce(function (all, row) { return all.concat(row.map(Number)); }, []);
        const maximum = Math.max.apply(null, [1].concat(flat));
        host.replaceChildren();
        host.style.setProperty("--heat-columns", String(hours.length));

        host.appendChild(document.createElement("span"));
        hours.forEach(function (hour) {
            const label = document.createElement("span");
            label.className = "analytics-heat-label is-hour";
            label.textContent = hour;
            host.appendChild(label);
        });
        days.forEach(function (day, dayIndex) {
            const dayLabel = document.createElement("span");
            dayLabel.className = "analytics-heat-label is-day";
            dayLabel.textContent = day;
            host.appendChild(dayLabel);
            hours.forEach(function (hour, hourIndex) {
                const value = Number((values[dayIndex] && values[dayIndex][hourIndex]) || 0);
                const ratio = value / maximum;
                const cell = document.createElement("span");
                cell.className = "analytics-heat-cell";
                cell.style.setProperty("--heat-opacity", String(0.1 + ratio * 0.9));
                cell.title = day + " " + hour + ": " + value + " check-in" + (value === 1 ? "" : "s");
                cell.setAttribute("aria-label", cell.title);
                host.appendChild(cell);
            });
        });
    }

    function renderLocationOverview(overview, zonesConfigured) {
        const data = overview || {};
        const metrics = document.getElementById("analyticsLocationMetrics");
        const zones = document.getElementById("analyticsZones");
        const empty = document.getElementById("analyticsZonesEmpty");
        text("analyticsActiveTracks", Number(data.tracked_now || 0) + " active track" + (Number(data.tracked_now || 0) === 1 ? "" : "s"));
        metrics.replaceChildren();
        [
            { label: "Tracked now", value: data.tracked_now || 0, className: "is-blue" },
            { label: "Awaiting GPS", value: data.awaiting_gps || 0, className: "is-purple" },
            { label: "Devices seen", value: data.devices_seen || 0, className: "is-green" },
            { label: "Location updates", value: data.updates_recorded || 0, className: "is-orange" },
        ].forEach(function (metric) {
            const card = document.createElement("div");
            card.className = "analytics-location-metric " + metric.className;
            const value = document.createElement("strong");
            value.textContent = String(metric.value);
            const label = document.createElement("span");
            label.textContent = metric.label;
            card.append(value, label);
            metrics.appendChild(card);
        });

        zones.replaceChildren();
        const zoneRows = Array.isArray(data.zones) ? data.zones : [];
        zoneRows.forEach(function (zone) {
            const card = document.createElement("div");
            card.className = "analytics-zone-card";
            const title = document.createElement("strong");
            title.textContent = zone.name;
            const copy = document.createElement("span");
            copy.textContent = zone.visitors + " visitor" + (Number(zone.visitors) === 1 ? "" : "s") + " · " + zone.updates + " updates";
            card.append(title, copy);
            zones.appendChild(card);
        });
        empty.hidden = zoneRows.length > 0;
        empty.textContent = zonesConfigured
            ? "No campus-zone activity was recorded during this period."
            : "Campus zones are not configured yet. Your backend developer can add a zone_name field or geofence mapping later.";
    }

    function renderInsights(insights) {
        const host = document.getElementById("analyticsInsightList");
        const rows = Array.isArray(insights) ? insights : [];
        host.replaceChildren();
        rows.forEach(function (insight) {
            const item = document.createElement("article");
            item.className = "analytics-insight is-" + (insight.type || "neutral");
            const icon = document.createElement("span");
            icon.className = "analytics-insight-icon";
            icon.textContent = insight.type === "warning" ? "!" : insight.type === "success" ? "✓" : "↗";
            const copy = document.createElement("div");
            const title = document.createElement("strong");
            title.textContent = insight.title || "Insight";
            const message = document.createElement("p");
            message.textContent = insight.message || "";
            copy.append(title, message);
            item.append(icon, copy);
            host.appendChild(item);
        });
    }

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

    function renderRouteMetrics(summary) {
        const data = summary || {};
        const routes = Number(data.routes || 0);
        const busiest = Number(data.busiest_segment_visitors || 0);
        const metrics = document.getElementById("routeAnalyticsMetrics");
        metrics.replaceChildren();
        [
            { label: "Routes analyzed", value: routes, className: "is-blue" },
            { label: "Avg. walking distance", value: formatDistance(data.average_distance_meters), className: "is-green" },
            { label: "Routes on busiest path", value: routes > 0 ? Math.round((busiest / routes) * 100) + "%" : "—", className: "is-orange" },
            { label: "GPS points used", value: Number(data.points_used || 0), className: "is-purple" },
        ].forEach(function (metric) {
            const card = document.createElement("div");
            card.className = "analytics-location-metric " + metric.className;
            const value = document.createElement("strong");
            value.textContent = String(metric.value);
            const label = document.createElement("span");
            label.textContent = metric.label;
            card.append(value, label);
            metrics.appendChild(card);
        });
    }

    function renderRouteDestinations(destinations) {
        const host = document.getElementById("routeDestinationList");
        const rows = Array.isArray(destinations) ? destinations : [];
        const maxRoutes = rows.reduce(function (max, item) { return Math.max(max, Number(item.routes || 0)); }, 0);
        host.replaceChildren();
        rows.forEach(function (item) {
            const routes = Number(item.routes || 0);
            const button = document.createElement("button");
            button.type = "button";
            button.className = "analytics-route-destination" + (item.code === routeState.office ? " is-active" : "");
            button.setAttribute("data-route-office", item.code || "");
            const title = document.createElement("strong");
            title.textContent = item.label || officeNames[item.code] || item.code || "Unknown destination";
            const copy = document.createElement("span");
            copy.textContent = routes + " route" + (routes === 1 ? "" : "s") + " · avg " + formatDistance(item.average_distance_meters) + " walked";
            const walk = document.createElement("span");
            walk.className = "analytics-route-walk";
            walk.textContent = describeWalkToOffice(item);
            const bar = document.createElement("div");
            bar.className = "analytics-route-bar";
            const fill = document.createElement("i");
            fill.style.width = (maxRoutes > 0 ? Math.round((routes / maxRoutes) * 100) : 0) + "%";
            bar.appendChild(fill);
            button.append(title, copy, walk, bar);
            host.appendChild(button);
        });
        document.getElementById("routeDestinationEmpty").hidden = rows.length > 0;
    }

    function describeWalkToOffice(item) {
        if (!item.has_pin) {
            return "Place this office's pin in Campus Map to measure the walk to it.";
        }
        const arrivals = Number(item.arrivals || 0);
        if (arrivals === 0) {
            return "No tracked visitor reached the office pin yet.";
        }
        const walked = Number(item.average_walk_to_office_meters || 0);
        const direct = Number(item.average_direct_meters || 0);
        const detour = direct > 0 ? " (" + (walked / direct).toFixed(1) + "× the direct distance)" : "";
        const minutes = Number(item.average_minutes_to_office || 0);
        return "To office: " + formatDistance(walked) + detour + " · " + minutes + " min · "
            + arrivals + " arrival" + (arrivals === 1 ? "" : "s");
    }

    function renderRouteGates(gates, routes) {
        const host = document.getElementById("routeGateList");
        const rows = Array.isArray(gates) ? gates : [];
        const empty = document.getElementById("routeGateEmpty");
        host.replaceChildren();
        rows.forEach(function (gate) {
            const entries = Number(gate.entries || 0);
            const exits = Number(gate.exits || 0);
            const card = document.createElement("div");
            card.className = "analytics-route-destination is-static";
            const title = document.createElement("strong");
            title.textContent = gate.name;
            const copy = document.createElement("span");
            copy.textContent = entries + " entr" + (entries === 1 ? "y" : "ies")
                + (routes > 0 ? " (" + Math.round((entries / routes) * 100) + "%)" : "")
                + " · " + exits + " exit" + (exits === 1 ? "" : "s");
            const bar = document.createElement("div");
            bar.className = "analytics-route-bar is-gate";
            const fill = document.createElement("i");
            fill.style.width = (routes > 0 ? Math.round((entries / routes) * 100) : 0) + "%";
            bar.appendChild(fill);
            card.append(title, copy, bar);
            host.appendChild(card);
        });
        empty.hidden = rows.length > 0;
    }

    async function applyRouteCampus() {
        routeState.campus = await CampusMap.load();
        if (!routeState.map) {
            return;
        }
        CampusMap.drawOverlay(routeState.campusLayer, routeState.campus);
        // Frame the campus only if the route data has not already framed the map.
        CampusMap.lockToCampus(routeState.map, routeState.campus, !routeState.fittedKey);
    }

    function drawRouteMap() {
        const host = document.getElementById("routeAnalyticsMap");
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
            // In the page, the mouse wheel scrolls the page; in full view it zooms the map.
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
            routeState.campusLayer = L.layerGroup().addTo(routeState.map);
            routeState.layer = L.layerGroup().addTo(routeState.map);
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
        // Segments arrive busiest first; draw them last so they sit on top.
        segments.slice().reverse().forEach(function (segment) {
            const ratio = Number(segment.visitors || 0) / maxVisitors;
            L.polyline([segment.from, segment.to], {
                color: routeUsageColor(ratio),
                weight: 3 + ratio * 7,
                opacity: 0.55 + ratio * 0.4,
                lineCap: "round",
            }).bindTooltip(segment.visitors + " of " + routes + " visitor route" + (routes === 1 ? "" : "s") + " used this path", { sticky: true })
                .addTo(routeState.layer);
        });
        document.getElementById("routeAnalyticsEmpty").hidden = segments.length > 0;

        // Only re-frame the map when the filter changes, so auto-refresh keeps the admin's zoom.
        const key = analyticsState.period + "|" + routeState.office;
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
        const params = new URLSearchParams({ period: analyticsState.period });
        if (routeState.office) {
            params.set("office", routeState.office);
        }
        try {
            const data = await fetchJson("admin_route_analytics.php?" + params.toString());
            if (!data || !data.success) {
                showMessage("routeAnalyticsError", (data && data.message) || "Could not load route analytics.");
                return;
            }
            routeState.data = data;
            renderRouteMetrics(data.summary);
            renderRouteDestinations(data.destinations);
            renderRouteGates(data.gates, Number((data.summary && data.summary.routes) || 0));
            document.getElementById("routeCampusHint").hidden = Boolean(data.campus_configured);
            drawRouteMap();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("routeAnalyticsError", error.message || "Could not reach the analytics server.");
            }
        }
    }

    function setRouteOffice(office) {
        routeState.office = office || "";
        document.getElementById("routeOfficeFilter").value = routeState.office;
        loadRouteAnalytics();
    }

    async function loadAnalytics() {
        loadRouteAnalytics();
        showMessage("analyticsError", "");
        document.querySelectorAll("[data-analytics-period]").forEach(function (button) {
            button.classList.toggle("is-active", button.getAttribute("data-analytics-period") === analyticsState.period);
        });
        const exportPeriod = "admin_analytics.php?period=" + encodeURIComponent(analyticsState.period);
        document.getElementById("analyticsExportBtn").href = exportPeriod + "&format=xlsx";
        document.getElementById("analyticsCsvBtn").href = exportPeriod + "&format=csv";
        try {
            const data = await fetchJson("admin_analytics.php?period=" + encodeURIComponent(analyticsState.period));
            if (!data || !data.success) {
                showMessage("analyticsError", (data && data.message) || "Could not load visitor analytics.");
                return;
            }
            analyticsState.loaded = true;
            const summary = data.summary || {};
            text("analyticsRangeLabel", formatAnalyticsRange(data.range));
            text("analyticsTotalVisitors", summary.total_visitors || 0);
            text("analyticsGpsTracked", summary.gps_tracked_now || 0);
            text("analyticsAverageDuration", formatDuration(summary.average_duration_minutes));
            renderTrendChart(data.trend || {});
            renderPurposeDistribution(data.purpose_distribution, Boolean(data.capabilities && data.capabilities.purpose_collected));
            renderHeatmap(data.heatmap || {});
            renderLocationOverview(data.location_overview || {}, Boolean(data.capabilities && data.capabilities.zones_configured));
            renderInsights(data.insights || []);
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("analyticsError", error.message || "Could not reach the analytics server.");
            }
        }
    }

    /* ---------- Account security in the user list ---------- */

    function securityBadges(user) {
        const badges = [];
        if (user.locked) {
            badges.push(["Locked", "is-danger"]);
        }
        if (user.temp_password_expired) {
            badges.push(["Temp password expired", "is-danger"]);
        } else if (user.must_change_password) {
            badges.push([user.temp_password_expires_at ? "Temporary password" : "Default password", "is-warn"]);
        }
        if (user.role !== "visitor") {
            if (user.two_factor_enabled) {
                badges.push(["2-step on", "is-good"]);
            } else {
                badges.push([user.two_factor_required ? "2-step required" : "2-step off", user.two_factor_required ? "is-warn" : "is-muted"]);
            }
        }
        return badges;
    }

    function securityCell(user) {
        const cell = document.createElement("td");
        const wrap = document.createElement("div");
        wrap.className = "sec-badges";
        securityBadges(user).forEach(function (badge) {
            const item = document.createElement("span");
            item.className = "sec-badge " + badge[1];
            item.textContent = badge[0];
            wrap.appendChild(item);
        });
        const last = document.createElement("small");
        last.className = "sec-last-login";
        last.textContent = user.last_login_at ? "Last sign-in " + UI.formatRelative(user.last_login_at) : "Never signed in";
        last.title = user.last_login_at ? UI.formatDateTime(user.last_login_at) + " from " + UI.formatIp(user.last_login_ip) : "";
        cell.append(wrap, last);
        return cell;
    }

    function showTemporaryPassword(info) {
        text("tempPasswordEyebrow", info.eyebrow);
        text("tempPasswordName", info.name || info.username);
        text("tempPasswordUsername", info.username);
        text("tempPasswordValue", info.password);
        text("tempPasswordExpires", info.expires ? UI.formatDateTime(info.expires) : "in 24 hours");
        document.getElementById("tempPasswordTwoFactor").hidden = !info.twoFactor;
        document.getElementById("copyTempPasswordBtn").textContent = "Copy";
        tempPasswordDialog.showModal();
    }

    function findUser(id) {
        return userState.users.find(function (item) { return Number(item.id) === Number(id); }) || null;
    }

    function openManageUser(id) {
        manageUserId = id;
        showMessage("manageUserError", "");
        showMessage("manageUserSuccess", "");
        closeEditDetails();
        renderManageUser();
        if (!manageUserDialog.open) {
            manageUserDialog.showModal();
        }
    }

    function renderManageUser() {
        const user = findUser(manageUserId);
        if (!user) {
            if (manageUserDialog.open) {
                manageUserDialog.close();
            }
            return;
        }
        text("manageUserTitle", user.display_name || user.username);
        let password = "Chosen by the user";
        if (user.temp_password_expired) {
            password = "Temporary password expired";
        } else if (user.must_change_password) {
            password = user.temp_password_expires_at
                ? "Temporary, expires " + UI.formatDateTime(user.temp_password_expires_at)
                : "Default password, must be changed at sign-in";
        }
        let twoFactor = "Not used for visitor accounts";
        if (user.role !== "visitor") {
            twoFactor = user.two_factor_enabled ? "On" : (user.two_factor_required ? "Required, set up at next sign-in" : "Off");
        }
        const details = document.getElementById("manageUserDetails");
        details.replaceChildren();
        [
            ["Name", user.has_name ? user.first_name + " " + user.last_name : "No name on file — add it with Edit details"],
            ["Position", user.position || "—"],
            ["Username", user.username],
            ["Role", roleLabel(user.role) + (user.role === "offices" ? " · " + (officeNames[user.office_code] || user.office_code) : "")],
            ["Contact", [user.contact_number, user.email].filter(Boolean).join(" · ") || "—"],
            ["Status", (Number(user.is_active) === 1 ? "Active" : "Suspended") + (user.online ? " · online now" : (user.last_seen_at ? " · last seen " + UI.formatRelative(user.last_seen_at) : ""))],
            ["Last sign-in", user.last_login_at ? UI.formatDateTime(user.last_login_at) + " · " + UI.formatIp(user.last_login_ip) : "Never"],
            ["Failed sign-ins (24 h)", String(user.failed_24h || 0)],
            ["Password", password],
            ["Two-step verification", twoFactor],
            ["Sign-in", user.locked ? "Paused by a lockout" : "Not locked"],
        ].forEach(function (pair) {
            const term = document.createElement("dt");
            term.textContent = pair[0];
            const value = document.createElement("dd");
            value.textContent = pair[1];
            if ((pair[0] === "Sign-in" && user.locked) || (pair[0] === "Password" && user.temp_password_expired)
                || (pair[0] === "Name" && !user.has_name)) {
                value.className = pair[0] === "Name" ? "is-warn" : "is-danger";
            }
            details.append(term, value);
        });

        const actions = document.getElementById("manageUserActions");
        actions.replaceChildren();
        const label = user.display_name || user.username;
        addManageAction(actions, "Edit details", "Name, position, contact details" + (user.role === "offices" ? ", and department." : "."), "Edit details", function () {
            openEditDetails(user);
        });
        if (user.role !== "visitor") {
            addManageAction(actions, "View activity", "Sign-ins, approvals, check-ins, and other actions by this person.", "View activity", function () {
                manageUserDialog.close();
                openPersonDialog(user.id);
            });
        }
        if (Number(user.id) === Number(PhoneTrackerAuth.getUserId())) {
            addManageAction(actions, "Your own account", "Change your password, two-step verification, and sessions in Account security.", "Open Account security", function () {
                manageUserDialog.close();
                Security.open();
            });
            return;
        }
        if (user.locked) {
            addManageAction(actions, "Unlock sign-in", "Lets this account sign in again right away.", "Unlock", function () {
                runManageAction("unlock_user", user, "");
            });
        }
        addManageAction(actions, "Reset password", "Creates a one-time temporary password and signs the user out everywhere.", "Reset password", function () {
            runManageAction("reset_password", user, "Reset the password for " + label + "? They will be signed out on every device.");
        });
        if (user.two_factor_enabled) {
            addManageAction(actions, "Reset two-step verification", user.two_factor_required
                ? "For a lost phone. They set it up again at their next sign-in."
                : "For a lost phone. Turns two-step verification off for this account.", "Reset 2-step", function () {
                runManageAction("reset_two_factor", user, "Reset two-step verification for " + label + "?");
            });
        }
        addManageAction(actions, "Sign out everywhere", "Ends this user's open sessions on every device.", "Sign out", function () {
            runManageAction("force_logout", user, "Sign " + label + " out on every device?");
        });
    }

    /* ---------- Edit a person's details (Manage dialog) ---------- */

    function openEditDetails(user) {
        document.getElementById("editFirstName").value = user.first_name || "";
        document.getElementById("editLastName").value = user.last_name || "";
        document.getElementById("editPosition").value = user.position || "";
        document.getElementById("editContactNumber").value = user.contact_number || "";
        document.getElementById("editEmail").value = user.email || "";
        const isOffice = user.role === "offices";
        document.getElementById("editDepartmentField").hidden = !isOffice;
        if (isOffice) {
            const select = document.getElementById("editDepartment");
            // Keep the person's current department selectable even if it was archived.
            if (!Array.from(select.options).some(function (option) { return option.value === user.office_code; })) {
                select.appendChild(new Option((officeNames[user.office_code] || user.office_code) + " (current)", user.office_code));
            }
            select.value = user.office_code;
        }
        showMessage("manageUserError", "");
        showMessage("manageUserSuccess", "");
        document.getElementById("manageEditForm").hidden = false;
        document.getElementById("manageUserActions").hidden = true;
        document.getElementById("editFirstName").focus();
    }

    function closeEditDetails() {
        document.getElementById("manageEditForm").hidden = true;
        document.getElementById("manageUserActions").hidden = false;
    }

    async function saveEditDetails(event) {
        event.preventDefault();
        const user = findUser(manageUserId);
        if (!user) {
            return;
        }
        const payload = {
            user_id: user.id,
            first_name: document.getElementById("editFirstName").value.trim(),
            last_name: document.getElementById("editLastName").value.trim(),
            position: document.getElementById("editPosition").value.trim(),
            contact_number: document.getElementById("editContactNumber").value.trim(),
            email: document.getElementById("editEmail").value.trim(),
        };
        if (user.role === "offices") {
            payload.office_code = document.getElementById("editDepartment").value;
        }
        if (!payload.first_name || !payload.last_name) {
            showMessage("manageUserError", "Enter the person's first and last name.");
            return;
        }
        if (user.role === "offices" && payload.office_code !== user.office_code
            && !window.confirm("Move " + (user.display_name || user.username) + " to " + (officeNames[payload.office_code] || payload.office_code)
                + "? They will be signed out and will only see that department's appointments.")) {
            return;
        }
        const button = document.getElementById("saveEditDetailsBtn");
        button.disabled = true;
        try {
            const data = await Security.withStepUp(function () {
                return fetchJson("update_user.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify(payload),
                });
            });
            if (!data || !data.success) {
                if (!data || !data.cancelled) {
                    showMessage("manageUserError", (data && data.message) || "Could not save the details.");
                }
                return;
            }
            closeEditDetails();
            await loadUsers();
            renderManageUser();
            showMessage("manageUserSuccess", data.message || "Saved.");
            loadDepartments();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("manageUserError", "Could not reach the server. Please try again.");
            }
        } finally {
            button.disabled = false;
        }
    }

    /* ---------- Department directory ---------- */

    function renderDepartments() {
        const grid = document.getElementById("departmentsGrid");
        grid.replaceChildren();
        departmentState.departments.forEach(function (department) {
            const card = element("article", "dept-card" + (department.is_active ? "" : " is-archived"));
            const header = element("header", "dept-card-header");
            const title = element("div");
            title.append(element("h3", "", department.name), element("span", "dept-code", department.code));
            header.append(title, element("span", "sec-badge " + (department.is_active ? "is-good" : "is-muted"), department.is_active ? "Active" : "Archived"));
            card.appendChild(header);
            card.appendChild(element("p", "dept-location", department.location || "No location set"));
            if (department.description) {
                card.appendChild(element("p", "dept-description", department.description));
            }

            const stats = element("div", "dept-stats");
            [
                [department.personnel.length, department.personnel.length === 1 ? "Person" : "People"],
                [department.pending_appointments, "Waiting for approval"],
                [department.open_appointments, "Open appointments"],
            ].forEach(function (stat) {
                const box = element("div");
                box.append(element("strong", "", stat[0]), element("span", "", stat[1]));
                stats.appendChild(box);
            });
            card.appendChild(stats);

            const flags = element("div", "dept-flags");
            if (department.is_active) {
                flags.appendChild(element("span", "sec-badge " + (department.accepting_visitors ? "is-good" : "is-warn"),
                    department.accepting_visitors ? "Accepting visitors" : "Not accepting visitors"));
            }
            if (department.has_map_pin) {
                flags.appendChild(element("span", "sec-badge is-good", "On campus map"));
            } else {
                const pin = element("button", "sec-badge is-warn dept-pin-link", "No map pin — add one");
                pin.type = "button";
                pin.dataset.deptAction = "pin";
                flags.appendChild(pin);
            }
            if (department.is_active && department.personnel.filter(function (p) { return p.is_active; }).length === 0) {
                flags.appendChild(element("span", "sec-badge is-warn", "No one to approve requests"));
            }
            card.appendChild(flags);

            const people = element("ul", "dept-people");
            department.personnel.forEach(function (person) {
                const item = element("li");
                const button = element("button", "dept-person");
                button.type = "button";
                button.dataset.personId = String(person.id);
                button.title = "Show " + person.name + "'s activity";
                button.append(
                    element("span", "dept-dot" + (person.online ? " is-online" : ""), ""),
                    element("span", "dept-person-name", person.name + (person.position ? " · " + person.position : "")),
                );
                if (!person.has_name) {
                    button.appendChild(element("span", "sec-badge is-warn", "No name"));
                }
                if (!person.is_active) {
                    button.appendChild(element("span", "sec-badge is-muted", "Suspended"));
                }
                item.appendChild(button);
                people.appendChild(item);
            });
            if (!department.personnel.length) {
                people.appendChild(element("li", "dept-empty", "No Office Personnel yet"));
            }
            card.appendChild(people);

            const actions = element("footer", "dept-actions");
            const addAction = function (label, action, className) {
                const button = element("button", className || "sec-manage-button", label);
                button.type = "button";
                button.dataset.deptAction = action;
                actions.appendChild(button);
            };
            if (department.is_active) {
                addAction("Add personnel", "add-person");
            }
            addAction("Edit", "edit");
            addAction(department.is_active ? "Archive" : "Restore", department.is_active ? "archive" : "restore");
            if (department.total_appointments === 0 && department.personnel.length === 0) {
                addAction("Delete", "delete", "sec-manage-button is-danger");
            }
            card.dataset.code = department.code;
            card.appendChild(actions);
            grid.appendChild(card);
        });
        if (!departmentState.departments.length) {
            grid.appendChild(element("p", "dept-empty", "No departments yet. Add the first one."));
        }
    }

    async function loadDepartments() {
        try {
            const data = await fetchJson("admin_departments.php");
            if (!data || !data.success) {
                showMessage("departmentsError", (data && data.message) || "Could not load the department directory.");
                return;
            }
            showMessage("departmentsError", "");
            departmentState.departments = data.departments || [];
            renderDepartments();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("departmentsError", "Could not reach the server. Please try again.");
            }
        }
    }

    function openDepartmentDialog(department) {
        departmentState.editingCode = department ? department.code : "";
        departmentState.codeEdited = Boolean(department);
        text("departmentDialogTitle", department ? "Edit " + department.name : "Add department");
        text("saveDepartmentBtn", department ? "Save changes" : "Add department");
        document.getElementById("departmentName").value = department ? department.name : "";
        const code = document.getElementById("departmentCode");
        code.value = department ? department.code : "";
        code.readOnly = Boolean(department);
        text("departmentCodeHint", department
            ? "The short code is permanent because appointments and accounts store it."
            : "Used in records and reports. It cannot be changed later.");
        document.getElementById("departmentLocation").value = department ? department.location : "";
        document.getElementById("departmentDescription").value = department ? department.description : "";
        showMessage("departmentFormError", "");
        document.getElementById("departmentDialog").showModal();
        document.getElementById("departmentName").focus();
    }

    function suggestDepartmentCode() {
        if (departmentState.editingCode || departmentState.codeEdited) {
            return;
        }
        window.clearTimeout(departmentState.suggestTimer);
        const name = document.getElementById("departmentName").value.trim();
        if (name.length < 2) {
            document.getElementById("departmentCode").value = "";
            return;
        }
        departmentState.suggestTimer = window.setTimeout(async function () {
            try {
                const data = await fetchJson("admin_departments.php?suggest_code=" + encodeURIComponent(name));
                if (data && data.success && !departmentState.codeEdited && document.getElementById("departmentName").value.trim() === name) {
                    document.getElementById("departmentCode").value = data.code;
                }
            } catch (error) {}
        }, 300);
    }

    async function saveDepartment(event) {
        event.preventDefault();
        const payload = {
            action: departmentState.editingCode ? "update" : "create",
            code: departmentState.editingCode || document.getElementById("departmentCode").value.trim().toUpperCase(),
            name: document.getElementById("departmentName").value.trim(),
            location: document.getElementById("departmentLocation").value.trim(),
            description: document.getElementById("departmentDescription").value.trim(),
        };
        if (payload.name.length < 2) {
            showMessage("departmentFormError", "Enter the department name.");
            return;
        }
        const button = document.getElementById("saveDepartmentBtn");
        button.disabled = true;
        try {
            const data = await departmentRequest(payload);
            if (!data || !data.success) {
                if (!data || !data.cancelled) {
                    showMessage("departmentFormError", (data && data.message) || "Could not save the department.");
                }
                return;
            }
            document.getElementById("departmentDialog").close();
            showMessage("departmentsSuccess", data.message || "Saved.");
            afterDepartmentChange();
        } finally {
            button.disabled = false;
        }
    }

    function departmentRequest(payload) {
        return Security.withStepUp(function () {
            return fetchJson("admin_departments.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload),
            });
        }).catch(function (error) {
            return { success: false, message: error.message === "Not authenticated" ? "" : "Could not reach the server. Please try again." };
        });
    }

    function afterDepartmentChange() {
        loadDepartments();
        loadDirectory();
        loadUsers();
        document.dispatchEvent(new CustomEvent("departments:updated"));
    }

    async function handleDepartmentAction(button) {
        const card = button.closest(".dept-card");
        const department = departmentState.departments.find(function (item) { return item.code === (card && card.dataset.code); });
        if (!department) {
            return;
        }
        const action = button.dataset.deptAction;
        if (action === "edit") {
            openDepartmentDialog(department);
            return;
        }
        if (action === "add-person") {
            openAddUser("offices", department.code);
            return;
        }
        if (action === "pin") {
            switchView("campus");
            return;
        }
        let confirmText = "";
        if (action === "archive") {
            const people = department.personnel.filter(function (p) { return p.is_active; }).length;
            confirmText = "Archive " + department.name + "?\n\nVisitors will no longer be able to book it. Its history stays."
                + (department.open_appointments ? "\n" + department.open_appointments + " open appointment(s) will still need to be handled." : "")
                + (people ? "\n" + people + " active personnel are still assigned — move or suspend them afterwards." : "");
        } else if (action === "delete") {
            confirmText = "Delete " + department.name + "? This cannot be undone.";
        } else if (action === "restore") {
            confirmText = "Make " + department.name + " active again so visitors can book it?";
        }
        if (confirmText && !window.confirm(confirmText)) {
            return;
        }
        button.disabled = true;
        const data = await departmentRequest({ action: action, code: department.code });
        button.disabled = false;
        if (!data || !data.success) {
            if (!data || !data.cancelled) {
                showMessage("departmentsError", (data && data.message) || "Could not update the department.");
            }
            return;
        }
        showMessage("departmentsError", "");
        showMessage("departmentsSuccess", data.message || "Done.");
        afterDepartmentChange();
    }

    /* ---------- Security page: User activity tab ---------- */

    function selectSecurityTab(tab) {
        activityState.tab = tab;
        document.querySelectorAll("[data-sec-tab]").forEach(function (button) {
            const active = button.getAttribute("data-sec-tab") === tab;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-selected", String(active));
            button.tabIndex = active ? 0 : -1;
        });
        document.getElementById("secPanelSignin").hidden = tab !== "signin";
        document.getElementById("secPanelActivity").hidden = tab !== "activity";
        window.history.replaceState(null, "", window.location.pathname + (tab === "activity" ? "#activity" : "#security"));
        if (tab === "activity" && !activityState.loaded) {
            fillPersonSelect();
            loadUserActivity(true);
        }
    }

    function activityRange() {
        if (activityState.period === "custom") {
            const from = document.getElementById("uaFrom").value;
            const to = document.getElementById("uaTo").value;
            if (from && to) {
                return { from: from, to: to };
            }
            return rangeForDays(7);
        }
        return activityState.period === "today" ? rangeForDays(1) : rangeForDays(Number(activityState.period));
    }

    function activityParams(extra) {
        const range = activityRange();
        const params = new URLSearchParams({
            from: range.from,
            to: range.to,
            category: activityState.category,
            department: activityState.department,
            user_id: activityState.userId,
            q: activityState.q,
        });
        Object.keys(extra || {}).forEach(function (key) { params.set(key, extra[key]); });
        return params;
    }

    /** People who can be picked in the "Person" filter for the chosen department. */
    function fillPersonSelect() {
        const select = document.getElementById("uaPerson");
        const current = activityState.userId;
        select.replaceChildren(new Option("Everyone", ""));
        userState.users
            .filter(function (user) {
                if (activityState.department === "system") {
                    return false;
                }
                if (!activityState.department) {
                    return user.role !== "visitor";
                }
                return departmentKey(user) === activityState.department;
            })
            .sort(function (a, b) { return String(a.display_name).localeCompare(String(b.display_name)); })
            .forEach(function (user) {
                select.appendChild(new Option((user.display_name || user.username) + " (" + user.username + ")", String(user.id)));
            });
        select.value = Array.from(select.options).some(function (option) { return option.value === current; }) ? current : "";
        activityState.userId = select.value;
    }

    function renderActivityCounts(counts) {
        document.querySelectorAll("[data-ua-count]").forEach(function (badge) {
            const value = counts ? Number(counts[badge.getAttribute("data-ua-count")] || 0) : 0;
            badge.textContent = value > 999 ? "999+" : String(value);
            badge.hidden = !counts;
        });
    }

    function appendActivityLog(entries, reset) {
        const log = document.getElementById("uaLog");
        if (reset) {
            log.replaceChildren();
            activityState.lastDay = "";
        }
        entries.forEach(function (entry) {
            const day = dayLabel(entry.created_at);
            if (day !== activityState.lastDay) {
                log.appendChild(element("h3", "ua-day", day));
                activityState.lastDay = day;
            }
            log.appendChild(renderActivityEntry(entry, false));
        });
    }

    function formatDay(isoDay) {
        const date = parseServerDate(isoDay + " 00:00:00");
        return date ? new Intl.DateTimeFormat(undefined, { month: "short", day: "numeric", year: "numeric" }).format(date) : isoDay;
    }

    async function loadUserActivity(reset) {
        // A new filter starts a new list; "Load older activity" continues the current one.
        if (reset) {
            activityState.requestId += 1;
            activityState.nextBeforeId = null;
            loadActivityBoard();
        }
        const requestId = activityState.requestId;
        const range = activityRange();
        document.getElementById("uaExportBtn").href = "admin_activity.php?" + activityParams({ format: "xlsx" }).toString();
        document.getElementById("uaCsvBtn").href = "admin_activity.php?" + activityParams({ format: "csv" }).toString();
        showMessage("uaError", "");
        const params = activityParams({ limit: "50" });
        if (!reset && activityState.nextBeforeId) {
            params.set("before_id", String(activityState.nextBeforeId));
        }
        try {
            const data = await fetchJson("admin_activity.php?" + params.toString());
            if (requestId !== activityState.requestId) {
                return;
            }
            if (!data || !data.success) {
                showMessage("uaError", (data && data.message) || "Could not load the activity log.");
                return;
            }
            activityState.loaded = true;
            if (data.counts) {
                renderActivityCounts(data.counts);
                const total = Number(data.counts[activityState.category] || 0);
                text("uaLogSummary", total + (total === 1 ? " activity" : " activities") + " · "
                    + (range.from === range.to ? formatDay(range.from) : formatDay(range.from) + " to " + formatDay(range.to)));
            }
            appendActivityLog(data.entries || [], reset);
            activityState.nextBeforeId = data.has_more ? data.next_before_id : null;
            document.getElementById("uaMoreBtn").hidden = !data.has_more;
            const empty = reset && (!data.entries || data.entries.length === 0);
            document.getElementById("uaLogEmpty").hidden = !empty;
            document.getElementById("uaLog").hidden = empty;
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("uaError", "Could not reach the server. Please try again.");
            }
        }
    }

    function countChips(counts) {
        const chips = [];
        [
            ["approved", "approved", "approved", "is-good"],
            ["declined", "declined", "declined", "is-danger"],
            ["rescheduled", "new time offered", "new times offered", "is-warn"],
            ["meetings_done", "meeting done", "meetings done", "is-good"],
            ["checked_in", "checked in", "checked in", "is-good"],
            ["visits_ended", "visit ended", "visits ended", "is-muted"],
            ["pass_overrides", "pass override", "pass overrides", "is-warn"],
            ["schedule_changes", "schedule change", "schedule changes", "is-info"],
            ["sign_ins", "sign-in", "sign-ins", "is-muted"],
        ].forEach(function (spec) {
            const value = Number(counts[spec[0]] || 0);
            if (value > 0) {
                chips.push(element("span", "ua-count " + spec[3], value + " " + (value === 1 ? spec[1] : spec[2])));
            }
        });
        return chips;
    }

    async function loadActivityBoard() {
        const token = (activityState.boardRequestId || 0) + 1;
        activityState.boardRequestId = token;
        const range = activityRange();
        try {
            const data = await fetchJson("admin_activity.php?" + new URLSearchParams({ view: "board", from: range.from, to: range.to }).toString());
            if (token !== activityState.boardRequestId || !data || !data.success) {
                return;
            }
            const board = document.getElementById("uaBoard");
            board.replaceChildren();
            data.departments
                .filter(function (department) {
                    return !activityState.department || activityState.department === department.key;
                })
                .forEach(function (department) {
                    const card = element("article", "ua-dept");
                    const header = element("header", "ua-dept-header");
                    const online = department.people.filter(function (p) { return p.online; }).length;
                    const title = element("div");
                    title.append(
                        element("h3", "", department.name),
                        element("span", "ua-dept-meta", department.people.length + (department.people.length === 1 ? " person" : " people") + (online ? " · " + online + " online" : "")),
                    );
                    header.appendChild(title);
                    const badges = element("div", "ua-dept-badges");
                    if (department.kind === "office" && department.pending_appointments > 0) {
                        badges.appendChild(element("span", "sec-badge is-warn", department.pending_appointments + " waiting for approval"));
                    }
                    if (!department.is_active) {
                        badges.appendChild(element("span", "sec-badge is-muted", "Archived"));
                    }
                    header.appendChild(badges);
                    card.appendChild(header);

                    const list = element("ul", "ua-people");
                    department.people.forEach(function (person) {
                        const item = element("li");
                        const button = element("button", "ua-person" + (person.is_active ? "" : " is-suspended"));
                        button.type = "button";
                        button.dataset.personId = String(person.id);
                        const avatar = element("span", "ua-avatar" + (person.online ? " is-online" : ""), initials(person.name));
                        avatar.setAttribute("aria-hidden", "true");
                        const main = element("span", "ua-person-main");
                        const name = element("strong", "", person.name);
                        if (!person.has_name) {
                            name.appendChild(element("span", "sec-badge is-warn person-no-name", "No name on file"));
                        }
                        if (!person.is_active) {
                            name.appendChild(element("span", "sec-badge is-muted person-no-name", "Suspended"));
                        }
                        main.append(name, element("small", "", (person.position || roleLabel(person.role)) + " · " + person.username));
                        const meta = element("span", "ua-person-meta");
                        // Last seen started being recorded with this update; fall back to the last sign-in.
                        const seenAt = person.last_seen_at || person.last_login_at;
                        meta.append(
                            element("span", person.online ? "is-online" : "", person.online ? "Online now" : (seenAt ? "Seen " + UI.formatRelative(seenAt) : "Never signed in")),
                            element("small", "", person.last_login_at ? "Signed in " + UI.formatRelative(person.last_login_at) : ""),
                        );
                        const counts = element("span", "ua-person-counts");
                        const chips = countChips(person.counts);
                        if (chips.length) {
                            chips.forEach(function (chip) { counts.appendChild(chip); });
                        } else {
                            counts.appendChild(element("span", "ua-count is-none", "No actions in this period"));
                        }
                        button.append(avatar, main, meta, counts);
                        item.appendChild(button);
                        list.appendChild(item);
                    });
                    if (!department.people.length) {
                        list.appendChild(element("li", "dept-empty", "No accounts in this department yet"));
                    }
                    card.appendChild(list);
                    board.appendChild(card);
                });
        } catch (error) {}
    }

    /* ---------- Person and appointment dialogs ---------- */

    function fillDetails(host, rows) {
        host.replaceChildren();
        rows.forEach(function (row) {
            const value = element("dd", row[2] || "", row[1] == null || row[1] === "" ? "—" : row[1]);
            host.append(element("dt", "", row[0]), value);
        });
    }

    async function openPersonDialog(userId) {
        personDialogUserId = Number(userId);
        const dialog = document.getElementById("personDialog");
        document.getElementById("personLoading").hidden = false;
        document.getElementById("personBody").hidden = true;
        text("personDialogTitle", "Activity");
        text("personDialogDepartment", "Person");
        if (!dialog.open) {
            dialog.showModal();
        }
        try {
            const range = rangeForDays(30);
            const [profile, activity] = await Promise.all([
                fetchJson("admin_activity.php?" + new URLSearchParams({ view: "person", user_id: String(userId) }).toString()),
                fetchJson("admin_activity.php?" + new URLSearchParams({ user_id: String(userId), from: range.from, to: range.to, limit: "25" }).toString()),
            ]);
            if (personDialogUserId !== Number(userId)) {
                return;
            }
            if (!profile || !profile.success) {
                text("personLoading", (profile && profile.message) || "Could not load this person.");
                return;
            }
            const person = profile.person;
            text("personDialogTitle", person.name);
            text("personDialogDepartment", person.department + (person.position ? " · " + person.position : ""));
            fillDetails(document.getElementById("personDetails"), [
                ["Name", person.has_name ? person.first_name + " " + person.last_name : "No name on file", person.has_name ? "" : "is-warn"],
                ["Username", person.username],
                ["Role", roleLabel(person.role)],
                ["Contact", [person.contact_number, person.email].filter(Boolean).join(" · ")],
                ["Status", (person.is_active ? "Active" : "Suspended") + (person.online ? " · online now" : "")],
                ["Last seen", person.last_seen_at ? UI.formatDateTime(person.last_seen_at) : "Never"],
                ["Last sign-in", person.last_login_at ? UI.formatDateTime(person.last_login_at) + " · " + UI.formatIp(person.last_login_ip) : "Never"],
                ["Two-step verification", person.two_factor_enabled ? "On" : "Off"],
                ["Account created", UI.formatDateTime(person.created_at)],
            ]);

            const stats = document.getElementById("personStats");
            stats.replaceChildren();
            const counts = profile.counts_30d || {};
            [
                [counts.decisions, "Approvals & declines"],
                [counts.gate, "Gate & visits"],
                [counts.schedules, "Schedule changes"],
                [counts.signins, "Sign-ins & sign-outs"],
                [profile.failed_sign_ins_30d, "Failed sign-ins"],
            ].forEach(function (stat) {
                const box = element("div", "ua-stat");
                box.append(element("strong", "", Number(stat[0] || 0)), element("span", "", stat[1]));
                stats.appendChild(box);
            });
            stats.appendChild(element("p", "ua-stat-note", "Last 30 days"));

            const devices = document.getElementById("personDevices");
            devices.replaceChildren();
            profile.devices.forEach(function (device) {
                const item = element("li");
                const copy = element("div");
                copy.append(
                    element("strong", "", device.device + (device.channel === "mobile" ? " (visitor app)" : "")),
                    element("small", "", UI.formatIp(device.ip) + " · " + device.uses + (device.uses === 1 ? " sign-in" : " sign-ins") + " · last " + UI.formatRelative(device.last_used)),
                );
                item.appendChild(copy);
                devices.appendChild(item);
            });
            if (!profile.devices.length) {
                devices.appendChild(element("li", "acct-empty", "No sign-ins in the last 30 days."));
            }
            const warning = document.getElementById("personSharedWarning");
            warning.hidden = profile.distinct_devices < 3;
            warning.textContent = "This account was used on " + profile.distinct_devices + " different devices or networks in 30 days. "
                + "If several people share it, give each person their own account so every action has a name.";

            const log = document.getElementById("personActivity");
            log.replaceChildren();
            const entries = activity && activity.success ? activity.entries : [];
            let lastDay = "";
            entries.forEach(function (entry) {
                const day = dayLabel(entry.created_at);
                if (day !== lastDay) {
                    log.appendChild(element("h3", "ua-day", day));
                    lastDay = day;
                }
                log.appendChild(renderActivityEntry(entry, false));
            });
            if (!entries.length) {
                log.appendChild(element("p", "acct-empty", "No activity in the last 30 days."));
            }
            document.getElementById("personManageBtn").hidden = !findUser(person.id);
            document.getElementById("personLoading").hidden = true;
            document.getElementById("personBody").hidden = false;
        } catch (error) {
            if (error.message !== "Not authenticated") {
                text("personLoading", "Could not reach the server. Please try again.");
            }
        }
    }

    async function openTrail(appointmentId) {
        const dialog = document.getElementById("trailDialog");
        document.getElementById("trailLoading").hidden = false;
        document.getElementById("trailBody").hidden = true;
        showMessage("trailError", "");
        text("trailDialogTitle", "Appointment #" + appointmentId);
        text("trailDialogEyebrow", "Appointment history");
        if (!dialog.open) {
            dialog.showModal();
        }
        try {
            const data = await fetchJson("admin_activity.php?" + new URLSearchParams({ view: "appointment", appointment_id: String(appointmentId) }).toString());
            document.getElementById("trailLoading").hidden = true;
            if (!data || !data.success) {
                showMessage("trailError", (data && data.message) || "Could not load this appointment.");
                return;
            }
            const appointment = data.appointment;
            text("trailDialogTitle", appointment.visitor_name || "Visitor");
            text("trailDialogEyebrow", "Appointment #" + appointment.id + " · " + appointment.office);
            fillDetails(document.getElementById("trailDetails"), [
                ["Visitor", appointment.visitor_name],
                ["Contact", [appointment.contact_number, appointment.visitor_email].filter(Boolean).join(" · ")],
                ["Office", appointment.office],
                ["Scheduled", UI.formatDateTime(appointment.scheduled_start_at)],
                ["Purpose", [appointment.purpose, appointment.subject].filter(Boolean).join(" · ")],
                ["Status", appointment.status.charAt(0).toUpperCase() + appointment.status.slice(1)],
                ["Requested", UI.formatDateTime(appointment.created_at)],
            ].concat(appointment.rejection_reason ? [["Decline reason", appointment.rejection_reason]] : []));

            const handled = document.getElementById("trailHandled");
            handled.replaceChildren();
            data.handled_by.forEach(function (item) {
                const chip = element("div", "ua-handled-item");
                chip.append(
                    element("span", "ua-handled-label", item.label),
                    element("strong", "", item.person ? item.person.name : "Unknown"),
                    element("small", "", (item.person ? item.person.department + " · " : "") + (item.at ? UI.formatDateTime(item.at) : "")),
                );
                handled.appendChild(chip);
            });
            handled.hidden = data.handled_by.length === 0;

            const timeline = document.getElementById("trailTimeline");
            timeline.replaceChildren();
            const tones = { good: "is-good", warn: "is-warn", danger: "is-danger", info: "is-info", muted: "is-muted" };
            if (data.events.length) {
                data.events.forEach(function (entry) {
                    const item = element("li", "ua-timeline-item " + (tones[entry.tone] || "is-muted"));
                    const copy = element("div");
                    copy.append(element("strong", "", entry.title), element("p", "", entry.text));
                    copy.appendChild(element("small", "", (entry.actor ? "by " + entry.actor.name + " · " + entry.actor.department : "Automatic")
                        + " · " + UI.formatDateTime(entry.created_at) + (entry.ip ? " · " + UI.formatIp(entry.ip) : "")));
                    item.appendChild(copy);
                    timeline.appendChild(item);
                });
            } else {
                // Older records only have the status history.
                data.history.forEach(function (row) {
                    const item = element("li", "ua-timeline-item is-info");
                    const copy = element("div");
                    copy.append(element("strong", "", (row.from ? row.from + " → " : "") + row.to));
                    if (row.note) {
                        copy.appendChild(element("p", "", row.note));
                    }
                    copy.appendChild(element("small", "", (row.by ? "by " + row.by.name + " · " + row.by.department : "Automatic") + " · " + UI.formatDateTime(row.at)));
                    item.appendChild(copy);
                    timeline.appendChild(item);
                });
            }
            if (!timeline.childNodes.length) {
                timeline.appendChild(element("li", "acct-empty", "No recorded actions yet."));
            }
            document.getElementById("trailBody").hidden = false;
        } catch (error) {
            document.getElementById("trailLoading").hidden = true;
            if (error.message !== "Not authenticated") {
                showMessage("trailError", "Could not reach the server. Please try again.");
            }
        }
    }

    function addManageAction(host, title, description, buttonLabel, handler) {
        const row = document.createElement("div");
        row.className = "sec-manage-action";
        const copy = document.createElement("div");
        const strong = document.createElement("strong");
        strong.textContent = title;
        const small = document.createElement("small");
        small.textContent = description;
        copy.append(strong, small);
        const button = document.createElement("button");
        button.type = "button";
        button.className = "admin-cancel-button";
        button.textContent = buttonLabel;
        button.addEventListener("click", handler);
        row.append(copy, button);
        host.appendChild(row);
    }

    async function runManageAction(action, user, confirmText) {
        if (confirmText && !window.confirm(confirmText)) {
            return;
        }
        showMessage("manageUserError", "");
        showMessage("manageUserSuccess", "");
        try {
            const data = await Security.withStepUp(function () {
                return fetchJson("admin_security.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ action: action, user_id: Number(user.id) }),
                });
            });
            if (!data || !data.success) {
                if (!data || !data.cancelled) {
                    showMessage("manageUserError", (data && data.message) || "Could not complete that action.");
                }
                return;
            }
            if (action === "reset_password") {
                manageUserDialog.close();
                showTemporaryPassword({
                    eyebrow: "Password reset",
                    name: data.display_name,
                    username: data.username,
                    password: data.temporary_password,
                    expires: data.expires_at,
                    twoFactor: false,
                });
            } else {
                showMessage("manageUserSuccess", data.message || "Done.");
            }
            await loadUsers();
            renderManageUser();
            loadSecurity();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("manageUserError", "Could not reach the server. Please try again.");
            }
        }
    }

    /* ---------- Security view ---------- */

    const LOCK_SCOPES = {
        account_device: "This account, one network",
        account: "This account, all networks",
        ip: "Every account, one network",
    };
    const EVENT_LABELS = {
        "auth.lockout": ["Sign-in paused after failed attempts", "is-danger"],
        "auth.account_alarm": ["Account locked: attempts from several networks", "is-danger"],
        "auth.lockout_released": ["Lockout released", "is-good"],
        "auth.password_changed": ["Password changed", "is-good"],
        "auth.two_factor_enabled": ["Two-step verification turned on", "is-good"],
        "auth.two_factor_disabled": ["Two-step verification turned off", "is-warn"],
        "auth.backup_code_used": ["Backup code used to sign in", "is-warn"],
        "auth.backup_codes_regenerated": ["New backup codes created", "is-good"],
        "auth.device_trusted": ["Browser trusted for 30 days", "is-muted"],
        "auth.trusted_devices_forgotten": ["Trusted browsers forgotten", "is-muted"],
        "auth.signed_out_other_sessions": ["Signed out of other sessions", "is-muted"],
        "user.created": ["Account created", "is-good"],
        "user.deleted": ["Account removed", "is-danger"],
        "user.suspended": ["Account suspended", "is-danger"],
        "user.activated": ["Account activated", "is-good"],
        "user.unlocked": ["Account unlocked", "is-good"],
        "user.password_reset": ["Password reset by an administrator", "is-warn"],
        "user.signed_out_by_admin": ["Signed out by an administrator", "is-warn"],
    };

    function describeEvent(event) {
        const details = event.details || {};
        if (event.action === "auth.lockout" || event.action === "auth.account_alarm") {
            const who = details.identifier || "Any account";
            const where = details.scope === "account" ? "all networks" : UI.formatIp(details.ip);
            return who + " · " + where + " · " + UI.formatMinutes(details.minutes || 1) + (details.channel === "mobile" ? " · visitor app" : "");
        }
        const target = event.target_name || details.username || "";
        if (details.by === "command_line") {
            return (target ? target + " · " : "") + "from the server command line";
        }
        if (event.actor_name && target && event.actor_name !== target) {
            return target + " · by " + event.actor_name;
        }
        return target || event.actor_name || "";
    }

    function updateLockCountdowns() {
        document.querySelectorAll("[data-lock-until]").forEach(function (cell) {
            const left = (Number(cell.dataset.lockUntil) - Date.now()) / 1000;
            cell.textContent = left > 0 ? UI.formatCountdown(left) : "Ended";
        });
    }

    function renderSecurity() {
        const data = securityState.data;
        if (!data) {
            return;
        }
        const stats = data.stats;
        text("secFailed", stats.failed_24h);
        text("secLocked", stats.active_lockouts);
        text("secTwoFactor", stats.staff_two_factor + "/" + stats.staff_total);
        text("secMustChange", stats.must_change);
        const badge = document.getElementById("securityNavBadge");
        badge.textContent = stats.active_lockouts > 99 ? "99+" : String(stats.active_lockouts);
        badge.hidden = stats.active_lockouts === 0;

        const lockBody = document.getElementById("lockoutsBody");
        lockBody.replaceChildren();
        data.lockouts.forEach(function (lock) {
            const row = document.createElement("tr");
            const account = appendCell(row, lock.scope === "ip" ? "Any account" : (lock.display_name || lock.identifier));
            if (lock.scope !== "ip" && lock.display_name && lock.identifier) {
                const small = document.createElement("small");
                small.className = "sec-cell-note";
                small.textContent = lock.identifier;
                account.appendChild(small);
            }
            appendCell(row, LOCK_SCOPES[lock.scope] || lock.scope);
            appendCell(row, lock.scope === "account" ? "All networks" : UI.formatIp(lock.ip));
            appendCell(row, lock.failure_count + " failed" + (lock.blocked_attempts ? " · " + lock.blocked_attempts + " blocked" : ""));
            const ends = appendCell(row, "");
            ends.className = "sec-countdown";
            ends.dataset.lockUntil = String(securityState.receivedAt + lock.retry_after * 1000);
            const actionCell = document.createElement("td");
            actionCell.className = "admin-row-actions";
            const unlock = document.createElement("button");
            unlock.type = "button";
            unlock.className = "sec-manage-button";
            unlock.textContent = "Unlock";
            unlock.dataset.lockId = String(lock.id);
            actionCell.appendChild(unlock);
            row.appendChild(actionCell);
            lockBody.appendChild(row);
        });
        document.getElementById("lockoutsWrap").hidden = data.lockouts.length === 0;
        document.getElementById("lockoutsEmpty").hidden = data.lockouts.length > 0;
        updateLockCountdowns();

        const attemptBody = document.getElementById("attemptsBody");
        attemptBody.replaceChildren();
        const moreButton = document.getElementById("attemptsMoreBtn");
        moreButton.hidden = data.attempts.length <= securityState.attemptLimit;
        moreButton.textContent = "Show more (" + (data.attempts.length - securityState.attemptLimit) + " older)";
        data.attempts.slice(0, securityState.attemptLimit).forEach(function (attempt) {
            const row = document.createElement("tr");
            const time = appendCell(row, UI.formatRelative(attempt.created_at));
            time.title = UI.formatDateTime(attempt.created_at);
            const account = appendCell(row, attempt.display_name || attempt.identifier || "—");
            if (attempt.user_id === null && attempt.identifier) {
                const small = document.createElement("small");
                small.className = "sec-cell-note";
                small.textContent = "no such account";
                account.appendChild(small);
            }
            const result = document.createElement("td");
            const pill = document.createElement("span");
            pill.className = "sec-badge " + (attempt.outcome === "success" ? "is-good" : "is-danger");
            pill.textContent = attempt.outcome === "success" ? "Success" : "Failed";
            const reason = document.createElement("small");
            reason.className = "sec-cell-note";
            reason.textContent = UI.describeAttempt(attempt);
            result.append(pill, reason);
            row.appendChild(result);
            appendCell(row, attempt.device);
            appendCell(row, UI.formatIp(attempt.ip));
            appendCell(row, attempt.channel === "mobile" ? "Visitor app" : "Website");
            attemptBody.appendChild(row);
        });
        document.getElementById("attemptsWrap").hidden = data.attempts.length === 0;
        document.getElementById("attemptsEmpty").hidden = data.attempts.length > 0;

        const events = document.getElementById("securityEvents");
        events.replaceChildren();
        data.events.forEach(function (event) {
            const meta = EVENT_LABELS[event.action] || [event.action, "is-muted"];
            const item = document.createElement("li");
            const marker = document.createElement("span");
            marker.className = "sec-event-marker " + meta[1];
            const copy = document.createElement("div");
            const title = document.createElement("strong");
            title.textContent = meta[0];
            const detail = document.createElement("small");
            detail.textContent = describeEvent(event);
            copy.append(title, detail);
            const time = document.createElement("time");
            time.textContent = UI.formatRelative(event.created_at);
            time.title = UI.formatDateTime(event.created_at);
            item.append(marker, copy, time);
            events.appendChild(item);
        });
        document.getElementById("securityEventsEmpty").hidden = data.events.length > 0;

        const policy = data.policy;
        text("securityPolicy", "Policy: sign-in pauses for " + policy.base_minutes + " minutes after " + policy.threshold
            + " wrong attempts on one account from one network, doubling for repeats within 24 hours. An account is locked everywhere after "
            + policy.account_alarm_failures + " failures from " + policy.account_alarm_ips + "+ networks in an hour, and a network is paused after "
            + policy.ip_failures + " failures across accounts in 10 minutes. "
            + (policy.idle_minutes > 0
                ? "Sessions end after " + policy.idle_minutes + " minutes of inactivity"
                : "Staff stay signed in until they sign out")
            + (policy.session_max_hours > 0 ? ", for up to " + policy.session_max_hours + " hours after signing in. " : ". ")
            + "Two-step verification is required for: "
            + (policy.two_factor_required_roles.length ? policy.two_factor_required_roles.map(roleLabel).join(", ") : "no roles") + ".");
    }

    async function loadSecurity() {
        try {
            const data = await fetchJson("admin_security.php?filter=" + encodeURIComponent(securityState.filter));
            if (!data || !data.success) {
                showMessage("securityError", (data && data.message) || "Could not load security information.");
                return;
            }
            showMessage("securityError", "");
            securityState.data = data;
            securityState.receivedAt = Date.now();
            renderSecurity();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("securityError", "Could not reach the server. Please try again.");
            }
        }
    }

    function updateOfficeField() {
        document.getElementById("newUserTwoFactorNote").hidden = document.getElementById("newUserRole").value !== "admin";
        const showOffice = document.getElementById("newUserRole").value === "offices";
        const field = document.getElementById("newUserOfficeField");
        const select = document.getElementById("newUserOfficeCode");
        field.hidden = !showOffice;
        select.required = showOffice;
        if (!showOffice) {
            select.value = "";
        }
    }

    function closeAddUserDialog() {
        addUserDialog.close();
        showMessage("adminFormError", "");
    }

    document.querySelectorAll("[data-admin-view]").forEach(function (button) {
        button.addEventListener("click", function () {
            const view = button.getAttribute("data-admin-view");
            switchView(view);
            if (view === "analytics" && !analyticsState.loaded) {
                loadAnalytics();
            }
            if (view === "security") {
                loadSecurity();
            }
        });
    });

    document.querySelectorAll("[data-analytics-period]").forEach(function (button) {
        button.addEventListener("click", function () {
            analyticsState.period = button.getAttribute("data-analytics-period") || "month";
            loadAnalytics();
        });
    });

    /* ---------- Resetting Visitor Analytics ---------- */

    // Deletes visit records after the admin picks what to delete, types RESET, and enters a
    // fresh authenticator (or backup) code; the server checks all three again.
    const resetDialog = document.getElementById("analyticsResetDialog");
    const resetState = { counts: null, twoFactor: true, backupCodes: 0, method: "authenticator", busy: false };

    function resetScope() {
        const checked = document.querySelector("input[name='analyticsResetScope']:checked");
        return checked ? checked.value : "all";
    }

    function plural(count, one, many) {
        return Number(count || 0).toLocaleString() + " " + (Number(count) === 1 ? one : many);
    }

    function resetCountsFor(scope) {
        return resetState.counts ? resetState.counts[scope] || null : null;
    }

    function renderResetImpact() {
        const host = document.getElementById("analyticsResetImpact");
        const live = document.getElementById("analyticsResetLive");
        const scope = resetScope();
        const counts = resetCountsFor(scope);
        host.replaceChildren();
        live.hidden = true;
        if (!counts) {
            host.appendChild(element("p", "acct-loading", "Counting records…"));
            updateResetSubmit();
            return;
        }
        host.appendChild(element("p", "reset-impact-title", "This will permanently delete:"));
        const grid = element("div", "reset-impact-grid");
        [
            [counts.appointments, "Visit record", "Visit records"],
            [counts.gps_points, "GPS point", "GPS points"],
            [counts.tracking_sessions, "Tracking session", "Tracking sessions"],
            [counts.notifications, "Notification", "Notifications"],
        ].forEach(function (item) {
            const cell = element("div", "reset-impact-item" + (Number(item[0]) > 0 ? "" : " is-zero"));
            cell.append(element("strong", "", Number(item[0] || 0).toLocaleString()), element("span", "", Number(item[0]) === 1 ? item[1] : item[2]));
            grid.appendChild(cell);
        });
        host.appendChild(grid);
        const notes = [];
        if (scope === "all" && counts.upcoming > 0) {
            notes.push(plural(counts.upcoming, "upcoming booking", "upcoming bookings"));
        }
        if (counts.visits > 0) {
            notes.push(plural(counts.visits, "multi-office visit", "multi-office visits"));
        }
        if (counts.oldest && counts.newest) {
            const oldest = formatDateTime(counts.oldest);
            const newest = formatDateTime(counts.newest);
            notes.push(oldest === newest ? "dated " + oldest : "dated " + oldest + " to " + newest);
        }
        if (notes.length) {
            host.appendChild(element("p", "reset-impact-note", "Includes " + notes.join(" · ")));
        }
        if (scope === "all" && counts.on_campus > 0) {
            live.textContent = plural(counts.on_campus, "visitor is", "visitors are") +
                " on campus right now. Their visit and live tracking end and are deleted too; Security will no longer see them.";
            live.hidden = false;
        }
        updateResetSubmit();
    }

    function resetCodeValid() {
        const code = document.getElementById("analyticsResetCode").value.trim();
        return resetState.method === "backup_code"
            ? code.replace(/[^A-Za-z0-9]/g, "").length === 10
            : /^\d{6}$/.test(code);
    }

    function updateResetSubmit() {
        const counts = resetCountsFor(resetScope());
        const nothing = Boolean(counts) && !counts.appointments && !counts.gps_points && !counts.visits;
        const confirmed = document.getElementById("analyticsResetConfirm").value.trim() === "RESET";
        const submit = document.getElementById("analyticsResetSubmit");
        submit.disabled = resetState.busy || !resetState.twoFactor || !counts || nothing || !confirmed || !resetCodeValid();
        if (resetState.busy) {
            submit.textContent = "Deleting…";
        } else if (nothing) {
            submit.textContent = "Nothing to delete";
        } else {
            submit.textContent = resetScope() === "all" ? "Delete all visitor data" : "Delete finished visits";
        }
    }

    function setResetMethod(method) {
        resetState.method = method;
        const input = document.getElementById("analyticsResetCode");
        const backup = method === "backup_code";
        input.value = "";
        input.maxLength = backup ? 12 : 6;
        input.inputMode = backup ? "text" : "numeric";
        input.placeholder = backup ? "Backup code" : "6-digit code";
        text("analyticsResetCodeLabel", backup ? "Backup code" : "Authenticator code");
        text("analyticsResetCodeHelp", backup
            ? "One of the backup codes you saved when you set up two-step verification. Each code works once."
            : "The same 6-digit code you enter when you sign in.");
        const toggle = document.getElementById("analyticsResetMethodBtn");
        toggle.textContent = backup ? "Use my authenticator app instead" : "Use a backup code instead";
        toggle.hidden = !backup && resetState.backupCodes === 0;
        updateResetSubmit();
    }

    async function openResetDialog() {
        resetState.counts = null;
        resetState.busy = false;
        document.getElementById("analyticsResetForm").reset();
        document.getElementById("analyticsResetNo2fa").hidden = true;
        showMessage("analyticsResetError", "");
        setResetMethod("authenticator");
        renderResetImpact();
        resetDialog.showModal();
        try {
            const data = await fetchJson("admin_analytics_reset.php", { cache: "no-store" });
            if (!data || !data.success) {
                showMessage("analyticsResetError", (data && data.message) || "Could not count the visit records.");
                return;
            }
            resetState.counts = data.scopes || null;
            resetState.twoFactor = Boolean(data.two_factor_enabled);
            resetState.backupCodes = Number(data.backup_codes_remaining || 0);
            document.getElementById("analyticsResetNo2fa").hidden = resetState.twoFactor;
            document.getElementById("analyticsResetCodeField").hidden = !resetState.twoFactor;
            setResetMethod("authenticator");
            renderResetImpact();
            document.getElementById("analyticsResetConfirm").focus();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("analyticsResetError", "Could not reach the server. Please try again.");
            }
        }
    }

    function closeResetDialog() {
        if (resetState.busy) {
            return;
        }
        document.getElementById("analyticsResetCode").value = "";
        if (resetDialog.open) {
            resetDialog.close();
        }
    }

    async function submitReset(event) {
        event.preventDefault();
        updateResetSubmit();
        if (document.getElementById("analyticsResetSubmit").disabled) {
            return;
        }
        const scope = resetScope();
        resetState.busy = true;
        updateResetSubmit();
        showMessage("analyticsResetError", "");
        try {
            const data = await fetchJson("admin_analytics_reset.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    scope: scope,
                    confirm: document.getElementById("analyticsResetConfirm").value.trim(),
                    method: resetState.method,
                    code: document.getElementById("analyticsResetCode").value.trim(),
                }),
            });
            if (!data || !data.success) {
                let message = (data && data.message) || "The reset did not go through.";
                if (data && data.attempts_remaining != null && data.attempts_remaining <= 3) {
                    message += " " + plural(data.attempts_remaining, "try", "tries") + " left before sign-in is paused.";
                }
                showMessage("analyticsResetError", message);
                document.getElementById("analyticsResetCode").value = "";
                document.getElementById("analyticsResetCode").focus();
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
            resetState.busy = false;
            closeResetDialog();
            showMessage("analyticsResetDone", "Visitor data reset (" + (scope === "all" ? "all visitor data" : "finished visits") + "): deleted " +
                parts.join(", ") + ". The reset is recorded in the activity log.");
            loadAnalytics();
            loadDashboard();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("analyticsResetError", "Could not reach the server. Nothing was deleted; please try again.");
            }
        } finally {
            resetState.busy = false;
            updateResetSubmit();
        }
    }

    document.getElementById("analyticsResetBtn").addEventListener("click", function () {
        showMessage("analyticsResetDone", "");
        openResetDialog();
    });
    document.getElementById("analyticsResetForm").addEventListener("submit", submitReset);
    document.querySelectorAll("input[name='analyticsResetScope']").forEach(function (input) {
        input.addEventListener("change", renderResetImpact);
    });
    document.getElementById("analyticsResetConfirm").addEventListener("input", updateResetSubmit);
    document.getElementById("analyticsResetCode").addEventListener("input", function (event) {
        if (resetState.method === "authenticator") {
            event.target.value = event.target.value.replace(/\D/g, "").slice(0, 6);
        }
        updateResetSubmit();
    });
    document.getElementById("analyticsResetMethodBtn").addEventListener("click", function () {
        setResetMethod(resetState.method === "authenticator" ? "backup_code" : "authenticator");
        document.getElementById("analyticsResetCode").focus();
    });
    document.querySelectorAll("[data-reset-cancel]").forEach(function (button) {
        button.addEventListener("click", closeResetDialog);
    });
    resetDialog.addEventListener("cancel", function (event) {
        if (resetState.busy) {
            event.preventDefault();
        }
        document.getElementById("analyticsResetCode").value = "";
    });

    const routeOfficeFilter = document.getElementById("routeOfficeFilter");
    Object.keys(officeNames).forEach(function (code) {
        const option = document.createElement("option");
        option.value = code;
        option.textContent = officeNames[code];
        routeOfficeFilter.appendChild(option);
    });
    document.querySelectorAll("[data-open-campus]").forEach(function (link) {
        link.addEventListener("click", function (event) {
            event.preventDefault();
            switchView("campus");
        });
    });
    document.addEventListener("campus:updated", function () {
        // The analytics map is hidden while the campus page is open; re-apply the campus when it is shown.
        routeState.campusStale = true;
        // The campus boundary changes which points count, so reload the numbers too.
        if (analyticsState.loaded) {
            loadRouteAnalytics();
        }
    });
    routeOfficeFilter.addEventListener("change", function () {
        setRouteOffice(routeOfficeFilter.value);
    });
    document.getElementById("routeDestinationList").addEventListener("click", function (event) {
        const button = event.target.closest("[data-route-office]");
        if (button) {
            const office = button.getAttribute("data-route-office");
            // Clicking the selected destination again goes back to all destinations.
            setRouteOffice(office === routeState.office ? "" : office);
        }
    });

    document.querySelectorAll("[data-visitor-filter]").forEach(function (card) {
        card.addEventListener("click", function () {
            visitorState.status = card.getAttribute("data-visitor-filter") || "";
            document.getElementById("visitorStatusFilter").value = visitorState.status;
            renderVisitors();
            document.getElementById("recentVisitorsTitle").scrollIntoView({ behavior: "smooth", block: "start" });
        });
    });

    notificationBtn.addEventListener("click", function () {
        const willOpen = notificationPanel.hidden;
        notificationPanel.hidden = !willOpen;
        notificationBtn.setAttribute("aria-expanded", String(willOpen));
        if (willOpen) {
            profileMenu.hidden = true;
            profileBtn.setAttribute("aria-expanded", "false");
        }
    });

    document.getElementById("closeNotificationsBtn").addEventListener("click", function () {
        notificationPanel.hidden = true;
        notificationBtn.setAttribute("aria-expanded", "false");
    });

    document.getElementById("viewPendingBtn").addEventListener("click", function () {
        visitorState.status = "pending_approval";
        document.getElementById("visitorStatusFilter").value = "pending_approval";
        notificationPanel.hidden = true;
        notificationBtn.setAttribute("aria-expanded", "false");
        switchView("dashboard");
        renderVisitors();
        document.getElementById("recentVisitorsTitle").scrollIntoView({ behavior: "smooth", block: "start" });
    });

    document.getElementById("recentActivitiesBtn").addEventListener("click", function () {
        document.getElementById("recentActivitiesSection").scrollIntoView({ behavior: "smooth", block: "start" });
    });

    document.getElementById("visitorStatusFilter").addEventListener("change", function (event) {
        visitorState.status = event.target.value;
        renderVisitors();
    });

    document.getElementById("visitorSearch").addEventListener("input", function (event) {
        visitorState.query = event.target.value.trim();
        renderVisitors();
    });

    document.querySelectorAll("[data-role-filter]").forEach(function (button) {
        button.addEventListener("click", function () {
            document.querySelectorAll("[data-role-filter]").forEach(function (item) {
                item.classList.toggle("is-active", item === button);
            });
            userState.role = button.getAttribute("data-role-filter") || "";
            userState.page = 1;
            renderUsers();
        });
    });

    document.getElementById("userSearch").addEventListener("input", function (event) {
        userState.query = event.target.value.trim();
        userState.page = 1;
        renderUsers();
    });

    document.getElementById("sortUsersBtn").addEventListener("click", function (event) {
        userState.sortDirection *= -1;
        event.currentTarget.textContent = userState.sortDirection === 1 ? "Sort A–Z" : "Sort Z–A";
        renderUsers();
    });

    let usernameEdited = false;

    /** "Ma. Theresa" + "Dela Cruz" -> "mdelacruz" (a free variation if it is taken). */
    function suggestUsername() {
        if (usernameEdited) {
            return;
        }
        const clean = function (value) {
            return String(value || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().replace(/[^a-z0-9]/g, "");
        };
        const first = clean(document.getElementById("newFirstName").value);
        const last = clean(document.getElementById("newLastName").value);
        let base = (first.charAt(0) + last).slice(0, 30);
        if (base.length < 3) {
            base = (first + last).slice(0, 30);
        }
        if (base.length < 3) {
            document.getElementById("newUsername").value = "";
            return;
        }
        const taken = new Set(userState.users.map(function (user) { return String(user.username).toLowerCase(); }));
        let candidate = base;
        for (let n = 2; taken.has(candidate); n += 1) {
            candidate = base + n;
        }
        document.getElementById("newUsername").value = candidate;
    }

    function openAddUser(role, officeCode) {
        document.getElementById("addUserForm").reset();
        usernameEdited = false;
        if (role) {
            document.getElementById("newUserRole").value = role;
        }
        updateOfficeField();
        if (officeCode) {
            document.getElementById("newUserOfficeCode").value = officeCode;
        }
        showMessage("adminFormError", "");
        addUserDialog.showModal();
        window.setTimeout(function () { document.getElementById("newFirstName").focus(); }, 0);
    }

    document.getElementById("openAddUserBtn").addEventListener("click", function () {
        openAddUser("", "");
    });
    ["newFirstName", "newLastName"].forEach(function (id) {
        document.getElementById(id).addEventListener("input", suggestUsername);
    });
    document.getElementById("newUsername").addEventListener("input", function () {
        usernameEdited = this.value.trim() !== "";
    });
    document.getElementById("closeAddUserBtn").addEventListener("click", closeAddUserDialog);
    document.getElementById("cancelAddUserBtn").addEventListener("click", closeAddUserDialog);
    document.getElementById("newUserRole").addEventListener("change", updateOfficeField);
    addUserDialog.addEventListener("click", function (event) {
        if (event.target === addUserDialog) {
            closeAddUserDialog();
        }
    });

    document.getElementById("addUserForm").addEventListener("submit", async function (event) {
        event.preventDefault();
        showMessage("adminFormError", "");
        const addButton = document.getElementById("addUserBtn");
        const payload = {
            first_name: document.getElementById("newFirstName").value.trim(),
            last_name: document.getElementById("newLastName").value.trim(),
            position: document.getElementById("newPosition").value.trim(),
            contact_number: document.getElementById("newContactNumber").value.trim(),
            email: document.getElementById("newEmail").value.trim(),
            username: document.getElementById("newUsername").value.trim(),
            role: document.getElementById("newUserRole").value,
            office_code: document.getElementById("newUserOfficeCode").value,
        };
        if (!payload.first_name || !payload.last_name) {
            showMessage("adminFormError", "Enter the person's first and last name so every action can be traced to them.");
            return;
        }
        if (!payload.username) {
            showMessage("adminFormError", "Username is required.");
            return;
        }
        if (!/^[A-Za-z0-9][A-Za-z0-9._@-]{2,63}$/.test(payload.username)) {
            showMessage("adminFormError", "Username must be 3–64 characters: letters, numbers, dots, dashes, underscores, or @.");
            return;
        }
        if (payload.role === "offices" && !payload.office_code) {
            showMessage("adminFormError", "Select the department this person works in.");
            return;
        }
        addButton.disabled = true;
        try {
            const data = await Security.withStepUp(function () {
                return fetchJson("add_user.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify(payload),
                });
            });
            if (!data || !data.success) {
                if (!data || !data.cancelled) {
                    showMessage("adminFormError", (data && data.message) || "Could not add user.");
                }
                return;
            }
            closeAddUserDialog();
            showTemporaryPassword({
                eyebrow: "Account created",
                name: data.user.display_name,
                username: data.user.username,
                password: data.temporary_password,
                expires: data.expires_at,
                twoFactor: data.two_factor_required,
            });
            await loadUsers();
            loadDepartments();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                showMessage("adminFormError", "Could not reach the server. Please try again.");
            }
        } finally {
            addButton.disabled = false;
        }
    });

    usersBody.addEventListener("click", async function (event) {
        const button = event.target.closest("button[data-user-id]");
        if (!button || button.disabled) {
            return;
        }
        const user = userState.users.find(function (item) {
            return Number(item.id) === Number(button.dataset.userId);
        });
        const label = user ? (user.display_name || user.username) : "this user";
        if (button.dataset.action === "manage") {
            openManageUser(Number(button.dataset.userId));
            return;
        }
        if (button.dataset.action === "status") {
            const nextActive = Number(button.dataset.nextActive) === 1;
            const actionLabel = nextActive ? "activate" : "suspend";
            if (!window.confirm("Do you want to " + actionLabel + " " + label + "?")) {
                return;
            }
            button.disabled = true;
            try {
                const statusData = await fetchJson("toggle_user_status.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        id: Number(button.dataset.userId),
                        is_active: nextActive,
                    }),
                });
                if (!statusData || !statusData.success) {
                    window.alert((statusData && statusData.message) || "Could not update account status.");
                    button.disabled = false;
                    return;
                }
                await loadUsers();
            } catch (error) {
                if (error.message !== "Not authenticated") {
                    window.alert("Could not reach the server. Please try again.");
                    button.disabled = false;
                }
            }
            return;
        }
        if (!window.confirm("Remove " + label + "? This cannot be undone.")) {
            return;
        }
        button.disabled = true;
        try {
            const data = await Security.withStepUp(function () {
                return fetchJson("delete_user.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ id: Number(button.dataset.userId) }),
                });
            });
            if (!data || !data.success) {
                if (!data || !data.cancelled) {
                    window.alert((data && data.message) || "Could not remove user.");
                }
                button.disabled = false;
                return;
            }
            await loadUsers();
            loadSecurity();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                window.alert("Could not reach the server. Please try again.");
                button.disabled = false;
            }
        }
    });

    document.querySelectorAll("[data-attempt-filter]").forEach(function (button) {
        button.addEventListener("click", function () {
            document.querySelectorAll("[data-attempt-filter]").forEach(function (item) {
                item.classList.toggle("is-active", item === button);
            });
            securityState.filter = button.getAttribute("data-attempt-filter") || "all";
            securityState.attemptLimit = 20;
            loadSecurity();
        });
    });

    document.getElementById("attemptsMoreBtn").addEventListener("click", function () {
        securityState.attemptLimit += 20;
        renderSecurity();
    });

    document.getElementById("lockoutsBody").addEventListener("click", async function (event) {
        const button = event.target.closest("button[data-lock-id]");
        if (!button || button.disabled) {
            return;
        }
        button.disabled = true;
        try {
            const data = await fetchJson("admin_security.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ action: "unlock", lockout_id: Number(button.dataset.lockId) }),
            });
            if (!data || !data.success) {
                window.alert((data && data.message) || "Could not unlock.");
            }
            await loadSecurity();
            loadUsers();
        } catch (error) {
            if (error.message !== "Not authenticated") {
                window.alert("Could not reach the server. Please try again.");
            }
        } finally {
            button.disabled = false;
        }
    });

    document.getElementById("closeManageUserBtn").addEventListener("click", function () {
        manageUserDialog.close();
    });
    manageUserDialog.addEventListener("click", function (event) {
        if (event.target === manageUserDialog) {
            manageUserDialog.close();
        }
    });
    document.getElementById("copyTempPasswordBtn").addEventListener("click", function () {
        const button = this;
        UI.copyText(document.getElementById("tempPasswordValue").textContent).then(function (copied) {
            button.textContent = copied ? "Copied" : "Copy failed";
        });
    });
    tempPasswordDialog.addEventListener("close", function () {
        // Do not leave the one-time password in the page.
        text("tempPasswordValue", "");
    });

    /* ---------- Activity, department, and people bindings ---------- */

    // A click on a person's name or an appointment number anywhere opens its history.
    ["recentActivitiesList", "uaLog", "uaBoard", "personActivity", "departmentsGrid", "recentVisitorsBody"].forEach(function (id) {
        document.getElementById(id).addEventListener("click", function (event) {
            const person = event.target.closest("[data-person-id]");
            if (person) {
                openPersonDialog(Number(person.dataset.personId));
                return;
            }
            const appointment = event.target.closest("[data-appointment-id]");
            if (appointment) {
                openTrail(Number(appointment.dataset.appointmentId));
                return;
            }
            const departmentButton = event.target.closest("[data-dept-action]");
            if (departmentButton && id === "departmentsGrid") {
                handleDepartmentAction(departmentButton);
            }
        });
    });

    document.querySelectorAll("[data-feed-category]").forEach(function (button) {
        button.addEventListener("click", function () {
            document.querySelectorAll("[data-feed-category]").forEach(function (item) {
                item.classList.toggle("is-active", item === button);
            });
            feedState.category = button.getAttribute("data-feed-category") || "all";
            loadFeed();
        });
    });
    document.getElementById("feedDepartment").addEventListener("change", function (event) {
        feedState.department = event.target.value;
        loadFeed();
    });
    document.getElementById("openActivityLogBtn").addEventListener("click", function () {
        activityState.department = feedState.department;
        activityState.category = feedState.category;
        activityState.userId = "";
        document.getElementById("uaDepartment").value = feedState.department;
        document.querySelectorAll("[data-ua-category]").forEach(function (item) {
            item.classList.toggle("is-active", item.getAttribute("data-ua-category") === feedState.category);
        });
        switchView("security");
        loadSecurity();
        activityState.loaded = false;
        selectSecurityTab("activity");
    });

    document.getElementById("userDepartmentFilter").addEventListener("change", function (event) {
        userState.department = event.target.value;
        userState.page = 1;
        renderUsers();
    });

    document.getElementById("addDepartmentBtn").addEventListener("click", function () {
        showMessage("departmentsSuccess", "");
        openDepartmentDialog(null);
    });
    document.getElementById("departmentName").addEventListener("input", suggestDepartmentCode);
    document.getElementById("departmentCode").addEventListener("input", function (event) {
        const cleaned = event.target.value.toUpperCase().replace(/[^A-Z0-9_]/g, "");
        if (cleaned !== event.target.value) {
            event.target.value = cleaned;
        }
        departmentState.codeEdited = cleaned !== "";
    });
    document.getElementById("departmentForm").addEventListener("submit", saveDepartment);

    document.getElementById("manageEditForm").addEventListener("submit", saveEditDetails);
    document.getElementById("cancelEditDetailsBtn").addEventListener("click", function () {
        closeEditDetails();
        showMessage("manageUserError", "");
    });

    document.querySelectorAll("[data-close-dialog]").forEach(function (button) {
        button.addEventListener("click", function () {
            document.getElementById(button.getAttribute("data-close-dialog")).close();
        });
    });
    ["departmentDialog", "personDialog", "trailDialog"].forEach(function (id) {
        const dialog = document.getElementById(id);
        dialog.addEventListener("click", function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });
    document.getElementById("personManageBtn").addEventListener("click", function () {
        document.getElementById("personDialog").close();
        openManageUser(personDialogUserId);
    });
    document.getElementById("personFullLogBtn").addEventListener("click", function () {
        const user = findUser(personDialogUserId);
        const personId = String(personDialogUserId);
        document.getElementById("personDialog").close();
        activityState.department = user ? departmentKey(user) : "";
        activityState.userId = personId;
        document.getElementById("uaDepartment").value = activityState.department;
        fillPersonSelect();
        const personSelect = document.getElementById("uaPerson");
        if (personSelect.value !== personId) {
            personSelect.appendChild(new Option(user ? user.display_name : "Selected person", personId));
            personSelect.value = personId;
            activityState.userId = personId;
        }
        switchView("security");
        loadSecurity();
        activityState.loaded = true;
        selectSecurityTab("activity");
        loadUserActivity(true);
    });

    const securityTabs = Array.from(document.querySelectorAll("[data-sec-tab]"));
    securityTabs.forEach(function (button, index) {
        button.addEventListener("click", function () {
            selectSecurityTab(button.getAttribute("data-sec-tab"));
        });
        button.addEventListener("keydown", function (event) {
            if (event.key !== "ArrowRight" && event.key !== "ArrowLeft") {
                return;
            }
            const next = securityTabs[(index + (event.key === "ArrowRight" ? 1 : securityTabs.length - 1)) % securityTabs.length];
            next.focus();
            selectSecurityTab(next.getAttribute("data-sec-tab"));
        });
    });

    document.getElementById("uaPeriod").addEventListener("change", function (event) {
        activityState.period = event.target.value;
        const custom = activityState.period === "custom";
        document.querySelectorAll("[data-ua-custom]").forEach(function (field) { field.hidden = !custom; });
        if (custom) {
            const range = rangeForDays(7);
            document.getElementById("uaFrom").value = document.getElementById("uaFrom").value || range.from;
            document.getElementById("uaTo").value = document.getElementById("uaTo").value || range.to;
        }
        loadUserActivity(true);
    });
    ["uaFrom", "uaTo"].forEach(function (id) {
        document.getElementById(id).addEventListener("change", function () {
            if (document.getElementById("uaFrom").value && document.getElementById("uaTo").value) {
                loadUserActivity(true);
            }
        });
    });
    document.getElementById("uaDepartment").addEventListener("change", function (event) {
        activityState.department = event.target.value;
        fillPersonSelect();
        loadUserActivity(true);
    });
    document.getElementById("uaPerson").addEventListener("change", function (event) {
        activityState.userId = event.target.value;
        loadUserActivity(true);
    });
    document.getElementById("uaSearch").addEventListener("input", function (event) {
        window.clearTimeout(activityState.searchTimer);
        activityState.searchTimer = window.setTimeout(function () {
            activityState.q = event.target.value.trim();
            loadUserActivity(true);
        }, 350);
    });
    document.querySelectorAll("[data-ua-category]").forEach(function (button) {
        button.addEventListener("click", function () {
            document.querySelectorAll("[data-ua-category]").forEach(function (item) {
                item.classList.toggle("is-active", item === button);
            });
            activityState.category = button.getAttribute("data-ua-category") || "all";
            loadUserActivity(true);
        });
    });
    document.getElementById("uaMoreBtn").addEventListener("click", function () {
        loadUserActivity(false);
    });

    profileBtn.addEventListener("click", function () {
        const willOpen = profileMenu.hidden;
        profileMenu.hidden = !willOpen;
        profileBtn.setAttribute("aria-expanded", String(willOpen));
        if (willOpen) {
            notificationPanel.hidden = true;
            notificationBtn.setAttribute("aria-expanded", "false");
        }
    });
    document.addEventListener("click", function (event) {
        if (!profileMenu.hidden && !profileMenu.contains(event.target) && !profileBtn.contains(event.target)) {
            profileMenu.hidden = true;
            profileBtn.setAttribute("aria-expanded", "false");
        }
        if (!notificationPanel.hidden && !notificationPanel.contains(event.target) && !notificationBtn.contains(event.target)) {
            notificationPanel.hidden = true;
            notificationBtn.setAttribute("aria-expanded", "false");
        }
    });
    document.getElementById("logoutBtn").addEventListener("click", function () {
        PhoneTrackerAuth.logout().then(function () {
            window.location.href = "login.html";
        });
    });

    const displayName = PhoneTrackerAuth.getDisplayName() || PhoneTrackerAuth.getUsername() || "Administrator";
    text("profileName", displayName);
    text("profileUsername", PhoneTrackerAuth.getUsername() || "admin");
    text("dashboardDate", new Intl.DateTimeFormat(undefined, {
        weekday: "long",
        month: "long",
        day: "numeric",
        year: "numeric",
    }).format(new Date()));

    // Show the five original departments right away; loadDirectory() replaces them with the real list.
    directoryState.offices = Object.keys(officeNames).map(function (code) {
        return { code: code, name: officeNames[code], location: "", is_active: true };
    });
    fillDepartmentSelects();

    const hashViews = { "#users": "users", "#analytics": "analytics", "#campus": "campus", "#security": "security", "#activity": "security" };
    const initialHash = window.location.hash;
    const initialView = hashViews[initialHash] || "dashboard";
    switchView(initialView);
    updateOfficeField();
    loadDirectory();
    loadDashboard();
    loadUsers().then(function () {
        if (initialHash === "#activity") {
            selectSecurityTab("activity");
        }
    });
    loadDepartments();
    loadAnalytics();
    loadSecurity();
    window.setInterval(loadDashboard, 30000);
    window.setInterval(loadSecurity, 30000);
    window.setInterval(updateLockCountdowns, 1000);
    window.setInterval(function () {
        if (window.location.hash === "#analytics") {
            loadAnalytics();
        }
    }, 60000);
})();
