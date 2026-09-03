'use strict';

var config = window.CMS_UPDATE_CONFIG || {};

(function(){
    var chk = document.getElementById('chkHard');
    var hint = document.getElementById('hintHard');
    if (chk && hint) {
        chk.addEventListener('change', function(){
            hint.style.display = this.checked ? 'block' : 'none';
        });
    }
})();

function confirmReinstall(e){
    e.preventDefault();
    var hard = document.getElementById('chkHard');
    if (hard && hard.checked) {
        showResetModal();
    } else {
        showReinstallModal();
    }
    return false;
}

function showReinstallModal(){
    var modal = document.getElementById('reinstallModal');
    if (modal) modal.style.display = 'flex';
}

function closeReinstallModal(){
    var modal = document.getElementById('reinstallModal');
    if (modal) modal.style.display = 'none';
}

function showResetModal(){
    var modal = document.getElementById('resetModal');
    var btn = document.getElementById('resetApplyBtn');
    if (!modal || !btn) return;
    var boxes = document.querySelectorAll('.reset-cbox');
    for (var i = 0; i < boxes.length; i++) boxes[i].checked = false;
    btn.disabled = true;
    modal.style.display = 'flex';
}

function closeResetModal(){
    var modal = document.getElementById('resetModal');
    if (modal) modal.style.display = 'none';
}

(function(){
    var btn = document.getElementById('resetApplyBtn');
    var boxes = document.querySelectorAll('.reset-cbox');
    if (btn && boxes.length) {
        function checkAll(){
            for (var i = 0; i < boxes.length; i++) {
                if (!boxes[i].checked) { btn.disabled = true; return; }
            }
            btn.disabled = false;
        }
        for (var i = 0; i < boxes.length; i++) {
            boxes[i].addEventListener('change', checkAll);
        }
        btn.addEventListener('click', function(){
            var form = document.querySelector('.up-card[style*="border-color:var(--adam-danger)"] form');
            if (form) form.submit();
        });
    }
})();

(function(){
    var btn = document.getElementById('reinstallApplyBtn');
    if (btn) {
        btn.addEventListener('click', function(){
            var form = document.querySelector('.up-card[style*="border-color:var(--adam-danger)"] form');
            if (form) form.submit();
        });
    }
})();

(function(){
    var fileInput = document.getElementById('upFile');
    var upBtn = document.getElementById('upBtn');
    if (fileInput && upBtn) {
        fileInput.addEventListener('change', function(){
            upBtn.disabled = !this.files || !this.files[0];
        });
        upBtn.disabled = true;
    }
})();

// Progress overlay helpers
var _cmsPollTimer = null;
var _cmsWatchdogTimer = null;
var _cmsToken = '';
var _cmsUpdateFinished = false;
var _cmsDisplayedProgress = 0;

function closeCmsUpdateModal(){
    var modal = document.getElementById('cmsUpdateConfirmModal');
    if (modal) modal.style.display = 'none';
}

function cmsUpdateProgressBar(pct, status) {
    pct = Math.max(_cmsDisplayedProgress, Math.min(Number(pct) || 0, 100));
    _cmsDisplayedProgress = pct;
    var bar = document.getElementById('cmsProgressBar');
    var pctEl = document.getElementById('cmsProgressPct');
    var detailEl = document.getElementById('cmsProgressDetail');
    var statusEl = document.getElementById('cmsProgressStatus');
    var spinner = document.getElementById('cmsProgressSpinner');
    if (bar) bar.style.width = Math.min(pct, 100) + '%';
    if (pctEl) pctEl.textContent = pct + '%';
    if (detailEl) detailEl.textContent = status || '';
    if (pct >= 100 && statusEl) {
        statusEl.textContent = config.done;
        if (spinner) spinner.style.display = 'none';
    }
}

function cmsShowProgress() {
    var overlay = document.getElementById('cmsUpdateProgress');
    if (!overlay) return;
    _cmsDisplayedProgress = 0;
    cmsUpdateProgressBar(0, config.starting);
    overlay.style.display = 'flex';
}

function cmsHideProgress() {
    var overlay = document.getElementById('cmsUpdateProgress');
    if (overlay) overlay.style.display = 'none';
}

function cmsStopUpdatePolling() {
    if (_cmsPollTimer) clearInterval(_cmsPollTimer);
    if (_cmsWatchdogTimer) clearTimeout(_cmsWatchdogTimer);
    _cmsPollTimer = null;
    _cmsWatchdogTimer = null;
}

function cmsShowUpdateFailure(message) {
    if (_cmsUpdateFinished) return;
    _cmsUpdateFinished = true;
    cmsStopUpdatePolling();
    var statusEl = document.getElementById('cmsProgressStatus');
    var detailEl = document.getElementById('cmsProgressDetail');
    var spinner = document.getElementById('cmsProgressSpinner');
    var bar = document.getElementById('cmsProgressBar');
    var closeBtn = document.getElementById('cmsProgressClose');
    if (statusEl) statusEl.textContent = config.updateFailed;
    if (detailEl) detailEl.textContent = message || config.updateFailed;
    if (spinner) spinner.style.display = 'none';
    if (bar) bar.style.background = 'var(--adam-danger)';
    if (closeBtn) closeBtn.style.display = 'inline-flex';
}

function cmsMakeToken() {
    var hex = '0123456789abcdef';
    var token = '';
    for (var i = 0; i < 32; i++) token += hex[Math.floor(Math.random() * 16)];
    return token;
}

function cmsStartUpdate() {
    closeCmsUpdateModal();
    var token = cmsMakeToken();
    _cmsToken = token;
    _cmsUpdateFinished = false;
    cmsShowProgress();
    cmsUpdateProgressBar(1, config.preparing);

    var progressUrl = config.progressUrl + token;
    var applyUrl = config.applyUrl;
    var closeBtn = document.getElementById('cmsProgressClose');
    var spinner = document.getElementById('cmsProgressSpinner');
    var bar = document.getElementById('cmsProgressBar');
    if (closeBtn) closeBtn.style.display = 'none';
    if (spinner) spinner.style.display = 'block';
    if (bar) bar.style.background = 'var(--adam-success)';

    _cmsWatchdogTimer = setTimeout(function() {
        cmsShowUpdateFailure(config.timeout);
    }, 10 * 60 * 1000);

    _cmsPollTimer = setInterval(function() {
        fetch(progressUrl, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (_cmsUpdateFinished) return;
            cmsUpdateProgressBar(data.percentage || 0, data.status || '');
            if (data.done || data.error) {
                cmsStopUpdatePolling();
                if (data.error) {
                    cmsShowUpdateFailure(data.error);
                } else {
                    _cmsUpdateFinished = true;
                    setTimeout(function() {
                        window.location.href = config.successUrl;
                    }, 1500);
                }
            }
        })
        .catch(function() {});
    }, 1500);

    var formData = new FormData();
    formData.append('csrf_token', config.csrfToken);
    formData.append('action', 'apply_update');
    formData.append('token', token);

    fetch(applyUrl, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        },
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.ok) cmsShowUpdateFailure(data.error || config.updateFailed);
    })
    .catch(function(err) {
        cmsShowUpdateFailure(err.message);
    });
}

(function(){
    var btn = document.getElementById('cmsProgressClose');
    if (btn) btn.addEventListener('click', cmsHideProgress);
})();

// Wire up the Apply Update button
(function(){
    var btn = document.getElementById('cmsApplyUpdateBtn');
    if (btn) {
        btn.addEventListener('click', function(e) {
            var modal = document.getElementById('cmsUpdateConfirmModal');
            if (modal) modal.style.display = 'flex';
        });
    }
})();

// Wire up the confirm button
(function(){
    var btn = document.getElementById('cmsUpdateApplyConfirmBtn');
    if (btn) {
        btn.addEventListener('click', function() {
            cmsStartUpdate();
        });
    }
})();

// Show overlay on check_remote / upload_update POST submit
(function(){
    var overlay = document.getElementById('cmsUpdateProgress');
    if (!overlay) return;
    var actions = ['check_remote', 'upload_update'];
    document.querySelectorAll('form').forEach(function(f){
        f.addEventListener('submit', function(){
            var inp = this.querySelector('input[name="action"]');
            if (!inp) return;
            if (actions.indexOf(inp.value) === -1) return;
            overlay.style.display = 'flex';
        });
    });
})();

// Flash success on ?cms_update_ok=1
document.addEventListener('DOMContentLoaded', function() {
    var urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('cms_update_ok') === '1' && window.NewNotifToast) {
        NewNotifToast.success(config.updateSuccess);
        window.history.replaceState({}, '', config.selfUrl);
    }
});
