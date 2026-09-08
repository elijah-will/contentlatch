(function () {
  var config = window.contentguardEditorWarnings;
  if (!config || !config.postId) {
    return;
  }

  var NOTICE_ID = "contentguard-editor-warnings";

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

  function navigate(fieldKey, layout, displayRow) {
    if (typeof window.contentguardNavigateToField === "function") {
      window.contentguardNavigateToField(fieldKey, layout, displayRow);
    }
  }

  function safeLayout(layout) {
    return typeof layout === "string" && /^[A-Za-z0-9_-]+$/.test(layout) ? layout : "";
  }

  function sanitizeDisplayRows(rows) {
    if (!Array.isArray(rows)) {
      return [];
    }

    var seen = {};
    var safe = [];
    rows.forEach(function (value) {
      var row = parseInt(value, 10);
      if (row > 0 && !seen[row]) {
        seen[row] = true;
        safe.push(row);
      }
    });
    return safe;
  }

  function issueText(warning) {
    if (typeof warning === "string") {
      return warning;
    }

    if (!warning || typeof warning !== "object") {
      return "";
    }

    var label = asString(warning.label);
    var message = asString(warning.message);
    var text = asString(warning.text);

    if (label !== "" && message !== "") {
      return label + " — " + message;
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

  function fieldTriggerAttributes(fieldKey, layout, displayRow) {
    var attrs = 'data-contentguard-field="' + escapeHtml(fieldKey) + '"';
    if (safeLayout(layout)) {
      attrs += ' data-contentguard-layout="' + escapeHtml(layout) + '"';
    }
    if (displayRow > 0) {
      attrs += ' data-contentguard-display-row="' + displayRow + '"';
    }
    return attrs;
  }

  function rowButtonsHtml(fieldKey, label, layout, rows) {
    if (rows.length < 2) {
      return "";
    }

    var buttons = rows.map(function (row) {
      var aria = "Go to " + label + ", row " + row;
      return (
        '<button type="button" class="contentguard-warning-field contentguard-warning-row" ' +
        fieldTriggerAttributes(fieldKey, layout, row) +
        ' aria-label="' +
        escapeHtml(aria) +
        '">Row ' +
        row +
        "</button>"
      );
    });

    return ' <span class="contentguard-warning-rows">' + buttons.join('<span aria-hidden="true"> · </span>') + "</span>";
  }

  function itemHtml(warning) {
    var label = asString(warning.label);
    var message = asString(warning.message) || "Content warning.";
    var fieldKey = asString(warning.fieldKey);
    var layout = safeLayout(asString(warning.layout));
    var rows = sanitizeDisplayRows(warning.affectedRows);
    var primaryRow = rows.length === 1 ? rows[0] : rows[0] || 0;
    var aria = rows.length === 1
      ? "Go to " + label + ", row " + primaryRow
      : ((config.i18n && config.i18n.goToField) || "Go to field: %s").replace("%s", label);

    return (
      '<button type="button" class="contentguard-warning-field" ' +
      fieldTriggerAttributes(fieldKey, layout, primaryRow) +
      ' aria-label="' +
      escapeHtml(aria) +
      '">' +
      escapeHtml(label) +
      "</button> — " +
      escapeHtml(message) +
      rowButtonsHtml(fieldKey, label, layout, rows)
    );
  }

  function noticeTitle() {
    return "ContentGuard · " + ((config.i18n && config.i18n.warning) || "Warning");
  }

  function noticeCount(count) {
    if (count <= 1) {
      return "";
    }

    return count + " warnings";
  }

  function buildNoticeHtml(warnings) {
    var items = [];
    var lines = [];

    (warnings || []).forEach(function (warning) {
      if (!warning) {
        return;
      }

      var line = issueText(warning);
      if (line !== "") {
        lines.push(line);
      }

      if (isClickable(warning)) {
        items.push(itemHtml(warning));
      } else if (line !== "" && line !== "[object Object]") {
        items.push(escapeHtml(line));
      }
    });

    if (items.length === 0) {
      return { html: "", text: "" };
    }

    var html = '<div class="contentguard-editor-warnings">';
    html += '<p class="contentguard-editor-warnings__title">' + escapeHtml(noticeTitle()) + "</p>";
    var count = noticeCount(items.length);
    if (count !== "") {
      html += '<p class="contentguard-editor-warnings__count">' + escapeHtml(count) + "</p>";
    }
    html += '<ul class="contentguard-editor-warnings__list">';
    items.forEach(function (item) {
      html += "<li>" + item + "</li>";
    });
    html += "</ul></div>";

    var textLines = [noticeTitle()];
    if (count !== "") {
      textLines.push(count);
    }
    textLines = textLines.concat(lines);

    return { html: html, text: textLines.join("\n") };
  }

  function showNotice(html, text) {
    var notices = noticeStore();
    if (!notices) {
      return;
    }

    if (typeof html !== "string" || html === "" || html === "[object Object]") {
      if (typeof notices.removeNotice === "function") {
        notices.removeNotice(NOTICE_ID);
      }
      return;
    }

    notices.createNotice("warning", html, {
      id: NOTICE_ID,
      isDismissible: true,
      type: "default",
      spokenMessage: typeof text === "string" ? text : "",
      __unstableHTML: true
    });
  }

  function showWarnings(warnings, html, text) {
    if (typeof html === "string" && html !== "" && html !== "[object Object]") {
      showNotice(html, typeof text === "string" ? text : "");
      return;
    }

    var built = buildNoticeHtml(warnings);
    showNotice(built.html, built.text);
  }

  function refreshFromRest() {
    if (!window.wp || !wp.apiFetch || !config.restPath) {
      return;
    }

    wp.apiFetch({ path: config.restPath }).then(function (payload) {
      var nextHtml = payload && typeof payload.html === "string" ? payload.html : "";
      var nextText = payload && typeof payload.text === "string" ? payload.text : "";
      if (nextHtml !== "") {
        showNotice(nextHtml, nextText);
        return;
      }
      if (payload && payload.warnings && payload.warnings.length) {
        showWarnings(payload.warnings);
        return;
      }
      showWarnings(payload && payload.messages ? payload.messages : []);
    });
  }

  showWarnings(config.warnings, config.html, config.text);

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
    navigate(
      fieldKey,
      trigger.getAttribute("data-contentguard-layout") || "",
      trigger.getAttribute("data-contentguard-display-row") || 0
    );
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
