import { uiLoader } from "../../vendor/helpers.pbb.ph/js/ui/ui.loader.js";

uiLoader.setPreferBundles(true);

const STEPS = [
  { id: "welcome", title: "Welcome", description: "Choose install mode and understand the installer flow." },
  { id: "checks", title: "Environment checks", description: "Validate PHP, filesystem, database, and Realtime prerequisites." },
  { id: "config", title: "Configuration", description: "Capture app, DB, Realtime, and admin bootstrap values." },
  { id: "review", title: "Review", description: "Review the config summary before running install actions." },
  { id: "install", title: "Install", description: "Run scaffolded install actions and generate an install report." },
  { id: "validate", title: "Validation", description: "Run post-install validations against the current draft setup." },
  { id: "finish", title: "Finish", description: "Review report output and next actions." },
];

const API = {
  state: "./api/state.php",
  preflight: "./api/preflight.php",
  configImport: "./api/config-import.php",
  configSave: "./api/config-save.php",
  installRun: "./api/install-run.php",
  repairRun: "./api/repair-run.php",
  validateRun: "./api/validate-run.php",
  report: "./api/report.php",
  reportDownload: "./api/report-download.php",
  logDownload: "./api/log-download.php",
  serviceArtifactDownload: "./api/service-artifact-download.php",
};

const state = {
  installer: null,
  report: null,
  loading: true,
  busy: false,
  toast: null,
  confirmDialog: null,
  errors: {},
};

const root = () => document.getElementById("installer-app");
const clone = (value) => JSON.parse(JSON.stringify(value));

function setBusy(next) {
  state.busy = Boolean(next);
  render();
}

async function fetchJson(url, options = {}) {
  const response = await fetch(url, {
    headers: { "Content-Type": "application/json", ...(options.headers || {}) },
    ...options,
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(payload.message || `Request failed: ${response.status}`);
    if (payload.errors) {
      error.errors = payload.errors;
    }
    throw error;
  }
  return payload;
}

async function loadInstallerState() {
  state.loading = true;
  render();
  const payload = await fetchJson(API.state);
  state.installer = payload.state;
  state.report = payload.report || null;
  state.loading = false;
  render();
}

function currentStep() {
  return STEPS.find((step) => step.id === state.installer?.current_step) || STEPS[0];
}

function config() {
  return state.installer?.config || {};
}

function updateDraft(path, value) {
  const next = clone(config());
  let cursor = next;
  for (let i = 0; i < path.length - 1; i += 1) {
    const key = path[i];
    cursor[key] = cursor[key] || {};
    cursor = cursor[key];
  }
  cursor[path[path.length - 1]] = value;
  state.installer.config = next;
}

async function saveDraft(step = state.installer.current_step) {
  const payload = await fetchJson(API.configSave, {
    method: "POST",
    body: JSON.stringify({ current_step: step, config: state.installer.config }),
  });
  state.installer = payload.state;
  state.errors = {};
  return payload;
}

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function escapeAttr(value) {
  return escapeHtml(value);
}

function maskSecret(value) {
  const text = String(value || "");
  if (!text) return "[missing]";
  if (text.length <= 4) return "*".repeat(text.length);
  return `${text.slice(0, 2)}${"*".repeat(Math.max(text.length - 4, 2))}${text.slice(-2)}`;
}

function reviewJson() {
  const next = clone(config());
  next.database.password = maskSecret(next.database.password);
  next.realtime.token_signing_secret = maskSecret(next.realtime.token_signing_secret);
  next.admin.password = maskSecret(next.admin.password);
  return JSON.stringify(next, null, 2);
}

function renderCheckList(items) {
  if (!items.length) {
    return `<div class="installer-help">Nothing recorded yet for this step.</div>`;
  }

  return `<div class="installer-check-list">${items.map((item) => `
    <div class="installer-check-row is-${item.status}">
      <div class="installer-check-head">
        <strong>${escapeHtml(item.label)}</strong>
        <span class="installer-check-status">${escapeHtml(item.status)}</span>
      </div>
      <div class="installer-check-message">${escapeHtml(item.message)}</div>
      <div class="installer-help">${item.blocking ? "Blocking check" : "Non-blocking check"}</div>
    </div>
  `).join("")}</div>`;
}

function renderReport(report) {
  if (!report) {
    return `<div class="installer-help">No report has been generated yet.</div>`;
  }

  return `
    <div class="installer-report-list">
      <div class="installer-report-card" style="padding:14px 16px;">
        <div class="installer-report-head">
          <strong>Summary</strong>
          <span>${escapeHtml(report.mode || "unknown")}</span>
        </div>
        <div class="installer-report-copy">${escapeHtml(report.summary || "No summary.")}</div>
      </div>
      <div class="installer-code">${escapeHtml(JSON.stringify(report, null, 2))}</div>
    </div>
  `;
}

function validationSummary() {
  const items = state.installer?.validation || [];
  const passed = items.filter((item) => item.status === "pass").length;
  const failedItems = items.filter((item) => item.status !== "pass");

  return {
    total: items.length,
    passed,
    failed: failedItems.length,
    failedItems,
  };
}

function renderAcceptanceSummary() {
  const summary = validationSummary();
  const overrideUsed = finishBypassEnabled();
  const status = summary.failed === 0 ? "pass" : (overrideUsed ? "warn" : "fail");
  const headline = summary.total === 0
    ? "Validation has not been run yet."
    : summary.failed === 0
      ? "Validation passed."
      : overrideUsed
        ? "Validation has failures, but finish override is enabled."
        : "Validation has failures. Finish should not be treated as accepted.";

  return `
    <div class="installer-check-row is-${status}">
      <div class="installer-check-head">
        <strong>Acceptance summary</strong>
        <span class="installer-check-status">${escapeHtml(status)}</span>
      </div>
      <div class="installer-check-message">${escapeHtml(headline)}</div>
      <div class="installer-help">Passed: ${summary.passed} / Failed: ${summary.failed} / Total: ${summary.total}</div>
      ${summary.failedItems.length ? `
        <ul class="installer-acceptance-list">
          ${summary.failedItems.map((item) => `<li><strong>${escapeHtml(item.label)}</strong>: ${escapeHtml(item.message || item.status)}</li>`).join("")}
        </ul>
      ` : ""}
      <div class="installer-help">Finish override used: ${overrideUsed ? "yes" : "no"}</div>
    </div>
  `;
}

function servicePreview() {
  const targetOs = config().service?.target_os || "windows";
  const serviceManager = config().service?.service_manager || (targetOs === "linux" ? "systemd" : "scheduled-task");
  const startupMode = config().service?.startup_mode || "automatic";
  const registrationMode = config().service?.registration_mode || "template";
  return `${targetOs} / ${serviceManager} / ${startupMode} / ${registrationMode}`;
}

function validationHasFailures() {
  return (state.installer?.validation || []).some((item) => item.status !== "pass");
}

function finishBypassEnabled() {
  return Boolean(config().service?.allow_finish_with_failed_validation);
}

function stepContent() {
  const step = currentStep();
  const draft = config();

  if (step.id === "welcome") {
    return `
      <h2 class="installer-panel-title">Choose install mode</h2>
      <p class="installer-panel-copy">The installer now supports a cross-platform deployment contract. Windows and Linux use the same browser workflow, but service registration artifacts are generated per target OS and service manager.</p>
      <div class="installer-form-grid">
        <div class="installer-field">
          <label for="installer-mode">Install mode</label>
          <select id="installer-mode">
            <option value="fresh" ${draft.mode === "fresh" ? "selected" : ""}>Fresh install</option>
            <option value="upgrade" ${draft.mode === "upgrade" ? "selected" : ""}>Upgrade</option>
            <option value="repair" ${draft.mode === "repair" ? "selected" : ""}>Repair</option>
          </select>
        </div>
        <div class="installer-field">
          <label for="installer-import-json">Import config JSON</label>
          <textarea id="installer-import-json" placeholder='{"mode":"fresh","app":{"app_url":"https://realtime.hub-a.pbb.ph"}}'></textarea>
        </div>
      </div>
      <div class="installer-actions">
        <button class="installer-button" data-action="save-welcome">Save draft</button>
        <button class="installer-button is-secondary" data-action="import-config">Import config</button>
        <button class="installer-button is-secondary" data-action="goto-checks">Continue to checks</button>
      </div>
      ${state.installer.completion_marker?.installed_at ? `<div class="installer-help" style="margin-top:14px;">Existing completion marker found from ${escapeHtml(state.installer.completion_marker.installed_at)}. Fresh install should not be re-run without intent.</div>` : ""}
    `;
  }

  if (step.id === "checks") {
    return `
      <h2 class="installer-panel-title">Environment checks</h2>
      <p class="installer-panel-copy">The scaffold already runs actual checks against PHP, extensions, filesystem writeability, DB connectivity, websocket port availability, and token secret sanity.</p>
      ${renderCheckList(state.installer.preflight || [])}
      <div class="installer-actions">
        <button class="installer-button" data-action="run-preflight">Run checks</button>
        <button class="installer-button is-secondary" data-action="goto-config">Continue to configuration</button>
      </div>
    `;
  }

  if (step.id === "config") {
    const targetOs = draft.service?.target_os === "linux" ? "linux" : "windows";
    const serviceManager = draft.service?.service_manager || (targetOs === "linux" ? "systemd" : "scheduled-task");
    return `
      <h2 class="installer-panel-title">Configuration</h2>
      <p class="installer-panel-copy">This draft form already persists the deployment contract the final installer will write.</p>
      <div class="installer-form-grid">
        <div class="installer-field-row">
          <div class="installer-field">
            <label for="app-install-path">Install path</label>
            <input id="app-install-path" type="text" value="${escapeAttr(draft.app.install_path)}">
          </div>
          <div class="installer-field">
            <label for="app-url">APP_URL</label>
            <input id="app-url" type="text" value="${escapeAttr(draft.app.app_url)}">
          </div>
        </div>
        <div class="installer-field-row">
          <div class="installer-field">
            <label for="db-host">DB host</label>
            <input id="db-host" type="text" value="${escapeAttr(draft.database.host)}">
          </div>
          <div class="installer-field">
            <label for="db-database">DB database</label>
            <input id="db-database" type="text" value="${escapeAttr(draft.database.database)}">
          </div>
        </div>
        <div class="installer-field-row">
          <div class="installer-field">
            <label for="db-username">DB username</label>
            <input id="db-username" type="text" value="${escapeAttr(draft.database.username)}">
          </div>
          <div class="installer-field">
            <label for="db-password">DB password</label>
            <input id="db-password" type="password" value="${escapeAttr(draft.database.password)}">
          </div>
        </div>
        <div class="installer-field-row">
          <div class="installer-field">
            <label for="app-run-seeders">Optional seed/bootstrap actions</label>
            <select id="app-run-seeders">
              <option value="false" ${!draft.app?.run_seeders ? "selected" : ""}>Do not run seeders</option>
              <option value="true" ${draft.app?.run_seeders ? "selected" : ""}>Run optional seeders</option>
            </select>
          </div>
          <div class="installer-field">
            <label for="app-seed-command">Seeder command</label>
            <input id="app-seed-command" type="text" value="${escapeAttr(draft.app?.seed_command || "db:seed --force")}">
          </div>
        </div>
        <div class="installer-field-row">
          <div class="installer-field">
            <label for="rt-service-name">Realtime service name</label>
            <input id="rt-service-name" type="text" value="${escapeAttr(draft.realtime.service_name)}">
          </div>
          <div class="installer-field">
            <label for="rt-token-audience">Token audience</label>
            <input id="rt-token-audience" type="text" value="${escapeAttr(draft.realtime.token_audience)}">
          </div>
        </div>
        <div class="installer-field-row">
          <div class="installer-field">
            <label for="rt-secret">Token signing secret</label>
            <input id="rt-secret" type="password" value="${escapeAttr(draft.realtime.token_signing_secret)}">
          </div>
          <div class="installer-field">
            <label for="rt-trusted-issuers">Trusted issuers</label>
            <input id="rt-trusted-issuers" type="text" value="${escapeAttr(draft.realtime.trusted_issuers)}">
          </div>
        </div>
        <div class="installer-field-row">
          <div class="installer-field">
            <label for="rt-public-ws-url">Public websocket URL</label>
            <input id="rt-public-ws-url" type="text" value="${escapeAttr(draft.realtime.public_websocket_url)}">
          </div>
          <div class="installer-field">
            <label for="rt-port">Websocket port</label>
            <input id="rt-port" type="number" value="${escapeAttr(draft.realtime.ws_port)}">
          </div>
        </div>
        <div class="installer-field-row">
          <div class="installer-field">
            <label for="admin-name">Admin name</label>
            <input id="admin-name" type="text" value="${escapeAttr(draft.admin.name)}">
          </div>
          <div class="installer-field">
            <label for="admin-email">Admin email</label>
            <input id="admin-email" type="email" value="${escapeAttr(draft.admin.email)}">
          </div>
        </div>
        <div class="installer-field">
          <label for="admin-password">Admin password</label>
          <input id="admin-password" type="password" value="${escapeAttr(draft.admin.password)}">
        </div>
        <div class="installer-field-row">
          <div class="installer-field">
            <label for="service-target-os">Target OS</label>
            <select id="service-target-os">
              <option value="windows" ${targetOs === "windows" ? "selected" : ""}>Windows</option>
              <option value="linux" ${targetOs === "linux" ? "selected" : ""}>Linux</option>
            </select>
          </div>
          <div class="installer-field">
            <label for="service-manager">Service manager</label>
            <select id="service-manager">
              ${targetOs === "linux" ? `
                <option value="systemd" ${serviceManager === "systemd" ? "selected" : ""}>systemd</option>
              ` : `
                <option value="scheduled-task" ${serviceManager === "scheduled-task" ? "selected" : ""}>Scheduled startup task</option>
                <option value="windows-service" ${serviceManager === "windows-service" ? "selected" : ""}>Windows service wrapper</option>
              `}
            </select>
          </div>
        </div>
        <div class="installer-field-row">
          <div class="installer-field">
            <label for="service-startup-mode">Startup mode</label>
            <select id="service-startup-mode">
              <option value="automatic" ${draft.service?.startup_mode === "automatic" ? "selected" : ""}>Automatic</option>
              <option value="manual" ${draft.service?.startup_mode === "manual" ? "selected" : ""}>Manual</option>
            </select>
          </div>
          <div class="installer-field">
            <label for="service-registration-mode">Service registration</label>
            <select id="service-registration-mode">
              <option value="template" ${draft.service?.registration_mode === "template" ? "selected" : ""}>Generate template</option>
              <option value="register" ${draft.service?.registration_mode === "register" ? "selected" : ""}>Register on this host</option>
            </select>
          </div>
          <div class="installer-field">
            <label for="service-allow-existing-install">Existing install override</label>
            <select id="service-allow-existing-install">
              <option value="false" ${!draft.service?.allow_existing_install ? "selected" : ""}>Do not allow fresh rerun</option>
              <option value="true" ${draft.service?.allow_existing_install ? "selected" : ""}>Allow fresh rerun</option>
            </select>
          </div>
        </div>
      </div>
      <div class="installer-actions">
        <button class="installer-button" data-action="save-config">Save configuration</button>
        <button class="installer-button is-secondary" data-action="goto-review">Continue to review</button>
      </div>
      ${renderErrors()}
    `;
  }

  if (step.id === "review") {
    return `
      <h2 class="installer-panel-title">Review</h2>
      <p class="installer-panel-copy">The current draft is persisted server-side. Secrets are masked here.</p>
      <div class="installer-code">${escapeHtml(reviewJson())}</div>
      <div class="installer-help" style="margin-top:12px;">Service registration path: ${escapeHtml(servicePreview())}</div>
      <div class="installer-actions">
        <button class="installer-button" data-action="save-config">Save draft</button>
        <button class="installer-button is-secondary" data-action="goto-install">Continue to install</button>
      </div>
    `;
  }

  if (step.id === "install") {
    const mode = draft.mode || "fresh";
    const primaryActionLabel = mode === "repair" ? "Run repair actions" : "Run installer";
    const modeCopy = mode === "repair"
      ? "Repair mode now runs targeted fixes for missing APP_KEY, pending migrations, missing admin bootstrap, and service artifact generation. It does not replace a full upgrade flow."
      : "Install mode writes the target .env, generates APP_KEY when needed, runs database migrations, bootstraps the initial admin, generates the OS-specific service artifact, and writes the install manifest.";
    return `
      <h2 class="installer-panel-title">${mode === "repair" ? "Repair runtime" : "Install runtime"}</h2>
      <p class="installer-panel-copy">${modeCopy}</p>
      <div class="installer-actions">
        <button class="installer-button" data-action="${mode === "repair" ? "run-repair" : "run-install"}">${primaryActionLabel}</button>
        <button class="installer-button is-secondary" data-action="goto-validate">Continue to validation</button>
      </div>
      ${mode === "upgrade" ? `<div class="installer-help" style="margin-top:16px;">Upgrade mode will back up installer-managed release files and generated artifacts before re-running migrations and writing refreshed runtime config.</div>` : ""}
      <div class="installer-help" style="margin-top:16px;">Install log</div>
      <div class="installer-log">${escapeHtml(state.installer.install?.log || "")}</div>
    `;
  }

  if (step.id === "validate") {
    const hasFailures = validationHasFailures();
    return `
      <h2 class="installer-panel-title">Validation</h2>
      <p class="installer-panel-copy">Validation checks installed-state health, pending migrations, admin account presence, service artifact generation, HTTP endpoints, and websocket bind target reachability.</p>
      ${renderCheckList(state.installer.validation || [])}
      ${hasFailures ? `
        <div class="installer-check-row is-warn" style="margin-top:16px;">
          <div class="installer-check-head">
            <strong>Acceptance lock</strong>
            <span class="installer-check-status">warn</span>
          </div>
          <div class="installer-check-message">Validation still has failing checks. Finish remains locked until the operator explicitly overrides it.</div>
          <label class="installer-help" style="display:block;margin-top:10px;">
            <input id="service-allow-finish-with-failed-validation" type="checkbox" ${finishBypassEnabled() ? "checked" : ""}>
            Allow finish despite current validation failures
          </label>
        </div>
      ` : ""}
      <div class="installer-actions">
        <button class="installer-button" data-action="run-validation">Run validation</button>
        <button class="installer-button is-secondary" data-action="goto-finish">Continue to finish</button>
      </div>
    `;
  }

  return `
    <h2 class="installer-panel-title">Finish</h2>
    <p class="installer-panel-copy">The report and scaffold artifacts are now available. This is where the full installer will also surface go-live manual tasks.</p>
    ${renderAcceptanceSummary()}
    ${renderReport(state.report)}
    ${state.report?.service ? `<div class="installer-help" style="margin-top:12px;">Generated service template target: ${escapeHtml(state.report.service.target_os || "")}</div>` : ""}
    <div class="installer-actions">
      <button class="installer-button" data-action="refresh-report">Refresh report</button>
      <button class="installer-button is-secondary" data-download-url="${escapeAttr(API.reportDownload)}">Download report JSON</button>
      <button class="installer-button is-secondary" data-download-url="${escapeAttr(API.logDownload)}">Download install log</button>
      <button class="installer-button is-secondary" data-download-url="${escapeAttr(API.serviceArtifactDownload)}">Download service artifact</button>
    </div>
  `;
}

function renderAside() {
  const step = currentStep();
  return `
    <div class="installer-aside-section">
      <div class="installer-aside-block">
        <h3>${escapeHtml(step.title)}</h3>
        <p>${escapeHtml(step.description)}</p>
      </div>
      <div class="installer-aside-block">
        <h3>Scaffold status</h3>
        <ul>
          <li>Browser shell: active</li>
          <li>State persistence: active</li>
          <li>Preflight checks: active</li>
          <li>.env writing: active</li>
          <li>APP_KEY generation: active</li>
          <li>Database migrations: active</li>
          <li>Optional seeders: active</li>
          <li>Admin bootstrap: active</li>
          <li>Repair actions: active</li>
          <li>OS-specific service artifacts: active</li>
          <li>Runtime registration: active</li>
          <li>Manifest writing: active</li>
        </ul>
      </div>
      <div class="installer-aside-block">
        <h3>Installer artifacts</h3>
        <ul>
          <li><code>storage/app/installer/state.json</code></li>
          <li><code>storage/app/installer/install.log</code></li>
          <li><code>storage/app/installer/install-report.json</code></li>
          <li><code>storage/app/installer/install-manifest.json</code></li>
          <li><code>storage/app/installer/completed.json</code></li>
        </ul>
      </div>
    </div>
  `;
}

function renderErrors() {
  const entries = Object.entries(state.errors || {});
  if (!entries.length) {
    return "";
  }

  return `<div class="installer-check-list" style="margin-top:16px;">${entries.map(([key, value]) => `
    <div class="installer-check-row is-fail">
      <div class="installer-check-head">
        <strong>${escapeHtml(key)}</strong>
        <span class="installer-check-status">fail</span>
      </div>
      <div class="installer-check-message">${escapeHtml(value)}</div>
    </div>
  `).join("")}</div>`;
}

function render() {
  if (!root()) return;
  if (state.loading || !state.installer) {
    root().innerHTML = `<div class="installer-shell"><section class="installer-hero"><div class="installer-eyebrow">Installer</div><h1 class="installer-title">Loading installer scaffold…</h1></section></div>`;
    return;
  }

  root().innerHTML = `
    <div class="installer-shell">
      <section class="installer-hero">
        <div class="installer-hero-row">
          <div>
            <div class="installer-eyebrow">PBB Realtime installer</div>
            <h1 class="installer-title">Hub deployment installer scaffold</h1>
            <p class="installer-subtitle">Browser-run PHP installer for hub-local PBB Realtime deployments. This scaffold already handles state, preflight, reporting, and the operator step flow.</p>
          </div>
          <div class="installer-actions">
            <button class="installer-button is-secondary" data-action="refresh-report">Refresh report</button>
          </div>
        </div>
        <div class="installer-summary">
          <div class="installer-summary-card"><strong>${escapeHtml(config().mode)}</strong><span>Selected install mode</span></div>
          <div class="installer-summary-card"><strong>${escapeHtml(state.installer.current_step)}</strong><span>Current step</span></div>
          <div class="installer-summary-card"><strong>${state.installer.preflight.length}</strong><span>Preflight checks recorded</span></div>
          <div class="installer-summary-card"><strong>${state.installer.completed_steps.length}</strong><span>Completed steps</span></div>
        </div>
      </section>
      <section class="installer-body">
        <aside class="installer-panel">
          <div class="installer-eyebrow">Steps</div>
          <div class="installer-step-list">
            ${STEPS.map((step) => `
              <button class="installer-step-button ${step.id === state.installer.current_step ? "is-active" : ""}" data-step="${step.id}" ${state.busy ? "disabled" : ""}>
                ${escapeHtml(step.title)}
                <small>${escapeHtml(step.description)}</small>
              </button>
            `).join("")}
          </div>
        </aside>
        <main class="installer-panel">${stepContent()}</main>
        <aside class="installer-panel">${renderAside()}</aside>
      </section>
    </div>
  `;

  bindEvents();
}

function readConfigForm() {
  const finishBypass = document.getElementById("service-allow-finish-with-failed-validation");
  if (finishBypass) {
    updateDraft(["service", "allow_finish_with_failed_validation"], finishBypass.checked);
  }

  if (currentStep().id !== "config") return;
  updateDraft(["app", "install_path"], document.getElementById("app-install-path")?.value || "");
  updateDraft(["app", "app_url"], document.getElementById("app-url")?.value || "");
  updateDraft(["database", "host"], document.getElementById("db-host")?.value || "");
  updateDraft(["database", "database"], document.getElementById("db-database")?.value || "");
  updateDraft(["database", "username"], document.getElementById("db-username")?.value || "");
  updateDraft(["database", "password"], document.getElementById("db-password")?.value || "");
  updateDraft(["app", "run_seeders"], (document.getElementById("app-run-seeders")?.value || "false") === "true");
  updateDraft(["app", "seed_command"], document.getElementById("app-seed-command")?.value || "db:seed --force");
  updateDraft(["realtime", "service_name"], document.getElementById("rt-service-name")?.value || "");
  updateDraft(["realtime", "token_audience"], document.getElementById("rt-token-audience")?.value || "");
  updateDraft(["realtime", "token_signing_secret"], document.getElementById("rt-secret")?.value || "");
  updateDraft(["realtime", "trusted_issuers"], document.getElementById("rt-trusted-issuers")?.value || "");
  updateDraft(["realtime", "public_websocket_url"], document.getElementById("rt-public-ws-url")?.value || "");
  updateDraft(["realtime", "ws_port"], Number(document.getElementById("rt-port")?.value || 8080));
  updateDraft(["admin", "name"], document.getElementById("admin-name")?.value || "");
  updateDraft(["admin", "email"], document.getElementById("admin-email")?.value || "");
  updateDraft(["admin", "password"], document.getElementById("admin-password")?.value || "");
  const targetOs = document.getElementById("service-target-os")?.value || "windows";
  updateDraft(["service", "target_os"], targetOs);
  updateDraft(["service", "service_manager"], document.getElementById("service-manager")?.value || (targetOs === "linux" ? "systemd" : "scheduled-task"));
  updateDraft(["service", "startup_mode"], document.getElementById("service-startup-mode")?.value || "automatic");
  updateDraft(["service", "registration_mode"], document.getElementById("service-registration-mode")?.value || "template");
  updateDraft(["service", "allow_existing_install"], (document.getElementById("service-allow-existing-install")?.value || "false") === "true");
}

function bindEvents() {
  const serviceTargetSelect = document.getElementById("service-target-os");
  if (serviceTargetSelect) {
    serviceTargetSelect.addEventListener("change", () => {
      readConfigForm();
      render();
    });
  }

  const finishBypassToggle = document.getElementById("service-allow-finish-with-failed-validation");
  if (finishBypassToggle) {
    finishBypassToggle.addEventListener("change", () => {
      readConfigForm();
      render();
    });
  }

  document.querySelectorAll("[data-step]").forEach((element) => {
    element.addEventListener("click", async () => {
      if (state.busy) return;
      if (element.dataset.step === "finish" && validationHasFailures() && !finishBypassEnabled()) {
        state.toast.show("Validation still has failures. Finish is locked until you acknowledge the override.", { type: "warn", title: "Installer" });
        return;
      }
      state.installer.current_step = element.dataset.step;
      await saveDraft(element.dataset.step);
      render();
    });
  });

  document.querySelectorAll("[data-action]").forEach((element) => {
    element.addEventListener("click", async () => {
      const action = element.dataset.action;
      try {
        if (action === "save-welcome") {
          updateDraft(["mode"], document.getElementById("installer-mode")?.value || "fresh");
          setBusy(true); await saveDraft(); state.toast.show("Draft saved.", { type: "success", title: "Installer" }); return;
        }
        if (action === "import-config") {
          const payload = document.getElementById("installer-import-json")?.value || "";
          if (!payload.trim()) {
            state.toast.show("Paste JSON first.", { type: "warn", title: "Import" });
            return;
          }
          setBusy(true);
          const result = await fetchJson(API.configImport, { method: "POST", body: JSON.stringify({ config: payload }) });
          state.installer = result.state;
          state.errors = {};
          state.toast.show("Config imported.", { type: "success", title: "Installer" });
          return;
        }
        if (action === "goto-checks") {
          updateDraft(["mode"], document.getElementById("installer-mode")?.value || "fresh");
          setBusy(true); await saveDraft("checks"); state.installer.current_step = "checks"; return;
        }
        if (action === "run-preflight") {
          setBusy(true);
          const payload = await fetchJson(API.preflight, { method: "POST", body: JSON.stringify({ config: state.installer.config }) });
          state.installer = payload.state;
          state.toast.show("Checks completed.", { type: "success", title: "Preflight" });
          return;
        }
        if (action === "goto-config") { state.installer.current_step = "config"; setBusy(true); await saveDraft("config"); return; }
        if (action === "save-config") { readConfigForm(); setBusy(true); await saveDraft(); state.toast.show("Configuration saved.", { type: "success", title: "Installer" }); return; }
        if (action === "goto-review") { readConfigForm(); setBusy(true); await saveDraft("review"); state.installer.current_step = "review"; return; }
        if (action === "goto-install") { state.installer.current_step = "install"; setBusy(true); await saveDraft("install"); return; }
        if (action === "run-install") {
          const mode = state.installer.config?.mode || "fresh";
          const confirmed = await state.confirmDialog({
            title: `Run ${mode} installer?`,
            message: mode === "upgrade"
              ? "Upgrade mode will back up installer-managed release files, preserve the current environment as the base, re-run migrations, refresh the admin bootstrap, regenerate the OS-specific service artifact, and update the install manifest."
              : "This installer already writes the target .env, generates APP_KEY when needed, runs database migrations, bootstraps the initial admin, generates the OS-specific service artifact, and writes the install manifest. Final runtime registration is still an operator step.",
            confirmLabel: "Run installer",
            cancelLabel: "Cancel",
          });
          if (!confirmed) return;
          setBusy(true);
          const payload = await fetchJson(API.installRun, { method: "POST", body: JSON.stringify({ config: state.installer.config }) });
          state.installer = payload.state;
          state.report = payload.report || null;
          state.toast.show(mode === "upgrade"
            ? "Upgrade backup, migrations, runtime config refresh, and service artifact generation completed."
            : "Environment, migrations, admin bootstrap, and service artifact generation completed.", { type: "success", title: "Installer" });
          return;
        }
        if (action === "run-repair") {
          const confirmed = await state.confirmDialog({
            title: "Run repair actions?",
            message: "Repair mode will detect and fix missing APP_KEY, pending migrations, missing admin bootstrap, and missing service artifact output using the current draft configuration.",
            confirmLabel: "Run repair",
            cancelLabel: "Cancel",
          });
          if (!confirmed) return;
          setBusy(true);
          const payload = await fetchJson(API.repairRun, { method: "POST", body: JSON.stringify({ config: state.installer.config }) });
          state.installer = payload.state;
          state.report = payload.report || null;
          state.toast.show(payload.message || "Repair actions completed.", { type: "success", title: "Repair" });
          return;
        }
        if (action === "goto-validate") { state.installer.current_step = "validate"; setBusy(true); await saveDraft("validate"); return; }
        if (action === "run-validation") {
          setBusy(true);
          const payload = await fetchJson(API.validateRun, { method: "POST", body: JSON.stringify({ config: state.installer.config }) });
          state.installer = payload.state;
          state.report = payload.report || null;
          state.toast.show("Validation completed.", { type: "success", title: "Validation" });
          return;
        }
        if (action === "goto-finish") {
          readConfigForm();
          if (validationHasFailures() && !finishBypassEnabled()) {
            state.toast.show("Validation still has failures. Finish is locked until you acknowledge the override.", { type: "warn", title: "Installer" });
            return;
          }
          state.installer.current_step = "finish"; setBusy(true); await saveDraft("finish"); return;
        }
        if (action === "refresh-report") {
          setBusy(true);
          const payload = await fetchJson(API.report);
          state.report = payload.report || null;
          state.toast.show("Report refreshed.", { type: "success", title: "Report" });
          return;
        }
      } catch (error) {
        if (error && typeof error === "object" && error.errors) {
          state.errors = error.errors;
        }
        state.toast.show(error.message, { type: "error", title: "Installer error" });
      } finally {
        setBusy(false);
        render();
      }
    });
  });

  document.querySelectorAll("[data-download-url]").forEach((element) => {
    element.addEventListener("click", () => {
      if (state.busy) return;
      const url = element.getAttribute("data-download-url");
      if (!url) return;
      window.location.href = url;
    });
  });
}

async function boot() {
  await uiLoader.loadMany(["ui.toast", "ui.dialog.confirm"]);
  const toastFactory = await uiLoader.get("ui.toast");
  state.confirmDialog = await uiLoader.get("ui.dialog.confirm");
  state.toast = toastFactory({ position: "top-right" });
  await loadInstallerState();
}

boot();
