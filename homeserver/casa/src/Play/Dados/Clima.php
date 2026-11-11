<?php
declare(strict_types=1);

namespace Casa\Play\Dados;

/**
 * Clima da Home das TVs (09/11/2026): o homeserver consulta o Open-Meteo a cada 30 min e guarda em
 * play.cache 'clima'. A TV não descobre mais a cidade pelo IP nem chama sites de fora.
 * Cidade: CLIMA_CIDADE, CLIMA_LAT e CLIMA_LON no .env (padrão: São Paulo).
 */
final class Clima
{
    private static function cidade(): string { return getenv('CLIMA_CIDADE') ?: 'São Paulo'; }
    private static function lat(): float { return (float) (getenv('CLIMA_LAT') ?: -23.5505); }
    private static function lon(): float { return (float) (getenv('CLIMA_LON') ?: -46.6333); }

    /** Código WMO -> [texto, ícone] (ícone: sol|parcial|nuvem|nevoa|chuva|garoa|tempestade|neve). Puro. */
    public static function ceu(int $codigo, bool $dia = true): array
    {
        return match (true) {
            $codigo === 0           => [$dia ? 'Céu limpo' : 'Noite limpa', $dia ? 'sol' : 'lua'],
            $codigo <= 2            => ['Parcialmente nublado', 'parcial'],
            $codigo === 3           => ['Nublado', 'nuvem'],
            $codigo <= 48           => ['Neblina', 'nevoa'],
            $codigo <= 57           => ['Garoa', 'garoa'],
            $codigo <= 67, $codigo >= 80 && $codigo <= 82 => ['Chuva', 'chuva'],
            $codigo <= 77, $codigo === 85, $codigo === 86 => ['Neve', 'neve'],
            default                 => ['Tempestade', 'tempestade'],
        };
    }

    /** Resposta do Open-Meteo -> formato da Home (puro). */
    public static function montar(array $r): array
    {
        $c = $r['current'] ?? [];
        $dia = (int) ($c['is_day'] ?? 1) === 1;
        [$texto, $icone] = self::ceu((int) ($c['weather_code'] ?? 0), $dia);
        $d = $r['daily'] ?? [];
        $dias = [];
        foreach (($d['time'] ?? []) as $i => $data) {
            [$t, $ic] = self::ceu((int) ($d['weather_code'][$i] ?? 0));
            $dias[] = ['data' => $data, 'max' => (int) round((float) ($d['temperature_2m_max'][$i] ?? 0)),
                       'min' => (int) round((float) ($d['temperature_2m_min'][$i] ?? 0)), 'ceu' => $t, 'icone' => $ic];
        }
        $por = (string) ($d['sunset'][0] ?? '');
        return ['cidade' => self::cidade(), 'temp' => (int) round((float) ($c['temperature_2m'] ?? 0)), 'ceu' => $texto, 'icone' => $icone,
                'por_do_sol' => $por !== '' ? substr($por, 11, 5) : null, 'dias' => $dias, 'em' => date('c')];
    }

    public static function tarefa(): array
    {
        $url = 'https://api.open-meteo.com/v1/forecast?latitude=' . self::LAT . '&longitude=' . self::LON
            . '&current=temperature_2m,weather_code,is_day&daily=weather_code,temperature_2m_max,temperature_2m_min,sunset'
            . '&timezone=America%2FSao_Paulo&forecast_days=4';
        $c = self::montar((array) Http::json($url, [], 20));
        Banco::guardar('clima', $c);
        return ['msg' => "{$c['temp']}° {$c['ceu']} em {$c['cidade']}"];
    }
}
