/* Post Type Generator admin behavior.
 *
 * choose() writes the selected Dashicon into the icon field.
 * The search box filters that grid and does not submit the form.
 * An empty key is suggested from a Latin singular name until the key is edited.
 * Add and edit open the same dialog and fill it from the JSON next to the row.
 * Copy reads the export textarea. Delete asks for confirmation first.
 * Save and delete post through admin-ajax, then the list screen reloads.
 */
(function () {
    var dialog = document.getElementById("ptg-dialog");
    var form = document.getElementById("ptg-editor");
    var root = document.querySelector("[data-ptg-icons]");
    var input = document.getElementById("ptg-menu-icon");
    var preview = document.getElementById("ptg-icon-preview");
    var search = document.getElementById("ptg-icon-search");
    var slug = document.getElementById("ptg-slug");
    var singular = document.getElementById("ptg-singular");
    var touched = false;
    var choose = function () {};

    if (root && input && preview) {
        var buttons = Array.prototype.slice.call(root.querySelectorAll("button"));

        choose = function (icon) {
            input.value = icon;
            preview.className = "dashicons ptg-icon-preview " + icon;
            buttons.forEach(function (button) {
                var selected = button.getAttribute("data-icon") === icon;
                button.classList.toggle("is-selected", selected);
                button.setAttribute("aria-pressed", selected ? "true" : "false");
            });
        };

        buttons.forEach(function (button) {
            button.addEventListener("click", function () {
                choose(button.getAttribute("data-icon") || "");
            });
        });

        input.addEventListener("input", function () {
            choose(input.value.trim());
        });

        if (search) {
            search.addEventListener("keydown", function (event) {
                if (event.key === "Enter") {
                    event.preventDefault();
                }
            });

            search.addEventListener("input", function () {
                var query = search.value.trim().toLowerCase();
                buttons.forEach(function (button) {
                    var icon = button.getAttribute("data-icon") || "";
                    button.hidden = query !== "" && icon.indexOf(query) === -1;
                });
            });
        }
    }

    if (slug && singular) {
        singular.addEventListener("input", function () {
            if (touched || slug.readOnly) {
                return;
            }

            var suggested = singular.value
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, "_")
                .replace(/^_+|_+$/g, "")
                .slice(0, 20);

            if (suggested !== "") {
                slug.value = suggested;
            }
        });

        slug.addEventListener("input", function () {
            touched = true;
        });
    }

    function read(node) {
        if (!node) {
            return null;
        }

        try {
            return JSON.parse(node.textContent);
        } catch (error) {
            return null;
        }
    }

    function field(id) {
        return document.getElementById(id);
    }

    function flag(name, on) {
        var box = form.querySelector('input[name="' + name + '"]');
        if (box) {
            box.checked = !!on;
        }
    }

    function group(name, selected) {
        var list = Array.isArray(selected) ? selected : [];
        form.querySelectorAll('input[name="' + name + '"]').forEach(function (box) {
            box.checked = list.indexOf(box.value) !== -1;
        });
    }

    function text(mark, value) {
        var cell = form.querySelector('[data-ptg-sum="' + mark + '"]');
        if (cell) {
            cell.textContent = value;
        }
    }

    function code(mark, value) {
        var cell = form.querySelector('[data-ptg-sum="' + mark + '"]');
        if (!cell) {
            return;
        }

        cell.textContent = "";
        var node = document.createElement("code");
        node.className = "ptg-key";
        node.textContent = value;
        cell.appendChild(node);
    }

    function features() {
        var cell = form.querySelector('[data-ptg-sum="features"]');
        if (!cell || !dialog) {
            return;
        }

        cell.textContent = "";
        var chosen = form.querySelectorAll('input[name="supports[]"]:checked');

        if (!chosen.length) {
            cell.textContent = dialog.getAttribute("data-none") || "";
            return;
        }

        chosen.forEach(function (box) {
            var span = document.createElement("span");
            var label = box.closest("label");
            span.textContent = label ? label.innerText.trim() : box.value;
            cell.appendChild(span);
        });
    }

    function paint(record) {
        var base = record.rewrite_slug || record.slug;
        var archive = record.has_archive && base ? "/" + base + "/" : dialog.getAttribute("data-none") || "";

        if (record.slug) {
            code("key", record.slug);
        } else {
            text("key", "—");
        }

        text("status", record.enabled ? dialog.getAttribute("data-active") : dialog.getAttribute("data-idle"));
        text("visibility", record.public ? dialog.getAttribute("data-public") : (record.show_ui ? dialog.getAttribute("data-admin") : dialog.getAttribute("data-hidden")));

        if (archive.charAt(0) === "/") {
            code("archive", archive);
        } else {
            text("archive", archive);
        }

        features();
    }

    function fill(record) {
        if (!form || !record) {
            return;
        }

        field("ptg-singular").value = record.singular || "";
        field("ptg-plural").value = record.plural || "";
        field("ptg-description").value = record.description || "";
        field("ptg-slug").value = record.slug || "";
        field("ptg-slug").readOnly = record.slug !== "";
        field("ptg-original").value = record.slug || "";
        field("ptg-rewrite").value = record.rewrite_slug || "";
        field("ptg-position").value = record.menu_position === 0 || record.menu_position ? record.menu_position : "";
        touched = record.slug !== "";

        flag("enabled", record.enabled);
        flag("public", record.public);
        flag("show_ui", record.show_ui);
        flag("show_in_rest", record.show_in_rest);
        flag("has_archive", record.has_archive);
        flag("hierarchical", record.hierarchical);
        flag("exclude_from_search", record.exclude_from_search);
        flag("with_front", record.with_front);
        group("supports[]", record.supports);
        group("taxonomies[]", record.taxonomies);
        choose(record.menu_icon || "");

        if (search) {
            search.value = "";
            search.dispatchEvent(new Event("input"));
        }

        var links = form.querySelector(".ptg-side-links");
        var entries = field("ptg-entries");
        var entry = field("ptg-entry");

        if (links && entries && entry) {
            if (record.entries) {
                entries.href = record.entries;
                entry.href = record.entry || "#";
                links.hidden = false;
            } else {
                links.hidden = true;
            }
        }

        var danger = form.querySelector(".ptg-danger");
        if (danger) {
            danger.hidden = record.slug === "";
        }

        var layout = form.querySelector(".ptg-layout");
        var box = field("ptg-export");
        var area = field("ptg-code");

        if (layout) {
            layout.hidden = false;
        }

        if (box && area) {
            area.value = record.code || "";
            box.hidden = !record.code;
        }

        var submit = field("ptg-submit");
        var title = field("ptg-dialog-title");

        if (submit && dialog) {
            submit.textContent = record.slug ? dialog.getAttribute("data-update") : dialog.getAttribute("data-create");
        }

        if (title && dialog) {
            title.textContent = record.slug ? dialog.getAttribute("data-saved") : dialog.getAttribute("data-fresh");
        }

        paint(record);
    }

    function openDialog() {
        if (!dialog) {
            return;
        }

        if (!dialog.open) {
            dialog.showModal();
        }

        if (singular) {
            var layout = form ? form.querySelector(".ptg-layout") : null;
            if (!layout || !layout.hidden) {
                singular.focus();
            }
        }
    }

    document.querySelectorAll("[data-ptg-add]").forEach(function (button) {
        button.addEventListener("click", function (event) {
            event.preventDefault();
            fill(read(document.getElementById("ptg-blank")));
            openDialog();
        });
    });

    document.querySelectorAll("[data-ptg-edit]").forEach(function (button) {
        button.addEventListener("click", function () {
            var cell = button.closest("td");
            var record = cell ? read(cell.querySelector(".ptg-record")) : null;
            fill(record);
            openDialog();

            var jump = button.getAttribute("data-ptg-jump");
            var node = jump ? document.getElementById(jump) : null;
            if (node) {
                node.scrollIntoView({ block: "nearest" });
            }
        });
    });

    document.querySelectorAll("[data-ptg-bundle]").forEach(function (button) {
        button.addEventListener("click", function () {
            var bundle = read(document.getElementById("ptg-bundle"));
            var layout = form.querySelector(".ptg-layout");
            var box = field("ptg-export");
            var area = field("ptg-code");
            var title = field("ptg-dialog-title");

            if (layout) {
                layout.hidden = true;
            }

            if (box && area && typeof bundle === "string") {
                area.value = bundle;
                box.hidden = false;
            }

            if (title && dialog) {
                title.textContent = dialog.getAttribute("data-export") || "";
            }

            openDialog();
            if (area) {
                area.scrollIntoView({ block: "nearest" });
            }
        });
    });

    if (dialog) {
        dialog.querySelectorAll("[data-ptg-close]").forEach(function (button) {
            button.addEventListener("click", function () {
                dialog.close();
            });
        });

        dialog.addEventListener("click", function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });

        dialog.addEventListener("close", function () {
            var layout = form.querySelector(".ptg-layout");
            if (layout) {
                layout.hidden = false;
            }
        });

        if (dialog.hasAttribute("data-ptg-hold")) {
            openDialog();
        }
    }

    document.querySelectorAll("[data-ptg-copy]").forEach(function (button) {
        button.addEventListener("click", function () {
            var target = document.getElementById(button.getAttribute("data-ptg-copy"));
            if (!target) {
                return;
            }

            var original = button.textContent;
            var done = function () {
                button.textContent = (window.ptg && ptg.copied) || original;
                window.setTimeout(function () {
                    button.textContent = original;
                }, 1500);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(target.value).then(done).catch(function () {
                    target.focus();
                    target.select();
                });
                return;
            }

            target.focus();
            target.select();
        });
    });

    document.addEventListener("submit", function (event) {
        var form = event.target;

        if (!form || !form.classList || !form.classList.contains("ptg-ajax")) {
            return;
        }

        if (!window.ptg || !ptg.ajax) {
            return;
        }

        event.preventDefault();

        if (form.getAttribute("data-ptg-busy") === "1") {
            return;
        }

        var data = new FormData(form);
        var sender = event.submitter;

        if (sender && sender.name) {
            data.set(sender.name, sender.value);
        }

        data.set("action", "ptg_manage");
        form.setAttribute("data-ptg-busy", "1");

        var buttons = form.querySelectorAll("button");
        buttons.forEach(function (button) {
            button.disabled = true;
        });

        var release = function () {
            form.removeAttribute("data-ptg-busy");
            buttons.forEach(function (button) {
                button.disabled = false;
            });
        };

        var fail = function (message) {
            var host = form.id === "ptg-editor" ? form.parentNode : document.querySelector(".wrap");
            var box = host ? host.querySelector(":scope > .ptg-ajax-error") : null;

            if (!box && host) {
                box = document.createElement("div");
                box.className = "notice notice-error inline ptg-ajax-error";
                box.appendChild(document.createElement("p"));

                if (form.id === "ptg-editor") {
                    host.insertBefore(box, form);
                } else {
                    var intro = host.querySelector(".ptg-intro");
                    if (intro) {
                        intro.parentNode.insertBefore(box, intro);
                    } else {
                        host.insertBefore(box, host.firstChild);
                    }
                }
            }

            if (box) {
                box.hidden = false;
                box.querySelector("p").textContent = message || ptg.failed || "";
            }

            release();
        };

        fetch(ptg.ajax, {
            method: "POST",
            credentials: "same-origin",
            body: data
        }).then(function (response) {
            return response.json();
        }).then(function (body) {
            if (body && body.success && body.data && body.data.url) {
                window.location.replace(body.data.url);
                return;
            }

            fail(body && body.data && body.data.message);
        }).catch(function () {
            fail(ptg.failed);
        });
    });

    document.querySelectorAll("[data-ptg-confirm]").forEach(function (button) {
        button.addEventListener("click", function (event) {
            var message = (window.ptg && ptg.confirm) || "";
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
}());
