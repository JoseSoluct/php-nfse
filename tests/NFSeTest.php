<?php

namespace NFePHP\NFSe\Tests;

use NFePHP\Common\Certificate;
use NFePHP\NFSe\NFSe;

class NFSeTest extends NFSeTestCase
{
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
}
