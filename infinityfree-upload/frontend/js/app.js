/* =========================================================
   DALA ROUTE
   Frontend Application
========================================================= */


/* =========================================================
   DOM
========================================================= */

const API_BASE = "/api/v1";

const backendStatus =
  document.querySelector("#backend-status");

const backendStatusDot =
  document.querySelector("#backend-status-dot");

const resultsSection =
  document.querySelector("#results-section");

const results =
  document.querySelector("#results");

const resultCount =
  document.querySelector("#result-count");

const detailSection =
  document.querySelector("#detail-section");

const detailContent =
  document.querySelector("#detail-content");

const searchMessage =
  document.querySelector("#search-message");

const routeMap =
  document.querySelector("#route-map");

const mapMessage =
  document.querySelector("#map-message");

const fromInput =
  document.querySelector("#from-input");

const toInput =
  document.querySelector("#to-input");

const fromId =
  document.querySelector("#from-id");

const toId =
  document.querySelector("#to-id");

const findRouteButton =
  document.querySelector("#find-route");

const closeDetailButton =
  document.querySelector("#close-detail");

const toggleMapButton =
  document.querySelector("#toggle-map-size");

const swapButton =
  document.querySelector("#swap-locations");


/* =========================================================
   STATE
========================================================= */

let activeMap = null;

let currentRoutes = [];

let mapExpanded = true;


const syncMapSizeToggle = () => {

  if (!routeMap || !toggleMapButton) {
    return;
  }

  routeMap.classList.toggle(
    "is-expanded",
    mapExpanded
  );

  routeMap.classList.toggle(
    "is-compact",
    !mapExpanded
  );

  toggleMapButton.textContent =
    mapExpanded
      ? "Return to compact"
      : "Maximize map";

  toggleMapButton.setAttribute(
    "aria-label",
    mapExpanded
      ? "Return map to compact size"
      : "Maximize map"
  );

  if (activeMap) {
    requestAnimationFrame(() => {
      activeMap.invalidateSize();
    });
  }

};

const setMapExpanded = (expanded) => {
  mapExpanded = expanded;
  syncMapSizeToggle();
};

if (toggleMapButton) {
  toggleMapButton.addEventListener(
    "click",
    () => {
      setMapExpanded(!mapExpanded);
    }
  );
}


/* =========================================================
   SECURITY
========================================================= */

const escapeHtml = (value) => {

  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");

};


/* =========================================================
   LOCATION LABEL
========================================================= */

const locationLabel = (location) => {

  if (!location) {
    return "";
  }


  if (typeof location === "string") {
    return escapeHtml(location);
  }


  return escapeHtml(
    location.name || ""
  );

};


/* =========================================================
   LOCATION SEARCH
========================================================= */

const setupLocationSearch = (
  inputId,
  hiddenId,
  suggestionsId
) => {

  const input =
    document.querySelector(`#${inputId}`);

  const hidden =
    document.querySelector(`#${hiddenId}`);

  const suggestions =
    document.querySelector(`#${suggestionsId}`);


  if (
    !input ||
    !hidden ||
    !suggestions
  ) {
    return;
  }


  let searchTimer = null;


  const closeSuggestions = () => {

    suggestions.innerHTML = "";

    input.setAttribute(
      "aria-expanded",
      "false"
    );

  };


  const renderSuggestions = (
    locations
  ) => {

    if (
      !locations ||
      !locations.length
    ) {

      suggestions.innerHTML = `
        <div class="suggestion-empty">
          No matching locations found
        </div>
      `;

      input.setAttribute(
        "aria-expanded",
        "true"
      );

      return;
    }


    suggestions.innerHTML =
      locations
        .map((location) => {

          const name =
            escapeHtml(
              location.name || ""
            );


          const alias =
            location.aliases?.[0]?.name
              ? escapeHtml(
                  location.aliases[0].name
                )
              : "";


          return `
            <button
              class="suggestion"
              type="button"
              data-id="${escapeHtml(location.id)}"
              data-name="${name}"
            >

              <span
                class="suggestion-icon"
                aria-hidden="true"
              >

                <svg
                  width="17"
                  height="17"
                  viewBox="0 0 24 24"
                  fill="none"
                >

                  <path
                    d="M12 2c-4.4 0-8 3.6-8 8 0 6 8 12 8 12s8-6 8-12c0-4.4-3.6-8-8-8z"
                    fill="currentColor"
                  />

                  <circle
                    cx="12"
                    cy="10"
                    r="3"
                    fill="white"
                  />

                </svg>

              </span>


              <span class="suggestion-content">

                <strong>
                  ${name}
                </strong>

                ${
                  alias
                    ? `
                      <small>
                        ${alias}
                      </small>
                    `
                    : ""
                }

              </span>

            </button>
          `;

        })
        .join("");


    input.setAttribute(
      "aria-expanded",
      "true"
    );

  };


  const searchLocations = async (
    query
  ) => {

    try {

      const response =
        await fetch(
          `${API_BASE}/locations/search?q=${encodeURIComponent(query)}`
        );


      if (!response.ok) {
        throw new Error(
          "Location search failed"
        );
      }


      const locations =
        await response.json();


      renderSuggestions(
        locations
      );

    } catch (error) {

      suggestions.innerHTML = `
        <div class="suggestion-empty">
          Locations could not be loaded
        </div>
      `;

      input.setAttribute(
        "aria-expanded",
        "true"
      );

    }

  };


  input.addEventListener(
    "input",
    () => {

      hidden.value = "";

      clearTimeout(
        searchTimer
      );


      const query =
        input.value.trim();


      if (!query) {

        closeSuggestions();

        return;

      }


      searchTimer =
        setTimeout(() => {

          searchLocations(
            query
          );

        }, 180);

    }
  );


  const selectSuggestion = (
    event
  ) => {

    const choice =
      event.target.closest(
        "button[data-id]"
      );


    if (!choice) {
      return;
    }


    input.value =
      choice.dataset.name;


    hidden.value =
      choice.dataset.id;


    closeSuggestions();

  };


  suggestions.addEventListener(
    "pointerdown",
    (event) => {

      const choice =
        event.target.closest(
          "button[data-id]"
        );


      if (!choice) {
        return;
      }


      event.preventDefault();

      selectSuggestion(
        event
      );

    }
  );


  suggestions.addEventListener(
    "click",
    selectSuggestion
  );


  input.addEventListener(
    "blur",
    () => {

      setTimeout(
        closeSuggestions,
        150
      );

    }
  );


  input.addEventListener(
    "keydown",
    (event) => {

      if (
        event.key === "Escape"
      ) {

        closeSuggestions();

      }

    }
  );

};


/* =========================================================
   RESOLVE LOCATION
========================================================= */

const resolveLocation = async (
  inputId,
  hiddenId
) => {

  const input =
    document.querySelector(
      `#${inputId}`
    );

  const hidden =
    document.querySelector(
      `#${hiddenId}`
    );


  if (
    !input ||
    !hidden
  ) {
    return null;
  }


  if (hidden.value) {
    return hidden.value;
  }


  const query =
    input.value.trim();


  if (!query) {
    return null;
  }


  try {

    const response =
      await fetch(
        `${API_BASE}/locations/search?q=${encodeURIComponent(query)}`
      );


    if (!response.ok) {
      return null;
    }


    const locations =
      await response.json();


    const normalizedQuery =
      query.toLocaleLowerCase();


    const exactMatch =
      locations.find(
        (location) => {

          const names = [

            location.name,

            location.slug,

            ...(location.aliases || [])
              .map(
                alias => alias.name
              )

          ].filter(Boolean);


          return names.some(
            name =>
              String(name)
                .toLocaleLowerCase()
                === normalizedQuery
          );

        }
      );


    if (!exactMatch) {
      return null;
    }


    hidden.value =
      exactMatch.id;


    return exactMatch.id;

  } catch {

    return null;

  }

};


/* =========================================================
   SWAP LOCATIONS
========================================================= */

if (swapButton) {

  swapButton.addEventListener(
    "click",
    () => {

      const fromValue =
        fromInput.value;

      const toValue =
        toInput.value;


      const fromValueId =
        fromId.value;

      const toValueId =
        toId.value;


      fromInput.value =
        toValue;

      toInput.value =
        fromValue;


      fromId.value =
        toValueId;

      toId.value =
        fromValueId;

    }
  );

}


/* =========================================================
   FARE
========================================================= */

const formatFare = (
  fare
) => {

  if (
    fare === null ||
    fare === undefined
  ) {

    return {
      amount: "—",
      currency: ""
    };

  }


  return {

    amount:
      Number(fare)
        .toLocaleString(),

    currency:
      "TZS"

  };

};


/* =========================================================
   ROUTE CARD
========================================================= */

const renderRouteCard = (
  route,
  index
) => {

  const fare =
    formatFare(
      route.estimated_fare
    );


  const vehicleText =
    route.legs === 1
      ? "1 vehicle"
      : `${route.legs} vehicles`;


  const cheapest =
    index === 0 &&
    route.estimated_fare !== null;


  const routeStops =
    Array.isArray(route.stops)
      ? route.stops
          .map(stop => stop?.name)
          .filter(Boolean)
      : [];

  const pathText =
    routeStops.length
      ? routeStops.length === 1
        ? escapeHtml(routeStops[0])
        : `${escapeHtml(
            routeStops[0]
          )} → ${escapeHtml(
            routeStops[
              routeStops.length - 1
            ]
          )}`
      : "Route information";


  return `

    <article
      class="route-card ${
        cheapest
          ? "cheapest"
          : ""
      }"
      data-route-index="${index}"
      tabindex="0"
    >


      ${
        cheapest
          ? `
            <span class="route-badge">
              Cheapest
            </span>
          `
          : ""
      }


      <div class="route-card-top">


        <div class="route-icon">

          <svg
            width="20"
            height="20"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
          >

            <path
              d="M12 2c-4.4 0-8 3.6-8 8 0 6 8 12 8 12s8-6 8-12c0-4.4-3.6-8-8-8z"
              fill="currentColor"
            />

            <circle
              cx="12"
              cy="10"
              r="3"
              fill="white"
            />

          </svg>

        </div>


        <div class="route-path-info">

          <span class="route-path-text">
            ${pathText}
          </span>

          <span class="route-meta">
            ${escapeHtml(vehicleText)}
          </span>

        </div>


        <div class="route-price">

          <span class="fare-amount">
            ${fare.amount}
          </span>

          <span class="fare-currency">
            ${fare.currency}
          </span>

        </div>

      </div>


      ${
        route.segments?.length
          ? `

            <div class="route-segments-preview">

              ${route.segments
                .map(
                  (
                    segment,
                    segmentIndex
                  ) => `

                    <div class="segment-preview">

                      <span class="segment-number">
                        ${segmentIndex + 1}
                      </span>

                      <div class="segment-route-group">

                        <span class="segment-route">
                          ${escapeHtml(
                            segment.route_name
                          )}
                        </span>

                        ${
                          segment.via
                            ? `
                              <span class="segment-via-preview">
                                Via ${escapeHtml(
                                  segment.via
                                )}
                              </span>
                            `
                            : ""
                        }

                      </div>

                      <span class="segment-fare">

                        ${
                          segment.estimated_fare ===
                          null
                            ? "—"
                            : `${Number(
                                segment.estimated_fare
                              ).toLocaleString()} TZS`
                        }

                      </span>

                    </div>


                    ${
                      segmentIndex <
                      route.segments.length - 1
                        ? `
                          <div class="change-line">
                            Change vehicle
                          </div>
                        `
                        : ""
                    }

                  `
                )
                .join("")}

            </div>

          `
          : ""
      }


      <div class="route-card-bottom">

        <span class="route-card-note">

          ${
            route.segments?.length
              ? `${route.segments.length} leg${
                  route.segments.length === 1
                    ? ""
                    : "s"
                }`
              : vehicleText
          }

        </span>


        <button
          class="view-button"
          type="button"
          data-route-index="${index}"
        >
          View details
        </button>

      </div>


    </article>

  `;

};


/* =========================================================
   RENDER RESULTS
========================================================= */

const renderResults = (
  data
) => {

  resultsSection.hidden =
    false;

  detailSection.hidden =
    true;


  currentRoutes =
    Array.isArray(data?.routes)
      ? [...data.routes]
      : [];


  currentRoutes.sort(
    (first, second) => {

      if (
        first.estimated_fare === null
      ) {
        return 1;
      }


      if (
        second.estimated_fare === null
      ) {
        return -1;
      }


      return (
        Number(
          first.estimated_fare
        ) -
        Number(
          second.estimated_fare
        )
      );

    }
  );


  resultCount.textContent =
    `${currentRoutes.length} option${
      currentRoutes.length === 1
        ? ""
        : "s"
    }`;


  if (
    !currentRoutes.length
  ) {

    results.innerHTML = `

      <div class="empty-state">

        <div
          class="empty-icon"
          aria-hidden="true"
        >

          <svg
            width="28"
            height="28"
            viewBox="0 0 24 24"
            fill="none"
          >

            <circle
              cx="12"
              cy="12"
              r="9"
              stroke="currentColor"
              stroke-width="1.7"
            />

            <path
              d="M8.5 12h7"
              stroke="currentColor"
              stroke-width="1.7"
              stroke-linecap="round"
            />

          </svg>

        </div>


        <h3>
          No route found
        </h3>


        <p>
          We don't have this journey
          in the database yet.
        </p>

      </div>

    `;

    return;

  }


  results.innerHTML =
    currentRoutes
      .map(
        renderRouteCard
      )
      .join("");


  results
    .querySelectorAll(
      ".view-button"
    )
    .forEach(
      button => {

        button.addEventListener(
          "click",
          event => {

            event.stopPropagation();


            const index =
              Number(
                button.dataset.routeIndex
              );


            showDetail(
              currentRoutes[index]
            );

          }
        );

      }
    );


  results
    .querySelectorAll(
      ".route-card"
    )
    .forEach(
      card => {

        card.addEventListener(
          "click",
          event => {

            if (
              event.target.closest(
                "button"
              )
            ) {
              return;
            }


            const index =
              Number(
                card.dataset.routeIndex
              );


            showDetail(
              currentRoutes[index]
            );

          }
        );


        card.addEventListener(
          "keydown",
          event => {

            if (
              event.key !== "Enter" &&
              event.key !== " "
            ) {
              return;
            }


            event.preventDefault();


            const index =
              Number(
                card.dataset.routeIndex
              );


            showDetail(
              currentRoutes[index]
            );

          }
        );

      }
    );

};


/* =========================================================
   DETAIL STOP FLOW
========================================================= */

const renderStopFlow = (
  stops
) => {

  if (
    !stops ||
    !stops.length
  ) {

    return `
      <span class="no-stops">
        Stop information unavailable
      </span>
    `;

  }


  return `

    <div class="route-stop-flow">

      ${stops
        .map(
          (stop, index) => {

            const isLast =
              index ===
              stops.length - 1;


            return `

              <span class="route-stop-pill">

                <span class="route-stop-name">
                  ${locationLabel(stop)}
                </span>

              </span>


              ${
                !isLast
                  ? `
                    <span
                      class="route-stop-arrow"
                      aria-hidden="true"
                    >
                      →
                    </span>
                  `
                  : ""
              }

            `;

          }
        )
        .join("")}

    </div>

  `;

};


/* =========================================================
   SHOW DETAIL
========================================================= */

const showDetail = (
  route
) => {

  if (!route) {
    return;
  }


  detailSection.hidden =
    false;


  const journey =
    route.legs === 1
      ? "1 vehicle"
      : `${route.legs} vehicles`;


  detailContent.innerHTML = `

    <div class="detail-stat">

      <span class="detail-label">
        Journey
      </span>

      <strong>
        ${escapeHtml(journey)}
      </strong>

    </div>


    <div class="segments">

      ${
        route.segments?.length
          ? route.segments
              .map(
                (
                  segment,
                  index
                ) => `

                  <div class="segment">

                    <div class="segment-number">
                      ${index + 1}
                    </div>


                    <div class="segment-body">

                      <p class="segment-label">
                        Take this daladala
                      </p>


                      <h3>
                        ${escapeHtml(
                          segment.route_name
                        )}
                      </h3>


                      ${
                        segment.via
                          ? `
                            <p class="segment-via">
                              Via ${escapeHtml(
                                segment.via
                              )}
                            </p>
                          `
                          : ""
                      }


                      ${
                        segment.stops?.length
                          ? `
                            <div class="segment-stop-flow">

                              ${renderStopFlow(
                                segment.stops
                              )}

                            </div>
                          `
                          : ""
                      }


                      <div class="segment-bottom">

                        <span class="segment-fare">

                          ${
                            segment.estimated_fare ===
                            null
                              ? "Fare unavailable"
                              : `${Number(
                                  segment.estimated_fare
                                ).toLocaleString()} TZS`
                          }

                        </span>

                      </div>

                    </div>

                  </div>


                  ${
                    index <
                    route.segments.length - 1
                      ? `
                        <div class="change">
                          Change vehicle here
                        </div>
                      `
                      : ""
                  }

                `
              )
              .join("")
          : `

            <div class="empty-state">

              <h3>
                Route details unavailable
              </h3>

              <p>
                Detailed journey information
                is not available yet.
              </p>

            </div>

          `
      }

    </div>

  `;


  detailSection.scrollIntoView({
    behavior: "smooth",
    block: "start"
  });


  renderRouteMap(
    route
  );

};


/* =========================================================
   ROUTE MAP
========================================================= */

const renderRouteMap = (
  route
) => {

  if (activeMap) {

    activeMap.remove();

    activeMap = null;

  }


  routeMap.innerHTML = "";


  const stops =
    Array.isArray(route?.stops)
      ? route.stops
      : [];


  const coordinates =
    stops
      .filter(
        stop =>
          Number.isFinite(
            Number(stop.latitude)
          ) &&
          Number.isFinite(
            Number(stop.longitude)
          )
      )
      .map(
        stop => [
          Number(stop.latitude),
          Number(stop.longitude),
          stop.name
        ]
      );


  if (
    !coordinates.length ||
    coordinates.length !== stops.length
  ) {

    routeMap.hidden =
      true;


    mapMessage.textContent =
      "Map unavailable: this route does not have verified location coordinates.";

    return;

  }


  if (!window.L) {

    routeMap.hidden =
      true;


    mapMessage.textContent =
      "Map unavailable while the map service is loading.";

    return;

  }


  routeMap.hidden =
    false;

  setMapExpanded(mapExpanded);

  mapMessage.textContent =
    "Map data © OpenStreetMap contributors.";


  activeMap =
    window.L.map(
      routeMap,
      {
        scrollWheelZoom: false
      }
    );

  requestAnimationFrame(() => {
    activeMap.invalidateSize();
  });


  window.L.tileLayer(
    "https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",
    {
      attribution:
        "© OpenStreetMap contributors",

      maxZoom: 19

    }
  ).addTo(
    activeMap
  );


  const points =
    coordinates.map(
      ([latitude, longitude]) => [
        latitude,
        longitude
      ]
    );

  const toXY = ([latitude, longitude]) => {
    const lat = Number(latitude);
    const lng = Number(longitude);
    const x = lng * Math.cos((lat * Math.PI) / 180);
    return [x, lat];
  };

  const pointToSegmentDistance = (point, segmentStart, segmentEnd) => {
    const [px, py] = toXY(point);
    const [x1, y1] = toXY(segmentStart);
    const [x2, y2] = toXY(segmentEnd);

    const dx = x2 - x1;
    const dy = y2 - y1;
    if (dx === 0 && dy === 0) {
      return Math.hypot(px - x1, py - y1);
    }

    const t = ((px - x1) * dx + (py - y1) * dy) / (dx * dx + dy * dy);
    const clamped = Math.max(0, Math.min(1, t));
    const closestX = x1 + clamped * dx;
    const closestY = y1 + clamped * dy;
    return Math.hypot(px - closestX, py - closestY);
  };

  const distanceToPolyline = (point, polyline) => {
    let minDistance = Infinity;
    for (let i = 0; i < polyline.length - 1; i += 1) {
      const distance = pointToSegmentDistance(point, polyline[i], polyline[i + 1]);
      if (distance < minDistance) {
        minDistance = distance;
      }
    }
    return minDistance;
  };

  const scoreRoute = (geometry, targets) => {
    const route = geometry.map(([lng, lat]) => [lat, lng]);
    return targets.reduce((score, target) => {
      return score + distanceToPolyline(target, route);
    }, 0);
  };

  const fetchRouteGeometry = async () => {
    const waypointSource = points[0];
    const waypointDestination = points[points.length - 1];
    const routeWaypoints = `${waypointSource[1]},${waypointSource[0]};${waypointDestination[1]},${waypointDestination[0]}`;

    const response = await fetch(
      `https://router.project-osrm.org/route/v1/driving/${routeWaypoints}?overview=full&geometries=geojson&steps=false&alternatives=true`
    );

    if (!response.ok) {
      throw new Error("Routing service unavailable");
    }

    const data = await response.json();
    if (data.code !== "Ok" || !data.routes?.length) {
      throw new Error("Routing service returned no route");
    }

    if (points.length <= 2 || !data.routes[0].geometry?.coordinates) {
      return data.routes[0].geometry.coordinates.map(
        ([longitude, latitude]) => [latitude, longitude]
      );
    }

    const intermediateStops = points.slice(1, -1);
    let bestRoute = data.routes[0];
    let bestScore = scoreRoute(bestRoute.geometry.coordinates, intermediateStops);

    for (let i = 1; i < data.routes.length; i += 1) {
      const candidate = data.routes[i];
      if (!candidate.geometry?.coordinates) {
        continue;
      }
      const candidateScore = scoreRoute(candidate.geometry.coordinates, intermediateStops);
      if (candidateScore < bestScore) {
        bestScore = candidateScore;
        bestRoute = candidate;
      }
    }

    return bestRoute.geometry.coordinates.map(
      ([longitude, latitude]) => [latitude, longitude]
    );
  };

  const drawRouteLine = async () => {
    let routeCoordinates = points;
    try {
      routeCoordinates = await fetchRouteGeometry();
    } catch (error) {
      console.warn("Route geometry fallback:", error);
      mapMessage.textContent =
        "Map data © OpenStreetMap contributors. Showing straight line route because routing geometry is unavailable.";
    }

    window.L.polyline(
      routeCoordinates,
      {
        color: "#087f7b",
        weight: 4,
        opacity: 1,
        lineCap: "round",
        lineJoin: "round",
        smoothFactor: 1,
        interactive: false
      }
    ).addTo(activeMap);
  };

  const createMarkerIcon = (color, size) => {
    const markerSvg = `
      <svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size * 1.5}" viewBox="0 0 24 32" aria-hidden="true">
        <path d="M12 0C5.4 0 0 5.4 0 12c0 9 12 20 12 20s12-11 12-20C24 5.4 18.6 0 12 0z" fill="${color}"/>
        <circle cx="12" cy="12" r="4.5" fill="rgba(255,255,255,0.9)"/>
      </svg>
    `;

    return window.L.icon({
      iconUrl: `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(markerSvg)}`,
      shadowUrl: "https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png",
      iconSize: [size, size * 1.5],
      iconAnchor: [size / 2, size * 1.5],
      shadowSize: [41, 41],
      shadowAnchor: [12, 41],
      popupAnchor: [0, -(size * 1.5) / 2]
    });
  };

  drawRouteLine();


  coordinates.forEach(
    (
      [latitude, longitude, name],
      index
    ) => {

      const isOrigin = index === 0;
      const isDestination = index === coordinates.length - 1;
      const markerColor = isOrigin ? "#2e9f5b" : isDestination ? "#d9534f" : "#3baa62";
      const markerSize = isOrigin || isDestination ? 22 : 12;

      const marker =
        window.L.marker([
          latitude,
          longitude
        ], {
          icon: createMarkerIcon(markerColor, markerSize)
        }).addTo(
          activeMap
        );

      marker.bindPopup(`
        <div style="
          background: #111111;
          color: #ffffff;
          font-family: inherit;
          font-size: 12px;
          line-height: 1.4;
          padding: 6px 10px;
          border-radius: 8px;
        "><strong>${escapeHtml(name)}</strong></div>
      `);

    }
  );


  activeMap.fitBounds(
    points,
    {
      padding:
        [30, 30]
    }
  );

};


/* =========================================================
   SEARCH ROUTES
========================================================= */

const searchRoutes =
  async () => {

    searchMessage.textContent =
      "";


    findRouteButton.disabled =
      true;


    findRouteButton.innerHTML = `

      <span class="button-spinner"></span>

      Checking...

    `;


    const from =
      await resolveLocation(
        "from-input",
        "from-id"
      );


    const to =
      await resolveLocation(
        "to-input",
        "to-id"
      );


    if (!from || !to) {

      searchMessage.textContent =
        "Please select valid starting and destination locations.";


      resetFindButton();

      return;

    }


    if (
      String(from) ===
      String(to)
    ) {

      searchMessage.textContent =
        "Choose two different locations.";


      resetFindButton();

      return;

    }


    findRouteButton.innerHTML = `

      <span class="button-spinner"></span>

      Finding routes...

    `;


    try {

      const response =
        await fetch(
          `${API_BASE}/routes/search?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`
        );


      if (!response.ok) {

        throw new Error(
          "Route search failed"
        );

      }


      const data =
        await response.json();


      renderResults(
        data
      );


      resultsSection.scrollIntoView({
        behavior: "smooth",
        block: "start"
      });


    } catch (error) {

      searchMessage.textContent =
        "Routes could not be loaded. Please try again.";

    } finally {

      resetFindButton();

    }

  };


/* =========================================================
   RESET FIND BUTTON
========================================================= */

const resetFindButton = () => {

  findRouteButton.disabled =
    false;


  findRouteButton.innerHTML = `

    <span>
      Find routes
    </span>

    <span
      class="button-arrow"
      aria-hidden="true"
    >
      →
    </span>

  `;

};


/* =========================================================
   FIND BUTTON
========================================================= */

if (findRouteButton) {

  findRouteButton.addEventListener(
    "click",
    searchRoutes
  );

}


/* =========================================================
   ENTER KEY
========================================================= */

[
  fromInput,
  toInput
].forEach(
  input => {

    if (!input) {
      return;
    }


    input.addEventListener(
      "keydown",
      event => {

        if (
          event.key === "Enter"
        ) {

          event.preventDefault();

          searchRoutes();

        }

      }
    );

  }
);


/* =========================================================
   CLOSE DETAIL
========================================================= */

if (closeDetailButton) {

  closeDetailButton.addEventListener(
    "click",
    () => {

      detailSection.hidden =
        true;


      if (activeMap) {

        activeMap.remove();

        activeMap = null;

      }

    }
  );

}


/* =========================================================
   QUICK OPTIONS
========================================================= */

document
  .querySelectorAll(
    ".quick-option"
  )
  .forEach(
    button => {

      button.addEventListener(
        "click",
        () => {

          document
            .querySelectorAll(
              ".quick-option"
            )
            .forEach(
              item =>
                item.classList.remove(
                  "active"
                )
            );


          button.classList.add(
            "active"
          );

        }
      );

    }
  );


/* =========================================================
   HEALTH CHECK
========================================================= */

const checkBackend =
  async () => {

    if (!backendStatus) {
      return;
    }


    try {

      const response =
        await fetch(
          `${API_BASE}/health`
        );


      if (!response.ok) {

        throw new Error(
          "Health check failed"
        );

      }


      const data =
        await response.json();


      if (
        data.status === "ok"
      ) {

        backendStatus.textContent =
          "Connected";


        backendStatusDot?.classList.add(
          "connected"
        );

      } else {

        backendStatus.textContent =
          "Unavailable";

      }


    } catch {

      backendStatus.textContent =
        "Unavailable";

    }

  };


/* =========================================================
   INITIALIZE
========================================================= */

setupLocationSearch(
  "from-input",
  "from-id",
  "from-suggestions"
);


setupLocationSearch(
  "to-input",
  "to-id",
  "to-suggestions"
);


checkBackend();


/* =========================================================
   SERVICE WORKER
========================================================= */

if (
  "serviceWorker" in navigator
) {

  window.addEventListener(
    "load",
    () => {

      const serviceWorkerUrl =
        new URL(
          "service-worker.js?v=9",
          window.location.href
        ).href;

      navigator.serviceWorker
        .register(
          serviceWorkerUrl
        )
        .catch(
          () => {
            // Service worker unavailable.
          }
        );

    }
  );

}