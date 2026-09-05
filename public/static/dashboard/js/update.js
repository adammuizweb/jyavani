'use strict';

var config = window.CMS_UPDATE_CONFIG || {};

window.createUpdateProcessUI = function(options) {
    var overlay = document.getElementById(options.overlayId);
    if (!overlay) return null;
    var panel = overlay.querySelector('[data-update-process-panel]');
    var titleEl = overlay.querySelector('[data-update-process-title]');
    var spinner = overlay.querySelector('[data-update-process-spinner]');
    var stageEl = overlay.querySelector('[data-update-process-stage]');
    var statusEl = overlay.querySelector('[data-update-process-status]');
    var warningEl = overlay.querySelector('.update-process-warning');
    var bar = overlay.querySelector('[data-update-process-bar]');
    var pctEl = overlay.querySelector('[data-update-process-pct]');
    var actions = overlay.querySelector('[data-update-process-actions]');
    var cancelButton = overlay.querySelector('[data-update-process-cancel]');
    var token = '';
    var context = '';
    var displayedProgress = 0;
    var active = false;
    var terminal = false;
    var pollTimer = null;
    var watchdogTimer = null;
    var pollController = null;
    var generation = 0;
    var dispatchError = '';
    var missingAfterDispatch = 0;
    var inertElements = [];
    var previousOverflow = '';

    function text(key, fallback) {
        return options.labels && options.labels[key] ? options.labels[key] : fallback;
    }
    function contextual(label) { return context ? label + ': ' + context : label; }
    function setBackgroundInert(inert) {
        if (inert) {
            previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            inertElements = [];
            var branch = overlay;
            while (branch && branch !== document.body) {
                Array.prototype.forEach.call(branch.parentElement ? branch.parentElement.children : [], function(element) {
                    if (element === branch || element.tagName === 'SCRIPT') return;
                    inertElements.push({element: element, inert: element.inert});
                    element.inert = true;
                });
                branch = branch.parentElement;
            }
            return;
        }
        inertElements.forEach(function(item) { item.element.inert = item.inert; });
        inertElements = [];
        document.body.style.overflow = previousOverflow;
    }
    function beforeUnload(event) {
        if (!active) return;
        event.preventDefault();
        event.returnValue = '';
    }
    function keepFocusInside(event) {
        if (active && !overlay.contains(event.target) && panel) panel.focus();
    }
    function stopPolling() {
        if (pollTimer) window.clearTimeout(pollTimer);
        if (watchdogTimer) window.clearTimeout(watchdogTimer);
        if (pollController) pollController.abort();
        pollTimer = null;
        watchdogTimer = null;
        pollController = null;
    }
    function setProgress(value) {
        var numeric = Math.max(0, Math.min(Number(value) || 0, 100));
        displayedProgress = Math.max(displayedProgress, numeric);
        if (bar) bar.style.width = displayedProgress + '%';
        if (pctEl) pctEl.textContent = Math.round(displayedProgress) + '%';
    }
    function renderCancel(state) {
        if (!cancelButton || terminal) return;
        cancelButton.style.display = 'inline-flex';
        if (state.cancel_requested || state.stage === 'cancelling') {
            cancelButton.disabled = true;
            cancelButton.textContent = text('cancelling', 'Cancelling...');
        } else if (state.cancel_allowed === true) {
            cancelButton.disabled = false;
            cancelButton.textContent = text('cancel', 'Cancel update');
        } else if (state.stage && state.stage !== 'starting') {
            cancelButton.disabled = true;
            cancelButton.textContent = text('finishing', 'Finishing process...');
        } else {
            cancelButton.disabled = true;
            cancelButton.textContent = text('cancel', 'Cancel update');
        }
    }
    function finish(outcome, message) {
        if (terminal) return;
        terminal = true;
        active = false;
        generation++;
        stopPolling();
        window.removeEventListener('beforeunload', beforeUnload);
        document.removeEventListener('focusin', keepFocusInside, true);
        overlay.classList.remove('is-running');
        overlay.classList.add('is-terminal', 'is-' + outcome);
        if (spinner) spinner.style.display = 'none';
        if (warningEl) warningEl.style.display = 'none';
        if (stageEl) stageEl.style.display = 'none';
        if (statusEl) statusEl.textContent = message || text(outcome, outcome);
        if (titleEl) {
            var key = outcome === 'completed' ? 'completeTitle' : (outcome === 'cancelled' ? 'cancelledTitle' : 'failedTitle');
            titleEl.textContent = contextual(text(key, outcome));
        }
        if (outcome === 'completed') setProgress(100);
        if (actions) actions.replaceChildren();
        var done = document.createElement('button');
        done.type = 'button';
        done.className = 'btn btn-primary update-process-done';
        done.textContent = text('done', 'Done');
        done.addEventListener('click', function() {
            setBackgroundInert(false);
            if (typeof options.onDone === 'function') options.onDone(outcome);
        });
        if (actions) actions.appendChild(done);
        done.focus();
    }
    function applyState(state) {
        if (terminal || !state || typeof state !== 'object') return;
        if (state.found === false && dispatchError) {
            missingAfterDispatch++;
            if (missingAfterDispatch >= 3) {
                finish('failed', dispatchError);
                return;
            }
        } else if (state.found !== false) {
            missingAfterDispatch = 0;
        }
        setProgress(state.percentage);
        if (stageEl) stageEl.textContent = text('stage', 'Stage:') + ' ' + (state.stage || 'starting');
        if (statusEl) statusEl.textContent = state.error || state.status || text('starting', 'Starting...');
        if (state.done || ['completed', 'failed', 'cancelled'].indexOf(state.outcome) !== -1) {
            finish(state.outcome === 'completed' ? 'completed' : (state.outcome === 'cancelled' ? 'cancelled' : 'failed'), state.error || state.status);
            return;
        }
        renderCancel(state);
    }
    function readJson(response) {
        return response.json().catch(function() {
            throw new Error(text('invalidResponse', 'The update server returned an invalid response.'));
        }).then(function(data) {
            if (!response.ok && !(response.status === 409 && data && data.outcome === 'running')) {
                throw new Error((data && data.error) || text('requestFailed', 'The update request failed.'));
            }
            return data;
        });
    }
    function schedulePoll(currentGeneration) {
        if (terminal || currentGeneration !== generation) return;
        pollTimer = window.setTimeout(function() { poll(currentGeneration); }, options.pollInterval || 1500);
    }
    function poll(currentGeneration) {
        if (terminal || currentGeneration !== generation) return;
        pollController = new AbortController();
        fetch(options.processUrl + encodeURIComponent(token), {
            method: 'GET', credentials: 'same-origin', cache: 'no-store', signal: pollController.signal
        }).then(readJson).then(function(state) {
            if (terminal || currentGeneration !== generation) return;
            applyState(state);
            schedulePoll(currentGeneration);
        }).catch(function(error) {
            if (error.name === 'AbortError' || terminal || currentGeneration !== generation) return;
            if (statusEl) statusEl.textContent = error.message || text('requestFailed', 'The update request failed.');
            schedulePoll(currentGeneration);
        });
    }
    function start(nextToken, nextContext) {
        stopPolling();
        generation++;
        token = nextToken;
        context = nextContext || '';
        displayedProgress = 0;
        dispatchError = '';
        missingAfterDispatch = 0;
        terminal = false;
        active = true;
        overlay.classList.remove('is-terminal', 'is-completed', 'is-failed', 'is-cancelled');
        overlay.classList.add('is-running');
        if (titleEl) titleEl.textContent = contextual(text('runningTitle', 'Update in progress'));
        if (spinner) spinner.style.display = 'block';
        if (stageEl) {
            stageEl.style.display = '';
            stageEl.textContent = text('stage', 'Stage:') + ' ' + text('starting', 'Starting...');
        }
        if (statusEl) statusEl.textContent = text('starting', 'Starting...');
        if (warningEl) warningEl.style.display = '';
        if (actions) {
            actions.style.display = 'flex';
            actions.replaceChildren(cancelButton);
        }
        if (cancelButton) {
            cancelButton.style.display = 'inline-flex';
            cancelButton.disabled = true;
            cancelButton.textContent = text('cancel', 'Cancel update');
        }
        setProgress(0);
        overlay.style.display = 'flex';
        setBackgroundInert(true);
        window.addEventListener('beforeunload', beforeUnload);
        document.addEventListener('focusin', keepFocusInside, true);
        if (panel) panel.focus();
        var currentGeneration = generation;
        watchdogTimer = window.setTimeout(function() {
            if (currentGeneration === generation && active && statusEl) {
                statusEl.textContent = text('timeout', 'The update is taking longer than expected. Waiting for a confirmed result.');
            }
        }, options.watchdog || 10 * 60 * 1000);
        poll(currentGeneration);
    }
    if (cancelButton) cancelButton.addEventListener('click', function() {
        if (!active || terminal || cancelButton.disabled) return;
        cancelButton.disabled = true;
        cancelButton.textContent = text('cancelling', 'Cancelling...');
        if (statusEl) statusEl.textContent = text('cancelling', 'Cancelling...');
        var body = new FormData();
        body.append('action', 'cancel');
        body.append('token', token);
        body.append('csrf_token', options.csrfToken);
        fetch(options.processUrl.replace(/\?token=$/, ''), {
            method: 'POST', credentials: 'same-origin', cache: 'no-store', body: body,
            headers: {'Accept': 'application/json'}
        }).then(readJson).then(applyState).catch(function() {
            if (!terminal && statusEl) statusEl.textContent = text('cancelFailed', 'Unable to request cancellation. The update is still running.');
        });
    });
    overlay.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            return;
        }
        if (event.key !== 'Tab') return;
        var focusable = overlay.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), [tabindex]:not([tabindex="-1"])');
        if (!focusable.length) { event.preventDefault(); if (panel) panel.focus(); return; }
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && (document.activeElement === first || document.activeElement === panel)) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    return {
        start: start,
        update: applyState,
        fail: function(message) { finish('failed', message); },
        notice: function(message) {
            if (!terminal && statusEl) statusEl.textContent = message || text('requestFailed', 'The update request failed.');
        },
        dispatchFailed: function(message) {
            if (terminal) return;
            dispatchError = message || text('requestFailed', 'The update request failed.');
            if (statusEl) statusEl.textContent = dispatchError;
        },
        dismissTerminal: function() {
            if (!terminal) return false;
            setBackgroundInert(false);
            overlay.style.display = 'none';
            return true;
        },
        isTerminal: function() { return terminal; }
    };
};

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

// Core update progress uses the shared blocking process modal.
var _cmsDisplayedProgress = 0;
var _cmsProcess = null;

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
    if (statusEl && status) statusEl.textContent = status;
    if (spinner && pct >= 100) spinner.style.display = 'none';
}

function cmsMakeToken() {
    var bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    return Array.prototype.map.call(bytes, function(value) { return value.toString(16).padStart(2, '0'); }).join('');
}

function cmsStartUpdate() {
    closeCmsUpdateModal();
    var token = cmsMakeToken();
    _cmsDisplayedProgress = 0;
    if (!_cmsProcess) return;
    _cmsProcess.start(token, config.context || 'Jyavani CMS');

    var formData = new FormData();
    formData.append('csrf_token', config.csrfToken);
    formData.append('action', 'apply_update');
    formData.append('token', token);

    fetch(config.applyUrl, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        },
    })
    .then(function(r) {
        return r.json().catch(function() { return {ok: false, error: config.invalidResponse}; });
    })
    .then(function(data) {
        if (!data.ok && !data.cancelled) _cmsProcess.dispatchFailed(data.error || config.updateFailed);
    })
    .catch(function(err) {
        _cmsProcess.dispatchFailed(err.message || (config.labels && config.labels.requestFailed) || config.updateFailed);
    });
}

(function(){
    _cmsProcess = window.createUpdateProcessUI({
        overlayId: 'cmsUpdateProgress',
        processUrl: config.progressUrl,
        csrfToken: config.csrfToken,
        labels: config.labels,
        watchdog: 10 * 60 * 1000,
        onDone: function(outcome) {
            window.location.assign(outcome === 'completed' ? config.successUrl : config.selfUrl);
        }
    });
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
            var processActions = overlay.querySelector('[data-update-process-actions]');
            var processWarning = overlay.querySelector('.update-process-warning');
            var processStage = overlay.querySelector('[data-update-process-stage]');
            if (processActions) processActions.style.display = 'none';
            if (processWarning) processWarning.style.display = 'none';
            if (processStage) processStage.style.display = 'none';
            overlay.style.display = 'flex';
        });
    });
})();
