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
    // Valores (valores)
    // -------------------------------------------------------------------------

    /**
     * @var array{vServ: float, vBC: float, pAliqAplic: float, vISSQN: float, vLiq: float,
     *            descIncond: float, descCond: float, vTotalRet: float,
     *            vRetIRRF: float, vRetCSLL: float, vRetCP: float}
     */
    public $infValores = [
        'vServ'     => 0.00,
        'vBC'       => 0.00,
        'pAliqAplic' => 0.00,
        'vISSQN'    => 0.00,
        'vLiq'      => 0.00,
        'descIncond' => 0.00,
        'descCond'  => 0.00,
        'vTotalRet' => 0.00,
        'vRetIRRF'  => 0.00,
        'vRetCSLL'  => 0.00,
        'vRetCP'    => 0.00,
    ];

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
        $this->infSerie = str_pad($value, 5, '0', STR_PAD_LEFT);
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

    public function vServ(float $value): void
    {
        if (!Validator::floatVal()->min(0)->validate($value)) {
            throw new InvalidArgumentException("vServ deve ser um valor decimal >= 0. Informado: '$value'");
        }
        $this->infValores['vServ'] = round($value, 2);
    }

    public function vBC(float $value): void
    {
        if (!Validator::floatVal()->min(0)->validate($value)) {
            throw new InvalidArgumentException("vBC deve ser um valor decimal >= 0. Informado: '$value'");
        }
        $this->infValores['vBC'] = round($value, 2);
    }

    public function pAliqAplic(float $value): void
    {
        if (!Validator::floatVal()->min(0)->validate($value)) {
            throw new InvalidArgumentException("pAliqAplic deve ser um valor decimal >= 0. Informado: '$value'");
        }
        $this->infValores['pAliqAplic'] = round($value, 4);
    }

    public function vISSQN(float $value): void
    {
        if (!Validator::floatVal()->min(0)->validate($value)) {
            throw new InvalidArgumentException("vISSQN deve ser um valor decimal >= 0. Informado: '$value'");
        }
        $this->infValores['vISSQN'] = round($value, 2);
    }

    public function vLiq(float $value): void
    {
        if (!Validator::floatVal()->min(0)->validate($value)) {
            throw new InvalidArgumentException("vLiq deve ser um valor decimal >= 0. Informado: '$value'");
        }
        $this->infValores['vLiq'] = round($value, 2);
    }

    public function descIncond(float $value): void
    {
        $this->infValores['descIncond'] = round($value, 2);
    }

    public function descCond(float $value): void
    {
        $this->infValores['descCond'] = round($value, 2);
    }

    public function vTotalRet(float $value): void
    {
        $this->infValores['vTotalRet'] = round($value, 2);
    }

    public function vRetIRRF(float $value): void
    {
        $this->infValores['vRetIRRF'] = round($value, 2);
    }

    public function vRetCSLL(float $value): void
    {
        $this->infValores['vRetCSLL'] = round($value, 2);
    }

    public function vRetCP(float $value): void
    {
        $this->infValores['vRetCP'] = round($value, 2);
    }
}
