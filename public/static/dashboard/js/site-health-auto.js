(function () {
  'use strict';

  var config = window.jyavaniSiteHealthAuto;
  if (!config || config.stale !== true || !config.csrf) return;

  var throttleKey = 'jyavani-site-health-auto-scan';
  var lastAttempt = 0;
  try { lastAttempt = parseInt(sessionStorage.getItem(throttleKey) || '0', 10); } catch (_) {}
  var throttled = lastAttempt && Date.now() - lastAttempt < 300000;
  if (throttled && config.reloadOnSuccess !== true) return;
  if (!throttled) {
    try { sessionStorage.setItem(throttleKey, String(Date.now())); } catch (_) {}
  }

  var body = new URLSearchParams();
  body.set('csrf_token', config.csrf);
  var observing = Boolean(throttled && config.reloadOnSuccess === true);
  var attempt = 0;
  var requestScan = function () {
    attempt++;
    if (observing) body.set('observe_only', '1');
    else body.delete('observe_only');
    fetch((window.ADMIN_PATH || '') + '/admin/site_health_scan_ajax.php', {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
      body: body.toString()
    }).then(function (response) {
      return response.json().catch(function () { return null; }).then(function (data) {
        return {response: response, data: data};
      });
    }).then(function (result) {
      if (result.response.ok && result.data && result.data.ok === true) {
        if (!observing && config.reloadOnSuccess === true) window.location.reload();
        else if (observing && result.data.stale === false) window.location.reload();
        else if (observing && attempt < 8) window.setTimeout(requestScan, 5000);
        return;
      }
      if (result.response.status === 409 && result.data && result.data.retryable === true
          && config.reloadOnSuccess === true) {
        observing = true;
        attempt = 0;
        window.setTimeout(requestScan, 5000);
      }
    }).catch(function () {});
  };
  requestScan();
})();
