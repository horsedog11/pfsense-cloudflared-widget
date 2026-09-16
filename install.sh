#!/bin/sh

set -eu

if [ "$(id -u)" -ne 0 ]; then
	echo "Run this installer as root."
	exit 1
fi

SOURCE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
WIDGET_SOURCE="$SOURCE_DIR/cloudflare_tunnel.widget.php"
INCLUDE_SOURCE="$SOURCE_DIR/cloudflare_tunnel.inc"
WIDGET_TARGET="/usr/local/www/widgets/widgets/cloudflare_tunnel.widget.php"
INCLUDE_TARGET="/usr/local/www/widgets/include/cloudflare_tunnel.inc"
PERSIST_DIR="/conf/cloudflare-tunnel-widget"

for REQUIRED_FILE in "$WIDGET_SOURCE" "$INCLUDE_SOURCE"; do
	if [ ! -f "$REQUIRED_FILE" ]; then
		echo "Missing required file: $REQUIRED_FILE"
		exit 1
	fi
done

if [ ! -d /usr/local/www/widgets/widgets ] || [ ! -d /usr/local/www/widgets/include ]; then
	echo "pfSense dashboard widget directories were not found."
	exit 1
fi

if ! /usr/local/bin/php -l "$WIDGET_SOURCE"; then
	echo "PHP validation failed; nothing was installed."
	exit 1
fi

mkdir -p "$PERSIST_DIR"

STAMP=$(date +%Y%m%d-%H%M%S)
if [ -f "$WIDGET_TARGET" ]; then
	cp -p "$WIDGET_TARGET" "$PERSIST_DIR/cloudflare_tunnel.widget.php.$STAMP.bak"
fi
if [ -f "$INCLUDE_TARGET" ]; then
	cp -p "$INCLUDE_TARGET" "$PERSIST_DIR/cloudflare_tunnel.inc.$STAMP.bak"
fi

if [ "$SOURCE_DIR" != "$PERSIST_DIR" ]; then
	cp "$WIDGET_SOURCE" "$PERSIST_DIR/cloudflare_tunnel.widget.php"
	cp "$INCLUDE_SOURCE" "$PERSIST_DIR/cloudflare_tunnel.inc"
	cp "$SOURCE_DIR/install.sh" "$PERSIST_DIR/install.sh"
	cp "$SOURCE_DIR/uninstall.sh" "$PERSIST_DIR/uninstall.sh"
fi

cp "$WIDGET_SOURCE" "$WIDGET_TARGET"
cp "$INCLUDE_SOURCE" "$INCLUDE_TARGET"
chmod 0644 "$WIDGET_TARGET" "$INCLUDE_TARGET"
chmod 0755 "$PERSIST_DIR/install.sh" "$PERSIST_DIR/uninstall.sh"

/usr/local/bin/php -l "$WIDGET_TARGET"

echo "Cloudflare Tunnel dashboard widget installed."
echo "Open Status > Dashboard, expand Available Widgets, and add Cloudflare Tunnel."
echo "After a pfSense upgrade, restore it with: sh $PERSIST_DIR/install.sh"
