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
namespace Leaps\Web;

use Leaps\Kernel;
use Leaps\Utility\Arr;
use Leaps\Web\Router\Exception as RouteException;

class Application extends \Leaps\Core\Application
{
	/**
	 * 默认路由
	 *
	 * @var unknown
	 */
	public $defaultRoute = 'home';

	/**
	 * 控制器实例
	 *
	 * @var Controller
	 */
	public $controller;

	/**
	 * (non-PHPdoc)
	 *
	 * @param resource Leaps\Http\Request
	 * @see \Leaps\Core\Application::handleRequest()
	 */
	public function handleRequest($request)
	{
		Kernel::setAlias ( '@Webroot', dirname ( $request->getScriptFile () ) );
		Kernel::setAlias ( '@Web', $request->getBaseUrl () );
		list ( $route, $params ) = $request->resolve ();
		try {
			kernel::trace ( "Route requested: '$route'", __METHOD__ );
			$this->requestedRoute = $route;
			$result = $this->runAction ( $route, $params );
			if ($result instanceof Response) {
				return $result;
			} else {
				$response = $this->getShared ( 'response' );
				if ($result !== null) {
					$response->data = $result;
				}
				return $response;
			}
		} catch ( RouteException $e ) {
			throw new NotFoundHttpException ( 'Page not found.', $e->getCode (), $e );
		}
	}
	private $_homeUrl;

	/**
	 *
	 * @return string the homepage URL
	 */
	public function getHomeUrl()
	{
		if ($this->_homeUrl === null) {
			if ($this->getRouter ()->showScriptName) {
				return $this->getRequest ()->getScriptUrl ();
			} else {
				return $this->getRequest ()->getBaseUrl () . '/';
			}
		} else {
			return $this->_homeUrl;
		}
	}

	/**
	 *
	 * @param string $value the homepage URL
	 */
	public function setHomeUrl($value)
	{
		$this->_homeUrl = $value;
	}

	/**
	 * (non-PHPdoc)
	 *
	 * @see \Leaps\Application::coreServices()
	 */
	public function coreServices()
	{
		return Arr::mergeArray ( parent::coreServices (), [
				'request' => [
						'className' => 'Leaps\Http\Request'
				],
				'response' => [
						'className' => 'Leaps\Http\Response'
				],
				'router' => [
						'className' => 'Leaps\Web\Router'
				],
				'cookie' => [
						'className' => 'Leaps\Http\CookieCollection'
				],
				'session' => [
						'className' => 'Leaps\Web\Session'
				],
				'errorHandler' => [
						'className' => 'Leaps\Web\ErrorHandler'
				]
		] );
	}
}