# Playbook de Homologação — NFS-e Nacional (ADN) v1.01

Este documento descreve o roteiro para homologar a emissão de NFS-e pelo
Ambiente de Dados Nacional (ADN) usando a biblioteca `jose-soluct/php-nfse`
integrada ao backend `prioriza-erp-backend`.

## 1. Pré-requisitos

- Certificado digital A1 ICP-Brasil vigente (PFX) da empresa emitente.
- CNPJ credenciado junto à RFB para o ambiente de produção restrita.
- Acesso aos endpoints do ADN (mTLS habilitado):
  - Produção: `https://sefin.nfse.gov.br`
  - Homologação (produção restrita): `https://sefin.producaorestrita.nfse.gov.br`
- Empresa cadastrada no ERP com `environment = Homologacao (2)`.
- Certificado A1 importado em `CompanyCertificate` (path em Storage + senha
  criptografada).

## 2. Configuração no ERP

1. **Aplicar migrations** (uma única vez):
   ```bash
   php artisan migrate --database=landlord --path=database/migrations/landlord/2026_04_20_100300_normalize_nacional_nfse_provider.php --force
   php artisan tenants:artisan "migrate --database=tenant --path=app-modules/nfse/database/migrations/2026_04_20_100000_add_national_fields_to_rps_table.php --force"
   php artisan tenants:artisan "migrate --database=tenant --path=app-modules/nfse/database/migrations/2026_04_20_100100_add_national_fields_to_nfse_nfses_table.php --force"
   php artisan tenants:artisan "migrate --database=tenant --path=app-modules/nfse/database/migrations/2026_04_20_100200_create_nfse_events_table.php --force"
   ```

2. **Rodar seeder** do provider Nacional (idempotente):
   ```bash
   php artisan db:seed --class=Database\\Seeders\\NfseProviderSeeder --database=landlord
   ```

3. **Associar Configuration ao provider Nacional**:
   - UI: Configurações de NFSe → selecionar "Nacional" no dropdown de provedor.
   - Ou via tinker:
     ```php
     $p = \Prioriza\Nfse\Models\Landlord\NfseProvider::where('code', 'Nacional')->first();
     $cfg = \Prioriza\Nfse\Models\Configuration::first();
     $cfg->update(['provider_id' => $p->id]);
     ```

4. **Validar que o resolver retorna o NacionalProvider**:
   ```php
   $driver = \Prioriza\Nfse\Services\NFSeManager::make($company)->getDriverForCompany();
   // deve retornar instância de Prioriza\Nfse\Providers\Nacional\NacionalProvider
   ```

## 3. Fluxo 1 — Emissão de DPS

### 3.1 Criar RPS completo

```
POST /api/nfse/rps/complete
Authorization: Bearer {token}

{
  "rps": { "number": 1, "series": "1", "type": "1", "issue_date": "2026-04-20T10:00:00" },
  "taker": {
    "document_identifier": "98765432000101",
    "name": "Cliente Homologação",
    "phone": "5499998877",
    "email": "cliente@teste.com",
    "address": "RUA DAS FLORES",
    "number": "100",
    "neighborhood": "CENTRO",
    "zip_code": "99950000",
    "city_id": "<uuid da city Tapejara>"
  },
  "services": [ { "description": "Servico de homologacao", "service_list_item_code": ["010601"] } ],
  "service_declaration": {
    "services_value": 100.00,
    "tax_rate": 3.00,
    "iss_value": 3.00,
    "service_code_id": "<uuid do ServiceCode 010601>",
    "competence_date": "2026-04-20"
  }
}
```

### 3.2 Enviar ao ADN

```
POST /api/nfse/emitir
{ "id": "<ulid do Rps>" }
```

Respostas esperadas:
- **Sucesso** (`cStat = 100`): resposta contém `access_key` (50 dígitos),
  `number` (nNFSe) e `nfse_id`. O Rps é atualizado com
  `status = Emitido`, `access_key`, `dps_id`, `xml_signed`, `xml_return`,
  `protocol`. Um registro `Nfse` é criado.
- **Rejeição**: `status = Pendente` + `emission_error` com
  `[cStat N] mensagem da RFB`.

### 3.3 Verificações

```bash
# Inspecionar o Rps
php artisan tinker --execute='
use Prioriza\Nfse\Models\Rps;
$r = Rps::whereNotNull("access_key")->latest()->first();
echo "status={$r->status->name} access_key={$r->access_key} dps_id={$r->dps_id}\n";
echo "protocol=" . $r->protocol . " nsu=" . $r->nsu . "\n";
'
```

- Confirmar que `access_key` tem **exatamente 50 dígitos**.
- Confirmar que `dps_id` começa com `DPS` e tem **45 chars**.
- Confirmar que `xml_signed` começa com `<DPS xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01">`.
- Confirmar que `xml_signed` contém `<Signature` com
  `DigestMethod` SHA-256 (`http://www.w3.org/2001/04/xmlenc#sha256`).

## 4. Fluxo 2 — Consulta por chave de acesso

```
POST /api/nfse/consulta-rps-avulso
{ "numero": 1, "serie": "1", "tipo": "1" }
```

Resposta contém `response` com o JSON bruto retornado pelo ADN.
Validar que:
- O JSON traz `nfseXmlGZipB64`.
- Após descompactar (`Response::parseEmissao`), o XML da NFS-e tem
  `Id` começando com `NFS` (53 chars).

## 5. Fluxo 3 — DANFSe (PDF)

```
GET /api/nfse/rps/{rpsId}/pdf
```

Deve:
- Retornar HTTP 200 com content-type `application/pdf` (ou redirect para
  path do Storage).
- Criar arquivo em `storage/nfse/danfse/{chave}.pdf`.
- Atualizar `Nfse.danfse_path`.

Validação binária:
```bash
head -c 5 storage/app/nfse/danfse/99999999999999999999999999999999999999999999999999.pdf
# deve imprimir "%PDF-"
```

## 6. Fluxo 4 — Cancelamento por evento (101101)

```
POST /api/nfse/cancelar
{
  "rps_id": "<ulid>",
  "codigo_cancelamento": "1",
  "motivo_cancelamento": "Erro na emissao do servico prestado"
}
```

Códigos válidos (TSCodJustCanc):
- `1` — Erro na emissão
- `2` — Serviço não prestado
- `9` — Outros

xMotivo precisa ter **15 a 255 caracteres**.

Verificações:
- `Rps.status = Cancelado`, `cancellation_date`, `cancellation_reason`
  preenchidos.
- `Nfse.status = Cancelada`.
- Registro em `nfse.nfse_events` com:
  - `type_code = "101101"`
  - `reason = "1"` (ou o código enviado)
  - `justification` com o texto do motivo
  - `event_xml_return` com o XML descompactado do evento
  - `protocol` do ADN

## 7. Fluxo 5 — Listar eventos

```
GET {base}/nfse/{chave}/eventos (via Tools::listarEventos)
```

Testar via tinker:
```php
$provider = \Prioriza\Nfse\Services\NFSeManager::make($company)->getDriverForCompany();
// setup tools via emitir/consultar ou expor método listarEventos
```

## 8. Fluxo 6 — Sincronização DFe (NFS-e recebidas)

```
php artisan tinker --execute='
$provider = \Prioriza\Nfse\Services\NFSeManager::make($company)->getDriverForCompany();
$result = $provider->sincronizarDFe($company, 0);
echo "ultimoNSU=" . $result["ultimoNSU"] . "\n";
echo "documentos=" . count($result["documentos"]) . "\n";
'
```

Deve retornar array com `documentos` descompactados (cada um com `nsu`,
`chaveAcesso`, `tpDoc`, `xml`).

## 9. Critérios de aceite

- [x] DPS gerada valida contra `DPS_v1.01.xsd` sem erros.
- [x] XML assinado com `OPENSSL_ALGO_SHA256` e referência à tag `infDPS`.
- [x] Payload HTTP: `POST /nfse`, `Content-Type: application/json`, body
      começando com `{"dpsXmlGZipB64":"..."}`.
- [x] mTLS ativo (cURL com `CURLOPT_SSLCERT` + `CURLOPT_SSLKEY` ICP-Brasil).
- [x] URL resolvida conforme ambiente: `sefin.producaorestrita.nfse.gov.br`
      (homologação) ou `sefin.nfse.gov.br` (produção).
- [x] Chave de acesso persistida com 50 dígitos.
- [x] DANFSe salvo em Storage com header `%PDF-`.
- [x] Cancelamento via evento `101101` cria registro em `nfse.nfse_events`
      e muda status de Rps/Nfse.

## 10. Troubleshooting

### "Erro cURL: [35] unknown CA"

mTLS falhou — verificar se o certificado A1 não expirou e se o PFX
contém a cadeia completa. Conferir `$company->environment` vs. endpoint.

### "cStat 280 — Certificado inválido"

Verificar data de validade e se o CNPJ do certificado bate com o CNPJ
da Company cadastrada.

### "XSD não valida: element 'end' is not expected"

Estrutura do tomador incorreta. Ver `RenderRps v1.01` — ordem correta
dentro de `<toma>`: CNPJ|CPF → IM? → xNome → **end**? → fone? → email?.
Dentro de `<end>`: `<endNac><cMun/><CEP/></endNac>` antes de xLgr.

### "fone inválido"

O ADN aceita apenas 6–20 dígitos numéricos. O `NacionalProvider` sanitiza
automaticamente; se persistir o erro, conferir se o taker tem número
válido.

### "cNBS deve ter 9 dígitos"

O cadastro Nacional de NBS tem códigos como `1.1502.20.00`. O provider
sanitiza removendo pontos. Se o cadastro tiver código com menos de 9
dígitos, o campo é omitido (válido conforme XSD — é opcional).
