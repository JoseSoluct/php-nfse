<?php

namespace NFePHP\NFSe\Tests;

use DateTime;
use DateTimeZone;
use Log;
use NFePHP\Common\Certificate;
use NFePHP\NFSe\Models\Tecnos\Rps;
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

    public function testConsultarNFSe()
    {
        $nfse = new NFSe(
            $this->configJson,
            Certificate::readPfx($this->contentpfx, $this->passwordpfx)
        );
        $response = $nfse->tools->consultarNfsePorRps(1, 'UNICA', 1);
        $this->assertIsString($response, 'O response não é uma string.');
        $this->assertStringContainsString('<?xml', $response, 'O response não contém XML válido.');
    }

    public function testEmiteNfse()
    {
        $nfse = new NFSe(
            $this->configJson,
            Certificate::readPfx($this->contentpfx, $this->passwordpfx)
        );

        $rps = new Rps();

        $rps->prestador($rps::CNPJ, '48704149000188', 'PRIORIZA SISTEMAS LTDA', '8963');
        $rps->tomador($rps::CPF, '11111111111', '', '', 'JOSE ALCIDES', '54996178803', 'jose@priorizasistemas.com.br');
        $rps->tomadorEndereco(
            'Av 7 De Setembro',
            '783',
            'SALA 2',
            'Centro',
            '4320909',
            'RS',
            '1058',
            '99950000'
        );

        $rps->numero(1);
        $rps->serie('UNICA');
        $rps->status($rps::STATUS_NORMAL);
        $rps->tipo($rps::TIPO_RPS);

        $timezone = new DateTimeZone('America/Sao_Paulo');
        $rps->dataEmissao(new DateTime("now", $timezone));
        $rps->municipioPrestacaoServico('4305108');
        $rps->codigoPais(1058); //999 em ambiente de produção
        $rps->naturezaOperacao($rps::NATUREZA_EXIGIVEL);
        $rps->codigoCnae(0);
        $rps->itemListaServico('01.07');
        $rps->codigoTributacaoMunicipio('4320909');
        $rps->discriminacao('TESTE ### Valor Aproximado dos Tributos: R$ 0,17');
        $rps->regimeEspecialTributacao($rps::REGIME_NENHUM);
        $rps->optanteSimplesNacional($rps::SIM);
        $rps->incentivadorCultural($rps::NAO);
        $rps->issRetido($rps::NAO);
        $rps->responsavelRetencao($rps::TOMADOR);
        $rps->aliquota(3.0000);
        $rps->baseCalculoCRS(0);
        $rps->irrfIndenizacao(0);
        $rps->valorServicos(1);
        $rps->valorDeducoes(0.00);
        $rps->outrasRetencoes(0.00);
        $rps->descontoCondicionado(0.00);
        $rps->descontoIncondicionado(0.00);
        $rps->baseCalculo(1);
        $rps->numeroProcesso('48704149000188000000001');

        $rps->valorIss(0.03);
        $rps->valorPis(0.00);
        $rps->valorCofins(0.00);
        $rps->valorCsll(0.00);
        $rps->valorInss(0.00);
        $rps->valorIr(0.00);

        //(ValorServicos - ValorPIS - ValorCOFINS - ValorINSS - ValorIR - ValorCSLL - OutrasRetençoes - ValorISSRetido - DescontoIncondicionado - DescontoCondicionado)
        $rps->valorLiquidoNfse(1);

        //$rps->construcaoCivil('1234', '234-4647-aa');

        //envio do RPS
        $response = $nfse->tools->recepcionarLoteRpsSincrono(1, [$rps]);
        echo $response;
        die;
        $this->assertIsString($response, 'O response não é uma string.');
        $this->assertStringContainsString('<?xml', $response, 'O response não contém XML válido.');
    }
}
