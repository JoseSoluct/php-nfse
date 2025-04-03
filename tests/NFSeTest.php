<?php

namespace NFePHP\NFSe\Tests;

use NFePHP\Common\Certificate;
use NFePHP\NFSe\NFSe;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NFSeTest extends TestCase
{
    public $fixturesPath = '';
    public $configJson = '';
    public $contentpfx = '';
    public $passwordpfx = '';

    protected function setUp(): void
    {
        $this->fixturesPath = dirname(__FILE__) . '/fixtures/';
        $config = [
            "atualizacao" => date("Y-m-d H:i:s"),
            "tpAmb" => 2,
            "versao" => 1,
            "razaosocial" => "PRIORIZA SISTEMAS LTDA",
            "cnpj" => "48704149000188",
            "cpf" => "",
            "im" => "8963",
            "cmun" => "4320909",
            "siglaUF" => "RS",
            "pathNFSeFiles" => "/tmp/nfse",
            "aProxyConf" => [
                "proxyIp" => "",
                "proxyPort" => "",
                "proxyUser" => "",
                "proxyPass" => ""
            ]
        ];
        $filePath = $this->fixturesPath . "certs/prioriza.pfx";
        if (!file_exists($filePath)) {
            throw new RuntimeException("Arquivo $filePath não encontrado.");
        }
        $this->contentpfx = file_get_contents($filePath);
        $this->passwordpfx = 'prioriza' ?: 'senha-padrao';
        $this->configJson = json_encode($config);
    }

    public function testInstanciarNFSE()
    {
        $nfse = new NFSe(
            $this->configJson,
            Certificate::readPfx($this->contentpfx, $this->passwordpfx)
        );
        $this->assertInstanceOf('NFePHP\NFSe\Counties\M4320909\Tools', $nfse->tools);
        $this->assertInstanceOf('NFePHP\NFSe\Counties\M4320909\Rps', $nfse->rps);
        $this->assertInstanceOf('NFePHP\NFSe\Counties\M4320909\Convert', $nfse->convert);
        $this->assertInstanceOf('NFePHP\NFSe\Counties\M4320909\Response', $nfse->response);
    }

    public function testBuscarSequenciaNFSe()
    {
        $nfse = new NFSe(
            $this->configJson,
            Certificate::readPfx($this->contentpfx, $this->passwordpfx)
        );
        $response = $nfse->tools->consultarSequenciaLoteNota();
        $this->assertIsString($response, 'O response não é uma string.');
        $this->assertStringContainsString('<?xml', $response, 'O response não contém XML válido.');
    }
}
