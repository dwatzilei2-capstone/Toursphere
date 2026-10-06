 





class AIRouteEngine {
  constructor() {
    this.map = null;
    this.mapType = "google";
    this.directionsService = null;
    this.directionsRenderer = null;
    this.trafficLayer = null;
    this.trafficEnabled = false;
    this.trafficAware = true;
    this.mapTypeSatellite = false;
    this.fullscreenListenersBound = false;
    
    this.googleMarkers = [];
    this.touristMarkers = [];

     
    this.userLocationMarker = null;
    this.userAccuracyCircle = null;
    this.userHeading = null;
    this.isNavigating = false;
    this.watchId = null;
    this.lastUserPosition = null;
    this.currentDirectionsResult = null;
    this.currentRouteSummary = null;
    this.hasLiveGpsFix = false;
    this.navigationStartedAt = 0;
    this.offRouteConsecutiveCount = 0;
    this.navigationFollowUser = false;
    this.navigationCameraInitialized = false;
    this.hasReachedPickup = false;

     
    this.currentStepIndex = 0;
    this.currentLegIndex = 0;
    this.accumulatedLegDistance = 0;
    this.accumulatedLegDuration = 0;
    this.totalLegDistance = 0;
    this.totalLegDuration = 0;
    
    this.currentMode = window.TC_INITIAL_MODE || "balanced";
    this.activePreset = null;
    this.waypoints = [];
    this.isGenerating = false;
    this.currentRouteData = null;
    this.candidatePolylines = [];
    this.selectedRoutePolyline = null;
    this.liveNavigationPolyline = null;
    this.prePickupPath = [];
    this.prePickupRoutes = [];
    this.prePickupSelectedIndex = 0;
    this.planningGpsRequested = false;
    this.preserveViewportOnRouteSwitch = false;
    this.liveNavigationRequestId = 0;
    this.liveNavigationRoutePending = false;
    this.lastLiveNavigationRequestAt = 0;
    this.lastLiveNavigationRequestPosition = null;
    this.liveNavigationRequestCooldownMs = 15000;
    this.liveNavigationRequestMinDistanceMeters = 50;
    this.cachedDirectionsResponse = null;
    this.activeDirectionsRequestSignature = null;
    this.lastDirectionsRequestSignature = null;
    this.lastDirectionsRequestCompletedAt = 0;
    this.directionsRequestCooldownMs = 10000;
    this.currentEvaluationData = null;
    this.evaluationRequestId = 0;
    this.revenueRequestId = 0;
    this.revenueScopeTimer = null;
    this.revenueScopeListenersBound = false;
    this.savedRouteSignature = null;
    this.routeSaveInFlight = null;
    this.routeContextInitialized = false;
    window.addEventListener('pagehide',()=>{ if(this.isNavigating)this.persistPlannerState().catch(()=>{}); });
    this.plannerLifecycle = 'INITIAL';
    this.plannerRevision = 0;
    this.appliedRoute = null;
    this.modeEvaluations = {};
    this.generationId = null;
    this.plannerRestorePending = false;
    this.persistQueue = Promise.resolve();
    
     
    this.vehicleFuelEconomy = {
      "Tour Bus": 3.8,
      "Coaster Bus": 6.4,
      "Executive Van": 9.2,
      "VIP SUV": 10.5
    };
    this.fuelPricePerLiter = null;
  }

  init(containerId = "map") {
    const targetId = typeof containerId === "string" ? containerId : "map";
    const container = document.getElementById(targetId) || document.getElementById("map") || document.getElementById("route-map");
    if (!container) return;

    const hasGoogleMaps = typeof google !== "undefined" && typeof google.maps !== "undefined" && typeof google.maps.Map === "function";

    if (hasGoogleMaps) {
      this.initGoogleMap(container);
    } else {
       
      if (!document.getElementById("map-loading-indicator")) {
        container.innerHTML = `
          <div id="map-loading-indicator" class="d-flex flex-column align-items-center justify-content-center h-100 p-4 text-center" style="min-height: 520px; background: #F8FAFC;">
            <div class="spinner-border text-primary mb-3" style="width: 2.5rem; height: 2.5rem;" role="status">
              <span class="visually-hidden">Loading Google Maps...</span>
            </div>
            <h6 class="fw-semibold text-dark mb-1">Loading Google Maps...</h6>
            <p class="text-muted-custom small mb-0">Connecting to Google Maps & ROUTETHINK Routing Engine</p>
          </div>
        `;
      }
    }

     
    this.bindInputValidationListeners();
    this.bindRevenueScopeListeners();
    this.applyOperationalRouteContext();

     
     
     
    if (!window.TC_ROUTE_CONTEXT?.isDriver && window.TC_INITIAL_PRESET && typeof window.TC_INITIAL_PRESET === "string" && window.TC_INITIAL_PRESET.trim() !== "") {
      if (!this.plannerRestorePending && !this.cachedDirectionsResponse) this.loadPresetInputs(window.TC_INITIAL_PRESET);
    } else {
      this.refreshRevenueCard();
    }
  }

  bindInputValidationListeners() {
    if(this.inputValidationBound)return;this.inputValidationBound=true;
    ["route-origin-input", "route-dest-input", "route-vehicle-select"].forEach((id) => {
      const el = document.getElementById(id);
      if (el) {
        el.addEventListener("input", () => el.classList.remove("is-invalid"));
        el.addEventListener("change", () => {
          el.classList.remove("is-invalid");
          if(!this.restoringPlanner && !this.isNavigating && this.generatedInputs && JSON.stringify(this.plannerInputs())!==JSON.stringify(this.generatedInputs)){
            this.invalidatePreparedRoute();
          }
        });
      }
    });
    document.getElementById('waypoints-container')?.addEventListener('input',()=>{
      if(!this.restoringPlanner && !this.isNavigating && this.generatedInputs && JSON.stringify(this.plannerInputs())!==JSON.stringify(this.generatedInputs))this.invalidatePreparedRoute();
    });
  }

  invalidatePreparedRoute() {
    this.cachedDirectionsResponse=null;this.currentRouteData=null;this.currentEvaluationData=null;
    this.currentDirectionsResult=null;this.currentRouteSummary=null;this.appliedRoute=null;
    this.modeEvaluations={};this.plannerLifecycle='INITIAL';this.savedRouteSignature=null;
    this.evaluationRequestId++;
    this.selectedRoutePolyline?.setMap(null);this.clearCandidatePolylines();
    this.directionsRenderer?.setDirections({routes:[]});
    for(const id of ['ai-res-title','ai-res-distance','ai-res-duration','ai-res-fuel','ai-res-toll','ai-res-cost','ai-res-score','ai-res-reason']){
      if(id==='ai-res-cost' && ['driver','admin'].includes(window.TC_ROUTE_REVENUE?.role))continue;
      const element=document.getElementById(id);if(element)element.textContent=id==='ai-res-title'?'Generate a route for the updated inputs.':'—';
    }
    this.updateApplyButton();
  }

  bindRevenueScopeListeners() {
    const revenue = window.TC_ROUTE_REVENUE;
    if (this.revenueScopeListenersBound || revenue?.role !== "admin") return;
    this.revenueScopeListenersBound = true;

    const scheduleRefresh = () => {
      window.clearTimeout(this.revenueScopeTimer);
      this.revenueScopeTimer = window.setTimeout(() => this.refreshRevenueCard(), 350);
    };
    ["route-origin-input", "route-dest-input"].forEach((id) => {
      const input = document.getElementById(id);
      if (input) input.addEventListener("input", scheduleRefresh);
    });
    const date = document.getElementById("route-revenue-date");
    if (date) date.addEventListener("change", scheduleRefresh);
  }

  async refreshRevenueCard() {
    const config = window.TC_ROUTE_REVENUE;
    if (!config || !["driver", "admin"].includes(config.role)) return;

    const value = document.getElementById("ai-res-cost");
    const context = document.getElementById("ai-res-cost-context");
    if (!value || !context) return;

    const params = new URLSearchParams();
    let scopeLabel = "";
    if (config.role === "driver") {
      if (!config.tripId) {
        value.textContent = "—";
        context.textContent = "No assigned trip selected.";
        return;
      }
      params.set("trip_id", config.tripId);
      scopeLabel = "Assigned trip " + config.tripId;
    } else {
      const origin = document.getElementById("route-origin-input")?.value.trim() || "";
      const destination = document.getElementById("route-dest-input")?.value.trim() || "";
      const date = document.getElementById("route-revenue-date")?.value || "";
      if (!origin || !destination || !date) {
        value.textContent = "—";
        context.textContent = "Select a route and date.";
        return;
      }
      params.set("origin", origin);
      params.set("destination", destination);
      params.set("date", date);
      scopeLabel = `${origin} → ${destination} · ${date}`;
    }

    const requestId = ++this.revenueRequestId;
    value.textContent = "…";
    context.textContent = scopeLabel;
    try {
      const response = await fetch(`${config.endpoint}?${params.toString()}`, {
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" },
      });
      const data = await response.json();
      if (requestId !== this.revenueRequestId) return;
      if (!response.ok || !data.ok) throw new Error(data.error || "Revenue is unavailable.");

      if (!data.hasRevenue) {
        value.textContent = "—";
        context.textContent = config.role === "driver"
          ? `No eligible reservations for ${data.tripId}.`
          : `No eligible reservations · ${data.origin} → ${data.destination} · ${data.date}`;
        return;
      }

      value.textContent = new Intl.NumberFormat("en-PH", {
        style: "currency",
        currency: "PHP",
        minimumFractionDigits: 2,
      }).format(Number(data.revenue));
      if (config.role === "driver") {
        const passengerLabel = Number(data.passengers) === 1 ? "Assigned Passenger" : "Assigned Passengers";
        context.textContent = `${Number(data.passengers).toLocaleString("en-PH")} ${passengerLabel} · ${data.tripId}`;
      } else {
        const tripLabel = Number(data.tripCount) === 1 ? "trip" : "trips";
        context.textContent = `${Number(data.passengers).toLocaleString("en-PH")} passengers across ${Number(data.tripCount).toLocaleString("en-PH")} ${tripLabel} · ${data.origin} → ${data.destination} · ${data.date}`;
      }
    } catch {
      if (requestId !== this.revenueRequestId) return;
      value.textContent = "—";
      context.textContent = "Revenue is temporarily unavailable.";
    }
  }

  applyOperationalRouteContext() {
    if (this.routeContextInitialized) return;
    const context = window.TC_ROUTE_CONTEXT || null;
    if (!context) return;
    this.routeContextInitialized = true;

    const container = document.getElementById("waypoints-container");
    if (container && Array.isArray(context.waypoints) && context.waypoints.length > 0) {
      container.innerHTML = "";
      context.waypoints.forEach((waypoint) => this.addWaypointField(waypoint));
    }
  }

  initMap(containerId = "map") {
    this.init(containerId);
  }

   
   
   
  initGoogleMap(container) {
    if (this.map) return;  

     
    container.innerHTML = "";

    this.mapType = "google";
    
     
    const defaultCenter = { lat: 14.8500, lng: 120.8000 };
    
    this.map = new google.maps.Map(container, {
      center: defaultCenter,
      zoom: 8,
      mapTypeId: google.maps.MapTypeId.ROADMAP,
      mapTypeControl: false,
      streetViewControl: false,
      fullscreenControl: false,
      zoomControl: true,
      zoomControlOptions: {
        position: google.maps.ControlPosition.RIGHT_BOTTOM
      },
      styles: [
        { featureType: "poi", elementType: "labels", stylers: [{ visibility: "off" }] }
      ]
    });

    this.setupFullscreenListeners();

    this.directionsService = new google.maps.DirectionsService();
    this.directionsRenderer = new google.maps.DirectionsRenderer({
      map: this.map,
      suppressMarkers: false,
       
       
      suppressPolylines: true,
       
       
      preserveViewport: true,
      polylineOptions: {
        strokeColor: "#2F80ED",
        strokeWeight: 6,
        strokeOpacity: 0.85
      }
    });

    this.trafficLayer = new google.maps.TrafficLayer();

     
    this.setupAutocomplete("route-origin-input");
    this.setupAutocomplete("route-dest-input");

     
    this.renderTouristDestinationMarkers();

     
    if (!this.plannerRestorePending && !this.plannerRestoreCompleted) this.restorePlannerState();
  }

  setupAutocomplete(inputId) {
    const input = document.getElementById(inputId);
    if (!input || input.readOnly || input.disabled || typeof google === "undefined" || !google.maps || !google.maps.places) return;

    const autocomplete = new google.maps.places.Autocomplete(input, {
      componentRestrictions: { country: "ph" },
      fields: ["formatted_address", "geometry", "name"]
    });

    autocomplete.addListener("place_changed", () => {
      const place = autocomplete.getPlace();
      if (!place.geometry || !place.geometry.location) return;
      
      if (inputId === "route-origin-input" || inputId === "route-dest-input") {
        const presetSelect = document.getElementById("route-preset-select");
        if (presetSelect) presetSelect.value = "custom";
        this.refreshRevenueCard();
      }
    });
  }

  renderTouristDestinationMarkers() {
    if (!this.map || this.mapType !== "google" || !window.TC_TOURIST_DESTINATIONS) return;

    this.clearTouristMarkers();

    const infoWindow = new google.maps.InfoWindow();

    window.TC_TOURIST_DESTINATIONS.forEach((spot) => {
      const marker = new google.maps.Marker({
        position: { lat: spot.coords[0], lng: spot.coords[1] },
        map: this.map,
        title: spot.name,
        icon: {
          path: google.maps.SymbolPath.CIRCLE,
          scale: 7,
          fillColor: "#F2994A",
          fillOpacity: 1,
          strokeColor: "#FFFFFF",
          strokeWeight: 2
        }
      });

      marker.addListener("click", () => {
        const content = `
          <div style="font-family: 'Poppins', sans-serif; padding: 4px 6px; max-width: 240px;">
            <div style="font-weight: 700; font-size: 13px; color: #1F2937; margin-bottom: 2px;">${spot.name}</div>
            <span style="display:inline-block; font-size: 10px; background: #FEF5EC; color: #F2994A; border: 1px solid #F2994A; padding: 1px 6px; border-radius: 4px; margin-bottom: 6px;">${spot.category}</span>
            <div style="font-size: 11px; color: #6B7280; margin-bottom: 8px;">${spot.description}</div>
            <div style="display: flex; gap: 4px;">
              <button onclick="aiRouteEngine.setTouristSpotAsDestination('${spot.address}')" style="background:#2F80ED; color:#fff; border:none; border-radius:4px; padding:4px 8px; font-size:11px; cursor:pointer; font-weight:600;">Set as Destination</button>
              <button onclick="aiRouteEngine.addTouristSpotAsWaypoint('${spot.address}')" style="background:#F1F5F9; color:#1F2937; border:1px solid #CBD5E1; border-radius:4px; padding:4px 8px; font-size:11px; cursor:pointer;">+ Add Stop</button>
            </div>
          </div>
        `;
        infoWindow.setContent(content);
        infoWindow.open(this.map, marker);
      });

      this.touristMarkers.push(marker);
    });
  }

  clearTouristMarkers() {
    this.touristMarkers.forEach((m) => m.setMap(null));
    this.touristMarkers = [];
  }

  setTouristSpotAsDestination(address) {
    const destInput = document.getElementById("route-dest-input");
    if (destInput) destInput.value = address;
    const presetSelect = document.getElementById("route-preset-select");
    if (presetSelect) presetSelect.value = "custom";
    if (window.showAppToast) {
      window.showAppToast("Destination Set", `Target set to ${address}`, "info");
    }
  }

  addTouristSpotAsWaypoint(address) {
    this.addWaypointField(address);
    if (window.showAppToast) {
      window.showAppToast("Stop Added", `Added ${address} to itinerary waypoints.`, "info");
    }
  }

  selectTouristDestination(destId) {
    if (!destId || !window.TC_TOURIST_DESTINATIONS) return;
    const found = window.TC_TOURIST_DESTINATIONS.find((d) => d.id === destId);
    if (found) {
      const destInput = document.getElementById("route-dest-input");
      if (destInput) destInput.value = found.address;
      const presetSelect = document.getElementById("route-preset-select");
      if (presetSelect) presetSelect.value = "custom";
      if (this.map) {
        this.map.panTo({ lat: found.coords[0], lng: found.coords[1] });
        this.map.setZoom(11);
      }
    }
  }

   
   
   
  selectMode(mode, el) {
    if (this.isNavigating || !['balanced','fastest','fuelEfficient','shortest'].includes(mode) || !this.cachedDirectionsResponse) return;
    this.currentMode = mode;
    document.querySelectorAll('.opt-option-card').forEach(card => card.classList.toggle('selected', card.dataset.mode === mode));
    this.preserveViewportOnRouteSwitch = true;
    this.updatePrePickupPathForMode();
    const evaluation = this.modeEvaluations[mode];
    if (evaluation) this.applyEvaluationResult(evaluation, this.cachedDirectionsResponse, this.candidatePayload);
    else this.processGoogleDirectionsResult(this.cachedDirectionsResponse);
    this.updateApplyButton();
    this.persistPlannerState().catch(() => {});
  }

  setRouteStrategyLocked(locked, customMessage = "") {
    const lockedMessage = document.getElementById("route-strategy-locked");
    const options = document.getElementById("route-strategy-options");
    const generateButton = document.getElementById("btn-generate-ai-route");
    this.navigationInputLocks ||= new Map();
    for(const id of ['route-origin-input','route-dest-input','route-vehicle-select','route-preset-select','traffic-aware-toggle']){
      const input=document.getElementById(id);if(!input)continue;
      if(locked){if(!this.navigationInputLocks.has(id))this.navigationInputLocks.set(id,input.disabled);input.disabled=true;}
      else if(this.navigationInputLocks.has(id)){input.disabled=this.navigationInputLocks.get(id);this.navigationInputLocks.delete(id);}
    }

    document.querySelectorAll(".opt-option-card").forEach((card) => {
      card.setAttribute("aria-disabled", locked ? "true" : "false");
      card.style.pointerEvents = locked ? "none" : "";
    });
    if (generateButton) generateButton.disabled = locked;

    if (locked) {
      const selectedCard = document.querySelector(".opt-option-card.selected .opt-title");
      const selectedLabel = selectedCard?.textContent?.replace(/\s+/g, " ").trim()
        || this.getModeTitle(this.currentMode);
      if (options) {
        options.classList.remove("is-visible");
        options.setAttribute("aria-hidden", "true");
      }
      if (lockedMessage) {
        lockedMessage.classList.remove("is-hidden");
        const message = lockedMessage.querySelector("span");
        if (message) message.textContent = customMessage || `Navigation active — Selected Route: ${selectedLabel}`;
      }
      return;
    }

    if (this.cachedDirectionsResponse?.routes?.length) {
      if (lockedMessage) lockedMessage.classList.add("is-hidden");
      if (options) {
        options.setAttribute("aria-hidden", "false");
        options.classList.add("is-visible");
      }
    }
  }

  revealRouteStrategies() {
    const lockedMessage = document.getElementById("route-strategy-locked");
    const options = document.getElementById("route-strategy-options");
    if (window.TC_ROUTE_CONTEXT?.routeModeLocked) {
      if (lockedMessage) {
        lockedMessage.classList.remove("is-hidden");
        const message = lockedMessage.querySelector("span");
        if (message) {
          const selectedLabel = window.TC_ROUTE_CONTEXT.selectedModeLabel || this.getModeTitle(this.currentMode);
          message.textContent = `Driver-selected route: ${selectedLabel}. This strategy is fixed for the trip.`;
        }
      }
      return;
    }
    if (!options || options.classList.contains("is-visible")) return;

    if (lockedMessage) lockedMessage.classList.add("is-hidden");
    options.setAttribute("aria-hidden", "false");
    window.requestAnimationFrame(() => {
      options.classList.add("is-visible");

      const reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
      window.setTimeout(() => {
        options.scrollIntoView({
          behavior: reduceMotion ? "auto" : "smooth",
          block: "center",
          inline: "nearest"
        });

        if (!reduceMotion) {
          options.classList.add("route-strategy-arrived");
          window.setTimeout(() => options.classList.remove("route-strategy-arrived"), 900);
        }
      }, reduceMotion ? 0 : 180);
    });
  }

  onVehicleChange(vehicleName) {
    if(!this.isNavigating && this.generatedInputs && JSON.stringify(this.plannerInputs())!==JSON.stringify(this.generatedInputs))this.invalidatePreparedRoute();
  }

  toggleTrafficAwareness(enabled) {
    if(this.isNavigating)return;
    const changed=this.trafficAware!==enabled;
    this.trafficAware = enabled;
    if(changed && this.currentRouteData)this.invalidatePreparedRoute();
  }

  toggleTrafficLayer() {
    if (this.mapType === "google" && this.trafficLayer) {
      this.trafficEnabled = !this.trafficEnabled;
      this.trafficLayer.setMap(this.trafficEnabled ? this.map : null);
      const btn = document.getElementById("map-btn-traffic");
      if (btn) {
        btn.classList.toggle("active", this.trafficEnabled);
      }
      if (window.showAppToast) {
        window.showAppToast("Traffic Layer", this.trafficEnabled ? "Real-Time Google Traffic Layer Active" : "Traffic Layer Turned Off", "info");
      }
    } else if (window.showAppToast) {
      window.showAppToast("Traffic Info", "Traffic layer requires an active Google Maps API key.", "warning");
    }
  }

  toggleMapType() {
    if (this.mapType === "google" && this.map) {
      this.mapTypeSatellite = !this.mapTypeSatellite;
      this.map.setMapTypeId(this.mapTypeSatellite ? google.maps.MapTypeId.HYBRID : google.maps.MapTypeId.ROADMAP);
      
      const btn = document.getElementById("map-btn-type");
      const label = document.getElementById("map-btn-type-label");
      if (btn) btn.classList.toggle("active", this.mapTypeSatellite);
      if (label) label.textContent = this.mapTypeSatellite ? "Map View" : "Satellite";
    }
  }

  setupFullscreenListeners() {
    if (this.fullscreenListenersBound) return;
    this.fullscreenListenersBound = true;

    const handleFsChange = () => {
      const container = document.getElementById("map-card-container") || document.getElementById("map");
      const isFull = !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement);
      this.syncFullscreenUI(isFull);
    };

    document.addEventListener("fullscreenchange", handleFsChange);
    document.addEventListener("webkitfullscreenchange", handleFsChange);
    document.addEventListener("mozfullscreenchange", handleFsChange);
    document.addEventListener("MSFullscreenChange", handleFsChange);
  }

  toggleFullscreen() {
    const container = document.getElementById("map-card-container") || document.getElementById("map");
    if (!container) return;

    this.setupFullscreenListeners();

    const isFull = !!(document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement || container.classList.contains("is-fullscreen"));

    if (!isFull) {
      if (container.requestFullscreen) {
        container.requestFullscreen().catch(() => container.classList.add("is-fullscreen"));
      } else if (container.webkitRequestFullscreen) {
        container.webkitRequestFullscreen();
      } else if (container.mozRequestFullScreen) {
        container.mozRequestFullScreen();
      } else if (container.msRequestFullscreen) {
        container.msRequestFullscreen();
      } else {
        container.classList.add("is-fullscreen");
      }
      this.syncFullscreenUI(true);
    } else {
      if (document.exitFullscreen) {
        document.exitFullscreen().catch(() => container.classList.remove("is-fullscreen"));
      } else if (document.webkitExitFullscreen) {
        document.webkitExitFullscreen();
      } else if (document.mozCancelFullScreen) {
        document.mozCancelFullScreen();
      } else if (document.msExitFullscreen) {
        document.msExitFullscreen();
      }
      container.classList.remove("is-fullscreen");
      this.syncFullscreenUI(false);
    }
  }

  syncFullscreenUI(isFull) {
    const btn = document.getElementById("map-btn-fullscreen");
    const icon = document.getElementById("map-fullscreen-icon");
    const label = document.getElementById("map-fullscreen-label");

    if (btn) btn.classList.toggle("active", isFull);
    if (icon) {
      icon.className = isFull ? "bi bi-fullscreen-exit" : "bi bi-fullscreen";
    }
    if (label) {
      label.textContent = isFull ? "Exit Fullscreen" : "Fullscreen";
    }

     
    setTimeout(() => {
      if (this.map && typeof google !== "undefined") {
        google.maps.event.trigger(this.map, "resize");
      }
      this.fitMapBounds();
    }, 150);
  }

  fitMapBounds() {
    if (this.selectedRoutePolyline && this.prePickupPath.length && this.map) {
      const bounds = new google.maps.LatLngBounds();
      this.selectedRoutePolyline.getPath().forEach((point) => bounds.extend(point));
      if (this.lastUserPosition) bounds.extend(this.lastUserPosition);
      if (!bounds.isEmpty()) {
        this.map.fitBounds(bounds, { top: 130, right: 70, bottom: 90, left: 70 });
        return;
      }
    }
    if (this.directionsRenderer && this.directionsRenderer.getDirections()) {
      const routeIndex = Number(this.currentEvaluationData?.selectedIndex || 0);
      const bounds = this.directionsRenderer.getDirections().routes[routeIndex]?.bounds
        || this.directionsRenderer.getDirections().routes[0]?.bounds;
      if (bounds && this.map) {
        this.map.fitBounds(bounds, { top: 90, right: 60, bottom: 80, left: 60 });
      }
    } else if (this.map) {
      this.map.setCenter({ lat: 14.8500, lng: 120.8000 });
      this.map.setZoom(8);
    }
  }

  calculateRouteBearing(userPos) {
    if (!this.currentDirectionsResult || typeof google === "undefined" || !google.maps || !google.maps.geometry) return 0;
    const path = this.getRouteRoadPath(this.getSelectedGoogleRoute());
    if (!path || path.length < 2) return 0;

    let nearestIndex = 0;
    let minDistance = Infinity;
    const userLatLng = new google.maps.LatLng(userPos.lat, userPos.lng);

    for (let i = 0; i < path.length; i++) {
      const d = google.maps.geometry.spherical.computeDistanceBetween(userLatLng, path[i]);
      if (d < minDistance) {
        minDistance = d;
        nearestIndex = i;
      }
    }

     
    let targetIndex = Math.min(nearestIndex + 3, path.length - 1);
    if (targetIndex <= nearestIndex) {
      targetIndex = Math.min(nearestIndex + 1, path.length - 1);
    }

    if (targetIndex > nearestIndex) {
      return Math.round(google.maps.geometry.spherical.computeHeading(path[nearestIndex], path[targetIndex]));
    } else if (nearestIndex > 0) {
      return Math.round(google.maps.geometry.spherical.computeHeading(path[nearestIndex - 1], path[nearestIndex]));
    }
    return 0;
  }

  updateUserLocationMarker(lat, lng, accuracy = 0, heading = null) {
    if (!this.map || typeof google === "undefined" || !google.maps) return;

    const pos = { lat, lng };
    this.lastUserPosition = pos;

    let effectiveHeading = 0;
    if (heading !== null && !isNaN(heading)) {
      effectiveHeading = Math.round(heading);
    } else {
      effectiveHeading = this.calculateRouteBearing(pos);
    }

    let iconSvg = "";
    let iconSize = 38;
    let anchorPoint = new google.maps.Point(19, 19);

    if (this.isNavigating) {
       
      iconSize = 48;
      anchorPoint = new google.maps.Point(24, 24);
      iconSvg = `
        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 48 48">
          <defs>
            <filter id="navGlow" x="-20%" y="-20%" width="140%" height="140%">
              <feDropShadow dx="0" dy="2" stdDeviation="3" flood-color="#000000" flood-opacity="0.38"/>
            </filter>
          </defs>
          <circle cx="24" cy="24" r="22" fill="#1A73E8" fill-opacity="0.22"/>
          <g transform="rotate(${effectiveHeading}, 24, 24)" filter="url(#navGlow)">
            <!-- Left Wing -->
            <polygon points="24,4 10,40 24,32" fill="#2F80ED" stroke="#FFFFFF" stroke-width="2.5" stroke-linejoin="round"/>
            <!-- Right Wing / 3D Shadow -->
            <polygon points="24,4 38,40 24,32" fill="#1A73E8" stroke="#FFFFFF" stroke-width="2.5" stroke-linejoin="round"/>
            <!-- Core Center -->
            <circle cx="24" cy="24" r="3.5" fill="#FFFFFF"/>
          </g>
        </svg>
      `;
    } else {
       
      const hasHeading = (heading !== null && !isNaN(heading));
      iconSvg = `
        <svg xmlns="http://www.w3.org/2000/svg" width="38" height="38" viewBox="0 0 38 38">
          <circle cx="19" cy="19" r="16" fill="#1A73E8" fill-opacity="0.22"/>
          <circle cx="19" cy="19" r="8" fill="#1A73E8" stroke="#FFFFFF" stroke-width="2.5"/>
          ${hasHeading ? `<polygon points="19,3 25,14 19,11 13,14" fill="#1A73E8" stroke="#FFFFFF" stroke-width="1.2" transform="rotate(${effectiveHeading}, 19, 19)"/>` : ''}
        </svg>
      `;
    }

    const icon = {
      url: "data:image/svg+xml;charset=UTF-8," + encodeURIComponent(iconSvg),
      anchor: anchorPoint,
      scaledSize: new google.maps.Size(iconSize, iconSize)
    };

    if (!this.userLocationMarker) {
      this.userLocationMarker = new google.maps.Marker({
        position: pos,
        map: this.map,
        title: "Your GPS Location",
        icon: icon,
        zIndex: 9999
      });
    } else {
      this.userLocationMarker.setPosition(pos);
      this.userLocationMarker.setIcon(icon);
      this.userLocationMarker.setMap(this.map);
    }

    if (accuracy && accuracy > 0) {
      if (!this.userAccuracyCircle) {
        this.userAccuracyCircle = new google.maps.Circle({
          map: this.map,
          center: pos,
          radius: Math.min(accuracy, 250),
          fillColor: "#1A73E8",
          fillOpacity: 0.08,
          strokeColor: "#1A73E8",
          strokeOpacity: 0.25,
          strokeWeight: 1,
          zIndex: 9998
        });
      } else {
        this.userAccuracyCircle.setCenter(pos);
        this.userAccuracyCircle.setRadius(Math.min(accuracy, 250));
        this.userAccuracyCircle.setMap(this.map);
      }
    }
  }

  useCurrentLocationAsOrigin() {
    if (!navigator.geolocation) {
      if (window.showAppToast) window.showAppToast("Geolocation Unavailable", "GPS geolocation is not supported by your browser.", "danger");
      return;
    }

     
    const locateBtn = document.getElementById("map-btn-locate");
    const originalHTML = locateBtn ? locateBtn.innerHTML : "";
    if (locateBtn) {
      locateBtn.innerHTML = `<span class="spinner-border spinner-border-sm text-success" role="status"></span><span>Locating...</span>`;
      locateBtn.disabled = true;
    }

    if (window.showAppToast) {
      window.showAppToast("Acquiring GPS", "Requesting your real device location...", "info");
    }

    navigator.geolocation.getCurrentPosition(
      (pos) => {
         
        if (locateBtn) {
          locateBtn.innerHTML = originalHTML;
          locateBtn.disabled = false;
        }

        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        const accuracy = pos.coords.accuracy || 0;
        const heading = pos.coords.heading;
        const originInput = document.getElementById("route-origin-input");

        this.updateUserLocationMarker(lat, lng, accuracy, heading);
        this.hasLiveGpsFix = true;

        if (this.map) {
          this.map.panTo({ lat, lng });
          this.map.setZoom(15);
        }

        if (typeof google !== "undefined" && google.maps && google.maps.Geocoder) {
          const geocoder = new google.maps.Geocoder();
          geocoder.geocode({ location: { lat, lng } }, (results, status) => {
            if (status === "OK" && results && results[0]) {
              if (originInput) {
                originInput.value = results[0].formatted_address;
                originInput.classList.remove("is-invalid");
              }
            } else {
              if (originInput) {
                originInput.value = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
                originInput.classList.remove("is-invalid");
              }
            }
            if (window.showAppToast) window.showAppToast("GPS Acquired", "Origin set to your current GPS location.", "success");
          });
        } else {
          if (originInput) {
            originInput.value = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            originInput.classList.remove("is-invalid");
          }
          if (window.showAppToast) window.showAppToast("GPS Acquired", "Origin set to GPS coordinates.", "success");
        }
      },
      (err) => {
         
        if (locateBtn) {
          locateBtn.innerHTML = originalHTML;
          locateBtn.disabled = false;
        }

        let msg = "Unable to retrieve your GPS coordinates.";
        let detail = "";
        if (err.code === 1) {
          msg = "Location Permission Denied";
          detail = "Please allow location access in your browser or device settings, then try again.";
        } else if (err.code === 2) {
          msg = "Location Unavailable";
          detail = "Your device GPS signal could not be determined. Please move to an open area and try again.";
        } else if (err.code === 3) {
          msg = "Location Request Timed Out";
          detail = "The GPS request took too long. Please check your connection and try again.";
        }
        if (window.showAppToast) window.showAppToast(msg, detail || msg, "danger");
      },
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
    );
  }

  acquireDriverPlanningPosition() {
    if (!window.TC_ROUTE_CONTEXT?.isDriver || this.planningGpsRequested || !navigator.geolocation) return;
    this.planningGpsRequested = true;

    navigator.geolocation.getCurrentPosition(
      (pos) => {
        this.planningGpsRequested = false;
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        this.lastUserPosition = { lat, lng };
        this.hasLiveGpsFix = true;
        this.updateUserLocationMarker(lat, lng, pos.coords.accuracy || 0, pos.coords.heading);
        this.computeDriverToPickupPath(lat, lng);
      },
      () => {
        this.planningGpsRequested = false;
        if (window.showAppToast) {
          window.showAppToast(
            "Driver Location Unavailable",
            "The assigned A-to-B route is shown. Allow location access to include the Driver-to-A pickup leg.",
            "warning"
          );
        }
      },
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 15000 }
    );
  }

  computeDriverToPickupPath(lat, lng) {
    const pickup = document.getElementById("route-origin-input")?.value.trim();
    if (!pickup || !this.directionsService) return;

    const request = {
      origin: { lat, lng },
      destination: pickup,
      travelMode: google.maps.TravelMode.DRIVING,
      provideRouteAlternatives: true
    };
    if (this.trafficAware) {
      request.drivingOptions = {
        departureTime: new Date(Date.now() + 5 * 60 * 1000),
        trafficModel: google.maps.TrafficModel.BEST_GUESS
      };
    }

    this.directionsService.route(request, (response, status) => {
      if (status !== google.maps.DirectionsStatus.OK || !response?.routes?.[0]?.overview_path) return;
      this.prePickupRoutes = response.routes;
      this.updatePrePickupPathForMode();
    });
  }

  updatePrePickupPathForMode() {
    if (!this.prePickupRoutes.length) return;

    const routeMetrics = this.prePickupRoutes.map((route, index) => {
      let distanceMeters = 0;
      let durationSeconds = 0;
      let baseDurationSeconds = 0;
      (route.legs || []).forEach((leg) => {
        distanceMeters += leg.distance?.value || 0;
        durationSeconds += leg.duration_in_traffic?.value || leg.duration?.value || 0;
        baseDurationSeconds += leg.duration?.value || 0;
      });
      const trafficRatio = baseDurationSeconds > 0 ? Math.max(1, durationSeconds / baseDurationSeconds) : 1;
      return {
        index,
        distanceMeters,
        durationSeconds,
         
        fuelScore: distanceMeters * (1 + 0.40 * (trafficRatio - 1))
      };
    });

    let selected = routeMetrics[0];
    if (this.currentMode === "fastest") {
      selected = routeMetrics.reduce((best, item) => item.durationSeconds < best.durationSeconds ? item : best);
    } else if (this.currentMode === "shortest") {
      selected = routeMetrics.reduce((best, item) => item.distanceMeters < best.distanceMeters ? item : best);
    } else if (this.currentMode === "fuelEfficient") {
      selected = routeMetrics.reduce((best, item) => item.fuelScore < best.fuelScore ? item : best);
    }

    this.prePickupPath = this.getRouteRoadPath(this.prePickupRoutes[selected.index]);
    this.prePickupSelectedIndex = selected.index;
    const mapContainer = document.getElementById("map-card-container");
    if (mapContainer) {
      mapContainer.dataset.approachRouteIndex = String(selected.index);
      mapContainer.dataset.approachCandidateCount = String(this.prePickupRoutes.length);
    }
    this.refreshSelectedRoutePolyline();
  }

  getSelectedGoogleRoute() {
    if (!this.currentDirectionsResult?.routes?.length) return null;
    const selectedIndex = Number(this.currentEvaluationData?.selectedIndex || 0);
    return this.currentDirectionsResult.routes[selectedIndex] || this.currentDirectionsResult.routes[0];
  }

  // Step paths retain road bends that Google's simplified overview omits.
  getRouteRoadPath(route) {
    const path = [];
    for (const leg of route?.legs || []) {
      for (const step of leg.steps || []) {
        const points = step.path?.getArray ? step.path.getArray() : step.path;
        if (!points?.length) return Array.from(route?.overview_path || []);
        for (const point of points) {
          const previous = path[path.length - 1];
          if (!previous || !previous.equals?.(point)) path.push(point);
        }
      }
    }
    return path.length ? path : Array.from(route?.overview_path || []);
  }

  getDriverPlanningPath(route) {
    const tripPath = this.getRouteRoadPath(route);
    if (!this.prePickupPath.length) return tripPath;
     
     
    return [...this.prePickupPath, ...tripPath.slice(1)];
  }

  refreshSelectedRoutePolyline() {
    const chosenRoute = this.getSelectedGoogleRoute();
    if (!chosenRoute || !this.selectedRoutePolyline) return;
    this.selectedRoutePolyline.setPath(this.getDriverPlanningPath(chosenRoute));
    if (!this.liveNavigationPolyline) this.selectedRoutePolyline.setMap(this.map);
    if (!this.preserveViewportOnRouteSwitch) this.fitMapBounds();
  }

  locateUser() {
    if (this.isNavigating) {
      this.recenterNavigation();
    } else if (window.TC_ROUTE_CONTEXT?.isDriver) {
       
       
      this.acquireNavigationPosition(true);
    } else {
      this.useCurrentLocationAsOrigin();
    }
  }

  addWaypointField(value = "") {
    const container = document.getElementById("waypoints-container");
    if (!container) return;

    const wpIndex = container.children.length + 1;
    const wpId = `wp-input-${Date.now()}-${wpIndex}`;

    const row = document.createElement("div");
    row.className = "input-group input-group-sm mb-1 waypoint-row";
    const locked = Boolean(window.TC_ROUTE_CONTEXT?.isDriver);
    const badge = document.createElement("span");
    badge.className = "input-group-text bg-light text-primary";
    badge.style.cssText = "font-size:11px; font-weight:700;";
    badge.textContent = String(wpIndex);

    const input = document.createElement("input");
    input.type = "text";
    input.className = "form-control waypoint-input";
    input.id = wpId;
    input.placeholder = "Enter stop or waypoint...";
    input.value = value;
    input.readOnly = locked;
    row.appendChild(badge);
    row.appendChild(input);

    if (!locked) {
      const removeButton = document.createElement("button");
      removeButton.type = "button";
      removeButton.className = "btn btn-outline-danger btn-sm";
      removeButton.title = "Remove stop";
      removeButton.innerHTML = `<i class="bi bi-x"></i>`;
      removeButton.addEventListener("click", () => this.removeWaypointField(removeButton));
      row.appendChild(removeButton);
    }
    container.appendChild(row);
    if(!this.restoringPlanner && !this.isNavigating && this.generatedInputs)this.invalidatePreparedRoute();

    if (this.mapType === "google") {
      this.setupAutocomplete(wpId);
    }
  }

  removeWaypointField(btn) {
    const row = btn.closest(".waypoint-row");
    if (row) {
      row.remove();
      this.renumberWaypoints();
      if(!this.restoringPlanner && !this.isNavigating && this.generatedInputs)this.invalidatePreparedRoute();
    }
  }

  renumberWaypoints() {
    const container = document.getElementById("waypoints-container");
    if (!container) return;
    const rows = container.querySelectorAll(".waypoint-row");
    rows.forEach((r, idx) => {
      const badge = r.querySelector(".input-group-text");
      if (badge) badge.textContent = idx + 1;
    });
  }

  getWaypointsList() {
    const container = document.getElementById("waypoints-container");
    if (!container) return [];
    const inputs = container.querySelectorAll(".waypoint-input");
    const list = [];
    inputs.forEach((inp) => {
      const val = inp.value.trim();
      if (val) list.push(val);
    });
    return list;
  }

  setPreset(presetId) {
    if (!presetId || presetId === "custom" || !window.TC_ROUTE_DATA) {
      this.activePreset = null;
      this.refreshRevenueCard();
      return;
    }
    const found = window.TC_ROUTE_DATA.find((p) => p.id === presetId);
    if (found) {
      this.activePreset = found;
      const originInput = document.getElementById("route-origin-input");
      const destInput = document.getElementById("route-dest-input");
      const vehicleSelect = document.getElementById("route-vehicle-select");

      if (originInput) {
        originInput.value = found.origin;
        originInput.classList.remove("is-invalid");
      }
      if (destInput) {
        destInput.value = found.destination;
        destInput.classList.remove("is-invalid");
      }
      if (vehicleSelect && found.recommendedVehicle) {
        for (let i = 0; i < vehicleSelect.options.length; i++) {
          if (vehicleSelect.options[i].value.includes(found.recommendedVehicle) || vehicleSelect.options[i].text.includes(found.recommendedVehicle)) {
            vehicleSelect.selectedIndex = i;
            vehicleSelect.classList.remove("is-invalid");
            break;
          }
        }
      }

       
      const container = document.getElementById("waypoints-container");
      if (container) {
        container.innerHTML = "";
        if (found.defaultWaypoints) {
          found.defaultWaypoints.forEach((wp) => this.addWaypointField(wp.name));
        }
      }

      this.renderCurrentPreset();
      this.updateResultsUI();
      this.refreshRevenueCard();
    }
  }

  renderCurrentPreset() {
    if (!this.loadingPreset) this.generateRoute();
  }

   
   
   
  generateRoute() {
    const originEl = document.getElementById("route-origin-input");
    const destEl = document.getElementById("route-dest-input");
    const vehicleEl = document.getElementById("route-vehicle-select");

    const origin = originEl?.value.trim();
    const destination = destEl?.value.trim();
    const vehicle = vehicleEl?.value.trim();
    const waypointsList = this.getWaypointsList();
     
     
    this.preserveViewportOnRouteSwitch = false;

    let hasError = false;
    let firstErrorEl = null;

    if (!origin) {
      originEl?.classList.add("is-invalid");
      if (!firstErrorEl) firstErrorEl = originEl;
      hasError = true;
    } else {
      originEl?.classList.remove("is-invalid");
    }

    if (!destination) {
      destEl?.classList.add("is-invalid");
      if (!firstErrorEl) firstErrorEl = destEl;
      hasError = true;
    } else {
      destEl?.classList.remove("is-invalid");
    }

    if (!vehicle) {
      vehicleEl?.classList.add("is-invalid");
      if (!firstErrorEl) firstErrorEl = vehicleEl;
      hasError = true;
    } else {
      vehicleEl?.classList.remove("is-invalid");
    }

    if (hasError) {
      if (firstErrorEl) firstErrorEl.focus();
      if (window.showAppToast) {
        window.showAppToast(
          "Required Information Missing",
          "Please enter Departure Origin, Final Destination, and select an Assigned Vehicle before planning the route.",
          "warning"
        );
      }
      return;
    }

    if (this.isNavigating || this.isGenerating || this.plannerRestorePending) return;
    if (this.cachedDirectionsResponse && JSON.stringify(this.plannerInputs()) === JSON.stringify(this.generatedInputs)) {
      return this.applySelectedRoute();
    }
    this.appliedRoute = null;
    this.modeEvaluations = {};
    this.generationId = crypto.randomUUID();
    this.generatedInputs = this.plannerInputs();
    this.plannerLifecycle = 'INITIAL';

    const requestSignature = JSON.stringify({
      origin: origin.toLowerCase(),
      destination: destination.toLowerCase(),
      waypoints: waypointsList.map((waypoint) => waypoint.toLowerCase()),
      mode: this.currentMode,
      trafficAware: this.trafficAware
    });
    const now = Date.now();
    if (
      this.isGenerating ||
      this.activeDirectionsRequestSignature === requestSignature
    ) {
      return;
    }

     
     
    this.acquireDriverPlanningPosition();

     
    this.savedRouteSignature = null;
    this.isGenerating = true;
    this.activeDirectionsRequestSignature = requestSignature;
    const btn = document.getElementById("btn-generate-ai-route");
    if (btn) {
      btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status"></span>ROUTETHINK Optimizing...`;
      btn.disabled = true;
    }

    if (this.mapType === "google" && this.directionsService) {
      const waypoints = waypointsList.map((wp) => ({ location: wp, stopover: true }));
      
      const request = {
        origin: origin,
        destination: destination,
        waypoints: waypoints,
        optimizeWaypoints: false,
        provideRouteAlternatives: true,  
        travelMode: google.maps.TravelMode.DRIVING
      };

      if (this.trafficAware) {
        request.drivingOptions = {
           
           
          departureTime: new Date(Date.now() + 5 * 60 * 1000),
          trafficModel: google.maps.TrafficModel.BEST_GUESS
        };
      }

      if (this.directionsRenderer) {
        this.directionsRenderer.setOptions({
          polylineOptions: {
            strokeColor: "#2F80ED",
            strokeWeight: 6,
            strokeOpacity: 0.90,
            zIndex: 10
          }
        });
      }

      let retriedWithoutTrafficTime = false;
      const handleDirectionsResponse = (response, status) => {
         
         
         
         
        if (
          status === google.maps.DirectionsStatus.INVALID_REQUEST &&
          request.drivingOptions &&
          !retriedWithoutTrafficTime
        ) {
          retriedWithoutTrafficTime = true;
          delete request.drivingOptions;
          this.directionsService.route(request, handleDirectionsResponse);
          return;
        }

        this.isGenerating = false;
        this.activeDirectionsRequestSignature = null;
        this.lastDirectionsRequestSignature = requestSignature;
        this.lastDirectionsRequestCompletedAt = Date.now();
        if (btn) {
          btn.innerHTML = `<i class="bi bi-stars me-1"></i> Generate ROUTETHINK Route`;
          btn.disabled = false;
        }

        if (status === google.maps.DirectionsStatus.OK) {
          this.cachedDirectionsResponse = response;
          this.directionsRenderer.setDirections(response);
          this.processGoogleDirectionsResult(response);
          if (window.showAppToast && !window.TC_ROUTE_CONTEXT?.resumeNavigation) {
            window.showAppToast("ROUTETHINK Complete", `Evaluated ${response.routes.length} candidate road corridor(s) with live traffic & fuel modeling.`, "success");
          }
        } else {
           
          const errorMessages = {
            "ZERO_RESULTS": "No route could be found between the origin and destination. Try different addresses.",
            "NOT_FOUND": "One or both locations could not be geocoded. Please check the addresses.",
            "MAX_WAYPOINTS_EXCEEDED": "Too many waypoints. The limit is 10 intermediate stops.",
            "MAX_ROUTE_LENGTH_EXCEEDED": "The route is too long. Try splitting it into shorter segments.",
            "REQUEST_DENIED": "Google Maps API request was denied. Check your API key and billing.",
            "OVER_QUERY_LIMIT": "Google Maps API quota exceeded. Please try again later."
          };
          const msg = errorMessages[status] || `Route calculation failed (status: ${status}). Please verify your inputs and try again.`;
          if (window.showAppToast) {
            window.showAppToast("Route Calculation Failed", msg, "danger");
          }
          const titleEl = document.getElementById("ai-res-title");
          if (titleEl) {
            titleEl.textContent = msg;
            titleEl.className = "fw-bold small text-danger mb-3";
          }
        }
      };
      this.directionsService.route(request, handleDirectionsResponse);
    } else {
      this.isGenerating = false;
      this.activeDirectionsRequestSignature = null;
      if (btn) {
        btn.innerHTML = `<i class="bi bi-stars me-1"></i> Generate ROUTETHINK Route`;
        btn.disabled = false;
      }
      if (window.showAppToast) {
        window.showAppToast("Google Maps Required", "The Google Maps API is not loaded. Please ensure the API key is configured in config/maps.php and the page is reloaded.", "danger");
      }
      const titleEl = document.getElementById("ai-res-title");
      if (titleEl) {
        titleEl.textContent = "Google Maps API is not available. Please configure your API key in config/maps.php.";
        titleEl.className = "fw-bold small text-danger mb-3";
      }
    }
  }

  clearCandidatePolylines() {
    if (this.candidatePolylines && Array.isArray(this.candidatePolylines)) {
      this.candidatePolylines.forEach((p) => p.setMap(null));
    }
    this.candidatePolylines = [];
  }

  evaluateCandidatesLocally(candidates) {
    const weightsByMode = {
      fastest: { time: 0.70, fuel: 0.10, distance: 0.10, cost: 0.10 },
      shortest: { time: 0.10, fuel: 0.10, distance: 0.70, cost: 0.10 },
      fuelEfficient: { time: 0.15, fuel: 0.65, distance: 0.10, cost: 0.10 },
      balanced: { time: 0.30, fuel: 0.30, distance: 0.20, cost: 0.20 }
    };
    const weights = weightsByMode[this.currentMode] || weightsByMode.balanced;
    const vehicleName = document.getElementById("route-vehicle-select")?.value || "Tour Bus";
    const economy = this.getVehicleEconomy(vehicleName);
    const configuredPrice=Number(document.getElementById('route-vehicle-select')?.selectedOptions[0]?.dataset.fuelPrice);
    this.fuelPricePerLiter=configuredPrice>0?configuredPrice:null;
    const vehicleLabel = vehicleName.toLowerCase();
    const vehicleCapacity = Number(document.getElementById("route-vehicle-select")?.selectedOptions[0]?.dataset.capacity) || (vehicleLabel.includes("tour bus") ? 45
      : vehicleLabel.includes("coaster") ? 29
        : vehicleLabel.includes("suv") ? 7 : 14);
    const vehicleWeightClass = vehicleLabel.includes("tour bus") ? 3
      : vehicleLabel.includes("coaster") ? 2 : 1;
    const passengerCount = Number(window.TC_ROUTE_CONTEXT?.passengerCount || 0);
    const waypointCount = this.getWaypointsList().length;
    const evaluated = candidates.map((candidate) => {
      const trafficRatio = Math.max(1, candidate.trafficDelayRatio || 1);
      const exactDistanceKm = (candidate.distanceMeters || (candidate.distanceKm * 1000)) / 1000;
      const exactDurationSecs = candidate.durationSecs || (candidate.durationMins * 60);
      const loadRatio=Math.min(1,passengerCount/Math.max(1,vehicleCapacity));
      const fuel = economy>0 ? (exactDistanceKm / economy) * (1 + 0.40 * Math.min(2,trafficRatio - 1)) * (1 + 0.12 * loadRatio) + 0.20 * waypointCount : null;
      const toll = null; // No configured toll source exists; exclude it from operational estimates.
      const fuelCost = fuel!==null && this.fuelPricePerLiter!==null ? fuel * this.fuelPricePerLiter : null;
      return {
        ...candidate,
        exactDistanceMeters: candidate.distanceMeters || (candidate.distanceKm * 1000),
        exactDurationSecs,
        exactFuelEstimateLiters: fuel,
        fuelEstimateLiters: fuel===null?null:Number(fuel.toFixed(2)),
        fuelCost: fuelCost===null?null:Math.round(fuelCost),
        tollEstimate: toll,
        totalTripCost: fuelCost===null?null:Math.round(fuelCost),
        features: {
          distance_km: exactDistanceKm,
          base_duration_mins: candidate.baseDurationMins,
          traffic_duration_mins: candidate.durationMins,
          traffic_delay_ratio: trafficRatio,
          waypoint_count: waypointCount,
          baseline_km_per_liter: economy,
          vehicle_weight_class: vehicleWeightClass,
          passenger_load_ratio: Math.min(1, Math.max(0, passengerCount / vehicleCapacity)),
          highway_ratio: candidate.highwayRatio || 0
        }
      };
    });
    const values = (key) => evaluated.map((candidate) => candidate[key]);
    const normalize = (value, list) => {
      const min = Math.min(...list);
      const span = Math.max(0.001, Math.max(...list) - min);
      return evaluated.length > 1 ? (value - min) / span : 0;
    };
    const distances = values("exactDistanceMeters");
    const durations = values("exactDurationSecs");
    const fuels = values("exactFuelEstimateLiters");
    const costs = values("totalTripCost");
    let selectedIndex = 0;
    let bestScore = -Infinity;
    evaluated.forEach((candidate, index) => {
      const penalty = weights.time * normalize(candidate.exactDurationSecs, durations)
        + weights.fuel * normalize(candidate.exactFuelEstimateLiters, fuels)
        + weights.distance * normalize(candidate.exactDistanceMeters, distances)
        + weights.cost * normalize(candidate.totalTripCost, costs);
      candidate.compositeScore = Math.max(50, Math.min(99, Math.round(100 * (1 - penalty))));
      if (-penalty > bestScore) {
        bestScore = -penalty;
        selectedIndex = index;
      }
    });

     
     
    if (this.currentMode === "fastest") {
      selectedIndex = evaluated.reduce((best, item, index, list) =>
        item.exactDurationSecs < list[best].exactDurationSecs ? index : best, 0);
    } else if (this.currentMode === "shortest") {
      selectedIndex = evaluated.reduce((best, item, index, list) =>
        item.exactDistanceMeters < list[best].exactDistanceMeters ? index : best, 0);
    } else if (this.currentMode === "fuelEfficient") {
      selectedIndex = evaluated.reduce((best, item, index, list) =>
        item.exactFuelEstimateLiters < list[best].exactFuelEstimateLiters ? index : best, 0);
    }
    const selected = evaluated[selectedIndex];
    const hours = Math.floor(selected.durationMins / 60);
    const minutes = selected.durationMins % 60;
    return {
      modeTitle: this.getModeTitle(this.currentMode),
      selectedIndex,
      selectedCandidate: selected,
      candidates: evaluated,
      distance: `${selected.distanceKm.toFixed(1)} km`,
      duration: hours ? `${hours} hr${hours > 1 ? "s" : ""} ${minutes} min${minutes !== 1 ? "s" : ""}` : `${minutes} mins`,
      fuelEstimate: selected.fuelEstimateLiters===null?'Efficiency unavailable':`${selected.fuelEstimateLiters.toFixed(1)} L`,
      fuelCost: selected.fuelCost===null?'Current fuel price unavailable':`₱${selected.fuelCost.toLocaleString()}`,
      tollEstimate: 'Not Included in Estimate',
      totalTripCost: selected.totalTripCost===null?'Not Available':`₱${selected.totalTripCost.toLocaleString()}`,
      routeScore: selected.compositeScore,
      explanation: `Selected ${selected.summary} as the best available candidate for ${this.getModeTitle(this.currentMode)}.`,
      modelVersion: "v0-kinematic",
      isMlActive: false
    };
  }

  updateStrategyRouteNotes(candidatePayload, learnedSelections = null) {
    if (!candidatePayload.length) return;
    const originalMode = this.currentMode;
    const selections = {};
    ["balanced", "fuelEfficient", "fastest", "shortest"].forEach((mode) => {
      this.currentMode = mode;
      selections[mode] = learnedSelections?.[mode] ?? this.evaluateCandidatesLocally(candidatePayload).selectedIndex;
    });
    this.currentMode = originalMode;

    document.querySelectorAll(".opt-option-card").forEach((card) => {
      card.querySelector(".route-google-choice-note")?.remove();
      const mode = card.dataset.mode;
      const selectedIndex = selections[mode];
      const note = document.createElement("div");
      note.className = "route-google-choice-note mt-1";
      note.style.fontSize = "10px";
      if (mode !== "balanced" && selectedIndex === selections.balanced) {
        note.className += " text-primary";
        note.textContent = "Same Google road is also best for this objective.";
      } else {
        note.className += " text-muted-custom";
        note.textContent = `Uses verified Google route ${selectedIndex + 1} of ${candidatePayload.length}.`;
      }
      card.appendChild(note);
    });
  }

  applyEvaluationResult(aiData, result, candidatePayload) {
    const requestedIndex = Number(aiData.selectedIndex);
    const selectedIdx = Number.isInteger(requestedIndex) && requestedIndex >= 0 && requestedIndex < result.routes.length
      ? requestedIndex
      : 0;
    const selectedCandidate = aiData.selectedCandidate || candidatePayload[selectedIdx] || candidatePayload[0];
    if (!selectedCandidate) return;

    if (this.directionsRenderer) {
       
       
      this.directionsRenderer.setOptions({ suppressPolylines: true, preserveViewport: true });
      this.directionsRenderer.setDirections(result);
      this.directionsRenderer.setRouteIndex(selectedIdx);
    }
    this.clearCandidatePolylines();

    this.currentDirectionsResult = result;
    this.currentEvaluationData = aiData;
    const chosenRoute = result.routes[selectedIdx] || result.routes[0];
     
    if (this.selectedRoutePolyline) {
      this.selectedRoutePolyline.setMap(null);
    }
    this.selectedRoutePolyline = new google.maps.Polyline({
       
       
       
      map: this.liveNavigationPolyline ? null : this.map,
      path: this.getDriverPlanningPath(chosenRoute),
      strokeColor: "#1565D8",
      strokeWeight: 8,
      strokeOpacity: 1,
      clickable: false,
      zIndex: 30
    });
    const mapContainer = document.getElementById("map-card-container");
    if (mapContainer) {
      mapContainer.dataset.routeMode = this.currentMode;
      mapContainer.dataset.tripRouteIndex = String(selectedIdx);
      mapContainer.dataset.tripCandidateCount = String(result.routes.length);
      mapContainer.dataset.combinedPathPoints = String(this.selectedRoutePolyline.getPath().getLength());
    }
    const legsData = selectedCandidate.legs || selectedCandidate.rawRoute?.legs || candidatePayload[selectedIdx]?.legs || [];
    this.currentRouteSummary = {
      totalKm: selectedCandidate.distanceKm,
      totalMeters: selectedCandidate.distanceKm * 1000,
      totalSeconds: selectedCandidate.durationMins * 60,
      totalSecondsBase: selectedCandidate.baseDurationMins * 60,
      durationStr: aiData.duration,
      hasTrafficData: this.trafficAware,
      legs: legsData,
      destination: chosenRoute.legs[chosenRoute.legs.length - 1]?.end_address || "Destination"
    };
    this.currentRouteData = {
      routeId: `${this.generationId}:${this.currentMode}:${selectedIdx}`,
      mode: this.currentMode,
      title: `ROUTETHINK: ${aiData.modeTitle}`,
      distance: aiData.distance,
      distanceKm: selectedCandidate.distanceKm,
      duration: aiData.duration,
      durationMins: selectedCandidate.durationMins,
      fuelEstimate: selectedCandidate.fuelCost === null
        ? `${aiData.fuelEstimate} · ${!aiData.vehicleSpecs?.efficiency_available ? "Add vehicle fuel efficiency in Fleet & Vehicles to estimate fuel cost." : "Configure the current fuel price in Settings → Fuel Prices."}`
        : `${aiData.fuelEstimate} (${aiData.fuelCost})`,
      fuelEstimateLiters: selectedCandidate.fuelEstimateLiters,
      tollEstimate: aiData.tollEstimate,
      totalTripCost: aiData.totalTripCost,
      routeScore: `${aiData.routeScore} / 100`,
      reason: aiData.explanation || aiData.reason,
      modelVersion: aiData.modelVersion || "v0-kinematic",
      isMlActive: aiData.isMlActive,
      isFullyMlActive: Boolean(aiData.isFullyMlActive || selectedCandidate.isFullyMlActive),
      fuelModelVersion: aiData.fuelModelVersion || selectedCandidate.fuelModelVersion || null,
      durationModelVersion: aiData.durationModelVersion || selectedCandidate.durationModelVersion || null,
      legs: legsData,
      candidates: aiData.candidates || []
    };
    const navBtn = document.getElementById("btn-start-navigation");
    if (navBtn) {
      navBtn.style.display = this.isNavigating ? "none" : "block";
      navBtn.disabled = this.isNavigating;
      if (!this.isNavigating) {
        navBtn.innerHTML = `<i class="bi bi-compass-fill me-2 fs-6"></i> START NAVIGATION`;
      }
    }
    this.updateResultsUI();
    this.updateDispatchStartButton();
    this.updateApplyButton();
    if (!this.isNavigating && !this.preserveViewportOnRouteSwitch) this.fitMapBounds();

  }

  processGoogleDirectionsResult(result) {
    if (!result || !result.routes || result.routes.length === 0) return;
    this.cachedDirectionsResponse = result;
    this.generationId ||= crypto.randomUUID();
    this.generatedInputs = this.plannerInputs();
    this.plannerLifecycle = this.appliedRoute ? 'APPLIED' : 'GENERATED';
    this.revealRouteStrategies();

    const vehicleSelect = document.getElementById("route-vehicle-select");
    const vehicleValue = vehicleSelect ? vehicleSelect.value : "Tour Bus";
    const origin = document.getElementById("route-origin-input")?.value.trim() || "Origin";
    const destination = document.getElementById("route-dest-input")?.value.trim() || "Destination";
    const waypointsList = this.getWaypointsList();

     
    const candidatePayload = result.routes.map((r, idx) => {
      let totalMeters = 0;
      let totalSecondsTraffic = 0;
      let totalSecondsBase = 0;
      let highwayMeters = 0;
      const legsData = [];

      r.legs.forEach((leg) => {
        totalMeters += leg.distance.value;
        totalSecondsTraffic += (leg.duration_in_traffic ? leg.duration_in_traffic.value : leg.duration.value);
        totalSecondsBase += leg.duration.value;
        (leg.steps || []).forEach((step) => {
          const roadText = `${step.instructions || ""} ${step.html_instructions || ""}`.replace(/<[^>]*>/g, " ");
          if (/\b(expressway|highway|motorway|tollway|nlex|slex|sctex|tplex|cavitex|calax|skyway)\b/i.test(roadText)) {
            highwayMeters += step.distance?.value || 0;
          }
        });
        legsData.push({
          from: leg.start_address.split(",")[0],
          to: leg.end_address.split(",")[0],
          distance: leg.distance.text,
          distanceMeters: leg.distance.value,
          duration: leg.duration.text,
          durationSeconds: leg.duration.value,
          durationInTraffic: leg.duration_in_traffic ? leg.duration_in_traffic.text : leg.duration.text,
          durationInTrafficSeconds: leg.duration_in_traffic ? leg.duration_in_traffic.value : leg.duration.value
        });
      });

      const distKm = parseFloat((totalMeters / 1000).toFixed(2));
      const durMins = Math.max(1, Math.round(totalSecondsTraffic / 60));
      const baseMins = Math.max(1, Math.round(totalSecondsBase / 60));

      return {
        index: idx,
        summary: r.summary ? `via ${r.summary}` : `Route Alternative ${idx + 1}`,
        distanceMeters: totalMeters,
        distanceKm: distKm,
        durationSecs: totalSecondsTraffic,
        durationMins: durMins,
        baseDurationMins: baseMins,
        trafficDurationMins: durMins,
        trafficDelayRatio: baseMins > 0 ? parseFloat((durMins / baseMins).toFixed(3)) : 1.0,
        highwayRatio: totalMeters > 0 ? Number((highwayMeters / totalMeters).toFixed(3)) : 0,
        legs: legsData,
      };
    });

    const mapContainer = document.getElementById("map-card-container");
    if (mapContainer) {
      mapContainer.dataset.tripCandidateMetrics = JSON.stringify(candidatePayload.map((candidate) => ({
        index: candidate.index,
        distanceKm: candidate.distanceKm,
        durationMins: candidate.durationMins,
        baseDurationMins: candidate.baseDurationMins,
        trafficDelayRatio: candidate.trafficDelayRatio
      })));
    }
    this.candidatePayload = candidatePayload;
    const savedMode = this.currentMode;
    this.modeEvaluations = {};
    for (const mode of ['balanced','fastest','fuelEfficient','shortest']) {
      this.currentMode = mode;
      this.modeEvaluations[mode] = this.evaluateCandidatesLocally(candidatePayload);
    }
    this.currentMode = savedMode;
    this.updateStrategyRouteNotes(candidatePayload);

     
    this.applyEvaluationResult(this.modeEvaluations[this.currentMode], result, candidatePayload);
    if(!this.rerouting) this.persistPlannerState().catch(() => {});
    const requestId = ++this.evaluationRequestId;
    const requestedGeneration=this.generationId;

     
    const form = new FormData();
    form.append("origin", origin);
    form.append("destination", destination);
    form.append("vehicle", vehicleSelect?.selectedOptions[0]?.dataset.vehicleId || vehicleValue);
    form.append("trip_id", window.TC_ROUTE_CONTEXT?.tripId || "");
    form.append("reservation_id", window.TC_ROUTE_CONTEXT?.reservationId || "");
    form.append("mode", this.currentMode);
    form.append("route_phase", window.TC_ROUTE_CONTEXT?.routePhase || "outbound");
    form.append("passenger_count", window.TC_ROUTE_CONTEXT?.passengerCount ?? 0);
    form.append("waypoints", JSON.stringify(waypointsList));
    form.append("candidates", JSON.stringify(candidatePayload));

    this.evaluationPromise = fetch(`${window.TC_BASE_URL}/actions/route_ai.php`, { method: "POST", body: form })
      .then((res) => res.json())
      .then((resData) => {
        if (requestId !== this.evaluationRequestId || requestedGeneration!==this.generationId || !this.cachedDirectionsResponse || (this.isNavigating && !this.rerouting) || this.applyingRoute) return;
        if (!resData.ok || !resData.data) throw new Error(resData.error || 'Route evaluation unavailable.');
        if (resData.ok && resData.data) {
          this.updateStrategyRouteNotes(candidatePayload, resData.data.modeSelections);
          const evaluations = resData.data.modeEvaluations || {};
          for (const [mode,evaluation] of Object.entries(evaluations)) {
            const selected = evaluation.selectedCandidate;
            this.modeEvaluations[mode] = {...resData.data,...evaluation,mode,vehicleSpecs:resData.data.vehicleSpecs,
              distance:selected.distanceFormatted,duration:selected.durationFormatted,fuelEstimate:selected.fuelFormatted,
              fuelCost:selected.fuelCost===null?'Fuel price unavailable':`₱${selected.fuelCost.toLocaleString()}`,
              tollEstimate:selected.tollFormatted,totalTripCost:selected.costFormatted,routeScore:selected.compositeScore};
          }
          this.applyEvaluationResult(this.modeEvaluations[this.currentMode] || resData.data, result, candidatePayload);
          if(this.rerouting) {
            this.appliedRoute=this.captureRouteSnapshot();this.plannerLifecycle='NAVIGATING';
            this.currentStepIndex=0;this.currentLegIndex=0;this._computeLegTotals();
            this.persistPlannerState(true).catch(error=>window.showAppToast?.('Reroute Save',error.message,'warning'));
            this.rerouting=false;
          } else this.persistPlannerState().catch(() => {});
        }
      })
      .catch((err) => {
        console.warn("Server-side RouteThink evaluation error, using client fallback:", err);
        if(this.rerouting){this.appliedRoute=this.captureRouteSnapshot();this.plannerLifecycle='NAVIGATING';this.currentStepIndex=0;this.currentLegIndex=0;this._computeLegTotals();this.persistPlannerState(true).catch(()=>{});this.rerouting=false;}
      });
  }

   
   

   
   
   
  async startNavigation(options = {}) {
    if(window.TC_ROUTE_CONTEXT?.canApply===false)return;
    if (window.TC_ROUTE_CONTEXT?.isDriver && !['Dispatched', 'In Transit', 'Returning to Depot'].includes(window.TC_ROUTE_CONTEXT.tripStatus)) {
      this.updateDispatchStartButton();
      window.showAppToast?.('Waiting for Dispatch', 'This trip has not been dispatched yet. Please wait for the Dispatcher/Admin.', 'warning');
      return;
    }
    if (this.isNavigating) {
      this.recenterNavigation();
      return;
    }
    if (!this.currentRouteData && !this.currentDirectionsResult) {
      if (window.showAppToast) {
        window.showAppToast("Navigation Notice", "Please generate a valid ROUTETHINK route first before starting navigation.", "warning");
      }
      return;
    }

    if(this.applyingRoute || this.navigationStarting || this.arrivalConfirmed)return;
    if (!this.appliedRoute) {
      window.showAppToast?.('Apply Route First','Apply the selected route before starting navigation.','warning');
      return;
    }
    this.restoreRouteSnapshot(this.appliedRoute);
    const isResume = options && options.resume === true && window.TC_ROUTE_CONTEXT?.resumeNavigation;
    this.navigationStarting=true;
    await this.persistQueue.catch(()=>{});
    const routeSaved = await this.saveSelectedRouteToHistory();
    this.navigationStarting=false;
    if (!routeSaved) return;

    this.isNavigating = true;
    this.plannerLifecycle = 'NAVIGATING';
    this.updateApplyButton();
    this.setRouteStrategyLocked(true);
    this.navigationStartedAt = Date.now();
    this.arrivalSince = null;
    this.arrivalLastTimestamp = 0;
    this.arrivalPending = false;
    this.arrivalConfirmed = false;
    this.hasLiveGpsFix = Boolean(this.hasLiveGpsFix && this.lastUserPosition);
    this.offRouteConsecutiveCount = 0;
    this.navigationFollowUser = true;
    this.navigationCameraInitialized = false;
    this.hasReachedPickup = false;
    this.currentStepIndex = 0;
    this.currentLegIndex = 0;
    this.accumulatedLegDistance = 0;
    this.accumulatedLegDuration = 0;
    if(isResume && this.navigationRestoreState){
      const saved=this.navigationRestoreState;
      this.navigationStartedAt=saved.startedAt || this.navigationStartedAt;
      this.currentStepIndex=saved.step || 0;this.currentLegIndex=saved.leg || 0;
      this.hasReachedPickup=Boolean(saved.hasReachedPickup);
      this.navigationRestoreState=null;
    }

     
    this._computeLegTotals();
    this.persistPlannerState().catch(error=>window.showAppToast?.('Navigation State',error.message,'warning'));

     
    const hud = document.getElementById("nav-hud-overlay");
    if (hud) hud.style.display = "flex";

     
    const mapControls = document.getElementById("map-controls-toolbar");
    if (mapControls) mapControls.style.display = "flex";
    const legend = document.getElementById("map-legend-overlay");
    if (legend) legend.style.display = "block";
    const mapContainer = document.getElementById("map-card-container");
    if (mapContainer) mapContainer.classList.add("navigation-active");
    const startButton = document.getElementById("btn-start-navigation");
    if (startButton) {
      startButton.disabled = true;
      startButton.style.display = "none";
    }

     
    const targetNameEl = document.getElementById("nav-hud-target-name");
    const distEl = document.getElementById("nav-hud-distance");
    const durEl = document.getElementById("nav-hud-duration");
    const etaEl = document.getElementById("nav-hud-eta");
    const iconEl = document.getElementById("nav-hud-icon");
    const subtextEl = document.getElementById("nav-hud-subtext");
    const offrouteEl = document.getElementById("nav-hud-offroute-alert");
    const trafficBadge = document.getElementById("nav-hud-traffic-badge");
    const selectedRouteBadge = document.getElementById("nav-hud-selected-route");
    const targetLabel = document.getElementById("nav-hud-target-label");
    const progressEl = document.getElementById("nav-hud-progress-bar");
    const progressText = document.getElementById("nav-hud-progress-text");

    if (offrouteEl) offrouteEl.classList.add("d-none");

    const selectedCard = document.querySelector(".opt-option-card.selected .opt-title");
    const selectedLabel = selectedCard?.textContent?.replace(/\s+/g, " ").trim()
      || this.getModeTitle(this.currentMode);
    if (selectedRouteBadge) {
      selectedRouteBadge.innerHTML = `<i class="bi bi-signpost-split-fill me-1"></i>Selected: ${selectedLabel}`;
    }

    const dest = this.currentRouteSummary?.destination || document.getElementById("route-dest-input")?.value || "Final Destination";
    if (targetNameEl) targetNameEl.textContent = dest;
     
    const totalDist = this.currentRouteSummary?.totalKm;
    const totalDur = this.currentRouteSummary?.durationStr;
    if (distEl) distEl.textContent = totalDist ? `${totalDist} km` : "—";
    if (durEl) durEl.textContent = totalDur || "—";
    if (targetLabel) targetLabel.textContent = "Next Destination";

     
    if (trafficBadge) {
      if (this.trafficAware) {
        trafficBadge.className = "badge bg-success text-white";
        trafficBadge.innerHTML = `<i class="bi bi-broadcast me-1"></i>Live GPS`;
      } else {
        trafficBadge.className = "badge bg-secondary text-white";
        trafficBadge.innerHTML = `<i class="bi bi-geo-alt me-1"></i>GPS`;
      }
    }

     
    const trafficInfo = document.getElementById("nav-hud-traffic-info");
    if (trafficInfo) {
      if (this.trafficEnabled) {
        trafficInfo.className = "badge bg-white text-dark border";
        trafficInfo.innerHTML = `<i class="bi bi-cone-striped text-dark me-1"></i>Traffic: Live`;
      } else {
        trafficInfo.className = "badge bg-white text-muted border";
        trafficInfo.innerHTML = `<i class="bi bi-cone-striped text-dark me-1"></i>Traffic: Off`;
      }
    }

     
    const totalSecs = this.currentRouteSummary?.totalSeconds || 0;
    if (totalSecs > 0) {
      const etaDate = new Date(Date.now() + totalSecs * 1000);
      if (etaEl) {
        etaEl.textContent = etaDate.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
      }
    } else if (etaEl) {
      etaEl.textContent = "—";
    }

     
    this._updateStepDisplay();

     
    if (progressEl) progressEl.style.width = "0%";
    if (progressText) progressText.textContent = "0%";

     
     
    if (this.lastUserPosition && this.hasLiveGpsFix) {
      this.updateUserLocationMarker(this.lastUserPosition.lat, this.lastUserPosition.lng, 20, this.userHeading);
    }

     
    if (this.directionsRenderer && this.currentDirectionsResult) {
      this.directionsRenderer.setMap(this.map);
      this.directionsRenderer.setDirections(this.currentDirectionsResult);
      this.directionsRenderer.setRouteIndex(Number(this.currentEvaluationData?.selectedIndex || 0));
      this.directionsRenderer.setOptions({
        preserveViewport: true,
        suppressPolylines: true
      });
    }
    if (this.selectedRoutePolyline) this.selectedRoutePolyline.setMap(this.map);
     
     
     
    this.fitMapBounds();
     
     
    this.acquireNavigationPosition(isResume ? "resume" : "auto");

     
    if (navigator.geolocation) {
      this.watchId = navigator.geolocation.watchPosition(
        (pos) => this.onNavigationPositionUpdate(pos),
        (err) => this.onNavigationPositionError(err),
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 1500 }
      );
    }

     
    if (window.DeviceOrientationEvent && typeof window.DeviceOrientationEvent.requestPermission === "function") {
      window.DeviceOrientationEvent.requestPermission().then((res) => {
        if (res === "granted") {
          window.addEventListener("deviceorientation", (e) => this.onDeviceOrientation(e), true);
        }
      }).catch(() => {});
    } else if (window.DeviceOrientationEvent) {
      window.addEventListener("deviceorientation", (e) => this.onDeviceOrientation(e), true);
    }

    if (window.showAppToast) {
      window.showAppToast(
        isResume ? "Navigation Resumed" : "Navigation Started",
        isResume
          ? "Your active trip and real-time navigation have been restored."
          : "Real-time navigation and route tracking is now active.",
        "success"
      );
    }
  }

  _computeLegTotals() {
    if (!this.currentDirectionsResult) return;
    const route = this.getSelectedGoogleRoute();
    if (!route) return;

    this._legTotals = route.legs.map((leg) => {
      let totalDist = 0;
      let totalDur = 0;
      leg.steps.forEach((step) => {
        totalDist += step.distance ? step.distance.value : 0;
        totalDur += step.duration ? step.duration.value : 0;
      });
      return { distance: totalDist, duration: totalDur };
    });
  }

  _updateStepDisplay() {
    if (!this.currentDirectionsResult) return;
    const route = this.getSelectedGoogleRoute();
    if (!route) return;

    const leg = route.legs[this.currentLegIndex];
    if (!leg || !leg.steps) return;

    const iconEl = document.getElementById("nav-hud-icon");
    const subtextEl = document.getElementById("nav-hud-subtext");
    const targetNameEl = document.getElementById("nav-hud-target-name");
    const targetLabel = document.getElementById("nav-hud-target-label");
    const distEl = document.getElementById("nav-hud-distance");

    const step = leg.steps[this.currentStepIndex];
    if (!step) return;

    const cleanInstruction = (step.instructions || "").replace(/<[^>]*>?/gm, " ").trim();
    if (subtextEl) subtextEl.textContent = cleanInstruction || "Proceed along route";
    if (iconEl) iconEl.className = this.getStepManeuverIcon(step.maneuver, cleanInstruction);

     
    const isLastStep = this.currentStepIndex >= leg.steps.length - 1;
    if (isLastStep && targetNameEl && targetLabel) {
      targetLabel.textContent = "Arriving At";
      targetNameEl.textContent = leg.end_address ? leg.end_address.split(",")[0] : "Next Stop";
    } else if (targetLabel) {
      targetLabel.textContent = "Next Destination";
    }

     
    if (distEl && step.distance) {
      distEl.textContent = step.distance.text;
    }
  }

  clearRouteArrows() {
    if (this.routeArrowPolyline) {
      this.routeArrowPolyline.setMap(null);
      this.routeArrowPolyline = null;
    }
  }

  getStepManeuverIcon(maneuver, instructionText = "") {
    const text = (instructionText || "").toLowerCase();
    const man = (maneuver || "").toLowerCase();

    if (man.includes("right-slight") || text.includes("slight right") || text.includes("bear right")) {
      return "bi bi-arrow-up-right";
    }
    if (man.includes("right-sharp") || text.includes("sharp right")) {
      return "bi bi-arrow-90deg-right";
    }
    if (man.includes("right") || text.includes("turn right") || text.includes("keep right")) {
      return "bi bi-arrow-right";
    }
    if (man.includes("left-slight") || text.includes("slight left") || text.includes("bear left")) {
      return "bi bi-arrow-up-left";
    }
    if (man.includes("left-sharp") || text.includes("sharp left")) {
      return "bi bi-arrow-90deg-left";
    }
    if (man.includes("left") || text.includes("turn left") || text.includes("keep left")) {
      return "bi bi-arrow-left";
    }
    if (man.includes("uturn") || text.includes("u-turn")) {
      return "bi bi-arrow-counterclockwise";
    }
    if (man.includes("ramp") || man.includes("fork") || text.includes("ramp") || text.includes("fork")) {
      return "bi bi-signpost-split";
    }
    return "bi bi-arrow-up";
  }

  onDeviceOrientation(e) {
    if (!this.isNavigating) return;
    let heading = null;
    if (e.webkitCompassHeading) {
      heading = e.webkitCompassHeading;
    } else if (e.alpha) {
      heading = 360 - e.alpha;
    }
    if (heading !== null && this.lastUserPosition) {
      this.userHeading = heading;
      this.updateUserLocationMarker(this.lastUserPosition.lat, this.lastUserPosition.lng, 0, heading);
    }
  }

  onNavigationPositionUpdate(pos) {
    if (!this.isNavigating) return;

    const lat = pos.coords.latitude;
    const lng = pos.coords.longitude;
    const accuracy = pos.coords.accuracy || 0;
    const heading = (pos.coords.heading !== null && !isNaN(pos.coords.heading)) ? pos.coords.heading : this.userHeading;
    const speed = pos.coords.speed;  

    this.lastUserPosition = { lat, lng };
    this.hasLiveGpsFix = true;
    this.updateUserLocationMarker(lat, lng, accuracy, heading);

     
    if (this.currentDirectionsResult && typeof google !== "undefined" && google.maps && google.maps.geometry) {
      const userLatLng = new google.maps.LatLng(lat, lng);
      const selectedIndex = Number(this.currentEvaluationData?.selectedIndex || 0);
      const route = this.currentDirectionsResult.routes[selectedIndex] || this.currentDirectionsResult.routes[0];

      if (this.getRouteRoadPath(route).length > 1) {
        const pickupPoint = route.legs?.[0]?.start_location;
        if (pickupPoint) {
          const pickupDistance = google.maps.geometry.spherical.computeDistanceBetween(userLatLng, pickupPoint);
          const pickupThreshold = Math.max(40, Math.min(100, accuracy || 40));
          if (pickupDistance <= pickupThreshold) this.hasReachedPickup = true;
        }
         
        const gracePeriodComplete = Date.now() - this.navigationStartedAt >= 15000;
        const accuracyUsable = accuracy > 0 && accuracy <= 100;
        const toleranceDegrees = Math.max(30, Math.min(75, accuracy * 1.5)) / 111320;
        const isOnRoute = google.maps.geometry.poly.isLocationOnEdge(
          userLatLng,
          new google.maps.Polyline({ path: this.getRouteRoadPath(route) }),
          toleranceDegrees
        );
         
         
        if (accuracyUsable && this.navigationFollowUser && this.map) {
          this.focusNavigationCamera({ lat, lng }, !this.navigationCameraInitialized);
        }
        if (gracePeriodComplete && accuracyUsable && !isOnRoute) {
          this.offRouteConsecutiveCount += 1;
        } else {
          this.offRouteConsecutiveCount = 0;
        }
        const shouldShowOffRoute = this.offRouteConsecutiveCount >= 3;
        if (shouldShowOffRoute && !this.liveNavigationPolyline && !this.liveNavigationRoutePending) {
          this.renderLiveNavigationRoute(lat, lng);
        } else if (isOnRoute && (this.liveNavigationPolyline || this.liveNavigationRoutePending)) {
          // Ignore an in-flight connector response after returning to the route.
          this.liveNavigationRequestId += 1;
          this.liveNavigationRoutePending = false;
          if (this.liveNavigationPolyline) this.liveNavigationPolyline.setMap(null);
          this.liveNavigationPolyline = null;
          if (this.selectedRoutePolyline) this.selectedRoutePolyline.setMap(this.map);
        }
        const offrouteEl = document.getElementById("nav-hud-offroute-alert");
        if (offrouteEl) {
          if (shouldShowOffRoute) {
            offrouteEl.classList.remove("d-none");
            offrouteEl.classList.add("d-flex");
          } else {
            offrouteEl.classList.add("d-none");
            offrouteEl.classList.remove("d-flex");
          }
        }

         
        this._trackCurrentStep(userLatLng, route);
        const progressSignature=`${this.currentLegIndex}:${this.currentStepIndex}:${this.hasReachedPickup}`;
        if(!this.rerouting && this.persistedNavigationProgress!==progressSignature){
          this.persistedNavigationProgress=progressSignature;
          this.persistPlannerState().catch(error=>{this.persistedNavigationProgress=null;window.showAppToast?.('Navigation State',error.message,'warning');});
        }

         
         
         
        const routePath = this.getDriverPlanningPath(route);
        const computedRouteMeters = this._computePathDistance(routePath);
        const totalRouteMeters = computedRouteMeters || this.currentRouteSummary?.totalMeters || 1;
        let traveledMeters = (!isOnRoute && !this.prePickupPath.length)
          ? 0
          : this._computeDistanceTraveledAlongPath(userLatLng, routePath);
        traveledMeters = Math.min(totalRouteMeters, Math.max(0, traveledMeters));
        const remainingMeters = Math.max(0, totalRouteMeters - traveledMeters);
        const remainingKm = (remainingMeters / 1000).toFixed(1);

         
        const distEl = document.getElementById("nav-hud-distance");
        if (distEl) distEl.textContent = `${remainingKm} km`;

         
        let effectiveSpeedKmH;
        if (speed && speed > 1) {
           
          effectiveSpeedKmH = speed * 3.6;
        } else if (this.currentRouteSummary?.totalSeconds > 0 && this.currentRouteSummary?.totalMeters > 0) {
           
          effectiveSpeedKmH = (this.currentRouteSummary.totalMeters / 1000) / (this.currentRouteSummary.totalSeconds / 3600);
        } else {
           
          effectiveSpeedKmH = 35;
        }

        const remainingMins = Math.max(1, Math.round((parseFloat(remainingKm) / effectiveSpeedKmH) * 60));
        const hours = Math.floor(remainingMins / 60);
        const mins = remainingMins % 60;
        const durEl = document.getElementById("nav-hud-duration");
        if (durEl) {
          durEl.textContent = hours > 0 ? `${hours} hr ${mins} min` : `${mins} min`;
        }

        const etaDate = new Date(Date.now() + remainingMins * 60 * 1000);
        const etaEl = document.getElementById("nav-hud-eta");
        if (etaEl) {
          etaEl.textContent = etaDate.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
        }

         
        const destinationPoint = route.legs?.[route.legs.length - 1]?.end_location;
        const destinationDistance = destinationPoint
          ? google.maps.geometry.spherical.computeDistanceBetween(userLatLng, destinationPoint)
          : Infinity;
        this.checkDestinationArrival(pos, destinationDistance);
        let pct = Math.min(99, Math.max(0, Math.round((traveledMeters / totalRouteMeters) * 100)));
        if (this.arrivalConfirmed) pct = 100;
        const progressBar = document.getElementById("nav-hud-progress-bar");
        const progressText = document.getElementById("nav-hud-progress-text");
        if (progressBar) progressBar.style.width = `${pct}%`;
        if (progressText) progressText.textContent = `${pct}%`;
      }
    }
  }

  _computeDistanceTraveledAlongPath(userLatLng, routePath) {
    if (!routePath || routePath.length < 2) return 0;
    if (typeof google === "undefined" || !google.maps || !google.maps.geometry) return 0;

     
    let nearestIndex = 0;
    let minDistance = Infinity;
    for (let i = 0; i < routePath.length; i++) {
      const d = google.maps.geometry.spherical.computeDistanceBetween(userLatLng, routePath[i]);
      if (d < minDistance) {
        minDistance = d;
        nearestIndex = i;
      }
    }

     
    let traveledMeters = 0;
    for (let i = 0; i < nearestIndex; i++) {
      traveledMeters += google.maps.geometry.spherical.computeDistanceBetween(routePath[i], routePath[i + 1]);
    }

     
    if (nearestIndex > 0) {
      traveledMeters += google.maps.geometry.spherical.computeDistanceBetween(routePath[nearestIndex - 1], userLatLng);
    }

    return traveledMeters;
  }

  _computePathDistance(routePath) {
    if (!routePath || routePath.length < 2) return 0;
    if (typeof google === "undefined" || !google.maps?.geometry?.spherical) return 0;

    let totalMeters = 0;
    for (let i = 0; i < routePath.length - 1; i++) {
      totalMeters += google.maps.geometry.spherical.computeDistanceBetween(routePath[i], routePath[i + 1]);
    }
    return totalMeters;
  }

  _trackCurrentStep(userLatLng, route) {
    const leg = route.legs[this.currentLegIndex];
    if (!leg || !leg.steps) return;

    const steps = leg.steps;
    const stepThreshold = 40;  

     
    if (this.currentLegIndex < route.legs.length - 1) {
      const legEndDist = google.maps.geometry.spherical.computeDistanceBetween(userLatLng, leg.end_location);
      if (legEndDist < stepThreshold) {
         
        this.currentLegIndex++;
        this.currentStepIndex = 0;
        this._updateStepDisplay();
        return;
      }
    }

     
    if (this.currentStepIndex < steps.length - 1) {
      const currentStep = steps[this.currentStepIndex];
      if (currentStep && currentStep.end_location) {
        const stepEndDist = google.maps.geometry.spherical.computeDistanceBetween(userLatLng, currentStep.end_location);
        if (stepEndDist < stepThreshold) {
          this.currentStepIndex++;
          this._updateStepDisplay();
          return;
        }
      }
    }

     
    let nearestStepIdx = 0;
    let minDist = Infinity;
    for (let i = 0; i < steps.length; i++) {
      if (steps[i].start_location) {
        const d = google.maps.geometry.spherical.computeDistanceBetween(userLatLng, steps[i].start_location);
        if (d < minDist) {
          minDist = d;
          nearestStepIdx = i;
        }
      }
    }
    if (nearestStepIdx !== this.currentStepIndex) {
      this.currentStepIndex = nearestStepIdx;
      this._updateStepDisplay();
    }
  }

  onNavigationPositionError(err) {
    if (!this.isNavigating) return;
    let msg = "GPS Signal Lost.";
    if (err.code === 1) msg = "Location permission was denied.";
    else if (err.code === 2) msg = "Position unavailable. Retrying GPS connection...";
    else if (err.code === 3) msg = "GPS request timed out.";

    const subtextEl = document.getElementById("nav-hud-subtext");
    if (subtextEl) subtextEl.textContent = msg;
  }

  recenterNavigation() {
     
     
    this.navigationFollowUser = true;
    this.acquireNavigationPosition(true);
  }

  focusNavigationCamera(position, forceZoom = false) {
    if (!this.map || !position) return;
    this.navigationFollowUser = true;
    this.map.panTo(position);
    if (this.isNavigating && (forceZoom || !this.navigationCameraInitialized)) {
      this.map.setZoom(16);
      this.navigationCameraInitialized = true;
    } else if (!this.isNavigating && forceZoom) {
      this.map.setZoom(15);
    }
  }

  isPositionOnSelectedRoute(lat, lng, accuracy = 0) {
    if (!this.currentDirectionsResult || typeof google === "undefined" || !google.maps?.geometry?.poly) return false;
    const selectedIndex = Number(this.currentEvaluationData?.selectedIndex || 0);
    const route = this.currentDirectionsResult.routes[selectedIndex] || this.currentDirectionsResult.routes[0];
    if (this.getRouteRoadPath(route).length < 2) return false;
    const toleranceDegrees = Math.max(30, Math.min(75, accuracy * 1.5)) / 111320;
    return google.maps.geometry.poly.isLocationOnEdge(
      new google.maps.LatLng(lat, lng),
      new google.maps.Polyline({ path: this.getRouteRoadPath(route) }),
      toleranceDegrees
    );
  }

  renderLiveNavigationRoute(lat, lng) {
    if (!this.isNavigating || !this.directionsService || !this.currentDirectionsResult || this.liveNavigationRoutePending) return;

    const now = Date.now();
    const previousPosition = this.lastLiveNavigationRequestPosition;
    if (now - this.lastLiveNavigationRequestAt < this.liveNavigationRequestCooldownMs) return;
    if (previousPosition) {
      const earthRadiusMeters = 6371000;
      const toRadians = (degrees) => degrees * Math.PI / 180;
      const deltaLat = toRadians(lat - previousPosition.lat);
      const deltaLng = toRadians(lng - previousPosition.lng);
      const a = Math.sin(deltaLat / 2) ** 2 +
        Math.cos(toRadians(previousPosition.lat)) * Math.cos(toRadians(lat)) *
        Math.sin(deltaLng / 2) ** 2;
      const movedMeters = earthRadiusMeters * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
      if (movedMeters < this.liveNavigationRequestMinDistanceMeters) return;
    }
    const selectedIndex = Number(this.currentEvaluationData?.selectedIndex || 0);
    const plannedRoute = this.currentDirectionsResult.routes[selectedIndex] || this.currentDirectionsResult.routes[0];
    const legs = plannedRoute?.legs || [];
    const pickup = legs[0]?.start_location;
    if (!pickup || this.getRouteRoadPath(plannedRoute).length < 2) return;

     
     
     
    const plannedPath = this.getRouteRoadPath(plannedRoute);
    let rejoinIndex = 0;
    let connectorDestination = pickup;
    if (this.hasReachedPickup) {
      const livePoint = new google.maps.LatLng(lat, lng);
      let nearestDistance = Infinity;
      plannedPath.forEach((point, index) => {
        const distance = google.maps.geometry.spherical.computeDistanceBetween(livePoint, point);
        if (distance < nearestDistance) {
          nearestDistance = distance;
          rejoinIndex = index;
          connectorDestination = point;
        }
      });
    }

    const requestId = ++this.liveNavigationRequestId;
    this.liveNavigationRoutePending = true;
    this.lastLiveNavigationRequestAt = now;
    this.lastLiveNavigationRequestPosition = { lat, lng };
    const request = {
      origin: { lat, lng },
      destination: this.hasReachedPickup ? legs[legs.length-1].end_location : connectorDestination,
      waypoints:this.hasReachedPickup?legs.slice(this.currentLegIndex,-1).map(leg=>({location:leg.end_location,stopover:true})):[],
      travelMode: google.maps.TravelMode.DRIVING,
      provideRouteAlternatives: true
    };
    if (this.trafficAware) {
      request.drivingOptions = {
         
        departureTime: new Date(Date.now() + 5 * 60 * 1000),
        trafficModel: google.maps.TrafficModel.BEST_GUESS
      };
    }

    let retriedWithoutTrafficTime = false;
    const handleLiveRouteResponse = (response, status) => {
      if (
        status === google.maps.DirectionsStatus.INVALID_REQUEST &&
        request.drivingOptions &&
        !retriedWithoutTrafficTime
      ) {
        retriedWithoutTrafficTime = true;
        delete request.drivingOptions;
        this.directionsService.route(request, handleLiveRouteResponse);
        return;
      }

      if (!this.isNavigating || requestId !== this.liveNavigationRequestId) return;
      this.liveNavigationRoutePending = false;
      if (status !== google.maps.DirectionsStatus.OK || !response?.routes?.[0]?.overview_path) return;
      if(this.hasReachedPickup){
        this.rerouting=true;this.currentMode=this.appliedRoute.mode;this.generationId=crypto.randomUUID();
        if(this.liveNavigationPolyline){this.liveNavigationPolyline.setMap(null);this.liveNavigationPolyline=null;}
        this.processGoogleDirectionsResult(response);return;
      }
      if (this.liveNavigationPolyline) this.liveNavigationPolyline.setMap(null);
       
       
      if (this.selectedRoutePolyline) this.selectedRoutePolyline.setMap(null);
      const connectorCandidates=response.routes.map((route,index)=>{
        const meters=route.legs.reduce((sum,leg)=>sum+leg.distance.value,0);
        const seconds=route.legs.reduce((sum,leg)=>sum+(leg.duration_in_traffic||leg.duration).value,0);
        const base=route.legs.reduce((sum,leg)=>sum+leg.duration.value,0);
        return {index,distanceMeters:meters,distanceKm:meters/1000,durationSecs:seconds,durationMins:seconds/60,baseDurationMins:base/60,trafficDelayRatio:seconds/Math.max(1,base)};
      });
      const connectorIndex=this.evaluateCandidatesLocally(connectorCandidates).selectedIndex;
      const connectorPath = this.getRouteRoadPath(response.routes[connectorIndex]);
      const selectedRouteTail = plannedPath.slice(rejoinIndex);
      const preservedSelectedPath = [
        ...connectorPath,
        ...selectedRouteTail.slice(connectorPath.length ? 1 : 0)
      ];
      this.liveNavigationPolyline = new google.maps.Polyline({
        map: this.map,
        path: preservedSelectedPath,
        strokeColor: "#0B63E5",
        strokeWeight: 8,
        strokeOpacity: 1,
        clickable: false,
        zIndex: 45
      });
    };
    this.directionsService.route(request, handleLiveRouteResponse);
  }

  acquireNavigationPosition(focusCamera = false) {
    if (!navigator.geolocation) {
      if (window.showAppToast) window.showAppToast("Geolocation Unavailable", "GPS is not supported by this browser.", "danger");
      return;
    }
    const recenterBtn = document.getElementById("nav-recenter-btn");
    const originalContent = recenterBtn ? recenterBtn.innerHTML : "";
    if (recenterBtn) {
      recenterBtn.disabled = true;
      recenterBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Locating`;
    }
    const silentResume = focusCamera === "resume";
    if (window.showAppToast && !silentResume) window.showAppToast("Acquiring GPS", "Locating the Driver's current position...", "info");
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        this.lastUserPosition = { lat, lng };
        this.hasLiveGpsFix = true;
        this.navigationFollowUser = true;
        this.updateUserLocationMarker(lat, lng, pos.coords.accuracy || 0, pos.coords.heading);
        const autoFocus = focusCamera === "auto" || silentResume;
        const isNearAssignedRoute = this.isNavigating
          ? this.isPositionOnSelectedRoute(lat, lng, pos.coords.accuracy || 0)
          : false;
        if (focusCamera === true || autoFocus) {
          this.focusNavigationCamera({ lat, lng }, true);
        }
        if (this.isNavigating && !isNearAssignedRoute) {
           
           
          this.renderLiveNavigationRoute(lat, lng);
        } else if (this.liveNavigationPolyline) {
          this.liveNavigationPolyline.setMap(null);
          this.liveNavigationPolyline = null;
          if (this.selectedRoutePolyline) this.selectedRoutePolyline.setMap(this.map);
        }
        if (recenterBtn) {
          recenterBtn.disabled = false;
          recenterBtn.innerHTML = originalContent;
        }
        if (window.showAppToast && !silentResume) {
          window.showAppToast(
            (focusCamera === true || autoFocus) ? "GPS Re-centered" : "GPS Connected",
            (focusCamera === true || autoFocus)
              ? (isNearAssignedRoute || focusCamera === true
                  ? "Map aligned with the Driver's current location."
                  : "Map aligned with the Driver GPS. The assigned route remains loaded.")
              : "Live Driver location is now connected.",
            "success"
          );
        }
      },
      (err) => {
        if (recenterBtn) {
          recenterBtn.disabled = false;
          recenterBtn.innerHTML = originalContent;
        }
        this.onNavigationPositionError(err);
        if (window.showAppToast) window.showAppToast("GPS Unavailable", "Allow precise location access, then press Recenter again.", "warning");
      },
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
    );
  }

  checkDestinationArrival(pos, distance) {
    const timestamp = Number(pos.timestamp);
    const accuracy = Number(pos.coords.accuracy);
    // Never let poor GPS accuracy enlarge the destination boundary.
    const usable = Number.isFinite(timestamp) && Math.abs(Date.now() - timestamp) <= 5000
      && accuracy > 0 && accuracy <= 25 && distance + accuracy <= 50;
    if (!usable) {
      this.arrivalSince = null;
      return;
    }
    if (timestamp <= this.arrivalLastTimestamp) return;
    if (timestamp - this.arrivalLastTimestamp > 5000) this.arrivalSince = null;
    this.arrivalLastTimestamp = timestamp;
    if (this.arrivalSince === null) this.arrivalSince = timestamp;
    if (timestamp - this.arrivalSince >= 10000 && !this.arrivalPending) {
      this.completeDestinationArrival(pos);
    }
  }

  async completeDestinationArrival(pos) {
    this.arrivalPending = true;
    try {
      const tripId = window.TC_ROUTE_CONTEXT?.tripId;
      if (tripId) {
        const form = new FormData();
        form.append("trip_id", tripId);
        form.append("latitude", pos.coords.latitude);
        form.append("longitude", pos.coords.longitude);
        form.append("accuracy", pos.coords.accuracy);
        form.append("timestamp", pos.timestamp);
        const response = await fetch(`${window.TC_BASE_URL}/actions/route-navigation-state.php`, {
          method: "POST", body: form
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || "Unable to record arrival.");
        window.TC_ROUTE_CONTEXT.resumeNavigation = false;
      }
      this.arrivalConfirmed = true;
      this.finishArrivedNavigation();
    } catch (error) {
      this.arrivalSince = null;
      if (window.showAppToast) window.showAppToast("Arrival Save Failed", error.message, "warning");
    } finally {
      this.arrivalPending = false;
    }
  }

  finishArrivedNavigation() {
    if (!this.arrivalConfirmed) return;
    this.isNavigating = false;
    this.plannerLifecycle='APPLIED';
    this.persistPlannerState().catch(()=>{});
    this.updateApplyButton();
    this.setRouteStrategyLocked(false);
    this.hasLiveGpsFix = false;
    this.navigationStartedAt = 0;
    this.offRouteConsecutiveCount = 0;
    this.navigationFollowUser = false;
    this.navigationCameraInitialized = false;
    this.liveNavigationRequestId += 1;
    this.liveNavigationRoutePending = false;
    this.currentStepIndex = 0;
    this.currentLegIndex = 0;

    if (this.watchId !== null) {
      navigator.geolocation.clearWatch(this.watchId);
      this.watchId = null;
    }

     
    this.clearRouteArrows();
    if (this.liveNavigationPolyline) {
      this.liveNavigationPolyline.setMap(null);
      this.liveNavigationPolyline = null;
    }
    if (this.selectedRoutePolyline) this.selectedRoutePolyline.setMap(this.map);

     
    if (this.lastUserPosition) {
      this.updateUserLocationMarker(this.lastUserPosition.lat, this.lastUserPosition.lng, 0, null);
    }

     
    const hud = document.getElementById("nav-hud-overlay");
    if (hud) hud.style.display = "none";

     
    const mapControls = document.getElementById("map-controls-toolbar");
    if (mapControls) mapControls.style.display = "flex";
    const legend = document.getElementById("map-legend-overlay");
    if (legend) legend.style.display = "block";
    const startButton = document.getElementById("btn-start-navigation");
    if (startButton && this.currentRouteData) {
      startButton.disabled = true;
      startButton.style.display = "none";
      startButton.innerHTML = `<i class="bi bi-compass-fill me-2 fs-6"></i> START NAVIGATION`;
    }

     
    const mapContainer = document.getElementById("map-card-container");
    if (mapContainer) mapContainer.classList.remove("navigation-active");
    if (mapContainer && (document.fullscreenElement || mapContainer.classList.contains("is-fullscreen"))) {
      this.toggleFullscreen();
    }

     
    this.fitMapBounds();

    if (window.showAppToast) {
      window.showAppToast("Arrived", "Destination reached. Navigation has ended.", "success");
    }
  }

  plannerInputs() {
    const vehicle = document.getElementById('route-vehicle-select');
    return {origin:document.getElementById('route-origin-input')?.value.trim() || '',
      destination:document.getElementById('route-dest-input')?.value.trim() || '',
      vehicle:vehicle?.value || '',vehicleId:vehicle?.selectedOptions[0]?.dataset.vehicleId || '',
      waypoints:this.getWaypointsList(),trafficAware:this.trafficAware};
  }

  loadPresetInputs(id) {
    this.loadingPreset = true;
    try { this.setPreset(id); } finally { this.loadingPreset = false; }
  }

  captureRouteSnapshot() {
    if (!this.currentRouteData || !this.currentEvaluationData) return null;
    return JSON.parse(JSON.stringify({routeId:this.currentRouteData.routeId,mode:this.currentMode,
      data:this.currentRouteData,summary:this.currentRouteSummary,evaluation:{...this.currentEvaluationData,mode:this.currentMode},
      directions:this.currentDirectionsResult}));
  }

  hydrateDirections(value) {
    if (Array.isArray(value)) return value.map(item => this.hydrateDirections(item));
    if (value && typeof value === 'object') {
      if (typeof value.lat === 'number' && typeof value.lng === 'number') return new google.maps.LatLng(value.lat,value.lng);
      if (['south','west','north','east'].every(key => typeof value[key] === 'number')) {
        return new google.maps.LatLngBounds({lat:value.south,lng:value.west},{lat:value.north,lng:value.east});
      }
      return Object.fromEntries(Object.entries(value).map(([key,item]) => [key,this.hydrateDirections(item)]));
    }
    return value;
  }

  restoreRouteSnapshot(route) {
    if (!route) return;
    this.currentMode = route.mode;
    const directions = this.hydrateDirections(route.directions || this.cachedDirectionsResponse);
    this.applyEvaluationResult(route.evaluation,directions,this.candidatePayload || []);
    this.currentRouteData = route.data;
    this.currentRouteSummary = route.summary;
    document.querySelectorAll('.opt-option-card').forEach(card => card.classList.toggle('selected',card.dataset.mode === route.mode));
    this.updateResultsUI();this.updateApplyButton();
  }

  updateApplyButton() {
    const button = document.getElementById('btn-generate-ai-route');
    if (!button || this.isGenerating) return;
    const applied = this.appliedRoute?.routeId === this.currentRouteData?.routeId;
    button.disabled = this.isNavigating || this.applyingRoute || applied || (Boolean(this.currentRouteData) && window.TC_ROUTE_CONTEXT?.canApply === false);
    button.textContent = this.isNavigating ? 'Navigation Active' : applied ? 'Applied' : this.currentRouteData ? this.getModeButtonLabel(this.currentMode) : 'Generate ROUTETHINK Route';
    const navigation = document.getElementById('btn-start-navigation');
    if(navigation && !this.isNavigating) {
      navigation.disabled = !applied || window.TC_ROUTE_CONTEXT?.canApply===false;
      navigation.textContent = applied ? 'START NAVIGATION' : 'Apply Route First';
      if(this.arrivalConfirmed){navigation.disabled=true;navigation.textContent='Destination Reached';}
      if(window.TC_ROUTE_CONTEXT?.tripId && !['Dispatched','In Transit','Returning to Depot'].includes(window.TC_ROUTE_CONTEXT.tripStatus)) {
        navigation.disabled=true;navigation.textContent='Waiting for Dispatch';
      }
    }
  }

  persistPlannerState(reroute = false) {
    if (!this.currentRouteData || this.restoringPlanner) return Promise.resolve();
    const state = JSON.parse(JSON.stringify({version:1,generationId:this.generationId,inputs:this.generatedInputs || this.plannerInputs(),
      directions:this.cachedDirectionsResponse,modeEvaluations:this.modeEvaluations,candidates:this.candidatePayload,
      selected:this.captureRouteSnapshot(),applied:this.appliedRoute,
      navigation:{startedAt:this.navigationStartedAt,step:this.currentStepIndex,leg:this.currentLegIndex,hasReachedPickup:this.hasReachedPickup}}));
    const lifecycle=this.plannerLifecycle;
    this.persistQueue=this.persistQueue.catch(() => {}).then(async () => {
      const context=window.TC_ROUTE_CONTEXT || {};
      const form=new URLSearchParams({csrf:context.stateCsrf || '',trip_id:context.tripId || '',phase:context.routePhase || 'outbound',
        lifecycle,revision:String(this.plannerRevision),state:JSON.stringify(state),reroute:reroute?'1':''});
      const response=await fetch(`${window.TC_BASE_URL}/actions/route-planner-state.php`,{method:'POST',body:form});
      const data=await response.json();if(!response.ok || !data.ok)throw new Error(data.error || 'Route state could not be saved.');
      this.plannerRevision=data.revision;
      return data;
    });
    return this.persistQueue;
  }

  async applySelectedRoute() {
    if (this.applyingRoute || this.isNavigating || window.TC_ROUTE_CONTEXT?.canApply === false) return;
    await this.evaluationPromise;
    if (!this.currentRouteData || this.appliedRoute?.routeId===this.currentRouteData.routeId) return;
    this.applyingRoute=true;this.updateApplyButton();
    const previous=this.appliedRoute;
    try {
      this.appliedRoute=this.captureRouteSnapshot();this.plannerLifecycle='APPLIED';
      await this.persistPlannerState();
      this.savedRouteSignature=null;
      window.showAppToast?.('Route Applied',`${this.getModeTitle(this.currentMode)} is saved for navigation.`,'success');
    } catch(error) {
      this.appliedRoute=previous;this.plannerLifecycle=previous?'APPLIED':'GENERATED';
      window.showAppToast?.('Apply Failed',error.message,'danger');
    } finally {this.applyingRoute=false;this.updateApplyButton();}
  }

  async restorePlannerState() {
    this.plannerRestorePending=true;this.plannerRestoreCompleted=true;
    const context=window.TC_ROUTE_CONTEXT || {};
    try {
      const params=new URLSearchParams({trip_id:context.tripId || '',phase:context.routePhase || 'outbound'});
      const response=await fetch(`${window.TC_BASE_URL}/actions/route-planner-state.php?${params}`);
      const result=await response.json();if(!response.ok || !result.ok)throw new Error(result.error || 'Route restoration failed.');
      const record=result.state;if(!record)return;
      this.plannerRevision=Number(record.revision);
      const state=record.state_data;
      const current=this.plannerInputs();
      if(context.tripId && (state.inputs.origin!==current.origin || state.inputs.destination!==current.destination || state.inputs.vehicleId!==context.vehicleId))return;
      this.restoringPlanner=true;
      for(const [id,key] of [['route-origin-input','origin'],['route-dest-input','destination'],['route-vehicle-select','vehicle']]){
        const input=document.getElementById(id);if(input)input.value=state.inputs[key];
      }
      const waypoints=document.getElementById('waypoints-container');if(waypoints){waypoints.replaceChildren();(state.inputs.waypoints || []).forEach(value=>this.addWaypointField(value));}
      this.trafficAware=state.inputs.trafficAware;
      this.generatedInputs=state.inputs;this.generationId=state.generationId;
      this.cachedDirectionsResponse=this.hydrateDirections(state.directions);
      this.modeEvaluations=state.modeEvaluations || {};this.candidatePayload=state.candidates || [];
      this.arrivalConfirmed=result.current_step==='Arrived';
      this.appliedRoute=state.applied;this.plannerRevision=record.revision;this.plannerLifecycle=record.lifecycle;
      this.restoreRouteSnapshot(record.lifecycle==='NAVIGATING'?state.applied:state.selected);
      this.revealRouteStrategies();this.updateApplyButton();this.fitMapBounds();
      this.restoringPlanner=false;
      if(record.lifecycle==='NAVIGATING' && context.canApply!==false && (!context.tripId || Number(result.navigation_active)===1)){
        context.resumeNavigation=true;
        this.navigationRestoreState=state.navigation;
        await this.startNavigation({resume:true});
      }
    } catch(error) {window.showAppToast?.('Route State',error.message,'warning');}
    finally {this.restoringPlanner=false;this.plannerRestorePending=false;}
  }

  getVehicleEconomy(name) {
    const value=document.getElementById('route-vehicle-select')?.selectedOptions[0]?.dataset.consumption || '';
    const match=value.match(/^\s*(\d+(?:\.\d+)?)\s*(?:km\s*\/\s*l)?\s*$/i);
    return match && Number(match[1])>0?Number(match[1]):null;
  }
  async saveFundingRoute(button) {
    const message=document.getElementById('funding-route-message');
    if(!this.appliedRoute || this.appliedRoute.routeId!==this.currentRouteData?.routeId){message.textContent='Apply the selected route first.';return;}
    button.disabled=true;
    try{
      const fields=new URLSearchParams({csrf:window.TC_ROUTE_PREPARE_CSRF,trip_id:window.TC_ROUTE_CONTEXT.tripId,
        vehicle_id:document.getElementById('route-vehicle-select')?.selectedOptions[0]?.dataset.vehicleId||'',
        origin:document.getElementById('route-origin-input').value,destination:document.getElementById('route-dest-input').value,
        route_id:this.appliedRoute.routeId,distance_km:this.appliedRoute.data.distanceKm,strategy:this.appliedRoute.mode});
      const response=await fetch(`${window.TC_BASE_URL}/actions/route-preparation.php`,{method:'POST',body:fields,credentials:'same-origin'});
      const data=await response.json();if(!data.ok)throw Error(data.error);message.textContent='Route saved. Return to Reservation & Dispatch to prepare Trip Funding.';
    }catch(error){message.textContent=error.message;}finally{button.disabled=false;}
  }

  getModeTitle(mode) {
    switch (mode) {
      case "balanced": return "Balanced Route Recommendation";
      case "fuelEfficient": return "Eco-Optimized & Fuel Efficient";
      case "fastest": return "Fastest Express Corridor";
      case "shortest": return "Shortest Geographic Distance";
      default: return "AI Optimized Route";
    }
  }

  getModeButtonLabel(mode) {
    switch (mode) {
      case "balanced": return "Apply Balanced Route";
      case "fuelEfficient": return "Apply Eco Route";
      case "fastest": return "Apply Fastest Route";
      case "shortest": return "Apply Shortest Route";
      default: return "Apply Selected Route";
    }
  }

  updateResultsUI() {
    if (!this.currentRouteData) return;
    const data = this.currentRouteData;

    const setText = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.textContent = val;
    };

    setText("ai-res-title", data.title);
    setText("ai-res-distance", data.distance);
    setText("ai-res-duration", data.duration);
    setText("ai-res-fuel", data.fuelEstimate);
    if (!["driver", "admin"].includes(window.TC_ROUTE_REVENUE?.role)) {
      setText("ai-res-cost", data.totalTripCost);
    }
    setText("ai-res-score", data.routeScore);
    const fullyTrained = Boolean(data.isFullyMlActive);
    setText("ai-res-duration-label", Boolean(data.durationModelVersion) ? "Fleet AI Travel Time" : "Google Traffic ETA");
    setText("ai-res-fuel-label", Boolean(data.fuelModelVersion) ? "Fleet AI Fuel Prediction" : "Formula Fuel Estimate");
    setText("ai-res-score-label", "Relative Route Score:");

    const reasonEl = document.getElementById("ai-res-reason");
    if (reasonEl) reasonEl.textContent = data.reason;

    const scoreNum = parseInt(data.routeScore, 10) || 95;
    const scoreBar = document.getElementById("ai-res-score-bar");
    if (scoreBar) scoreBar.style.width = `${Math.min(100, Math.max(0, scoreNum))}%`;

    const badge = document.getElementById("ai-res-badge");
    if (badge) {
      if (this.currentMode === "balanced") {
        badge.className = fullyTrained ? "badge bg-primary" : "badge bg-secondary";
        badge.textContent = fullyTrained ? "Trained AI Recommended" : "Baseline Recommended";
      } else if (this.currentMode === "fuelEfficient") {
        badge.className = "badge bg-success";
        badge.textContent = "Lowest Fuel & Carbon";
      } else if (this.currentMode === "fastest") {
        badge.className = "badge bg-warning text-dark";
        badge.textContent = "Shortest Duration";
      } else {
        badge.className = "badge bg-secondary";
        badge.textContent = "Direct Path";
      }
    }

     
    this.renderItineraryBreakdown(data.legs);
  }

  renderItineraryBreakdown(legs) {
    const listContainer = document.getElementById("ai-itinerary-list");
    const countBadge = document.getElementById("itinerary-stops-count");
    if (!listContainer) return;

    if (!legs || legs.length === 0) {
      const origin = document.getElementById("route-origin-input")?.value.split(",")[0] || "Origin";
      const dest = document.getElementById("route-dest-input")?.value.split(",")[0] || "Destination";
      if (countBadge) countBadge.textContent = "Direct Route";
      listContainer.innerHTML = `
        <div class="p-2 border rounded bg-white small d-flex justify-content-between align-items-center">
          <div><i class="bi bi-geo-alt-fill text-success me-1"></i><strong>${origin}</strong></div>
          <i class="bi bi-arrow-right text-muted-custom"></i>
          <div><i class="bi bi-flag-fill text-danger me-1"></i><strong>${dest}</strong></div>
        </div>
      `;
      return;
    }

    if (countBadge) countBadge.textContent = `${legs.length} Leg${legs.length > 1 ? "s" : ""}`;
    let html = "";
    legs.forEach((leg, idx) => {
      html += `
        <div class="p-2 border rounded bg-white small mb-1">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="fw-bold" style="font-size: 11px;"><i class="bi bi-signpost text-primary me-1"></i>Leg ${idx + 1}: ${leg.from} → ${leg.to}</span>
            <span class="badge bg-light text-dark border" style="font-size: 10px;">${leg.distance}</span>
          </div>
          <div class="text-muted-custom" style="font-size: 11px;"><i class="bi bi-clock me-1"></i>Traffic-aware: ${leg.durationInTraffic || leg.duration}</div>
        </div>
      `;
    });
    listContainer.innerHTML = html;
  }

   
   
   
  saveSelectedRouteToHistory() {
    const presetSelect = document.getElementById("route-preset-select");
    const origin = document.getElementById("route-origin-input")?.value;
    const destination = document.getElementById("route-dest-input")?.value;
    const vehicle = document.getElementById("route-vehicle-select")?.value || "Tour Bus";
    const presetId = (presetSelect && presetSelect.value !== "custom") ? presetSelect.value : "";
    const routeTitle = presetId ? (this.activePreset?.name || "AI Tour Route") : `${origin} to ${destination}`;
    const signature = JSON.stringify({
      presetId,
      origin,
      destination,
      vehicle,
      mode: this.currentMode,
      distance: this.currentRouteData?.distance || "",
      duration: this.currentRouteData?.duration || "",
      selectedIndex: this.currentEvaluationData?.selectedIndex || 0
    });

    if (this.savedRouteSignature === signature) return Promise.resolve(true);
    if (this.routeSaveInFlight) return this.routeSaveInFlight;

    const form = new FormData();
    form.append("state_csrf", window.TC_ROUTE_CONTEXT?.stateCsrf || "");
    form.append("route_id", this.appliedRoute?.routeId || "");
    form.append("route_phase", window.TC_ROUTE_CONTEXT?.routePhase || "outbound");
    form.append("preset_id", presetId);
    form.append("trip_id", window.TC_ROUTE_CONTEXT?.tripId || "");
    form.append("reservation_id", window.TC_ROUTE_CONTEXT?.reservationId || "");
    form.append("mode", this.currentMode);
    form.append("route_title", routeTitle);
    form.append("vehicle", vehicle);
    if (this.currentRouteData) {
      form.append("distance", this.currentRouteData.distance);
      form.append("distance_km", this.currentRouteData.distanceKm || parseFloat(this.currentRouteData.distance) || 0);
      form.append("duration", this.currentRouteData.duration);
      form.append("duration_mins", this.currentRouteData.durationMins || 0);
      form.append("fuel_liters", this.currentRouteData.fuelEstimateLiters || parseFloat(this.currentRouteData.fuelEstimate) || 0);
      form.append("route_score", this.currentRouteData.routeScore || "");
      form.append("model_version", this.currentRouteData.modelVersion || "v0-kinematic");
    }
    if (this.currentEvaluationData) {
      form.append("selected_index", this.currentEvaluationData.selectedIndex || 0);
      form.append("candidates_json", JSON.stringify(this.currentEvaluationData.candidates || []));
      form.append("features_json", JSON.stringify(this.currentEvaluationData.selectedCandidate?.features || {}));
    }
    form.append("origin", origin || "");
    form.append("destination", destination || "");
    form.append("waypoints_json", JSON.stringify(this.getWaypointsList()));
    form.append("route_data_json", JSON.stringify({
      destinationLocation: this.getSelectedGoogleRoute()?.legs?.slice(-1)[0]?.end_location?.toJSON(),
      mode: this.currentMode,
      routeId: this.appliedRoute.routeId,
      directions: this.currentDirectionsResult,
      route: this.currentRouteData || null,
      selectedCandidate: this.currentEvaluationData?.selectedCandidate || null
    }));

    const navBtn = document.getElementById("btn-start-navigation");
    if (navBtn) {
      navBtn.disabled = true;
      navBtn.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status"></span>SAVING ROUTE...`;
    }

    this.routeSaveInFlight = fetch(`${window.TC_BASE_URL}/actions/route-start.php`, { method: "POST", body: form })
      .then(async (res) => {
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error(data.error || "Unable to save the selected route.");
        return data;
      })
      .then((data) => {
        this.savedRouteSignature = signature;
        if (window.showAppToast) {
          window.showAppToast("Route Saved", data.message || "Navigation route saved to Route History.", "success");
        }
        return true;
      })
      .catch((error) => {
        if (window.showAppToast) {
          window.showAppToast("Route Save Failed", error.message || "Unable to save the route. Navigation was not started.", "danger");
        }
        return false;
      })
      .finally(() => {
        this.routeSaveInFlight = null;
        if (navBtn) {
          navBtn.disabled = false;
          navBtn.innerHTML = `<i class="bi bi-compass-fill me-2 fs-6"></i> START NAVIGATION`;
          this.updateDispatchStartButton();
        }
      });

    return this.routeSaveInFlight;
  }

  updateDispatchStartButton() {
    if (!window.TC_ROUTE_CONTEXT?.isDriver) return;
    const button = document.getElementById('btn-start-navigation');
    if (!button || this.isNavigating || this.routeSaveInFlight) return;
    const dispatched = ['Dispatched', 'In Transit', 'Returning to Depot'].includes(window.TC_ROUTE_CONTEXT.tripStatus);
    const applied = Boolean(this.appliedRoute && this.currentRouteData?.routeId===this.appliedRoute.routeId);
    const allowed = dispatched && applied && !this.arrivalConfirmed;
    button.disabled = !allowed;
    button.textContent = this.arrivalConfirmed ? 'Destination Reached' : allowed ? 'START NAVIGATION' : (!dispatched ? 'Waiting for Dispatch' : 'Apply Route First');
    button.title = allowed ? '' : 'This trip has not been dispatched yet. Please wait for the Dispatcher/Admin.';
  }
}

 
window.aiRouteEngine = new AIRouteEngine();

 
if (window._googleMapsReady || (typeof google !== "undefined" && typeof google.maps !== "undefined" && typeof google.maps.Map === "function")) {
  window.aiRouteEngine.init();
}

 
window.gm_authFailure = function () {
  console.warn("Google Maps API authentication warning. Please check Google Cloud Console credentials and permissions.");
  if (window.showAppToast) {
    window.showAppToast(
      "Google Maps Configuration Required",
      "Google Maps encountered an issue: 1) Enable 'Maps JavaScript API', 'Places API' & 'Directions API', 2) Check API key restrictions (allow localhost), 3) Ensure a Billing account is linked in Google Cloud Console.",
      "warning"
    );
  }
};

 
window.initGoogleMapsCallback = function () {
  if (window.aiRouteEngine) {
    window.aiRouteEngine.init();
  }
};

 
document.addEventListener("DOMContentLoaded", function () {
  if (window.aiRouteEngine) {
    window.aiRouteEngine.init();
  }
});
