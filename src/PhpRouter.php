<?php

namespace router;

/**
 * @method static get(string $route, Callable $callback)
 * @method static post(string $route, Callable $callback)
 * @method static put(string $route, Callable $callback)
 * @method static delete(string $route, Callable $callback)
 * @method static options(string $route, Callable $callback)
 * @method static head(string $route, Callable $callback)
 */
class PhpRouter {
  public static $halts = false;
  public static $routes = array();
  public static $methods = array();
  public static $callbacks = array();
  public static $patterns = array(
      ':any'  => '[^/]+',
      ':num'  => '[0-9]+',
      ':all'  => '.*',
      ':uuid' => '[a-f0-9]{8}\-[a-f0-9]{4}\-4[a-f0-9]{3}\-(8|9|a|b)[a-f0-9]{3}\-[a-f0-9]{12}'
  );
  public static $_base_uri = '';
  public static $error_callbacks = array(); // HTTP Status Code => callback
  public static $role_access = array(); // Authorization
  public static $unauthorized_url = '/'; // Redirect to this when the authorization is false

  // matched controller and action
  public static $CONTROLLER;
  public static $ACTION;

  public static function base_uri($base_uri)
  {
    self::$_base_uri = rtrim($base_uri, '/');
    return;
  }

  public static function unauthorized_url($url)
  {
    self::$unauthorized_url = $url;
  }

  /**
   * Defines a route w/ callback and method
   * Handles: PhpRouter::post(), PhpRouter::get(), PhpRouter::any()
   */
  public static function __callstatic($method, $params)
  {
    // ----------------------------------------------
    // echo '============================================='. PHP_EOL;
    // $pps = print_r($params, true);
    // echo "callstatic $method $pps ". PHP_EOL;
    // ----------------------------------------------
    $match_uri = $params[0];
    $callback = $params[1];

    if (in_array($method, array('get', 'post', 'any')))
      $uri = strpos($match_uri, '/') === 0 ? $match_uri : '/' . $match_uri;
    else
       throw new \Exception("Method '$method' not recognized");

    $uri = self::$_base_uri . $uri;

    // ----------------------------------------------
    // echo $uri . PHP_EOL;
    // echo $method . PHP_EOL;
    // echo $callback . PHP_EOL;
    // ----------------------------------------------

    $method = strtoupper($method);

    // This was breacking having the same path with different methods! I added the method check to the condition.
    // this allows to overwrite routes on the fly to display errors, for instance
    // if the controller exists but the action doesn't exist yet, without this, it
    // was not possible to show the nice 404 page
    $index = array_search($uri, self::$routes);
    if ($index !== false && self::$methods[$index] == $method)
    {
      //echo "$uri found in $index". PHP_EOL;
      //self::$routes[$index] = $uri; // this is already there!
      self::$methods[$index] = $method;
      self::$callbacks[$index] = $callback;
    }
    else
    {
      array_push(self::$routes, $uri); // /a/b/(:num)
      array_push(self::$methods, $method); // GET, POST, ANY
      array_push(self::$callbacks, $callback); // \controllers\AController@action
    }


    if (count($params) > 2)
    {
      $roles = $params[2];
      if (is_array($roles))
      {
        self::$role_access[$uri] = $roles;
      }
      else // is string, just one role
      {
        self::$role_access[$uri] = [$roles]; // role_access[uri] is always an array
      }
    }
    // if there are no roles passed, the action is open

    // ----------------------------------------------
    // print_r(self::$routes);
    // print_r(self::$methods);
    // print_r(self::$callbacks);
    // echo PHP_EOL;
    // echo PHP_EOL;
    // ----------------------------------------------
  }

  /**
   * Defines callback if route is not found
  */
  // onError(405, function() {...})
  public static function onError($status, $callback) {
    self::$error_callbacks[$status] = $callback;
  }

  public static function haltOnMatch($flag = true) {
    self::$halts = $flag;
  }

  /**
   * Runs the callback for the given request
   */
  public static function dispatch($uri = '')
  {
    global $_BASE, $jwt_user; // jwt is used to check API access
    // $uri us the requested route that should match wit the items in $routes
    // exactly or using regex matchers

    //echo $_SERVER['REQUEST_URI'] . PHP_EOL;
    if (empty($uri))
    {
      $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); // /PhpRouter/a/123
    }

    $method = $_SERVER['REQUEST_METHOD'];

    $searches = array_keys(static::$patterns);
    $replaces = array_values(static::$patterns);

    $found_route = false;

    // FIXME: add try/catch to action call to be able to return 500 errors
    // 404, 405, ...
    $return_status = 0;

    // echo "DISPATCH ========================================". PHP_EOL;
    // echo 'URI:'. $uri . PHP_EOL;
    //print_r(self::$routes);
    //print_r(self::$callbacks);
    //print_r($searches); // (:num)
    //print_r($replaces); // [0-9]+

    // changes // to /
    self::$routes = preg_replace('/\/+/', '/', self::$routes);

    //$plates = new \lib\League\Plates\Engine($_BASE . \config\Config::TEMPLATES_PATH);
    $plates = new \League\Plates\Engine($_BASE . \config\Config::TEMPLATES_PATH); // Plates follower PSR-4, no need to add it to composer.json:autoload

    // Check if route is defined without regex (exact uri match)
    if (in_array($uri, self::$routes))
    {
      // if we have post(uri) and get(uri), route_positions has 2 values, below checks for the method
      $route_positions = array_keys(self::$routes, $uri);
      foreach ($route_positions as $pos)
      {
        $found_route = true;

        // if method matches
        // Using an ANY option to match both GET and POST requests
        if (self::$methods[$pos] == $method ||
            self::$methods[$pos] == 'ANY')
        {
          // callback for route is not an function (is a controller@action)
          // would be the same to do is_string(...) instead of !is_object(..)
          if (!is_object(self::$callbacks[$pos]))
          {
            // // Grab all parts based on a / separator
            // $parts = explode('/',self::$callbacks[$pos]);
            //
            // print_r($parts);
            //
            // // Collect the last index of the array
            // $last = end($parts);

            // \controller\ControllerA@action
            $controller_action_string = self::$callbacks[$pos];

            //echo $controller_action_string . PHP_EOL;

            // Grab the controller name and method call
            $controller_action = explode('@', $controller_action_string);

            // Instantitate controller
            if (!class_exists($controller_action[0]))
            {
              //echo 'controller class '. $controller_action[0] .' doesnt exists';
              $return_status = 404;
              self::handle_error($uri, $return_status);
              return;
            }
            $controller = new $controller_action[0]();

            // Inject plates
            $controller->set_layout($plates);

            if (!method_exists($controller, $controller_action[1]))
            {
              //echo "controller ". $controller_action[0] ." and action ". $controller_action[1] ." not found 404";
              $return_status = 404;
              self::handle_error($uri, $return_status);
              return;
            }

            // authorization check
            // not set is an open action
            if (isset(self::$role_access[$uri]))
            {
              $user = \services\AuthService::current_user();

              if (!$user) // not logged in
              {
                if ($jwt_user == null)
                {
                  // redirect to self::$unauthorized_url
                  // status: 401 unauthorized
                  $_SESSION['flash'] = "You are not authorized to access this section";
                  header("Location: ". self::$_base_uri . self::$unauthorized_url);
                  return;
                }
                else // API access should return a payload error
                {
                  http_response_code(401);
                  echo json_encode([
                    'status'  => 'error',
                    'message' => 'not authenticated',
                    'code'    => 'E016'
                  ]);
                  return;
                }
              }

              // user is logged in
              $allowed_roles = self::$role_access[$uri];
              if (!in_array($user->get_role(), $allowed_roles)) // is not allowed to access the uri?
              {
                if ($jwt_user == null)
                {
                  // redirect to self::$unauthorized_url
                  // status: 403 forbidden
                  $_SESSION['flash'] = "You are not authorized to access this section"; // FIXME: I18N
                  if (isset($_SERVER['HTTP_REFERER'])) // try to redirect to the previous page
                    header("Location: ". $_SERVER['HTTP_REFERER']);
                  else
                    header("Location: ". self::$_base_uri . self::$unauthorized_url);
                  return;
                }
                else // API access should return a payload error
                {
                  http_response_code(403);
                  echo json_encode([
                    'status'  => 'error',
                    'message' => "user role can't access that action",
                    'code'    => 'E017'
                  ]);
                  return;
                }
              }
            }

            self::$CONTROLLER = $controller_action[0];
            self::$ACTION     = $controller_action[1];

            $controller->{$controller_action[1]}();
            $return_status = 0; // resets the possible 405 marked on the loop

            if (self::$halts) return;
          }
          else
          {
            // Call closure
            call_user_func(self::$callbacks[$pos]);
            $return_status = 0; // resets the possible 405 marked on the loop

            if (self::$halts) return;
          }
        }
        else
        {
          // FIXME: Matched route but incorrect method: 405
          $return_status = 405;
        }
      }
    }
    else
    {
      //echo 'Check if uri matches with regex' . PHP_EOL;

      // Check if defined with regex
      $pos = 0; // the index of the methods, routes and callbacks
      foreach (self::$routes as $route)
      {
        // Puts the correspondent regex in place of the short matcher,
        // e.g. (:num) => [0-9]+
        if (strpos($route, ':') !== false) {
          $route = str_replace($searches, $replaces, $route);
        }

        // If the route with the regex matches with the requested route
        if (preg_match('#^' . $route . '$#', $uri, $matched))
        {
          $found_route = true;

          // echo "matched $route in pos $pos". PHP_EOL;
          // echo "method of pos is ". self::$methods[$pos] . PHP_EOL;

          // if method matches
          if (self::$methods[$pos] == $method ||
              self::$methods[$pos] == 'ANY')
          {
            // Array
            // (
            //     [0] => /PhpRouter/a/edit/23423
            //     [1] => 23423
            // )

            // removes the url but keeps the parameters for the action call!
            // Remove $matched[0] as [1] is the first parameter.
            array_shift($matched);

            // Array
            // (
            //     [0] => 23423
            // )

            //echo "matched $route and method $method". PHP_EOL;


            // if the callback is not a function (is a Controller@action)
            if (!is_object(self::$callbacks[$pos]))
            {
              /*
              // Grab all parts based on a / separator
              $parts = explode('/',self::$callbacks[$pos]);

              echo 'CALLBACK PARTS: ';
              print_r($parts);

              // Collect the last index of the array
              $last = end($parts);

              echo $last . PHP_EOL;
              */

              // \controller\ControllerA@action
              $controller_action_string = self::$callbacks[$pos];

              // array(controller, action)
              $controller_action = explode('@', $controller_action_string);

              // Instantitate controller
              if (!class_exists($controller_action[0]))
              {
                //echo 'controller class doesnt exists';
                $return_status = 404;
                self::handle_error($uri, $return_status);
                return;
              }
              $controller = new $controller_action[0]();

              // Inject plates
              $controller->set_layout($plates);

              // Fix multi parameters
              if (!method_exists($controller, $controller_action[1]))
              {
                //echo "controller and action not found";
                $return_status = 404;
                self::handle_error($uri, $return_status);
                return;
              }

              self::$CONTROLLER = $controller_action[0];
              self::$ACTION     = $controller_action[1];

              call_user_func_array(array($controller, $controller_action[1]), $matched);
              $return_status = 0; // resets the possible 405 marked on the loop
            }
            else // if the callback is directly a function, just call it!
            {
              call_user_func_array(self::$callbacks[$pos], $matched);
              $return_status = 0; // resets the possible 405 marked on the loop
            }

            if (self::$halts) return;
          }
          else
          {
            // FIXME: Matched route but incorrect method: 405
            $return_status = 405;
          }
        }
        $pos++;
      }
    }

    if (!$found_route) $return_status = 404;

    //echo $return_status . PHP_EOL;
    //echo $_SERVER['REQUEST_URI'] . PHP_EOL;

    self::handle_error($uri, $return_status);
  }

  static function handle_error($uri, $return_status)
  {
    //echo $uri .' '. $return_status . PHP_EOL;
    //print_r(self::$error_callbacks);
    //var_dump($return_status);

    // user defined error handlers
    if (array_key_exists($return_status, self::$error_callbacks))
    {
      //echo "Error callback exists for $return_status". PHP_EOL;

      $c = self::$error_callbacks[$return_status];
      if (is_string($c))
      {
        //echo "is string $c". PHP_EOL;

        // Avoid loops on 404
        // Next dispatch wont find the error callback because was already used
        unset(self::$error_callbacks[$return_status]);

        // Set the controller@action as callback to the requested route and
        // re-dispatch to make all the process and execute the error callback
        $method_low = strtolower($_SERVER['REQUEST_METHOD']); // get/post
        $uri_no_base = substr($uri, strlen(self::$_base_uri)); // uri with error

        // registers post/get/any for the uri with error, and the callback is the error handler $c
        // this will add the base_uri prefix
        self::$method_low($uri_no_base, $c);

        //print_r(self::$routes);
        //print_r(self::$callbacks);

        // executes the routing again, now the current error uri should be handled by the callback $c of the error handler
        self::dispatch();
      }
      else // callback should be a function
      {
        $c();
      }
      return;
    }

    // default 404 and 405 handlers
    switch ($return_status)
    {
      case 404:
        header($_SERVER['SERVER_PROTOCOL']." 404 Not Found");
        echo "Default: Controller or action not found";
      break;
      case 405:
        header($_SERVER['SERVER_PROTOCOL']." 405 Method Not Allowed");
        echo "Default: Requested method doesn't match allowed methods";
      break;
    }
  }
}
