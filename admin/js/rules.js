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
  var preview = document.getElementById("contentguard-rule-preview");
  var fields = [];
  var previewCopy = config.preview || {};

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

  function selectedOptionLabel(select) {
    if (!select || select.selectedIndex < 0) {
      return "";
    }
    return (select.options[select.selectedIndex].textContent || "").trim();
  }

  function fieldLabelFor(key, select) {
    if (select && select.value === key) {
      var label = selectedOptionLabel(select);
      if (label && label !== "Choose a field" && label !== "Field") {
        return label;
      }
    }
    var match = fields.find(function (field) {
      return field.key === key;
    });
    return match ? (match.label || match.name || match.key) : key;
  }

  function srLabel(text, forId) {
    var label = document.createElement("label");
    label.className = "screen-reader-text";
    label.setAttribute("for", forId);
    label.textContent = text;
    return label;
  }

  function fieldSelect(name, selected, id) {
    var select = document.createElement("select");
    select.name = name;
    select.className = "contentguard-field";
    if (id) {
      select.id = id;
    }
    select.appendChild(option("", "Choose a field", selected === ""));
    fields.forEach(function (field) {
      var label = field.label || field.name || field.key;
      select.appendChild(option(field.key, label, field.key === selected, field.type || ""));
    });
    return select;
  }

  function operatorLabelsForType(fieldType) {
    var map = config.operatorsByType || {};
    if (fieldType && map[fieldType]) {
      return map[fieldType];
    }
    return map.default || config.operators || {};
  }

  function requiresOperand(operator) {
    return [
      "equals",
      "not_equals",
      "greater_than",
      "greater_than_or_equal",
      "less_than",
      "less_than_or_equal"
    ].indexOf(operator) !== -1;
  }

  function operatorSelect(name, selected, id, fieldType) {
    var select = document.createElement("select");
    select.name = name;
    select.className = "contentguard-operator";
    if (id) {
      select.id = id;
    }
    var labels = operatorLabelsForType(fieldType);
    Object.keys(labels).forEach(function (value) {
      select.appendChild(option(value, labels[value], value === selected));
    });
    return select;
  }

  function validatorSelect(name, selected, id) {
    var select = document.createElement("select");
    select.name = name;
    select.className = "contentguard-validator";
    if (id) {
      select.id = id;
    }
    Object.keys(config.validators).forEach(function (value) {
      select.appendChild(option(value, config.validators[value], value === selected));
    });
    return select;
  }

  function operandControl(name, value, fieldType, hidden, id) {
    if (fieldType === "true_false") {
      var select = document.createElement("select");
      select.className = "contentguard-operand";
      select.name = name;
      if (id) {
        select.id = id;
      }
      select.appendChild(option("1", "Yes", value === "1" || value === ""));
      select.appendChild(option("0", "No", value === "0"));
      select.hidden = hidden;
      return select;
    }

    var input = document.createElement("input");
    input.type = fieldType === "number" || fieldType === "range" ? "number" : "text";
    if (input.type === "number") {
      input.step = "any";
    }
    input.className = "contentguard-operand";
    input.name = name;
    input.value = value;
    input.hidden = hidden;
    if (id) {
      input.id = id;
    }
    return input;
  }

  function paramGroup(kind, name, value, hidden, id, labelText, suffix) {
    var group = document.createElement("span");
    group.className = "contentguard-param-group contentguard-param-group--" + kind;
    group.hidden = hidden;
    group.appendChild(srLabel(labelText, id));

    var input = document.createElement("input");
    input.id = id;
    input.className = "contentguard-param contentguard-" + kind;
    input.name = name;
    input.value = value;
    input.disabled = hidden;
    if (kind === "values") {
      input.type = "text";
      input.placeholder = "value1, value2";
    } else {
      input.type = "number";
      input.min = "0";
    }
    group.appendChild(input);

    if (suffix) {
      var text = document.createElement("span");
      text.className = "contentguard-param-suffix";
      text.textContent = suffix;
      group.appendChild(text);
    }

    return group;
  }

  function syncOperand(row) {
    var field = row.querySelector(".contentguard-field");
    var operator = row.querySelector(".contentguard-operator");
    var operand = row.querySelector(".contentguard-operand");
    if (!field || !operator || !operand) {
      return;
    }

    var needsValue = requiresOperand(operator.value);
    var next = operandControl(operand.name, operand.value, selectedFieldType(field), !needsValue, operand.id);
    operand.replaceWith(next);
  }

  function syncOperator(row) {
    var field = row.querySelector(".contentguard-field");
    var operator = row.querySelector(".contentguard-operator");
    if (!field || !operator) {
      return;
    }

    var type = selectedFieldType(field);
    var current = operator.value;
    var labels = operatorLabelsForType(type);
    if (!Object.prototype.hasOwnProperty.call(labels, current)) {
      current = "equals";
    }
    operator.replaceWith(operatorSelect(operator.name, current, operator.id, type));
  }

  function toggleCondition(row) {
    syncOperator(row);
    syncOperand(row);
  }

  function setParamGroup(group, visible) {
    if (!group) {
      return;
    }
    group.hidden = !visible;
    var input = group.querySelector(".contentguard-param");
    if (!input) {
      return;
    }
    input.disabled = !visible;
    if (!visible) {
      input.value = "";
    }
  }

  function toggleValidation(row) {
    var type = row.querySelector(".contentguard-validator");
    if (!type) {
      return;
    }
    setParamGroup(row.querySelector(".contentguard-param-group--min"), type.value === "min_length");
    setParamGroup(row.querySelector(".contentguard-param-group--max"), type.value === "max_length");
    setParamGroup(row.querySelector(".contentguard-param-group--values"), type.value === "allowed_values");
  }

  function nextIndex(container) {
    return container.querySelectorAll(".contentguard-row").length;
  }

  function addCondition(selected) {
    var index = nextIndex(conditions);
    var row = document.createElement("div");
    row.className = "contentguard-row contentguard-builder-row";
    row.setAttribute("data-row", "condition");

    var id = document.createElement("input");
    id.type = "hidden";
    id.name = "conditions[" + index + "][id]";
    row.appendChild(id);

    var controls = document.createElement("div");
    controls.className = "contentguard-builder-row__controls";
    var fieldId = "contentguard-condition-field-" + index;
    var operatorId = "contentguard-condition-operator-" + index;
    var operandId = "contentguard-condition-operand-" + index;
    controls.appendChild(srLabel("WHEN field", fieldId));
    controls.appendChild(fieldSelect("conditions[" + index + "][field_key]", selected || "", fieldId));
    controls.appendChild(srLabel("Operator", operatorId));
    controls.appendChild(operatorSelect("conditions[" + index + "][operator]", "equals", operatorId, fieldTypeFor(selected || "")));
    controls.appendChild(srLabel("Value", operandId));
    controls.appendChild(operandControl("conditions[" + index + "][operand]", "", fieldTypeFor(selected || ""), false, operandId));
    row.appendChild(controls);

    var remove = document.createElement("button");
    remove.type = "button";
    remove.className = "button contentguard-remove";
    remove.textContent = "Remove";
    remove.setAttribute("aria-label", "Remove condition " + (index + 1));
    row.appendChild(remove);

    conditions.appendChild(row);
    toggleCondition(row);
    updatePreview();
  }

  function addValidation(selected) {
    var index = nextIndex(validations);
    var row = document.createElement("div");
    row.className = "contentguard-row contentguard-builder-row";
    row.setAttribute("data-row", "validation");

    var id = document.createElement("input");
    id.type = "hidden";
    id.name = "validations[" + index + "][id]";
    row.appendChild(id);

    var controls = document.createElement("div");
    controls.className = "contentguard-builder-row__controls";
    var fieldId = "contentguard-validation-field-" + index;
    var typeId = "contentguard-validation-type-" + index;
    controls.appendChild(srLabel("THEN field", fieldId));
    controls.appendChild(fieldSelect("validations[" + index + "][field_key]", selected || "", fieldId));
    controls.appendChild(srLabel("Requirement", typeId));
    controls.appendChild(validatorSelect("validations[" + index + "][type]", "required", typeId));
    controls.appendChild(paramGroup("min", "validations[" + index + "][min]", "", true, "contentguard-validation-min-" + index, "Minimum length", "characters"));
    controls.appendChild(paramGroup("max", "validations[" + index + "][max]", "", true, "contentguard-validation-max-" + index, "Maximum length", "characters"));
    controls.appendChild(paramGroup("values", "validations[" + index + "][values]", "", true, "contentguard-validation-values-" + index, "Allowed values", ""));

    var messageId = "contentguard-validation-message-" + index;
    controls.appendChild(srLabel("Custom message (optional)", messageId));
    var message = document.createElement("input");
    message.type = "text";
    message.id = messageId;
    message.className = "contentguard-validation-message";
    message.name = "validations[" + index + "][message]";
    message.placeholder = "Custom message (optional)";
    controls.appendChild(message);
    row.appendChild(controls);

    var remove = document.createElement("button");
    remove.type = "button";
    remove.className = "button contentguard-remove";
    remove.textContent = "Remove";
    remove.setAttribute("aria-label", "Remove requirement " + (index + 1));
    row.appendChild(remove);

    validations.appendChild(row);
    toggleValidation(row);
    updatePreview();
  }

  function rebuildFieldSelects() {
    form.querySelectorAll(".contentguard-field").forEach(function (select) {
      var current = select.value;
      var name = select.name;
      var replacement = fieldSelect(name, current, select.id);
      select.replaceWith(replacement);
    });
    form.querySelectorAll("[data-row='condition']").forEach(toggleCondition);
    updatePreview();
  }

  function operandPreviewValue(operand, fieldType) {
    if (!operand || operand.hidden) {
      return "";
    }
    if (fieldType === "true_false") {
      if (operand.value === "1") {
        return "Yes";
      }
      if (operand.value === "0") {
        return "No";
      }
    }
    return (operand.value || "").trim();
  }

  function conditionPhrase(field, operator, value) {
    if (operator === "equals") {
      return field + " is " + value;
    }
    if (operator === "not_equals") {
      return field + " is not " + value;
    }
    if (operator === "is_empty") {
      return field + " is empty";
    }
    if (operator === "is_not_empty") {
      return field + " is not empty";
    }
    if (operator === "greater_than") {
      return field + " is greater than " + value;
    }
    if (operator === "greater_than_or_equal") {
      return field + " is at least " + value;
    }
    if (operator === "less_than") {
      return field + " is less than " + value;
    }
    if (operator === "less_than_or_equal") {
      return field + " is at most " + value;
    }
    return field + " " + operator;
  }

  function validationPhrase(field, type, min, max, values) {
    if (type === "required") {
      return field + " is required";
    }
    if (type === "min_length") {
      return field + " must be at least " + min + " characters";
    }
    if (type === "max_length") {
      return field + " must be at most " + max + " characters";
    }
    if (type === "allowed_values") {
      return field + " must be one of: " + values;
    }
    return field + " " + type;
  }

  function updatePreview() {
    if (!preview) {
      return;
    }

    var whenParts = [];
    var whenIncomplete = false;
    form.querySelectorAll("[data-row='condition']").forEach(function (row) {
      var field = row.querySelector(".contentguard-field");
      var operator = row.querySelector(".contentguard-operator");
      var operand = row.querySelector(".contentguard-operand");
      if (!field || !operator) {
        return;
      }
      var fieldKey = field.value;
      var operandValue = operand && !operand.hidden ? (operand.value || "").trim() : "";
      var started = fieldKey !== "" || operator.value !== "" || operandValue !== "";
      if (!started) {
        return;
      }
      if (fieldKey === "" || operator.value === "") {
        whenIncomplete = true;
        return;
      }
      var needsValue = requiresOperand(operator.value);
      var fieldType = selectedFieldType(field);
      var displayValue = operandPreviewValue(operand, fieldType);
      if (needsValue && displayValue === "") {
        whenIncomplete = true;
        return;
      }
      whenParts.push(conditionPhrase(fieldLabelFor(fieldKey, field), operator.value, displayValue));
    });

    var thenParts = [];
    var thenIncomplete = false;
    form.querySelectorAll("[data-row='validation']").forEach(function (row) {
      var field = row.querySelector(".contentguard-field");
      var type = row.querySelector(".contentguard-validator");
      var min = row.querySelector(".contentguard-min");
      var max = row.querySelector(".contentguard-max");
      var values = row.querySelector(".contentguard-values");
      if (!field || !type) {
        return;
      }
      var fieldKey = field.value;
      var minValue = min && !min.disabled ? min.value.trim() : "";
      var maxValue = max && !max.disabled ? max.value.trim() : "";
      var valuesValue = values && !values.disabled ? values.value.trim() : "";
      var started = fieldKey !== "" || minValue !== "" || maxValue !== "" || valuesValue !== "";
      if (!started && type.value === "") {
        return;
      }
      if (fieldKey === "" || type.value === "") {
        if (started || type.value !== "") {
          thenIncomplete = thenParts.length > 0 || started;
        }
        return;
      }
      if (type.value === "min_length" && minValue === "") {
        thenIncomplete = true;
        return;
      }
      if (type.value === "max_length" && maxValue === "") {
        thenIncomplete = true;
        return;
      }
      if (type.value === "allowed_values" && valuesValue.replace(/\s+/g, "") === "") {
        thenIncomplete = true;
        return;
      }
      thenParts.push(validationPhrase(fieldLabelFor(fieldKey, field), type.value, minValue, maxValue, valuesValue));
    });

    if (whenIncomplete) {
      preview.textContent = previewCopy.incompleteWhen || "Finish the WHEN condition to preview this rule.";
      return;
    }
    if (thenParts.length === 0) {
      preview.textContent = thenIncomplete
        ? (previewCopy.incompleteThen || "Finish the THEN requirement to preview this rule.")
        : (previewCopy.needThen || "Add a THEN requirement to preview this rule.");
      return;
    }
    if (thenIncomplete) {
      preview.textContent = previewCopy.incompleteThen || "Finish the THEN requirement to preview this rule.";
      return;
    }

    var thenText = thenParts.join(" and ");
    preview.textContent = whenParts.length
      ? "When " + whenParts.join(" and ") + ", " + thenText + "."
      : thenText + ".";
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
        updatePreview();
      }
    }
  });

  form.addEventListener("change", function (event) {
    var target = event.target;
    if (!(target instanceof HTMLElement)) {
      return;
    }
    var row = target.closest(".contentguard-row");
    if (row) {
      if (target.classList.contains("contentguard-operator") || target.classList.contains("contentguard-field")) {
        toggleCondition(row);
      }
      if (target.classList.contains("contentguard-validator")) {
        toggleValidation(row);
      }
    }
    updatePreview();
  });

  form.addEventListener("input", function () {
    updatePreview();
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
  updatePreview();

  function prefersReducedMotion() {
    return !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches);
  }

  function restoreNoticeHash() {
    if (!window.history || !window.history.replaceState) {
      return;
    }
    window.history.replaceState(
      null,
      "",
      window.location.pathname + window.location.search + "#contentguard-rule-notice"
    );
  }

  function noticeMessageEl(notice) {
    var el = notice.querySelector(".contentguard-notice-message");
    if (el) {
      return el;
    }
    el = document.createElement("p");
    el.className = "contentguard-notice-message";
    notice.appendChild(el);
    return el;
  }

  function ensureWarningLabel(notice) {
    if (notice.querySelector(".contentguard-notice-label")) {
      return;
    }
    var label = document.createElement("p");
    label.className = "contentguard-notice-label";
    var strong = document.createElement("strong");
    strong.textContent = "Warning:";
    label.appendChild(strong);
    notice.insertBefore(label, notice.firstChild);
  }

  function revealErrorNotice(notice, message, restoreHash) {
    if (!notice) {
      return;
    }
    ensureWarningLabel(notice);
    if (message) {
      noticeMessageEl(notice).textContent = message;
    }
    notice.hidden = false;
    notice.setAttribute("role", "alert");
    notice.setAttribute("tabindex", "-1");

    var behavior = prefersReducedMotion() ? "auto" : "smooth";
    if (typeof notice.scrollIntoView === "function") {
      notice.scrollIntoView({ block: "start", behavior: behavior });
    }

    var focusNotice = function () {
      if (typeof notice.focus === "function") {
        notice.focus({ preventScroll: true });
      }
    };
    if (behavior === "smooth") {
      window.setTimeout(focusNotice, 400);
    } else {
      focusNotice();
    }

    if (restoreHash) {
      restoreNoticeHash();
    }
  }

  var serverNotice = document.getElementById("contentguard-rule-notice");
  if (serverNotice && serverNotice.classList.contains("notice-error")) {
    revealErrorNotice(serverNotice, "", true);
  }

  form.addEventListener("submit", function (event) {
    var message = clientGuardMessage();
    var notice = document.getElementById("contentguard-rule-client-notice");
    if (message) {
      event.preventDefault();
      revealErrorNotice(notice, message, false);
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
