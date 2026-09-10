<?php

namespace NFePHP\NFSe\Tests\Models\IPM;

use InvalidArgumentException;
use NFePHP\NFSe\Models\IPM\ItensRps;
use NFePHP\NFSe\Models\IPM\Rps;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Setters do {@see ItensRps} do modelo IPM (Atende.Net), NTE 35/2021 v2.9.
 */
final class ItensRpsTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function valoresAceitosDeTributaMunicipio(): array
    {
        return [
            'string 0' => ['0', '0'],
            'string 1' => ['1', '1'],
            'N' => ['N', 'N'],
            'S' => ['S', 'S'],
            'n minusculo' => ['n', 'N'],
            's minusculo' => ['s', 'S'],
            'int 0' => [0, '0'],
            'int 1' => [1, '1'],
            'constante local da prestacao' => [Rps::TRIBUTA_LOCAL_PRESTACAO, '0'],
            'constante municipio do prestador' => [Rps::TRIBUTA_MUNICIPIO_PRESTADOR, '1'],
            'Rps::NAO' => [Rps::NAO, '0'],
            'Rps::SIM' => [Rps::SIM, '1'],
        ];
    }

    #[DataProvider('valoresAceitosDeTributaMunicipio')]
    public function testTributaMunicipioAceitaZeroUmNS(mixed $entrada, string $esperado): void
    {
        $item = new ItensRps();
        $item->tributaMunicipioPrestador($entrada);

        $this->assertSame($esperado, $item->infTributaMunicipioPrestador);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function valoresRejeitadosDeTributaMunicipio(): array
    {
        return [
            'dois (dominio antigo da lib)' => [2],
            'string 2' => ['2'],
            'vazio' => [''],
            'letra fora do dominio' => ['X'],
            'sim por extenso' => ['SIM'],
        ];
    }

    #[DataProvider('valoresRejeitadosDeTributaMunicipio')]
    public function testTributaMunicipioRejeitaForaDoDominio(mixed $entrada): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ItensRps())->tributaMunicipioPrestador($entrada);
    }

    public function testTributaMunicipioDefaultEhMunicipioDoPrestador(): void
    {
        $item = new ItensRps();
        $item->tributaMunicipioPrestador();

        $this->assertSame('1', $item->infTributaMunicipioPrestador);
    }

    public function testCodigoAtividadeEhOpcional(): void
    {
        $item = new ItensRps();
        $this->assertNull($item->infCodigoAtividade);

        $item->codigoAtividade(123);
        $this->assertSame(123, $item->infCodigoAtividade);

        $item->codigoAtividade('456');
        $this->assertSame('456', $item->infCodigoAtividade);
    }

    public function testCodigoAtividadeRejeitaNaoNumerico(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ItensRps())->codigoAtividade('abc');
    }

    public function testValoresSaoFormatadosComVirgula(): void
    {
        $item = new ItensRps();
        $item->valorTributavel(1321.5);
        $item->valorDeducao(10);
        $item->valorIssrf(3.555);
        $item->aliquotaItemListaServico(2.5);

        $this->assertSame('1321,50', $item->infValorTributavel);
        $this->assertSame('10,00', $item->infValorDeducao);
        $this->assertSame('3,56', $item->infValorIssrf);
        $this->assertSame('2,50', $item->infAliquotaItemListaServico);
    }

    /**
     * Tabela 3: a barra é o único caractere da lista marcado como "Não é
     * permitido" — os demais o webservice escapa. A troca por hífen mora na
     * biblioteca porque é exigência do provedor, não do consumidor.
     */
    public function testDescritivoTrocaBarraPorHifen(): void
    {
        $item = new ItensRps();
        $item->descritivo('Manutenção 24/7 do sistema - OS 45/2026');

        $this->assertSame('Manutenção 24-7 do sistema - OS 45-2026', $item->infDescritivo);
    }

    public function testDescritivoSemBarraSegueIntacto(): void
    {
        $item = new ItensRps();
        $item->descritivo('Consultoria em TI (mensal)');

        $this->assertSame('Consultoria em TI (mensal)', $item->infDescritivo);
    }

    /**
     * A troca é 1:1, então o texto no limite de 1000 caracteres continua
     * válido mesmo quando é todo feito de barras.
     */
    public function testDescritivoComBarrasNoLimiteContinuaValido(): void
    {
        $item = new ItensRps();
        $item->descritivo(str_repeat('/', 1000));

        $this->assertSame(str_repeat('-', 1000), $item->infDescritivo);
    }

    public function testDescritivoVazioContinuaRejeitado(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ItensRps())->descritivo('');
    }
}
