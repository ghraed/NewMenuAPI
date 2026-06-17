<?php

namespace Tests\Unit;

use App\Support\DomainName;
use PHPUnit\Framework\TestCase;

class DomainNameTest extends TestCase
{
    public function test_custom_domain_normalization_removes_protocol_www_path_query_and_slash(): void
    {
        $normalized = DomainName::normalize('  HTTPS://WWW.Example.com/menu?lang=en#section/  ');

        $this->assertSame('example.com', $normalized);
    }

    public function test_invalid_custom_domains_are_rejected(): void
    {
        $this->assertFalse(DomainName::isValidCustomDomain('localhost'));
        $this->assertFalse(DomainName::isValidCustomDomain('127.0.0.1'));
        $this->assertFalse(DomainName::isValidCustomDomain('*.example.com'));
        $this->assertFalse(DomainName::isValidCustomDomain('example.com:8080'));
    }
}
