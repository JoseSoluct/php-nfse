<?php

namespace NFePHP\NFSe\Models\IPM\Factories\v100;

use InvalidArgumentException;
use stdClass;
use NFePHP\NFSe\Models\IPM\CancelarRps;
use NFePHP\NFSe\Common\DOMImproved as Dom;
use NFePHP\NFSe\Models\IPM\Factories\Signer;
use NFePHP\NFSe\Models\IPM\Factories\Factory;

class CancelarNota extends Factory
{
    /**
     * Monta o XML de cancelamento de NFS-e conforme a NTE 35/2021 v2.9 (Tabela 5).
     *
     * Tags obrigatórias entram em $dom->errors quando vazias e o render() lança
     * InvalidArgumentException antes de assinar.
     *
     * @param CancelarRps $rps
     * @param stdClass $config - usa teste, cod_tom_municipio e encoding (UTF-8|ISO-8859-1)
     * @return string
     * @throws InvalidArgumentException
     */
    public function render(
        CancelarRps $rps,
        stdClass $config
    ) {
        $dom = new Dom('1.0', 'utf-8');
        $dom->formatOutput = false;
        //Cria o elemento nfse
        $root = $dom->createElement('nfse');
        if ($this->certificate) {
            $root->setAttribute('id', 'nota');
        }

        //Adiciona as tags ao DOM
        $dom->appendChild($root);

        if (!empty($config->teste)) {
            $dom->addChild(
                $root,
                'nfse_teste',
                1,
                false,
                "Definir como teste de integração",
                true
            );
        }

        //Cria o elemento nf
        $nf = $dom->createElement('nf');

        $dom->addChild(
            $nf,
            'numero',
            $rps->infNumeroNfse,
            true,
            "Número da nota fiscal",
            true
        );

        //Tabela 5: <serie_nfse> entre <numero> e <situacao>
        $dom->addChild(
            $nf,
            'serie_nfse',
            $rps->infSerieNfse,
            true,
            "Série da nota fiscal",
            true
        );

        $dom->addChild(
            $nf,
            'situacao',
            $rps->infSituacao,
            true,
            "Status para cancelamento da nota. Deve ser preenchido como C",
            true
        );

        // A CONFIRMAR: o catálogo de erros traz [119] "Não é necessário informar as observações
        // sobre o cancelamento da NFS-e"; a doc não deixa claro se a tag vazia é aceita quando
        // o município não usa observação. Mantido o formato atual (tag sempre presente).
        $dom->addChild(
            $nf,
            'observacao',
            $rps->infObservacao ?? '',
            false,
            "Motivo do cancelamento da NFS-e",
            true
        );

        //Adiciona as tags ao DOM
        $root->appendChild($nf);

        //Cria o elemento prestador
        $prestador = $dom->createElement('prestador');

        $dom->addChild(
            $prestador,
            'cpfcnpj',
            $rps->infCpfCnpjPrestador,
            true,
            "CPF/CNPJ do emissor da nota",
            true
        );

        $dom->addChild(
            $prestador,
            'cidade',
            $config->cod_tom_municipio ?? '',
            true,
            "Código tom do municipio do emissor da nota",
            true
        );

        //Adiciona as tags ao DOM
        $root->appendChild($prestador);

        //Nada de XML incompleto segue para assinatura ou transmissão
        $this->lancarErrosDoDom($dom);

        $body = $this->clear($dom->saveXML());

        #Se prefeitura trabalhar com assinatura deve ser passado o certificado
        if ($this->certificate) {
            $body = Signer::sign(
                $this->certificate,
                $body,
                'nfse',
                'id',
                $this->algorithm,
                [false, false, null, null],
                ''
            );
        }

        $body = $this->clear($body);
        return $this->declararEncoding($body, $config);
    }
}
