#!/bin/bash
# Arrête le serveur lancé par run-stack.sh et retire les assets compilés (le mode dev les sert à la volée).
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
if [ -f "$HERE/.tmp/pids" ]; then xargs -r kill < "$HERE/.tmp/pids" 2>/dev/null || true; rm -f "$HERE/.tmp/pids"; fi
rm -rf "$ROOT/public/assets"
