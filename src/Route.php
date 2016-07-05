<?
namespace Grithin;

use \Grithin\Debug;
use \Grithin\Tool;
use \Grithin\Files;
use \Grithin\Bound;
use \Grithin\Http;





///Used to handle requests by determining path, then determining controls
/**
See routes.sample.php for route rule information

Route Rules Logic
	All routes are optional
	Routes are discovered one level at a time, and previous routing rules affect the discovery of new routes
	if a matching rule is found, the Route rules loop starts over with the new path (unless option set to not do this)

	http://bobery.com/bob/bill/sue:
		control/routes.php
		control/bob/routes.php
		control/bob/bill/routes.php
		control/bob/bill/sue/routes.php


Controls Calling Logic:
	All controls are optional.  However, if the Route is still looping tokens (stop it by exiting or emptying $this->unparsedTokens) and the last token does not match a control, page not found returned

	http://bobery.com/bob/bill/sue:
		control/control.php
		control/bob.php || control/bob/control.php
		control/bob/bill.php || control/bob/bill/control.php
		control/bob/bill/sue.php || control/bob/bill/sue/control.php

File Routing
	If, for some reason, Route is given a request that has a urlProjectFileToken or a systemPublicFolder prefix, Route will send that file after determining the path through the Route Rules Logic

@note	if you get confused about what is going on with the rules, you can print out both self::$matchedRules and $this->ruleSets at just about any time
@note	dashes in paths will not work with namespacing.  Dashes in the last token will be handled by turning the name of the corresponding local tool into a lower camel cased name.
*/



/**

@param	options	{
	notFoundCallback: <the callback to use (passed $this) when a route has non handled trailing tokens>,
	folder: <the control folder to use for disconvering control files and route files>
}
*/
class Route{
	function __construct($options=[]){
		if(!$options['folder']){
			$firstFile = \Grithin\Reflection::firstFileExecuted();
			$options['folder'] = dirname($firstFile).'/control/';
		}
		if(!is_dir($options['folder'])){
			throw new \Exception('Control folder does not exist');
		}
		$this->options = $options;
	}

	public $debug = false;

	public $tokens = array();///<an array of url path parts; rules can change this array
	public $originalTokens = array();///<the original array of url path parts
	public $parsedTokens = array();///<used internally
	public $unparsedTokens = array();///<used internally
	public $matchedRules;///<list of rules that were matched
	public $path;///<the resulting url path
	public $caselessPath;///<path, but cases removed
	public $currentToken;///<used internally; serves as the item compared on token compared rules

	public $regexMatch=[];///< routing rules: the last regex rule match
	public $matcher='';///< routing rules: the last matcher string used with a callback

	public $globals = [];///< variables to add to every loaded control.  Will always include 'route', to allow in-control additions

	/// routes path, then calls off all the control until no more or told to stop
	function handle($path=null){
		$path = $path ? $path : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

		$this->applyPath($path);
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

	///will load controls according to parsedTokens and unparsedTokens
	function load(){
		$this->globals['route'] = $this;

		#see if there is an initial control.php file at the start of the control token loop
		if(!$this->parsedTokens){
			if($this->debug){
				Debug::log('Loading Control: '.$this->options['folder'].'_control.php',['title'=>'Route']);
			}
			Files::inc($this->options['folder'].'_control.php',null,$this->globals);	}

		$loaded = true;

		while($this->unparsedTokens){
			$this->currentToken = array_shift($this->unparsedTokens);
			if($this->currentToken){//ignore blank tokens
				$this->parsedTokens[] = $this->currentToken;

				//++ load the control {
				$path = $this->options['folder'].implode('/',$this->parsedTokens);

				$loaded = false;
				//if named file, load, otherwise load generic control in directory
				if(is_file($path.'.php')){
					$file = $path.'.php';
					if($this->debug){
						Debug::log('Loading Control: '.$path.'.php',['title'=>'Route']);
					}
					$loaded = Files::inc($file,null,$this->globals);
				}elseif(is_file($path.'/_control.php')){
					$file = $path.'/_control.php';
					if($this->debug){
						Debug::log('Loading Control: '.$path.'.php',['title'=>'Route']);
					}
					$loaded = Files::inc($file,null,$this->globals);
				}
				//++ }
			}
			//not loaded and was last token, page not found
			if($loaded === false && !$this->unparsedTokens){
				if($this->options['notFoundCallback']){
					call_user_func($this->options['notFoundCallback'],$this);
				}else{
					Debug::toss('Request handler encountered unresolvable token at control level.'."\nCurrent token: ".$this->currentToken."\nTokens parsed".print_r($this->parsedTokens,true), 'RouteException');		}	}	}
	}

	function applyPath($path){
		$this->tokens = \Grithin\Strings::explode('/',$path);
		$this->path = implode('/', $this->tokens);
		# recap the "/" on the path
		if(substr($path,-1) == '/'){
			$this->path .= '/';
		}
		$this->caselessPath = strtolower($this->path);
	}

	static $ruleSets;///<files containing rules that have been included

	///Gets files and then applies rules for routing
	function resolveRoutes(){
		$this->unparsedTokens = array_merge([''],$this->tokens);
		$this->globals['route'] = $this;

		while($this->unparsedTokens && !$this->stopRouting){
			$this->currentToken = array_shift($this->unparsedTokens);
			if($this->currentToken){
				$this->parsedTokens[] = $this->currentToken;
			}

			$path = $this->options['folder'].implode('/',$this->parsedTokens);
			if(!isset($this->ruleSets[$path])){
				$this->ruleSets[$path] = (array)Files::inc($path.'/_routing.php', null, $this->globals, ['rules'])['rules'];
			}
			if(!$this->ruleSets[$path] || $this->stopRouting){
				continue;
			}
			//note, on match, matehRules resets unparsedTokens (having the effect of loopiing matchRules over again)
			$this->matchRules($path,$this->ruleSets[$path]);
		}

		$this->parsedTokens = [];
	}

	///for handling '[name]' style regex replacements
	static function regexReplacer($replacement,$matches){
		foreach($matches as $k=>$v){
			if(!is_int($k)){
				$replacement = str_replace('['.$k.']',$v,$replacement);
			}
		}
		return $replacement;
	}

	///internal use. Parses all current files and rules
	/** adds file and rules to ruleSets and parses all active rules in current file and former files
	@param	path	str	file location string
	*/
	function matchRules($path,&$rules){
		foreach($rules as $ruleKey=>&$rule){
			if(!$rule){
				# rule may have been flagged "once"
				continue;
			}
			unset($matched);
			if(!isset($rule['flags'])){
				$flags = $rule[2] ? explode(',',$rule[2]) : array();
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
				if(preg_match($rule['matcher'],$subject,$this->regexMatch)){
					$matched = true;	}
			}else{
				if($rule['matcher'] == $subject){
					$matched = true;	}	}

			if($matched){
				if($this->debug){
					Debug::log(['Matched Rule',$rule],['title'=>'Route']);
				}
				$this->matchedRules[] = $rule;
				//++ apply replacement logic {
				if($rule['flags']['regex']){
					if(is_callable($rule[1])){
						$this->matcher = $rule['matcher'];
						$bound = new Bound($rule[1], [$this]);
					}else{
						$bound = new Bound('\Grithin\Route::regexReplacer', [$rule[1]]);
					}
					$replacement = preg_replace_callback($rule['matcher'], $bound, $this->path, 1);
				}else{
					if(is_callable($rule[1])){
						$this->matcher = $rule['matcher'];
						$replacement = call_user_func($rule[1], $this);
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
				$this->applyPath($replacement);
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
