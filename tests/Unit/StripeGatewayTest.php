<?php
declare(strict_types=1);

namespace Mea\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Mea\Module\Payments\PaymentException;
use Mea\Module\Payments\StripeGateway;
use PHPUnit\Framework\TestCase;

final class StripeGatewayTest extends TestCase
{
    /** @var list<array{request:\Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function gateway(array $responses, string $key = 'sk_test_abc123', string $hook = 'whsec_x'): StripeGateway
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new StripeGateway(new Client(['handler' => $stack]), $key, $hook);
    }

    public function testConfiguredAndMode(): void
    {
        $this->assertTrue($this->gateway([])->isConfigured());
        $this->assertSame('sandbox', $this->gateway([])->mode());
        $this->assertSame('live', $this->gateway([], 'sk_live_abc')->mode());
        $this->assertFalse($this->gateway([], '')->isConfigured());
        $this->assertFalse($this->gateway([], 'pk_test_abc')->isConfigured());
    }

    public function testCreateOrderSendsAmountAndReturnsUrl(): void
    {
        $g = $this->gateway([new Response(200, [], json_encode(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']))]);
        $o = $g->createOrder(12345, 'EUR', 'REF1', 'telc B1', 'https://x/ok', 'https://x/no', 'idem-1');
        $this->assertSame(['order_id' => 'cs_test_1', 'approve_url' => 'https://checkout.stripe.com/c/pay/cs_test_1'], $o);
        $req = $this->history[0]['request'];
        $this->assertSame('idem-1', $req->getHeaderLine('Idempotency-Key'));
        parse_str((string) $req->getBody(), $form);
        $this->assertSame('12345', $form['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('eur', $form['line_items'][0]['price_data']['currency']);
        $this->assertSame('REF1', $form['client_reference_id']);
    }

    public function testCreateOrderWithoutUrlFails(): void
    {
        $this->expectException(PaymentException::class);
        $this->gateway([new Response(200, [], '{"id":"cs_1"}')])->createOrder(100, 'EUR', 'R', 'x', 'https://a', 'https://b', 'k');
    }

    public function testPaidSessionIsCompleted(): void
    {
        $g = $this->gateway([new Response(200, [], json_encode(['payment_status' => 'paid', 'status' => 'complete', 'payment_intent' => 'pi_1', 'amount_total' => 5000, 'currency' => 'eur', 'client_reference_id' => 'R9']))]);
        $c = $g->captureOrder('cs_1', 'k');
        $this->assertSame('COMPLETED', $c['status']);
        $this->assertSame('pi_1', $c['capture_id']);
        $this->assertSame(5000, $c['amount_cents']);
        $this->assertSame('EUR', $c['currency']);
        $this->assertSame('R9', $c['reference']);
    }

    public function testUnpaidAndExpiredSessions(): void
    {
        $g = $this->gateway([new Response(200, [], '{"payment_status":"unpaid","status":"open"}'), new Response(200, [], '{"payment_status":"unpaid","status":"expired"}')]);
        $this->assertSame('PENDING', $g->getOrder('a')['status']);
        $this->assertSame('FAILED', $g->getOrder('b')['status']);
    }

    public function testRefundAndErrors(): void
    {
        $g = $this->gateway([new Response(200, [], '{"id":"re_1","status":"succeeded"}'), new Response(402, [], '{"error":{"code":"charge_already_refunded"}}')]);
        $this->assertSame(['refund_id' => 're_1', 'status' => 'SUCCEEDED'], $g->refund('pi_1', 500, 'EUR', 'x', 'k'));
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('charge_already_refunded');
        $g->refund('pi_1', 500, 'EUR', 'x', 'k2');
    }

    public function testWebhookSignature(): void
    {
        $g = $this->gateway([]);
        $body = '{"id":"evt_1"}';
        $t = 1_800_000_000;
        $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, 'whsec_x');
        $this->assertTrue($g->verifyWebhook(['stripe-signature' => $sig], $body, $t + 10));
        $this->assertFalse($g->verifyWebhook(['stripe-signature' => $sig], $body . ' ', $t + 10), 'veränderter Text');
        $this->assertFalse($g->verifyWebhook(['stripe-signature' => $sig], $body, $t + 1000), 'zu alt');
        $this->assertFalse($g->verifyWebhook([], $body, $t));
        $this->assertFalse($this->gateway([], 'sk_test_abc', '')->verifyWebhook(['stripe-signature' => $sig], $body, $t));
    }
}
