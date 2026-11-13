/* Extranet DeepFind : thème clair/sombre, menu repliable, confirmations. */
(function () {
  'use strict';
  var root = document.documentElement;
  function read(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function write(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* stockage indisponible */ } }

  // Appliqué avant l'affichage pour éviter un flash de thème.
  var theme = read('df-theme');
  if (theme !== 'light' && theme !== 'dark') {
    theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  }
  root.setAttribute('data-theme', theme);
  if (read('df-menu') === 'collapsed') { root.classList.add('menu-collapsed'); }

  /*
   * Fenêtres de confirmation et d'information aux couleurs de l'extranet (remplacent confirm() / alert() du navigateur).
   * DeepFindUI.confirm(message, {ok, danger}) et DeepFindUI.alert(message) renvoient une promesse.
   */
  function uiDialog(message, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var dlg = document.createElement('dialog');
      dlg.className = 'modal modal-confirm';
      dlg.setAttribute('aria-labelledby', 'df-ui-title');
      var head = document.createElement('div');
      head.className = 'modal-head';
      var h = document.createElement('h2');
      h.id = 'df-ui-title';
      h.textContent = opts.title || (opts.alert ? 'Information' : 'Confirmation');
      head.appendChild(h);
      var body = document.createElement('div');
      body.className = 'modal-body';
      var p = document.createElement('p');
      p.textContent = message;
      body.appendChild(p);
      var foot = document.createElement('div');
      foot.className = 'modal-foot';
      var cancel = null;
      if (!opts.alert) {
        cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-light';
        cancel.textContent = 'Annuler';
        cancel.addEventListener('click', function () { dlg.close('cancel'); });
        foot.appendChild(cancel);
      }
      var ok = document.createElement('button');
      ok.type = 'button';
      ok.className = 'btn' + (opts.danger ? ' btn-danger-solid' : '');
      ok.setAttribute('data-ok', '');
      ok.textContent = opts.ok || 'OK';
      ok.addEventListener('click', function () { dlg.close('ok'); });
      foot.appendChild(ok);
      dlg.appendChild(head);
      dlg.appendChild(body);
      dlg.appendChild(foot);
      // Clic hors du cadre = annuler ; Échap = annuler (comportement natif de <dialog>)
      dlg.addEventListener('click', function (ev) { if (ev.target === dlg) { dlg.close('cancel'); } });
      dlg.addEventListener('close', function () {
        resolve(dlg.returnValue === 'ok' || (opts.alert && true));
        dlg.remove();
      });
      document.body.appendChild(dlg);
      dlg.showModal();
      (opts.danger && cancel ? cancel : ok).focus();
    });
  }
  window.DeepFindUI = {
    confirm: function (message, opts) { return uiDialog(message, opts); },
    alert: function (message, opts) { return uiDialog(message, Object.assign({ alert: true }, opts || {})); }
  };

  document.addEventListener('DOMContentLoaded', function () {
    function label() {
      document.querySelectorAll('[data-theme-label]').forEach(function (el) {
        el.textContent = root.getAttribute('data-theme') === 'light' ? 'clair' : 'sombre';
      });
    }
    label();
    document.querySelectorAll('[data-toggle-theme]').forEach(function (b) {
      b.addEventListener('click', function () {
        var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        root.setAttribute('data-theme', next);
        write('df-theme', next);
        label();
      });
    });
    var navigation = document.querySelector('[data-nav-groups]');
    if (navigation) {
      var groups = Array.from(navigation.querySelectorAll('[data-nav-group]'));
      var activeGroup = navigation.querySelector('.contains-active');
      var lastGroup = read('df-nav-group');
      var currentGroup = activeGroup || groups.find(function (group) { return group.dataset.navGroup === lastGroup; });
      if (currentGroup) {
        groups.forEach(function (group) { group.open = group === currentGroup; });
      }
      if (activeGroup) { write('df-nav-group', activeGroup.dataset.navGroup); }
      groups.forEach(function (group) {
        group.querySelector('summary').addEventListener('click', function () {
          if (!group.open) { write('df-nav-group', group.dataset.navGroup); }
        });
      });
    }
    document.querySelectorAll('[data-toggle-menu]').forEach(function (b) {
      b.addEventListener('click', function () {
        var collapsed = root.classList.toggle('menu-collapsed');
        write('df-menu', collapsed ? 'collapsed' : 'open');
      });
    });
    // data-confirm : demande de confirmation avant l'envoi (le bouton cliqué donne son libellé au bouton de validation)
    document.addEventListener('submit', function (ev) {
      var form = ev.target;
      if (form.__dfConfirmed) { form.__dfConfirmed = false; return; }
      var btn = ev.submitter;
      var msg = (btn && btn.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
      if (!msg) { return; }
      ev.preventDefault();
      ev.stopImmediatePropagation();
      var label = btn ? btn.textContent.replace(/\s+/g, ' ').trim() : '';
      var danger = !!(btn && /btn-danger/.test(btn.className)) || /supprim|désactiv|vider|purge|restaur|écras|bloqu/i.test(label + ' ' + msg);
      window.DeepFindUI.confirm(msg, { ok: label && label.length <= 30 ? label : 'Confirmer', danger: danger }).then(function (yes) {
        if (!yes) { return; }
        form.__dfConfirmed = true;
        if (form.requestSubmit) { form.requestSubmit(btn && btn.form === form ? btn : undefined); } else { form.submit(); }
      });
    }, true);
    document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
      el.addEventListener('change', function () { el.form.submit(); });
    });
    // Suivi des imports en cours (interrogation toutes les 2 s, arrêt quand tout est terminé)
    var progEls = document.querySelectorAll('[data-run-progress]');
    if (progEls.length && window.fetch) {
      var base = (document.querySelector('link[href*="/assets/admin.css"]') || {}).href || '';
      var statusUrl = base.replace(/\/assets\/admin\.css.*$/, '/admin/imports/status');
      var ids = Array.prototype.map.call(progEls, function (n) { return n.getAttribute('data-run-progress'); });
      var timer = setInterval(poll, 2000);
      poll();
      function poll() {
        fetch(statusUrl + '?ids=' + ids.join(','), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (data) {
            if (!data) { return; }
            var stillActive = false;
            data.runs.forEach(function (run) {
              document.querySelectorAll('[data-run-progress="' + run.id + '"]').forEach(function (box) {
                var bar = box.querySelector('.prog-bar span');
                if (bar) { bar.style.width = run.percent + '%'; }
                ['percent', 'label', 'detail', 'elapsed', 'eta'].forEach(function (f) {
                  var el = box.querySelector('[data-f="' + f + '"]');
                  if (el && run[f] !== null && run[f] !== undefined) { el.textContent = run[f]; }
                });
                var w = box.querySelector('[data-f-wrap="eta"]');
                if (w) { w.hidden = !run.eta; }
                var steps = box.querySelectorAll('[data-step]');
                var idx = -1;
                steps.forEach(function (st, i) { if (st.getAttribute('data-step') === run.phase) { idx = i; } });
                steps.forEach(function (st, i) {
                  st.className = run.status === 'success' || (idx >= 0 && i < idx) ? 'done' : (i === idx && run.active ? 'on' : '');
                });
              });
              if (run.active) { stillActive = true; }
            });
            if (!stillActive) {
              clearInterval(timer);
              setTimeout(function () { window.location.reload(); }, 800);
            }
          })
          .catch(function () { /* nouvel essai au prochain tour */ });
      }
    }

    // Fenêtres modales (<dialog>) : ouverture par data-dialog-open="id", fermeture par bouton, Échap ou clic hors du cadre
    document.querySelectorAll('[data-dialog-open]').forEach(function (b) {
      b.addEventListener('click', function () {
        var dlg = document.getElementById(b.getAttribute('data-dialog-open'));
        if (dlg && typeof dlg.showModal === 'function') { dlg.showModal(); }
      });
    });
    document.querySelectorAll('dialog.modal').forEach(function (dlg) {
      dlg.addEventListener('click', function (ev) {
        if (ev.target === dlg || ev.target.closest('[data-dialog-close]')) { dlg.close(); }
      });
    });

    // Champs d'envoi de fichier (file_field) : glisser-déposer, nom et taille du fichier, retrait
    function fmtSize(b) { return b < 1024 ? b + ' o' : b < 1048576 ? Math.round(b / 1024) + ' Ko' : (b / 1048576).toFixed(1).replace('.', ',') + ' Mo'; }
    document.querySelectorAll('[data-file-drop]').forEach(function (box) {
      var input = box.querySelector('input[type=file]');
      var zone = box.querySelector('[data-file-zone]');
      var chosen = box.querySelector('[data-file-chosen]');
      var err = box.querySelector('[data-file-error]');
      var accept = (input.getAttribute('accept') || '').split(',').map(function (a) { return a.trim().toLowerCase(); }).filter(Boolean);
      function okType(file) {
        if (!accept.length) { return true; }
        var name = file.name.toLowerCase();
        return accept.some(function (a) { return a.charAt(0) === '.' ? name.slice(-a.length) === a : (a.slice(-2) === '/*' ? file.type.indexOf(a.slice(0, -1)) === 0 : file.type === a); });
      }
      function show() {
        var file = input.files && input.files[0];
        err.hidden = true;
        zone.hidden = !!file;
        chosen.hidden = !file;
        if (file) {
          chosen.querySelector('[data-file-name]').textContent = file.name;
          chosen.querySelector('[data-file-size]').textContent = fmtSize(file.size);
        }
      }
      input.addEventListener('change', show);
      box.querySelector('[data-file-clear]').addEventListener('click', function () { input.value = ''; show(); zone.focus(); });
      ['dragenter', 'dragover'].forEach(function (t) { box.addEventListener(t, function (ev) { ev.preventDefault(); box.classList.add('is-over'); }); });
      ['dragleave', 'drop'].forEach(function (t) { box.addEventListener(t, function (ev) { ev.preventDefault(); if (t === 'drop' || !box.contains(ev.relatedTarget)) { box.classList.remove('is-over'); } }); });
      box.addEventListener('drop', function (ev) {
        var file = ev.dataTransfer && ev.dataTransfer.files[0];
        if (!file) { return; }
        if (!okType(file)) { err.textContent = 'Format non accepté : ' + file.name; err.hidden = false; return; }
        var dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        show();
      });
      // la zone est un <label> : Entrée / Espace l'ouvrent aussi au clavier
      zone.tabIndex = 0;
      zone.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); input.click(); } });
    });

    document.querySelectorAll('[data-dim-row] input').forEach(function (cb) {
      cb.addEventListener('change', function () { var tr = cb.closest('tr'); if (tr) { tr.classList.toggle('dim', !cb.checked); } });
    });

    // Planification : un choix prédéfini remplit l'expression cron
    document.querySelectorAll('select[data-cron-preset]').forEach(function (sel) {
      var input = document.getElementById(sel.getAttribute('data-cron-preset'));
      sel.addEventListener('change', function () {
        if (sel.value !== '__custom') { input.value = sel.value; } else { input.focus(); }
      });
      input.addEventListener('input', function () {
        var match = Array.prototype.some.call(sel.options, function (o) { return o.value === input.value.trim(); });
        sel.value = match ? input.value.trim() : '__custom';
      });
    });
    // Mapping visuel : exemple de valeur du champ choisi
    function showSample(sel) {
      var opt = sel.options[sel.selectedIndex];
      var out = sel.parentNode.querySelector('[data-sample-out]');
      if (out) {
        var txt = opt && opt.getAttribute('data-sample') ? 'ex. ' + opt.getAttribute('data-sample') : '';
        var interp = opt && opt.getAttribute('data-interpret');
        out.textContent = txt + (interp ? '  →  ' + interp : '');
      }
      var row = sel.closest('tr');
      if (row && row.classList.contains('map-missing') && sel.value) { row.classList.remove('map-missing'); }
    }
    document.querySelectorAll('select[data-sample-select]').forEach(function (sel) {
      showSample(sel);
      sel.addEventListener('change', function () {
        showSample(sel);
        var badge = sel.parentNode.querySelector('.badge-auto');
        if (badge) { badge.remove(); }
      });
    });
    document.querySelectorAll('code.select').forEach(function (el) {
      el.addEventListener('click', function () {
        var r = document.createRange(); r.selectNodeContents(el);
        var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
      });
    });
  });
})();
