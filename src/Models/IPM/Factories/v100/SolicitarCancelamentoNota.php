<?php

namespace NFePHP\NFSe\Models\IPM\Factories\v100;

use InvalidArgumentException;
use stdClass;
use NFePHP\NFSe\Models\IPM\CancelarRps;
use NFePHP\NFSe\Common\DOMImproved as Dom;
use NFePHP\NFSe\Models\IPM\Factories\Factory;

class SolicitarCancelamentoNota extends Factory
{
    /**
     * Monta o XML de solicitação de cancelamento de NFS-e conforme a NTE 35/2021 v2.9
     * (Tabela 6). Usado quando o prazo de cancelamento autônomo já expirou; a solicitação
     * passa por análise do município.
     *
     * A Tabela 6 não prevê <nfse_teste> para este serviço, por isso a tag não é emitida.
     *
     * A CONFIRMAR: esta factory não assina o XML mesmo com certificado presente. O §4.10
     * define a referência de assinatura apenas para a tag <nfse id="nota"> (emissão e
     * cancelamento); a raiz aqui é <solicitacao_cancelamento> e a doc não diz onde a
     * assinatura entraria nem se o serviço a exige.
     *
     * @param CancelarRps $rps
     * @param stdClass $config - usa cod_tom_municipio e encoding (UTF-8|ISO-8859-1)
     * @return string
     * @throws InvalidArgumentException
     */
    public function render(
        CancelarRps $rps,
        stdClass $config
    ) {
        $dom = new Dom('1.0', 'utf-8');
        $dom->formatOutput = false;
        //Cria o elemento solicitacao_cancelamento
        $root = $dom->createElement('solicitacao_cancelamento');

        //Adiciona as tags ao DOM
        $dom->appendChild($root);

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

        if (empty($rps->infDocumentos)) {
            throw new InvalidArgumentException("Deve ser informado ao menos um item de documento de cancelamento");
        }

        if (count($rps->infDocumentos) > 25) {
            throw new InvalidArgumentException("Deve ser informado até 25 itens de documento de cancelamento");
        }

        //Cria o elemento documentos
        $documentos = $dom->createElement('documentos');

        /** @var CancelarRps $documento */
        foreach ($rps->infDocumentos as $documento) {
            //Cria o elemento nfse
            $nfse = $dom->createElement('nfse');

            $dom->addChild(
                $nfse,
                'numero',
                $documento->infNumeroNfse,
                true,
                "Número da nota a ser cancelada",
                true
            );

            $dom->addChild(
                $nfse,
                'serie',
                $documento->infSerieNfse,
                true,
                "Série da nota a ser cancelada",
                true
            );

            $dom->addChild(
                $nfse,
                'observacao',
                $documento->infObservacao,
                true,
                "Motivo do cancelamento da NFS-e",
                true
            );

            //Grupo <substituta> é opcional: só sai quando informado
            if ($documento->infNumeroNfseSubstituta || $documento->infSerieNfseSubstituta) {
                //Cria o elemento substituta
                $substituta = $dom->createElement('substituta');

                $dom->addChild(
                    $substituta,
                    'numero',
                    $documento->infNumeroNfseSubstituta,
                    true,
                    "Número da nota substituta",
                    true
                );

                $dom->addChild(
                    $substituta,
                    'serie',
                    $documento->infSerieNfseSubstituta,
                    true,
                    "Série da nota substituta",
                    true
                );
                //Adiciona as tags ao DOM
                $nfse->appendChild($substituta);
            }

            //Adiciona as tags ao DOM
            $documentos->appendChild($nfse);
        }
        //Adiciona as tags ao DOM
        $root->appendChild($documentos);

        //Nada de XML incompleto segue para transmissão
        $this->lancarErrosDoDom($dom);

        $body = $this->clear($dom->saveXML());

        // A CONFIRMAR: nao assina mesmo com certificado; o §4.10 so define a referencia
        // id="nota" para a raiz <nfse>, e a doc nao diz como assinar <solicitacao_cancelamento>.
        return $this->declararEncoding($body, $config);
    }
}
