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
     * @var array{tipo: int, cnpjcpf: string, im: string, xNome: string, fone: string, email: string}|null
     */
    public $infIntermediario = null;

    // -------------------------------------------------------------------------
    // Prestador (prest)
    // -------------------------------------------------------------------------

    /**
     * @var array{tipo: int, cnpjcpf: string, im: string, xNome: string}
     */
    public $infPrestador = ['tipo' => '', 'cnpjcpf' => '', 'im' => '', 'xNome' => ''];

    /**
     * @var array{opSimpNac: int, regEspTrib: int}
     */
    public $infRegTrib = ['opSimpNac' => '', 'regEspTrib' => ''];

    // -------------------------------------------------------------------------
    // Tomador (toma) — opcional
    // -------------------------------------------------------------------------

    /**
     * @var array{tipo: int, cnpjcpf: string, im: string, xNome: string, fone: string, email: string}
     */
    public $infTomador = ['tipo' => '', 'cnpjcpf' => '', 'im' => '', 'xNome' => '', 'fone' => '', 'email' => ''];

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

    /** @var float|null Valor retido INSS/CP (R$) */
    public $infVRetCP = null;

    /** @var float|null Valor retido IRRF (R$) */
    public $infVRetIRRF = null;

    /** @var float|null Valor retido CSLL (R$) */
    public $infVRetCSLL = null;

    /**
     * Indicador para totTrib. Choice do schema; default 0 = "Não informar estimado"
     * (Decreto 8.264/2014).
     * @var int
     */
    public $infIndTotTrib = 0;

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
    public function intermediario(int $tipo, string $cnpjcpf, string $im, string $xNome, string $fone = '', string $email = ''): void
    {
        $this->infIntermediario = [
            'tipo' => $tipo,
            'cnpjcpf' => $cnpjcpf,
            'im' => $im,
            'xNome' => $xNome,
            'fone' => $fone,
            'email' => $email,
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
     * @param int $tipo 1=CPF, 2=CNPJ
     */
    public function prestador(int $tipo, string $cnpjcpf, string $im, string $xNome): void
    {
        $this->infPrestador = [
            'tipo'    => $tipo,
            'cnpjcpf' => $cnpjcpf,
            'im'      => $im,
            'xNome'   => $xNome,
        ];
    }

    /**
     * Define o regime tributário do prestador.
     * @param int $opSimpNac 1=Não optante, 2=MEI, 3=ME/EPP
     * @param int $regEspTrib 0=Nenhum, 1-6, 9=Outros
     */
    public function regimeTributacao(int $opSimpNac, int $regEspTrib): void
    {
        if (!Validator::numericVal()->intVal()->between(1, 3)->validate($opSimpNac)) {
            throw new InvalidArgumentException("opSimpNac deve ser 1, 2 ou 3. Informado: '$opSimpNac'");
        }
        $allowedRegimes = [0, 1, 2, 3, 4, 5, 6, 9];
        if (!in_array($regEspTrib, $allowedRegimes, true)) {
            throw new InvalidArgumentException("regEspTrib deve ser 0-6 ou 9. Informado: '$regEspTrib'");
        }
        $this->infRegTrib = [
            'opSimpNac'  => $opSimpNac,
            'regEspTrib' => $regEspTrib,
        ];
    }

    // =========================================================================
    // SETTERS — tomador (opcional)
    // =========================================================================

    /**
     * Define dados do tomador de serviço.
     * @param int $tipo 1=CPF, 2=CNPJ
     */
    public function tomador(int $tipo, string $cnpjcpf, string $im, string $xNome, string $fone = '', string $email = ''): void
    {
        $this->infTomador = [
            'tipo'    => $tipo,
            'cnpjcpf' => $cnpjcpf,
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
     * Indicador do grupo totTrib — usar 0 para "não informar estimado" (Decreto 8.264/2014).
     */
    public function indTotTrib(int $value): void
    {
        if ($value !== 0) {
            throw new InvalidArgumentException('indTotTrib suportado atualmente apenas com valor 0. Para vTotTrib/pTotTrib/pTotTribSN use setters específicos (futuro).');
        }
        $this->infIndTotTrib = 0;
    }
}
