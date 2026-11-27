/*
 * The ID check before a check-in (GROUP_VISITS.md): whose pass it is, then one row per person on
 * it. The guard opens a person, compares their name with their ID and taps the kind of ID; the
 * next person opens by itself. Children are named but need no ID. The guard can fix the list
 * (Didn't come, Edit name, Add someone who came) and turn someone away (Name doesn't match).
 *
 * Used by the Scan page (security_scan.js) and Security Home (dashboard.js: Check in without QR,
 * and someone who came after their group). Needs dialogs.js for its confirmations.
 *
 *   const card = IdCheck.render(host, {
 *       name, sub, facts: [[label, value], ...], people: [person payloads from the server],
 *       idTypes, maxPeople, canAdd, listLabel, holderRole ("holder" | "joining"), holderGroupName,
 *       onCheckIn(answer) → Promise, onRefuse() → Promise   // the pass holder's name didn't match
 *   });
 *   card.setBusy(true | false); card.showError(message);
 *
 * answer = {holder: {id_type, no_id_reason}, people: [{id, result, id_type, no_id_reason, full_name}
 *           | {full_name, is_adult, result: "in", id_type, no_id_reason}]}   (scan_appointment.php)
 */
(function () {
    "use strict";

    const NO_ID = "none";
    const SHORT = { "PhilSys national ID": "PhilSys ID" };
    const ICON = {
        people: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20"/><circle cx="9.5" cy="7.5" r="3.5"/><path d="M17 11a3 3 0 1 0 0-6M21 20v-1.5a4 4 0 0 0-3-3.9"/></svg>',
        id: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M6 16c.6-1.4 1.7-2 3-2s2.4.6 3 2M15 10h3M15 13h3"/></svg>',
        check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>',
        cross: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17"/></svg>',
        dash: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 12h10"/></svg>',
        plus: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
        copy: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>',
        chevron: '<svg class="gp-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
    };
    const IN_STATES = ["id", "noid", "here"];
    let cardCount = 0;

    function esc(text) {
        return String(text == null ? "" : text).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
    }

    function firstName(name) {
        return String(name || "").trim().split(/\s+/)[0] || String(name || "");
    }

    /** "Juan", "Juan and Elena", "Juan, Elena and Miguel" */
    function listNames(names) {
        if (names.length <= 1) {
            return names.join("");
        }
        return names.slice(0, -1).join(", ") + " and " + names[names.length - 1];
    }

    function shortId(type) {
        return SHORT[type] || type;
    }

    function isIn(person) {
        return IN_STATES.indexOf(person.state) !== -1;
    }

    function statusText(person) {
        switch (person.state) {
            case "id": return shortId(person.idType);
            case "noid": return "No ID shown";
            case "here": return "No ID needed";
            case "absent": return "Didn't come";
            case "refused": return "Not let in: name didn't match";
            default: return "Check the ID";
        }
    }

    function markHtml(person, index) {
        if (isIn(person)) {
            return ICON.check;
        }
        if (person.state === "absent") {
            return ICON.dash;
        }
        if (person.state === "refused") {
            return ICON.cross;
        }
        return "<b>" + (index + 1) + "</b>";
    }

    function render(host, options) {
        cardCount += 1;
        const uid = cardCount;
        const idTypes = options.idTypes && options.idTypes.length ? options.idTypes : [
            "PhilSys national ID", "Driver's license", "Passport", "School ID", "Company ID", "Other government ID",
        ];
        const maxPeople = Number(options.maxPeople) || 10;
        const canAdd = options.canAdd !== false;
        const holderRole = options.holderRole || "holder";
        const holderGroup = firstName(options.holderGroupName || options.name);
        // Someone joining a group later may be under 18 (options.holderAdult false): no ID needed.
        const holderAdult = options.holderAdult !== false;
        const people = [{ id: null, name: options.name, adult: holderAdult, role: holderRole, state: holderAdult ? "" : "here", idType: "", note: "", fixed: false }]
            .concat((options.people || []).filter(function (person) {
                return person.gate_status === "expected" || person.gate_status === undefined;
            }).map(function (person) {
                const adult = person.is_adult !== false;
                return { id: person.id, name: person.full_name, booked: person.full_name, adult: adult, role: "booked", state: adult ? "" : "here", idType: "", note: "", fixed: false };
            }));
        const st = { open: 0, adding: false, addName: "", addAdult: true, renaming: -1, lastType: "", busy: false, error: "" };
        let timer = 0;

        const others = people.length - 1;
        let sub = "";
        if (options.sub) {
            sub = '<p class="idc-with">' + ICON.people + esc(options.sub) + "</p>";
        } else if (others > 0) {
            sub = '<p class="idc-with">' + ICON.people + "and " + others + (others === 1 ? " person" : " people") + " with them</p>";
        }
        host.innerHTML = '<div class="idc">' +
            '<div><p class="idc-name">' + esc(options.name) + "</p>" + sub + "</div>" +
            (options.facts && options.facts.length ? '<dl class="idc-facts">' + options.facts.map(function (fact) {
                return "<div><dt>" + esc(fact[0]) + "</dt><dd>" + esc(fact[1]) + "</dd></div>";
            }).join("") + "</dl>" : "") +
            '<div class="gp" data-gp></div>' +
            '<div class="idc-actions">' +
            '<button class="security-checkout-button idc-checkin" type="button" data-idc-checkin disabled>' + ICON.check + "<span>Check in</span></button>" +
            '<p class="idc-hint" data-idc-hint role="status"></p>' +
            "</div></div>";
        const box = host.querySelector("[data-gp]");
        const button = host.querySelector("[data-idc-checkin]");
        const label = button.querySelector("span");
        const hint = host.querySelector("[data-idc-hint]");

        function pending() {
            return people.filter(function (person) { return person.adult && person.state === ""; });
        }

        function nextPending(after) {
            for (let step = 1; step <= people.length; step += 1) {
                const index = (after + step) % people.length;
                if (people[index].adult && people[index].state === "") {
                    return index;
                }
            }
            return -1;
        }

        function roleText(person) {
            if (person.role === "holder") {
                return "Pass holder";
            }
            if (person.role === "joining") {
                return "Joining " + holderGroup + "'s group";
            }
            let text = person.adult ? "18 or older" : "Under 18";
            if (person.role === "gate") {
                text += " · added at the gate";
            }
            if (person.fixed) {
                text += " · name fixed";
            }
            return text;
        }

        function chipsHtml(person, index) {
            const group = "idc" + uid + "p" + index;
            return idTypes.map(function (type) {
                const on = person.state === "id" && person.idType === type;
                return '<label class="idc-chip"><input type="radio" name="' + group + '" value="' + esc(type) + '" data-gp-id="' + index + '"' + (on ? " checked" : "") + "><span>" + esc(shortId(type)) + "</span></label>";
            }).join("") +
                '<label class="idc-chip is-none"><input type="radio" name="' + group + '" value="' + NO_ID + '" data-gp-id="' + index + '"' + (person.state === "noid" ? " checked" : "") + "><span>No ID</span></label>";
        }

        function act(name, index, text, red) {
            return '<button type="button" data-gp-act="' + name + '" data-i="' + index + '"' + (red ? ' class="is-red"' : "") + ">" + esc(text) + "</button>";
        }

        function actsHtml(person, index) {
            if (person.role === "holder") {
                return act("refuse", index, "Name doesn't match", true);
            }
            const out = [];
            if (person.role === "gate") {
                out.push(act("remove", index, "Remove"));
            } else if (person.role !== "joining") {
                if (person.state === "absent") {
                    if (!person.adult) {
                        out.push(act("here", index, "Here after all"));
                    }
                } else {
                    out.push(act("absent", index, "Didn't come"));
                }
            }
            if (person.role !== "joining") {
                out.push(act("rename", index, "Edit name"));
            }
            if (person.adult && person.state !== "absent") {
                out.push(act("refuse", index, "Name doesn't match", true));
            }
            return out.join("");
        }

        function rowHtml(person, index) {
            const locked = person.state === "refused";
            const isOpen = st.open === index && !locked;
            let body = "";
            if (isOpen) {
                if (person.adult) {
                    if (person.state === "absent") {
                        body += '<p class="gp-note">Marked as not here. If they came after all, choose their ID.</p>';
                    }
                    body += '<div class="idc-chips" role="radiogroup" aria-label="' + esc("ID " + firstName(person.name) + " showed") + '">' + chipsHtml(person, index) + "</div>";
                    if (person.state === "noid") {
                        body += '<label class="idc-note"><span>Why no ID? (optional)</span><input type="text" maxlength="150" placeholder="For example: senior citizen the office knows" data-gp-note="' + index + '" value="' + esc(person.note) + '"></label>';
                    }
                } else {
                    body += '<p class="gp-note">' + (person.state === "absent" ? "Marked as not here." : "Under 18: no ID needed.") + "</p>";
                }
                if (st.renaming === index) {
                    body += '<div class="gp-rename"><label class="gp-field"><span>Name as on their ID</span>' +
                        '<input type="text" maxlength="150" autocomplete="off" spellcheck="false" data-gp-rename-input value="' + esc(person.name) + '"></label>' +
                        '<div class="gp-form-actions"><button type="button" class="admin-cancel-button" data-gp-rename-cancel>Cancel</button>' +
                        '<button type="button" class="admin-primary-button" data-gp-rename-save="' + index + '">Save the name</button></div></div>';
                }
                const acts = actsHtml(person, index);
                if (acts) {
                    body += '<div class="gp-acts">' + acts + "</div>";
                }
            }
            return '<li class="gp-person' + (isOpen ? " is-open" : "") + '" data-state="' + person.state + '">' +
                '<button class="gp-sum" type="button" data-gp-toggle="' + index + '" aria-expanded="' + isOpen + '"' + (locked ? " disabled" : "") + ">" +
                '<span class="gp-mark" aria-hidden="true">' + markHtml(person, index) + "</span>" +
                '<span class="gp-who"><strong>' + esc(person.name) + "</strong><small>" + esc(roleText(person)) +
                ' · <span class="gp-status">' + esc(statusText(person)) + "</span></small></span>" +
                (locked ? "" : ICON.chevron) + "</button>" +
                (isOpen ? '<div class="gp-body">' + body + "</div>" : "") +
                "</li>";
        }

        function addHtml() {
            if (people.length - 1 >= maxPeople) {
                return '<p class="gp-max">Up to ' + maxPeople + " people can come with one visitor.</p>";
            }
            if (!st.adding) {
                return '<button class="gp-add-btn" type="button" data-gp-add>' + ICON.plus + "Add someone who came</button>";
            }
            const group = "idcAge" + uid;
            return '<div class="gp-add-form">' +
                '<div><p class="gp-add-title">Add someone who came</p><p class="gp-add-sub">With ' + esc(firstName(options.name)) + ", but not on the list.</p></div>" +
                '<label class="gp-field"><span>Full name, as on their ID</span><input type="text" maxlength="150" autocomplete="off" spellcheck="false" data-gp-newname value="' + esc(st.addName) + '"></label>' +
                '<div class="gp-age" role="radiogroup" aria-label="Age">' +
                '<label class="idc-chip"><input type="radio" name="' + group + '" value="adult" data-gp-age' + (st.addAdult ? " checked" : "") + "><span>18 or older</span></label>" +
                '<label class="idc-chip"><input type="radio" name="' + group + '" value="child" data-gp-age' + (st.addAdult ? "" : " checked") + "><span>Under 18</span></label></div>" +
                '<p class="gp-add-error" data-gp-add-error hidden>Type their name first.</p>' +
                '<div class="gp-form-actions"><button type="button" class="admin-cancel-button" data-gp-add-cancel>Cancel</button>' +
                '<button type="button" class="admin-primary-button" data-gp-add-save>' + ICON.plus + "Add to the list</button></div>" +
                "</div>";
        }

        function updateButton() {
            const left = pending();
            const ready = left.length === 0 && isIn(people[0]);
            // The people who will come in: everyone not marked as absent or refused.
            const count = people.filter(function (person) { return person.state !== "absent" && person.state !== "refused"; }).length;
            button.disabled = !ready || st.busy;
            label.textContent = st.busy ? "Checking in…" : (count <= 1 ? "Check in " + firstName(people[0].name) : "Check in " + count + " people");
            if (st.error) {
                hint.textContent = st.error;
                hint.classList.add("is-error");
                hint.hidden = false;
                return;
            }
            hint.classList.remove("is-error");
            hint.hidden = ready;
            if (!ready) {
                const names = left.map(function (person) { return firstName(person.name); });
                hint.textContent = names.length > 3
                    ? "Still to check: " + names.slice(0, 2).join(", ") + " and " + (names.length - 2) + " others."
                    : "Still to check: " + listNames(names) + ".";
            }
        }

        function draw() {
            const group = people.length > 1;
            const left = pending().length;
            // With one person left, tapping their ID is as quick as the shortcut.
            const showSame = Boolean(st.lastType) && left > 1 && people.some(function (person) { return person.state === "id"; });
            box.innerHTML = '<div class="gp-head">' +
                '<p class="idc-label">' + esc(options.listLabel || "People on this pass") + ' <span class="gp-count">' + people.length + "</span></p>" +
                '<p class="idc-must">' + ICON.id + "<span>" + (group ? "Each adult's ID must match their name. Under 18: no ID needed." : "The name on their ID must match.") + "</span></p></div>" +
                (showSame ? '<button class="gp-same" type="button" data-gp-same>' + ICON.copy + "<span>Same ID for everyone: <b>" + esc(shortId(st.lastType)) + "</b><small>Fills the " + left + " people not checked yet</small></span></button>" : "") +
                '<ol class="gp-list">' + people.map(rowHtml).join("") + "</ol>" +
                (canAdd ? addHtml() : "");
            updateButton();
        }

        function focusLater(selector) {
            window.requestAnimationFrame(function () {
                const field = host.querySelector(selector);
                if (field) {
                    field.focus();
                }
            });
        }

        function setId(index, value) {
            const person = people[index];
            if (value === NO_ID) {
                person.state = "noid";
                person.idType = "";
            } else {
                person.state = "id";
                person.idType = value;
                st.lastType = value;
            }
        }

        async function confirmRefuse(index) {
            const person = people[index];
            if (person.role === "holder") {
                const group = people.length > 1;
                const first = firstName(person.name);
                return window.AppDialog.confirm({
                    eyebrow: "Name doesn't match",
                    title: group ? "Don't check in this group?" : "Don't check in this person?",
                    message: "This pass is " + person.name + "'s." + (group ? " Without " + first + ", nobody on it comes in." : "") +
                        " It stays valid, so " + first + " can still use it.\n\nThis is written in the activity log with your name and the time.",
                    confirmLabel: "Don't check in",
                    cancelLabel: "Back",
                    danger: true,
                });
            }
            return window.AppDialog.confirm({
                eyebrow: "Name doesn't match",
                title: "Don't let " + person.name + " in?",
                message: "Their ID shows a different name." + (person.role === "joining" ? "" : " The others on this pass can still come in.") +
                    "\n\nOnly a spelling mistake, or a middle name missing? Choose Back, then Edit name.\n\nThis is written in the activity log with your name and the time.",
                confirmLabel: "Don't let in",
                cancelLabel: "Back",
                danger: true,
            });
        }

        async function doAct(name, index) {
            const person = people[index];
            st.error = "";
            if (name === "absent") {
                person.state = "absent";
                person.idType = "";
                st.open = nextPending(index);
                st.renaming = -1;
            } else if (name === "here") {
                person.state = "here";
            } else if (name === "remove") {
                people.splice(index, 1);
                st.open = -1;
                st.renaming = -1;
            } else if (name === "rename") {
                st.renaming = index;
                draw();
                focusLater("[data-gp-rename-input]");
                return;
            } else if (name === "refuse") {
                if (!(await confirmRefuse(index))) {
                    return;
                }
                if (person.role === "holder" || person.role === "joining") {
                    setBusy(true);
                    try {
                        await options.onRefuse();
                    } finally {
                        setBusy(false);
                    }
                    return;
                }
                person.state = "refused";
                person.idType = "";
                st.open = nextPending(index);
                st.renaming = -1;
            }
            draw();
        }

        function saveAdd() {
            const input = host.querySelector("[data-gp-newname]");
            const name = (input ? input.value : st.addName).trim().replace(/\s+/g, " ");
            if (name.length < 2 || !/\p{L}/u.test(name)) {
                host.querySelector("[data-gp-add-error]").hidden = false;
                if (input) {
                    input.focus();
                }
                return;
            }
            people.push({ id: null, name: name, adult: st.addAdult, role: "gate", state: st.addAdult ? "" : "here", idType: "", note: "", fixed: false });
            st.adding = false;
            st.addName = "";
            if (st.addAdult) {
                st.open = people.length - 1;
            }
            draw();
        }

        function saveRename(index) {
            const input = host.querySelector("[data-gp-rename-input]");
            const name = input ? input.value.trim().replace(/\s+/g, " ") : "";
            if (name.length >= 2 && /\p{L}/u.test(name) && name !== people[index].name) {
                people[index].name = name;
                people[index].fixed = name !== people[index].booked;
            }
            st.renaming = -1;
            draw();
        }

        function answer() {
            const holder = people[0];
            return {
                holder: {
                    id_type: holder.state === "noid" ? NO_ID : (holder.state === "here" ? "" : holder.idType),
                    no_id_reason: holder.state === "noid" ? holder.note : "",
                },
                people: people.slice(1).map(function (person) {
                    const result = person.state === "absent" ? "absent" : (person.state === "refused" ? "refused" : "in");
                    const idType = person.adult && result === "in" ? (person.state === "noid" ? NO_ID : person.idType) : "";
                    const reason = person.state === "noid" ? person.note : "";
                    if (person.id === null) {
                        return { full_name: person.name, is_adult: person.adult, result: "in", id_type: idType, no_id_reason: reason };
                    }
                    return { id: person.id, full_name: person.name, result: result, id_type: idType, no_id_reason: reason };
                }),
            };
        }

        function setBusy(busy) {
            st.busy = Boolean(busy);
            updateButton();
        }

        host.addEventListener("click", function (event) {
            const target = event.target;
            const toggle = target.closest("[data-gp-toggle]");
            if (toggle) {
                const index = Number(toggle.dataset.gpToggle);
                st.open = st.open === index ? -1 : index;
                st.renaming = -1;
                draw();
                return;
            }
            const actButton = target.closest("[data-gp-act]");
            if (actButton) {
                doAct(actButton.dataset.gpAct, Number(actButton.dataset.i));
                return;
            }
            if (target.closest("[data-gp-same]")) {
                people.forEach(function (person) {
                    if (person.adult && person.state === "") {
                        person.state = "id";
                        person.idType = st.lastType;
                    }
                });
                st.open = -1;
                draw();
                return;
            }
            if (target.closest("[data-gp-add]")) {
                st.adding = true;
                st.addName = "";
                st.addAdult = true;
                draw();
                focusLater("[data-gp-newname]");
                return;
            }
            if (target.closest("[data-gp-add-cancel]")) {
                st.adding = false;
                draw();
                return;
            }
            if (target.closest("[data-gp-add-save]")) {
                saveAdd();
                return;
            }
            const renameSave = target.closest("[data-gp-rename-save]");
            if (renameSave) {
                saveRename(Number(renameSave.dataset.gpRenameSave));
                return;
            }
            if (target.closest("[data-gp-rename-cancel]")) {
                st.renaming = -1;
                draw();
                return;
            }
            if (target.closest("[data-idc-checkin]") && !button.disabled) {
                st.error = "";
                setBusy(true);
                Promise.resolve(options.onCheckIn(answer())).finally(function () {
                    setBusy(false);
                });
            }
        });

        host.addEventListener("change", function (event) {
            const target = event.target;
            if (target.matches("[data-gp-id]")) {
                const index = Number(target.dataset.gpId);
                setId(index, target.value);
                st.error = "";
                window.clearTimeout(timer);
                draw();
                if (target.value === NO_ID) {
                    focusLater('[data-gp-note="' + index + '"]');
                    return;
                }
                // Show the tick for a moment, then open the next person still to check.
                timer = window.setTimeout(function () {
                    if (st.open === index) {
                        st.open = nextPending(index);
                        draw();
                    }
                }, 320);
                return;
            }
            if (target.matches("[data-gp-age]")) {
                st.addAdult = target.value === "adult";
            }
        });

        host.addEventListener("input", function (event) {
            const target = event.target;
            if (target.matches("[data-gp-note]")) {
                people[Number(target.dataset.gpNote)].note = target.value;
            } else if (target.matches("[data-gp-newname]")) {
                st.addName = target.value;
            }
        });

        host.addEventListener("keydown", function (event) {
            if (event.key !== "Enter") {
                return;
            }
            if (event.target.matches("[data-gp-newname]")) {
                event.preventDefault();
                saveAdd();
            } else if (event.target.matches("[data-gp-rename-input]")) {
                event.preventDefault();
                const save = host.querySelector("[data-gp-rename-save]");
                saveRename(Number(save.dataset.gpRenameSave));
            }
        });

        draw();
        return {
            setBusy: setBusy,
            showError: function (message) {
                st.error = String(message || "");
                updateButton();
            },
        };
    }

    /** After a check-in: who came in, and who didn't (server person payloads plus the visitor). */
    function doneListHtml(holderName, holderIdType, holderReason, people) {
        const rows = [{ name: holderName, state: holderIdType ? "id" : "noid", text: holderIdType ? shortId(holderIdType) : "No ID shown" + (holderReason ? ": " + holderReason : "") }];
        (people || []).forEach(function (person) {
            let state = "id";
            let text = "";
            if (person.gate_status === "absent") {
                state = "absent";
                text = "Didn't come";
            } else if (person.gate_status === "refused") {
                state = "refused";
                text = "Not let in: name didn't match";
            } else if (!person.is_adult) {
                state = "here";
                text = "Under 18, no ID needed";
            } else if (person.id_type_shown) {
                text = shortId(person.id_type_shown);
            } else {
                state = "noid";
                text = "No ID shown" + (person.no_id_reason ? ": " + person.no_id_reason : "");
            }
            if (person.added_at_gate) {
                text += " · added at the gate";
            }
            if (person.booked_name) {
                text += " · booked as " + person.booked_name;
            }
            rows.push({ name: person.full_name, state: state, text: text });
        });
        return '<ul class="gp-done">' + rows.map(function (row) {
            const mark = row.state === "absent" ? ICON.dash : (row.state === "refused" ? ICON.cross : ICON.check);
            return '<li data-state="' + row.state + '"><span class="gp-mark" aria-hidden="true">' + mark + "</span>" +
                "<span><b>" + esc(row.name) + "</b><small>" + esc(row.text) + "</small></span></li>";
        }).join("") + "</ul>";
    }

    window.IdCheck = { render: render, doneListHtml: doneListHtml, firstName: firstName, listNames: listNames, shortId: shortId };
})();
