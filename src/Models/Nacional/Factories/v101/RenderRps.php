<?php

namespace NFePHP\NFSe\Models\Nacional\Factories\v101;

use NFePHP\Common\Certificate;
use NFePHP\Common\DOMImproved as Dom;
use NFePHP\Common\Signer;
use NFePHP\NFSe\Models\Nacional\Rps;

/**
 * Renderiza o XML de uma DPS (Declaração de Prestação de Serviços)
 * conforme o padrão NFS-e Nacional v1.01 (Receita Federal / ADN).
 * Namespace: http://www.sped.fazenda.gov.br/nfse
 */
class RenderRps
{
    protected const XMLNS = 'http://www.sped.fazenda.gov.br/nfse';

    /** @var Certificate */
    protected static $certificate;

    /** @var int */
    protected static $algorithm = OPENSSL_ALGO_SHA256;

    /**
     * Gera o XML assinado de uma DPS (SHA-256, XMLDSIG).
     */
    public static function toXml(
        Rps $rps,
        Certificate $certificate,
        int $algorithm = OPENSSL_ALGO_SHA256
    ): string {
        self::$certificate = $certificate;
        self::$algorithm = $algorithm;

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
        $root->setAttribute('versao', '1.01');
        $dom->appendChild($root);

        $infDPS = $dom->createElement('infDPS');
        $infDPS->setAttribute('Id', $rps->infId);
        $dom->appChild($root, $infDPS, 'Adicionando infDPS');

        // ── Identificação ────────────────────────────────────────────────────
        $dom->addChild($infDPS, 'tpAmb', $rps->infTpAmb, true, 'Tipo de ambiente', false);
        $dom->addChild($infDPS, 'dhEmi', $rps->infDhEmi->format('Y-m-d\TH:i:sP'), true, 'Data/hora emissão', false);
        $dom->addChild($infDPS, 'verAplic', $rps->infVerAplic, true, 'Versão aplicativo', false);
        $dom->addChild($infDPS, 'serie', $rps->infSerie, true, 'Série', false);
        $dom->addChild($infDPS, 'nDPS', $rps->infNDps, true, 'Número DPS', false);
        $dom->addChild($infDPS, 'dCompet', $rps->infDCompet, true, 'Data de competência', false);
        $dom->addChild($infDPS, 'tpEmit', $rps->infTpEmit, true, 'Tipo emitente', false);

        // ── cMotivoEmisTI e chNFSeRej (opcionais, quando tpEmit=2|3) ─────────
        if ($rps->infCMotivoEmisTI !== null) {
            $dom->addChild($infDPS, 'cMotivoEmisTI', (string) $rps->infCMotivoEmisTI, true, 'Motivo emissão por Tomador/Intermediário', false);
        }
        if (!empty($rps->infChNFSeRej)) {
            $dom->addChild($infDPS, 'chNFSeRej', $rps->infChNFSeRej, true, 'Chave da NFS-e rejeitada', false);
        }

        $dom->addChild($infDPS, 'cLocEmi', $rps->infCLocEmi, true, 'Código município emissor', false);

        // ── Substituição (opcional) ──────────────────────────────────────────
        if (!empty($rps->infSubst) && !empty($rps->infSubst['chSubstda'])) {
            $subst = $dom->createElement('subst');
            $dom->appChild($infDPS, $subst, 'Adicionando subst');
            $dom->addChild($subst, 'chSubstda', $rps->infSubst['chSubstda'], true, 'Chave NFS-e substituída', false);
            $dom->addChild($subst, 'cMotivo', $rps->infSubst['cMotivo'], true, 'Código do motivo', false);
            if (!empty($rps->infSubst['xMotivo'])) {
                $dom->addChild($subst, 'xMotivo', $rps->infSubst['xMotivo'], false, 'Descrição do motivo', false);
            }
        }

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
        $dom->addChild($regTrib, 'opSimpNac', $rps->infRegTrib['opSimpNac'], true, 'Opção Simples Nacional', false);
        $dom->addChild($regTrib, 'regEspTrib', $rps->infRegTrib['regEspTrib'], true, 'Regime Especial Tributação', false);

        // ── Tomador (opcional) — ordem TCInfoPessoa: CNPJ|CPF → IM? → xNome → end? → fone? → email? ───
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

            // Endereço nacional — TCEndereco: <endNac><cMun/><CEP/></endNac><xLgr><nro><xCpl>?<xBairro>
            if (!empty($rps->infTomadorEndereco['xLgr'])) {
                $end = $dom->createElement('end');
                $dom->appChild($toma, $end, 'Adicionando end tomador');

                $endNac = $dom->createElement('endNac');
                $dom->appChild($end, $endNac, 'Adicionando endNac');
                $dom->addChild($endNac, 'cMun', $rps->infTomadorEndereco['cMun'], true, 'Código IBGE', false);
                $dom->addChild($endNac, 'CEP', $rps->infTomadorEndereco['CEP'], true, 'CEP', false);

                $dom->addChild($end, 'xLgr', $rps->infTomadorEndereco['xLgr'], true, 'Logradouro', false);
                $dom->addChild($end, 'nro', $rps->infTomadorEndereco['nro'], true, 'Número', false);

                if (!empty($rps->infTomadorEndereco['xCpl'])) {
                    $dom->addChild($end, 'xCpl', $rps->infTomadorEndereco['xCpl'], false, 'Complemento', false);
                }

                $dom->addChild($end, 'xBairro', $rps->infTomadorEndereco['xBairro'], true, 'Bairro', false);
            }

            if (!empty($rps->infTomador['fone'])) {
                $dom->addChild($toma, 'fone', $rps->infTomador['fone'], false, 'Telefone Tomador', false);
            }

            if (!empty($rps->infTomador['email'])) {
                $dom->addChild($toma, 'email', $rps->infTomador['email'], false, 'E-mail Tomador', false);
            }
        }

        // ── Intermediário (opcional) ─────────────────────────────────────────
        if (!empty($rps->infIntermediario) && !empty($rps->infIntermediario['cnpjcpf'])) {
            $interm = $dom->createElement('interm');
            $dom->appChild($infDPS, $interm, 'Adicionando interm');

            if ($rps->infIntermediario['tipo'] == Rps::CNPJ) {
                $dom->addChild($interm, 'CNPJ', $rps->infIntermediario['cnpjcpf'], true, 'CNPJ Intermediário', false);
            } else {
                $dom->addChild($interm, 'CPF', $rps->infIntermediario['cnpjcpf'], true, 'CPF Intermediário', false);
            }

            if (!empty($rps->infIntermediario['im'])) {
                $dom->addChild($interm, 'IM', $rps->infIntermediario['im'], false, 'IM Intermediário', false);
            }
            $dom->addChild($interm, 'xNome', $rps->infIntermediario['xNome'], true, 'Nome Intermediário', false);

            if (!empty($rps->infIntermediario['fone'])) {
                $dom->addChild($interm, 'fone', $rps->infIntermediario['fone'], false, 'Telefone Intermediário', false);
            }
            if (!empty($rps->infIntermediario['email'])) {
                $dom->addChild($interm, 'email', $rps->infIntermediario['email'], false, 'E-mail Intermediário', false);
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

        // vServPrest (obrigatório)
        $vServPrest = $dom->createElement('vServPrest');
        $dom->appChild($valores, $vServPrest, 'Adicionando vServPrest');
        if ($rps->infVReceb !== null) {
            $dom->addChild($vServPrest, 'vReceb', number_format($rps->infVReceb, 2, '.', ''), false, 'Valor recebido pelo intermediário', false);
        }
        $dom->addChild($vServPrest, 'vServ', number_format($rps->infVServ, 2, '.', ''), true, 'Valor serviços', false);

        // vDescCondIncond (opcional)
        if ($rps->infVDescIncond !== null || $rps->infVDescCond !== null) {
            $vDescCI = $dom->createElement('vDescCondIncond');
            $dom->appChild($valores, $vDescCI, 'Adicionando vDescCondIncond');
            if ($rps->infVDescIncond !== null) {
                $dom->addChild($vDescCI, 'vDescIncond', number_format($rps->infVDescIncond, 2, '.', ''), false, 'Desconto incondicionado', false);
            }
            if ($rps->infVDescCond !== null) {
                $dom->addChild($vDescCI, 'vDescCond', number_format($rps->infVDescCond, 2, '.', ''), false, 'Desconto condicionado', false);
            }
        }

        // trib (obrigatório)
        $trib = $dom->createElement('trib');
        $dom->appChild($valores, $trib, 'Adicionando trib');

        // tribMun (obrigatório)
        $tribMun = $dom->createElement('tribMun');
        $dom->appChild($trib, $tribMun, 'Adicionando tribMun');
        $dom->addChild($tribMun, 'tribISSQN', (string) $rps->infTribISSQN, true, 'Tributação ISSQN', false);
        $dom->addChild($tribMun, 'tpRetISSQN', (string) $rps->infTpRetISSQN, true, 'Tipo retenção ISSQN', false);
        if ($rps->infPAliq !== null) {
            $dom->addChild($tribMun, 'pAliq', number_format($rps->infPAliq, 2, '.', ''), false, 'Alíquota ISSQN', false);
        }

        // tribFed (opcional) — retenções federais
        if ($rps->infVRetCP !== null || $rps->infVRetIRRF !== null || $rps->infVRetCSLL !== null) {
            $tribFed = $dom->createElement('tribFed');
            $dom->appChild($trib, $tribFed, 'Adicionando tribFed');
            if ($rps->infVRetCP !== null) {
                $dom->addChild($tribFed, 'vRetCP', number_format($rps->infVRetCP, 2, '.', ''), false, 'Retenção CP (INSS)', false);
            }
            if ($rps->infVRetIRRF !== null) {
                $dom->addChild($tribFed, 'vRetIRRF', number_format($rps->infVRetIRRF, 2, '.', ''), false, 'Retenção IRRF', false);
            }
            if ($rps->infVRetCSLL !== null) {
                $dom->addChild($tribFed, 'vRetCSLL', number_format($rps->infVRetCSLL, 2, '.', ''), false, 'Retenção CSLL', false);
            }
        }

        // totTrib (obrigatório) — choice: indTotTrib=0 por padrão (não informar estimado)
        $totTrib = $dom->createElement('totTrib');
        $dom->appChild($trib, $totTrib, 'Adicionando totTrib');
        $dom->addChild($totTrib, 'indTotTrib', (string) $rps->infIndTotTrib, true, 'Indicador total tributos', false);

        return $dom->saveXML();
    }
}
