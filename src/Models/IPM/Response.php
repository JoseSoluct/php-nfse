<?php

namespace NFePHP\NFSe\Models\IPM;

use DOMDocument;
use DOMElement;
use RuntimeException;
use stdClass;

/**
 * Parser do XML <retorno> do webservice Atende.Net (IPM), NTE 35/2021 v2.9.
 *
 * Formato reduzido (padrão, Tabela 10) e completo (Tabela 11, ligado pelo
 * emissor no Portal da Prefeitura em "Nota Fiscal Eletrônica >> Manutenção >>
 * Personalização do Prestador") são aceitos pela mesma leitura:
 *
 *   <retorno>
 *     <mensagem><codigo>[00001] - Sucesso</codigo></mensagem>   (0..n)
 *     <nfse>
 *       <identificador>…</identificador>
 *       <rps>…</rps>                                               (opcional)
 *       <nfe>  (ou <nf> no completo)
 *         <numero_nfse/> <serie_nfse/> <data_nfse/> <hora_nfse/>
 *         <situacao_codigo_nfse/> <situacao_descricao_nfse/>
 *         <link_nfse/> <cod_verificador_autenticidade/>
 *         … valores da nota (só no completo)
 *       </nfe>
 *       <prestador/> <tomador/> <itens><Item/></itens>            (só no completo; ignorados)
 *     </nfse>
 *   </retorno>
 *
 * Retorno da solicitação de cancelamento (Tabela 7) tem outra forma e é lido
 * por {@see readSolicitacaoCancelamento()}:
 *
 *   <retorno><documentos><nfse><dados><numero/><serie/></dados><mensagem><codigo/></mensagem></nfse></documentos></retorno>
 *
 * Códigos: "[NNNNN] - Descrição". A NTE diz que o número tem cinco posições,
 * mas na prática aparece como [1], [01] e [00001]; a comparação é pelo inteiro.
 *
 * @category  NFePHP
 * @package   NFePHP\NFSe\Models\IPM
 * @license   http://www.gnu.org/licenses/lgpl.txt LGPLv3+
 * @license   https://opensource.org/licenses/MIT MIT
 * @license   http://www.gnu.org/licenses/gpl.txt GPLv3+
 */
class Response
{
    /** Código de mensagem que indica sucesso ("[00001] - Sucesso"). */
    public const CODIGO_SUCESSO = 1;

    public const SITUACAO_EMITIDA = 1;

    public const SITUACAO_CANCELADA = 2;

    /** Campos canônicos do grupo <nfe>/<nf> (Tabelas 10, 11 e 12). */
    public const NFE_FIELDS = [
        'numero_nfse',
        'serie_nfse',
        'data_nfse',
        'hora_nfse',
        'situacao_codigo_nfse',
        'situacao_descricao_nfse',
        'link_nfse',
        'cod_verificador_autenticidade',
        /*
         * Chave de acesso da NFS-e NACIONAL. Só existe no retorno COMPLETO, que
         * o município liga em "Manutenção > Personalização do Prestador > aba
         * WebService > Utiliza Retorno Completo na Importação de XML". Entra na
         * lista para sair sempre presente (null quando o retorno é reduzido),
         * em vez de aparecer e desaparecer conforme a configuração da
         * prefeitura.
         */
        'chave_acesso_nfse_nacional',
    ];

    /**
     * Mensagens de <mensagem><codigo>, cada uma {code: ?int, text: string, raw: string}.
     *
     * @var list<stdClass>
     */
    public array $messages = [];

    /** <nfse><identificador> — identificador do arquivo processado. */
    public ?string $identificador = null;

    /**
     * Grupo <rps>: nro_recibo_provisorio, serie_recibo_provisorio,
     * data_emissao_recibo_provisorio, hora_emissao_recibo_provisorio.
     */
    public ?stdClass $rps = null;

    /**
     * Grupo <nfe>/<nf>: os campos de {@see NFE_FIELDS} (situacao_codigo_nfse
     * como int|null, os demais string|null) mais os filhos escalares extras do
     * retorno completo (valor_total, valor_desconto, …, observacao) como string.
     */
    public ?stdClass $nfe = null;

    /**
     * Só em {@see readSolicitacaoCancelamento()}: lista de
     * {numero: ?string, serie: ?string, messages: list<stdClass>, success: bool}.
     *
     * @var list<stdClass>
     */
    public array $documentos = [];

    /** Resposta original, como veio do webservice. */
    public string $raw = '';

    /**
     * Lê o retorno de emissão, cancelamento simples e consulta
     * (formato reduzido ou completo).
     *
     * @throws RuntimeException quando a resposta não é o XML esperado
     */
    public static function read(string $xml): static
    {
        $response = new static();
        $response->raw = $xml;

        $dom = static::loadDom($xml);
        $root = $dom->documentElement;

        $response->messages = static::readMessages($root);

        $nfseEl = strcasecmp((string) $root->localName, 'nfse') === 0 ? $root : static::firstChild($root, 'nfse');
        if ($nfseEl !== null) {
            $identificador = static::firstChild($nfseEl, 'identificador');
            $response->identificador = $identificador !== null ? static::text($identificador) : null;

            $rpsEl = static::firstChild($nfseEl, 'rps');
            if ($rpsEl !== null) {
                $scalars = static::scalarChildren($rpsEl);
                $response->rps = $scalars !== [] ? (object) $scalars : null;
            }
        }

        /*
         * Os dados da nota vêm dentro de <nfse> no retorno COMPLETO, e soltos
         * na raiz de <retorno> no REDUZIDO — que é o que a emissão devolve:
         *
         *   <retorno>
         *     <mensagem><codigo>00001 - Sucesso</codigo></mensagem>
         *     <numero_nfse>1402</numero_nfse>
         *     <link_nfse>...</link_nfse>
         *   </retorno>
         *
         * Ler só dentro de <nfse> deixava $nfe nulo num retorno de SUCESSO. O
         * consumidor, sem o número, tratava a emissão como recusa — e a nota
         * existia no município. É o pior desfecho possível, então a raiz entra
         * como fallback: `readNfe()` já devolve null quando não acha nada, o
         * que preserva o comportamento nos retornos de erro.
         */
        $response->nfe = static::readNfe($nfseEl ?? $root);

        return $response;
    }

    /**
     * Lê o retorno da solicitação de cancelamento (Tabela 7), um bloco por nota.
     * {@see $messages} recebe a união das mensagens de todos os documentos e
     * {@see isSuccess()} só é verdadeiro quando TODOS os documentos têm código 1.
     *
     * @throws RuntimeException quando a resposta não é o XML esperado
     */
    public static function readSolicitacaoCancelamento(string $xml): static
    {
        $response = new static();
        $response->raw = $xml;

        $dom = static::loadDom($xml);
        $root = $dom->documentElement;

        $documentosEl = static::firstChild($root, 'documentos') ?? $root;
        foreach (static::children($documentosEl, 'nfse') as $nfseEl) {
            $dadosEl = static::firstChild($nfseEl, 'dados') ?? $nfseEl;

            $numeroEl = static::firstChild($dadosEl, 'numero');
            $serieEl = static::firstChild($dadosEl, 'serie');
            $messages = static::readMessages($nfseEl);

            $doc = new stdClass();
            $doc->numero = $numeroEl !== null ? static::text($numeroEl) : null;
            $doc->serie = $serieEl !== null ? static::text($serieEl) : null;
            $doc->messages = $messages;
            $doc->success = static::listHasCode($messages, self::CODIGO_SUCESSO);

            $response->documentos[] = $doc;
            $response->messages = array_merge($response->messages, $messages);
        }

        // Mensagens soltas na raiz (ex.: erro de acesso antes de processar os documentos).
        if ($response->documentos === []) {
            $response->messages = static::readMessages($root);
        }

        return $response;
    }

    // =========================================================================
    // Consulta do resultado
    // =========================================================================

    /**
     * Sucesso: alguma mensagem com código inteiro 1. No retorno de solicitação
     * de cancelamento, todos os documentos precisam ter código 1.
     */
    public function isSuccess(): bool
    {
        if ($this->documentos !== []) {
            foreach ($this->documentos as $doc) {
                if (empty($doc->success)) {
                    return false;
                }
            }

            return true;
        }

        return $this->hasCode(self::CODIGO_SUCESSO);
    }

    public function hasCode(int $code): bool
    {
        return static::listHasCode($this->messages, $code);
    }

    /**
     * Mensagens que não são de sucesso.
     *
     * @return list<stdClass>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn (stdClass $m): bool => $m->code !== self::CODIGO_SUCESSO
        ));
    }

    /**
     * Texto das mensagens de erro no formato "[NNNNN] - Descrição", unidas por $glue.
     */
    public function errorsText(string $glue = ' | '): string
    {
        return implode($glue, array_map(
            static fn (stdClass $m): string => $m->code !== null
                ? sprintf('[%05d] - %s', $m->code, $m->text)
                : $m->text,
            $this->errors()
        ));
    }

    /** Situação da NFS-e: 1 = Emitida, 2 = Cancelada, null quando ausente. */
    public function situacaoCodigo(): ?int
    {
        return $this->nfe->situacao_codigo_nfse ?? null;
    }

    public function isEmitida(): bool
    {
        return $this->situacaoCodigo() === self::SITUACAO_EMITIDA;
    }

    public function isCancelada(): bool
    {
        return $this->situacaoCodigo() === self::SITUACAO_CANCELADA;
    }

    /**
     * @return array{success: bool, messages: list<array{code: ?int, text: string, raw: string}>, identificador: ?string, rps: ?array<string, string>, nfe: ?array<string, mixed>, documentos: list<array<string, mixed>>, raw: string}
     */
    public function toArray(): array
    {
        $toArray = static fn (?stdClass $o): ?array => $o === null ? null : get_object_vars($o);
        $messagesToArray = static fn (array $list): array => array_map($toArray, $list);

        return [
            'success' => $this->isSuccess(),
            'messages' => $messagesToArray($this->messages),
            'identificador' => $this->identificador,
            'rps' => $toArray($this->rps),
            'nfe' => $toArray($this->nfe),
            'documentos' => array_map(static function (stdClass $doc) use ($messagesToArray): array {
                return [
                    'numero' => $doc->numero,
                    'serie' => $doc->serie,
                    'messages' => $messagesToArray($doc->messages),
                    'success' => $doc->success,
                ];
            }, $this->documentos),
            'raw' => $this->raw,
        ];
    }

    // =========================================================================
    // Leitura do DOM
    // =========================================================================

    /**
     * Carrega o XML respeitando a declaração de encoding da resposta. Falha com
     * mensagem clara para HTML (página de login), corpo vazio ou raiz inesperada.
     *
     * @throws RuntimeException
     */
    protected static function loadDom(string $xml): DOMDocument
    {
        $clean = ltrim((string) preg_replace('/^\xEF\xBB\xBF/', '', ltrim($xml)));

        if ($clean === '') {
            throw new RuntimeException('Resposta do Atende.Net vazia: esperado XML <retorno>.');
        }
        if ($clean[0] !== '<') {
            throw new RuntimeException(
                'Resposta do Atende.Net não é XML (esperado <retorno>): ' . static::excerpt($clean)
            );
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $ok = $dom->loadXML($clean, LIBXML_NONET);

        // Sem declaração de encoding e com bytes fora do UTF-8: o servidor
        // costuma responder em ISO-8859-1; converte e tenta de novo.
        if (!$ok && !preg_match('/^<\?xml[^>]*encoding=/i', $clean) && !preg_match('//u', $clean)) {
            libxml_clear_errors();
            $ok = $dom->loadXML(mb_convert_encoding($clean, 'UTF-8', 'ISO-8859-1'), LIBXML_NONET);
        }

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$ok || $dom->documentElement === null) {
            $detail = $errors !== [] ? trim((string) $errors[0]->message) : 'documento sem elemento raiz';
            throw new RuntimeException(
                'Resposta do Atende.Net não é um XML válido (provável página HTML de login ou erro do servidor): '
                . static::excerpt($clean) . ' [libxml: ' . $detail . ']'
            );
        }

        $rootName = strtolower((string) $dom->documentElement->localName);
        // A CONFIRMAR: a consulta (§4.7) diz devolver "o XML da nota"; aceitamos raiz <nfse> por isso.
        if (!in_array($rootName, ['retorno', 'nfse'], true)) {
            throw new RuntimeException(
                "Resposta do Atende.Net com raiz <{$rootName}> em vez de <retorno>: " . static::excerpt($clean)
            );
        }

        return $dom;
    }

    /**
     * Lê <mensagem><codigo> filhos diretos de $context.
     *
     * @return list<stdClass>
     */
    protected static function readMessages(DOMElement $context): array
    {
        $messages = [];
        foreach (static::children($context, 'mensagem') as $mensagemEl) {
            $codigos = static::children($mensagemEl, 'codigo');
            if ($codigos === []) {
                $messages[] = static::parseMessage(static::text($mensagemEl));
                continue;
            }
            foreach ($codigos as $codigoEl) {
                $messages[] = static::parseMessage(static::text($codigoEl));
            }
        }

        return $messages;
    }

    /**
     * "[00001] - Sucesso" → {code: 1, text: "Sucesso", raw: "[00001] - Sucesso"}.
     *
     * O número também é reconhecido SEM os colchetes ("00001 - Sucesso"): a
     * NTE 35/2021 descreve o conteúdo de <codigo> como
     * "[Número do Erro] - [Descrição do Erro]" e nunca esclarece se os
     * colchetes são literais ou apenas a notação de campo do documento. Exigir
     * os colchetes tornava `isSuccess()` falso num retorno de sucesso sem
     * eles — a emissão dava certo no município e o consumidor a tratava como
     * falha. A forma sem colchetes é aceita apenas com os cinco dígitos que a
     * §4.6 garante ("o número do erro sempre será de cinco posições"), para
     * não confundir com uma descrição que comece por número.
     *
     * Sem nenhuma das duas formas, code fica null e text recebe o conteúdo
     * inteiro.
     */
    protected static function parseMessage(string $raw): stdClass
    {
        $message = new stdClass();
        $message->raw = $raw;
        $message->code = null;
        $message->text = $raw;

        if (preg_match('/^\s*\[\s*(\d+)\s*\]\s*(?:-\s*)?(.*)$/su', $raw, $m)) {
            $message->code = (int) $m[1];
            $message->text = trim($m[2]);

            return $message;
        }

        if (preg_match('/^\s*(\d{5})\s*-\s*(.*)$/su', $raw, $m)) {
            $message->code = (int) $m[1];
            $message->text = trim($m[2]);
        }

        return $message;
    }

    /**
     * Grupo <nfe> (reduzido) ou <nf> (a Tabela 11 abre <nfe> e fecha </nf>).
     * Os oito campos canônicos também são procurados em qualquer nível de <nfse>
     * (a consulta, §4.7, acrescenta-os ao layout da nota); nomes terminados em
     * "_nfse" não colidem com <prestador>, <tomador> nem <itens><Item>.
     */
    protected static function readNfe(DOMElement $nfseEl): ?stdClass
    {
        $nfe = new stdClass();

        $groupEl = static::firstChild($nfseEl, 'nfe') ?? static::firstChild($nfseEl, 'nf');
        if ($groupEl !== null) {
            foreach (static::scalarChildren($groupEl) as $tag => $value) {
                $nfe->{$tag} = $value;
            }
        }

        $found = false;
        foreach (self::NFE_FIELDS as $field) {
            $value = $nfe->{$field} ?? static::firstDescendantText($nfseEl, $field);
            if ($value !== null && $value !== '') {
                $found = true;
            }
            $nfe->{$field} = ($value === '' ? null : $value);
        }

        $nfe->situacao_codigo_nfse = ($nfe->situacao_codigo_nfse !== null && is_numeric($nfe->situacao_codigo_nfse))
            ? (int) $nfe->situacao_codigo_nfse
            : null;

        if (!$found && $groupEl === null) {
            return null;
        }

        return $nfe;
    }

    /**
     * @return list<DOMElement>
     */
    protected static function children(DOMElement $parent, string $name): array
    {
        $found = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && strcasecmp((string) $child->localName, $name) === 0) {
                $found[] = $child;
            }
        }

        return $found;
    }

    protected static function firstChild(DOMElement $parent, string $name): ?DOMElement
    {
        $children = static::children($parent, $name);

        return $children[0] ?? null;
    }

    /**
     * Filhos diretos sem elementos-filho, como tag => texto.
     *
     * @return array<string, string>
     */
    protected static function scalarChildren(DOMElement $parent): array
    {
        $scalars = [];
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $hasElementChild = false;
            foreach ($child->childNodes as $grandChild) {
                if ($grandChild instanceof DOMElement) {
                    $hasElementChild = true;
                    break;
                }
            }
            if (!$hasElementChild) {
                $scalars[(string) $child->localName] = static::text($child);
            }
        }

        return $scalars;
    }

    protected static function firstDescendantText(DOMElement $context, string $name): ?string
    {
        $node = $context->getElementsByTagName($name)->item(0);

        return $node instanceof DOMElement ? static::text($node) : null;
    }

    protected static function text(DOMElement $el): string
    {
        return trim((string) $el->textContent);
    }

    /**
     * @param list<stdClass> $messages
     */
    protected static function listHasCode(array $messages, int $code): bool
    {
        foreach ($messages as $message) {
            if (($message->code ?? null) === $code) {
                return true;
            }
        }

        return false;
    }

    protected static function excerpt(string $body, int $length = 300): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $body));

        return substr($flat, 0, $length);
    }
}
