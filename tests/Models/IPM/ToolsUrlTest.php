<?php

namespace NFePHP\NFSe\Tests\Models\IPM;

use NFePHP\NFSe\Models\IPM\Response;
use NFePHP\NFSe\Models\IPM\Tools;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Montagem da URL do Atende.Net por município (NTE 35/2021 v2.9, Tabela 1).
 */
final class ToolsUrlTest extends TestCase
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
            'senha' => 'senha-secreta',
            'teste' => 1,
        ];
        foreach ($overrides as $key => $value) {
            $config->{$key} = $value;
        }

        return $config;
    }

    public function testMontaUrlPeloSlugDoMunicipio(): void
    {
        $tools = new Tools($this->makeConfig());

        $this->assertSame(
            'https://colombo.atende.net/?pg=rest&service=WNERestServiceNFSe',
            $tools->getUrl()
        );
    }

    public function testSlugENormalizadoSemAcentoPontuacaoOuEspaco(): void
    {
        $tools = new Tools($this->makeConfig(['city_slug' => 'São José dos Pinhais']));

        $this->assertSame(
            'https://saojosedospinhais.atende.net/?pg=rest&service=WNERestServiceNFSe',
            $tools->getUrl()
        );
        $this->assertSame('santarosadosul', Tools::normalizeCitySlug("Santa Rosa do Sul"));
        $this->assertSame('boaesperancadoiguacu', Tools::normalizeCitySlug("Boa Esperança do Iguaçu"));
    }

    /**
     * O subdomínio NEM SEMPRE é o nome do município: Lagoa Vermelha/RS atende
     * em `nfse-lagoavermelha.atende.net`. Remover o hífen produzia
     * `nfselagoavermelha`, host que não resolve, e o erro chegava como cURL 6
     * "Could not resolve host" — sem nenhuma pista de que a causa era o
     * saneamento do slug.
     */
    public function testSlugPreservaHifenDoSubdominio(): void
    {
        $tools = new Tools($this->makeConfig(['city_slug' => 'nfse-lagoavermelha']));

        $this->assertSame(
            'https://nfse-lagoavermelha.atende.net/?pg=rest&service=WNERestServiceNFSe',
            $tools->getUrl()
        );
        $this->assertSame('nfse-lagoavermelha', Tools::normalizeCitySlug('NFSE-Lagoa Vermelha'));
    }

    /**
     * Rótulo DNS não pode começar nem terminar com hífen, nem ter hífen
     * repetido: deixar passar geraria outro host inválido, com o mesmo erro
     * opaco de resolução.
     */
    public function testSlugLimpaHifenDasPontasERepetido(): void
    {
        $this->assertSame('brusque', Tools::normalizeCitySlug('-brusque-'));
        $this->assertSame('a-b', Tools::normalizeCitySlug('a--b'));
        $this->assertSame('', Tools::normalizeCitySlug('---'));
    }

    public function testTemplateSobrescrevivel(): void
    {
        $tools = new Tools($this->makeConfig([
            'url_template' => 'https://homologacao.exemplo.test/{cidade}/rest/nfse',
        ]));

        $this->assertSame('https://homologacao.exemplo.test/colombo/rest/nfse', $tools->getUrl());

        // Template sem marcador dispensa o slug.
        $fixed = new Tools($this->makeConfig([
            'city_slug' => '',
            'url_template' => 'https://fixo.exemplo.test/?pg=rest&service=WNERestServiceNFSe',
        ]));
        $this->assertSame('https://fixo.exemplo.test/?pg=rest&service=WNERestServiceNFSe', $fixed->getUrl());
    }

    public function testSlugAusenteLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('city_slug');

        new Tools($this->makeConfig(['city_slug' => '']));
    }

    public function testSlugSoComPontuacaoTambemLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);

        new Tools($this->makeConfig(['city_slug' => '.-. ']));
    }

    public function testUrlNaoAcumulaQueryStringEntreChamadas(): void
    {
        $tools = new FakeCurlTools($this->makeConfig());
        $tools->queueResponse(self::RETORNO_OK);
        $tools->queueResponse(self::RETORNO_OK);
        $tools->queueResponse(self::RETORNO_OK);

        $expected = 'https://colombo.atende.net/?pg=rest&service=WNERestServiceNFSe';
        $xml = '<?xml version="1.0" encoding="ISO-8859-1"?><nfse><nf><numero>1</numero></nf></nfse>';

        $tools->sendXml($xml);
        $first = $tools->lastSentUrl();
        $tools->sendXml($xml);
        $second = $tools->lastSentUrl();
        $tools->sendXml($xml);
        $third = $tools->lastSentUrl();

        $this->assertSame($expected, $first);
        $this->assertSame($expected, $second);
        $this->assertSame($expected, $third);
        $this->assertSame(1, substr_count($third, '?'), 'a URL não pode acumular "?" entre chamadas');
        $this->assertSame($expected, $tools->getLastRequest()['url']);
    }

    public function testEnviaPostMultipartComUmaParteDeArquivo(): void
    {
        $tools = new FakeCurlTools($this->makeConfig());
        $tools->queueResponse(self::RETORNO_OK);

        $xml = '<?xml version="1.0" encoding="ISO-8859-1"?><nfse><nf><numero>1</numero></nf></nfse>';
        $tools->sendXml($xml);

        $fields = $tools->lastSentPostFields();
        $this->assertCount(1, $fields, 'só a parte do arquivo vai no form-data (sem login/senha/cidade)');
        $this->assertArrayHasKey('File', $fields);
        $this->assertInstanceOf(\CURLStringFile::class, $fields['File']);
        $this->assertSame($xml, $fields['File']->data);

        $options = end($tools->captured);
        $this->assertTrue($options[CURLOPT_POST]);
        $this->assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        $this->assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        $this->assertSame(60, $options[CURLOPT_TIMEOUT]);
    }

    public function testNomeDaParteMultipartEConfiguravel(): void
    {
        $tools = new FakeCurlTools($this->makeConfig(['multipart_field' => 'arquivo']));
        $tools->queueResponse(self::RETORNO_OK);

        $tools->sendXml('<nfse/>');

        $this->assertArrayHasKey('arquivo', $tools->lastSentPostFields());
        $this->assertSame('arquivo', $tools->getLastRequest()['multipartField']);
    }

    public function testSetTimeoutChegaAoCurl(): void
    {
        $tools = new FakeCurlTools($this->makeConfig());
        $tools->setTimeout(120);
        $tools->queueResponse(self::RETORNO_OK);

        $tools->sendXml('<nfse/>');

        $options = end($tools->captured);
        $this->assertSame(120, $options[CURLOPT_TIMEOUT]);
    }

    /**
     * O 500 com página de erro é a resposta do webservice a XML que ele não
     * trata. Confundir isso com "servidor fora do ar" manda a investigação
     * para o lado errado, então a mensagem tem de dizer o que significa e
     * para onde olhar.
     */
    public function testHttp500ComPaginaDeErroApontaParaOXmlEnviado(): void
    {
        $tools = new FakeCurlTools($this->makeConfig());
        $tools->queueResponse('<html><body><h1>Server Error</h1></body></html>', 500);

        try {
            $tools->sendXml('<nfse/>');
            $this->fail('esperava RuntimeException para o HTTP 500');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 500 (erro interno do webservice)', $e->getMessage());
            $this->assertStringContainsString('confira o XML enviado', $e->getMessage());
            $this->assertStringContainsString('Server Error', $e->getMessage());
        }
    }

    public function testHttp4xxNaoCulpaOXmlEnviado(): void
    {
        $tools = new FakeCurlTools($this->makeConfig());
        $tools->queueResponse('<html>Unauthorized</html>', 401);

        try {
            $tools->sendXml('<nfse/>');
            $this->fail('esperava RuntimeException para o HTTP 401');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringNotContainsString('confira o XML enviado', $e->getMessage());
        }
    }

    /**
     * XML de retorno legítimo com status 5xx segue sendo interpretado: quem
     * decide se a nota foi aceita é o `<retorno>`, não o código HTTP.
     */
    public function testRetornoXmlCom5xxAindaEEntregueAoParser(): void
    {
        $tools = new FakeCurlTools($this->makeConfig());
        $tools->queueResponse(self::RETORNO_OK, 500);

        // O prologo sai reescrito para UTF-8; o conteudo e o mesmo.
        $body = $tools->sendXml('<nfse/>');

        $this->assertStringContainsString('[00001] - Sucesso', $body);
        $this->assertStringContainsString('encoding="UTF-8"', $body);
    }

    /**
     * A recusa real do município veio assim, em ISO-8859-1 e com acento. Os
     * bytes crus contaminavam tudo a jusante: coluna UTF-8 do Postgres
     * (SQLSTATE 22021) e `json_encode()` — e o erro de encoding substituía o
     * motivo da recusa, entregando um 500 sem explicação.
     */
    public function testRespostaEmIso88591ChegaEmUtf8ComPrologoCoerente(): void
    {
        $retorno = '<?xml version="1.0" encoding="ISO-8859-1"?>'
            . '<retorno><mensagem><codigo> 00427 - Para a lista de servi'
            . chr(0xE7) . 'o informada o preenchimento do IBS/CBS '
            . chr(0xE9) . ' obrigat' . chr(0xF3) . 'rio.</codigo></mensagem></retorno>';

        $tools = new FakeCurlTools($this->makeConfig());
        $tools->queueResponse($retorno);

        $body = $tools->sendXml('<nfse/>');

        $this->assertTrue((bool) preg_match('//u', $body), 'o corpo devolvido tem de ser UTF-8 válido');
        $this->assertStringContainsString('serviço', $body);
        $this->assertStringContainsString('é obrigatório', $body);
        $this->assertStringContainsString('encoding="UTF-8"', $body);
        $this->assertStringNotContainsString('ISO-8859-1', $body);

        // Convertido, o retorno ainda tem de ser interpretável — e o acento
        // chega intacto na mensagem que o usuário vai ler.
        $response = Response::read($body);
        $this->assertFalse($response->isSuccess());
        $this->assertTrue($response->hasCode(427));
        $this->assertStringContainsString('IBS/CBS é obrigatório', $response->errorsText());
    }

    public function testRespostaJaEmUtf8NaoEMexida(): void
    {
        $retorno = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<retorno><mensagem><codigo> 00427 - Preenchimento é obrigatório.</codigo></mensagem></retorno>';

        $tools = new FakeCurlTools($this->makeConfig());
        $tools->queueResponse($retorno);

        $this->assertSame($retorno, $tools->sendXml('<nfse/>'));
    }

    /**
     * Latin-1 sem prólogo: não há declaração para corrigir, mas os bytes ainda
     * precisam virar UTF-8 antes de chegar ao banco.
     */
    public function testLatin1SemPrologoTambemEConvertido(): void
    {
        $tools = new FakeCurlTools($this->makeConfig());
        $tools->queueResponse('<retorno><mensagem><codigo> 00427 - servi' . chr(0xE7) . 'o</codigo></mensagem></retorno>');

        $body = $tools->sendXml('<nfse/>');

        $this->assertTrue((bool) preg_match('//u', $body));
        $this->assertStringContainsString('serviço', $body);
    }
}
