<?php

namespace NFePHP\NFSe\Counties\M4320909;

/**
 * Classe para a comunicação com os webservices da
 * Cidade de Santa Maria RS
 * conforme o modelo Tecnos
 *
 * @category  NFePHP
 * @package   NFePHP\NFSe\Counties\M4320909\Tools
 * @copyright NFePHP Copyright (c) 2016
 * @license   http://www.gnu.org/licenses/lgpl.txt LGPLv3+
 * @license   https://opensource.org/licenses/MIT MIT
 * @license   http://www.gnu.org/licenses/gpl.txt GPLv3+
 * @author    José Alcides Benetti de Souza
 * @link      http://github.com/nfephp-org/sped-nfse for the canonical source repository
 */

use DOMDocument;
use NFePHP\NFSe\Models\Tecnos\Tools as ToolsModel;

class Tools extends ToolsModel
{
    /**
     * Webservices URL
     * @var array
     */
    protected $url = [
        1 => [

        ],
        2 => [
            'ConsultaLoteNotasTomadas' => 'http://homologatapejara.nfse-tecnos.com.br:9083',
            'ConsultaSequenciaLoteNotaRPS' => 'http://homologatapejara.nfse-tecnos.com.br:9084',
            'SubstituirNfseEnvio' => 'http://homologatapejara.nfse-tecnos.com.br:9086',
            'EnvioLoteRPSSincrono' => 'http://homologatapejara.nfse-tecnos.com.br:9091',
            'EnvioLoteNotasTomadas' => 'http://homologatapejara.nfse-tecnos.com.br:9092',
            'ConsultarNfseServicoTomadoEnvio' => 'http://homologatapejara.nfse-tecnos.com.br:9093',
            'ConsultarNfseServicoPrestadoEnvio' => 'http://homologatapejara.nfse-tecnos.com.br:9094',
            'ConsultaNFSePorRPS' => 'http://homologatapejara.nfse-tecnos.com.br:9095',
            'ConsultarNfseFaixaEnvio' => 'http://homologatapejara.nfse-tecnos.com.br:9096',
            'ConsultarLoteRpsEnvio' => 'http://homologatapejara.nfse-tecnos.com.br:9097',
            'CancelarNfseEnvio' => 'http://homologatapejara.nfse-tecnos.com.br:9098',
        ]
    ];

    /**
     * Soap Version
     * @var int
     */
    protected $soapversion = SOAP_1_2;
    /**
     * SIAFI County Cod
     * @var int
     */
    protected $codcidade = '';
    /**
     * Indicates when use CDATA string on message
     * @var boolean
     */
    protected $withcdata = true;
    /**
     * Encription signature algorithm
     * @var string
     */
    protected $algorithm = OPENSSL_ALGO_SHA1;
    /**
     * Version of schemas
     * @var int
     */
    protected $versao = 100;
    /**
     * namespaces for soap envelope
     * @var array
     */
    protected $namespaces = [
        1 => [
            'xmlns:soapenv' => "http://schemas.xmlsoap.org/soap/envelope/",
            'xmlns' => "http://tempuri.org/"
        ],
        2 => [
            'xmlns:xsi' => "http://www.w3.org/2001/XMLSchema-instance",
            'xmlns:xsd' => "http://www.w3.org/2001/XMLSchema",
            'xmlns:soap' => "http://schemas.xmlsoap.org/soap/envelope/",

        ]
    ];

    /**
     * @param $lote
     * @param $rpss
     * @return string
     */
    public function recepcionarLoteRpsSincrono($lote, $rpss)
    {
        $class = "NFePHP\\NFSe\\Models\\Tecnos\\Factories\\v{$this->versao}\\RecepcionarLoteRps";
        $fact = new $class($this->certificate);
        $this->soapAction = 'http://tempuri.org/mEnvioLoteRPSSincrono';
        return $this->recepcionarLoteRpsSincronoCommon($fact, $lote, $rpss);
    }


    /**
     * Os métodos que realizar operações no webservice precisam ser sobrescritos (Override)
     * somente para setar o soapAction espefico de cada operação (INFSEGeracao, INFSEConsultas, etc.)
     * @param $protocolo
     * @return string
     */
    public function consultarLoteRps($protocolo)
    {
        $this->soapAction = 'http://tempuri.org/ConsultarLoteRpsEnvio/';
        return parent::consultarLoteRps($protocolo);
    }

    protected function sendRequest($url, $message)
    {
        $this->xmlRequest = $message;

        if (!$url) {
            $url = $this->url[$this->config->tpAmb][$this->method];
        }
        if (!is_object($this->soap)) {
            $this->soap = new SoapCurl($this->certificate);
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($message);

        $request = $this->makeRequest($message);

        $this->params = [
            "Content-Type: text/xml; charset=utf-8;charset=utf-8;",
            "SOAPAction: \"{$this->soapAction}\""
        ];
        $action = $this->soapAction;
        return $this->soap->send(
            $url,
            $this->method,
            $action,
            $this->soapversion,
            $this->params,
            $this->namespaces[$this->soapversion],
            $request
        );
    }


}
