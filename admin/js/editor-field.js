(function () {
  var config = window.contentguardEditorField || {};

  function prefersReducedMotion() {
    return window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  }

  function isSafeFieldKey(fieldKey) {
    return typeof fieldKey === "string" && /^field_[A-Za-z0-9_]+$/.test(fieldKey);
  }

  function parseResolutionId(id) {
    if (typeof id !== "string") {
      return { clone: "", key: "" };
    }
    var pos = id.lastIndexOf("_field_");
    if (pos <= 0) {
      return { clone: "", key: id };
    }
    var clone = id.slice(0, pos);
    var key = id.slice(pos + 1);
    if (!isSafeFieldKey(clone) || !isSafeFieldKey(key)) {
      return { clone: "", key: id };
    }
    return { clone: clone, key: key };
  }

  function isSafeLayout(layout) {
    return typeof layout === "string" && /^[A-Za-z0-9_-]+$/.test(layout);
  }

  function sanitizeDisplayRow(value) {
    var row = parseInt(value, 10);
    return row > 0 ? row : 0;
  }

  function isRealLayout(node) {
    return !!(
      node &&
      node.classList &&
      node.classList.contains("layout") &&
      !node.classList.contains("acf-clone") &&
      !node.closest(".acf-clone")
    );
  }

  function firstRealField(nodes) {
    for (var i = 0; i < nodes.length; i++) {
      if (!nodes[i].closest(".acf-clone")) {
        return nodes[i];
      }
    }

    return null;
  }

  function realChildLayouts(container) {
    var values = container.querySelector(".values") || container;
    var children = values.children || [];
    var rows = [];
    for (var i = 0; i < children.length; i++) {
      if (isRealLayout(children[i])) {
        rows.push(children[i]);
      }
    }

    return rows;
  }

  function findClonedField(scope, originalKey, clone) {
    if (!isSafeFieldKey(originalKey) || !isSafeFieldKey(clone)) {
      return null;
    }

    var wrappers = scope.querySelectorAll('.acf-field[data-key="' + clone + '"]');
    for (var i = 0; i < wrappers.length; i++) {
      if (wrappers[i].closest(".acf-clone")) {
        continue;
      }
      var nested = firstRealField(wrappers[i].querySelectorAll('.acf-field[data-key="' + originalKey + '"]'));
      if (nested) {
        return nested;
      }
    }

    var composite = clone + "_" + originalKey;
    var inputs = scope.querySelectorAll("input[name], textarea[name], select[name]");
    for (var j = 0; j < inputs.length; j++) {
      if (inputs[j].closest(".acf-clone")) {
        continue;
      }
      var name = inputs[j].getAttribute("name") || "";
      if (
        name.indexOf("[" + clone + "][" + composite + "]") !== -1 ||
        name.indexOf("[" + clone + "][" + originalKey + "]") !== -1
      ) {
        var field = inputs[j].closest(".acf-field");
        if (field && !field.closest(".acf-clone")) {
          return field;
        }
      }
    }

    return null;
  }

  function findFieldInScope(scope, fieldKey, clone) {
    if (clone) {
      var cloned = findClonedField(scope, fieldKey, clone);
      if (cloned) {
        return cloned;
      }
    }

    return firstRealField(scope.querySelectorAll('.acf-field[data-key="' + fieldKey + '"]'));
  }

  function findFieldInLayout(fieldKey, layout, clone) {
    var layouts = document.querySelectorAll('.layout[data-layout="' + layout + '"]');
    for (var i = 0; i < layouts.length; i++) {
      if (!isRealLayout(layouts[i])) {
        continue;
      }

      var match = findFieldInScope(layouts[i], fieldKey, clone);
      if (match) {
        return match;
      }
    }

    return null;
  }

  function seedLayout(fieldKey, layout) {
    if (isSafeLayout(layout)) {
      var layouts = document.querySelectorAll('.layout[data-layout="' + layout + '"]');
      for (var i = 0; i < layouts.length; i++) {
        if (isRealLayout(layouts[i])) {
          return layouts[i];
        }
      }
    }

    var field = firstRealField(document.querySelectorAll('.acf-field[data-key="' + fieldKey + '"]'));
    return field ? field.closest(".layout") : null;
  }

  function findFieldAtDisplayRow(fieldKey, layout, displayRow, clone) {
    var seed = seedLayout(fieldKey, layout);
    if (!seed) {
      return null;
    }

    var flex = seed.closest(".acf-flexible-content");
    if (!flex) {
      return null;
    }

    var rows = realChildLayouts(flex);
    var row = rows[displayRow - 1];
    if (!isRealLayout(row)) {
      return null;
    }

    if (isSafeLayout(layout) && row.getAttribute("data-layout") !== layout) {
      return null;
    }

    return findFieldInScope(row, fieldKey, clone);
  }

  function findField(fieldKey, layout, displayRow) {
    var parsed = parseResolutionId(fieldKey);
    var clone = parsed.clone;
    if (clone) {
      fieldKey = parsed.key;
    }

    if (!isSafeFieldKey(fieldKey)) {
      return null;
    }

    displayRow = sanitizeDisplayRow(displayRow);
    if (displayRow > 0) {
      var targeted = findFieldAtDisplayRow(fieldKey, layout, displayRow, clone);
      if (targeted) {
        return targeted;
      }
    }

    if (isSafeLayout(layout)) {
      var scoped = findFieldInLayout(fieldKey, layout, clone);
      if (scoped) {
        return scoped;
      }
    }

    if (clone) {
      var cloned = findClonedField(document, fieldKey, clone);
      if (cloned) {
        return cloned;
      }
    }

    var fallback = firstRealField(document.querySelectorAll('.acf-field[data-key="' + fieldKey + '"]'));
    if (fallback) {
      return fallback;
    }

    var cloneChild = document.querySelector('.acf-clone .acf-field[data-key="' + fieldKey + '"]');
    if (cloneChild) {
      return cloneChild.closest(".acf-field-repeater, .acf-flexible-content, .layout");
    }

    return null;
  }

  function openClosedPostbox(field) {
    var postbox = field.closest(".postbox.closed");
    if (!postbox) {
      return;
    }

    var handle = postbox.querySelector(".handlediv, button.handlediv, .postbox-header");
    if (handle && typeof handle.click === "function") {
      handle.click();
      return;
    }

    postbox.classList.remove("closed");
  }

  function openCollapsedAncestors(field) {
    var node = field.parentElement;
    while (node && node !== document.documentElement) {
      if (node.classList && node.classList.contains("layout") && node.classList.contains("-collapsed")) {
        node.classList.remove("-collapsed");
        var layoutToggle = node.querySelector('[data-name="collapse-layout"]');
        if (layoutToggle && typeof layoutToggle.click === "function") {
          layoutToggle.click();
        }
      }
      if (node.classList && node.classList.contains("acf-row") && node.classList.contains("-collapsed")) {
        node.classList.remove("-collapsed");
        var rowToggle = node.querySelector('[data-event="collapse-row"]');
        if (rowToggle && typeof rowToggle.click === "function") {
          rowToggle.click();
        }
      }
      if (node.classList && node.classList.contains("acf-field") && node.classList.contains("-collapsed")) {
        node.classList.remove("-collapsed");
        var toggle = node.querySelector(".-collapse, [data-name=\"collapse\"], .acf-field-header");
        if (toggle && toggle.getAttribute("aria-expanded") === "false") {
          toggle.setAttribute("aria-expanded", "true");
        }
      }
      node = node.parentElement;
    }
  }

  function announce(field, displayRow) {
    var live = document.getElementById("contentguard-field-nav-status");
    if (!live) {
      live = document.createElement("div");
      live.id = "contentguard-field-nav-status";
      live.className = "screen-reader-text";
      live.setAttribute("role", "status");
      live.setAttribute("aria-live", "polite");
      document.body.appendChild(live);
    }

    var label = "";
    var labelNode = field.querySelector(".acf-label label, .acf-label");
    if (labelNode && labelNode.textContent) {
      label = labelNode.textContent.replace(/\s+/g, " ").trim();
    }

    var message = (config.i18n && config.i18n.navigated) || "";
    if (label !== "") {
      message = message + " " + label;
    }
    displayRow = sanitizeDisplayRow(displayRow);
    if (displayRow > 0) {
      message = message + " (row " + displayRow + ")";
    }
    live.textContent = "";
    live.textContent = message;
  }

  function reveal(field, displayRow) {
    openClosedPostbox(field);
    openCollapsedAncestors(field);
    announce(field, displayRow);

    var behavior = prefersReducedMotion() ? "auto" : "smooth";
    if (typeof field.scrollIntoView === "function") {
      field.scrollIntoView({ block: "center", behavior: behavior });
    }
  }

  function tryFocus(fieldKey, layout, displayRow, attemptsLeft) {
    var field = findField(fieldKey, layout, displayRow);
    if (field) {
      reveal(field, displayRow);
      return;
    }

    if (attemptsLeft <= 0) {
      return;
    }

    window.setTimeout(function () {
      tryFocus(fieldKey, layout, displayRow, attemptsLeft - 1);
    }, 250);
  }

  function navigateToField(fieldKey, layout, displayRow) {
    if (!isSafeFieldKey(fieldKey)) {
      return;
    }

    tryFocus(fieldKey, layout || "", sanitizeDisplayRow(displayRow), 20);
  }

  window.contentguardNavigateToField = navigateToField;

  document.addEventListener("click", function (event) {
    var trigger = event.target && event.target.closest
      ? event.target.closest("[data-contentguard-field]")
      : null;
    if (!trigger) {
      return;
    }

    var fieldKey = trigger.getAttribute("data-contentguard-field") || "";
    if (!isSafeFieldKey(fieldKey)) {
      return;
    }

    event.preventDefault();
    navigateToField(
      fieldKey,
      trigger.getAttribute("data-contentguard-layout") || config.layout || "",
      trigger.getAttribute("data-contentguard-display-row") || config.displayRow || 0
    );
  });

  var autoStarted = false;
  function startFromUrl() {
    if (autoStarted || !config.autoNavigate || !isSafeFieldKey(config.fieldKey)) {
      return;
    }
    autoStarted = true;
    navigateToField(config.fieldKey, config.layout || "", config.displayRow || 0);
  }

  if (window.acf && typeof window.acf.addAction === "function") {
    window.acf.addAction("ready", startFromUrl);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", startFromUrl);
  } else {
    startFromUrl();
  }
})();
