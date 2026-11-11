# Installs /tmp/play.ipk only when WebOS TV Player isn't in use (not foreground, nothing playing in it).
for i in $(seq 1 120); do
    fg=$(luna-send -n 1 luna://com.webos.applicationManager/getForegroundAppInfo '{}' | grep -o '"appId":"[^"]*"')
    case "$fg" in
        *org.webostv.play*) sleep 30 ;;
        *)
            luna-send -n 1 luna://com.webos.applicationManager/closeByAppId '{"id":"org.webostv.play"}' >/dev/null
            timeout 90 luna-send-pub -n 30 luna://com.webos.appInstallService/dev/install '{"id":"org.webostv.play","ipkUrl":"/tmp/play.ipk","subscribe":true}' | grep -oE '"state":"installed"' | head -1
            echo "installed while foreground was $fg"
            exit 0 ;;
    esac
done
echo "player stayed open for an hour; not installed"
