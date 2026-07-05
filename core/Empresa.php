<?php
/**
 * core/Empresa.php
 * Núcleo de identidade visual e dados do escritório para o ROJEX.AI.
 * Versão corrigida: tolerante a falhas na tabela configuracoes para não bloquear login.
 */

declare(strict_types=1);

class Empresa
{
    private mysqli $conn;
    private array $cache = [];

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
        $this->garantirTabelaConfiguracoes();
        $this->carregarConfiguracoes();
    }

    public static function criar(): self
    {
        if (!function_exists('conectar')) {
            require_once __DIR__ . '/../config/database.php';
        }
        return new self(conectar());
    }

    private function garantirTabelaConfiguracoes(): void
    {
        try {
            @$this->conn->query("CREATE TABLE IF NOT EXISTS configuracoes (
                chave VARCHAR(80) NOT NULL,
                valor TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (chave)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            error_log('ROJEX Empresa::garantirTabelaConfiguracoes: ' . $e->getMessage());
        }
    }

    private function carregarConfiguracoes(): void
    {
        try {
            $result = @$this->conn->query("SELECT chave, valor FROM configuracoes");
            if ($result instanceof mysqli_result) {
                while ($row = $result->fetch_assoc()) {
                    $this->cache[(string)$row['chave']] = (string)($row['valor'] ?? '');
                }
                $result->free();
            }
        } catch (Throwable $e) {
            error_log('ROJEX Empresa::carregarConfiguracoes: ' . $e->getMessage());
        }
    }

    public function get(string $chave, string $padrao = ''): string
    {
        return trim((string)($this->cache[$chave] ?? $padrao));
    }

    public function set(string $chave, string $valor): void
    {
        try {
            $stmt = $this->conn->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
            if ($stmt) {
                $stmt->bind_param('ss', $chave, $valor);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('ROJEX Empresa::set: ' . $e->getMessage());
        }
        $this->cache[$chave] = $valor;
    }

    public function nomeSistema(): string { return 'ROJEX.AI'; }
    public function sloganSistema(): string { return 'Inteligência Artificial para PMEs'; }
    public function nomeEscritorio(): string { return $this->get('nome_escritorio', 'SGL Advocacia'); }
    public function razaoSocial(): string { return $this->get('razao_social', $this->nomeEscritorio()); }
    public function logoOficial(): string { return 'assets/img/logo_rojex_ai.png'; }

    public function logoEscritorio(): string
    {
        $logo = $this->get('logo_arquivo', '');
        if ($logo === '') { return ''; }
        $arquivo = preg_replace('/[^a-zA-Z0-9._-]/', '', $logo);
        $relativo = 'assets/img/' . $arquivo;
        $absoluto = __DIR__ . '/../' . $relativo;
        return is_file($absoluto) ? $relativo : '';
    }

    public function logoPrincipal(): string
    {
        $logoEscritorio = $this->logoEscritorio();
        return $logoEscritorio !== '' ? $logoEscritorio : $this->logoOficial();
    }

    public function temLogoEscritorio(): bool { return $this->logoEscritorio() !== ''; }
    public function poweredBy(): string { return 'Powered by ROJEX.AI'; }
    public function corPrimaria(): string { return $this->hex($this->get('cor_primaria', '#081f2d'), '#081f2d'); }
    public function corSecundaria(): string { return $this->hex($this->get('cor_secundaria', '#0d6efd'), '#0d6efd'); }
    public function corAccent(): string { return $this->hex($this->get('cor_accent', '#d4af37'), '#d4af37'); }
    public function timezone(): string { return $this->get('timezone', 'America/Sao_Paulo'); }

    private function hex(string $valor, string $padrao): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $valor) ? $valor : $padrao;
    }
}
