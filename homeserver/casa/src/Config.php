<?php
declare(strict_types=1);

namespace Casa;

/** Configurações guardadas no banco (editáveis pelo painel). */
final class Config
{
    public static function get(string $chave, ?string $padrao = null): ?string
    {
        $st = Db::pdo()->prepare('select valor from configuracao where chave = ?');
        $st->execute([$chave]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $padrao : (string) $v;
    }

    public static function set(string $chave, ?string $valor, ?string $quem = null): void
    {
        Db::pdo()->prepare('
            insert into configuracao (chave, valor, atualizado_por) values (?, ?, ?)
            on conflict (chave) do update set valor = excluded.valor, atualizado_em = now(), atualizado_por = excluded.atualizado_por')
            ->execute([$chave, $valor, $quem]);
    }

    /**
     * Correção do RAPL para "consumo na tomada", pela classe da máquina
     * (mini PC, placa H81U, i3-4030U, SSD SATA, Wi-Fi/BT, rede Realtek, fonte externa):
     *   total = (RAPL + resto fixo) ÷ eficiência da fonte
     * Resto fixo ~5,5 W: chipset H81, SSD, rede, Wi-Fi/BT e perdas da placa em repouso.
     * Eficiência ~85%: fonte externa trabalhando com carga baixa.
     * São estimativas da classe; calibrar com um medidor de tomada substitui os padrões.
     */
    public const ENERGIA_FIXO_PADRAO = 5.5;
    public const ENERGIA_EFICIENCIA_PADRAO = 0.85;

    public static function energiaCorrecao(): array
    {
        $fixo = self::get('energia_fixo_w');
        $ef = self::get('energia_eficiencia');
        return [
            'fixo_w'     => $fixo !== null ? (float) $fixo : self::ENERGIA_FIXO_PADRAO,
            'eficiencia' => $ef !== null && (float) $ef > 0 ? (float) $ef : self::ENERGIA_EFICIENCIA_PADRAO,
            'origem'     => self::get('energia_correcao_origem', 'classe'),
        ];
    }

    /** Tarifa em R$/kWh: painel primeiro, depois TARIFA_KWH do .env. */
    public static function tarifaKwh(): ?float
    {
        $v = self::get('tarifa_kwh') ?? (getenv('TARIFA_KWH') ?: null);
        return $v !== null && (float) $v > 0 ? (float) $v : null;
    }
}
