# CLAUDE.md — instruções para o Claude Code

Projeto: Home própria + Player de TV para LG webOS, com um homeserver (Docker) que faz o trabalho pesado.
Quem clonou quer que você **instale e configure** tudo. Leia `README.md` e `docs/instalacao.md` primeiro.

## Antes de começar, pergunte ao usuário

1. **A TV já tem root e Homebrew Channel?** Se não, pare e explique: o root é por conta e risco dele, feito com
   ferramentas externas (guia: https://tv.slada.sk/guides/rooting-guide). Você não faz root.
2. Acesso ao servidor: `ssh usuario@host` (com chave) e a pasta de destino (padrão `/srv/casa`).
3. IP de cada TV, versão do webOS (4.x ou 10) e se o SSH da TV usa senha ou chave.
4. Token do TMDB e dados do painel Xtream (servidor, usuário, senha) — **peça para o usuário digitar direto
   no servidor** (`vi .env`, `read -rs SENHA`); não peça para colar na conversa.
5. Cidade para o clima (nome, latitude, longitude) → `CLIMA_CIDADE`, `CLIMA_LAT`, `CLIMA_LON` no `.env`.

## Ordem

1. Homeserver: copiar `homeserver/` → `/srv/casa`, `sh scripts/instalar.sh`, preencher `.env`,
   `docker compose up -d --build`, conferir `docker logs casa-worker` (migrações) e `/api/play/saude`.
2. Primeiro usuário do painel (`bin/usuario.php criar`), conta do painel (`bin/play.php conta-adicionar`).
3. Pacotes: `player/` (`npm ci && npm run package`, precisa do `ares-package`), `launcher/` (`python scripts/build.py`).
4. TV (como root): Player + `safe_install.sh` + atualizador, `helper.py` + `60-webostv-helper`, troca da Home
   (pasta `tv/home-takeover-webos10/` ou `tv/home-takeover-webos4/`), ajustes opcionais de `tv/init.d/`.
5. Aprovar a TV no painel (Player › Listas e TVs).

## Regras

- **Nunca** grave senha, token, chave ou lista de canais em arquivo versionado, commit, log ou resposta.
  Segredos ficam só no `.env` do servidor (chmod 600), no banco e no `config.json` da TV.
  `.env`, `launcher/app/defaults.js` e `config.json` estão no `.gitignore`; não tire.
- Não ligue TV remotamente (Wake-on-LAN) sem o usuário pedir.
- Instale na TV só com o Player fechado (o `safe_install.sh` espera); não interrompa vídeo tocando.
- webOS 10: não rode `systemctl restart sam` com a TV em uso (tela preta); reinicie a TV.
- Mudou rota ou formato entre servidor e TV? Atualize `homeserver/docs/play-2.0.md` (seção de mudanças).
- Mudou o banco? Nova migração numerada em `homeserver/casa/sql/` e atualize `db/schema.sql` e `docs/banco-de-dados.md`.

## Testes

```bash
# Player (servidor simulado + Chrome headless, 1920x1080)
cd player && npm ci && npm run media && npm run build
node dev/mock-server.mjs &            # porta 8787
CHROME=/caminho/do/chrome node dev/test-nav.mjs

# Servidor (PHP 8.4; a maioria dos testes roda sem banco)
php homeserver/casa/tests/play/rodar.php
```

## Mapa rápido

- `player/src/js/` — app da TV: `main.js` (boot), `net.js` (API), `discovery.js` (acha o homeserver),
  `screens/*` (telas), `livecache.js` (Ao vivo sem servidor), `trailer.js`, `player.js` (vídeo/HLS).
- `homeserver/casa/src/Play/Api/` — rotas `/api/play/*` (`Rotas.php` lista todas); `Dados/` — worker
  (`Tarefas.php` agenda; `Tmdb`, `Xtream`, `Epg`, `Jogos`, `Top10`, `Recomendador`, `Legendas`, `Clima`).
- `launcher/app/index.html` — Home inteira (um arquivo); lê `GET /api/play/launcher`.
- `tv/helper/helper.py` — serviço root na TV (127.0.0.1:3911).
