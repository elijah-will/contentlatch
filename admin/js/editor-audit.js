(function () {
  var config = window.contentguardEditorAudit || {};
  var html = typeof config.html === "string" ? config.html : "";
  var text = typeof config.text === "string" ? config.text : "";

  if (html === "" || html === "[object Object]") {
    return;
  }

  function noticeStore() {
    return window.wp && wp.data && wp.data.dispatch ? wp.data.dispatch("core/notices") : null;
  }

  var notices = noticeStore();
  if (!notices) {
    return;
  }

  notices.createNotice("error", html, {
    id: "contentguard-audit-blockers",
    isDismissible: true,
    type: "default",
    spokenMessage: text,
    __unstableHTML: true
  });
})();
