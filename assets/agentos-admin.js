(function () {
  const data = window.AgentOSAdminData || null;
  if (!data) {
    return;
  }

  const mappingContainer = document.querySelector('[data-field-map-target]');
  const postTypeSelect = document.getElementById('agentos-post-types');
  if (!mappingContainer || !postTypeSelect) {
    return;
  }

  const LOG_PREFIX = '[AgentOS][Settings]';
  const loggingEnabled = !!data.loggingEnabled;
  const logInfo = (...args) => {
    if (!loggingEnabled) return;
    try { console.info(LOG_PREFIX, ...args); } catch (_) {}
  };

  const FIELD_KEYS = ['model', 'voice', 'system_prompt', 'user_prompt'];
  const fieldOptions = data.fieldOptions || {};
  const existingMaps = data.existingMaps || {};
  const postTypeLabels = data.postTypes || {};
  const strings = data.strings || {};
  const placeholders = strings.placeholders || {};
  const headings = strings.fieldHeadings || {};

  injectStyles();

  const emptyState = document.createElement('p');
  emptyState.className = 'description agentos-map-empty';
  emptyState.textContent = strings.emptyState || 'Select a post type to configure field mappings.';
  mappingContainer.appendChild(emptyState);

  ensureFieldsets();

  postTypeSelect.addEventListener('change', () => {
    const selected = getSelectedPostTypes();
    logInfo('Post types selected', selected);
    ensureFieldsets();
  });

  function ensureFieldsets() {
    const selected = getSelectedPostTypes();
    syncFieldsets(selected);
    emptyState.style.display = mappingContainer.querySelector('fieldset[data-post-type]') ? 'none' : '';
  }

  function getSelectedPostTypes() {
    return Array.from(postTypeSelect.selectedOptions || [])
      .map(option => option.value)
      .filter(Boolean);
  }

  function syncFieldsets(selected) {
    const active = new Set(selected);
    Array.from(mappingContainer.querySelectorAll('fieldset[data-post-type]')).forEach(fieldset => {
      if (!active.has(fieldset.dataset.postType)) {
        fieldset.remove();
      }
    });

    selected.forEach(postType => {
      if (!mappingContainer.querySelector(`fieldset[data-post-type="${postType}"]`)) {
        mappingContainer.appendChild(buildFieldset(postType));
      }
    });
  }

  function buildFieldset(postType) {
    const fieldset = document.createElement('fieldset');
    fieldset.dataset.postType = postType;
    fieldset.className = 'agentos-map-fieldset';

    const legend = document.createElement('legend');
    legend.innerHTML = `<strong>${escapeHtml(postTypeLabels[postType] || postType)}</strong>`;
    fieldset.appendChild(legend);

    const options = fieldOptions[postType] || [];
    if (!options.length && strings.noFields) {
      const note = document.createElement('p');
      note.className = 'description';
      note.textContent = strings.noFields;
      fieldset.appendChild(note);
    }

    const mapForType = existingMaps[postType] || {};
    FIELD_KEYS.forEach(key => {
      fieldset.appendChild(buildMappingRow(postType, key, options, mapForType[key] || ''));
    });

    return fieldset;
  }

  function buildMappingRow(postType, fieldKey, options, existingValue) {
    const wrapper = document.createElement('div');
    wrapper.className = 'agentos-map-row';

    const label = document.createElement('label');
    label.className = 'agentos-map-label';
    wrapper.appendChild(label);

    const title = document.createElement('span');
    title.className = 'agentos-map-title';
    title.textContent = headings[fieldKey] || fieldKey;
    label.appendChild(title);

    const select = document.createElement('select');
    select.className = 'agentos-map-select';
    select.dataset.postType = postType;
    select.dataset.fieldKey = fieldKey;

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = placeholders[fieldKey] || '';
    select.appendChild(placeholder);

    options.forEach(option => {
      if (!option || !option.key) {
        return;
      }
      const opt = document.createElement('option');
      opt.value = option.key;
      opt.textContent = option.label || option.key;
      select.appendChild(opt);
    });

    const customOpt = document.createElement('option');
    customOpt.value = '__custom__';
    customOpt.textContent = strings.customLabel || 'Custom meta key…';
    select.appendChild(customOpt);

    label.appendChild(select);

    const input = document.createElement('input');
    input.type = 'text';
    input.name = `agent[field_maps][${postType}][${fieldKey}]`;
    input.className = 'regular-text agentos-map-input';
    input.placeholder = strings.customPlaceholder || '';
    input.value = existingValue || '';
    label.appendChild(input);

    applyInitialSelection(select, input, options, existingValue);

    select.addEventListener('change', () => {
      handleSelectChange(select, input);
      logInfo('Field mapping changed', {
        postType,
        fieldKey,
        selection: select.value,
        input: input.value
      });
    });
    input.addEventListener('input', () => {
      handleInputChange(select, input);
      logInfo('Field mapping input', {
        postType,
        fieldKey,
        value: input.value
      });
    });

    return wrapper;
  }

  function applyInitialSelection(select, input, options, value) {
    const hasMatch = value && options.some(option => option.key === value);
    if (hasMatch) {
      select.value = value;
      setInputReadonly(input, true);
      return;
    }
    if (value) {
      select.value = '__custom__';
      setInputReadonly(input, false);
      return;
    }
    select.value = '';
    input.value = '';
    setInputReadonly(input, false);
  }

  function handleSelectChange(select, input) {
    if (select.value === '__custom__') {
      setInputReadonly(input, false);
      input.focus();
      return;
    }
    if (select.value) {
      input.value = select.value;
      setInputReadonly(input, true);
      return;
    }
    input.value = '';
    setInputReadonly(input, false);
  }

  function handleInputChange(select, input) {
    if (select.value === '__custom__') {
      return;
    }
    if (!input.readOnly) {
      return;
    }
    // If the user broke readonly via dev tools, keep select in sync.
    select.value = '__custom__';
    setInputReadonly(input, false);
  }

  function setInputReadonly(input, isReadonly) {
    input.readOnly = !!isReadonly;
    if (isReadonly) {
      input.classList.add('agentos-map-input--readonly');
    } else {
      input.classList.remove('agentos-map-input--readonly');
    }
  }

  function injectStyles() {
    const styleId = 'agentos-admin-inline-css';
    if (document.getElementById(styleId)) {
      return;
    }
    const style = document.createElement('style');
    style.id = styleId;
    style.textContent = `
      .agentos-map-fieldset {
        border:1px solid #ddd;
        padding:12px;
        margin:12px 0;
        border-radius:8px;
        background:#fff;
      }
      .agentos-map-row + .agentos-map-row {
        margin-top:12px;
      }
      .agentos-map-label {
        display:flex;
        flex-direction:column;
        gap:4px;
      }
      .agentos-map-select {
        max-width:260px;
      }
      .agentos-map-input {
        max-width:260px;
      }
      .agentos-map-input--readonly {
        background:#f6f7f7;
        color:#555;
      }
    `;
    document.head.appendChild(style);
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
})();
