<?php

namespace NFePHP\NFSe\Models\Tecnos\Factories\v100;

use NFePHP\Common\DOMImproved as Dom;
use NFePHP\NFSe\Models\Tecnos\Factories\Factory;
use NFePHP\NFSe\Models\Tecnos\Factories\Signer;

class CancelarNfse extends Factory
{
    protected $xmlns = 'http://www.abrasf.org.br/nfse.xsd';
    protected $schemeFolder = 'v100';
    protected $cmun = '0';
    public $timezone = 'America/Sao_Paulo';

    public function setXmlns(string $xmlns): void
    {
        $this->xmlns = $xmlns;
    }

    public function setSchemeFolder(string $schemeFolder): void
    {
        $this->schemeFolder = $schemeFolder;
    }

    public function setCmun(string $cmun): void
    {
        $this->cmun = $cmun;
    }


    /**
     * Método usado para gerar o XML do Soap Request
     * @param $remetenteTipoDoc
     * @param $remetenteCNPJCPF
     * @param $inscricaoMunicipal
     * @param $nfseNumero
     * @param $codigoCancelamento
     * @param $motivoCancelamento
     * @return string
     */
    public function render(

        $remetenteTipoDoc,
        $remetenteCNPJCPF,
        $inscricaoMunicipal,
        $nfseNumero,
        $codigoCancelamento,
        $motivoCancelamento
    ) 
    {
        $method = 'CancelarNfseEnvio';

        $dom = new Dom('1.0', 'utf-8');
        $dom->formatOutput = false;
        //Cria o elemento pai
        $root = $dom->createElement('CancelarNfseEnvio');
        //$root->setAttribute('xmlns', $this->xmlns);

        //Adiciona as tags ao DOM
        $dom->appendChild($root);

        $loteRps = $dom->createElement('Pedido');

        $dom->appChild($root, $loteRps, 'Adicionando tag Pedido');
        
        $InfPedidoCancelamento = $dom->createElement('InfPedidoCancelamento');

        $dom->appChild(
            $loteRps,
            $InfPedidoCancelamento,
            "Inf Pedido Cancelamento"
        );
        
        $identificacaoNfse = $dom->createElement('IdentificacaoNfse');
        
        $dom->appChild(
            $InfPedidoCancelamento,
            $identificacaoNfse,
            'Identificação da Nfse'
        );
        
        /* Inscrição Municipal */
        $dom->addChild(
            $identificacaoNfse,
            'Numero',
            $nfseNumero,
            false,
            "Numero NFse",
            false
        );

        /* CPF CNPJ */
        $cpfCnpj = $dom->createElement('CpfCnpj');

        if ($remetenteTipoDoc == '2') {
            $tag = 'Cnpj';
        } else {
            $tag = 'Cpf';
        }
        //Adiciona o Cpf/Cnpj na tag CpfCnpj
        $dom->addChild(
            $cpfCnpj,
            $tag,
            $remetenteCNPJCPF,
            true,
            "Cpf / Cnpj",
            true
        );
        $dom->appChild($identificacaoNfse, $cpfCnpj, 'Adicionando tag CpfCnpj ao Prestador');

        /* Inscrição Municipal */
        $dom->addChild(
            $identificacaoNfse,
            'InscricaoMunicipal',
            $inscricaoMunicipal,
            false,
            "Inscricao Municipal",
            false
        );
        
        /* Código do Municipio */
        $dom->addChild(
            $identificacaoNfse,
            'CodigoMunicipio',
            $this->cmun,
            false,
            "Código Municipio",
            false
        );
        
        /* Código do Cancelamento */
        $dom->addChild(
            $InfPedidoCancelamento,
            'CodigoCancelamento',
            $codigoCancelamento,
            false,
            "Código Municipio",
            false
        );

        /* Código do Cancelamento */
        $dom->addChild(
            $InfPedidoCancelamento,
            'MotivoCancelamento',
            $motivoCancelamento,
            false,
            "Motivo Cancelamento",
            false
        );

        //Parse para XML
        $xml = str_replace('<?xml version="1.0" encoding="utf-8"?>', '', $dom->saveXML());

        $body = Signer::sign(
            $this->certificate,
            $xml,
            'InfPedidoCancelamento',
            'Id',
            $this->algorithm,
            [false, false, null, null],
            'Pedido',
            true
        );
        $body = $this->clear($body);

        return $body;
    }
}
