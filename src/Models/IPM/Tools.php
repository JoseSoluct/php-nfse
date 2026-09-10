<?php

namespace NFePHP\NFSe\Models\IPM;

use CURLStringFile;
use NFePHP\Common\Certificate;
use NFePHP\NFSe\Common\DateTime;
use NFePHP\NFSe\Common\Tools as ToolsBase;
use RuntimeException;
use stdClass;

/**
 * Cliente REST do webservice Atende.Net (IPM Sistemas) para emissão de NFS-e,
 * conforme a Nota Técnica NTE 35/2021 versão 2.9.
 *
 * Protocolo oficial:
 *   - URL por município do PRESTADOR:
 *       https://{cidade}.atende.net/?pg=rest&service=WNERestServiceNFSe
 *     onde {cidade} é o nome do município sem pontuação e sem espaços.
 *   - Autenticação HTTP Basic: username = CPF/CNPJ do emissor (só dígitos,
 *     erro [144] caso contrário) e password = senha de acesso ao sistema.
 *   - POST multipart/form-data com UMA parte de arquivo contendo o XML.
 *     Uma nota por XML; as requisições são síncronas (só iniciar a próxima
 *     depois de concluir a anterior).
 *   - A primeira resposta devolve o cookie PHPSESSID; reenviá-lo nas próximas
 *     requisições reduz consideravelmente o tempo de emissão.
 *   - Resposta sempre em XML (<retorno>), interpretada por {@see Response}.
 *
 * Configuração ($config) esperada:
 *   - city_slug        (string, obrigatório salvo url_template sem {cidade})
 *                      nome do município do prestador; é normalizado
 *                      (minúsculas, sem acento, sem pontuação/espaço).
 *   - url_template     (string, opcional) sobrescreve o template inteiro;
 *                      pode conter o marcador {cidade}.
 *   - multipart_field  (string, opcional, padrão 'File') nome da parte do
 *                      form-data que carrega o XML.
 *   - login            (string, opcional) usuário da autenticação; quando
 *                      vazio usa o cnpj/cpf do config. Só dígitos são aceitos.
 *   - senha            (string, obrigatório) senha do sistema.
 *   - cnpj | cpf, razaosocial, im, siglaUF — dados do emissor.
 *   - cod_tom_municipio (string|int) código TOM do município do prestador,
 *                      usado pelas factories no XML.
 *   - teste            (0|1) liga a tag <nfse_teste> (teste de integração).
 *   - versao           (opcional) 1 ou 100 — ambos resolvem para v100.
 *
 * Nenhum dado de credencial é gravado em {@see getLastRequest()} nem em
 * mensagens de exceção: o header Authorization e o Cookie saem redigidos.
 *
 * @category  NFePHP
 * @package   NFePHP\NFSe\Models\IPM
 * @license   http://www.gnu.org/licenses/lgpl.txt LGPLv3+
 * @license   https://opensource.org/licenses/MIT MIT
 * @license   http://www.gnu.org/licenses/gpl.txt GPLv3+
 */
class Tools extends ToolsBase
{
    /** Única versão de layout existente para o modelo IPM (Factories\v100). */
    public const VERSAO = 100;

    /** Template oficial da URL (NTE 35/2021 v2.9, Tabela 1). */
    public const URL_TEMPLATE = 'https://{cidade}.atende.net/?pg=rest&service=WNERestServiceNFSe';

    /**
     * Nome padrão da parte multipart que carrega o XML.
     * A NTE só diz "alterar a Key para File" (§4.14, Postman); por isso é
     * configurável via $config->multipart_field.
     */
    public const MULTIPART_FIELD = 'File';

    /** Nome do cookie de sessão devolvido pelo Atende.Net. */
    public const SESSION_COOKIE = 'PHPSESSID';

    protected $versao = self::VERSAO;

    /** Timeout (s) para requisições HTTP ao Atende.Net. */
    protected int $timeout = 60;

    /**
     * Slug padrão do município, para subclasses de município
     * (ex.: Counties\M4105805). O $config->city_slug tem precedência.
     */
    protected $citySlug = '';

    /** Identificador de sessão (valor do cookie PHPSESSID) capturado da última resposta. */
    protected ?string $sessionId = null;

    /**
     * Registro da última requisição HTTP enviada, já sem credenciais.
     *
     * @var array{method: string, url: string, headers: array<int, string>, multipartField: string, bodyLength: int, httpCode: int}|null
     */
    protected ?array $lastRequest = null;

    /**
     * @param stdClass                          $config
     * @param \NFePHP\Common\Certificate|null   $certificate opcional: o Atende.Net não exige
     *                                                       certificado; quando presente o XML é assinado.
     */
    public function __construct(stdClass $config, ?Certificate $certificate = null)
    {
        $this->config = $config;
        $this->certificate = $certificate;

        $this->versao = $this->normalizeVersao($config->versao ?? $this->versao);

        $this->remetenteCNPJCPF = (string) ($config->cpf ?? '');
        $this->remetenteRazao = (string) ($config->razaosocial ?? '');
        $this->remetenteIM = (string) ($config->im ?? '');
        $this->remetenteTipoDoc = 1;
        if (!empty($config->cnpj)) {
            $this->remetenteCNPJCPF = (string) $config->cnpj;
            $this->remetenteTipoDoc = 2;
        }

        // DateTime::tzdBR() chama date_default_timezone_set() (efeito global no
        // processo do consumidor); aqui só resolvemos o fuso, sem alterar o padrão.
        $uf = strtoupper((string) ($config->siglaUF ?? ''));
        $this->timezone = new \DateTimeZone(DateTime::$tzUFlist[$uf] ?? 'America/Sao_Paulo');

        // Padrões usados pelas factories, para o consumidor não precisar
        // repetir o que a subclasse de município já sabe.
        if (empty($this->config->cod_tom_municipio) && (int) $this->codcidade > 0) {
            $this->config->cod_tom_municipio = $this->codcidade;
        }
        if (!isset($this->config->teste)) {
            $this->config->teste = 0;
        }

        // Falha cedo se a URL não puder ser montada (slug ausente, template inválido).
        $this->getUrl();
    }

    // =========================================================================
    // Configuração pública
    // =========================================================================

    /**
     * Define timeout em segundos para requisições ao Atende.Net.
     */
    public function setTimeout(int $seconds): void
    {
        $this->timeout = max(5, $seconds);
    }

    /**
     * Identificador de sessão (PHPSESSID) capturado da última resposta,
     * para o consumidor persistir entre requisições/processos se quiser.
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Reaproveita um identificador de sessão obtido anteriormente.
     * Passe null para forçar a abertura de uma sessão nova.
     */
    public function setSessionId(?string $sessionId): void
    {
        $sessionId = $sessionId !== null ? trim($sessionId) : null;
        $this->sessionId = ($sessionId === '' || $sessionId === null) ? null : $sessionId;
    }

    /**
     * Retorna o registro da última requisição enviada (método, URL, headers
     * redigidos, tamanho do corpo e status HTTP). Nunca contém credenciais.
     */
    public function getLastRequest(): ?array
    {
        return $this->lastRequest;
    }

    /**
     * URL do webservice do município do prestador, montada a cada chamada
     * (não há estado acumulado entre requisições).
     */
    public function getUrl(): string
    {
        $template = trim((string) ($this->config->url_template ?? ''));
        if ($template === '') {
            $template = self::URL_TEMPLATE;
        }

        if (strpos($template, '{cidade}') === false) {
            return $template;
        }

        $slug = self::normalizeCitySlug((string) ($this->config->city_slug ?? $this->citySlug));
        if ($slug === '') {
            throw new RuntimeException(
                'Atende.Net: informe $config->city_slug com o nome do município do prestador '
                . '(sem pontuação e sem espaços), usado em ' . self::URL_TEMPLATE
            );
        }

        return str_replace('{cidade}', $slug, $template);
    }

    /**
     * Normaliza o identificador do município para o subdomínio do Atende.Net:
     * minúsculas, sem acentos, sem espaços e sem pontuação — EXCETO o hífen.
     * Ex.: "São José dos Pinhais" → "saojosedospinhais".
     *
     * O hífen é preservado porque o subdomínio real NEM SEMPRE é o nome do
     * município: Lagoa Vermelha/RS atende em `nfse-lagoavermelha.atende.net`.
     * Removê-lo (como se fazia aqui) produzia `nfselagoavermelha`, host que não
     * resolve, e o erro chegava como "Could not resolve host" — cURL 6, sem
     * nenhuma pista de que o problema era o saneamento do slug.
     *
     * Hífen no início ou no fim é descartado: rótulo DNS não pode começar nem
     * terminar com hífen, e deixá-lo passar geraria outro host inválido.
     */
    public static function normalizeCitySlug(string $city): string
    {
        $map = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'Á' => 'a', 'À' => 'a', 'Ã' => 'a', 'Â' => 'a', 'Ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'Ó' => 'o', 'Ò' => 'o', 'Õ' => 'o', 'Ô' => 'o', 'Ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
            'ç' => 'c', 'Ç' => 'c', 'ñ' => 'n', 'Ñ' => 'n',
        ];

        $slug = strtolower(strtr(trim($city), $map));
        $slug = (string) preg_replace('/[^a-z0-9-]/', '', $slug);

        // Colapsa hífens repetidos e remove os das pontas (regra de rótulo DNS).
        $slug = (string) preg_replace('/-+/', '-', $slug);

        return trim($slug, '-');
    }

    // =========================================================================
    // Operações (uma nota por XML, síncronas)
    // =========================================================================

    /**
     * Emitir nota de serviço.
     *
     * @param Rps $rps
     * @return string XML <retorno> bruto (ver {@see Response::read()})
     */
    public function gerarNota($rps): string
    {
        $class = $this->factoryClass('GerarNota');
        $this->method = 'gerarNota';

        $fact = new $class($this->certificate);
        $message = $fact->render($rps, $this->config);

        return $this->sendRequest('', $message);
    }

    /**
     * Cancelar uma nota de serviço.
     *
     * @param CancelarRps $rps
     * @return string XML <retorno> bruto (ver {@see Response::read()})
     */
    public function cancelarNota($rps): string
    {
        $class = $this->factoryClass('CancelarNota');
        $this->method = 'cancelarNota';

        $fact = new $class($this->certificate);
        $message = $fact->render($rps, $this->config);

        return $this->sendRequest('', $message);
    }

    /**
     * Solicitar o cancelamento de nota(s) de serviço ao município
     * (passa pela análise do fiscal). Pode indicar nota substituta.
     *
     * @param CancelarRps $rps
     * @return string XML <retorno> bruto (ver {@see Response::readSolicitacaoCancelamento()})
     */
    public function solicitarCancelamentoNota($rps): string
    {
        $class = $this->factoryClass('SolicitarCancelamentoNota');
        $this->method = 'solicitarCancelamentoNota';

        $fact = new $class($this->certificate);
        $message = $fact->render($rps, $this->config);

        return $this->sendRequest('', $message);
    }

    /**
     * Consultar pelo código de autenticidade da NFS-e.
     *
     * @param string $codigoAutenticidade
     * @return string XML bruto
     */
    public function consultarByCodigoAutenticidade($codigoAutenticidade): string
    {
        $class = $this->factoryClass('ConsultarCodigoAutenticidade');
        $this->method = 'consultarByCodigoAutenticidade';

        $fact = new $class($this->certificate);
        $message = $fact->render((string) $codigoAutenticidade);

        return $this->sendRequest('', $message);
    }

    /**
     * @deprecated Nome com erro de digitação; use {@see consultarByCodigoAutenticidade()}.
     *
     * @param string $codigoAutenticidade
     * @return string
     */
    public function consultarByCodigoAutentticidade($codigoAutenticidade): string
    {
        return $this->consultarByCodigoAutenticidade($codigoAutenticidade);
    }

    /**
     * Consultar pelo código TOM do município, série e número do RPS.
     *
     * @param int|string $cidade
     * @param int|string $serie
     * @param int|string $numero
     * @return string XML bruto
     */
    public function consultarByCidadeSerieNumeroRps($cidade, $serie, $numero): string
    {
        $class = $this->factoryClass('ConsultarCidadeSerieNumeroRps');
        $this->method = 'consultarByCidadeSerieNumeroRps';

        $fact = new $class($this->certificate);
        $message = $fact->render($cidade, $serie, $numero);

        return $this->sendRequest('', $message);
    }

    /**
     * Consultar pelo cadastro econômico do prestador com número e série da NFS-e.
     *
     * @param int|string $numero
     * @param int|string $serie
     * @param int|string $cadastro
     * @return string XML bruto
     */
    public function consultarByConsultarNumeroSerieCadastro($numero, $serie, $cadastro): string
    {
        $class = $this->factoryClass('ConsultarNumeroSerieCadastro');
        $this->method = 'consultarByConsultarNumeroSerieCadastro';

        $fact = new $class($this->certificate);
        $message = $fact->render($numero, $serie, $cadastro);

        return $this->sendRequest('', $message);
    }

    // =========================================================================
    // Transporte HTTP (cURL, multipart/form-data, Basic Auth, sessão)
    // =========================================================================

    /**
     * Satisfaz o contrato abstrato Common\Tools::sendRequest.
     *
     * A URL é sempre montada por município ({@see getUrl()}); um $url não vazio
     * é aceito apenas como sobrescrita explícita pontual.
     *
     * @param string $url
     * @param string $message XML a enviar
     * @return string corpo XML da resposta
     */
    protected function sendRequest($url, $message): string
    {
        return $this->httpRequest((string) $message, (string) $url);
    }

    /**
     * Envia o XML ao Atende.Net como a única parte de um POST multipart/form-data.
     *
     * A biblioteca antiga acrescentava "?eletron=1" (emissão/cancelamento) e
     * "?formato_saida=2" (consultas) à URL; nenhuma das duas consta na NTE 2.9,
     * por isso foram removidas.
     * // A CONFIRMAR: se alguma consulta do Atende.Net exigir formato_saida=2,
     * //              acrescente via $config->url_template, nunca com ".=" na URL.
     *
     * @param string $xml XML da operação
     * @param string $urlOverride URL explícita (vazio = montar pelo município)
     * @return string corpo da resposta (XML <retorno>)
     * @throws RuntimeException em erro de rede, HTTP >= 400 ou corpo que não é XML
     */
    protected function httpRequest(string $xml, string $urlOverride = ''): string
    {
        $url = $urlOverride !== '' ? $urlOverride : $this->getUrl();
        $field = $this->multipartField();
        $headers = $this->requestHeaders();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                $field => new CURLStringFile($xml, 'nfse.xml', 'text/xml'),
            ],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $result = $this->executeCurl($options);

        $httpCode = (int) $result['httpCode'];
        $body = $this->normalizeResponseEncoding((string) $result['body']);
        $rawHeaders = (string) $result['headers'];

        $this->xmlRequest = $xml;
        $this->lastRequest = [
            'method' => 'POST',
            'url' => $url,
            'headers' => $this->redactHeaders($headers),
            'multipartField' => $field,
            'bodyLength' => strlen($xml),
            'httpCode' => $httpCode,
        ];

        if ((int) $result['errno'] !== 0) {
            throw new RuntimeException(
                "Erro cURL ao comunicar com Atende.Net (POST {$url}): [{$result['errno']}] {$result['error']}"
            );
        }

        $this->captureSessionCookie($rawHeaders);

        /*
         * O 5xx com corpo que não é XML de retorno é a forma como o webservice
         * reage a conteúdo que ele não trata: estoura do lado dele e devolve
         * uma página de erro ("Server Error"), sem código nem motivo. Vale
         * dizer isso na mensagem, porque a leitura natural de um 500 é "o
         * servidor está fora" — e a investigação certa é o XML enviado.
         */
        if ($httpCode >= 500 && !$this->looksLikeRetornoXml($body)) {
            throw new RuntimeException(
                "Atende.Net retornou HTTP {$httpCode} (erro interno do webservice) em POST {$url}. "
                . 'O servidor responde assim quando o XML tem conteúdo que ele não trata, sem informar '
                . 'qual campo — confira o XML enviado. Resposta: ' . $this->excerpt($body)
            );
        }

        if ($httpCode >= 400 && !$this->looksLikeRetornoXml($body)) {
            throw new RuntimeException(
                "Atende.Net retornou HTTP {$httpCode} em POST {$url}: " . $this->excerpt($body)
            );
        }

        if ($httpCode === 0) {
            throw new RuntimeException("Atende.Net não devolveu resposta HTTP em POST {$url}.");
        }

        if (!$this->looksLikeRetornoXml($body)) {
            throw new RuntimeException(
                "Atende.Net retornou HTTP {$httpCode} em POST {$url} com conteúdo que não é o XML esperado "
                . '(provável página HTML de login ou erro do servidor): ' . $this->excerpt($body)
            );
        }

        return $body;
    }

    /**
     * Executa o cURL. Isolado para os testes substituírem por subclasse
     * (sem bater na rede). Recebe as opções prontas e devolve o resultado cru.
     *
     * @param array<int, mixed> $options opções para curl_setopt_array
     * @return array{body: string, headers: string, httpCode: int, errno: int, error: string}
     */
    protected function executeCurl(array $options): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $raw = is_string($raw) ? $raw : '';

        return [
            'body' => substr($raw, $headerSize) ?: '',
            'headers' => substr($raw, 0, $headerSize) ?: '',
            'httpCode' => $httpCode,
            'errno' => (int) $errno,
            'error' => (string) $error,
        ];
    }

    /**
     * Headers da requisição: Basic Auth, cookie de sessão (quando houver),
     * Accept, e "Expect:" vazio para não esperar o 100-continue do multipart.
     *
     * @return array<int, string>
     */
    protected function requestHeaders(): array
    {
        $headers = [
            'Authorization: Basic ' . base64_encode($this->authUsername() . ':' . $this->authPassword()),
            'Accept: text/xml, application/xml;q=0.9, */*;q=0.8',
            'Expect:',
        ];

        if ($this->sessionId !== null) {
            $headers[] = 'Cookie: ' . self::SESSION_COOKIE . '=' . $this->sessionId;
        }

        return $headers;
    }

    /**
     * Username da autenticação: CPF/CNPJ do emissor contendo apenas dígitos
     * (o webservice devolve [144] se houver outro caractere).
     */
    protected function authUsername(): string
    {
        $login = trim((string) ($this->config->login ?? ''));
        if ($login === '') {
            $login = (string) $this->remetenteCNPJCPF;
        }

        $digits = (string) preg_replace('/[.\/\-\s]/', '', $login);
        if ($digits === '' || !ctype_digit($digits)) {
            throw new RuntimeException(
                'Atende.Net: o usuário da autenticação deve ser o CPF/CNPJ do emissor contendo apenas números '
                . '(erro [144] do webservice). Informe $config->login com o CPF/CNPJ ou deixe em branco '
                . 'para usar $config->cnpj / $config->cpf.'
            );
        }

        return $digits;
    }

    protected function authPassword(): string
    {
        $password = (string) ($this->config->senha ?? '');
        if ($password === '') {
            throw new RuntimeException('Atende.Net: informe $config->senha (senha de acesso ao sistema).');
        }

        return $password;
    }

    protected function multipartField(): string
    {
        $field = trim((string) ($this->config->multipart_field ?? ''));

        return $field !== '' ? $field : self::MULTIPART_FIELD;
    }

    /**
     * Remove credenciais dos headers antes de registrá-los em $lastRequest.
     *
     * @param array<int, string> $headers
     * @return array<int, string>
     */
    protected function redactHeaders(array $headers): array
    {
        return array_map(static function (string $header): string {
            if (stripos($header, 'Authorization:') === 0) {
                return 'Authorization: Basic ***';
            }
            if (stripos($header, 'Cookie:') === 0) {
                return 'Cookie: ' . self::SESSION_COOKIE . '=***';
            }

            return $header;
        }, $headers);
    }

    /**
     * Guarda o PHPSESSID devolvido em Set-Cookie para reenviar nas próximas requisições.
     */
    protected function captureSessionCookie(string $rawHeaders): void
    {
        if ($rawHeaders === '') {
            return;
        }

        $pattern = '/^Set-Cookie:\s*' . preg_quote(self::SESSION_COOKIE, '/') . '=([^;\r\n]+)/mi';
        if (preg_match_all($pattern, $rawHeaders, $matches) && !empty($matches[1])) {
            $value = trim((string) end($matches[1]));
            if ($value !== '') {
                $this->sessionId = $value;
            }
        }
    }

    /**
     * A NTE define o retorno como XML com raiz <retorno>; a consulta (§4.7)
     * devolve "o XML da nota", cuja raiz pode ser <nfse>. Qualquer outra coisa
     * (HTML de login, erro do servidor) não é resposta válida.
     */
    protected function looksLikeRetornoXml(string $body): bool
    {
        $trimmed = ltrim((string) preg_replace('/^\xEF\xBB\xBF/', '', ltrim($body)));
        if ($trimmed === '' || $trimmed[0] !== '<') {
            return false;
        }

        return (bool) preg_match('/<(retorno|nfse)[\s>\/]/i', substr($trimmed, 0, 512));
    }

    /**
     * Converte a resposta para UTF-8, reescrevendo a declaração do prólogo.
     *
     * O webservice responde em ISO-8859-1 mesmo quando o envio é UTF-8 —
     * `<?xml version="1.0" encoding="ISO-8859-1"?>` — e a mensagem de erro tem
     * acento ("serviço", "obrigatório"). Devolver esses bytes crus contamina
     * TUDO que a aplicação faça com eles depois: gravar em coluna UTF-8 do
     * Postgres dá `SQLSTATE[22021] invalid byte sequence for encoding "UTF8"`,
     * e `json_encode()` dá "Malformed UTF-8 characters". Como o texto da falha
     * costuma virar mensagem de erro, o erro de encoding SUBSTITUI o motivo
     * real da recusa e o usuário recebe um 500 sem explicação — a recusa do
     * município, que veio perfeitamente descrita, se perde no caminho.
     *
     * Converter aqui, no transporte, é o único ponto que cobre todos os
     * consumidores: parser, persistência, auditoria e resposta HTTP.
     */
    protected function normalizeResponseEncoding(string $body): string
    {
        if ($body === '') {
            return '';
        }

        $declared = null;
        if (preg_match('/^<\?xml[^>]*\bencoding\s*=\s*["\']([^"\']+)["\']/i', ltrim($body), $m) === 1) {
            $declared = strtoupper(trim($m[1]));
        }

        $isUtf8 = (bool) preg_match('//u', $body);

        if (($declared === null || $declared === 'UTF-8' || $declared === 'UTF8') && $isUtf8) {
            return $body;
        }

        /*
         * Sem declaração e com bytes inválidos, ou declarando algo que não é
         * UTF-8: ISO-8859-1 é o que este webservice usa. `mb_convert_encoding`
         * a partir de Latin-1 nunca falha (todo byte é um caractere válido),
         * então não há caminho de erro a tratar.
         */
        $from = ($declared === null || $declared === 'UTF-8' || $declared === 'UTF8')
            ? 'ISO-8859-1'
            : $declared;

        $converted = @mb_convert_encoding($body, 'UTF-8', $from);

        if (!is_string($converted) || $converted === '' || !preg_match('//u', $converted)) {
            $converted = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
        }

        // O prólogo tem de acompanhar os bytes, ou o parser desfaz a conversão.
        return (string) preg_replace(
            '/(<\?xml[^>]*\bencoding\s*=\s*["\'])[^"\']+(["\'])/i',
            '${1}UTF-8${2}',
            $converted,
            1
        );
    }

    protected function excerpt(string $body, int $length = 500): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $body));

        return substr($flat, 0, $length);
    }

    // =========================================================================
    // Versão / factories
    // =========================================================================

    /**
     * Só existe a v100. Aceita 1 e 100 (o example antigo mandava "versao" => 1,
     * que resolveria para Factories\v1 — inexistente); qualquer outro valor falha.
     *
     * @param mixed $versao
     */
    protected function normalizeVersao($versao): int
    {
        if ($versao === null || $versao === '') {
            return self::VERSAO;
        }

        $int = (int) $versao;
        if ($int === 1 || $int === self::VERSAO) {
            return self::VERSAO;
        }

        throw new \LogicException(
            "Versão '{$versao}' não suportada pelo modelo IPM/Atende.Net; use " . self::VERSAO . ' (ou 1).'
        );
    }

    protected function factoryClass(string $name): string
    {
        $class = "NFePHP\\NFSe\\Models\\IPM\\Factories\\v{$this->versao}\\{$name}";
        if (!class_exists($class)) {
            throw new RuntimeException("Factory {$class} não existe para a versão {$this->versao} do modelo IPM.");
        }

        return $class;
    }
}
