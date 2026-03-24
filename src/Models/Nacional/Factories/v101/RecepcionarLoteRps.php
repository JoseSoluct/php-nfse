<?php

namespace NFePHP\NFSe\Models\Nacional\Factories\v101;

use NFePHP\NFSe\Models\Nacional\Factories\v100\RecepcionarLoteRps as RecepcionarLoteRpsBase;

/**
 * Prepara o XML de uma DPS para envio via REST (NFS-e Nacional v1.01).
 *
 * A v1.01 usa a mesma estrutura da v1.00; apenas a validação
 * aponta para o schema DPS_v1.01.xsd.
 */
class RecepcionarLoteRps extends RecepcionarLoteRpsBase
{
    protected string $schemaVersao = '1.01';
}
