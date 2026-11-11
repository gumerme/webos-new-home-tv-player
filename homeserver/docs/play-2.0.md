# WebOS TV Player 2.0 — contrato (servidor ⇄ TVs)

Fonte de verdade para as três frentes: **worker** (`casa-play`), **API/painel** (`casa-web`) e **app da TV**
(`player/`).
Design system: `launcher/design-system/webos-tv-player/MASTER.md`.

## Decisões fixas

- O homeserver faz o pesado (catálogo, TMDB, JustWatch, ESPN, EPG, recomendação, busca, teste de links,
  legendas). A TV só desenha e toca. O vídeo vai **direto do painel IPTV para a TV**.
- Pode haver mais de uma conta de painel cadastrada (troca/failover manual), uma ativa por vez.
- Perfis por pessoa (sem Infantil). Perfil "Casa" recebe a importação do histórico da v10.
- Top 10 = Top 10 **de cada plataforma** (JustWatch Brasil), séries e filmes separados. Plataformas
  configuráveis no painel; padrão: `nfx` Netflix, `prv` Prime Video, `dnp` Disney+, `mxx` HBO Max,
  `gop` Globoplay (ordem nessa sequência). Título que não está na lista aparece com `na_lista:false`
  (a TV desenha apagado com "Avisar").
- Credenciais da lista são cadastradas só no painel. A TV recebe `GET /api/play/conta` (servidor, usuário,
  senha) e guarda **cópia** para montar links se o homeserver cair; nunca é editada na TV.
- Início (ordem): destaque, continuar, top10 séries, top10 filmes, recomendados, chegou, porque_assistiu
  (até 2), sagas, época (Natal 01/11–06/01), gêneros do gosto (2), minha lista. Seção vazia some.
- Jogos de hoje ficam no Ao vivo. "Suas séries" é submenu de Séries. "Ao vivo agora" não existe no Início.

## Infra

- Postgres 17 (`casa`), esquema **`play`** (migração `sql/007_play.sql`; frente worker usa 008–009,
  frente API usa 010–011 se precisar). Extensões `pg_trgm` e `unaccent`.
- Worker novo: `casa/bin/play-worker.php`, serviço `casa-play` no `compose.yaml` (mesma imagem
  `casa-php:local`, `network_mode: host`, `command: php /app/bin/play-worker.php`). Classes em
  `casa/src/Play/` (namespace `Casa\Play`). HTTP de saída por streams (`file_get_contents` + contexto;
  a imagem não tem ext curl; zlib existe). Sem GD: imagens TMDB servidas nos tamanhos do próprio TMDB.
- Cache em disco: `casa/var/play/` (img/, legendas/, tmp/). Já montado no container (`./casa:/app`).
- Sinais em tempo real: tabela `play.sinal` + `NOTIFY play_sinal, '<id>'`. A API serve SSE.
- Tarefas registram em `play.tarefa` (nome, última, duração, status, mensagem, próxima).

## IPs que mudam (DHCP)

TVs, homeserver e demais aparelhos trocam de IP com frequência. Nada pode depender de IP fixo.

- **TV → homeserver (descoberta):** `GET /api/play/saude` (sem token) responde
  `{"app":"casa-play","versao":"2.0.0","servidor":"homeserver","ip":"<ip do servidor>","porta":8080}`.
  O app tenta, nesta ordem, parando no primeiro que responder com `app=casa-play` (timeout 1,5 s):
  1. endereço salvo (`localStorage play2_servidor`);
  2. `http://homeserver:8080` e `http://homeserver.local:8080`;
  3. IPs já vistos do servidor (`play2_servidor_ips`, últimos 5);
  4. varredura da sub-rede da própria TV (via `webOS` / RTCPeerConnection não existe no Chromium 53: usar
     as sub-redes conhecidas `192.168.1.0/24`, `192.168.0.0/24`, e a do último IP do servidor) com até
     24 requisições em paralelo, `:8080/api/play/saude`;
  5. falhou tudo: tela "Procurando o homeserver…" com tentativa a cada 30 s, botão para digitar o endereço,
     e o app segue com a cópia local (catálogo e conta) para tocar.
  Achou em outro IP ⇒ salva e segue sem pedir nada. O token continua valendo (não depende de IP).
- **homeserver → TV:** cada chamada da TV atualiza `play.tv.ip` com `REMOTE_ADDR`. A TV fica ligada ao
  aparelho do painel (`public.dispositivo`, `tv-1`/`tv-2`) pelo `dispositivo_slug`, escolhido no painel ou
  achado sozinho no registro (IP da chamada ↔ `rede_dispositivo.mac` ↔ `dispositivo.config.mac`). Quando o IP
  da chamada difere de `dispositivo.config.ip`, a API corrige o `config.ip` na hora (além do
  `Rede::sincronizarIps` do casa-worker, que corrige por MAC a cada 60 s). Avisos SSAP, Wake-on-LAN e
  "continuar" (Siri) usam sempre o IP atualizado dessa forma.
- O painel mostra em Player › Listas e TVs o IP atual de cada TV, há quanto tempo foi visto e um aviso para
  reservar IP no roteador (recomendado, não obrigatório).

## Autenticação

- TV: `POST /api/play/tv/registrar` devolve `token` (guardado no localStorage). Toda chamada leva
  `Authorization: Bearer <token>` **ou** `?t=<token>` (EventSource e `<img>` não mandam cabeçalho).
  Token novo nasce `pendente`; admin aprova em Painel › Player › Listas e TVs. Pendente ⇒ 403
  `{"erro":"aguardando aprovação","codigo":"AB12"}` (a TV mostra o código).
- Painel (sessão) também acessa `/api/play/*` (para prévias); `CASA_TOKEN` acessa as rotas de integração
  (`/api/play/continuar`, `/api/play/minhas-series`).
- Imagens (`/api/play/img/*`) não exigem token (só conteúdo público TMDB/painel em cache).

## Formatos comuns

`Cartao` (todo item de fileira):
```json
{ "id": 123, "tipo": "m|s", "nome": "Interestelar", "ano": 2014,
  "poster": "/api/play/img/w342/abc.jpg", "fundo": "/api/play/img/w780/def.jpg",
  "imdb": 8.7, "qualidade": "4k|fhd|hd|sd|null", "na_lista": true,
  "plataformas": [{"codigo":"prv","posicao":3}],
  "progresso": {"pct":46,"restante_s":5460,"temporada":null,"episodio":null,"tv":"TV 2","quando":"2026-11-08T19:10:00-03:00","proximo":false},
  "selo": "NOVO · T3:E3 | Você pediu | Chegou hoje | 4K | null" }
```
`na_lista:false` ⇒ `id` é null e vem `"tmdb": 1234` (para "Avisar quando chegar").
URLs de imagem são relativas ao servidor; a TV prefixa a base.

Erro: HTTP ≠ 2xx com `{"erro":"mensagem"}`.

## Rotas da TV (`/api/play/...`)

| Rota | Retorno |
|---|---|
| `POST tv/registrar` `{nome, modelo, duid, versao, webos}` | `{token, status, codigo}` |
| `GET estado` | `{tv:{id,nome,status}, conta:{vence_em, dias}, catalogo:{versao, atualizado}, avisos_pendentes}` |
| `GET conta` | `{servidor, usuario, senha, formato:{filme:"{s}/movie/{u}/{p}/{id}.{ext}", serie:"{s}/series/{u}/{p}/{id}.{ext}", canal:"{s}/live/{u}/{p}/{id}.m3u8"}}` |
| `GET perfis` / `POST perfis {nome, cor}` | `[{id,nome,cor,inicial}]` |
| `GET inicio?perfil=` | `{destaque:[Cartao+{sinopse,motivo,trailer_youtube}], continuar:[Cartao], top10:{s:[{codigo,nome,itens:[Cartao+{posicao,mov}]}], m:[...]}, recomendados:[], chegou:[], porque:[{titulo,itens:[]}], sagas:[{id,nome,vistos,total,proximo:Cartao,fundo}], epoca:{titulo,itens}|null, generos:[{nome,itens}], minha_lista:[]}` |
| `GET secao?tipo=s\|m&nome=para-voce\|top10\|lancamentos\|sagas\|4k&perfil=` | `{fileiras:[{titulo,itens:[Cartao]}]}` |
| `GET suas-series?perfil=` | `{chegaram:[Cartao+{selo,quando}], proximos:[{data, rotulo:"Amanhã", serie:Cartao, temporada, episodio, aviso_id\|null}]}` |
| `GET categorias?tipo=&perfil=` | `{seu_gosto:[{id,nome,total}], todas:[{id,nome,total}]}` |
| `GET categoria/{id}?tipo=&ordem=vistos\|recentes\|nota\|az&pagina=1&esconder_vistos=0&so4k=0&perfil=` | `{nome,total,pagina,paginas,itens:[Cartao]}` (60 por página) |
| `GET ranking?tipo=s\|m&perfil=` | `{atualizado, plataformas:[{codigo,nome,itens:[Cartao+{posicao,mov:"▲2\|▼1\|=\|NOVO"}]}]}` |
| `GET titulo/{id}?perfil=` | Cartao + `{sinopse, generos[], duracao_min, temporadas, classificacao, diretor, criadores[], elenco:[{tmdb,nome,personagem,foto}], notas:{imdb,imdb_votos,rotten,jw_gostaram}, plataformas[], dias_top10, versoes:[{id,idioma:"dub\|leg",uhd}], versao_escolhida, legenda_pronta, qualidade_testada_em, saga:{id,nome,itens:[Cartao],vistos}, similares:[Cartao], trailer_youtube, minha_lista, nota_usuario, retomar:{versao,temporada,episodio,posicao_s,tv,quando}}` |
| `GET titulo/{id}/temporadas?versao=` | `{temporadas:[{numero, episodios:[{id, numero, nome, sinopse, duracao_s, still, progresso:{pct,terminado}}]}]}` |
| `GET titulo/{id}/apos?perfil=` | `{sugestao:Cartao+{trailer_youtube}, outras:[Cartao]}` (pós-filme) |
| `GET tocar?titulo=&versao=&temporada=&episodio=&perfil=` | `{url, formato:"mp4\|mkv\|hls", inicio_s, duracao_s, abertura:{inicio_s,fim_s}\|null, creditos_s, proximo:{temporada,episodio,nome}\|null, legendas:[{id,idioma,nome,url}], legenda_auto:bool, prefs:{tamanho:"p\|m\|g", atraso_s}}` |
| `POST progresso` `{perfil, titulo, versao, temporada, episodio, posicao_s, duracao_s, evento:"inicio\|tick\|pausa\|fim\|creditos"}` | `{ok}` (tick a cada 15 s; o servidor manda sinal `progresso` para as outras TVs) |
| `POST lista {perfil,titulo,acao:"add\|del"}` · `POST nota {perfil,titulo,valor:1\|-1\|0}` · `POST continuar-ocultar {perfil,titulo}` | `{ok}` |
| `POST versao {perfil,titulo,versao}` | `{ok}` |
| `POST abertura {titulo, inicio_s, fim_s}` | `{ok}` (aprendizado do "Pular abertura", da casa) |
| `GET busca?q=&perfil=` | `{q, corrigido\|null, pessoas:[{tmdb,nome,foto,papel,total}], titulos:[Cartao], fora:[Cartao com na_lista:false], canais:[Canal]}` |
| `GET pessoa/{tmdb}?perfil=` | `{nome, foto, titulos:[Cartao], fora:[Cartao]}` |
| `GET avisos?perfil=` · `POST aviso {perfil,tipo:"jogo\|programa\|episodio\|chegar",alvo:{...},quando?}` · `POST aviso/{id}/cancelar` | lista / `{id}` / `{ok}` |
| `GET legenda/{id}` | texto SRT (UTF-8) |
| `GET legendas?titulo=&temporada=&episodio=` | `[{id,idioma,nome,fonte}]` (busca sob demanda) |
| `POST preferencia {perfil, chave, valor}` · `GET preferencias?perfil=` | `{ok}` / `{chave:valor}` — chaves: `legenda_auto`, `legenda_tamanho`, `legenda_atraso:<titulo>:<arquivo>`, `trailer_destaque`, `esconder_fora_lista` |
| `POST importar {perfil, dados:{...localStorage da v10}}` | `{importados:{historico,lista,notas,aberturas,versoes,favoritos,progresso}}` |

`Canal`: `{id, nome, numero, logo, grupo, favorito, agora:{titulo,inicio,fim,pct}|null, qualidade}`.

### Ao vivo

| Rota | Retorno |
|---|---|
| `GET aovivo/grupos?perfil=` | `[{id:"jogos\|fav\|<cat>", nome, total}]` (jogos só se houver jogo hoje; abre neles) |
| `GET aovivo/jogos` | `[{id, inicio, status:"agora\|depois\|fim", minuto, placar, casa:{nome,sigla,escudo}, fora:{...}, canal:Canal, aviso_id}]` |
| `GET aovivo/canais?grupo=&perfil=` | `[Canal]` |
| `GET aovivo/grade?canais=1,2,3&de=ISO&ate=ISO` | `{canais:[{id, programas:[{id,titulo,inicio,fim,descricao,aviso_id}]}]}` |
| `GET aovivo/tocar/{canal}` | `{url, formato:"hls"}` |
| `POST aovivo/favorito {perfil,canal,acao}` | `{ok}` |

### Sinais (SSE)

`GET /api/play/sinal?t=<token>&perfil=<id>` (`text/event-stream`, fecha em ~80 s; o EventSource reconecta).
Eventos (`event:` + `data:` JSON):
- `progresso` `{titulo, nome, posicao_s, duracao_s, tv, temporada, episodio}` — outra TV parou/assistiu
- `aviso` `{id, tipo, texto, acao:{tipo:"canal\|titulo", id}}` — lembrete (jogo, programa, episódio chegou, chegou na lista, vencimento)
- `atualizado` `{o_que:"catalogo\|top10\|recomendacoes\|epg"}` — a TV recarrega a tela atual sem perder foco
- `ping` a cada 20 s

## Rotas de integração (CASA_TOKEN)

- `GET /api/play/minhas-series?perfil=` — para a Home "WebOS TV" (formato do helper `/series` antigo:
  `[{tmdb,title,season,episode,xs,new,poster}]`).
- `POST /api/play/continuar {tv:"tv-1", perfil?}` — Siri/Atalhos: liga a TV (WoL), abre
  `org.webostv.play` com `{"abrir":"continuar"}` e a TV retoma o último item do perfil.

## Deep link do app (launch params / `webOSRelaunch`)

`{abrir:"titulo", id}` · `{abrir:"tocar", id, temporada, episodio}` · `{abrir:"continuar"}` ·
`{abrir:"canal", id}` · compatível com a v10: `open?type=m|s&tmdb=&xs=&season=&episode=` (contentTarget).

## Avisos (worker)

- Jogo/programa: 5 min antes ⇒ sinal `aviso` para as TVs do perfil **e**, se o Player não estiver aberto,
  comando `notificar` do casa-worker em cada TV ligada (`comando` com `origem='play'`).
- Episódio novo de série seguida / título "avisar quando chegar" que entrou na lista ⇒ aviso + fileira.
- Vencimento da conta: 15, 7 e 1 dia antes (uma vez por dia).

## Mudanças no contrato (API)

Feitas pela frente API/painel (`casa/src/Play/Api`). O app da TV deve seguir estes pontos.

- **Imagens do painel/JustWatch/escudos**: `/api/play/img/painel/<hash>?u=<url em base64url>`. O `<hash>` é um
  HMAC-SHA1 da URL (chave derivada do `CASA_TOKEN`), não um sha1 simples: o servidor só baixa URLs que ele mesmo
  entregou. A TV usa a URL exatamente como veio. Depois do primeiro download o `?u=` não é mais necessário.
- **Início**: todas as chaves vêm sempre (`[]` ou `null` quando vazias; a TV esconde a fileira). Vem também
  `perfil` (id usado). Itens de `porque` trazem `titulo_id` do título de origem.
- **`mov` (Top 10/ranking)**: `null` quando a plataforma não teve coleta no dia anterior (primeiro dia), para não
  marcar tudo como `NOVO`.
- **Cartão fora da lista**: traz `aviso_id` (aviso "chegar" ativo do perfil) ou `null`.
- **`continuar`** (Início/seções): `selo` = `"T3:E3"` ou `"Próximo depois de T3:E3"` (episódio terminado).
- **`tocar`**: `creditos_s` = **duração** dos créditos em segundos contados do fim (regra `creditsLength` da v10;
  a TV compara `posição >= duração_real - creditos_s`). Campos a mais: `versao`, `temporada`, `episodio`, `nome`,
  `nome_episodio` (série), `prefs.atrasos` (`{"<legenda_id>": segundos}`). `prefs.atraso_s` é o da 1ª legenda.
  Chave da preferência de atraso na 2.0: `legenda_atraso:<titulo>:<legenda_id>`.
- **`titulo/{id}/temporadas`** aceita `perfil` (progresso por episódio) e `atualizar=1` (relê o painel); devolve `versao`.
- **Token desconhecido** (TV apagada no painel): 401 `{"erro":"token inválido: registre a TV de novo","registrar":true}`
  ⇒ a TV chama `tv/registrar` de novo. Bloqueada: 403 `{"erro":"TV bloqueada no painel","codigo":"AB12","bloqueada":true}`.
- **`tv/registrar`** devolve também `id`. Mesmo `duid` reaproveita a TV (novo token e código, volta a `pendente`;
  bloqueada continua bloqueada). Limite: 20 registros por hora.
- **`estado`**: a mais `perfil`, `conta.status`, `conta.ativa`, `servidor:{versao,hora}`; `catalogo.versao` = epoch da
  última sincronia do catálogo.
- **`conta`**: só TV aprovada ou admin; traz `vence_em`.
- **`progresso`** devolve `{ok, terminado}`. O sinal `progresso` traz também `evento` e `terminado`.
- **`lista`/`nota`/`versao`** devolvem o estado novo (`minha_lista`, `nota_usuario`, `idioma`). `versao` aceita o id
  da versão ou `"dub"`/`"leg"`.
- **`aviso`**: devolve `{id, quando}` (`repetido:true` se já existia igual). `chegar` de título que já está na lista ⇒
  409 com `titulo`. `jogo`: `alvo:{jogo}`; `programa`: `alvo:{programa}` (id de `aovivo/grade`); `episodio`:
  `alvo:{titulo,temporada,episodio}`; `chegar`: `alvo:{tmdb,tipo,nome}`.
- **`legendas`**: cada item traz `url`.
- **`aovivo/grade`** devolve também `de` e `ate`; no máximo 40 canais e 24 h. `aovivo/jogos` traz `liga`.
- **`suas-series.proximos`** traz `nome` do episódio.
- **`secao`**: `top10` traz `codigo` na fileira; `sagas` traz `saga_id` e a fileira "Continue a saga" com `saga` em cada item.
- **SSE**: cada evento tem `id:`; o `EventSource` reconecta com `Last-Event-ID` e recebe o que perdeu (até 10 min).
  O primeiro evento é `ping` com `{ola:true, tv, perfil}`.
- **`minhas-series`**: `poster` em URL absoluta do TMDB (a Home não passa pelo homeserver) e `id` do título.
- **`continuar` (Siri)**: abre o app com `{"abrir":"continuar","perfil":<id>}` (perfil padrão da TV ligada ao aparelho).
- **Atendido o pedido do app (seção abaixo)**: corpo JSON lido qualquer que seja o Content-Type; `GET titulo/por-tmdb`;
  `tocar` com `tmdb` e `xs` (id do painel da versão tocada); `xt` em Cartão de filme (`{id, ext}`), episódios e Canal
  (`{id}`); `aovivo/tocar` com `canal`; Cartão com `tmdb` e `minha_lista` sempre; Início com `minha_lista` completa e
  `destaque[]` com `rotulo` ("Em alta" se está no Top 10, senão "Para você"), `classificacao`, `duracao_min`, `temporadas`;
  `suas-series.proximos[]` com `data:null` + `nota`; primeiro `ping` com `tv` = nome da TV (`tv_id` à parte); avisos
  `jogo`/`programa` aceitam o `quando` da TV quando o jogo/programa não está no banco. Divergências só do mock: stills
  em `w300` (a API usa `w342`; a TV usa a URL como vier) e `xs` como texto (a API manda número).

## Mudanças no contrato (worker)

Feitas pela frente worker (`casa/src/Play/Dados`, `sql/008_play_worker.sql`).

- **`play.norm()` redefinida na 008** para ser igual ao `Texto::norm` (PHP) e ao `norm()` da v10: além de minúsculas e
  sem acento, tira apóstrofo, `[colchetes]`, `(parênteses)` e as marcas `4k fhd uhd hd sd hdr legendado leg dublado
  dub dual audio nacional cam hdcam`, e apara as pontas. `titulo.nome_norm`, `canal.nome_norm` e `pessoa.nome_norm`
  são gravados com essa regra; a busca deve comparar com `play.norm(q)`.
- **A lista do painel não traz id TMDB** (nem filmes nem séries; `tmdb_painel` fica nulo). A junção das versões é por
  nome normalizado + ano ±1 (com o tmdb, quando houver, como desempate). O `titulo.tmdb_id` vem da tarefa `tmdb`
  (busca por nome+ano; filme sem resultado: `get_vod_info`), do JustWatch (Top 10) e do teste de links.
  Título que o TMDB não conhece: `detalhes = {"sem_tmdb": true}` (tenta de novo em 30 dias).
- **`titulo.detalhes`** (chaves presentes só quando há valor): `nome_tmdb, nome_original, tagline, data, duracao_min,
  temporadas, episodios, status, classificacao, generos_nomes[], keywords[ids], trailer (chave YouTube), nota_tmdb,
  votos_tmdb, popularidade, idioma, elenco[{tmdb,nome,personagem,foto}] (15), diretor[{tmdb,nome}],
  criadores[{tmdb,nome}], colecao{id,nome}, proximo_ep{data,temporada,episodio,nome,duracao_min},
  ultimo_ep{...}`. `titulo.poster`/`fundo` = caminho TMDB (`/abc.jpg`) depois dos detalhes; antes, URL do painel.
- **`titulo.jw`** = `{id, imdb, imdb_votos, rotten, gostaram (%; só com ≥ 30 votos), curtidas, descurtidas}`.
- **`play.top10.poster`** = URL completa do JustWatch em 332 px (`https://images.justwatch.com/poster/.../s332/x.jpg`).
- **`play.recomendacao.dados`**: `{destaque:[5 ids], recomendados:[até 40 ids], porque:[{titulo_id, ids}] (até 2),
  generos:[{tmdb, nome, ids}] (até 4), epoca:{titulo, ids}|null, gosto:{generos:[{tmdb,nome,peso}],
  pessoas:[{tmdb,nome,peso}]}}`. Coluna nova `chave` (impressão digital do que gerou). Perfil sem nenhum like:
  recomendados = em alta no Brasil + Top 10 que estão na lista, sem `porque`/`generos`.
- **Preferência `gosto`** (perfil Casa, semente da 008 com o `PLAY_TASTE` da v10): `{v, titulos:[{tipo,tmdb,painel,nome,w}],
  generos:{id_tmdb:peso}, pessoas:[{tmdb,nome,w}], blockbuster, epoca:{keyword,titulo,de,ate,categoria_painel}}`.
  Perfil sem `epoca` usa o Natal (01/11–06/01). O `GET preferencias` vai mostrar essa chave.
- **Tabelas/colunas novas (008)**: `play.cache (chave, dados, em)` (respostas TMDB/JustWatch, 30 dias),
  `play.canal.epg_em` (cache de 30 min do `Epg::canal`), `play.recomendacao.chave`.
- **`play.jogo`**: `id` = id do evento da ESPN, ou `p<hash>` quando a ESPN não tem o jogo; `casa`/`fora` =
  `{nome (como no painel), sigla, escudo (URL 128 px da ESPN ou null)}`; `placar` = `"2 x 1"` (casa x fora, na ordem
  do painel); `minuto` = texto da ESPN (`"45'+2'"`, `"HT"`) ou `"Fim"`. O worker ajusta o `quando` dos avisos `jogo`
  quando o horário muda. Jogo de hoje cujo canal sumiu do painel é apagado (salvo se tiver aviso ativo).
- **Avisos**: o worker dispara com `texto` = o do aviso ou um padrão ("Novo episódio de X: T2:E5", "Chegou na lista: X",
  "O jogo vai começar: A x B"); `acao` de `programa` vem do `canal_id` do programa. `chegar`: quando o título entra,
  o worker grava `quando = agora` e `alvo.titulo` (id do título). `episodio` criado pelo worker leva `alvo.versao`
  (`dub|leg`). Vencimento: `tipo:"vencimento"`, `perfil_id` nulo (todos), `alvo:{conta, dias, vence_em}`, só entre 9 h e 22 h.
- **Toast na TV (`notificar`)**: vai para cada TV `ativa` ligada a um aparelho online e ligado, quando o app da frente
  (casa-worker) não é `org.webostv.play` **e** a TV não chamou a API nos últimos 60 s (`play.tv.visto_em`; a API deve
  atualizá-lo a cada chamada).
- **Legendas**: `idioma` = `pt-BR` ou `pt`; `fonte` = `opensubtitles` (add-on do Stremio) ou `opensubtitles-api`
  (`url_origem = "os:<file_id>"`); `nome` = "Português (Brasil) · <release>". `play.legenda.arquivo` =
  `var/play/legendas/<id>.srt` (UTF-8). A busca vale 24 h.
- **`qualidade`** do título = `4k` quando há versão 4K ativa; senão a da versão principal (altura do vídeo, marca no
  nome ou taxa de bits do `get_vod_info`: ≥ 12 Mbit/s 4k, ≥ 2,5 fhd, ≥ 1 hd, senão sd); `null` quando não dá para saber.
- **Pastas de cache**: o casa-play roda como root e o casa-web como www-data; `var/play/*` é criada com 0777.
- **Sinal `aviso` (ajuste para o app da TV)**: `{id, tipo, texto, subtexto, acao:{tipo, id, nome}|null}`. `subtexto` =
  "<liga> · <canal> · aviso que você ligou" (jogo), "<canal> · aviso que você ligou" (programa), "Série que você segue"
  (episódio), "Você pediu para avisar" (chegar), "Renove a lista para não ficar sem" (vencimento). `acao.nome` = nome do
  canal (jogo/programa) ou do título. Texto padrão de jogo/programa: "<nome> começa em 5 min". Alvos aceitos como a TV
  cria: `jogo {jogo, canal}`, `programa {canal, programa, titulo (nome do programa)}`, `episodio {titulo, temporada,
  episodio}` + `quando`, `chegar {tmdb, tipo, nome}`. Aviso `episodio` pedido na TV com data futura é antecipado para
  "agora" quando o episódio chega no painel antes. O EPG também busca os canais de `alvo.canal` dos avisos ativos.
- **`recomendacao.dados.epoca`** traz `subtitulo` ("Os mais novos primeiro").
- **`xt` (tocar sem homeserver)**: nada novo no esquema; a API monta de `versao.painel_id/ext` (filme),
  `episodio.ep_id/ext` (episódio) e `canal.painel_id` (canal). `tocar.tmdb`/`xs` = `titulo.tmdb_id` / `versao.painel_id`.

## Mudanças no contrato (app da TV)

Feitas pela frente da TV (`player/`). O servidor simulado `player/dev/mock-server.mjs` já segue isto.
O app já segue as seções "API" e "worker" acima (inclusive `creditos_s` como duração, `aviso_id` no cartão fora da
lista, `prefs.atrasos`, `evento`/`terminado` no sinal, 401 `registrar`, 403 `bloqueada`, `perfil` no deep link).

- **CORS sem preflight** (o app roda em `file://`, origem `null`): a TV manda o token **sempre em `?t=`** (nunca no
  cabeçalho) e o corpo dos POST como JSON com `Content-Type: text/plain;charset=UTF-8`. O servidor deve ler o corpo
  JSON qualquer que seja o Content-Type e responder `Access-Control-Allow-Origin: *` em todo `/api/play/*`
  (inclusive erros, `img/*`, `legenda/*` e o SSE). `OPTIONS` → 204 por garantia.
- **`GET titulo/por-tmdb?tipo=m|s&tmdb=&xs=`** (nova) → `{id, nome, tipo}` ou 404. Usada pelo deep link da v10
  (`open?type=&tmdb=&xs=`), que só traz tmdb/id Xtream.
- **`GET tocar`**: `temporada`/`episodio` são opcionais; sem eles o servidor escolhe (retomada ou próximo episódio;
  série nova = T1:E1). Pedido a mais na resposta: `tmdb` e `xs` (id da série no painel) — a TV ainda chama o helper
  antigo `127.0.0.1:3911/series/set?tmdb=&xs=…` ao começar um episódio (a Home antiga lê de lá).
- **Cópia local para tocar sem homeserver**: campo opcional `xt:{id, ext}` (stream do painel) em `Cartao` de filme, em
  cada episódio de `titulo/{id}/temporadas` e em `Canal` (`xt:{id}`). A TV só usa com a cópia de `GET conta`
  (`formato.filme|serie|canal`) quando a API não responde. Sem `xt`, sem reprodução offline daquele item.
- **`GET aovivo/tocar/{canal}`**: incluir `canal: Canal` (deep link `{abrir:"canal"}` e "Ir ao canal" do aviso chegam
  só com o id).
- **Sinal `aviso`**: `acao` pode trazer `nome` (rótulo do canal/título) e o evento pode trazer `subtexto` (2ª linha,
  ex.: "Premiere · aviso que você ligou"). O primeiro `ping {ola, tv}` define o nome desta TV; o `tv` do sinal
  `progresso` precisa ser igual a `estado.tv.nome` (a TV ignora o próprio progresso).
- **"Continuar aqui"**: oferecido quando chega `progresso` com `evento:"pausa"`, ou após 40 s sem novo `tick` da
  outra TV; nunca com `terminado`/`fim`. Ao abrir o app, também se `continuar[0].progresso.tv` for outra TV e
  `quando` tiver menos de 20 min.
- **Início**: `destaque[]` pode trazer `rotulo` ("Para você"/"Em alta"), `classificacao`, `duracao_min`, `temporadas`;
  `epoca` pode trazer `subtitulo`. `porque[].titulo` pode ser o nome do título de origem ou a frase inteira
  ("Porque você assistiu X"). **`minha_lista` vem completa** (a aba Minha lista usa essa chave; não há rota própria).
  `Cartao.minha_lista` (bool) em todo cartão, não só no `titulo/{id}`.
- **`suas-series.proximos[]`**: `data` pode ser `null` (sem data exata); `nota` opcional ("fim da temporada · sem data").
- **`POST perfis`** pode devolver a lista `[Perfil]` (a TV aceita também `{id}` e relê `GET perfis`).
- **Avisos pedidos pela TV**: `chegar {tmdb,tipo,nome}`; `episodio {titulo,temporada,episodio}` + `quando` (data);
  `jogo {jogo, canal}` + `quando`; `programa {canal, programa, titulo}` + `quando`. Campos a mais são ignoráveis.
  `GET avisos` precisa de `[{id, tipo, alvo}]` para a TV marcar o que já está ligado.
- **`POST progresso` `pausa`** também é enviado ao sair do player (Voltar/Home) e ao trocar de episódio.
- **`tv/registrar`**: `nome` = "TV <modelName>" (ou "Navegador (teste)"), `duid` = LGUDID (luna `deviceid/getIDs`)
  ou aleatório guardado, `webos` = `platformVersion`. Renomear no painel.
- **Descoberta**: a TV guarda a `porta` e o `ip` de `/saude` (últimos 5 IPs) — mantenha `ip` = IP da LAN, não 127.0.0.1.

## Mudanças no contrato (08/11/2026, noite — app 2.0.1)

- **Sem aprovação de TV**: `tv/registrar` só aceita IP de rede de casa/Tailscale (`Tvs::ipLocal`; fora = 403) e já
  devolve `status:"ativa"`. Linha antiga `pendente` vale como ativa (`Acesso::barreira`). Bloqueada continua bloqueada.
- **Sem escolha de perfil na TV**: o app usa `estado.perfil` (padrão da TV), senão o perfil "Casa". As rotas de perfis
  continuam no servidor, sem uso pela TV.
- **`inicio.top_brasil`**: `{atualizado, itens:[Cartao+{posicao}]}` ou null — ranking geral do JustWatch Brasil (todas as
  plataformas, filmes e séries juntos, 20). Calculado pela tarefa `top10` do worker em `play.cache` chave `top:brasil`.
- **Ordem do Início na TV**: destaque, Continuar, Recomendados, Top Brasil, Top 10 séries, Top 10 filmes, Chegou, ...
- **Ranking**: Séries/Filmes viram botões focáveis (zona `rk-tipo`, ▲ da grade); a tela entra na grade (`entry`).
- **Atualização do app**: `GET app/versao`, `app/pacote/{arquivo}`, `app/atualizador[?init=1]` (públicas, ver
  `docs/instalacao.md`, seção 3).
- **Visual**: volta à família azul da Home/v10 (`--accent #0a84ff`, fundo `#0a0e1a` com gradiente `#171d33`);
  vermelho `--live #e5484d` só para AO VIVO/jogo ao vivo/linha do agora.

## Mudanças no contrato (08/11/2026, noite — app 2.0.2)

- **Categorias pelo JustWatch**: `GET categorias` devolve em `todas` só Lançamentos/4K/Cinema do painel (id número) e os
  gêneros do JustWatch com id texto `"jw:<shortName>"` (jw:act, jw:cmy, jw:drm...); `seu_gosto` = gêneros JustWatch mais
  fortes do perfil. `GET categoria/{id}` aceita os dois (`jw:cmy` ou `jw%3Acmy`). Classificação: `play.titulo.jw->'g'`
  (tarefa `generos` + toda consulta ao JustWatch); sem ela, gêneros TMDB traduzidos (`Dados\Generos::DE_TMDB`).
- **Fileiras "<gênero> para você"** (`inicio.generos`): gêneros JustWatch do perfil, catálogo inteiro do gênero, nota pelo
  gosto × qualidade JustWatch (recomendador `r2`). Formato igual: `{nome, itens}`.
- **Ao vivo**: grupos em lista vertical à esquerda (zona `grupos`), conteúdo à direita.

## Mudanças no contrato (08/11/2026, 21:50)

- **`POST importar` desligado** (`Importar::DESLIGADA`): responde `{importados:null, bloqueado:true}` sem gravar.
- **Favoritos do Ao vivo sincronizados na hora**: `POST aovivo/favorito` manda `atualizado {o_que:"favoritos"}` às outras TVs
  do perfil (a que mudou fica de fora); a tela aberta recarrega mantendo o foco.
- **Correção 08/11 22h**: a rota passou a ser `categoria/{cat}` (texto) — `{id}` só aceitava número e todo `jw:*` dava 404.
- **"Para você" por tipo**: recomendação guarda `por_tipo:{m:{recomendados, generos}, s:{...}}` (recomendador r4); a seção
  de Filmes/Séries usa a lista do tipo (40, completada pelas fileiras de gênero) e até 6 fileiras "<gênero> para você".

## Mudanças no contrato (08/11/2026, 23h — app 2.0.4)

- **Gosto da Netflix**: `docker exec casa-play php bin/play.php netflix-importar var/play/netflix/<arquivo>.csv [--perfil N]`
  lê a "Atividade de visualização" (Title,Date), agrupa em obras, identifica no TMDB e grava `preferencia 'netflix'`
  (reimportar substitui). Recomendador r5 usa como sinais (meia-vida 1 ano; séries por episódios/maratona; filme revisto).
  `inicio.porque[].titulo_id` pode ser null (título da Netflix fora da lista): só o nome.
- **Plataformas**: + Looke (`lke`), Paramount+ (`pmp`), Crunchyroll (`cru`) — 8 no Top 10. Ranking com mais de 5
  plataformas rola na horizontal (5 colunas visíveis).

## Mudanças no contrato (09/11/2026 — filtragem colaborativa, recomendador r6)

- **Peso da Netflix** (ponto de partida): 0,5 × meia-vida de 120 dias (piso 0,075); nunca supera um filme visto até o fim
  hoje no Player.
- **casa-rec** (novo container Python, `../rec/`): MovieLens 32M (GroupLens; uso pessoal, sem redistribuir) + biblioteca
  `implicit` (ALS, 128 fatores, só avaliações 3,5+, bm25). Treina uma vez (`rec/dados/modelo.npz`) e refaz 1x/dia (ou com
  `play.cache 'cf:pedido'`) os 40 vizinhos de cada filme do catálogo e da Netflix importada, só entre filmes do catálogo:
  `play.cache 'cf:ml:<tmdb>' = {v:[[tmdb, cosseno]...]}`; meta em `'cf:ml'`.
- **Trakt** (`Dados\Trakt`, tarefa `trakt` a cada 15 min): relacionados das séries e dos filmes fora do MovieLens em
  `'cf:trakt:<s|m>:<tmdb>' = {v:[[tmdb, tipo]...]}`, refeito após 60 dias. Exige `TRAKT_CLIENT_ID` no `.env` (sem ela a
  tarefa não faz nada). A limpeza não apaga `cf:*`.
- **Recomendador r6**: `Dados\Colaborativo` vira fonte de candidatos por sinal (MovieLens 1,25 × peso do sinal, Trakt 0,8;
  obras extras da Netflix com metade e sem "porque"); a chave da recomendação muda quando os vizinhos são refeitos.
- CLI: `play.php colaborativo [--refazer]`; `netflix-importar` também pede vizinhos novos e o Trakt.
- **Tag Genome** (casa-rec, `rec.py genoma`): MovieLens 25M (só a genome-scores: 13,8 mil filmes × 1.128 características),
  centrada e normalizada; vizinhos pelo "clima" em `'cf:tg:<tmdb>'` (meta `'cf:tg'`), peso 0,7.
- **AniList** (`Dados\AniList`, tarefa `anilist` a cada 15 min, sem chave, 30 pedidos/min, 10 animes por pedido): ponte
  AniList↔TMDB pelo `anime-list-full.json` do Fribb/anime-lists (var/play/anime/, renovado 1x/semana); até 4 temporadas
  somadas por série; `'cf:anilist:<s|m>:<tmdb>' = {v:[[tmdb, tipo]...]}`, peso 0,9. O mapa traz também `simkl_id`.

## Mudanças no contrato (09/11/2026 — Home das TVs)

- **`GET launcher?v=&fundo=w780|w1280&poster=w185|w342`** (público, só rede de casa; `Api\Launcher`): tudo da Home
  "WebOS TV" numa chamada (~15 KB, 6 KB com gzip): `{v, destaque:{fileira,i}, continuar, jogos, recomendados,
  top_brasil, aviso, clima}`. Cartão enxuto `{id,tipo,nome,ano,imdb,poster,fundo,sinopse,progresso?,selo?,posicao?,
  episodio_novo?}`. Com `v` igual à versão atual responde só `{v, igual:true}` (a TV pergunta a cada minuto com a tela
  à vista). Destaque em repouso: jogo ao vivo > Continuar > recomendação.
- **Tarefa `clima`** (30 min, `Dados\Clima`): Open-Meteo da cidade em CLIMA_CIDADE/CLIMA_LAT/CLIMA_LON (.env) em `play.cache 'clima'`
  `{cidade,temp,ceu,icone,por_do_sol,dias:[{data,max,min,ceu,icone}]}`; a Home não chama mais ipapi/Open-Meteo.
- `Inicio::cartoesComSelo` virou público; `AoVivo::jogosDo(perfil)` serve o Ao vivo e a Home.

## Mudanças no contrato (09/11/2026 noite — trailer inteiro, último canal)

- **Trailer do destaque inteiro (Player 2.0.7).** O iframe continua sendo `HELPER/trailer?v=<youtube>[&leg=pt]`
  (helper local da TV, `http://127.0.0.1:3911`). A página do helper avisa a janela de cima com
  `parent.postMessage({trailer: 'tocando' | 'fim' | 'erro'}, '*')` (o Player também aceita o mesmo objeto como texto
  JSON e ignora qualquer mensagem sem `trailer`). No destaque do Início: `'fim'` passa para o próximo destaque (que, com
  trailer, começa sozinho 0,6 s depois); `'erro'` passa depois de 2 s; `'tocando'` rearma a trava de segurança de
  5 min (sem `'fim'`, passa sozinho). Acabou o corte de 60 s. O trailer para ao trocar de destaque (◀ ▶), rolar a tela,
  abrir algo por cima (detalhe, player), tocar vídeo ou sair do Início; aí o giro de 12 s volta a contar. O cartão de
  aviso no canto não o interrompe. No pós-filme (`player.js`), `'fim'`/`'erro'` só deixam o trailer parado.
  Código: `player/src/js/trailer.js` (um ouvinte de `message` para todos os iframes de trailer).
- **Último canal no "Continuar assistindo".** `GET aovivo/tocar/{canal}` (tela cheia ou prévia) grava
  `play.preferencia (perfil, 'ultimo_canal') = {id, em}` (`AoVivo::lembrarCanal`; falha ali nunca impede de tocar).
  `GET inicio` e `GET launcher` encaixam esse canal no `continuar` (`Inicio::encaixarCanal`, puro): só se visto nas
  últimas 24 h e o canal ainda existe e está ativo; em **1º** se for mais recente que o 1º título, senão em **2º**.
  Os títulos do `continuar` passam a trazer `quando` (hora do progresso, `play.progresso.atualizado_em`).
  - Player (`GET inicio`): `{tipo:'canal', id, nome, logo, numero, agora:{titulo,inicio,fim,pct}|null, quando, qualidade, xt}`.
    OK abre o canal em tela cheia; Verde tira do Continuar com `POST preferencia {chave:'ultimo_canal', valor:null}`
    (apaga; volta quando tocar um canal de novo). Valor diferente de `null` para `ultimo_canal` é 422: só o servidor grava.
  - Home (`GET launcher`): `{tipo:'canal', id, nome, numero, logo, agora:{titulo,inicio,fim}|null, quando}`, logo como
    URL de imagem do homeserver (`/api/play/img/...`). Sem `pct` de propósito: a Home calcula pelo relógio e o `v` não
    muda a cada minuto. OK abre `abrir?canal=ID&nome=`.
  - O `destaque` da Home em repouso pode cair no cartão do canal (é o `continuar[0]` quando ele é o mais recente).

## Mudanças no contrato (09/11/2026 noite — destaque em tela cheia, gostei = visto)

- **Destaque do Início em tela cheia (Player 2.0.9).** `.hero` 1920x1080: arte (`.bd`) e trailer (`.bd-tv iframe`)
  ocupam a tela toda atrás do cabeçalho; sombra `.bd-shade` 1080 px (preto à esquerda até 64 %, fundo `--bg` embaixo
  até 48 %); texto (`.htx`) a 150 px do pé. As fileiras começam abaixo da tela: parado no destaque nenhuma aparece;
  ▼ vai ao Continuar e a tela rola até ele (âncora 140; o trailer para ao rolar, como antes); ▲ volta ao destaque e ao
  topo. Sem destaque (`.rows.nohero`) nada muda. Sem mudança de API.
- **Gostei / Não gostei no destaque.** Botões do destaque: Assistir · Mais informações · Minha lista · **Gostei** ·
  **Não gostei** (mesmo visual e regra do Detalhe: apertar de novo o mesmo desfaz com `valor:0`). Usa a rota que já
  existia, `POST nota {perfil,titulo,valor:1|-1|0}`. Ao marcar: toast ("Marcado como visto · vamos recomendar
  parecidos" / "Não vamos recomendar parecidos") e, 1,5 s depois, o próximo destaque (desfazer dentro desse tempo
  não passa). O Detalhe usa os mesmos textos (`A.rateTitle`). Ao recarregar o Início (SSE), o destaque fica no mesmo
  título se ele ainda está na lista.
- **Gostei = já vi (servidor).** Nota 1 conta como VISTO na leitura, sem gravar histórico: `Base::VISTOS` (subconsulta
  = histórico terminado ∪ progresso terminado ∪ `play.nota valor > 0`; parâmetros `Base::vistosPar(perfil)`; regra pura
  `Base::visto(terminado, nota)`). Série com Gostei = série vista (não marca episódios). Onde vale:
  - `GET categoria/{id}?esconder_vistos=1`: esconde também os com Gostei;
  - sagas do Início/Filmes ("você viu X de Y", próximo da saga) e `saga.vistos` do `GET titulo/{id}`;
  - `GET titulo/{id}/apos` (pós-filme): avaliados (Gostei ou Não gostei) nunca sugeridos;
  - "Continuar": **filme** com Gostei sai (série fica: o Continuar dela é o próximo episódio);
  - "Porque você assistiu X": um Gostei sem histórico também pode ser a origem (recomendador; antes só histórico);
  - destaque, Recomendados, "porque", gêneros, época, `por_tipo` (seções "Para você" e "Para você em 4K"), Home das TVs
    (`launcher`): `Inicio::rec` tira na hora os vistos e avaliados (`Base::foraDasSugestoes` + `Inicio::semVistos`,
    puro), antes mesmo do recálculo; o destaque de reserva (perfil sem recomendação, Top 10) também.
  - O recomendador já tirava os avaliados (contam como "começados"); "Não gostei" continua sinal negativo.
  - Não há selo "visto" nos cartões (o `Cartao` não tem esse campo); Top 10/Top Brasil são ranking e não escondem vistos.
- **Recálculo na hora.** `POST nota` chama `Tarefas::forcar('recomendacoes')` (NOTIFY `play_tarefa`): com o worker livre,
  o recálculo sai em ~8 s (medido no homeserver) e manda `atualizado {o_que:'recomendacoes'}` ao perfil; a TV recarrega o
  Início. Novo `Tarefas::URGENTES = ['recomendacoes']`: quando forçada, roda também dentro de `ceder()` das tarefas
  longas (Trakt, catálogo, TMDB, gêneros), sem esperar elas acabarem.
