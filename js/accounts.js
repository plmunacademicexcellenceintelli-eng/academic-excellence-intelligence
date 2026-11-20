// Phase 2 - Admin > Settings > User Accounts. Talks to api/accounts.php (Admin only; checked on the server).
// Uses the shared helpers of js/app.js: $, esc, tbl, pill, modal, S.
(function () {
  var B = window.AEI_BOOT, body = document.getElementById('accBody');
  if (!B || !body) return;
  var accounts = [], me = 0;

  function api(method, data) {
    var opt = { method: method, credentials: 'same-origin', headers: { 'X-CSRF-Token': B.csrf } };
    if (data) { var fd = new FormData(); Object.keys(data).forEach(function (k) { fd.append(k, data[k]); }); opt.body = fd; }
    return fetch(B.urls.accounts, opt).then(function (r) {
      return r.json().catch(function () { return { error: 'Unexpected server response (' + r.status + ').' }; }).then(function (j) {
        if (r.status === 401) { location.href = B.urls.login + '?expired=1'; }
        if (!r.ok) throw new Error(j.error || ('Request failed (' + r.status + ').'));
        return j;
      });
    });
  }
  function note(type, html) { var m = document.getElementById('accMsg'); if (m) m.innerHTML = '<div class="alert alert-' + type + ' py-2 small mb-2">' + html + '</div>'; }
  function tempBox(user, pw) {
    return 'Temporary password for <b>' + esc(user) + '</b>: <span class=temp-pw>' + esc(pw) + '</span><br>' +
      '<span class=small>Shown only once. Give it to the user privately; they must choose a new password at first sign-in.</span>';
  }

  function draw() {
    var rows = accounts.map(function (a) {
      var linked = a.role === 'student' ? (a.studentId ? 'ID ' + a.studentId : '<span class="pill y">not linked</span>') : '—';
      var status = (a.active ? pill('Active', 'g') : pill('Inactive', 'r')) + (a.mustChange ? ' ' + pill('Temporary password', 'y') : '');
      var acts = '<button class="btn btn-o btn-sm acc-reset" data-u="' + a.userId + '">Reset password</button> ' +
        (a.userId === me ? '<small class=text-muted>(you)</small>' :
          '<button class="btn btn-o btn-sm acc-act ' + (a.active ? 'text-danger' : '') + '" data-u="' + a.userId + '" data-a="' + (a.active ? 0 : 1) + '">' + (a.active ? 'Deactivate' : 'Activate') + '</button>');
      return [esc(a.username), esc(a.name), a.role === 'admin' ? 'Admin' : 'Student', linked, status, esc(a.lastLogin || 'Never'), acts];
    });
    body.innerHTML = '<div id=accMsg></div>' +
      '<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2"><span class=text-muted>' + accounts.length + ' account(s). Admin accounts are created only on the server with <code>scripts/create_admin.php</code>.</span>' +
      '<button class="btn btn-p btn-sm" id=accNew><i class="bi bi-person-plus"></i> Create student account</button></div>' +
      tbl(['Username', 'Name', 'Role', 'Student record', 'Status', 'Last sign-in', 'Actions'], rows, 'accTable');
    document.getElementById('accNew').onclick = createForm;
    body.querySelectorAll('.acc-reset').forEach(function (b) { b.onclick = function () { reset(+b.dataset.u); }; });
    body.querySelectorAll('.acc-act').forEach(function (b) { b.onclick = function () { setActive(+b.dataset.u, b.dataset.a); }; });
  }
  function load() {
    return api('GET').then(function (j) { accounts = j.accounts; me = j.me; draw(); })
      .catch(function (e) { body.innerHTML = '<div class="alert alert-danger py-2 small">' + esc(e.message) + '</div>'; });
  }
  function find(id) { return accounts.find(function (a) { return a.userId === id; }); }

  function reset(id) {
    var a = find(id); if (!a) return;
    if (!confirm('Set a new temporary password for ' + a.username + '? The current password will stop working.')) return;
    api('POST', { action: 'reset_password', user_id: id }).then(function (j) { return load().then(function () { note('warning', tempBox(a.username, j.tempPassword)); }); })
      .catch(function (e) { note('danger', esc(e.message)); });
  }
  function setActive(id, val) {
    var a = find(id); if (!a) return;
    if (val === '0' && !confirm('Deactivate ' + a.username + '? They will be signed out and cannot sign in until the account is activated again.')) return;
    api('POST', { action: 'set_active', user_id: id, active: val }).then(function (j) { return load().then(function () { note('success', esc(j.message)); }); })
      .catch(function (e) { note('danger', esc(e.message)); });
  }

  // Phase 3: the student record is created first in Student Management (Module 1); here the Admin only
  // creates its sign-in account. Username = the student number; the server generates a temporary password.
  function createForm() {
    var free = S.filter(function (s) { return !s.hasAccount; });
    if (!free.length) {
      modal('Create student account', '<p class=mb-0>Every student record already has a sign-in account. Add the student in <a href="profile.php">Student Management</a> first.</p>');
      return;
    }
    var opts = free.map(function (s) { return '<option value="' + s.id + '">' + s.id + ' · ' + esc(s.name) + ' · ' + esc(s.num) + '</option>'; }).join('');
    modal('Create student account',
      '<form id=accForm novalidate><div id=accErr></div>' +
      '<label for=acc_student class=form-label>Student record (from Student Management)</label><select id=acc_student class=form-select required>' + opts + '</select>' +
      '<p class="small text-muted mt-2 mb-3">The sign-in username is the student number. A temporary password is generated by the server and shown once; the student must change it at first sign-in.</p>' +
      '<button class="btn btn-p" type=submit>Create account</button> <button class="btn btn-o" type=button data-bs-dismiss=modal>Cancel</button></form>');
    var form = document.getElementById('accForm');
    form.onsubmit = function (e) {
      e.preventDefault();
      var sb = form.querySelector('button[type=submit]'); sb.disabled = true;
      var sid = +document.getElementById('acc_student').value;
      api('POST', { action: 'create_student', student_id: sid }).then(function (j) {
        var st = S.find(function (x) { return x.id === sid; }); if (st) st.hasAccount = true;
        bootstrap.Modal.getInstance(document.getElementById('m')).hide();
        return load().then(function () { note('success', 'Student account created. ' + tempBox(j.username, j.tempPassword)); });
      }).catch(function (er) { sb.disabled = false; document.getElementById('accErr').innerHTML = '<div class="alert alert-danger py-2 small">' + esc(er.message) + '</div>'; });
    };
  }
  load();
})();
