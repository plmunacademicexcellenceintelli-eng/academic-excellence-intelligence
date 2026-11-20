// Phase 2 - session helpers for every protected page (loaded after js/app.js).
// Security is enforced by PHP on the server; this file only improves the experience:
//  - Log out: confirmation dialog, then a POST with the CSRF token to logout.php
//  - Inactivity: activity on the page keeps the session alive; after the timeout the page reloads
//    and the server sends the user to the sign-in page
//  - Back button: a page restored from the browser cache is reloaded (and re-checked by the server)
(function () {
  var B = window.AEI_BOOT;
  if (!B) return;

  // ---------- Log out ----------
  var wrap = document.createElement('div');
  wrap.innerHTML =
    '<div class="modal fade" id=logoutModal tabindex=-1 aria-labelledby=logoutTitle aria-hidden=true><div class="modal-dialog modal-dialog-centered modal-sm"><div class=modal-content>' +
    '<div class=modal-header><h6 class=m-0 id=logoutTitle>Log out</h6><button type=button class=btn-close data-bs-dismiss=modal aria-label=Close></button></div>' +
    '<div class=modal-body>Are you sure you want to log out? You will need to sign in again to continue.</div>' +
    '<div class=modal-footer><button type=button class="btn btn-o" data-bs-dismiss=modal>Cancel</button>' +
    '<form method=post id=logoutForm class=m-0><input type=hidden name=csrf_token><button type=submit class="btn btn-p" id=logoutConfirm>Log out</button></form></div>' +
    '</div></div></div>';
  document.body.appendChild(wrap.firstChild);
  var form = document.getElementById('logoutForm');
  form.action = B.urls.logout;
  form.elements.csrf_token.value = B.csrf;

  var btn = document.getElementById('logoutBtn');
  if (btn) btn.addEventListener('click', function () {
    if (window.bootstrap && bootstrap.Modal) bootstrap.Modal.getOrCreateInstance(document.getElementById('logoutModal')).show();
    else if (window.confirm('Are you sure you want to log out?')) form.submit();
  });

  // ---------- Inactivity timeout ----------
  var idleMs = (B.idleTimeout || 1800) * 1000, lastInput = Date.now(), lastPing = Date.now();
  function expired() { location.href = B.urls.login + '?expired=1'; }
  function ping() {
    lastPing = Date.now();
    fetch(B.urls.keepalive, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': B.csrf } })
      .then(function (r) { if (r.status === 401) expired(); })
      .catch(function () { /* offline: the server still enforces the timeout */ });
  }
  ['click', 'keydown', 'scroll', 'touchstart', 'mousemove'].forEach(function (ev) {
    window.addEventListener(ev, function () {
      lastInput = Date.now();
      if (lastInput - lastPing > 60000) ping();   // at most one keep-alive per minute
    }, { passive: true });
  });
  setInterval(function () {
    // No activity for the whole timeout: reload; the server ends the session and shows the sign-in page.
    if (Date.now() - lastInput > idleMs + 5000) location.reload();
  }, 15000);

  // ---------- Back / forward cache ----------
  window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });

  window.AEI_SESSION = { ping: ping };
})();
