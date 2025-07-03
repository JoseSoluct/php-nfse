<?php

namespace NFePHP\NFSe\Models\Tecnos;

/**
 * Classe para a comunicação com os webservices
 * conforme o modelo Tecnos
 *
 * @category  NFePHP
 * @package   NFePHP\NFSe\Models\Tecnos\Tools
 * @copyright NFePHP Copyright (c) 2016
 * @license   http://www.gnu.org/licenses/lgpl.txt LGPLv3+
 * @license   https://opensource.org/licenses/MIT MIT
 * @license   http://www.gnu.org/licenses/gpl.txt GPLv3+
 * @author Jose Alcides
 * @link      http://github.com/nfephp-org/sped-nfse for the canonical source repository
 */

use DOMDocument;
use LogicException;
use NFePHP\Common\Soap\SoapCurl as SoapCurl;
use NFePHP\NFSe\Common\Tools as ToolsBase;

class Tools extends ToolsBase
{
    protected $xmlns = 'http://www.abrasf.org.br/nfse.xsd';
    protected $soapAction = '';
    protected $schemeFolder = 'Tecnos';
    protected $params = [];

    /**
     * @param $lote
     * @param $rpss
     * @return string
     */
    public function recepcionarLoteRpsSincrono($lote, $rpss)
    {
        $class = "NFePHP\\NFSe\\Models\\Tecnos\\Factories\\v{$this->versao}\\RecepcionarLoteRpsSincrono";
        $fact = new $class($this->certificate);

        return $this->recepcionarLoteRpsSincronoCommon($fact, $lote, $rpss);
    }

    /**
     * @param Factories\RecepcionarLoteRpsSincrono $fact
     * @param $lote
     * @param $rpss
     * @param string $url
     * @return string
     */
    protected function recepcionarLoteRpsSincronoCommon($fact, $lote, $rpss, $url = '')
    {
        $this->method = 'EnvioLoteRPSSincrono';
        $fact->setSignAlgorithm($this->algorithm);
        $fact->setTimezone($this->timezone);
        $message = $fact->render(
            $this->versao,
            $this->remetenteTipoDoc,
            $this->remetenteCNPJCPF,
            $this->remetenteIM,
            $lote,
            $rpss
        );
        $this->xmlns = 'http://tempuri.org/';
        return $this->sendRequest($url, $message);
    }

    /**
     * Monta o request da mensagem SOAP
     * @param string $url
     * @param string $message
     * @return string
     */
    protected function sendRequest($url, $message)
    {
        $this->xmlRequest = $message;

        if (!$url) {
            $url = $this->url[$this->config->tpAmb][$this->method];
        }
        if (!is_object($this->soap)) {
            $this->soap = new SoapCurl($this->certificate);
        }
        //formata o xml da mensagem para o padão esperado pelo webservice
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $dom->loadXML($message);

        $message = str_replace('<?xml version="1.0"?>', '', $dom->saveXML());

        $messageText = $message;
        if ($this->withcdata) {
            $messageText = $this->stringTransform($message);
        }
        $request = $this->makeRequest($messageText);
        //Realiza o request SOAP
        return $this->soap->send(
            $url,
            $this->method,
            $this->soapAction,
            $this->soapversion,
            $this->params,
            $this->namespaces[$this->soapversion],
            $request
        );
    }

    /**
     * @param $message
     * @return string
     */
    protected function makeRequest($message)
    {
        $versao = '20.01';
        switch ($this->versao) {
            case 100:
                $request =
                    "<m{$this->method} xmlns=\"{$this->xmlns}\">"
                    . "<remessa>"
                    . "<![CDATA["
                    . $message
                    . "]]>"
                    . "</remessa>"
                    . "<cabecalho>"
                    . "<![CDATA["
                    . "<cabecalho xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns:xsd=\"http://www.w3.org/2001/XMLSchema\" xmlns=\"http://www.abrasf.org.br/nfse.xsd\"><versaoDados>{$versao}</versaoDados></cabecalho>"
                    . "]]>"
                    . "</cabecalho>"
                    . "</m{$this->method}>";
                break;
            default:
                throw new LogicException('Versão não suportada');
        }
        return $request;
    }

    /**
     * Consulta Lote
     * @return string
     */
    public function consultarSequenciaLoteNota()
    {
        $class = "NFePHP\\NFSe\\Models\\Tecnos\\Factories\\v{$this->versao}\\ConsultarSequenciaLoteNotaRps";
        $fact = new $class($this->certificate);
        return $this->consultarSequenciaLoteNotaCommom($fact);
    }

    /**
     * @param $fact
     * @param $protocolo
     * @return string
     */
    protected function consultarSequenciaLoteNotaCommom($fact, $url = '')
    {
        $this->method = 'ConsultaSequenciaLoteNotaRPS';
        $this->setXmlns($this->xmlns);
        $message = $fact->render($this->remetenteCNPJCPF, $this->remetenteRazao, $this->remetenteIM);
        $this->soapAction = 'http://tempuri.org/mConsultaSequenciaLoteNotaRPS';
        $this->xmlns = 'http://tempuri.org/';
        return $this->sendRequest($url, $message);
    }

    /**
     * @param $xmlns
     */
    public function setXmlns($xmlns)
    {
        $this->xmlns = $xmlns;
    }

    /**
     * @param $numero
     * @param $serie
     * @param $tipo
     * @param string $url
     * @return string
     */
    public function consultarNfsePorRps($numero, $serie, $tipo)
    {
        $class = "NFePHP\\NFSe\\Models\\Tecnos\\Factories\\v{$this->versao}\\ConsultarNfsePorRps";
        $fact = new $class($this->certificate);
        return $this->consultarNfsePorRpsCommon($fact, $numero, $serie, $tipo);
    }

    /**
     * @param $fact
     * @param $numero
     * @param $serie
     * @param $tipo
     * @param string $url
     * @return string
     */
    protected function consultarNfsePorRpsCommon($fact, $numero, $serie, $tipo, $url = '')
    {
        $this->method = 'ConsultaNFSePorRPS';
        $this->soapAction = 'http://tempuri.org/mConsultaNFSePorRPS';
        $this->xmlns = 'http://tempuri.org/';
        $message = $fact->render($this->versao, $this->remetenteTipoDoc, $this->remetenteCNPJCPF, $this->remetenteRazao, $this->remetenteIM, $numero, $serie, $tipo);
        return $this->sendRequest($url, $message);
    }

    /**
     * Consulta Lote
     * @param string $nfseNumero
     * @return string
     */
    public function cancelarNfse($codigoMunicipio, $nfseNumero, $codigoCancelamento, $motivoCancelamento)
    {
        $class = "NFePHP\\NFSe\\Models\\Tecnos\\Factories\\v{$this->versao}\\CancelarNfse";
        $fact = new $class($this->certificate);
        return $this->cancelarNfseCommon($fact, $codigoMunicipio, $nfseNumero, $codigoCancelamento, $motivoCancelamento);
    }

    /**
     * @param $fact
     * @param string $nfseNumero
     * @param string $url
     * @return string
     */
    protected function cancelarNfseCommon($fact, $codigoMunicipio, $nfseNumero, $codigoCancelamento, $motivoCancelamento, $url = '')
    {
        $this->method = 'CancelarNfse';
        $fact->setXmlns($this->xmlns);
        $fact->setSchemeFolder($this->schemeFolder);
        $fact->setCodMun($this->config->cmun);
        $fact->setSignAlgorithm($this->algorithm);
        $fact->setTimezone($this->timezone);
        $message = $fact->render($this->remetenteTipoDoc, $this->remetenteCNPJCPF, $codigoMunicipio, $this->remetenteIM, $nfseNumero, $codigoCancelamento, $motivoCancelamento);
        return $this->sendRequest($url, $message);
    }
//
//    /**
//     * @param $numeroNfseInicial
//     * @param $numeroNfseFinal
//     * @param $pagina
//     * @return string
//     */
//    public function consultarNfsePorFaixa($numeroNfseInicial, $numeroNfseFinal, $pagina)
//    {
//        $class = "NFePHP\\NFSe\\Models\\Tecnos\\Factories\\v{$this->versao}\\ConsultarNfsePorFaixa";
//        $fact = new $class($this->certificate);
//        return $this->consultarNfsePorFaixaCommon($fact, $numeroNfseInicial, $numeroNfseFinal, $pagina);
//    }
//    /**
//     * @param $fact
//     * @param $numeroNfseInicial
//     * @param $numeroNfseFinal
//     * @param $pagina
//     * @param string $url
//     * @return string
//     */
//    protected function consultarNfsePorFaixaCommon($fact, $numeroNfseInicial, $numeroNfseFinal, $pagina, $url = '')
//    {
//        $this->method = 'ConsultarNfseFaixa';
//        $fact->setXmlns($this->xmlns);
//        $fact->setSchemeFolder($this->schemeFolder);
//        $fact->setCodMun($this->config->cmun);
//        $fact->setSignAlgorithm($this->algorithm);
//        $fact->setTimezone($this->timezone);
//        $message = $fact->render(
//            $this->versao,
//            $this->remetenteTipoDoc,
//            $this->remetenteCNPJCPF,
//            $this->remetenteIM,
//            $numeroNfseInicial,
//            $numeroNfseFinal,
//            $pagina
//        );
//        return $this->sendRequest($url, $message);
//    }

//    /**
//     * @param $numero
//     * @param $serie
//     * @param $tipo
//     * @param string $url
//     * @return string
//     */
//    public function consultarNfsePorRps($numero, $serie, $tipo)
//    {
//        $class = "NFePHP\\NFSe\\Models\\Tecnos\\Factories\\v{$this->versao}\\ConsultarNfsePorRps";
//        $fact = new $class($this->certificate);
//        return $this->consultarNfsePorRpsCommon($fact, $numero, $serie, $tipo);
//    }
//
//    /**
//     * @param $fact
//     * @param $numero
//     * @param $serie
//     * @param $tipo
//     * @param string $url
//     * @return string
//     */
//    protected function consultarNfsePorRpsCommon($fact, $numero, $serie, $tipo,  $url = '')
//    {
//        $this->method = 'ConsultarNfsePorRps';
//        $fact->setXmlns($this->xmlns);
//        $fact->setSchemeFolder($this->schemeFolder);
//        $fact->setCodMun($this->config->cmun);
//        $fact->setSignAlgorithm($this->algorithm);
//        $fact->setTimezone($this->timezone);
//        $message = $fact->render($this->versao, $this->remetenteTipoDoc, $this->remetenteCNPJCPF, $this->remetenteIM, $numero, $serie, $tipo);
//        return $this->sendRequest($url, $message);
//    }
//
//    /**
//     * @param NfseServicoPrestado $nsPrestado
//     * @param string $url
//     * @return string
//     */
//    public function consultarNfseServicoPrestado(NfseServicoPrestado $nsPrestado)
//    {
//        $class = "NFePHP\\NFSe\\Models\\Tecnos\\Factories\\v{$this->versao}\\ConsultarNfseServicoPrestado";
//        $fact = new $class($this->certificate);
//        return $this->consultarNfseServicoPrestadoCommon($fact, $nsPrestado);
//    }
//
//    /**
//     * @param $fact
//     * @param NfseServicoPrestado $nsPrestado
//     * @return string
//     */
//    public function consultarNfseServicoPrestadoCommon($fact, NfseServicoPrestado $nsPrestado, $url = '')
//    {
//        $this->method = 'ConsultarNfseServicoPrestado';
//        $fact->setXmlns($this->xmlns);
//        $fact->setSchemeFolder($this->schemeFolder);
//        $fact->setCodMun($this->config->cmun);
//        $fact->setSignAlgorithm($this->algorithm);
//        $fact->setTimezone($this->timezone);
//        $message = $fact->render($this->versao, $nsPrestado);
//        return $this->sendRequest($url, $message);
//    }
//
//    /**
//     * @param NfseServicoTomado $nsTomado
//     * @param string $url
//     * @return string
//     */
//    public function consultarNfseServicoTomado(NfseServicoTomado $nsTomado)
//    {
//        $class = "NFePHP\\NFSe\\Models\\Tecnos\\Factories\\v{$this->versao}\\ConsultarNfseServicoTomado";
//        $fact = new $class($this->certificate);
//        return $this->consultarNfseServicoTomadoCommon($fact, $nsTomado);
//    }
//
//    /**
//     * @param $fact
//     * @param NfseServicoTomado $nsTomado
//     * @return string
//     */
//    public function consultarNfseServicoTomadoCommon($fact, NfseServicoTomado $nsTomado, $url = '')
//    {
//        $this->method = 'ConsultarNfseServicoTomado';
//        $fact->setXmlns($this->xmlns);
//        $fact->setSchemeFolder($this->schemeFolder);
//        $fact->setCodMun($this->config->cmun);
//        $fact->setSignAlgorithm($this->algorithm);
//        $fact->setTimezone($this->timezone);
//        $message = $fact->render($this->versao, $nsTomado);
//        return $this->sendRequest($url, $message);
//    }
//
//
//
//    /**
//     * @param $lote
//     * @param $rpss
//     * @return string
//     */
//    public function recepcionarLoteRps($lote, $rpss)
//    {
//        $class = "NFePHP\\NFSe\\Models\\Tecnos\\Factories\\v{$this->versao}\\RecepcionarLoteRps";
//        $fact = new $class($this->certificate);
//
//        return $this->recepcionarLoteRpsCommon($fact, $lote, $rpss);
//    }
//
//    /**
//     * @param Factories\RecepcionarLoteRps $fact
//     * @param $lote
//     * @param $rpss
//     * @param string $url
//     * @return string
//     */
//    protected function recepcionarLoteRpsCommon(Factories\RecepcionarLoteRps $fact, $lote, $rpss, $url = '')
//    {
//        $this->method = 'RecepcionarLoteRps';
//        $fact->setXmlns($this->xmlns);
//        $fact->setSchemeFolder($this->schemeFolder);
//        $fact->setCodMun($this->config->cmun);
//        $fact->setSignAlgorithm($this->algorithm);
//        $fact->setTimezone($this->timezone);
//        $message = $fact->render(
//            $this->versao,
//            $this->remetenteTipoDoc,
//            $this->remetenteCNPJCPF,
//            $this->remetenteIM,
//            $lote,
//            $rpss
//        );
//
//        // @header ("Content-Disposition: attachment; filename=\"NFSe_Lote.xml\"" );
//        // echo $message;
//        // exit;
//        return $this->sendRequest($url, $message);
//    }
//

//    }
//
//    public function substituirNfse()
//    {
//    }

//    /**
//     * Consulta Lote
//     * @param string $nfseNumero
//     * @return string
//     */
//    public function cancelarNfse($nfseNumero)
//    {
//        $class = "NFePHP\\NFSe\\Models\\Tecnos\\Factories\\v{$this->versao}\\CancelarNfse";
//        $fact = new $class($this->certificate);
//        return $this->cancelarNfseCommon($fact, $nfseNumero);
//    }
//
//    /**
//     * @param $fact
//     * @param string $nfseNumero
//     * @param string $url
//     * @return string
//     */
//    protected function cancelarNfseCommon($fact, $nfseNumero, $url = '')
//    {
//        $this->method = 'CancelarNfse';
//        $fact->setXmlns($this->xmlns);
//        $fact->setSchemeFolder($this->schemeFolder);
//        $fact->setCodMun($this->config->cmun);
//        $fact->setSignAlgorithm($this->algorithm);
//        $fact->setTimezone($this->timezone);
//        $message = $fact->render($this->versao, $this->remetenteTipoDoc, $this->remetenteCNPJCPF, $this->remetenteIM, $nfseNumero);
//        return $this->sendRequest($url, $message);
//    }
//
//    /**
//     * Consulta Lote
//     * @param string $protocolo
//     * @return string
//     */
//    public function consultarLoteRps($protocolo)
//    {
//        $class = "NFePHP\\NFSe\\Models\\Tecnos\\Factories\\v{$this->versao}\\ConsultarLoteRps";
//        $fact = new $class($this->certificate);
//        return $this->consultarLoteRpsCommon($fact, $protocolo);
//    }
//
//    /**
//     * @param $fact
//     * @param $protocolo
//     * @param string $url
//     * @return string
//     */
//    protected function consultarLoteRpsCommon($fact, $protocolo, $url = '')
//    {
//        $this->method = 'ConsultarLoteRps';
//        $fact->setXmlns($this->xmlns);
//        $message = $fact->render($this->remetenteCNPJCPF, $this->remetenteIM, $protocolo);
//        return $this->sendRequest($url, $message);
//    }
}
