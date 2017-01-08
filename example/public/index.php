<?
$_ENV['public_path'] = __DIR__;
$_ENV['public_folder'] = __DIR__.'/';

require __DIR__ . '/../vendor/autoload.php';
$_ENV['root_path'] = realpath(__DIR__.'/../'); # code is the primary subject, so this is `root` instead of `code` or `private`
$_ENV['root_folder'] = $_ENV['root_path'].'/';

\Grithin\GlobalFunctions::init();

use \Grithin\Route;

class RouteLogger{
	function __construct($folder){
		$this->fh = fopen($folder.'route_log', 'w');
	}
	public $call_count = 0;
	function log($name, $route, $details=null){
		if($this->call_count > 40){
			throw new Exception('Too many log calls');
		}
		$this->call_count++;

		fwrite($this->fh, json_encode(['name'=>$name, 'details'=>$details], JSON_PRETTY_PRINT)."\n");
	}
}

$route_logger = new RouteLogger($_ENV['root_folder'].'log/');

$route = new Route(['folder'=>$_ENV['root_folder'].'control/', 'logger'=>[$route_logger, 'log']]);

try{
	$route->handle();
}catch(RouteException $e){
	\Grithin\Debug::out('Route exception encountered');
	\Grithin\Debug::out($route);
	throw $e;
}

