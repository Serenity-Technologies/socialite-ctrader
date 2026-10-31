<?php

namespace SocialiteProviders\Ctrader\Tests;

use Mockery as m;
use PHPUnit\Framework\TestCase;
use SocialiteProviders\Ctrader\Exceptions\ProviderException;
use SocialiteProviders\Ctrader\Protobuf\ProtoOACtidProfile;
use SocialiteProviders\Ctrader\Protobuf\ProtoOAGetCtidProfileByTokenRes;
use SocialiteProviders\Ctrader\Protobuf\ProtoOAPayloadType;
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
            'userId' => 1234567,
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
        
        $profile = new ProtoOACtidProfile();
        $profile->setUserId(1234567);
        
        $apiResponse = new ProtoOAGetCtidProfileByTokenRes();
        $apiResponse->setProfile($profile);

        $request = m::mock(Request::class);
        $provider = m::mock(Provider::class, [$request, 'client_id', 'client_secret', 'redirect_uri'])->makePartial();
        $provider->shouldAllowMockingProtectedMethods();

        $provider->shouldReceive('sendApiRequest')
            ->once()
            ->with(ProtoOAPayloadType::PROTO_OA_GET_CTID_PROFILE_BY_TOKEN_REQ, m::any())
            ->andReturn($apiResponse);

        $reflection = new \ReflectionClass(Provider::class);
        $method = $reflection->getMethod('getUserByToken');
        $method->setAccessible(true);

        $user = $method->invokeArgs($provider, [$token]);

        $this->assertEquals(1234567, $user['userId']);
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
            ->andReturn(null);

        $reflection = new \ReflectionClass(Provider::class);
        $method = $reflection->getMethod('getUserByToken');
        $method->setAccessible(true);

        $user = $method->invokeArgs($provider, [$token]);

        $this->assertEquals('test_token', $user['access_token']);
        $this->assertArrayNotHasKey('userId', $user);
    }

    /** @test */
    public function it_rejects_a_400_invalid_grant_response()
    {
        $provider = new Provider(m::mock(Request::class), 'client_id', 'client_secret', 'redirect_uri');
        $method = $this->tokenResponseMethod();

        try {
            $method->invoke($provider, 400, [
                'error' => 'invalid_grant',
                'error_description' => 'The authorization code has expired.',
            ]);

            $this->fail('Expected the token exchange to be refused.');
        } catch (ProviderException $e) {
            $this->assertSame('invalid_grant', $e->getOauthError());
            $this->assertSame('The authorization code has expired.', $e->getOauthErrorDescription());
            $this->assertSame(400, $e->getStatus());
            $this->assertStringContainsString('invalid_grant', $e->getMessage());
            $this->assertStringEndsNotWith(': ', $e->getMessage());
        }
    }

    /** @test */
    public function it_rejects_a_200_response_without_an_access_token()
    {
        $provider = new Provider(m::mock(Request::class), 'client_id', 'client_secret', 'redirect_uri');
        $method = $this->tokenResponseMethod();

        try {
            $method->invoke($provider, 200, ['refreshToken' => 'only-a-refresh-token']);

            $this->fail('Expected a body with no access token to be refused.');
        } catch (ProviderException $e) {
            $this->assertSame(200, $e->getStatus());
            $this->assertStringContainsString('no access token', $e->getMessage());
        }
    }

    /** @test */
    public function it_returns_a_successful_token_body_unchanged()
    {
        $provider = new Provider(m::mock(Request::class), 'client_id', 'client_secret', 'redirect_uri');
        $method = $this->tokenResponseMethod();

        $body = [
            'accessToken' => 'access-token',
            'refreshToken' => 'refresh-token',
            'expiresIn' => 2628000,
        ];

        $this->assertSame($body, $method->invoke($provider, 200, $body));
    }

    /** @test */
    public function it_refuses_a_blank_token_before_the_protobuf_client()
    {
        $request = m::mock(Request::class);
        $provider = new Provider($request, 'client_id', 'client_secret', 'redirect_uri');

        $reflection = new \ReflectionClass(Provider::class);
        $method = $reflection->getMethod('getUserByToken');
        $method->setAccessible(true);

        $this->expectException(ProviderException::class);

        $method->invokeArgs($provider, ['']);
    }

    private function tokenResponseMethod(): \ReflectionMethod
    {
        $reflection = new \ReflectionClass(Provider::class);
        $method = $reflection->getMethod('assertUsableTokenResponse');
        $method->setAccessible(true);

        return $method;
    }
}
