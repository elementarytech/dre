<?php
/**
 * Banco do Brasil – Cobrança Registrada (API v2 / OAuth2)
 * Produção: credentials "ambiente":"producao"
 */
declare(strict_types=1);

class BancoDobrasilApi
{
    // ── URLs por ambiente ─────────────────────────────────────────────────────
    private static array $urls = [
        'producao'    => ['oauth' => 'https://oauth.bb.com.br/oauth/token',    'api' => 'https://api.bb.com.br'],
        'homologacao' => ['oauth' => 'https://oauth.hm.bb.com.br/oauth/token', 'api' => 'https://api.hm.bb.com.br'],
    ];

    // ── Credenciais e ambiente (carregados do banco via loadConfig) ───────────
    private static string $clientId     = '';
    private static string $clientSecret = '';
    private static string $appKey       = '';
    private static string $convenio     = '';
    private static string $ambiente     = 'producao';
    private static int    $carteira     = 17;
    private static int    $variacao     = 35;

    /** Carrega credenciais e ambiente da tabela tb_configuracoes. Chamar antes de usar a API. */
    public static function loadConfig(PDO $pdo): void
    {
        $rows = $pdo->query("
            SELECT CFG_CHAVE, CFG_VALOR
            FROM tb_configuracoes
            WHERE CFG_CHAVE IN ('bb_client_id','bb_client_secret','bb_app_key','bb_numero_convenio','bb_ambiente','bb_carteira','bb_variacao_carteira')
        ")->fetchAll();

        foreach ($rows as $r) {
            switch ($r['CFG_CHAVE']) {
                case 'bb_client_id':       self::$clientId     = (string)($r['CFG_VALOR'] ?? ''); break;
                case 'bb_client_secret':   self::$clientSecret = (string)($r['CFG_VALOR'] ?? ''); break;
                case 'bb_app_key':         self::$appKey       = (string)($r['CFG_VALOR'] ?? ''); break;
                case 'bb_numero_convenio': self::$convenio     = (string)($r['CFG_VALOR'] ?? ''); break;
                case 'bb_ambiente':           self::$ambiente  = (string)($r['CFG_VALOR'] ?? 'producao'); break;
                case 'bb_carteira':           self::$carteira  = (int)($r['CFG_VALOR'] ?? 17); break;
                case 'bb_variacao_carteira':  self::$variacao  = (int)($r['CFG_VALOR'] ?? 35); break;
            }
        }
    }

    public static function getConvenio(): string { return self::$convenio; }
    public static function getAmbiente(): string { return self::$ambiente; }
    public static function getCarteira(): int    { return self::$carteira; }
    public static function getVariacao(): int    { return self::$variacao; }

    private static function oauthUrl(): string
    {
        return self::$urls[self::$ambiente]['oauth'] ?? self::$urls['producao']['oauth'];
    }

    private static function apiBase(): string
    {
        return self::$urls[self::$ambiente]['api'] ?? self::$urls['producao']['api'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Token OAuth2
    // ─────────────────────────────────────────────────────────────────────────

    public static function getToken(): string
    {
        $cacheFile = sys_get_temp_dir() . '/bb_oauth_token_' . self::$ambiente . '.json';

        if (file_exists($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (
                is_array($cached)
                && isset($cached['access_token'], $cached['expires_at'])
                && $cached['expires_at'] > time() + 60
            ) {
                return (string)$cached['access_token'];
            }
        }

        $response = self::curlPost(self::oauthUrl(), http_build_query([
            'grant_type' => 'client_credentials',
            'scope'      => 'cobrancas.boletos-requisicao cobrancas.boletos-info',
        ]), [
            'Authorization: Basic ' . base64_encode(self::$clientId . ':' . self::$clientSecret),
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        if (empty($response['body']['access_token'])) {
            throw new RuntimeException(
                'BB OAuth: falha ao obter token. HTTP ' . $response['http_code'] .
                ' – ' . json_encode($response['body'], JSON_UNESCAPED_UNICODE)
            );
        }

        $ttl     = (int)($response['body']['expires_in'] ?? 3600);
        $payload = [
            'access_token' => $response['body']['access_token'],
            'expires_at'   => time() + $ttl,
        ];
        @file_put_contents($cacheFile, json_encode($payload));

        return $payload['access_token'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Criar boleto
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array $dados  Payload completo conforme BB API v2
     * @return array        Resposta da API com linhaDigitavel, numero, etc.
     */
    public static function criarBoleto(array $dados): array
    {
        $token = self::getToken();
        $url   = self::apiBase() . '/cobrancas/v2/boletos?gw-dev-app-key=' . self::$appKey;

        $response = self::curlPost($url, json_encode($dados), [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'developer-application-key: ' . self::$appKey,
        ], true);

        if ($response['http_code'] < 200 || $response['http_code'] >= 300) {
            throw new RuntimeException(
                'BB API criarBoleto: HTTP ' . $response['http_code'] .
                ' – ' . json_encode($response['body'], JSON_UNESCAPED_UNICODE)
            );
        }

        return (array)($response['body'] ?? []);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Consultar boleto por número
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param string $numeroBoleto  Número do boleto retornado na criação
     * @param string $convenio      Número do convênio
     */
    public static function consultarBoleto(string $numeroBoleto, string $convenio): array
    {
        $token = self::getToken();
        $url   = self::apiBase() . '/cobrancas/v2/boletos/' . urlencode($numeroBoleto)
               . '?gw-dev-app-key=' . self::$appKey
               . '&numeroConvenio=' . urlencode($convenio);

        $response = self::curlGet($url, [
            'Authorization: Bearer ' . $token,
            'developer-application-key: ' . self::$appKey,
        ]);

        if ($response['http_code'] !== 200) {
            throw new RuntimeException(
                'BB API consultarBoleto: HTTP ' . $response['http_code'] .
                ' – ' . json_encode($response['body'], JSON_UNESCAPED_UNICODE)
            );
        }

        return (array)($response['body'] ?? []);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers cURL
    // ─────────────────────────────────────────────────────────────────────────

    private static function caBundle(): string
    {
        $candidates = [
            ini_get('curl.cainfo'),
            __DIR__ . '/../../../../bin/php/php8.3.28/extras/cacert.pem',
            'C:/wamp64/bin/php/php8.3.28/extras/cacert.pem',
        ];
        foreach ($candidates as $p) {
            if ($p && is_file($p)) return $p;
        }
        return '';
    }

    private static function curlPost(string $url, mixed $body, array $headers, bool $bodyIsJson = false): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        $ca = self::caBundle();
        if ($ca) $opts[CURLOPT_CAINFO] = $ca;
        curl_setopt_array($ch, $opts);
        $raw      = (string)curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new RuntimeException('cURL error: ' . $curlErr);
        }

        return ['http_code' => $httpCode, 'body' => json_decode($raw, true)];
    }

    private static function curlGet(string $url, array $headers): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        $ca = self::caBundle();
        if ($ca) $opts[CURLOPT_CAINFO] = $ca;
        curl_setopt_array($ch, $opts);
        $raw      = (string)curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new RuntimeException('cURL error: ' . $curlErr);
        }

        return ['http_code' => $httpCode, 'body' => json_decode($raw, true)];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers de formatação
    // ─────────────────────────────────────────────────────────────────────────

    /** Formata data no padrão BB: DD.MM.AAAA */
    public static function fmtData(string $ymd): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
            throw new \InvalidArgumentException("Data inválida: $ymd");
        }
        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }

    /** Remove todos os caracteres não-dígitos de um CPF/CNPJ */
    public static function somenteDigitos(string $v): string
    {
        return preg_replace('/\D/', '', $v);
    }

    /** 1 = CPF (11 dígitos), 2 = CNPJ (14 dígitos) */
    public static function tipoInscricao(string $cpfCnpj): int
    {
        return strlen(self::somenteDigitos($cpfCnpj)) <= 11 ? 1 : 2;
    }
}
