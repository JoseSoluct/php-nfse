<?php

namespace NFePHP\NFSe\Counties\M4105805;

/**
 * Classe para a comunicação com os webservices da
 * Cidade de Colombo PR
 * conforme o modelo IPM (Atende.Net)
 *
 * @category  NFePHP
 * @package   NFePHP\NFSe\Counties\M4105805\Tools
 * @copyright NFePHP Copyright (c) 2016
 * @license   http://www.gnu.org/licenses/lgpl.txt LGPLv3+
 * @license   https://opensource.org/licenses/MIT MIT
 * @license   http://www.gnu.org/licenses/gpl.txt GPLv3+
 * @author    Maykon da S. de Siqueira <maykon at multilig dot com dot br>
 * @link      http://github.com/nfephp-org/sped-nfse for the canonical source repository
 */

use NFePHP\NFSe\Models\IPM\Tools as ToolsIPM;

class Tools extends ToolsIPM
{
    /**
     * County Namespace
     * @var string
     */
    protected $xmlns = '';
    /**
     * Soap Version
     * @var int
     */
    protected $soapversion = SOAP_1_1;
    /**
     * SIAFI/TOM County Cod — usado como padrão de $config->cod_tom_municipio
     * quando a configuração não o informa.
     * @var int
     */
    protected $codcidade = 7513;
    /**
     * Slug do município na URL do Atende.Net
     * (https://colombo.atende.net/?pg=rest&service=WNERestServiceNFSe).
     * $config->city_slug, quando informado, tem precedência.
     * // A CONFIRMAR: subdomínio exato de Colombo/PR no Atende.Net.
     * @var string
     */
    protected $citySlug = 'colombo';
    /**
     * Indicates when use CDATA string on message
     * @var boolean
     */
    protected $withcdata = true;
    /**
     * Encription signature algorithm
     * @var string
     */
    protected $algorithm = OPENSSL_ALGO_SHA1;
    /**
     * Version of schemas
     * @var int
     */
    protected $versao = 100;
    /**
     * namespaces for soap envelope
     * @var array
     */
    protected $namespaces = [];
}
