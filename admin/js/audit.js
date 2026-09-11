(function () {
  var config = window.contentguardAudit;
  if (!config) {
    return;
  }

  var i18n = config.i18n || {};
  var startButtons = document.querySelectorAll(".contentguard-audit-start, #contentguard-audit-start");
  var cancelButton = document.getElementById("contentguard-audit-cancel");
  var confirmBox = document.getElementById("contentguard-audit-cancel-confirm");
  var confirmYes = document.getElementById("contentguard-audit-cancel-confirm-yes");
  var confirmNo = document.getElementById("contentguard-audit-cancel-confirm-no");
  var activePanel = document.getElementById("contentguard-audit-active");
  var clientNotice = document.getElementById("contentguard-audit-client-notice");
  var cancelling = false;

  function prefersReducedMotion() {
    return !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches);
  }

  function post(action, data) {
    var body = new URLSearchParams();
    body.set("action", action);
    body.set("_wpnonce", config.nonce);
    Object.keys(data || {}).forEach(function (key) {
      body.set(key, data[key]);
    });

    return fetch(config.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: body.toString()
    }).then(function (response) {
      return response.json();
    });
  }

  function setStartEnabled(enabled) {
    startButtons.forEach(function (button) {
      button.disabled = !enabled;
    });
  }

  function showNotice(message) {
    if (!clientNotice) {
      return;
    }
    var text = clientNotice.querySelector(".contentguard-notice-message") || clientNotice.querySelector("p");
    if (text) {
      text.textContent = message;
    }
    clientNotice.hidden = false;
    if (typeof clientNotice.focus === "function") {
      clientNotice.setAttribute("tabindex", "-1");
      clientNotice.focus({ preventScroll: prefersReducedMotion() });
    }
  }

  function hideNotice() {
    if (clientNotice) {
      clientNotice.hidden = true;
    }
  }

  function formatProgress(scanned, total) {
    if (total > 0) {
      return (i18n.progressKnown || "%1$d of %2$d content items checked")
        .replace("%1$d", String(scanned))
        .replace("%2$d", String(total));
    }
    return (i18n.progressUnknown || "%d content items checked").replace("%d", String(scanned));
  }

  function updateProgress(run) {
    if (!run) {
      return;
    }

    var status = document.getElementById("contentguard-audit-status");
    var scanned = document.getElementById("contentguard-audit-scanned");
    var total = document.getElementById("contentguard-audit-total");
    var failed = document.getElementById("contentguard-audit-failed");
    var warned = document.getElementById("contentguard-audit-warned");
    var countText = document.getElementById("contentguard-audit-count-text");
    var percent = document.getElementById("contentguard-audit-progress");
    var percentWrap = document.getElementById("contentguard-audit-progress-wrap");
    var bar = document.getElementById("contentguard-audit-progress-bar");
    var progressbar = document.getElementById("contentguard-audit-progressbar");

    if (status) {
      status.textContent = run.status === "pending" ? "Pending" : "Running";
    }
    if (scanned) {
      scanned.textContent = String(run.posts_scanned || 0);
    }
    if (total) {
      total.textContent = String(run.posts_total || 0);
    }
    if (failed) {
      failed.textContent = String(run.posts_failed || 0);
    }
    if (warned) {
      warned.textContent = String(run.posts_warned || 0);
    }
    if (countText) {
      countText.textContent = formatProgress(run.posts_scanned || 0, run.posts_total || 0);
    }
    if (cancelButton && run.id) {
      cancelButton.setAttribute("data-run", String(run.id));
    }

    var known = run.posts_total > 0 && run.progress !== null && run.progress !== undefined;
    if (progressbar) {
      if (known) {
        progressbar.setAttribute("aria-valuemax", "100");
        progressbar.setAttribute("aria-valuenow", String(run.progress));
        progressbar.removeAttribute("aria-busy");
      } else {
        progressbar.setAttribute("aria-busy", "true");
        progressbar.removeAttribute("aria-valuenow");
      }
    }
    if (bar) {
      bar.style.width = known ? String(run.progress) + "%" : "";
    }
    if (percent) {
      percent.textContent = known ? String(run.progress) : "";
    }
    if (percentWrap) {
      percentWrap.hidden = !known;
    }
  }

  function showRunning(run) {
    if (!activePanel) {
      return;
    }
    activePanel.hidden = false;
    updateProgress(run);
    if (typeof activePanel.focus === "function") {
      activePanel.focus({ preventScroll: prefersReducedMotion() });
    }
  }

  function runBatches(runId) {
    return post(config.actions.batch, { run_id: runId }).then(function (payload) {
      if (cancelling) {
        return;
      }
      if (!payload || !payload.ok || !payload.run) {
        showNotice((payload && payload.message) || i18n.batchFailed || "The audit could not continue.");
        window.location.reload();
        return;
      }

      updateProgress(payload.run);

      if (payload.run.status === "running" || payload.run.status === "pending") {
        return runBatches(payload.run.id);
      }

      window.location.reload();
    });
  }

  function startAudit() {
    hideNotice();
    setStartEnabled(false);
    if (confirmBox) {
      confirmBox.hidden = true;
    }
    post(config.actions.start, {}).then(function (payload) {
      if (!payload || !payload.ok || !payload.run) {
        showNotice((payload && payload.message) || i18n.couldNotStart || "Could not start the audit.");
        setStartEnabled(true);
        return;
      }

      showRunning(payload.run);
      return runBatches(payload.run.id);
    });
  }

  function cancelAudit() {
    if (!cancelButton) {
      return;
    }
    cancelling = true;
    cancelButton.disabled = true;
    post(config.actions.cancel, { run_id: cancelButton.getAttribute("data-run") }).then(function () {
      window.location.reload();
    });
  }

  startButtons.forEach(function (button) {
    button.addEventListener("click", function () {
      startAudit();
    });
  });

  if (cancelButton) {
    cancelButton.addEventListener("click", function () {
      if (!confirmBox) {
        cancelAudit();
        return;
      }
      confirmBox.hidden = false;
      if (confirmYes && typeof confirmYes.focus === "function") {
        confirmYes.focus();
      }
    });
  }

  if (confirmYes) {
    confirmYes.addEventListener("click", function () {
      cancelAudit();
    });
  }

  if (confirmNo) {
    confirmNo.addEventListener("click", function () {
      if (confirmBox) {
        confirmBox.hidden = true;
      }
      if (cancelButton && typeof cancelButton.focus === "function") {
        cancelButton.focus();
      }
    });
  }

  var historyDetails = document.querySelector(".contentguard-history__details");
  if (historyDetails) {
    var historySummary = historyDetails.querySelector(".contentguard-history__summary");
    var syncHistoryState = function () {
      if (!historySummary) {
        return;
      }
      historySummary.setAttribute("aria-expanded", historyDetails.open ? "true" : "false");
    };
    syncHistoryState();
    historyDetails.addEventListener("toggle", syncHistoryState);
  }

  if (activePanel && !activePanel.hidden && cancelButton) {
    var activeId = cancelButton.getAttribute("data-run");
    if (activeId) {
      runBatches(activeId);
    }
  }
})();
