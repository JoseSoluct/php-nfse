<?php

namespace NFePHP\NFSe\Tests\Models\IPM;

use LogicException;
use NFePHP\NFSe\Counties\M4105805\Tools as ColomboTools;
use NFePHP\NFSe\Models\IPM\Tools;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Versão, fuso horário, sessão (PHPSESSID) e padrões da subclasse de município.
 */
final class ToolsConfigTest extends TestCase
{
    private const RETORNO_OK = '<?xml version="1.0" encoding="ISO-8859-1"?><retorno><mensagem><codigo>[00001] - Sucesso</codigo></mensagem></retorno>';

    private function makeConfig(array $overrides = []): stdClass
    {
        $config = (object) [
            'city_slug' => 'colombo',
            'cnpj' => '99999999000191',
            'cpf' => '',
            'razaosocial' => 'EMPRESA TESTE LTDA',
            'im' => '12345',
            'siglaUF' => 'PR',
            'cod_tom_municipio' => '7513',
            'senha' => 'senha',
        ];
        foreach ($overrides as $key => $value) {
            $config->{$key} = $value;
        }

        return $config;
    }

    private function versaoOf(Tools $tools): int
    {
        $ref = new \ReflectionProperty(Tools::class, 'versao');
        $ref->setAccessible(true);

        return (int) $ref->getValue($tools);
    }

    public function testVersao1ENormalizadaPara100(): void
    {
        $this->assertSame(100, $this->versaoOf(new Tools($this->makeConfig(['versao' => 1]))));
        $this->assertSame(100, $this->versaoOf(new Tools($this->makeConfig(['versao' => '1']))));
        $this->assertSame(100, $this->versaoOf(new Tools($this->makeConfig(['versao' => 100]))));
        $this->assertSame(100, $this->versaoOf(new Tools($this->makeConfig())), 'sem versao usa a única existente');
    }

    public function testVersaoDesconhecidaFalhaComMensagemClara(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Versão '2'");

        new Tools($this->makeConfig(['versao' => 2]));
    }

    public function testConstrutorNaoAlteraFusoPadraoDoProcesso(): void
    {
        $before = date_default_timezone_get();
        $target = $before === 'America/Manaus' ? 'America/Rio_Branco' : 'America/Manaus';
        $uf = $target === 'America/Manaus' ? 'AM' : 'AC';

        new Tools($this->makeConfig(['siglaUF' => $uf]));

        $this->assertSame($before, date_default_timezone_get(), 'DateTime::tzdBR() não pode ser chamado: ele faz date_default_timezone_set()');
    }

    public function testCookieDeSessaoECapturadoEReenviado(): void
    {
        $tools = new FakeCurlTools($this->makeConfig());
        $tools->queueResponse(
            self::RETORNO_OK,
            200,
            "HTTP/1.1 200 OK\r\nSet-Cookie: PHPSESSID=9iqf9s10ikml8okl8s4ok1cs73; path=/; HttpOnly\r\nContent-Type: text/xml\r\n\r\n"
        );
        $tools->queueResponse(self::RETORNO_OK, 200, "HTTP/1.1 200 OK\r\nContent-Type: text/xml\r\n\r\n");

        $this->assertNull($tools->getSessionId());

        $tools->sendXml('<nfse/>');
        $this->assertStringNotContainsString('Cookie:', implode("\n", $tools->lastSentHeaders()), 'primeira requisição ainda não tem sessão');
        $this->assertSame('9iqf9s10ikml8okl8s4ok1cs73', $tools->getSessionId());

        $tools->sendXml('<nfse/>');
        $this->assertContains('Cookie: PHPSESSID=9iqf9s10ikml8okl8s4ok1cs73', $tools->lastSentHeaders());
        $this->assertSame('9iqf9s10ikml8okl8s4ok1cs73', $tools->getSessionId(), 'sem novo Set-Cookie a sessão é mantida');
    }

    public function testSessaoPodeSerInjetadaELimpaPeloConsumidor(): void
    {
        $tools = new FakeCurlTools($this->makeConfig());
        $tools->queueResponse(self::RETORNO_OK);
        $tools->queueResponse(self::RETORNO_OK);

        $tools->setSessionId('persistida-em-cache');
        $tools->sendXml('<nfse/>');
        $this->assertContains('Cookie: PHPSESSID=persistida-em-cache', $tools->lastSentHeaders());

        $tools->setSessionId(null);
        $tools->sendXml('<nfse/>');
        $this->assertStringNotContainsString('Cookie:', implode("\n", $tools->lastSentHeaders()));
        $this->assertNull($tools->getSessionId());
    }

    public function testDesabilitaExpect100ContinueNoMultipart(): void
    {
        $tools = new FakeCurlTools($this->makeConfig());
        $tools->queueResponse(self::RETORNO_OK);

        $tools->sendXml('<nfse/>');

        $this->assertContains('Expect:', $tools->lastSentHeaders());
    }

    public function testMunicipioColomboUsaSlugECodigoTomPadrao(): void
    {
        $config = $this->makeConfig();
        unset($config->city_slug, $config->cod_tom_municipio, $config->teste);

        $tools = new ColomboTools($config);

        $this->assertSame('https://colombo.atende.net/?pg=rest&service=WNERestServiceNFSe', $tools->getUrl());
        $this->assertSame(7513, $config->cod_tom_municipio, 'codcidade da subclasse vira o padrão do TOM para as factories');
        $this->assertSame(0, $config->teste);
        $this->assertSame(100, $this->versaoOf($tools));
    }

    public function testConfigDoConsumidorTemPrecedenciaSobreOsPadroesDoMunicipio(): void
    {
        $tools = new ColomboTools($this->makeConfig(['city_slug' => 'outracidade', 'cod_tom_municipio' => '9999']));

        $this->assertSame('https://outracidade.atende.net/?pg=rest&service=WNERestServiceNFSe', $tools->getUrl());
    }

    public function testAliasDepreciadoDelegaAoNomeCorreto(): void
    {
        $this->assertTrue(method_exists(Tools::class, 'consultarByCodigoAutenticidade'));
        $this->assertTrue(method_exists(Tools::class, 'consultarByCodigoAutentticidade'));

        $doc = (new \ReflectionMethod(Tools::class, 'consultarByCodigoAutentticidade'))->getDocComment();
        $this->assertStringContainsString('@deprecated', (string) $doc);
    }
}
