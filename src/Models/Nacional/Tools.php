<?php

namespace NFePHP\NFSe\Models\Nacional;

use NFePHP\NFSe\Common\Tools as ToolsBase;
use NFePHP\NFSe\Models\Nacional\Factories\v101\RecepcionarLoteRps;
use NFePHP\NFSe\Models\Nacional\Factories\v101\RenderEvento;
use RuntimeException;

/**
 * Cliente REST do Ambiente de Dados Nacional (ADN) da NFS-e.
 *
 * Protocolo oficial (gov.br/nfse):
 *   - mTLS com certificado ICP-Brasil A1/A3
 *   - Requisições JSON; documentos fiscais em XML assinado (XMLDSIG SHA-256),
 *     compactados via GZip e codificados em Base64 no campo correspondente.
 *   - Resposta em JSON contendo o XML da NFS-e/Evento também em GZip+Base64.
 *
 * Endpoints (concat. ao host por ambiente):
 *   POST  /nfse                         → recepção de DPS (resposta síncrona com NFS-e)
 *   GET   /nfse/{chaveAcesso}           → consulta NFS-e emitida
 *   GET   /dps/{id}                     → consulta DPS enviada
 *   HEAD  /dps/{id}                     → verifica se DPS virou NFS-e
 *   POST  /nfse/{chaveAcesso}/eventos   → registra evento (cancelamento, substituição, …)
 *   GET   /nfse/{chaveAcesso}/eventos   → lista eventos da NFS-e
 *   GET   /danfse/{chaveAcesso}         → download do DANFSe em PDF (binário)
 *   GET   /DFe/{nsu}                    → distribuição DFe (NFS-e onde a empresa é tomadora)
 *   GET   /parametros_municipais/{ibge}/{grupo}
 */
class Tools extends ToolsBase
{
    public const VERSAO = 101;

    public const SCHEMA_VERSION = '1.01';

    protected $xmlns = 'http://www.sped.fazenda.gov.br/nfse';

    /** Padrão Nacional exige SHA-256 na XMLDSIG */
    protected $algorithm = OPENSSL_ALGO_SHA256;

    protected $versao = self::VERSAO;

    /** Timeout (s) para requisições HTTP ao ADN */
    protected int $timeout = 60;

    /**
     * Hosts base do ADN por ambiente.
     *
     * @var array<int, string>
     */
    protected $url = [
        1 => 'https://sefin.nfse.gov.br',              // Produção
        2 => 'https://sefin.producaorestrita.nfse.gov.br', // Homologação (produção restrita)
    ];

    /**
     * Define timeout em segundos para requisições ao ADN.
     */
    public function setTimeout(int $seconds): void
    {
        $this->timeout = max(5, $seconds);
    }

    /**
     * Retorna o host base conforme ambiente configurado em $config->tpAmb.
     */
    protected function baseUrl(): string
    {
        $tpAmb = (int) ($this->config->tpAmb ?? 2);
        $base = $this->url[$tpAmb] ?? null;

        if (empty($base)) {
            throw new RuntimeException("Ambiente inválido para NFS-e Nacional: {$tpAmb}");
        }

        return $base;
    }

    // =========================================================================
    // DPS — recepção e consulta
    // =========================================================================

    /**
     * Envia uma DPS ao ADN via POST /nfse.
     *
     * O XML assinado é compactado (GZip) e codificado em Base64,
     * transmitido em JSON no campo "dpsXmlGZipB64". A resposta síncrona
     * da RFB traz a NFS-e emitida (ou erro).
     *
     * @return string JSON bruto retornado pelo ADN
     */
    public function enviarDps(Rps $rps): string
    {
        $factory = new RecepcionarLoteRps($this->certificate, $this->algorithm);
        $xmlSigned = $factory->render($rps);

        $payload = json_encode(
            ['dpsXmlGZipB64' => base64_encode(gzencode($xmlSigned, 9))],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $this->httpRequest(
            'POST',
            $this->baseUrl() . '/nfse',
            $payload,
            'application/json'
        );
    }

    /**
     * Consulta uma NFS-e emitida pela chave de acesso (50 caracteres).
     */
    public function consultarNfse(string $chaveAcesso): string
    {
        $this->guardChave($chaveAcesso);

        return $this->httpRequest(
            'GET',
            $this->baseUrl() . '/nfse/' . rawurlencode($chaveAcesso)
        );
    }

    /**
     * Consulta os dados de uma DPS pelo seu Id local (45 caracteres).
     */
    public function consultarDps(string $idDps): string
    {
        $this->guardDpsId($idDps);

        return $this->httpRequest(
            'GET',
            $this->baseUrl() . '/dps/' . rawurlencode($idDps)
        );
    }

    /**
     * Verifica se uma DPS já se tornou NFS-e (HEAD /dps/{id}).
     * Retorna o status HTTP (200 = virou NFS-e, 404 = ainda não).
     */
    public function verificarDps(string $idDps): int
    {
        $this->guardDpsId($idDps);

        return $this->sendRequestHead(
            $this->baseUrl() . '/dps/' . rawurlencode($idDps)
        );
    }

    // =========================================================================
    // Eventos (cancelamento, substituição, rejeição de tomador, etc.)
    // =========================================================================

    /**
     * Registra um evento já renderizado e assinado (XML assinado em string).
     * O XML é compactado (GZip+Base64) e enviado em JSON no campo "eventoXmlGZipB64".
     *
     * A construção do XML de evento é feita pela classe Evento/RenderEvento
     * (ver Fase 2 do plano). Este método recebe o XML pronto para não acoplar
     * Tools ao builder.
     */
    public function registrarEvento(string $chaveAcesso, string $eventoXmlAssinado): string
    {
        $this->guardChave($chaveAcesso);

        $payload = json_encode(
            ['eventoXmlGZipB64' => base64_encode(gzencode($eventoXmlAssinado, 9))],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $this->httpRequest(
            'POST',
            $this->baseUrl() . '/nfse/' . rawurlencode($chaveAcesso) . '/eventos',
            $payload,
            'application/json'
        );
    }

    /**
     * Constrói, assina e envia um evento já preparado ({@see Evento}) ao ADN.
     * Retorna o JSON bruto da resposta do serviço.
     */
    public function enviarEvento(Evento $evento): string
    {
        if (empty($evento->infId)) {
            $evento->gerarId();
        }

        $xml = RenderEvento::toXml($evento, $this->certificate, $this->algorithm);

        return $this->registrarEvento($evento->infChNFSe, $xml);
    }

    /**
     * Cancelamento simples de NFS-e (evento 101101).
     *
     * @param string $chaveAcesso Chave da NFS-e a cancelar
     * @param string $cMotivo     '1' (Erro emissão) | '2' (Serviço não prestado) | '9' (Outros)
     * @param string $xMotivo     Descrição (15..255 chars)
     * @param int    $autorTipo   Evento::AUTOR_CPF ou AUTOR_CNPJ
     * @param string $autorDoc    CPF/CNPJ do autor (normalmente o próprio prestador)
     */
    public function cancelarNfsePorEvento(string $chaveAcesso, string $cMotivo, string $xMotivo, int $autorTipo, string $autorDoc): string
    {
        $evento = new Evento();
        $evento->tpAmb((int) ($this->config->tpAmb ?? 2));
        $evento->dhEvento(new \DateTime('now', $this->timezone));
        $evento->autor($autorTipo, $autorDoc);
        $evento->chNFSe($chaveAcesso);
        $evento->cancelamento($cMotivo, $xMotivo);

        return $this->enviarEvento($evento);
    }

    /**
     * Lista os eventos registrados para uma NFS-e.
     */
    public function listarEventos(string $chaveAcesso): string
    {
        $this->guardChave($chaveAcesso);

        return $this->httpRequest(
            'GET',
            $this->baseUrl() . '/nfse/' . rawurlencode($chaveAcesso) . '/eventos'
        );
    }

    // =========================================================================
    // DANFSe, DFe e parâmetros municipais
    // =========================================================================

    /**
     * Baixa o PDF oficial do DANFSe pela chave de acesso.
     * Retorna os bytes do PDF (binário).
     */
    public function baixarDanfse(string $chaveAcesso): string
    {
        $this->guardChave($chaveAcesso);

        return $this->httpRequest(
            'GET',
            $this->baseUrl() . '/danfse/' . rawurlencode($chaveAcesso),
            null,
            null,
            'application/pdf'
        );
    }

    /**
     * Distribuição DFe: retorna NFS-e onde a empresa é tomadora, a partir de um NSU.
     */
    public function distribuicaoDFe(int $nsu): string
    {
        if ($nsu < 0) {
            throw new RuntimeException('NSU deve ser >= 0.');
        }

        return $this->httpRequest(
            'GET',
            $this->baseUrl() . '/DFe/' . $nsu
        );
    }

    /**
     * Consulta parâmetros municipais (alíquotas, códigos, etc.) por município e grupo.
     */
    public function consultarParametrosMunicipais(string $codigoIbge, string $grupo): string
    {
        if (!preg_match('/^\d{7}$/', $codigoIbge)) {
            throw new RuntimeException('Código IBGE deve ter 7 dígitos.');
        }

        return $this->httpRequest(
            'GET',
            $this->baseUrl() . '/parametros_municipais/' . $codigoIbge . '/' . rawurlencode($grupo)
        );
    }

    // =========================================================================
    // Transporte HTTP (cURL com mTLS ICP-Brasil)
    // =========================================================================

    /**
     * Satisfaz o contrato abstrato Common\Tools::sendRequest.
     * Encaminha a chamada como POST JSON (formato padrão do ADN).
     */
    protected function sendRequest($url, $message): string
    {
        return $this->httpRequest('POST', (string) $url, (string) $message, 'application/json');
    }

    /**
     * Registro da última requisição HTTP enviada (método, URL, headers, body).
     * Útil para logging/debug e testes.
     *
     * @var array{method: string, url: string, headers: array<int, string>, body: string|null, httpCode: int}|null
     */
    protected ?array $lastRequest = null;

    /**
     * Retorna o registro da última requisição enviada via httpRequest.
     */
    public function getLastRequest(): ?array
    {
        return $this->lastRequest;
    }

    /**
     * Executa uma requisição HTTP ao ADN com mTLS.
     *
     * @param string      $method      HTTP method (GET, POST)
     * @param string      $url         URL completa
     * @param string|null $body        Body da requisição (JSON, já serializado)
     * @param string|null $contentType Content-Type do body
     * @param string|null $accept      Accept header (default: application/json)
     * @return string                  Corpo da resposta (JSON ou PDF binário)
     */
    protected function httpRequest(string $method, string $url, ?string $body = null, ?string $contentType = null, ?string $accept = null): string
    {
        [$certFile, $keyFile] = $this->writeCertPair();

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper((string) $method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLCERT => $certFile,
            CURLOPT_SSLCERTTYPE => 'PEM',
            CURLOPT_SSLKEY => $keyFile,
            CURLOPT_SSLKEYTYPE => 'PEM',
        ]);

        $headers = ['Accept: ' . ($accept ?? 'application/json')];
        if ($body !== null && $body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $headers[] = 'Content-Type: ' . ($contentType ?? 'application/json');
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        @unlink($certFile);
        @unlink($keyFile);

        $this->lastRequest = [
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'httpCode' => $httpCode,
        ];
        $this->xmlRequest = (string) $body;

        if ($errno) {
            throw new RuntimeException("Erro cURL ao comunicar com NFS-e Nacional: [{$errno}] {$error}");
        }

        if ($httpCode >= 500) {
            throw new RuntimeException("ADN retornou HTTP {$httpCode}: " . substr((string) $response, 0, 500));
        }

        return (string) $response;
    }

    /**
     * Executa um HEAD para verificar existência (retorna apenas o status HTTP).
     */
    protected function sendRequestHead(string $url): int
    {
        [$certFile, $keyFile] = $this->writeCertPair();

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => 'HEAD',
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLCERT => $certFile,
            CURLOPT_SSLCERTTYPE => 'PEM',
            CURLOPT_SSLKEY => $keyFile,
            CURLOPT_SSLKEYTYPE => 'PEM',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        @unlink($certFile);
        @unlink($keyFile);

        if ($errno) {
            throw new RuntimeException("Erro cURL (HEAD) NFS-e Nacional: [{$errno}] {$error}");
        }

        return $httpCode;
    }

    /**
     * Grava certificado público e chave privada em arquivos PEM temporários
     * com permissão restrita. Retorna [$certPath, $keyPath].
     *
     * @return array{0: string, 1: string}
     */
    protected function writeCertPair(): array
    {
        $certFile = tempnam(sys_get_temp_dir(), 'nfse_cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'nfse_key_');

        file_put_contents($certFile, (string) $this->certificate->publicKey);
        file_put_contents($keyFile, (string) $this->certificate->privateKey);
        @chmod($certFile, 0600);
        @chmod($keyFile, 0600);

        return [$certFile, $keyFile];
    }

    /**
     * Valida chave de acesso TSChaveAcesso (50 dígitos).
     */
    protected function guardChave(string $chave): void
    {
        if (!preg_match('/^\d{50}$/', $chave)) {
            throw new RuntimeException("Chave de acesso inválida (esperado 50 dígitos): '{$chave}'");
        }
    }

    /**
     * Valida Id da DPS (45 caracteres: 'DPS' + 42 dígitos).
     */
    protected function guardDpsId(string $idDps): void
    {
        if (!preg_match('/^DPS\d{42}$/', $idDps)) {
            throw new RuntimeException("Id da DPS inválido (esperado 'DPS' + 42 dígitos): '{$idDps}'");
        }
    }
}
