<?php

namespace NFePHP\NFSe\Models\IPM\Factories;

use stdClass;
use InvalidArgumentException;
use NFePHP\Common\Certificate;
use NFePHP\NFSe\Common\DOMImproved as Dom;
use NFePHP\NFSe\Common\Factory as FactoryBase;

class Factory extends FactoryBase
{
    /**
     * Construtor recebe a classe de certificados
     *
     * @param \NFePHP\Common\Certificate|null $certificate
     * @param int $algorithm
     */
    public function __construct(?Certificate $certificate = null, $algorithm = OPENSSL_ALGO_SHA1)
    {
        $this->certificate = $certificate;
        $this->algorithm = $algorithm;
        $this->pathSchemes = __DIR__ . '/../../schemes';
    }

    /**
     * Lança exceção quando a montagem do DOM acumulou erros de preenchimento
     * obrigatório (ver DOMImproved::addChild()). Deve ser chamado antes de
     * assinar, para que nenhum XML incompleto seja assinado ou transmitido.
     *
     * @param Dom $dom
     * @return void
     * @throws InvalidArgumentException
     */
    protected function lancarErrosDoDom(Dom $dom)
    {
        if (!empty($dom->errors)) {
            throw new InvalidArgumentException(implode(' | ', $dom->errors));
        }
    }

    /**
     * Prefixa a declaração XML com o encoding configurado em $config->encoding
     * (default UTF-8) e converte o corpo para ele quando for ISO-8859-1.
     * O DOM é sempre montado em UTF-8, então a declaração nunca anuncia um
     * encoding diferente do conteúdo real.
     *
     * @param string $body corpo do XML, sem declaração, em UTF-8
     * @param stdClass $config
     * @return string
     * @throws InvalidArgumentException
     */
    protected function declararEncoding($body, stdClass $config)
    {
        $encoding = strtoupper(trim((string) ($config->encoding ?? 'UTF-8')));
        if ($encoding === 'ISO-8859-1') {
            $body = mb_convert_encoding($body, 'ISO-8859-1', 'UTF-8');
        } elseif ($encoding !== 'UTF-8') {
            throw new InvalidArgumentException(
                "Encoding '$encoding' não suportado; use UTF-8 ou ISO-8859-1."
            );
        }
        return '<?xml version="1.0" encoding="' . $encoding . '"?>' . trim($body);
    }
}
