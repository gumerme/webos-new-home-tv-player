<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

/**
 * Normalização de nomes do painel (porte fiel do `fold/norm/cleanName/isLeg/isUhd` da v10).
 * `norm()` é igual ao `play.norm()` do banco (redefinido na 008 com as mesmas regras): minúsculas,
 * sem acento, sem apóstrofo, sem [colchetes] e (parênteses), sem as marcas de versão (4k, hd, dub,
 * leg...), só letras e números separados por um espaço.
 */
final class Texto
{
    /** Marcas de versão que não fazem parte do nome (TAGS da v10). */
    private const MARCAS = '/\b(4k|fhd|uhd|hd|sd|hdr|legendado|leg|dublado|dub|dual|audio|nacional|cam|hdcam)\b/';

    /** Categorias que não são gênero (NOT_GENRES da v10; "legendadas" também, como vem nas séries). */
    public const NAO_GENERO = '/mais assistidos|lancamentos|lançamentos|4k|legendad[oa]s|cinema|dublagem|reels|shorts/iu';

    /** Ordem padrão das fileiras de gênero antes do gosto do perfil (GENRE_ORDER da v10). */
    public const ORDEM_GENEROS = ['acao', 'comedia', 'terror', 'drama', 'animacao', 'ficcao', 'suspense', 'aventura',
        'romance', 'crime', 'nacionais', 'familia', 'fantasia', 'misterio', 'documentario'];

    /** O painel escreve alguns nomes de categoria sem acento (ACCENTS da v10). */
    private const ACENTOS = ['acao' => 'Ação', 'comedia' => 'Comédia', 'animacao' => 'Animação', 'documentario' => 'Documentário',
        'documentarios' => 'Documentários', 'familia' => 'Família', 'misterio' => 'Mistério', 'historia' => 'História',
        'musica' => 'Música', 'lancamentos' => 'Lançamentos', 'noticias' => 'Notícias'];

    /** Sem intl na imagem: tabela própria no lugar do NFD (cobre o latim que aparece no painel). */
    private const SEM_ACENTO = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'į' => 'i', 'ı' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ō' => 'o', 'ő' => 'o', 'ø' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ñ' => 'n', 'ń' => 'n', 'ň' => 'n', 'ý' => 'y', 'ÿ' => 'y',
        'ś' => 's', 'š' => 's', 'ş' => 's', 'ș' => 's', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z', 'ř' => 'r', 'ď' => 'd',
        'ť' => 't', 'ț' => 't', 'ğ' => 'g', 'ł' => 'l', 'đ' => 'd', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'þ' => 'th',
    ];

    /** Minúsculas e sem acento (fold da v10). */
    public static function fold(?string $s): string
    {
        $s = mb_strtolower((string) $s, 'UTF-8');
        $s = strtr($s, self::SEM_ACENTO);
        // marcas combinantes soltas (texto que já veio decomposto)
        return preg_replace('/\p{Mn}+/u', '', $s) ?? $s;
    }

    public static function norm(?string $s): string
    {
        $s = self::fold($s);
        $s = preg_replace("/['’`]/u", '', $s) ?? $s;
        $s = preg_replace('/\[[^\]]*\]|\([^)]*\)/', ' ', $s) ?? $s;
        $s = preg_replace(self::MARCAS, ' ', $s) ?? $s;
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
        return trim($s);
    }

    /** Ano do nome "(2014)" ou do campo do painel (yearOf da v10). 0 = sem ano. */
    public static function ano(?string $nome, mixed $ano = null): int
    {
        $a = (int) substr(trim((string) $ano), 0, 4);
        if ($a >= 1900 && $a <= 2100) {
            return $a;
        }
        return preg_match('/\((\d{4})\)/', (string) $nome, $m) ? (int) $m[1] : 0;
    }

    /** Nome para exibir: sem [L], [4K], [DV][HDR], (ano) (cleanName da v10) e sem "4K"/"UHD" soltos no fim. */
    public static function limparNome(?string $nome): string
    {
        $s = preg_replace('/\s*(\[[^\]]*\]|\(\d{4}\))\s*/u', ' ', (string) $nome) ?? (string) $nome;
        $s = preg_replace('/(\s+[-–|]?\s*\b(4k|uhd)\b)+\s*$/iu', '', $s) ?? $s;
        return trim(preg_replace('/\s{2,}/u', ' ', $s) ?? $s);
    }

    /** Entrada legendada: o painel marca "Nome [L]". */
    public static function ehLegendado(?string $nome): bool
    {
        return str_contains((string) $nome, '[L]');
    }

    /** Cópia 4K: "4K", "UHD", "[HDR]", "[DV]". */
    public static function ehUhd(?string $nome): bool
    {
        return (bool) preg_match('/\b(4k|uhd)\b|\[(hdr|dv)\]/i', (string) $nome);
    }

    /** Nome em alfabeto latino (a v10 tira das fileiras os títulos em árabe, russo...). */
    public static function latino(?string $nome): bool
    {
        return (bool) preg_match('/[A-Za-z]{2}/', self::limparNome($nome));
    }

    /** Nome da categoria para exibir: sem "Filmes |" e com acento (catTitle da v10). */
    public static function acentuar(string $categoria): string
    {
        $t = trim(preg_replace('/^(Filmes|Series|Séries|Canais)\s*\|\s*/iu', '', $categoria) ?? $categoria);
        return self::ACENTOS[self::fold($t)] ?? $t;
    }

    /** A categoria é gênero (fileira "Ação", "Comédia")? Lançamentos, 4K, Legendados... não são. */
    public static function ehGenero(string $categoria): bool
    {
        return !preg_match(self::NAO_GENERO, $categoria);
    }

    /** Chave de time (teamKey da v10). */
    public static function chaveTime(?string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', self::fold($s)) ?? '');
    }
}
