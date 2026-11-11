#!/bin/sh
# Installs a new FreeMyTvWebOS .ipk while it's acting as Home, then re-applies the
# takeover. Usage: update.sh /tmp/app.ipk
# The bind mounts must come off first: with the hidden appinfo.json mounted over the
# real one, the installer would see the disguised manifest instead of the package's.

IPK="$1"
SRC=/media/developer/apps/usr/palm/applications/org.webos.freemytv
DST=/usr/palm/applications/com.webos.app.home
INIT=/var/lib/webosbrew/init.d/50-freemytv-as-home

if [ ! -f "$IPK" ]; then
    echo "usage: $0 /path/app.ipk" >&2
    exit 1
fi

grep -q " $SRC/appinfo.json " /proc/mounts && umount -l "$SRC/appinfo.json"
grep -q " $DST " /proc/mounts && umount -l "$DST"

OUT=$(timeout 60 luna-send-pub -n 30 luna://com.webos.appInstallService/dev/install \
    "{\"id\":\"org.webos.freemytv\",\"ipkUrl\":\"$IPK\",\"subscribe\":true}")
rm -f "$IPK"

if echo "$OUT" | grep -q '"state":"installed"'; then
    echo "updated, rebooting"
else
    echo "install failed, rebooting to restore the current Home: $OUT" >&2
fi

# Reboot rather than re-applying live: restarting sam with the TV already running left
# the screen black (twice), while a clean boot re-applies the takeover reliably.
reboot
