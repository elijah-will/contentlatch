(function () {
  var config = window.contentguardEditorAudit || {};
  var NOTICE_ID = "contentguard-audit-blockers";
  var html = typeof config.html === "string" ? config.html : "";
  var text = typeof config.text === "string" ? config.text : "";

  function editorSelect() {
    return window.wp && wp.data && wp.data.select ? wp.data.select("core/editor") : null;
  }

  function noticeStore() {
    return window.wp && wp.data && wp.data.dispatch ? wp.data.dispatch("core/notices") : null;
  }

  function showNotice(nextHtml, nextText) {
    var notices = noticeStore();
    if (!notices) {
      return;
    }

    if (typeof nextHtml !== "string" || nextHtml === "" || nextHtml === "[object Object]") {
      if (typeof notices.removeNotice === "function") {
        notices.removeNotice(NOTICE_ID);
      }
      return;
    }

    notices.createNotice("error", nextHtml, {
      id: NOTICE_ID,
      isDismissible: true,
      type: "default",
      spokenMessage: typeof nextText === "string" ? nextText : "",
      __unstableHTML: true
    });
  }

  function refreshFromRest() {
    if (!window.wp || !wp.apiFetch || !config.restPath) {
      return;
    }

    wp.apiFetch({ path: config.restPath }).then(function (payload) {
      var nextHtml = payload && typeof payload.html === "string" ? payload.html : "";
      var nextText = payload && typeof payload.text === "string" ? payload.text : "";
      showNotice(nextHtml, nextText);
    });
  }

  if (html !== "" && html !== "[object Object]") {
    showNotice(html, text);
  }

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
