<?php

declare(strict_types=1);

namespace Techork\PaymentService\Common\ValueObject;

use RuntimeException;

/**
 * Card network detected from a card's full PAN. The patterns require enough
 * digits to disambiguate networks whose BINs overlap (Visa vs. VisaElectron,
 * Discover vs. Maestro, Hipercard's 16+ digit pattern), so detection MUST be
 * fed the full number — never the BIN alone.
 *
 * Used wherever a card brand is exchanged: stored alongside tokens / payment
 * methods, requested on virtual-card issuance, sent to gateways. Per-gateway
 * adapters narrow this down to whatever subset their API accepts (e.g.
 * ConnexPay accepts only Visa/Mastercard).
 */
enum CardBrand: string
{
    case Amex = 'amex';
    case Dankort = 'dankort';
    case DinersClub = 'dinersclub';
    case Discover = 'discover';
    case Forbrugsforeningen = 'forbrugsforeningen';
    case Hipercard = 'hipercard';
    case JCB = 'jcb';
    case Maestro = 'maestro';
    case Mastercard = 'mastercard';
    case Mir = 'mir';
    case Troy = 'troy';
    case UnionPay = 'unionpay';
    case Visa = 'visa';
    case VisaElectron = 'visaelectron';

    private const array PATTERNS = [
        'amex' => '/^3[47][0-9]/',
        'dankort' => '/^5019/',
        'dinersclub' => '/^3(0[0-5]|[68][0-9])[0-9]/',
        'discover' => '/^6(011|22126|22925|4[4-9]|5)/',
        'forbrugsforeningen' => '/^600/',
        'hipercard' => '/^(606282\d{10}(\d{3})?)|(3841\d{15})/',
        'jcb' => '/^(?:2131|1800|35\d{3})/',
        'maestro' => '/^(5(018|0[235]|[678])|6(1|39|7|8|9))/',
        'mastercard' => '/^(5[0-5]|2(2(2[1-9]|[3-9])|[3-6]|7(0|1|20)))/',
        'mir' => '/^220/',
        'troy' => '/^9(?!(79200|79289))/',
        'unionpay' => '/^62(?!(2126|2925))/',
        'visa' => '/^4/',
        'visaelectron' => '/^4(026|17500|405|508|844|91[37])/',
    ];

    public static function fromNumber(string $number): self
    {
        foreach (self::PATTERNS as $brand => $pattern) {
            if (preg_match($pattern, $number) === 1) {
                return self::from($brand);
            }
        }

        throw new RuntimeException('Unable to detect card brand from number.');
    }
}
