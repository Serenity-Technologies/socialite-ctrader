<?php

namespace SocialiteProviders\Ctrader\Tests;

use Mockery as m;
use PHPUnit\Framework\TestCase;
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
}
