<?php

namespace NFePHP\NFSe\Models\IPM;

use Exception;
use NFePHP\Common\Soap\SoapBase;
use NFePHP\Common\Exception\SoapException;

/**
 * Transporte REST do webservice ANTIGO da IPM (nfs-e.net), que recebia
 * login/senha/cidade como campos do form-data.
 *
 * @deprecated O protocolo mudou (Atende.Net, NTE 35/2021 v2.9): a autenticação
 *             é HTTP Basic e a URL é por município. {@see \NFePHP\NFSe\Models\IPM\Tools}
 *             faz o transporte com cURL próprio e não usa esta classe. Mantida
 *             apenas para consumidores externos que ainda a instanciem.
 *
 * @author Tiago Franco
 */
class SoapCurl extends SoapBase
{
    /**
     * Comunica com os servidores IPM via REST
     * @param string $url
     * @param string $operation
     * @param string $action
     * @param int $soapver
     * @param array $parameters
     * @param array $namespaces
     * @param string $request
     * @param \SoapHeader $soapheader
     * @return string
     * @throws \NFePHP\Common\Exception\SoapException
     */
    public function send(
        $url,
        $operation = '',
        $action = '',
        $soapver = SOAP_1_2,
        $parameters = [],
        $namespaces = [],
        $request = '',
        $soapheader = null
    ) {
        //check or create key files
        //before send request
        $response = '';
        $httpcode = 0;

        try {
            $oCurl = curl_init();
            curl_setopt($oCurl, CURLOPT_URL, $url);
            curl_setopt($oCurl, CURLOPT_POST, true);
            curl_setopt($oCurl, CURLOPT_POSTFIELDS, $parameters);
            curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, 1);
            $response = curl_exec($oCurl);
            $this->soaperror = curl_error($oCurl);
            $ainfo = curl_getinfo($oCurl);
            if (is_array($ainfo)) {
                $this->soapinfo = $ainfo;
            }
            $httpcode = curl_getinfo($oCurl, CURLINFO_HTTP_CODE);
            curl_close($oCurl);

            // Nunca gravar a senha em disco: os campos de credencial saem redigidos.
            $this->saveDebugFiles(
                $operation,
                json_encode(self::redactParameters($parameters)),
                (string) $response
            );
        } catch (\Exception $e) {
            throw $e;
        }
        if ($this->soaperror != '') {
            throw new \Exception($this->soaperror . " [$url]", 500);
        }
        if ($httpcode != 200) {
            // O corpo é onde vem a mensagem de erro do município; as credenciais
            // (login/senha do form-data) nunca entram na exceção.
            throw new \Exception(
                "POST [$url] retornou HTTP $httpcode: " . substr(trim((string) $response), 0, 500),
                500
            );
        }

        return $response;
    }

    /**
     * Sem certificado (o IPM não exige) o SoapBase::saveDebugFiles() do
     * sped-common chama $this->certificate->getCnpj() em null e derruba o
     * processo; aqui o debug simplesmente não grava nada nesse caso.
     *
     * @param string $operation
     * @param string $request
     * @param string $response
     * @return void
     */
    public function saveDebugFiles($operation, $request, $response)
    {
        if (!$this->debugmode || $this->certificate === null) {
            return;
        }

        parent::saveDebugFiles($operation, $request, $response);
    }

    /**
     * Substitui valores de credencial por "***" antes de qualquer registro.
     *
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private static function redactParameters($parameters)
    {
        if (!is_array($parameters)) {
            return [];
        }
        foreach ($parameters as $key => $value) {
            if (preg_match('/senha|password|passwd|pass|secret|token/i', (string) $key)) {
                $parameters[$key] = '***';
            } elseif ($value instanceof \CURLFile || $value instanceof \CURLStringFile) {
                $parameters[$key] = '[arquivo]';
            }
        }

        return $parameters;
    }
}
