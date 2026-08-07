(function(){
'use strict';

var bell    = document.getElementById('adam-update-bell');
var badge   = document.getElementById('adam-bell-badge');
var dd      = document.getElementById('adam-update-dropdown');
var ddBody  = document.getElementById('adam-update-dd-body');
var refresh = document.getElementById('adam-update-refresh');
var basePath = window.ADMIN_PATH || '';

var checkUrl   = basePath + '/admin/check_updates_ajax.php';
var checkUrlR  = checkUrl + '?refresh=1';

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
  var url = refreshMode ? checkUrlR : checkUrl;

  var xhr = new XMLHttpRequest();
  xhr.open('GET', url, true);
  xhr.setRequestHeader('Accept', 'application/json');
  xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

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

  xhr.send();
}

function updateBadge(total) {
  if (total > 0) {
    badge.textContent = total > 99 ? '99+' : total;
    badge.style.display = '';
  } else {
    badge.style.display = 'none';
  }
}

function loadUpdates(refreshMode) {
  var tr = window.i18n_upd || {};
  if (refreshMode) {
    ddBody.innerHTML = '<div class="adam-update-dd-empty">' + (tr.checking || 'Checking...') + '</div>';
  }
  fetchUpdates(refreshMode, function(data, err){
    if (data) {
      _lastResult = data;
      updateBadge(data.total || 0);
      showCriticalAdvisory(data.critical_advisory);
      if (shown) renderDropdown(data);
    } else {
      if (shown) renderDropdown(null, err);
    }
    if (refreshMode) {
      if (data && data.total > 0) {
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
    loadUpdates(false);
  }
}

function renderDropdown(data, error) {
  var tr = window.i18n_upd || {};
  if (error || !data) {
    ddBody.innerHTML = '<div class="adam-update-dd-empty">' + (tr.failed_to_check || 'Failed to check updates.') + (error ? ': ' + error : '') + '</div>';
    return;
  }

  var items = [];

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
    ddBody.innerHTML = items.join('');
  }
}

function closeDropdown(e) {
  if (!shown) return;
  if (dd.contains(e.target) || bell.contains(e.target)) return;
  shown = false;
  dd.style.display = 'none';
}

if (bell) {
  // initial load — fetch silently and show badge
  fetchUpdates(false, function(data){
    if (data) {
      _lastResult = data;
      updateBadge(data.total || 0);
      showCriticalAdvisory(data.critical_advisory);
    }
  });

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
