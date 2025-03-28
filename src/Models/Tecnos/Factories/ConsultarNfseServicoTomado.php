<?php

namespace NFePHP\NFSe\Models\Tecnos\Factories;

use NFePHP\NFSe\Models\Tecnos\NfseServicoTomado;

abstract class ConsultarNfseServicoTomado extends Factory
{
    protected $xmlns;
    protected $schemeFolder;
    protected $cmun;

    /**
     * Método usado para gerar o XML do Soap Request
     * @param $versao
     * @return mixed
     */
    abstract public function render(
        $versao,
        NfseServicoTomado $nsTomado
    );

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
