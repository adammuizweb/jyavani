const WebSocket = require('ws');
const pty = require('node-pty');
const { v4: uuidv4 } = require('uuid');
const fs = require('fs');
const path = require('path');
const os = require('os');

const TOKEN_DIR = process.env.TERMINAL_TOKEN_DIR || '/var/www/jyavani.com/cfg/var/terminal-tokens';
const MAX_SESSIONS = 10;
const SESSION_TIMEOUT = 24 * 60 * 60 * 1000;

const PORT = parseInt(process.env.TERMINAL_PORT || '8765', 10);

const activeSessions = new Map();
const wss = new WebSocket.Server({ port: PORT });

console.log(`Terminal WebSocket server running on ws://0.0.0.0:${PORT}`);
console.log(`Token dir: ${TOKEN_DIR}`);
console.log(`Max sessions: ${MAX_SESSIONS}`);

wss.on('connection', (ws, req) => {
  const url = new URL(req.url, 'http://localhost');
  const token = url.searchParams.get('token');
  const cols = parseInt(url.searchParams.get('cols')) || 80;
  const rows = parseInt(url.searchParams.get('rows')) || 24;

  if (!token || !validateToken(token)) {
    ws.close(1008, 'Invalid or expired token');
    return;
  }

  if (activeSessions.size >= MAX_SESSIONS) {
    ws.close(1008, 'Maximum sessions reached');
    return;
  }

  const sessionId = uuidv4();
  console.log(`[${sessionId}] New connection from ${req.socket.remoteAddress}`);

  let ptyProcess;
  try {
    ptyProcess = pty.spawn('bash', [], {
      name: 'xterm-256color',
      cols: cols,
      rows: rows,
      cwd: '/home/adam',
      env: {
        ...process.env,
        HOME: '/home/adam',
        USER: 'adam',
        TERM: 'xterm-256color',
        SHELL: '/bin/bash',
      },
    });

    ptyProcess.on('data', (data) => {
      if (ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({ type: 'data', data }));
      }
    });

    ptyProcess.on('exit', (code) => {
      console.log(`[${sessionId}] PTY exited with code ${code}`);
      ws.close();
    });

    ws.on('message', (message) => {
      try {
        const msg = JSON.parse(message.toString());
        switch (msg.type) {
          case 'data':
            if (ptyProcess.pid) {
              ptyProcess.write(msg.data);
            }
            break;
          case 'resize':
            if (ptyProcess.pid) {
              ptyProcess.resize(msg.cols, msg.rows);
            }
            break;
          case 'ping':
            ws.send(JSON.stringify({ type: 'pong' }));
            break;
        }
      } catch (err) {
        console.error(`[${sessionId}] Message parse error:`, err.message);
      }
    });

    ws.on('close', () => {
      console.log(`[${sessionId}] Connection closed`);
      if (ptyProcess.pid) {
        ptyProcess.kill();
      }
      activeSessions.delete(sessionId);
    });

    ws.on('error', (err) => {
      console.error(`[${sessionId}] WebSocket error:`, err.message);
      if (ptyProcess.pid) {
        ptyProcess.kill();
      }
      activeSessions.delete(sessionId);
    });

    activeSessions.set(sessionId, { ws, pty: ptyProcess, createdAt: Date.now() });
    console.log(`[${sessionId}] PTY spawned, PID: ${ptyProcess.pid}`);

    ws.send(JSON.stringify({ type: 'connected', sessionId }));

  } catch (err) {
    console.error(`[${sessionId}] Failed to spawn PTY:`, err.message);
    ws.close(1011, 'Failed to create terminal');
  }
});

function validateToken(token) {
  try {
    const tokenPath = path.join(TOKEN_DIR, token);
    if (!fs.existsSync(tokenPath)) {
      return false;
    }

    const content = fs.readFileSync(tokenPath, 'utf8');
    const tokenData = JSON.parse(content);
    const now = Date.now();

    if (now > tokenData.expiresAt) {
      fs.unlinkSync(tokenPath);
      return false;
    }

    fs.unlinkSync(tokenPath);
    return true;
  } catch (err) {
    return false;
  }
}

setInterval(() => {
  for (const [id, session] of activeSessions) {
    if (Date.now() - session.createdAt > SESSION_TIMEOUT) {
      console.log(`[${id}] Session timeout, killing`);
      session.pty.kill();
      activeSessions.delete(id);
    }
  }
}, 60000);
