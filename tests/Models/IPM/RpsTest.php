<?php

namespace NFePHP\NFSe\Tests\Models\IPM;

use DateTime;
use InvalidArgumentException;
use NFePHP\NFSe\Models\IPM\Rps;
use PHPUnit\Framework\TestCase;

/**
 * Setters e constantes do {@see Rps} do modelo IPM (Atende.Net), NTE 35/2021 v2.9.
 */
final class RpsTest extends TestCase
{
    public function testConstantesDePagamentoConformeDoc(): void
    {
        $this->assertSame(1, Rps::AVISTA);
        $this->assertSame(2, Rps::APRAZO);
        $this->assertSame(3, Rps::DEPOSITO);
        $this->assertSame(4, Rps::NAAPRESENTACAO);
        $this->assertSame(5, Rps::CARTAODEBITO);
        $this->assertSame(6, Rps::CARTAOCREDITO);
        $this->assertSame(7, Rps::CHEQUE);
        $this->assertSame(8, Rps::PIX);
    }

    public function testConstantesSimNaoETributaConformeDoc(): void
    {
        $this->assertSame(1, Rps::SIM);
        $this->assertSame(0, Rps::NAO);
        $this->assertSame('0', Rps::TRIBUTA_LOCAL_PRESTACAO);
        $this->assertSame('1', Rps::TRIBUTA_MUNICIPIO_PRESTADOR);
    }

    public function testFormaPagamentoAceitaDominioDaTabela4(): void
    {
        $rps = new Rps();
        $rps->formaPagamento(Rps::PIX);
        $this->assertSame(8, $rps->infFormasPagamentos['tipo_pagamento']);
        $this->assertSame([], $rps->infFormasPagamentos['parcelas']);

        $rps->formaPagamento('1');
        $this->assertSame(1, $rps->infFormasPagamentos['tipo_pagamento']);
    }

    public function testFormaPagamentoRejeitaTipoForaDoDominio(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Rps())->formaPagamento(9);
    }

    public function testAddParcelaFormataValorComVirgula(): void
    {
        $rps = new Rps();
        $rps->formaPagamento(Rps::APRAZO);
        $rps->addParcela(1, 100, new DateTime('2019-07-24'));
        $rps->addParcela(24, 50.5, new DateTime('2019-08-24'));

        $this->assertSame('100,00', $rps->infFormasPagamentos['parcelas'][0]['valor']);
        $this->assertSame('50,50', $rps->infFormasPagamentos['parcelas'][1]['valor']);
        $this->assertSame(24, $rps->infFormasPagamentos['parcelas'][1]['numero']);
    }

    public function testAddParcelaRejeitaNumeroForaDeUmA24(): void
    {
        $rps = new Rps();
        $rps->formaPagamento(Rps::APRAZO);

        $this->expectException(InvalidArgumentException::class);
        $rps->addParcela(25, 10, new DateTime('2019-07-24'));
    }

    public function testCpfCnpjRemovePontuacao(): void
    {
        $rps = new Rps();
        $rps->cpfCnpjPrestador('12.345.678/0001-90');
        $this->assertSame('12345678000190', $rps->infCpfCnpjPrestador);

        $rps->tomador(Rps::TOMADORPF, ' 123.456.789-09 ', '', 'Fulano', '', '');
        $this->assertSame('12345678909', $rps->infTomador['cpfcnpj']);
    }

    public function testCpfCnpjRejeitaTamanhoInvalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('11 ou 14');
        (new Rps())->cpfCnpjPrestador('1');
    }

    public function testCpfCnpjRejeitaTrezeDigitos(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Rps())->cpfCnpjPrestador('1234567800019');
    }

    public function testTomadorEstrangeiroPodeNaoTerCpfCnpj(): void
    {
        $rps = new Rps();
        $rps->tomador(Rps::TOMADORES, '', '', 'Foreign Customer', '', '');
        $this->assertSame('', $rps->infTomador['cpfcnpj']);

        $rps->tomador(Rps::TOMADORES, null, '', 'Foreign Customer', '', '');
        $this->assertSame('', $rps->infTomador['cpfcnpj']);
    }

    public function testTomadorNacionalExigeCpfCnpj(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Rps())->tomador(Rps::TOMADORPJ, '', '', 'Cliente', '', '');
    }

    public function testCodEquipamentoRetornaValor(): void
    {
        $rps = new Rps();
        $rps->pedagio(' 23434 ');

        $this->assertSame('23434', $rps->infPedagio['cod_equipamento_automatico']);
    }

    public function testCodEquipamentoVazioLanca(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Rps())->pedagio('');
    }

    public function testTomadorEnderecoDefaultTemAsChavesDoLeiaute(): void
    {
        $rps = new Rps();

        $this->assertSame(
            ['endereco_informado', 'logradouro', 'numero_residencia', 'complemento', 'ponto_referencia', 'bairro', 'cidade', 'cep'],
            array_keys($rps->infTomadorEndereco)
        );

        $rps->tomadorEndereco(Rps::NAO, 'Rua', '1', '', '', 'Centro', 8055, '88350000');
        $this->assertSame(
            ['endereco_informado', 'logradouro', 'numero_residencia', 'complemento', 'ponto_referencia', 'bairro', 'cidade', 'cep'],
            array_keys($rps->infTomadorEndereco)
        );
        $this->assertSame(0, $rps->infTomadorEndereco['endereco_informado']);
    }

    public function testSerieNfseEhOpcionalEValidaInteiroPositivo(): void
    {
        $rps = new Rps();
        $this->assertNull($rps->infSerieNfse);

        $rps->serieNfse(2);
        $this->assertSame(2, $rps->infSerieNfse);

        $this->expectException(InvalidArgumentException::class);
        $rps->serieNfse(0);
    }

    public function testIdentificadorMensagemFalaEm80Caracteres(): void
    {
        $rps = new Rps();
        $rps->identificador(str_repeat('a', 80), 'identificador');
        $this->assertSame(80, strlen($rps->infIdentificador));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('80 caracteres');
        $rps->identificador(str_repeat('a', 81), 'identificador');
    }

    public function testIntermediarioFoiRemovido(): void
    {
        $this->assertFalse(method_exists(Rps::class, 'intermediario'));
        $this->assertFalse(property_exists(Rps::class, 'infIntermediario'));
    }

    /**
     * Tabela 3: a barra é o único caractere da lista marcado como "Não é
     * permitido" — os demais o webservice escapa. A troca por hífen mora na
     * biblioteca porque é exigência do provedor, não do consumidor.
     */
    public function testObservacaoTrocaBarraPorHifen(): void
    {
        $rps = new Rps();
        $rps->observacao('Serviço 10/2026 - ref. NF 123/456');

        $this->assertSame('Serviço 10-2026 - ref. NF 123-456', $rps->infObservacao);
    }

    public function testObservacaoSemBarraSeguerIntacta(): void
    {
        $rps = new Rps();
        $rps->observacao('Tributos aprox. R$ 5,00 (Lei 12.741)');

        $this->assertSame('Tributos aprox. R$ 5,00 (Lei 12.741)', $rps->infObservacao);
    }

    /**
     * A troca é 1:1, então o texto de 1000 caracteres continua válido mesmo
     * quando é todo feito de barras.
     */
    public function testObservacaoComBarrasNoLimiteContinuaValida(): void
    {
        $rps = new Rps();
        $rps->observacao(str_repeat('/', 1000));

        $this->assertSame(str_repeat('-', 1000), $rps->infObservacao);
    }

    public function testAddLinhaGenericoTrocaBarraPorHifenNosDoisCampos(): void
    {
        $rps = new Rps();
        $rps->addLinhaGenerico('Contrato n/2026', 'Vigência 01/01/2026 a 31/12/2026');

        $this->assertSame('Contrato n-2026', $rps->infGenericos[0]['titulo']);
        $this->assertSame('Vigência 01-01-2026 a 31-12-2026', $rps->infGenericos[0]['descricao']);
    }
}
