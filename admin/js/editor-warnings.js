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

  function showMessages(messages) {
    var notices = noticeStore();
    if (!notices) {
      return;
    }

    shownIds.forEach(function (id) {
      notices.removeNotice(id);
    });
    shownIds = [];

    (messages || []).forEach(function (message, index) {
      if (!message) {
        return;
      }
      var id = "contentguard-warning-" + index;
      notices.createNotice("warning", message, {
        id: id,
        isDismissible: true,
        type: "default"
      });
      shownIds.push(id);
    });
  }

  function refreshFromRest() {
    if (!window.wp || !wp.apiFetch || !config.restPath) {
      return;
    }

    wp.apiFetch({ path: config.restPath }).then(function (payload) {
      showMessages(payload && payload.messages ? payload.messages : []);
    });
  }

  if (config.messages && config.messages.length) {
    showMessages(config.messages);
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
