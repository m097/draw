<?php

namespace Draw\Bundle\TesterBundle\Tests;

use Draw\Bundle\TesterBundle\DependencyInjection\ServiceTesterTrait;
use Draw\Bundle\TesterBundle\Http\BrowserFactoryInterface;
use Draw\Bundle\TesterBundle\Http\HttpTesterTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\BrowserKit\AbstractBrowser;

class TestCase extends KernelTestCase implements BrowserFactoryInterface
{
    use ServiceTesterTrait;
    use HttpTesterTrait;
    protected static $class = AppKernel::class;

    public function createBrowser(): AbstractBrowser
    {
        return static::bootKernel()->getContainer()->get('test.client');
    }
}
