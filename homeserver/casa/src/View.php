<?php
declare(strict_types=1);

namespace Casa;

final class View
{
    public static function render(string $view, array $vars = []): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        require dirname(__DIR__) . "/views/$view.php";
        return (string) ob_get_clean();
    }

    /** Página dentro do layout (menu lateral). */
    public static function pagina(string $titulo, string $menu, string $view, array $vars = []): void
    {
        $conteudo = self::render($view, $vars + ['usuario' => Auth::usuario()]);
        echo self::render('layout', [
            'titulo'    => $titulo,
            'subtitulo' => $vars['subtitulo'] ?? null,
            'menu'      => $menu,
            'conteudo' => $conteudo,
            'usuario'  => Auth::usuario(),
            'flash'    => Auth::flash(),
            'pagina'   => $view,
        ]);
    }

    /** Iniciais para o avatar (ex.: "Maria Silva" -> "MS"). */
    public static function iniciais(string $nome): string
    {
        $partes = preg_split('/\s+/', trim($nome)) ?: [];
        $ini = mb_substr($partes[0] ?? '?', 0, 1) . (count($partes) > 1 ? mb_substr(end($partes), 0, 1) : '');
        return mb_strtoupper($ini);
    }

    public static function bytes(?float $b, int $dec = 1): string
    {
        if ($b === null) {
            return '—';
        }
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < count($u) - 1) {
            $b /= 1024;
            $i++;
        }
        return number_format($b, $i === 0 ? 0 : $dec, ',', '.') . ' ' . $u[$i];
    }

    public static function duracao(int $s): string
    {
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        $m = intdiv($s % 3600, 60);
        return ($d ? "{$d}d " : '') . ($d || $h ? "{$h}h " : '') . "{$m}min";
    }

    /** "agora", "há 5 min", "há 3 h", "há 2 dias" a partir de um timestamp Unix. */
    public static function relativo(?int $ts): string
    {
        if (!$ts) {
            return '—';
        }
        $d = max(0, time() - $ts);
        return match (true) {
            $d < 90     => 'agora',
            $d < 3600   => 'há ' . round($d / 60) . ' min',
            $d < 86400  => 'há ' . round($d / 3600) . ' h',
            default     => 'há ' . round($d / 86400) . (round($d / 86400) == 1 ? ' dia' : ' dias'),
        };
    }

    public static function dataHora(?string $ts): string
    {
        if (!$ts) {
            return '—';
        }
        return (new \DateTimeImmutable($ts))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d/m H:i:s');
    }
}
