(function () {
  var config = window.contentguardEditorRestBlockers || {};
  var ERROR_CODE = config.errorCode || "contentguard_validation_failed";
  var NOTICE_ID = config.noticeId || "contentguard-audit-blockers";
  var SAVE_NOTICE_ID = config.saveNoticeId || "SAVE_POST_NOTICE_ID";
  var capturedError = null;
  var shownFromSave = false;

  function editorSelect() {
    return window.wp && wp.data && wp.data.select ? wp.data.select("core/editor") : null;
  }

  function coreSelect() {
    return window.wp && wp.data && wp.data.select ? wp.data.select("core") : null;
  }

  function noticeStore() {
    return window.wp && wp.data && wp.data.dispatch ? wp.data.dispatch("core/notices") : null;
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

  function issueText(failure) {
    if (typeof failure === "string") {
      return failure;
    }

    if (!failure || typeof failure !== "object") {
      return "";
    }

    var label = asString(failure.label);
    var message = asString(failure.message);
    var required = (config.i18n && config.i18n.required) || "This field is required.";

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

  function noticeTitle() {
    return "ContentGuard · " + ((config.i18n && config.i18n.blocking) || "Blocking");
  }

  function noticeCount(count) {
    if (count <= 1) {
      return "";
    }

    var template = (config.i18n && config.i18n.count) || "%d blocking issues";
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
      items.push(escapeHtml(line));
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

    notices.createNotice("error", html, {
      id: NOTICE_ID,
      isDismissible: true,
      type: "default",
      spokenMessage: typeof text === "string" ? text : "",
      __unstableHTML: true
    });
  }

  function hideBlockingNotice() {
    if (!shownFromSave) {
      return;
    }

    shownFromSave = false;
    showNotice("", "");
  }

  function suppressGutenbergSaveNotice() {
    var notices = noticeStore();
    if (notices && typeof notices.removeNotice === "function") {
      notices.removeNotice(SAVE_NOTICE_ID);
    }
  }

  function showContentGuardError(error) {
    if (!isContentGuardError(error)) {
      return;
    }

    var built = buildNotice(error);
    shownFromSave = true;
    showNotice(built.html, built.text);
    suppressGutenbergSaveNotice();
    if (typeof requestAnimationFrame === "function") {
      requestAnimationFrame(suppressGutenbergSaveNotice);
    }
    setTimeout(suppressGutenbergSaveNotice, 0);
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
          showContentGuardError(error);
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
    if (!editor) {
      return;
    }

    var isSaving = !!editor.isSavingPost();
    var autosaving = isAutosaving();
    if (wasSaving && !isSaving && !autosaving) {
      if (typeof editor.didPostSaveRequestSucceed === "function" && editor.didPostSaveRequestSucceed()) {
        capturedError = null;
        hideBlockingNotice();
      } else if (typeof editor.didPostSaveRequestFail === "function" && editor.didPostSaveRequestFail()) {
        showContentGuardError(lastSaveError());
      }
    }
    wasSaving = isSaving;
  });
})();
