(function(){
'use strict';

var bell    = document.getElementById('adam-update-bell');
var badge   = document.getElementById('adam-bell-badge');
var dd      = document.getElementById('adam-update-dropdown');
var ddBody  = document.getElementById('adam-update-dd-body');
var refresh = document.getElementById('adam-update-refresh');
var basePath = window.ADMIN_PATH || '';

var checkUrl   = basePath + '/admin/check_updates_ajax.php';
var csrfToken  = window.jyavaniUpdateCsrf || '';

var shown = false;
var _lastResult = null;

function showCriticalAdvisory(advisory) {
  if (!advisory || !advisory.id) return;
  var storageKey = 'jyavani-critical-advisory:' + advisory.id;
  try { if (localStorage.getItem(storageKey)) return; } catch (_) {}

  var modal = document.getElementById('critical-update-modal');
  var title = document.getElementById('critical-update-title');
  var message = document.getElementById('critical-update-message');
  var version = document.getElementById('critical-update-version');
  var details = document.getElementById('critical-update-details');
  var dismiss = document.getElementById('critical-update-dismiss');
  if (!modal || !title || !message || !version || !dismiss) return;

  title.textContent = advisory.title || 'Critical security update';
  message.textContent = advisory.message || '';
  version.textContent = 'v' + (advisory.fixed_version || '');
  if (details) {
    if (typeof advisory.url === 'string' && /^https:\/\//i.test(advisory.url)) {
      details.href = advisory.url;
      details.style.display = '';
    } else {
      details.style.display = 'none';
    }
  }
  dismiss.onclick = function() {
    try { localStorage.setItem(storageKey, '1'); } catch (_) {}
    modal.style.display = 'none';
  };
  modal.style.display = 'flex';
}

function escapeHtml(s) {
  if (typeof s !== 'string') return '';
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function fetchUpdates(refreshMode, done) {
  var xhr = new XMLHttpRequest();
  xhr.open(refreshMode ? 'POST' : 'GET', checkUrl, true);
  xhr.setRequestHeader('Accept', 'application/json');
  xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
  if (refreshMode) xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');

  xhr.onload = function(){
    if (xhr.status !== 200) {
      if (done) done(null, 'HTTP ' + xhr.status);
      return;
    }
    try {
      var data = JSON.parse(xhr.responseText);
      if (data && data.ok) {
        if (done) done(data);
      } else {
        if (done) done(null, data && data.error ? data.error : 'Response not ok');
      }
    } catch(_){
      if (done) done(null, 'Invalid JSON: ' + xhr.responseText.substring(0,200));
    }
  };

  xhr.onerror = function(){
    if (done) done(null, 'Network error');
  };

  xhr.send(refreshMode ? 'csrf_token=' + encodeURIComponent(csrfToken) : null);
}

function updateBadge(total) {
  if (total > 0) {
    badge.textContent = total > 99 ? '99+' : total;
    badge.style.display = '';
  } else {
    badge.style.display = 'none';
  }
}

function loadUpdates(refreshMode, silent) {
  var tr = window.i18n_upd || {};
  if (refreshMode && !silent) {
    ddBody.innerHTML = '<div class="adam-update-dd-empty">' + (tr.checking || 'Checking...') + '</div>';
  }
  fetchUpdates(refreshMode, function(data, err){
    if (data) {
      _lastResult = data;
      updateBadge(data.total || 0);
      showCriticalAdvisory(data.critical_advisory);
      updateDashboardWidget(data);
      window.jyavaniUpdateStatus = data;
      window.dispatchEvent(new CustomEvent('jyavani:update-status', {detail: data}));
      if (shown) renderDropdown(data);
      if (refreshMode && !silent && document.querySelector('[data-update-status-page]')) {
        window.setTimeout(function(){ window.location.reload(); }, 500);
      }
    } else {
      if (shown) renderDropdown(null, err);
    }
    if (refreshMode && !silent) {
      if (data && (data.state === 'partial' || data.state === 'error')) {
        window.NewNotifToast && window.NewNotifToast.warning(
          (tr.partial_result || 'Some update sources could not be reached. Showing the last known results.'),
          (tr.notification || 'Update Notification')
        );
      } else if (data && data.total > 0) {
        window.NewNotifToast && window.NewNotifToast.info(
          (tr.updates_available || 'Updates available:') + ' ' + (data.total || 0) + ' ' + (tr.item || 'item'),
          (tr.notification || 'Update Notification')
        );
      } else if (data) {
        window.NewNotifToast && window.NewNotifToast.success(
          (tr.all_up_to_date || 'All up to date.'),
          (tr.notification || 'Update Notification')
        );
      }
    }
  });
}

window.adamRefreshUpdateStatus = function() {
  loadUpdates(false);
};

function toggleDropdown() {
  shown = !shown;
  dd.style.display = shown ? '' : 'none';
  if (shown) {
    if (_lastResult) {
      renderDropdown(_lastResult);
    } else {
      var tr = window.i18n_upd || {};
      ddBody.innerHTML = '<div class="adam-update-dd-empty">' + (tr.checking || 'Checking...') + '</div>';
    }
    if (!_lastResult) loadUpdates(false);
  }
}

function renderDropdown(data, error) {
  var tr = window.i18n_upd || {};
  if (error || !data) {
    ddBody.innerHTML = '<div class="adam-update-dd-empty">' + (tr.failed_to_check || 'Failed to check updates.') + (error ? ': ' + error : '') + '</div>';
    return;
  }

  var items = [];

  if (data.state === 'unknown') {
    ddBody.innerHTML = '<div class="adam-update-dd-empty">' + (tr.never_checked || 'Updates have not been checked yet.') + '</div>';
    return;
  }

  if (data.state === 'partial' || data.state === 'error') {
    items.push('<div class="adam-update-dd-empty">' + (tr.partial_result || 'Some update sources could not be reached. Showing the last known results.') + '</div>');
  } else if (data.stale) {
    items.push('<div class="adam-update-dd-empty">' + (tr.stale_result || 'Update information is out of date. Checking again…') + '</div>');
  }

  if (data.cms && data.cms.has_update) {
    items.push(
      '<div class="adam-update-dd-item">',
        '<span class="adam-update-dd-item-cat">CMS</span>',
        '<span class="adam-update-dd-item-name">Jyavani CMS</span>',
        '<span class="adam-update-dd-item-versions">v' + escapeHtml(data.cms.current) + ' → v' + escapeHtml(data.cms.latest) + '</span>',
      '</div>'
    );
  }

  if (data.plugins && data.plugins.length) {
    data.plugins.forEach(function(p){
      items.push(
        '<div class="adam-update-dd-item">',
          '<span class="adam-update-dd-item-cat">' + (tr.plugin || 'Plugin') + '</span>',
          '<span class="adam-update-dd-item-name">' + escapeHtml(p.name) + '</span>',
          '<span class="adam-update-dd-item-versions">v' + escapeHtml(p.current) + ' → v' + escapeHtml(p.latest) + '</span>',
        '</div>'
      );
    });
  }

  if (data.themes && data.themes.length) {
    data.themes.forEach(function(th){
      items.push(
        '<div class="adam-update-dd-item">',
          '<span class="adam-update-dd-item-cat">' + (tr.theme || 'Theme') + '</span>',
          '<span class="adam-update-dd-item-name">' + escapeHtml(th.name) + '</span>',
          '<span class="adam-update-dd-item-versions">v' + escapeHtml(th.current) + ' → v' + escapeHtml(th.latest) + '</span>',
        '</div>'
      );
    });
  }

  if (items.length === 0) {
    ddBody.innerHTML = '<div class="adam-update-dd-empty">' + (tr.all_up_to_date || 'All up to date.') + '</div>';
  } else {
    var checked = data.checked_at ? '<div class="adam-update-dd-empty">' + (tr.last_checked || 'Last checked:') + ' ' + new Date(data.checked_at * 1000).toLocaleString() + '</div>' : '';
    ddBody.innerHTML = items.join('') + checked;
  }
}

function updateDashboardWidget(data) {
  var widget = document.querySelector('[data-update-widget]');
  var latestBadges = document.querySelectorAll('[data-cms-latest]');
  var tr = window.i18n_upd || {};
  if (data.cms) {
    latestBadges.forEach(function(latest) {
      latest.textContent = '';
      if (data.cms.state !== 'ok' || data.cms.has_update) return;
      var badge = document.createElement('span');
      badge.className = latest.getAttribute('data-latest-class') || 'dw-latest';
      badge.textContent = tr.latest || 'Latest';
      latest.appendChild(badge);
    });
  }
  if (!widget) return;
  var body = widget.querySelector('[data-update-items]');
  var total = widget.querySelector('[data-update-total]');
  var meta = widget.querySelector('[data-update-meta]');
  var rows = [];
  function row(label, name, current, next, url) {
    rows.push('<tr><td>' + escapeHtml(label) + ' ' + escapeHtml(name) + '</td><td class="dw-up">v' + escapeHtml(current) + ' → v' + escapeHtml(next) + '</td><td><a class="dw-link" href="' + escapeHtml(url) + '">' + (tr.update || 'Update') + '</a></td></tr>');
  }
  if (data.cms && data.cms.has_update) row('CMS', 'Jyavani CMS', data.cms.current, data.cms.latest, basePath + '/?page=admin/update/index');
  (data.plugins || []).forEach(function(item){ row(tr.plugin || 'Plugin', item.name, item.current, item.latest, basePath + '/?page=admin/plugins/index'); });
  (data.themes || []).forEach(function(item){ row(tr.theme || 'Theme', item.name, item.current, item.latest, basePath + '/?page=admin/themes/assign'); });
  if (!rows.length) {
    var empty = data.state === 'unknown'
      ? (tr.never_checked || 'Updates have not been checked yet.')
      : ((data.state === 'partial' || data.state === 'error')
        ? (tr.partial_result || 'Some update sources could not be reached. Showing the last known results.')
        : (tr.all_up_to_date || 'All up to date.'));
    rows.push('<tr><td colspan="3" class="dw-na">' + empty + '</td></tr>');
  }
  if (body) body.innerHTML = rows.join('');
  if (total) {
    total.textContent = data.total || 0;
    total.style.display = data.total > 0 ? '' : 'none';
  }
  if (meta) {
    meta.textContent = (data.state === 'partial' || data.state === 'error')
      ? (tr.partial_result || 'Some update sources could not be reached. Showing the last known results.')
      : (data.checked_at ? (tr.last_checked || 'Last checked:') + ' ' + new Date(data.checked_at * 1000).toLocaleString() : (tr.never_checked || 'Updates have not been checked yet.'));
  }
}

function closeDropdown(e) {
  if (!shown) return;
  if (dd.contains(e.target) || bell.contains(e.target)) return;
  shown = false;
  dd.style.display = 'none';
}

if (bell) {
  var initial = window.jyavaniUpdateStatus;
  if (initial && initial.ok) {
    _lastResult = initial;
    updateBadge(initial.total || 0);
    showCriticalAdvisory(initial.critical_advisory);
    updateDashboardWidget(initial);
    if (initial.stale) {
      var autoKey = 'jyavani-update-auto-check';
      var lastAuto = 0;
      try { lastAuto = parseInt(sessionStorage.getItem(autoKey) || '0', 10); } catch (_) {}
      if (!lastAuto || Date.now() - lastAuto > 300000) {
        try { sessionStorage.setItem(autoKey, String(Date.now())); } catch (_) {}
        loadUpdates(true, true);
      }
    }
  } else {
    loadUpdates(false, true);
  }

  bell.addEventListener('click', function(e){
    e.stopPropagation();
    toggleDropdown();
  });

  refresh && refresh.addEventListener('click', function(){
    var tr = window.i18n_upd || {};
    ddBody.innerHTML = '<div class="adam-update-dd-empty">' + (tr.checking || 'Checking...') + '</div>';
    loadUpdates(true);
  });

  document.addEventListener('click', closeDropdown);
  document.addEventListener('focusin', closeDropdown);
}

})();
