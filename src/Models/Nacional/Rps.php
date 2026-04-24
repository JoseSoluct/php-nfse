<?php

namespace NFePHP\NFSe\Models\Nacional;

use DateTime;
use InvalidArgumentException;
use NFePHP\NFSe\Common\Rps as RpsBase;
use Respect\Validation\Validator;

/**
 * Modelo de dados para a DPS (Declaração de Prestação de Serviços)
 * conforme o padrão NFS-e Nacional (Receita Federal / Sefin Nacional)
 * Namespace: http://www.sped.fazenda.gov.br/nfse
 */
class Rps extends RpsBase
{
    // Ambiente
    const TPAMB_PRODUCAO    = 1;
    const TPAMB_HOMOLOGACAO = 2;

    // Tipo de documento do emitente da DPS
    const TPEMIT_PRESTADOR    = 1;
    const TPEMIT_TOMADOR      = 2;
    const TPEMIT_INTERMEDIARIO = 3;

    // Tipo de pessoa (CNPJ/CPF)
    const CNPJ = 2;
    const CPF  = 1;
    const NIF  = 3;

    // opSimpNac — situação no Simples Nacional
    const OP_SIMP_NAC_NAO_OPTANTE = 1;
    const OP_SIMP_NAC_MEI         = 2;
    const OP_SIMP_NAC_ME_EPP      = 3;

    // regEspTrib — regime especial de tributação
    const REG_ESP_TRIB_NENHUM       = 0;
    const REG_ESP_TRIB_COOPERATIVA  = 1;
    const REG_ESP_TRIB_ESTIMATIVA   = 2;
    const REG_ESP_TRIB_MICROEMPRESA = 3;
    const REG_ESP_TRIB_NOTARIO      = 4;
    const REG_ESP_TRIB_AUTONOMO     = 5;
    const REG_ESP_TRIB_SOCIEDADE    = 6;
    const REG_ESP_TRIB_OUTROS       = 9;

    // -------------------------------------------------------------------------
    // Identificação da DPS
    // -------------------------------------------------------------------------

    /** @var string Identificador único da DPS (45 chars: "DPS" + 42 dígitos) */
    public $infId;

    /** @var int Tipo de ambiente: 1=Produção, 2=Homologação */
    public $infTpAmb;

    /** @var DateTime Data/hora de emissão (UTC) */
    public $infDhEmi;

    /** @var string Versão do aplicativo emissor (1-20 chars) */
    public $infVerAplic;

    /** @var string Série da DPS (até 5 dígitos) */
    public $infSerie;

    /** @var int Número sequencial da DPS */
    public $infNDps;

    /** @var string Data de competência (YYYY-MM-DD) */
    public $infDCompet;

    /** @var int Tipo do emitente: 1=Prestador, 2=Tomador, 3=Intermediário */
    public $infTpEmit;

    /** @var string Código IBGE do município emissor (7 dígitos) */
    public $infCLocEmi;

    /**
     * Motivo da emissão da DPS pelo Tomador/Intermediário (opcional):
     * 1=Importação, 2=Obrigado por legislação municipal, 3=Recusa de emissão pelo prestador,
     * 4=Rejeição da NFS-e emitida pelo prestador.
     * @var int|null
     */
    public $infCMotivoEmisTI = null;

    /** @var string|null Chave de acesso (50 dígitos) da NFS-e rejeitada pelo Tomador/Intermediário */
    public $infChNFSeRej = null;

    /**
     * Substituição de NFS-e (grupo opcional):
     *   chSubstda: chave (50) da NFS-e substituída
     *   cMotivo: 01, 02, 03, 04, 05, 99
     *   xMotivo: descrição (mín. 15 chars) — opcional
     *
     * @var array{chSubstda: string, cMotivo: string, xMotivo: string}|null
     */
    public $infSubst = null;

    /**
     * Intermediário (grupo opcional) — mesma estrutura de tomador.
     *
     * @var array{tipo: int|string, cnpjcpf: string, caepf: string, im: string, xNome: string, fone: string, email: string}|null
     */
    public $infIntermediario = null;

    /**
     * Endereço do intermediário (opcional).
     * @var array{xLgr: string, nro: string, xCpl: string, xBairro: string, cMun: string, UF: string, CEP: string}|null
     */
    public $infIntermediarioEndereco = null;

    // -------------------------------------------------------------------------
    // Prestador (prest)
    // -------------------------------------------------------------------------

    /**
     * Dados do prestador. `tipo` usa TCInfoPrestador (choice entre CNPJ/CPF/NIF/cNaoNIF).
     * `tipo` 1=CPF, 2=CNPJ, 3=NIF, 4=cNaoNIF (pessoa estrangeira não-NIF).
     * @var array{tipo: int|string, cnpjcpf: string, caepf: string, im: string, xNome: string, fone: string, email: string}
     */
    public $infPrestador = [
        'tipo'    => '',
        'cnpjcpf' => '',
        'caepf'   => '',
        'im'      => '',
        'xNome'   => '',
        'fone'    => '',
        'email'   => '',
    ];

    /**
     * Endereço nacional do prestador (opcional). Mesma estrutura do tomador.
     * @var array{xLgr: string, nro: string, xCpl: string, xBairro: string, cMun: string, UF: string, CEP: string}|null
     */
    public $infPrestadorEndereco = null;

    /**
     * @var array{opSimpNac: int, regApTribSN: ?int, regEspTrib: int}
     */
    public $infRegTrib = ['opSimpNac' => '', 'regApTribSN' => null, 'regEspTrib' => ''];

    // -------------------------------------------------------------------------
    // Tomador (toma) — opcional
    // -------------------------------------------------------------------------

    /**
     * Dados do tomador. `tipo` usa choice TCInfoPessoa (CNPJ/CPF/NIF/cNaoNIF).
     * @var array{tipo: int|string, cnpjcpf: string, caepf: string, im: string, xNome: string, fone: string, email: string}
     */
    public $infTomador = [
        'tipo'    => '',
        'cnpjcpf' => '',
        'caepf'   => '',
        'im'      => '',
        'xNome'   => '',
        'fone'    => '',
        'email'   => '',
    ];

    /**
     * @var array{xLgr: string, nro: string, xCpl: string, xBairro: string, cMun: string, UF: string, CEP: string}
     */
    public $infTomadorEndereco = [
        'xLgr'   => '',
        'nro'    => '',
        'xCpl'   => '',
        'xBairro' => '',
        'cMun'   => '',
        'UF'     => '',
        'CEP'    => '',
    ];

    // -------------------------------------------------------------------------
    // Serviço (serv)
    // -------------------------------------------------------------------------

    /**
     * @var array{cLocPrestacao: string}
     */
    public $infLocPrest = ['cLocPrestacao' => ''];

    /**
     * @var array{cTribNac: string, cTribMun: string, xDescServ: string, cNBS: string}
     */
    public $infCServ = ['cTribNac' => '', 'cTribMun' => '', 'xDescServ' => '', 'cNBS' => ''];

    // -------------------------------------------------------------------------
    // Valores (valores) — estrutura v1.01: vServPrest + vDescCondIncond? + vDedRed? + trib{tribMun, tribFed?, totTrib}
    // -------------------------------------------------------------------------

    // Tributação ISSQN
    const TRIB_ISSQN_TRIBUTAVEL  = 1;
    const TRIB_ISSQN_IMUNIDADE   = 2;
    const TRIB_ISSQN_EXPORTACAO  = 3;
    const TRIB_ISSQN_NAO_INCID   = 4;

    // Retenção ISSQN
    const RET_ISSQN_NAO_RETIDO        = 1;
    const RET_ISSQN_TOMADOR           = 2;
    const RET_ISSQN_INTERMEDIARIO     = 3;

    /** @var float Valor dos serviços (R$) — obrigatório */
    public $infVServ = 0.00;

    /** @var float|null Valor recebido pelo intermediário (R$) — opcional */
    public $infVReceb = null;

    /** @var float|null Desconto incondicionado (R$) */
    public $infVDescIncond = null;

    /** @var float|null Desconto condicionado (R$) */
    public $infVDescCond = null;

    /** @var int Tributação ISSQN: 1=Tributável, 2=Imunidade, 3=Exportação, 4=Não incidência */
    public $infTribISSQN = self::TRIB_ISSQN_TRIBUTAVEL;

    /** @var int Tipo retenção ISSQN: 1=Não retido, 2=Tomador, 3=Intermediário */
    public $infTpRetISSQN = self::RET_ISSQN_NAO_RETIDO;

    /** @var float|null Alíquota do ISSQN no município (%). Opcional */
    public $infPAliq = null;

    /**
     * Código ISO do país onde se verificou o resultado do serviço exportado.
     * Obrigatório quando `tribISSQN` = 3 (Exportação).
     * @var string|null
     */
    public $infCPaisResult = null;

    /**
     * Tipo de imunidade do ISSQN. Obrigatório quando `tribISSQN` = 2 (Imunidade).
     * 1..5 conforme TCTribMunicipal/tpImunidade.
     * @var int|null
     */
    public $infTpImunidade = null;

    /**
     * Exigibilidade suspensa do ISSQN (judicial/administrativa).
     * @var array{tpSusp: int, nProcesso: string}|null
     */
    public $infExigSusp = null;

    /**
     * Benefício municipal parametrizado (redução de BC).
     * @var array{nBM: string, vRedBCBM: ?float, pRedBCBM: ?float}|null
     */
    public $infBM = null;

    /** @var float|null Valor retido INSS/CP (R$) */
    public $infVRetCP = null;

    /** @var float|null Valor retido IRRF (R$) */
    public $infVRetIRRF = null;

    /** @var float|null Valor retido CSLL (R$) */
    public $infVRetCSLL = null;

    /**
     * Grupo PIS/COFINS dentro de tribFed (TCTribOutrosPisCofins).
     * Ordem: CST → vBCPisCofins → pAliqPis → pAliqCofins → vPis → vCofins → tpRetPisCofins.
     *
     * @var array{
     *   CST: string,
     *   vBC: ?float,
     *   pAliqPis: ?float,
     *   pAliqCofins: ?float,
     *   vPis: ?float,
     *   vCofins: ?float,
     *   tpRet: ?int
     * }|null
     */
    public $infPisCofins = null;

    /**
     * Indicador para totTrib. Choice do schema; default 0 = "Não informar estimado"
     * (Decreto 8.264/2014). `null` suprime o bloco `<totTrib>` inteiro — obrigatório
     * para SN ME/EPP e MEI, que não podem informar este campo (E0712).
     * @var int|null
     */
    public $infIndTotTrib = 0;

    /**
     * Valores monetários totais aproximados dos tributos (Lei 12.741/2012).
     * Alternativa ao `indTotTrib`; o schema exige os 3 campos quando presente.
     * @var array{fed: float, est: float, mun: float}|null
     */
    public $infVTotTrib = null;

    /**
     * Percentuais totais aproximados dos tributos (Lei 12.741/2012).
     * @var array{fed: float, est: float, mun: float}|null
     */
    public $infPTotTrib = null;

    /**
     * Percentual total aproximado da alíquota do Simples Nacional (%).
     * Alternativa específica para optantes do SN — simpleType.
     * @var float|null
     */
    public $infPTotTribSN = null;

    // =========================================================================
    // SETTERS — identificação da DPS
    // =========================================================================

    public function tpAmb(int $value): void
    {
        if (!Validator::numericVal()->intVal()->between(1, 2)->validate($value)) {
            throw new InvalidArgumentException("tpAmb deve ser 1 (Produção) ou 2 (Homologação). Informado: '$value'");
        }
        $this->infTpAmb = $value;
    }

    public function dhEmi(DateTime $value): void
    {
        $this->infDhEmi = $value;
    }

    public function verAplic(string $value): void
    {
        if (!Validator::stringType()->length(1, 20)->validate($value)) {
            throw new InvalidArgumentException("verAplic deve ter entre 1 e 20 caracteres. Informado: '$value'");
        }
        $this->infVerAplic = $value;
    }

    public function serie(string $value): void
    {
        if (!Validator::digit()->length(1, 5)->validate($value)) {
            throw new InvalidArgumentException("serie deve ter entre 1 e 5 dígitos numéricos. Informado: '$value'");
        }
        $this->infSerie = ltrim($value, '0') ?: '0';
    }

    public function nDps(int $value): void
    {
        if (!Validator::numericVal()->intVal()->positive()->validate($value)) {
            throw new InvalidArgumentException("nDPS deve ser um inteiro positivo. Informado: '$value'");
        }
        $this->infNDps = $value;
    }

    public function dCompet(string $value): void
    {
        if (!Validator::date('Y-m-d')->validate($value)) {
            throw new InvalidArgumentException("dCompet deve estar no formato YYYY-MM-DD. Informado: '$value'");
        }
        $this->infDCompet = $value;
    }

    public function tpEmit(int $value): void
    {
        if (!Validator::numericVal()->intVal()->between(1, 3)->validate($value)) {
            throw new InvalidArgumentException("tpEmit deve ser 1, 2 ou 3. Informado: '$value'");
        }
        $this->infTpEmit = $value;
    }

    public function cLocEmi(string $value): void
    {
        if (!Validator::digit()->length(7, 7)->validate($value)) {
            throw new InvalidArgumentException("cLocEmi deve ter exatamente 7 dígitos (código IBGE). Informado: '$value'");
        }
        $this->infCLocEmi = $value;
    }

    /**
     * Emissão pelo Tomador/Intermediário (opcional). Obrigatório quando tpEmit=2 ou 3.
     *
     * @param int         $cMotivo  1..4 — motivo da emissão
     * @param string|null $chRej    Chave (50) da NFS-e rejeitada (obrigatório apenas se cMotivo=4)
     */
    public function motivoEmisTI(int $cMotivo, ?string $chRej = null): void
    {
        if (!Validator::numericVal()->intVal()->between(1, 4)->validate($cMotivo)) {
            throw new InvalidArgumentException("cMotivoEmisTI deve ser 1..4. Informado: '$cMotivo'");
        }
        if ($chRej !== null && !Validator::digit()->length(50, 50)->validate($chRej)) {
            throw new InvalidArgumentException("chNFSeRej deve ter 50 dígitos. Informado: '$chRej'");
        }
        $this->infCMotivoEmisTI = $cMotivo;
        $this->infChNFSeRej = $chRej;
    }

    /**
     * Define a substituição de uma NFS-e existente (grupo subst).
     *
     * @param string      $chSubstda Chave (50) da NFS-e a ser substituída
     * @param string      $cMotivo   Código 01..05 ou 99
     * @param string|null $xMotivo   Descrição (mín. 15 chars quando informado)
     */
    public function substituicao(string $chSubstda, string $cMotivo, ?string $xMotivo = null): void
    {
        if (!Validator::digit()->length(50, 50)->validate($chSubstda)) {
            throw new InvalidArgumentException("chSubstda deve ter 50 dígitos. Informado: '$chSubstda'");
        }
        $allowed = ['01', '02', '03', '04', '05', '99'];
        if (!in_array($cMotivo, $allowed, true)) {
            throw new InvalidArgumentException("cMotivo de substituição inválido. Informado: '$cMotivo'");
        }
        if ($xMotivo !== null && strlen(trim($xMotivo)) < 15) {
            throw new InvalidArgumentException('xMotivo deve ter no mínimo 15 caracteres quando informado.');
        }
        $this->infSubst = [
            'chSubstda' => $chSubstda,
            'cMotivo' => $cMotivo,
            'xMotivo' => $xMotivo ?? '',
        ];
    }

    /**
     * Define dados do intermediário (grupo interm) — opcional.
     * @param int $tipo 1=CPF, 2=CNPJ
     */
    public function intermediario(
        int $tipo,
        string $cnpjcpf,
        string $im,
        string $xNome,
        string $fone = '',
        string $email = '',
        string $caepf = ''
    ): void {
        $this->infIntermediario = [
            'tipo'    => $tipo,
            'cnpjcpf' => $cnpjcpf,
            'caepf'   => $caepf,
            'im'      => $im,
            'xNome'   => $xNome,
            'fone'    => $fone,
            'email'   => $email,
        ];
    }

    /**
     * Define endereço nacional do intermediário.
     */
    public function intermediarioEndereco(string $xLgr, string $nro, string $xBairro, string $cMun, string $uf, string $cep, string $xCpl = ''): void
    {
        $this->infIntermediarioEndereco = [
            'xLgr'    => $xLgr,
            'nro'     => $nro,
            'xCpl'    => $xCpl,
            'xBairro' => $xBairro,
            'cMun'    => $cMun,
            'UF'      => $uf,
            'CEP'     => $cep,
        ];
    }

    /**
     * Gera e armazena o Id da DPS no formato TSIdDPS:
     * "DPS" + cMun(7) + tipoInsc(1) + inscFed(14) + serie(5) + nDPS(15)
     */
    public function gerarId(): void
    {
        $tipoInsc = ($this->infPrestador['tipo'] == self::CNPJ) ? '2' : '1';
        $this->infId = 'DPS'
            . str_pad($this->infCLocEmi, 7, '0', STR_PAD_LEFT)
            . $tipoInsc
            . str_pad($this->infPrestador['cnpjcpf'], 14, '0', STR_PAD_LEFT)
            . str_pad($this->infSerie, 5, '0', STR_PAD_LEFT)
            . str_pad($this->infNDps, 15, '0', STR_PAD_LEFT);
    }

    // =========================================================================
    // SETTERS — prestador
    // =========================================================================

    /**
     * Define dados do prestador de serviço.
     * @param int    $tipo    1=CPF, 2=CNPJ, 3=NIF (estrangeiro), 4=cNaoNIF
     * @param string $cnpjcpf CNPJ/CPF/NIF ou código cNaoNIF (0/1/2) conforme o tipo
     * @param string $im      Inscrição Municipal
     * @param string $xNome   Razão Social
     * @param string $fone    Telefone (opcional)
     * @param string $email   E-mail (opcional)
     * @param string $caepf   CAEPF (opcional, produtor rural PF)
     */
    public function prestador(
        int $tipo,
        string $cnpjcpf,
        string $im,
        string $xNome,
        string $fone = '',
        string $email = '',
        string $caepf = ''
    ): void {
        $this->infPrestador = [
            'tipo'    => $tipo,
            'cnpjcpf' => $cnpjcpf,
            'caepf'   => $caepf,
            'im'      => $im,
            'xNome'   => $xNome,
            'fone'    => $fone,
            'email'   => $email,
        ];
    }

    /**
     * Define endereço nacional do prestador (opcional).
     */
    public function prestadorEndereco(string $xLgr, string $nro, string $xBairro, string $cMun, string $uf, string $cep, string $xCpl = ''): void
    {
        $this->infPrestadorEndereco = [
            'xLgr'    => $xLgr,
            'nro'     => $nro,
            'xCpl'    => $xCpl,
            'xBairro' => $xBairro,
            'cMun'    => $cMun,
            'UF'      => $uf,
            'CEP'     => $cep,
        ];
    }

    /**
     * Define o regime tributário do prestador.
     * @param int      $opSimpNac   1=Não optante, 2=MEI, 3=ME/EPP
     * @param int      $regEspTrib  0=Nenhum, 1-6, 9=Outros
     * @param int|null $regApTribSN Regime de apuração do SN (obrigatório para ME/EPP):
     *                              1=Competência, 2=Caixa
     */
    public function regimeTributacao(int $opSimpNac, int $regEspTrib, ?int $regApTribSN = null): void
    {
        if (!Validator::numericVal()->intVal()->between(1, 3)->validate($opSimpNac)) {
            throw new InvalidArgumentException("opSimpNac deve ser 1, 2 ou 3. Informado: '$opSimpNac'");
        }
        $allowedRegimes = [0, 1, 2, 3, 4, 5, 6, 9];
        if (!in_array($regEspTrib, $allowedRegimes, true)) {
            throw new InvalidArgumentException("regEspTrib deve ser 0-6 ou 9. Informado: '$regEspTrib'");
        }
        if ($regApTribSN !== null && !in_array($regApTribSN, [1, 2], true)) {
            throw new InvalidArgumentException("regApTribSN deve ser 1 (Competência) ou 2 (Caixa). Informado: '$regApTribSN'");
        }
        $this->infRegTrib = [
            'opSimpNac'   => $opSimpNac,
            'regApTribSN' => $regApTribSN,
            'regEspTrib'  => $regEspTrib,
        ];
    }

    // =========================================================================
    // SETTERS — tomador (opcional)
    // =========================================================================

    /**
     * Define dados do tomador de serviço.
     * @param int $tipo 1=CPF, 2=CNPJ, 3=NIF, 4=cNaoNIF
     */
    public function tomador(
        int $tipo,
        string $cnpjcpf,
        string $im,
        string $xNome,
        string $fone = '',
        string $email = '',
        string $caepf = ''
    ): void {
        $this->infTomador = [
            'tipo'    => $tipo,
            'cnpjcpf' => $cnpjcpf,
            'caepf'   => $caepf,
            'im'      => $im,
            'xNome'   => $xNome,
            'fone'    => $fone,
            'email'   => $email,
        ];
    }

    /**
     * Define endereço nacional do tomador.
     */
    public function tomadorEndereco(string $xLgr, string $nro, string $xBairro, string $cMun, string $uf, string $cep, string $xCpl = ''): void
    {
        $this->infTomadorEndereco = [
            'xLgr'    => $xLgr,
            'nro'     => $nro,
            'xCpl'    => $xCpl,
            'xBairro' => $xBairro,
            'cMun'    => $cMun,
            'UF'      => $uf,
            'CEP'     => $cep,
        ];
    }

    // =========================================================================
    // SETTERS — serviço
    // =========================================================================

    /**
     * Define o local de prestação do serviço pelo código IBGE (7 dígitos).
     */
    public function localPrestacao(string $cLocPrestacao): void
    {
        if (!Validator::digit()->length(7, 7)->validate($cLocPrestacao)) {
            throw new InvalidArgumentException("cLocPrestacao deve ter 7 dígitos. Informado: '$cLocPrestacao'");
        }
        $this->infLocPrest = ['cLocPrestacao' => $cLocPrestacao];
    }

    /**
     * Define o código do serviço prestado.
     * @param string $cTribNac Código de tributação nacional LC 116 — 6 dígitos
     * @param string $xDescServ Discriminação do serviço (1-2000 chars)
     * @param string $cTribMun Código de tributação municipal — 3 dígitos (opcional)
     * @param string $cNBS Código NBS 2.0 (opcional)
     */
    public function codigoServico(string $cTribNac, string $xDescServ, string $cTribMun = '', string $cNBS = ''): void
    {
        if (!Validator::digit()->length(6, 6)->validate($cTribNac)) {
            throw new InvalidArgumentException("cTribNac deve ter exatamente 6 dígitos. Informado: '$cTribNac'");
        }
        if (!Validator::stringType()->length(1, 2000)->validate($xDescServ)) {
            throw new InvalidArgumentException("xDescServ deve ter entre 1 e 2000 caracteres.");
        }
        $this->infCServ = [
            'cTribNac'  => $cTribNac,
            'cTribMun'  => $cTribMun,
            'xDescServ' => $xDescServ,
            'cNBS'      => $cNBS,
        ];
    }

    // =========================================================================
    // SETTERS — valores
    // =========================================================================

    /**
     * Valor dos serviços prestados (R$) — vai em vServPrest/vServ. Obrigatório.
     */
    public function vServ(float $value): void
    {
        if (!Validator::floatVal()->min(0)->validate($value)) {
            throw new InvalidArgumentException("vServ deve ser >= 0. Informado: '$value'");
        }
        $this->infVServ = round($value, 2);
    }

    /**
     * Valor recebido pelo intermediário (R$) — vai em vServPrest/vReceb. Opcional.
     */
    public function vReceb(float $value): void
    {
        if (!Validator::floatVal()->min(0)->validate($value)) {
            throw new InvalidArgumentException("vReceb deve ser >= 0. Informado: '$value'");
        }
        $this->infVReceb = round($value, 2);
    }

    public function vDescIncond(float $value): void
    {
        if ($value > 0) {
            $this->infVDescIncond = round($value, 2);
        }
    }

    public function vDescCond(float $value): void
    {
        if ($value > 0) {
            $this->infVDescCond = round($value, 2);
        }
    }

    /**
     * Tributação ISSQN: 1=Tributável, 2=Imunidade, 3=Exportação, 4=Não incidência.
     */
    public function tribISSQN(int $value): void
    {
        if (!Validator::numericVal()->intVal()->between(1, 4)->validate($value)) {
            throw new InvalidArgumentException("tribISSQN deve ser 1..4. Informado: '$value'");
        }
        $this->infTribISSQN = $value;
    }

    /**
     * Tipo retenção ISSQN: 1=Não retido, 2=Tomador, 3=Intermediário.
     */
    public function tpRetISSQN(int $value): void
    {
        if (!Validator::numericVal()->intVal()->between(1, 3)->validate($value)) {
            throw new InvalidArgumentException("tpRetISSQN deve ser 1..3. Informado: '$value'");
        }
        $this->infTpRetISSQN = $value;
    }

    /**
     * Alíquota do ISSQN (%) — opcional. Se o município estiver no ADN, a alíquota é
     * parametrizada e pode ser omitida. Informe quando o município não estiver no ADN.
     */
    public function pAliq(float $value): void
    {
        if (!Validator::floatVal()->min(0)->validate($value)) {
            throw new InvalidArgumentException("pAliq deve ser >= 0. Informado: '$value'");
        }
        $this->infPAliq = round($value, 2);
    }

    public function vRetCP(float $value): void
    {
        if ($value > 0) {
            $this->infVRetCP = round($value, 2);
        }
    }

    public function vRetIRRF(float $value): void
    {
        if ($value > 0) {
            $this->infVRetIRRF = round($value, 2);
        }
    }

    public function vRetCSLL(float $value): void
    {
        if ($value > 0) {
            $this->infVRetCSLL = round($value, 2);
        }
    }

    /**
     * Define o país de resultado do serviço exportado (ISO, 2-3 dígitos).
     * Obrigatório quando tribISSQN=3 (Exportação).
     */
    public function cPaisResult(string $isoCode): void
    {
        $this->infCPaisResult = $isoCode;
    }

    /**
     * Define o tipo de imunidade do ISSQN (1..5).
     * Obrigatório quando tribISSQN=2 (Imunidade).
     */
    public function tpImunidade(int $value): void
    {
        if (!Validator::numericVal()->intVal()->between(0, 5)->validate($value)) {
            throw new InvalidArgumentException("tpImunidade deve ser 0..5. Informado: '$value'");
        }
        $this->infTpImunidade = $value;
    }

    /**
     * Define a exigibilidade suspensa do ISSQN.
     * @param int    $tpSusp    1=Judicial, 2=Administrativo
     * @param string $nProcesso Número do processo
     */
    public function exigSusp(int $tpSusp, string $nProcesso): void
    {
        if (!in_array($tpSusp, [1, 2], true)) {
            throw new InvalidArgumentException("tpSusp deve ser 1 (Judicial) ou 2 (Administrativo).");
        }
        $this->infExigSusp = ['tpSusp' => $tpSusp, 'nProcesso' => $nProcesso];
    }

    /**
     * Define benefício municipal parametrizado (redução BC). Informe somente um
     * entre `vRedBCBM` e `pRedBCBM`.
     */
    public function beneficioMunicipal(string $nBM, ?float $vRedBCBM = null, ?float $pRedBCBM = null): void
    {
        $this->infBM = [
            'nBM' => $nBM,
            'vRedBCBM' => $vRedBCBM !== null ? round($vRedBCBM, 2) : null,
            'pRedBCBM' => $pRedBCBM !== null ? round($pRedBCBM, 2) : null,
        ];
    }

    /**
     * Define o grupo PIS/COFINS (tribFed/piscofins).
     * @param string     $cst         Código de Situação Tributária PIS/COFINS (TSTipoCST)
     * @param float|null $vBC         Valor base de cálculo (R$)
     * @param float|null $pAliqPis    Alíquota PIS (%)
     * @param float|null $pAliqCofins Alíquota COFINS (%)
     * @param float|null $vPis        Valor PIS (R$)
     * @param float|null $vCofins     Valor COFINS (R$)
     * @param int|null   $tpRet       Tipo de retenção PIS/COFINS (TSTipoRetPISCofins)
     */
    public function pisCofins(
        string $cst,
        ?float $vBC = null,
        ?float $pAliqPis = null,
        ?float $pAliqCofins = null,
        ?float $vPis = null,
        ?float $vCofins = null,
        ?int $tpRet = null
    ): void {
        $this->infPisCofins = [
            'CST'         => $cst,
            'vBC'         => $vBC !== null ? round($vBC, 2) : null,
            'pAliqPis'    => $pAliqPis !== null ? round($pAliqPis, 2) : null,
            'pAliqCofins' => $pAliqCofins !== null ? round($pAliqCofins, 2) : null,
            'vPis'        => $vPis !== null ? round($vPis, 2) : null,
            'vCofins'     => $vCofins !== null ? round($vCofins, 2) : null,
            'tpRet'       => $tpRet,
        ];
    }

    /**
     * Indicador do grupo totTrib — usar 0 para "não informar estimado" (Decreto 8.264/2014).
     */
    public function indTotTrib(int $value): void
    {
        if ($value !== 0) {
            throw new InvalidArgumentException('indTotTrib suportado atualmente apenas com valor 0.');
        }
        $this->infIndTotTrib = 0;
        $this->infVTotTrib = null;
        $this->infPTotTrib = null;
        $this->infPTotTribSN = null;
    }

    /**
     * Define os valores monetários totais aproximados de tributos (Lei 12.741/2012).
     * O schema exige os 3 campos (federal, estadual, municipal) quando presente.
     */
    public function vTotTrib(float $fed, float $est = 0.0, float $mun = 0.0): void
    {
        $this->infVTotTrib = ['fed' => $fed, 'est' => $est, 'mun' => $mun];
        $this->infIndTotTrib = null;
        $this->infPTotTrib = null;
        $this->infPTotTribSN = null;
    }

    /**
     * Define os percentuais totais aproximados de tributos (Lei 12.741/2012).
     * O schema exige os 3 campos (federal, estadual, municipal) quando presente.
     */
    public function pTotTrib(float $fed, float $est = 0.0, float $mun = 0.0): void
    {
        $this->infPTotTrib = ['fed' => $fed, 'est' => $est, 'mun' => $mun];
        $this->infIndTotTrib = null;
        $this->infVTotTrib = null;
        $this->infPTotTribSN = null;
    }

    /**
     * Define o percentual total aproximado da alíquota do Simples Nacional (%).
     * Específico para optantes do SN — simpleType no schema.
     */
    public function pTotTribSN(float $value): void
    {
        $this->infPTotTribSN = $value;
        $this->infIndTotTrib = null;
        $this->infVTotTrib = null;
        $this->infPTotTrib = null;
    }
}
