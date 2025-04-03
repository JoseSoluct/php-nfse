<?php

namespace NFePHP\NFSe\Models\Tecnos\Factories\v100;

use NFePHP\Common\DOMImproved as Dom;
use NFePHP\NFSe\Models\Tecnos\Factories\Factory;

class ConsultarSequenciaLoteNotaRps extends Factory
{
    /**
     * Método usado para gerar o XML do Soap Request
     * @param $cpfCnpj
     * @param $razaosocial
     * @param $inscricaoMunicipal
     * @return string
     */
    public function render(
        $cpfCnpj,
        $razaosocial,
        $inscricaoMunicipal,
    )
    {
        $method = 'ConsultarSequenciaLoteNotaRPSEnvio';
        $xsd = "ConsultarSequenciaLoteNotaRPSEnvio";


        $dom = new Dom('1.0', 'utf-8');
        $dom->formatOutput = false;
        //Cria o elemento pai
        $root = $dom->createElement('ConsultarSequenciaLoteNotaRPSEnvio');
        $root->setAttribute('xmlns', 'http://www.abrasf.org.br/nfse.xsd');

        //Adiciona as tags ao DOM
        $dom->appendChild($root);

        $tag_prestador = $dom->createElement('Prestador');

        $dom->appChild($root, $tag_prestador, 'Adicionando tag Prestador');

        /* CPF CNPJ */
        $tag_cpfCnpj = $dom->createElement('CpfCnpj');

        if (strlen($cpfCnpj) === 14) {
            $tag = 'Cnpj';
        } else {
            $tag = 'Cpf';
        }
        //Adiciona o Cpf/Cnpj na tag CpfCnpj
        $dom->addChild(
            $tag_cpfCnpj,
            $tag,
            $cpfCnpj,
            true,
            "Cpf / Cnpj",
            true
        );
        $dom->appChild($tag_prestador, $tag_cpfCnpj, 'Adicionando tag CpfCnpj ao Prestador');

        $dom->addChild(
            $tag_prestador,
            'RazaoSocial',
            $razaosocial,
            false,
            "Razão Social",
            false
        );

        /* Inscrição Municipal */
        $dom->addChild(
            $tag_prestador,
            'InscricaoMunicipal',
            $inscricaoMunicipal,
            false,
            "Inscricao Municipal",
            false
        );


        //Parse para XML
        $xml = str_replace('<?xml version="1.0" encoding="utf-8"?>', '', $dom->saveXML());
        //$this->validar($versao, $body, $this->schemeFolder, $xsd, '');

        return $xml;
    }
}
