<?php

/*********************************
    Laravel 4/5 Z-Ray Extension
**********************************/

namespace ZRay;

class Laravel
{
    private $visibleConfigurations = array(); //Put here Laravel configurations files which you want to be display (eg. app, database, cache, full list on /app/config/) 
    private $tracedAlready = false;
    private $zre = null;

    public function setZRE($zre)
    {
        $this->zre = $zre;
    }

    public function laravelRunExit($context, &$storage)
    {
        global $app,$zend_laravel_views,$zend_laravel_events;
        if (strpos(php_sapi_name(), 'cli') !== false || !is_object($app) || version_compare($app::VERSION, 4, '<') || version_compare($app::VERSION, 6, '>=')) {
            return; //this extension support only laravel 4/5 non-cli
        }
        
        $this->loadLaravelPanel($storage);
        if (version_compare($app::VERSION, 4.1, '>=')) {
            $this->loadLaravelRoutePanel($storage);
            $this->loadSessionPanel($storage);
            $this->loadUserPanel($storage);
        } else {
            if (is_a(\Route::getCurrentRoute(), 'Symfony\Component\Routing\Route')) {
                $this->loadSymfonyRoutePanel($storage);
            }
        }

        $this->loadConfigurationPanel($storage);

        $storage['views'] = $zend_laravel_views;
        $storage['eventsLog'] = $zend_laravel_events;
    }
    
    public function laravelLogWriterError($context, &$storage)
    {
        try {
            $storage['logs'][] = array(
                                'Level' => !empty(\Monolog\Logger::getLevelName($context['functionArgs'][0])) ? \Monolog\Logger::getLevelName($context['functionArgs'][0]) : $context['functionArgs'][0],
                                'Message' => $context['functionArgs'][1],
                                'Context' => empty($context['functionArgs'][2]) ? '' : json_encode($context['functionArgs'][2]),
             );
        } catch(Exception $e) {
            // Can't serialize error
        }
    }
    
    public function laravelBeforeRun($context, &$storage)
    {
        global $app;
        if (strpos(php_sapi_name(), 'cli') !== false || !is_object($app) || version_compare($app::VERSION, 4, '<') || version_compare($app::VERSION, 6, '>=')) {
            return; //this extension support only laravel 4/5 non-cli
        }
        
        if (version_compare($app::VERSION, 4.1, '<')) {
            $this->loadSessionPanel($storage);
            $this->loadUserPanel($storage);
        }
        $this->loadViewPanel($storage);
        $this->loadEventsPanel($storage);
    }
    
    public function loadConfigurationPanel(&$storage)
    {
        foreach ($this->visibleConfigurations as $conf) {
            $storage['Configurations'][$conf] = \Config::get($conf);
        }
    }
    
    public function loadSessionPanel(&$storage)
    {
        global $app;
        $data = array();
        foreach ($app['session']->all() as $key => $value) {
            $storage['session'][$key] = array('Name' => $key,'Value' => $value);
        }

        return $data;
    }
    
    public function loadEventsPanel(&$storage)
    {
        global $app,$zend_laravel_events;
        $zend_laravel_events = array();
        $events = $app['events'];
        $events->listen(
            '*',
            function () use ($events) {
                global $zend_laravel_events;
                if (method_exists($events, 'firing')) {
                    $event = $events->firing();
                } else {
                    $args = func_get_args();
                    $event = end($args);
                }
                $zend_laravel_events[] = array('Name' => $event);
            }
        );
    }
    
    public function loadViewPanel(&$storage)
    {
        if ($this->tracedAlready) {
            return;
        } else {
            $this->tracedAlready = true;
        }
        global $app,$zend_laravel_views;
        $zend_laravel_views = array();
        
        if (version_compare($app::VERSION, 5.4, '<')) {
            $app['events']->listen('composing:*', function ($view) use (&$storage) {
                global $zend_laravel_views;
                $data = array();
                foreach ($view->getData() as $key => $value) {
                    if (is_object($value) && method_exists($value, 'toArray')) {
                        $value = $value->toArray();
                    }
                    $data[$key] = $this->exportValue($value);
                }
                $zend_laravel_views[$view->getName()] = array(
                    'Path' => $view->getPath(),
                    'Params (' . count($data) . ')' => $data
                );
            });
        } else {
            $app['events']->listen('composing:*', function ($view, $datas) use (&$storage) {
                global $zend_laravel_views;
                $data = array();
                foreach ($datas as $key => $value) {
                    if (is_object($value) && method_exists($value, 'toArray')) {
                        $value = $value->toArray();
                    }
                    $data[$key] = $this->exportValue($value);
                }
                $zend_laravel_views[$view] = array(
                    'Path' => '/',
                    'Params (' . count($data) . ')' => $data
                );
            });
        }
    }
    
    protected function loadLaravelPanel(&$storage)
    {
        global $app;
        $storage['general'][] = array('Name' => 'Application Path','Value' => app_path());
        $storage['general'][] = array('Name' => 'Base Path','Value' => base_path());
        $storage['general'][] = array('Name' => 'Public Path','Value' => public_path());
        $storage['general'][] = array('Name' => 'Storage Path','Value' => storage_path());
        $storage['general'][] = array('Name' => 'URL Path','Value' => \URL::to('/'));
        $storage['general'][] = array('Name' => 'Environment','Value' => \App::environment());
        $storage['general'][] = array('Name' => 'Version','Value' => $app::VERSION);
        $storage['general'][] = array('Name' => 'Locale','Value' => $app->getLocale());
    }
    
    protected function loadUserPanel(&$storage)
    {
        global $app;
        $user = $app['auth']->user();
        if (!$user) {
            //guest
            $storage['userInformation'][] = array('Name' => 'Guest','Additional Info' => 'Not Logged-in');

            return;
        } else {
            $storage['userInformation'][] = array('Name' => $user->id);
        }
    }
    
    protected function loadSymfonyRoutePanel(&$storage)
    {
        $name = \Route::currentRouteName();
        $route = \Route::getCurrentRoute();
        $routePanel = array();
        $host = $route->getHost();
        if (!empty($host)) {
            $routePanel['Host'] = $host;
        }
        if (!empty($name)) {
            $routePanel['Name'] = $name;
        }
        $path = $route->getPath();
        if (!empty($path)) {
            $routePanel['Path'] = $path;
        }
        $routePanel['Action'] = $route->getAction() ?: 'Closure';
        $routePanel['Before Filters'] = $route->getBeforeFilters();
        $routePanel['After Filters'] = $route->getAfterFilters();

        $storage['route'][] = $routePanel;
    }
    
    protected function loadLaravelRoutePanel(&$storage)
    {
        global $app;
        $route = \Route::getCurrentRoute();
        $routePanel = array();
        
        if (version_compare($app::VERSION, 5.4, '<')) {
            if (get_class($route) != 'Illuminate\Routing\Route') {
                return;
            }
        }else{
            if (get_class($route) != 'Illuminate\Support\Facades\Route') {
                return;
            }
        }
        
        $domain = $route->domain();
        if (!empty($domain)) {
            $routePanel['Host'] = $domain;
        }
        $name = $route->getName();
        if (!empty($name)) {
            $routePanel['Name'] = $name;
        }
        $path = $route->getPath();
        if (!empty($path)) {
            $routePanel['Path'] = $path;
        }
        $routePanel['Action'] = $route->getActionName();
        if (version_compare($app::VERSION, 5.1, '<')) {
          $routePanel['Before Filters'] = $route->beforeFilters();
          $routePanel['After Filters'] = $route->afterFilters();
        } else {
          $routePanel['Middleware(s)'] =  implode(', ', array_values($route->middleware()));
          $action = $route->getAction();
          $routePanel['Namespace'] = $action['namespace'];
          $routePanel['Prefix'] = $action['prefix'];
          $routePanel['Where'] = $action['where'];
        }

        $storage['route'][] = $routePanel;
    }
    
    protected function exportValue($value, $depth = 1, $deep = false)
    {
        if (is_object($value)) {
            return sprintf('Object(%s)', get_class($value));
        }

        if (is_array($value)) {
            if (empty($value)) {
                return '[]';
            }

            $indent = str_repeat('  ', $depth);

            $a = array();
            foreach ($value as $k => $v) {
                if (is_array($v)) {
                    $deep = true;
                }
                $a[] = sprintf('%s => %s', $k, $this->exportValue($v, $depth + 1, $deep));
            }

            if ($deep) {
                return sprintf("[\n%s%s\n%s]", $indent, implode(sprintf(", \n%s", $indent), $a), str_repeat('  ', $depth - 1));
            }

            return sprintf('[%s]', implode(', ', $a));
        }

        if (is_resource($value)) {
            return sprintf('Resource(%s#%d)', get_resource_type($value), $value);
        }

        if (null === $value) {
            return 'null';
        }

        if (false === $value) {
            return 'false';
        }

        if (true === $value) {
            return 'true';
        }

        return (string) $value;
    }
}

$zre = new \ZRayExtension('laravel');

$zrayLaravel = new Laravel();
$zrayLaravel->setZRE($zre);

$zre->setMetadata(array(
    'logo' => __DIR__.DIRECTORY_SEPARATOR.'logo.png',
));

$zre->setEnabledAfter('Illuminate\Foundation\Application::detectEnvironment');
$zre->traceFunction('Illuminate\Foundation\Application::boot', function () {}, array($zrayLaravel, 'laravelBeforeRun'));
$zre->traceFunction('Symfony\Component\HttpFoundation\Response::send', function () {}, array($zrayLaravel, 'laravelRunExit'));
$zre->traceFunction('Monolog\Logger::addRecord', function () {}, array($zrayLaravel, 'laravelLogWriterError'));
