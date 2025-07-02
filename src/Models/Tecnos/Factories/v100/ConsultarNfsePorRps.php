<?php

namespace NFePHP\NFSe\Models\Tecnos\Factories\v100;

use NFePHP\Common\DOMImproved as Dom;
use NFePHP\NFSe\Models\Tecnos\Factories\Factory;

class ConsultarNfsePorRps extends Factory
{
    protected $xmlns = 'http://www.abrasf.org.br/nfse.xsd';
//    protected $xmlns = '';
    protected $schemeFolder = 'Tecnos';

    /**
     * Método usado para gerar o XML do Soap Request
     * @param $versao
     * @param $remetenteTipoDoc
     * @param $remetenteCNPJCPF
     * @param $inscricaoMunicipal
     * @param $numeroNfseInicial
     * @param $numeroNfseFinal
     * @param $pagina
     * @return mixed
     */
    public function render(
        $versao,
        $remetenteTipoDoc,
        $remetenteCNPJCPF,
        $razaoSocial,
        $inscricaoMunicipal,
        $numero,
        $serie,
        $tipo
    )
    {
        $xsd = "ConsultarNfseRpsEnvio";
        $dom = new Dom('1.0', 'utf-8');
        //Cria o elemento pai
        $root = $dom->createElement('ConsultarNfseRpsEnvio');
        //Atribui o namespace
        $root->setAttribute('xmlns', $this->xmlns);

        //Cria os dados da IdentificacaoRps
        $identificacaoRps = $dom->createElement('IdentificacaoRps');

        //Adiciona o Numero do rps na tag Numero
        $dom->addChild(
            $identificacaoRps,
            'Numero',
            $numero,
            true,
            "Numero",
            true
        );

        //Adiciona a serie do rps na tag Serie
        $dom->addChild(
            $identificacaoRps,
            'Serie',
            $serie,
            true,
            "Serie",
            true
        );

        //Adiciona o tipo do rps na tag Tipo
        $dom->addChild(
            $identificacaoRps,
            'Tipo',
            $tipo,
            true,
            "Tipo",
            true
        );

        //Adiciona a tag Prestador a consulta
        $dom->appChild($root, $identificacaoRps, 'Adicionando tag IdentificacaoRps');

        //Cria os dados do prestador
        $prestador = $dom->createElement('Prestador');

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
        $dom->appChild($prestador, $cpfCnpj, 'Adicionando tag CpfCnpj ao Prestador');

        //Adiciona a InscricaoMunicipal na tag InscricaoMunicipal
        $dom->addChild(
            $prestador,
            'RazaoSocial',
            $razaoSocial,
            true,
            "RazaoSocial",
            true
        );
        //Adiciona a InscricaoMunicipal na tag InscricaoMunicipal
        $dom->addChild(
            $prestador,
            'InscricaoMunicipal',
            $inscricaoMunicipal,
            true,
            "InscricaoMunicipal",
            true
        );

        //Adiciona a tag Prestador a consulta
        $dom->appChild($root, $prestador, 'Adicionando tag Prestador');

        //Adiciona as tags ao DOM
        $dom->appendChild($root);
        //Parse para XML
        $body = $dom->saveXML();
        $body = $this->clear($body);
        $this->validar($versao, $body, $this->schemeFolder, $xsd, '');

        return $body;
    }

}
