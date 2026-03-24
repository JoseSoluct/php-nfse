<?php

namespace NFePHP\NFSe\Models\Nacional;

use NFePHP\NFSe\Models\Nacional\Factories\v100\RecepcionarLoteRps as RecepcionarLoteRpsV100;
use NFePHP\NFSe\Models\Nacional\Factories\v101\RecepcionarLoteRps as RecepcionarLoteRpsV101;
use NFePHP\NFSe\Common\Tools as ToolsBase;
use RuntimeException;

class Tools extends ToolsBase
{
    public const VERSAO_100 = 100; // schema v1.00
    public const VERSAO_101 = 101; // schema v1.01

    protected $xmlns = 'http://www.sped.fazenda.gov.br/nfse';
    protected $soapAction = '';
    protected $schemeFolder = 'Nacional';
    protected $params = [];

    /**
     * Versão interna: 100 = schema v1.00, 101 = schema v1.01
     * @var int
     */
    protected $versao = self::VERSAO_101;

    /**
     * URLs dos webservices NFS-e Nacional por ambiente.
     *
     * @var array{int: array{DPS: string, consultar: string, cancelar: string}}
     */
    protected $url = [
        1 => [ // produção
            'DPS'      => 'https://nfse.sped.fazenda.gov.br/v1/dps',       // TODO: confirmar URL oficial
            'consultar' => 'https://nfse.sped.fazenda.gov.br/v1/nfse',
            'cancelar'  => 'https://nfse.sped.fazenda.gov.br/v1/eventos',
        ],
        2 => [ // homologação
            'DPS'      => 'https://hom.nfse.sped.fazenda.gov.br/v1/dps',   // TODO: confirmar URL oficial
            'consultar' => 'https://hom.nfse.sped.fazenda.gov.br/v1/nfse',
            'cancelar'  => 'https://hom.nfse.sped.fazenda.gov.br/v1/eventos',
        ],
    ];

    /**
     * Retorna a string de versão usada na validação dos schemas e nos arquivos XSD.
     * Mapeia a versão interna (100/101) para o formato do padrão Nacional (1.00/1.01).
     */
    public function getSchemaVersion(): string
    {
        $map = [
            self::VERSAO_100 => '1.00',
            self::VERSAO_101 => '1.01',
        ];

        return $map[$this->versao] ?? '1.01';
    }

    /**
     * Define a versão do schema Nacional a ser utilizada.
     * @param int $versao Use as constantes VERSAO_100 ou VERSAO_101
     */
    public function setVersao(int $versao): void
    {
        $this->versao = $versao;
    }

    /**
     * Envia uma ou mais DPS via REST ao serviço NFS-e Nacional.
     *
     * @param Rps|Rps[] $rpss
     * @return string Resposta JSON/XML do webservice
     */
    public function enviarDps(Rps|array $rpss): string
    {
        $factory = $this->makeFactory();
        $xml = $factory->render($rpss);

        $tpAmb = $this->config->tpAmb ?? 2;
        $url = $this->url[$tpAmb]['DPS'];

        return $this->sendRequest($url, $xml);
    }

    /**
     * Consulta uma NFS-e pelo número de chave (cChaveAcesso).
     */
    public function consultarNfse(string $chave): string
    {
        $tpAmb = $this->config->tpAmb ?? 2;
        $url = $this->url[$tpAmb]['consultar'] . '/' . rawurlencode($chave);

        return $this->sendRequest($url, '');
    }

    /**
     * Cancela uma NFS-e pelo número de chave e código de justificativa.
     *
     * @param string $chave   Chave de acesso da NFS-e
     * @param int    $codJust Código do evento de cancelamento
     */
    public function cancelarNfse(string $chave, int $codJust): string
    {
        $tpAmb = $this->config->tpAmb ?? 2;
        $url = $this->url[$tpAmb]['cancelar'];

        $body = json_encode([
            'cChaveAcesso' => $chave,
            'cEvento'      => $codJust,
        ]);

        return $this->sendRequest($url, $body);
    }

    /**
     * Envia a requisição HTTP ao webservice Nacional via cURL.
     *
     * Para envio de DPS ($message não vazio), usa POST com Content-Type: application/xml.
     * Para consulta ($message vazio), usa GET.
     */
    protected function sendRequest($url, $message): string
    {
        if (empty($url)) {
            throw new RuntimeException('URL do webservice NFS-e Nacional não configurada.');
        }

        $certFile = tempnam(sys_get_temp_dir(), 'nfse_cert_');
        $keyFile  = tempnam(sys_get_temp_dir(), 'nfse_key_');
        file_put_contents($certFile, (string) $this->certificate->publicKey);
        file_put_contents($keyFile, (string) $this->certificate->privateKey);

        $ch = curl_init();

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSLCERT        => $certFile,
            CURLOPT_SSLKEY         => $keyFile,
        ];

        if (!empty($message)) {
            $isJson = str_starts_with(ltrim($message), '{');
            $contentType = $isJson ? 'application/json' : 'application/xml; charset=utf-8';

            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = $message;
            $options[CURLOPT_HTTPHEADER] = [
                'Content-Type: ' . $contentType,
                'Accept: application/json',
            ];
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);

        curl_close($ch);

        @unlink($certFile);
        @unlink($keyFile);

        if ($errno) {
            throw new RuntimeException("Erro cURL ao comunicar com NFS-e Nacional: [{$errno}] {$error}");
        }

        return (string) $response;
    }

    /**
     * Instancia a factory correta conforme a versão configurada.
     */
    private function makeFactory(): RecepcionarLoteRpsV100|RecepcionarLoteRpsV101
    {
        if ($this->versao === self::VERSAO_101) {
            return new RecepcionarLoteRpsV101($this->certificate, $this->algorithm);
        }

        return new RecepcionarLoteRpsV100($this->certificate, $this->algorithm);
    }
}
