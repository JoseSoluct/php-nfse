<?php

namespace NFePHP\NFSe\Models\Nacional\Factories\v101;

use InvalidArgumentException;
use NFePHP\NFSe\Common\Factory;
use NFePHP\NFSe\Models\Nacional\Rps;

/**
 * Prepara o XML de uma DPS para envio via REST (NFS-e Nacional v1.01).
 *
 * O padrão Nacional usa REST (HTTPS POST), não SOAP.
 * Cada DPS é enviada individualmente — não há envelope de lote.
 */
class RecepcionarLoteRps extends Factory
{
    protected string $schemaVersao = '1.01';

    /**
     * Renderiza e valida o XML de uma ou mais DPS.
     *
     * Quando $rpss contiver mais de um RPS, cada DPS será validada
     * individualmente; o método retorna o XML da última DPS.
     * Para enviar múltiplas DPS, chame enviarDps() em loop no Tools.
     *
     * @param Rps|Rps[] $rpss
     * @return string XML assinado pronto para envio REST
     */
    public function render(Rps|array $rpss): string
    {
        if ($rpss instanceof Rps) {
            $rpss = [$rpss];
        }

        if (empty($rpss)) {
            throw new InvalidArgumentException('Ao menos uma DPS deve ser informada.');
        }

        $xmlSigned = '';

        foreach ($rpss as $rps) {
            $xml = RenderRps::toXml($rps, $this->certificate, $this->algorithm);
            $this->validar($this->schemaVersao, $xml, 'Nacional', 'DPS', 'v', null);
            $xmlSigned = $xml;
        }

        return $xmlSigned;
    }
}
