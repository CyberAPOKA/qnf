<?php

namespace App\Services;

class PixService
{
    private string $pixKey;

    private string $merchantName;

    private string $merchantCity;

    public function __construct()
    {
        $this->pixKey = config('services.pix.key');
        $this->merchantName = mb_substr(config('services.pix.merchant_name', 'QNF Futsal'), 0, 25);
        $this->merchantCity = mb_substr(config('services.pix.merchant_city', 'Sao Paulo'), 0, 15);
    }

    /**
     * Gera o payload EMV do Pix estático (copia e cola).
     *
     * @param  int  $amountCents  Valor em centavos
     * @param  string  $txId  Identificador da transação
     */
    public function generatePayload(int $amountCents, string $txId): string
    {
        $amount = number_format($amountCents / 100, 2, '.', '');

        $merchantAccount = $this->tlv('00', 'br.gov.bcb.pix')
            .$this->tlv('01', $this->pixKey);

        $additionalData = $this->tlv('05', $txId);

        $payload = $this->tlv('00', '01')                          // Payload Format Indicator
            .$this->tlv('01', '12')                                 // Point of Initiation Method (static)
            .$this->tlv('26', $merchantAccount)                     // Merchant Account Information
            .$this->tlv('52', '0000')                               // Merchant Category Code
            .$this->tlv('53', '986')                                // Transaction Currency (BRL)
            .$this->tlv('54', $amount)                              // Transaction Amount
            .$this->tlv('58', 'BR')                                 // Country Code
            .$this->tlv('59', $this->merchantName)                  // Merchant Name
            .$this->tlv('60', $this->merchantCity)                  // Merchant City
            .$this->tlv('62', $additionalData);                     // Additional Data Field

        // CRC16 placeholder — the spec says to include "6304" before computing
        $payload .= '6304';
        $crc = $this->crc16($payload);

        return $payload.$crc;
    }

    private function tlv(string $id, string $value): string
    {
        return $id.str_pad(strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    /**
     * CRC-16/CCITT-FALSE (polynomial 0x1021, init 0xFFFF).
     */
    private function crc16(string $payload): string
    {
        $crc = 0xFFFF;
        $len = strlen($payload);

        for ($i = 0; $i < $len; $i++) {
            $crc ^= (ord($payload[$i]) << 8);
            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x8000) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
