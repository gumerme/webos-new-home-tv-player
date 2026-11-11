<?php
declare(strict_types=1);

namespace Casa;

/**
 * Ícones Tabler (vendorizados em assets/vendor/tabler-icons).
 * As views usam nomes curtos em português; aqui vira <i class="ti ti-...">.
 */
final class Icone
{
    private const T = [
        'home' => 'home', 'painel' => 'layout-dashboard', 'tv' => 'device-tv', 'historico' => 'history',
        'usuarios' => 'users', 'plug' => 'plug-connected', 'usuario' => 'user', 'sair' => 'logout',
        'power' => 'power', 'vol-menos' => 'volume-2', 'vol-mais' => 'volume', 'mudo' => 'volume-off',
        'sol' => 'sun', 'lua' => 'moon', 'pausa' => 'player-pause', 'play' => 'player-play',
        'cpu' => 'cpu', 'ram' => 'cpu-2', 'disco' => 'database', 'raio' => 'bolt', 'termo' => 'temperature',
        'rede' => 'activity', 'moeda' => 'coin', 'servidor' => 'server', 'ok' => 'circle-check', 'x' => 'circle-x',
        'alerta' => 'alert-triangle', 'mais' => 'plus', 'editar' => 'pencil', 'copiar' => 'copy', 'olho' => 'eye',
        'relogio' => 'clock', 'link' => 'link', 'chave' => 'key', 'escudo' => 'shield-check', 'codigo' => 'code',
        'voltar' => 'arrow-left', 'cadeado' => 'lock', 'menu' => 'menu-2', 'apps' => 'apps', 'caixa' => 'box',
        'arquivo' => 'archive', 'download' => 'download', 'globo' => 'world', 'sino' => 'bell',
        'atividade' => 'activity-heartbeat', 'info' => 'info-circle', 'roteador' => 'router',
        'celular' => 'device-mobile', 'impressora' => 'printer', 'camera' => 'camera', 'som' => 'speakerphone',
        'lista' => 'list-details', 'pata' => 'paw',
    ];

    public static function svg(string $nome, string $classe = '', ?string $rotulo = null): string
    {
        $ti = self::T[$nome] ?? 'point';
        $a11y = $rotulo !== null ? 'role="img" aria-label="' . htmlspecialchars($rotulo, ENT_QUOTES) . '"' : 'aria-hidden="true"';
        $extra = implode(' ', array_filter(preg_split('/\s+/', $classe) ?: [], fn($c) => $c !== '' && $c !== 'ico'));
        return '<i class="ti ti-' . $ti . ($extra !== '' ? ' ' . $extra : '') . '" ' . $a11y . '></i>';
    }

    /** nome => classe Tabler (o JavaScript monta os mesmos ícones). */
    public static function todos(): array
    {
        return self::T;
    }
}
