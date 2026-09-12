(function () {
  var config = window.contentguardEditorField || {};

  function prefersReducedMotion() {
    return window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  }

  function isSafeFieldKey(fieldKey) {
    return typeof fieldKey === "string" && /^field_[A-Za-z0-9_]+$/.test(fieldKey);
  }

  function coreConfig() {
    return config.core && typeof config.core === "object" ? config.core : {};
  }

  function coreSurface() {
    var surface = coreConfig().surface;
    if (surface === "gutenberg" || surface === "classic") {
      return surface;
    }

    return window.wp && wp.data && wp.data.select && wp.data.select("core/editor")
      ? "gutenberg"
      : "classic";
  }

  function coreIdsForSurface(surface) {
    var ids = coreConfig()[surface];
    if (Array.isArray(ids) && ids.length) {
      return ids;
    }

    return surface === "gutenberg"
      ? ["title", "content", "excerpt", "featured_image"]
      : ["title", "content", "excerpt", "featured_image", "slug", "author"];
  }

  function isSupportedCore(fieldId) {
    return typeof fieldId === "string" && coreIdsForSurface(coreSurface()).indexOf(fieldId) !== -1;
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

  function parseRepeaterPath(raw) {
    if (raw === undefined || raw === null || raw === "") {
      return [];
    }
    if (raw === "invalid") {
      return null;
    }
    var parsed = raw;
    if (typeof raw === "string") {
      try {
        parsed = JSON.parse(raw);
      } catch (error) {
        return null;
      }
    }
    if (!Array.isArray(parsed)) {
      return null;
    }
    if (!parsed.length) {
      return [];
    }

    var path = [];
    for (var i = 0; i < parsed.length; i++) {
      var step = parsed[i] || {};
      var repeater = step.repeater || "";
      var displayRow = sanitizeDisplayRow(step.display_row || step.displayRow);
      if (!isSafeFieldKey(repeater) || displayRow < 1) {
        return null;
      }
      path.push({ repeater: repeater, display_row: displayRow });
    }

    return path;
  }

  function isRealRepeaterRow(node) {
    return !!(
      node &&
      node.classList &&
      node.classList.contains("acf-row") &&
      !node.classList.contains("acf-clone") &&
      !node.closest(".acf-clone")
    );
  }

  function firstChildByClass(parent, className) {
    if (!parent || !parent.children) {
      return null;
    }
    for (var i = 0; i < parent.children.length; i++) {
      if (parent.children[i].classList && parent.children[i].classList.contains(className)) {
        return parent.children[i];
      }
    }
    return null;
  }

  function realRepeaterRows(repeaterField) {
    if (!repeaterField) {
      return [];
    }

    var repeater = firstChildByClass(repeaterField, "acf-repeater");
    if (!repeater) {
      var input = firstChildByClass(repeaterField, "acf-input");
      repeater = input ? firstChildByClass(input, "acf-repeater") : null;
    }
    if (!repeater) {
      return [];
    }

    var rowParent = repeater;
    var table = firstChildByClass(repeater, "acf-table");
    if (!table) {
      for (var t = 0; t < repeater.children.length; t++) {
        if (repeater.children[t].tagName === "TABLE") {
          table = repeater.children[t];
          break;
        }
      }
    }
    if (table) {
      rowParent = table.tBodies && table.tBodies[0] ? table.tBodies[0] : table;
    }

    var rows = [];
    var children = rowParent.children || [];
    for (var i = 0; i < children.length; i++) {
      if (isRealRepeaterRow(children[i])) {
        rows.push(children[i]);
      }
    }

    return rows;
  }

  function findFieldInRepeaterPath(fieldKey, path, clone) {
    if (!Array.isArray(path) || !path.length) {
      return null;
    }

    var scope = document;
    for (var i = 0; i < path.length; i++) {
      var repeaterField = scope === document
        ? findFieldInScope(document, path[i].repeater, "")
        : findFieldInScope(scope, path[i].repeater, "");
      if (!repeaterField) {
        return null;
      }

      var rows = realRepeaterRows(repeaterField);
      var row = rows[path[i].display_row - 1];
      if (!row) {
        return null;
      }
      scope = row;
    }

    return findFieldInScope(scope, fieldKey, clone);
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

  function findField(fieldKey, layout, displayRow, repeaterPath) {
    var parsed = parseResolutionId(fieldKey);
    var clone = parsed.clone;
    if (clone) {
      fieldKey = parsed.key;
    }

    if (!isSafeFieldKey(fieldKey)) {
      return null;
    }

    if (repeaterPath === null) {
      return null;
    }
    if (Array.isArray(repeaterPath) && repeaterPath.length) {
      return findFieldInRepeaterPath(fieldKey, repeaterPath, clone);
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

  function tryFocus(fieldKey, layout, displayRow, attemptsLeft, repeaterPath) {
    var field = findField(fieldKey, layout, displayRow, repeaterPath);
    if (field) {
      var announcedRow = displayRow;
      if (Array.isArray(repeaterPath) && repeaterPath.length) {
        announcedRow = repeaterPath[repeaterPath.length - 1].display_row;
      }
      reveal(field, announcedRow);
      return;
    }

    if (attemptsLeft <= 0) {
      return;
    }

    window.setTimeout(function () {
      tryFocus(fieldKey, layout, displayRow, attemptsLeft - 1, repeaterPath);
    }, 250);
  }

  function navigateToField(fieldKey, layout, displayRow, repeaterPath) {
    if (!isSafeFieldKey(fieldKey)) {
      return;
    }

    var path = parseRepeaterPath(repeaterPath);
    if (path === null) {
      return;
    }

    tryFocus(fieldKey, layout || "", sanitizeDisplayRow(displayRow), 20, path);
  }

  function firstMatch(selectors) {
    for (var i = 0; i < selectors.length; i++) {
      var node = document.querySelector(selectors[i]);
      if (node) {
        return node;
      }
    }

    return null;
  }

  function focusNode(node) {
    if (!node) {
      return false;
    }

    reveal(node, 0);
    if (typeof node.focus === "function") {
      try {
        node.focus({ preventScroll: true });
      } catch (error) {
        node.focus();
      }
    }

    return true;
  }

  function dispatchEditor(store) {
    return window.wp && wp.data && wp.data.dispatch ? wp.data.dispatch(store) : null;
  }

  function selectEditor(store) {
    return window.wp && wp.data && wp.data.select ? wp.data.select(store) : null;
  }

  function openDocumentSidebar() {
    var editPost = dispatchEditor("core/edit-post");
    if (editPost && typeof editPost.openGeneralSidebar === "function") {
      editPost.openGeneralSidebar("edit-post/document");
      return;
    }

    var editor = dispatchEditor("core/editor");
    if (editor && typeof editor.openGeneralSidebar === "function") {
      editor.openGeneralSidebar("edit-post/document");
    }
  }

  function openEditorPanel(name) {
    var select = selectEditor("core/editor") || selectEditor("core/edit-post");
    var dispatch = dispatchEditor("core/editor") || dispatchEditor("core/edit-post");
    if (!select || !dispatch) {
      return true;
    }

    if (typeof select.isEditorPanelEnabled === "function" && !select.isEditorPanelEnabled(name)) {
      return false;
    }

    if (
      typeof select.isEditorPanelOpened === "function" &&
      !select.isEditorPanelOpened(name) &&
      typeof dispatch.toggleEditorPanelOpened === "function"
    ) {
      dispatch.toggleEditorPanelOpened(name);
    }

    return true;
  }

  function clickFirst(selectors) {
    var node = firstMatch(selectors);
    if (node && typeof node.click === "function") {
      node.click();
      return true;
    }

    return false;
  }

  function excerptControl() {
    return firstMatch([
      ".editor-post-excerpt textarea",
      ".editor-post-excerpt__dropdown__content textarea",
      ".editor-post-excerpt .components-textarea-control__input"
    ]);
  }

  function excerptDropdownTrigger() {
    return firstMatch([
      ".editor-post-excerpt__dropdown__trigger",
      "button.editor-post-excerpt__dropdown__trigger",
      ".editor-post-excerpt__dropdown button"
    ]);
  }

  function isExcerptDropdownOpen(trigger) {
    if (trigger && trigger.getAttribute("aria-expanded") === "true") {
      return true;
    }

    return !!firstMatch([
      ".editor-post-excerpt__dropdown__content textarea",
      ".editor-post-excerpt__dropdown__content"
    ]);
  }

  function openExcerptDropdownIfClosed() {
    var trigger = excerptDropdownTrigger();
    if (!trigger || typeof trigger.click !== "function" || isExcerptDropdownOpen(trigger)) {
      return;
    }

    trigger.click();
  }

  function navigateGutenbergExcerpt() {
    var control = excerptControl();
    if (control) {
      return focusNode(control);
    }

    openDocumentSidebar();
    openEditorPanel("post-excerpt");

    control = excerptControl();
    if (control) {
      return focusNode(control);
    }

    openExcerptDropdownIfClosed();
    return focusNode(excerptControl());
  }

  function navigateGutenbergCore(fieldId) {
    if (fieldId === "title") {
      return focusNode(firstMatch([".editor-post-title__input", "h1.editor-post-title"]));
    }

    if (fieldId === "content") {
      return focusNode(firstMatch([
        ".block-editor-writing-flow",
        ".editor-visual-editor",
        ".editor-styles-wrapper"
      ]));
    }

    if (fieldId === "excerpt") {
      return navigateGutenbergExcerpt();
    }

    if (fieldId === "featured_image") {
      openDocumentSidebar();
      openEditorPanel("featured-image");
      return focusNode(firstMatch([
        ".editor-post-featured-image",
        ".editor-post-featured-image__container",
        ".editor-post-featured-image button"
      ]));
    }

    return false;
  }

  function navigateClassicCore(fieldId) {
    var map = {
      title: ["#title", 'input[name="post_title"]'],
      content: ["#postdivrich", "#wp-content-wrap", "#content"],
      excerpt: ["#excerpt", "#postexcerpt textarea", "#postexcerpt"],
      featured_image: ["#postimagediv", "#set-post-thumbnail"],
      slug: ["#new-post-slug", "#editable-post-name", "#edit-slug-box", 'input[name="post_name"]'],
      author: ['select[name="post_author_override"]', "#post_author_override", "#authordiv"]
    };
    var selectors = map[fieldId];
    if (!selectors) {
      return false;
    }

    var node = firstMatch(selectors);
    if (!node) {
      return false;
    }

    if (fieldId === "slug") {
      clickFirst(["#edit-slug-buttons .edit-slug", "button.edit-slug", "#edit-slug-box .edit-slug"]);
      node = firstMatch(["#new-post-slug", "#editable-post-name", 'input[name="post_name"]']) || node;
    }

    return focusNode(node);
  }

  var coreNavGeneration = 0;

  function tryCoreFocus(fieldId, attemptsLeft, generation) {
    if (generation !== coreNavGeneration) {
      return;
    }

    var found = coreSurface() === "gutenberg"
      ? navigateGutenbergCore(fieldId)
      : navigateClassicCore(fieldId);
    if (found || attemptsLeft <= 0) {
      return;
    }

    window.setTimeout(function () {
      tryCoreFocus(fieldId, attemptsLeft - 1, generation);
    }, 250);
  }

  function navigateToCore(fieldId) {
    if (!isSupportedCore(fieldId)) {
      return;
    }

    coreNavGeneration += 1;
    tryCoreFocus(fieldId, 20, coreNavGeneration);
  }

  window.contentguardNavigateToField = navigateToField;
  window.contentguardNavigateToCore = navigateToCore;

  document.addEventListener("click", function (event) {
    var coreTrigger = event.target && event.target.closest
      ? event.target.closest("[data-contentguard-core]")
      : null;
    if (coreTrigger) {
      var coreId = coreTrigger.getAttribute("data-contentguard-core") || "";
      if (!isSupportedCore(coreId)) {
        return;
      }

      event.preventDefault();
      navigateToCore(coreId);
      return;
    }

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
      trigger.getAttribute("data-contentguard-display-row") || config.displayRow || 0,
      trigger.getAttribute("data-contentguard-repeater-path")
    );
  });

  var autoStarted = false;
  function startFromUrl() {
    if (autoStarted || !config.autoNavigate) {
      return;
    }
    if (isSafeFieldKey(config.fieldKey)) {
      autoStarted = true;
      navigateToField(
        config.fieldKey,
        config.layout || "",
        config.displayRow || 0,
        config.repeaterPath
      );
      return;
    }
    if (isSupportedCore(config.fieldKey)) {
      autoStarted = true;
      navigateToCore(config.fieldKey);
    }
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
