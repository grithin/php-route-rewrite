<?php
namespace Grithin;

use \Grithin\Tool;
use \Grithin\Bound;
use \Grithin\Url;

use \Nyholm\Psr7\Factory\Psr17Factory;


/** A php implementation of some mod_rewrite features
Mod rewrite provides some useful features:
-	various redirect responses
-	path rewriting without a redirect response
-	progressive rule application
-	looped rule application

RouteRewrite provides these
*/

class RouteRewrite implements \Psr\Http\Server\MiddlewareInterface{
	public $data = [];  # rules can cause additions to data
	public $logger = false;

	public $tokens = array();///<an array of url path parts; rules can change this array
	public $tokens_original = array();///<the original array of url path parts
	public $tokens_parsed = array();///<used internally
	public $tokens_unparsed = array();///<used internally
	public $rules_matched;///<list of rules that were matched
	public $path;///<the resulting url path
	public $path_caseless;///<path, but cases removed
	public $token_current;///<used internally; serves as the item compared on token compared rules

	public $regex_last_match=[]; # routing rules: the last regex rule match
	public $regex_matches = []; # compilation of regex match groups
	public $matcher='';///< routing rules: the last matcher string used with a callback

	public $globals = [];///< variables to add to every loaded control.  Will always include 'Route', to allow in-control additions

	public $depth_max = 100;



	/** params
	< rulesets >
		< path > : < ruleset >
		...
	< options >
		respond: < t:bool > < whether to make a PSR response and return that, or just pass the RouteRewriteAction into the request attribute and depend furthe request handlers to deal with it >
		logger: < function to log what RouteRewrite is doing (helps debug complex routes ) > < function($message, \Grithin\RouteRewrite $route, $rule)
		data_handler: < function to handle array of accumulated data from the matched rules >  < function(Array $data){} >
	*/
	public function __construct($rulesets=[], $options=[]){
		$defaults = ['respond'=>true, 'logger'=>false];
		$this->options = array_merge($defaults, $options);
		$this->rulesets = $rulesets;
		if($this->options['logger']){
			$this->logger = $this->options['logger'];
		}
	}

	/** append a rule set to a path
	@return void
	*/
	/** params
	< set >
		< match string >
		< replace string >
		< flags >
		< accumulate > < will accumulate into RouteRewriteAction->data >
	< path > < path on which to apply the rule set >
	*/
	public function ruleset_add($set, $path){
		if(isset($this->ruleset[$path])){
			$set = array_merge($this->ruleset[$path], $set);
		}
		$this->ruleset_set($path, $set);
	}
	/** set the rule set as the only rule set for the path
	@return void
	*/
	/** params
	< set >
		< match string >
		< replace string >
		< flags >
		< accumulate > < will accumulate into RouteRewriteAction->data >
	< path > < path on which to apply the rule set >
	*/
	public function ruleset_set($set, $path){
		$this->ruleset[$path] = $set;
	}


	/** routes path, then calls off all the control until no more or told to stop */
	public function process(\Psr\Http\Message\ServerRequestInterface $request,  \Psr\Http\Server\RequestHandlerInterface $handler): \Psr\Http\Message\ResponseInterface {
		$request->getUri();
		$Psr17Factory = new Psr17Factory;
		$uri = $request->getUri();
		$result = $this->routes_resolve($uri->getPath());

		if(is_object($result)){
			if($result->redirect){ # redirect
				if($result->preserve_query){
					$result->target = Url::appends(Url::query_parse($uri->getQuery()), $result->target);
				}
				if($this->options['respond']){
					$uri = $Psr17Factory->createUri($result->target);
					return $Psr17Factory->createResponse($result->status)->withUri($uri);
				}
			}
			if($result->status && $this->options['respond']){ # status code
				$response = $Psr17Factory->createResponse($result->status);
				if($result->message){
					$response->withBody($message);
				}
				return $response;
			}

			# normal path change
			$request = $request->withUri($uri->withPath($result->target));
		}

		# handle data (like apply route middleware)
		if($this->options['data_handler'] && $this->data){
			$this->options['data_handler']($this->data, $this);
		}

		$request= $request->withAttribute('rewrite', $result);
		return $handler->handle($request);
	}

	public function path_set($path){
		$this->tokens = explode('/', $path);
		$this->tokens_unparsed = $this->tokens;
		$this->tokens_parsed = [];
		$this->path = $path;
		$this->path_caseless = strtolower($this->path);
	}

	/** Gets files and then applies rules for routing


	@return	false|\Grithin\RouteRewriteAction
	*/
	public function routes_resolve($path){
		$this->path_set($path);
		$this->tokens_original = $this->tokens;

		for($i=0; $this->tokens_unparsed; $i++){
			#+ manage possible infinite loop {
			if($i > $this->depth_max){
				throw new RouteRewriteException(array_merge((array)$this, ['message'=>'Router route loop appears to be looping infinitely.  Or, increase depth_max']));
			}
			#+ }

			$this->token_current = array_shift($this->tokens_unparsed);
			$this->tokens_parsed[] = $this->token_current;

			$path_current = implode('/',$this->tokens_parsed);

			# note, on match, rules_match resets tokens_unparsed (having the effect of loopiing rules_match over again)
			$result = $this->rules_match($this->path, $path_current);
			if(is_object($result)){
				break;
			}
			# before moving on to the next token, because this is a directory path, check ruleset with ending slash
			if($this->tokens_unparsed){
				$result = $this->rules_match($this->path, $path_current.'/');
				if(is_object($result)){
					break;
				}
			}
		}

		if(!is_object($result) && $this->path != $path){ #< path changed and there was no RouteRewriteAction, so make one
			$result = new RouteRewriteAction(['target'=>$this->path]);
		}
		if(is_object($result)){
			$result->original = $path;
			$result->variables = $this->regex_last_match;
			$result->tokens = $this->tokens_parsed;
			$result->data = $this->data;
			return $result;
		}
		return false;
	}

	/* resolve a ruleset, which may be a callable that returns an array */
	public function &ruleset_get($path){
		if(!isset($this->rulesets[$path])){
			$this->rulesets[$path] = [];
		}
		if(is_callable($this->rulesets[$path])){
			$this->rulesets[$path] = $this->rulesets[$path]($this);
		}
		return $this->rulesets[$path];
	}

	/** used by preg_replace_callback to replace matches */
	public function regex_callback($replacer, $matches){
		foreach($matches as $k=>$v){
			$this->regex_matches[$k] = $v;
		}
		return $replacer($matches);
	}

	/** for handling '[name]' style regex replacements */
	static function regex_replacements($replacement,$matches){
		foreach($matches as $k=>$v){
			$replacement = str_replace('['.$k.']',$v,$replacement);
		}
		return $replacement;
	}

	/** internal use. Parses all rule sets */
	/** adds file and rules to rulesets and parses all active rules in current file and former files
	*/
	/* params
	< path > < the full path >
	< rules > < ruleset to check >
	< path_current > < the
	*/
	function rules_match($path, $rules_path){
		$rules = &$this->ruleset_get($rules_path);

		if($this->logger){
			($this->logger)('route rules testing', $this, $path); # must call from options, otherwise php will get confused about the `$this` part
		}
		foreach((array)$rules as $rule_key=>&$rule){
			if(!$rule){
				# rule may have been flagged "once"
				continue;
			}
			$matched = false;

			#+ the rule has not yet been parsed, so parse it for use {
			if(!isset($rule['flags'])){
				$flags = Arrays::from($rule[2]);
				$rule['flags'] = array_fill_keys(array_values($flags),true);

				// handle [.] in matcher
				$rule[0] = preg_replace('@\[\.\]@', preg_quote($rules_path), $rule[0]);

				//parse flags for determining match string
				if(!empty($rule['flags']['regex'])){
					$rule['matcher'] = \Grithin\Strings::preg_delimit($rule[0]);
					if(!empty($rule['flags']['caseless'])){
						$rule['matcher'] .= 'i';	}

				}else{
					if(!empty($rule['flags']['caseless'])){
						$rule['matcher'] = strtolower($rule[0]);
					}else{
						$rule['matcher'] = $rule[0];	}	}	}
			#+ }

			# handle caseless  flag
			if(!empty($rule['flags']['caseless'])){
				$subject = $this->path_caseless;
			}else{
				$subject = $this->path;	}

			# test match
			if(!empty($rule['flags']['regex'])){
				if(preg_match($rule['matcher'],$subject, $this->regex_last_match)){
					$matched = true;	}
			}else{
				if($rule['matcher'] == $subject){
					$matched = true;	}	}

			if($matched){
				if($this->logger){
					($this->logger)('route rule matched', $this, $rule); # must call from options, otherwise php will get confused about the `$this` part
				}

				$this->rules_matched[] = $rule;
				//++ apply replacement logic {
				if(!empty($rule['flags']['regex'])){

					if($rule[1] instanceof \Closure){ # target is a function, call it to make new path
						$this->matcher = $rule['matcher'];
						$bound = new Bound($rule[1], [$subject, $rule, $this]);
					}else{ # use the default regex replacements function
						$bound = new Bound([__CLASS__, 'regex_replacements'], [$rule[1]]);
					}
					#+ link the specific replacer to the preg_replace_callback function {
					$callback = new Bound([$this, 'regex_callback'], [$bound]);
					$replacement = preg_replace_callback($rule['matcher'], $callback, $this->path, 1);
					#+ }
				}else{
					if($rule[1] instanceof \Closure){
						$this->matcher = $rule['matcher'];
						$replacement = call_user_func($rule[1], $subject, $rule, $this);
					}else{
						$replacement = $rule[1];	}	}

				#+ handle redirects {
				if(!empty($rule['flags'][301])){
					$httpRedirect = 301;	}
				if(!empty($rule['flags'][303])){
					$httpRedirect = 303;	}
				if(!empty($rule['flags'][307])){
					$httpRedirect = 307;	}
				if($httpRedirect){
					$preserve_query = false;
					if(!empty($rule['flags']['params'])){
						$preserve_query = true;
					}
					return new RouteRewriteAction(['target'=>$replacement, 'status'=>$httpRedirect, 'preserve_query'=>$preserve_query]);
				}
				#+ }

				#+ handle status codes {
				foreach($rule['flags'] as $flag){
					if(Tool::is_int($flag)){
						$message = !empty($rule[3]) ? $rule[3] : null;
						return new RouteRewriteAction(['message'=>$message, 'status'=>$httpRedirect]);
					}
				}

				#+ }

				#+ append data with the accumulate part of the rule {
				if(!empty($rule[3])){
					$this->data[] = $rule[3];
				}
				#+ }



				//remake url with replacement
				if($this->logger){
					($this->logger)('replacing path with "'.$replacement.'"', $this, $rule); # must call from options, otherwise php will get confused about the `$this` part
				}

				$this->path_set($replacement);
				//++ }

				//++ apply parse flag {
				if(!empty($rule['flags']['once'])){
					$rules[$rule_key] = null;
				}elseif(!empty($rule['flags']['set:last'])){
					$this->rulesets[$path] = [];
				}elseif(!empty($rule['flags']['last'])){
					$this->tokens_unparsed = [];	}
				//++ }

				return true;	}
		} unset($rule);

		return false;
	}
}


class RouteRewriteException extends \Grithin\ComplexException{}
