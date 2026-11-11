<div align="center">

# WebOS TV Player + Home

**Tela inicial (Home) própria e Player de TV para LG webOS, com o trabalho pesado num servidor de casa.**

A TV só desenha; recomendações, catálogo, grade, placares, rankings e legendas são montados no
*homeserver* (Docker). Se o servidor cair, a TV continua funcionando com a última cópia guardada.

[![License: MIT](https://img.shields.io/badge/licença-MIT-0a84ff.svg)](LICENSE)
[![webOS](https://img.shields.io/badge/LG_webOS-4.x_a_10-30d158.svg)](#requisitos)
[![Root](https://img.shields.io/badge/root-obrigatório-e06a62.svg)](#-root-é-obrigatório)
[![Claude Code](https://img.shields.io/badge/instala_com-Claude_Code-ff9f0a.svg)](#-instalação-com-o-claude-code)

<img src="docs/prints/player-01-inicio.jpg" width="900" alt="Início do Player: destaque com trailer, Gostei/Não gostei e Continuar assistindo">

</div>

---

## ⚠️ Root é obrigatório

Este projeto **só funciona em TV LG com root** (acesso de administrador ao sistema da TV) e o
[Homebrew Channel](https://github.com/webosbrew/webos-homebrew-channel) instalado. Sem root a TV não deixa
trocar a Home, rodar o *helper* nem instalar atualizações sozinha.

O root **não faz parte deste projeto**. Ele pode ser feito com ferramentas externas, conforme o modelo e a
versão do firmware da sua TV. Um bom ponto de partida é o guia:

👉 **https://tv.slada.sk/guides/rooting-guide**

> **Por sua própria conta e risco.** Fazer root pode anular a garantia, travar a TV ou parar de funcionar
> numa atualização de firmware. Leia o guia inteiro, confira se o seu modelo e a sua versão são compatíveis
> e só siga se aceitar os riscos. Depois do root, **troque a senha padrão do SSH da TV**.

---

## 🤖 Instalação com o Claude Code

O repositório foi preparado para ser instalado pelo [Claude Code](https://claude.com/claude-code).
Basta clonar e abrir:

```bash
git clone https://github.com/gumerme/webos-tv-player.git
cd webos-tv-player
claude
```

E pedir, por exemplo: **"instale o projeto no meu homeserver e nas minhas TVs"**.
O arquivo [`CLAUDE.md`](CLAUDE.md) explica ao Claude Code a arquitetura, a ordem da instalação, os testes e
as regras de segurança (nunca gravar senha ou token no repositório). Ele vai perguntar o que só você sabe:
endereço do servidor, IPs das TVs, sua chave do TMDB e os dados do seu painel.

Prefere fazer à mão? Siga o [guia de instalação](docs/instalacao.md).

---

## O que tem

### 🏠 Home (substitui a tela inicial da LG)

- Relógio grande, data e clima da sua cidade (Open-Meteo), fundo escuro fixo.
- Fileira de apps em cima; descendo aparecem **Continuar** (das duas TVs, inclusive o último canal ao vivo),
  **Jogos de hoje** com placar e escudos, **Para você** e **Top Brasil** (20 mais vistos do dia).
- Aviso animado com a porcentagem quando um app está instalando ou atualizando.
- Funciona sem o servidor: mostra a última cópia; relógio, apps e "Todos os apps" nunca dependem dele.
- Fork do [FreeMyTV](https://github.com/danielthejoker18/FreeMyTvWebOS) (MIT), reescrito para consumir o homeserver.

### ▶️ Player de TV (`org.webostv.play`)

- **Destaque** com 10 títulos escolhidos para o perfil, trailer dublado ou legendado em português (YouTube sem anúncios).
- **Gostei / Não gostei** direto no destaque; "gostei" num filme já conta como visto.
- **Recomendações próprias**: o que a casa assiste cruzado com MovieLens (filtragem colaborativa), Trakt e AniList.
- **Continuar assistindo** compartilhado entre as TVs; botão verde tira da lista.
- **Séries** com a imagem e o nome de cada episódio (TMDB).
- **Ao vivo**: grupos, jogos do dia com placar, grade de programação, favoritos, aviso quando o programa começa.
- **Ranking**: Top 10 de cada plataforma comparado com o dia anterior.
- **Minha lista**, busca (título, pessoa, plataforma) e legendas.
- **Sem servidor também funciona**: abre com a cópia guardada na TV e os canais tocam direto do painel.
- Atualiza sozinho: as TVs instalam a versão publicada no homeserver quando o Player está fechado.

### 🖥️ Homeserver (`homeserver/`)

- Docker Compose: `casa-web` (painel + API, PHP 8.4), `casa-play` (worker do Player), `casa-rec` (recomendador
  em Python), `casa-worker` (fala com as TVs), PostgreSQL 17.
- Painel web com login para aprovar TVs, perfis, listas, tarefas, estatísticas e plataformas do ranking.
- Opcionais que vêm junto: ponte com o Apple Home (Homebridge), Matter, métricas do servidor e varredura da rede.

---

## Prints

| Home | |
|---|---|
| ![Início](docs/prints/home-01-inicio.jpg) | ![Continuar](docs/prints/home-02-continuar.jpg) |
| ![Jogos de hoje](docs/prints/home-03-jogos-de-hoje.jpg) | ![Para você](docs/prints/home-04-para-voce.jpg) |
| ![Top Brasil](docs/prints/home-05-top-brasil.jpg) | ![Todos os apps](docs/prints/home-06-todos-os-apps.jpg) |

| Player de TV | |
|---|---|
| ![Início](docs/prints/player-01-inicio.jpg) | ![Continuar assistindo](docs/prints/player-02-continuar.jpg) |
| ![Recomendados](docs/prints/player-03-recomendados.jpg) | ![Top 10](docs/prints/player-04-top10.jpg) |
| ![Detalhe da série](docs/prints/player-05-detalhe-serie.jpg) | ![Séries](docs/prints/player-06-series.jpg) |
| ![Filmes](docs/prints/player-07-filmes.jpg) | ![Ranking](docs/prints/player-11-ranking.jpg) |
| ![Ao vivo](docs/prints/player-08-aovivo.jpg) | ![Canal tocando](docs/prints/player-09-canal-tocando.jpg) |
| ![Lista de canais](docs/prints/player-10-canal-lista.jpg) | ![Minha lista](docs/prints/player-12-minhalista.jpg) |
| ![Busca](docs/prints/player-13-busca.jpg) | ![Ao vivo sem servidor](docs/prints/player-15-aovivo-sem-servidor.png) |

Prints da TV real (1920×1080). O último é do simulador, com o homeserver desligado.

---

## Arquitetura

```
   TVs LG (root + Homebrew Channel)                         Homeserver (Docker Compose)
 ┌──────────────────────────────────┐   HTTP :8080   ┌──────────────────────────────────────────┐
 │ Home  (launcher/, no lugar da    │ ─────────────► │ casa-web    painel + API /api/play/*      │
 │        Home da LG)               │                │ casa-play   catálogo, TMDB, EPG, jogos,   │
 │ Player de TV (player/)           │ ◄───────────── │             Top 10, legendas, avisos      │
 │ helper.py  (root, 127.0.0.1)     │   SSE sinais   │ casa-rec    recomendador (MovieLens/ALS)  │
 │ atualizador (init.d, 5 min)      │                │ casa-worker liga/desliga TVs, estado      │
 └──────────────────────────────────┘                │ postgres    esquemas public + play        │
          │ vídeo direto do painel (Xtream)           └──────────────────────────────────────────┘
          ▼
   seu painel / provedor
```

- O vídeo **não passa pelo servidor**: a TV toca direto do painel. O servidor só organiza os dados.
- O contrato servidor ⇄ TV (rotas e formatos) está em [`homeserver/docs/play-2.0.md`](homeserver/docs/play-2.0.md).
- A estrutura completa do banco está em [`docs/banco-de-dados.md`](docs/banco-de-dados.md) e [`db/schema.sql`](db/schema.sql).

## Estrutura

| Pasta | Conteúdo |
|---|---|
| `launcher/` | Home (app web `org.webos.freemytv`), protetor de tela e `scripts/build.py` que gera o `.ipk` |
| `player/` | Player de TV (`org.webostv.play`): código em `src/`, servidor simulado e testes em `dev/` |
| `homeserver/` | `compose.yaml`, `.env.example`, código PHP (`casa/`), recomendador (`rec/`), scripts e systemd |
| `tv/` | O que vai na TV com root: helper, scripts de `init.d`, troca da Home (webOS 10 e webOS 4), instalação segura |
| `db/` | Esquema do PostgreSQL (só estrutura) |
| `docs/` | Instalação, banco de dados e prints |

## Requisitos

- **TV LG webOS com root** e Homebrew Channel (testado em webOS 10 e webOS 4.x).
- **Homeserver** Linux x86 com Docker e Docker Compose (testado em Rocky Linux 10, i3 com 8 GB de RAM).
  O recomendador baixa o MovieLens 32M (~1 GB) uma vez.
- **Token do [TMDB](https://www.themoviedb.org/settings/api)** (grátis).
- **Seu painel** compatível com a API Xtream Codes, de uma assinatura legal sua. O projeto não fornece
  nenhum conteúdo, lista ou servidor.
- Para compilar: Node 20+, Python 3, PHP 8.4 (testes) e `ares-cli` ou o empacotador incluso.

## Segurança

- Nenhuma senha, token ou lista vem neste repositório. Tudo fica no `.env` do servidor (modelo em
  [`homeserver/.env.example`](homeserver/.env.example)) e no banco do seu homeserver.
- A API do Player só aceita registro da rede local (e Tailscale) e cada TV precisa ser **aprovada no painel**
  pelo código mostrado na tela (`PLAY_APROVACAO=1`, padrão). Só TV aprovada recebe os dados do painel, que ela
  guarda para tocar os canais quando o servidor cair.
- Não coloque um proxy reverso na frente do `casa-web` sem ajustar a checagem de IP: com proxy, todo pedido
  chega como `127.0.0.1` e conta como rede local.
- `launcher/app/defaults.js`, `.env` e `config.json` estão no `.gitignore`.
- O `helper.py` da TV escuta só em `127.0.0.1`.

## Créditos e avisos

- Home baseada no [FreeMyTV para LG webOS](https://github.com/danielthejoker18/FreeMyTvWebOS) (MIT).
- [hls.js](https://github.com/video-dev/hls.js) (Apache-2.0), fontes Inter e Outfit (OFL), ícones Tabler (MIT),
  logos de serviços do [Simple Icons](https://simpleicons.org) (CC0; as marcas pertencem aos donos).
- Este produto usa a API do TMDB, mas não é endossado nem certificado pelo TMDB. Dados de ranking do JustWatch,
  placares da ESPN, legendas do add-on público do OpenSubtitles para Stremio, clima do Open-Meteo.
- Projeto pessoal, sem relação com a LG. Use só com conteúdo que você tem direito de assistir.

## Licença

[MIT](LICENSE).
