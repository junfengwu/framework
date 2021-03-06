<?php
// +----------------------------------------------------------------------
// | Leaps Framework [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2011-2014 Leaps Team (http://www.tintsoft.com)
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author XuTongle <xutongle@gmail.com>
// +----------------------------------------------------------------------
namespace Leaps\Events;

use Leaps\Di\ContainerInterface;
use Leaps\Di\ServiceProviderInterface;

class EventServiceProvider implements ServiceProviderInterface
{
	public function register(ContainerInterface $di)
	{
		$di->set ( 'events', function ($di)
		{
			return new Dispatcher ( $di );
		} );
	}
}
