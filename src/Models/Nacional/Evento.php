<?php

namespace NFePHP\NFSe\Models\Nacional;

use DateTime;
use InvalidArgumentException;
use Respect\Validation\Validator;

/**
 * VO do pedido de registro de evento (pedRegEvento) do padrão NFS-e Nacional v1.01.
 *
 * Eventos que o contribuinte pode gerar:
 *   101101  Cancelamento
 *   105102  Cancelamento por substituição
 *   101103  Solicitação de análise fiscal para cancelamento
 *   202201  Confirmação do Prestador
 *   203202  Confirmação do Tomador
 *   204203  Confirmação do Intermediário
 *   202205  Rejeição do Prestador
 *   203206  Rejeição do Tomador
 *   204207  Rejeição do Intermediário
 *
 * Identificador gerado (TSIdPedRegEvt, 59 chars):
 *   "PRE" + Chave NFS-e (50 dígitos) + Tipo do evento (6 dígitos) → 59 chars
 *
 * O autor (contribuinte) assina o XML em `infPedReg` com SHA-256. O órgão
 * receptor embrulha em `<evento>` ao processar, preenchendo verAplic/dhProc/nDFSe
 * e assinando em `infEvento` — esses campos não são gerados pelo contribuinte.
 */
class Evento
{
    // Tipos de evento (código oficial de 6 dígitos)
    public const TP_CANCELAMENTO = '101101';
    public const TP_CANCELAMENTO_SUBSTITUICAO = '105102';
    public const TP_SOLICITACAO_ANALISE_FISCAL = '101103';
    public const TP_CONFIRMACAO_PRESTADOR = '202201';
    public const TP_CONFIRMACAO_TOMADOR = '203202';
    public const TP_CONFIRMACAO_INTERMEDIARIO = '204203';
    public const TP_REJEICAO_PRESTADOR = '202205';
    public const TP_REJEICAO_TOMADOR = '203206';
    public const TP_REJEICAO_INTERMEDIARIO = '204207';

    // Tipo de pessoa do autor
    public const AUTOR_CPF = 1;
    public const AUTOR_CNPJ = 2;

    // Códigos de justificativa — TSCodJustCanc (enum string)
    public const JUST_CANC_ERRO_EMISSAO = '1';
    public const JUST_CANC_SERVICO_NAO_PRESTADO = '2';
    public const JUST_CANC_OUTROS = '9';

    // Códigos de justificativa — TSCodJustSubst (enum string)
    public const JUST_SUBST_DESENQ_SIMPLES = '01';
    public const JUST_SUBST_ENQ_SIMPLES = '02';
    public const JUST_SUBST_INCL_IMUNIDADE = '03';
    public const JUST_SUBST_EXCL_IMUNIDADE = '04';
    public const JUST_SUBST_REJEICAO_TOMADOR = '05';
    public const JUST_SUBST_OUTROS = '99';

    // Códigos de motivo de rejeição — TSCodMotivoRejeicao
    public const REJ_DUPLICIDADE = '1';
    public const REJ_JA_EMITIDA_TOMADOR = '2';
    public const REJ_FATO_GERADOR = '3';
    public const REJ_RESPONSABILIDADE = '4';
    public const REJ_VALOR_SERVICO = '5';
    public const REJ_OUTROS = '9';

    /** @var string TSIdPedRegEvt (59 chars): "PRE" + chave(50) + tipoEvento(6) */
    public $infId;

    /** @var int tpAmb 1=Produção, 2=Homologação */
    public $infTpAmb;

    /** @var string verAplic (1..20 chars) */
    public $infVerAplic = 'prioriza-1.0';

    /** @var DateTime dhEvento (UTC com timezone) */
    public $infDhEvento;

    /** @var int tipo do autor (CPF=1, CNPJ=2) */
    public $infAutorTipo;

    /** @var string CPF/CNPJ do autor */
    public $infAutorDoc;

    /** @var string chave NFS-e (50 dígitos) alvo do evento */
    public $infChNFSe;

    /** @var string Código do tipo de evento (6 dígitos) — ver TP_* */
    public $infTpEvento;

    /**
     * Payload específico do evento. Estrutura varia por tipo:
     *   cancelamento          → ['xDesc', 'cMotivo', 'xMotivo']
     *   cancelamentoSubst     → ['xDesc', 'cMotivo', 'xMotivo'?, 'chSubstituta']
     *   solicitacaoAnalise    → ['xDesc', 'cMotivo', 'xMotivo']
     *   confirmacao*          → ['xDesc']
     *   rejeicao*             → ['xDesc', 'cMotivo', 'xMotivo'?]
     *
     * @var array<string, string>
     */
    public $infEventoPayload = [];

    // =========================================================================
    // Setters de identificação
    // =========================================================================

    public function tpAmb(int $value): void
    {
        if (!Validator::numericVal()->intVal()->between(1, 2)->validate($value)) {
            throw new InvalidArgumentException("tpAmb deve ser 1 ou 2. Informado: '$value'");
        }
        $this->infTpAmb = $value;
    }

    public function verAplic(string $value): void
    {
        if (!Validator::stringType()->length(1, 20)->validate($value)) {
            throw new InvalidArgumentException("verAplic deve ter 1..20 caracteres.");
        }
        $this->infVerAplic = $value;
    }

    public function dhEvento(DateTime $value): void
    {
        $this->infDhEvento = $value;
    }

    /**
     * Autor do evento (quem solicita o registro).
     * @param int $tipo AUTOR_CPF=1 ou AUTOR_CNPJ=2
     */
    public function autor(int $tipo, string $cnpjcpf): void
    {
        if (!in_array($tipo, [self::AUTOR_CPF, self::AUTOR_CNPJ], true)) {
            throw new InvalidArgumentException("Tipo de autor inválido: '$tipo'");
        }
        $expected = $tipo === self::AUTOR_CNPJ ? 14 : 11;
        if (!Validator::digit()->length($expected, $expected)->validate($cnpjcpf)) {
            throw new InvalidArgumentException("Documento do autor deve ter $expected dígitos.");
        }
        $this->infAutorTipo = $tipo;
        $this->infAutorDoc = $cnpjcpf;
    }

    public function chNFSe(string $chave50): void
    {
        if (!Validator::digit()->length(50, 50)->validate($chave50)) {
            throw new InvalidArgumentException("chNFSe deve ter 50 dígitos. Informado: '$chave50'");
        }
        $this->infChNFSe = $chave50;
    }

    // =========================================================================
    // Setters de tipo de evento (cada um define $infTpEvento e $infEventoPayload)
    // =========================================================================

    /**
     * Evento 101101 — Cancelamento.
     *
     * @param string $cMotivo JUST_CANC_* ('1', '2' ou '9')
     * @param string $xMotivo Descrição (15..255 chars)
     */
    public function cancelamento(string $cMotivo, string $xMotivo): void
    {
        if (!in_array($cMotivo, [self::JUST_CANC_ERRO_EMISSAO, self::JUST_CANC_SERVICO_NAO_PRESTADO, self::JUST_CANC_OUTROS], true)) {
            throw new InvalidArgumentException("cMotivo de cancelamento inválido: '$cMotivo'");
        }
        $this->validarXMotivo($xMotivo);

        $this->infTpEvento = self::TP_CANCELAMENTO;
        $this->infEventoPayload = [
            'xDesc' => 'Cancelamento de NFS-e',
            'cMotivo' => $cMotivo,
            'xMotivo' => $xMotivo,
        ];
    }

    /**
     * Evento 105102 — Cancelamento por substituição.
     *
     * @param string      $cMotivo       JUST_SUBST_* ('01'..'05' ou '99')
     * @param string      $chSubstituta  Chave NFS-e substituta (50 dígitos)
     * @param string|null $xMotivo       Descrição opcional (15..255 chars)
     */
    public function cancelamentoSubstituicao(string $cMotivo, string $chSubstituta, ?string $xMotivo = null): void
    {
        $allowed = [self::JUST_SUBST_DESENQ_SIMPLES, self::JUST_SUBST_ENQ_SIMPLES, self::JUST_SUBST_INCL_IMUNIDADE, self::JUST_SUBST_EXCL_IMUNIDADE, self::JUST_SUBST_REJEICAO_TOMADOR, self::JUST_SUBST_OUTROS];
        if (!in_array($cMotivo, $allowed, true)) {
            throw new InvalidArgumentException("cMotivo de substituição inválido: '$cMotivo'");
        }
        if (!Validator::digit()->length(50, 50)->validate($chSubstituta)) {
            throw new InvalidArgumentException("chSubstituta deve ter 50 dígitos.");
        }
        if ($xMotivo !== null) {
            $this->validarXMotivo($xMotivo);
        }

        $this->infTpEvento = self::TP_CANCELAMENTO_SUBSTITUICAO;
        $this->infEventoPayload = [
            'xDesc' => 'Cancelamento de NFS-e por Substituição',
            'cMotivo' => $cMotivo,
            'chSubstituta' => $chSubstituta,
        ];
        if ($xMotivo !== null) {
            $this->infEventoPayload['xMotivo'] = $xMotivo;
        }
    }

    /**
     * Evento 101103 — Solicitação de análise fiscal para cancelamento.
     *
     * @param string $cMotivo JUST_CANC_* ('1', '2' ou '9')
     * @param string $xMotivo Descrição (15..255 chars)
     */
    public function solicitacaoAnaliseFiscal(string $cMotivo, string $xMotivo): void
    {
        if (!in_array($cMotivo, [self::JUST_CANC_ERRO_EMISSAO, self::JUST_CANC_SERVICO_NAO_PRESTADO, self::JUST_CANC_OUTROS], true)) {
            throw new InvalidArgumentException("cMotivo inválido: '$cMotivo'");
        }
        $this->validarXMotivo($xMotivo);

        $this->infTpEvento = self::TP_SOLICITACAO_ANALISE_FISCAL;
        $this->infEventoPayload = [
            'xDesc' => 'Solicitação de Análise Fiscal para Cancelamento de NFS-e',
            'cMotivo' => $cMotivo,
            'xMotivo' => $xMotivo,
        ];
    }

    /** Evento 202201 — Confirmação do Prestador (sem payload adicional). */
    public function confirmacaoPrestador(): void
    {
        $this->infTpEvento = self::TP_CONFIRMACAO_PRESTADOR;
        $this->infEventoPayload = ['xDesc' => 'Manifestação de NFS-e - Confirmação do Prestador'];
    }

    /** Evento 203202 — Confirmação do Tomador. */
    public function confirmacaoTomador(): void
    {
        $this->infTpEvento = self::TP_CONFIRMACAO_TOMADOR;
        $this->infEventoPayload = ['xDesc' => 'Manifestação de NFS-e - Confirmação do Tomador'];
    }

    /** Evento 204203 — Confirmação do Intermediário. */
    public function confirmacaoIntermediario(): void
    {
        $this->infTpEvento = self::TP_CONFIRMACAO_INTERMEDIARIO;
        $this->infEventoPayload = ['xDesc' => 'Manifestação de NFS-e - Confirmação do Intermediário'];
    }

    /**
     * Evento 202205 — Rejeição do Prestador.
     *
     * @param string      $cMotivo REJ_* ('1'..'5' ou '9')
     * @param string|null $xMotivo Descrição opcional
     */
    public function rejeicaoPrestador(string $cMotivo, ?string $xMotivo = null): void
    {
        $this->rejeicaoComum($cMotivo, $xMotivo, self::TP_REJEICAO_PRESTADOR, 'Manifestação de NFS-e - Rejeição do Prestador');
    }

    /** Evento 203206 — Rejeição do Tomador. */
    public function rejeicaoTomador(string $cMotivo, ?string $xMotivo = null): void
    {
        $this->rejeicaoComum($cMotivo, $xMotivo, self::TP_REJEICAO_TOMADOR, 'Manifestação de NFS-e - Rejeição do Tomador');
    }

    /** Evento 204207 — Rejeição do Intermediário. */
    public function rejeicaoIntermediario(string $cMotivo, ?string $xMotivo = null): void
    {
        $this->rejeicaoComum($cMotivo, $xMotivo, self::TP_REJEICAO_INTERMEDIARIO, 'Manifestação de NFS-e - Rejeição do Intermediário');
    }

    /**
     * Monta o Id do pedRegEvento (TSIdPedRegEvt, 59 chars):
     *   "PRE" + chave(50) + tipoEvento(6)
     *
     * Requer que chNFSe e tpEvento já tenham sido definidos.
     */
    public function gerarId(): void
    {
        if (empty($this->infChNFSe) || empty($this->infTpEvento)) {
            throw new \LogicException('chNFSe e tipo de evento devem estar definidos antes de gerarId().');
        }
        $this->infId = 'PRE' . $this->infChNFSe . $this->infTpEvento;
    }

    // =========================================================================
    // Helpers internos
    // =========================================================================

    private function rejeicaoComum(string $cMotivo, ?string $xMotivo, string $tpEvento, string $xDesc): void
    {
        $allowed = [self::REJ_DUPLICIDADE, self::REJ_JA_EMITIDA_TOMADOR, self::REJ_FATO_GERADOR, self::REJ_RESPONSABILIDADE, self::REJ_VALOR_SERVICO, self::REJ_OUTROS];
        if (!in_array($cMotivo, $allowed, true)) {
            throw new InvalidArgumentException("cMotivo de rejeição inválido: '$cMotivo'");
        }
        if ($xMotivo !== null) {
            $this->validarXMotivo($xMotivo);
        }

        $this->infTpEvento = $tpEvento;
        $this->infEventoPayload = ['xDesc' => $xDesc, 'cMotivo' => $cMotivo];
        if ($xMotivo !== null) {
            $this->infEventoPayload['xMotivo'] = $xMotivo;
        }
    }

    private function validarXMotivo(string $xMotivo): void
    {
        $len = strlen(trim($xMotivo));
        if ($len < 15 || $len > 255) {
            throw new InvalidArgumentException("xMotivo deve ter entre 15 e 255 caracteres. Recebido: $len");
        }
    }
}
