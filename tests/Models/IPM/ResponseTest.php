<?php

namespace NFePHP\NFSe\Tests\Models\IPM;

use NFePHP\NFSe\Counties\M4105805\Response as ColomboResponse;
use NFePHP\NFSe\Models\IPM\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Parser do <retorno> do Atende.Net (NTE 35/2021 v2.9, Tabelas 7, 10, 11 e 12).
 */
final class ResponseTest extends TestCase
{
    private function fixture(string $name): string
    {
        $path = __DIR__ . '/../../fixtures/ipm/' . $name;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testLeRetornoReduzido(): void
    {
        $xml = $this->fixture('retorno_reduzido_sucesso.xml');
        $response = Response::read($xml);

        $this->assertTrue($response->isSuccess());
        $this->assertSame($xml, $response->raw);

        $this->assertCount(1, $response->messages);
        $this->assertSame(1, $response->messages[0]->code);
        $this->assertSame('Sucesso', $response->messages[0]->text);
        $this->assertSame('[00001] - Sucesso', $response->messages[0]->raw);

        $this->assertSame('PED-2026-000123', $response->identificador);

        $this->assertNotNull($response->rps);
        $this->assertSame('123', $response->rps->nro_recibo_provisorio);
        $this->assertSame('1', $response->rps->serie_recibo_provisorio);
        $this->assertSame('09/09/2026', $response->rps->data_emissao_recibo_provisorio);
        $this->assertSame('10:15:02', $response->rps->hora_emissao_recibo_provisorio);

        $nfe = $response->nfe;
        $this->assertNotNull($nfe);
        $this->assertSame('4521', $nfe->numero_nfse);
        $this->assertSame('1', $nfe->serie_nfse);
        $this->assertSame('09/09/2026', $nfe->data_nfse);
        $this->assertSame('10:15:32', $nfe->hora_nfse);
        $this->assertSame(1, $nfe->situacao_codigo_nfse);
        $this->assertSame('Emitida', $nfe->situacao_descricao_nfse);
        $this->assertSame(
            'https://cidade.atende.net/?pg=servicos&service=nfse&cod=ABCD1234EFGH5678',
            $nfe->link_nfse
        );
        $this->assertSame('ABCD-1234-EFGH-5678', $nfe->cod_verificador_autenticidade);

        $this->assertTrue($response->isEmitida());
        $this->assertFalse($response->isCancelada());
        $this->assertSame([], $response->documentos);
        $this->assertSame([], $response->errors());
        $this->assertSame('', $response->errorsText());
    }

    public function testLeRetornoCompletoIgnorandoItem(): void
    {
        $xml = $this->fixture('retorno_completo_sucesso.xml');
        $this->assertFalse((bool) preg_match('//u', $xml), 'a fixture precisa estar em ISO-8859-1 de verdade');

        $response = Response::read($xml);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('PED-2026-000124', $response->identificador, 'o <identificador> do tomador não pode vencer o da nota');
        $this->assertNull($response->rps);

        $nfe = $response->nfe;
        $this->assertNotNull($nfe);
        $this->assertSame('4522', $nfe->numero_nfse);
        $this->assertSame('1', $nfe->serie_nfse);
        $this->assertSame('11:02:45', $nfe->hora_nfse);
        $this->assertSame(1, $nfe->situacao_codigo_nfse);
        $this->assertSame('ZZZZ-9999-YYYY-8888', $nfe->cod_verificador_autenticidade);

        // Valores extras do formato completo vêm junto, já em UTF-8.
        $this->assertSame('1500.00', $nfe->valor_total);
        $this->assertSame('Serviço de manutenção predial', $nfe->observacao);

        // <prestador>, <tomador> e <itens><Item> não vazam para o grupo da nota.
        $vars = get_object_vars($nfe);
        $this->assertArrayNotHasKey('cpfcnpj', $vars);
        $this->assertArrayNotHasKey('descritivo', $vars);
        $this->assertArrayNotHasKey('Item', $vars);
        $this->assertArrayNotHasKey('itens', $vars);
        $this->assertArrayNotHasKey('tipo', $vars);
    }

    /**
     * A NTE descreve o conteúdo de <codigo> como "[Número do Erro] -
     * [Descrição do Erro]" e nunca diz se os colchetes são literais. Aceitar as
     * duas grafias é o que impede um retorno de SUCESSO sem colchetes de ser
     * lido como falha — a nota sairia no município e o consumidor a trataria
     * como não emitida.
     */
    public function testSucessoAceitaCodigoSemColchetes(): void
    {
        foreach (['00001 - Sucesso', '00001-Sucesso', ' 00001  -  Sucesso.'] as $codigo) {
            $response = Response::read(sprintf(
                '<?xml version="1.0" encoding="ISO-8859-1"?><retorno><mensagem><codigo>%s</codigo></mensagem></retorno>',
                $codigo
            ));

            $this->assertTrue($response->isSuccess(), "'{$codigo}' deveria ser sucesso");
            $this->assertSame(1, $response->messages[0]->code, "'{$codigo}' deveria ter código inteiro 1");
        }

        $erro = Response::read(
            '<?xml version="1.0" encoding="UTF-8"?><retorno><mensagem><codigo>00248 - É necessário informar ao menos 5 caracteres na Tag Observação.</codigo></mensagem></retorno>'
        );
        $this->assertFalse($erro->isSuccess());
        $this->assertSame(248, $erro->messages[0]->code);
        $this->assertSame('É necessário informar ao menos 5 caracteres na Tag Observação.', $erro->messages[0]->text);
        // errorsText() renormaliza para a grafia com colchetes do catálogo do §5.
        $this->assertSame('[00248] - É necessário informar ao menos 5 caracteres na Tag Observação.', $erro->errorsText());
    }

    /**
     * Só os cinco dígitos que a §4.6 garante ("o número do erro sempre será de
     * cinco posições") viram código sem colchetes — uma descrição que comece
     * por número não pode ser confundida com um código.
     */
    public function testDescricaoQueComecaComNumeroNaoViraCodigo(): void
    {
        $response = Response::read(
            '<?xml version="1.0" encoding="ISO-8859-1"?><retorno><mensagem><codigo>2 - Cancelada</codigo></mensagem></retorno>'
        );

        $this->assertNull($response->messages[0]->code);
        $this->assertSame('2 - Cancelada', $response->messages[0]->text);
    }

    public function testSucessoAceitaUmZeroUmEZerosAEsquerda(): void
    {
        foreach (['[1] - Sucesso', '[01] - Sucesso', '[00001] - Sucesso', '[00001]-Sucesso', ' [ 00001 ]  Sucesso.'] as $codigo) {
            $response = Response::read(sprintf(
                '<?xml version="1.0" encoding="ISO-8859-1"?><retorno><mensagem><codigo>%s</codigo></mensagem></retorno>',
                $codigo
            ));

            $this->assertTrue($response->isSuccess(), "'{$codigo}' deveria ser sucesso");
            $this->assertSame(1, $response->messages[0]->code, "'{$codigo}' deveria ter código inteiro 1");
        }

        $notSuccess = Response::read(
            '<?xml version="1.0" encoding="ISO-8859-1"?><retorno><mensagem><codigo>[00010] - Solicitação não encontrada.</codigo></mensagem></retorno>'
        );
        $this->assertFalse($notSuccess->isSuccess());
        $this->assertSame(10, $notSuccess->messages[0]->code);

        $semCodigo = Response::read(
            '<?xml version="1.0" encoding="ISO-8859-1"?><retorno><mensagem><codigo>Sucesso</codigo></mensagem></retorno>'
        );
        $this->assertFalse($semCodigo->isSuccess(), 'sem número entre colchetes não dá para afirmar sucesso');
        $this->assertNull($semCodigo->messages[0]->code);
        $this->assertSame('Sucesso', $semCodigo->messages[0]->text);
    }

    public function testMultiplasMensagensDeErro(): void
    {
        $response = Response::read($this->fixture('retorno_erros_multiplos.xml'));

        $this->assertFalse($response->isSuccess());
        $this->assertNull($response->nfe);
        $this->assertNull($response->rps);
        $this->assertNull($response->identificador);

        $this->assertCount(2, $response->messages);
        $this->assertSame(11, $response->messages[0]->code);
        $this->assertSame(20, $response->messages[1]->code);
        $this->assertStringStartsWith('Tipo do tomador ("F" - Física', $response->messages[0]->text);
        $this->assertSame('Nome/razão social do tomador não definido no arquivo XML.', $response->messages[1]->text);

        $this->assertCount(2, $response->errors());
        $this->assertSame(
            '[00011] - ' . $response->messages[0]->text . ' | [00020] - Nome/razão social do tomador não definido no arquivo XML.',
            $response->errorsText()
        );
        $this->assertTrue($response->hasCode(20));
        $this->assertFalse($response->hasCode(1));
    }

    public function testErroDeAcesso132(): void
    {
        $response = Response::read($this->fixture('retorno_erro_acesso_132.xml'));

        $this->assertFalse($response->isSuccess());
        $this->assertTrue($response->hasCode(132));
        $this->assertSame('Usuário ou Senha inválidos!', $response->messages[0]->text);
        $this->assertSame('[00132] - Usuário ou Senha inválidos!', $response->errorsText());
    }

    public function testHtmlDeLoginViraErro(): void
    {
        $html = $this->fixture('login_page.html');

        try {
            Response::read($html);
            $this->fail('HTML deveria lançar RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('não é um XML válido', $e->getMessage());
            $this->assertStringContainsString('página HTML de login', $e->getMessage());
            $this->assertStringContainsString('<!DOCTYPE html>', $e->getMessage());
        }
    }

    public function testXhtmlBemFormadoTambemViraErroPelaRaiz(): void
    {
        $xhtml = '<?xml version="1.0" encoding="UTF-8"?><html><head><title>Login</title></head><body><form/></body></html>';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('raiz <html> em vez de <retorno>');

        Response::read($xhtml);
    }

    public function testRespostaVaziaOuTextoViraErro(): void
    {
        try {
            Response::read("   \n ");
            $this->fail('vazio deveria lançar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('vazia', $e->getMessage());
        }

        try {
            Response::read('Internal Server Error');
            $this->fail('texto puro deveria lançar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('não é XML', $e->getMessage());
            $this->assertStringContainsString('Internal Server Error', $e->getMessage());
        }
    }

    public function testRetornoDeSolicitacaoDeCancelamento(): void
    {
        $response = Response::readSolicitacaoCancelamento($this->fixture('retorno_solicitacao_cancelamento.xml'));

        $this->assertCount(2, $response->documentos);

        $primeiro = $response->documentos[0];
        $this->assertSame('4521', $primeiro->numero);
        $this->assertSame('1', $primeiro->serie);
        $this->assertTrue($primeiro->success);
        $this->assertCount(1, $primeiro->messages);
        $this->assertSame(1, $primeiro->messages[0]->code);

        $segundo = $response->documentos[1];
        $this->assertSame('4522', $segundo->numero);
        $this->assertSame('1', $segundo->serie);
        $this->assertFalse($segundo->success);
        $this->assertCount(2, $segundo->messages);
        $this->assertSame(117, $segundo->messages[0]->code);
        $this->assertSame('A NFS-e já se encontra cancelada.', $segundo->messages[0]->text);
        $this->assertSame(162, $segundo->messages[1]->code);

        $this->assertFalse($response->isSuccess(), 'um documento rejeitado derruba o sucesso do lote');
        $this->assertCount(3, $response->messages, 'messages agrega as mensagens de todos os documentos');
        $this->assertNull($response->nfe);

        $array = $response->toArray();
        $this->assertFalse($array['success']);
        $this->assertSame('4522', $array['documentos'][1]['numero']);
        $this->assertSame(117, $array['documentos'][1]['messages'][0]['code']);
    }

    public function testSolicitacaoDeCancelamentoComTodosOsDocumentosAceitos(): void
    {
        $response = Response::readSolicitacaoCancelamento(
            '<?xml version="1.0" encoding="ISO-8859-1"?><retorno><documentos>'
            . '<nfse><dados><numero>10</numero><serie>1</serie></dados><mensagem><codigo>[1] - Sucesso</codigo></mensagem></nfse>'
            . '<nfse><dados><numero>11</numero><serie>1</serie></dados><mensagem><codigo>[00001] - Sucesso</codigo></mensagem></nfse>'
            . '</documentos></retorno>'
        );

        $this->assertTrue($response->isSuccess());
        $this->assertCount(2, $response->documentos);
    }

    public function testSolicitacaoDeCancelamentoComErroDeAcessoNaRaiz(): void
    {
        $response = Response::readSolicitacaoCancelamento($this->fixture('retorno_erro_acesso_132.xml'));

        $this->assertSame([], $response->documentos);
        $this->assertFalse($response->isSuccess());
        $this->assertTrue($response->hasCode(132));
    }

    public function testNotaCanceladaNaConsulta(): void
    {
        $response = Response::read(
            '<?xml version="1.0" encoding="ISO-8859-1"?><retorno>'
            . '<mensagem><codigo>[00001] - Sucesso</codigo></mensagem>'
            . '<nfse><identificador>X</identificador><nfe>'
            . '<numero_nfse>77</numero_nfse><serie_nfse>1</serie_nfse>'
            . '<situacao_codigo_nfse>2</situacao_codigo_nfse><situacao_descricao_nfse>Cancelada</situacao_descricao_nfse>'
            . '</nfe></nfse></retorno>'
        );

        $this->assertTrue($response->isSuccess());
        $this->assertSame(2, $response->situacaoCodigo());
        $this->assertTrue($response->isCancelada());
        $this->assertFalse($response->isEmitida());
        $this->assertNull($response->nfe->link_nfse);
        $this->assertNull($response->nfe->data_nfse);
    }

    public function testSemDeclaracaoDeEncodingComBytesLatin1AindaLe(): void
    {
        $xml = mb_convert_encoding(
            '<retorno><mensagem><codigo>[00132] - Usuário ou Senha inválidos!</codigo></mensagem></retorno>',
            'ISO-8859-1',
            'UTF-8'
        );

        $response = Response::read($xml);

        $this->assertSame('Usuário ou Senha inválidos!', $response->messages[0]->text);
    }

    public function testToArrayDoRetornoReduzido(): void
    {
        $array = Response::read($this->fixture('retorno_reduzido_sucesso.xml'))->toArray();

        $this->assertTrue($array['success']);
        $this->assertSame(1, $array['messages'][0]['code']);
        $this->assertSame('PED-2026-000123', $array['identificador']);
        $this->assertSame('123', $array['rps']['nro_recibo_provisorio']);
        $this->assertSame('4521', $array['nfe']['numero_nfse']);
        $this->assertSame(1, $array['nfe']['situacao_codigo_nfse']);
        $this->assertSame([], $array['documentos']);
        $this->assertStringContainsString('<retorno>', $array['raw']);
    }

    public function testSubclasseDeMunicipioDevolveAPropriaClasse(): void
    {
        $response = ColomboResponse::read($this->fixture('retorno_reduzido_sucesso.xml'));

        $this->assertInstanceOf(ColomboResponse::class, $response);
        $this->assertTrue($response->isSuccess());
    }

    /**
     * O retorno REAL de uma emissao bem-sucedida em Lagoa Vermelha: formato
     * reduzido, com os dados da nota soltos na raiz de <retorno> — e nao dentro
     * de <nfse>, como no retorno completo.
     *
     * Ler so dentro de <nfse> deixava $nfe nulo num retorno de SUCESSO, e o
     * consumidor, sem o numero, tratava a emissao como recusa. A nota 1402
     * existia no municipio e o ERP dizia que a emissao havia falhado: o pior
     * desfecho possivel, porque manda corrigir e reenviar o que ja foi emitido.
     */
    public function testEmissaoDeSucessoComDadosSoltosNaRaizDoRetorno(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<retorno>'
            . '  <mensagem><codigo>00001 - Sucesso</codigo></mensagem>'
            . '  <numero_nfse>1402</numero_nfse>'
            . '  <serie_nfse>1</serie_nfse>'
            . '  <data_nfse>10/09/2026</data_nfse>'
            . '  <hora_nfse>14:28:09</hora_nfse>'
            . '  <situacao_codigo_nfse>1</situacao_codigo_nfse>'
            . '  <situacao_descricao_nfse>Emitida</situacao_descricao_nfse>'
            . '  <link_nfse>https://nfse-lagoavermelha.atende.net/detalhar/1/abc</link_nfse>'
            . '</retorno>';

        $response = Response::read($xml);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->hasCode(1));
        $this->assertNotNull($response->nfe, 'os dados da nota tem de ser lidos da raiz de <retorno>');
        $this->assertSame('1402', $response->nfe->numero_nfse);
        $this->assertSame('1', $response->nfe->serie_nfse);
        $this->assertSame('10/09/2026', $response->nfe->data_nfse);
        $this->assertSame('14:28:09', $response->nfe->hora_nfse);
        $this->assertSame(1, $response->nfe->situacao_codigo_nfse);
        $this->assertSame('Emitida', $response->nfe->situacao_descricao_nfse);
        $this->assertStringContainsString('nfse-lagoavermelha', $response->nfe->link_nfse);
        $this->assertSame(1, $response->situacaoCodigo());
        $this->assertTrue($response->isEmitida());
    }

    /**
     * O retorno de erro segue sem dados de nota: `readNfe()` devolvendo null e
     * nao um objeto vazio e o que permite ao consumidor distinguir "nao emitiu"
     * de "emitiu e eu nao consegui ler".
     */
    public function testRetornoDeErroNaoInventaDadosDeNota(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<retorno><mensagem><codigo>00383 - Lista de Servico informada nao possui '
            . 'desdobramento nacional</codigo></mensagem></retorno>';

        $response = Response::read($xml);

        $this->assertFalse($response->isSuccess());
        $this->assertTrue($response->hasCode(383));
        $this->assertNull($response->nfe);
        $this->assertNull($response->situacaoCodigo());
        $this->assertFalse($response->isEmitida());
    }
}
