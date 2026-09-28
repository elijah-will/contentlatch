(function () {
  var config = window.contentlatchEditorWarnings;
  if (!config || !config.postId) {
    return;
  }

  var i18nApi = (window.wp && wp.i18n) ? wp.i18n : null;
  function __(text) {
    return i18nApi ? i18nApi.__(text, "contentlatch") : text;
  }
  function sprintf(fmt) {
    if (i18nApi && typeof i18nApi.sprintf === "function") {
      return i18nApi.sprintf.apply(i18nApi, arguments);
    }
    var args = Array.prototype.slice.call(arguments, 1);
    var i = 0;
    return String(fmt).replace(/%(?:(\d+)\$)?[sd]/g, function (match, num) {
      var idx = num ? parseInt(num, 10) - 1 : i++;
      return idx in args ? String(args[idx]) : match;
    });
  }

  var NOTICE_ID = "contentlatch-editor-warnings";

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

  function gutenbergCoreIds() {
    var core = window.contentlatchEditorField && window.contentlatchEditorField.core;
    if (core && Array.isArray(core.gutenberg)) {
      return core.gutenberg;
    }

    return ["title", "content", "excerpt", "featured_image"];
  }

  function isSupportedCore(fieldId) {
    return typeof fieldId === "string" && gutenbergCoreIds().indexOf(fieldId) !== -1;
  }

  function navigate(fieldKey, layout, displayRow, repeaterPath) {
    if (typeof window.contentlatchNavigateToField === "function") {
      window.contentlatchNavigateToField(fieldKey, layout, displayRow, repeaterPath);
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
    if (!warning || typeof warning !== "object" || asString(warning.label) === "") {
      return false;
    }

    var fieldKey = asString(warning.fieldKey);
    return isSafeFieldKey(fieldKey) || isSupportedCore(fieldKey);
  }

  function fieldTriggerAttributes(fieldKey, layout, displayRow, repeaterPath, pathBlocked) {
    if (isSupportedCore(fieldKey) && !isSafeFieldKey(fieldKey)) {
      return 'data-contentlatch-core="' + escapeHtml(fieldKey) + '"';
    }

    var attrs = 'data-contentlatch-field="' + escapeHtml(fieldKey) + '"';
    if (safeLayout(layout)) {
      attrs += ' data-contentlatch-layout="' + escapeHtml(layout) + '"';
    }
    if (displayRow > 0 && !(Array.isArray(repeaterPath) && repeaterPath.length) && !pathBlocked) {
      attrs += ' data-contentlatch-display-row="' + displayRow + '"';
    }
    if (pathBlocked) {
      attrs += ' data-contentlatch-repeater-path="invalid"';
    } else if (Array.isArray(repeaterPath) && repeaterPath.length) {
      var encoded = [];
      for (var i = 0; i < repeaterPath.length; i++) {
        var step = repeaterPath[i] || {};
        if (!isSafeFieldKey(asString(step.repeater))) {
          encoded = [];
          break;
        }
        var row = parseInt(step.display_row || step.displayRow, 10);
        if (!(row > 0)) {
          encoded = [];
          break;
        }
        encoded.push('{"repeater":"' + step.repeater + '","display_row":' + row + '}');
      }
      if (encoded.length) {
        attrs += ' data-contentlatch-repeater-path="' + escapeHtml('[' + encoded.join(',') + ']') + '"';
      }
    }
    return attrs;
  }

  function rowButtonsHtml(fieldKey, label, layout, rows) {
    if (rows.length < 2) {
      return "";
    }

    var buttons = rows.map(function (row) {
      var aria = sprintf(__("Go to %1$s, row %2$d"), label, row);
      return (
        '<button type="button" class="contentlatch-warning-field contentlatch-warning-row" ' +
        fieldTriggerAttributes(fieldKey, layout, row) +
        ' aria-label="' +
        escapeHtml(aria) +
        '">' +
        sprintf(__("Row %d"), row) +
        "</button>"
      );
    });

    return ' <span class="contentlatch-warning-rows">' + buttons.join('<span aria-hidden="true"> · </span>') + "</span>";
  }

  function itemHtml(warning) {
    var label = asString(warning.label);
    var message = asString(warning.message) || __("Content warning.");
    var fieldKey = asString(warning.fieldKey);
    var layout = safeLayout(asString(warning.layout));
    var rows = sanitizeDisplayRows(warning.affectedRows);
    var repeaterPath = Array.isArray(warning.repeaterPath) ? warning.repeaterPath : [];
    var pathBlocked = !!warning.repeaterPathInvalid;
    var primaryRow = rows.length === 1 ? rows[0] : rows[0] || 0;
    var aria = rows.length === 1
      ? sprintf(__("Go to %1$s, row %2$d"), label, primaryRow)
      : ((config.i18n && config.i18n.goToField) || __("Go to field: %s")).replace("%s", label);

    return (
      '<button type="button" class="contentlatch-warning-field" ' +
      fieldTriggerAttributes(fieldKey, layout, primaryRow, repeaterPath, pathBlocked) +
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
    return __("ContentLatch") + " · " + ((config.i18n && config.i18n.warning) || __("Warning"));
  }

  function noticeCount(count) {
    if (count <= 1) {
      return "";
    }

    return sprintf(__("%d warnings"), count);
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

    var html = '<div class="contentlatch-editor-warnings">';
    html += '<p class="contentlatch-editor-warnings__title">' + escapeHtml(noticeTitle()) + "</p>";
    var count = noticeCount(items.length);
    if (count !== "") {
      html += '<p class="contentlatch-editor-warnings__count">' + escapeHtml(count) + "</p>";
    }
    html += '<ul class="contentlatch-editor-warnings__list">';
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
      ? event.target.closest(".contentlatch-warning-field[data-contentlatch-field]")
      : null;
    if (!trigger) {
      return;
    }

    var fieldKey = trigger.getAttribute("data-contentlatch-field") || "";
    if (!isSafeFieldKey(fieldKey)) {
      return;
    }

    if (event && event.stopPropagation) {
      event.stopPropagation();
    }
    navigate(
      fieldKey,
      trigger.getAttribute("data-contentlatch-layout") || "",
      trigger.getAttribute("data-contentlatch-display-row") || 0,
      trigger.getAttribute("data-contentlatch-repeater-path")
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
