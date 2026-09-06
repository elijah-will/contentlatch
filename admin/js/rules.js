(function () {
  var config = window.contentguardRules;
  if (!config) {
    return;
  }

  var form = document.getElementById("contentguard-rule-form");
  if (!form) {
    return;
  }

  var postType = document.getElementById("contentguard-rule-post-type");
  var conditions = document.getElementById("contentguard-conditions");
  var validations = document.getElementById("contentguard-validations");
  var fields = [];

  function option(value, label, selected, type) {
    var node = document.createElement("option");
    node.value = value;
    node.textContent = label;
    if (selected) {
      node.selected = true;
    }
    if (type) {
      node.setAttribute("data-type", type);
    }
    return node;
  }

  function fieldTypeFor(key) {
    var match = fields.find(function (field) {
      return field.key === key;
    });
    return match && match.type ? match.type : "";
  }

  function selectedFieldType(select) {
    var selected = select.options[select.selectedIndex];
    if (selected && selected.getAttribute("data-type")) {
      return selected.getAttribute("data-type");
    }

    return fieldTypeFor(select.value);
  }

  function fieldSelect(name, selected) {
    var select = document.createElement("select");
    select.name = name;
    select.className = "contentguard-field";
    select.appendChild(option("", "Field", selected === ""));
    fields.forEach(function (field) {
      var label = field.label || field.name || field.key;
      select.appendChild(option(field.key, label, field.key === selected, field.type || ""));
    });
    return select;
  }

  function operatorSelect(name, selected) {
    var select = document.createElement("select");
    select.name = name;
    select.className = "contentguard-operator";
    Object.keys(config.operators).forEach(function (value) {
      select.appendChild(option(value, config.operators[value], value === selected));
    });
    return select;
  }

  function validatorSelect(name, selected) {
    var select = document.createElement("select");
    select.name = name;
    select.className = "contentguard-validator";
    Object.keys(config.validators).forEach(function (value) {
      select.appendChild(option(value, config.validators[value], value === selected));
    });
    return select;
  }

  function operandControl(name, value, fieldType, hidden) {
    if (fieldType === "true_false") {
      var select = document.createElement("select");
      select.className = "contentguard-operand";
      select.name = name;
      select.appendChild(option("1", "Yes", value === "1" || value === ""));
      select.appendChild(option("0", "No", value === "0"));
      select.hidden = hidden;
      return select;
    }

    var input = document.createElement("input");
    input.type = "text";
    input.className = "contentguard-operand";
    input.name = name;
    input.value = value;
    input.hidden = hidden;
    return input;
  }

  function syncOperand(row) {
    var field = row.querySelector(".contentguard-field");
    var operator = row.querySelector(".contentguard-operator");
    var operand = row.querySelector(".contentguard-operand");
    if (!field || !operator || !operand) {
      return;
    }

    var needsValue = operator.value === "equals" || operator.value === "not_equals";
    var next = operandControl(operand.name, operand.value, selectedFieldType(field), !needsValue);
    operand.replaceWith(next);
  }

  function toggleCondition(row) {
    syncOperand(row);
  }

  function toggleValidation(row) {
    var type = row.querySelector(".contentguard-validator");
    if (!type) {
      return;
    }
    var min = row.querySelector(".contentguard-min");
    var max = row.querySelector(".contentguard-max");
    var values = row.querySelector(".contentguard-values");
    if (min) {
      min.hidden = type.value !== "min_length";
    }
    if (max) {
      max.hidden = type.value !== "max_length";
    }
    if (values) {
      values.hidden = type.value !== "allowed_values";
    }
  }

  function nextIndex(container) {
    return container.querySelectorAll(".contentguard-row").length;
  }

  function addCondition(selected) {
    var index = nextIndex(conditions);
    var row = document.createElement("div");
    row.className = "contentguard-row";
    row.setAttribute("data-row", "condition");

    var id = document.createElement("input");
    id.type = "hidden";
    id.name = "conditions[" + index + "][id]";
    row.appendChild(id);
    row.appendChild(fieldSelect("conditions[" + index + "][field_key]", selected || ""));
    row.appendChild(operatorSelect("conditions[" + index + "][operator]", "equals"));
    row.appendChild(operandControl("conditions[" + index + "][operand]", "", fieldTypeFor(selected || ""), false));

    var remove = document.createElement("button");
    remove.type = "button";
    remove.className = "button contentguard-remove";
    remove.textContent = "Remove";
    row.appendChild(remove);

    conditions.appendChild(row);
    toggleCondition(row);
  }

  function addValidation(selected) {
    var index = nextIndex(validations);
    var row = document.createElement("div");
    row.className = "contentguard-row";
    row.setAttribute("data-row", "validation");

    var id = document.createElement("input");
    id.type = "hidden";
    id.name = "validations[" + index + "][id]";
    row.appendChild(id);
    row.appendChild(fieldSelect("validations[" + index + "][field_key]", selected || ""));
    row.appendChild(validatorSelect("validations[" + index + "][type]", "required"));

    var min = document.createElement("input");
    min.type = "number";
    min.min = "0";
    min.className = "contentguard-param contentguard-min";
    min.name = "validations[" + index + "][min]";
    min.placeholder = "Min (characters)";
    min.hidden = true;
    row.appendChild(min);

    var max = document.createElement("input");
    max.type = "number";
    max.min = "0";
    max.className = "contentguard-param contentguard-max";
    max.name = "validations[" + index + "][max]";
    max.placeholder = "Max (characters)";
    max.hidden = true;
    row.appendChild(max);

    var values = document.createElement("input");
    values.type = "text";
    values.className = "contentguard-param contentguard-values";
    values.name = "validations[" + index + "][values]";
    values.placeholder = "value1, value2";
    values.hidden = true;
    row.appendChild(values);

    var message = document.createElement("input");
    message.type = "text";
    message.className = "contentguard-validation-message";
    message.name = "validations[" + index + "][message]";
    message.placeholder = "Custom message (optional)";
    row.appendChild(message);

    var remove = document.createElement("button");
    remove.type = "button";
    remove.className = "button contentguard-remove";
    remove.textContent = "Remove";
    row.appendChild(remove);

    validations.appendChild(row);
    toggleValidation(row);
  }

  function rebuildFieldSelects() {
    form.querySelectorAll(".contentguard-field").forEach(function (select) {
      var current = select.value;
      var name = select.name;
      var replacement = fieldSelect(name, current);
      select.replaceWith(replacement);
    });
    form.querySelectorAll("[data-row='condition']").forEach(syncOperand);
  }

  function loadFields(type) {
    var body = new URLSearchParams();
    body.set("action", config.fieldsAction);
    body.set("_wpnonce", config.nonce);
    body.set("post_type", type);

    return fetch(config.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: body.toString()
    }).then(function (response) {
      return response.json();
    }).then(function (payload) {
      fields = payload && payload.ok && Array.isArray(payload.fields) ? payload.fields : [];
      rebuildFieldSelects();
    });
  }

  form.addEventListener("click", function (event) {
    var target = event.target;
    if (!(target instanceof HTMLElement)) {
      return;
    }
    if (target.classList.contains("contentguard-remove")) {
      var row = target.closest(".contentguard-row");
      if (row) {
        row.remove();
      }
    }
  });

  form.addEventListener("change", function (event) {
    var target = event.target;
    if (!(target instanceof HTMLElement)) {
      return;
    }
    var row = target.closest(".contentguard-row");
    if (!row) {
      return;
    }
    if (target.classList.contains("contentguard-operator") || target.classList.contains("contentguard-field")) {
      toggleCondition(row);
    }
    if (target.classList.contains("contentguard-validator")) {
      toggleValidation(row);
    }
  });

  var addConditionButton = document.getElementById("contentguard-add-condition");
  if (addConditionButton) {
    addConditionButton.addEventListener("click", function () {
      addCondition();
    });
  }

  var addValidationButton = document.getElementById("contentguard-add-validation");
  if (addValidationButton) {
    addValidationButton.addEventListener("click", function () {
      addValidation();
    });
  }

  form.querySelectorAll("[data-row='condition']").forEach(toggleCondition);
  form.querySelectorAll("[data-row='validation']").forEach(toggleValidation);

  form.addEventListener("submit", function (event) {
    var message = clientGuardMessage();
    var notice = document.getElementById("contentguard-rule-client-notice");
    if (message) {
      event.preventDefault();
      if (notice) {
        var text = notice.querySelector("p");
        if (text) {
          text.textContent = message;
        }
        notice.hidden = false;
      }
      return;
    }
    if (notice) {
      notice.hidden = true;
    }
  });

  if (postType) {
    postType.addEventListener("change", function () {
      loadFields(postType.value);
    });
    loadFields(postType.value);
  }

  function clientGuardMessage() {
    var opsByField = {};
    var minByField = {};
    var maxByField = {};
    var hasThen = false;
    var error = "";

    form.querySelectorAll("[data-row='condition']").forEach(function (row) {
      var field = row.querySelector(".contentguard-field");
      var operator = row.querySelector(".contentguard-operator");
      if (!field || !operator || !field.value) {
        return;
      }
      if (!opsByField[field.value]) {
        opsByField[field.value] = [];
      }
      opsByField[field.value].push(operator.value);
    });

    Object.keys(opsByField).forEach(function (key) {
      var ops = opsByField[key];
      if (ops.indexOf("is_empty") !== -1 && ops.indexOf("is_not_empty") !== -1) {
        error = "This rule cannot be saved because a field cannot be both empty and not empty.";
      }
    });
    if (error) {
      return error;
    }

    Array.prototype.some.call(form.querySelectorAll("[data-row='validation']"), function (row) {
      var field = row.querySelector(".contentguard-field");
      var type = row.querySelector(".contentguard-validator");
      if (!field || !type) {
        return false;
      }
      if (!field.value && !type.value) {
        return false;
      }
      if (!field.value || !type.value) {
        error = "Each requirement needs a field and a validator.";
        return true;
      }
      hasThen = true;
      var key = field.value;
      var ops = opsByField[key] || [];
      if (type.value === "required" && ops.indexOf("is_empty") !== -1) {
        error = "This rule cannot be saved because a field cannot be required when the rule only applies when that same field is empty.";
        return true;
      }
      if (type.value === "required" && ops.indexOf("is_not_empty") !== -1) {
        error = "This rule cannot be saved because a field is already required to have a value by the WHEN condition.";
        return true;
      }
      if (type.value === "required" && ops.indexOf("equals") !== -1) {
        error = "This rule cannot be saved because a field that must already have a specific value does not need to be required.";
        return true;
      }
      if (type.value === "min_length") {
        var min = row.querySelector(".contentguard-min");
        if (min && min.value !== "") {
          minByField[key] = parseInt(min.value, 10);
        }
      }
      if (type.value === "max_length") {
        var max = row.querySelector(".contentguard-max");
        if (max && max.value !== "") {
          maxByField[key] = parseInt(max.value, 10);
        }
      }
      if (type.value === "allowed_values") {
        var values = row.querySelector(".contentguard-values");
        if (!values || values.value.replace(/\s+/g, "") === "") {
          error = "Enter at least one allowed value.";
          return true;
        }
      }
      return false;
    });
    if (error) {
      return error;
    }

    Object.keys(minByField).some(function (key) {
      if (typeof maxByField[key] === "number" && minByField[key] > maxByField[key]) {
        error = "Minimum length cannot be greater than maximum length.";
        return true;
      }
      return false;
    });
    if (error) {
      return error;
    }

    return hasThen ? "" : "Each requirement needs a field and a validator.";
  }
})();
