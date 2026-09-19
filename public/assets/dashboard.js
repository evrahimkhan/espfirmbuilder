// OAuth providers can return some mobile browsers with a stale desktop layout
// viewport. Perform one clean same-origin reload after removing the callback
// marker so the browser reparses the viewport meta tag normally. Do not resize
// the interface with CSS zoom because that conflicts with accessibility scaling.
const oauthReturn = new URLSearchParams(location.search).get("oauth_return");
if (oauthReturn === "1") {
  history.replaceState(null, "", location.pathname + location.hash);
  location.reload();
}
let csrf = "",
  repos = [],
  loader = null,
  transport = null;
const $ = (s) => document.querySelector(s),
  $$ = (s) => document.querySelectorAll(s);
const tabNames = ["overview", "repos", "builds", "flash", "settings"];
function tab(name, remember = true) {
  if (!tabNames.includes(name)) name = "overview";
  $$(".tab").forEach((x) => x.classList.toggle("active", x.id === name));
  $$("aside nav button").forEach((x) =>
    x.classList.toggle("active", x.dataset.tab === name),
  );
  $("#title").textContent =
    {
      overview: "Workspace overview",
      repos: "Repositories",
      builds: "Live Builds",
      flash: "Web Flasher",
      settings: "Settings",
    }[name] || "ESPForge";
  if (remember) {
    localStorage.setItem("espforge-active-tab", name);
    history.replaceState(null, "", "#" + name);
  }
}
$$("[data-tab]").forEach((b) => (b.onclick = () => tab(b.dataset.tab)));
$("#new-project").onclick = () => tab("repos");
const requestedTab =
  location.hash.slice(1) ||
  localStorage.getItem("espforge-active-tab") ||
  "overview";
tab(requestedTab);
window.addEventListener("hashchange", () => tab(location.hash.slice(1), false));
const oauthError = new URLSearchParams(location.search).get("oauth_error");
if (oauthError) {
  history.replaceState(null, "", location.pathname + "#settings");
  let previous = {};
  try {
    previous = JSON.parse(
      sessionStorage.getItem("espforge-oauth-error-shown") || "{}",
    );
  } catch (_) {}
  const repeated = previous.message === oauthError;
  if (!repeated) {
    sessionStorage.setItem(
      "espforge-oauth-error-shown",
      JSON.stringify({ message: oauthError, time: Date.now() }),
    );
    queueMicrotask(() => notify(oauthError, "Account connection failed"));
  }
}
async function logout(button) {
  button.disabled = true;
  try {
    await api("api/auth.php?action=logout", { method: "POST", body: "{}" });
    localStorage.removeItem("espforge-active-tab");
    location.href = "index.html";
  } catch (error) {
    button.disabled = false;
    await notify(error.message, "Logout failed");
  }
}
$("#logout-button").onclick = () => logout($("#logout-button"));
$("#mobile-logout").onclick = () => logout($("#mobile-logout"));
$$("[data-oauth]").forEach(
  (button) =>
    (button.onclick = async () => {
      button.disabled = true;
      sessionStorage.removeItem("espforge-oauth-error-shown");
      try {
        const query = new URLSearchParams({ action: button.dataset.oauth });
        if (button.dataset.capability)
          query.set("capability", button.dataset.capability);
        const result = await api(`api/auth.php?${query}`, {
          method: "POST",
          body: "{}",
        });
        location.assign(result.authorization_url);
      } catch (error) {
        button.disabled = false;
        await notify(error.message, "Account connection failed");
      }
    }),
);
async function api(url, opt = {}) {
  opt.headers = {
    ...(opt.headers || {}),
    "Content-Type": "application/json",
    "X-CSRF-Token": csrf,
  };
  const r = await fetch(url, opt),
    raw = await r.text();
  let d;
  try {
    d = raw ? JSON.parse(raw) : {};
  } catch (_) {
    const message = raw
      .replace(/<[^>]*>/g, " ")
      .replace(/\s+/g, " ")
      .trim();
    throw Error(
      message || `Server returned an invalid response (HTTP ${r.status}).`,
    );
  }
  if (r.status === 401) {
    const error = Error("Your session expired. Please sign in again.");
    error.code = "auth_required";
    location.replace(
      "index.html?auth_message=Your%20session%20expired.%20Please%20sign%20in%20again.",
    );
    throw error;
  }
  if (!r.ok) {
    const reference = d.request_id ? ` (reference ${d.request_id})` : "";
    throw Error((d.error || "Request failed") + reference);
  }
  return d;
}
function escapeHtml(s) {
  const e = document.createElement("div");
  e.textContent = s ?? "";
  return e.innerHTML;
}
let dialogResolve = null,
  dialogInvoker = null;
function showDialog(
  message,
  {
    title = "ESPForge",
    confirmText = "OK",
    cancel = false,
    danger = false,
  } = {},
) {
  const dialog = $("#app-dialog");
  if (dialog.open) closeDialog(false);
  dialogInvoker = document.activeElement;
  $("#app-dialog-title").textContent = title;
  $("#app-dialog-message").textContent = message;
  $("#app-dialog-icon").textContent = danger ? "!" : "✓";
  $("#app-dialog-icon").className =
    "app-dialog-icon " + (danger ? "danger" : "info");
  const confirmButton = $("#app-dialog-confirm");
  confirmButton.textContent = confirmText;
  confirmButton.className = danger ? "primary dialog-danger" : "primary";
  dialog
    .querySelectorAll("[data-dialog-cancel]")
    .forEach((button) => (button.hidden = !cancel));
  dialog.showModal();
  queueMicrotask(() => confirmButton.focus());
  return new Promise((resolve) => {
    dialogResolve = resolve;
  });
}
function closeDialog(result) {
  const dialog = $("#app-dialog");
  if (dialog.open) dialog.close();
  dialogResolve?.(result);
  dialogResolve = null;
  if (dialogInvoker instanceof HTMLElement) dialogInvoker.focus();
  dialogInvoker = null;
}
$("#app-dialog-confirm").onclick = () => closeDialog(true);
$("#app-dialog")
  .querySelectorAll("[data-dialog-cancel],[data-dialog-close]")
  .forEach((button) => (button.onclick = () => closeDialog(false)));
$("#app-dialog").addEventListener("cancel", (event) => {
  event.preventDefault();
  closeDialog(false);
});
const notify = (message, title = "ESPForge") =>
  showDialog(message, {
    title,
    danger:
      /failed|failure|error|unable|unavailable|stopped|expired|could not|unexpected/i.test(
        title + " " + message,
      ),
  });
const ask = (message, title = "Please confirm") =>
  showDialog(message, {
    title,
    confirmText: "Continue",
    cancel: true,
    danger: true,
  });
async function load() {
  const session = await api("api/auth.php?action=session");
  csrf = session.csrf;
  if (!session.user) {
    location.href = "index.html";
    return;
  }
  $("#username").textContent = session.user.name;
  $("#email").textContent = session.user.email;
  $("#avatar").textContent = session.user.name[0].toUpperCase();
  const requests = [
    api("api/settings.php"),
    api("api/projects.php"),
    api("api/builds.php?refresh=1"),
    api("api/flashes.php"),
  ];
  const [settingsResult, projectsResult, buildsResult, flashesResult] =
    await Promise.allSettled(requests);
  if (settingsResult.status === "fulfilled") {
    const s = settingsResult.value.settings,
      github = $("#github-status"),
      deletion = $("#github-delete-status");
    github.className =
      "connection-status " +
      (s.github_connected ? "connected" : "disconnected");
    github.querySelector("small").textContent =
      (s.github_status_message ||
        (s.github_connected
          ? "GitHub repository and workflow access is connected."
          : "GitHub is not connected.")) +
      (s.github_verified_at
        ? ` Last verified ${new Date(s.github_verified_at.replace(" ", "T") + "Z").toLocaleString()}.`
        : "");
    deletion.className =
      "connection-status " +
      (s.github_delete_permission ? "connected" : "disconnected");
    deletion.querySelector("small").textContent = s.github_delete_permission
      ? "Permission granted. ESPForge can delete a fork when you explicitly confirm it."
      : "Permission not granted. Existing repositories and builds are unaffected.";
    $("#github-connect-button").textContent = s.github_connected
      ? "Refresh GitHub access"
      : "Connect GitHub access";
    $("#github-delete-button").textContent = s.github_delete_permission
      ? "Refresh delete permission"
      : "Grant fork-deletion access";
    if (s.ai_provider)
      $("#settings-form [name=ai_provider]").value = s.ai_provider;
    const keyStatus = $("#api-key-status");
    if (keyStatus && !keyStatus.dataset.checked)
      keyStatus.textContent = s.ai_key_configured
        ? "A saved API key is configured for this account."
        : "No API key is configured for this account.";
    $("#remove-ai-key").hidden = !s.ai_key_configured;
  } else {
    const github = $("#github-status");
    github.className = "connection-status disconnected";
    github.querySelector("small").textContent =
      "Connection status could not be loaded. Refresh to retry.";
  }
  if (projectsResult.status === "fulfilled") {
    repos = projectsResult.value.projects;
    renderRepos();
    $("#repos-load-status").textContent = "";
  } else {
    $("#repos-load-status").textContent =
      "Repositories could not be loaded. Refresh to retry.";
  }
  if (buildsResult.status === "fulfilled") {
    const b = buildsResult.value;
    $("#build-count").textContent = b.total ?? b.builds.length;
    $("#success-rate").textContent =
      b.success_rate == null ? "—" : `${b.success_rate}%`;
    renderBuilds(b.builds);
  } else {
    $("#build-refresh-status").textContent =
      "Build status could not be loaded. Use Refresh to retry.";
  }
  if (flashesResult.status === "fulfilled")
    $("#flash-count").textContent = flashesResult.value.monthly_total ?? 0;
}
const expandedBuilds = new Set(),
  buildJobCache = new Map(),
  buildJobUpdatedAt = new Map(),
  cancellingBuilds = new Set();
function jobDetails(jobs) {
  return (jobs || [])
    .map(
      (job) =>
        `<div class="build-job"><b>${escapeHtml(job.name)}</b>${(job.steps || []).map((step) => `<p><i class="step-state ${escapeHtml(step.conclusion || step.status)}"></i><span>${escapeHtml(step.name)}</span><small>${escapeHtml(step.conclusion || step.status)}</small></p>`).join("")}</div>`,
    )
    .join("");
}
function buildDate(value) {
  if (!value) return null;
  const date =
    typeof value === "number" || /^\d+$/.test(String(value))
      ? new Date(Number(value) * 1000)
      : new Date(String(value).replace(" ", "T"));
  return Number.isNaN(date.getTime()) ? null : date;
}
function buildDuration(start, end = null) {
  const from = buildDate(start),
    to = buildDate(end) || new Date();
  if (!from) return "00.00";
  const seconds = Math.max(0, Math.floor((to - from) / 1000)),
    minutes = Math.floor(seconds / 60);
  return `${String(minutes).padStart(2, "0")}.${String(seconds % 60).padStart(2, "0")}`;
}
window.downloadBuild = (id, kind) => {
  const form = document.createElement("form");
  form.method = "POST";
  form.action = "api/build-download.php";
  form.hidden = true;
  for (const [name, value] of Object.entries({
    build_id: String(id),
    kind,
    csrf,
  })) {
    const input = document.createElement("input");
    input.type = "hidden";
    input.name = name;
    input.value = value;
    form.appendChild(input);
  }
  document.body.appendChild(form);
  form.submit();
  form.remove();
};

function buildCard(build) {
  const state = build.conclusion || build.status || "queued",
    active = ["queued", "in_progress"].includes(build.status);
  if (!active) cancellingBuilds.delete(Number(build.id));
  const cancelling =
      cancellingBuilds.has(Number(build.id)) ||
      build.logs === "Cancellation requested.",
    duration = buildDuration(
      build.created_epoch || build.created_at,
      active ? null : build.completed_epoch || build.completed_at,
    ),
    jobs = build.jobs || buildJobCache.get(Number(build.id)) || [];
  if (build.jobs?.length) buildJobCache.set(Number(build.id), build.jobs);
  const current =
      jobs
        .flatMap((job) => job.steps || [])
        .find((step) => step.status === "in_progress")?.name ||
      (active
        ? "Waiting for the next GitHub update"
        : build.conclusion === "success"
          ? "Firmware artifact ready"
          : "Build finished with errors"),
    open = expandedBuilds.has(Number(build.id));
  const download =
    build.status === "completed"
      ? build.conclusion === "success"
        ? `<button type="button" class="build-download" data-action="download" data-id="${build.id}" data-kind="artifact">Download artifact</button>`
        : `<button type="button" class="build-download error-download" data-action="download" data-id="${build.id}" data-kind="logs">Download error log</button>`
      : "";
  return `<article class="build-card ${active ? "live" : ""}" data-build="${build.id}"><header><div><span class="build-status ${escapeHtml(state)}">${active ? "● " : ""}${escapeHtml(state)}</span><h3>${escapeHtml(build.full_name)} <small>#${build.id}</small></h3><time class="build-time" data-start="${escapeHtml(build.created_epoch || build.created_at || "")}" data-end="${escapeHtml(active ? "" : build.completed_epoch || build.completed_at || build.created_epoch || build.created_at || "")}">${active ? "Live" : "Total"} time ${duration}</time><p class="build-summary">${escapeHtml(current)}</p></div><div class="build-actions"><button type="button" data-action="build-details" data-id="${build.id}">${open ? "Minimize" : "View"}</button>${active ? `<button type="button" class="cancel-build" data-action="cancel-build" data-id="${build.id}" ${cancelling ? "disabled" : ""}>${cancelling ? "Cancelling…" : "Cancel"}</button>` : ""}${download}${build.artifact_url ? `<a href="${escapeHtml(build.artifact_url)}" target="_blank" rel="noopener">Open GitHub run</a>` : ""}</div></header><div class="build-details" ${open ? "" : "hidden"}>${jobDetails(jobs) || `<div class="build-waiting">${active ? "Waiting for GitHub runner and live steps…" : "Loading build steps…"}</div>`}</div></article>`;
}
window.toggleBuildDetails = async (id, button) => {
  id = Number(id);
  const card = button.closest(".build-card"),
    details = card.querySelector(".build-details");
  if (expandedBuilds.has(id)) {
    expandedBuilds.delete(id);
    details.hidden = true;
    button.textContent = "View";
    return;
  }
  expandedBuilds.add(id);
  details.hidden = false;
  button.textContent = "Minimize";
  if (!buildJobCache.has(id)) {
    details.innerHTML = '<div class="build-waiting">Loading build steps…</div>';
    buildJobUpdatedAt.set(id, Date.now());
    try {
      const result = await api(`api/builds.php?details=${id}`);
      buildJobCache.set(id, result.jobs || []);
      buildJobUpdatedAt.set(id, Date.now());
      details.innerHTML =
        jobDetails(result.jobs) ||
        '<div class="build-waiting">No step details are available.</div>';
    } catch (error) {
      details.innerHTML = `<div class="build-waiting error">${escapeHtml(error.message)}</div>`;
    }
  }
};
window.cancelBuild = async (id, button) => {
  if (!(await ask("Cancel this running build?", "Cancel build"))) return;
  button.disabled = true;
  button.textContent = "Cancelling…";
  try {
    await api("api/builds.php", {
      method: "POST",
      body: JSON.stringify({ action: "cancel_build", build_id: id }),
    });
    cancellingBuilds.add(Number(id));
    expandedBuilds.delete(Number(id));
    await refreshBuilds();
  } catch (error) {
    await notify(error.message, "Cancellation failed");
    button.disabled = false;
    button.textContent = "Cancel";
  }
};
let hasActiveBuilds = false,
  buildRefreshFailures = 0;
function renderBuilds(builds) {
  const live = builds.filter((build) =>
      ["queued", "in_progress"].includes(build.status),
    ),
    previous = builds.filter(
      (build) => build.status === "completed" && build.logs !== "__CLEARED__",
    );
  hasActiveBuilds = live.length > 0;
  $("#live-build-list").innerHTML = live.length
    ? live.map(buildCard).join("")
    : '<div class="terminal compact"><p><span>system</span> No build is currently running.</p></div>';
  $("#build-list").innerHTML = previous.length
    ? previous.map(buildCard).join("")
    : '<div class="terminal compact"><p><span>system</span> No previous builds or error logs.</p></div>';
}
function updateBuildTimers() {
  document.querySelectorAll(".build-time").forEach((timer) => {
    const live = !timer.dataset.end,
      duration = buildDuration(timer.dataset.start, timer.dataset.end || null);
    timer.textContent = `${live ? "Live" : "Total"} time ${duration}`;
  });
}
async function refreshExpandedJobDetails(builds) {
  const now = Date.now(),
    active = (builds || []).filter(
      (build) =>
        ["queued", "in_progress"].includes(build.status) &&
        expandedBuilds.has(Number(build.id)) &&
        now - (buildJobUpdatedAt.get(Number(build.id)) || 0) >= 5000,
    );
  await Promise.all(
    active.map(async (build) => {
      const id = Number(build.id);
      buildJobUpdatedAt.set(id, now);
      try {
        const result = await api(`api/builds.php?details=${id}`),
          jobs = result.jobs || [];
        buildJobCache.set(id, jobs);
        const details = document.querySelector(
          `.build-card[data-build="${id}"] .build-details`,
        );
        if (details && expandedBuilds.has(id))
          details.innerHTML =
            jobDetails(jobs) ||
            '<div class="build-waiting">Waiting for GitHub runner and live steps…</div>';
      } catch (error) {
        console.error(error);
      }
    }),
  );
}
let buildRefreshActive = false;
async function refreshBuilds(force = false) {
  if (buildRefreshActive) return;
  buildRefreshActive = true;
  try {
    const result = await api(
      `api/builds.php?refresh=1${force ? "&force=1" : ""}`,
    );
    $("#build-count").textContent = result.total ?? result.builds.length;
    $("#success-rate").textContent =
      result.success_rate == null ? "—" : `${result.success_rate}%`;
    renderBuilds(result.builds);
    await refreshExpandedJobDetails(result.builds);
    buildRefreshFailures = 0;
    $("#build-refresh-status").textContent = "";
  } catch (error) {
    buildRefreshFailures++;
    $("#build-refresh-status").textContent =
      `Live status is temporarily unavailable. Retrying${buildRefreshFailures > 1 ? " with a longer delay" : ""}.`;
  } finally {
    buildRefreshActive = false;
  }
}
$("#refresh-builds").onclick = () => refreshBuilds(true);
$("#clear-builds").onclick = async () => {
  if (
    !(await ask(
      "Clear previous build and error logs from this page? Build totals and GitHub Actions runs will be kept.",
      "Clear logs",
    ))
  )
    return;
  const button = $("#clear-builds");
  button.disabled = true;
  try {
    await api("api/builds.php", {
      method: "POST",
      body: JSON.stringify({ action: "clear_logs" }),
    });
    await refreshBuilds();
  } catch (error) {
    await notify(error.message, "Unable to clear logs");
  } finally {
    button.disabled = false;
  }
};
function renderRepos() {
  $("#repo-count").textContent = repos.length;
  const html = repos
    .map(
      (r) =>
        `<div class="repo-row"><i>⌘</i><div><b>${escapeHtml(r.full_name)}</b><small>${escapeHtml(r.framework || "Awaiting analysis")} · ${escapeHtml(r.status)}</small></div><div class="repo-actions"><button type="button" data-action="repository" data-operation="sync" data-id="${r.id}">Sync</button><button type="button" data-action="repository" data-operation="remove" data-id="${r.id}">Remove</button><button type="button" class="danger" data-action="repository" data-operation="delete" data-id="${r.id}">Delete fork</button><button type="button" class="primary" data-action="build" data-id="${r.id}">Build</button></div></div>`,
    )
    .join("");
  $("#repos-list").innerHTML = html;
  $("#project-list").className = repos.length ? "" : "empty";
  $("#project-list").innerHTML =
    html ||
    "<b>No repositories yet</b><span>Connect a GitHub repository to start building.</span>";
}
window.repositoryAction = async (id, action) => {
  const project = repos.find((repo) => Number(repo.id) === Number(id)),
    name = project?.full_name || "this repository";
  if (
    action === "remove" &&
    !(await ask(
      `Remove ${name} from ESPForge? The GitHub repository will be kept.`,
      "Remove repository",
    ))
  )
    return;
  if (
    action === "delete" &&
    !(await ask(
      `Permanently delete the GitHub fork ${name}? This cannot be undone.`,
      "Delete GitHub fork",
    ))
  )
    return;
  try {
    const result = await api("api/projects.php", {
      method: "POST",
      body: JSON.stringify({ action, repo_id: id }),
    });
    if (action !== "sync")
      repos = repos.filter((repo) => Number(repo.id) !== Number(id));
    renderRepos();
    if (action === "sync")
      await notify(
        result.message || "Repository synchronized.",
        "Synchronization complete",
      );
  } catch (error) {
    await notify(error.message, "Repository action failed");
  }
};
$("#sync-all-repos").onclick = async () => {
  if (!repos.length) {
    await notify("There are no repositories to synchronize.");
    return;
  }
  const button = $("#sync-all-repos");
  if (
    !(await ask(
      `Synchronize all ${repos.length} connected repositories with their upstream sources?`,
      "Sync all repositories",
    ))
  )
    return;
  button.disabled = true;
  button.textContent = "Syncing all…";
  try {
    const result = await api("api/projects.php", {
      method: "POST",
      body: JSON.stringify({ action: "sync_all" }),
    });
    let message = result.message || "Synchronization complete.";
    if (result.errors?.length) message += "\n\n" + result.errors.join("\n");
    await notify(message, "Synchronization complete");
    await load();
  } catch (error) {
    await notify(error.message, "Synchronization failed");
  } finally {
    button.disabled = false;
    button.textContent = "Sync all";
  }
};
$("#repo-form").onsubmit = async (e) => {
  e.preventDefault();
  const err = $("#repos .error"),
    button = e.submitter;
  err.textContent = "";
  button.disabled = true;
  button.textContent = "Analyzing & deploying…";
  try {
    const result = await api("api/projects.php", {
      method: "POST",
      body: JSON.stringify(Object.fromEntries(new FormData(e.target))),
    });
    e.target.reset();
    await load();
    if (result.forked_from) {
      err.style.color = "#19a878";
      err.textContent = `Forked ${result.forked_from} to ${result.full_name} and deployed the workflow.`;
    }
  } catch (x) {
    err.style.color = "";
    err.textContent = x.message;
  } finally {
    button.disabled = false;
    button.textContent = "Connect repository";
  }
};
const analysisDialog = $("#analysis-dialog"),
  analysisTerminal = $("#analysis-terminal"),
  analysisLauncher = $("#analysis-terminal-launcher"),
  analysisStatus = $("#analysis-dialog-status");
let analysisRunning = false;
function analysisLine(message, state = "info") {
  const time = new Date().toLocaleTimeString([], {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
  });
  const line = document.createElement("span");
  if (state === "ok") line.className = "analysis-ok";
  line.textContent = `[${time}] ${message}\n`;
  analysisTerminal.append(line);
  analysisTerminal.scrollTop = analysisTerminal.scrollHeight;
}
function renderAnalysisProgress(events, seen) {
  for (const event of Array.isArray(events) ? events : []) {
    const key = `${event.time || 0}:${event.stage || ""}:${event.message || ""}`;
    if (seen.has(key)) continue;
    seen.add(key);
    analysisLine(
      event.message || "Analysis updated.",
      event.stage === "ai_result" ? "ok" : "info",
    );
    if (event.stage === "ai_request" && event.details?.model)
      analysisLine(
        `AI API: ${event.details.provider} / ${event.details.model}`,
      );
    for (const target of Array.isArray(event.details?.targets)
      ? event.details.targets
      : [])
      analysisLine(
        `AI result: ${target.name} · ${target.type} · ${target.source}${target.confidence === null ? "" : ` · confidence ${Math.round(target.confidence * 100)}%`}${target.evidence ? ` · evidence ${target.evidence}` : ""}`,
        "ok",
      );
  }
}
function openAnalysisTerminal(reset = false) {
  if (reset) {
    analysisTerminal.textContent = "";
    analysisStatus.textContent = "AI and deterministic checks are running…";
  }
  analysisLauncher.hidden = false;
  analysisLauncher.classList.toggle("live", analysisRunning);
  if (!analysisDialog.open) analysisDialog.showModal();
}
function minimizeAnalysisTerminal() {
  if (analysisDialog.open) analysisDialog.close();
  analysisLauncher.hidden = !analysisRunning && !analysisTerminal.textContent;
}
analysisLauncher.onclick = () => openAnalysisTerminal();
analysisDialog
  .querySelectorAll("[data-analysis-close]")
  .forEach((button) => (button.onclick = minimizeAnalysisTerminal));
analysisDialog.addEventListener("cancel", (event) => {
  event.preventDefault();
  minimizeAnalysisTerminal();
});
analysisDialog.addEventListener("click", (event) => {
  if (event.target === analysisDialog) minimizeAnalysisTerminal();
});
window.build = async (id) => {
  const triggers = [
      ...document.querySelectorAll(
        `button[data-action="build"][data-id="${id}"]`,
      ),
    ],
    labels = triggers.map((button) => button.textContent);
  triggers.forEach((button) => {
    button.disabled = true;
    button.textContent = "Finding targets…";
  });
  analysisRunning = true;
  openAnalysisTerminal(true);
  analysisLine(
    "Build request received. Resolving the immutable repository revision…",
  );
  const progressSeen = new Set();
  try {
    let result = await api(`api/targets.php?repo_id=${id}&retry=1`);
    renderAnalysisProgress(result.progress, progressSeen);
    analysisLine(
      result.commit_sha
        ? `Revision ${String(result.commit_sha).slice(0, 12)} locked for analysis.`
        : "Repository revision resolved.",
      "ok",
    );
    for (
      let attempt = 0;
      result.status === "analyzing" && attempt < 45;
      attempt++
    ) {
      triggers.forEach(
        (button) => (button.textContent = "AI analysis running…"),
      );
      if (attempt === 0)
        analysisLine(
          "Analysis queued. AI is reviewing project structure and hardware evidence…",
        );
      else if (attempt % 5 === 0)
        analysisLine(
          `Worker still active. Validating targets and preparing immutable workflows (${attempt + 1})…`,
        );
      await new Promise((resolve) =>
        setTimeout(
          resolve,
          Math.max(1000, Number(result.retry_after || 2) * 1000),
        ),
      );
      result = await api(`api/targets.php?repo_id=${id}`);
      renderAnalysisProgress(result.progress, progressSeen);
    }
    if (result.status === "analyzing")
      throw Error("Analysis is still running. Try again shortly.");
    const targets = result.targets || [];
    if (!targets.length)
      throw Error("No verified ESP hardware targets were detected.");
    analysisRunning = false;
    analysisLauncher.classList.remove("live");
    analysisStatus.textContent = "Analysis complete";
    analysisLine(
      `${targets.length} verified hardware target${targets.length === 1 ? "" : "s"} ready to build.`,
      "ok",
    );
    minimizeAnalysisTerminal();
    if (targets.length === 1) {
      const selected = targets[0];
      if (
        selected.requires_confirmation &&
        !(await ask(
          `${selected.name}\n${selected.fqbn || selected.environment || selected.idf_target}\n\n${selected.warning}`,
          "Confirm AI hardware plan",
        ))
      )
        return;
      return runBuild(id, selected.id);
    }
    const dialog = $("#target-dialog"),
      list = $("#target-list");
    $("#target-error").textContent = "";
    list.innerHTML = targets
      .map(
        (t) =>
          `<button type="button" data-target="${escapeHtml(t.id)}"><span class="target-copy"><b>${escapeHtml(t.name)} <em class="target-source ${escapeHtml(t.source || "deterministic")}">${escapeHtml(t.source === "ai_verified" ? "AI verified" : t.source === "ai" ? "AI review" : t.source === "fallback" ? "Inferred" : "Verified config")}</em></b><small>${escapeHtml(t.fqbn || t.environment || t.idf_target || t.type)}</small>${t.evidence ? `<small>Source: ${escapeHtml(t.evidence)}</small>` : ""}${t.warning ? `<small class="target-warning">${escapeHtml(t.warning)}</small>` : ""}</span><span class="target-arrow">→</span></button>`,
      )
      .join("");
    list.querySelectorAll("button").forEach(
      (button) =>
        (button.onclick = async () => {
          const selected = targets.find(
            (target) => target.id === button.dataset.target,
          );
          if (
            selected?.requires_confirmation &&
            !(await ask(
              `${selected.name}\n${selected.fqbn || selected.environment || selected.idf_target}\n\n${selected.warning || "This target was selected by AI without repository configuration evidence."}`,
              "Confirm AI hardware plan",
            ))
          )
            return;
          runBuild(id, button.dataset.target, button);
        }),
    );
    dialog.showModal();
  } catch (x) {
    analysisRunning = false;
    analysisLauncher.classList.remove("live");
    if (x.code === "auth_required") {
      minimizeAnalysisTerminal();
      return;
    }
    analysisStatus.textContent = "Analysis stopped";
    analysisLine(`Stopped: ${x.message}`);
    minimizeAnalysisTerminal();
    await notify(x.message, "Build unavailable");
  } finally {
    analysisRunning = false;
    analysisLauncher.classList.remove("live");
    triggers.forEach((button, index) => {
      button.disabled = false;
      button.textContent = labels[index];
    });
  }
};
async function runBuild(repoId, targetId, button = null) {
  try {
    if (button) {
      button.disabled = true;
      button.querySelector(".target-arrow").textContent = "…";
    }
    $("#target-dialog").close();
    tab("builds");
    $("#build-refresh-status").textContent =
      "Preparing workflow and dispatching the build…";
    await api("api/builds.php", {
      method: "POST",
      body: JSON.stringify({ repo_id: repoId, target_id: targetId }),
    });
    $("#build-refresh-status").textContent =
      "Build dispatched. Waiting for the GitHub runner…";
    await refreshBuilds(true);
  } catch (x) {
    $("#build-refresh-status").textContent = "";
    await notify(x.message, "Build could not be started");
    if (button) {
      button.disabled = false;
      button.querySelector(".target-arrow").textContent = "→";
    }
  }
}
const targetDialog = $("#target-dialog");
targetDialog.querySelector(".dialog-close").onclick = () =>
  targetDialog.close();
function clickedBackdrop(event, dialog) {
  const rect = dialog.getBoundingClientRect();
  return (
    event.clientX < rect.left ||
    event.clientX > rect.right ||
    event.clientY < rect.top ||
    event.clientY > rect.bottom
  );
}
targetDialog.addEventListener("click", (event) => {
  if (clickedBackdrop(event, targetDialog)) targetDialog.close();
});
$("#app-dialog").addEventListener("click", (event) => {
  if (clickedBackdrop(event, $("#app-dialog"))) closeDialog(false);
});
$("#test-ai-key").onclick = async () => {
  const form = $("#settings-form"),
    button = $("#test-ai-key"),
    status = $("#api-key-status"),
    data = Object.fromEntries(new FormData(form));
  status.dataset.checked = "1";
  status.className = "api-key-status checking";
  status.textContent = "Checking API key…";
  button.disabled = true;
  try {
    const result = await api("api/settings.php", {
      method: "POST",
      body: JSON.stringify({ ...data, action: "test_ai_key" }),
    });
    status.className =
      "api-key-status " + (result.usable === false ? "warning" : "valid");
    status.textContent =
      (result.usable === false ? "⚠ " : "✓ ") + result.message;
  } catch (x) {
    status.className = "api-key-status invalid";
    status.textContent = "✕ " + x.message;
  } finally {
    button.disabled = false;
  }
};
$("#remove-ai-key").onclick = async () => {
  if (
    !(await ask(
      "Remove the saved AI API key from this ESPForge account? This does not revoke the key at the provider.",
      "Remove API key",
    ))
  )
    return;
  const button = $("#remove-ai-key");
  button.disabled = true;
  try {
    const result = await api("api/settings.php", {
      method: "POST",
      body: JSON.stringify({ action: "remove_ai_key" }),
    });
    $("#settings-form").ai_api_key.value = "";
    $("#api-key-status").dataset.checked = "";
    $("#api-key-status").className = "api-key-status";
    $("#api-key-status").textContent =
      "No API key is configured for this account.";
    button.hidden = true;
    await notify(result.message, "API key removed");
  } catch (error) {
    await notify(error.message, "Unable to remove API key");
  } finally {
    button.disabled = false;
  }
};
$("#settings-form").onsubmit = async (e) => {
  e.preventDefault();
  const err = e.target.querySelector(".error");
  err.textContent = "";
  try {
    await api("api/settings.php", {
      method: "POST",
      body: JSON.stringify(Object.fromEntries(new FormData(e.target))),
    });
    e.target.ai_api_key.value = "";
    $("#api-key-status").dataset.checked = "";
    err.style.color = "#19a878";
    err.textContent = "Settings saved securely.";
    await load();
  } catch (x) {
    err.style.color = "";
    err.textContent = x.message;
  }
};
let flashManifest = null;
const normalizedChip = (value) =>
  String(value)
    .toLowerCase()
    .replace(/[^a-z0-9]/g, "");
const chipFamily = (value) => {
  const normalized = normalizedChip(value);
  return (
    ["esp32s2", "esp32s3", "esp32c3", "esp32c5", "esp32c6"].find((chip) =>
      normalized.includes(chip),
    ) || (normalized.includes("esp32") ? "esp32" : "")
  );
};
function safeFlashAddress(value, size) {
  const text =
    typeof value === "number"
      ? "0x" + value.toString(16)
      : String(value).trim();
  if (!/^0x[0-9a-f]{1,6}$/i.test(text))
    throw Error("Flash offset must be a hexadecimal address such as 0x10000.");
  const address = Number.parseInt(text, 16),
    flashLimit = 16 * 1024 * 1024;
  if (
    !Number.isSafeInteger(address) ||
    address < 0 ||
    address % 0x1000 !== 0 ||
    address + size > flashLimit
  )
    throw Error(
      "Flash offset is unaligned or the firmware exceeds the supported 16 MB address range.",
    );
  return address;
}
$("#flash-manifest").onchange = async (e) => {
  const file = e.target.files[0],
    log = $("#flash-log");
  flashManifest = null;
  if (!file) return;
  try {
    if (file.size > 1024 * 1024)
      throw Error("Manifest exceeds the 1 MB safety limit.");
    const parsed = JSON.parse(await file.text());
    if (
      parsed.version !== 1 ||
      !Array.isArray(parsed.files) ||
      parsed.files.length < 1 ||
      parsed.files.length > 16 ||
      typeof parsed.chip !== "string" ||
      !/^esp32(?:-?(?:s2|s3|c3|c5|c6))?$/i.test(parsed.chip)
    )
      throw Error("Unsupported manifest format.");
    for (const item of parsed.files) {
      if (
        !item ||
        typeof item.path !== "string" ||
        item.path.length > 500 ||
        !/[a-f0-9]{64}/i.test(String(item.sha256)) ||
        String(item.sha256).length !== 64
      )
        throw Error("Manifest contains an invalid firmware entry.");
    }
    const provenance = await api("api/flashes.php", {
      method: "POST",
      body: JSON.stringify({ action: "verify_manifest", manifest: parsed }),
    });
    if (!provenance.verified)
      throw Error("Artifact publisher signature could not be verified.");
    flashManifest = parsed;
    log.textContent = `Signed manifest verified for ${parsed.chip} at commit ${String(parsed.commit || "unknown").slice(0, 12)}. Choose its firmware binary.`;
  } catch (error) {
    e.target.value = "";
    log.textContent = "Manifest rejected: " + error.message;
  }
};
async function verifyFlashManifest(file) {
  if (!flashManifest) return false;
  const entry =
    flashManifest.files.find(
      (item) => item.path?.split("/").pop() === file.name,
    ) ||
    (flashManifest.files.length === 1 && flashManifest.files[0]);
  if (!entry)
    throw Error("This binary is not listed in the selected build manifest.");
  const digest = [
    ...new Uint8Array(
      await crypto.subtle.digest("SHA-256", await file.arrayBuffer()),
    ),
  ]
    .map((byte) => byte.toString(16).padStart(2, "0"))
    .join("");
  if (digest !== String(entry.sha256).toLowerCase())
    throw Error(
      "Binary SHA-256 does not match the build manifest. Flashing was stopped.",
    );
  if (normalizedChip($("#chip").value) !== normalizedChip(flashManifest.chip))
    throw Error(
      `Selected chip ${$("#chip").value} does not match manifest chip ${flashManifest.chip}.`,
    );
  if (entry.offset !== null && entry.offset !== undefined)
    $("#offset").value =
      "0x" + safeFlashAddress(entry.offset, file.size).toString(16);
  return true;
}
function showSelectedBinary(file) {
  if (!file) return;
  const drop = $("#binary").closest("label");
  drop.querySelector("b").textContent = file.name;
  drop.querySelector("span").textContent =
    `${(file.size / 1024).toFixed(1)} KB · ready to flash`;
}
$("#binary").onchange = (e) => showSelectedBinary(e.target.files[0]);
const dropZone = $("#binary").closest(".drop");
["dragenter", "dragover"].forEach((type) =>
  dropZone.addEventListener(type, (event) => {
    event.preventDefault();
    dropZone.classList.add("dragging");
  }),
);
["dragleave", "drop"].forEach((type) =>
  dropZone.addEventListener(type, (event) => {
    event.preventDefault();
    dropZone.classList.remove("dragging");
  }),
);
dropZone.addEventListener("drop", (event) => {
  const file = [...event.dataTransfer.files].find((item) =>
    item.name.toLowerCase().endsWith(".bin"),
  );
  if (!file) return notify("Choose a .bin firmware file.", "Unsupported file");
  const transfer = new DataTransfer();
  transfer.items.add(file);
  $("#binary").files = transfer.files;
  showSelectedBinary(file);
});
const isiOS =
  /iPad|iPhone|iPod/.test(navigator.userAgent) ||
  (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
if (isiOS) {
  $("#connect-device").disabled = true;
  $("#connect-device").textContent = "Unavailable on iPhone/iPad";
  $("#serial-support").textContent =
    "Apple iPhone and iPad browsers do not support Web Serial. Download the firmware and flash it from a supported desktop or Android device.";
} else if (!("serial" in navigator)) {
  $("#serial-support").textContent =
    "This browser does not support Web Serial. Download the firmware or use desktop Chrome/Edge or a compatible Android browser.";
}
$("#connect-device").onclick = async () => {
  const log = $("#flash-log"),
    button = $("#connect-device"),
    file = $("#binary").files[0];
  if (!file) {
    log.textContent = "Choose a .bin firmware file first.";
    return;
  }
  if (file.size > 16 * 1024 * 1024) {
    log.textContent = "Firmware exceeds the 16 MB safety limit.";
    return;
  }
  if (!("serial" in navigator)) {
    log.textContent =
      "Web Serial is unavailable. Use Chrome or Edge over HTTPS.";
    return;
  }
  try {
    button.disabled = true;
    button.textContent = "Verifying…";
    const hashMatched = await verifyFlashManifest(file);
    const address = safeFlashAddress($("#offset").value, file.size);
    button.textContent = "Connecting…";
    const { Transport, ESPLoader } = await import("./vendor/esptool.js");
    const port = await navigator.serial.requestPort();
    transport = new Transport(port, true);
    const terminal = {
      clean: () => (log.textContent = ""),
      writeLine: (s) => {
        log.textContent += s + "\n";
        log.scrollTop = log.scrollHeight;
      },
      write: (s) => {
        log.textContent += s;
        log.scrollTop = log.scrollHeight;
      },
    };
    loader = new ESPLoader({
      transport,
      baudrate: Number($("#baud").value || 460800),
      terminal,
    });
    const chip = await loader.main(),
      expected = chipFamily($("#chip").value),
      detected = chipFamily(chip);
    if (!detected || detected !== expected)
      throw Error(
        `Connected device ${chip} does not match selected chip ${$("#chip").value}. Flashing was stopped.`,
      );
    terminal.writeLine(`Connected to ${chip}. Preparing flash…`);
    const bytes = new Uint8Array(await file.arrayBuffer());
    let binary = "";
    for (let i = 0; i < bytes.length; i += 8192)
      binary += String.fromCharCode(...bytes.subarray(i, i + 8192));
    await loader.writeFlash({
      fileArray: [{ data: binary, address }],
      flashSize: "keep",
      eraseAll: false,
      compress: true,
      reportProgress: (i, w, t) => {
        button.textContent = `Flashing ${Math.round((w / t) * 100)}%`;
      },
    });
    terminal.writeLine("Flash complete. Resetting device…");
    await transport.setDTR(false);
    await transport.setRTS(true);
    await new Promise((r) => setTimeout(r, 100));
    await transport.setRTS(false);
    button.textContent = "Flash complete ✓";
    try {
      const result = await api("api/flashes.php", {
        method: "POST",
        body: JSON.stringify({
          chip: $("#chip").value,
          firmware_size: file.size,
          hash_matched: hashMatched,
        }),
      });
      if (result.ok)
        $("#flash-count").textContent =
          Number($("#flash-count").textContent || 0) + 1;
    } catch (reportError) {
      console.error("Flash completion reporting failed", reportError);
    }
  } catch (e) {
    log.textContent += "\nFlash failed: " + e.message;
    button.textContent = "Try again";
  } finally {
    button.disabled = false;
  }
};
document.addEventListener("click", (event) => {
  const button = event.target.closest("button[data-action]");
  if (!button) return;
  const id = Number(button.dataset.id);
  if (!Number.isSafeInteger(id) || id < 1) return;
  const action = button.dataset.action;
  if (action === "build") window.build(id);
  else if (action === "repository")
    window.repositoryAction(id, button.dataset.operation);
  else if (action === "build-details") window.toggleBuildDetails(id, button);
  else if (action === "cancel-build") window.cancelBuild(id, button);
  else if (action === "download") window.downloadBuild(id, button.dataset.kind);
});
load().catch((e) => notify(e.message, "Workspace unavailable"));
async function scheduleBuildRefresh() {
  if ($("#builds").classList.contains("active") && !document.hidden)
    await refreshBuilds();
  const base = hasActiveBuilds ? 2000 : 10000,
    delay = buildRefreshFailures
      ? Math.min(60000, base * 2 ** Math.min(buildRefreshFailures, 4))
      : base;
  setTimeout(scheduleBuildRefresh, delay);
}
setTimeout(scheduleBuildRefresh, 2000);
setInterval(() => {
  if (!document.hidden) updateBuildTimers();
}, 1000);
