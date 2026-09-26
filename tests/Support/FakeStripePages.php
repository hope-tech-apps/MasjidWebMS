<?php

namespace Tests\Support;

/**
 * What the stubbed Stripe in MakesRamadanGivingForms was asked for: every page created
 * (its params and account) and each page's status. Static, because the stub is an
 * anonymous subclass of the checkout service built by the container.
 */
final class FakeStripePages
{
    /** @var array<int,array{params: array<string,mixed>, account: string}> */
    public static array $created = [];

    /** @var array<string,string> session id => 'open' | 'complete' | 'expired' */
    public static array $pages = [];
}
