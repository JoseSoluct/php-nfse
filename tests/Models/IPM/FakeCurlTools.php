<?php

namespace NFePHP\NFSe\Tests\Models\IPM;

use NFePHP\NFSe\Models\IPM\Tools;
use RuntimeException;

/**
 * Tools com o cURL substituído: enfileira respostas prontas e guarda as
 * opções que seriam passadas ao cURL, para os testes inspecionarem o que
 * REALMENTE seria enviado (headers crus, campo multipart, URL) sem rede.
 */
final class FakeCurlTools extends Tools
{
    /** @var list<array{body: string, headers: string, httpCode: int, errno: int, error: string}> */
    private array $queue = [];

    /** @var list<array<int, mixed>> */
    public array $captured = [];

    public function queueResponse(string $body, int $httpCode = 200, string $rawHeaders = '', int $errno = 0, string $error = ''): void
    {
        $this->queue[] = [
            'body' => $body,
            'headers' => $rawHeaders,
            'httpCode' => $httpCode,
            'errno' => $errno,
            'error' => $error,
        ];
    }

    /**
     * Atalho para exercitar o transporte sem depender das factories de XML.
     */
    public function sendXml(string $xml): string
    {
        return $this->sendRequest('', $xml);
    }

    protected function executeCurl(array $options): array
    {
        $this->captured[] = $options;

        if ($this->queue === []) {
            throw new RuntimeException('FakeCurlTools: nenhuma resposta enfileirada.');
        }

        return array_shift($this->queue);
    }

    /**
     * @return array<int, string>
     */
    public function lastSentHeaders(): array
    {
        $last = end($this->captured);

        return $last === false ? [] : (array) ($last[CURLOPT_HTTPHEADER] ?? []);
    }

    public function lastSentUrl(): string
    {
        $last = end($this->captured);

        return $last === false ? '' : (string) ($last[CURLOPT_URL] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function lastSentPostFields(): array
    {
        $last = end($this->captured);

        return $last === false ? [] : (array) ($last[CURLOPT_POSTFIELDS] ?? []);
    }
}
