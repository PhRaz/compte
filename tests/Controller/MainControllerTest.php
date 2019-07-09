<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MainControllerTest extends WebTestCase
{
    public function testHomePage()
    {
        $client = static::createClient();

        $client->request('GET', '/');

        $this->assertEquals(
            301,
            $client->getResponse()->getStatusCode()
        );

        $this->assertTrue(
            $client->getResponse()->isRedirect('http://localhost/fr/home')
        );

        $crawler = $client->request('GET', 'http://localhost/fr/home');

        $this->assertEquals(
            200,
            $client->getResponse()->getStatusCode()
        );

        $this->assertCount(5, $crawler->filter('h1'));

        $this->assertSelectorTextContains('p.lead.text-center', 'Une solution simple pour partager les dépenses.');

        $this->assertEquals(5, $crawler->filter('h1')->count());
    }
}
