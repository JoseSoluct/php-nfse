<?php

namespace NFePHP\NFSe\Models\IPM\Factories\v100;

use InvalidArgumentException;
use stdClass;
use NFePHP\NFSe\Models\IPM\Rps;
use NFePHP\NFSe\Models\IPM\ItensRps;
use NFePHP\NFSe\Common\DOMImproved as Dom;
use NFePHP\NFSe\Models\IPM\Factories\Signer;
use NFePHP\NFSe\Models\IPM\Factories\Factory;

class GerarNota extends Factory
{
    /**
     * Monta o XML de emissão de NFS-e conforme a NTE 35/2021 v2.9 (Tabela 4) do Atende.Net.
     *
     * Tags obrigatórias ($obrigatorio = true) entram em $dom->errors quando vazias e o
     * render() lança InvalidArgumentException antes de assinar. As demais saem vazias quando
     * não informadas ($force = true), preservando o formato de fio aceito pelo webservice.
     *
     * A soma das parcelas de <forma_pagamento> NÃO é validada aqui: pela Tabela 4 ela deve
     * bater com <valor_tributavel> descontados <valor_deducao> e <valor_issrf> "quando a
     * situação tributária permitir" — regra que depende da situação tributária de cada item
     * (§4.9) e que só a aplicação conhece; o webservice responde [270] quando divergir.
     *
     * @param Rps $rps
     * @param stdClass $config - usa teste, trabalha_com_rps, cod_tom_municipio e encoding (UTF-8|ISO-8859-1)
     * @return string
     * @throws InvalidArgumentException
     */
    public function render(
        Rps $rps,
        stdClass $config
    ) {
        $dom = new Dom('1.0', 'utf-8');
        $dom->formatOutput = false;
        //Cria o elemento nfse
        $root = $dom->createElement('nfse');
        if ($this->certificate) {
            $root->setAttribute('id', 'nota');
        }

        //Adiciona as tags ao DOM
        $dom->appendChild($root);

        //§4.8: <nfse_teste> com conteúdo "1" logo após a tag geral
        if (!empty($config->teste)) {
            $dom->addChild(
                $root,
                'nfse_teste',
                1,
                false,
                "Definir como teste de integração",
                true
            );
        }

        if ($rps->infIdentificador) {
            $dom->addChild(
                $root,
                'identificador',
                $rps->infIdentificador,
                false,
                "Identificador do arquivo a ser processado",
                true
            );
        }

        if (!empty($config->trabalha_com_rps)) {
            //Cria o elemento rps se a prefeitura trabalha com rps
            $rpsE = $dom->createElement('rps');

            $dom->addChild(
                $rpsE,
                'nro_recibo_provisorio',
                $rps->infNumero ?? '',
                false,
                "Número do Rps",
                true
            );

            $dom->addChild(
                $rpsE,
                'serie_recibo_provisorio',
                $rps->infSerie ?? '',
                false,
                "Série do Rps",
                true
            );

            $dom->addChild(
                $rpsE,
                'data_emissao_recibo_provisorio',
                $rps->infDataEmissao->format('d/m/Y'),
                false,
                "Data de emissão do Rps",
                true
            );

            $dom->addChild(
                $rpsE,
                'hora_emissao_recibo_provisorio',
                $rps->infDataEmissao->format('H:i:s'),
                false,
                "Hora de emissão do Rps",
                true
            );

            //Adiciona as tags ao DOM
            $root->appendChild($rpsE);
        }

        if ($rps->infPedagio) {
            //Cria o elemento pedagio
            $pedagio = $dom->createElement('pedagio');

            $dom->addChild(
                $pedagio,
                'cod_equipamento_automatico',
                $rps->infPedagio['cod_equipamento_automatico'],
                false,
                "Código do equipamento eletrônico do pedágio",
                true
            );

            //Adiciona as tags ao DOM
            $root->appendChild($pedagio);
        }

        //Cria o elemento nf
        $nf = $dom->createElement('nf');

        //Tabela 4: <serie_nfse> é a primeira tag de <nf>; só sai quando informada
        if ($rps->infSerieNfse !== null && $rps->infSerieNfse !== '') {
            $dom->addChild(
                $nf,
                'serie_nfse',
                $rps->infSerieNfse,
                false,
                "Série da NFS-e",
                true
            );
        }

        $dom->addChild(
            $nf,
            'data_fato_gerador',
            $rps->infDataFatoGerador->format('d/m/Y'),
            true,
            "Data do fato gerador da nota fiscal",
            true
        );

        $dom->addChild(
            $nf,
            'valor_total',
            $rps->infValorTotal,
            true,
            "Valor total da nota fiscal",
            true
        );

        $dom->addChild(
            $nf,
            'valor_desconto',
            $rps->infValorDesconto ?? '',
            false,
            "Valor desconto da nota fiscal",
            true
        );

        $dom->addChild(
            $nf,
            'valor_ir',
            $rps->infValorIr ?? '',
            false,
            "Valor do imposto de renda retido da nota fiscal",
            true
        );

        $dom->addChild(
            $nf,
            'valor_inss',
            $rps->infValorInss ?? '',
            false,
            "Valor do INSS nota fiscal",
            true
        );

        $dom->addChild(
            $nf,
            'valor_contribuicao_social',
            $rps->infValorContribuicaoSocial ?? '',
            false,
            "Valor da contribuição social nota fiscal",
            true
        );

        $dom->addChild(
            $nf,
            'valor_rps',
            $rps->infValorRps ?? '',
            false,
            "Valor de retenções da previdência social nota fiscal",
            true
        );

        /*
         * §3 da NTE 122/2025: <pis_cofins> é o PIS/COFINS PRÓPRIO, deduzido da
         * base de cálculo do IBS/CBS e que NÃO reduz o líquido da nota. As tags
         * <valor_pis>/<valor_cofins> logo abaixo continuam sendo a RETENÇÃO,
         * que reduz o líquido e não mexe na base. A tag <tipo_retencao> foi
         * descontinuada: o grupo em que o valor aparece é o que distingue.
         *
         * O grupo só sai completo — o erro [396] cobra a base maior que zero, e
         * meia informação deixaria o webservice sem como calcular.
         */
        if ($rps->infPisCofinsCst !== null) {
            $pisCofins = $dom->createElement('pis_cofins');

            $dom->addChild(
                $pisCofins,
                'cst',
                $rps->infPisCofinsCst,
                true,
                "Código da situação tributária do PIS e COFINS",
                true
            );

            $dom->addChild(
                $pisCofins,
                'base_calculo',
                $rps->infPisCofinsBaseCalculo ?? '',
                true,
                "Valor da base de cálculo de PIS e COFINS",
                true
            );

            $dom->addChild(
                $pisCofins,
                'aliquota_pis',
                $rps->infAliquotaPis ?? '',
                true,
                "Valor da alíquota do PIS",
                true
            );

            $dom->addChild(
                $pisCofins,
                'aliquota_cofins',
                $rps->infAliquotaCofins ?? '',
                true,
                "Valor da alíquota do COFINS",
                true
            );

            $nf->appendChild($pisCofins);
        }

        $dom->addChild(
            $nf,
            'valor_pis',
            $rps->infValorPis ?? '',
            false,
            "Valor do PIS nota fiscal",
            true
        );

        $dom->addChild(
            $nf,
            'valor_cofins',
            $rps->infValorCofins ?? '',
            false,
            "Valor do COFINS nota fiscal",
            true
        );

        $dom->addChild(
            $nf,
            'observacao',
            $rps->infObservacao ?? '',
            false,
            "Observações nota fiscal",
            true
        );

        /*
         * Último filho de <nf>. Carrega só o município de incidência, em
         * código IBGE — e o contraste com <prestador><cidade>, que é TOM, é
         * proposital: o mesmo XML usa os dois padrões. Trocá-los rende [419].
         */
        if ($rps->infLocalidadeIncidencia !== null) {
            $ibsCbsNf = $dom->createElement('IBSCBS');

            $dom->addChild(
                $ibsCbsNf,
                'cLocalidadeIncid',
                $rps->infLocalidadeIncidencia,
                true,
                "Código IBGE do município de incidência do IBS/CBS",
                true
            );

            $nf->appendChild($ibsCbsNf);
        }

        //Adiciona as tags ao DOM
        $root->appendChild($nf);

        //Cria o elemento prestador
        $prestador = $dom->createElement('prestador');

        $dom->addChild(
            $prestador,
            'cpfcnpj',
            $rps->infCpfCnpjPrestador,
            true,
            "CPF/CNPJ do emissor da nota",
            true
        );

        $dom->addChild(
            $prestador,
            'cidade',
            $config->cod_tom_municipio ?? '',
            true,
            "Código tom do municipio do emissor da nota",
            true
        );

        //Adiciona as tags ao DOM
        $root->appendChild($prestador);

        //Cria o elemento tomador
        $tomador = $dom->createElement('tomador');

        $dom->addChild(
            $tomador,
            'endereco_informado',
            $rps->infTomadorEndereco['endereco_informado'],
            false,
            "Define se apresenta o endereco do tomador na nota",
            true
        );

        $dom->addChild(
            $tomador,
            'tipo',
            $rps->infTomador['tipo'],
            true,
            "Tipo de pessoa do tomador na nota",
            true
        );

        if ($rps->infTomadorEstrangeiro) {
            if ($rps->infTomador['tipo'] != Rps::TOMADORES) {
                throw new InvalidArgumentException("Definido informações de tomador estrangeiro para tomador diferente de 'E'");
            }
            $dom->addChild(
                $tomador,
                'identificador',
                $rps->infTomadorEstrangeiro['identificador'],
                false,
                "Numero do cartao de identificacao estrangeira ou passaporte",
                true
            );

            $dom->addChild(
                $tomador,
                'estado',
                $rps->infTomadorEstrangeiro['estado'],
                false,
                "Estado de origem do tomador estrangeiro",
                true
            );

            $dom->addChild(
                $tomador,
                'pais',
                $rps->infTomadorEstrangeiro['pais'],
                false,
                "Pais de origem do tomador estrangeiro",
                true
            );
        }

        $dom->addChild(
            $tomador,
            'cpfcnpj',
            $rps->infTomador['cpfcnpj'],
            false,
            "CPF/Cnpj do tomador",
            true
        );

        $dom->addChild(
            $tomador,
            'ie',
            $rps->infTomador['ie'],
            false,
            "Inscrição Estadual do tomador",
            true
        );

        $dom->addChild(
            $tomador,
            'nome_razao_social',
            $rps->infTomador['nome_razao_social'],
            true,
            "Nome do tomador",
            true
        );

        $dom->addChild(
            $tomador,
            'sobrenome_nome_fantasia',
            $rps->infTomador['sobrenome_nome_fantasia'],
            false,
            "Sobrenome ou nome fantasia do tomador",
            true
        );

        $dom->addChild(
            $tomador,
            'logradouro',
            $rps->infTomadorEndereco['logradouro'],
            false,
            "Logradouro do endereço do tomador",
            true
        );

        $dom->addChild(
            $tomador,
            'email',
            $rps->infTomador['email'],
            false,
            "Emails do tomador, quando necessário informar os emails separados por ;",
            true
        );

        $dom->addChild(
            $tomador,
            'numero_residencia',
            $rps->infTomadorEndereco['numero_residencia'],
            false,
            "Número do endereço do tomador",
            true
        );

        $dom->addChild(
            $tomador,
            'complemento',
            $rps->infTomadorEndereco['complemento'],
            false,
            "Complemento do endereço do tomador",
            true
        );

        $dom->addChild(
            $tomador,
            'ponto_referencia',
            $rps->infTomadorEndereco['ponto_referencia'],
            false,
            "Ponto de referência do endereço do tomador",
            true
        );

        $dom->addChild(
            $tomador,
            'bairro',
            $rps->infTomadorEndereco['bairro'],
            false,
            "Bairro do endereço do tomador",
            true
        );

        $dom->addChild(
            $tomador,
            'cidade',
            $rps->infTomadorEndereco['cidade'],
            false,
            "Código tom ou nome da cidade, se estrangeiro, do endereço do tomador",
            true
        );

        $dom->addChild(
            $tomador,
            'cep',
            $rps->infTomadorEndereco['cep'],
            false,
            "Cep do endereço do tomador",
            true
        );

        $dom->addChild(
            $tomador,
            'ddd_fone_comercial',
            $rps->infTomadorTelefone['ddd_fone_comercial'],
            false,
            "Código de área do telefone do estabelecimento do Tomador",
            true
        );

        $dom->addChild(
            $tomador,
            'fone_comercial',
            $rps->infTomadorTelefone['fone_comercial'],
            false,
            "Telefone do estabelecimento do Tomador",
            true
        );

        $dom->addChild(
            $tomador,
            'ddd_fone_residencial',
            $rps->infTomadorTelefone['ddd_fone_residencial'],
            false,
            "Código de área do telefone residencial do Tomador",
            true
        );

        $dom->addChild(
            $tomador,
            'fone_residencial',
            $rps->infTomadorTelefone['fone_residencial'],
            false,
            "Telefone residencial do Tomador",
            true
        );

        $dom->addChild(
            $tomador,
            'ddd_fax',
            $rps->infTomadorTelefone['ddd_fax'],
            false,
            "Código de área do fax do Tomador",
            true
        );

        $dom->addChild(
            $tomador,
            'fone_fax',
            $rps->infTomadorTelefone['fone_fax'],
            false,
            "Fax do Tomador",
            true
        );

        //Adiciona as tags ao DOM
        $root->appendChild($tomador);

        //Cria o elemento itens
        $itens = $dom->createElement('itens');

        /** @var ItensRps $item */
        foreach ($rps->infItens as $item) {
            //Tabela 4: cada item é um <lista> dentro de <itens>
            $lista = $dom->createElement('lista');

            $dom->addChild(
                $lista,
                'tributa_municipio_prestador',
                $item->infTributaMunicipioPrestador,
                true,
                "Informa onde sera recolhido o imposto",
                true
            );

            /*
             * Excludente do de cima: [389] recusa a incidência informada para
             * prestador E tomador, e [390] a recusa para o tomador quando não
             * há tomador. Por isso a tag só sai quando a aplicação a informa.
             */
            if ($item->infTributaMunicipioTomador !== null) {
                $dom->addChild(
                    $lista,
                    'tributa_municipio_tomador',
                    $item->infTributaMunicipioTomador,
                    false,
                    "Informa se o imposto e recolhido no municipio do tomador",
                    true
                );
            }

            $dom->addChild(
                $lista,
                'codigo_local_prestacao_servico',
                $item->infCodigoLocalPrestacaoServico,
                true,
                "Codigo tom da cidade onde o serviço foi prestado",
                true
            );

            $dom->addChild(
                $lista,
                'unidade_codigo',
                $item->infUnidadeCodigo ?? '',
                false,
                "Codigo das unidades de serviços já cadastradas",
                true
            );

            $dom->addChild(
                $lista,
                'unidade_quantidade',
                $item->infUnidadeQuantidade ?? '',
                false,
                "Quantidade dos serviços prestados relativo à unidade informada",
                true
            );

            $dom->addChild(
                $lista,
                'unidade_valor_unitario',
                $item->infUnidadeValorUnitario ?? '',
                false,
                "Valor unitario dos serviços prestados relativo à unidade informada",
                true
            );

            /*
             * A ordem é a da seção 5.1 da NTE 122/2025: o NBS vem ANTES do
             * subitem da lista de serviços. Obrigatório quando o município
             * exige IBS/CBS ([366] cobra a tag, [367] recusa NBS inexistente),
             * e opcional aqui porque quem sabe se o município exige é a
             * aplicação.
             */
            if ($item->infCodigoNbs !== null) {
                $dom->addChild(
                    $lista,
                    'codigo_nbs',
                    $item->infCodigoNbs,
                    false,
                    "Código da NBS (Nomenclatura Brasileira de Serviços)",
                    true
                );
            }

            $dom->addChild(
                $lista,
                'codigo_item_lista_servico',
                $item->infCodigoItemListaServico,
                true,
                "Código do subitem da lista de serviços",
                true
            );

            //Opcional: código de atividade conforme definido no município
            if ($item->infCodigoAtividade !== null && $item->infCodigoAtividade !== '') {
                $dom->addChild(
                    $lista,
                    'codigo_atividade',
                    $item->infCodigoAtividade,
                    false,
                    "Código de atividade conforme definido no município",
                    true
                );
            }

            $dom->addChild(
                $lista,
                'descritivo',
                $item->infDescritivo,
                true,
                "Descritivo coloquial do serviço prestado",
                true
            );

            $dom->addChild(
                $lista,
                'aliquota_item_lista_servico',
                $item->infAliquotaItemListaServico,
                true,
                "Alíquota que irá incidir sobre a base de cálculo. Cuidado com esse campo",
                true
            );

            $dom->addChild(
                $lista,
                'situacao_tributaria',
                $item->infSituacaoTributaria,
                true,
                "Código da Situação Tributária",
                true
            );

            $dom->addChild(
                $lista,
                'valor_tributavel',
                $item->infValorTributavel,
                true,
                "Valor do serviço prestado, sem a dedução aplicada",
                true
            );

            $dom->addChild(
                $lista,
                'valor_deducao',
                $item->infValorDeducao ?? '',
                false,
                "Valor da dedução, quando houver e se a situação tributária permitir",
                true
            );

            $dom->addChild(
                $lista,
                'valor_issrf',
                $item->infValorIssrf ?? '',
                false,
                "Valor do ISS Retido na Fonte, quando houver e se a situação tributária permitir",
                true
            );

            /*
             * Desconto INCONDICIONAL do item, que entra na base do IBS/CBS
             * (vBC = valor do serviço − desconto incondicionado − PIS/COFINS
             * próprio). Não confundir com <valor_desconto> de <nf>, que é o
             * desconto da nota e não participa desse cálculo.
             */
            if ($item->infValorDescontoIncondicional !== null) {
                $dom->addChild(
                    $lista,
                    'valor_desconto_incondicional',
                    $item->infValorDescontoIncondicional,
                    false,
                    "Valor do desconto incondicional do item",
                    true
                );
            }

            //Adiciona as tags ao DOM
            $itens->appendChild($lista);
        }

        //Adiciona as tags ao DOM
        $root->appendChild($itens);

        $this->appendIbsCbs($dom, $root, $rps);

        if (!empty($rps->infGenericos)) {
            //Cria o elemento genericos
            $genericos = $dom->createElement('genericos');

            foreach ($rps->infGenericos as $generico) {
                //Cria o elemento linha
                $linha = $dom->createElement('linha');

                $dom->addChild(
                    $linha,
                    'titulo',
                    $generico['titulo'],
                    false,
                    "Título do campo livre.",
                    true
                );

                $dom->addChild(
                    $linha,
                    'descricao',
                    $generico['descricao'],
                    false,
                    "Conteúdo do campo livre.",
                    true
                );

                //Cada linha fica dentro de <genericos>
                $genericos->appendChild($linha);
            }
            //Adiciona as tags ao DOM
            $root->appendChild($genericos);
        }

        if ($rps->infProdutos) {
            //Cria o elemento produtos
            $produtos = $dom->createElement('produtos');

            $dom->addChild(
                $produtos,
                'descricao',
                $rps->infProdutos['descricao'],
                false,
                "Tudo que se quer que saia na nota a respeito dos produtos (quantidade, desconto, etc.) de forma agrupada",
                true
            );

            $dom->addChild(
                $produtos,
                'valor',
                $rps->infProdutos['valor'],
                false,
                "Soma do valor dos produtos da NFS-e.",
                true
            );

            //Adiciona as tags ao DOM
            $root->appendChild($produtos);
        }

        if ($rps->infFormasPagamentos) {
            if (empty($rps->infFormasPagamentos['parcelas'])) {
                throw new InvalidArgumentException("Definição de tipo de pagamentos sem adicionar as parcelas");
            }

            //Cria o elemento forma_pagamento
            $forma_pagamento = $dom->createElement('forma_pagamento');

            $dom->addChild(
                $forma_pagamento,
                'tipo_pagamento',
                $rps->infFormasPagamentos['tipo_pagamento'],
                true,
                "Código da forma de pagamento (1 a 8)",
                true
            );

            //Cria o agrupador parcelas, com uma <parcela> por parcela informada
            $parcelas = $dom->createElement('parcelas');

            foreach ($rps->infFormasPagamentos['parcelas'] as $parcela) {
                $parcelaE = $dom->createElement('parcela');

                $dom->addChild(
                    $parcelaE,
                    'numero',
                    $parcela['numero'],
                    true,
                    "Numero da parcela",
                    true
                );

                $dom->addChild(
                    $parcelaE,
                    'valor',
                    $parcela['valor'],
                    true,
                    "Valor da parcela",
                    true
                );

                $dom->addChild(
                    $parcelaE,
                    'data_vencimento',
                    $parcela['data_vencimento']->format('d/m/Y'),
                    true,
                    "Data de vencimento da parcela",
                    true
                );

                $parcelas->appendChild($parcelaE);
            }

            $forma_pagamento->appendChild($parcelas);

            //Adiciona as tags ao DOM
            $root->appendChild($forma_pagamento);
        }

        //Nada de XML incompleto segue para assinatura ou transmissão
        $this->lancarErrosDoDom($dom);

        $body = $this->clear($dom->saveXML());

        if ($this->certificate) {
            $body = Signer::sign(
                $this->certificate,
                $body,
                'nfse',
                'id',
                $this->algorithm,
                [false, false, null, null],
                ''
            );
        }

        $body = $this->clear($body);
        return $this->declararEncoding($body, $config);
    }

    /**
     * Grupo <IBSCBS> da raiz (NTE 122/2025 v1.7, §5.1).
     *
     * O que vai aqui é CLASSIFICAÇÃO, não conta: "os valores de IBS e CBS serão
     * calculados automaticamente e serão retornados na emissão da NFS-e". Por
     * isso nem vBC, nem alíquotas efetivas, nem valores de tributo saem no
     * envio — informá-los seria disputar o cálculo com o município.
     *
     * O grupo inteiro é opcional na biblioteca porque a exigência é por
     * MUNICÍPIO e por LISTA DE SERVIÇO (o erro 00427 é justamente "para a lista
     * de serviço informada o preenchimento do IBS/CBS é obrigatório"), e não se
     * aplica a optantes do Simples Nacional. Quem sabe disso é a aplicação.
     *
     * @param Dom $dom
     * @param \DOMElement $root
     * @param Rps $rps
     * @return void
     */
    private function appendIbsCbs($dom, $root, Rps $rps)
    {
        if ($rps->infFinNFSe === null) {
            return;
        }

        $ibsCbs = $dom->createElement('IBSCBS');

        $dom->addChild(
            $ibsCbs,
            'finNFSe',
            $rps->infFinNFSe,
            true,
            "Indicador da finalidade da emissao da NFS-e",
            true
        );

        $dom->addChild(
            $ibsCbs,
            'indFinal',
            $rps->infIndFinal ?? '',
            true,
            "Indica operacao de uso ou consumo pessoal",
            true
        );

        $dom->addChild(
            $ibsCbs,
            'cIndOp',
            $rps->infCIndOp ?? '',
            true,
            "Codigo indicador da operacao de fornecimento",
            true
        );

        if ($rps->infTpOper !== null) {
            $dom->addChild(
                $ibsCbs,
                'tpOper',
                $rps->infTpOper,
                false,
                "Tipo de operacao com entes governamentais",
                true
            );
        }

        /*
         * [361]/[362]: as referências só valem com tpOper 2 ou 3. A regra é
         * validada aqui porque enviar o grupo fora disso é recusa garantida, e
         * a mensagem do webservice não diz qual das duas condições falhou.
         */
        if (!empty($rps->infRefNFSe)) {
            if (!in_array((string) $rps->infTpOper, array('2', '3'), true)) {
                throw new InvalidArgumentException(
                    'O grupo de documentos referenciados (gRefNFSe) so pode ser informado com tpOper 2 ou 3 '
                    . '(erros [361] e [362] do webservice).'
                );
            }

            $gRef = $dom->createElement('gRefNFSe');

            foreach ($rps->infRefNFSe as $chave) {
                $dom->addChild(
                    $gRef,
                    'refNFSe',
                    $chave,
                    true,
                    "Chave de acesso da NFS-e referenciada",
                    true
                );
            }

            $ibsCbs->appendChild($gRef);
        } elseif (in_array((string) $rps->infTpOper, array('2', '3'), true)) {
            throw new InvalidArgumentException(
                'Com tpOper 2 ou 3 o grupo de documentos referenciados (gRefNFSe) e obrigatorio '
                . '(erro [362] do webservice).'
            );
        }

        if (!empty($rps->infImovel)) {
            $imovel = $dom->createElement('imovel');

            foreach (array('inscImobFisc', 'cCIB') as $tag) {
                if (isset($rps->infImovel[$tag])) {
                    $dom->addChild(
                        $imovel,
                        $tag,
                        $rps->infImovel[$tag],
                        false,
                        "Identificacao do imovel",
                        true
                    );
                }
            }

            $endereco = array();
            foreach (array('CEP', 'xLgr', 'nro', 'xCpl', 'xBairro') as $tag) {
                if (isset($rps->infImovel[$tag])) {
                    $endereco[$tag] = $rps->infImovel[$tag];
                }
            }

            if ($endereco !== array()) {
                $end = $dom->createElement('end');

                foreach ($endereco as $tag => $valor) {
                    $dom->addChild(
                        $end,
                        $tag,
                        $valor,
                        false,
                        "Endereco do imovel",
                        true
                    );
                }

                $imovel->appendChild($end);
            }

            $ibsCbs->appendChild($imovel);
        }

        $valores = $dom->createElement('valores');
        $trib = $dom->createElement('trib');
        $gIbsCbs = $dom->createElement('gIBSCBS');

        $dom->addChild(
            $gIbsCbs,
            'CST',
            $rps->infIbsCbsCst ?? '',
            true,
            "Codigo da situacao tributaria do IBS e da CBS",
            true
        );

        $dom->addChild(
            $gIbsCbs,
            'cClassTrib',
            $rps->infIbsCbsClassTrib ?? '',
            true,
            "Codigo de classificacao tributaria do IBS e da CBS",
            true
        );

        $trib->appendChild($gIbsCbs);
        $valores->appendChild($trib);
        $ibsCbs->appendChild($valores);

        $root->appendChild($ibsCbs);
    }
}
