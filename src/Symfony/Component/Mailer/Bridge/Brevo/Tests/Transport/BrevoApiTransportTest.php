<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Bridge\Brevo\Tests\Transport;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoApiTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Contracts\HttpClient\ResponseInterface;

class BrevoApiTransportTest extends TestCase
{
    /**
     * @dataProvider getTransportData
     */
    public function testToString(BrevoApiTransport $transport, string $expected)
    {
        $this->assertSame($expected, (string) $transport);
    }

    public static function getTransportData(): \Generator
    {
        yield [
            new BrevoApiTransport('ACCESS_KEY'),
            'brevo+api://api.brevo.com',
        ];

        yield [
            (new BrevoApiTransport('ACCESS_KEY'))->setHost('example.com'),
            'brevo+api://example.com',
        ];

        yield [
            (new BrevoApiTransport('ACCESS_KEY'))->setHost('example.com')->setPort(99),
            'brevo+api://example.com:99',
        ];
    }

    public function testCustomHeader()
    {
        $params = ['param1' => 'foo', 'param2' => 'bar'];
        $json = json_encode(['"custom_header_1' => 'custom_value_1']);

        $email = new Email();
        $email->getHeaders()
            ->add(new MetadataHeader('custom', $json))
            ->add(new TagHeader('TagInHeaders'))
            ->addTextHeader('templateId', 1)
            ->addParameterizedHeader('params', 'params', $params)
            ->addTextHeader('foo', 'bar');
        $envelope = new Envelope(new Address('alice@system.com', 'Alice'), [new Address('bob@system.com', 'Bob')]);

        $transport = new BrevoApiTransport('ACCESS_KEY');
        $method = new \ReflectionMethod(BrevoApiTransport::class, 'getPayload');
        $payload = $method->invoke($transport, $email, $envelope);

        $this->assertArrayHasKey('X-Mailin-Custom', $payload['headers']);
        $this->assertEquals($json, $payload['headers']['X-Mailin-Custom']);

        $this->assertArrayHasKey('tags', $payload);
        $this->assertEquals('TagInHeaders', current($payload['tags']));
        $this->assertArrayHasKey('templateId', $payload);
        $this->assertEquals(1, $payload['templateId']);
        $this->assertArrayHasKey('params', $payload);
        $this->assertEquals('foo', $payload['params']['param1']);
        $this->assertEquals('bar', $payload['params']['param2']);
        $this->assertArrayHasKey('foo', $payload['headers']);
        $this->assertEquals('bar', $payload['headers']['foo']);
    }

    public function testSendThrowsForErrorResponse()
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.brevo.com:8984/v3/smtp/email', $url);
            $this->assertStringContainsString('Accept: */*', $options['headers'][2] ?? $options['request_headers'][1]);

            return new MockResponse(json_encode(['message' => 'i\'m a teapot']), [
                'http_code' => 418,
                'response_headers' => [
                    'content-type' => 'application/json',
                ],
            ]);
        });

        $transport = new BrevoApiTransport('ACCESS_KEY', $client);
        $transport->setPort(8984);

        $mail = new Email();
        $mail->subject('Hello!')
            ->to(new Address('saif.gmati@symfony.com', 'Saif Eddin'))
            ->from(new Address('fabpot@symfony.com', 'Fabien'))
            ->text('Hello There!');

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Unable to send an email: i\'m a teapot (code 418).');
        $transport->send($mail);
    }

    public function testSend()
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.brevo.com:8984/v3/smtp/email', $url);
            $this->assertStringContainsString('Accept: */*', $options['headers'][2] ?? $options['request_headers'][1]);

            return new MockResponse(json_encode(['messageId' => 'foobar']), [
                'http_code' => 201,
            ]);
        });

        $transport = new BrevoApiTransport('ACCESS_KEY', $client);
        $transport->setPort(8984);

        $mail = new Email();
        $mail->subject('Hello!')
            ->to(new Address('saif.gmati@symfony.com', 'Saif Eddin'))
            ->from(new Address('fabpot@symfony.com', 'Fabien'))
            ->text('Hello here!')
            ->html('Hello there!')
            ->addCc('foo@bar.fr')
            ->addBcc('foo@bar.fr')
            ->addReplyTo('foo@bar.fr')
            ->addPart(new DataPart('body'));

        $message = $transport->send($mail);

        $this->assertSame('foobar', $message->getMessageId());
    }

    /**
     * IDN (internationalized domain names) like kältetechnik-xyz.de need to be transformed to ACE
     * (ASCII Compatible Encoding) e.g.xn--kltetechnik-xyz-0kb.de, otherwise brevo api answers with 400 http code.
     *
     * @throws \Symfony\Component\Mailer\Exception\TransportExceptionInterface
     */
    public function testSendForIdnDomains()
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.brevo.com:8984/v3/smtp/email', $url);
            $this->assertStringContainsString('Accept: */*', $options['headers'][2] ?? $options['request_headers'][1]);

            $body = json_decode($options['body'], true);
            // to
            $this->assertSame('kältetechnik@xn--kltetechnik-xyz-0kb.de', $body['to'][0]['email']);
            $this->assertSame('Kältetechnik Xyz', $body['to'][0]['name']);
            // sender
            $this->assertSame('info@xn--kltetechnik-xyz-0kb.de', $body['sender']['email']);
            $this->assertSame('Kältetechnik Xyz', $body['sender']['name']);

            return new MockResponse(json_encode(['messageId' => 'foobar']), [
                'http_code' => 201,
            ]);
        });

        $transport = new BrevoApiTransport('ACCESS_KEY', $client);
        $transport->setPort(8984);

        $mail = new Email();
        $mail->subject('Hello!')
            ->to(new Address('kältetechnik@kältetechnik-xyz.de', 'Kältetechnik Xyz'))
            ->from(new Address('info@kältetechnik-xyz.de', 'Kältetechnik Xyz'))
            ->text('Hello here!')
            ->html('Hello there!');

        $message = $transport->send($mail);

        $this->assertSame('foobar', $message->getMessageId());
    }
}
