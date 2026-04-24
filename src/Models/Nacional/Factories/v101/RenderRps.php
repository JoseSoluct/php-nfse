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
     *
     * O Signer do nfephp-common emite o XML com LIBXML_NOXMLDECL (sem prólogo),
     * padrão herdado do ABRASF/NFe. O Sefin Nacional exige a declaração XML com
     * encoding UTF-8 (rejeita com E1229), então reintroduzimos o prólogo aqui.
     */
    public static function toXml(
        Rps $rps,
        Certificate $certificate,
        int $algorithm = OPENSSL_ALGO_SHA256
    ): string {
        self::$certificate = $certificate;
        self::$algorithm = $algorithm;

        $xml = self::render($rps);

        $signed = Signer::sign(
            self::$certificate,
            $xml,
            'infDPS',
            'Id',
            self::$algorithm,
            [false, false, null, null]
        );

        return '<?xml version="1.0" encoding="UTF-8"?>' . $signed;
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

        // ── Prestador (TCInfoPrestador) ──────────────────────────────────────
        // Ordem: choice(CNPJ|CPF|NIF|cNaoNIF) → CAEPF? → IM? → xNome? → end? → fone? → email? → regTrib
        //
        // Quando o próprio prestador é o emitente da DPS (tpEmit=1), o ADN busca
        // identificação, endereço e contatos da base da RFB; informar esses
        // campos é proibido (E0121 xNome, E0128 end, e provavelmente E0129/130
        // para fone/email). Mantemos somente CNPJ/CPF, CAEPF, IM e regTrib.
        $prestadorEhEmitente = (int) $rps->infTpEmit === 1;

        $prest = $dom->createElement('prest');
        $dom->appChild($infDPS, $prest, 'Adicionando prest');
        self::appendDocIdentificacao($dom, $prest, $rps->infPrestador, 'Prestador');

        if (!empty($rps->infPrestador['caepf'])) {
            $dom->addChild($prest, 'CAEPF', $rps->infPrestador['caepf'], false, 'CAEPF Prestador', false);
        }
        if (!empty($rps->infPrestador['im'])) {
            $dom->addChild($prest, 'IM', $rps->infPrestador['im'], false, 'Inscrição Municipal', false);
        }
        if (! $prestadorEhEmitente && !empty($rps->infPrestador['xNome'])) {
            $dom->addChild($prest, 'xNome', $rps->infPrestador['xNome'], false, 'Razão Social', false);
        }
        if (! $prestadorEhEmitente && is_array($rps->infPrestadorEndereco) && !empty($rps->infPrestadorEndereco['xLgr'])) {
            self::appendEnderecoNacional($dom, $prest, $rps->infPrestadorEndereco);
        }
        if (! $prestadorEhEmitente && !empty($rps->infPrestador['fone'])) {
            $dom->addChild($prest, 'fone', $rps->infPrestador['fone'], false, 'Telefone Prestador', false);
        }
        if (! $prestadorEhEmitente && !empty($rps->infPrestador['email'])) {
            $dom->addChild($prest, 'email', $rps->infPrestador['email'], false, 'E-mail Prestador', false);
        }

        $regTrib = $dom->createElement('regTrib');
        $dom->appChild($prest, $regTrib, 'Adicionando regTrib');
        $dom->addChild($regTrib, 'opSimpNac', $rps->infRegTrib['opSimpNac'], true, 'Opção Simples Nacional', false);
        if (!empty($rps->infRegTrib['regApTribSN'])) {
            $dom->addChild($regTrib, 'regApTribSN', (string) $rps->infRegTrib['regApTribSN'], false, 'Regime apuração tributos SN (1=Competência, 2=Caixa)', false);
        }
        $dom->addChild($regTrib, 'regEspTrib', $rps->infRegTrib['regEspTrib'], true, 'Regime Especial Tributação', false);

        // ── Tomador (TCInfoPessoa, opcional) ─────────────────────────────────
        // Ordem: choice(CNPJ|CPF|NIF|cNaoNIF) → CAEPF? → IM? → xNome → end? → fone? → email?
        if (!empty($rps->infTomador['cnpjcpf']) || !empty($rps->infTomador['xNome'])) {
            $toma = $dom->createElement('toma');
            $dom->appChild($infDPS, $toma, 'Adicionando toma');
            self::appendPessoa($dom, $toma, $rps->infTomador, $rps->infTomadorEndereco, 'Tomador');
        }

        // ── Intermediário (TCInfoPessoa, opcional) ───────────────────────────
        if (!empty($rps->infIntermediario) && !empty($rps->infIntermediario['cnpjcpf'])) {
            $interm = $dom->createElement('interm');
            $dom->appChild($infDPS, $interm, 'Adicionando interm');
            self::appendPessoa($dom, $interm, $rps->infIntermediario, $rps->infIntermediarioEndereco, 'Intermediário');
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

        // tribMun (obrigatório) — ordem:
        //   tribISSQN → cPaisResult? → tpImunidade? → exigSusp? → BM? → tpRetISSQN → pAliq?
        $tribMun = $dom->createElement('tribMun');
        $dom->appChild($trib, $tribMun, 'Adicionando tribMun');
        $dom->addChild($tribMun, 'tribISSQN', (string) $rps->infTribISSQN, true, 'Tributação ISSQN', false);

        if ($rps->infCPaisResult !== null && $rps->infCPaisResult !== '') {
            $dom->addChild($tribMun, 'cPaisResult', $rps->infCPaisResult, false, 'País de resultado (exportação)', false);
        }
        if ($rps->infTpImunidade !== null) {
            $dom->addChild($tribMun, 'tpImunidade', (string) $rps->infTpImunidade, false, 'Tipo de imunidade', false);
        }
        if (is_array($rps->infExigSusp)) {
            $exigSusp = $dom->createElement('exigSusp');
            $dom->appChild($tribMun, $exigSusp, 'Adicionando exigSusp');
            $dom->addChild($exigSusp, 'tpSusp', (string) $rps->infExigSusp['tpSusp'], true, 'Opção exigibilidade suspensa', false);
            $dom->addChild($exigSusp, 'nProcesso', $rps->infExigSusp['nProcesso'], true, 'Número do processo', false);
        }
        if (is_array($rps->infBM) && !empty($rps->infBM['nBM'])) {
            $bm = $dom->createElement('BM');
            $dom->appChild($tribMun, $bm, 'Adicionando BM');
            $dom->addChild($bm, 'nBM', $rps->infBM['nBM'], true, 'Identificador benefício municipal', false);
            if (isset($rps->infBM['vRedBCBM'])) {
                $dom->addChild($bm, 'vRedBCBM', number_format($rps->infBM['vRedBCBM'], 2, '.', ''), false, 'Valor redução BC (BM)', false);
            } elseif (isset($rps->infBM['pRedBCBM'])) {
                $dom->addChild($bm, 'pRedBCBM', number_format($rps->infBM['pRedBCBM'], 2, '.', ''), false, 'Percentual redução BC (BM)', false);
            }
        }

        $dom->addChild($tribMun, 'tpRetISSQN', (string) $rps->infTpRetISSQN, true, 'Tipo retenção ISSQN', false);
        if ($rps->infPAliq !== null) {
            $dom->addChild($tribMun, 'pAliq', number_format($rps->infPAliq, 2, '.', ''), false, 'Alíquota ISSQN', false);
        }

        // tribFed (opcional) — ordem: piscofins? → vRetCP? → vRetIRRF? → vRetCSLL?
        $hasTribFed = is_array($rps->infPisCofins)
            || $rps->infVRetCP !== null
            || $rps->infVRetIRRF !== null
            || $rps->infVRetCSLL !== null;
        if ($hasTribFed) {
            $tribFed = $dom->createElement('tribFed');
            $dom->appChild($trib, $tribFed, 'Adicionando tribFed');

            if (is_array($rps->infPisCofins)) {
                $pc = $dom->createElement('piscofins');
                $dom->appChild($tribFed, $pc, 'Adicionando piscofins');
                $dom->addChild($pc, 'CST', (string) $rps->infPisCofins['CST'], true, 'CST PIS/COFINS', false);
                if (isset($rps->infPisCofins['vBC'])) {
                    $dom->addChild($pc, 'vBCPisCofins', number_format($rps->infPisCofins['vBC'], 2, '.', ''), false, 'Base cálculo PIS/COFINS', false);
                }
                if (isset($rps->infPisCofins['pAliqPis'])) {
                    $dom->addChild($pc, 'pAliqPis', number_format($rps->infPisCofins['pAliqPis'], 2, '.', ''), false, 'Alíquota PIS', false);
                }
                if (isset($rps->infPisCofins['pAliqCofins'])) {
                    $dom->addChild($pc, 'pAliqCofins', number_format($rps->infPisCofins['pAliqCofins'], 2, '.', ''), false, 'Alíquota COFINS', false);
                }
                if (isset($rps->infPisCofins['vPis'])) {
                    $dom->addChild($pc, 'vPis', number_format($rps->infPisCofins['vPis'], 2, '.', ''), false, 'Valor PIS', false);
                }
                if (isset($rps->infPisCofins['vCofins'])) {
                    $dom->addChild($pc, 'vCofins', number_format($rps->infPisCofins['vCofins'], 2, '.', ''), false, 'Valor COFINS', false);
                }
                if (isset($rps->infPisCofins['tpRet']) && $rps->infPisCofins['tpRet'] !== null) {
                    $dom->addChild($pc, 'tpRetPisCofins', (string) $rps->infPisCofins['tpRet'], false, 'Tipo retenção PIS/COFINS', false);
                }
            }

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

        // totTrib — choice entre vTotTrib, pTotTrib e indTotTrib. Obrigatório
        // pelo schema quando tribFed está ausente. Para SN (ME/EPP e MEI)
        // indTotTrib é proibido (E0712); usar pTotTrib ou vTotTrib nesse caso.
        $hasTotTrib = $rps->infVTotTrib !== null
            || $rps->infPTotTrib !== null
            || $rps->infIndTotTrib !== null;

        // totTrib é obrigatório no schema (TCTribTotal — choice entre 4 opções):
        //   - vTotTrib (TCTribTotalMonet): sequence(vTotTribFed, vTotTribEst, vTotTribMun)
        //   - pTotTrib (TCTribTotalPercent): sequence(pTotTribFed, pTotTribEst, pTotTribMun)
        //   - indTotTrib (simpleType, fixo 0)
        //   - pTotTribSN (simpleType, % alíquota SN)
        $totTrib = $dom->createElement('totTrib');
        $dom->appChild($trib, $totTrib, 'Adicionando totTrib');

        if (is_array($rps->infVTotTrib)) {
            $vTot = $dom->createElement('vTotTrib');
            $dom->appChild($totTrib, $vTot, 'Adicionando vTotTrib');
            $dom->addChild($vTot, 'vTotTribFed', number_format($rps->infVTotTrib['fed'], 2, '.', ''), true, 'Valor total tributos federais (R$)', false);
            $dom->addChild($vTot, 'vTotTribEst', number_format($rps->infVTotTrib['est'], 2, '.', ''), true, 'Valor total tributos estaduais (R$)', false);
            $dom->addChild($vTot, 'vTotTribMun', number_format($rps->infVTotTrib['mun'], 2, '.', ''), true, 'Valor total tributos municipais (R$)', false);
        } elseif (is_array($rps->infPTotTrib)) {
            $pTot = $dom->createElement('pTotTrib');
            $dom->appChild($totTrib, $pTot, 'Adicionando pTotTrib');
            $dom->addChild($pTot, 'pTotTribFed', number_format($rps->infPTotTrib['fed'], 2, '.', ''), true, 'Percentual total tributos federais (%)', false);
            $dom->addChild($pTot, 'pTotTribEst', number_format($rps->infPTotTrib['est'], 2, '.', ''), true, 'Percentual total tributos estaduais (%)', false);
            $dom->addChild($pTot, 'pTotTribMun', number_format($rps->infPTotTrib['mun'], 2, '.', ''), true, 'Percentual total tributos municipais (%)', false);
        } elseif ($rps->infPTotTribSN !== null) {
            $dom->addChild($totTrib, 'pTotTribSN', number_format($rps->infPTotTribSN, 2, '.', ''), true, 'Percentual aproximado alíquota SN (%)', false);
        } else {
            $dom->addChild($totTrib, 'indTotTrib', (string) ($rps->infIndTotTrib ?? 0), true, 'Indicador total tributos', false);
        }

        return $dom->saveXML();
    }

    /**
     * Emite o choice de identificação (CNPJ|CPF|NIF|cNaoNIF) dentro do elemento pai.
     *
     * Convenções da lib:
     *   - `tipo` 1=CPF, 2=CNPJ, 3=NIF, 4=cNaoNIF
     *   - quando `tipo`=4, `cnpjcpf` carrega o código de não-informação (0/1/2)
     *
     * @param array<string, mixed> $dados
     */
    private static function appendDocIdentificacao(Dom $dom, \DOMElement $parent, array $dados, string $label): void
    {
        $tipo = (int) ($dados['tipo'] ?? 0);
        $valor = (string) ($dados['cnpjcpf'] ?? '');

        switch ($tipo) {
            case Rps::CPF:
                $dom->addChild($parent, 'CPF', $valor, true, "CPF {$label}", false);
                break;
            case Rps::CNPJ:
                $dom->addChild($parent, 'CNPJ', $valor, true, "CNPJ {$label}", false);
                break;
            case Rps::NIF:
                $dom->addChild($parent, 'NIF', $valor, true, "NIF {$label}", false);
                break;
            case 4:
                $dom->addChild($parent, 'cNaoNIF', $valor !== '' ? $valor : '0', true, "cNaoNIF {$label}", false);
                break;
            default:
                // fallback por tamanho do documento (CPF=11, CNPJ=14)
                if (strlen($valor) === 11) {
                    $dom->addChild($parent, 'CPF', $valor, true, "CPF {$label}", false);
                } else {
                    $dom->addChild($parent, 'CNPJ', $valor, true, "CNPJ {$label}", false);
                }
        }
    }

    /**
     * Emite um bloco TCInfoPessoa (tomador ou intermediário) respeitando a
     * ordem: choice(doc) → CAEPF? → IM? → xNome → end? → fone? → email?
     *
     * @param array<string, mixed>      $dados
     * @param array<string, mixed>|null $endereco
     */
    private static function appendPessoa(Dom $dom, \DOMElement $parent, array $dados, ?array $endereco, string $label): void
    {
        self::appendDocIdentificacao($dom, $parent, $dados, $label);

        if (!empty($dados['caepf'])) {
            $dom->addChild($parent, 'CAEPF', (string) $dados['caepf'], false, "CAEPF {$label}", false);
        }
        if (!empty($dados['im'])) {
            $dom->addChild($parent, 'IM', (string) $dados['im'], false, "IM {$label}", false);
        }
        if (!empty($dados['xNome'])) {
            $dom->addChild($parent, 'xNome', (string) $dados['xNome'], true, "Nome {$label}", false);
        }
        if (is_array($endereco) && !empty($endereco['xLgr'])) {
            self::appendEnderecoNacional($dom, $parent, $endereco);
        }
        if (!empty($dados['fone'])) {
            $dom->addChild($parent, 'fone', (string) $dados['fone'], false, "Telefone {$label}", false);
        }
        if (!empty($dados['email'])) {
            $dom->addChild($parent, 'email', (string) $dados['email'], false, "E-mail {$label}", false);
        }
    }

    /**
     * Emite um bloco TCEndereco com endNac (sem suporte a endExt por ora).
     * Ordem: choice(endNac|endExt) → xLgr → nro → xCpl? → xBairro.
     *
     * Como o bloco `end` é opcional no XSD para TCInfoPessoa/TCInfoPrestador,
     * a emissão é pulada silenciosamente quando faltar algum campo obrigatório
     * (cMun 7 dígitos, CEP 8 dígitos, xLgr, nro, xBairro).
     *
     * @param array<string, mixed> $e
     */
    private static function appendEnderecoNacional(Dom $dom, \DOMElement $parent, array $e): void
    {
        $cMun = preg_replace('/\D/', '', (string) ($e['cMun'] ?? ''));
        $cep = preg_replace('/\D/', '', (string) ($e['CEP'] ?? ''));
        $xLgr = trim((string) ($e['xLgr'] ?? ''));
        $nro = trim((string) ($e['nro'] ?? ''));
        $xBairro = trim((string) ($e['xBairro'] ?? ''));

        if (strlen($cMun) !== 7 || strlen($cep) !== 8 || $xLgr === '' || $nro === '' || $xBairro === '') {
            return;
        }

        $end = $dom->createElement('end');
        $dom->appChild($parent, $end, 'Adicionando end');

        $endNac = $dom->createElement('endNac');
        $dom->appChild($end, $endNac, 'Adicionando endNac');
        $dom->addChild($endNac, 'cMun', $cMun, true, 'Código IBGE', false);
        $dom->addChild($endNac, 'CEP', $cep, true, 'CEP', false);

        $dom->addChild($end, 'xLgr', $xLgr, true, 'Logradouro', false);
        $dom->addChild($end, 'nro', $nro, true, 'Número', false);
        if (!empty($e['xCpl'])) {
            $dom->addChild($end, 'xCpl', (string) $e['xCpl'], false, 'Complemento', false);
        }
        $dom->addChild($end, 'xBairro', $xBairro, true, 'Bairro', false);
    }
}
