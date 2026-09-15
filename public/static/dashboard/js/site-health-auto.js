(function () {
  'use strict';

  var config = window.jyavaniSiteHealthAuto;
  if (!config || config.stale !== true || !config.csrf) return;

  var throttleKey = 'jyavani-site-health-auto-scan';
  var lastAttempt = 0;
  try { lastAttempt = parseInt(sessionStorage.getItem(throttleKey) || '0', 10); } catch (_) {}
  if (lastAttempt && Date.now() - lastAttempt < 300000) return;
  try { sessionStorage.setItem(throttleKey, String(Date.now())); } catch (_) {}

  var body = new URLSearchParams();
  body.set('csrf_token', config.csrf);
  fetch((window.ADMIN_PATH || '') + '/admin/site_health_scan_ajax.php', {
    method: 'POST',
    credentials: 'same-origin',
    keepalive: true,
    headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
    body: body.toString()
  }).catch(function () {});
})();
