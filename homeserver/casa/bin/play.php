<?php
declare(strict_types=1);

/**
 * Linha de comando do Player 2.0 (dentro do container):
 *   docker exec -i casa-play php bin/play.php conta-adicionar --nome "Lista" --servidor http://painel.exemplo --usuario fulano   (senha pelo STDIN)
 *   docker exec casa-play php bin/play.php conta-testar [--id N]
 *   docker exec casa-play php bin/play.php tarefa <nome> [--agora]
 *   docker exec casa-play php bin/play.php status
 * Senha e usuário nunca são impressos.
 */

use Casa\Db;
use Casa\Play\Api\App;
use Casa\Play\Dados\Banco;
use Casa\Play\Dados\Http;
use Casa\Play\Dados\Tarefas;
use Casa\Play\Dados\Xtream;

require dirname(__DIR__) . '/src/autoload.php';
ini_set('memory_limit', getenv('PLAY_MEMORIA') ?: '1024M');

function opcao(array $args, string $nome): ?string
{
    $i = array_search("--$nome", $args, true);
    return $i !== false && isset($args[$i + 1]) ? (string) $args[$i + 1] : null;
}

function sair(string $msg, int $codigo = 1): never
{
    fwrite($codigo ? STDERR : STDOUT, $msg . "\n");
    exit($codigo);
}

/** Lê a senha do STDIN (sem eco quando é um terminal). */
function lerSenha(): string
{
    $tty = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) {
        fwrite(STDERR, 'Senha da lista: ');
        shell_exec('stty -echo 2>/dev/null');
    }
    $s = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo 2>/dev/null');
        fwrite(STDERR, "\n");
    }
    return $s;
}

function testar(array $conta): void
{
    $i = Xtream::informacoes($conta);
    Db::pdo()->prepare('update play.conta set status = ?, vence_em = to_timestamp(?), info = cast(? as jsonb), latencia_ms = ?, ultima_resposta_em = now() where id = ?')
        ->execute([$i['status'], $i['vence_em'], Banco::json($i['info']), $i['ms'], $conta['id']]);
    printf("conta %d (%s): status %s, vence %s, %d ms, conexões no painel: %s\n", $conta['id'], $conta['nome'], $i['status'] ?? '?',
        $i['vence_em'] ? date('d/m/Y H:i', $i['vence_em']) : 'sem data', $i['ms'], $i['info']['usuario']['max_connections'] ?? '?');
}

$args = array_slice($argv, 1);
$cmd = $args[0] ?? '';
try {
    switch ($cmd) {
        case 'conta-adicionar':
            $nome = opcao($args, 'nome') ?? 'Lista';
            $servidor = rtrim((string) opcao($args, 'servidor'), '/');
            $usuario = (string) opcao($args, 'usuario');
            if (!preg_match('#^https?://[^\s/]+(:\d+)?(/\S*)?$#', $servidor) || $usuario === '') {
                sair('uso: play.php conta-adicionar --nome "Lista" --servidor http://painel:porta --usuario USUARIO   (senha pelo STDIN)');
            }
            $senha = lerSenha();
            if ($senha === '') {
                sair('senha vazia');
            }
            Http::segredo($usuario);
            Http::segredo($senha);
            $pdo = Db::pdo();
            $pdo->beginTransaction();
            $pdo->exec('update play.conta set ativa = false where ativa');
            $st = $pdo->prepare('insert into play.conta (nome, servidor, usuario, senha, ativa) values (?, ?, ?, ?, true) returning id');
            $st->execute([$nome, $servidor, $usuario, $senha]);
            $id = (int) $st->fetchColumn();
            $pdo->commit();
            echo "conta $id cadastrada e ativa (as outras ficaram inativas)\n";
            try {
                testar(Xtream::conta($id));
            } catch (Throwable $e) {
                echo 'aviso: o teste falhou: ' . Http::mascarar($e->getMessage()) . "\n";
            }
            Tarefas::forcar('catalogo');
            echo "catálogo pedido ao casa-play (acompanhe: docker logs -f casa-play)\n";
            break;

        case 'conta-testar':
            $id = opcao($args, 'id');
            $conta = $id !== null ? Xtream::conta((int) $id) : Xtream::contaAtiva();
            if (!$conta) {
                sair($id !== null ? "conta $id não existe" : 'nenhuma conta ativa');
            }
            testar($conta);
            break;

        case 'tarefa':
            $nome = $args[1] ?? '';
            if (!Tarefas::existe($nome)) {
                sair('tarefas: ' . implode(', ', array_keys(Tarefas::LISTA)));
            }
            if (in_array('--agora', $args, true)) {
                // roda aqui mesmo, como se o painel tivesse pedido (links fora da janela roda um lote)
                Tarefas::garantir();
                $r = Tarefas::executar($nome, true);
                echo "{$r['status']}: {$r['msg']} ({$r['ms']} ms)\n";
                exit($r['status'] === 'ok' ? 0 : 1);
            }
            Tarefas::forcar($nome);
            echo "tarefa $nome pedida ao casa-play\n";
            break;

        case 'status':
            $pdo = Db::pdo();
            $c = $pdo->query("select id, nome, status, vence_em, latencia_ms, ultima_resposta_em from play.conta where ativa")->fetch();
            echo $c ? sprintf("conta ativa: %d %s — %s, vence %s, %s ms, última resposta %s\n", $c['id'], $c['nome'], $c['status'] ?? '?',
                $c['vence_em'] ?? '-', $c['latencia_ms'] ?? '-', $c['ultima_resposta_em'] ?? '-') : "nenhuma conta ativa\n";
            $n = $pdo->query("select
                    (select count(*) from play.titulo where ativo and tipo = 'm') as filmes,
                    (select count(*) from play.titulo where ativo and tipo = 's') as series,
                    (select count(*) from play.versao where ativo) as versoes,
                    (select count(*) from play.canal where ativo) as canais,
                    (select count(*) from play.titulo where ativo and detalhes_em is null) as sem_tmdb,
                    (select count(*) from play.titulo where ativo and link_ok = false) as quebrados,
                    (select max(coletado_em) from play.top10) as top10,
                    (select count(*) from play.jogo where data = current_date) as jogos,
                    (select count(*) from play.aviso where status = 'ativo') as avisos")->fetch();
            printf("títulos: %d filmes, %d séries (%d versões) · %d canais · %d sem detalhes TMDB · %d links quebrados\n",
                $n['filmes'], $n['series'], $n['versoes'], $n['canais'], $n['sem_tmdb'], $n['quebrados']);
            printf("top10: %s · jogos hoje: %d · avisos ativos: %d\n\n", $n['top10'] ?? 'nunca', $n['jogos'], $n['avisos']);
            printf("%-14s %-7s %-19s %-19s %8s  %s\n", 'tarefa', 'status', 'última', 'próxima', 'duração', 'mensagem');
            foreach (Tarefas::listar() as $t) {
                printf("%-14s %-7s %-19s %-19s %8s  %s\n", $t['nome'] . (Banco::bool($t['forcar']) ? '*' : ''), $t['status'] ?? '-',
                    $t['ultima_em'] ? substr((string) $t['ultima_em'], 0, 19) : '-', $t['proxima_em'] ? substr((string) $t['proxima_em'], 0, 19) : '-',
                    $t['duracao_ms'] !== null ? round($t['duracao_ms'] / 1000, 1) . ' s' : '-', mb_substr((string) $t['mensagem'], 0, 90));
            }
            break;

        case 'netflix-importar':
            // play.php netflix-importar var/play/netflix/NetflixViewingHistory.csv [--perfil N]
            $arq = (string) ($args[1] ?? '');
            if ($arq === '' || !is_file($arq)) {
                sair('uso: play.php netflix-importar <NetflixViewingHistory.csv> [--perfil N]');
            }
            $perfilId = (int) (opcao($args, 'perfil') ?? Db::pdo()->query('select min(id) from play.perfil')->fetchColumn());
            $r = Casa\Play\Dados\Netflix::importar($perfilId, (string) file_get_contents($arq),
                fn($i, $n) => fwrite(STDERR, "\ridentificando no TMDB: $i de $n"));
            fwrite(STDERR, "\n");
            printf("perfil %d: %d linhas, %d obras (%d séries), %d identificadas no TMDB\n", $perfilId, $r['linhas'], $r['obras'], $r['series'], $r['achadas']);
            if ($r['nao_achadas']) {
                echo 'não identificadas (' . count($r['nao_achadas']) . '): ' . implode(' | ', array_slice($r['nao_achadas'], 0, 40)) . "\n";
            }
            Banco::guardar('cf:pedido', ['em' => date('c')]);      // casa-rec refaz os vizinhos (obras novas da Netflix)
            Tarefas::forcar('trakt');
            Tarefas::forcar('anilist');
            Tarefas::forcar('recomendacoes');
            echo "recomendações pedidas ao casa-play\n";
            break;

        case 'colaborativo':
            // play.php colaborativo [--refazer]  (estado do MovieLens/Trakt; --refazer pede os vizinhos de novo ao casa-rec)
            if (in_array('--refazer', $args, true)) {
                Banco::guardar('cf:pedido', ['em' => date('c')]);
                echo "vizinhos pedidos ao casa-rec (até 5 min)\n";
            }
            $e = Casa\Play\Dados\Colaborativo::estado();
            $m = $e['movielens'];
            echo $m ? sprintf("MovieLens: modelo de %s (%s pessoas, %s filmes, %s gostos); vizinhos de %s: %d filmes; catálogo coberto %d de %d\n",
                    substr((string) $m['treinado_em'], 0, 16), number_format($m['pessoas'], 0, ',', '.'), number_format($m['filmes'], 0, ',', '.'),
                    number_format($m['gostos'], 0, ',', '.'), substr((string) $m['vizinhos_em'], 0, 16), $m['origens'], $m['catalogo'], $m['catalogo_total'])
                : "MovieLens: ainda não (acompanhe: docker logs -f casa-rec)\n";
            printf("Trakt: %s; %d títulos consultados, %d com relacionados\n", $e['trakt_chave'] ? 'chave ok' : 'SEM TRAKT_CLIENT_ID no .env',
                $e['trakt'], $e['trakt_com']);
            $g = $e['genoma'];
            echo $g ? sprintf("Tag Genome: %d filmes × %d características; vizinhos: %d filmes; catálogo coberto %d de %d\n", $g['filmes'],
                    $g['caracteristicas'], $g['origens'], $g['catalogo'], $g['catalogo_total']) : "Tag Genome: ainda não\n";
            printf("AniList: %d animes consultados, %d com recomendações\n", $e['anilist'], $e['anilist_com']);
            break;

        case 'app-publicar':
            // play.php app-publicar <arquivo.ipk> [--notas "..."]  (caminho relativo a /app ou absoluto)
            $ipk = (string) ($args[1] ?? '');
            if ($ipk === '' || !is_file($ipk)) {
                sair('uso: play.php app-publicar var/play/app/entrada/<pacote>.ipk [--notas "..."]  (ou o nome de uma versão guardada)');
            }
            $a = App::publicar($ipk, (string) (opcao($args, 'notas') ?? ''));
            printf("publicada %s (%s, %d bytes, md5 %s); as TVs instalam em até 5 min, com o Player fechado\n",
                $a['versao'], $a['arquivo'], $a['tamanho'], $a['md5']);
            break;

        case 'app-status':
            $a = App::atual();
            echo $a ? "publicada: {$a['versao']} ({$a['arquivo']}, {$a['publicado_em']})" . ($a['notas'] ? " — {$a['notas']}" : '') . "\n" : "nada publicado\n";
            echo 'guardadas: ' . (implode(', ', App::guardadas()) ?: '-') . "\n";
            echo 'atualizador md5: ' . (App::md5Atualizador() ?? '-') . "\n";
            foreach (App::tvs() as $ip => $t) {
                printf("TV %-15s versão %-8s atualizador %s  %s%s\n", $ip, $t['versao'], substr((string) $t['atualizador'], 0, 8),
                    $t['em'], $t['atualizador'] !== App::md5Atualizador() ? '  (atualizador antigo)' : '');
            }
            break;

        default:
            sair("comandos: conta-adicionar --nome N --servidor URL --usuario U (senha no STDIN) | conta-testar [--id N] | tarefa <nome> [--agora] | status | app-publicar <ipk> [--notas T] | app-status | netflix-importar <csv> | colaborativo [--refazer]");
    }
} catch (Throwable $e) {
    sair('erro: ' . Http::mascarar($e->getMessage()));
}
