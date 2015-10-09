<?
$base = realpath(__DIR__.'/../');

# Assumes composer used
require $base . '/vendor/autoload.php';

use \Grithin\Route;

$route = new Route(['folder'=>$base.'/control/']);

/**
Based on the controls and the routes, will handle the following paths:

-	/
-	/bill/123
-	/staticMethod
-	/instanceMethod
-	/globalFunction
-	/index
-	/test/normal


*/


try{
	$route->handle();
}catch(RouteException $e){
	\Grithin\Debug::out('Route exception encountered');
	\Grithin\Debug::out($route);
	throw $e;
}