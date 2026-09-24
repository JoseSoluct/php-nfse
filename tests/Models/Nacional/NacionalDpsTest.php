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

    // ========== PIS/COFINS próprio e IBS/CBS ==========

    public function testPisCofinsProprioComRetencaoValidaXsd(): void
    {
        $rps = $this->makeRpsBase();
        $rps->pisCofins('01', 100.00, 0.65, 3.00, 0.65, 3.00, 8);
        $rps->vRetCSLL(1.00);
        $rps->gerarId();

        $xml = RenderRps::render($rps);

        $this->assertXsdValid($xml);
        $this->assertStringContainsString(
            '<piscofins><CST>01</CST><vBCPisCofins>100.00</vBCPisCofins><pAliqPis>0.65</pAliqPis>'
            . '<pAliqCofins>3.00</pAliqCofins><vPis>0.65</vPis><vCofins>3.00</vCofins>'
            . '<tpRetPisCofins>8</tpRetPisCofins></piscofins>',
            $xml
        );
    }

    public function testPisCofinsSoComCstValidaXsd(): void
    {
        $rps = $this->makeRpsBase();
        $rps->pisCofins('06');
        $rps->gerarId();

        $xml = RenderRps::render($rps);

        $this->assertXsdValid($xml);
        $this->assertStringContainsString('<piscofins><CST>06</CST></piscofins>', $xml);
    }

    public function testPisCofinsRejeitaCstForaDoFormato(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Rps())->pisCofins('1');
    }

    public function testPisCofinsRejeitaTipoDeRetencaoInexistente(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Rps())->pisCofins('01', tpRet: 10);
    }

    public function testGrupoIbsCbsValidaXsdComoUltimoFilhoDeInfDps(): void
    {
        $rps = $this->makeRpsBase();
        $rps->codigoServico('010601', 'Servico de teste prestado', '', '115022000');
        $rps->ibsCbs('100301', '000', '000001', '0');
        $rps->gerarId();

        $xml = RenderRps::render($rps);

        $this->assertXsdValid($xml);
        $this->assertStringContainsString(
            '</valores><IBSCBS><finNFSe>0</finNFSe><indFinal>0</indFinal><cIndOp>100301</cIndOp>'
            . '<indDest>0</indDest><valores><trib><gIBSCBS><CST>000</CST><cClassTrib>000001</cClassTrib>'
            . '</gIBSCBS></trib></valores></IBSCBS></infDPS>',
            $xml
        );
    }

    public function testGrupoIbsCbsSemIndFinalEComTpOperValidaXsd(): void
    {
        $rps = $this->makeRpsBase();
        $rps->codigoServico('010601', 'Servico de teste prestado', '', '115022000');
        $rps->ibsCbs('100301', '200', '200001', null, '1');
        $rps->gerarId();

        $xml = RenderRps::render($rps);

        $this->assertXsdValid($xml);
        $this->assertStringNotContainsString('<indFinal>', $xml);
        $this->assertStringContainsString('<cIndOp>100301</cIndOp><tpOper>1</tpOper><indDest>0</indDest>', $xml);
    }

    public function testSemGrupoIbsCbsONoNaoSai(): void
    {
        $xml = RenderRps::render($this->makeRpsBase());

        $this->assertStringNotContainsString('<IBSCBS>', $xml);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: ?string, 4: ?string}>
     */
    public static function ibsCbsInvalido(): array
    {
        return [
            'cIndOp com 5 dígitos' => ['10030', '000', '000001', null, null],
            'CST com 2 dígitos' => ['100301', '00', '000001', null, null],
            'cClassTrib com letra' => ['100301', '000', '00000A', null, null],
            'indFinal fora do domínio' => ['100301', '000', '000001', '2', null],
            'tpOper que exige NFS-e referenciada' => ['100301', '000', '000001', null, '2'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ibsCbsInvalido')]
    public function testGrupoIbsCbsRejeitaCodigoForaDoFormato(
        string $cIndOp,
        string $cst,
        string $cClassTrib,
        ?string $indFinal,
        ?string $tpOper
    ): void {
        $this->expectException(InvalidArgumentException::class);
        (new Rps())->ibsCbs($cIndOp, $cst, $cClassTrib, $indFinal, $tpOper);
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
