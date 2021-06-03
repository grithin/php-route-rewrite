<?
namespace Grithin;

use \Grithin\Debug;
use \Grithin\Tool;
use \Grithin\Files;
use \Grithin\Bound;
use \Grithin\Http;





///Used to handle requests by determining path, then determining controls
/**
@note	if you get confused about what is going on with the rules, you can print out both self::$matchedRules and $this->ruleSets at just about any time
@note	dashes in paths will not work with namespacing.  Dashes in the last token will be handled by turning the name of the corresponding local tool into a lower camel cased name.
*/



/**

@param	options	{
	notFoundCallback: <the callback to use (passed $this) when a route has non handled trailing tokens>,
	folder: <the control folder to use for disconvering control files and route files>
	logger: <callback to use to log at various points in the routing process>
}

logger in form function($name, $route_instance, $details)
*/
class Route{
	public $logger = false;
	function __construct($options=[]){
		if(empty($options['folder'])){
			$firstFile = \Grithin\Reflection::firstFileExecuted();
			$options['folder'] = dirname($firstFile).'/control/';
		}
		if(!is_dir($options['folder'])){
			throw new \Exception('Control folder does not exist');
		}
		$this->options = $options;
		if($options['logger']){
			$this->logger = $options['logger'];
		}

		#+ deprecation linking {
		$this->regexMatch = &$this->regex_last_match;
		#+ }
	}
	/*
	state:
	-	0: no debug
	-	1: debug log
	-	2: debug log + skip loads
	*/
	public $debug = 0;

	public $tokens = array();///<an array of url path parts; rules can change this array
	public $originalTokens = array();///<the original array of url path parts
	public $parsedTokens = array();///<used internally
	public $unparsedTokens = array();///<used internally
	public $matchedRules;///<list of rules that were matched
	public $path;///<the resulting url path
	public $caselessPath;///<path, but cases removed
	public $currentToken;///<used internally; serves as the item compared on token compared rules

	public $regex_last_match=[]; # routing rules: the last regex rule match
	public $regex_matches = []; # compilation of regex match groups
	public $matcher='';///< routing rules: the last matcher string used with a callback

	public $globals = [];///< variables to add to every loaded control.  Will always include 'route', to allow in-control additions

	public $max_loops = 20;


	/// routes path, then calls off all the control until no more or told to stop
	function handle($path=null){
		$path = $path ? $path : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

		$this->path_set($path);
		$this->originalTokens = $this->tokens;

		$this->resolveRoutes();
		$this->unparsedTokens = $this->tokens;//blank token loads in control
		$this->load();
	}

	///handle the loading of a particular control path
	/**
	@param	start	<<will skip controls prior to start>>
	*/
	function particular($target,$start=''){
		$this->parsedTokens = [];
		$this->unparsedTokens = explode('/',$target);
		if($start){
			$this->parsedTokens = explode('/',$start);
			$this->unparsedTokens = array_diff($this->unparsedTokens,$this->parsedTokens);
		}

		$this->originalTokens = $this->tokens = array_merge($this->parsedTokens, $this->unparsedTokens);

		$this->load();
	}

	/// will load controls according to parsedTokens and unparsedTokens
	function load(){
		$this->globals['Route'] = $this->globals['route'] = $this;

		# see if there is an initial control.php file at the start of the control token loop
		if(!$this->parsedTokens){
			if($this->logger){
				$this->options['logger']('loading global control', $this, $this->options['folder'].'_control.php'); # must call from options, otherwise php will get confused about the `$this` part
			}
			Files::inc($this->options['folder'].'_control.php',$this->globals);	}

		$loaded = true;
		$i = 0;

		while($this->unparsedTokens && !$this->control_ended){
			$this->currentToken = array_shift($this->unparsedTokens);
			if($this->currentToken){//ignore blank tokens
				$i++;
				if($i > $this->max_loops){
					throw new RouteException(array_merge((array)$this, ['message'=>'Router control loop appears to be looping infinitely']));
				}

				$this->parsedTokens[] = $this->currentToken;

				//++ load the control {
				$path = $this->options['folder'].implode('/',$this->parsedTokens);


				$loaded = false;
				// if named file, load, otherwise load generic control in directory
				if(is_file($path.'.php')){
					$file = $path.'.php';
					if($this->logger){
						$this->options['logger']('loading control', $this, $file); # must call from options, otherwise php will get confused about the `$this` part
					}
					$loaded = Files::inc($file,$this->globals);
				}elseif(is_file($path.'/_control.php')){
					$file = $path.'/_control.php';
					if($this->logger){
						$this->options['logger']('loading control', $this, $file); # must call from options, otherwise php will get confused about the `$this` part
					}
					$loaded = Files::inc($file,$this->globals);
				}
				//++ }
			}


			//not loaded and was last token, page not found
			if($loaded === false && !$this->unparsedTokens){
				#+ Try contexted 404s {
				$parsedTokens = $this->parsedTokens;
				$test_404 = function($parsedTokens){
					$path = $this->options['folder'].implode('/',$parsedTokens);
					if(is_file($path.'/404.php')){
						$file = $path.'/404.php';
						if($this->logger){
							$this->options['logger']('loading control', $this, $file); # must call from options, otherwise php will get confused about the `$this` part
						}
						return Files::inc($file,$this->globals);
					}
					return false;
				};
				while(array_pop($parsedTokens)){
					if($loaded = $test_404($parsedTokens)){
						break;
					}
				}
				if(!$loaded){
					$loaded = $test_404([]);
				}
				#+ }
				if($loaded === false){
					if($this->options['notFoundCallback']){
						call_user_func($this->options['notFoundCallback'],$this);
					}else{
						throw new RouteException(array_merge((array)$this, ['message'=>'Request handler encountered unresolvable token at control level']));
					}
				}	}	}
	}

	protected $control_ended = false;
	public function control_end(){
		$this->control_ended = true;
	}

	function path_set($path){
		$this->tokens = \Grithin\Strings::explode('/',$path); # clear multiple ending `/`'s
		$this->path = implode('/', $this->tokens);
		# recap the "/" on the path, if the path is not simply `/`
		if(strlen($path)>1 && substr($path,-1) == '/'){
			$this->path .= '/';
		}
		$this->caselessPath = strtolower($this->path);
	}

	static $ruleSets;///<files containing rules that have been included

	///Gets files and then applies rules for routing
	function resolveRoutes(){
		$this->unparsedTokens = array_merge([''],$this->tokens);
		$this->globals['Route'] = $this->globals['route'] = $this;

		$i = 0;

		while(
			$this->unparsedTokens
			&& !$this->routing_ended # a rule callback may have set this
		){
			$i++;
			if($i > $this->max_loops){
				throw new RouteException(array_merge((array)$this, ['message'=>'Router route loop appears to be looping infinitely']));
			}

			$this->currentToken = array_shift($this->unparsedTokens);
			if($this->currentToken){
				$this->parsedTokens[] = $this->currentToken;
			}

			$path = $this->options['folder'].implode('/',$this->parsedTokens);
			if(!isset($this->ruleSets[$path])){
				$rules = Files::inc($path.'/_routing.php', $this->globals);
				$this->ruleSets[$path] = is_array($rules) ? $rules : [];
			}
			if($this->routing_ended){ # route file may have set this
				continue;
			}
			//note, on match, matehRules resets unparsedTokens (having the effect of loopiing matchRules over again)
			$this->matchRules($path,$this->ruleSets[$path]);
		}

		$this->parsedTokens = [];
	}

	protected $routing_ended = false;
	public function routing_end(){
		$this->routing_ended = true;
	}


	public function regex_callback($replacer, $matches){
		foreach($matches as $k=>$v){
			$this->regex_matches[$k] = $v;
		}
		return $replacer($matches);
	}

	///for handling '[name]' style regex replacements
	static function regexReplacer($replacement,$matches){
		foreach($matches as $k=>$v){
			$replacement = str_replace('['.$k.']',$v,$replacement);
		}
		return $replacement;
	}
	/*
	allow special handling of strings  starting with '\' to be considered potentially callable
	*/
	static function is_callable($thing){
		if((!is_string($thing) || $thing[0] == '\\') && is_callable($thing)){
			return true;
		}
		return false;
	}

	///internal use. Parses all current files and rules
	/** adds file and rules to ruleSets and parses all active rules in current file and former files
	@param	path	str	file location string
	*/
	function matchRules($path,&$rules){
		if($this->logger){
			$this->options['logger']('route rules testing', $this, $path); # must call from options, otherwise php will get confused about the `$this` part
		}
		foreach((array)$rules as $ruleKey=>&$rule){
			if(!$rule){
				# rule may have been flagged "once"
				continue;
			}
			unset($matched);
			if(!isset($rule['flags'])){
				if(is_string($rule[2])){
					$flags = explode(',',$rule[2]);
				}else{
					$flags = (array)$rule[2];
				}
				$rule['flags'] = array_fill_keys(array_values($flags),true);

				//parse flags for determining match string
				if($rule['flags']['regex']){
					$rule['matcher'] = \Grithin\Strings::pregDelimit($rule[0]);
					if($rule['flags']['caseless']){
						$rule['matcher'] .= 'i';	}

				}else{
					if($rule['flags']['caseless']){
						$rule['matcher'] = strtolower($rule[0]);
					}else{
						$rule['matcher'] = $rule[0];	}	}	}

			if($rule['flags']['caseless']){
				$subject = $this->caselessPath;
			}else{
				$subject = $this->path;	}

			//test match
			if($rule['flags']['regex']){
				if(preg_match($rule['matcher'],$subject, $this->regex_last_match)){
					$matched = true;	}
			}else{
				if($rule['matcher'] == $subject){
					$matched = true;	}	}

			if($matched){
				if($this->logger){
					$this->options['logger']('route rule matched', $this, $rule); # must call from options, otherwise php will get confused about the `$this` part
				}

				$this->matchedRules[] = $rule;
				//++ apply replacement logic {
				if($rule['flags']['regex']){
					if(self::is_callable($rule[1])){
						$this->matcher = $rule['matcher'];
						$bound = new Bound($rule[1], [$this, $rule]);
					}else{
						$bound = new Bound('\Grithin\Route::regexReplacer', [$rule[1]]);
					}
					$callback = new Bound([$this, 'regex_callback'], [$bound]);
					$replacement = preg_replace_callback($rule['matcher'], $callback, $this->path, 1);
				}else{
					if(self::is_callable($rule[1])){
						$this->matcher = $rule['matcher'];
						$replacement = call_user_func($rule[1], $this, $rule);
					}else{
						$replacement = $rule[1];	}	}

				//handle redirects
				if($rule['flags'][301]){
					$httpRedirect = 301;	}
				if($rule['flags'][303]){
					$httpRedirect = 303;	}
				if($rule['flags'][307]){
					$httpRedirect = 307;	}
				if($httpRedirect){
					if($rule['flags']['params']){
						$replacement = Http::appendsUrl(Http::parseQuery($_SERVER['QUERY_STRING']),$replacement);	}
					Http::redirect($replacement,'head',$httpRedirect);	}

				//remake url with replacement
				$this->path_set($replacement);
				$this->parsedTokens = [];
				$this->unparsedTokens = array_merge([''],$this->tokens);
				//++ }

				//++ apply parse flag {
				if($rule['flags']['once']){
					$rules[$ruleKey] = null;
				}elseif($rule['flags']['file:last']){
					$this->ruleSets[$path] = [];
				}elseif($rule['flags']['last']){
					$this->unparsedTokens = [];	}
				//++ }

				return true;	}
		} unset($rule);

		return false;
	}
}


class RouteException extends \Grithin\ComplexException{}
