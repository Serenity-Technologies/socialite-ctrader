<?php

namespace SocialiteProviders\Ctrader;

use SocialiteProviders\Manager\SocialiteWasCalled;

class CtraderExtendSocialite
{
    /**
     * Register the provider.
     *
     * @param \SocialiteProviders\Manager\SocialiteWasCalled $socialiteWasCalled
     * @return void
     */
    public function handle(SocialiteWasCalled $socialiteWasCalled)
    {
        $socialiteWasCalled->extendSocialite('ctrader', Provider::class);
    }
}
