<?php

namespace NFePHP\NFSe\Tests\Models\IPM;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use NFePHP\NFSe\Models\IPM\CancelarRps;
use NFePHP\NFSe\Models\IPM\Factories\v100\CancelarNota;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * XML de cancelamento do modelo IPM (Atende.Net) conforme a NTE 35/2021 v2.9, Tabela 5.
 */
final class CancelarNotaTest extends TestCase
{
    private function makeConfig(array $overrides = []): stdClass
    {
        return (object) array_merge([
            'teste' => 1,
            'cod_tom_municipio' => '8055',
        ], $overrides);
    }

    private function makeRps(): CancelarRps
    {
        $rps = new CancelarRps();
        $rps->cpfCnpjPrestador('12345678000190');
        $rps->numeroNfse(10);
        $rps->serieNfse(1);
        $rps->situacao();
        $rps->observacao('Cancelamento por erro de digitação');

        return $rps;
    }

    private function load(string $xml): DOMDocument
    {
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'O XML gerado nao carrega no DOMDocument');

        return $dom;
    }

    private function value(DOMDocument $dom, string $xpath): string
    {
        $nodes = (new DOMXPath($dom))->query($xpath);
        $this->assertSame(1, $nodes->length, "Esperado exatamente um no em {$xpath}");

        return $nodes->item(0)->nodeValue;
    }

    /**
     * @return string[]
     */
    private function childNames(DOMElement $element): array
    {
        $names = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $names[] = $child->nodeName;
            }
        }

        return $names;
    }

    public function testEmiteSerieNfse(): void
    {
        $dom = $this->load((new CancelarNota())->render($this->makeRps(), $this->makeConfig()));

        $nf = (new DOMXPath($dom))->query('/nfse/nf')->item(0);
        $this->assertSame(['numero', 'serie_nfse', 'situacao', 'observacao'], $this->childNames($nf));
        $this->assertSame('10', $this->value($dom, '/nfse/nf/numero'));
        $this->assertSame('1', $this->value($dom, '/nfse/nf/serie_nfse'));
        $this->assertSame(['nfse_teste', 'nf', 'prestador'], $this->childNames($dom->documentElement));
        $this->assertSame('12345678000190', $this->value($dom, '/nfse/prestador/cpfcnpj'));
        $this->assertSame('8055', $this->value($dom, '/nfse/prestador/cidade'));
    }

    public function testSituacaoC(): void
    {
        $dom = $this->load((new CancelarNota())->render($this->makeRps(), $this->makeConfig()));

        $this->assertSame(CancelarRps::CANCELAR, $this->value($dom, '/nfse/nf/situacao'));
        $this->assertSame('C', $this->value($dom, '/nfse/nf/situacao'));
    }

    public function testSerieNfseAusenteLanca(): void
    {
        $rps = $this->makeRps();
        $rps->infSerieNfse = null;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/\[serie_nfse\]/');
        (new CancelarNota())->render($rps, $this->makeConfig());
    }

    public function testDeclaracaoDeEncodingBateComOConteudo(): void
    {
        $utf8 = (new CancelarNota())->render($this->makeRps(), $this->makeConfig());
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $utf8);
        $this->assertStringContainsString("digita\xC3\xA7\xC3\xA3o", $utf8);
        $this->assertSame(1, substr_count($utf8, '<?xml'));

        $iso = (new CancelarNota())->render($this->makeRps(), $this->makeConfig(['encoding' => 'ISO-8859-1']));
        $this->assertStringStartsWith('<?xml version="1.0" encoding="ISO-8859-1"?>', $iso);
        $this->assertStringContainsString("digita\xE7\xE3o", $iso);
        $this->assertSame('Cancelamento por erro de digitação', $this->value($this->load($iso), '/nfse/nf/observacao'));
    }

    public function testNfseTesteSoSaiEmModoTeste(): void
    {
        $dom = $this->load((new CancelarNota())->render($this->makeRps(), $this->makeConfig(['teste' => 0])));

        $this->assertSame(['nf', 'prestador'], $this->childNames($dom->documentElement));
    }
}
