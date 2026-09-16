#!/bin/sh

set -eu

if [ "$(id -u)" -ne 0 ]; then
	echo "Run this uninstaller as root."
	exit 1
fi

WIDGET_TARGET="/usr/local/www/widgets/widgets/cloudflare_tunnel.widget.php"
INCLUDE_TARGET="/usr/local/www/widgets/include/cloudflare_tunnel.inc"

if [ -f "$WIDGET_TARGET" ]; then
	rm "$WIDGET_TARGET"
fi
if [ -f "$INCLUDE_TARGET" ]; then
	rm "$INCLUDE_TARGET"
fi

echo "Cloudflare Tunnel dashboard widget removed."
echo "The retained installer and backups remain in /conf/cloudflare-tunnel-widget."

