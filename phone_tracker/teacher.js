/*
 * The Teacher dashboard (TEACHER_VISITS.md). Built for a phone between classes: the status first,
 * then who needs an answer, then who is coming. The server only ever gives this page the signed-in
 * teacher's own visits, and never a visitor's phone number, email or location.
 */
(function () {
    "use strict";

    if (typeof PhoneTrackerAuth === "undefined" || !PhoneTrackerAuth.requireRole(["teacher"])) {
        return;
    }

    const POLL_MS = 15000;
    const DAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
    const WEEKDAYS_SHORT = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
    const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
    const MONTHS_LONG = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    const DAY_NAMES = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];

    const CHIPS = {
        pending: ["Needs your answer", "is-amber"],
        arrived: ["Arrived", "is-green"],
        here: ["Here now", "is-green"],
        campus: ["On campus", "is-blue"],
        expected: ["Expected", "is-grey"],
        accepted: ["Accepted", "is-blue"],
        proposed: ["Picking a time", "is-amber"],
        done: ["Done", "is-grey"],
        declined: ["Declined", "is-red"],
        cancelled: ["Cancelled", "is-red"],
        missed: ["Didn't come", "is-amber"],
        noanswer: ["No answer", "is-amber"],
    };

    const state = {
        view: viewFromHash(),
        data: null,
        lastDataText: "",
        filter: "upcoming",
        seenWalkIns: {},
        firstLoad: true,
        deadlines: {},
        toastTimer: 0,
        away: { pick: "", visits: [], from: "", to: "", busy: false },
        day: { number: 0, windows: [], copyTo: {}, conflicts: null },
    };

    const main = document.getElementById("teacherMain");
    const sheet = document.getElementById("teacherSheet");

    function viewFromHash() {
        const view = window.location.hash.replace("#", "");
        return ["today", "visits", "hours", "profile"].indexOf(view) !== -1 ? view : "today";
    }

    function esc(value) {
        return String(value == null ? "" : value).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
    }

    /* ---------- Times. Everything from the server is Philippine time, "Y-m-d H:i:s". ---------- */

    function parts(value) {
        const m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(value || ""));
        return m ? { y: +m[1], mo: +m[2], d: +m[3], h: +m[4], mi: +m[5] } : null;
    }

    function clock(h, mi) {
        return (h % 12 === 0 ? 12 : h % 12) + ":" + String(mi).padStart(2, "0") + " " + (h < 12 ? "AM" : "PM");
    }

    function timeOf(value) {
        const p = parts(value);
        return p ? clock(p.h, p.mi) : "";
    }

    function weekdayOf(p) {
        return new Date(Date.UTC(p.y, p.mo - 1, p.d)).getUTCDay();
    }

    function dateKey(value) {
        return String(value || "").slice(0, 10);
    }

    function todayKey() {
        return state.data ? dateKey(state.data.server_time) : "";
    }

    /** "Fri, Nov 6", or "Today, Nov 6". */
    function dayLabel(value) {
        const p = parts(value);
        if (!p) {
            return "";
        }
        const name = dateKey(value) === todayKey() ? "Today" : WEEKDAYS_SHORT[(weekdayOf(p) + 6) % 7];
        return name + ", " + MONTHS[p.mo - 1] + " " + p.d;
    }

    function whenLabel(value) {
        return dayLabel(value) + ", " + timeOf(value);
    }

    /** "9:00 to 11:30 AM", or "11:00 AM to 1:00 PM". */
    function rangeText(start, end) {
        const a = /^(\d{2}):(\d{2})/.exec(start);
        const b = /^(\d{2}):(\d{2})/.exec(end);
        if (!a || !b) {
            return "";
        }
        const startText = clock(+a[1], +a[2]);
        const endText = clock(+b[1], +b[2]);
        return startText.slice(-2) === endText.slice(-2) ? startText.slice(0, -3) + " to " + endText : startText + " to " + endText;
    }

    function slotText(start, end) {
        const a = parts(start);
        const b = parts(end);
        if (!a || !b) {
            return "";
        }
        const pad = function (n) { return String(n).padStart(2, "0"); };
        return rangeText(pad(a.h) + ":" + pad(a.mi), pad(b.h) + ":" + pad(b.mi));
    }

    function minutesOf(text) {
        const m = /^(\d{2}):(\d{2})/.exec(text || "");
        return m ? +m[1] * 60 + +m[2] : 0;
    }

    /* ---------- Talking to the server ---------- */

    async function getJson(url) {
        const response = await fetch(url, { credentials: "same-origin", cache: "no-store" });
        if (response.status === 401) {
            window.location.href = "login.html";
            throw new Error("Not authenticated");
        }
        return response.json();
    }

    async function postJson(url, body) {
        const response = await fetch(url, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(body),
        });
        if (response.status === 401) {
            window.location.href = "login.html";
            throw new Error("Not authenticated");
        }
        return response.json();
    }

    function toast(text) {
        const box = document.getElementById("teacherToast");
        box.textContent = text;
        box.hidden = false;
        window.clearTimeout(state.toastTimer);
        state.toastTimer = window.setTimeout(function () { box.hidden = true; }, 3600);
    }

    function pageError(text) {
        const box = document.getElementById("teacherPageError");
        box.textContent = text || "";
        box.hidden = !text;
    }

    /** Runs a change, shows what came back, and reloads. Returns the reply, or null when it failed. */
    async function change(url, body, button, successText) {
        if (button) {
            button.disabled = true;
        }
        try {
            const data = await postJson(url, body);
            if (!data || !data.success) {
                if (!data || data.code !== "schedule_conflicts") {
                    toast((data && data.message) || "That didn't save. Please try again.");
                }
                return data || null;
            }
            toast(successText || data.message || "Saved.");
            await load();
            return data;
        } catch (error) {
            if (error.message !== "Not authenticated") {
                toast("Could not reach the server. Please try again.");
            }
            return null;
        } finally {
            if (button) {
                button.disabled = false;
            }
        }
    }

    function action(id, name, reason, button, successText) {
        return change("office_appointment_action.php", { appointment_id: id, action: name, reason: reason || "" }, button, successText);
    }

    function hoursAction(body, button, successText) {
        return change("teacher_hours.php", body, button, successText);
    }

    /* ---------- Loading ---------- */

    async function load() {
        let data;
        try {
            data = await getJson("teacher_dashboard.php");
        } catch (error) {
            if (error.message !== "Not authenticated") {
                pageError("Could not reach the server. It will try again shortly.");
            }
            return;
        }
        if (!data || !data.success) {
            pageError((data && data.message) || "Could not load your dashboard.");
            return;
        }
        pageError("");
        const walkInIds = {};
        data.walk_ins.forEach(function (visit) {
            walkInIds[visit.id] = true;
            // The time left counts down here between reloads.
            state.deadlines[visit.id] = Date.now() + visit.seconds_left * 1000;
        });
        const arrivedNew = !state.firstLoad && data.walk_ins.some(function (visit) { return !state.seenWalkIns[visit.id]; });
        state.seenWalkIns = walkInIds;
        state.firstLoad = false;
        const comparable = JSON.stringify(Object.assign({}, data, {
            server_time: "",
            walk_ins: data.walk_ins.map(function (visit) { return visit.id; }),
            free_times: [],
        }));
        state.data = data;
        if (comparable !== state.lastDataText) {
            state.lastDataText = comparable;
            renderIfQuiet();
        }
        if (arrivedNew) {
            announceWalkIn(data.walk_ins[data.walk_ins.length - 1]);
        }
        updateCounts();
    }

    /** Doesn't redraw under someone typing in the room field. */
    function renderIfQuiet() {
        const active = document.activeElement;
        if (active && main.contains(active) && active.tagName === "INPUT" && active.type === "text") {
            return;
        }
        render();
    }

    function updateCounts() {
        const data = state.data;
        if (!data) {
            return;
        }
        const waiting = data.requests.length + data.walk_ins.length;
        const count = document.getElementById("teacherNavCount");
        count.hidden = waiting === 0;
        count.textContent = waiting;
        const bell = document.getElementById("teacherBellCount");
        bell.hidden = data.unread_notifications === 0;
        bell.textContent = data.unread_notifications > 99 ? "99+" : data.unread_notifications;
        document.title = (data.walk_ins.length ? "(" + data.walk_ins.length + ") Walk-in waiting: " : "") + "ISATU Teacher Dashboard";
        document.getElementById("teacherName").textContent = data.teacher.name;
        document.getElementById("teacherPlace").textContent = data.teacher.place;
        document.getElementById("teacherUsername").textContent = data.teacher.username;
        if (data.teacher.profile_image_url) {
            document.getElementById("teacherAvatar").src = data.teacher.profile_image_url;
        }
    }

    /** A short sound and a browser notification, when the browser allows them. */
    function announceWalkIn(visit) {
        try {
            const Context = window.AudioContext || window.webkitAudioContext;
            if (Context) {
                const audio = new Context();
                [0, 0.22].forEach(function (delay) {
                    const tone = audio.createOscillator();
                    const gain = audio.createGain();
                    tone.frequency.value = 880;
                    gain.gain.value = 0.08;
                    tone.connect(gain).connect(audio.destination);
                    tone.start(audio.currentTime + delay);
                    tone.stop(audio.currentTime + delay + 0.16);
                });
            }
        } catch (error) {
            // Browsers can refuse sound until the page has been touched; the card still shows.
        }
        if (visit && "Notification" in window && Notification.permission === "granted" && document.hidden) {
            try {
                new Notification(visit.visitor + " wants to see you", { body: "Answer within 10 minutes", icon: "assets/images/isatu-logo.png" });
            } catch (error) {
                // Android browsers only show these through the service worker, which handles the phone alert.
            }
        }
    }

    /* ---------- Today ---------- */

    function statusCard() {
        const data = state.data;
        const status = data.status;
        const teacher = data.teacher;
        if (!status.has_hours) {
            return '<section class="t-status is-setup"><div><p class="t-status-title">Welcome, ' + esc(teacher.short_name) + '</p>' +
                '<p class="t-status-sub">Visitors can\'t find you yet. Set the times you can see them and you\'ll appear in the visitor app.</p></div>' +
                '<button class="t-btn is-block" type="button" data-act="nav" data-view="hours">Set my hours</button></section>';
        }
        const later = (status.later_today || []).map(function (window) { return rangeText(window[0], window[1]); });
        if (status.state === "open") {
            const until = status.until ? "Until " + timeOf(status.until) : "Open";
            return '<section class="t-status is-open"><div><p class="t-status-title"><span class="t-dot"></span>Available now</p>' +
                '<p class="t-status-sub">' + esc(until) + (later.length ? ", then " + esc(later.join(", ")) : "") + "</p></div>" +
                '<button class="t-btn" type="button" data-act="open-away">I\'m not available</button></section>';
        }
        if (status.state === "paused" || status.state === "not_accepting") {
            return '<section class="t-status is-away"><div><p class="t-status-title"><span class="t-dot"></span>Not available</p>' +
                '<p class="t-status-sub">' + esc(awayUntilText(status.until)) + " Visitors can't book or ask for now.</p></div>" +
                '<button class="t-btn is-dark" type="button" data-act="resume">I\'m available again</button></section>';
        }
        const next = status.until ? "Next: " + whenLabel(status.until) : "No more hours this week";
        return '<section class="t-status is-closed"><div><p class="t-status-title"><span class="t-dot"></span>Outside your hours</p>' +
            '<p class="t-status-sub">' + esc(next) + "</p></div>" +
            '<button class="t-btn is-line" type="button" data-act="open-away">I\'m not available</button></section>';
    }

    function awayUntilText(until) {
        if (!until) {
            return "For now.";
        }
        if (dateKey(until) === todayKey()) {
            return /23:59/.test(until) ? "For the rest of today." : "Until " + timeOf(until) + ".";
        }
        return "Until " + dayLabel(until) + ".";
    }

    function secondsLeftText(id) {
        const left = Math.max(0, Math.round(((state.deadlines[id] || Date.now()) - Date.now()) / 1000));
        return Math.floor(left / 60) + ":" + String(left % 60).padStart(2, "0") + " left";
    }

    function walkInCards() {
        return state.data.walk_ins.map(function (visit) {
            return '<section class="t-walkin" aria-label="Walk-in waiting for your answer">' +
                '<div class="t-walkin-top"><span class="t-pulse"></span><span>Wants to see you now</span><span class="t-left" data-left="' + visit.id + '">' + esc(secondsLeftText(visit.id)) + "</span></div>" +
                "<h3>" + esc(visit.visitor) + studentTag(visit) + "</h3>" +
                (studentLine(visit) ? '<p class="t-student">' + esc(studentLine(visit)) + "</p>" : "") +
                '<p class="t-why">' + esc(visit.purpose) + ": " + esc(visit.subject) + "</p>" +
                (visit.details ? '<p class="t-more">' + esc(visit.details) + "</p>" : "") +
                '<p class="t-slot">If you accept: ' + esc(slotText(visit.start, visit.end)) + "</p>" +
                '<div class="t-actions"><button class="t-btn is-green" type="button" data-act="accept-walk" data-id="' + visit.id + '">Accept</button>' +
                '<button class="t-btn is-line" type="button" data-act="decline-walk" data-id="' + visit.id + '">Not now</button></div></section>';
        }).join("");
    }

    function requestCards() {
        const list = state.data.requests;
        if (!list.length) {
            return "";
        }
        return '<section class="t-block"><h2>Needs your answer <span class="t-count">' + list.length + "</span></h2>" +
            list.map(function (visit) {
                return '<article class="t-card">' +
                    '<p class="t-req-who">' + esc(visit.visitor) + studentTag(visit) + "</p>" +
                    (studentLine(visit) ? '<p class="t-student">' + esc(studentLine(visit)) + "</p>" : "") +
                    '<p class="t-req-when">' + esc(whenLabel(visit.start)) + "</p>" +
                    '<p class="t-req-why">' + esc(visit.purpose) + ": " + esc(visit.subject) + "</p>" +
                    (visit.details ? '<p class="t-more">' + esc(visit.details) + "</p>" : "") +
                    '<div class="t-actions is-three"><button class="t-btn is-green" type="button" data-act="accept" data-id="' + visit.id + '">Accept</button>' +
                    '<button class="t-btn is-redline" type="button" data-act="open-decline" data-id="' + visit.id + '">Decline</button>' +
                    '<button class="t-btn is-line" type="button" data-act="open-other" data-id="' + visit.id + '">Other time</button></div></article>';
            }).join("") + "</section>";
    }

    function studentTag(visit) {
        return visit.is_student ? ' <span class="t-tag">Student</span>' : "";
    }

    /** "IT 3-B, 2023-01845" for a student, or "" for a visitor. */
    function studentLine(visit) {
        if (!visit.is_student || !visit.student) {
            return "";
        }
        return [visit.student.label, visit.student.student_number].filter(Boolean).join(", ");
    }

    /** A student's visit has no gate, so it can be marked done once accepted. */
    function canMarkDone(visit) {
        return visit.state === "arrived" || visit.state === "campus" || visit.state === "here"
            || (visit.is_student && visit.state === "expected");
    }

    function acceptedText(visit) {
        if (!visit) {
            return "Accepted.";
        }
        return visit.is_student ? "Accepted. " + visit.visitor + " was told." : "Accepted. " + visit.visitor + " has a pass now.";
    }

    function chip(visitState) {
        const c = CHIPS[visitState] || ["", "is-grey"];
        return '<span class="t-chip ' + c[1] + '">' + esc(c[0]) + "</span>";
    }

    function visitRow(visit, withDone) {
        const canFinish = withDone && canMarkDone(visit);
        const small = [studentLine(visit), visit.subject].filter(Boolean).join(". ");
        return "<li>" +
            '<button class="t-rowbtn" type="button" data-act="open-visit" data-id="' + visit.id + '">' +
            '<span class="t-time">' + esc(timeOf(visit.start)) + "</span>" +
            '<span class="t-who"><strong>' + esc(visit.visitor) + studentTag(visit) + "</strong><small>" + esc(small) + "</small></span>" +
            chip(visit.state) + "</button>" +
            (canFinish ? '<div class="t-rowdone"><button class="t-btn is-green" type="button" data-act="done" data-id="' + visit.id + '">Mark as done</button></div>' : "") +
            "</li>";
    }

    function renderToday() {
        const data = state.data;
        const p = parts(data.server_time);
        const dateLine = p ? DAY_NAMES[weekdayOf(p)] + ", " + MONTHS_LONG[p.mo - 1] + " " + p.d : "";
        return '<div class="t-head"><h1>Today</h1><p>' + esc(dateLine) + "</p></div>" +
            '<div class="t-today"><div class="t-today-left">' + statusCard() + walkInCards() + requestCards() + "</div>" +
            '<div class="t-today-right"><section class="t-block"><h2>Today\'s visits</h2>' +
            (data.today.length
                ? '<ol class="t-list">' + data.today.map(function (visit) { return visitRow(visit, true); }).join("") + "</ol>"
                : '<p class="t-empty">No visits today.</p>') + "</section></div></div>";
    }

    /* ---------- Visits ---------- */

    function groupByDay(visits) {
        const groups = [];
        visits.forEach(function (visit) {
            const key = dateKey(visit.start);
            const last = groups[groups.length - 1];
            if (last && last.key === key) {
                last.items.push(visit);
            } else {
                groups.push({ key: key, label: dayLabel(visit.start), items: [visit] });
            }
        });
        return groups;
    }

    function renderVisits() {
        const data = state.data;
        const list = state.filter === "upcoming" ? data.today.concat(data.upcoming) : data.past;
        const groups = groupByDay(list);
        return '<div class="t-head"><h1>Visits</h1><p>Only your own visitors appear here.</p></div>' +
            '<div class="t-seg is-full" role="group" aria-label="Which visits">' +
            '<button type="button" data-act="filter" data-filter="upcoming" aria-pressed="' + (state.filter === "upcoming") + '">Upcoming</button>' +
            '<button type="button" data-act="filter" data-filter="past" aria-pressed="' + (state.filter === "past") + '">Past</button></div>' +
            (groups.length ? groups.map(function (group) {
                return '<section class="t-block"><h2>' + esc(group.label) + '</h2><ol class="t-list">' +
                    group.items.map(function (visit) { return visitRow(visit, false); }).join("") + "</ol></section>";
            }).join("") : '<section class="t-block"><p class="t-empty">' + (state.filter === "upcoming" ? "No visits coming up." : "No past visits yet.") + "</p></section>");
    }

    /* ---------- My hours ---------- */

    function timeOffLabel(off) {
        const a = parts(off.starts_at);
        const b = parts(off.ends_at);
        if (!a || !b) {
            return "";
        }
        if (dateKey(off.starts_at) === dateKey(off.ends_at)) {
            return MONTHS[a.mo - 1] + " " + a.d;
        }
        return MONTHS[a.mo - 1] + " " + a.d + " to " + (a.mo === b.mo ? "" : MONTHS[b.mo - 1] + " ") + b.d;
    }

    function renderHours() {
        const data = state.data;
        const settings = data.settings;
        const hasHours = data.status.has_hours;
        return '<div class="t-head"><h1>My hours</h1><p>' + (hasHours
            ? "Visitors can only book these times."
            : "Tap a day to add the times you can see visitors. You don't need to enter your classes.") + "</p></div>" +
            '<div class="t-settings"><div>' +
            '<ul class="t-week t-list">' + data.hours.map(function (day) {
                return '<li><button class="t-day" type="button" data-act="open-day" data-day="' + day.day_of_week + '">' +
                    '<span class="t-dayname">' + esc(day.name) + "</span>" +
                    '<span class="t-daytimes' + (day.windows.length ? "" : " is-off") + '">' +
                    (day.windows.length ? day.windows.map(function (w) { return esc(rangeText(w.start, w.end)); }).join("<br>") : "Not open") + "</span>" +
                    '<svg class="t-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></button></li>';
            }).join("") + "</ul>" +
            '<section class="t-block t-card"><p class="t-set-title">Each visit lasts</p><div class="t-seg is-full" role="group" aria-label="Visit length" style="margin-top:9px">' +
            [[15, "15 min"], [30, "30 min"], [60, "1 hour"]].map(function (option) {
                return '<button type="button" data-act="length" data-length="' + option[0] + '" aria-pressed="' + (settings.slot_duration_minutes === option[0]) + '">' + option[1] + "</button>";
            }).join("") + "</div></section>" +
            "</div><div>" +
            '<section class="t-block t-card"><p class="t-set-title">Booking requests</p>' +
            '<label class="t-choice' + (settings.auto_accept ? "" : " is-on") + '"><input type="radio" name="teacherAccept" data-act="accept-mode" value="ask"' + (settings.auto_accept ? "" : " checked") + "><span><strong>Ask me first</strong><small>You accept or decline each request.</small></span></label>" +
            '<label class="t-choice' + (settings.auto_accept ? " is-on" : "") + '"><input type="radio" name="teacherAccept" data-act="accept-mode" value="auto"' + (settings.auto_accept ? " checked" : "") + "><span><strong>Accept automatically</strong><small>Requests during your hours are accepted right away.</small></span></label></section>" +
            '<section class="t-block t-card"><label class="t-switchrow"><span><span class="t-set-title" style="display:block">Walk-ins</span><span class="t-set-help" style="display:block">Let visitors ask to see you while you\'re available. You accept or decline each one.</span></span>' +
            '<span class="t-switch"><input type="checkbox" data-act="walk-toggle" aria-label="Walk-ins"' + (settings.accept_walk_ins ? " checked" : "") + "><i></i></span></label></section>" +
            '<section class="t-block t-card"><p class="t-set-title">Time off</p><p class="t-set-help">Leave, seminars, holidays. Visitors can\'t book these days.</p>' +
            data.time_off.map(function (off) {
                return '<div class="t-off"><span>' + esc(timeOffLabel(off)) + '</span><button class="t-link" type="button" data-act="off-remove" data-id="' + off.id + '">Remove</button></div>';
            }).join("") +
            '<button class="t-btn is-line is-block" type="button" data-act="open-away" data-pick="dates" style="margin-top:10px">Add time off</button></section>' +
            "</div></div>";
    }

    /* ---------- Profile ---------- */

    function renderProfile() {
        const data = state.data;
        const teacher = data.teacher;
        return '<div class="t-head"><h1>Profile</h1><p>Your name and course are set by the admin.</p></div>' +
            '<div class="t-settings"><div>' +
            '<section class="t-card"><div class="t-formrow" style="margin-top:0"><span>Name</span><div class="t-locked">' + esc(teacher.name) + "</div></div>" +
            '<div class="t-formrow"><span>Department and course</span><div class="t-locked">' + esc(teacher.place) + "</div></div>" +
            '<label class="t-formrow"><span>Your room</span><input class="t-field" id="teacherRoom" type="text" data-act="room" value="' + esc(teacher.room) + '" maxlength="150" placeholder="e.g. Faculty Room, CCI Building, 2nd floor"><small>Visitors see this only after you accept them.</small></label></section>' +
            "</div><div>" +
            '<section class="t-block t-card"><label class="t-switchrow"><span><span class="t-set-title" style="display:block">Email alerts</span><span class="t-set-help" style="display:block">New requests go to ' + esc(teacher.email) + "</span></span>" +
            '<span class="t-switch"><input type="checkbox" data-act="email-toggle" aria-label="Email alerts"' + (data.settings.email_alerts ? " checked" : "") + "><i></i></span></label></section>" +
            '<section class="t-block t-card" id="teacherPhoneAlerts"><label class="t-switchrow"><span><span class="t-set-title" style="display:block">Phone alerts</span><span class="t-set-help" style="display:block" id="teacherPhoneHelp">Alerts on this phone even when this page is closed.</span></span>' +
            '<span class="t-switch"><input type="checkbox" id="teacherPhoneToggle" data-act="phone-toggle" aria-label="Phone alerts"><i></i></span></label></section>' +
            '<div class="t-block" style="display:grid;gap:8px"><button class="t-btn is-line is-block" type="button" data-act="security">Password and security</button>' +
            '<button class="t-btn is-redline is-block" type="button" data-act="signout">Sign out</button></div>' +
            "</div></div>";
    }

    function render() {
        if (!state.data) {
            return;
        }
        const views = { today: renderToday, visits: renderVisits, hours: renderHours, profile: renderProfile };
        main.innerHTML = (views[state.view] || renderToday)();
        document.querySelectorAll(".admin-nav-item").forEach(function (button) {
            const on = button.dataset.view === state.view;
            button.classList.toggle("is-active", on);
            if (on) {
                button.setAttribute("aria-current", "page");
            } else {
                button.removeAttribute("aria-current");
            }
        });
        if (state.view === "profile" && window.TeacherPush) {
            window.TeacherPush.refresh();
        }
    }

    /* ---------- Sheets ---------- */

    function openSheet(html) {
        sheet.innerHTML = html;
        if (!sheet.open) {
            sheet.showModal();
        }
        sheet.scrollTop = 0;
    }

    function closeSheet() {
        if (sheet.open) {
            sheet.close();
        }
    }

    function findVisit(id) {
        const data = state.data;
        const all = data.walk_ins.concat(data.requests, data.today, data.upcoming, data.past);
        return all.find(function (visit) { return visit.id === Number(id); }) || null;
    }

    function declineSheet(visit) {
        return '<div class="t-sheet-in" tabindex="-1" autofocus><span class="t-grab"></span><h2 id="sheetTitle">Decline ' + esc(visit.visitor) + "?</h2>" +
            '<p class="t-sheet-sub">' + esc(whenLabel(visit.start)) + "</p>" +
            '<span class="t-sheet-label">Message to the visitor</span><div class="t-chips">' +
            declineChoices().map(function (text) {
                return '<button class="t-chip-btn" type="button" data-act="decline-chip" data-text="' + esc(text) + '" aria-pressed="false">' + esc(text) + "</button>";
            }).join("") + "</div>" +
            '<textarea id="declineText" aria-label="Message to the visitor" style="margin-top:10px" maxlength="400" placeholder="Or write your own"></textarea>' +
            '<div class="t-sheet-actions"><button class="t-btn is-line" type="button" data-act="close">Keep it</button>' +
            '<button class="t-btn is-red" type="button" data-act="decline-save" data-id="' + visit.id + '">Decline request</button></div></div>';
    }

    function declineChoices() {
        return ["I'm not free at that time", "I'm on leave that day", "Please see your department's office"];
    }

    function otherSheet(visit) {
        const times = state.data.free_times;
        return '<div class="t-sheet-in" tabindex="-1" autofocus><span class="t-grab"></span><h2 id="sheetTitle">Suggest another time</h2>' +
            '<p class="t-sheet-sub">' + esc(visit.visitor) + " asked for " + esc(whenLabel(visit.start)) + "</p>" +
            (times.length
                ? '<span class="t-sheet-label">Pick up to 3 of your free times</span><div class="t-chips" id="otherTimes">' +
                    times.map(function (slot, index) {
                        return '<button class="t-chip-btn" type="button" data-act="other-chip" data-start="' + esc(slot.start) + '" data-end="' + esc(slot.end) + '" aria-pressed="' + (index < 2) + '">' + esc(whenLabel(slot.start)) + "</button>";
                    }).join("") + "</div>" +
                    '<p class="t-note">The visitor picks one, and it\'s accepted right away.</p>'
                : '<p class="t-note">You have no free times in the next few weeks. Add more hours first.</p>') +
            '<div class="t-sheet-actions"><button class="t-btn is-line" type="button" data-act="close">Cancel</button>' +
            '<button class="t-btn is-blue" type="button" data-act="other-save" data-id="' + visit.id + '"' + (times.length ? "" : " disabled") + ">Send times</button></div></div>";
    }

    function soFarText(visit) {
        const lines = [];
        if (visit.checked_in_at) {
            lines.push("Passed the gate " + timeOf(visit.checked_in_at) + ".");
        }
        if (visit.arrived_at) {
            lines.push((visit.is_student ? "Said they're here at " : "Arrived ") + timeOf(visit.arrived_at) + ".");
        }
        const other = {
            done: "The meeting is done.",
            declined: visit.reason ? "You said: " + visit.reason : "You declined this request.",
            cancelled: "This visit was cancelled.",
            missed: visit.is_student ? "The student didn't come." : "The visitor didn't come.",
            noanswer: "The request closed with no answer.",
            proposed: "The visitor is picking from the times you suggested.",
            expected: "You accepted this visit.",
            accepted: "You accepted this visit.",
            pending: "This request needs your answer.",
        }[visit.state];
        if (other) {
            lines.push(other);
        }
        return lines.join(" ");
    }

    function visitSheet(visit) {
        const actions = [];
        if (visit.state === "pending") {
            actions.push('<button class="t-btn is-redline" type="button" data-act="open-decline" data-id="' + visit.id + '">Decline</button>');
            actions.push('<button class="t-btn is-green" type="button" data-act="accept" data-id="' + visit.id + '">Accept</button>');
        } else if (visit.state === "arrived" || visit.state === "campus" || visit.state === "here") {
            actions.push('<button class="t-btn is-line" type="button" data-act="close">Close</button>');
            actions.push('<button class="t-btn is-green" type="button" data-act="done" data-id="' + visit.id + '">Mark as done</button>');
        } else if (visit.is_student && visit.state === "expected") {
            actions.push('<button class="t-btn is-redline" type="button" data-act="cancel-visit" data-id="' + visit.id + '">Cancel visit</button>');
            actions.push('<button class="t-btn is-green" type="button" data-act="done" data-id="' + visit.id + '">Mark as done</button>');
        } else if (visit.state === "expected" || visit.state === "accepted" || visit.state === "proposed") {
            actions.push('<button class="t-btn is-redline" type="button" data-act="cancel-visit" data-id="' + visit.id + '">Cancel visit</button>');
            actions.push(visit.state !== "proposed" && visit.visit_type !== "walk_in"
                ? '<button class="t-btn is-line" type="button" data-act="open-other" data-id="' + visit.id + '">Suggest other times</button>'
                : '<button class="t-btn is-line" type="button" data-act="close">Close</button>');
        } else {
            actions.push('<button class="t-btn is-line" type="button" data-act="close">Close</button>');
        }
        return '<div class="t-sheet-in" tabindex="-1" autofocus><span class="t-grab"></span>' +
            '<div class="t-sheet-top"><h2 id="sheetTitle">' + esc(visit.visitor) + "</h2>" + chip(visit.state) + "</div>" +
            '<dl class="t-facts">' +
            (studentLine(visit) ? "<div><dt>Student</dt><dd>" + esc(studentLine(visit)) + "</dd></div>" : "") +
            "<div><dt>When</dt><dd>" + esc(whenLabel(visit.start)) + "</dd></div>" +
            "<div><dt>Reason</dt><dd>" + esc(visit.purpose) + "</dd></div>" +
            "<div><dt>Concern</dt><dd>" + esc(visit.subject) + "</dd></div>" +
            (visit.details ? "<div><dt>Details</dt><dd>" + esc(visit.details) + "</dd></div>" : "") +
            "<div><dt>So far</dt><dd>" + esc(soFarText(visit)) + "</dd></div></dl>" +
            '<div class="t-sheet-actions' + (actions.length === 1 ? " is-one" : "") + '">' + actions.join("") + "</div></div>";
    }

    /* "I'm not available" and "Add time off" share one sheet. */
    function awaySheet() {
        const away = state.away;
        const options = [["30", "30 minutes"], ["60", "1 hour"], ["end_of_day", "Rest of today"], ["dates", "Pick dates"]];
        const ready = away.pick && (away.pick !== "dates" || (away.from && away.to));
        return '<div class="t-sheet-in" tabindex="-1" autofocus><span class="t-grab"></span>' +
            '<h2 id="sheetTitle">' + (away.pick === "dates" ? "Add time off" : "I'm not available") + "</h2>" +
            '<span class="t-sheet-label">How long?</span><div class="t-chips">' + options.map(function (option) {
                return '<button class="t-chip-btn" type="button" data-act="away-pick" data-pick="' + option[0] + '" aria-pressed="' + (away.pick === option[0]) + '">' + option[1] + "</button>";
            }).join("") + "</div>" +
            (away.pick === "dates"
                ? '<div class="t-dates"><label><span>First day</span><input type="date" id="awayFrom" data-act="away-date" min="' + esc(todayKey()) + '" value="' + esc(away.from) + '"></label>' +
                  '<label><span>Last day</span><input type="date" id="awayTo" data-act="away-date" min="' + esc(away.from || todayKey()) + '" value="' + esc(away.to) + '"></label></div>'
                : "") +
            (away.visits.length
                ? '<div class="t-clash"><strong>' + (away.visits.length === 1 ? "1 visit falls" : away.visits.length + " visits fall") + " in that time. Cancel and tell them?</strong>" +
                    away.visits.map(function (visit) {
                        return '<label><input type="checkbox" checked data-clash="' + visit.id + '"> ' + esc(visit.visitor) + ", " + esc(whenLabel(visit.starts_at)) + "</label>";
                    }).join("") + "<small>Untick a visit to keep it.</small></div>"
                : "") +
            '<p class="t-note">Visitors only see that you\'re not available, not why.</p>' +
            '<div class="t-sheet-actions"><button class="t-btn is-line" type="button" data-act="close">Cancel</button>' +
            '<button class="t-btn is-blue" type="button" data-act="away-save"' + (ready && !away.busy ? "" : " disabled") + ">" + (away.pick === "dates" ? "Add time off" : "Set as not available") + "</button></div></div>";
    }

    async function refreshAwayPreview() {
        const away = state.away;
        away.visits = [];
        if (!away.pick || (away.pick === "dates" && !(away.from && away.to))) {
            openSheet(awaySheet());
            return;
        }
        away.busy = true;
        openSheet(awaySheet());
        try {
            const body = away.pick === "dates"
                ? { action: "preview_away", from: away.from, to: away.to }
                : { action: "preview_away", length: away.pick };
            const data = await postJson("teacher_hours.php", body);
            away.visits = data && data.success ? data.visits : [];
        } catch (error) {
            away.visits = [];
        }
        away.busy = false;
        if (sheet.open) {
            openSheet(awaySheet());
        }
    }

    /* Editing one day */
    function daySheet() {
        const day = state.day;
        const name = DAYS[day.number - 1];
        const open = day.windows.length > 0;
        const length = state.data.settings.slot_duration_minutes;
        let visits = 0;
        day.windows.forEach(function (window) {
            visits += Math.max(0, Math.floor((minutesOf(window.end) - minutesOf(window.start)) / length));
        });
        return '<div class="t-sheet-in" tabindex="-1" autofocus><span class="t-grab"></span>' +
            '<label class="t-switchrow"><span><span id="sheetTitle" class="t-sheet-name">' + esc(name) + "</span>" +
            '<span class="t-set-help" style="display:block">' + (open ? visits + (visits === 1 ? " visit of " : " visits of ") + (length === 60 ? "1 hour" : length + " minutes") : "Not open for visitors") + "</span></span>" +
            '<span class="t-switch"><input type="checkbox" data-act="day-open" aria-label="Open for visitors on ' + esc(name) + '"' + (open ? " checked" : "") + "><i></i></span></label>" +
            day.windows.map(function (window, index) {
                return '<div class="t-window"><input type="time" value="' + esc(window.start) + '" data-act="day-time" data-i="' + index + '" data-end="0" aria-label="From"><span>to</span>' +
                    '<input type="time" value="' + esc(window.end) + '" data-act="day-time" data-i="' + index + '" data-end="1" aria-label="Until">' +
                    '<button class="t-x" type="button" data-act="day-remove" data-i="' + index + '" aria-label="Remove this time">×</button></div>';
            }).join("") +
            (open ? '<button class="t-link" type="button" data-act="day-add" style="display:block;margin-top:12px">+ Add another time</button>' : "") +
            (open ? '<span class="t-sheet-label">Same times on</span><div class="t-chips">' + DAYS.map(function (other, index) {
                if (index + 1 === day.number) {
                    return "";
                }
                return '<button class="t-chip-btn" type="button" data-act="day-copy" data-day="' + (index + 1) + '" aria-pressed="' + Boolean(day.copyTo[index + 1]) + '">' + WEEKDAYS_SHORT[index] + "</button>";
            }).join("") + "</div>" : "") +
            (day.conflicts
                ? '<div class="t-clash"><strong>' + esc(day.conflicts.message) + "</strong>" +
                    day.conflicts.list.map(function (visit) { return "<small>" + esc(visit.visitor) + ", " + esc(whenLabel(visit.starts_at)) + "</small>"; }).join("") +
                    "<small>Saving keeps these visits. You can cancel them under Visits.</small></div>"
                : "") +
            '<div class="t-sheet-actions"><button class="t-btn is-line" type="button" data-act="close">Cancel</button>' +
            '<button class="t-btn is-blue" type="button" data-act="day-save">' + (day.conflicts ? "Save anyway" : "Save " + esc(name)) + "</button></div></div>";
    }

    function bellSheet() {
        const list = state.data.notifications;
        return '<div class="t-sheet-in" tabindex="-1" autofocus><span class="t-grab"></span><h2 id="sheetTitle">Notifications</h2>' +
            (list.length
                ? '<ul class="t-bell">' + list.map(function (item) {
                    return '<li class="' + (item.read ? "" : "is-new") + '"><strong>' + esc(item.title) + "</strong><span>" + esc(item.message) + "</span><small>" + esc(whenLabel(item.created_at)) + "</small></li>";
                }).join("") + "</ul>"
                : '<p class="t-empty" style="margin-top:12px">No notifications yet.</p>') +
            '<div class="t-sheet-actions is-one"><button class="t-btn is-line" type="button" data-act="close">Close</button></div></div>';
    }

    /* ---------- Clicks ---------- */

    function setView(view) {
        state.view = view;
        window.history.replaceState(null, "", "#" + view);
        document.getElementById("officeProfileMenu").hidden = true;
        document.getElementById("officeProfileBtn").setAttribute("aria-expanded", "false");
        closeSheet();
        render();
        window.scrollTo(0, 0);
    }

    document.addEventListener("click", function (event) {
        const menu = document.getElementById("officeProfileMenu");
        const profile = event.target.closest("#officeProfileBtn");
        if (profile) {
            menu.hidden = !menu.hidden;
            profile.setAttribute("aria-expanded", String(!menu.hidden));
            return;
        }
        if (!menu.hidden && !event.target.closest("#officeProfileMenu")) {
            menu.hidden = true;
            document.getElementById("officeProfileBtn").setAttribute("aria-expanded", "false");
        }
        if (event.target === sheet) {
            closeSheet();
            return;
        }
        const target = event.target.closest("[data-act]");
        if (!target || target.tagName === "INPUT") {
            return;
        }
        const act = target.dataset.act;
        const id = target.dataset.id ? Number(target.dataset.id) : 0;
        const handler = CLICKS[act];
        if (handler) {
            event.preventDefault();
            handler(target, id);
        }
    });

    const CLICKS = {
        nav: function (target) { setView(target.dataset.view); },
        close: closeSheet,
        filter: function (target) {
            state.filter = target.dataset.filter;
            render();
        },
        bell: function () {
            if (!state.data) {
                return;
            }
            openSheet(bellSheet());
            if (state.data.unread_notifications > 0) {
                postJson("office_notifications.php", { action: "mark_all_read" }).then(load).catch(function () {});
            }
        },
        security: function () {
            if (window.PhoneTrackerSecurity) {
                window.PhoneTrackerSecurity.open();
            }
        },
        signout: function () {
            PhoneTrackerAuth.logout().then(function () { window.location.href = "login.html"; });
        },

        accept: async function (target, id) {
            const visit = findVisit(id);
            closeSheet();
            await action(id, "approve", "", target, acceptedText(visit));
        },
        "accept-walk": async function (target, id) {
            const visit = findVisit(id);
            await action(id, "approve", "", target, acceptedText(visit));
        },
        "decline-walk": async function (target, id) {
            const visit = findVisit(id);
            await action(id, "reject", "I can't see you right now.", target, visit ? visit.visitor + " was told, and offered your free times." : "Sent.");
        },
        "open-decline": function (target, id) {
            const visit = findVisit(id);
            if (visit) {
                openSheet(declineSheet(visit));
            }
        },
        "decline-chip": function (target) {
            sheet.querySelectorAll('[data-act="decline-chip"]').forEach(function (button) {
                button.setAttribute("aria-pressed", String(button === target));
            });
            document.getElementById("declineText").value = target.dataset.text;
        },
        "decline-save": async function (target, id) {
            const reason = document.getElementById("declineText").value.trim();
            if (reason.length < 5) {
                toast("Choose a message, or write one.");
                return;
            }
            const visit = findVisit(id);
            const data = await action(id, "reject", reason, target, "Declined. " + (visit ? visit.visitor : "The visitor") + " was told.");
            if (data && data.success) {
                closeSheet();
            }
        },
        "open-other": function (target, id) {
            const visit = findVisit(id);
            if (visit) {
                openSheet(otherSheet(visit));
            }
        },
        "other-chip": function (target) {
            const on = target.getAttribute("aria-pressed") === "true";
            const count = sheet.querySelectorAll('[data-act="other-chip"][aria-pressed="true"]').length;
            if (!on && count >= 3) {
                toast("You can offer up to 3 times.");
                return;
            }
            target.setAttribute("aria-pressed", String(!on));
        },
        "other-save": async function (target, id) {
            const slots = Array.from(sheet.querySelectorAll('[data-act="other-chip"][aria-pressed="true"]')).map(function (button) {
                return { start: button.dataset.start, end: button.dataset.end };
            });
            if (!slots.length) {
                toast("Pick at least one time.");
                return;
            }
            const visit = findVisit(id);
            const data = await change("office_propose_reschedule.php", { appointment_id: id, reason: "That time doesn't work for me.", slots: slots }, target, "Sent. " + (visit ? visit.visitor : "The visitor") + " will pick one.");
            if (data && data.success) {
                closeSheet();
            }
        },
        "open-visit": function (target, id) {
            const visit = findVisit(id);
            if (visit) {
                openSheet(visitSheet(visit));
            }
        },
        done: async function (target, id) {
            const visit = findVisit(id);
            closeSheet();
            await action(id, "done", "", target, (visit ? visit.visitor + "'s" : "The") + " visit is done.");
        },
        "cancel-visit": async function (target, id) {
            const visit = findVisit(id);
            if (!visit || !window.confirm("Cancel " + visit.visitor + "'s visit on " + whenLabel(visit.start) + "? They'll be told" + (visit.is_student ? "." : ", and their pass stops working."))) {
                return;
            }
            const data = await action(id, "cancel", "", target, "Cancelled. " + visit.visitor + " was told.");
            if (data && data.success) {
                closeSheet();
            }
        },

        "open-away": function (target) {
            state.away = { pick: target.dataset.pick || "", visits: [], from: "", to: "", busy: false };
            openSheet(awaySheet());
        },
        "away-pick": function (target) {
            state.away.pick = target.dataset.pick;
            refreshAwayPreview();
        },
        "away-save": async function (target) {
            const away = state.away;
            const cancelIds = Array.from(sheet.querySelectorAll("[data-clash]")).filter(function (box) { return box.checked; }).map(function (box) { return Number(box.dataset.clash); });
            const body = away.pick === "dates"
                ? { action: "time_off_add", from: away.from, to: away.to, cancel_ids: cancelIds }
                : { action: "pause", length: away.pick, cancel_ids: cancelIds };
            const data = await hoursAction(body, target);
            if (data && data.success) {
                closeSheet();
            }
        },
        resume: function (target) { hoursAction({ action: "resume" }, target); },
        "off-remove": function (target, id) { hoursAction({ action: "time_off_remove", id: id }, target); },
        length: function (target) { hoursAction({ action: "save_settings", slot_duration_minutes: Number(target.dataset.length) }, target); },

        "open-day": function (target) {
            const number = Number(target.dataset.day);
            const saved = state.data.hours.find(function (day) { return day.day_of_week === number; });
            state.day = {
                number: number,
                windows: (saved ? saved.windows : []).map(function (window) { return { start: window.start, end: window.end }; }),
                copyTo: {},
                conflicts: null,
            };
            openSheet(daySheet());
        },
        "day-add": function () {
            const windows = state.day.windows;
            const last = windows[windows.length - 1];
            const start = last ? Math.min(minutesOf(last.end) + 60, 22 * 60) : 9 * 60;
            const pad = function (n) { return String(n).padStart(2, "0"); };
            const text = function (m) { return pad(Math.floor(m / 60)) + ":" + pad(m % 60); };
            windows.push({ start: text(start), end: text(Math.min(start + 120, 23 * 60)) });
            state.day.conflicts = null;
            openSheet(daySheet());
        },
        "day-remove": function (target) {
            state.day.windows.splice(Number(target.dataset.i), 1);
            state.day.conflicts = null;
            openSheet(daySheet());
        },
        "day-copy": function (target) {
            const number = Number(target.dataset.day);
            state.day.copyTo[number] = !state.day.copyTo[number];
            target.setAttribute("aria-pressed", String(state.day.copyTo[number]));
            state.day.conflicts = null;
        },
        "day-save": async function (target) {
            const day = state.day;
            const wasFirst = !state.data.status.has_hours;
            const body = {
                action: "save_day",
                day_of_week: day.number,
                windows: day.windows,
                copy_to: Object.keys(day.copyTo).filter(function (key) { return day.copyTo[key]; }).map(Number),
                confirm_conflicts: Boolean(day.conflicts),
            };
            const data = await hoursAction(body, target, wasFirst && day.windows.length ? "Saved. Visitors can find you now." : undefined);
            if (data && data.code === "schedule_conflicts") {
                day.conflicts = { message: data.message, list: (data.conflicts || []).map(function (visit) { return { visitor: visit.visitor, starts_at: visit.starts_at }; }) };
                openSheet(daySheet());
            } else if (data && data.success) {
                closeSheet();
            }
        },
    };

    /* ---------- Changes in fields ---------- */

    document.addEventListener("change", function (event) {
        const target = event.target;
        const act = target.dataset ? target.dataset.act : "";
        if (act === "accept-mode") {
            hoursAction({ action: "save_settings", auto_accept: target.value === "auto" }, null,
                target.value === "auto" ? "Requests during your hours are now accepted automatically." : "You'll be asked about each request.");
        } else if (act === "walk-toggle") {
            hoursAction({ action: "save_settings", accept_walk_ins: target.checked }, null,
                target.checked ? "Visitors can ask to see you." : "Walk-ins are off. Visitors can only book a time.");
        } else if (act === "email-toggle") {
            hoursAction({ action: "save_settings", email_alerts: target.checked });
        } else if (act === "phone-toggle") {
            if (window.TeacherPush) {
                window.TeacherPush.toggle(target);
            }
        } else if (act === "room") {
            hoursAction({ action: "save_settings", room: target.value.trim() });
        } else if (act === "day-open") {
            state.day.windows = target.checked ? [{ start: "09:00", end: "11:00" }] : [];
            state.day.conflicts = null;
            openSheet(daySheet());
        } else if (act === "day-time") {
            state.day.windows[Number(target.dataset.i)][target.dataset.end === "1" ? "end" : "start"] = target.value;
            state.day.conflicts = null;
            openSheet(daySheet());
        } else if (act === "away-date") {
            if (target.id === "awayFrom") {
                state.away.from = target.value;
                if (!state.away.to || state.away.to < target.value) {
                    state.away.to = target.value;
                }
            } else {
                state.away.to = target.value;
            }
            refreshAwayPreview();
        }
    });

    window.addEventListener("hashchange", function () {
        state.view = viewFromHash();
        render();
    });

    document.getElementById("officeLogoutBtn").addEventListener("click", function () {
        PhoneTrackerAuth.logout().then(function () { window.location.href = "login.html"; });
    });

    /* The time left on a walk-in counts down every second. */
    window.setInterval(function () {
        main.querySelectorAll("[data-left]").forEach(function (label) {
            label.textContent = secondsLeftText(label.dataset.left);
        });
    }, 1000);

    document.addEventListener("visibilitychange", function () {
        if (!document.hidden) {
            load();
        }
    });
    window.setInterval(function () {
        if (!document.hidden) {
            load();
        }
    }, POLL_MS);

    /* ---------- Phone alerts: the browser's own notifications, with no app to install ---------- */

    function base64UrlToBytes(text) {
        const padded = text + "=".repeat((4 - text.length % 4) % 4);
        const raw = window.atob(padded.replace(/-/g, "+").replace(/_/g, "/"));
        return Uint8Array.from(raw, function (c) { return c.charCodeAt(0); });
    }

    function pushSupported() {
        return "serviceWorker" in navigator && "PushManager" in window && "Notification" in window;
    }

    async function pushRegistration() {
        return navigator.serviceWorker.register("teacher-sw.js", { scope: "./teacher" });
    }

    window.TeacherPush = {
        /** Shows whether this phone has alerts on. */
        refresh: async function () {
            const toggle = document.getElementById("teacherPhoneToggle");
            const help = document.getElementById("teacherPhoneHelp");
            if (!toggle) {
                return;
            }
            if (!window.isSecureContext) {
                toggle.disabled = true;
                help.textContent = "Phone alerts only work on the site's secure (https) address.";
                return;
            }
            if (!pushSupported()) {
                toggle.disabled = true;
                help.textContent = "This browser can't show alerts. On an iPhone, add this page to your Home Screen first.";
                return;
            }
            if (Notification.permission === "denied") {
                toggle.disabled = true;
                help.textContent = "Alerts are blocked for this site. You can allow them in your browser's settings.";
                return;
            }
            try {
                const registration = await navigator.serviceWorker.getRegistration("./teacher");
                const subscription = registration ? await registration.pushManager.getSubscription() : null;
                toggle.checked = Boolean(subscription);
            } catch (error) {
                toggle.checked = false;
            }
        },
        toggle: async function (toggle) {
            toggle.disabled = true;
            try {
                if (toggle.checked) {
                    const permission = await Notification.requestPermission();
                    if (permission !== "granted") {
                        toggle.checked = false;
                        toast("Alerts weren't allowed, so they stay off.");
                        return;
                    }
                    const key = await getJson("teacher_push.php");
                    if (!key || !key.success) {
                        throw new Error((key && key.message) || "no key");
                    }
                    const registration = await pushRegistration();
                    await navigator.serviceWorker.ready;
                    const subscription = await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: base64UrlToBytes(key.public_key),
                    });
                    const saved = await postJson("teacher_push.php", { action: "subscribe", endpoint: subscription.endpoint });
                    if (!saved || !saved.success) {
                        await subscription.unsubscribe();
                        throw new Error((saved && saved.message) || "not saved");
                    }
                    toast("Phone alerts are on.");
                } else {
                    const registration = await navigator.serviceWorker.getRegistration("./teacher");
                    const subscription = registration ? await registration.pushManager.getSubscription() : null;
                    if (subscription) {
                        await postJson("teacher_push.php", { action: "unsubscribe", endpoint: subscription.endpoint });
                        await subscription.unsubscribe();
                    }
                    toast("Phone alerts are off.");
                }
            } catch (error) {
                toggle.checked = false;
                toast("Phone alerts couldn't be turned on. Check your connection and try again.");
            } finally {
                toggle.disabled = false;
            }
        },
    };

    load();
})();
