(function () {
  var config = window.contentguardAudit;
  if (!config) {
    return;
  }

  var startButton = document.getElementById("contentguard-audit-start");
  var cancelButton = document.getElementById("contentguard-audit-cancel");

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

  function runBatches(runId) {
    return post(config.actions.batch, { run_id: runId }).then(function (payload) {
      if (!payload || !payload.ok || !payload.run) {
        window.alert((payload && payload.message) || "Audit batch failed.");
        window.location.reload();
        return;
      }

      if (payload.run.status === "running" || payload.run.status === "pending") {
        return runBatches(payload.run.id);
      }

      window.location.reload();
    });
  }

  if (startButton) {
    startButton.addEventListener("click", function () {
      startButton.disabled = true;
      post(config.actions.start, {}).then(function (payload) {
        if (!payload || !payload.ok || !payload.run) {
          window.alert((payload && payload.message) || "Could not start audit.");
          startButton.disabled = false;
          return;
        }

        return runBatches(payload.run.id);
      });
    });
  }

  if (cancelButton) {
    cancelButton.addEventListener("click", function () {
      cancelButton.disabled = true;
      post(config.actions.cancel, { run_id: cancelButton.getAttribute("data-run") }).then(function () {
        window.location.reload();
      });
    });
  }

  if (cancelButton) {
    var activeId = cancelButton.getAttribute("data-run");
    if (activeId) {
      runBatches(activeId);
    }
  }
})();
