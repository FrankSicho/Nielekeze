const tokenKey = "daladala-admin-token";
const apiBase = "/Nielekeze/api/v1";
const loginPanel = document.querySelector("#login-panel");
const dashboard = document.querySelector("#dashboard");
const loginMessage = document.querySelector("#login-message");
const logoutButton = document.querySelector("#logout-button");
const locationMessage = document.querySelector("#location-message");
const routeMessage = document.querySelector("#route-message");
const queue = document.querySelector("#queue");
const locationList = document.querySelector("#location-list");
const routeList = document.querySelector("#route-list");
const originInput = document.querySelector("#route-origin");
const destinationInput = document.querySelector("#route-destination");
const originSuggestions = document.querySelector("#route-origin-suggestions");
const destinationSuggestions = document.querySelector("#route-destination-suggestions");
let locations = [];

const escapeHtml = (value) => String(value)
  .replaceAll("&", "&amp;")
  .replaceAll("<", "&lt;")
  .replaceAll(">", "&gt;")
  .replaceAll('"', "&quot;")
  .replaceAll("'", "&#039;");

const api = async (path, options = {}) => {
  const headers = { ...(options.headers || {}), "Content-Type": "application/json" };
  const token = localStorage.getItem(tokenKey);
  if (token) headers.Authorization = `Bearer ${token}`;
  const resolvedPath = path.startsWith("/") ? `${apiBase}${path}` : path;
  const response = await fetch(resolvedPath, { ...options, headers });
  const body = response.status === 204 ? null : await response.json();
  if (!response.ok) throw new Error(body?.error || body?.detail || "Request failed");
  return body;
};

const setMessage = (element, message, error = false) => {
  element.textContent = message;
  element.classList.toggle("error", error);
};

const renderLocations = () => {
  locationList.innerHTML = locations.length
    ? locations.map((location) => `<div class="location-row" data-location-id="${location.id}" data-active="${location.is_active}"><div><strong>${escapeHtml(location.name)}</strong><span>#${location.id} · ${escapeHtml(location.slug)} · ${location.is_active ? "ACTIVE" : "INACTIVE"}</span></div><div class="admin-actions"><button class="quiet-button edit-location" type="button">Edit</button><button class="${location.is_active ? "danger-button" : "approve"} toggle-location" type="button">${location.is_active ? "Deactivate" : "Activate"}</button></div></div>`).join("")
    : `<p class="empty">No locations yet.</p>`;
};

const loadLocations = async () => {
  const pageSize = 100;
  const loadedLocations = [];
  let offset = 0;

  while (true) {
    const page = await api(`/locations?limit=${pageSize}&offset=${offset}`);
    loadedLocations.push(...page);
    if (page.length < pageSize) break;
    offset += pageSize;
  }

  locations = loadedLocations;
  renderLocations();
};

const getLocationIdFromInput = (input) => {
  const locationId = Number(input.dataset.locationId);
  return locations.some((location) => location.id === locationId) ? locationId : null;
};

const setupLocationPicker = (input, suggestions) => {
  const hideSuggestions = () => {
    suggestions.hidden = true;
    input.setAttribute("aria-expanded", "false");
  };

  const renderSuggestions = () => {
    const searchTerm = input.value.trim().toLocaleLowerCase();
    input.dataset.locationId = "";
    const matches = locations.filter((location) => {
      const aliases = (location.aliases || []).join(" ");
      return `${location.name} ${aliases}`.toLocaleLowerCase().includes(searchTerm);
    }).slice(0, 5);

    suggestions.innerHTML = matches.map((location) => `<button class="location-suggestion" type="button" role="option" data-location-id="${location.id}">${escapeHtml(location.name)} <span>#${location.id}</span></button>`).join("");
    suggestions.hidden = matches.length === 0;
    input.setAttribute("aria-expanded", String(matches.length > 0));
  };

  input.addEventListener("input", renderSuggestions);
  input.addEventListener("focus", renderSuggestions);
  input.addEventListener("blur", () => window.setTimeout(hideSuggestions, 150));
  input.addEventListener("keydown", (event) => {
    if (event.key === "Escape") hideSuggestions();
  });
  suggestions.addEventListener("click", (event) => {
    const suggestion = event.target.closest("[data-location-id]");
    if (!suggestion) return;
    const location = locations.find((item) => item.id === Number(suggestion.dataset.locationId));
    if (!location) return;
    input.value = `${location.name} (#${location.id})`;
    input.dataset.locationId = location.id;
    hideSuggestions();
  });
};

setupLocationPicker(originInput, originSuggestions);
setupLocationPicker(destinationInput, destinationSuggestions);

let allRoutes = [];

const renderRoutes = (routes) => {
  allRoutes = routes;
  routeList.innerHTML = routes.length
    ? routes.map((route) => `<article class="route-admin-row" data-route-id="${route.id}" data-status="${escapeHtml(route.status)}" data-via="${escapeHtml(route.via || "")}"><div><strong>${escapeHtml(route.name)}</strong><span>#${route.id} · ${escapeHtml(route.status)} · ${escapeHtml(route.verification_status)}</span><small>${route.stops.map((stop) => escapeHtml(stop.location.name)).join(" → ")}${route.via ? ` • via ${escapeHtml(route.via)}` : ""}</small></div><div class="admin-actions"><button class="quiet-button edit-route" type="button">Edit</button><button class="${route.status === "ACTIVE" ? "approve" : "quiet-button"} toggle-route" type="button">${route.status === "ACTIVE" ? "Inactive" : "Activate"}</button></div></article>`).join("")
    : `<p class="empty">No routes yet.</p>`;
};

const renderLocationOptions = () => {
  const options = locations.map((location) => `<option value="${location.id}">${escapeHtml(location.name)} (#${location.id})</option>`).join("");
  document.querySelector("#route-editor-origin").innerHTML = `<option value="">Choose origin</option>${options}`;
  document.querySelector("#route-editor-destination").innerHTML = `<option value="">Choose destination</option>${options}`;
};

const openLocationEditor = (locationId) => {
  const location = locations.find((item) => String(item.id) === String(locationId));
  if (!location) return;
  const modal = document.querySelector("#location-editor-modal");
  const form = document.querySelector("#location-editor-form");
  form.querySelector("#location-editor-id").value = location.id;
  form.querySelector("#location-editor-name").value = location.name || "";
  form.querySelector("#location-editor-slug").value = location.slug || "";
  form.querySelector("#location-editor-latitude").value = location.latitude ?? "";
  form.querySelector("#location-editor-longitude").value = location.longitude ?? "";
  form.querySelector("#location-editor-aliases").value = (location.aliases || []).join(", ");
  form.querySelector("#location-editor-type").value = location.location_type || "";
  form.querySelector("#location-editor-description").value = location.description || "";
  form.querySelector("#location-editor-active").checked = Boolean(location.is_active);
  modal.classList.remove("hidden");
  modal.setAttribute("aria-hidden", "false");
};

const closeLocationEditor = () => {
  const modal = document.querySelector("#location-editor-modal");
  modal.classList.add("hidden");
  modal.setAttribute("aria-hidden", "true");
  document.querySelector("#location-editor-form").reset();
};

const openRouteEditor = (routeId) => {
  const route = allRoutes.find((item) => String(item.id) === String(routeId));
  if (!route) return;
  renderLocationOptions();
  const modal = document.querySelector("#route-editor-modal");
  const form = document.querySelector("#route-editor-form");
  form.querySelector("#route-editor-id").value = route.id;
  form.querySelector("#route-editor-name").value = route.name;
  form.querySelector("#route-editor-origin").value = route.origin.id;
  form.querySelector("#route-editor-destination").value = route.destination.id;
  form.querySelector("#route-editor-stops").value = route.stops.map((stop) => stop.location.id).join(", ");
  form.querySelector("#route-editor-via").value = route.via || "";
  form.querySelector("#route-editor-fare").value = route.estimated_fare ?? "";
  form.querySelector("#route-editor-status").value = route.status;
  form.querySelector("#route-editor-verification").value = route.verification_status;
  form.querySelector("#route-editor-source").value = route.source || "";
  modal.classList.remove("hidden");
  modal.setAttribute("aria-hidden", "false");
};

const closeRouteEditor = () => {
  const modal = document.querySelector("#route-editor-modal");
  modal.classList.add("hidden");
  modal.setAttribute("aria-hidden", "true");
  document.querySelector("#route-editor-form").reset();
};

const loadRoutes = async () => {
  renderRoutes(await api("/routes"));
};

const renderQueue = (items) => {
  queue.innerHTML = items.length
    ? items.map((item) => `<article class="queue-card"><header><strong>${escapeHtml(item.contribution_type)}</strong><span class="hint">${escapeHtml(item.status)}</span></header><p>${escapeHtml(item.payload.notes || "No notes provided")}</p><div class="queue-actions"><button class="approve" type="button" data-action="approve" data-id="${item.id}">Approve</button><button class="reject" type="button" data-action="reject" data-id="${item.id}">Reject</button></div></article>`).join("")
    : `<p class="empty">No pending contributions.</p>`;
};

const loadQueue = async () => {
  renderQueue([]);
};

const showDashboard = async (user) => {
  if (!user.is_admin) throw new Error("This account is not an administrator");
  loginPanel.hidden = true;
  dashboard.hidden = false;
  logoutButton.hidden = false;
  document.querySelector("#admin-identity").textContent = user.email;
  await Promise.all([loadLocations(), loadRoutes()]);
  loadQueue();
};

const login = async (event) => {
  event.preventDefault();
  setMessage(loginMessage, "Signing in...");
  try {
    const response = await api("/auth/login", { method: "POST", body: JSON.stringify({ email: document.querySelector("#login-email").value, password: document.querySelector("#login-password").value }) });
    localStorage.setItem(tokenKey, response.access_token);
    await showDashboard(response.user);
  } catch (error) {
    setMessage(loginMessage, error.message, true);
  }
};

document.querySelector("#login-form").addEventListener("submit", login);

document.querySelector("#location-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  try {
    await api("/locations", { method: "POST", body: JSON.stringify({ name: document.querySelector("#location-name").value, slug: document.querySelector("#location-slug").value || null, latitude: document.querySelector("#location-latitude").value ? Number(document.querySelector("#location-latitude").value) : null, longitude: document.querySelector("#location-longitude").value ? Number(document.querySelector("#location-longitude").value) : null, location_type: document.querySelector("#location-type").value || null, aliases: document.querySelector("#location-aliases").value.split(",").map((alias) => alias.trim()).filter(Boolean) }) });
    event.target.reset();
    setMessage(locationMessage, "Location created.");
    await loadLocations();
  } catch (error) { setMessage(locationMessage, error.message, true); }
});

document.querySelector("#route-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const origin = getLocationIdFromInput(originInput);
  const destination = getLocationIdFromInput(destinationInput);
  const stopIds = document.querySelector("#route-stops").value.split(",").map((id) => Number(id.trim())).filter((id) => Number.isInteger(id) && id > 0);
  try {
    if (!origin || !destination) throw new Error("Choose the origin and destination from the location suggestions.");
    if (stopIds.length < 2 || stopIds[0] !== origin || stopIds.at(-1) !== destination) throw new Error("Stops must start at origin and end at destination.");
    const via = document.querySelector("#route-via").value.trim();
    await api("/routes", { method: "POST", body: JSON.stringify({ name: document.querySelector("#route-name").value, origin_id: origin, destination_id: destination, stops: stopIds.map((location_id) => ({ location_id })), estimated_fare: document.querySelector("#route-fare").value ? Number(document.querySelector("#route-fare").value) : null, via: via || null, verification_status: document.querySelector("#route-verification").value, source: document.querySelector("#route-source").value }) });
    event.target.reset();
    setMessage(routeMessage, "Route created.");
    await Promise.all([loadLocations(), loadRoutes()]);
  } catch (error) { setMessage(routeMessage, error.message, true); }
});

queue.addEventListener("click", async (event) => {
  const button = event.target.closest("button[data-action]");
  if (!button) return;
  setMessage(routeMessage, "Contribution review is not available in this version.", true);
});

document.querySelector("#refresh-button").addEventListener("click", async () => {
  try { await Promise.all([loadLocations(), loadRoutes()]); loadQueue(); } catch (error) { setMessage(routeMessage, error.message, true); }
});

document.querySelector("#close-location-editor").addEventListener("click", closeLocationEditor);
document.querySelector("#cancel-location-editor").addEventListener("click", closeLocationEditor);
document.querySelector("#location-editor-modal").addEventListener("click", (event) => {
  if (event.target === event.currentTarget) closeLocationEditor();
});

document.querySelector("#location-editor-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  const locationId = form.querySelector("#location-editor-id").value;
  const valueOrNull = (selector) => {
    const value = form.querySelector(selector).value.trim();
    return value || null;
  };
  try {
    if (!locationId || !form.querySelector("#location-editor-name").value.trim()) {
      throw new Error("Location name is required.");
    }
    await api(`/locations/${locationId}`, {
      method: "PUT",
      body: JSON.stringify({
        name: form.querySelector("#location-editor-name").value.trim(),
        slug: valueOrNull("#location-editor-slug"),
        latitude: valueOrNull("#location-editor-latitude"),
        longitude: valueOrNull("#location-editor-longitude"),
        aliases: form.querySelector("#location-editor-aliases").value.split(",").map((alias) => alias.trim()).filter(Boolean),
        location_type: valueOrNull("#location-editor-type"),
        description: valueOrNull("#location-editor-description"),
        is_active: form.querySelector("#location-editor-active").checked,
      }),
    });
    closeLocationEditor();
    await Promise.all([loadLocations(), loadRoutes()]);
  } catch (error) { setMessage(locationMessage, error.message, true); }
});

document.querySelector("#close-route-editor").addEventListener("click", closeRouteEditor);
document.querySelector("#cancel-route-editor").addEventListener("click", closeRouteEditor);
document.querySelector("#route-editor-modal").addEventListener("click", (event) => {
  if (event.target === event.currentTarget) closeRouteEditor();
});

document.querySelector("#route-editor-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const routeId = document.querySelector("#route-editor-id").value;
  const origin = Number(document.querySelector("#route-editor-origin").value);
  const destination = Number(document.querySelector("#route-editor-destination").value);
  const stopIds = document.querySelector("#route-editor-stops").value.split(",").map((id) => Number(id.trim())).filter((id) => Number.isInteger(id) && id > 0);
  const status = document.querySelector("#route-editor-status").value;
  const via = document.querySelector("#route-editor-via").value.trim();
  try {
    if (!routeId || !origin || !destination || stopIds.length < 2 || stopIds[0] !== origin || stopIds.at(-1) !== destination) {
      throw new Error("Stops must start at origin and end at destination.");
    }
    const payload = {
      name: document.querySelector("#route-editor-name").value.trim(),
      origin_id: origin,
      destination_id: destination,
      stops: stopIds.map((location_id) => ({ location_id })),
      estimated_fare: document.querySelector("#route-editor-fare").value ? Number(document.querySelector("#route-editor-fare").value) : null,
      via: via || null,
      status,
      verification_status: document.querySelector("#route-editor-verification").value,
      source: document.querySelector("#route-editor-source").value.trim() || null,
    };
    await api(`/routes/${routeId}`, { method: "PUT", body: JSON.stringify(payload) });
    closeRouteEditor();
    await loadRoutes();
  } catch (error) { setMessage(routeMessage, error.message, true); }
});

document.querySelector("#delete-route-button").addEventListener("click", async () => {
  const routeId = document.querySelector("#route-editor-id").value;
  if (!routeId) return;
  if (!window.confirm("Delete this route permanently? It will be removed from the system.")) return;
  try {
    await api(`/routes/${routeId}`, { method: "DELETE" });
    closeRouteEditor();
    await loadRoutes();
  } catch (error) { setMessage(routeMessage, error.message, true); }
});

routeList.addEventListener("click", async (event) => {
  const row = event.target.closest("[data-route-id]");
  if (!row) return;
  const routeId = row.dataset.routeId;
  if (event.target.closest(".toggle-route")) {
    const active = row.dataset.status === "ACTIVE";
    if (!window.confirm(`${active ? "Mark this route inactive?" : "Activate this route?"}`)) return;
    try {
      await api(`/routes/${routeId}`, { method: "PUT", body: JSON.stringify({ status: active ? "INACTIVE" : "ACTIVE" }) });
      await loadRoutes();
    } catch (error) { setMessage(routeMessage, error.message, true); }
    return;
  }
  if (event.target.closest(".edit-route")) {
    openRouteEditor(routeId);
  }
});

locationList.addEventListener("click", async (event) => {
  const row = event.target.closest("[data-location-id]");
  if (!row) return;
  const locationId = row.dataset.locationId;
  if (event.target.closest(".toggle-location")) {
    const active = row.dataset.active === "true";
    if (!window.confirm(`${active ? "Deactivate" : "Activate"} this location?`)) return;
    try { await api(`/api/v1/locations/${locationId}`, { method: active ? "DELETE" : "PUT", body: active ? undefined : JSON.stringify({ is_active: true }) }); await Promise.all([loadLocations(), loadRoutes()]); }
    catch (error) { setMessage(locationMessage, error.message, true); }
    return;
  }
  if (event.target.closest(".edit-location")) {
    openLocationEditor(locationId);
  }
});

logoutButton.addEventListener("click", async () => {
  try { await api("/auth/logout", { method: "POST" }); } catch { /* local session cleanup still applies */ }
  localStorage.removeItem(tokenKey);
  window.location.reload();
});

const existingToken = localStorage.getItem(tokenKey);
if (existingToken) {
  api("/auth/me").then(showDashboard).catch(() => localStorage.removeItem(tokenKey));
}
