<?php

namespace NFePHP\NFSe\Tests\Models\IPM;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * A implementação antiga colocava login e senha na mensagem da exceção e nos
 * arquivos de debug. Estes testes garantem que nada que saia do transporte
 * (registro da requisição, exceções) carregue credenciais.
 */
final class ToolsCredentialSafetyTest extends TestCase
{
    private const PASSWORD = 'S3nh4-Sup3r-S3cr3t4!';

    private const USERNAME = '99999999000191';

    private const RETORNO_OK = '<?xml version="1.0" encoding="ISO-8859-1"?><retorno><mensagem><codigo>[00001] - Sucesso</codigo></mensagem></retorno>';

    private function makeConfig(array $overrides = []): stdClass
    {
        $config = (object) [
            'city_slug' => 'colombo',
            'cnpj' => self::USERNAME,
            'cpf' => '',
            'razaosocial' => 'EMPRESA TESTE LTDA',
            'im' => '12345',
            'siglaUF' => 'PR',
            'cod_tom_municipio' => '7513',
            'senha' => self::PASSWORD,
        ];
        foreach ($overrides as $key => $value) {
            $config->{$key} = $value;
        }

        return $config;
    }

    private function expectedBasicToken(): string
    {
        return base64_encode(self::USERNAME . ':' . self::PASSWORD);
    }

    public function testGetLastRequestRedigeAuthorization(): void
    {
        $tools = new FakeCurlTools($this->makeConfig());
        $tools->setSessionId('abc123sessao');
        $tools->queueResponse(self::RETORNO_OK);

        $tools->sendXml('<nfse/>');

        // O que realmente foi ao cURL leva o Basic Auth de verdade...
        $sent = implode("\n", $tools->lastSentHeaders());
        $this->assertStringContainsString('Authorization: Basic ' . $this->expectedBasicToken(), $sent);
        $this->assertStringContainsString('Cookie: PHPSESSID=abc123sessao', $sent);

        // ...mas o registro para auditoria sai redigido.
        $last = $tools->getLastRequest();
        $this->assertNotNull($last);
        $this->assertSame('POST', $last['method']);
        $this->assertSame(200, $last['httpCode']);
        $this->assertSame(strlen('<nfse/>'), $last['bodyLength']);
        $this->assertContains('Authorization: Basic ***', $last['headers']);
        $this->assertContains('Cookie: PHPSESSID=***', $last['headers']);

        $serialized = json_encode($last);
        $this->assertStringNotContainsString(self::PASSWORD, $serialized);
        $this->assertStringNotContainsString($this->expectedBasicToken(), $serialized);
        $this->assertStringNotContainsString('abc123sessao', $serialized);
    }

    public function testExcecaoDeHttpNaoContemSenha(): void
    {
        $tools = new FakeCurlTools($this->makeConfig());
        $tools->queueResponse(
            '<html><body>Acesso negado para ' . self::USERNAME . '</body></html>',
            401,
            "HTTP/1.1 401 Unauthorized\r\nContent-Type: text/html\r\n\r\n"
        );

        try {
            $tools->sendXml('<nfse/>');
            $this->fail('HTTP 401 com HTML deveria lançar RuntimeException');
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('401', $message);
            $this->assertStringContainsString('https://colombo.atende.net/', $message);
            $this->assertStringContainsString('Acesso negado', $message, 'o corpo da resposta precisa aparecer');
            $this->assertStringNotContainsString(self::PASSWORD, $message);
            $this->assertStringNotContainsString($this->expectedBasicToken(), $message);
            $this->assertStringNotContainsString('Authorization', $message);
        }
    }

    public function testExcecaoDeCurlNaoContemSenha(): void
    {
        $tools = new FakeCurlTools($this->makeConfig());
        $tools->queueResponse('', 0, '', 28, 'Operation timed out after 60000 milliseconds');

        try {
            $tools->sendXml('<nfse/>');
            $this->fail('erro de cURL deveria lançar RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('[28]', $e->getMessage());
            $this->assertStringContainsString('timed out', $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            $this->assertStringNotContainsString($this->expectedBasicToken(), $e->getMessage());
        }
    }

    public function testHttp200ComHtmlDeLoginViraExcecaoSemCredencial(): void
    {
        $tools = new FakeCurlTools($this->makeConfig());
        $tools->queueResponse(file_get_contents(__DIR__ . '/../../fixtures/ipm/login_page.html'), 200);

        try {
            $tools->sendXml('<nfse/>');
            $this->fail('HTML de login com HTTP 200 deveria lançar RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('não é o XML esperado', $e->getMessage());
            $this->assertStringContainsString('<!DOCTYPE html>', $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
    }

    public function testHttp4xxComXmlDeRetornoEDevolvidoParaOParser(): void
    {
        $tools = new FakeCurlTools($this->makeConfig());
        $xml132 = file_get_contents(__DIR__ . '/../../fixtures/ipm/retorno_erro_acesso_132.xml');
        $tools->queueResponse($xml132, 401);

        $body = $tools->sendXml('<nfse/>');

        $this->assertSame($xml132, $body, 'um <retorno> com mensagem de erro é resposta válida mesmo em 4xx');
        $this->assertSame(401, $tools->getLastRequest()['httpCode']);
    }

    public function testUsuarioComLetrasLancaExcecaoAntesDeEnviar(): void
    {
        $tools = new FakeCurlTools($this->makeConfig(['login' => 'usuario@user.com.br']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('[144]');

        $tools->sendXml('<nfse/>');
        $this->assertSame([], $tools->captured);
    }

    public function testUsuarioComPontuacaoDeCnpjEAceitoSoComDigitos(): void
    {
        $tools = new FakeCurlTools($this->makeConfig(['login' => '99.999.999/0001-91']));
        $tools->queueResponse(self::RETORNO_OK);

        $tools->sendXml('<nfse/>');

        $sent = implode("\n", $tools->lastSentHeaders());
        $this->assertStringContainsString('Authorization: Basic ' . $this->expectedBasicToken(), $sent);
    }

    public function testSenhaAusenteLancaExcecaoAntesDeEnviar(): void
    {
        $tools = new FakeCurlTools($this->makeConfig(['senha' => '']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('senha');

        $tools->sendXml('<nfse/>');
    }
}
