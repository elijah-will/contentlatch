(function () {
  var config = window.contentguardEditorWarnings;
  if (!config || !config.postId) {
    return;
  }

  var shownIds = [];

  function editorSelect() {
    return window.wp && wp.data && wp.data.select ? wp.data.select("core/editor") : null;
  }

  function noticeStore() {
    return window.wp && wp.data && wp.data.dispatch ? wp.data.dispatch("core/notices") : null;
  }

  function asString(value) {
    return typeof value === "string" ? value : "";
  }

  function isSafeFieldKey(fieldKey) {
    return typeof fieldKey === "string" && /^field_[A-Za-z0-9]+$/.test(fieldKey);
  }

  function navigate(fieldKey) {
    if (typeof window.contentguardNavigateToField === "function") {
      window.contentguardNavigateToField(fieldKey);
    }
  }

  function warningText(warning) {
    if (typeof warning === "string") {
      return warning;
    }

    if (!warning || typeof warning !== "object") {
      return "";
    }

    var label = asString(warning.label);
    var message = asString(warning.message);
    var text = asString(warning.text);
    var prefix = (config.i18n && config.i18n.warning) || "Warning";

    if (label !== "" && message !== "") {
      return prefix + ": " + label + " — " + message;
    }

    return text || message || label;
  }

  function escapeHtml(value) {
    return asString(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function isClickable(warning) {
    return !!(
      warning &&
      typeof warning === "object" &&
      isSafeFieldKey(asString(warning.fieldKey)) &&
      asString(warning.label) !== ""
    );
  }

  function warningHtml(warning) {
    var label = asString(warning.label);
    var message = asString(warning.message) || "Content warning.";
    var fieldKey = asString(warning.fieldKey);
    var prefix = (config.i18n && config.i18n.warning) || "Warning";
    var aria = ((config.i18n && config.i18n.goToField) || "Go to field: %s").replace("%s", label);

    return (
      escapeHtml(prefix) +
      ': <button type="button" class="contentguard-warning-field" data-contentguard-field="' +
      escapeHtml(fieldKey) +
      '" aria-label="' +
      escapeHtml(aria) +
      '">' +
      escapeHtml(label) +
      "</button> — " +
      escapeHtml(message)
    );
  }

  function warningContent(warning) {
    if (isClickable(warning)) {
      return warningHtml(warning);
    }

    return warningText(warning);
  }

  function showWarnings(warnings) {
    var notices = noticeStore();
    if (!notices) {
      return;
    }

    shownIds.forEach(function (id) {
      notices.removeNotice(id);
    });
    shownIds = [];

    (warnings || []).forEach(function (warning, index) {
      if (!warning) {
        return;
      }

      var content = warningContent(warning);
      if (typeof content !== "string" || content === "" || content === "[object Object]") {
        return;
      }

      var id = "contentguard-warning-" + index;
      notices.createNotice("warning", content, {
        id: id,
        isDismissible: true,
        type: "default",
        spokenMessage: warningText(warning),
        __unstableHTML: isClickable(warning)
      });
      shownIds.push(id);
    });
  }

  function refreshFromRest() {
    if (!window.wp || !wp.apiFetch || !config.restPath) {
      return;
    }

    wp.apiFetch({ path: config.restPath }).then(function (payload) {
      if (payload && payload.warnings && payload.warnings.length) {
        showWarnings(payload.warnings);
        return;
      }
      showWarnings(payload && payload.messages ? payload.messages : []);
    });
  }

  if (config.warnings && config.warnings.length) {
    showWarnings(config.warnings);
  } else if (config.messages && config.messages.length) {
    showWarnings(config.messages);
  }

  document.addEventListener("click", function (event) {
    var trigger = event.target && event.target.closest
      ? event.target.closest(".contentguard-warning-field[data-contentguard-field]")
      : null;
    if (!trigger) {
      return;
    }

    var fieldKey = trigger.getAttribute("data-contentguard-field") || "";
    if (!isSafeFieldKey(fieldKey)) {
      return;
    }

    if (event && event.stopPropagation) {
      event.stopPropagation();
    }
    navigate(fieldKey);
  });

  if (!window.wp || !wp.data || !wp.data.subscribe || !editorSelect()) {
    return;
  }

  var wasSaving = false;
  wp.data.subscribe(function () {
    var editor = editorSelect();
    if (!editor) {
      return;
    }

    var isSaving = !!editor.isSavingPost();
    var isAutosaving = !!editor.isAutosavingPost();
    if (wasSaving && !isSaving && !isAutosaving && editor.didPostSaveRequestSucceed()) {
      refreshFromRest();
    }
    wasSaving = isSaving;
  });
})();
