// Sign-in and change-password pages: password visibility toggle and simple input checks.
// These checks only help the user; the server repeats every check.
(function () {
  document.querySelectorAll('.pw-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.dataset.target);
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Hide' : 'Show';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      input.focus();
    });
  });

  var form = document.querySelector('form[novalidate]');
  if (!form) return;
  form.addEventListener('submit', function (e) {
    form.querySelectorAll('input').forEach(function (i) {
      if (i.type !== 'hidden') i.value = i.name === 'identifier' ? i.value.trim() : i.value;
    });
    var nw = document.getElementById('new_password'), cf = document.getElementById('confirm_password');
    if (nw && cf) cf.setCustomValidity(cf.value && cf.value !== nw.value ? 'mismatch' : '');
    if (!form.checkValidity()) {
      e.preventDefault();
      form.classList.add('was-validated');
      var bad = form.querySelector(':invalid');
      if (bad) bad.focus();
      return;
    }
    var b = form.querySelector('button[type=submit]');
    if (b) { b.disabled = true; b.textContent = 'Please wait…'; }
  });
  // Pages restored from the browser's back/forward cache are reloaded so they never show stale state.
  window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
})();
