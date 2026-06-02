#!/bin/bash
# Terminal Plugin — Install Script
# Usage: sudo bash plugins/terminal/install.sh
# Run from CMS project root

set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$PLUGIN_DIR/../.." && pwd)"
CMS_NAME="$(basename "$PROJECT_DIR")"

echo "== Terminal Plugin Installer =="
echo "Project: $CMS_NAME ($PROJECT_DIR)"

# 1. Detect CFG path
if [ -d "$PROJECT_DIR/cfg" ]; then
    CFG_DIR="$PROJECT_DIR/cfg"
elif [ -d "$PROJECT_DIR/../cfg" ]; then
    CFG_DIR="$(cd "$PROJECT_DIR/../cfg" && pwd)"
else
    echo "ERROR: Cannot find cfg/ directory"
    exit 1
fi

# 2. Create token directory
TOKEN_DIR="$CFG_DIR/var/terminal-tokens"
mkdir -p "$TOKEN_DIR"
chmod 770 "$TOKEN_DIR"
echo "Token dir: $TOKEN_DIR"

# 3. Install npm dependencies
cd "$PLUGIN_DIR/server"
npm install --silent 2>/dev/null || npm install
echo "NPM dependencies installed"

# 4. Install systemd service
SERVICE_FILE="/etc/systemd/system/terminal-websocket.service"
sudo tee "$SERVICE_FILE" > /dev/null << SERVICEEOF
[Unit]
Description=Terminal WebSocket Server ($CMS_NAME)
After=network.target

[Service]
Type=simple
User=adam
WorkingDirectory=$PLUGIN_DIR/server
ExecStart=/usr/bin/node server.js
Restart=on-failure
RestartSec=5
Environment=TERMINAL_TOKEN_DIR=$TOKEN_DIR
Environment=TERMINAL_PORT=8765
StandardOutput=journal
StandardError=journal
PrivateTmp=false

[Install]
WantedBy=multi-user.target
SERVICEEOF

sudo systemctl daemon-reload
sudo systemctl enable terminal-websocket.service
sudo systemctl restart terminal-websocket.service
echo "Systemd service installed & started"

# 5. Verify xterm.js vendor assets
STATIC_DIR="$PROJECT_DIR/public/static/vendor/xterm"
XTERM_FILES=("xterm.min.css" "xterm.min.js" "addon-fit.min.js" "addon-web-links.min.js")
ALL_EXIST=true
for f in "${XTERM_FILES[@]}"; do
    if [ ! -f "$STATIC_DIR/$f" ]; then
        ALL_EXIST=false
        break
    fi
done

if [ "$ALL_EXIST" = false ]; then
    echo "WARNING: Some xterm.js vendor files missing in $STATIC_DIR"
    echo "Download from: https://cdn.jsdelivr.net/npm/@xterm/xterm/css/xterm.css"
    echo "and: https://cdn.jsdelivr.net/npm/@xterm/xterm/lib/xterm.js"
fi

echo ""
echo "== Terminal Plugin Installed =="
echo "Access: http://{cms-domain}/?page=admin/terminal/index"
echo "Or via nav menu: Settings > Terminal"
echo "Service status: sudo systemctl status terminal-websocket"
