// Admin Campus Map > Inside tab (INDOOR_DIRECTIONS.md): each department's building, floor and
// room, a description for visitors, and the steps from the building door to the office door.
// The phone beside the form shows what the visitor will see, and nothing reaches visitors until
// Save directions. Photos are shrunk here, because the server has no image library, and they are
// sent together with the save, so an abandoned edit leaves nothing on the server.
(function () {
    "use strict";

    const MAX_STEPS = 8;
    const GOOD_STEP_TEXT = 100;
    const MAX_STEP_TEXT = 140;
    const MAX_MESSAGE = 160;
    const PHOTO_LONGEST_SIDE = 1280;
    const PHOTO_MAX_BYTES = 450 * 1024;
    const FLOORS = ["Ground floor", "2nd floor", "3rd floor", "4th floor", "5th floor"];
    /** Where the page stacks the panel above the form (matches style.css). */
    const SMALL_SCREEN = "(max-width: 1050px)";

    const svg = function (path) {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' + path + '"/></svg>';
    };
    const ICON = {
        up: svg("M4 12l1.41 1.41L11 7.83V20h2V7.83l5.58 5.59L20 12l-8-8-8 8z"),
        down: svg("M20 12l-1.41-1.41L13 16.17V4h-2v12.17l-5.58-5.59L4 12l8 8 8-8z"),
        remove: svg("M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"),
        camera: svg("M12 15.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4zM9 2 7.17 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-3.17L15 2H9zm3 15c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5z"),
        volume: svg("M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"),
        back: svg("M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"),
        building: svg("M17 11V3H7v4H3v14h8v-4h2v4h8V11h-4zM7 19H5v-2h2v2zm0-4H5v-2h2v2zm0-4H5V9h2v2zm4 4H9v-2h2v2zm0-4H9V9h2v2zm0-4H9V5h2v2zm4 8h-2v-2h2v2zm0-4h-2V9h2v2zm0-4h-2V5h2v2zm4 12h-2v-2h2v2zm0-4h-2v-2h2v2z"),
        check: svg("M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"),
        nav: svg("M12 2 4.5 20.29l.71.71L12 18l6.79 3 .71-.71z"),
    };

    const state = {
        started: false,
        unavailable: "",
        saved: {},
        locations: {},
        code: "",
        draft: null,
        dirty: false,
        saving: false,
        selected: 0,
        previewMode: "near",
    };

    function el(id) {
        return document.getElementById(id);
    }

    function escapeHtml(text) {
        return String(text === null || text === undefined ? "" : text).replace(/[&<>"]/g, function (character) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[character];
        });
    }

    function officeName(code) {
        return String(CampusSetup.officeNames()[code] || code).replace(" (archived)", "");
    }

    /** Departments in the order the directory lists them, without archived ones. */
    function activeCodes() {
        const names = CampusSetup.officeNames();
        return Object.keys(names).filter(function (code) { return names[code].indexOf("(archived)") === -1; });
    }

    function blankDirections() {
        return { building: "", floor: "", room: "", visitor_message: "", steps: [] };
    }

    function hasWhere(directions) {
        return Boolean(directions.building && directions.floor);
    }

    function isReady(directions) {
        return hasWhere(directions) && Boolean(directions.visitor_message || directions.steps.length);
    }

    function showMessage(text, isError) {
        const box = el("insideMessage");
        box.textContent = text || "";
        box.hidden = !text;
        box.classList.toggle("is-error", Boolean(isError));
        box.classList.toggle("is-success", Boolean(text) && !isError);
    }

    /* ---------- The draft being edited ---------- */

    function openDepartment(code) {
        const saved = state.saved[code] || blankDirections();
        state.code = code;
        state.dirty = false;
        state.selected = 0;
        state.previewMode = "near";
        state.draft = {
            building: saved.building,
            floor: saved.floor,
            room: saved.room,
            visitor_message: saved.visitor_message,
            steps: saved.steps.map(function (step) {
                return { text: step.text, photo: step.photo, shown: step.photo, blob: null };
            }),
        };
        el("insideDepartment").value = code;
        showMessage("");
        renderAll();
    }

    function markChanged() {
        state.dirty = true;
        el("insideSavedNote").textContent = "Not saved";
        renderChecklist();
        renderPreview();
    }

    /** What the visitor app would show now, with this draft. */
    function previewData() {
        return {
            office: officeName(state.code),
            building: state.draft.building.trim(),
            floor: state.draft.floor,
            room: state.draft.room.trim(),
            message: state.draft.visitor_message.trim(),
            steps: state.draft.steps,
        };
    }

    /** The first draft of a sentence for a quick-add button, from the fields above. */
    function quickSteps() {
        const draft = state.draft;
        const building = draft.building.trim();
        const floor = draft.floor || "next floor";
        const room = draft.room.trim();
        return [
            ["Enter", "Enter through the main door" + (building ? " of " + building : "") + "."],
            ["Stairs up", "Take the stairs up to the " + floor + "."],
            ["Stairs down", "Take the stairs down to the " + floor + "."],
            ["Elevator", "Take the elevator to the " + floor + "."],
            ["Turn left", "Turn left."],
            ["Turn right", "Turn right."],
            ["Go straight", "Walk straight ahead."],
            ["Office door", (room || "The office") + " is the door with the " + officeName(state.code) + " sign."],
        ];
    }

    /* ---------- The phone preview, the same screens the app shows ---------- */

    function join(parts) {
        return parts.filter(Boolean).join(", ");
    }

    function statusBar(onMap) {
        return '<div class="inside-statusbar' + (onMap ? " on-map" : "") + '"><span>2:14</span></div>';
    }

    function nearScreen(data) {
        const hasSteps = data.steps.length > 0;
        const title = data.floor ? escapeHtml(data.office) + " is on the " + escapeHtml(data.floor) : escapeHtml(data.office);
        const where = join([data.building, data.room]);
        return '<div class="inside-mapbg"><img src="assets/images/inside-preview-map.svg" alt=""></div>' + statusBar(true)
            + '<div class="inside-banner"><span class="inside-banner-arrow">' + ICON.nav + "</span><strong>"
            + escapeHtml(data.building || data.office) + " is just ahead</strong></div>"
            + '<div class="inside-sheet"><span class="inside-handle"></span>'
            + '<div class="inside-sheet-head"><span class="inside-iconbox">' + ICON.building + "</span><div><strong>" + title + "</strong>"
            + (where ? "<span>" + escapeHtml(where) + "</span>" : "") + "</div></div>"
            + (data.message ? '<p class="inside-phone-message">' + escapeHtml(data.message) + "</p>" : "")
            + '<p class="inside-phone-question">' + (hasSteps ? "Are you at the building now?" : "Are you at " + escapeHtml(data.office) + " now?") + "</p>"
            + '<span class="inside-phone-button">Yes, I\'m here</span><span class="inside-phone-text">Not yet</span></div>';
    }

    function stepScreen(data, index) {
        const step = data.steps[index];
        const last = index === data.steps.length - 1;
        const segments = data.steps.map(function (unused, position) {
            return "<i" + (position <= index ? ' class="on"' : "") + "></i>";
        }).join("");
        return statusBar(false)
            + '<div class="inside-appbar"><span class="inside-appbar-back">' + ICON.back + "</span><strong>To " + escapeHtml(data.office) + "</strong>"
            + '<span class="inside-appbar-voice">' + ICON.volume + "</span></div>"
            + '<div class="inside-stepbody"><div class="inside-stepmeta"><span>Step ' + (index + 1) + " of " + data.steps.length + "</span>"
            + '<span class="inside-segments">' + segments + "</span></div>"
            + (step.shown ? '<div class="inside-stepphoto"><img src="' + escapeHtml(step.shown) + '" alt=""></div>' : "")
            + '<p class="inside-steptext">' + escapeHtml(step.text) + "</p>"
            + '<span class="inside-phone-hear">' + ICON.volume + "Hear again</span></div>"
            + '<div class="inside-stepfoot">' + (last ? '<p class="inside-phone-question">Are you at ' + escapeHtml(data.office) + " now?</p>" : "")
            + '<div class="inside-steplinks"><span>I can\'t find it</span>' + (last ? "" : '<span class="is-quiet">I\'m already there</span>') + "</div>"
            + '<div class="inside-stepbuttons"><span class="inside-phone-outline">Back</span>'
            + (last
                ? '<span class="inside-phone-button is-green">' + ICON.check + "Yes, I'm here</span>"
                : '<span class="inside-phone-button">Next</span>')
            + "</div></div>";
    }

    function renderPreview() {
        const hasSteps = state.draft.steps.length > 0;
        if (!hasSteps) {
            state.previewMode = "near";
        }
        state.selected = Math.min(state.selected, Math.max(0, state.draft.steps.length - 1));
        el("insidePreviewSteps").disabled = !hasSteps;
        el("insidePreviewNear").classList.toggle("is-on", state.previewMode === "near");
        el("insidePreviewSteps").classList.toggle("is-on", state.previewMode === "steps");
        const data = previewData();
        el("insidePhone").innerHTML = '<div class="inside-phone"><div class="inside-phone-screen">'
            + (state.previewMode === "near" ? nearScreen(data) : stepScreen(data, state.selected))
            + "</div></div>";
    }

    /** The same words the app speaks near the building. */
    function nearVoice(data) {
        const parts = [];
        if (data.building) {
            parts.push(data.building + " is just ahead.");
        }
        parts.push(data.office + (data.floor ? " is on the " + data.floor : "") + (data.room ? ", " + data.room : "") + ".");
        if (data.message) {
            parts.push(data.message);
        }
        return parts.join(" ");
    }

    function hear() {
        if (!window.speechSynthesis) {
            showMessage("This browser cannot read aloud. The phone will.", true);
            return;
        }
        const data = previewData();
        const step = data.steps[state.selected];
        const line = new SpeechSynthesisUtterance(state.previewMode === "near" || !step ? nearVoice(data) : step.text);
        line.lang = "en-US";
        window.speechSynthesis.cancel();
        window.speechSynthesis.speak(line);
    }

    /* ---------- The form ---------- */

    function renderCoverage() {
        const codes = activeCodes();
        const ready = codes.filter(function (code) { return state.saved[code] && isReady(state.saved[code]); });
        el("insideCoverageTitle").textContent = "Directions for " + ready.length + " of " + codes.length + " department" + (codes.length === 1 ? "" : "s");
        const chips = el("insideCoverageChips");
        chips.replaceChildren();
        codes.forEach(function (code) {
            const chip = document.createElement("span");
            const has = ready.indexOf(code) !== -1;
            chip.className = "campus-route-chip" + (has ? " has-route inside-chip-ready" : "");
            chip.textContent = (has ? "✓ " : "") + officeName(code);
            chips.appendChild(chip);
        });
    }

    function renderDepartmentSelect() {
        const select = el("insideDepartment");
        const current = state.code || select.value;
        select.replaceChildren();
        activeCodes().forEach(function (code) {
            const option = document.createElement("option");
            option.value = code;
            option.textContent = officeName(code);
            select.appendChild(option);
        });
        if (current && Array.prototype.some.call(select.options, function (option) { return option.value === current; })) {
            select.value = current;
        }
    }

    function renderChecklist() {
        const draft = state.draft;
        const pins = CampusSetup.officePins();
        const routes = window.CampusRoutes ? window.CampusRoutes.countFor(state.code) : 0;
        const items = [
            ["Pin", Boolean(pins[state.code])],
            ["Route", routes > 0],
            ["Floor", hasWhere(draft)],
            ["Description", Boolean(draft.visitor_message.trim())],
            ["Steps" + (draft.steps.length ? " (" + draft.steps.length + ")" : ""), draft.steps.length > 0],
        ];
        el("insideChecklist").innerHTML = items.map(function (item) {
            return '<li class="' + (item[1] ? "is-done" : "") + '"><i>' + (item[1] ? "✓" : "") + "</i>" + escapeHtml(item[0]) + "</li>";
        }).join("");
        const label = el("insideState");
        const ready = isReady({
            building: draft.building.trim(),
            floor: draft.floor,
            visitor_message: draft.visitor_message.trim(),
            steps: draft.steps,
        });
        if (ready && draft.steps.length) {
            label.textContent = "Ready for visitors";
            label.className = "inside-state is-ready";
        } else if (ready) {
            label.textContent = "Ready, description only";
            label.className = "inside-state is-part";
        } else {
            label.textContent = "Not set up yet";
            label.className = "inside-state";
        }
    }

    function stepRow(step, index) {
        const row = document.createElement("li");
        row.className = "inside-step" + (index === state.selected ? " is-on" : "");
        row.dataset.index = String(index);
        const count = step.text.length;
        row.innerHTML = '<span class="inside-step-n">' + (index + 1) + "</span>"
            + '<label class="inside-photo' + (step.shown ? " has-photo" : "") + '">'
            + (step.shown ? '<img src="' + escapeHtml(step.shown) + '" alt="Photo for step ' + (index + 1) + '">' : "")
            + "<span>" + (step.shown ? "Change photo" : ICON.camera + "Add photo") + "</span>"
            + '<input type="file" accept="image/*" aria-label="Photo for step ' + (index + 1) + '"></label>'
            + '<div class="inside-step-copy"><textarea rows="2" maxlength="' + MAX_STEP_TEXT + '" aria-label="What to do in step ' + (index + 1) + '">' + escapeHtml(step.text) + "</textarea>"
            + '<div class="inside-step-under">'
            + (step.shown ? '<button class="inside-step-photo-remove" type="button" data-act="remove-photo">Remove photo</button>' : "<span></span>")
            + '<span class="inside-step-count' + (count > GOOD_STEP_TEXT ? " is-long" : "") + '">' + stepCountText(count) + "</span></div></div>"
            + '<div class="inside-step-tools">'
            + '<button type="button" data-act="up" aria-label="Move step ' + (index + 1) + ' up"' + (index === 0 ? " disabled" : "") + ">" + ICON.up + "</button>"
            + '<button type="button" data-act="down" aria-label="Move step ' + (index + 1) + ' down"' + (index === state.draft.steps.length - 1 ? " disabled" : "") + ">" + ICON.down + "</button>"
            + '<button class="is-remove" type="button" data-act="remove" aria-label="Remove step ' + (index + 1) + '">' + ICON.remove + "</button></div>";
        return row;
    }

    function stepCountText(count) {
        return (count > GOOD_STEP_TEXT ? "A bit long to hear aloud: " : "") + count + "/" + GOOD_STEP_TEXT;
    }

    function renderSteps() {
        const list = el("insideStepList");
        list.replaceChildren();
        state.draft.steps.forEach(function (step, index) {
            list.appendChild(stepRow(step, index));
        });
        el("insideStepsEmpty").hidden = state.draft.steps.length > 0;
        el("insideAddButtons").querySelectorAll("button").forEach(function (button) {
            button.disabled = state.draft.steps.length >= MAX_STEPS;
        });
    }

    function renderForm() {
        const draft = state.draft;
        el("insideTitle").textContent = officeName(state.code);
        el("insideBuilding").value = draft.building;
        const floor = el("insideFloor");
        if (draft.floor && FLOORS.indexOf(draft.floor) === -1) {
            floor.add(new Option(draft.floor, draft.floor));
        }
        floor.value = draft.floor;
        el("insideRoom").value = draft.room;
        el("insideDescription").value = draft.visitor_message;
        el("insideDescriptionCount").textContent = draft.visitor_message.length + "/" + MAX_MESSAGE;
        el("insideSavedNote").textContent = state.dirty ? "Not saved" : "";
        // A location typed in the Department Directory, to copy into these fields.
        const typed = state.locations[state.code] || "";
        const note = el("insideDirectoryNote");
        note.hidden = !typed || hasWhere(draft);
        note.textContent = typed ? "The Department Directory says: " + typed : "";
        // Building names used so far, so the same building is spelled the same way.
        const names = el("insideBuildingNames");
        names.replaceChildren();
        Array.from(new Set(Object.keys(state.saved).map(function (code) { return state.saved[code].building; }).filter(Boolean))).forEach(function (name) {
            const option = document.createElement("option");
            option.value = name;
            names.appendChild(option);
        });
        const copyFrom = el("insideCopyFrom");
        copyFrom.replaceChildren(new Option("Another department", ""));
        Object.keys(state.saved).filter(function (code) {
            return code !== state.code && state.saved[code].steps.length > 0;
        }).forEach(function (code) {
            const count = state.saved[code].steps.length;
            copyFrom.add(new Option(officeName(code) + " (" + count + " step" + (count === 1 ? "" : "s") + ")", code));
        });
        copyFrom.disabled = copyFrom.options.length < 2;
    }

    function renderAll() {
        renderDepartmentSelect();
        renderCoverage();
        renderForm();
        renderSteps();
        renderChecklist();
        renderPreview();
    }

    function select(index, mode) {
        state.selected = index;
        if (mode) {
            state.previewMode = mode;
        }
        document.querySelectorAll(".inside-step").forEach(function (row, position) {
            row.classList.toggle("is-on", position === index);
        });
        renderPreview();
    }

    /* ---------- Photos ---------- */

    /** A photo no larger than the phone needs, without its hidden location data. */
    async function shrinkPhoto(file) {
        let picture;
        try {
            picture = await createImageBitmap(file);
        } catch (error) {
            throw new Error("This picture could not be read. Use a JPG or PNG photo.");
        }
        const scale = Math.min(1, PHOTO_LONGEST_SIDE / Math.max(picture.width, picture.height));
        const canvas = document.createElement("canvas");
        canvas.width = Math.max(1, Math.round(picture.width * scale));
        canvas.height = Math.max(1, Math.round(picture.height * scale));
        canvas.getContext("2d").drawImage(picture, 0, 0, canvas.width, canvas.height);
        if (picture.close) {
            picture.close();
        }
        let blob = null;
        for (const quality of [0.82, 0.7, 0.58]) {
            blob = await new Promise(function (resolve) { canvas.toBlob(resolve, "image/jpeg", quality); });
            if (blob && blob.size <= PHOTO_MAX_BYTES) {
                break;
            }
        }
        if (!blob) {
            throw new Error("This picture could not be read. Use a JPG or PNG photo.");
        }
        return blob;
    }

    /* ---------- Saving ---------- */

    async function save() {
        if (state.saving || !state.code) {
            return;
        }
        const draft = state.draft;
        const empty = draft.steps.findIndex(function (step) { return !step.text.trim(); });
        if (empty !== -1) {
            showMessage("Write what to do in step " + (empty + 1) + ", or remove the step.", true);
            select(empty, "steps");
            const row = el("insideStepList").children[empty];
            if (row) {
                row.querySelector("textarea").focus();
            }
            return;
        }
        state.saving = true;
        el("insideSaveBtn").disabled = true;
        showMessage("");
        el("insideSavedNote").textContent = "Saving...";
        const form = new FormData();
        form.append("office_code", state.code);
        let photoCount = 0;
        const steps = draft.steps.map(function (step) {
            if (!step.blob) {
                return { text: step.text, photo: step.photo };
            }
            form.append("photo_" + photoCount, step.blob, "step.jpg");
            return { text: step.text, new_photo: photoCount++ };
        });
        form.append("payload", JSON.stringify({
            building: draft.building,
            floor: draft.floor,
            room: draft.room,
            visitor_message: draft.visitor_message,
            steps: steps,
        }));
        let saved = false;
        try {
            const response = await fetch("admin_office_directions.php", { method: "POST", credentials: "same-origin", body: form });
            const data = await response.json();
            if (!data || !data.success) {
                showMessage((data && data.message) || "Could not save the directions.", true);
                return;
            }
            state.saved[state.code] = data.directions;
            const where = join([data.directions.building, data.directions.floor, data.directions.room]);
            if (where) {
                state.locations[state.code] = where;
            }
            const code = state.code;
            const mode = state.previewMode;
            const selected = state.selected;
            openDepartment(code);
            state.previewMode = data.directions.steps.length ? mode : "near";
            state.selected = selected;
            renderSteps();
            renderPreview();
            saved = true;
            showMessage(data.message || "Saved.", false);
            // The Department Directory shows the location these directions set.
            document.dispatchEvent(new CustomEvent("departments:updated"));
        } catch (error) {
            showMessage("Could not reach the server. Your changes are still here; try Save again.", true);
        } finally {
            state.saving = false;
            el("insideSaveBtn").disabled = false;
            el("insideSavedNote").textContent = saved ? "Saved" : (state.dirty ? "Not saved" : "");
        }
    }

    /* ---------- Loading ---------- */

    async function load() {
        el("insideUnavailable").hidden = true;
        try {
            const results = await Promise.all([
                fetch("admin_office_directions.php", { credentials: "same-origin", cache: "no-store" }).then(function (response) { return response.json(); }),
                fetch("office_directory.php?all=1", { credentials: "same-origin", cache: "no-store" }).then(function (response) { return response.json(); }),
            ]);
            const data = results[0];
            if (!data || !data.success) {
                throw new Error((data && data.message) || "The directions could not be loaded.");
            }
            state.saved = data.directions || {};
            state.locations = {};
            ((results[1] && results[1].offices) || []).forEach(function (office) {
                state.locations[office.code] = office.location || "";
            });
            const codes = activeCodes();
            if (!state.code || codes.indexOf(state.code) === -1) {
                state.code = codes[0] || "";
            }
            el("insideForm").hidden = !state.code;
            if (state.code) {
                openDepartment(state.code);
            }
        } catch (error) {
            state.started = false;
            el("insideUnavailable").textContent = error.message || "The directions could not be loaded. Refresh the page.";
            el("insideUnavailable").hidden = false;
            el("insideForm").hidden = true;
        }
    }

    /* ---------- Wiring ---------- */

    function onMode(event) {
        const inside = Boolean(event.detail && event.detail.mode === "inside");
        const layout = document.querySelector(".campus-setup-layout");
        if (inside) {
            CampusSetup.setExpanded(false);
        }
        layout.classList.toggle("is-inside", inside);
        el("insideEditor").hidden = !inside;
        if (inside) {
            // On a phone the panel comes first, so the preview starts folded.
            el("insidePreview").open = !window.matchMedia(SMALL_SCREEN).matches;
            if (!state.started) {
                state.started = true;
                load();
            } else if (state.draft) {
                renderCoverage();
                renderChecklist();
            }
        } else {
            if (window.speechSynthesis) {
                window.speechSynthesis.cancel();
            }
            const map = CampusSetup.map();
            if (map) {
                window.requestAnimationFrame(function () { map.invalidateSize({ pan: false }); });
            }
        }
    }

    [["insideBuilding", "building"], ["insideFloor", "floor"], ["insideRoom", "room"], ["insideDescription", "visitor_message"]].forEach(function (pair) {
        el(pair[0]).addEventListener("input", function () {
            state.draft[pair[1]] = this.value;
            if (pair[1] === "visitor_message") {
                el("insideDescriptionCount").textContent = this.value.length + "/" + MAX_MESSAGE;
            }
            state.previewMode = "near";
            markChanged();
        });
    });

    el("insideAddButtons").addEventListener("click", function (event) {
        const button = event.target.closest("[data-quick]");
        if (!button || !state.draft || state.draft.steps.length >= MAX_STEPS) {
            return;
        }
        state.draft.steps.push({ text: quickSteps()[Number(button.dataset.quick)][1], photo: "", shown: "", blob: null });
        state.selected = state.draft.steps.length - 1;
        state.previewMode = "steps";
        renderSteps();
        markChanged();
        const area = el("insideStepList").lastElementChild.querySelector("textarea");
        area.focus();
        area.setSelectionRange(area.value.length, area.value.length);
    });

    el("insideStepList").addEventListener("click", async function (event) {
        const row = event.target.closest(".inside-step");
        if (!row) {
            return;
        }
        const index = Number(row.dataset.index);
        const action = event.target.closest("[data-act]");
        if (!action) {
            select(index, "steps");
            return;
        }
        const steps = state.draft.steps;
        if (action.dataset.act === "remove") {
            const text = steps[index].text.trim();
            if (text && !(await AppDialog.confirm({
                title: "Remove step " + (index + 1) + "?",
                message: "“" + (text.length > 140 ? text.slice(0, 140).trim() + "…" : text) + "”",
                confirmLabel: "Remove step",
                danger: true,
            }))) {
                return;
            }
            steps.splice(index, 1);
            state.selected = Math.max(0, index - 1);
        } else if (action.dataset.act === "remove-photo") {
            steps[index].photo = "";
            steps[index].shown = "";
            steps[index].blob = null;
            state.selected = index;
        } else {
            const target = action.dataset.act === "up" ? index - 1 : index + 1;
            const moved = steps.splice(index, 1)[0];
            steps.splice(target, 0, moved);
            state.selected = target;
        }
        state.previewMode = steps.length ? "steps" : "near";
        renderSteps();
        markChanged();
    });

    el("insideStepList").addEventListener("input", function (event) {
        if (event.target.tagName !== "TEXTAREA") {
            return;
        }
        const row = event.target.closest(".inside-step");
        const index = Number(row.dataset.index);
        state.draft.steps[index].text = event.target.value;
        const count = row.querySelector(".inside-step-count");
        count.textContent = stepCountText(event.target.value.length);
        count.classList.toggle("is-long", event.target.value.length > GOOD_STEP_TEXT);
        state.selected = index;
        state.previewMode = "steps";
        document.querySelectorAll(".inside-step").forEach(function (other, position) {
            other.classList.toggle("is-on", position === index);
        });
        markChanged();
    });

    el("insideStepList").addEventListener("change", async function (event) {
        if (event.target.type !== "file" || !event.target.files[0]) {
            return;
        }
        const index = Number(event.target.closest(".inside-step").dataset.index);
        const step = state.draft.steps[index];
        const file = event.target.files[0];
        try {
            step.blob = await shrinkPhoto(file);
            step.shown = URL.createObjectURL(step.blob);
            step.photo = "";
            showMessage("");
        } catch (error) {
            showMessage(error.message, true);
            return;
        }
        state.selected = index;
        state.previewMode = "steps";
        renderSteps();
        markChanged();
    });

    el("insideCopyFrom").addEventListener("change", async function () {
        const from = state.saved[this.value];
        const name = officeName(this.value);
        this.value = "";
        if (!from) {
            return;
        }
        if (state.draft.steps.length && !(await AppDialog.confirm({
            title: "Replace the steps here?",
            message: from.steps.length === 1
                ? "The 1 step from " + name + " takes their place."
                : "The " + from.steps.length + " steps from " + name + " take their place.",
            confirmLabel: "Replace steps",
        }))) {
            return;
        }
        state.draft.steps = from.steps.map(function (step) {
            return { text: step.text, photo: step.photo, shown: step.photo, blob: null };
        });
        state.selected = 0;
        state.previewMode = "steps";
        renderSteps();
        markChanged();
        showMessage("Copied " + from.steps.length + " steps from " + name + ". Change what's different, then save.", false);
    });

    el("insideDepartment").addEventListener("change", async function () {
        const select = this;
        const code = select.value;
        if (state.dirty && !(await AppDialog.confirm({
            title: "Discard your changes?",
            message: "Your changes to " + officeName(state.code) + " aren't saved yet.",
            confirmLabel: "Discard changes",
            cancelLabel: "Keep editing",
            danger: true,
        }))) {
            select.value = state.code;
            return;
        }
        openDepartment(code);
    });
    el("insidePreviewNear").addEventListener("click", function () {
        state.previewMode = "near";
        renderPreview();
    });
    el("insidePreviewSteps").addEventListener("click", function () {
        state.previewMode = "steps";
        renderPreview();
    });
    el("insideHearBtn").innerHTML = ICON.volume + "Hear it read aloud";
    el("insideHearBtn").addEventListener("click", hear);
    el("insideSaveBtn").addEventListener("click", save);

    el("insideFloor").replaceChildren(new Option("Choose", ""));
    FLOORS.forEach(function (floor) {
        el("insideFloor").add(new Option(floor, floor));
    });
    el("insideAddButtons").innerHTML = ["Enter", "Stairs up", "Stairs down", "Elevator", "Turn left", "Turn right", "Go straight", "Office door"].map(function (label, index) {
        return '<button type="button" data-quick="' + index + '">' + label + "</button>";
    }).join("");

    document.addEventListener("campus-setup:mode", onMode);
    // New departments appear here, and renamed ones update, without reloading the page.
    document.addEventListener("campus-setup:changed", function () {
        if (state.draft && CampusSetup.mode() === "inside") {
            renderDepartmentSelect();
            renderCoverage();
            renderChecklist();
        }
    });
    window.addEventListener("beforeunload", function (event) {
        if (state.dirty) {
            event.preventDefault();
            event.returnValue = "";
        }
    });
})();
