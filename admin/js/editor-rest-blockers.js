(function () {
  var config = window.contentguardEditorRestBlockers || {};
  var i18nApi = (window.wp && wp.i18n) ? wp.i18n : null;
  function __(text) {
    return i18nApi ? i18nApi.__(text, "contentguard") : text;
  }
  var ERROR_CODE = config.errorCode || "contentguard_validation_failed";
  var NOTICE_ID = config.noticeId || "contentguard-audit-blockers";
  var SAVE_NOTICE_ID = config.saveNoticeId || "SAVE_POST_NOTICE_ID";
  var SAVE_NOTICE_IDS = Array.isArray(config.saveNoticeIds) && config.saveNoticeIds.length
    ? config.saveNoticeIds
    : [SAVE_NOTICE_ID, "editor-save"];
  var ACF_VALIDATION_NOTICE_ID = "acf-validation";
  var capturedError = null;
  var shownFromSave = false;
  var shownFromAcfValidation = false;
  var lastNoticeHtml = null;
  var dispatchingNotice = false;
  var acfContentGuardIssues = [];

  function editorSelect() {
    return window.wp && wp.data && wp.data.select ? wp.data.select("core/editor") : null;
  }

  function coreSelect() {
    return window.wp && wp.data && wp.data.select ? wp.data.select("core") : null;
  }

  function noticeSelect() {
    return window.wp && wp.data && wp.data.select ? wp.data.select("core/notices") : null;
  }

  function noticeStore() {
    return window.wp && wp.data && wp.data.dispatch ? wp.data.dispatch("core/notices") : null;
  }

  function listedNotices() {
    var select = noticeSelect();
    if (!select || typeof select.getNotices !== "function") {
      return [];
    }

    var global = select.getNotices() || [];
    var snackbar = select.getNotices("snackbar") || [];
    return global.concat(snackbar);
  }

  function isNativeSaveErrorNotice(notice) {
    if (!notice || notice.status !== "error") {
      return false;
    }

    return SAVE_NOTICE_IDS.indexOf(asString(notice.id)) !== -1;
  }

  function nativeSaveErrorNotices() {
    return listedNotices().filter(isNativeSaveErrorNotice);
  }

  function asString(value) {
    return typeof value === "string" ? value : "";
  }

  function escapeHtml(value) {
    return asString(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function isContentGuardError(error) {
    if (!error || typeof error !== "object") {
      return false;
    }

    if (asString(error.code) === ERROR_CODE) {
      return true;
    }

    if (error.data && typeof error.data === "object" && asString(error.data.code) === ERROR_CODE) {
      return true;
    }

    return false;
  }

  function errorData(error) {
    if (!error || typeof error !== "object") {
      return {};
    }

    return error.data && typeof error.data === "object" ? error.data : {};
  }

  function failuresFromError(error) {
    var data = errorData(error);
    if (Array.isArray(data.failures) && data.failures.length) {
      return data.failures;
    }

    if (Array.isArray(data.messages) && data.messages.length) {
      return data.messages.map(function (message) {
        return { message: message };
      });
    }

    var message = asString(error && error.message);
    return message !== "" ? [{ message: message }] : [];
  }

  function isSafeFieldKey(fieldKey) {
    return typeof fieldKey === "string" && /^field_[A-Za-z0-9_]+$/.test(fieldKey);
  }

  function gutenbergCoreIds() {
    var core = window.contentguardEditorField && window.contentguardEditorField.core;
    if (core && Array.isArray(core.gutenberg)) {
      return core.gutenberg;
    }

    return ["title", "content", "excerpt", "featured_image"];
  }

  function isSupportedCore(fieldId) {
    return typeof fieldId === "string" && gutenbergCoreIds().indexOf(fieldId) !== -1;
  }

  function failureFieldId(failure) {
    if (!failure || typeof failure !== "object") {
      return "";
    }

    return asString(failure.field || failure.fieldKey);
  }

  function isClickableFailure(failure) {
    if (!failure || typeof failure !== "object") {
      return false;
    }

    var label = asString(failure.label);
    var fieldId = failureFieldId(failure);
    return label !== "" && (isSafeFieldKey(fieldId) || isSupportedCore(fieldId));
  }

  function safeLayout(layout) {
    return typeof layout === "string" && /^[A-Za-z0-9_-]+$/.test(layout) ? layout : "";
  }

  function fieldTriggerAttributes(fieldKey, layout, displayRow, repeaterPath, pathBlocked) {
    if (isSupportedCore(fieldKey) && !isSafeFieldKey(fieldKey)) {
      return 'data-contentguard-core="' + escapeHtml(fieldKey) + '"';
    }

    var attrs = 'data-contentguard-field="' + escapeHtml(fieldKey) + '"';
    if (safeLayout(layout)) {
      attrs += ' data-contentguard-layout="' + escapeHtml(layout) + '"';
    }
    if (displayRow > 0 && !(Array.isArray(repeaterPath) && repeaterPath.length) && !pathBlocked) {
      attrs += ' data-contentguard-display-row="' + displayRow + '"';
    }
    if (pathBlocked) {
      attrs += ' data-contentguard-repeater-path="invalid"';
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
        attrs += ' data-contentguard-repeater-path="' + escapeHtml('[' + encoded.join(',') + ']') + '"';
      }
    }
    return attrs;
  }

  function issueText(failure) {
    if (typeof failure === "string") {
      return failure;
    }

    if (!failure || typeof failure !== "object") {
      return "";
    }

    var label = asString(failure.label);
    var message = asString(failure.message);
    var required = (config.i18n && config.i18n.required) || __("This field is required.");

    if (label !== "" && message === label + " is required.") {
      return label + " — " + required;
    }

    if (label !== "" && message !== "") {
      if (message.indexOf(label + " — ") === 0) {
        return message;
      }

      if (message.indexOf(label) !== 0) {
        return label + " — " + message;
      }
    }

    return message || label;
  }

  function issueItemHtml(failure, line) {
    if (!isClickableFailure(failure)) {
      return escapeHtml(line);
    }

    var label = asString(failure.label);
    var fieldId = failureFieldId(failure);
    var required = (config.i18n && config.i18n.required) || __("This field is required.");
    var message = asString(failure.message);
    if (label !== "" && message === label + " is required.") {
      message = required;
    } else if (message.indexOf(label + " — ") === 0) {
      message = message.slice((label + " — ").length);
    } else if (message.indexOf(label + " is required.") === 0) {
      message = required;
    }

    var goTo = (config.i18n && config.i18n.goToField) || __("Go to field: %s");
    var layout = safeLayout(asString(failure.layout));
    var repeaterPath = Array.isArray(failure.repeaterPath) ? failure.repeaterPath : [];
    var pathBlocked = !!failure.repeaterPathInvalid;
    var displayRow = 0;
    if (Array.isArray(failure.affectedRows) && failure.affectedRows.length === 1) {
      displayRow = parseInt(failure.affectedRows[0], 10) || 0;
    }
    var attr = fieldTriggerAttributes(fieldId, layout, displayRow, repeaterPath, pathBlocked);

    return (
      '<button type="button" class="contentguard-warning-field" ' +
      attr +
      ' aria-label="' +
      escapeHtml(goTo.replace("%s", label)) +
      '">' +
      escapeHtml(label) +
      "</button> — " +
      escapeHtml(message || required)
    );
  }

  function noticeTitle() {
    return __("ContentGuard") + " · " + ((config.i18n && config.i18n.blocking) || __("Blocking"));
  }

  function noticeCount(count) {
    if (count <= 1) {
      return "";
    }

    var template = (config.i18n && config.i18n.count) || __("%d blocking issues");
    return template.replace("%d", String(count));
  }

  function buildNotice(error) {
    var items = [];
    var lines = [];

    failuresFromError(error).forEach(function (failure) {
      var line = issueText(failure);
      if (line === "" || line === "[object Object]") {
        return;
      }

      lines.push(line);
      items.push(issueItemHtml(failure, line));
    });

    if (items.length === 0) {
      return { html: "", text: "" };
    }

    var html = '<div class="contentguard-audit-blockers">';
    html += '<p class="contentguard-audit-blockers__title">' + escapeHtml(noticeTitle()) + "</p>";
    var count = noticeCount(items.length);
    if (count !== "") {
      html += '<p class="contentguard-audit-blockers__count">' + escapeHtml(count) + "</p>";
    }
    html += '<ul class="contentguard-audit-blockers__list">';
    items.forEach(function (item) {
      html += "<li>" + item + "</li>";
    });
    html += "</ul></div>";

    var textLines = [noticeTitle()];
    if (count !== "") {
      textLines.push(count);
    }

    return { html: html, text: textLines.concat(lines).join("\n") };
  }

  function afterCurrentCycle(callback) {
    setTimeout(callback, 0);
  }

  function showNotice(html, text) {
    var notices = noticeStore();
    if (!notices || dispatchingNotice) {
      return;
    }

    if (typeof html !== "string" || html === "" || html === "[object Object]") {
      if (lastNoticeHtml === "") {
        return;
      }
      lastNoticeHtml = "";
      dispatchingNotice = true;
      try {
        if (typeof notices.removeNotice === "function") {
          notices.removeNotice(NOTICE_ID);
        }
      } finally {
        dispatchingNotice = false;
      }
      return;
    }

    if (html === lastNoticeHtml) {
      return;
    }

    lastNoticeHtml = html;
    dispatchingNotice = true;
    try {
      notices.createNotice("error", html, {
        id: NOTICE_ID,
        isDismissible: true,
        type: "default",
        spokenMessage: typeof text === "string" ? text : "",
        __unstableHTML: true
      });
    } finally {
      dispatchingNotice = false;
    }
  }

  function hideBlockingNotice() {
    if (!shownFromSave && !shownFromAcfValidation) {
      return;
    }

    shownFromSave = false;
    shownFromAcfValidation = false;
    showNotice("", "");
  }

  function isAcfValidationNotice(notice) {
    if (!notice || notice.status !== "error") {
      return false;
    }

    return asString(notice.id) === ACF_VALIDATION_NOTICE_ID;
  }

  function acfValidationNotices() {
    return listedNotices().filter(isAcfValidationNotice);
  }

  function shouldReplaceAcfValidationNotice() {
    return shownFromAcfValidation;
  }

  function suppressAcfValidationNotice() {
    var notices = noticeStore();
    if (!shouldReplaceAcfValidationNotice() || dispatchingNotice || !notices || typeof notices.removeNotice !== "function") {
      return;
    }

    acfValidationNotices().forEach(function (notice) {
      dispatchingNotice = true;
      try {
        if (notice.context) {
          notices.removeNotice(notice.id, notice.context);
        } else {
          notices.removeNotice(notice.id);
        }
      } finally {
        dispatchingNotice = false;
      }
    });
  }

  function queueAcfValidationNoticeSuppress() {
    afterCurrentCycle(suppressAcfValidationNotice);
    if (typeof requestAnimationFrame === "function") {
      requestAnimationFrame(function () {
        afterCurrentCycle(suppressAcfValidationNotice);
      });
    }
  }

  function contentGuardIssueFromAcfError(error) {
    if (!error || typeof error !== "object") {
      return null;
    }

    var payload = error.contentguard;
    if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
      return null;
    }

    if (asString(payload.field || payload.fieldKey) === "") {
      return null;
    }

    return payload;
  }

  function collectAcfContentGuardIssues(data) {
    acfContentGuardIssues = [];
    if (!data || typeof data !== "object" || !Array.isArray(data.errors)) {
      return data;
    }

    data.errors.forEach(function (error) {
      var issue = contentGuardIssueFromAcfError(error);
      if (issue) {
        acfContentGuardIssues.push(issue);
      }
    });

    return data;
  }

  function showAcfContentGuardNotice() {
    if (!acfContentGuardIssues.length) {
      return;
    }

    var failures = acfContentGuardIssues.slice();
    acfContentGuardIssues = [];
    var built = buildNotice({
      code: ERROR_CODE,
      data: { failures: failures }
    });
    if (!built.html) {
      return;
    }

    shownFromSave = true;
    shownFromAcfValidation = true;
    showNotice(built.html, built.text);
    queueAcfValidationNoticeSuppress();
  }

  function bindAcfValidationHooks() {
    if (!window.acf || typeof acf.addFilter !== "function" || typeof acf.addAction !== "function") {
      return;
    }
    if (bindAcfValidationHooks.bound) {
      return;
    }

    bindAcfValidationHooks.bound = true;
    acf.addFilter("validation_complete", function (data) {
      return collectAcfContentGuardIssues(data);
    });
    acf.addAction("validation_failure", showAcfContentGuardNotice);
  }

  bindAcfValidationHooks();
  if (document.addEventListener) {
    document.addEventListener("DOMContentLoaded", bindAcfValidationHooks);
  }

  function shouldReplaceNativeSaveNotice() {
    return shownFromSave && isContentGuardError(lastSaveError());
  }

  function suppressGutenbergSaveNotice() {
    var notices = noticeStore();
    if (!shouldReplaceNativeSaveNotice() || dispatchingNotice || !notices || typeof notices.removeNotice !== "function") {
      return;
    }

    nativeSaveErrorNotices().forEach(function (notice) {
      dispatchingNotice = true;
      try {
        if (notice.context) {
          notices.removeNotice(notice.id, notice.context);
        } else {
          notices.removeNotice(notice.id);
        }
      } finally {
        dispatchingNotice = false;
      }
    });
  }

  function queueNativeSaveNoticeSuppress() {
    afterCurrentCycle(suppressGutenbergSaveNotice);
    if (typeof requestAnimationFrame === "function") {
      requestAnimationFrame(function () {
        afterCurrentCycle(suppressGutenbergSaveNotice);
      });
    }
  }

  function showContentGuardError(error) {
    if (!isContentGuardError(error)) {
      return;
    }

    var built = buildNotice(error);
    shownFromSave = true;
    showNotice(built.html, built.text);
    queueNativeSaveNoticeSuppress();
  }

  function lastSaveError() {
    var editor = editorSelect();
    if (editor && typeof editor.getLastPostSaveError === "function") {
      var fromEditor = editor.getLastPostSaveError();
      if (fromEditor) {
        return fromEditor;
      }
    }

    var postType = editor && typeof editor.getCurrentPostType === "function" ? editor.getCurrentPostType() : "";
    var postId = editor && typeof editor.getCurrentPostId === "function" ? editor.getCurrentPostId() : 0;
    var core = coreSelect();
    if (core && typeof core.getLastEntitySaveError === "function") {
      var fromCore = core.getLastEntitySaveError("postType", postType, postId);
      if (fromCore) {
        return fromCore;
      }
    }

    return capturedError;
  }

  function isAutosaving() {
    var editor = editorSelect();
    return !!(editor && typeof editor.isAutosavingPost === "function" && editor.isAutosavingPost());
  }

  if (window.wp && wp.apiFetch && typeof wp.apiFetch.use === "function") {
    wp.apiFetch.use(function (options, next) {
      return next(options).catch(function (error) {
        if (isContentGuardError(error) && !isAutosaving()) {
          capturedError = error;
          afterCurrentCycle(function () {
            showContentGuardError(error);
          });
        }
        return Promise.reject(error);
      });
    });
  }

  if (!window.wp || !wp.data || !wp.data.subscribe) {
    return;
  }

  var wasSaving = false;
  wp.data.subscribe(function () {
    var editor = editorSelect();
    if (!editor || dispatchingNotice) {
      return;
    }

    var isSaving = !!editor.isSavingPost();
    var autosaving = isAutosaving();
    var finished = wasSaving && !isSaving && !autosaving;
    wasSaving = isSaving;
    if (!finished) {
      return;
    }

    if (typeof editor.didPostSaveRequestSucceed === "function" && editor.didPostSaveRequestSucceed()) {
      capturedError = null;
      afterCurrentCycle(hideBlockingNotice);
      return;
    }

    if (typeof editor.didPostSaveRequestFail === "function" && editor.didPostSaveRequestFail()) {
      var error = lastSaveError();
      afterCurrentCycle(function () {
        showContentGuardError(error);
      });
    }
  });

  wp.data.subscribe(function () {
    if (!shouldReplaceNativeSaveNotice() || dispatchingNotice) {
      return;
    }

    if (nativeSaveErrorNotices().length === 0) {
      return;
    }

    afterCurrentCycle(suppressGutenbergSaveNotice);
  });

  wp.data.subscribe(function () {
    if (!shouldReplaceAcfValidationNotice() || dispatchingNotice) {
      return;
    }

    if (acfValidationNotices().length === 0) {
      return;
    }

    afterCurrentCycle(suppressAcfValidationNotice);
  });
})();
