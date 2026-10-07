 








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
    document.querySelectorAll('[data-vehicle-photo]').forEach(image => {
      if (image.complete && !image.naturalWidth) this.handleVehiclePhotoError(image);
    });
    this.setupEventListeners();
    this.setupModals();
    this.applyToastFromUrl();
    this.initCharts();
    this.applyChartTheme();
    new MutationObserver(() => this.applyChartTheme()).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
    this.initRouteMap();
    this.initNotificationsTabs();
    this.setupSidebarScrollPersistence();
    this.setupComplianceUploadReview();
    if (window.TC_OPEN_VEHICLE_ID && window.TC_VEHICLES_DATA?.[window.TC_OPEN_VEHICLE_ID]) {
      this.viewVehicleDetails(window.TC_OPEN_VEHICLE_ID);
    }
  },

  setupComplianceUploadReview() {
    const labels = {
      provider: "Provider", policy_number: "Policy Number", effective_date: "Effective Date",
      expiration_date: "Expiration Date", permit_number: "Permit Number", plate_number: "Plate Number",
      chassis_number: "Chassis Number", engine_number: "Engine Number", mv_file_number: "MV File Number",
      cpc_number: "CPC Number"
    };
    document.addEventListener("change", (event) => {
      const input = event.target.closest('form[data-compliance-upload] input[name="document"]');
      if (!input) return;
      const form = input.closest("form");
      form.dataset.reviewed = "false";
      const review = form.querySelector("[data-document-review]");
      const confirm = form.querySelector("[data-manual-review]");
      const button = form.querySelector('button[type="submit"]');
      if (review) review.textContent = "";
      if (confirm) {
        confirm.hidden = true;
        confirm.querySelector("input").required = false;
        confirm.querySelector("input").checked = false;
        const manualPlate = confirm.querySelector('[name="manual_vehicle_plate"]');
        if (manualPlate) {
          manualPlate.required = false;
          manualPlate.value = "";
        }
      }
      form.dataset.matchStatus = "";
      if (button) {
        button.disabled = false;
        button.textContent = "Analyze Document";
      }
    });

    document.addEventListener("submit", async (event) => {
      const form = event.target.closest("form[data-compliance-upload]");
      if (!form || form.dataset.reviewed === "true") return;
      event.preventDefault();
      const input = form.querySelector('input[name="document"]');
      const review = form.querySelector("[data-document-review]");
      const confirm = form.querySelector("[data-manual-review]");
      const button = form.querySelector('button[type="submit"]');
      if (!input?.files.length) return;

      let documentTypeBlocked = false;
      button.disabled = true;
      review.textContent = "Analyzing this document...";
      try {
        const response = await fetch(`${window.TC_BASE_URL}/actions/vehicle-document-analyze.php`, {
          method: "POST", body: new FormData(form), headers: { Accept: "application/json" }
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || "Document analysis failed.");
        const extracted = Object.entries(result.document_data || {})
          .map(([key, value]) => `${labels[key] || key}: ${value}`).join(" · ");
        const match = result.vehicle_match;
        const typeVerification = result.document_type_verification;
        const typeStatus = typeVerification?.status || "NEEDS_REVIEW";
        const matchStatus = match?.status || "NEEDS_REVIEW";
        form.dataset.matchStatus = matchStatus;
        form.dataset.typeStatus = typeStatus;

        let overallResult;
        if (typeStatus === "MISMATCHED") {
          overallResult = `REJECTED — Wrong document type. ${typeVerification.reason}`;
        } else if (typeStatus !== "MATCHED") {
          overallResult = `NEEDS REVIEW — Document type could not be verified. ${typeVerification?.reason || "Upload a clearer searchable document."}`;
        } else if (matchStatus === "MISMATCHED") {
          overallResult = `REJECTED — Document belongs to a different vehicle. ${match.reason}`;
        } else if (matchStatus !== "MATCHED") {
          overallResult = `NEEDS REVIEW — Vehicle identity could not be verified. ${match?.reason || "Upload a clearer document or review its identifiers."}`;
        } else {
          overallResult = "CHECKS PASSED — Document type and vehicle identity are both verified.";
        }

        const matchDetails = match
          ? `${match.status} — ${match.reason}${match.matched_identifiers?.length ? ` Matched: ${match.matched_identifiers.join(", ")}.` : ""}${match.conflicting_identifiers?.length ? ` Conflicts: ${match.conflicting_identifiers.join(", ")}.` : ""}`
          : "NEEDS_REVIEW — Vehicle match could not be checked.";
        const typeDetails = typeVerification
          ? `${typeVerification.status} — ${typeVerification.reason}`
          : "NEEDS_REVIEW — Document type could not be checked.";
        review.textContent = `${overallResult} Document type: ${typeDetails} Vehicle match: ${matchDetails} Extracted fields: ${extracted || "No reliable vehicle identifier was detected."}`;
        if (typeVerification?.status !== "MATCHED") {
          form.dataset.reviewed = "true";
          documentTypeBlocked = true;
          button.textContent = typeVerification?.status === "MISMATCHED" ? "Wrong document type" : "Type not verified";
          return;
        }
        if (match?.status === "MISMATCHED") {
          form.dataset.reviewed = "true";
          button.textContent = "Document does not match";
          documentTypeBlocked = true;
          return;
        }
        if (match?.status !== "MATCHED" && confirm) {
          confirm.hidden = false;
          confirm.querySelector("input").required = true;
          const manualPlate = confirm.querySelector('[name="manual_vehicle_plate"]');
          if (manualPlate) manualPlate.required = match?.status !== "MATCHED";
        }
        form.dataset.reviewed = "true";
        button.textContent = match?.status === "MATCHED"
          ? (form.dataset.existing === "true" ? "Confirm & Replace" : "Confirm & Upload")
          : "Confirm Review & Retry";
      } catch (error) {
        review.textContent = error.message;
      } finally {
        button.disabled = documentTypeBlocked;
      }
    });
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
    // Mobile keyboards shrink the visible viewport, not always the layout viewport.
    const updateVisibleViewport = () => {
      const viewport = window.visualViewport;
      document.documentElement.style.setProperty('--tc-visible-height', `${viewport ? viewport.height : window.innerHeight}px`);
      document.documentElement.style.setProperty('--tc-visible-top', `${viewport ? viewport.offsetTop : 0}px`);
    };
    updateVisibleViewport();
    window.addEventListener('resize', updateVisibleViewport, {passive: true});
    window.visualViewport?.addEventListener('resize', updateVisibleViewport, {passive: true});
    window.visualViewport?.addEventListener('scroll', updateVisibleViewport, {passive: true});
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

   
   
   
  renderVehiclePhoto(photo, name, extraClass = "") {
    const e = (value) => this.escapeHtml(value);
    const placeholder = `${window.TC_BASE_URL || ""}/assets/images/vehicle-placeholder.svg`;
    const p = photo || {src:placeholder,source:"placeholder",placeholderSrc:placeholder,label:"Vehicle photo unavailable"};
    return `<span class="vehicle-photo ${e(extraClass)}" data-photo-source="${e(p.source)}"><img src="${e(p.src)}" alt="${e(name)} — ${e(p.label)}" loading="lazy" decoding="async" onload="this.parentElement.dataset.photoLoaded=1" data-vehicle-photo data-source="${e(p.source)}" data-sample-src="${e(p.sampleSrc || "")}" data-placeholder-src="${e(p.placeholderSrc || placeholder)}" onerror="App.handleVehiclePhotoError(this)"><span class="vehicle-photo-label" title="${e(p.label)}">${p.source === "sample" ? "Sample" : p.source === "placeholder" ? "No photo" : ""}</span></span>`;
  },

  handleVehiclePhotoError(image) {
    const wrapper = image.closest(".vehicle-photo");
    if (wrapper) delete wrapper.dataset.photoLoaded;
    const label = wrapper?.querySelector(".vehicle-photo-label");
    let source = "placeholder";
    if (image.dataset.source === "actual" && image.dataset.sampleSrc) {
      source = "sample";
      image.src = image.dataset.sampleSrc;
    } else if (image.dataset.source !== "placeholder") {
      image.src = image.dataset.placeholderSrc;
    } else {
      // Inline neutral icon remains visible even if the placeholder asset fails.
      image.hidden = true;
      wrapper?.insertAdjacentHTML("afterbegin", '<i class="bi bi-truck-front" aria-hidden="true"></i>');
    }
    image.dataset.source = source;
    if (wrapper) wrapper.dataset.photoSource = source;
    image.alt = source === "sample" ? "Sample vehicle illustration — not actual fleet" : "Vehicle photo unavailable";
    if (label) { label.textContent = source === "sample" ? "Sample" : "No photo"; label.title = image.alt; }
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

    const renderDocument = (label, documentInfo, type, allowUpload = false) => {
      const documentData = documentInfo || { exists: false, summary: "—", status: "not_detected" };
      const reviewStatus = documentData.exists
        ? documentData.testFixture ? "TEST ONLY fixture"
          : documentData.status === "needs_review" ? "Needs review"
          : documentData.status === "not_detected" ? "Not detected"
            : "Extracted successfully"
        : "";
      const matchStatus = documentData.exists && (type === "insurance" || type === "ltfrb_permit")
        ? documentData.testFixture ? "TEST ONLY; not verified against a live vehicle document."
          : `Vehicle match: ${documentData.vehicleMatchStatus || "Needs review"}`
        : "";
      const formId = `compliance-${this.escapeHtml(v.id)}-${type}`;
      const isVerifiedDocument = documentData.exists && documentData.vehicleMatchStatus === "MATCHED";
      const uploadForm = allowUpload && v.canManageDocuments && !isVerifiedDocument ? `
        <form method="post" action="${window.TC_BASE_URL}/actions/vehicle-compliance-document.php" enctype="multipart/form-data" class="mt-2" data-compliance-upload data-existing="${documentData.exists ? "true" : "false"}" data-reviewed="false">
          <input type="hidden" name="csrf_token" value="${this.escapeHtml(v.csrfToken)}">
          <input type="hidden" name="vehicle_id" value="${this.escapeHtml(v.id)}">
          <input type="hidden" name="document_type" value="${type}">
          <div class="d-flex flex-column flex-sm-row gap-2">
            <input id="${formId}" type="file" name="document" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required aria-label="${label} document">
            <button type="submit" class="tc-btn tc-btn-secondary tc-btn-sm flex-shrink-0">Analyze Document</button>
          </div>
          <div class="small text-muted-custom mt-1" data-document-review role="status" aria-live="polite"></div>
          <div class="small text-muted-custom mt-1" data-manual-review hidden>
            <label><input type="checkbox" name="manual_review_confirm" value="1"> I reviewed this document manually.</label>
            <label class="d-block mt-1">Plate number shown on the document
              <input type="text" name="manual_vehicle_plate" class="form-control form-control-sm text-uppercase" maxlength="20" autocomplete="off">
            </label>
          </div>
        </form>` : "";
      return `<div class="py-2 border-bottom">
        <div class="d-flex flex-wrap justify-content-between gap-2"><strong>${label}:</strong><span>${this.escapeHtml(documentData.summary || "—")}</span></div>
        ${reviewStatus ? `<div class="small text-muted-custom mt-1">${reviewStatus}</div>` : ""}
        ${matchStatus ? `<div class="small text-muted-custom mt-1">${this.escapeHtml(matchStatus)}</div>` : ""}
        ${uploadForm}
      </div>`;
    };

    modalBody.innerHTML = `
      <section class="vehicle-photo-profile" aria-label="Vehicle Photo">
        ${this.renderVehiclePhoto(v.photo, `${v.brand} ${v.model}`)}
        <div class="vehicle-photo-profile-controls">
          <h5 class="fw-bold mb-1">Vehicle Photo</h5>
          <div class="small text-muted-custom">${this.escapeHtml(v.photo?.label || "Vehicle photo unavailable")}</div>
          ${v.canManagePhoto ? `<form method="post" enctype="multipart/form-data" action="${window.TC_BASE_URL}/actions/vehicle-photo-upload.php">
            <input type="hidden" name="vehicle_id" value="${this.escapeHtml(v.id)}">
            <input type="hidden" name="csrf_token" value="${this.escapeHtml(v.csrfToken)}">
            <input type="file" class="form-control form-control-sm" name="vehicle_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required aria-label="Actual Vehicle Photo">
            <button type="submit" class="tc-btn tc-btn-primary tc-btn-sm">${v.photo?.hasActual ? "Change Photo" : "Upload Actual Photo"}</button>
          </form><div class="small text-muted-custom mt-1">JPG, PNG, or WebP · Up to 5 MB. Updates the directory and assignments automatically.</div>` : ""}
        </div>
      </section>
      <div class="row g-3">
        <div class="col-md-6 border-end">
          <h5 class="fw-bold mb-3 text-primary-custom"><i class="bi bi-truck me-2"></i>${v.brand} ${v.model} (${v.year})</h5>
          <table class="table table-sm border-0 small">
            <tr><td class="text-muted-custom">Vehicle ID:</td><td class="fw-semibold">${v.id}</td></tr>
            <tr><td class="text-muted-custom">Plate Number:</td><td><span class="badge bg-light text-dark border">${v.plateNumber}</span></td></tr>
            <tr><td class="text-muted-custom">Vehicle Type:</td><td class="fw-semibold">${v.type}</td></tr>
            <tr><td class="text-muted-custom">Passenger Capacity:</td><td>${v.capacity} Persons</td></tr>
            <tr><td class="text-muted-custom">Current Status:</td><td><span class="status-badge ${v.status === 'Available' ? 'status-available' : 'status-ontrip'}">${v.status}</span></td></tr>
            <tr><td class="text-muted-custom">Operational Status:</td><td><strong class="${v.operational.operational ? 'text-success' : 'text-danger'}">${this.escapeHtml(v.operational.status)}</strong>${v.operational.reason ? `<div class="small text-danger mt-1">${this.escapeHtml(v.operational.reason)}</div>` : v.operational.test_data ? '<div class="small text-muted-custom mt-1">TEST ONLY data</div>' : ''}</td></tr>
            <tr><td class="text-muted-custom">Odometer:</td><td>${Number(v.odometer).toLocaleString()} km</td></tr>
          </table>
        </div>
        <div class="col-md-6">
          <h5 class="fw-bold mb-3"><i class="bi bi-file-earmark-text me-2"></i>Documents & Compliance</h5>
          <div class="p-2 bg-light rounded small mb-3">
            ${renderDocument("Registration", v.documents.registration, "registration")}
            ${renderDocument("Insurance", v.documents.insurance, "insurance", true)}
            ${renderDocument("LTFRB Permit", v.documents.ltfrbPermit, "ltfrb_permit", true)}
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

        <h5 class="fw-bold mb-2 small text-uppercase text-muted-custom">Customer Ratings</h5>
        <div class="p-2 border rounded small mb-3">
          ${payload.ratingCount > 0
            ? `<div class="d-flex justify-content-between align-items-center"><strong class="text-warning"><i class="bi bi-star-fill me-1"></i>${Number(payload.rating).toFixed(2)}/5</strong><span class="text-muted-custom">${payload.ratingCount} rating${payload.ratingCount === 1 ? "" : "s"}</span></div>
               ${(payload.recentReviews || []).length ? `<div class="mt-2 pt-2 border-top">${payload.recentReviews.map((review) => `<div class="mb-2"><span class="text-warning">${"★".repeat(Number(review.stars))}${"☆".repeat(5 - Number(review.stars))}</span>${review.feedback ? `<div class="text-muted-custom">${this.escapeHtml(review.feedback)}</div>` : ""}</div>`).join("")}</div>` : ""}`
            : `<span class="text-muted-custom">No customer ratings yet.</span>`}
        </div>

        <h5 class="fw-bold mb-2 small text-uppercase text-muted-custom">Assigned Vehicle</h5>
        <div class="p-2 border rounded small d-flex justify-content-between align-items-center">
          <span><span class="text-muted-custom">Default:</span> ${this.escapeHtml(payload.assignedVehicle)}<br><span class="text-muted-custom">Current Trip:</span> ${this.escapeHtml(payload.currentTripVehicle)}</span>
          <span class="badge bg-success">${payload.currentTripVehicle !== "None" ? "On Trip" : "Default"}</span>
        </div>
      </div>
    `;
    this.openDrawer("drawer-driver-profile");
  },

   
   
   
  openDispatchModal(reservationId, intent = "dispatch") {
    if (!window.TC_KANBAN_RESERVATIONS || !window.TC_DISPATCH_DATA) {
      this.showToast("Unavailable", "Dispatch options could not be loaded.", "danger");
      return;
    }
    const r = window.TC_KANBAN_RESERVATIONS.find((x) => x.id === reservationId);
    if (!r) return;

    const modalBody = document.getElementById("modal-dispatch-body");
    if (!modalBody) return;

    const drivers = window.TC_DISPATCH_DATA.drivers || [];
    const currentVehicle = r.assignedVehicleId || (r.assignedVehicle && r.assignedVehicle !== "Pending" ? r.assignedVehicle.split(" ")[0] : "");
    const currentDriver = r.assignedDriverId || drivers.find((d) => d.name === r.assignedDriver)?.id || "";

    const dispatchLocked = !["Approved", "Assigned", "Confirmed"].includes(r.status);
    const canDispatch = window.TC_CAN_DISPATCH === true && !dispatchLocked;
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
          <div class="col-md-6"><span class="text-muted-custom">Required Vehicle Type:</span> <strong>${this.escapeHtml(r.requiredVehicleType || "Not recorded")}</strong></div>
          <div class="col-md-6"><span class="text-muted-custom">Required Capacity:</span> <strong>${r.requiredCapacity ? Number(r.requiredCapacity) + " passengers" : "Not recorded"}</strong></div>
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

    if (this.renderAssignmentForm(r, intent, currentVehicle, currentDriver) === false) return;
    this.openModal("modal-dispatch");
  },

  renderAssignmentForm(r, intent, currentVehicle, currentDriver) {
    if (["Assigned", "Confirmed"].includes(r.status) && intent !== "assign" && window.TripFunding) {
      this.closeModal("modal-dispatch");
      window.TripFunding.open(r.id);
      return false;
    }
    const body = document.getElementById("modal-dispatch-body");
    const esc = (value) => this.escapeHtml(String(value ?? ""));
    const locked = ["Assigned", "Confirmed"].includes(r.status) && intent !== "assign";
    body.innerHTML = `<form class="dispatch-assignment-form" method="post" action="${window.TC_BASE_URL}/actions/dispatch.php">
      <input type="hidden" name="csrf" value="${esc(window.TC_ASSIGNMENT_CSRF)}">
      <input type="hidden" name="reservation_id" value="${esc(r.id)}">
      <input type="hidden" name="return" value="${esc(window.location.pathname)}">
      <input type="hidden" name="vehicle_id" value="">
      <input type="hidden" name="driver_id" value="">
      <input type="hidden" name="reassignment_from" value="">
      <div class="assignment-form-content">
        <section class="assignment-summary" aria-label="Reservation summary">
          <div class="assignment-summary-heading"><div class="assignment-summary-identity"><span class="assignment-reservation-id">${esc(r.id)}</span><span class="assignment-status is-available">${esc(r.status)}</span></div><span class="assignment-status assignment-passengers"><i class="bi bi-people-fill" aria-hidden="true"></i> ${Number(r.passengerCount)} Passengers</span></div>
          <strong class="assignment-customer">${esc(r.clientName)}</strong>
          <div class="assignment-summary-bottom"><span class="assignment-route"><i class="bi bi-geo-alt" aria-hidden="true"></i> ${esc(r.origin)} → ${esc(r.destination)}</span><div class="assignment-requirements"><span><i class="bi bi-truck" aria-hidden="true"></i> ${esc(r.requiredVehicleType || "Any vehicle type")}</span><span><i class="bi bi-people" aria-hidden="true"></i> Minimum <span data-minimum>${Number(r.requiredCapacity || r.passengerCount)} seats</span></span></div></div>
        </section>
        <section><h5><i class="bi bi-truck" aria-hidden="true"></i> Vehicle Assignment</h5><div data-assignment-message role="status" aria-live="polite">Checking fleet and driver availability…</div><div data-vehicle-options></div></section>
        <section><h5><i class="bi bi-person-badge" aria-hidden="true"></i> Assigned Driver</h5><div class="assigned-driver-info" data-driver-info>Select a vehicle to resolve its designated driver.</div></section>
        <div class="assignment-trip-fields">
          <section><label class="tc-form-label" for="assignment-departure"><i class="bi bi-calendar-event" aria-hidden="true"></i> Target Departure Time</label><input id="assignment-departure" type="datetime-local" class="tc-form-control" name="departure" required value="${esc(r.departureDate)}T${esc(r.departureTime)}" ${locked || r.departureScheduleInstanceId ? "readonly" : ""}>${r.departureScheduleInstanceId ? '<p class="form-text">Shared departure follows the reservation schedule.</p>' : ''}</section>
          <section><label class="tc-form-label" for="assignment-notes"><i class="bi bi-file-earmark-text" aria-hidden="true"></i> Trip Notes & Dispatch Instructions</label><textarea id="assignment-notes" class="tc-form-control" name="notes" rows="2" placeholder="Add notes or special instructions…">${esc(r.notes)}</textarea></section>
        </div>
      </div><div class="assignment-form-footer"><button type="button" class="tc-btn tc-btn-secondary" onclick="App.closeModal('modal-dispatch')">Cancel</button><button type="submit" name="dispatch_action" value="${locked ? "dispatch" : "assign"}" class="tc-btn tc-btn-primary" disabled><i class="bi bi-check-circle" aria-hidden="true"></i> ${locked ? "Dispatch Trip" : "Confirm Assignment"}</button></div></form>`;
    const form = body.querySelector("form");
    const vehicleInput = form.elements.vehicle_id;
    const driverInput = form.elements.driver_id;
    const confirmation = form.elements.reassignment_from;
    const submit = form.querySelector('button[type="submit"]');
    const info = form.querySelector('[data-driver-info]');
    const message = form.querySelector('[data-assignment-message]');
    const options = form.querySelector('[data-vehicle-options]');
    let selected = null, snapshot = null, request = 0;
    const updateDriver = (v) => {
      const unavailableDrivers = (v.unavailable_drivers || []);
      const unavailableDriverDetails = unavailableDrivers.length ? `<details class="eligible-driver-panel mt-3"><summary>Unavailable drivers (${unavailableDrivers.length}) · View reasons</summary><ul class="small ps-3 mt-2 mb-0">${unavailableDrivers.map(d => `<li class="mb-2"><strong>${esc(d.name)}</strong><br><span class="text-danger">${esc(d.reason)}</span></li>`).join('')}</ul></details>` : '';
      driverInput.value = ""; confirmation.value = ""; submit.disabled = true;
      if (v.driver) {
        driverInput.value = v.driver.id;
        info.classList.remove('needs-driver', 'has-driver-selection');
        info.innerHTML = `<div class="assignment-driver-heading"><strong>${esc(v.driver.name)}</strong><span class="assignment-status ${v.driver.reason ? 'is-unavailable' : 'is-available'}">${v.driver.reason ? "Unavailable" : "Available"}</span></div><p>${esc(v.driver.reason || "Automatically assigned from " + v.id)}</p>`;
        submit.disabled = !v.eligible || (locked && v.driver.id !== currentDriver);
        info.insertAdjacentHTML('beforeend', unavailableDriverDetails);
        return;
      }
      info.classList.remove('has-driver-selection');
      info.classList.add('needs-driver');
      info.innerHTML = `<div class="assignment-driver-prompt"><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i><div><strong>No driver assigned</strong><p>Select an eligible driver for this vehicle.</p></div></div><details class="eligible-driver-panel"><summary><i class="bi bi-person-plus" aria-hidden="true"></i> Select Driver</summary><div class="eligible-driver-list">${v.drivers.map(d => `<button type="button" class="eligible-driver-option" data-driver-id="${esc(d.id)}"><strong>${esc(d.name)}</strong><span>Available · Compatible</span><span>Current Vehicle: ${esc(d.current_vehicles.map(x=>x.id+" ("+x.plate+")").join(", ") || "None")}</span>${d.reassignment ? '<span class="text-warning">Reassignment Required</span>' : ''}</button>`).join("")}</div></details><div data-reassignment-review></div>`;
      info.insertAdjacentHTML('beforeend', unavailableDriverDetails);
      info.querySelectorAll('[data-driver-id]').forEach(button => button.addEventListener('click', () => {
        const d = v.drivers.find(x=>x.id===button.dataset.driverId);
        driverInput.value = ""; confirmation.value = ""; submit.disabled = true;
        const review = info.querySelector('[data-reassignment-review]');
        const accept = () => {
          driverInput.value = d.id;
          confirmation.value = d.reassignment ? d.current_vehicles.map(x=>x.id).join(',') : '';
          info.classList.remove('needs-driver');
          info.classList.add('has-driver-selection');
          info.querySelector('.assignment-driver-prompt').innerHTML = `<i class="bi bi-person-check-fill" aria-hidden="true"></i><div><strong>${esc(d.name)}</strong><p>Selected for ${esc(v.id)} · Pending confirmation</p></div>`;
          info.querySelector('.eligible-driver-panel > summary').innerHTML = '<i class="bi bi-person-plus" aria-hidden="true"></i> Change Driver';
          review.innerHTML = `<p class="assignment-driver-selection-status"><i class="bi bi-check-circle" aria-hidden="true"></i> Available · Compatible${d.reassignment ? ' · Reassignment confirmed' : ''}</p>`;
          info.querySelector('details').open = false; submit.disabled = locked || !v.eligible;
        };
        if (!d.reassignment) { accept(); return; }
        review.innerHTML = `<dialog class="assignment-reassignment-dialog" aria-label="Reassign Driver?"><div class="assignment-reassignment"><h6>Reassign Driver?</h6><p>${esc(d.name)} is currently designated to ${esc(d.current_vehicles.map(x=>x.id).join(', '))}. Confirming will remove that designation.</p><p>Current: ${esc(d.current_vehicles.map(x=>x.id).join(', '))} → ${esc(d.name)}<br>New: ${esc(v.id)} → ${esc(d.name)}</p><div class="d-flex flex-wrap gap-2"><button type="button" class="tc-btn tc-btn-secondary" data-cancel-reassignment>Cancel</button><button type="button" class="tc-btn tc-btn-primary" data-confirm-reassignment>Confirm Reassignment</button></div></div></dialog>`;
        review.querySelector('dialog').showModal();
        review.querySelector('[data-cancel-reassignment]').addEventListener('click',()=>{review.innerHTML='';});
        review.querySelector('[data-confirm-reassignment]').addEventListener('click',accept);
      }));
    };
    const selectVehicle = id => {
      selected = snapshot.vehicles.find(v=>v.id===id && v.eligible);
      vehicleInput.value = selected?.id || '';
      options.querySelectorAll('[data-vehicle-id]').forEach(card=>{card.classList.toggle('is-selected',card.dataset.vehicleId===selected?.id);card.setAttribute('aria-pressed',String(card.dataset.vehicleId===selected?.id));});
      if (selected) updateDriver(selected);
    };
    const card = (v,recommended=false) => {
      const statusClass = !v.eligible ? 'is-unavailable' : v.status === 'Available' ? 'is-available' : 'is-attention';
      return `<button type="button" class="assignment-vehicle-card ${recommended ? 'is-recommended' : ''}" data-vehicle-id="${esc(v.id)}" ${!v.eligible || (locked && v.id!==currentVehicle) ? 'disabled' : ''} aria-pressed="false">
        <span class="assignment-choice-indicator" aria-hidden="true"></span>
        ${this.renderVehiclePhoto(v.photo, v.name, "assignment-vehicle-thumbnail")}
        <span class="assignment-vehicle-content">
          ${recommended ? '<span class="assignment-recommendation-label"><span class="assignment-recommended-badge"><i class="bi bi-star-fill" aria-hidden="true"></i> Recommended</span><span class="assignment-match-badge">Best Match</span></span>' : ''}
          <span class="assignment-vehicle-heading"><strong>${esc(v.name)}</strong><span class="assignment-status ${statusClass}">${esc(v.status)}</span></span>
          <span class="assignment-vehicle-meta">${esc(v.id)} · ${esc(v.plate)}${recommended ? '' : ` · ${v.capacity} seats · ${esc(v.type)}`}</span>
          ${recommended ? `<span class="assignment-vehicle-meta">${v.capacity} seats · ${esc(v.type)}</span>` : ''}
          <span class="assignment-vehicle-driver">${recommended ? 'Assigned Driver' : 'Driver'}: <span class="${!v.driver ? 'assignment-driver-none' : ''}">${esc(v.driver?.name || 'None')}</span></span>
          ${v.reasons.length ? `<span class="assignment-picker-reason">${esc(v.reasons.join(' · '))}</span>` : recommended ? `<span class="assignment-reason"><i class="bi bi-info-circle-fill" aria-hidden="true"></i> ${r.requiredVehicleType ? 'Matches required type and capacity.' : 'Meets required passenger capacity.'} ${v.driver ? 'Vehicle and designated driver are available.' : 'Select an eligible driver.'}</span>` : ''}
        </span></button>`;
    };
    const refresh = async () => {
      const token = ++request;
      submit.disabled=true; vehicleInput.value=''; driverInput.value=''; confirmation.value='';
      options.innerHTML=''; info.textContent='Checking driver availability…';
      message.textContent='Checking fleet and driver availability…';
      try {
        const params = new URLSearchParams({reservation_id:r.id,departure:form.elements.departure.value});
        const response = await fetch(`${window.TC_BASE_URL}/actions/reservation-assignment-options.php?${params}`,{credentials:'same-origin',cache:'no-store'});
        const data = await response.json();
        if (token!==request || !form.isConnected) return;
        if (!response.ok || data.error) throw new Error(data.error || 'Could not check availability.');
        snapshot=data;
        form.querySelector('[data-minimum]').textContent=`${data.minimum_capacity} seats`;
        const recommended = data.vehicles.find(v=>v.id===data.recommended_id);
        message.textContent=locked ? 'Dispatch uses the saved vehicle and driver only.' : recommended ? 'System recommended a suitable vehicle based on your reservation requirements.' : 'No valid vehicle-driver combination is available. Review the restrictions below.';
        const alternatives = data.vehicles.filter(v=>v.eligible && v.id!==data.recommended_id);
        const unavailable = data.vehicles.filter(v=>!v.eligible);
        options.innerHTML = `${recommended ? card(recommended,true) : ''}
          <div class="assignment-other-vehicles"><h6>Other Eligible Vehicles (${alternatives.length})</h6><div class="assignment-vehicle-list assignment-eligible-list">${alternatives.map(v=>card(v)).join('') || '<p class="assignment-empty-list">No other eligible vehicles.</p>'}</div></div>
          <details class="assignment-unavailable"><summary><i class="bi bi-slash-circle" aria-hidden="true"></i><span>Unavailable / Incompatible Vehicles (${unavailable.length})</span><i class="bi bi-chevron-down" aria-hidden="true"></i></summary><div class="assignment-vehicle-list assignment-unavailable-list">${unavailable.map(v=>card(v)).join('') || '<p class="assignment-empty-list">None.</p>'}</div></details>`;
        options.querySelectorAll('[data-vehicle-id]').forEach(button=>button.addEventListener('click',()=>selectVehicle(button.dataset.vehicleId)));
        const preferred = selected?.id || currentVehicle;
        selectVehicle(locked ? currentVehicle : (data.vehicles.some(v=>v.id===preferred && v.eligible) ? preferred : data.recommended_id));
        if (!selected) info.textContent='No valid assignment. Correct the listed restrictions before confirming.';
      } catch(error) { if(token===request) {message.textContent=error.message; info.textContent='Availability could not be verified.';} }
    };
    form.elements.departure.addEventListener('change',refresh);
    form.addEventListener('submit',event=>{if(submit.disabled || !vehicleInput.value || !driverInput.value) event.preventDefault();});
    refresh();
  },

  enhanceAssignmentPickers(container) {
    container.querySelectorAll("select[data-assignment-picker]").forEach((select) => {
      const picker = document.createElement("details");
      picker.className = "assignment-picker";
      const trigger = document.createElement("summary");
      trigger.className = "tc-form-select assignment-picker-trigger";
      trigger.setAttribute("aria-label", select.dataset.assignmentPicker);
      const list = document.createElement("div");
      list.className = "assignment-picker-list";
      const content = (option) => {
        const wrapper = document.createElement("span");
        wrapper.className = "assignment-picker-content";
        const heading = document.createElement("span");
        heading.className = "assignment-picker-heading";
        const text = document.createElement("span");
        text.textContent = option.disabled ? option.textContent.split(" — ")[0] : option.textContent;
        const badge = document.createElement("span");
        badge.className = "assignment-status " + (option.disabled ? "is-unavailable" : "is-available");
        badge.textContent = option.disabled ? "Unavailable" : "Available";
        heading.append(text, badge);
        wrapper.append(heading);
        if (option.disabled && option.dataset.reason) {
          const reason = document.createElement("span");
          reason.className = "assignment-picker-reason";
          reason.textContent = option.dataset.reason;
          wrapper.append(reason);
        }
        return wrapper;
      };
      const sync = () => {
        const option = select.selectedOptions[0];
        trigger.replaceChildren(option ? content(option) : document.createTextNode("No entries available"));
      };
      Array.from(select.options).forEach((option) => {
        const row = document.createElement("button");
        row.type = "button";
        row.className = "assignment-picker-row";
        row.disabled = option.disabled || select.disabled;
        row.append(content(option));
        row.addEventListener("click", () => {
          if (option.disabled || select.disabled) return;
          select.value = option.value;
          select.dispatchEvent(new Event("change", {bubbles: true}));
          picker.open = false;
          trigger.focus();
        });
        list.append(row);
      });
      select.addEventListener("change", sync);
      select.addEventListener("invalid", (event) => {
        event.preventDefault();
        picker.open = true;
        trigger.focus();
      });
      trigger.addEventListener("click", (event) => {
        if (select.disabled) event.preventDefault();
      });
      trigger.setAttribute("aria-disabled", String(select.disabled));
      picker.addEventListener("keydown", (event) => {
        if (event.key === "Escape") { picker.open = false; trigger.focus(); }
      });
      picker.addEventListener("focusout", () => {
        setTimeout(() => { if (!picker.contains(document.activeElement)) picker.open = false; }, 0);
      });
      picker.append(trigger, list);
      select.after(picker);
      select.classList.add("visually-hidden");
      select.tabIndex = -1;
      select.setAttribute("aria-hidden", "true");
      sync();
    });
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
    form.append("csrf_token", document.getElementById("header-notif-btn")?.dataset.csrf || "");
    form.append("id", id);

    fetch(`${window.TC_BASE_URL}/actions/notifications.php`, { method: "POST", body: form, keepalive: true })
      .then((res) => res.json())
      .then((data) => {
        if (!data.ok) return;
        this.updateNotifBadge(data.unread);
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
    form.append("csrf_token", document.getElementById("header-notif-btn")?.dataset.csrf || "");

    fetch(`${window.TC_BASE_URL}/actions/notifications.php`, { method: "POST", body: form })
      .then((res) => res.json())
      .then((data) => {
        if (!data.ok) return;
        this.updateNotifBadge(data.unread);
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

      if (document.body.classList.contains("toursphere-dashboard")) {
        const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        chartCfg.options.animation = {
          duration: reduceMotion ? 0 : 850,
          easing: "easeOutQuart",
          delay: (context) => reduceMotion ? 0 : (context.type === "data" ? context.dataIndex * 70 : 0)
        };
        chartCfg.options.interaction = { mode: "nearest", intersect: false };
        if (chartCfg.options.plugins?.tooltip) {
          Object.assign(chartCfg.options.plugins.tooltip, {
            displayColors: false,
            backgroundColor: "rgba(15, 23, 42, .92)",
            padding: 10,
            cornerRadius: 7
          });
        }
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
        if (chart.config.type === 'line') dataset.pointBorderColor = dataset.borderColor;
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
