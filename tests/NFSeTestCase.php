<?php

namespace NFePHP\NFSe\Tests;

use PHPUnit\Framework\TestCase;

class NFSeTestCase extends TestCase
{
    public $fixturesPath = '';
    public $configJson = '';
    public $contentpfx = '';
    public $passwordpfx = '';

    public function __construct()
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
        $this->contentpfx = file_get_contents($this->fixturesPath . "certs/certificado_teste.pfx");
        $this->passwordpfx = "associacao";
        $this->configJson = json_encode($config);
    }
}
