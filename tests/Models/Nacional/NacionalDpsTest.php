<?php

namespace NFePHP\NFSe\Tests\Models\Nacional;

use DateTime;
use DOMDocument;
use InvalidArgumentException;
use NFePHP\Common\Certificate;
use NFePHP\NFSe\Models\Nacional\Factories\v101\RecepcionarLoteRps;
use NFePHP\NFSe\Models\Nacional\Factories\v101\RenderRps;
use NFePHP\NFSe\Models\Nacional\Response;
use NFePHP\NFSe\Models\Nacional\Rps;
use PHPUnit\Framework\TestCase;

/**
 * Suíte de testes para o padrão NFS-e Nacional (ADN) v1.01.
 *
 * Cobre:
 *   - Construção da DPS e validação de setters do {@see Rps}.
 *   - Renderização do XML e validação contra `DPS_v1.01.xsd`.
 *   - Assinatura XMLDSIG SHA-256 em `infDPS`.
 *   - Parser de respostas JSON ({@see Response::parseEmissao}, parseEvento, parseErro).
 */
final class NacionalDpsTest extends TestCase
{
    private string $certsPath;

    private string $schemesPath;

    protected function setUp(): void
    {
        $this->certsPath = __DIR__ . '/../../fixtures/certs/';
        $this->schemesPath = __DIR__ . '/../../../schemes/Models/Nacional/v1.01/';
    }

    private function makeRpsBase(): Rps
    {
        $rps = new Rps();
        $rps->tpAmb(2);
        $rps->dhEmi(new DateTime('2026-04-20T10:00:00-03:00'));
        $rps->verAplic('prioriza-1.0');
        $rps->serie('1');
        $rps->nDps(100);
        $rps->dCompet('2026-04-20');
        $rps->tpEmit(1);
        $rps->cLocEmi('4314902');
        $rps->prestador(2, '12345678000190', '123456', 'Empresa Teste Ltda');
        $rps->regimeTributacao(1, 0);
        $rps->tomador(2, '98765432000101', '', 'Cliente Teste');
        $rps->localPrestacao('4314902');
        $rps->codigoServico('010601', 'Servico de teste prestado', '', '');
        $rps->vServ(100.00);
        $rps->tribISSQN(1);
        $rps->tpRetISSQN(1);
        $rps->pAliq(5.00);
        $rps->gerarId();

        return $rps;
    }

    public function testGerarIdDpsTem45Caracteres(): void
    {
        $rps = $this->makeRpsBase();
        $this->assertMatchesRegularExpression('/^DPS\d{42}$/', $rps->infId);
        $this->assertSame(45, strlen($rps->infId));
    }

    public function testSerieRemoveZerosAEsquerda(): void
    {
        $rps = new Rps();
        $rps->serie('00042');
        $this->assertSame('42', $rps->infSerie);
    }

    public function testSerieRejeitaNaoDigito(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Rps())->serie('AB');
    }

    public function testTpAmbRejeitaValorInvalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Rps())->tpAmb(3);
    }

    public function testRenderXmlValidaContraXsd(): void
    {
        $rps = $this->makeRpsBase();
        $xml = RenderRps::render($rps);

        $this->assertXsdValid($xml);
    }

    public function testRenderComBlocosOpcionaisValidaXsd(): void
    {
        $rps = $this->makeRpsBase();
        $rps->motivoEmisTI(1);
        $rps->substituicao(str_repeat('9', 50), '01', 'Motivo de substituicao adequadamente extenso');
        $rps->intermediario(2, '11111111000111', '', 'Intermediario Ltda');
        $rps->vDescIncond(5.00);
        $rps->vDescCond(2.50);
        $rps->vRetIRRF(1.50);
        $rps->vRetCSLL(0.50);
        $rps->vRetCP(1.00);
        $rps->gerarId();

        $xml = RenderRps::render($rps);
        $this->assertXsdValid($xml);
    }

    public function testRenderRootElementTemVersao101(): void
    {
        $rps = $this->makeRpsBase();
        $xml = RenderRps::render($rps);
        $this->assertStringContainsString('versao="1.01"', $xml);
    }

    public function testAssinaturaSha256EValidaXsd(): void
    {
        $cert = $this->loadTestCertificate();
        $rps = $this->makeRpsBase();

        $xmlSigned = RenderRps::toXml($rps, $cert, OPENSSL_ALGO_SHA256);

        $this->assertStringContainsString('http://www.w3.org/2001/04/xmlenc#sha256', $xmlSigned);
        $this->assertStringContainsString('http://www.w3.org/2001/04/xmldsig-more#rsa-sha256', $xmlSigned);
        $this->assertXsdValid($xmlSigned);
    }

    public function testRecepcionarLoteRpsFactoryAssinaEValidaIntegrado(): void
    {
        $cert = $this->loadTestCertificate();
        $rps = $this->makeRpsBase();

        $factory = new RecepcionarLoteRps($cert, OPENSSL_ALGO_SHA256);
        $xml = $factory->render($rps);

        $this->assertNotEmpty($xml);
        $this->assertStringContainsString('<infDPS', $xml);
        $this->assertStringContainsString('<Signature', $xml);
    }

    // ========== Response parser ==========

    public function testParseEmissaoExtraiChaveIdENumero(): void
    {
        $chave = str_repeat('9', 50);
        $idNfse = 'NFS' . $chave;
        $xmlNfse = "<NFSe><infNFSe Id=\"{$idNfse}\"><nNFSe>12345</nNFSe><cStat>100</cStat><xMotivo>Gerada com sucesso</xMotivo><dhProc>2026-04-20T10:00:00-03:00</dhProc></infNFSe></NFSe>";
        $json = json_encode([
            'nfseXmlGZipB64' => base64_encode(gzencode($xmlNfse)),
            'chaveAcesso' => $chave,
        ]);

        $result = Response::parseEmissao($json);

        $this->assertSame($chave, $result->chaveAcesso);
        $this->assertSame($idNfse, $result->idNFSe);
        $this->assertSame('12345', $result->numero);
        $this->assertSame(100, $result->cStat);
        $this->assertSame('Gerada com sucesso', $result->xMotivo);
    }

    public function testParseEmissaoDerivaChaveDoIdQuandoNaoVemNoJson(): void
    {
        $chave = str_repeat('8', 50);
        $idNfse = 'NFS' . $chave;
        $xmlNfse = "<NFSe><infNFSe Id=\"{$idNfse}\"><nNFSe>42</nNFSe></infNFSe></NFSe>";
        $json = json_encode(['nfseXmlGZipB64' => base64_encode(gzencode($xmlNfse))]);

        $result = Response::parseEmissao($json);

        $this->assertSame($chave, $result->chaveAcesso);
    }

    public function testParseEventoExtraiTipoESeq(): void
    {
        $chave = str_repeat('7', 50);
        $xmlEvento = "<evento><infEvento><chNFSe>{$chave}</chNFSe><tpEvento>110111</tpEvento><nSeqEvento>1</nSeqEvento><cStat>135</cStat><xMotivo>Evento registrado</xMotivo></infEvento></evento>";
        $json = json_encode(['eventoXmlGZipB64' => base64_encode(gzencode($xmlEvento))]);

        $result = Response::parseEvento($json);

        $this->assertSame($chave, $result->chaveAcesso);
        $this->assertSame('110111', $result->tpEvento);
        $this->assertSame(1, $result->nSeqEvento);
        $this->assertSame(135, $result->cStat);
    }

    public function testParseErroAceitaMultiplosFormatos(): void
    {
        $a = Response::parseErro(json_encode(['cStat' => 251, 'xMotivo' => 'NFS-e nao localizada']));
        $this->assertSame(251, $a->cStat);
        $this->assertSame('NFS-e nao localizada', $a->xMotivo);

        $b = Response::parseErro(json_encode(['message' => 'Unauthorized']));
        $this->assertSame('Unauthorized', $b->xMotivo);

        $c = Response::parseErro(json_encode(['mensagem' => 'Erro na validacao']));
        $this->assertSame('Erro na validacao', $c->xMotivo);
    }

    public function testUnzipBase64RoundTrip(): void
    {
        $xml = '<x>teste</x>';
        $b64 = base64_encode(gzencode($xml));
        $this->assertSame($xml, Response::unzipBase64($b64));
    }

    // ========== Helpers ==========

    private function assertXsdValid(string $xml): void
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($xml);
        $valid = @$dom->schemaValidate($this->schemesPath . 'DPS_v1.01.xsd');

        if (!$valid) {
            $messages = [];
            foreach (libxml_get_errors() as $err) {
                $messages[] = 'L' . $err->line . ': ' . trim($err->message);
            }
            libxml_clear_errors();
            $this->fail("XML nao validou contra DPS_v1.01.xsd:\n" . implode("\n", $messages));
        }
        libxml_clear_errors();
        $this->assertTrue(true);
    }

    private function loadTestCertificate(): Certificate
    {
        $pfx = $this->certsPath . 'test.pfx';
        if (!file_exists($pfx)) {
            $this->markTestSkipped('Certificado de teste nao encontrado em ' . $pfx);
        }
        return Certificate::readPfx(file_get_contents($pfx), 'test');
    }
}
