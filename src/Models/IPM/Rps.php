<?php

namespace NFePHP\NFSe\Models\IPM;

/**
 * Classe a construção do xml dos RPS
 * para o modelo IPM (Atende.Net, NTE 35/2021 v2.9)
 *
 *
 * @category  NFePHP
 * @package   NFePHP\NFSe\Models\IPM\Rps
 * @copyright NFePHP Copyright (c) 2016
 * @license   http://www.gnu.org/licenses/lgpl.txt LGPLv3+
 * @license   https://opensource.org/licenses/MIT MIT
 * @license   http://www.gnu.org/licenses/gpl.txt GPLv3+
 * @author    Maykon da S. de Siqueira <maykon at multilig dot com dot br>
 * @link      http://github.com/nfephp-org/sped-nfse for the canonical source repository
 */

use \DateTime;
use Respect\Validation\Validator;
use NFePHP\NFSe\Common\Rps as RpsBase;

class Rps extends RpsBase
{
    const TOMADORPJ = 'J';
    const TOMADORPF = 'F';
    const TOMADORES = 'E';

    /**
     * Domínio de <tipo_pagamento> (Tabela 4 da NTE 35/2021 v2.9).
     *
     * A CONFIRMAR: o catálogo de erros (§5, erro [268]) ainda lista o domínio antigo
     * "1 = À vista, 2 = À prazo, 3 = Na Apresentação, 4 = Cartão de Débito, 5 = Cartão de
     * Crédito"; a Tabela 4 (revisada na v2.4) é a fonte adotada aqui.
     */
    const AVISTA         = 1;
    const APRAZO         = 2;
    const DEPOSITO       = 3;
    const NAAPRESENTACAO = 4;
    const CARTAODEBITO   = 5;
    const CARTAOCREDITO  = 6;
    const CHEQUE         = 7;
    const PIX            = 8;

    /**
     * Campos 0/1 do leiaute (ex.: <endereco_informado>, <tributa_municipio_prestador>):
     * "1"/"S" é sim e "0"/"N" é não. O valor "2" NÃO é aceito pelo Atende.Net.
     */
    const SIM = 1;
    const NAO = 0;

    /**
     * Valores de <tributa_municipio_prestador>: "0" quando a tributação ocorre no local
     * da prestação do serviço; "1" quando ocorre no município do prestador.
     */
    const TRIBUTA_LOCAL_PRESTACAO      = '0';
    const TRIBUTA_MUNICIPIO_PRESTADOR  = '1';

    /**
     * @var string
     */
    public $infCpfCnpjPrestador;
    /**
     * @var array
     */
    public $infTomador = ['tipo' => '', 'cpfcnpj' => '', 'ie' => '', 'nome_razao_social' => '', 'sobrenome_nome_fantasia' => '', 'email' => ''];

    /**
     * @var array
     */
    public $infTomadorEstrangeiro;

    /**
     * Chaves iguais às tags do grupo <tomador> usadas pela factory
     * @var array
     */
    public $infTomadorEndereco = [
        'endereco_informado' => '',
        'logradouro' => '',
        'numero_residencia' => '',
        'complemento' => '',
        'ponto_referencia' => '',
        'bairro' => '',
        'cidade' => '',
        'cep' => ''
    ];

    /**
     * @var array
     */
    public $infTomadorTelefone = [
        'ddd_fone_comercial' => '',
        'fone_comercial' => '',
        'ddd_fone_residencial' => '',
        'fone_residencial' => '',
        'ddd_fax' => '',
        'fone_fax' => ''
    ];

    /**
     * @var array
     */
    public $infPedagio;


    /**
     * @var array
     */
    public $infGenericos = [];

    /**
     * @var array
     */
    public $infFormasPagamentos;

    /**
     * @var array
     */
    public $infProdutos;

    /**
     * @var ItensRps[]
     */
    public $infItens = [];

    /**
     * @var string
     */
    public $infIdentificador;

    /**
     * @var int
     */
    public $infNumero;

    /**
     * @var int
     */
    public $infSerie;

    /**
     * Série da NFS-e (tag <serie_nfse>, primeira de <nf>); só sai no XML quando informada
     * @var int|null
     */
    public $infSerieNfse;

    /**
     * @var DateTime
     */
    public $infDataEmissao;

    /**
     * @var DateTime
     */
    public $infDataFatoGerador;

    /**
     * @var float
     */
    public $infValorTotal;

    /**
     * @var float
     */
    public $infValorDesconto;

    /**
     * @var float
     */
    public $infValorIr;

    /**
     * @var float
     */
    public $infValorInss;

    /**
     * @var float
     */
    public $infValorContribuicaoSocial;

    /**
     * @var float
     */
    public $infValorRps;

    /**
     * @var float
     */
    public $infValorPis;
    /**
     * @var float
     */
    public $infValorCofins;

    /**
     * Observações da NFS-e (texto; quando informado, o webservice exige ao menos 5 caracteres — erro [248])
     * @var string
     */
    public $infObservacao;

    /**
     * Set informations of customer
     * @param string $tipo - TOMADORPJ, TOMADORPF ou TOMADORES
     * @param string $cpfcnpj - pode ficar vazio apenas para tomador estrangeiro (tipo E)
     * @param string $ie
     * @param string $nome_razao_social
     * @param string $sobrenome_nome_fantasia
     * @param string $email
     */
    public function tomador($tipo, $cpfcnpj, $ie, $nome_razao_social, $sobrenome_nome_fantasia, $email)
    {
        $this->infTomador = [
            'tipo' => $tipo,
            'cpfcnpj' => $this->getCpfCnpj($cpfcnpj, 'cpf/cnpj do tomador', $tipo === self::TOMADORES),
            'ie' => $ie,
            'nome_razao_social' => $nome_razao_social,
            'sobrenome_nome_fantasia' => $sobrenome_nome_fantasia,
            'email' => $email,
        ];
    }

    /**
     * Set informations of foreign customer
     * @param string $identificador
     * @param string $estado
     * @param string $pais
     */
    public function tomadorEstrangeiro($identificador, $estado, $pais)
    {
        $this->infTomadorEstrangeiro = [
            'identificador' => $identificador,
            'estado'        => $estado,
            'pais'          => $pais,
        ];
    }

    /**
     * Set address of customer
     * @param string $endereco_informado - Rps::SIM ou Rps::NAO (também aceita "S"/"N")
     * @param string $logradouro
     * @param string $numero_residencia
     * @param string $complemento
     * @param string $ponto_referencia
     * @param string $bairro
     * @param int $cidade
     * @param int $cep
     */
    public function tomadorEndereco(
        $endereco_informado,
        $logradouro,
        $numero_residencia,
        $complemento,
        $ponto_referencia,
        $bairro,
        $cidade,
        $cep
    ) {
        $this->infTomadorEndereco = [
            'endereco_informado' => $endereco_informado,
            'logradouro' => $logradouro,
            'numero_residencia' => $numero_residencia,
            'complemento' => $complemento,
            'ponto_referencia' => $ponto_referencia,
            'bairro' => $bairro,
            'cidade' => $cidade,
            'cep' => $cep
        ];
    }

    /**
     * Set phones of customer
     * @param string ddd_fone_comercial
     * @param string fone_comercial
     * @param string ddd_fone_residencial
     * @param string fone_residencial
     * @param string ddd_fax
     * @param string fone_fax
     */
    public function tomadorTelefone(
        $ddd_fone_comercial = '',
        $fone_comercial = '',
        $ddd_fone_residencial = '',
        $fone_residencial = '',
        $ddd_fax = '',
        $fone_fax = ''
    ) {
        $this->infTomadorTelefone = [
            'ddd_fone_comercial'   => $this->getDddFone($ddd_fone_comercial, 'ddd_fone_comercial'),
            'fone_comercial'       => $this->getFone($fone_comercial, 'fone_comercial'),
            'ddd_fone_residencial' => $this->getDddFone($ddd_fone_residencial, 'ddd_fone_residencial'),
            'fone_residencial'     => $this->getFone($fone_residencial, 'fone_residencial'),
            'ddd_fax'              => $this->getDddFone($ddd_fax, 'ddd_fax'),
            'fone_fax'             => $this->getFone($fone_fax, 'fone_fax'),
        ];
    }

    /**
     * Adiciona um item (<itens><lista>) à nota
     * @param ItensRps $item
     */
    public function addItens(
        ItensRps $item
    ) {
        $this->infItens[] = $item;
    }

    /**
     * Set inf generic inf
     *
     * A barra ("/") é trocada por hífen nos dois campos — exigência do
     * provedor (Tabela 3 da NTE 35/2021 v2.9, "Não é permitido").
     * {@see sanitizeTextoLivre()}
     *
     * @param string titulo
     * @param string descricao
     */
    public function addLinhaGenerico(
        $titulo,
        $descricao
    ) {
        $this->infGenericos[] = [
            'titulo'    => $this->sanitizeTextoLivre($titulo),
            'descricao' => $this->sanitizeTextoLivre($descricao)
        ];
    }

    /**
     * Set inf of products
     * @param string descricao
     * @param string valor
     */
    public function produtos(
        $descricao,
        $valor
    ) {
        $this->infProdutos = [
            'valor'     => $this->getValorFormatado($valor),
            'descricao' => $descricao
        ];
    }

    /**
     * Define a forma de pagamento (tag <tipo_pagamento>); as parcelas entram por addParcela()
     * @param int $tipo_pagamento - uma das constantes AVISTA..PIX (1 a 8)
     * @throws InvalidArgumentException
     */
    public function formaPagamento(
        $tipo_pagamento
    ) {
        if (!Validator::numericVal()->intVal()->between(1, 8)->validate($tipo_pagamento)) {
            throw new \InvalidArgumentException(
                "O tipo de pagamento deve ser um inteiro entre 1 e 8 (constantes AVISTA..PIX). Informado: '$tipo_pagamento'"
            );
        }
        $this->infFormasPagamentos = [
            'tipo_pagamento' => (int) $tipo_pagamento,
            'parcelas'       => []
        ];
    }

    /**
     * Adiciona uma parcela (<parcelas><parcela>) à forma de pagamento.
     * O leiaute permite números de parcela entre 1 e 24 (erros [271], [274], [277]).
     * @param int $numero
     * @param float $valor
     * @param DateTime $data_vencimento
     * @throws InvalidArgumentException
     */
    public function addParcela(
        int $numero,
        float $valor,
        DateTime $data_vencimento
    ) {
        if ($numero < 1 || $numero > 24) {
            throw new \InvalidArgumentException("O número da parcela deve estar entre 1 e 24. Informado: '$numero'");
        }
        if ($valor <= 0) {
            throw new \InvalidArgumentException("O valor da parcela deve ser maior que zero. Informado: '$valor'");
        }
        $this->infFormasPagamentos['parcelas'][] = [
            'numero'          => $numero,
            'valor'           => $this->getValorFormatado($valor),
            'data_vencimento' => $data_vencimento
        ];
    }

    /**
     * CPF/CNPJ do prestador (emissor da nota)
     * @param string $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function cpfCnpjPrestador($value, $campo = null)
    {
        $this->infCpfCnpjPrestador = $this->getCpfCnpj($value, $campo);
    }

    /**
     * Sanitiza e valida CPF/CNPJ: remove tudo que não for dígito e exige 11 (CPF) ou
     * 14 (CNPJ) números, pois o leiaute pede "apenas números" (erros [3], [14], [144]).
     * @param string|null $value
     * @param string|null $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @param bool $permiteVazio - true para tomador estrangeiro (tipo E), que não possui CPF/CNPJ
     * @return string
     * @throws InvalidArgumentException
     */
    protected function getCpfCnpj($value, $campo, $permiteVazio = false)
    {
        if (!$campo) {
            $msg = "O cpf cnpj não pode ser vazio e deve ter 11 ou 14 números.";
        } else {
            $msg = "O item '$campo' não pode ser vazio e deve ter 11 ou 14 números. Informado: '$value'";
        }

        $value = preg_replace('/\D/', '', (string) $value);
        if ($value === '' && $permiteVazio) {
            return '';
        }
        if (!Validator::regex('/^(\d{11}|\d{14})$/')->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        return $value;
    }

    /**
     * Código do equipamento eletrônico de cobrança automática de pedágio (<pedagio>)
     * @param string $codEquipamento
     */
    public function pedagio($codEquipamento)
    {
        $this->infPedagio = [
            'cod_equipamento_automatico' => $this->codEquipamento($codEquipamento)
        ];
    }

    /**
     * Informações referentes ao código do equipamento eletrônico de cobrança automática para pedágios
     * @param string $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @return string
     * @throws InvalidArgumentException
     */
    protected function codEquipamento($value, $campo = null)
    {
        if (!$campo) {
            $msg = "O código do equipamento eletrônico do pedágio não pode ser vazia e deve ter até 100 caracteres.";
        } else {
            $msg = "O item '$campo' não pode ser vazio e deve ter até 100 caracteres. Informado: '$value'";
        }

        $value = trim((string) $value);
        if (!Validator::stringType()->length(1, 100)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        return $value;
    }

    /**
     * Identificador do arquivo a ser processado (tag <identificador>, até 80 caracteres)
     * @param string $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function identificador($value, $campo = null)
    {
        if (!$campo) {
            $msg = "O identificador do arquivo não pode ser vazia e deve ter até 80 caracteres.";
        } else {
            $msg = "O item '$campo' não pode ser vazio e deve ter até 80 caracteres. Informado: '$value'";
        }

        $value = trim($value);
        if (!Validator::stringType()->length(1, 80)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infIdentificador = $value;
    }

    /**
     * Set number of RPS
     * @param int $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function numero($value, $campo = null)
    {
        if (!$campo) {
            $msg = "O numero do RPS deve ser um inteiro positivo apenas.";
        } else {
            $msg = "O item '$campo' deve ser um inteiro positivo apenas. Informado: '$value'";
        }

        if (!Validator::numericVal()->intVal()->positive()->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infNumero = $value;
    }

    /**
     * Set series of RPS
     * @param string $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function serie($value, $campo = null)
    {
        if (!$campo) {
            $msg = "A série do RPS deve ser um inteiro positivo apenas.";
        } else {
            $msg = "O item '$campo' deve ser um inteiro positivo apenas. Informado: '$value'";
        }

        $value = trim($value);
        if (!Validator::numericVal()->intVal()->positive()->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infSerie = $value;
    }

    /**
     * Série da NFS-e (tag <serie_nfse>, primeira tag de <nf>). Opcional: só sai no XML quando informada.
     * @param int $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function serieNfse($value, $campo = null)
    {
        if (!$campo) {
            $msg = "A série da NFS-e deve ser um inteiro positivo apenas.";
        } else {
            $msg = "O item '$campo' deve ser um inteiro positivo apenas. Informado: '$value'";
        }

        if (!Validator::numericVal()->intVal()->positive()->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infSerieNfse = $value;
    }

    /**
     * Set date of issue
     * @param DateTime $value
     */
    public function dataEmissao(DateTime $value)
    {
        $this->infDataEmissao = $value;
    }

    /**
     * Set date of issue
     * @param DateTime $value
     */
    public function dataFatoGerador(DateTime $value)
    {
        $this->infDataFatoGerador = $value;
    }

    /**
     * Set service amount
     * @param float $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function valorTotal($value = 0.00, $campo = null)
    {
        if (!$campo) {
            $msg = "Os valores devem ser numericos tipo float.";
        } else {
            $msg = "O item '$campo' deve ser numérico tipo float. Informado: '$value'";
        }

        if (!Validator::numericVal()->floatVal()->min(0)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infValorTotal = $this->getValorFormatado($value);
    }

    /**
     * Set service discont
     * @param float $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function valorDesconto($value = 0.00, $campo = null)
    {
        if (!$campo) {
            $msg = "Os valores devem ser numericos tipo float.";
        } else {
            $msg = "O item '$campo' deve ser numérico tipo float. Informado: '$value'";
        }

        if (!Validator::numericVal()->floatVal()->min(0)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infValorDesconto = $this->getValorFormatado($value);
    }

    /**
     * Set amount for IR tax
     * @param float $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function valorIr($value = 0.00, $campo = null)
    {
        if (!$campo) {
            $msg = "Os valores devem ser numericos tipo float.";
        } else {
            $msg = "O item '$campo' deve ser numérico tipo float. Informado: '$value'";
        }

        if (!Validator::numericVal()->floatVal()->min(0)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infValorIr = $this->getValorFormatado($value);
    }

    /**
     * Set amount for INSS tax
     * @param float $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function valorInss($value = 0.00, $campo = null)
    {
        if (!$campo) {
            $msg = "Os valores devem ser numericos tipo float.";
        } else {
            $msg = "O item '$campo' deve ser numérico tipo float. Informado: '$value'";
        }

        if (!Validator::numericVal()->floatVal()->min(0)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infValorInss = $this->getValorFormatado($value);
    }

    /**
     * Set amount for Contribuicao Social tax
     * @param float $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function valorContribuicaoSocial($value = 0.00, $campo = null)
    {
        if (!$campo) {
            $msg = "Os valores devem ser numericos tipo float.";
        } else {
            $msg = "O item '$campo' deve ser numérico tipo float. Informado: '$value'";
        }

        if (!Validator::numericVal()->floatVal()->min(0)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infValorContribuicaoSocial = $this->getValorFormatado($value);
    }

    /**
     * Set Retenções da Previdência Social
     * @param float $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function valorRps($value = 0.00, $campo = null)
    {
        if (!$campo) {
            $msg = "Os valores devem ser numericos tipo float.";
        } else {
            $msg = "O item '$campo' deve ser numérico tipo float. Informado: '$value'";
        }

        if (!Validator::numericVal()->floatVal()->min(0)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infValorRps = $this->getValorFormatado($value);
    }

    /**
     * Set amount for PIS tax
     * @param float $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function valorPis($value = 0.00, $campo = null)
    {
        if (!$campo) {
            $msg = "Os valores devem ser numericos tipo float.";
        } else {
            $msg = "O item '$campo' deve ser numérico tipo float. Informado: '$value'";
        }

        if (!Validator::numericVal()->floatVal()->min(0)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infValorPis = $this->getValorFormatado($value);
    }

    /**
     * Set amount for COFINS tax
     * @param float $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function valorCofins($value = 0.00, $campo = null)
    {
        if (!$campo) {
            $msg = "Os valores devem ser numericos tipo float.";
        } else {
            $msg = "O item '$campo' deve ser numérico tipo float. Informado: '$value'";
        }

        if (!Validator::numericVal()->floatVal()->min(0)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infValorCofins = $this->getValorFormatado($value);
    }

    /**
     * Observações da NFS-e (texto livre, até 1000 caracteres)
     *
     * A barra ("/") é trocada por hífen: a Tabela 3 da NTE 35/2021 v2.9 lista
     * os caracteres especiais que o webservice escapa e marca a barra como
     * "Não é permitido" — ela não tem entidade de escape e derruba o
     * processamento do arquivo. {@see sanitizeTextoLivre()}
     *
     * @param string $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function observacao($value, $campo = null)
    {
        if (!$campo) {
            $msg = "As observações da NFS-e não pode ser vazia e deve ter até 1000 caracteres.";
        } else {
            $msg = "O item '$campo' não pode ser vazio e deve ter até 1000 caracteres. Informado: '$value'";
        }

        $value = $this->sanitizeTextoLivre(trim($value));
        if (!Validator::stringType()->length(1, 1000)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infObservacao = $value;
    }

    private function getFone($value, $campo = "") {
        if($value == "") {
            return "";
        }
        if (!$campo) {
            $msg = "O telefone não pode ser vazio e deve possuir 8 ou 9 caracteres numéricos.";
        } else {
            $msg = "O item '$campo' não pode ser vazio e deve possuir 8 ou 9 caracteres numéricos. Informado: '$value'";
        }

        $value = preg_replace("/\D/","", $value);
        if (!Validator::length(8, 9)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        return $value;
    }

    private function getDddFone($value, $campo = "") {
        if($value == "") {
            return "";
        }

        if (!$campo) {
            $msg = "O ddd deve possuir 2 ou 3 caracteres numéricos.";
        } else {
            $msg = "O item '$campo' deve possuir 2 ou 3 caracteres numéricos. Informado: '$value'";
        }

        $value = preg_replace("/\D+/","", $value);
        // A NTE 2.9 trata o tamanho 3 como MÁXIMO, não como exato: os erros
        // [230], [231] e [232] reprovam o DDD que "contém mais que 3
        // caracteres". Exigir exatamente 3 reprovava todo DDD brasileiro, que
        // tem 2 dígitos — só passava a grafia antiga com zero à frente
        // ("041"), justamente a usada em examples/IPM, razão de o erro ter
        // sobrevivido sem ser notado.
        if (!Validator::length(2, 3)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        return $value;
    }

    private function getValorFormatado($value)
    {
        return \number_format(round($value, 2), 2, ',', '');
    }

    /**
     * Troca a barra ("/") por hífen nos campos de texto livre.
     *
     * A Tabela 3 da NTE 35/2021 v2.9 relaciona os caracteres especiais que o
     * Atende.Net escapa no XML (&, <, >, ", ') e registra a barra como "Não é
     * permitido": ela não possui entidade de escape e faz o webservice recusar
     * o arquivo. Como o saneamento é exigência do provedor — e não do
     * consumidor —, ele mora aqui, para que toda aplicação que use a
     * biblioteca herde o mesmo comportamento.
     *
     * A troca é 1:1, então não altera o tamanho validado do campo.
     *
     * @param string|null $value
     * @return string
     */
    private function sanitizeTextoLivre($value)
    {
        return \str_replace('/', '-', (string) $value);
    }
}
