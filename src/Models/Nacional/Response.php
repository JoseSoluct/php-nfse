<?php

namespace NFePHP\NFSe\Models\Nacional;

use DOMDocument;
use RuntimeException;
use stdClass;

/**
 * Parser das respostas JSON do Ambiente de Dados Nacional (ADN) da NFS-e.
 *
 * O ADN devolve respostas em JSON. Documentos fiscais (NFS-e, Eventos) vêm
 * compactados via GZip e codificados em Base64 no campo correspondente
 * (`nfseXmlGZipB64`, `eventoXmlGZipB64`, `dpsXmlGZipB64`).
 *
 * Esta classe concentra a lógica de:
 *   1. Decodificar Base64 + descompactar GZip.
 *   2. Extrair informações-chave (chave de acesso 50, número, status, XML cru).
 *   3. Padronizar o erro retornado pela RFB em um objeto previsível.
 */
class Response
{
    /**
     * Parseia a resposta de emissão de NFS-e (POST /nfse).
     *
     * Aceita os dois esquemas do Sefin Nacional:
     *  - Sucesso (HTTP 201, NFSePostResponseSucesso):
     *      {tipoAmbiente, versaoAplicativo, dataHoraProcessamento, idDps,
     *       chaveAcesso, nfseXmlGZipB64, alertas?}
     *  - Erro (HTTP 4xx, NFSePostResponseErro):
     *      {tipoAmbiente, versaoAplicativo, dataHoraProcessamento, idDPS?,
     *       erros: [{codigo, descricao, complemento}]}
     *
     * Retorna stdClass com:
     *   - chaveAcesso          (string 50)
     *   - idNFSe               (string 53)
     *   - numero               (string)   — nNFSe extraído do XML
     *   - cStat                (int)      — 100 quando NFS-e gerada; código do primeiro erro em rejeição
     *   - xMotivo              (string)   — descrição concatenada dos erros em rejeição
     *   - dhProc               (string)
     *   - nfseXmlAssinado      (string)   — XML da NFS-e descompactado
     *   - erros                (array)    — lista de {codigo, descricao, complemento} em rejeição
     *   - raw                  (stdClass)
     */
    public static function parseEmissao(string $json): stdClass
    {
        $data = self::decodeJson($json);

        $xml = '';
        if (!empty($data->nfseXmlGZipB64)) {
            $xml = self::unzipBase64((string) $data->nfseXmlGZipB64);
        }

        $result = new stdClass();
        $result->raw = $data;
        $result->nfseXmlAssinado = $xml;
        $result->chaveAcesso = (string) ($data->chaveAcesso ?? '');
        $result->idNFSe = '';
        $result->numero = '';
        $result->cStat = isset($data->cStat) ? (int) $data->cStat : null;
        $result->xMotivo = (string) ($data->xMotivo ?? '');
        $result->dhProc = (string) ($data->dhProc ?? $data->dataHoraProcessamento ?? '');
        $result->erros = [];

        if ($xml !== '') {
            self::enrichFromNfseXml($xml, $result);
        }

        if ($result->chaveAcesso === '' && $result->idNFSe !== '') {
            $result->chaveAcesso = self::chaveFromIdNFSe($result->idNFSe);
        }

        // Sucesso: quando chegou NFS-e assinada, marca cStat=100 se ainda não definido.
        if ($result->chaveAcesso !== '' && $xml !== '' && $result->cStat === null) {
            $result->cStat = 100;
        }

        // Erro (NFSePostResponseErro): propaga lista de mensagens para cStat/xMotivo.
        if (!empty($data->erros) && is_array($data->erros)) {
            foreach ($data->erros as $err) {
                if (!is_object($err)) {
                    continue;
                }
                $result->erros[] = (object) [
                    'codigo' => (string) ($err->codigo ?? ''),
                    'descricao' => (string) ($err->descricao ?? ''),
                    'complemento' => (string) ($err->complemento ?? ''),
                ];
            }

            if ($result->cStat === null && isset($result->erros[0]->codigo) && $result->erros[0]->codigo !== '') {
                $result->cStat = (int) $result->erros[0]->codigo;
            }
            if ($result->xMotivo === '') {
                $result->xMotivo = implode(' | ', array_map(
                    static function (stdClass $e): string {
                        $msg = trim($e->descricao);
                        if ($e->codigo !== '') {
                            $msg = "[{$e->codigo}] {$msg}";
                        }
                        if ($e->complemento !== '') {
                            $msg .= ' — ' . $e->complemento;
                        }
                        return $msg;
                    },
                    $result->erros
                ));
            }
        }

        return $result;
    }

    /**
     * Parseia a resposta de registro de evento (POST /nfse/{chave}/eventos).
     *
     * Retorna stdClass com:
     *   - chaveAcesso          (string 50)
     *   - tpEvento             (string)   — ex: 110111 (cancelamento)
     *   - nSeqEvento           (int)      — número sequencial
     *   - cStat                (int)      — status
     *   - xMotivo              (string)
     *   - dhProc               (string)
     *   - eventoXmlAssinado    (string)   — XML do evento descompactado
     *   - raw                  (stdClass)
     */
    public static function parseEvento(string $json): stdClass
    {
        $data = self::decodeJson($json);

        $xml = '';
        if (!empty($data->eventoXmlGZipB64)) {
            $xml = self::unzipBase64((string) $data->eventoXmlGZipB64);
        }

        $result = new stdClass();
        $result->raw = $data;
        $result->eventoXmlAssinado = $xml;
        $result->chaveAcesso = (string) ($data->chaveAcesso ?? $data->chNFSe ?? '');
        $result->tpEvento = (string) ($data->tpEvento ?? '');
        $result->nSeqEvento = isset($data->nSeqEvento) ? (int) $data->nSeqEvento : null;
        $result->cStat = isset($data->cStat) ? (int) $data->cStat : null;
        $result->xMotivo = (string) ($data->xMotivo ?? '');
        $result->dhProc = (string) ($data->dhProc ?? '');

        if ($xml !== '') {
            self::enrichFromEventoXml($xml, $result);
        }

        return $result;
    }

    /**
     * Parseia a resposta de distribuição DFe (GET /DFe/{NSU}).
     *
     * Espera-se um JSON com a lista de documentos disponíveis para a empresa tomadora.
     * Aceita dois formatos comuns do ADN: `{documentos: [...]}` ou array direto `[...]`.
     * Cada item pode conter `NSU`, `chaveAcesso` (ou `chNFSe`), `nfseXmlGZipB64` e
     * `tpDoc`/`tipoDocumento` identificando o tipo do documento.
     *
     * Retorna stdClass com:
     *   - ultimoNSU (int|null)            — último NSU processado, quando fornecido pelo ADN
     *   - maxNSU    (int|null)            — maior NSU disponível no servidor
     *   - documentos (array<stdClass>)    — cada item: {nsu, chaveAcesso, tpDoc, xml, raw}
     *   - raw        (stdClass)
     */
    public static function parseDistribuicaoDFe(string $json): stdClass
    {
        $data = self::decodeJsonLoose($json);

        $result = new stdClass();
        $result->raw = $data;
        $result->ultimoNSU = null;
        $result->maxNSU = null;
        $result->documentos = [];

        if (is_object($data)) {
            $result->ultimoNSU = isset($data->ultimoNSU) ? (int) $data->ultimoNSU : (isset($data->ultNSU) ? (int) $data->ultNSU : null);
            $result->maxNSU = isset($data->maxNSU) ? (int) $data->maxNSU : null;
            $lista = $data->documentos ?? $data->loteDistDFeInt ?? $data->lote ?? [];
        } elseif (is_array($data)) {
            $lista = $data;
        } else {
            return $result;
        }

        foreach ((array) $lista as $item) {
            if (!is_object($item)) {
                continue;
            }
            $doc = new stdClass();
            $doc->raw = $item;
            $doc->nsu = isset($item->NSU) ? (int) $item->NSU : (isset($item->nsu) ? (int) $item->nsu : null);
            $doc->chaveAcesso = (string) ($item->chaveAcesso ?? $item->chNFSe ?? $item->chave ?? '');
            $doc->tpDoc = (string) ($item->tpDoc ?? $item->tipoDocumento ?? '');
            $doc->xml = '';

            if (!empty($item->nfseXmlGZipB64)) {
                $doc->xml = self::unzipBase64((string) $item->nfseXmlGZipB64);
            } elseif (!empty($item->eventoXmlGZipB64)) {
                $doc->xml = self::unzipBase64((string) $item->eventoXmlGZipB64);
            } elseif (!empty($item->xmlGZipB64)) {
                $doc->xml = self::unzipBase64((string) $item->xmlGZipB64);
            }

            $result->documentos[] = $doc;
        }

        return $result;
    }

    /**
     * Parseia o corpo de um erro retornado pelo ADN.
     * Aceita formatos: {cStat, xMotivo}, {erro, mensagem}, {message}, etc.
     */
    public static function parseErro(string $json): stdClass
    {
        $data = self::decodeJson($json, false);

        $result = new stdClass();
        $result->cStat = null;
        $result->xMotivo = '';

        if (!is_object($data)) {
            $result->xMotivo = trim((string) $json);
            return $result;
        }

        if (isset($data->cStat)) {
            $result->cStat = (int) $data->cStat;
        }
        if (isset($data->xMotivo)) {
            $result->xMotivo = (string) $data->xMotivo;
        } elseif (isset($data->mensagem)) {
            $result->xMotivo = (string) $data->mensagem;
        } elseif (isset($data->message)) {
            $result->xMotivo = (string) $data->message;
        } elseif (isset($data->erro)) {
            $result->xMotivo = (string) (is_scalar($data->erro) ? $data->erro : json_encode($data->erro));
        }

        $result->raw = $data;
        return $result;
    }

    /**
     * Descompacta e decodifica o conteúdo (Base64 → GZip → string).
     */
    public static function unzipBase64(string $base64): string
    {
        $binary = base64_decode($base64, true);
        if ($binary === false) {
            throw new RuntimeException('Falha ao decodificar Base64 da resposta ADN.');
        }

        $xml = @gzdecode($binary);
        if ($xml === false) {
            throw new RuntimeException('Falha ao descompactar GZip da resposta ADN.');
        }

        return $xml;
    }

    // =========================================================================
    // Helpers internos
    // =========================================================================

    private static function decodeJson(string $json, bool $requireObject = true): stdClass
    {
        $decoded = json_decode(trim($json));

        if ($decoded === null && strtolower(trim($json)) !== 'null') {
            throw new RuntimeException('Resposta ADN não é um JSON válido: ' . substr($json, 0, 300));
        }

        if ($requireObject && !is_object($decoded)) {
            throw new RuntimeException('Resposta ADN não é um objeto JSON.');
        }

        return is_object($decoded) ? $decoded : new stdClass();
    }

    /**
     * Variante de decodeJson que aceita objeto ou array (usado em respostas de lista).
     *
     * @return \stdClass|array<int, mixed>
     */
    private static function decodeJsonLoose(string $json): object|array
    {
        $decoded = json_decode(trim($json));

        if ($decoded === null && strtolower(trim($json)) !== 'null') {
            throw new RuntimeException('Resposta ADN não é um JSON válido: ' . substr($json, 0, 300));
        }

        if (!is_object($decoded) && !is_array($decoded)) {
            return new stdClass();
        }

        return $decoded;
    }

    /**
     * Extrai campos do XML da NFS-e: Id (53), nNFSe, cStat, dhProc.
     */
    private static function enrichFromNfseXml(string $xml, stdClass $result): void
    {
        $doc = self::loadXml($xml);
        if ($doc === null) {
            return;
        }

        $infNFSe = $doc->getElementsByTagName('infNFSe')->item(0);
        if ($infNFSe !== null) {
            $id = $infNFSe->getAttribute('Id');
            if ($id !== '') {
                $result->idNFSe = $id;
            }
        }

        $map = ['nNFSe' => 'numero', 'cStat' => 'cStat', 'dhProc' => 'dhProc', 'xMotivo' => 'xMotivo'];
        foreach ($map as $tag => $prop) {
            $value = self::firstTag($doc, $tag);
            if ($value === null) {
                continue;
            }
            if ($result->{$prop} === '' || $result->{$prop} === null) {
                $result->{$prop} = ($prop === 'cStat') ? (int) $value : $value;
            }
        }
    }

    /**
     * Extrai campos do XML do evento: chNFSe, tpEvento, nSeqEvento, cStat.
     */
    private static function enrichFromEventoXml(string $xml, stdClass $result): void
    {
        $doc = self::loadXml($xml);
        if ($doc === null) {
            return;
        }

        $map = [
            'chNFSe' => 'chaveAcesso',
            'tpEvento' => 'tpEvento',
            'nSeqEvento' => 'nSeqEvento',
            'cStat' => 'cStat',
            'xMotivo' => 'xMotivo',
            'dhProc' => 'dhProc',
        ];
        foreach ($map as $tag => $prop) {
            $value = self::firstTag($doc, $tag);
            if ($value === null) {
                continue;
            }
            if ($result->{$prop} === '' || $result->{$prop} === null) {
                $result->{$prop} = in_array($prop, ['cStat', 'nSeqEvento'], true) ? (int) $value : $value;
            }
        }
    }

    private static function loadXml(string $xml): ?DOMDocument
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok ? $doc : null;
    }

    private static function firstTag(DOMDocument $doc, string $tag): ?string
    {
        $node = $doc->getElementsByTagName($tag)->item(0);
        return $node ? trim($node->textContent) : null;
    }

    /**
     * Deriva a chave de acesso (50 dígitos) do Id da NFSe (53 chars: "NFS" + 50 dígitos).
     */
    private static function chaveFromIdNFSe(string $idNfse): string
    {
        if (preg_match('/^NFS(\d{50})$/', $idNfse, $m)) {
            return $m[1];
        }
        return '';
    }
}
