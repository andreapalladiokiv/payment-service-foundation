<?php

declare(strict_types=1);

namespace Techork\PaymentService\Gateway\Concern;

use Omnipay\Common\Message\AbstractRequest;
use Techork\PaymentService\Common\Contract\DecryptInterface;
use Techork\PaymentService\Common\Contract\PaymentInstrument;
use Techork\PaymentService\Common\ValueObject\PaymentInitiation;
use Techork\PaymentService\Common\ValueObject\ThreeDS\ThreeDSResult;
use Techork\PaymentService\Gateway\Contract\CustomerRepository;
use Techork\PaymentService\Gateway\Contract\GatewayCredential;
use Techork\PaymentService\Gateway\Contract\GatewayInstrumentRepository;

/** @mixin AbstractRequest */
trait InstrumentParameters
{
    public function setInstrument(PaymentInstrument $value): self
    {
        return $this->setParameter('instrument', $value);
    }

    public function getInstrument(): ?PaymentInstrument
    {
        return $this->getParameter('instrument');
    }

    public function setGateway(GatewayCredential $value): self
    {
        return $this->setParameter('gateway', $value);
    }

    public function getGateway(): ?GatewayCredential
    {
        return $this->getParameter('gateway');
    }

    public function setDecrypter(DecryptInterface $value): self
    {
        return $this->setParameter('decrypter', $value);
    }

    public function getDecrypter(): DecryptInterface
    {
        return $this->getParameter('decrypter');
    }

    public function setReferenceResolver(GatewayInstrumentRepository $value): self
    {
        return $this->setParameter('referenceResolver', $value);
    }

    public function getReferenceResolver(): GatewayInstrumentRepository
    {
        return $this->getParameter('referenceResolver');
    }

    public function setCustomerRepository(?CustomerRepository $value): self
    {
        return $this->setParameter('customerRepository', $value);
    }

    public function getCustomerRepository(): ?CustomerRepository
    {
        return $this->getParameter('customerRepository');
    }

    public function setThreeDS(?ThreeDSResult $value): self
    {
        return $this->setParameter('threeDS', $value);
    }

    public function getThreeDS(): ?ThreeDSResult
    {
        return $this->getParameter('threeDS');
    }

    public function setInitiation(PaymentInitiation $value): self
    {
        return $this->setParameter('initiation', $value);
    }

    /**
     * Defaults rather than returning null: every request has an initiation, and a
     * caller that did not set one is describing a cardholder-present payment. A
     * nullable getter would push that same default into each adapter, where
     * forgetting it means quietly declaring a merchant-initiated payment as
     * cardholder-present — the direction that claims an SCA exemption we have no
     * right to.
     */
    public function getInitiation(): PaymentInitiation
    {
        return $this->getParameter('initiation') ?? PaymentInitiation::CardholderInitiated;
    }
}
