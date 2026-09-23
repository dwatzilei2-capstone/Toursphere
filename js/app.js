 








document.addEventListener("DOMContentLoaded", () => {
  App.init();
});

const App = {
  charts: {},
  notificationsFilter: "All",

  escapeHtml(value) {
    return String(value ?? "").replace(/[&<>'"]/g, (char) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", "'": "&#39;", '"': "&quot;"
    })[char]);
  },

  init() {
    this.setupEventListeners();
    this.setupModals();
    this.applyToastFromUrl();
    this.initCharts();
    this.applyChartTheme();
    new MutationObserver(() => this.applyChartTheme()).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
    this.initRouteMap();
    this.initNotificationsTabs();
    this.setupSidebarScrollPersistence();
  },

   
   
   
  setupEventListeners() {
    const sidebarToggleBtn = document.getElementById("sidebar-toggle-btn");
    const mobileMenuBtn = document.getElementById("mobile-menu-btn");
    const sidebar = document.getElementById("sidebar");
    const mainWrapper = document.getElementById("main-wrapper");
    const sidebarBackdrop = document.getElementById("sidebar-backdrop");
    let sidebarFlyout = null;
    let flyoutTrigger = null;

    const closeSidebarFlyout = () => {
      if (sidebarFlyout) sidebarFlyout.remove();
      if (flyoutTrigger) flyoutTrigger.setAttribute("aria-expanded", "false");
      sidebarFlyout = null;
      flyoutTrigger = null;
    };

    const openSidebarFlyout = (trigger, submenu) => {
      if (flyoutTrigger === trigger && sidebarFlyout) {
        closeSidebarFlyout();
        return;
      }

      closeSidebarFlyout();
      const triggerRect = trigger.getBoundingClientRect();
      const flyout = document.createElement("div");
      flyout.className = "sidebar-flyout";
      flyout.setAttribute("role", "menu");

      const title = document.createElement("div");
      title.className = "sidebar-flyout-title";
      title.textContent = trigger.querySelector(".nav-label")?.textContent?.trim() || "Module";
      flyout.appendChild(title);

      submenu.querySelectorAll(".nav-sublink").forEach((sourceLink) => {
        const link = sourceLink.cloneNode(true);
        link.classList.add("sidebar-flyout-link");
        link.setAttribute("role", "menuitem");
        flyout.appendChild(link);
      });

      document.body.appendChild(flyout);
      const flyoutRect = flyout.getBoundingClientRect();
      const top = Math.max(10, Math.min(triggerRect.top, window.innerHeight - flyoutRect.height - 10));
      flyout.style.left = `${Math.round(triggerRect.right + 8)}px`;
      flyout.style.top = `${Math.round(top)}px`;
      trigger.setAttribute("aria-expanded", "true");
      sidebarFlyout = flyout;
      flyoutTrigger = trigger;
    };

    const setSubmenuState = (trigger, open) => {
      const submenu = trigger.nextElementSibling;
      if (!submenu || !submenu.classList.contains("nav-submenu")) return;

      submenu.classList.toggle("open", open);
       
      submenu.style.removeProperty("display");
      trigger.classList.toggle("expanded", open);
      trigger.classList.toggle("open", open);
      trigger.setAttribute("aria-expanded", String(open));
    };

    const closeAllSubmenus = (except = null) => {
      document.querySelectorAll(".nav-has-sub").forEach((trigger) => {
        if (trigger !== except) setSubmenuState(trigger, false);
      });
    };

    if (sidebarToggleBtn) {
      sidebarToggleBtn.addEventListener("click", () => {
        const willCollapse = !sidebar.classList.contains("collapsed");
        sidebar.classList.toggle("collapsed", willCollapse);
        if (mainWrapper) mainWrapper.classList.toggle("expanded", willCollapse);
        closeSidebarFlyout();
        if (willCollapse) closeAllSubmenus();
      });
    }

    if (mobileMenuBtn) {
      mobileMenuBtn.addEventListener("click", () => {
        closeSidebarFlyout();
        sidebar.classList.toggle("mobile-open");
        if (sidebarBackdrop) sidebarBackdrop.classList.toggle("show");
      });
    }

    if (sidebarBackdrop) {
      sidebarBackdrop.addEventListener("click", () => {
        closeSidebarFlyout();
        sidebar.classList.remove("mobile-open");
        sidebarBackdrop.classList.remove("show");
      });
    }

    document.addEventListener("click", closeSidebarFlyout);
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") closeSidebarFlyout();
    });
    window.addEventListener("resize", closeSidebarFlyout);
    sidebar?.addEventListener("scroll", closeSidebarFlyout, { passive: true });

     
     
    document.querySelectorAll(".nav-has-sub").forEach((item) => {
      const submenu = item.nextElementSibling;
      item.setAttribute("aria-expanded", String(Boolean(submenu?.classList.contains("open"))));

      item.addEventListener("click", (e) => {
        e.preventDefault();
        e.stopPropagation();

        const submenu = item.nextElementSibling;
        if (!submenu || !submenu.classList.contains("nav-submenu")) return;

        if (sidebar?.classList.contains("collapsed") && window.matchMedia("(min-width: 992px)").matches) {
          openSidebarFlyout(item, submenu);
          return;
        }

        closeSidebarFlyout();
        const willOpen = !submenu.classList.contains("open");
        if (willOpen) closeAllSubmenus(item);
        setSubmenuState(item, willOpen);

         
        if (willOpen) {
          window.requestAnimationFrame(() => {
            (submenu.lastElementChild || submenu).scrollIntoView({ block: "nearest", inline: "nearest" });
          });
        }
      });
    });
  },

   
  setupSidebarScrollPersistence() {
    const sidebar = document.getElementById("sidebar");
    if (!sidebar) return;
    const STORAGE_KEY = "tc-sidebar-scroll";

    const saveScrollPosition = () => {
      try {
        const top = sidebar.scrollTop;
        sessionStorage.setItem(STORAGE_KEY, String(top));
        localStorage.setItem(STORAGE_KEY, String(top));
      } catch (_) {}
    };

     
     
    let scrollTicking = false;
    sidebar.addEventListener("scroll", () => {
      if (!scrollTicking) {
        window.requestAnimationFrame(() => {
          saveScrollPosition();
          scrollTicking = false;
        });
        scrollTicking = true;
      }
    }, { passive: true });

     
    sidebar.addEventListener("click", saveScrollPosition);
    sidebar.addEventListener("mousedown", saveScrollPosition);
    sidebar.addEventListener("touchstart", saveScrollPosition, { passive: true });

     
    window.addEventListener("beforeunload", saveScrollPosition);
    window.addEventListener("pagehide", saveScrollPosition);
    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "hidden") {
        saveScrollPosition();
      }
    });

     
    const getSavedPosition = () => {
      try {
        const val = sessionStorage.getItem(STORAGE_KEY) || localStorage.getItem(STORAGE_KEY);
        return val !== null ? (parseInt(val, 10) || 0) : null;
      } catch (_) {
        return null;
      }
    };

    const restoreScrollPosition = () => {
      const target = getSavedPosition();
      if (target !== null && target > 0) {
        sidebar.scrollTop = target;
      }
    };

     
    restoreScrollPosition();
    requestAnimationFrame(restoreScrollPosition);
    setTimeout(restoreScrollPosition, 50);
    setTimeout(restoreScrollPosition, 150);
    setTimeout(restoreScrollPosition, 300);

     
    window.addEventListener("pageshow", () => {
      restoreScrollPosition();
    });

    window.addEventListener("load", () => {
      restoreScrollPosition();
    });

     
     
    const ensureActiveVisibleIfNeeded = () => {
      const active = sidebar.querySelector(".nav-link-custom.active, .nav-sublink.active");
      if (!active) return;
      const sRect = sidebar.getBoundingClientRect();
      const aRect = active.getBoundingClientRect();
      if (aRect.top < sRect.top || aRect.bottom > sRect.bottom) {
        active.scrollIntoView({ block: "nearest", behavior: "auto" });
        saveScrollPosition();
      }
    };
    setTimeout(ensureActiveVisibleIfNeeded, 350);
  },

   
   
   
  applyToastFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const msg = params.get("msg");
    if (msg) {
      const type = params.get("type") || "success";
      this.showToast(type === "success" ? "Success" : type === "danger" ? "Action Failed" : type === "info" ? "Notice" : "Attention", msg, type);
      params.delete("msg");
      params.delete("type");
      const qs = params.toString();
      const url = window.location.pathname + (qs ? "?" + qs : "");
      history.replaceState(null, "", url);
    }
  },

  showToast(title, message, type = "info") {
    const container = document.getElementById("toast-container");
    if (!container) return;

    const bgMap = {
      success: "border-success bg-white text-dark",
      info: "border-primary bg-white text-dark",
      warning: "border-warning bg-white text-dark",
      danger: "border-danger bg-white text-dark"
    };
    const iconMap = {
      success: "bi-check-circle-fill text-success",
      info: "bi-info-circle-fill text-primary",
      warning: "bi-exclamation-triangle-fill text-warning",
      danger: "bi-x-circle-fill text-danger"
    };

    const toast = document.createElement("div");
    toast.className = `p-3 rounded shadow-lg border ${bgMap[type] || bgMap.info} mb-2 d-flex align-items-start gap-2`;
    toast.style.minWidth = "280px";
    toast.style.animation = "modalFadeIn 0.2s ease";
    toast.innerHTML = `
      <i class="bi ${iconMap[type] || iconMap.info} fs-5 mt-1"></i>
      <div class="flex-1">
        <strong class="d-block small fw-bold">${title}</strong>
        <span class="small text-muted-custom">${message}</span>
      </div>
    `;

    container.appendChild(toast);
    setTimeout(() => {
      toast.style.opacity = "0";
      toast.style.transition = "opacity 0.3s ease";
      setTimeout(() => toast.remove(), 300);
    }, 4000);
  },

   
   
   
  setupModals() {
    document.querySelectorAll(".tc-modal-backdrop").forEach((backdrop) => {
      backdrop.addEventListener("click", (e) => {
        if (e.target === backdrop) {
          backdrop.classList.remove("show");
        }
      });
    });
  },

  openModal(modalId) {
    const el = document.getElementById(modalId);
    if (el) el.classList.add("show");
  },

  closeModal(modalId) {
    const el = document.getElementById(modalId);
    if (el) el.classList.remove("show");
  },

  openDrawer(drawerId) {
    const el = document.getElementById(drawerId);
    if (el) el.classList.add("show");
  },

  closeDrawer(drawerId) {
    const el = document.getElementById(drawerId);
    if (el) el.classList.remove("show");
  },

  openAccountProfile() {
    this.openModal("modal-account-profile");
  },

  async uploadDriverAvatar(input) {
    const file = input?.files?.[0];
    if (!file) return;
    const allowedTypes = ["image/jpeg", "image/png", "image/webp"];
    if (!allowedTypes.includes(file.type) || file.size > 5 * 1024 * 1024) {
      this.showToast("Invalid Profile Photo", "Choose a JPG, PNG, or WebP image up to 5 MB.", "warning");
      input.value = "";
      return;
    }

    const button = document.getElementById("driver-avatar-button");
    const wrap = document.getElementById("driver-avatar-progress-wrap");
    const bar = document.getElementById("driver-avatar-progress");
    const value = document.getElementById("driver-avatar-progress-value");
    const label = document.getElementById("driver-avatar-progress-label");
    if (button) button.disabled = true;
    if (wrap) wrap.classList.remove("d-none");
    if (label) label.textContent = "Uploading profile photo...";
    if (bar) {
      bar.classList.remove("bg-danger");
      bar.style.width = "0%";
    }
    if (value) value.textContent = "0%";

    let progress = 0;
    const progressTimer = window.setInterval(() => {
      progress = Math.min(90, progress + 10);
      if (bar) bar.style.width = `${progress}%`;
      if (value) value.textContent = `${progress}%`;
    }, 500);

    const form = new FormData();
    form.append("avatar", file);
    const minimumWait = new Promise((resolve) => window.setTimeout(resolve, 5000));

    try {
      const request = fetch(`${window.TC_BASE_URL}/actions/profile-avatar.php`, { method: "POST", body: form })
        .then(async (response) => {
          const data = await response.json();
          if (!response.ok || !data.ok) throw new Error(data.error || "Profile photo upload failed.");
          return data;
        });
      const [data] = await Promise.all([request, minimumWait]);
      window.clearInterval(progressTimer);
      if (bar) bar.style.width = "100%";
      if (value) value.textContent = "100%";
      if (label) label.textContent = "Profile photo updated";
      document.querySelectorAll("[data-current-user-avatar]").forEach((image) => {
        image.src = `${data.avatar}?v=${Date.now()}`;
      });
      this.showToast("Profile Updated", data.message, "success");
      window.setTimeout(() => wrap?.classList.add("d-none"), 1200);
    } catch (error) {
      window.clearInterval(progressTimer);
      if (label) label.textContent = error.message;
      if (bar) bar.classList.add("bg-danger");
      this.showToast("Upload Failed", error.message, "danger");
    } finally {
      if (button) button.disabled = false;
      input.value = "";
    }
  },

   
   
   
  viewVehicleDetails(vehicleId) {
    let v = null;
    if (window.TC_VEHICLES_DATA && window.TC_VEHICLES_DATA[vehicleId]) {
      v = window.TC_VEHICLES_DATA[vehicleId];
    } else {
      const row = document.querySelector(`[data-vehicle-id="${vehicleId}"]`);
      if (row && row.dataset.vehicle) {
        try {
          v = JSON.parse(row.dataset.vehicle);
        } catch (err) {
           
        }
      }
    }
    if (!v) return;

    const modalBody = document.getElementById("modal-vehicle-details-body");
    if (!modalBody) return;

    modalBody.innerHTML = `
      <div class="row g-3">
        <div class="col-md-6 border-end">
          <h5 class="fw-bold mb-3 text-primary-custom"><i class="bi bi-truck me-2"></i>${v.brand} ${v.model} (${v.year})</h5>
          <table class="table table-sm border-0 small">
            <tr><td class="text-muted-custom">Vehicle ID:</td><td class="fw-semibold">${v.id}</td></tr>
            <tr><td class="text-muted-custom">Plate Number:</td><td><span class="badge bg-light text-dark border">${v.plateNumber}</span></td></tr>
            <tr><td class="text-muted-custom">Vehicle Type:</td><td class="fw-semibold">${v.type}</td></tr>
            <tr><td class="text-muted-custom">Passenger Capacity:</td><td>${v.capacity} Persons</td></tr>
            <tr><td class="text-muted-custom">Current Status:</td><td><span class="status-badge ${v.status === 'Available' ? 'status-available' : 'status-ontrip'}">${v.status}</span></td></tr>
            <tr><td class="text-muted-custom">Odometer:</td><td>${Number(v.odometer).toLocaleString()} km</td></tr>
            <tr><td class="text-muted-custom">Fuel Capacity / Level:</td><td>${v.fuelCapacity} L (${v.currentFuel}% Filled)</td></tr>
          </table>
        </div>
        <div class="col-md-6">
          <h5 class="fw-bold mb-3"><i class="bi bi-file-earmark-text me-2"></i>Documents & Compliance</h5>
          <div class="p-2 bg-light rounded small mb-3">
            <div><strong>Registration:</strong> ${v.documents.registration || '—'}</div>
            <div class="mt-1"><strong>Insurance:</strong> ${v.documents.insurance || '—'}</div>
            <div class="mt-1"><strong>LTFRB Permit:</strong> ${v.documents.ltfrbPermit || '—'}</div>
          </div>
          <h5 class="fw-bold mb-2"><i class="bi bi-speedometer2 me-2"></i>Performance & Cost</h5>
          <div class="p-2 bg-light rounded small">
            <div class="d-flex justify-content-between"><span>Lifetime Trips:</span> <strong>${v.performance.totalTrips}</strong></div>
            <div class="d-flex justify-content-between mt-1"><span>Average Fuel Economy:</span> <strong>${v.performance.avgFuelKm}</strong></div>
            <div class="d-flex justify-content-between mt-1"><span>Operating Cost:</span> <strong class="text-primary-custom">${v.performance.operatingCostKm}</strong></div>
          </div>
        </div>
      </div>
    `;
    this.openModal("modal-vehicle-details");
  },

   
   
   
  viewDriverDrawer(driverId) {
    let payload = null;
    document.querySelectorAll("[data-driver]").forEach((el) => {
      if (!payload) {
        try {
          const d = JSON.parse(el.dataset.driver);
          if (d.id === driverId) payload = d;
        } catch (err) {   }
      }
    });
    if (!payload) return;

    const drawerBody = document.getElementById("drawer-driver-content");
    if (!drawerBody) return;

    drawerBody.innerHTML = `
      <div class="text-center pb-3 border-bottom">
        <div class="driver-profile-photo-slot mx-auto mb-2" style="width:64px; height:64px;">
          ${payload.avatar ? `<img src="${payload.avatar}" alt="${payload.name} profile photo" class="driver-profile-photo">` : ""}
        </div>
        <h4 class="fw-bold mb-0">${payload.name}</h4>
        <span class="badge bg-primary-subtle text-primary border mt-1">${payload.empId}</span>
        <div class="text-muted-custom small mt-1">${payload.department}</div>
        <div class="small text-success mt-1"><i class="bi bi-check-circle-fill me-1"></i>${payload.hrmsStatus}</div>
      </div>

      <div class="p-3">
        <h5 class="fw-bold mb-2 small text-uppercase text-muted-custom">Licensing & Credentials</h5>
        <div class="bg-light p-2 rounded small mb-3">
          <div><strong>Driver License:</strong> ${payload.licenseNo}</div>
          <div class="mt-1"><strong>License Class:</strong> ${payload.licenseClass}</div>
          <div class="mt-1"><strong>License Expiry:</strong> ${payload.expiration}</div>
          <div class="mt-1"><strong>Contact Phone:</strong> ${payload.phone}</div>
        </div>

        <h5 class="fw-bold mb-2 small text-uppercase text-muted-custom">Operational Metrics</h5>
        <div class="row g-2 small text-center mb-3">
          <div class="col-4 p-2 bg-light rounded"><div class="fw-bold fs-6">${payload.tripCount}</div><div class="text-muted-custom">Total Trips</div></div>
          <div class="col-4 p-2 bg-light rounded"><div class="fw-bold fs-6 text-success">${payload.onTimeRate}</div><div class="text-muted-custom">On-Time</div></div>
          <div class="col-4 p-2 bg-light rounded"><div class="fw-bold fs-6 text-primary">${payload.safetyScore}</div><div class="text-muted-custom">Safety Pts</div></div>
        </div>

        <h5 class="fw-bold mb-2 small text-uppercase text-muted-custom">Assigned Vehicle</h5>
        <div class="p-2 border rounded small d-flex justify-content-between align-items-center">
          <span>${payload.assignedVehicle}</span>
          <span class="badge bg-success">Assigned</span>
        </div>
      </div>
    `;
    this.openDrawer("drawer-driver-profile");
  },

   
   
   
  openDispatchModal(reservationId) {
    if (!window.TC_KANBAN_RESERVATIONS || !window.TC_DISPATCH_DATA) {
      this.showToast("Unavailable", "Dispatch options could not be loaded.", "danger");
      return;
    }
    const r = window.TC_KANBAN_RESERVATIONS.find((x) => x.id === reservationId);
    if (!r) return;

    const modalBody = document.getElementById("modal-dispatch-body");
    if (!modalBody) return;

    const vehicles = window.TC_DISPATCH_DATA.vehicles || [];
    const drivers = window.TC_DISPATCH_DATA.drivers || [];
    const currentVehicle = r.assignedVehicle && r.assignedVehicle !== "Pending" ? r.assignedVehicle.split(" ")[0] : "";

    const dispatchLocked = !["Pending", "Assigned", "Confirmed"].includes(r.status);
    const canDispatch = (typeof window.TC_CAN_DISPATCH === "undefined" || window.TC_CAN_DISPATCH === true) && !dispatchLocked;

    if (!canDispatch) {
      const lockedNotice = dispatchLocked
        ? `<div class="alert alert-${r.status === "Completed" ? "success" : "secondary"} py-2 small">
             <i class="bi bi-lock-fill me-1"></i><strong>${r.status}:</strong> This trip is read-only and cannot be dispatched again.
           </div>`
        : "";
      modalBody.innerHTML = `
        ${lockedNotice}
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded">
            <div>
              <div class="fw-bold text-primary-custom">${r.id} - ${r.clientName}</div>
              <div class="small text-muted-custom">${r.origin} → ${r.destination}</div>
            </div>
            <span class="badge bg-primary">${r.passengerCount} Passengers</span>
          </div>
        </div>
        <div class="row g-3 small">
          <div class="col-md-6"><span class="text-muted-custom">Assigned Vehicle:</span> <strong>${r.assignedVehicle || "Pending"}</strong></div>
          <div class="col-md-6"><span class="text-muted-custom">Assigned Driver:</span> <strong>${r.assignedDriver || "Pending"}</strong></div>
          <div class="col-md-6"><span class="text-muted-custom">Departure Time:</span> <strong>${r.departureDate} ${r.departureTime}</strong></div>
          <div class="col-md-6"><span class="text-muted-custom">Estimated Cost:</span> <strong class="text-primary-custom">${r.estimatedCost}</strong></div>
          <div class="col-md-6"><span class="text-muted-custom">Trip Type:</span> <strong>${r.tripType || "Standard"}</strong></div>
          <div class="col-md-6"><span class="text-muted-custom">Contact:</span> <strong>${r.contactPhone || "—"}</strong></div>
          <div class="col-12"><span class="text-muted-custom">Notes:</span> <p class="mb-0 mt-1 p-2 bg-light rounded">${r.notes || "No special instructions provided."}</p></div>
        </div>
        <div class="d-flex justify-content-end gap-2 mt-4">
          <button type="button" class="tc-btn tc-btn-secondary" onclick="App.closeModal('modal-dispatch')">Close</button>
        </div>
      `;
      this.openModal("modal-dispatch");
      return;
    }

    modalBody.innerHTML = `
      <div class="mb-3">
        <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded">
          <div>
            <div class="fw-bold text-primary-custom">${r.id} - ${r.clientName}</div>
            <div class="small text-muted-custom">${r.origin} → ${r.destination}</div>
          </div>
          <span class="badge bg-primary">${r.passengerCount} Passengers</span>
        </div>
      </div>

      <form method="post" action="${window.TC_BASE_URL}/actions/dispatch.php">
        <input type="hidden" name="reservation_id" value="${r.id}">
        <input type="hidden" name="return" value="${window.location.pathname}">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="tc-form-label d-flex justify-content-between align-items-center">
              <span>Assign Vehicle</span>
              ${vehicles.some((v) => v.is_under_maintenance === true || v.is_under_maintenance === "t")
                ? `<span class="badge bg-danger-subtle text-danger border border-danger-subtle">Maintenance vehicles locked</span>`
                : ""}
            </label>
            <select class="tc-form-select" name="vehicle_id" required>
              ${vehicles.map((v) => {
                const underMaintenance = v.is_under_maintenance === true || v.is_under_maintenance === "t";
                return `<option value="${v.id}" ${v.id === currentVehicle ? "selected" : ""} ${underMaintenance ? "disabled" : ""}>${v.plate_number} - ${v.brand} ${v.model} (${v.capacity} pax)${underMaintenance ? " — UNDER MAINTENANCE · NOT AVAILABLE" : ` — ${v.status}`}</option>`;
              }).join("")}
            </select>
            <div class="form-text"><i class="bi bi-shield-lock me-1"></i>Vehicles under maintenance are visible for reference but cannot be selected.</div>
          </div>
          <div class="col-md-6">
            <label class="tc-form-label">Assign Driver</label>
            <select class="tc-form-select" name="driver_id" required>
              ${drivers.map((d) => `<option value="${d.id}" ${d.name === r.assignedDriver ? "selected" : ""}>${d.name} (${d.status} - Score: ${d.safety_score})</option>`).join("")}
            </select>
          </div>
          <div class="col-md-6">
            <label class="tc-form-label">Target Departure Time</label>
            <input type="text" class="tc-form-control" name="departure" value="${r.departureDate} ${r.departureTime}">
          </div>
          <div class="col-md-6">
            <label class="tc-form-label">Fuel Allowance / Budget</label>
            <input type="text" class="tc-form-control" value="${r.estimatedCost}" disabled>
          </div>
          <div class="col-12">
            <label class="tc-form-label">Trip Notes & Dispatch Instructions</label>
            <textarea class="tc-form-control" name="notes" rows="2">${r.notes || ""}</textarea>
          </div>
        </div>
        <div class="d-flex justify-content-end gap-2 mt-4">
          <button type="button" class="tc-btn tc-btn-secondary" onclick="App.closeModal('modal-dispatch')">Cancel</button>
          <button type="submit" class="tc-btn tc-btn-primary"><i class="bi bi-send-check me-1"></i> Confirm & Dispatch Trip</button>
        </div>
      </form>
    `;
    this.openModal("modal-dispatch");
  },

  openReservationCancellationModal(reservationId, actionType) {
    const records = window.TC_KANBAN_RESERVATIONS || [];
    const reservation = records.find((item) => item.id === reservationId);
    const body = document.getElementById("reservation-cancellation-body");
    const title = document.getElementById("reservation-cancellation-title");
    if (!reservation || !body) return;

    const isRecall = actionType === "recall_dispatch";
    const allowed = isRecall
      ? reservation.status === "Dispatched"
      : ["Pending", "Reserved", "Assigned", "Confirmed", "Ready for Dispatch"].includes(reservation.status);
    if (!allowed) {
      this.showToast("Action Locked", `A ${reservation.status} reservation cannot use this cancellation action.`, "warning");
      return;
    }

    const e = (value) => this.escapeHtml(value || "—");
    if (title) {
      title.innerHTML = isRecall
        ? '<i class="bi bi-arrow-counterclockwise me-2 text-danger"></i>Recall / Cancel Dispatch'
        : '<i class="bi bi-x-octagon me-2 text-danger"></i>Cancel Reservation';
    }
    body.innerHTML = `
      ${isRecall ? `<div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>This vehicle was dispatched but the trip has not started. Recall will cancel the dispatch and safely release its resources.</div>` : ""}
      <div class="border rounded p-3 mb-3 bg-light">
        <div class="row g-2 small">
          <div class="col-md-6"><span class="text-muted-custom">Reservation ID</span><div class="fw-semibold">${e(reservation.id)}</div></div>
          <div class="col-md-6"><span class="text-muted-custom">Trip Reference</span><div class="fw-semibold">${e(reservation.tripId)}</div></div>
          <div class="col-md-6"><span class="text-muted-custom">Pickup / Origin</span><div class="fw-semibold">${e(reservation.origin)}</div></div>
          <div class="col-md-6"><span class="text-muted-custom">Destination</span><div class="fw-semibold">${e(reservation.destination)}</div></div>
          <div class="col-md-6"><span class="text-muted-custom">Travel Schedule</span><div class="fw-semibold">${e(reservation.departureDate)} ${e(reservation.departureTime)}</div></div>
          <div class="col-md-6"><span class="text-muted-custom">Current Status</span><div><span class="badge bg-light text-dark border">${e(reservation.status)}</span></div></div>
          <div class="col-md-6"><span class="text-muted-custom">Assigned Vehicle</span><div class="fw-semibold">${e(reservation.assignedVehicle)}</div></div>
          <div class="col-md-6"><span class="text-muted-custom">Assigned Driver</span><div class="fw-semibold">${e(reservation.assignedDriver)}</div></div>
        </div>
      </div>
      <form method="post" action="${window.TC_BASE_URL}/actions/reservation-cancel.php" onsubmit="this.querySelector('[type=submit]').disabled=true">
        <input type="hidden" name="action" value="${isRecall ? "recall_dispatch" : "cancel_reservation"}">
        <input type="hidden" name="reservation_id" value="${e(reservation.id)}">
        <input type="hidden" name="return" value="${e(window.location.pathname)}">
        <div class="mb-3">
          <label class="tc-form-label">Cancellation Reason <span class="text-danger">*</span></label>
          <select class="tc-form-select" name="cancellation_reason" required onchange="App.toggleOtherCancellationReason(this)">
            <option value="">-- Select a reason --</option>
            <option>Customer Request</option><option>Trip Cancelled</option><option>Schedule Changed</option>
            <option>Duplicate Reservation</option><option>Vehicle Availability Issue</option>
            <option>Booking Information Error</option><option>Other</option>
          </select>
        </div>
        <div class="mb-3 d-none" data-other-reason-wrap>
          <label class="tc-form-label">Custom Explanation <span class="text-danger">*</span></label>
          <input type="text" class="tc-form-control" name="other_reason" maxlength="110">
        </div>
        <div class="mb-3">
          <label class="tc-form-label">Additional Notes <span class="text-muted-custom">(optional)</span></label>
          <textarea class="tc-form-control" name="cancellation_notes" rows="3" maxlength="2000"></textarea>
        </div>
        <div class="d-flex justify-content-end gap-2">
          <button type="button" class="tc-btn tc-btn-secondary" onclick="App.closeModal('modal-reservation-cancellation')">Keep Reservation / Close</button>
          <button type="submit" class="tc-btn tc-btn-danger"><i class="bi bi-check2-circle"></i>${isRecall ? "Confirm Recall & Cancellation" : "Confirm Cancellation"}</button>
        </div>
      </form>`;
    this.openModal("modal-reservation-cancellation");
  },

  toggleOtherCancellationReason(select) {
    const form = select.closest("form");
    const wrap = form?.querySelector("[data-other-reason-wrap]");
    const input = wrap?.querySelector("input");
    const visible = select.value === "Other";
    if (wrap) wrap.classList.toggle("d-none", !visible);
    if (input) {
      input.required = visible;
      if (!visible) input.value = "";
    }
  },

  openCancelledReservationDetails(reservationId) {
    const reservation = (window.TC_KANBAN_RESERVATIONS || []).find((item) => item.id === reservationId);
    const body = document.getElementById("reservation-cancellation-body");
    const title = document.getElementById("reservation-cancellation-title");
    if (!reservation || !body) return;
    const e = (value) => this.escapeHtml(value || "—");
    const cancelledAt = reservation.cancelledAt ? new Date(reservation.cancelledAt).toLocaleString() : "—";
    if (title) title.innerHTML = '<i class="bi bi-file-earmark-check me-2 text-secondary"></i>Cancellation Details';
    body.innerHTML = `
      <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
        <div><div class="text-muted-custom small">Reservation</div><div class="fw-bold">${e(reservation.id)}</div></div>
        <span class="status-badge bg-secondary text-white">Cancelled</span>
      </div>
      <dl class="row mb-0 small">
        <dt class="col-sm-4 text-muted-custom">Cancellation Type</dt><dd class="col-sm-8">${e(reservation.cancellationType)}</dd>
        <dt class="col-sm-4 text-muted-custom">Cancelled By</dt><dd class="col-sm-8">${e(reservation.cancelledBy)}</dd>
        <dt class="col-sm-4 text-muted-custom">Cancellation Date/Time</dt><dd class="col-sm-8">${e(cancelledAt)}</dd>
        <dt class="col-sm-4 text-muted-custom">Reason</dt><dd class="col-sm-8">${e(reservation.cancellationReason)}</dd>
        <dt class="col-sm-4 text-muted-custom">Additional Notes</dt><dd class="col-sm-8">${e(reservation.cancellationNotes)}</dd>
        <dt class="col-sm-4 text-muted-custom">Previously Assigned Vehicle</dt><dd class="col-sm-8">${e(reservation.cancelledVehicle)}</dd>
        <dt class="col-sm-4 text-muted-custom">Previously Assigned Driver</dt><dd class="col-sm-8">${e(reservation.cancelledDriver)}</dd>
      </dl>
      <div class="d-flex justify-content-end mt-3"><button type="button" class="tc-btn tc-btn-secondary" onclick="App.closeModal('modal-reservation-cancellation')">Close</button></div>`;
    this.openModal("modal-reservation-cancellation");
  },

   
   
   
  initNotificationsTabs() {
    const tabs = document.getElementById("notifications-tabs");
    if (!tabs) return;
    const first = tabs.querySelector(".tc-tab-btn");
    if (first) first.classList.add("active");
  },

  filterNotifications(cat) {
    this.notificationsFilter = cat;
    document.querySelectorAll("#notifications-tabs .tc-tab-btn").forEach((b) => {
      b.classList.toggle("active", b.dataset.notifCat === cat || (!b.dataset.notifCat && cat === "All"));
    });
    document.querySelectorAll("#notifications-full-list .notifications-item-row").forEach((row) => {
      const rowCat = row.dataset.notifCat || "";
      row.style.display = cat === "All" || rowCat === cat ? "" : "none";
    });
  },

  markNotificationRead(id, event) {
     
     
    if (event && event.stopPropagation) event.stopPropagation();
    const form = new FormData();
    form.append("action", "read");
    form.append("id", id);

    fetch(`${window.TC_BASE_URL}/actions/notifications.php`, { method: "POST", body: form, keepalive: true })
      .then((res) => res.json())
      .then((data) => {
        if (data.ok) this.updateNotifBadge(data.unread);
        const row = document.querySelector(`[data-notif-row="${id}"]`);
        if (row) row.classList.remove("unread");
        const item = document.querySelector(`[data-notif-id="${id}"]`);
        if (item) item.classList.remove("unread");
        this.hideMarkReadButtons(id);
      })
      .catch(() => {   });
  },

  markAllNotificationsRead() {
    const form = new FormData();
    form.append("action", "read_all");

    fetch(`${window.TC_BASE_URL}/actions/notifications.php`, { method: "POST", body: form })
      .then((res) => res.json())
      .then((data) => {
        if (data.ok) this.updateNotifBadge(data.unread);
        document.querySelectorAll(".notifications-item-row, .notifications-item").forEach((el) => el.classList.remove("unread"));
        document.querySelectorAll("[data-mark-read-btn]").forEach((el) => el.remove());
        this.showToast("All Cleared", "All notifications marked as read.", "success");
      })
      .catch(() => {   });
  },

  hideMarkReadButtons(id) {
    document.querySelectorAll(`[data-mark-read-btn="${id}"]`).forEach((el) => el.remove());
  },

  updateNotifBadge(unread) {
    const badge = document.getElementById("header-notif-badge");
    if (!badge) return;
    badge.textContent = unread;
    badge.style.display = unread > 0 ? "inline-block" : "none";
  },

  openNotification(id, event) {
    if (event) event.preventDefault();
    this.markNotificationRead(id);
    const row = document.querySelector(`[data-notif-row="${id}"]`);
    const target = row && row.dataset.notifTarget;
    if (target) {
      window.location.href = target;
    }
  },

  showReceipt(logId, receiptNo) {
    this.showToast("Fuel Receipt", `${logId} — Receipt ${receiptNo || "attached to transaction."}`, "info");
  },

   
   
   
  initCharts() {
    if (typeof Chart === "undefined" || !window.TC_CHART_DATA) return;

    Object.entries(window.TC_CHART_DATA).forEach(([canvasId, cfg]) => {
      const ctx = document.getElementById(canvasId);
      if (!ctx || this.charts[canvasId]) return;

      let chartCfg;
      if (cfg.type === "doughnut") {
        chartCfg = {
          type: "doughnut",
          data: {
            labels: cfg.labels,
            datasets: [{
              data: cfg.data,
              backgroundColor: cfg.colors,
              borderWidth: 2,
              borderColor: "#FFFFFF"
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: cfg.cutout || "70%",
            plugins: {
              legend: cfg.legend !== false ? { position: "bottom", labels: { font: { family: "Poppins", size: 11 } } } : { display: false }
            }
          }
        };
      } else if (cfg.type === "pie") {
        chartCfg = {
          type: "pie",
          data: {
            labels: cfg.labels,
            datasets: [{ data: cfg.data, backgroundColor: cfg.colors }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: cfg.legend !== false ? { position: "right" } : { display: false } }
          }
        };
      } else if (cfg.type === "line") {
        const yTicks = cfg.money
          ? { callback: (v) => (window.fleetCurrencySymbol || "₱") + (v / 1000) + "k", font: { family: "Poppins", size: 11 } }
          : { font: { family: "Poppins", size: 11 } };
        chartCfg = {
          type: "line",
          data: {
            labels: cfg.labels,
            datasets: [{
              label: cfg.label,
              data: cfg.data,
              borderColor: cfg.color,
              backgroundColor: cfg.fill ? cfg.color + "14" : "transparent",
              fill: !!cfg.fill,
              tension: 0.35,
              pointBackgroundColor: cfg.color,
              pointBorderColor: "#FFFFFF",
              pointBorderWidth: 2,
              pointRadius: 5,
              pointHoverRadius: 7,
              borderWidth: 3
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: cfg.legend !== false ? { labels: { font: { family: "Poppins", size: 11 } } } : { display: false } },
            scales: {
              y: { grid: { color: "#EEF2F7" }, ticks: yTicks },
              x: { grid: { display: false }, ticks: { font: { family: "Poppins", size: 11 } } }
            }
          }
        };
      } else {
         
        const yScale = {};
        if (cfg.min !== undefined) yScale.min = cfg.min;
        if (cfg.max !== undefined) yScale.max = cfg.max;
        yScale.ticks = cfg.max !== undefined ? { callback: (v) => v + "%" } : undefined;
        chartCfg = {
          type: "bar",
          data: {
            labels: cfg.labels,
            datasets: [{
              label: cfg.label,
              data: cfg.data,
              backgroundColor: cfg.color || "#2F80ED",
              borderRadius: 4
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: cfg.legend !== false ? { labels: { font: { family: "Poppins", size: 11 } } } : { display: false } },
            scales: {
              y: Object.keys(yScale).length ? yScale : { grid: { color: "#EEF2F7" }, ticks: { font: { family: "Poppins", size: 11 } } },
              x: { grid: { display: false }, ticks: { font: { family: "Poppins", size: 11 } } }
            }
          }
        };
      }

      this.charts[canvasId] = new Chart(ctx, chartCfg);
    });
  },

   
   
   
  applyChartTheme() {
    const styles = getComputedStyle(document.documentElement);
    const color = styles.getPropertyValue('--tc-text-muted').trim();
    const surface = styles.getPropertyValue('--tc-bg-card').trim();
    const border = styles.getPropertyValue('--tc-border').trim();
    Object.values(this.charts).forEach((chart) => {
      const options = chart.config.options;
      options.color = color;
      if (options.plugins.legend) {
        options.plugins.legend.labels = { ...options.plugins.legend.labels, color };
      }
      Object.values(options.scales || {}).forEach((scale) => {
        scale.ticks = { ...scale.ticks, color };
        scale.grid = { ...scale.grid, color: border };
        scale.border = { ...scale.border, color: border };
      });
      chart.data.datasets.forEach((dataset) => {
        if (['pie', 'doughnut'].includes(chart.config.type)) dataset.borderColor = surface;
        if (chart.config.type === 'line') dataset.pointBorderColor = surface;
      });
      chart.update('none');
    });
  },

  initRouteMap() {
    const mapEl = document.getElementById("map") || document.getElementById("route-map");
    if (typeof aiRouteEngine === "undefined" || !mapEl) return;
    setTimeout(() => {
      if (typeof aiRouteEngine.init === "function") {
        aiRouteEngine.init(mapEl.id);
      } else if (typeof aiRouteEngine.initMap === "function") {
        aiRouteEngine.initMap(mapEl.id);
      }
    }, 100);
  }
};

window.App = App;
window.showAppToast = (t, m, tp) => App.showToast(t, m, tp);
