#!/bin/sh
# Installs a new WebOS TV .ipk on the webOS 4.x TV while it's acting as Home, then
# re-applies the takeover. Usage: update.sh /tmp/app.ipk
# The bind mounts must come off first: with the hidden appinfo.json mounted over the
# real one, the installer would see the disguised manifest instead of the package's.
# Restarting sam with the TV on works on webOS 4.x (it blacked the screen on webOS 10).

IPK="$1"
SRC=/media/developer/apps/usr/palm/applications/org.webos.freemytv
DST=/usr/palm/applications/com.webos.app.fullhome

if [ ! -f "$IPK" ]; then
    echo "usage: $0 /path/app.ipk" >&2
    exit 1
fi

grep -q " $SRC/appinfo.json " /proc/mounts && umount -l "$SRC/appinfo.json"
grep -q " $DST " /proc/mounts && umount -l "$DST"

OUT=$(luna-send-pub -n 30 -w 90000 luna://com.webos.appInstallService/dev/install \
    "{\"id\":\"org.webos.freemytv\",\"ipkUrl\":\"$IPK\",\"subscribe\":true}")
rm -f "$IPK"

if echo "$OUT" | grep -q '"state":"installed"'; then
    echo "updated"
else
    echo "install failed: $OUT" >&2
fi

# The Home's page survives a sam restart (WAM keeps it loaded): restart WAM so the new
# version is what loads. The takeover script then relaunches the Home.
restart WebAppMgr >/dev/null
sleep 5
/var/lib/webosbrew/init.d/50-webostv-as-home
