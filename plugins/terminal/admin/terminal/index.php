<?php
// Plugin: Terminal WebSocket Frontend
declare(strict_types=1);

require_once DASH_PATH . '/admin/_deny.php';
if (!defined('DASHBOARD_CONTEXT') && !defined('ADAM_THEME')) adiwira_admin_404();
require_once DASH_PATH . '/admin/_guard.php';

[$uid, $role] = adiwira_require_role($pdo, ['admin'], false);

$host = gethostname();
$whoami = 'adam';
$home = '/home/adam';
$ip = trim(shell_exec('hostname -I | awk \'{print $1}\'') ?: 'N/A');

$forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
$wsProtocol = ($forwardedProto === 'https' || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')) ? 'wss' : 'ws';
$wsPath = '/ws-terminal';
$adminBase = defined('ADMIN_BASE_PATH') ? ADMIN_BASE_PATH : rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$wsBaseUrl = $adminBase . '/admin/terminal/token.php';
?>
<link rel="stylesheet" href="/static/vendor/xterm/xterm.min.css" />
<script data-cfasync="false" src="/static/vendor/xterm/xterm.min.js"></script>
<script data-cfasync="false" src="/static/vendor/xterm/addon-fit.min.js"></script>
<script data-cfasync="false" src="/static/vendor/xterm/addon-web-links.min.js"></script>

<style>
  .terminal-wrap {
    background: #1e1e2e;
    border-radius: 8px;
    overflow: hidden;
    font-family: 'Cascadia Code', 'Fira Code', 'JetBrains Mono', monospace;
  }
  .terminal-top {
    background: #313244;
    padding: 8px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    font-size: 13px;
    color: #cdd6f4;
  }
  .terminal-top .info {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .tdot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }
  .tdot.r { background: #f38ba8; }
  .tdot.y { background: #f9e2af; }
  .tdot.g { background: #a6e3a1; }

  .terminal-tabbar {
    display: flex;
    align-items: center;
    background: #181825;
    border-bottom: 1px solid #313244;
    overflow-x: auto;
    scrollbar-width: thin;
    scrollbar-color: #45475a transparent;
  }
  .terminal-tabbar::-webkit-scrollbar { height: 4px; }
  .terminal-tabbar::-webkit-scrollbar-track { background: transparent; }
  .terminal-tabbar::-webkit-scrollbar-thumb { background: #45475a; border-radius: 2px; }
  .tab-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    font-size: 12px;
    color: #6c7086;
    cursor: pointer;
    border-right: 1px solid #313244;
    min-width: 100px;
    max-width: 180px;
    user-select: none;
    white-space: nowrap;
    transition: background 0.15s, color 0.15s;
  }
  .tab-item:hover { background: #313244; color: #cdd6f4; }
  .tab-item.active { background: #1e1e2e; color: #cdd6f4; }
  .tab-close {
    margin-left: auto;
    width: 16px;
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 3px;
    font-size: 12px;
    line-height: 1;
    color: #585b70;
    flex-shrink: 0;
    border: none;
    background: none;
    cursor: pointer;
    padding: 0;
  }
  .tab-close:hover { background: #f38ba8; color: #1e1e2e; }
  .tab-add {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: none;
    border: none;
    color: #6c7086;
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    margin-left: 4px;
    border-radius: 4px;
    transition: background 0.15s, color 0.15s;
  }
  .tab-add:hover { background: #313244; color: #a6e3a1; }

  #terminal-container-wrap {
    position: relative;
    min-height: 520px;
    max-height: 70vh;
  }
  .term-container {
    padding: 8px;
    min-height: 520px;
    max-height: 70vh;
    display: none;
  }
  .term-container.active { display: block; }

  .tfoot {
    padding: 6px 16px;
    background: #181825;
    color: #6c7086;
    font-size: 12px;
    border-top: 1px solid #313244;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .tfoot button {
    background: none;
    border: 1px solid #45475a;
    color: #a6adc8;
    padding: 3px 10px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 11px;
    font-family: inherit;
  }
  .tfoot button:hover { background: #313244; }
  .xterm { padding: 4px; }
  .xterm-viewport::-webkit-scrollbar { width: 8px; }
  .xterm-viewport::-webkit-scrollbar-track { background: #1e1e2e; }
  .xterm-viewport::-webkit-scrollbar-thumb { background: #45475a; border-radius: 4px; }
  .xterm-viewport::-webkit-scrollbar-thumb:hover { background: #585b70; }
  .status-info { display: flex; align-items: center; gap: 6px; }
  .status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
  }
  .status-dot.connected { background: #a6e3a1; }
  .status-dot.disconnected { background: #f38ba8; }
  .status-dot.connecting { background: #f9e2af; animation: pulse 1s infinite; }
  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
  }
</style>

<div class="terminal-wrap">
  <div class="terminal-top">
    <div class="info">
      <span class="tdot r"></span>
      <span class="tdot y"></span>
      <span class="tdot g"></span>
      <span><?= htmlspecialchars($whoami . '@' . $host . ':~', ENT_QUOTES) ?></span>
      <span style="font-size:0.75rem;opacity:0.6;margin-left:8px;"><?= htmlspecialchars($ip, ENT_QUOTES) ?></span>
    </div>
    <div class="status-info">
      <span id="status-dot" class="status-dot connecting"></span>
      <span id="status-text">Connecting...</span>
    </div>
  </div>

  <div class="terminal-tabbar" id="tab-bar">
    <div id="tab-list" style="display:flex;align-items:center;"></div>
    <button class="tab-add" id="tab-add-btn" title="New Terminal">+</button>
  </div>

  <div id="terminal-container-wrap"></div>

  <div class="tfoot">
    <span>Perintah berjalan sebagai user <code>adam</code> di <code>/home/adam</code>. PTY real-time mode.</span>
    <button id="reconnect-btn">Reconnect</button>
  </div>
</div>

<script>
(function(){
  var MAX_TABS = 10;
  var tabs = {};
  var activeTabId = null;
  var tabCounter = 0;

  var tabList = document.getElementById('tab-list');
  var wrap = document.getElementById('terminal-container-wrap');
  var statusDot = document.getElementById('status-dot');
  var statusText = document.getElementById('status-text');

  function setStatus(state, text) {
    statusDot.className = 'status-dot ' + state;
    statusText.textContent = text;
  }

  function getToken(callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?= $wsBaseUrl ?>', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
      if (xhr.readyState === 4) {
        if (xhr.status === 200) {
          try {
            var r = JSON.parse(xhr.responseText);
            callback(r.token);
          } catch(e) {
            callback(null);
          }
        } else {
          callback(null);
        }
      }
    };
    xhr.send();
  }

  function createTerm(opts) {
    var term = new Terminal({
      cursorBlink: true,
      cursorStyle: 'block',
      fontSize: 13,
      fontFamily: "'Cascadia Code', 'Fira Code', 'JetBrains Mono', monospace",
      theme: {
        background: '#1e1e2e',
        foreground: '#cdd6f4',
        cursor: '#f5e0dc',
        cursorAccent: '#1e1e2e',
        selection: 'rgba(137, 180, 250, 0.3)',
        black: '#45475a',
        red: '#f38ba8',
        green: '#a6e3a1',
        yellow: '#f9e2af',
        blue: '#89b4fa',
        magenta: '#f5c2e7',
        cyan: '#94e2d5',
        white: '#bac2de',
        brightBlack: '#585b70',
        brightRed: '#f38ba8',
        brightGreen: '#a6e3a1',
        brightYellow: '#f9e2af',
        brightBlue: '#89b4fa',
        brightMagenta: '#f5c2e7',
        brightCyan: '#94e2d5',
        brightWhite: '#a6adc8',
      },
      allowProposedApi: true,
    });
    var fitAddon = new FitAddon.FitAddon();
    var webLinksAddon = new WebLinksAddon.WebLinksAddon();
    term.loadAddon(fitAddon);
    term.loadAddon(webLinksAddon);
    term.open(opts.container);
    return { term: term, fitAddon: fitAddon };
  }

  function switchTab(id) {
    if (id === activeTabId) return;
    if (activeTabId !== null) {
      var old = tabs[activeTabId];
      if (old) {
        old.tabEl.classList.remove('active');
        old.container.classList.remove('active');
      }
    }
    activeTabId = id;
    var tab = tabs[id];
    if (tab) {
      tab.tabEl.classList.add('active');
      tab.container.classList.add('active');
      tab.fitAddon.fit();
      tab.term.focus();
      setStatus(tab.state, tab.stateText);
    }
  }

  function closeTab(id) {
    var tab = tabs[id];
    if (!tab) return;
    var wasActive = (id === activeTabId);

    if (tab.ws) {
      tab.ws.onclose = null;
      tab.ws.close();
    }
    tab.term.dispose();
    tab.tabEl.remove();
    tab.container.remove();
    delete tabs[id];

    var remaining = Object.keys(tabs).length;
    if (remaining === 0) {
      activeTabId = null;
      setStatus('disconnected', 'No terminals');
      addTab();
      return;
    }

    if (wasActive) {
      var keys = Object.keys(tabs);
      switchTab(keys[0]);
    }
  }

  function connectTab(tab) {
    getToken(function(token) {
      if (!token) {
        tab.state = 'disconnected';
        tab.stateText = 'Failed to get token';
        if (tab.id === activeTabId) setStatus(tab.state, tab.stateText);
        return;
      }

      var cols = tab.term.cols;
      var rows = tab.term.rows;
      var wsUrl = '<?php echo $wsProtocol; ?>://' + window.location.host + '<?php echo $wsPath; ?>?token=' + token + '&cols=' + cols + '&rows=' + rows;

      var ws = new WebSocket(wsUrl);
      tab.ws = ws;

      ws.onopen = function() {
        tab.state = 'connected';
        tab.stateText = 'Connected';
        if (tab.id === activeTabId) setStatus(tab.state, tab.stateText);
        tab.term.focus();
      };

      ws.onmessage = function(e) {
        try {
          var msg = JSON.parse(e.data);
          if (msg.type === 'data') {
            tab.term.write(msg.data);
          } else if (msg.type === 'pong') {
          }
        } catch(err) {
          tab.term.write(e.data);
        }
      };

      ws.onclose = function() {
        tab.ws = null;
        tab.state = 'disconnected';
        tab.stateText = 'Disconnected';
        if (tab.id === activeTabId) setStatus(tab.state, tab.stateText);
      };

      ws.onerror = function() {
        tab.state = 'disconnected';
        tab.stateText = 'Error';
        if (tab.id === activeTabId) setStatus(tab.state, tab.stateText);
      };
    });
  }

  function addTab() {
    var remaining = Object.keys(tabs).length;
    if (remaining >= MAX_TABS) return;

    tabCounter++;
    var id = String(Date.now());
    var name = 'Terminal ' + tabCounter;

    var tabEl = document.createElement('div');
    tabEl.className = 'tab-item';
    tabEl.innerHTML = '<span>' + name + '</span><button class="tab-close">&times;</button>';
    tabList.appendChild(tabEl);

    var container = document.createElement('div');
    container.className = 'term-container';
    wrap.appendChild(container);

    var t = createTerm({ container: container });

    var tabInfo = {
      id: id,
      name: name,
      tabEl: tabEl,
      container: container,
      term: t.term,
      fitAddon: t.fitAddon,
      ws: null,
      state: 'connecting',
      stateText: 'Connecting...',
    };
    tabs[id] = tabInfo;

    t.term.onData(function(data) {
      if (tabInfo.ws && tabInfo.ws.readyState === WebSocket.OPEN) {
        tabInfo.ws.send(JSON.stringify({ type: 'data', data: data }));
      }
    });

    t.term.onResize(function(size) {
      if (tabInfo.ws && tabInfo.ws.readyState === WebSocket.OPEN) {
        tabInfo.ws.send(JSON.stringify({ type: 'resize', cols: size.cols, rows: size.rows }));
      }
    });

    switchTab(id);
    connectTab(tabInfo);

    tabEl.addEventListener('click', function(e) {
      if (e.target.classList.contains('tab-close')) return;
      switchTab(id);
    });

    tabEl.querySelector('.tab-close').addEventListener('click', function(e) {
      e.stopPropagation();
      closeTab(id);
    });
  }

  document.getElementById('reconnect-btn').addEventListener('click', function() {
    var tab = tabs[activeTabId];
    if (!tab) return;
    if (tab.ws) {
      tab.ws.onclose = null;
      tab.ws.close();
      tab.ws = null;
    }
    tab.state = 'connecting';
    tab.stateText = 'Connecting...';
    setStatus(tab.state, tab.stateText);
    connectTab(tab);
  });

  document.getElementById('tab-add-btn').addEventListener('click', addTab);

  wrap.addEventListener('click', function() {
    var tab = tabs[activeTabId];
    if (tab && tab.term) tab.term.focus();
  });

  var resizeObserver = new ResizeObserver(function() {
    setTimeout(function() {
      var tab = tabs[activeTabId];
      if (tab && tab.fitAddon) tab.fitAddon.fit();
    }, 100);
  });
  resizeObserver.observe(wrap);

  window.addEventListener('resize', function() {
    var tab = tabs[activeTabId];
    if (tab && tab.fitAddon) tab.fitAddon.fit();
  });

  addTab();
})();
</script>
