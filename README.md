# cTrader Socialite Provider

This is a [cTrader](https://ctrader.com/) Socialite provider for [Laravel Socialite](https://socialitejs.com/).

## Installation

You can install the package via composer:

```bash
composer require serenity-technologies/socialite-ctrader
```

## Configuration

Add the configuration to your `config/services.php` file:

```php
'ctrader' => [
    'client_id' => env('CTRADER_CLIENT_ID'),
    'client_secret' => env('CTRADER_CLIENT_SECRET'),
    'redirect' => env('CTRADER_REDIRECT_URI'),
],
```

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
