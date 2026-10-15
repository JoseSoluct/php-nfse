<?php

namespace NFePHP\NFSe\Tests\Models\Nacional;

use NFePHP\NFSe\Models\Nacional\Response;
use PHPUnit\Framework\TestCase;

/**
 * Suíte do parser da resposta de registro de evento (POST /nfse/{chave}/eventos).
 *
 * Em rejeição o ADN responde HTTP 4xx sem `eventoXmlGZipB64`; o motivo precisa
 * chegar em `xMotivo` para o ERP mostrar ao usuário.
 */
final class NacionalEventoResponseTest extends TestCase
{
    public function testSucessoDescompactaOXmlDoEvento(): void
    {
        $xml = '<evento xmlns="http://www.sped.fazenda.gov.br/nfse"><infEvento Id="EVT1">'
            . '<nSeqEvento>1</nSeqEvento><dhProc>2026-10-15T10:00:00-03:00</dhProc>'
            . '<pedRegEvento><infPedReg><chNFSe>' . str_repeat('9', 50) . '</chNFSe>'
            . '<tpEvento>101101</tpEvento></infPedReg></pedRegEvento></infEvento></evento>';

        $json = json_encode([
            'tipoAmbiente' => 2,
            'dataHoraProcessamento' => '2026-10-15T10:00:00-03:00',
            'eventoXmlGZipB64' => base64_encode(gzencode($xml)),
        ]);

        $parsed = Response::parseEvento($json);

        $this->assertSame($xml, $parsed->eventoXmlAssinado);
        $this->assertSame(1, $parsed->nSeqEvento);
        $this->assertSame(str_repeat('9', 50), $parsed->chaveAcesso);
        $this->assertSame([], $parsed->erros);
        $this->assertSame('', $parsed->xMotivo);
    }

    public function testRejeicaoComErrosEmPascalCase(): void
    {
        $json = json_encode([
            'tipoAmbiente' => 2,
            'dataHoraProcessamento' => '2026-10-15T10:00:00-03:00',
            'erros' => [
                ['Codigo' => 'E0840', 'Descricao' => 'Prazo de cancelamento expirado.', 'Complemento' => 'Use a solicitação.'],
                ['Codigo' => 'E1235', 'Descricao' => 'Outro erro.'],
            ],
        ]);

        $parsed = Response::parseEvento($json);

        $this->assertSame('', $parsed->eventoXmlAssinado);
        $this->assertNull($parsed->cStat);
        $this->assertCount(2, $parsed->erros);
        $this->assertSame('E0840', $parsed->erros[0]->codigo);
        $this->assertSame(
            '[E0840] Prazo de cancelamento expirado. — Use a solicitação. | [E1235] Outro erro.',
            $parsed->xMotivo
        );
        $this->assertSame('2026-10-15T10:00:00-03:00', $parsed->dhProc);
    }

    public function testRejeicaoComErrosEmLowercase(): void
    {
        $json = json_encode([
            'erros' => [['codigo' => 'E0302', 'descricao' => 'Chave de acesso inexistente.']],
        ]);

        $parsed = Response::parseEvento($json);

        $this->assertSame('[E0302] Chave de acesso inexistente.', $parsed->xMotivo);
    }

    public function testRejeicaoComErroSingular(): void
    {
        $json = json_encode([
            'erro' => ['codigo' => '1840', 'mensagem' => 'Evento duplicado.'],
        ]);

        $parsed = Response::parseEvento($json);

        $this->assertSame(1840, $parsed->cStat);
        $this->assertSame('[1840] Evento duplicado.', $parsed->xMotivo);
    }

    public function testRejeicaoSemListaDeErrosNaoInventaMotivo(): void
    {
        $parsed = Response::parseEvento(json_encode(['tipoAmbiente' => 2]));

        $this->assertSame('', $parsed->xMotivo);
        $this->assertNull($parsed->cStat);
        $this->assertSame([], $parsed->erros);
    }
}
