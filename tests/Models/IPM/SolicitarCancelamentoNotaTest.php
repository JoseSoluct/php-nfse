<?php

namespace NFePHP\NFSe\Tests\Models\IPM;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use NFePHP\Common\Certificate;
use NFePHP\NFSe\Models\IPM\CancelarRps;
use NFePHP\NFSe\Models\IPM\Factories\v100\SolicitarCancelamentoNota;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * XML de solicitação de cancelamento do modelo IPM (Atende.Net) conforme a NTE 35/2021 v2.9, Tabela 6.
 */
final class SolicitarCancelamentoNotaTest extends TestCase
{
    private function makeConfig(array $overrides = []): stdClass
    {
        return (object) array_merge([
            'teste' => 1,
            'cod_tom_municipio' => '8055',
        ], $overrides);
    }

    private function makeDocumento(int $numero): CancelarRps
    {
        $doc = new CancelarRps();
        $doc->numeroNfse($numero);
        $doc->serieNfse(1);
        $doc->observacao('Motivo da solicitacao de cancelamento');

        return $doc;
    }

    private function makeRps(int $quantidade = 1): CancelarRps
    {
        $rps = new CancelarRps();
        $rps->cpfCnpjPrestador('12345678000190');
        for ($i = 1; $i <= $quantidade; $i++) {
            $rps->addDocumentos($this->makeDocumento($i));
        }

        return $rps;
    }

    private function load(string $xml): DOMDocument
    {
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'O XML gerado nao carrega no DOMDocument');

        return $dom;
    }

    private function countNodes(DOMDocument $dom, string $xpath): int
    {
        return (new DOMXPath($dom))->query($xpath)->length;
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

    public function testNaoEmiteNfseTeste(): void
    {
        $dom = $this->load((new SolicitarCancelamentoNota())->render($this->makeRps(), $this->makeConfig(['teste' => 1])));

        $this->assertSame('solicitacao_cancelamento', $dom->documentElement->nodeName);
        $this->assertSame(0, $this->countNodes($dom, '//nfse_teste'));
        $this->assertSame(['prestador', 'documentos'], $this->childNames($dom->documentElement));
        $this->assertSame(['numero', 'serie', 'observacao'], $this->childNames((new DOMXPath($dom))->query('//documentos/nfse[1]')->item(0)));
    }

    public function testAceitaAte25Documentos(): void
    {
        $dom = $this->load((new SolicitarCancelamentoNota())->render($this->makeRps(25), $this->makeConfig()));

        $this->assertSame(25, $this->countNodes($dom, '/solicitacao_cancelamento/documentos/nfse'));
        $this->assertSame('25', $this->value($dom, '/solicitacao_cancelamento/documentos/nfse[25]/numero'));
    }

    public function testRejeitaMaisDe25Documentos(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SolicitarCancelamentoNota())->render($this->makeRps(26), $this->makeConfig());
    }

    public function testRejeitaNenhumDocumento(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SolicitarCancelamentoNota())->render($this->makeRps(0), $this->makeConfig());
    }

    public function testGrupoSubstitutaSoSaiQuandoInformado(): void
    {
        $semSubstituta = $this->load((new SolicitarCancelamentoNota())->render($this->makeRps(), $this->makeConfig()));
        $this->assertSame(0, $this->countNodes($semSubstituta, '//substituta'));

        $rps = $this->makeRps();
        $rps->infDocumentos[0]->infNumeroNfseSubstituta(3);
        $rps->infDocumentos[0]->serieNfseSubstituta(1);
        $dom = $this->load((new SolicitarCancelamentoNota())->render($rps, $this->makeConfig()));

        $this->assertSame(1, $this->countNodes($dom, '//documentos/nfse/substituta'));
        $this->assertSame('3', $this->value($dom, '//documentos/nfse/substituta/numero'));
        $this->assertSame('1', $this->value($dom, '//documentos/nfse/substituta/serie'));
        $this->assertSame(['numero', 'serie', 'observacao', 'substituta'], $this->childNames((new DOMXPath($dom))->query('//documentos/nfse[1]')->item(0)));
    }

    public function testCampoObrigatorioVazioLanca(): void
    {
        $rps = $this->makeRps();
        $rps->infDocumentos[0]->infObservacao = null;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/\[observacao\]/');
        (new SolicitarCancelamentoNota())->render($rps, $this->makeConfig());
    }

    public function testDeclaracaoDeEncodingBateComOConteudo(): void
    {
        $rps = $this->makeRps();
        $rps->infDocumentos[0]->observacao('Solicitação de cancelamento');

        $iso = (new SolicitarCancelamentoNota())->render($rps, $this->makeConfig(['encoding' => 'ISO-8859-1']));
        $this->assertStringStartsWith('<?xml version="1.0" encoding="ISO-8859-1"?>', $iso);
        $this->assertStringContainsString("Solicita\xE7\xE3o", $iso);
        $this->assertSame(1, substr_count($iso, '<?xml'));
        $this->assertSame('Solicitação de cancelamento', $this->value($this->load($iso), '//documentos/nfse/observacao'));

        $utf8 = (new SolicitarCancelamentoNota())->render($rps, $this->makeConfig());
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $utf8);
        $this->assertStringContainsString("Solicita\xC3\xA7\xC3\xA3o", $utf8);
    }

    public function testNaoAssinaMesmoComCertificado(): void
    {
        $pfx = __DIR__ . '/../../fixtures/certs/test.pfx';
        if (!file_exists($pfx)) {
            $this->markTestSkipped('Certificado de teste nao encontrado em ' . $pfx);
        }
        $cert = Certificate::readPfx(file_get_contents($pfx), 'test');

        $dom = $this->load((new SolicitarCancelamentoNota($cert))->render($this->makeRps(), $this->makeConfig()));

        $this->assertSame(0, $dom->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->length);
        $this->assertFalse($dom->documentElement->hasAttribute('id'));
    }
}
