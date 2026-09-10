<?php
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 'On');
require_once '../../bootstrap.php';


use NFePHP\NFSe\Models\IPM\ItensRps;
use NFePHP\NFSe\NFSe;
use NFePHP\NFSe\Models\IPM\Rps;
use NFePHP\NFSe\Models\IPM\SoapCurl;


$arr = [
    "atualizacao" => "2016-08-03 18:01:21",
    "tpAmb" => 2,
    "versao" => 1,
    "razaosocial" => "SUA RAZAO SOCIAL LTDA",
    "cnpj" => "99999999999999",
    "cpf" => "",
    "im" => "99999999",
    "ie" => "23445",
    "cmun" => "4105805", //COLOMBO
    "siglaUF" => "PR",
    "cod_tom_municipio" => "7513", //importante para uso das operacoes IPM
    "teste" => 1, #define a operacao como teste (tag <nfse_teste>)
    "trabalha_com_rps" => 1, //define se a prefeitura trabalha com rps
    "encoding" => "UTF-8", //UTF-8 (default) ou ISO-8859-1; a declaracao XML sempre reflete o encoding real do conteudo
    "login" => 'usuario@user.com.br', //usuario e senha para autenticacao
    "senha" => 'senha',
    "pathNFSeFiles" => "/dados/nfse",
    "proxyConf" => [
        "proxyIp" => "",
        "proxyPort" => "",
        "proxyUser" => "",
        "proxyPass" => ""
    ]
];

$configJson = json_encode($arr);

try {

    #permite utilizar tanto para prefeituras que exigem ou nao certificados
    #$nfse = new NFSe($configJson, Certificate::readPfx($contentpfx, 'senha'));
    $nfse = new NFSe($configJson);

    //Por ora apenas o SoapCurl funciona com IPM
    $nfse->tools->loadSoapClass(new SoapCurl());
    //caso o mode debug seja ativado serão salvos em arquivos
    //a requisicção SOAP e a resposta do webservice na pasta de
    //arquivos temporarios do SO em sub pasta denominada "soap"
    $nfse->tools->setDebugSoapMode(false);

    //Construção do RPS
    $rps = new Rps();
    $rps->cpfCnpjPrestador('99.999.999/9999-99'); //a pontuacao e removida; so os 14 digitos vao no XML
    //Tomador estrangeiro (tipo E) pode nao ter CPF/CNPJ; F e J exigem 11 ou 14 digitos
    $rps->tomador(Rps::TOMADORES, '', '78899999', 'TOMADOR TESTE', 'TESTE SA', 'teste@teste.com');
    $rps->tomadorEstrangeiro(78855, 'California', 'Estados Unidos');
    $rps->tomadorTelefone('041', '99999999', '041', '999999999');
    $rps->tomadorEndereco(
        Rps::SIM, //endereco_informado: 1/S usa o endereco do XML, 0/N nao
        'Rua 12',
        '1234',
        'casa 2',
        'Alameda dos Anjos',
        'Centro',
        'Los Angeles', //tomador estrangeiro: nome da cidade; demais: codigo TOM
        '78088408'
    );

    $rps->numero(2);
    $rps->serie(1);
    $rps->pedagio('23434');
    #$rps->serieNfse(1); //<serie_nfse>: so informar quando o municipio exigir

    $timezone = new \DateTimeZone('America/Sao_Paulo');
    $rps->dataEmissao(new \DateTime("now", $timezone));
    $rps->dataFatoGerador(new \DateTime("now", $timezone));
    $rps->valorTotal(1321.50);
    $rps->valorDesconto(5.0000);
    $rps->valorIr(0.00);
    $rps->valorInss(0.00);
    $rps->valorContribuicaoSocial(0.00);
    $rps->valorRps(0.00);
    $rps->valorPis(0.00);
    $rps->valorCofins(0.00);

    $rps->observacao('TESTE ### Valor Aproximado dos Tributos: R$ 0,17');

    $itemNfse = new ItensRps();
    //"0"/"N": tributa no local da prestacao; "1"/"S": tributa no municipio do prestador
    $itemNfse->tributaMunicipioPrestador(Rps::TRIBUTA_LOCAL_PRESTACAO);
    $itemNfse->codigoLocalPrestacaoServico(7513);
    $itemNfse->unidadeCodigo(545);
    $itemNfse->unidadeQuantidade(45.45);
    $itemNfse->unidadeValorUnitario(6.45);
    $itemNfse->codigoItemListaServico(878);
    #$itemNfse->codigoAtividade(123); //<codigo_atividade>: codigo definido pelo municipio, opcional
    $itemNfse->descritivo('Nota fiscal sobre uso de sistema');
    $itemNfse->aliquotaItemListaServico(0.5);
    $itemNfse->situacaoTributaria(0); //ver §4.9 Situacoes Tributarias
    $itemNfse->valorTributavel(78.33);
    $itemNfse->valorDeducao(0.00);
    $itemNfse->valorIssrf(0.00);

    $rps->addItens($itemNfse);

    $rps->addLinhaGenerico('TITULO DO CAMPO LIVRE', 'Conteudo do campo livre (ate 200 caracteres)');

    $rps->produtos('Produtos testes', 300.00);

    /*
    //<forma_pagamento>: tipo (1 A vista, 2 A prazo, 3 Deposito, 4 Na apresentacao,
    //5 Cartao de debito, 6 Cartao de credito, 7 Cheque, 8 PIX) + <parcelas><parcela>.
    //A soma das parcelas deve bater com <valor_tributavel> menos <valor_deducao> e
    //<valor_issrf> quando a situacao tributaria permitir — regra da aplicacao, nao da lib.
    $rps->formaPagamento(Rps::CARTAODEBITO);
    $rps->addParcela(1, 45.00, new \DateTime("2021-01-01", $timezone));
    $rps->addParcela(2, 45.00, new \DateTime("2021-02-01", $timezone));
    $rps->addParcela(3, 45.00, new \DateTime("2021-03-01", $timezone));
    $rps->addParcela(4, 45.00, new \DateTime("2021-04-01", $timezone));
    */

    //envio do RPS
    $response = $nfse->tools->gerarNota($rps);

    //apresentação do retorno
    header("Content-type: text/xml");
    echo $response;

} catch (\NFePHP\Common\Exception\SoapException $e) {
    echo $e->getMessage();
} catch (NFePHP\Common\Exception\CertificateException $e) {
    echo $e->getMessage();
} catch (Exception $e) {
    echo $e->getMessage();
}
