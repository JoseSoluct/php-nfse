<?php

namespace NFePHP\NFSe\Models\Tecnos\Factories\v100;

use NFePHP\NFSe\Models\Tecnos\Factories\Factory;

class RecepcionarLoteRpsSincrono extends Factory
{
    protected $xmlns;
    protected $schemeFolder;
    protected $cmun;

    /**
     * @param $xmlns
     */
    public function setXmlns($xmlns)
    {
        $this->xmlns = $xmlns;
    }

    /**
     * @param $schemeFolder
     */
    public function setSchemeFolder($schemeFolder)
    {
        $this->schemeFolder = $schemeFolder;
    }

    /**
     * @param $cmun
     */
    public function setCodMun($cmun)
    {
        $this->cmun = $cmun;
    }
}
