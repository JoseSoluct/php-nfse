<?php

namespace NFePHP\NFSe\Models\Nacional\Factories\v101;

use NFePHP\Common\Certificate;
use NFePHP\Common\DOMImproved as Dom;
use NFePHP\Common\Signer;
use NFePHP\NFSe\Models\Nacional\Evento;

/**
 * Renderiza o XML de um pedido de registro de evento (pedRegEvento)
 * conforme o padrão NFS-e Nacional v1.01 — Receita Federal / ADN.
 *
 * Estrutura alvo:
 *   <pedRegEvento versao="1.01">
 *     <infPedReg Id="PRE{chave50}{tipoEvento6}">
 *       <tpAmb/>
 *       <verAplic/>
 *       <dhEvento/>
 *       <CNPJAutor|CPFAutor/>
 *       <chNFSe/>
 *       <e{tipoEvento}> ... payload específico ... </e{tipoEvento}>
 *     </infPedReg>
 *     <ds:Signature/>
 *   </pedRegEvento>
 *
 * A assinatura XMLDSIG com SHA-256 é aplicada à tag `infPedReg`.
 */
class RenderEvento
{
    protected const XMLNS = 'http://www.sped.fazenda.gov.br/nfse';

    /**
     * Gera o XML assinado do pedido de registro de evento.
     *
     * Reintroduz o prólogo XML após a assinatura (Signer::sign usa LIBXML_NOXMLDECL),
     * pois o Sefin Nacional rejeita XML sem declaração de encoding (E1229).
     */
    public static function toXml(
        Evento $evento,
        Certificate $certificate,
        int $algorithm = OPENSSL_ALGO_SHA256
    ): string {
        $xml = self::render($evento);

        $signed = Signer::sign(
            $certificate,
            $xml,
            'infPedReg',
            'Id',
            $algorithm,
            [false, false, null, null]
        );

        return '<?xml version="1.0" encoding="UTF-8"?>' . $signed;
    }

    /**
     * Monta o DOM do pedRegEvento e retorna o XML sem assinatura.
     */
    public static function render(Evento $evento): string
    {
        self::guardCompleteness($evento);

        $dom = new Dom('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $root = $dom->createElement('pedRegEvento');
        $root->setAttribute('xmlns', self::XMLNS);
        $root->setAttribute('versao', '1.01');
        $dom->appendChild($root);

        $infPedReg = $dom->createElement('infPedReg');
        $infPedReg->setAttribute('Id', $evento->infId);
        $dom->appChild($root, $infPedReg, 'Adicionando infPedReg');

        $dom->addChild($infPedReg, 'tpAmb', (string) $evento->infTpAmb, true, 'Tipo de ambiente', false);
        $dom->addChild($infPedReg, 'verAplic', $evento->infVerAplic, true, 'Versão do aplicativo', false);
        $dom->addChild($infPedReg, 'dhEvento', $evento->infDhEvento->format('Y-m-d\TH:i:sP'), true, 'Data/hora do evento', false);

        $autorTag = $evento->infAutorTipo === Evento::AUTOR_CNPJ ? 'CNPJAutor' : 'CPFAutor';
        $dom->addChild($infPedReg, $autorTag, $evento->infAutorDoc, true, 'Autor do evento', false);

        $dom->addChild($infPedReg, 'chNFSe', $evento->infChNFSe, true, 'Chave da NFS-e', false);

        // Payload específico do evento: <e{tipoEvento}>
        $tagEvento = 'e' . $evento->infTpEvento;
        $eventoNode = $dom->createElement($tagEvento);
        $dom->appChild($infPedReg, $eventoNode, 'Adicionando payload do evento');

        self::appendPayload($dom, $eventoNode, $evento);

        return $dom->saveXML();
    }

    /**
     * Acrescenta os elementos do payload do evento na ordem correta do XSD.
     */
    private static function appendPayload(Dom $dom, \DOMElement $parent, Evento $evento): void
    {
        $payload = $evento->infEventoPayload;

        // xDesc é o primeiro elemento em todos os tipos
        $dom->addChild($parent, 'xDesc', $payload['xDesc'] ?? '', true, 'Descrição do evento', false);

        switch ($evento->infTpEvento) {
            case Evento::TP_CANCELAMENTO:
            case Evento::TP_SOLICITACAO_ANALISE_FISCAL:
                $dom->addChild($parent, 'cMotivo', $payload['cMotivo'], true, 'Código motivo', false);
                $dom->addChild($parent, 'xMotivo', $payload['xMotivo'], true, 'Descrição motivo', false);
                break;

            case Evento::TP_CANCELAMENTO_SUBSTITUICAO:
                $dom->addChild($parent, 'cMotivo', $payload['cMotivo'], true, 'Código motivo', false);
                if (!empty($payload['xMotivo'])) {
                    $dom->addChild($parent, 'xMotivo', $payload['xMotivo'], false, 'Descrição motivo', false);
                }
                $dom->addChild($parent, 'chSubstituta', $payload['chSubstituta'], true, 'Chave NFS-e substituta', false);
                break;

            case Evento::TP_REJEICAO_PRESTADOR:
            case Evento::TP_REJEICAO_TOMADOR:
            case Evento::TP_REJEICAO_INTERMEDIARIO:
                $dom->addChild($parent, 'cMotivo', $payload['cMotivo'], true, 'Código motivo', false);
                if (!empty($payload['xMotivo'])) {
                    $dom->addChild($parent, 'xMotivo', $payload['xMotivo'], false, 'Descrição motivo', false);
                }
                break;

            case Evento::TP_CONFIRMACAO_PRESTADOR:
            case Evento::TP_CONFIRMACAO_TOMADOR:
            case Evento::TP_CONFIRMACAO_INTERMEDIARIO:
                // Apenas xDesc — nenhum outro elemento.
                break;
        }
    }

    private static function guardCompleteness(Evento $evento): void
    {
        $missing = [];
        if (empty($evento->infId)) {
            $missing[] = 'infId (chame gerarId() após definir chNFSe e tipo)';
        }
        if (empty($evento->infTpAmb)) {
            $missing[] = 'tpAmb';
        }
        if (empty($evento->infDhEvento)) {
            $missing[] = 'dhEvento';
        }
        if (empty($evento->infAutorTipo) || empty($evento->infAutorDoc)) {
            $missing[] = 'autor';
        }
        if (empty($evento->infChNFSe)) {
            $missing[] = 'chNFSe';
        }
        if (empty($evento->infTpEvento) || empty($evento->infEventoPayload)) {
            $missing[] = 'tipo de evento (chame cancelamento(), confirmacaoPrestador(), etc.)';
        }

        if (!empty($missing)) {
            throw new \LogicException('Evento incompleto. Faltando: ' . implode(', ', $missing));
        }
    }
}
