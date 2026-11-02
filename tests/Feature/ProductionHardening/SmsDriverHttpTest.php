<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\Broadcasting\Services\Sms\CequensDriver;
use App\Modules\Broadcasting\Services\Sms\InfobipDriver;
use App\Modules\Broadcasting\Services\Sms\MsegatDriver;
use App\Modules\Broadcasting\Services\Sms\SmsMisrDriver;
use App\Modules\Broadcasting\Services\Sms\TaqnyatDriver;
use App\Modules\Broadcasting\Services\Sms\TwilioDriver;
use App\Modules\Broadcasting\Services\Sms\UnifonicDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests SMS driver HTTP interactions with Http::fake().
 */
class SmsDriverHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_twilio_driver_sends_to_correct_url_and_returns_sid(): void
    {
        Http::fake([
            'api.twilio.com/*' => Http::response(['sid' => 'SM123', 'status' => 'queued'], 201),
        ]);

        $driver = new TwilioDriver('ACtest', 'token', '+15005550006');
        $result = $driver->send('+16175551234', 'Hello');

        $this->assertTrue($result->success);
        $this->assertSame('SM123', $result->messageId);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.twilio.com') &&
                $request->data()['To'] === '+16175551234' &&
                $request->data()['Body'] === 'Hello';
        });
    }

    public function test_sms_misr_driver_sends_and_parses_success_code(): void
    {
        Http::fake([
            'smsmisr.com/*' => Http::response(['code' => '1901', 'SMSID' => 'MISR1', 'Cost' => '0.1'], 200),
        ]);

        $result = (new SmsMisrDriver('user', 'pass', 'SenderToken'))->send('+201234567890', 'Hello');

        $this->assertTrue($result->success);
        $this->assertSame('MISR1', $result->messageId);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'smsmisr.com') &&
            $request->data()['mobile'] === '201234567890' &&
            $request->data()['sender'] === 'SenderToken' &&
            $request->data()['environment'] === '1' &&
            $request->data()['message'] === 'Hello'
        );
    }

    public function test_sms_misr_driver_reports_failure_on_unexpected_code(): void
    {
        Http::fake([
            'smsmisr.com/*' => Http::response(['code' => '1902', 'message' => 'Invalid credentials'], 200),
        ]);

        $result = (new SmsMisrDriver('user', 'pass', 'sender'))->send('+201234567890', 'Hello');

        $this->assertFalse($result->success);
    }

    public function test_cequens_driver_authenticates_then_sends(): void
    {
        Http::fake([
            'apis.cequens.com/auth/v1/tokens/*' => Http::response(['data' => ['access_token' => 'JWT123']], 200),
            'apis.cequens.com/sms/v1/messages' => Http::response(['data' => ['messageId' => 'CEQ1']], 200),
        ]);

        $result = (new CequensDriver('svcuser', 'apikey', 'CEQUENS'))->send('+201234567890', 'Hello');

        $this->assertTrue($result->success);
        $this->assertSame('CEQ1', $result->messageId);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sms/v1/messages') &&
            $request->hasHeader('Authorization', 'Bearer JWT123') &&
            $request->data()['recipients'] === ['201234567890'] &&
            $request->data()['senderName'] === 'CEQUENS'
        );
        Http::assertSent(fn ($request) => str_contains($request->url(), '/auth/v1/tokens/'));
    }

    public function test_cequens_driver_fails_when_authentication_fails(): void
    {
        Http::fake([
            'apis.cequens.com/auth/v1/tokens/*' => Http::response(['replyMessage' => 'Invalid credentials'], 200),
        ]);

        $result = (new CequensDriver('svcuser', 'bad', 'CEQUENS'))->send('+201234567890', 'Hello');

        $this->assertFalse($result->success);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/sms/v1/messages'));
    }

    public function test_unifonic_driver_sends_and_parses_message_id(): void
    {
        Http::fake([
            'el.cloud.unifonic.com/*' => Http::response(['success' => true, 'data' => ['MessageID' => 'UNI1']], 200),
        ]);

        $result = (new UnifonicDriver('AppSid123', 'Sender'))->send('+966500000000', 'Hello');

        $this->assertTrue($result->success);
        $this->assertSame('UNI1', $result->messageId);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'el.cloud.unifonic.com') &&
            $request->data()['AppSid'] === 'AppSid123' &&
            $request->data()['Recipient'] === '966500000000' &&
            $request->data()['SenderID'] === 'Sender'
        );
    }

    public function test_taqnyat_driver_sends_bearer_request(): void
    {
        Http::fake([
            'api.taqnyat.sa/*' => Http::response(['statusCode' => 201, 'messageId' => 'TAQ1'], 201),
        ]);

        $result = (new TaqnyatDriver('token123', 'MyBrand'))->send('+966500000000', 'Hello');

        $this->assertTrue($result->success);
        $this->assertSame('TAQ1', $result->messageId);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.taqnyat.sa') &&
            $request->hasHeader('Authorization', 'Bearer token123') &&
            $request->data()['recipients'] === ['966500000000'] &&
            $request->data()['sender'] === 'MyBrand'
        );
    }

    public function test_msegat_driver_treats_success_codes_as_accepted(): void
    {
        Http::fake([
            'www.msegat.com/*' => Http::response(['code' => 'M0000', 'id' => 'MSG1'], 200),
        ]);

        $result = (new MsegatDriver('user', 'key', 'Sender'))->send('+966500000000', 'Hello');

        $this->assertTrue($result->success);
        $this->assertSame('MSG1', $result->messageId);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'msegat.com') &&
            $request->data()['userName'] === 'user' &&
            $request->data()['apiKey'] === 'key' &&
            $request->data()['userSender'] === 'Sender' &&
            $request->data()['numbers'] === '966500000000' &&
            $request->data()['msgEncoding'] === 'UTF8'
        );
    }

    public function test_msegat_driver_reports_failure_on_error_code(): void
    {
        Http::fake([
            'www.msegat.com/*' => Http::response(['code' => 'M0002', 'message' => 'Invalid login info'], 200),
        ]);

        $result = (new MsegatDriver('user', 'bad', 'Sender'))->send('+966500000000', 'Hello');

        $this->assertFalse($result->success);
    }

    public function test_infobip_driver_normalises_base_url_and_sends(): void
    {
        Http::fake([
            'xyz.api.infobip.com/*' => Http::response(['messages' => [['messageId' => 'IB1']]], 200),
        ]);

        $result = (new InfobipDriver('apikey', 'https://xyz.api.infobip.com/', 'InfoSMS'))->send('+966500000000', 'Hello');

        $this->assertTrue($result->success);
        $this->assertSame('IB1', $result->messageId);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'xyz.api.infobip.com/sms/2/text/advanced') &&
            $request->hasHeader('Authorization', 'App apikey') &&
            $request->data()['messages'][0]['destinations'] === [['to' => '966500000000']]
        );
    }

    public function test_infobip_driver_parses_delivery_report_status(): void
    {
        Http::fake([
            'xyz.api.infobip.com/*' => Http::response(['results' => [['messageId' => 'IB1', 'status' => ['groupName' => 'DELIVERED']]]], 200),
        ]);

        $status = (new InfobipDriver('apikey', 'xyz.api.infobip.com', 'InfoSMS'))->status('IB1');

        $this->assertSame('delivered', $status->status);
        $this->assertSame('IB1', $status->providerId);
    }
}
