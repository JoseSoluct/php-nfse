<?php

namespace NFePHP\NFSe\Tests\Models\Nacional;

use NFePHP\Common\Certificate;
use NFePHP\NFSe\Models\Nacional\Response;
use NFePHP\NFSe\Models\Nacional\Tools;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Suíte dos recursos de Distribuição/DANFSe/Parâmetros municipais.
 *
 * Como os endpoints exigem mTLS contra o ADN real, a comunicação HTTP é
 * substituída por um stub em {@see ToolsStub::httpRequest} que grava os
 * parâmetros da chamada e devolve uma resposta predefinida.
 */
final class NacionalDistribuicaoTest extends TestCase
{
    private string $certsPath;

    protected function setUp(): void
    {
        $this->certsPath = __DIR__ . '/../../fixtures/certs/';
    }

    // ==================================================================
    // parseDistribuicaoDFe
    // ==================================================================

    public function testParseDistribuicaoDFeComDocumentos(): void
    {
        $xml1 = '<NFSe><infNFSe Id="NFS' . str_repeat('1', 50) . '"><nNFSe>1</nNFSe></infNFSe></NFSe>';
        $xml2 = '<NFSe><infNFSe Id="NFS' . str_repeat('2', 50) . '"><nNFSe>2</nNFSe></infNFSe></NFSe>';
        $json = json_encode([
            'ultimoNSU' => 42,
            'maxNSU' => 100,
            'documentos' => [
                ['NSU' => 41, 'chaveAcesso' => str_repeat('1', 50), 'tpDoc' => 'nfse', 'nfseXmlGZipB64' => base64_encode(gzencode($xml1))],
                ['NSU' => 42, 'chNFSe' => str_repeat('2', 50), 'nfseXmlGZipB64' => base64_encode(gzencode($xml2))],
            ],
        ]);

        $result = Response::parseDistribuicaoDFe($json);

        $this->assertSame(42, $result->ultimoNSU);
        $this->assertSame(100, $result->maxNSU);
        $this->assertCount(2, $result->documentos);
        $this->assertSame(41, $result->documentos[0]->nsu);
        $this->assertSame(str_repeat('1', 50), $result->documentos[0]->chaveAcesso);
        $this->assertSame('nfse', $result->documentos[0]->tpDoc);
        $this->assertStringContainsString('<nNFSe>1</nNFSe>', $result->documentos[0]->xml);
        $this->assertSame(str_repeat('2', 50), $result->documentos[1]->chaveAcesso);
    }

    public function testParseDistribuicaoDFeAceitaArrayDireto(): void
    {
        $xml = '<NFSe><infNFSe Id="NFS' . str_repeat('3', 50) . '"/></NFSe>';
        $json = json_encode([
            ['NSU' => 10, 'chaveAcesso' => str_repeat('3', 50), 'nfseXmlGZipB64' => base64_encode(gzencode($xml))],
        ]);

        $result = Response::parseDistribuicaoDFe($json);

        $this->assertCount(1, $result->documentos);
        $this->assertSame(10, $result->documentos[0]->nsu);
        $this->assertNull($result->ultimoNSU);
    }

    public function testParseDistribuicaoDFeVazio(): void
    {
        $result = Response::parseDistribuicaoDFe(json_encode(['ultimoNSU' => 0, 'documentos' => []]));
        $this->assertCount(0, $result->documentos);
        $this->assertSame(0, $result->ultimoNSU);
    }

    public function testParseDistribuicaoDFeSuportaEventoXml(): void
    {
        $evXml = '<evento><infEvento Id="EVT"><chNFSe>' . str_repeat('4', 50) . '</chNFSe><tpEvento>110111</tpEvento></infEvento></evento>';
        $json = json_encode([
            'documentos' => [
                ['NSU' => 55, 'chaveAcesso' => str_repeat('4', 50), 'tpDoc' => 'evento', 'eventoXmlGZipB64' => base64_encode(gzencode($evXml))],
            ],
        ]);

        $result = Response::parseDistribuicaoDFe($json);

        $this->assertSame('evento', $result->documentos[0]->tpDoc);
        $this->assertStringContainsString('<tpEvento>110111</tpEvento>', $result->documentos[0]->xml);
    }

    // ==================================================================
    // Tools: URL construction (via stub)
    // ==================================================================

    public function testBaixarDanfseConstroiUrlCorretoComAcceptPdf(): void
    {
        $tools = $this->makeToolsStub(['response' => '%PDF-fake-bytes']);
        $chave = str_repeat('5', 50);

        $pdf = $tools->baixarDanfse($chave);

        $this->assertSame('%PDF-fake-bytes', $pdf);
        $last = $tools->getLastRequest();
        $this->assertSame('GET', $last['method']);
        $this->assertSame('https://sefin.producaorestrita.nfse.gov.br/danfse/' . $chave, $last['url']);
        $this->assertContains('Accept: application/pdf', $last['headers']);
    }

    public function testDistribuicaoDFeConstroiUrlComNsu(): void
    {
        $tools = $this->makeToolsStub(['response' => '{"ultimoNSU":0,"documentos":[]}']);

        $raw = $tools->distribuicaoDFe(123);

        $this->assertStringContainsString('documentos', $raw);
        $last = $tools->getLastRequest();
        $this->assertSame('https://sefin.producaorestrita.nfse.gov.br/DFe/123', $last['url']);
        $this->assertContains('Accept: application/json', $last['headers']);
    }

    public function testDistribuicaoDFeRejeitaNsuNegativo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->makeToolsStub()->distribuicaoDFe(-1);
    }

    public function testConsultarParametrosMunicipaisExigeIbge7Digitos(): void
    {
        $this->expectException(RuntimeException::class);
        $this->makeToolsStub()->consultarParametrosMunicipais('123', 'aliquotas');
    }

    public function testConsultarParametrosMunicipaisConstroiUrl(): void
    {
        $tools = $this->makeToolsStub(['response' => '{}']);

        $tools->consultarParametrosMunicipais('4314902', 'aliquotas');

        $last = $tools->getLastRequest();
        $this->assertSame('https://sefin.producaorestrita.nfse.gov.br/parametros_municipais/4314902/aliquotas', $last['url']);
    }

    public function testEnviarDpsPostaJsonComDpsXmlGZipB64(): void
    {
        $tools = $this->makeToolsStub(['response' => '{"chaveAcesso":"' . str_repeat('9', 50) . '"}']);
        $rps = $this->makeRpsMinimo();

        $tools->enviarDps($rps);

        $last = $tools->getLastRequest();
        $this->assertSame('POST', $last['method']);
        $this->assertSame('https://sefin.producaorestrita.nfse.gov.br/nfse', $last['url']);
        $this->assertContains('Content-Type: application/json', $last['headers']);

        $decoded = json_decode((string) $last['body'], true);
        $this->assertArrayHasKey('dpsXmlGZipB64', $decoded);
        $xmlAssinado = gzdecode(base64_decode($decoded['dpsXmlGZipB64']));
        $this->assertStringContainsString('<DPS', $xmlAssinado);
        $this->assertStringContainsString('<Signature', $xmlAssinado);
    }

    public function testListarEventosEConsultarNfseSaoGet(): void
    {
        $tools = $this->makeToolsStub(['response' => '[]']);
        $chave = str_repeat('9', 50);

        $tools->consultarNfse($chave);
        $this->assertSame('GET', $tools->getLastRequest()['method']);
        $this->assertSame('https://sefin.producaorestrita.nfse.gov.br/nfse/' . $chave, $tools->getLastRequest()['url']);

        $tools->listarEventos($chave);
        $this->assertSame('GET', $tools->getLastRequest()['method']);
        $this->assertSame('https://sefin.producaorestrita.nfse.gov.br/nfse/' . $chave . '/eventos', $tools->getLastRequest()['url']);
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function makeToolsStub(array $opts = []): ToolsStub
    {
        $cert = $this->loadTestCertificate();
        $config = (object) [
            'atualizacao' => date('Y-m-d H:i:s'),
            'tpAmb' => 2,
            'versao' => 101,
            'razaosocial' => 'PRIORIZA TESTES',
            'cnpj' => '12345678000190',
            'cpf' => '',
            'im' => '123456',
            'cmun' => '4314902',
            'siglaUF' => 'RS',
            'pathNFSeFiles' => sys_get_temp_dir(),
            'aProxyConf' => ['proxyIp' => '', 'proxyPort' => '', 'proxyUser' => '', 'proxyPass' => ''],
        ];

        $tools = new ToolsStub($config, $cert);
        $tools->stubResponse = $opts['response'] ?? '{}';
        return $tools;
    }

    private function makeRpsMinimo(): \NFePHP\NFSe\Models\Nacional\Rps
    {
        $rps = new \NFePHP\NFSe\Models\Nacional\Rps();
        $rps->tpAmb(2);
        $rps->dhEmi(new \DateTime('2026-04-20T10:00:00-03:00'));
        $rps->verAplic('prioriza-1.0');
        $rps->serie('1');
        $rps->nDps(100);
        $rps->dCompet('2026-04-20');
        $rps->tpEmit(1);
        $rps->cLocEmi('4314902');
        $rps->prestador(2, '12345678000190', '123456', 'Empresa');
        $rps->regimeTributacao(1, 0);
        $rps->tomador(2, '98765432000101', '', 'Cliente');
        $rps->localPrestacao('4314902');
        $rps->codigoServico('010601', 'Servico de teste', '', '');
        $rps->vServ(100.00);
        $rps->tribISSQN(1);
        $rps->tpRetISSQN(1);
        $rps->pAliq(5.00);
        $rps->gerarId();
        return $rps;
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

/**
 * Subclasse de Tools que intercepta httpRequest para testes, evitando a
 * chamada real ao ADN. Registra os parâmetros da requisição e devolve uma
 * resposta predefinida.
 */
class ToolsStub extends Tools
{
    public string $stubResponse = '{}';

    protected function httpRequest(string $method, string $url, ?string $body = null, ?string $contentType = null, ?string $accept = null): string
    {
        $headers = ['Accept: ' . ($accept ?? 'application/json')];
        if ($body !== null && $body !== '') {
            $headers[] = 'Content-Type: ' . ($contentType ?? 'application/json');
        }
        $this->lastRequest = [
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'httpCode' => 200,
        ];
        return $this->stubResponse;
    }
}
