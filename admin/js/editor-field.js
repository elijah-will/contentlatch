(function () {
  var config = window.contentguardEditorField || {};

  function prefersReducedMotion() {
    return window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  }

  function isSafeFieldKey(fieldKey) {
    return typeof fieldKey === "string" && /^field_[A-Za-z0-9]+$/.test(fieldKey);
  }

  function findField(fieldKey) {
    if (!isSafeFieldKey(fieldKey)) {
      return null;
    }

    return document.querySelector('.acf-field[data-key="' + fieldKey + '"]');
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

  function announce(field) {
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
    live.textContent = "";
    live.textContent = message;
  }

  function reveal(field) {
    openClosedPostbox(field);
    announce(field);

    var behavior = prefersReducedMotion() ? "auto" : "smooth";
    if (typeof field.scrollIntoView === "function") {
      field.scrollIntoView({ block: "center", behavior: behavior });
    }
  }

  function tryFocus(fieldKey, attemptsLeft) {
    var field = findField(fieldKey);
    if (field) {
      reveal(field);
      return;
    }

    if (attemptsLeft <= 0) {
      return;
    }

    window.setTimeout(function () {
      tryFocus(fieldKey, attemptsLeft - 1);
    }, 250);
  }

  function navigateToField(fieldKey) {
    if (!isSafeFieldKey(fieldKey)) {
      return;
    }

    tryFocus(fieldKey, 20);
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
    navigateToField(fieldKey);
  });

  var autoStarted = false;
  function startFromUrl() {
    if (autoStarted || !config.autoNavigate || !isSafeFieldKey(config.fieldKey)) {
      return;
    }
    autoStarted = true;
    navigateToField(config.fieldKey);
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
