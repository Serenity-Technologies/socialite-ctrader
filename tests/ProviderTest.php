<?php

namespace SocialiteProviders\Ctrader\Tests;

use Mockery as m;
use PHPUnit\Framework\TestCase;
use SocialiteProviders\Ctrader\Provider;
use Illuminate\Http\Request;
use Laravel\Socialite\Two\User;

class ProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    /** @test */
    public function it_can_map_user_to_object()
    {
        $userPayload = [
            'payloadType' => 2152,
            'payload' => [
                'profile' => [
                    'userId' => 1234567,
                ],
            ],
            'access_token' => 'test_token',
        ];

        $request = m::mock(Request::class);
        $provider = new Provider($request, 'client_id', 'client_secret', 'redirect_uri');

        // We use reflection to access the protected mapUserToObject method
        $reflection = new \ReflectionClass(Provider::class);
        $method = $reflection->getMethod('mapUserToObject');
        $method->setAccessible(true);

        $user = $method->invokeArgs($provider, [$userPayload]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals(1234567, $user->getId());
        $this->assertEquals('1234567@ctrader.com', $user->getEmail());
    }

    /** @test */
    public function it_falls_back_to_token_if_user_id_missing()
    {
        $userPayload = [
            'access_token' => 'test_token',
        ];

        $request = m::mock(Request::class);
        $provider = new Provider($request, 'client_id', 'client_secret', 'redirect_uri');

        $reflection = new \ReflectionClass(Provider::class);
        $method = $reflection->getMethod('mapUserToObject');
        $method->setAccessible(true);

        $user = $method->invokeArgs($provider, [$userPayload]);

        $this->assertEquals('test_token', $user->getId());
        $this->assertEquals('test_token@ctrader.com', $user->getEmail());
    }

    /** @test */
    public function it_can_get_user_by_token()
    {
        $token = 'test_token';
        $apiResponse = [
            'payloadType' => 2152,
            'payload' => [
                'profile' => [
                    'userId' => 1234567,
                ],
            ],
        ];

        $request = m::mock(Request::class);
        $provider = m::mock(Provider::class, [$request, 'client_id', 'client_secret', 'redirect_uri'])->makePartial();
        $provider->shouldAllowMockingProtectedMethods();

        $provider->shouldReceive('sendApiRequest')
            ->once()
            ->with(m::on(function ($payload) use ($token) {
                return $payload['payloadType'] === 2151 && $payload['payload']['access' . 'Token'] === $token;
            }))
            ->andReturn($apiResponse);

        $reflection = new \ReflectionClass(Provider::class);
        $method = $reflection->getMethod('getUserByToken');
        $method->setAccessible(true);

        $user = $method->invokeArgs($provider, [$token]);

        $this->assertEquals(1234567, $user['payload']['profile']['userId']);
        $this->assertEquals('test_token', $user['access_token']);
    }

    /** @test */
    public function it_handles_socket_errors_gracefully()
    {
        $token = 'test_token';

        $request = m::mock(Request::class);
        $provider = m::mock(Provider::class, [$request, 'client_id', 'client_secret', 'redirect_uri'])->makePartial();
        $provider->shouldAllowMockingProtectedMethods();

        $provider->shouldReceive('sendApiRequest')
            ->once()
            ->andThrow(new \Exception('Socket error'));

        $reflection = new \ReflectionClass(Provider::class);
        $method = $reflection->getMethod('getUserByToken');
        $method->setAccessible(true);

        $user = $method->invokeArgs($provider, [$token]);

        $this->assertEquals('test_token', $user['access_token']);
        $this->assertArrayNotHasKey('payload', $user);
    }
}
