#!/bin/bash
# Terminal Plugin — Uninstall Script
# Usage: sudo bash plugins/terminal/uninstall.sh
# Run from CMS project root

set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
echo "== Terminal Plugin Uninstaller =="
echo "Plugin: $PLUGIN_DIR"

read -p "Remove systemd service? (y/N): " REMOVE_SERVICE
if [[ "$REMOVE_SERVICE" =~ ^[Yy]$ ]]; then
    sudo systemctl stop terminal-websocket.service 2>/dev/null || true
    sudo systemctl disable terminal-websocket.service 2>/dev/null || true
    sudo rm -f /etc/systemd/system/terminal-websocket.service
    sudo systemctl daemon-reload
    echo "Systemd service removed"
fi

read -p "Remove token directory? (y/N): " REMOVE_TOKENS
if [[ "$REMOVE_TOKENS" =~ ^[Yy]$ ]]; then
    TOKEN_DIR="$PLUGIN_DIR/../../cfg/var/terminal-tokens"
    if [ -d "$TOKEN_DIR" ]; then
        rm -rf "$TOKEN_DIR"
        echo "Token directory removed: $TOKEN_DIR"
    fi
fi

read -p "Remove node_modules? (y/N): " REMOVE_NM
if [[ "$REMOVE_NM" =~ ^[Yy]$ ]]; then
    rm -rf "$PLUGIN_DIR/server/node_modules"
    echo "node_modules removed"
fi

echo ""
echo "== Terminal Plugin Uninstalled =="
echo "Plugin files remain at: $PLUGIN_DIR"
echo "To fully remove: rm -rf $PLUGIN_DIR"
