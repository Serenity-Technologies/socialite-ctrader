# cTrader Socialite Provider

This is a [cTrader](https://ctrader.com/) Socialite provider for [Laravel Socialite](https://socialitejs.com/).

## Installation

You can install the package via composer:

```bash
composer require serenity_technologies/socialite-ctrader
```

The package requires the `google/protobuf` library to communicate with the cTrader Open API binary protocol.

## Configuration

Add the configuration to your `config/services.php` file:

```php
'ctrader' => [
    'client_id'     => env('CTRADER_CLIENT_ID'),
    'client_secret' => env('CTRADER_CLIENT_SECRET'),
    'redirect'      => env('CTRADER_REDIRECT_URI'),
    
    // Optional: Protobuf Socket Settings
    'base_host'     => env('CTRADER_BASE_HOST', 'live.ctraderapi.com'), // Use demo.ctraderapi.com for Demo
    'base_port'     => env('CTRADER_BASE_PORT', 5035),
    'timeout'       => env('CTRADER_TIMEOUT', 5),
    'verify_peer'   => env('CTRADER_VERIFY_PEER', true),
    'allow_self_signed' => env('CTRADER_ALLOW_SELF_SIGNED', false),
],
```

### Advanced Settings

- `base_host`: Default is `live.ctraderapi.com`. Change to `demo.ctraderapi.com` for the Sandbox/Demo environment.
- `timeout`: Connection and read timeout in seconds (default: 5).
- `verify_peer`: Set to `false` if your server has issues verifying SSL certificates (not recommended).
- `allow_self_signed`: Set to `true` if using a proxy with self-signed certificates.

### Laravel Octane / FrankenPHP Compatibility

This provider is fully compatible with Laravel Octane and FrankenPHP. It automatically refreshes the request instance to avoid stale state issues common in persistent worker environments.

### Registration

You will need to register the provider using the `SocialiteExtendSocialite` event. Add the listener to your `app/Providers/EventServiceProvider.php`:

```php
protected $listen = [
    \SocialiteProviders\Manager\SocialiteWasCalled::class => [
        \SocialiteProviders\Ctrader\CtraderExtendSocialite::class.'@handle',
    ],
];
```

## Usage

You can now use the provider like any other Socialite provider:

```php
use Laravel\Socialite\Facades\Socialite;

// Redirect to cTrader for authorization
return Socialite::driver('ctrader')->redirect();

// Receive the callback from cTrader
$user = Socialite::driver('ctrader')->user();

// Access user details
$userId = $user->getId();
$email = $user->getEmail(); // Generated as {userId}@ctrader.com
$token = $user->token;
```

### Scopes

By default, the provider uses the `accounts` scope. You can change this or add more scopes:

```php
return Socialite::driver('ctrader')
    ->scopes(['trading'])
    ->redirect();
```

## User Profile Retrieval

Unlike many other Socialite providers, cTrader does not provide a standard REST endpoint for user profile information. This provider automatically handles this by:

1.  Exchanging the authorization code for an access token via REST.
2.  Connecting to the cTrader Open API gateway via an SSL TCP socket.
3.  Sending a `ProtoOAGetCtidProfileByTokenReq` message to retrieve the unique cTrader User ID.
4.  Mapping this ID to the Socialite User object and generating a `{userId}@ctrader.com` email address.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
