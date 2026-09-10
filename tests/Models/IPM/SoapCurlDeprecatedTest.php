<?php

namespace NFePHP\NFSe\Tests\Models\IPM;

use NFePHP\NFSe\Models\IPM\SoapCurl;
use PHPUnit\Framework\TestCase;

/**
 * A classe está @deprecated (o Atende.Net não usa mais form-data com
 * login/senha), mas continua publicada. Estes testes cobrem só os dois
 * consertos feitos nela: não gravar credencial e não estourar sem certificado.
 */
final class SoapCurlDeprecatedTest extends TestCase
{
    public function testEstaMarcadaComoDepreciada(): void
    {
        $doc = (string) (new \ReflectionClass(SoapCurl::class))->getDocComment();

        $this->assertStringContainsString('@deprecated', $doc);
        $this->assertStringContainsString('Models\IPM\Tools', $doc);
    }

    public function testSaveDebugFilesSemCertificadoNaoEstoura(): void
    {
        $soap = new SoapCurl();
        $soap->setDebugMode(true);

        // SoapBase::saveDebugFiles() faria $this->certificate->getCnpj() em null (fatal).
        $soap->saveDebugFiles('gerarNota', '{"login":"x"}', '<retorno/>');

        $this->addToAssertionCount(1);
    }

    public function testParametrosDeCredencialSaemRedigidosDoRegistro(): void
    {
        $method = new \ReflectionMethod(SoapCurl::class, 'redactParameters');
        $method->setAccessible(true);

        $redacted = $method->invoke(null, [
            'login' => '99999999000191',
            'senha' => 'S3nh4-Sup3r-S3cr3t4!',
            'cidade' => '7513',
            'f1' => new \CURLStringFile('<nfse/>', 'nfse.xml', 'text/xml'),
        ]);

        $this->assertSame('99999999000191', $redacted['login']);
        $this->assertSame('***', $redacted['senha']);
        $this->assertSame('7513', $redacted['cidade']);
        $this->assertSame('[arquivo]', $redacted['f1']);
        $this->assertStringNotContainsString('S3nh4', json_encode($redacted));
    }
}
