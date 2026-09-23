// Keep native link behavior, but do not navigate after a drag or text selection.
document.querySelectorAll('.dashboard-card-link, .dashboard-panel-link').forEach(function (link) {
  let start = null;
  let dragged = false;
  link.addEventListener('pointerdown', function (event) {
    start = { x: event.clientX, y: event.clientY };
    dragged = false;
  });
  link.addEventListener('pointermove', function (event) {
    if (start && Math.hypot(event.clientX - start.x, event.clientY - start.y) > 10) {
      dragged = true;
    }
  });
  link.addEventListener('pointercancel', function () {
    start = null;
    dragged = true;
  });
  link.addEventListener('click', function (event) {
    const selection = window.getSelection();
    const selectedText = selection && !selection.isCollapsed &&
      selection.containsNode(link, true);
    if (event.detail !== 0 && (dragged || selectedText)) event.preventDefault();
    start = null;
    dragged = false;
  });
});
