<?php

namespace NFePHP\NFSe\Tests\Models\Nacional;

use DateTime;
use DOMDocument;
use InvalidArgumentException;
use LogicException;
use NFePHP\Common\Certificate;
use NFePHP\NFSe\Models\Nacional\Evento;
use NFePHP\NFSe\Models\Nacional\Factories\v101\RenderEvento;
use PHPUnit\Framework\TestCase;

/**
 * Suíte dos eventos v1.01 (pedRegEvento).
 *
 * Cobre construção do VO {@see Evento}, renderização e validação contra
 * `pedRegEvento_v1.01.xsd`, assinatura SHA-256 em `infPedReg` e diferentes
 * tipos de evento disponíveis ao contribuinte.
 */
final class NacionalEventoTest extends TestCase
{
    private string $certsPath;

    private string $schemesPath;

    private string $chave;

    protected function setUp(): void
    {
        $this->certsPath = __DIR__ . '/../../fixtures/certs/';
        $this->schemesPath = __DIR__ . '/../../../schemes/Models/Nacional/v1.01/';
        $this->chave = str_repeat('9', 50);
    }

    private function makeEventoBase(): Evento
    {
        $ev = new Evento();
        $ev->tpAmb(2);
        $ev->dhEvento(new DateTime('2026-04-20T10:00:00-03:00'));
        $ev->autor(Evento::AUTOR_CNPJ, '12345678000190');
        $ev->chNFSe($this->chave);
        return $ev;
    }

    public function testIdTemFormatoPREChaveTipo(): void
    {
        $ev = $this->makeEventoBase();
        $ev->cancelamento(Evento::JUST_CANC_ERRO_EMISSAO, 'Erro no valor do servico prestado');
        $ev->gerarId();

        $this->assertSame(59, strlen($ev->infId));
        $this->assertSame('PRE' . $this->chave . Evento::TP_CANCELAMENTO, $ev->infId);
    }

    public function testGerarIdAntesDoTipoFalha(): void
    {
        $this->expectException(LogicException::class);
        $ev = $this->makeEventoBase();
        $ev->gerarId();
    }

    public function testCancelamentoExigeMotivoValido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $ev = new Evento();
        $ev->cancelamento('7', 'Motivo com mais de quinze caracteres');
    }

    public function testCancelamentoExigeXMotivoMinimo15(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $ev = new Evento();
        $ev->cancelamento(Evento::JUST_CANC_ERRO_EMISSAO, 'curto');
    }

    public function testRenderCancelamentoValidaXsd(): void
    {
        $ev = $this->makeEventoBase();
        $ev->cancelamento(Evento::JUST_CANC_ERRO_EMISSAO, 'Erro no valor do servico prestado');
        $ev->gerarId();

        $xml = RenderEvento::render($ev);
        $this->assertXsdValid($xml);
        $this->assertStringContainsString('<e101101>', $xml);
    }

    public function testRenderCancelamentoSubstituicaoValidaXsd(): void
    {
        $ev = $this->makeEventoBase();
        $ev->cancelamentoSubstituicao(
            Evento::JUST_SUBST_OUTROS,
            str_repeat('8', 50),
            'Motivo de substituicao adequadamente extenso'
        );
        $ev->gerarId();

        $xml = RenderEvento::render($ev);
        $this->assertXsdValid($xml);
        $this->assertStringContainsString('<chSubstituta>' . str_repeat('8', 50) . '</chSubstituta>', $xml);
    }

    public function testRenderConfirmacaoPrestadorValidaXsd(): void
    {
        $ev = $this->makeEventoBase();
        $ev->confirmacaoPrestador();
        $ev->gerarId();

        $xml = RenderEvento::render($ev);
        $this->assertXsdValid($xml);
        $this->assertStringContainsString('<e202201>', $xml);
    }

    public function testRenderRejeicaoTomadorValidaXsd(): void
    {
        $ev = $this->makeEventoBase();
        $ev->rejeicaoTomador(Evento::REJ_FATO_GERADOR, 'Servico nao foi efetivamente prestado');
        $ev->gerarId();

        $xml = RenderEvento::render($ev);
        $this->assertXsdValid($xml);
        $this->assertStringContainsString('<e203206>', $xml);
    }

    public function testRenderRejeicaoPrestadorSemXMotivoValidaXsd(): void
    {
        $ev = $this->makeEventoBase();
        $ev->rejeicaoPrestador(Evento::REJ_DUPLICIDADE);
        $ev->gerarId();

        $xml = RenderEvento::render($ev);
        $this->assertXsdValid($xml);
    }

    public function testAutorCpfUsaTagCpfAutor(): void
    {
        $ev = new Evento();
        $ev->tpAmb(2);
        $ev->dhEvento(new DateTime('2026-04-20T10:00:00-03:00'));
        $ev->autor(Evento::AUTOR_CPF, '12345678901');
        $ev->chNFSe($this->chave);
        $ev->confirmacaoTomador();
        $ev->gerarId();

        $xml = RenderEvento::render($ev);
        $this->assertStringContainsString('<CPFAutor>12345678901</CPFAutor>', $xml);
        $this->assertStringNotContainsString('<CNPJAutor>', $xml);
        $this->assertXsdValid($xml);
    }

    public function testAutorExigeDocumentoComTamanhoCorreto(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Evento())->autor(Evento::AUTOR_CNPJ, '123');
    }

    public function testAssinaturaSha256EmInfPedReg(): void
    {
        $cert = $this->loadTestCertificate();
        $ev = $this->makeEventoBase();
        $ev->cancelamento(Evento::JUST_CANC_ERRO_EMISSAO, 'Erro no valor do servico prestado');
        $ev->gerarId();

        $xmlSigned = RenderEvento::toXml($ev, $cert, OPENSSL_ALGO_SHA256);

        $this->assertStringContainsString('http://www.w3.org/2001/04/xmlenc#sha256', $xmlSigned);
        $this->assertStringContainsString('#' . $ev->infId, $xmlSigned);
        $this->assertXsdValid($xmlSigned);
    }

    public function testRenderSemCompletezaLanca(): void
    {
        $this->expectException(LogicException::class);
        $ev = new Evento();
        RenderEvento::render($ev);
    }

    // ========== helpers ==========

    private function assertXsdValid(string $xml): void
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadXML($xml);
        $valid = @$dom->schemaValidate($this->schemesPath . 'pedRegEvento_v1.01.xsd');

        if (!$valid) {
            $messages = [];
            foreach (libxml_get_errors() as $err) {
                $messages[] = 'L' . $err->line . ': ' . trim($err->message);
            }
            libxml_clear_errors();
            $this->fail("XML nao validou contra pedRegEvento_v1.01.xsd:\n" . implode("\n", $messages));
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
