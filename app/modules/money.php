<?php
declare(strict_types=1);
function decimal_centavos(string|int $value): int
{
    if (!preg_match('/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,2}))?$/D', (string)$value, $parts)) {
        throw new InvalidArgumentException('Invalid monetary amount.');
    }
    return (int)$parts[1]*100 + (int)str_pad($parts[2] ?? '', 2, '0');
}
function decimal_amount(int $centavos): string
{
    if ($centavos < 0) throw new InvalidArgumentException('Negative amount.');
    return intdiv($centavos,100).'.'.str_pad((string)($centavos%100),2,'0',STR_PAD_LEFT);
}
function peso_amount(int $centavos): string
{
    $pesos = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', (string)intdiv($centavos,100));
    return '₱'.$pesos.'.'.str_pad((string)($centavos%100),2,'0',STR_PAD_LEFT);
}
function percentage_centavos(int $total, string $percentage): int
{
    $basisPoints = decimal_centavos($percentage);
    if ($basisPoints <= 0 || $basisPoints > 10000) throw new BookingValidationException('Downpayment percentage has not been validly configured.');
    return intdiv($total*$basisPoints+5000,10000); // Round half up using integers.
}
