<?php

namespace NFePHP\NFSe\Tests\Models\IPM;

use DateTime;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use InvalidArgumentException;
use NFePHP\Common\Certificate;
use NFePHP\NFSe\Models\IPM\Factories\v100\GerarNota;
use NFePHP\NFSe\Models\IPM\ItensRps;
use NFePHP\NFSe\Models\IPM\Rps;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * XML de emissão do modelo IPM (Atende.Net) conforme a NTE 35/2021 v2.9, Tabela 4.
 *
 * As asserções são feitas sobre o DOM do XML gerado, não sobre substrings.
 */
final class GerarNotaTest extends TestCase
{
    private const NS_DSIG = 'http://www.w3.org/2000/09/xmldsig#';

    private function makeConfig(array $overrides = []): stdClass
    {
        return (object) array_merge([
            'teste' => 1,
            'trabalha_com_rps' => 1,
            'cod_tom_municipio' => '8055',
        ], $overrides);
    }

    private function makeItem(): ItensRps
    {
        $item = new ItensRps();
        $item->tributaMunicipioPrestador(Rps::TRIBUTA_MUNICIPIO_PRESTADOR);
        $item->codigoLocalPrestacaoServico(8055);
        $item->unidadeCodigo('1');
        $item->unidadeQuantidade(45.45);
        $item->unidadeValorUnitario(6.45);
        $item->codigoItemListaServico(1401);
        $item->descritivo('Manutencao de sistema');
        $item->aliquotaItemListaServico(2.5);
        $item->situacaoTributaria('0');
        $item->valorTributavel(1321.50);
        $item->valorDeducao(0);
        $item->valorIssrf(0);

        return $item;
    }

    private function makeRps(): Rps
    {
        $rps = new Rps();
        $rps->cpfCnpjPrestador('12345678000190');
        $rps->tomador(Rps::TOMADORPJ, '98765432000101', '', 'Cliente Teste Ltda', 'Cliente', 'cliente@teste.com');
        $rps->tomadorEndereco(Rps::SIM, 'Rua Um', '10', '', '', 'Centro', 8055, '88350000');
        $rps->numero(2);
        $rps->serie(1);
        $data = new DateTime('2026-04-20 10:00:00');
        $rps->dataEmissao($data);
        $rps->dataFatoGerador($data);
        $rps->valorTotal(1321.50);
        $rps->addItens($this->makeItem());

        return $rps;
    }

    private function render(Rps $rps, ?stdClass $config = null, ?Certificate $cert = null): string
    {
        return (new GerarNota($cert))->render($rps, $config ?? $this->makeConfig());
    }

    private function load(string $xml): DOMDocument
    {
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'O XML gerado nao carrega no DOMDocument');

        return $dom;
    }

    private function query(DOMDocument $dom, string $xpath): DOMNodeList
    {
        return (new DOMXPath($dom))->query($xpath);
    }

    private function value(DOMDocument $dom, string $xpath): string
    {
        $nodes = $this->query($dom, $xpath);
        $this->assertSame(1, $nodes->length, "Esperado exatamente um no em {$xpath}");

        return $nodes->item(0)->nodeValue;
    }

    /**
     * @return string[]
     */
    private function childNames(DOMElement $element): array
    {
        $names = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $names[] = $child->nodeName;
            }
        }

        return $names;
    }

    private function loadTestCertificate(): Certificate
    {
        $pfx = __DIR__ . '/../../fixtures/certs/test.pfx';
        if (!file_exists($pfx)) {
            $this->markTestSkipped('Certificado de teste nao encontrado em ' . $pfx);
        }

        return Certificate::readPfx(file_get_contents($pfx), 'test');
    }

    public function testItensUsamAgrupadorLista(): void
    {
        $rps = $this->makeRps();
        $rps->addItens($this->makeItem());

        $dom = $this->load($this->render($rps));

        $this->assertSame(2, $this->query($dom, '/nfse/itens/lista')->length);
        $this->assertSame(0, $this->query($dom, '//itens/itens')->length);
        $this->assertSame('1', $this->value($dom, '/nfse/itens/lista[1]/tributa_municipio_prestador'));
    }

    public function testValorIssrfVemDoCampoCorreto(): void
    {
        $rps = new Rps();
        $rps->cpfCnpjPrestador('12345678000190');
        $rps->tomador(Rps::TOMADORPF, '12345678909', '', 'Fulano', '', '');
        $rps->tomadorEndereco(Rps::NAO, '', '', '', '', '', 8055, '');
        $rps->dataFatoGerador(new DateTime('2026-04-20'));
        $rps->valorTotal(100);
        $item = $this->makeItem();
        $item->valorDeducao(10);
        $item->valorIssrf(3.5);
        $rps->addItens($item);

        $dom = $this->load($this->render($rps, $this->makeConfig(['trabalha_com_rps' => 0])));

        $this->assertSame('10,00', $this->value($dom, '//lista/valor_deducao'));
        $this->assertSame('3,50', $this->value($dom, '//lista/valor_issrf'));
    }

    public function testSerieNfseEhPrimeiraTagDeNf(): void
    {
        $rps = $this->makeRps();
        $rps->serieNfse(2);

        $dom = $this->load($this->render($rps));
        $nf = $this->query($dom, '/nfse/nf')->item(0);

        $this->assertSame('serie_nfse', $this->childNames($nf)[0]);
        $this->assertSame('2', $this->value($dom, '/nfse/nf/serie_nfse'));

        $semSerie = $this->load($this->render($this->makeRps()));
        $this->assertSame(0, $this->query($semSerie, '//serie_nfse')->length);
        $this->assertSame('data_fato_gerador', $this->childNames($this->query($semSerie, '/nfse/nf')->item(0))[0]);
    }

    public function testOrdemDasTagsSegueTabela4(): void
    {
        $rps = $this->makeRps();
        $rps->pedagio('EQ-01');
        $rps->addLinhaGenerico('Titulo', 'Descricao');
        $rps->produtos('Pecas', 30);
        $rps->formaPagamento(Rps::PIX);
        $rps->addParcela(1, 1321.50, new DateTime('2026-05-20'));

        $dom = $this->load($this->render($rps));

        $this->assertSame(
            ['nfse_teste', 'rps', 'pedagio', 'nf', 'prestador', 'tomador', 'itens', 'genericos', 'produtos', 'forma_pagamento'],
            $this->childNames($dom->documentElement)
        );
        $this->assertSame(
            ['data_fato_gerador', 'valor_total', 'valor_desconto', 'valor_ir', 'valor_inss', 'valor_contribuicao_social', 'valor_rps', 'valor_pis', 'valor_cofins', 'observacao'],
            $this->childNames($this->query($dom, '/nfse/nf')->item(0))
        );
        $this->assertSame(
            ['tributa_municipio_prestador', 'codigo_local_prestacao_servico', 'unidade_codigo', 'unidade_quantidade', 'unidade_valor_unitario', 'codigo_item_lista_servico', 'descritivo', 'aliquota_item_lista_servico', 'situacao_tributaria', 'valor_tributavel', 'valor_deducao', 'valor_issrf'],
            $this->childNames($this->query($dom, '/nfse/itens/lista')->item(0))
        );
    }

    public function testGenericosFicamDentroDoAgrupador(): void
    {
        $rps = $this->makeRps();
        $rps->addLinhaGenerico('Titulo 1', 'Descricao 1');
        $rps->addLinhaGenerico('Titulo 2', 'Descricao 2');

        $dom = $this->load($this->render($rps));

        $this->assertSame(2, $this->query($dom, '/nfse/genericos/linha')->length);
        $this->assertSame(0, $this->query($dom, '/nfse/linha')->length);
        $this->assertSame('Titulo 1', $this->value($dom, '/nfse/genericos/linha[1]/titulo'));
        $this->assertSame('Descricao 2', $this->value($dom, '/nfse/genericos/linha[2]/descricao'));
    }

    public function testFormaPagamentoTemTipoEParcelas(): void
    {
        $rps = $this->makeRps();
        $rps->formaPagamento(Rps::APRAZO);
        $rps->addParcela(1, 100, new DateTime('2019-07-24'));
        $rps->addParcela(2, 50.5, new DateTime('2019-08-24'));

        $dom = $this->load($this->render($rps));
        $forma = $this->query($dom, '/nfse/forma_pagamento')->item(0);

        $this->assertSame(['tipo_pagamento', 'parcelas'], $this->childNames($forma));
        $this->assertSame('2', $this->value($dom, '/nfse/forma_pagamento/tipo_pagamento'));
        $this->assertSame(2, $this->query($dom, '/nfse/forma_pagamento/parcelas/parcela')->length);
        $this->assertSame(
            ['numero', 'valor', 'data_vencimento'],
            $this->childNames($this->query($dom, '/nfse/forma_pagamento/parcelas/parcela[1]')->item(0))
        );
        $this->assertSame('1', $this->value($dom, '/nfse/forma_pagamento/parcelas/parcela[1]/numero'));
        $this->assertSame('100,00', $this->value($dom, '/nfse/forma_pagamento/parcelas/parcela[1]/valor'));
        $this->assertSame('24/07/2019', $this->value($dom, '/nfse/forma_pagamento/parcelas/parcela[1]/data_vencimento'));
        $this->assertSame('50,50', $this->value($dom, '/nfse/forma_pagamento/parcelas/parcela[2]/valor'));
    }

    public function testSomaDasParcelasNaoEValidadaPelaFactory(): void
    {
        $rps = $this->makeRps();
        $rps->formaPagamento(Rps::AVISTA);
        $rps->addParcela(1, 10, new DateTime('2019-07-24'));

        $dom = $this->load($this->render($rps));

        $this->assertSame('1321,50', $this->value($dom, '/nfse/nf/valor_total'));
        $this->assertSame('10,00', $this->value($dom, '//parcela/valor'));
    }

    public function testFormaPagamentoSemParcelasLanca(): void
    {
        $rps = $this->makeRps();
        $rps->formaPagamento(Rps::AVISTA);

        $this->expectException(InvalidArgumentException::class);
        $this->render($rps);
    }

    public function testCampoObrigatorioVazioLancaExcecao(): void
    {
        $rps = $this->makeRps();
        $item = new ItensRps();
        $item->tributaMunicipioPrestador(Rps::TRIBUTA_MUNICIPIO_PRESTADOR);
        $item->codigoLocalPrestacaoServico(8055);
        $item->codigoItemListaServico(1401);
        $item->aliquotaItemListaServico(2.5);
        $item->situacaoTributaria('0');
        $item->valorTributavel(10);
        $rps->infItens = [$item];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/\[descritivo\]/');
        $this->render($rps);
    }

    public function testCampoObrigatorioDoTomadorVazioLancaExcecao(): void
    {
        $rps = $this->makeRps();
        $rps->infTomador['nome_razao_social'] = '';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/\[nome_razao_social\]/');
        $this->render($rps);
    }

    public function testCampoOpcionalVazioNaoLanca(): void
    {
        $rps = $this->makeRps();

        $dom = $this->load($this->render($rps));

        $this->assertSame('', $this->value($dom, '/nfse/tomador/ie'));
        $this->assertSame('', $this->value($dom, '/nfse/tomador/ponto_referencia'));
        $this->assertSame('', $this->value($dom, '/nfse/tomador/ddd_fax'));
        $this->assertSame('', $this->value($dom, '/nfse/tomador/fone_fax'));
        $this->assertSame('', $this->value($dom, '/nfse/nf/valor_desconto'));
        $this->assertSame('', $this->value($dom, '/nfse/nf/observacao'));
    }

    public function testTomadorEstrangeiroSemCpfCnpjRenderiza(): void
    {
        $rps = $this->makeRps();
        $rps->tomador(Rps::TOMADORES, '', '', 'Foreign Customer', '', '');
        $rps->tomadorEstrangeiro('P123456', 'California', 'Estados Unidos');
        $rps->tomadorEndereco(Rps::SIM, 'Main St', '1', '', '', '', 'Los Angeles', '');

        $dom = $this->load($this->render($rps));

        $this->assertSame('E', $this->value($dom, '/nfse/tomador/tipo'));
        $this->assertSame('', $this->value($dom, '/nfse/tomador/cpfcnpj'));
        $this->assertSame('Los Angeles', $this->value($dom, '/nfse/tomador/cidade'));
        $this->assertSame(
            ['endereco_informado', 'tipo', 'identificador', 'estado', 'pais', 'cpfcnpj'],
            array_slice($this->childNames($this->query($dom, '/nfse/tomador')->item(0)), 0, 6)
        );
    }

    public function testEncodingIsoConverteAcentuacao(): void
    {
        $rps = $this->makeRps();
        $rps->observacao('Serviço de manutenção elétrica');

        $xml = $this->render($rps, $this->makeConfig(['encoding' => 'ISO-8859-1']));

        $this->assertStringStartsWith('<?xml version="1.0" encoding="ISO-8859-1"?>', $xml);
        $this->assertStringContainsString("Servi\xE7o de manuten\xE7\xE3o el\xE9trica", $xml);
        $this->assertStringNotContainsString("\xC3\xA7", $xml);

        $dom = $this->load($xml);
        $this->assertSame('Serviço de manutenção elétrica', $this->value($dom, '/nfse/nf/observacao'));
    }

    public function testDeclaracaoDeEncodingBateComOConteudo(): void
    {
        $rps = $this->makeRps();
        $rps->observacao('Serviço de manutenção elétrica');

        $xml = $this->render($rps);

        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertTrue(mb_check_encoding($xml, 'UTF-8'));
        $this->assertStringContainsString("Servi\xC3\xA7o", $xml);
        $this->assertSame('Serviço de manutenção elétrica', $this->value($this->load($xml), '/nfse/nf/observacao'));

        $minusculo = $this->render($rps, $this->makeConfig(['encoding' => 'utf-8']));
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $minusculo);
        $this->assertSame(1, substr_count($minusculo, '<?xml'));
    }

    public function testEncodingNaoSuportadoLanca(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->render($this->makeRps(), $this->makeConfig(['encoding' => 'UTF-16']));
    }

    public function testNfseTesteVemLogoAposRaiz(): void
    {
        $dom = $this->load($this->render($this->makeRps()));

        $this->assertSame('nfse', $dom->documentElement->nodeName);
        $this->assertSame('nfse_teste', $this->childNames($dom->documentElement)[0]);
        $this->assertSame('1', $this->value($dom, '/nfse/nfse_teste'));

        $producao = $this->load($this->render($this->makeRps(), $this->makeConfig(['teste' => 0])));
        $this->assertSame(0, $this->query($producao, '//nfse_teste')->length);
        $this->assertSame('rps', $this->childNames($producao->documentElement)[0]);
    }

    public function testCodigoAtividadeSoSaiQuandoInformado(): void
    {
        $semCodigo = $this->load($this->render($this->makeRps()));
        $this->assertSame(0, $this->query($semCodigo, '//codigo_atividade')->length);

        $rps = $this->makeRps();
        $rps->infItens[0]->codigoAtividade(123);
        $dom = $this->load($this->render($rps));

        $this->assertSame('123', $this->value($dom, '/nfse/itens/lista/codigo_atividade'));
        $node = $this->query($dom, '/nfse/itens/lista/codigo_atividade')->item(0);
        $this->assertSame('codigo_item_lista_servico', $node->previousSibling->nodeName);
        $this->assertSame('descritivo', $node->nextSibling->nodeName);
    }

    public function testDecimaisUsamVirgula(): void
    {
        $dom = $this->load($this->render($this->makeRps()));

        $this->assertSame('1321,50', $this->value($dom, '/nfse/nf/valor_total'));
        $this->assertSame('45,45', $this->value($dom, '//lista/unidade_quantidade'));
        $this->assertSame('6,45', $this->value($dom, '//lista/unidade_valor_unitario'));
        $this->assertSame('2,50', $this->value($dom, '//lista/aliquota_item_lista_servico'));
        $this->assertSame('1321,50', $this->value($dom, '//lista/valor_tributavel'));
        $this->assertSame('0,00', $this->value($dom, '//lista/valor_deducao'));
    }

    public function testRpsSoSaiQuandoMunicipioTrabalhaComRps(): void
    {
        $dom = $this->load($this->render($this->makeRps(), $this->makeConfig(['trabalha_com_rps' => 0])));
        $this->assertSame(0, $this->query($dom, '/nfse/rps')->length);

        $comRps = $this->load($this->render($this->makeRps()));
        $this->assertSame('2', $this->value($comRps, '/nfse/rps/nro_recibo_provisorio'));
        $this->assertSame('1', $this->value($comRps, '/nfse/rps/serie_recibo_provisorio'));
        $this->assertSame('20/04/2026', $this->value($comRps, '/nfse/rps/data_emissao_recibo_provisorio'));
        $this->assertSame('10:00:00', $this->value($comRps, '/nfse/rps/hora_emissao_recibo_provisorio'));
    }

    public function testAssinaturaEntraDentroDeNfseComIdNota(): void
    {
        $cert = $this->loadTestCertificate();

        $xml = $this->render($this->makeRps(), null, $cert);
        $dom = $this->load($xml);

        $this->assertSame('nota', $dom->documentElement->getAttribute('id'));
        $signatures = $dom->getElementsByTagNameNS(self::NS_DSIG, 'Signature');
        $this->assertSame(1, $signatures->length);
        $this->assertSame('nfse', $signatures->item(0)->parentNode->nodeName);
        $this->assertSame('#nota', $dom->getElementsByTagNameNS(self::NS_DSIG, 'Reference')->item(0)->getAttribute('URI'));
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertSame(1, substr_count($xml, '<?xml'));
    }

    public function testSemCertificadoNaoHaIdNemAssinatura(): void
    {
        $dom = $this->load($this->render($this->makeRps()));

        $this->assertFalse($dom->documentElement->hasAttribute('id'));
        $this->assertSame(0, $dom->getElementsByTagNameNS(self::NS_DSIG, 'Signature')->length);
    }

    // =========================================================================
    // Reforma Tributária — IBS/CBS (NTE 122/2025 v1.7)
    // =========================================================================

    /**
     * Nada de IBS/CBS sai por acidente: a exigência é por município e por lista
     * de serviço, e não se aplica ao Simples Nacional. Um XML que passou a
     * carregar tags novas sem ninguém pedir quebraria todos os municípios que
     * ainda estão na NTE 35/2021.
     */
    public function testSemDadosDeIbsCbsONadaMudaNoXml(): void
    {
        $dom = $this->load($this->render($this->makeRps()));

        $this->assertSame(0, $this->query($dom, '//IBSCBS')->length);
        $this->assertSame(0, $this->query($dom, '//pis_cofins')->length);
        $this->assertSame(0, $this->query($dom, '//codigo_nbs')->length);
        $this->assertSame(0, $this->query($dom, '//tributa_municipio_tomador')->length);
        $this->assertSame(0, $this->query($dom, '//valor_desconto_incondicional')->length);
    }

    /**
     * O cenario que a prefeitura de Lagoa Vermelha recusou com o codigo 00427
     * ("para a lista de servico informada o preenchimento do IBS/CBS e
     * obrigatorio"): o grupo completo, com classificacao e sem valores.
     */
    public function testGrupoIbsCbsSaiCompletoNaOrdemDaNte(): void
    {
        $rps = $this->makeRps();
        $rps->localidadeIncidencia('4311304');
        $rps->ibsCbs(1, 0, '100301', '011', '011004');

        $item = $this->makeItem();
        $item->codigoNbs('1.1501.10.00');
        $item->valorDescontoIncondicional(0);
        $rps->infItens = [$item];

        $dom = $this->load($this->render($rps));

        // 1) O IBSCBS de <nf> carrega so o municipio de incidencia, em IBGE.
        $this->assertSame('4311304', $this->value($dom, '/nfse/nf/IBSCBS/cLocalidadeIncid'));

        // 2) O IBSCBS da raiz carrega a classificacao.
        $this->assertSame('1', $this->value($dom, '/nfse/IBSCBS/finNFSe'));
        $this->assertSame('0', $this->value($dom, '/nfse/IBSCBS/indFinal'));
        $this->assertSame('100301', $this->value($dom, '/nfse/IBSCBS/cIndOp'));
        $this->assertSame('011', $this->value($dom, '/nfse/IBSCBS/valores/trib/gIBSCBS/CST'));
        $this->assertSame('011004', $this->value($dom, '/nfse/IBSCBS/valores/trib/gIBSCBS/cClassTrib'));

        // 3) O item leva o NBS.
        $this->assertSame('1.1501.10.00', $this->value($dom, '//lista/codigo_nbs'));

        /*
         * O municipio CALCULA os valores ("serao calculados automaticamente e
         * serao retornados na emissao"). Mandar base ou aliquota efetiva seria
         * disputar a conta com ele.
         */
        foreach (['vBC', 'pAliqEfetUF', 'pAliqEfetMun', 'pAliqEfetCBS', 'vIBSUF', 'vIBSMun', 'vCBS'] as $tag) {
            $this->assertSame(0, $this->query($dom, '//' . $tag)->length, "A tag {$tag} nao deve ser enviada");
        }

        // 4) tpOper e gRefNFSe ficam fora quando nao se aplicam ([361]/[364]).
        $this->assertSame(0, $this->query($dom, '//tpOper')->length);
        $this->assertSame(0, $this->query($dom, '//gRefNFSe')->length);
    }

    /**
     * A ordem das tags e a da secao 5.1: o NBS vem ANTES do subitem, e o
     * IBSCBS de <nf> e o ultimo filho de <nf>.
     */
    public function testOrdemDasTagsNovasSegueOLayout(): void
    {
        $rps = $this->makeRps();
        $rps->localidadeIncidencia('4311304');
        $rps->ibsCbs(1, 1, '100301', '011', '011004');
        $rps->pisCofinsProprio('01', 1000.00, 0.65, 4.00);

        $item = $this->makeItem();
        $item->codigoNbs('1.1501.10.00');
        $item->valorDescontoIncondicional(15.50);
        $item->tributaMunicipioTomador('0');
        $rps->infItens = [$item];

        $dom = $this->load($this->render($rps));

        $nf = $dom->getElementsByTagName('nf')->item(0);
        $nomes = $this->childNames($nf);

        $this->assertSame('IBSCBS', end($nomes), 'O IBSCBS e o ultimo filho de <nf>');
        $this->assertLessThan(
            array_search('valor_pis', $nomes, true),
            array_search('pis_cofins', $nomes, true),
            'O grupo pis_cofins vem antes de valor_pis'
        );
        $this->assertGreaterThan(
            array_search('valor_rps', $nomes, true),
            array_search('pis_cofins', $nomes, true),
            'O grupo pis_cofins vem depois de valor_rps'
        );

        $lista = $dom->getElementsByTagName('lista')->item(0);
        $nomesItem = $this->childNames($lista);

        $this->assertLessThan(
            array_search('codigo_item_lista_servico', $nomesItem, true),
            array_search('codigo_nbs', $nomesItem, true),
            'O codigo_nbs vem antes do subitem da lista de servicos'
        );
        $this->assertGreaterThan(
            array_search('valor_issrf', $nomesItem, true),
            array_search('valor_desconto_incondicional', $nomesItem, true),
            'O desconto incondicional vem depois do valor_issrf'
        );
        $this->assertSame(
            array_search('tributa_municipio_prestador', $nomesItem, true) + 1,
            array_search('tributa_municipio_tomador', $nomesItem, true),
            'O par de tributacao por municipio sai junto'
        );

        // §3: o proprio vai no grupo; a retencao segue nas tags antigas.
        $this->assertSame('01', $this->value($dom, '/nfse/nf/pis_cofins/cst'));
        $this->assertSame('1000,00', $this->value($dom, '/nfse/nf/pis_cofins/base_calculo'));
        $this->assertSame('0,65', $this->value($dom, '/nfse/nf/pis_cofins/aliquota_pis'));
        $this->assertSame('4,00', $this->value($dom, '/nfse/nf/pis_cofins/aliquota_cofins'));
        $this->assertSame('15,50', $this->value($dom, '//lista/valor_desconto_incondicional'));
    }

    /**
     * [362]: com tpOper 2 ou 3 as referencias sao obrigatorias. Recusar aqui
     * poupa uma ida ao webservice cuja mensagem nao diz qual condicao falhou.
     */
    public function testTpOper2SemReferenciaEBarrado(): void
    {
        $rps = $this->makeRps();
        $rps->localidadeIncidencia('4311304');
        $rps->ibsCbs(1, 0, '100301', '011', '011004', 2);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[362]');
        $this->render($rps);
    }

    /**
     * [361]: sem tpOper, ou com 1, 4 ou 5, as referencias nao podem ir.
     */
    public function testReferenciaSemTpOperCompativelEBarrada(): void
    {
        $rps = $this->makeRps();
        $rps->localidadeIncidencia('4311304');
        $rps->ibsCbs(1, 0, '100301', '011', '011004', 1);
        $rps->referenciasNFSe(['43260900000000000000000000000000000000000001']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[361]');
        $this->render($rps);
    }

    public function testTpOper3ComReferenciasSaiSemRepeticao(): void
    {
        $rps = $this->makeRps();
        $rps->localidadeIncidencia('4311304');
        $rps->ibsCbs(1, 0, '100301', '011', '011004', 3);
        // [363]: chave repetida e sempre erro, nunca intencao.
        $rps->referenciasNFSe(['CHAVE-A', 'CHAVE-B', 'CHAVE-A']);

        $dom = $this->load($this->render($rps));

        $this->assertSame('3', $this->value($dom, '/nfse/IBSCBS/tpOper'));
        $this->assertSame(2, $this->query($dom, '/nfse/IBSCBS/gRefNFSe/refNFSe')->length);
    }

    public function testImovelSaiComApenasOCib(): void
    {
        $rps = $this->makeRps();
        $rps->localidadeIncidencia('4311304');
        $rps->ibsCbs(1, 0, '100301', '011', '011004');
        $rps->imovel(['cCIB' => '12345679']);

        $dom = $this->load($this->render($rps));

        $this->assertSame('12345679', $this->value($dom, '/nfse/IBSCBS/imovel/cCIB'));
        $this->assertSame(0, $this->query($dom, '/nfse/IBSCBS/imovel/end')->length);
    }

    public function testImovelComEnderecoAgrupaEmEnd(): void
    {
        $rps = $this->makeRps();
        $rps->localidadeIncidencia('4311304');
        $rps->ibsCbs(1, 0, '100301', '011', '011004');
        $rps->imovel([
            'inscImobFisc' => '5151515151',
            'CEP' => '89160000',
            'xLgr' => 'Rua Um',
            'nro' => '100',
            'xBairro' => 'Centro',
        ]);

        $dom = $this->load($this->render($rps));

        $this->assertSame('5151515151', $this->value($dom, '/nfse/IBSCBS/imovel/inscImobFisc'));
        $this->assertSame('89160000', $this->value($dom, '/nfse/IBSCBS/imovel/end/CEP'));
        $this->assertSame('Rua Um', $this->value($dom, '/nfse/IBSCBS/imovel/end/xLgr'));
        $this->assertSame(0, $this->query($dom, '/nfse/IBSCBS/imovel/end/xCpl')->length);
    }

    /**
     * O local de incidencia e IBGE (7), nao TOM (4) — o mesmo XML usa os dois
     * padroes, e trocar rende [419]. Barrar aqui e o unico jeito de o erro
     * aparecer com nome de campo.
     */
    public function testLocalidadeIncidenciaComTomEBarrada(): void
    {
        $rps = new Rps();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('7 digitos');
        $rps->localidadeIncidencia('8727');
    }

    public function testFinalidadeInvalidaEBarrada(): void
    {
        $rps = new Rps();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('finNFSe');
        $rps->ibsCbs(0, 0, '100301', '011', '011004');
    }

    /**
     * [401]: as aliquotas de PIS e COFINS proprios vao de 0% a 10%.
     */
    public function testAliquotaDePisAcimaDeDezPorCentoEBarrada(): void
    {
        $rps = new Rps();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[401]');
        $rps->pisCofinsProprio('01', 1000.00, 12.5, 4.00);
    }

    public function testZerosAEsquerdaSaoPreservadosNaClassificacao(): void
    {
        $rps = $this->makeRps();
        $rps->localidadeIncidencia('4311304');
        // Cadastro que guarda os codigos como inteiro perde o zero a esquerda.
        $rps->ibsCbs(1, 0, 100301, 11, 11004);

        $dom = $this->load($this->render($rps));

        $this->assertSame('011', $this->value($dom, '/nfse/IBSCBS/valores/trib/gIBSCBS/CST'));
        $this->assertSame('011004', $this->value($dom, '/nfse/IBSCBS/valores/trib/gIBSCBS/cClassTrib'));
        $this->assertSame('100301', $this->value($dom, '/nfse/IBSCBS/cIndOp'));
    }
}
