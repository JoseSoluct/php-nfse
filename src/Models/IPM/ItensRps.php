<?php

namespace NFePHP\NFSe\Models\IPM;

use NFePHP\NFSe\Models\IPM\Rps;
use Respect\Validation\Validator;

/**
 * @author Tiago Franco
 * Definicao das informacoes da tag itens para geracao das notas
 */
class ItensRps
{

    /**
     * @var int
     */
    public $infCodigoLocalPrestacaoServico;

    /**
     * "0"/"N" tributa no local da prestação; "1"/"S" tributa no município do prestador
     * @var string
     */
    public $infTributaMunicipioPrestador;

    /**
     * @var int
     */
    public $infUnidadeCodigo;

    /**
     * @var float
     */
    public $infUnidadeQuantidade;

    /**
     * @var float
     */
    public $infUnidadeValorUnitario;

    /**
     * @var int
     */
    public $infCodigoItemListaServico;
    /**
     * Código de atividade conforme definido no município (opcional)
     * @var int|null
     */
    public $infCodigoAtividade;
    /**
     * @var string
     */
    public $infDescritivo;
    /**
     * @var float
     */
    public $infAliquotaItemListaServico;
    /**
     * @var float
     */
    public $infSituacaoTributaria;
    /**
     * @var float
     */
    public $infValorTributavel;
    /**
     * @var float
     */
    public $infValorDeducao;
    /**
     * @var float
     */
    public $infValorIssrf;

    // =========================================================================
    // Reforma Tributária — IBS/CBS (NTE 122/2025 v1.7)
    // =========================================================================

    /**
     * Código NBS (Nomenclatura Brasileira de Serviços), no formato
     * 9.9999.99.99 — a tag é `Caractere`, então o ponto é parte do dado, ao
     * contrário do subitem da LC 116.
     * @var string|null
     */
    public $infCodigoNbs;

    /**
     * Desconto INCONDICIONAL do item, que entra na base do IBS/CBS:
     * vBC = valor do serviço − desconto incondicionado − PIS/COFINS próprio.
     *
     * Não é o mesmo que `<valor_desconto>` de `<nf>`, que é o desconto da nota
     * e não participa desse cálculo.
     * @var string|null
     */
    public $infValorDescontoIncondicional;

    /**
     * Tributação no município do TOMADOR.
     *
     * Excludente de {@see $infTributaMunicipioPrestador}: o erro [389] recusa a
     * incidência informada para os dois, e o [390] a recusa para o tomador
     * quando não há tomador.
     * @var string|null
     */
    public $infTributaMunicipioTomador;
    /**
     * Set Codigo TOM county code where service was realized Receita Federal
     * @param int $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function codigoLocalPrestacaoServico($value, $campo = null)
    {
        if (!$campo) {
            $msg = "Deve ser passado o código TOM junto a receita federal.";
        } else {
            $msg = "O item '$campo' deve ser inteiro, referente ao código TOM junto a receita federal. Informado: '$value'";
        }

        if (!Validator::numericVal()->intVal()->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infCodigoLocalPrestacaoServico = $value;
    }

    /**
     * Informa onde será recolhido o imposto (tag <tributa_municipio_prestador>).
     * A NTE 35/2021 só aceita "0"/"N" (local da prestação) ou "1"/"S" (município
     * do prestador); os inteiros 0 e 1 também são aceitos. "2" é inválido.
     * @param string|int $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function tributaMunicipioPrestador($value = Rps::TRIBUTA_MUNICIPIO_PRESTADOR, $campo = null)
    {
        if (!$campo) {
            $msg = "Tributa municipio prestador deve ser 0/N (local da prestação) ou 1/S (município do prestador).";
        } else {
            $msg = "O item '$campo' deve ser 0/N ou 1/S. Informado: '$value'";
        }

        $value = strtoupper(trim((string) $value));
        if (!in_array($value, ['0', '1', 'N', 'S'], true)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infTributaMunicipioPrestador = $value;
    }

    /**
     * Set opting for Code Unidad
     * @param int $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function unidadeCodigo($value, $campo = null)
    {
        if (!$campo) {
            $msg = "Unidade Codigo deve ser númerico e possuir até 9 dígitos.";
        } else {
            $msg = "O item '$campo' deve ser númerico e possuir até 9 dígitos. Informado: '$value'";
        }

        if (!Validator::numericVal()->intVal()->length(1, 9)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infUnidadeCodigo = $value;
    }

    /**
     * Set opting for Code Unidad
     * @param float $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function unidadeQuantidade($value = 0.00, $campo = null)
    {
        if (!$campo) {
            $msg = "Os valores devem ser numericos tipo float.";
        } else {
            $msg = "O item '$campo' deve ser numérico tipo float. Informado: '$value'";
        }

        if (!Validator::numericVal()->floatVal()->min(0)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infUnidadeQuantidade = $this->getValorFormatado($value);
    }

    /**
     * Set opting for Code Unidad
     * @param float $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function unidadeValorUnitario($value = 0.00, $campo = null)
    {
        if (!$campo) {
            $msg = "Os valores devem ser numericos tipo float.";
        } else {
            $msg = "O item '$campo' deve ser numérico tipo float. Informado: '$value'";
        }

        if (!Validator::numericVal()->floatVal()->min(0)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infUnidadeValorUnitario = $this->getValorFormatado($value);
    }

    /**
     * Set opting for Code Unidad
     * @param int $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function codigoItemListaServico($value, $campo = null)
    {
        if (!$campo) {
            $msg = "O codigo do subitem da lista de serviços.";
        } else {
            $msg = "O item '$campo' deve ser inteiro, referente a subitem da lista de serviços. Informado: '$value'";
        }

        if (!Validator::numericVal()->intVal()->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infCodigoItemListaServico = $value;
    }

    /**
     * Código de atividade conforme definido no município (tag <codigo_atividade>).
     * Opcional: só sai no XML quando informado.
     * @param int $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function codigoAtividade($value, $campo = null)
    {
        if (!$campo) {
            $msg = "O código de atividade deve ser númerico e possuir até 9 dígitos.";
        } else {
            $msg = "O item '$campo' deve ser númerico e possuir até 9 dígitos. Informado: '$value'";
        }

        if (!Validator::numericVal()->intVal()->length(1, 9)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infCodigoAtividade = $value;
    }

    /**
     * Descritivo coloquial do serviço prestado (texto livre, até 1000 caracteres).
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
    public function descritivo($value, $campo = null)
    {
        if (!$campo) {
            $msg = "Descritivo coloquial do serviço prestado nao pode ser vazio e deve ter até 1000 caracteres";
        } else {
            $msg = "O item '$campo' não pode ser vazio e deve ter até 1000 caracteres. Informado: '$value'";
        }

        $value = $this->sanitizeTextoLivre($value);
        if (!Validator::length(1, 1000)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infDescritivo = $value;
    }

    /**
     * Set opting for Code Unidad
     * @param float $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function aliquotaItemListaServico($value = 0.00, $campo = null)
    {
        if (!$campo) {
            $msg = "Os valores devem ser numericos tipo float.";
        } else {
            $msg = "O item '$campo' deve ser numérico tipo float. Informado: '$value'";
        }

        if (!Validator::numericVal()->floatVal()->min(0)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infAliquotaItemListaServico = $this->getValorFormatado($value);
    }

    /**
     * Set opting for Code Unidad
     * @param int $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function situacaoTributaria($value, $campo = null)
    {
        if (!$campo) {
            $msg = "Código da situacao tributaria deve ser númerico e possuir até 4 digitos";
        } else {
            $msg = "O item '$campo' deve ser númerico e possuir até 4 dígitos. Informado: '$value'";
        }

        if (!Validator::numericVal()->length(1, 4)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infSituacaoTributaria = $value;
    }

    /**
     * Set opting for Code Unidad
     * @param float $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function valorTributavel($value = 0.00, $campo = null)
    {
        if (!$campo) {
            $msg = "Os valores devem ser numericos tipo float.";
        } else {
            $msg = "O item '$campo' deve ser numérico tipo float. Informado: '$value'";
        }

        if (!Validator::numericVal()->floatVal()->min(0)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infValorTributavel = $this->getValorFormatado($value);
    }

    /**
     * Set opting for Code Unidad
     * @param float $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function valorDeducao($value = 0.00, $campo = null)
    {
        if (!$campo) {
            $msg = "Os valores devem ser numericos tipo float.";
        } else {
            $msg = "O item '$campo' deve ser numérico tipo float. Informado: '$value'";
        }

        if (!Validator::numericVal()->floatVal()->min(0)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infValorDeducao = $this->getValorFormatado($value);
    }

    /**
     * Set opting for Code Unidad
     * @param float $value
     * @param string $campo - String com o nome do campo caso queira mostrar na mensagem de validação
     * @throws InvalidArgumentException
     */
    public function valorIssrf($value = 0.00, $campo = null)
    {
        if (!$campo) {
            $msg = "Os valores devem ser numericos tipo float.";
        } else {
            $msg = "O item '$campo' deve ser numérico tipo float. Informado: '$value'";
        }

        if (!Validator::numericVal()->floatVal()->min(0)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }
        $this->infValorIssrf = $this->getValorFormatado($value);
    }


    // =========================================================================
    // Reforma Tributária — IBS/CBS (NTE 122/2025 v1.7)
    // =========================================================================

    /**
     * Codigo NBS. Obrigatorio quando o municipio exige IBS/CBS — o erro [366]
     * cobra a tag e o [367] recusa NBS inexistente.
     *
     * A tag e `Caractere`, entao os pontos do formato 9.9999.99.99 ficam. Os
     * erros [368] e [414] validam o vinculo NBS x lista de servico x indicador
     * de operacao x classificacao tributaria, que e cadastro, nao formato.
     *
     * @param string $value
     * @param string|null $campo
     * @throws InvalidArgumentException
     */
    public function codigoNbs($value, $campo = null)
    {
        $rotulo = $campo ?: 'codigo NBS';

        /*
         * O XSD do webservice declara a tag como `xs:integer`, e recusa o
         * codigo pontuado:
         *
         *   XSD Error 1824: Element 'codigo_nbs': '1.2001.31.10' is not a
         *   valid value of the atomic type 'xs:integer'.
         *
         * A coluna "tipo" da NTE 122/2025 diz `Caractere`, o que contradiz o
         * XSD — mas a coluna "tamanho" diz 9, que e exatamente o numero de
         * digitos do formato 9.9999.99.99 sem os pontos. O XSD vence.
         */
        $digits = preg_replace('/[^0-9]/', '', (string) $value);

        if (!Validator::stringType()->length(1, 9)->validate($digits)) {
            throw new \InvalidArgumentException(
                "O item '$rotulo' deve ter ate 9 digitos (o codigo 9.9999.99.99 sem os pontos). Informado: '$value'"
            );
        }

        $this->infCodigoNbs = $digits;
    }

    /**
     * Desconto incondicional do item, que reduz a base do IBS/CBS.
     *
     * @param float $value
     * @param string|null $campo
     * @throws InvalidArgumentException
     */
    public function valorDescontoIncondicional($value = 0.00, $campo = null)
    {
        if (!$campo) {
            $msg = "Os valores devem ser numericos tipo float.";
        } else {
            $msg = "O item '$campo' deve ser numerico tipo float. Informado: '$value'";
        }

        if (!Validator::numericVal()->floatVal()->min(0)->validate($value)) {
            throw new \InvalidArgumentException($msg);
        }

        $this->infValorDescontoIncondicional = $this->getValorFormatado($value);
    }

    /**
     * Tributacao no municipio do tomador: 0/N ou 1/S, na mesma grafia de
     * {@see tributaMunicipioPrestador()}.
     *
     * @param string|int $value
     * @param string|null $campo
     * @throws InvalidArgumentException
     */
    public function tributaMunicipioTomador($value, $campo = null)
    {
        if (!$campo) {
            $msg = "Tributa municipio tomador deve ser 0/N ou 1/S.";
        } else {
            $msg = "O item '$campo' deve ser 0/N ou 1/S. Informado: '$value'";
        }

        $value = strtoupper(trim((string) $value));
        if (!in_array($value, array('0', '1', 'N', 'S'), true)) {
            throw new \InvalidArgumentException($msg);
        }

        $this->infTributaMunicipioTomador = $value;
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
