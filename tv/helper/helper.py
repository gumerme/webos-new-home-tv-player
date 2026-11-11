"""WebOS TV helper: running apps and app icons for the Home.

Runs as root, bound to 127.0.0.1 only. The Home (com.webos.app.home) is denied these
Luna calls and the Homebrew Channel's exec, and can't read other apps' icons over
file://, but root can.
GET /apps               -> listLaunchPoints, plus 'installed': every installed app id
GET /running            -> applicationManager/running, each entry with its app title
GET /launch?id=<appId>[&target=<contentTarget>] -> applicationManager/launch
GET /close?id=<appId>   -> applicationManager/closeByAppId
GET /icon?id=<appId>    -> the app's own launcher icon (PNG/JPEG)
GET /trailer?v=<YouTube id> -> a page embedding that YouTube video, autoplaying. WebOS TV
                           Player frames it for its post-play trailer: YouTube refuses embeds
                           from file:// pages (no referrer), this gives them an http origin.
GET /installing          -> apps being downloaded/installed/updated now (LG Content Store or dev
                           installs): {'apps': [{id, step, progress}]}, for the Home's tiles
GET /play/meta|catalog|hot|releases -> WebOS TV Player's local copies, kept by its sync
                           job (webostv-play/sync.py); 404 until the first sync
GET /play/sync          -> asks that job to refresh now
GET /play/account       -> the IPTV account the sync job uses: {server, username, hasPassword}
GET /play/account/set?server=&username=&password= -> changes it (from the player's Ajustes)
GET /subs/search?tmdb=<id>[&season=&episode=][&lang=pob,por] -> subtitles from Stremio's
                           public OpenSubtitles add-on (no key), Blu-ray/WEB releases first
GET /subs/get?file=<id>&url=<subs*.strem.io url> -> that subtitle as UTF-8 SRT, cached on disk
GET /series             -> the followed series, {'series': [...]}, newest change first
GET /series/set?tmdb=<id>&title=&app=&season=&episode=&xs= -> adds or updates one series
                           (xs: its series id on the IPTV panel, for WebOS TV Play)
GET /series/remove?tmdb=<id> -> stops following it
(Series are written only here, so the player and the Home never race on the file.)

Runs on Python 3 (webOS 10) and on webOS 4.x's trimmed Python 2.7, which has no
BaseHTTPServer, SocketServer or threading: hence the bare socket loop below.
"""
import json
import os
import re
import socket
import subprocess

try:
    from urllib.parse import parse_qs, urlparse
except ImportError:  # Python 2.7
    from urlparse import parse_qs, urlparse

PORT = 3911
SERIES_FILE = '/var/lib/webosbrew/webostv-series/series.json'
PLAY_FOLDER = '/var/lib/webosbrew/webostv-play'
PLAY_FILES = ('meta', 'catalog', 'hot', 'releases')
APP_ID = re.compile(r'^[A-Za-z0-9._-]+$')
YOUTUBE_ID = re.compile(r'^[A-Za-z0-9_-]{6,20}$')
TRAILER_PAGE = ('<!DOCTYPE html><html><head><meta charset="utf-8">'
                '<meta name="referrer" content="strict-origin-when-cross-origin">'
                '<style>html,body{margin:0;height:100%;background:#000;overflow:hidden}'
                '#p,iframe{border:0;width:100%;height:100%}</style></head><body><div id="p"></div><script>'
                # 09/11: YouTube IFrame API para avisar o Player quando o trailer acaba (o destaque so gira depois)
                'function avisar(e){try{parent.postMessage({trailer:e},"*")}catch(x){}}var CC={cc};'
                'var t0=setTimeout(function(){avisar("erro")},20000);'
                'function onYouTubeIframeAPIReady(){new YT.Player("p",{host:"https://www.youtube-nocookie.com",'
                'videoId:"{v}",width:"100%",height:"100%",playerVars:{autoplay:1,controls:0,rel:0,modestbranding:1,'
                'playsinline:1,iv_load_policy:3,{ccvars}},'
                'events:{onReady:function(e){e.target.playVideo()},'
                'onStateChange:function(e){if(e.data===1){clearTimeout(t0);avisar("tocando");if(!CC){try{e.target.unloadModule("captions");e.target.unloadModule("cc")}catch(x){}}}if(e.data===0){avisar("fim")}},'
                'onError:function(){clearTimeout(t0);avisar("erro")}}})}'
                '</script><script src="https://www.youtube.com/iframe_api"></script></body></html>')
REASONS = {200: 'OK', 400: 'Bad Request', 404: 'Not Found', 500: 'Internal Server Error', 502: 'Bad Gateway', 503: 'Service Unavailable'}
apps = {}  # appId -> {'title', 'icon'}, refreshed when an unknown id is asked for


def luna(uri, payload):
    proc = subprocess.Popen(['luna-send', '-n', '1', uri, json.dumps(payload)],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    out = proc.communicate()[0].decode('utf-8', 'replace')
    return json.loads(out or '{}')


def refresh_apps():
    global apps
    found = {}
    store_icons = {}
    for a in luna('luna://com.webos.applicationManager/listApps', {}).get('apps', []):
        folder = a.get('folderPath', '')
        icon = a.get('largeIcon') or a.get('icon') or ''
        if icon and not icon.startswith('/'):
            icon = os.path.join(folder, icon)
        found[a['id']] = {'title': a.get('title') or a['id'], 'icon': icon}
        store_icons[a['id']] = store_icon(folder)
    # Launch points carry the icon actually shown in LG's launcher (often larger).
    for lp in luna('luna://com.webos.applicationManager/listLaunchPoints', {}).get('launchPoints', []):
        icon = lp.get('largeIcon') or lp.get('icon') or ''
        if lp.get('id') in found and icon.startswith('/'):
            found[lp['id']]['icon'] = icon
    # Store-installed apps also ship the 192x192 LG Content Store icon, sharper than
    # the 130px launcher one once the tile scales it.
    for app_id, icon in store_icons.items():
        if icon:
            found[app_id]['icon'] = icon
    apps = found


def store_icon(folder):
    try:
        names = sorted(n for n in os.listdir(folder) if '192x192' in n and n.lower().endswith('.png'))
    except OSError:
        return ''
    return os.path.join(folder, names[0]) if names else ''


def app_info(app_id):
    if app_id not in apps:
        refresh_apps()
    return apps.get(app_id)


def load_series():
    try:
        with open(SERIES_FILE) as f:
            return json.load(f).get('series', [])
    except (IOError, OSError, ValueError):
        return []


def save_series(series):
    folder = os.path.dirname(SERIES_FILE)
    if not os.path.isdir(folder):
        os.makedirs(folder)
    tmp = SERIES_FILE + '.tmp'
    with open(tmp, 'w') as f:
        json.dump({'series': series}, f)
    os.rename(tmp, SERIES_FILE)


def int_arg(query, name):
    value = (query.get(name) or [''])[0]
    return int(value) if value.isdigit() and len(value) < 10 else None


def series_request(path, query):
    import time
    series = load_series()
    tmdb = int_arg(query, 'tmdb')
    if path == '/series':
        return {'series': series}
    if tmdb is None:
        return None
    rest = [s for s in series if s.get('tmdb') != tmdb]
    if path == '/series/remove':
        save_series(rest)
        return {'returnValue': True}
    old = ([s for s in series if s.get('tmdb') == tmdb] or [{}])[0]
    entry = dict(old, tmdb=tmdb, updated=int(time.time()))
    entry.setdefault('added', entry['updated'])
    title = (query.get('title') or [''])[0].strip()[:200]
    app = (query.get('app') or [''])[0]
    if title:
        entry['title'] = title
    if APP_ID.match(app):
        entry['app'] = app
    xs = int_arg(query, 'xs')
    if xs is not None:
        entry['xs'] = xs
    season, episode = int_arg(query, 'season'), int_arg(query, 'episode')
    if season is not None and episode is not None:
        entry['season'], entry['episode'] = season, episode
    save_series([entry] + rest)
    return {'returnValue': True, 'series': entry}


# Subtitles: the public OpenSubtitles add-on of Stremio (no key or account; OpenSubtitles'
# catalog by IMDb id, files served as UTF-8 SRT). IMDb ids come from TMDB.
SUBS_ADDON = 'https://opensubtitles-v3.strem.io/subtitles'
SUBS_URL = re.compile(r'^https://subs\d*\.strem\.io/[A-Za-z0-9/._-]+$')
SUBS_FOLDER = os.path.join(PLAY_FOLDER, 'subs')
SUBS_LANGS = {'pob': u'Portugu\u00eas (Brasil)', 'por': u'Portugu\u00eas (Portugal)', 'eng': u'Ingl\u00eas', 'spa': u'Espanhol'}
# Release kinds, best first: cinema recordings (Cam/Telesync) rarely match the panel's files.
FORMAT_RANK = [('blu', 0), ('web', 0), ('dvd', 1), ('hdtv', 1), ('tv', 2), ('telecine', 3), ('telesync', 3), ('cam', 3)]
_imdb = {}


def play_config():
    try:
        with open(os.path.join(PLAY_FOLDER, 'config.json')) as f:
            return json.load(f)
    except (IOError, OSError, ValueError):
        return {}


def fetch(url, headers=None, timeout=20):
    try:
        from urllib.request import Request, urlopen
    except ImportError:  # Python 2.7
        from urllib2 import Request, urlopen
    h = {'User-Agent': 'WebOSTVPlayer/1.0'}
    h.update(headers or {})
    return urlopen(Request(url, None, h), timeout=timeout).read()


def imdb_id(tmdb, is_series):
    key = ('s' if is_series else 'm') + str(tmdb)
    if key not in _imdb:
        path = ('tv/%d/external_ids' if is_series else 'movie/%d/external_ids') % tmdb
        out = json.loads(fetch('https://api.themoviedb.org/3/' + path,
                               {'Authorization': 'Bearer ' + play_config().get('tmdbKey', '')}).decode('utf-8'))
        _imdb[key] = out.get('imdb_id') or ''
    return _imdb[key]


def format_rank(name):
    name = (name or '').lower()
    for word, rank in FORMAT_RANK:
        if word in name:
            return rank
    return 1


def subs_request(path, query):
    if path == '/subs/search':
        tmdb, season, episode = int_arg(query, 'tmdb'), int_arg(query, 'season'), int_arg(query, 'episode')
        if tmdb is None:
            return 400, {'returnValue': False, 'errorText': 'bad query'}
        is_series = season is not None and episode is not None
        imdb = imdb_id(tmdb, is_series)
        if not re.match(r'^tt\d+$', imdb or ''):
            return 200, {'returnValue': True, 'results': []}
        url = '%s/%s/%s.json' % (SUBS_ADDON, 'series' if is_series else 'movie',
                                 '%s:%d:%d' % (imdb, season, episode) if is_series else imdb)
        subs = json.loads(fetch(url).decode('utf-8')).get('subtitles', [])
        wanted = (query.get('lang') or ['pob,por'])[0].split(',')
        results = []
        for x in subs:
            if x.get('lang') not in wanted or not SUBS_URL.match(x.get('url') or ''):
                continue
            fmt = x.get('releaseFormat') or ''
            results.append({'file': str(x.get('id')), 'url': x['url'], 'lang': x.get('lang'),
                            'label': SUBS_LANGS.get(x.get('lang'), x.get('lang')),
                            'release': (x.get('movieReleaseName') or x.get('subtitleFileName') or '')[:80], 'format': fmt,
                            'rank': wanted.index(x.get('lang')) * 10 + format_rank(fmt + ' ' + (x.get('movieReleaseName') or ''))})
        results.sort(key=lambda r: r['rank'])
        return 200, {'returnValue': True, 'results': results[:12]}
    file_id = (query.get('file') or [''])[0]
    url = (query.get('url') or [''])[0]
    if not re.match(r'^\d{1,12}$', file_id) or not SUBS_URL.match(url):
        return 400, {'returnValue': False, 'errorText': 'bad file'}
    cached = os.path.join(SUBS_FOLDER, file_id + '.srt')
    if os.path.isfile(cached):
        with open(cached, 'rb') as f:
            return 200, f.read()
    raw = fetch(url)
    for enc in ('utf-8-sig', 'cp1252', 'latin-1'):
        try:
            body = raw.decode(enc).encode('utf-8')
            break
        except UnicodeDecodeError:
            continue
    if not os.path.isdir(SUBS_FOLDER):
        os.makedirs(SUBS_FOLDER)
    with open(cached, 'wb') as f:
        f.write(body)
    return 200, body


SERVER_URL = re.compile(r'^https?://[A-Za-z0-9.-]+(:\d{1,5})?/?$')


def account_request(path, query):
    cfg = play_config()
    if path == '/play/account':
        return 200, 'application/json', json.dumps({'server': cfg.get('server', ''), 'username': cfg.get('username', ''),
                                                    'hasPassword': bool(cfg.get('password'))})
    server = (query.get('server') or [''])[0].strip().rstrip('/')
    user = (query.get('username') or [''])[0].strip()
    password = (query.get('password') or [''])[0]
    if not SERVER_URL.match(server) or not user or len(user) > 128 or len(password) > 128:
        return 400, 'application/json', json.dumps({'returnValue': False, 'errorText': 'bad account'})
    cfg.update(server=server, username=user)
    if password:
        cfg['password'] = password
    path_cfg = os.path.join(PLAY_FOLDER, 'config.json')
    with open(path_cfg + '.tmp', 'w') as f:
        json.dump(cfg, f)
    os.chmod(path_cfg + '.tmp', 0o600)
    os.rename(path_cfg + '.tmp', path_cfg)
    open('/tmp/webostv-play-sync-now', 'w').close()
    return 200, 'application/json', json.dumps({'returnValue': True})


def installing():
    out = []
    for a in luna('luna://com.webos.appInstallService/status', {}).get('status', {}).get('apps', []):
        d = a.get('details') or {}
        app_id = a.get('id') or d.get('packageId')
        state = d.get('state') or ''
        if not app_id or state in ('installed', 'removed') or 'fail' in state:
            continue
        try:
            total, got = float(d.get('totalSize') or 0), float(d.get('receivedSize') or 0)
        except ValueError:
            total = got = 0
        step = d.get('simpleStatus') or 'install'
        progress = int(got * 100 / total) if step == 'download' and total > 0 else None
        out.append({'id': app_id, 'step': step, 'progress': progress})
    return out


def handle(path):
    """Returns (status, content type, body) for one GET path."""
    url = urlparse(path)
    app_id = (parse_qs(url.query).get('id') or [''])[0]
    try:
        if url.path == '/installing':
            return 200, 'application/json', json.dumps({'apps': installing()})
        if url.path in ('/subs/search', '/subs/get'):
            code, out = subs_request(url.path, parse_qs(url.query))
            if isinstance(out, bytes):
                return code, 'text/plain; charset=utf-8', out
            return code, 'application/json', json.dumps(out)
        if url.path in ('/play/account', '/play/account/set'):
            return account_request(url.path, parse_qs(url.query))
        if url.path.startswith('/play/'):
            name = url.path[len('/play/'):]
            if name == 'sync':
                open('/tmp/webostv-play-sync-now', 'w').close()
                return 200, 'application/json', json.dumps({'returnValue': True})
            path = os.path.join(PLAY_FOLDER, name + '.json')
            if name not in PLAY_FILES or not os.path.isfile(path):
                return 404, 'application/json', json.dumps({'returnValue': False})
            with open(path, 'rb') as f:
                return 200, 'application/json', f.read()
        if url.path == '/trailer':
            video = (parse_qs(url.query).get('v') or [''])[0]
            if not YOUTUBE_ID.match(video):
                return 400, 'application/json', json.dumps({'returnValue': False, 'errorText': 'bad video id'})
            # leg=pt (09/11): trailer sem dublagem abre com a legenda em pt-BR; com som sempre
            cc = '1' if (parse_qs(url.query).get('leg') or [''])[0] == 'pt' else '0'
            return 200, 'text/html; charset=utf-8', TRAILER_PAGE.replace('{v}', video).replace('{cc}', cc).replace('{ccvars}', 'hl:"pt-BR",cc_load_policy:1,cc_lang_pref:"pt"' if cc == '1' else 'cc_load_policy:0')
        if url.path in ('/series', '/series/set', '/series/remove'):
            data = series_request(url.path, parse_qs(url.query))
            if data is None:
                return 400, 'application/json', json.dumps({'returnValue': False, 'errorText': 'bad tmdb id'})
        elif url.path == '/apps':
            # What is actually installed: the launcher's launch points plus every app id
            # (inputs like HDMI and Live TV are apps without a launch point on webOS 4).
            data = luna('luna://com.webos.applicationManager/listLaunchPoints', {})
            data['installed'] = [a['id'] for a in
                                 luna('luna://com.webos.applicationManager/listApps', {}).get('apps', [])]
        elif url.path == '/running':
            data = luna('luna://com.webos.applicationManager/running', {})
            for a in data.get('running', []):
                info = app_info(a.get('id', ''))
                a['title'] = info['title'] if info else a.get('id')
        elif url.path == '/launch':
            # webOS 4 refuses applicationManager/launch from the web Home running under
            # LG's native Home id; root can still launch. target: an optional contentTarget.
            if not APP_ID.match(app_id):
                return 400, 'application/json', json.dumps({'returnValue': False, 'errorText': 'bad id'})
            payload = {'id': app_id}
            target = (parse_qs(url.query).get('target') or [''])[0]
            if target:
                payload['params'] = {'contentTarget': target}
            data = luna('luna://com.webos.applicationManager/launch', payload)
        elif url.path in ('/close', '/icon'):
            if not APP_ID.match(app_id):
                return 400, 'application/json', json.dumps({'returnValue': False, 'errorText': 'bad id'})
            if url.path == '/icon':
                info = app_info(app_id)
                icon = info and info['icon']
                if not icon or not os.path.isfile(icon):
                    return 404, 'application/json', json.dumps({'returnValue': False})
                with open(icon, 'rb') as f:
                    body = f.read()
                return 200, 'image/jpeg' if icon.lower().endswith(('.jpg', '.jpeg')) else 'image/png', body
            data = luna('luna://com.webos.applicationManager/closeByAppId', {'id': app_id})
        else:
            return 404, 'application/json', json.dumps({'returnValue': False})
    except Exception as e:
        return 500, 'application/json', json.dumps({'returnValue': False, 'errorText': str(e)})
    return 200, 'application/json', json.dumps(data)


def serve():
    # One request at a time: they are tiny and come only from the Home on this TV.
    srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    srv.bind(('127.0.0.1', PORT))
    srv.listen(16)
    while True:
        conn = srv.accept()[0]
        try:
            conn.settimeout(5)
            req = b''
            while b'\r\n\r\n' not in req and len(req) < 8192:
                chunk = conn.recv(4096)
                if not chunk:
                    break
                req += chunk
            parts = req.split(b'\r\n', 1)[0].decode('latin-1').split(' ')
            if len(parts) >= 2 and parts[0] == 'GET':
                code, ctype, body = handle(parts[1])
            else:
                code, ctype, body = 400, 'application/json', '{}'
            if not isinstance(body, bytes):
                body = body.encode('utf-8')
            cache = 'Cache-Control: max-age=86400\r\n' if ctype.startswith('image/') else ''
            head = ('HTTP/1.0 %d %s\r\nContent-Type: %s\r\nContent-Length: %d\r\n'
                    'Access-Control-Allow-Origin: *\r\n%sConnection: close\r\n\r\n') % (
                code, REASONS.get(code, 'OK'), ctype, len(body), cache)
            conn.sendall(head.encode('latin-1') + body)
        except Exception:
            pass
        finally:
            conn.close()


serve()
