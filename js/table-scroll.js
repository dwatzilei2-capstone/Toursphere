/* Shared grab scrolling for existing wide table containers. */
(() => {
  'use strict';
  const controls = 'a,button,input,select,textarea,label,summary,[contenteditable]:not([contenteditable="false"]),[role="button"],[role="link"],[role="checkbox"],[role="switch"],[role="slider"],[role="textbox"],[role="combobox"],[onclick],[data-bs-toggle],[data-bs-dismiss],[data-no-table-drag]';
  const containers = new Set();
  let gesture = null;
  let scheduled = false;
  let suppressClick = false;
  const resize = new ResizeObserver(() => schedule());

  function refresh() {
    scheduled = false;
    document.querySelectorAll('table').forEach(table => {
      resize.observe(table);
      for (let parent = table.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
        if (!['auto', 'scroll'].includes(getComputedStyle(parent).overflowX)) continue;
        containers.add(parent);
        resize.observe(parent);
        break;
      }
    });
    containers.forEach(container => {
      if (!container.isConnected) { resize.unobserve(container); containers.delete(container); return; }
      const wide = container.clientWidth > 0 && container.scrollWidth > container.clientWidth + 1;
      container.classList.toggle('tc-table-draggable', wide);
      if (!wide && gesture?.container === container) finish();
    });
  }
  function schedule() {
    if (!scheduled) { scheduled = true; requestAnimationFrame(refresh); }
  }
  function targetContainer(target) {
    const container = target.closest('.tc-table-draggable');
    return container && container.scrollWidth > container.clientWidth + 1 ? container : null;
  }
  function verticalContainer(container) {
    for (let parent = container; parent && parent !== document.body; parent = parent.parentElement) {
      if (['auto', 'scroll'].includes(getComputedStyle(parent).overflowY) && parent.scrollHeight > parent.clientHeight + 1) return parent;
    }
    return document.scrollingElement;
  }
  function finish() {
    if (!gesture) return;
    const { container, pointerId, active } = gesture;
    gesture = null;
    container.classList.remove('tc-table-dragging');
    if (container.hasPointerCapture(pointerId)) container.releasePointerCapture(pointerId);
    if (active) {
      suppressClick = true;
      setTimeout(() => { suppressClick = false; }, 0);
    }
  }
  document.addEventListener('pointerdown', event => {
    if (event.pointerType !== 'mouse' || event.button !== 0 || event.target.closest(controls)) return;
    const container = targetContainer(event.target);
    if (!container) return;
    const rect = container.getBoundingClientRect();
    // Preserve direct interaction with the native bottom/right scrollbars.
    if (event.clientY >= rect.top + container.clientTop + container.clientHeight ||
        event.clientX >= rect.left + container.clientLeft + container.clientWidth) return;
    const vertical = verticalContainer(container);
    gesture = { container, vertical, pointerId: event.pointerId, x: event.clientX, y: event.clientY, left: container.scrollLeft, top: vertical.scrollTop, active: false };
  });
  document.addEventListener('pointermove', event => {
    if (!gesture || event.pointerId !== gesture.pointerId) return;
    if (!(event.buttons & 1)) { finish(); return; }
    const dx = event.clientX - gesture.x;
    const dy = event.clientY - gesture.y;
    if (!gesture.active) {
      if (Math.max(Math.abs(dx), Math.abs(dy)) < 5) return;
      gesture.active = true;
      gesture.container.setPointerCapture(event.pointerId);
      gesture.container.classList.add('tc-table-dragging');
      window.getSelection()?.removeAllRanges();
    }
    event.preventDefault();
    gesture.container.scrollLeft = gesture.left - dx;
    gesture.vertical.scrollTo({ top: gesture.top - dy, behavior: 'instant' });
  }, { passive: false });
  document.addEventListener('pointerup', finish);
  document.addEventListener('pointercancel', finish);
  document.addEventListener('lostpointercapture', finish);
  window.addEventListener('blur', finish);
  document.addEventListener('dragstart', event => {
    if (gesture && !event.target.closest(controls)) event.preventDefault();
  });
  document.addEventListener('click', event => {
    if (suppressClick && !event.target.closest(controls)) { event.preventDefault(); event.stopImmediatePropagation(); }
  }, true);
  document.addEventListener('wheel', event => {
    // Native horizontal trackpad gestures stay native. Convert vertical Shift-wheel only.
    if (!event.shiftKey || event.ctrlKey || event.deltaX || !event.deltaY || event.target.closest(controls)) return;
    const container = targetContainer(event.target);
    if (!container) return;
    const scale = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? container.clientWidth : 1;
    const previous = container.scrollLeft;
    container.scrollLeft += event.deltaY * scale;
    if (container.scrollLeft !== previous) event.preventDefault();
  }, { passive: false });
  new MutationObserver(records => {
    if (records.some(record => record.type === 'childList' || !['class'].includes(record.attributeName))) schedule();
  }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'hidden', 'class'] });
  window.addEventListener('resize', schedule);
  // Class changes can reveal modal/tab tables; refresh without observing our own classes.
  document.addEventListener('shown.bs.modal', schedule);
  document.addEventListener('shown.bs.tab', schedule);
  refresh();
})();
