<?php

namespace NFePHP\NFSe\Models\Nacional\Factories\v100;

use NFePHP\Common\Certificate;
use NFePHP\Common\DOMImproved as Dom;
use NFePHP\Common\Signer;
use NFePHP\NFSe\Models\Nacional\Rps;

/**
 * Renderiza o XML de uma DPS (Declaração de Prestação de Serviços)
 * conforme o padrão NFS-e Nacional v1.00
 * Namespace: http://www.sped.fazenda.gov.br/nfse
 */
class RenderRps
{
    protected const XMLNS = 'http://www.sped.fazenda.gov.br/nfse';

    /** @var Certificate */
    protected static $certificate;

    /** @var int */
    protected static $algorithm = OPENSSL_ALGO_SHA1;

    /**
     * Gera o XML assinado de uma DPS.
     */
    public static function toXml(
        Rps $rps,
        Certificate $certificate,
        int $algorithm = OPENSSL_ALGO_SHA1
    ): string {
        self::$certificate = $certificate;
        self::$algorithm   = $algorithm;

        $xml = self::render($rps);

        return Signer::sign(
            self::$certificate,
            $xml,
            'infDPS',
            'Id',
            self::$algorithm,
            [false, false, null, null]
        );
    }

    /**
     * Monta o DOM da DPS e retorna o XML sem assinatura.
     */
    public static function render(Rps $rps): string
    {
        $dom = new Dom('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $root = $dom->createElement('DPS');
        $root->setAttribute('xmlns', static::XMLNS);
        $dom->appendChild($root);

        $infDPS = $dom->createElement('infDPS');
        $infDPS->setAttribute('Id', $rps->infId);
        $dom->appChild($root, $infDPS, 'Adicionando infDPS');

        // ── Identificação ────────────────────────────────────────────────────
        $dom->addChild($infDPS, 'tpAmb',    $rps->infTpAmb,   true, 'Tipo de ambiente',         false);
        $dom->addChild($infDPS, 'dhEmi',    $rps->infDhEmi->format('Y-m-d\TH:i:sP'), true, 'Data/hora emissão', false);
        $dom->addChild($infDPS, 'verAplic', $rps->infVerAplic, true, 'Versão aplicativo',        false);
        $dom->addChild($infDPS, 'serie',    $rps->infSerie,    true, 'Série',                    false);
        $dom->addChild($infDPS, 'nDPS',     $rps->infNDps,     true, 'Número DPS',               false);
        $dom->addChild($infDPS, 'dCompet',  $rps->infDCompet,  true, 'Data de competência',      false);
        $dom->addChild($infDPS, 'tpEmit',   $rps->infTpEmit,   true, 'Tipo emitente',            false);
        $dom->addChild($infDPS, 'cLocEmi',  $rps->infCLocEmi,  true, 'Código município emissor', false);

        // ── Prestador ────────────────────────────────────────────────────────
        $prest = $dom->createElement('prest');
        $dom->appChild($infDPS, $prest, 'Adicionando prest');

        if ($rps->infPrestador['tipo'] == Rps::CNPJ) {
            $dom->addChild($prest, 'CNPJ', $rps->infPrestador['cnpjcpf'], true, 'CNPJ Prestador', false);
        } else {
            $dom->addChild($prest, 'CPF', $rps->infPrestador['cnpjcpf'], true, 'CPF Prestador', false);
        }

        if (!empty($rps->infPrestador['im'])) {
            $dom->addChild($prest, 'IM', $rps->infPrestador['im'], false, 'Inscrição Municipal', false);
        }

        $dom->addChild($prest, 'xNome', $rps->infPrestador['xNome'], true, 'Razão Social', false);

        $regTrib = $dom->createElement('regTrib');
        $dom->appChild($prest, $regTrib, 'Adicionando regTrib');
        $dom->addChild($regTrib, 'opSimpNac',  $rps->infRegTrib['opSimpNac'],  true, 'Opção Simples Nacional',     false);
        $dom->addChild($regTrib, 'regEspTrib', $rps->infRegTrib['regEspTrib'], true, 'Regime Especial Tributação', false);

        // ── Tomador (opcional) ───────────────────────────────────────────────
        if (!empty($rps->infTomador['cnpjcpf']) || !empty($rps->infTomador['xNome'])) {
            $toma = $dom->createElement('toma');
            $dom->appChild($infDPS, $toma, 'Adicionando toma');

            if (!empty($rps->infTomador['cnpjcpf'])) {
                if ($rps->infTomador['tipo'] == Rps::CNPJ) {
                    $dom->addChild($toma, 'CNPJ', $rps->infTomador['cnpjcpf'], true, 'CNPJ Tomador', false);
                } else {
                    $dom->addChild($toma, 'CPF', $rps->infTomador['cnpjcpf'], true, 'CPF Tomador', false);
                }
            }

            if (!empty($rps->infTomador['im'])) {
                $dom->addChild($toma, 'IM', $rps->infTomador['im'], false, 'IM Tomador', false);
            }

            if (!empty($rps->infTomador['xNome'])) {
                $dom->addChild($toma, 'xNome', $rps->infTomador['xNome'], true, 'Nome Tomador', false);
            }

            if (!empty($rps->infTomador['fone'])) {
                $dom->addChild($toma, 'fone', $rps->infTomador['fone'], false, 'Telefone Tomador', false);
            }

            if (!empty($rps->infTomador['email'])) {
                $dom->addChild($toma, 'email', $rps->infTomador['email'], false, 'E-mail Tomador', false);
            }

            if (!empty($rps->infTomadorEndereco['xLgr'])) {
                $end = $dom->createElement('end');
                $dom->appChild($toma, $end, 'Adicionando end tomador');
                $dom->addChild($end, 'xLgr',   $rps->infTomadorEndereco['xLgr'],    true,  'Logradouro',  false);
                $dom->addChild($end, 'nro',     $rps->infTomadorEndereco['nro'],     true,  'Número',      false);

                if (!empty($rps->infTomadorEndereco['xCpl'])) {
                    $dom->addChild($end, 'xCpl', $rps->infTomadorEndereco['xCpl'],  false, 'Complemento', false);
                }

                $dom->addChild($end, 'xBairro', $rps->infTomadorEndereco['xBairro'], true,  'Bairro',      false);
                $dom->addChild($end, 'cMun',     $rps->infTomadorEndereco['cMun'],    true,  'Código IBGE', false);
                $dom->addChild($end, 'UF',       $rps->infTomadorEndereco['UF'],      true,  'UF',          false);
                $dom->addChild($end, 'CEP',      $rps->infTomadorEndereco['CEP'],     true,  'CEP',         false);
            }
        }

        // ── Serviço ──────────────────────────────────────────────────────────
        $serv = $dom->createElement('serv');
        $dom->appChild($infDPS, $serv, 'Adicionando serv');

        $locPrest = $dom->createElement('locPrest');
        $dom->appChild($serv, $locPrest, 'Adicionando locPrest');
        $dom->addChild($locPrest, 'cLocPrestacao', $rps->infLocPrest['cLocPrestacao'], true, 'Código local prestação', false);

        $cServ = $dom->createElement('cServ');
        $dom->appChild($serv, $cServ, 'Adicionando cServ');
        $dom->addChild($cServ, 'cTribNac', $rps->infCServ['cTribNac'], true, 'Código tributação nacional', false);

        if (!empty($rps->infCServ['cTribMun'])) {
            $dom->addChild($cServ, 'cTribMun', $rps->infCServ['cTribMun'], false, 'Código tributação municipal', false);
        }

        $dom->addChild($cServ, 'xDescServ', $rps->infCServ['xDescServ'], true, 'Discriminação do serviço', false);

        if (!empty($rps->infCServ['cNBS'])) {
            $dom->addChild($cServ, 'cNBS', $rps->infCServ['cNBS'], false, 'Código NBS', false);
        }

        // ── Valores ──────────────────────────────────────────────────────────
        $valores = $dom->createElement('valores');
        $dom->appChild($infDPS, $valores, 'Adicionando valores');

        $dom->addChild($valores, 'vServ',      number_format($rps->infValores['vServ'],      2, '.', ''), true,  'Valor serviços',     false);
        $dom->addChild($valores, 'vBC',        number_format($rps->infValores['vBC'],        2, '.', ''), true,  'Base cálculo ISSQN', false);
        $dom->addChild($valores, 'pAliqAplic', number_format($rps->infValores['pAliqAplic'], 4, '.', ''), true,  'Alíquota aplicada',  false);
        $dom->addChild($valores, 'vISSQN',     number_format($rps->infValores['vISSQN'],     2, '.', ''), true,  'Valor ISSQN',        false);
        $dom->addChild($valores, 'vLiq',       number_format($rps->infValores['vLiq'],       2, '.', ''), true,  'Valor líquido',      false);

        if ($rps->infValores['descIncond'] > 0) {
            $dom->addChild($valores, 'descIncond', number_format($rps->infValores['descIncond'], 2, '.', ''), false, 'Desconto incondicionado', false);
        }

        if ($rps->infValores['descCond'] > 0) {
            $dom->addChild($valores, 'descCond', number_format($rps->infValores['descCond'], 2, '.', ''), false, 'Desconto condicionado', false);
        }

        if ($rps->infValores['vTotalRet'] > 0) {
            $dom->addChild($valores, 'vTotalRet', number_format($rps->infValores['vTotalRet'], 2, '.', ''), false, 'Total retenções', false);
        }

        if ($rps->infValores['vRetIRRF'] > 0) {
            $dom->addChild($valores, 'vRetIRRF', number_format($rps->infValores['vRetIRRF'], 2, '.', ''), false, 'Retenção IRRF', false);
        }

        if ($rps->infValores['vRetCSLL'] > 0) {
            $dom->addChild($valores, 'vRetCSLL', number_format($rps->infValores['vRetCSLL'], 2, '.', ''), false, 'Retenção CSLL', false);
        }

        if ($rps->infValores['vRetCP'] > 0) {
            $dom->addChild($valores, 'vRetCP', number_format($rps->infValores['vRetCP'], 2, '.', ''), false, 'Retenção CP', false);
        }

        $xml = $dom->saveXML();

        return str_replace('<?xml version="1.0" encoding="UTF-8"?>', '', $xml);
    }
}
